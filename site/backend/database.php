<?php

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/monthly_interest.php";

function getDB()
{
	static $db = null;

	if ($db instanceof PDO) {
		return $db;
	}

	$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
	$db = new PDO($dsn, DB_USER, DB_PASSWORD, array(
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
	));
	$db->exec("SET time_zone = '+00:00'");

	return $db;
}

function dbFetchAll($query, $params = array())
{
	$stmt = getDB()->prepare($query);
	$stmt->execute($params);
	return $stmt->fetchAll();
}

function dbFetchOne($query, $params = array())
{
	$stmt = getDB()->prepare($query);
	$stmt->execute($params);
	$row = $stmt->fetch();
	return $row === false ? null : $row;
}

function dbExecute($query, $params = array())
{
	$stmt = getDB()->prepare($query);
	$stmt->execute($params);
	return $stmt;
}

/*
**	LISTING STUFF
*/

function dbTransactionsAll()
{
	$result = dbFetchAll(
		"SELECT t.id, t.customer, c.fullname, t.datetime, t.amount, t.balance, t.approved
		FROM transactions t, customers c
		WHERE t.customer = c.id
		AND c.deleted_at IS NULL
		AND t.undone = 0
		AND t.approved = 1
		ORDER BY t.customer ASC, t.datetime ASC, t.id ASC"
	);

	return dbWithRunningBalances($result);
}

function dbWithRunningBalances($transactions)
{
	$balances = array();

	foreach ($transactions as $i => $current) {
		$customer = isset($current["customer"]) ? (int) $current["customer"] : (isset($current["fullname"]) ? $current["fullname"] : 0);
		if (!isset($balances[$customer])) {
			$balances[$customer] = 0;
		}
		$balances[$customer] += MonthlyInterestMoney::toCents($current["amount"]);
		$transactions[$i]["balance"] = (float) MonthlyInterestMoney::fromCents($balances[$customer]);
	}

	usort($transactions, function($a, $b) {
		$timeCompare = strcmp($b["datetime"], $a["datetime"]);
		if ($timeCompare !== 0) {
			return $timeCompare;
		}
		return (int) $b["id"] <=> (int) $a["id"];
	});

	return $transactions;
}

function dbTransactionsByCustomer($customer)
{
	$result = dbFetchAll(
		"SELECT t.id, t.customer, c.fullname, t.datetime, t.amount, t.balance, t.approved
		FROM transactions t, customers c
		WHERE t.customer = c.id
		AND c.deleted_at IS NULL
		AND t.undone = 0
		AND t.approved = 1
		AND c.id = :customer
		ORDER BY t.datetime ASC, t.id ASC",
		array("customer" => (int) $customer)
	);

	return dbWithRunningBalances($result);
}

function dbBalanceByCustomer($customer)
{
	$row = dbFetchOne(
		"SELECT sum(amount) AS balance
		FROM transactions t
		WHERE t.customer = :customer
		AND t.undone = 0
		AND t.approved = 1",
		array("customer" => (int) $customer)
	);

	if (!$row || $row["balance"] === null) {
		return 0;
	}

	return round($row["balance"], 2);
}

function dbNofInAndOut($customer)
{
	$row = dbFetchOne(
		"SELECT
			SUM(CASE WHEN t.amount >= 0 THEN 1 ELSE 0 END) AS nofin,
			SUM(CASE WHEN t.amount < 0 THEN 1 ELSE 0 END) AS nofout
		FROM transactions t
		WHERE t.customer = :customer
		AND t.undone = 0
		AND t.approved = 1
		AND t.kind = 'manual'",
		array("customer" => (int) $customer)
	);

	return array(
		"nofin" => $row["nofin"] ?: 0,
		"nofout" => $row["nofout"] ?: 0,
	);
}

