<?php

require_once __DIR__ . "/database.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/google_auth.php";
require_once __DIR__ . "/rewards.php";
require __DIR__ . "/flight/Flight.php";

function apiJson($result, $status = 200)
{
	Flight::response()->status($status);
	header("Content-Type: application/json; charset=utf-8");
	echo json_encode(utf8ize($result));
}

function authFailure($status, $message, $code)
{
	apiJson(array(
		"success" => false,
		"result" => $message,
		"code" => $code,
	), $status);
	return null;
}

function requireCurrentUser()
{
	$user = authCurrentUser();
	if (!$user) {
		return authFailure(401, "Nicht angemeldet.", "AUTH_REQUIRED");
	}
	return $user;
}

function requireBossUser()
{
	$user = requireCurrentUser();
	if (!$user) {
		return null;
	}
	if ((int) $user["boss"] < 1) {
		return authFailure(403, "Nicht berechtigt.", "BOSS_REQUIRED");
	}
	return $user;
}

function utf8ize($mixed)
{
	if (is_array($mixed)) {
		foreach ($mixed as $key => $value) {
			$mixed[$key] = utf8ize($value);
		}
	} elseif (is_string($mixed) && function_exists("mb_convert_encoding")) {
		return mb_convert_encoding($mixed, "UTF-8", "UTF-8");
	}
	return $mixed;
}

function transactionsAll()
{
	return array(
		"success" => true,
		"result" => dbTransactionsAll(),
	);
}

function transactionsByCustomer($customer)
{
	return array(
		"success" => true,
		"result" => dbTransactionsByCustomer($customer),
	);
}

function kpisByCustomer($customer)
{
	rewardsRunLazyMonthlyForCustomer($customer);
	$balance = dbBalanceByCustomer($customer);
	if (!$balance) {
		$balance = 0;
	}

	$nofpigs = (int) floor(max(0, $balance) / 100);
	$nofinandout = dbNofInAndOut($customer);

	return array(
		"success" => true,
		"result" => array(
			"balance" => $balance,
			"nofpigs" => $nofpigs,
			"nofins" => (int) $nofinandout["nofin"],
			"nofouts" => (int) $nofinandout["nofout"],
		),
	);
}

function customersAll($realm)
{
	return array(
		"success" => true,
		"result" => dbCustomersAll($realm),
	);
}

function bossOverview($realm)
{
	return array(
		"success" => true,
		"result" => dbBossOverview($realm),
	);
}

function bossRewards($realm)
{
	return array(
		"success" => true,
		"result" => dbRewardOverview($realm),
	);
}

function updateRewardSettings($settings)
{
	$allowed = array(
		"monthly_interest_rate" => "decimal",
		"savings_milestone_reward_rate" => "decimal",
		"input_lead_reward_rate" => "decimal",
		"savings_milestone_step" => "decimal",
		"reward_deposit_enabled" => "boolean",
		"reward_monthly_interest_enabled" => "boolean",
		"reward_savings_milestone_enabled" => "boolean",
		"reward_input_lead_enabled" => "boolean",
	);
	$updates = array();

	foreach ($allowed as $key => $type) {
		if (!array_key_exists($key, $settings)) {
			continue;
		}
		if ($type === "decimal") {
			if (!is_numeric($settings[$key]) || (float) $settings[$key] < 0) {
				return array(
					"success" => false,
					"result" => "Ungueltiger Wert fuer " . $key . ".",
				);
			}
			$updates[$key] = (string) (float) $settings[$key];
		} else {
			$updates[$key] = $settings[$key] ? "true" : "false";
		}
	}

	return array(
		"success" => true,
		"result" => dbUpdateRewardConfig($updates),
	);
}

function cashInOut($customerid, $transaction)
{
	$transaction["customer"] = (int) $customerid;
	if (!$transaction["customer"]) {
		return array(
			"success" => false,
			"result" => "Kein Kunde angegeben.",
		);
	}
	if (!isset($transaction["value"]) || $transaction["value"] === "") {
		return array(
			"success" => false,
			"result" => "Kein Betrag angegeben.",
		);
	}

	$result = dbChashInOutWithDetails($transaction);
	$balance = rewardsAfterManualMovement($result);

	return array(
		"success" => true,
		"result" => $balance,
	);
}

