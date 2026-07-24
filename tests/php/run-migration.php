<?php

$selectedEnvFile = getenv("BABABANK_ENV_FILE") ?: ".env.test";
putenv("BABABANK_ENV_FILE=" . $selectedEnvFile);

require_once __DIR__ . "/TestRunner.php";
require_once __DIR__ . "/../../site/config.php";

function migrationTestSql(PDO $db, $path)
{
	$sql = file_get_contents($path);
	if ($sql === false) {
		throw new RuntimeException("Unable to read SQL file: " . $path);
	}
	$sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
	$db->exec($sql);
}

function migrationTestFetchOne(PDO $db, $sql)
{
	$row = $db->query($sql)->fetch();
	return $row === false ? null : $row;
}

function migrationTestMoneySnapshot(PDO $db)
{
	$snapshot = array();
	$tables = array(
		"transactions" => array("amount", "balance"),
		"reward_events" => array("amount", "balance_before", "balance_after"),
	);

	foreach ($tables as $table => $columns) {
		$rows = $db->query(
			"SELECT id, " . implode(", ", $columns) . " FROM " . $table . " ORDER BY id"
		)->fetchAll();
		$snapshot[$table] = array_map(function($row) use ($columns) {
			$normalized = array("id" => (int) $row["id"]);
			foreach ($columns as $column) {
				$normalized[$column] = number_format((float) $row[$column], 2, ".", "");
			}
			return $normalized;
		}, $rows);
	}

	return $snapshot;
}

if (!preg_match('/^[A-Za-z0-9_]+$/', DB_NAME) || !preg_match('/(^test_|_test$)/i', DB_NAME)) {
	fwrite(STDERR, "Configured database is not safely test-scoped." . PHP_EOL);
	exit(1);
}