function dbCustomersAll($realm)
{
	return dbFetchAll(
		"SELECT c.id, c.fullname, c.username, c.email, c.display_name,
			COALESCE(SUM(CASE WHEN t.undone = 0 AND t.approved = 1 THEN t.amount ELSE 0 END), 0) AS balance
		FROM customers c
		LEFT JOIN transactions t ON t.customer = c.id
		WHERE c.realm = :realm
		AND c.boss = 0
		AND c.deleted_at IS NULL
		GROUP BY c.id, c.fullname, c.username, c.email, c.display_name
		ORDER BY c.fullname",
		array("realm" => (int) $realm)
	);
}

function dbBossOverview($realm)
{
	$customers = dbFetchAll(
		"SELECT c.id, c.fullname, c.username, c.email, c.display_name, c.deleted_at,
			COALESCE(SUM(CASE WHEN t.undone = 0 AND t.approved = 1 THEN t.amount ELSE 0 END), 0) AS balance,
			COALESCE(SUM(CASE WHEN t.undone = 0 AND t.approved = 1 AND t.kind = 'manual' AND t.amount >= 0 THEN 1 ELSE 0 END), 0) AS nofin,
			COALESCE(SUM(CASE WHEN t.undone = 0 AND t.approved = 1 AND t.kind = 'manual' AND t.amount < 0 THEN 1 ELSE 0 END), 0) AS nofout,
			COALESCE(open_rewards.open_count, 0) AS unopened_rewards
		FROM customers c
		LEFT JOIN transactions t ON t.customer = c.id
		LEFT JOIN (
			SELECT customer, COUNT(*) AS open_count
			FROM reward_events
			WHERE opened_at IS NULL
			GROUP BY customer
		) open_rewards ON open_rewards.customer = c.id
		WHERE c.realm = :realm
		AND c.boss = 0
		GROUP BY c.id, c.fullname, c.username, c.email, c.display_name, c.deleted_at, open_rewards.open_count
		ORDER BY c.deleted_at IS NULL DESC, c.fullname",
		array("realm" => (int) $realm)
	);

	$active = array_values(array_filter($customers, function($customer) {
		return $customer["deleted_at"] === null;
	}));
	$totalAssets = 0;
	$totalIn = 0;
	$totalOut = 0;
	$totalUnopened = 0;
	foreach ($active as $customer) {
		$totalAssets += (float) $customer["balance"];
		$totalIn += (int) $customer["nofin"];
		$totalOut += (int) $customer["nofout"];
		$totalUnopened += (int) $customer["unopened_rewards"];
	}

	return array(
		"metrics" => array(
			"active_customers" => count($active),
			"total_assets" => round($totalAssets, 2),
			"manual_in" => $totalIn,
			"manual_out" => $totalOut,
			"unopened_rewards" => $totalUnopened,
		),
		"customers" => $customers,
	);
}

/*
**	CASH IN AND OUT STUFF
*/

function dbChashInOut($transaction)
{
	$result = dbChashInOutWithDetails($transaction);
	return $result["balance_after"];
}

function dbChashInOutWithDetails($transaction)
{
	$db = getDB();
	$db->beginTransaction();

	try {
		$balanceBefore = dbBalanceByCustomer((int) $transaction["customer"]);
		dbExecute(
			"INSERT INTO transactions (customer, amount, balance, kind, approved, undone)
			VALUES (:customer, :amount, 0, 'manual', 1, 0)",
			array(
				"customer" => (int) $transaction["customer"],
				"amount" => (float) $transaction["value"],
			)
		);
		$newtransaction = (int) $db->lastInsertId();

		$balance = dbRecalculateBalancesForCustomer((int) $transaction["customer"]);

		dbExecute(
			"UPDATE transactions SET balance = :balance WHERE id = :id",
			array(
				"balance" => $balance,
				"id" => $newtransaction,
			)
		);

		$db->commit();
		return array(
			"id" => $newtransaction,
			"customer" => (int) $transaction["customer"],
			"amount" => (float) $transaction["value"],
			"balance_before" => $balanceBefore,
			"balance_after" => $balance,
		);
	} catch (Exception $e) {
		$db->rollBack();
		throw $e;
	}
}