function dailyRewardsByCustomer($customer)
{
	return array(
		"success" => true,
		"result" => rewardsDailyQueueForCustomer($customer),
	);
}

function openRewardForCustomer($customer, $event)
{
	return array(
		"success" => true,
		"result" => dbOpenRewardEvent($customer, $event),
	);
}

function transactionDelete($id)
{
	return array(
		"success" => true,
		"result" => dbTransactionDelete($id),
	);
}

function createCustomer($customer)
{
	if (!isset($customer["username"]) || !$customer["username"] || !dbCheckAvailability($customer["username"])) {
		return array(
			"success" => false,
			"result" => "Benutzername fehlt oder ist bereits vergeben.",
		);
	}

	if (!isset($customer["userpassword"]) || !$customer["userpassword"]) {
		return array(
			"success" => false,
			"result" => "Passwort fehlt.",
		);
	}
	$customer["userpassword"] = password_hash($customer["userpassword"], PASSWORD_DEFAULT);

	if (!isset($customer["fullname"]) || !$customer["fullname"]) {
		return array(
			"success" => false,
			"result" => "Vor- und Nachname fehlen.",
		);
	}

	if (!isset($customer["realm"]) || !$customer["realm"]) {
		return array(
			"success" => false,
			"result" => "Familie fehlt.",
		);
	}

	$result = dbCreateCustomer($customer);
	if (is_numeric($result)) {
		rewardsInitializeCustomer((int) $result);
	}

	return array(
		"success" => is_numeric($result),
		"result" => $result,
		"fullname" => $customer["fullname"],
	);
}

function updateCustomer($id, $realm, $customer)
{
	if (!isset($customer["fullname"]) || trim($customer["fullname"]) === "") {
		return array("success" => false, "result" => "Name fehlt.");
	}
	if (!isset($customer["username"]) || trim($customer["username"]) === "") {
		return array("success" => false, "result" => "Benutzername fehlt.");
	}
	if (!dbCheckUsernameAvailabilityForCustomer($customer["username"], $id)) {
		return array("success" => false, "result" => "Benutzername ist bereits vergeben.");
	}
	$email = isset($customer["email"]) ? trim($customer["email"]) : "";
	if (!dbCheckEmailAvailabilityForCustomer($email, $id)) {
		return array("success" => false, "result" => "Google E-Mail ist bereits vergeben.");
	}

	$result = dbUpdateCustomer($id, $realm, array(
		"fullname" => trim($customer["fullname"]),
		"username" => trim($customer["username"]),
		"email" => $email,
		"display_name" => isset($customer["display_name"]) ? trim($customer["display_name"]) : "",
		"userpassword" => isset($customer["userpassword"]) ? $customer["userpassword"] : "",
	));

	return array(
		"success" => $result !== null,
		"result" => $result !== null ? $result : "Kunde nicht gefunden.",
	);
}

function softDeleteCustomer($id, $realm, $data)
{
	$customer = dbManagedCustomerById($id, $realm);
	if (!$customer) {
		return array("success" => false, "result" => "Kunde nicht gefunden.");
	}
	if (!isset($data["confirm"]) || $data["confirm"] !== $customer["fullname"]) {
		return array("success" => false, "result" => "Bestaetigung stimmt nicht.");
	}

	return array(
		"success" => true,
		"result" => dbSoftDeleteCustomer($id, $realm),
	);
}

function restoreCustomer($id, $realm)
{
	return array(
		"success" => true,
		"result" => dbRestoreCustomer($id, $realm),
	);
}

function authenticateCustomer($auth)
{
	if (!isset($auth["username"]) || !isset($auth["userpassword"])) {
		return array(
			"success" => false,
			"result" => "Benutzername oder Passwort fehlt.",
		);
	}

	$boss = 0;
	if (array_key_exists("boss", $auth) && $auth["boss"]) {
		$boss = 1;
	}

	$customer = dbAuthenticateCustomer($auth["username"], $boss);
	if (($customer && count($customer) > 0) && password_verify($auth["userpassword"], $customer["userpassword"])) {
		$user = authLoginCustomer($customer);

		return array(
			"success" => true,
			"result" => true,
			"customer" => $user["id"],
			"fullname" => $user["fullname"],
			"realm" => $user["realm"],
			"boss" => $user["boss"],
			"user" => authUserForClient($user),
		);
	}

	return array(
		"success" => true,
		"result" => false,
	);
}

