<?php

require_once __DIR__ . "/../config.php";

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
		$balances[$customer] += (float) $current["amount"];
		$transactions[$i]["balance"] = round($balances[$customer], 2);
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
		AND t.approved = 1",
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
		"SELECT c.id, c.fullname, c.email, c.display_name
		FROM customers c
		WHERE c.realm = :realm
		AND c.boss = 0
		ORDER BY c.fullname",
		array("realm" => (int) $realm)
	);
}

/*
**	CASH IN AND OUT STUFF
*/

function dbChashInOut($transaction)
{
	$db = getDB();
	$db->beginTransaction();

	try {
		dbExecute(
			"INSERT INTO transactions (customer, amount, balance, approved, undone)
			VALUES (:customer, :amount, 0, 1, 0)",
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
		return $balance;
	} catch (Exception $e) {
		$db->rollBack();
		throw $e;
	}
}

function dbTransactionDelete($id)
{
	$transaction = dbFetchOne(
		"SELECT customer FROM transactions WHERE id = :id",
		array("id" => (int) $id)
	);

	$stmt = dbExecute(
		"UPDATE transactions SET undone = 1 WHERE id = :id",
		array("id" => (int) $id)
	);

	if ($transaction) {
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

	$balance = 0;
	foreach ($transactions as $transaction) {
		$balance = round($balance + (float) $transaction["amount"], 2);
		dbExecute(
			"UPDATE transactions SET balance = :balance WHERE id = :id",
			array(
				"balance" => $balance,
				"id" => (int) $transaction["id"],
			)
		);
	}

	return $balance;
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
		WHERE c.google_sub = :google_sub",
		array("google_sub" => $googleSub)
	);
}

function dbCustomerByEmail($email)
{
	return dbFetchOne(
		"SELECT c.id, c.fullname, c.username, c.userpassword, c.google_sub, c.email, c.display_name, c.boss, c.realm
		FROM customers c
		WHERE c.email = :email",
		array("email" => $email)
	);
}

function dbCustomerById($id)
{
	return dbFetchOne(
		"SELECT c.id, c.fullname, c.username, c.userpassword, c.google_sub, c.email, c.display_name, c.boss, c.realm
		FROM customers c
		WHERE c.id = :id",
		array("id" => (int) $id)
	);
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

?>