$migrationDatabase = DB_NAME . "_migration";
$liveMigrationDatabase = DB_NAME . "_live_migration";
$quotedMigrationDatabase = "`" . $migrationDatabase . "`";
$quotedLiveMigrationDatabase = "`" . $liveMigrationDatabase . "`";
$options = array(
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	PDO::ATTR_EMULATE_PREPARES => false,
);
$server = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASSWORD, $options);
$server->exec("DROP DATABASE IF EXISTS " . $quotedMigrationDatabase);
$server->exec("DROP DATABASE IF EXISTS " . $quotedLiveMigrationDatabase);
$server->exec("CREATE DATABASE " . $quotedMigrationDatabase . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server->exec("CREATE DATABASE " . $quotedLiveMigrationDatabase . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$db = new PDO(
	"mysql:host=" . DB_HOST . ";dbname=" . $migrationDatabase . ";charset=utf8mb4",
	DB_USER,
	DB_PASSWORD,
	$options
);
$liveDb = new PDO(
	"mysql:host=" . DB_HOST . ";dbname=" . $liveMigrationDatabase . ";charset=utf8mb4",
	DB_USER,
	DB_PASSWORD,
	$options
);

try {
	migrationTestSql($db, __DIR__ . "/../fixtures/schema-before-monthly-interest.sql");
	$db->exec(
		"INSERT INTO customers (id, fullname, username, userpassword, boss, realm, deleted_at) VALUES
		(1, 'Migration Active', 'migration_active', 'hash', 0, 991001, NULL),
		(2, 'Migration Archived', 'migration_archived', 'hash', 0, 991001, '2026-07-01 00:00:00'),
		(3, 'Migration Boss', 'migration_boss', 'hash', 1, 991001, NULL)"
	);
	$db->exec(
		"INSERT INTO transactions (customer, datetime, amount, balance, kind, approved, undone) VALUES
		(1, '2026-06-01 10:00:00', 100.10, 100.10, 'manual', 1, 0),
		(1, '2026-06-02 10:00:00', 0.20, 100.30, 'manual', 1, 0),
		(1, '2026-06-03 10:00:00', -25.05, 75.25, 'manual', 1, 0),
		(2, '2026-06-01 10:00:00', 50.00, 50.00, 'manual', 1, 0)"
	);
	$db->exec(
		"INSERT INTO reward_config (config_key, config_value, value_type, label, description) VALUES
		('monthly_interest_rate', '0.0008', 'decimal', 'Monatszins', 'Legacy lazy interest')"
	);
	$db->exec(
		"INSERT INTO customer_reward_state (customer, state_key, state_value) VALUES
		(1, 'monthly_interest_period', '2026-07')"
	);

	$balanceBefore = migrationTestFetchOne(
		$db,
		"SELECT ROUND(SUM(amount), 2) AS balance FROM transactions WHERE customer = 1 AND approved = 1 AND undone = 0"
	)["balance"];

	migrationTestSql($db, __DIR__ . "/../../database/migrations/20260724_001_add_monthly_interest_postings.sql");

	migrationTestSql($liveDb, __DIR__ . "/../../database/e93ud_bank.sql");
	$liveBefore = array(
		"customers" => (int) migrationTestFetchOne($liveDb, "SELECT COUNT(*) AS total FROM customers")["total"],
		"transactions" => (int) migrationTestFetchOne($liveDb, "SELECT COUNT(*) AS total FROM transactions")["total"],
		"reward_events" => (int) migrationTestFetchOne($liveDb, "SELECT COUNT(*) AS total FROM reward_events")["total"],
		"eligible" => (int) migrationTestFetchOne(
			$liveDb,
			"SELECT COUNT(*) AS total FROM customers WHERE boss = 0 AND deleted_at IS NULL"
		)["total"],
		"legacy_state" => (int) migrationTestFetchOne(
			$liveDb,
			"SELECT COUNT(*) AS total
			FROM customer_reward_state
			WHERE state_key = 'monthly_interest_period'"
		)["total"],
		"rate" => migrationTestFetchOne(
			$liveDb,
			"SELECT config_value FROM reward_config WHERE config_key = 'monthly_interest_rate'"
		)["config_value"],
		"money" => migrationTestMoneySnapshot($liveDb),
	);
	migrationTestSql($liveDb, __DIR__ . "/../../database/migrations/20260724_001_add_monthly_interest_postings.sql");

	$runner = new TestRunner();

	$runner->test("monthly-interest rollout contains one production migration", function(TestRunner $test) {
		$paths = glob(__DIR__ . "/../../database/migrations/20260724_*monthly_interest*.sql");
		$names = array_map("basename", $paths);
		sort($names);
		$test->assertSame(array("20260724_001_add_monthly_interest_postings.sql"), $names);
	});

	$runner->test("migration preserves approved balance to the cent", function(TestRunner $test) use ($db, $balanceBefore) {
		$balanceAfter = migrationTestFetchOne(
			$db,
			"SELECT SUM(amount) AS balance FROM transactions WHERE customer = 1 AND approved = 1 AND undone = 0"
		)["balance"];
		$test->assertSame(number_format((float) $balanceBefore, 2, ".", ""), $balanceAfter);
	});

	$runner->test("migration converts all money columns to fixed decimals", function(TestRunner $test) use ($db) {
		$rows = $db->query(
			"SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
			FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
			AND (
				(TABLE_NAME = 'transactions' AND COLUMN_NAME IN ('amount', 'balance'))
				OR
				(TABLE_NAME = 'reward_events' AND COLUMN_NAME IN ('amount', 'balance_before', 'balance_after'))
			)"
		)->fetchAll();
		$test->assertSame(5, count($rows));
		foreach ($rows as $row) {
			$test->assertSame("decimal(15,2)", strtolower($row["COLUMN_TYPE"]));
		}
	});

	$runner->test("migration initializes August 2026 global rate", function(TestRunner $test) use ($db) {
		$row = migrationTestFetchOne(
			$db,
			"SELECT effective_period, rate FROM monthly_interest_rates"
		);
		$test->assertSame("2026-08-01", $row["effective_period"]);
		$test->assertSame("0.00080000", $row["rate"]);
	});

	$runner->test("migration makes only active non-boss customers eligible", function(TestRunner $test) use ($db) {
		$rows = $db->query(
			"SELECT customer, start_period, end_period
			FROM customer_interest_eligibility
			ORDER BY customer"
		)->fetchAll();
		$test->assertSame(array(
			array(
				"customer" => 1,
				"start_period" => "2026-08-01",
				"end_period" => null,
			),
		), array_map(function($row) {
			return array(
				"customer" => (int) $row["customer"],
				"start_period" => $row["start_period"],
				"end_period" => $row["end_period"],
			);
		}, $rows));
	});

	$runner->test("migration updates the monthly rate description", function(TestRunner $test) use ($db) {
		$description = migrationTestFetchOne(
			$db,
			"SELECT description FROM reward_config WHERE config_key = 'monthly_interest_rate'"
		)["description"];
		$test->assertTrue(strpos($description, "Monatsende") !== false);
	});

	$runner->test("single migration removes obsolete lazy state", function(TestRunner $test) use ($db) {
		$count = (int) migrationTestFetchOne(
			$db,
			"SELECT COUNT(*) AS total
			FROM customer_reward_state
			WHERE state_key = 'monthly_interest_period'"
		)["total"];
		$test->assertSame(0, $count);
	});

	$runner->test("single migration applies safely to the current live dump", function(TestRunner $test) use ($liveDb, $liveBefore) {
		$test->assertTrue($liveBefore["legacy_state"] > 0, "The live snapshot must exercise legacy-state cleanup.");
		$test->assertSame(
			$liveBefore["customers"],
			(int) migrationTestFetchOne($liveDb, "SELECT COUNT(*) AS total FROM customers")["total"]
		);
		$test->assertSame(
			$liveBefore["transactions"],
			(int) migrationTestFetchOne($liveDb, "SELECT COUNT(*) AS total FROM transactions")["total"]
		);
		$test->assertSame(
			$liveBefore["reward_events"],
			(int) migrationTestFetchOne($liveDb, "SELECT COUNT(*) AS total FROM reward_events")["total"]
		);
		$test->assertSame($liveBefore["money"], migrationTestMoneySnapshot($liveDb));
		$test->assertSame(
			$liveBefore["eligible"],
			(int) migrationTestFetchOne($liveDb, "SELECT COUNT(*) AS total FROM customer_interest_eligibility")["total"]
		);
		$invalidEligibility = (int) migrationTestFetchOne(
			$liveDb,
			"SELECT COUNT(*) AS total
			FROM customer_interest_eligibility
			WHERE start_period <> '2026-08-01' OR end_period IS NOT NULL"
		)["total"];
		$test->assertSame(0, $invalidEligibility);
		$rate = migrationTestFetchOne(
			$liveDb,
			"SELECT effective_period, rate FROM monthly_interest_rates"
		);
		$test->assertSame("2026-08-01", $rate["effective_period"]);
		$test->assertSame(number_format((float) $liveBefore["rate"], 8, ".", ""), $rate["rate"]);
		$legacyCount = (int) migrationTestFetchOne(
			$liveDb,
			"SELECT COUNT(*) AS total
			FROM customer_reward_state
			WHERE state_key = 'monthly_interest_period'"
		)["total"];
		$test->assertSame(0, $legacyCount);
		$newTableCount = (int) migrationTestFetchOne(
			$liveDb,
			"SELECT COUNT(*) AS total
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME IN (
				'monthly_interest_rates',
				'customer_interest_eligibility',
				'monthly_interest_postings'
			)"
		)["total"];
		$test->assertSame(3, $newTableCount);
	});

	$status = $runner->finish();
} finally {
	$db = null;
	$liveDb = null;
	$server->exec("DROP DATABASE IF EXISTS " . $quotedMigrationDatabase);
	$server->exec("DROP DATABASE IF EXISTS " . $quotedLiveMigrationDatabase);
}

exit($status);

?>