function dbInsertSystemTransaction($customer, $amount, $kind, $note)
{
	dbExecute(
		"INSERT INTO transactions (customer, amount, balance, kind, note, approved, undone)
		VALUES (:customer, :amount, 0, :kind, :note, 1, 0)",
		array(
			"customer" => (int) $customer,
			"amount" => (float) $amount,
			"kind" => $kind,
			"note" => $note,
		)
	);
	$id = (int) getDB()->lastInsertId();
	$balance = dbRecalculateBalancesForCustomer((int) $customer);

	return array(
		"id" => $id,
		"balance" => $balance,
	);
}

function dbTransactionDelete($id)
{
	$transaction = dbFetchOne(
		"SELECT customer, kind FROM transactions WHERE id = :id",
		array("id" => (int) $id)
	);
	if (!$transaction || $transaction["kind"] === "monthly_interest") {
		return 0;
	}

	$stmt = dbExecute(
		"UPDATE transactions
		SET undone = 1
		WHERE id = :id
		AND kind <> 'monthly_interest'",
		array("id" => (int) $id)
	);

	if ($stmt->rowCount() > 0) {
		dbRecalculateBalancesForCustomer((int) $transaction["customer"]);
	}

	return $stmt->rowCount();
}

function dbRecalculateBalancesForCustomer($customer)
{
	$transactions = dbFetchAll(
		"SELECT id, amount
		FROM transactions
		WHERE customer = :customer
		AND undone = 0
		AND approved = 1
		ORDER BY datetime ASC, id ASC",
		array("customer" => (int) $customer)
	);

	$balanceCents = 0;
	foreach ($transactions as $transaction) {
		$balanceCents += MonthlyInterestMoney::toCents($transaction["amount"]);
		$balance = MonthlyInterestMoney::fromCents($balanceCents);
		dbExecute(
			"UPDATE transactions SET balance = :balance WHERE id = :id",
			array(
				"balance" => $balance,
				"id" => (int) $transaction["id"],
			)
		);
	}

	return (float) MonthlyInterestMoney::fromCents($balanceCents);
}

/*
**	AUTHENTICATION STUFF
*/

function dbCreateCustomer($customer)
{
	dbExecute(
		"INSERT INTO customers (fullname, username, userpassword, email, display_name, realm)
		VALUES (:fullname, :username, :userpassword, :email, :display_name, :realm)",
		array(
			"fullname" => $customer["fullname"],
			"username" => $customer["username"],
			"userpassword" => $customer["userpassword"],
			"email" => isset($customer["email"]) && $customer["email"] !== "" ? $customer["email"] : null,
			"display_name" => isset($customer["display_name"]) && $customer["display_name"] !== "" ? $customer["display_name"] : null,
			"realm" => (int) $customer["realm"],
		)
	);

	return (int) getDB()->lastInsertId();
}

function dbAuthenticateCustomer($username, $boss)
{
	return dbFetchOne(
		"SELECT c.id, c.fullname, c.username, c.userpassword, c.google_sub, c.email, c.display_name, c.boss, c.realm
		FROM customers c
		WHERE c.username = :username
		AND c.deleted_at IS NULL
		AND c.boss >= :boss",
		array(
			"username" => $username,
			"boss" => (int) $boss,
		)
	);
}

function dbCustomerByGoogleSub($googleSub)
{
	return dbFetchOne(
		"SELECT c.id, c.fullname, c.username, c.userpassword, c.google_sub, c.email, c.display_name, c.boss, c.realm
		FROM customers c
		WHERE c.google_sub = :google_sub
		AND c.deleted_at IS NULL",
		array("google_sub" => $googleSub)
	);
}

function dbCustomerByEmail($email)
{
	return dbFetchOne(
		"SELECT c.id, c.fullname, c.username, c.userpassword, c.google_sub, c.email, c.display_name, c.boss, c.realm
		FROM customers c
		WHERE c.email = :email
		AND c.deleted_at IS NULL",
		array("email" => $email)
	);
}