function currentAuthState()
{
	$user = authCurrentUser();
	return array(
		"success" => true,
		"result" => $user !== null,
		"user" => authUserForClient($user),
	);
}

function googleAuthenticate($data)
{
	if (!isset($data["credential"]) || !is_string($data["credential"]) || $data["credential"] === "") {
		return authFailure(401, "Google credential is required.", "GOOGLE_CREDENTIAL_REQUIRED");
	}

	try {
		$identity = googleTokenVerifier()->verify($data["credential"]);
		$customer = dbCustomerByGoogleSub($identity["sub"]);

		if (!$customer && $identity["email"] !== "") {
			$customer = dbCustomerByEmail($identity["email"]);
			if ($customer && isset($customer["google_sub"]) && $customer["google_sub"] !== null && $customer["google_sub"] !== "" && $customer["google_sub"] !== $identity["sub"]) {
				return authFailure(401, "Google account does not match the linked customer.", "GOOGLE_ACCOUNT_MISMATCH");
			}
			if ($customer) {
				$customer = dbLinkGoogleIdentity((int) $customer["id"], $identity["sub"], $identity["email"], $identity["name"]);
			}
		}

		if (!$customer) {
			return authFailure(401, "No customer is linked to this verified Google email.", "GOOGLE_ACCOUNT_NOT_LINKED");
		}

		$user = authLoginCustomer($customer);
		return array(
			"success" => true,
			"result" => true,
			"customer" => $user["id"],
			"fullname" => $user["fullname"],
			"realm" => $user["realm"],
			"boss" => $user["boss"],
			"user" => authUserForClient($user),
		);
	} catch (GoogleAuthFailedException $e) {
		return authFailure(401, $e->getMessage(), $e->getErrorCode());
	}
}

function checkCookieForCustomer($data)
{
	$user = authCurrentUser();
	if (!$user) {
		return array(
			"success" => true,
			"result" => false,
		);
	}

	$boss = array_key_exists("boss", $data) && $data["boss"];
	$customer = isset($data["customer"]) ? (int) $data["customer"] : null;

	return array(
		"success" => true,
		"result" => ((int) $user["boss"] >= 1 && $boss) || ((int) $user["id"] === $customer),
	);
}

function closeCookieForCustomer($data)
{
	authLogout();
	return array(
		"success" => true,
		"result" => true,
	);
}

Flight::route("POST /auth/login", function() {
	apiJson(authenticateCustomer(Flight::request()->data->getData()));
});

Flight::route("POST /auth/logout", function() {
	authLogout();
	apiJson(array(
		"success" => true,
		"result" => true,
	));
});

Flight::route("GET /auth/me", function() {
	apiJson(currentAuthState());
});

Flight::route("GET /auth/config", function() {
	apiJson(googleAuthConfig());
});

Flight::route("POST /auth/google", function() {
	$result = googleAuthenticate(Flight::request()->data->getData());
	if ($result !== null) {
		apiJson($result);
	}
});

Flight::route("GET /transactions", function() {
	if (!requireBossUser()) {
		return;
	}
	apiJson(transactionsAll());
});

Flight::route("GET /customers/me/transactions", function() {
	$user = requireCurrentUser();
	if (!$user) {
		return;
	}
	apiJson(transactionsByCustomer($user["id"]));
});

Flight::route("GET /customers/@id:[0-9]+/transactions", function($customer) {
	$user = requireCurrentUser();
	if (!$user) {
		return;
	}
	if ((int) $user["boss"] < 1 && (int) $user["id"] !== (int) $customer) {
		authFailure(403, "Nicht berechtigt.", "CUSTOMER_MISMATCH");
		return;
	}
	apiJson(transactionsByCustomer($customer));
});