function dbCustomerById($id)
{
	return dbFetchOne(
		"SELECT c.id, c.fullname, c.username, c.userpassword, c.google_sub, c.email, c.display_name, c.boss, c.realm
		FROM customers c
		WHERE c.id = :id
		AND c.deleted_at IS NULL",
		array("id" => (int) $id)
	);
}

function dbManagedCustomerById($id, $realm)
{
	return dbFetchOne(
		"SELECT c.id, c.fullname, c.username, c.email, c.display_name, c.boss, c.realm, c.deleted_at
		FROM customers c
		WHERE c.id = :id
		AND c.realm = :realm
		AND c.boss = 0",
		array(
			"id" => (int) $id,
			"realm" => (int) $realm,
		)
	);
}

function dbCheckUsernameAvailabilityForCustomer($username, $customer)
{
	$row = dbFetchOne(
		"SELECT count(*) as unavailable
		FROM customers c
		WHERE c.username = :username
		AND c.id <> :customer",
		array(
			"username" => $username,
			"customer" => (int) $customer,
		)
	);

	return $row["unavailable"] == 0;
}

function dbCheckEmailAvailabilityForCustomer($email, $customer)
{
	if ($email === null || $email === "") {
		return true;
	}

	$row = dbFetchOne(
		"SELECT count(*) as unavailable
		FROM customers c
		WHERE c.email = :email
		AND c.id <> :customer",
		array(
			"email" => $email,
			"customer" => (int) $customer,
		)
	);

	return $row["unavailable"] == 0;
}

function dbUpdateCustomer($id, $realm, $customer)
{
	$current = dbManagedCustomerById($id, $realm);
	if (!$current || $current["deleted_at"] !== null) {
		return null;
	}

	$fields = array(
		"fullname = :fullname",
		"username = :username",
		"email = :email",
		"display_name = :display_name",
	);
	$params = array(
		"id" => (int) $id,
		"realm" => (int) $realm,
		"fullname" => $customer["fullname"],
		"username" => $customer["username"],
		"email" => isset($customer["email"]) && $customer["email"] !== "" ? $customer["email"] : null,
		"display_name" => isset($customer["display_name"]) && $customer["display_name"] !== "" ? $customer["display_name"] : null,
	);

	if (isset($customer["userpassword"]) && $customer["userpassword"] !== "") {
		$fields[] = "userpassword = :userpassword";
		$params["userpassword"] = password_hash($customer["userpassword"], PASSWORD_DEFAULT);
	}

	dbExecute(
		"UPDATE customers
		SET " . implode(", ", $fields) . "
		WHERE id = :id
		AND realm = :realm
		AND boss = 0",
		$params
	);

	return dbManagedCustomerById($id, $realm);
}

function dbSoftDeleteCustomer($id, $realm, ?DateTimeImmutable $asOf = null)
{
	$db = getDB();
	$db->beginTransaction();
	try {
		$stmt = dbExecute(
			"UPDATE customers
			SET deleted_at = CURRENT_TIMESTAMP
			WHERE id = :id
			AND realm = :realm
			AND boss = 0
			AND deleted_at IS NULL",
			array(
				"id" => (int) $id,
				"realm" => (int) $realm,
			)
		);
		if ($stmt->rowCount() > 0) {
			dbCloseInterestEligibility((int) $id, dbCurrentInterestPeriodStart($asOf));
		}
		$db->commit();
		return $stmt->rowCount() > 0;
	} catch (Exception $e) {
		$db->rollBack();
		throw $e;
	}
}

function dbRestoreCustomer($id, $realm, ?DateTimeImmutable $asOf = null)
{
	$db = getDB();
	$db->beginTransaction();
	try {
		$stmt = dbExecute(
			"UPDATE customers
			SET deleted_at = NULL
			WHERE id = :id
			AND realm = :realm
			AND boss = 0
			AND deleted_at IS NOT NULL",
			array(
				"id" => (int) $id,
				"realm" => (int) $realm,
			)
		);
		if ($stmt->rowCount() > 0) {
			dbOpenInterestEligibility((int) $id, dbCurrentInterestPeriodStart($asOf));
		}
		$db->commit();
		return $stmt->rowCount() > 0;
	} catch (Exception $e) {
		$db->rollBack();
		throw $e;
	}
}

function dbLinkGoogleIdentity($id, $googleSub, $email, $displayName)
{
	dbExecute(
		"UPDATE customers
		SET google_sub = :google_sub,
			email = :email,
			display_name = :display_name
		WHERE id = :id",
		array(
			"id" => (int) $id,
			"google_sub" => $googleSub,
			"email" => $email,
			"display_name" => $displayName !== "" ? $displayName : null,
		)
	);

	return dbCustomerById($id);
}

function dbStoreCookieForCustomer($id, $cookie)
{
	$db = getDB();
	$db->beginTransaction();

	try {
		if (dbCheckValidLeaseForCustomer($id)) {
			dbExecute(
				"UPDATE leases l
				SET l.valid = 0
				WHERE l.customer = :customer
				AND l.valid = 1",
				array("customer" => (int) $id)
			);
		}

		dbExecute(
			"INSERT INTO leases (customer, lease, valid)
			VALUES (:customer, :lease, 1)",
			array(
				"customer" => (int) $id,
				"lease" => $cookie,
			)
		);

		$resultforclient = (int) $db->lastInsertId();
		$db->commit();
		return $resultforclient;
	} catch (Exception $e) {
		$db->rollBack();
		throw $e;
	}
}

function dbCloseCookieForCustomer($id, $cookie)
{
	if (dbCheckValidLeaseForCustomer($id)) {
		dbExecute(
			"UPDATE leases l
			SET l.valid = 0
			WHERE l.customer = :customer
			AND l.lease = :lease
			AND l.valid = 1",
			array(
				"customer" => (int) $id,
				"lease" => $cookie,
			)
		);

		return true;
	}

	return false;
}

function dbCheckValidLeaseForCustomer($id)
{
	$row = dbFetchOne(
		"SELECT count(*) as open
		FROM leases l
		WHERE l.customer = :customer
		AND l.valid = 1",
		array("customer" => (int) $id)
	);

	return $row["open"] > 0;
}

function dbCheckCookieForCustomer($id, $cookie, $boss)
{
	$timestamp_allowed = round(microtime(true) * 1000) - (10 * 60 * 1000);

	$row = dbFetchOne(
		"SELECT count(*) as valid
		FROM leases l, customers c
		WHERE c.id = :customer
		AND c.boss >= :boss
		AND c.id = l.customer
		AND l.lease = :lease
		AND l.datetime > :timestamp_allowed
		AND l.valid = 1",
		array(
			"customer" => (int) $id,
			"boss" => (int) $boss,
			"lease" => $cookie,
			"timestamp_allowed" => $timestamp_allowed,
		)
	);

	return $row["valid"] == 1;
}

function dbCheckAvailability($username)
{
	$row = dbFetchOne(
		"SELECT count(*) as unavailable
		FROM customers c
		WHERE c.username = :username",
		array("username" => $username)
	);

	return $row["unavailable"] == 0;
}

function dbRewardConfigValue($key)
{
	try {
		$row = dbFetchOne(
			"SELECT config_value
			FROM reward_config
			WHERE config_key = :config_key",
			array("config_key" => $key)
		);
		return $row ? $row["config_value"] : null;
	} catch (Exception $e) {
		return null;
	}
}

function dbRewardConfigAll()
{
	return dbFetchAll(
		"SELECT config_key, config_value, value_type, label, description, updated_at
		FROM reward_config
		ORDER BY config_key"
	);
}