Flight::route("GET /customers/me/kpis", function() {
	$user = requireCurrentUser();
	if (!$user) {
		return;
	}
	apiJson(kpisByCustomer($user["id"]));
});

Flight::route("GET /customers/@id:[0-9]+/kpis", function($customer) {
	$user = requireCurrentUser();
	if (!$user) {
		return;
	}
	if ((int) $user["boss"] < 1 && (int) $user["id"] !== (int) $customer) {
		authFailure(403, "Nicht berechtigt.", "CUSTOMER_MISMATCH");
		return;
	}
	apiJson(kpisByCustomer($customer));
});

Flight::route("GET /customers/me/rewards/daily", function() {
	$user = requireCurrentUser();
	if (!$user) {
		return;
	}
	apiJson(dailyRewardsByCustomer($user["id"]));
});

Flight::route("POST /customers/me/rewards/@id:[0-9]+/open", function($id) {
	$user = requireCurrentUser();
	if (!$user) {
		return;
	}
	apiJson(openRewardForCustomer($user["id"], $id));
});

Flight::route("GET /customers", function() {
	$user = requireBossUser();
	if (!$user) {
		return;
	}
	apiJson(customersAll($user["realm"]));
});

Flight::route("GET /boss/overview", function() {
	$user = requireBossUser();
	if (!$user) {
		return;
	}
	apiJson(bossOverview($user["realm"]));
});

Flight::route("GET /boss/rewards", function() {
	$user = requireBossUser();
	if (!$user) {
		return;
	}
	apiJson(bossRewards($user["realm"]));
});

Flight::route("PUT /boss/rewards/config", function() {
	if (!requireBossUser()) {
		return;
	}
	apiJson(updateRewardSettings(Flight::request()->data->getData()));
});

Flight::route("GET /customers/@realm:[0-9]+", function($realm) {
	$user = requireBossUser();
	if (!$user) {
		return;
	}
	apiJson(customersAll($user["realm"]));
});

Flight::route("POST /customers/@id:[0-9]+/cashin", function($id) {
	if (!requireBossUser()) {
		return;
	}
	apiJson(cashInOut($id, Flight::request()->data->getData()));
});

Flight::route("POST /customers/@id:[0-9]+/cashout", function($id) {
	if (!requireBossUser()) {
		return;
	}
	apiJson(cashInOut($id, Flight::request()->data->getData()));
});

Flight::route("DELETE /transactions/@id", function($id) {
	if (!requireBossUser()) {
		return;
	}
	apiJson(transactionDelete($id));
});

Flight::route("POST /customers", function() {
	$user = requireBossUser();
	if (!$user) {
		return;
	}
	$customer = Flight::request()->data->getData();
	$customer["realm"] = $user["realm"];
	apiJson(createCustomer($customer));
});

Flight::route("PUT /customers/@id:[0-9]+", function($id) {
	$user = requireBossUser();
	if (!$user) {
		return;
	}
	apiJson(updateCustomer($id, $user["realm"], Flight::request()->data->getData()));
});

Flight::route("DELETE /customers/@id:[0-9]+", function($id) {
	$user = requireBossUser();
	if (!$user) {
		return;
	}
	apiJson(softDeleteCustomer($id, $user["realm"], Flight::request()->data->getData()));
});

Flight::route("POST /customers/@id:[0-9]+/archive", function($id) {
	$user = requireBossUser();
	if (!$user) {
		return;
	}
	apiJson(softDeleteCustomer($id, $user["realm"], Flight::request()->data->getData()));
});

Flight::route("POST /customers/@id:[0-9]+/restore", function($id) {
	$user = requireBossUser();
	if (!$user) {
		return;
	}
	apiJson(restoreCustomer($id, $user["realm"]));
});

Flight::route("POST /customers/authenticate", function() {
	apiJson(authenticateCustomer(Flight::request()->data->getData()));
});

Flight::route("POST /customers/@id:[0-9]+/lease", function() {
	apiJson(checkCookieForCustomer(Flight::request()->data->getData()));
});

Flight::route("POST /customers/@id:[0-9]+/lease/devalidate", function() {
	apiJson(closeCookieForCustomer(Flight::request()->data->getData()));
});

Flight::start();

?>