function dbUpdateRewardConfig($configs, ?DateTimeImmutable $asOf = null)
{
	$db = getDB();
	$db->beginTransaction();
	try {
		foreach ($configs as $key => $value) {
			dbExecute(
				"UPDATE reward_config
				SET config_value = :config_value
				WHERE config_key = :config_key",
				array(
					"config_key" => $key,
					"config_value" => (string) $value,
				)
			);
		}

		if (
			array_key_exists("monthly_interest_rate", $configs)
			|| array_key_exists("reward_monthly_interest_enabled", $configs)
		) {
			$asOf = $asOf ?: new DateTimeImmutable("now");
			$nextPeriod = MonthlyInterestPeriod::containing($asOf)->next()->key() . "-01";
			$enabledValue = array_key_exists("reward_monthly_interest_enabled", $configs)
				? $configs["reward_monthly_interest_enabled"]
				: dbRewardConfigValue("reward_monthly_interest_enabled");
			$enabled = in_array(strtolower((string) $enabledValue), array("1", "true", "yes", "on"), true);
			$rate = array_key_exists("monthly_interest_rate", $configs)
				? $configs["monthly_interest_rate"]
				: dbRewardConfigValue("monthly_interest_rate");
			dbScheduleMonthlyInterestRate($nextPeriod, $enabled ? $rate : "0");
		}

		$result = dbRewardConfigAll();
		$db->commit();
		return $result;
	} catch (Exception $e) {
		$db->rollBack();
		throw $e;
	}
}

function dbCurrentInterestPeriodStart(?DateTimeImmutable $asOf = null)
{
	$asOf = $asOf ?: new DateTimeImmutable("now");
	return MonthlyInterestPeriod::containing($asOf)->key() . "-01";
}

function dbFirstConfiguredInterestPeriod()
{
	$row = dbFetchOne("SELECT MIN(effective_period) AS first_period FROM monthly_interest_rates");
	return $row && $row["first_period"] !== null ? $row["first_period"] : null;
}

function dbNormalizeEligibilityStart($startPeriod)
{
	$period = MonthlyInterestPeriod::fromKey(substr((string) $startPeriod, 0, 7))->key() . "-01";
	$firstConfigured = dbFirstConfiguredInterestPeriod();
	if ($firstConfigured !== null && strcmp($period, $firstConfigured) < 0) {
		return $firstConfigured;
	}
	return $period;
}

function dbOpenInterestEligibility($customer, $startPeriod)
{
	$customer = (int) $customer;
	$startPeriod = dbNormalizeEligibilityStart($startPeriod);
	$open = dbFetchOne(
		"SELECT id
		FROM customer_interest_eligibility
		WHERE customer = :customer
		AND end_period IS NULL
		ORDER BY start_period DESC
		LIMIT 1",
		array("customer" => $customer)
	);
	if ($open) {
		return (int) $open["id"];
	}

	dbExecute(
		"INSERT INTO customer_interest_eligibility (customer, start_period, end_period)
		VALUES (:customer, :start_period, NULL)
		ON DUPLICATE KEY UPDATE end_period = NULL",
		array(
			"customer" => $customer,
			"start_period" => $startPeriod,
		)
	);

	$row = dbFetchOne(
		"SELECT id
		FROM customer_interest_eligibility
		WHERE customer = :customer
		AND start_period = :start_period",
		array(
			"customer" => $customer,
			"start_period" => $startPeriod,
		)
	);
	return $row ? (int) $row["id"] : null;
}

function dbCloseInterestEligibility($customer, $endPeriod)
{
	$customer = (int) $customer;
	$endPeriod = MonthlyInterestPeriod::fromKey(substr((string) $endPeriod, 0, 7))->key() . "-01";
	$open = dbFetchOne(
		"SELECT id, start_period
		FROM customer_interest_eligibility
		WHERE customer = :customer
		AND end_period IS NULL
		ORDER BY start_period DESC
		LIMIT 1",
		array("customer" => $customer)
	);
	if (!$open) {
		return false;
	}

	if (strcmp($open["start_period"], $endPeriod) >= 0) {
		dbExecute(
			"DELETE FROM customer_interest_eligibility WHERE id = :id",
			array("id" => (int) $open["id"])
		);
		return true;
	}

	dbExecute(
		"UPDATE customer_interest_eligibility
		SET end_period = :end_period
		WHERE id = :id",
		array(
			"id" => (int) $open["id"],
			"end_period" => $endPeriod,
		)
	);
	return true;
}

function dbScheduleMonthlyInterestRate($effectivePeriod, $rate)
{
	$effectivePeriod = MonthlyInterestPeriod::fromKey(substr((string) $effectivePeriod, 0, 7))->key() . "-01";
	$rate = MonthlyInterestMoney::normalizedRate($rate);
	dbExecute(
		"INSERT INTO monthly_interest_rates (effective_period, rate)
		VALUES (:effective_period, :rate)
		ON DUPLICATE KEY UPDATE rate = VALUES(rate)",
		array(
			"effective_period" => $effectivePeriod,
			"rate" => $rate,
		)
	);
	return dbMonthlyInterestRateForPeriod(substr($effectivePeriod, 0, 7));
}

function dbMonthlyInterestRates()
{
	return dbFetchAll(
		"SELECT effective_period, rate, created_at
		FROM monthly_interest_rates
		ORDER BY effective_period"
	);
}

function dbMonthlyInterestRateForPeriod($period)
{
	$period = MonthlyInterestPeriod::fromKey(substr((string) $period, 0, 7))->key() . "-01";
	return dbFetchOne(
		"SELECT effective_period, rate
		FROM monthly_interest_rates
		WHERE effective_period <= :period
		ORDER BY effective_period DESC
		LIMIT 1",
		array("period" => $period)
	);
}

function dbCustomerEligibleForMonthlyInterestPeriod($customer, $period)
{
	$period = MonthlyInterestPeriod::fromKey(substr((string) $period, 0, 7))->key() . "-01";
	$row = dbFetchOne(
		"SELECT id
		FROM customer_interest_eligibility
		WHERE customer = :customer
		AND start_period <= :start_period
		AND (end_period IS NULL OR end_period > :end_period)
		ORDER BY start_period DESC
		LIMIT 1",
		array(
			"customer" => (int) $customer,
			"start_period" => $period,
			"end_period" => $period,
		)
	);
	return $row !== null;
}

function dbMonthlyInterestProjectionForCustomer($customer, $balance, MonthlyInterestClock $clock)
{
	$now = $clock->now();
	if (!($now instanceof DateTimeImmutable)) {
		throw new MonthlyInterestDomainException("Monthly interest clock must return DateTimeImmutable.");
	}

	$period = MonthlyInterestPeriod::containing($now);
	$base = array(
		"enabled" => false,
		"status" => "unavailable",
		"period" => $period->key(),
		"balance_basis_estimate" => MonthlyInterestMoney::fromCents(MonthlyInterestMoney::toCents($balance)),
		"rate" => null,
		"rate_percent" => null,
		"estimated_amount" => null,
		"posting_date" => $period->postingDate(),
		"timezone" => MonthlyInterestPeriod::BUSINESS_TIMEZONE,
		"is_estimate" => true,
	);

	if (!dbCustomerEligibleForMonthlyInterestPeriod($customer, $period->key())) {
		$base["status"] = "not_eligible";
		return $base;
	}

	$rateRow = dbMonthlyInterestRateForPeriod($period->key());
	if (!$rateRow) {
		return $base;
	}

	$enabled = MonthlyInterestMoney::normalizedRate($rateRow["rate"]) !== "0";
	$projection = MonthlyInterestProjection::build($balance, $rateRow["rate"], $clock, $enabled);
	$projection["status"] = $enabled ? "active" : "disabled";
	return $projection;
}

function dbRewardOverview($realm)
{
	return array(
		"config" => dbRewardConfigAll(),
		"unopened" => dbFetchAll(
			"SELECT c.id AS customer, c.fullname, COUNT(r.id) AS unopened_rewards,
				COALESCE(SUM(r.amount), 0) AS unopened_amount,
				MAX(r.earned_at) AS latest_reward_at
			FROM customers c
			LEFT JOIN reward_events r ON r.customer = c.id AND r.opened_at IS NULL
			WHERE c.realm = :realm
			AND c.boss = 0
			AND c.deleted_at IS NULL
			GROUP BY c.id, c.fullname
			ORDER BY unopened_rewards DESC, c.fullname",
			array("realm" => (int) $realm)
		),
		"recent" => dbFetchAll(
			"SELECT r.id, r.customer, c.fullname, r.reward_key, r.reward_type, r.chest_variant,
				r.title, r.amount, r.balance_after, r.earned_at, r.opened_at
			FROM reward_events r
			INNER JOIN customers c ON c.id = r.customer
			WHERE c.realm = :realm
			AND c.boss = 0
			ORDER BY r.earned_at DESC, r.id DESC
			LIMIT 50",
			array("realm" => (int) $realm)
		),
	);
}

/*
**	REWARD STUFF
*/

function dbRewardState($customer, $key, $default = null)
{
	$row = dbFetchOne(
		"SELECT state_value
		FROM customer_reward_state
		WHERE customer = :customer
		AND state_key = :state_key",
		array(
			"customer" => (int) $customer,
			"state_key" => $key,
		)
	);

	return $row ? $row["state_value"] : $default;
}

function dbSetRewardState($customer, $key, $value)
{
	dbExecute(
		"INSERT INTO customer_reward_state (customer, state_key, state_value)
		VALUES (:customer, :state_key, :state_value)
		ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)",
		array(
			"customer" => (int) $customer,
			"state_key" => $key,
			"state_value" => (string) $value,
		)
	);
}

function dbInsertRewardEvent($event)
{
	dbExecute(
		"INSERT INTO reward_events (
			customer, reward_key, reward_type, chest_variant, title, description,
			trigger_value, interest_rate, amount, balance_before, balance_after, transaction_id
		) VALUES (
			:customer, :reward_key, :reward_type, :chest_variant, :title, :description,
			:trigger_value, :interest_rate, :amount, :balance_before, :balance_after, :transaction_id
		)",
		array(
			"customer" => (int) $event["customer"],
			"reward_key" => $event["reward_key"],
			"reward_type" => $event["reward_type"],
			"chest_variant" => $event["chest_variant"],
			"title" => $event["title"],
			"description" => $event["description"],
			"trigger_value" => isset($event["trigger_value"]) ? $event["trigger_value"] : null,
			"interest_rate" => isset($event["interest_rate"]) ? $event["interest_rate"] : null,
			"amount" => (float) $event["amount"],
			"balance_before" => (float) $event["balance_before"],
			"balance_after" => (float) $event["balance_after"],
			"transaction_id" => isset($event["transaction_id"]) ? $event["transaction_id"] : null,
		)
	);

	return (int) getDB()->lastInsertId();
}

function dbUnopenedRewardEvents($customer)
{
	return dbFetchAll(
		"SELECT id, reward_key, reward_type, chest_variant, title, description,
			trigger_value, interest_rate, amount, balance_before, balance_after, earned_at
		FROM reward_events
		WHERE customer = :customer
		AND opened_at IS NULL
		ORDER BY earned_at ASC, id ASC",
		array("customer" => (int) $customer)
	);
}

function dbOpenRewardEvent($customer, $event)
{
	$stmt = dbExecute(
		"UPDATE reward_events
		SET opened_at = CURRENT_TIMESTAMP
		WHERE id = :id
		AND customer = :customer
		AND opened_at IS NULL",
		array(
			"id" => (int) $event,
			"customer" => (int) $customer,
		)
	);

	return $stmt->rowCount() > 0;
}

?>
