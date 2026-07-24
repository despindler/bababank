<?php

$selectedEnvFile = getenv("BABABANK_ENV_FILE") ?: ".env.test";
putenv("BABABANK_ENV_FILE=" . $selectedEnvFile);
require_once __DIR__ . "/../site/config.php";

function testDatabaseFail($message)
{
	fwrite(STDERR, $message . PHP_EOL);
	exit(1);
}

function testDatabaseRunSqlFile(PDO $db, $path)
{
	$sql = file_get_contents($path);
	if ($sql === false) {
		throw new RuntimeException("Unable to read SQL file: " . $path);
	}
	$sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
	$db->exec($sql);
}

$environment = strtolower((string) envValue("APP_ENV", envValue("BABABANK_ENV", "")));
$explicitTestFile = strtolower(basename($selectedEnvFile)) === ".env.test";
if ($environment !== "test" && !$explicitTestFile) {
	testDatabaseFail("Refusing to reset a database without APP_ENV=test or an explicitly selected .env.test file.");
}

$databaseName = DB_NAME;
if (!preg_match('/^[A-Za-z0-9_]+$/', $databaseName)) {
	testDatabaseFail("Test database name may contain only letters, numbers, and underscores.");
}
if (!preg_match('/(^test_|_test$)/i', $databaseName)) {
	testDatabaseFail("Refusing to reset a database whose name is not clearly test-scoped.");
}
if (in_array(strtolower($databaseName), array("mysql", "information_schema", "performance_schema", "sys"), true)) {
	testDatabaseFail("Refusing to reset a MySQL system database.");
}

$serverDsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
$options = array(
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	PDO::ATTR_EMULATE_PREPARES => false,
);
$server = new PDO($serverDsn, DB_USER, DB_PASSWORD, $options);
$quotedDatabase = "`" . $databaseName . "`";

$server->exec("DROP DATABASE IF EXISTS " . $quotedDatabase);
$server->exec("CREATE DATABASE " . $quotedDatabase . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$db = new PDO(
	"mysql:host=" . DB_HOST . ";dbname=" . $databaseName . ";charset=utf8mb4",
	DB_USER,
	DB_PASSWORD,
	$options
);
testDatabaseRunSqlFile($db, __DIR__ . "/../database/schema.sql");
testDatabaseRunSqlFile($db, __DIR__ . "/../database/test-seed.sql");

$tables = (int) $db->query(
	"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
)->fetchColumn();
$customers = (int) $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$configs = (int) $db->query("SELECT COUNT(*) FROM reward_config")->fetchColumn();

echo "Reset test database " . $databaseName . "." . PHP_EOL;
echo "Tables: " . $tables . "; customers: " . $customers . "; reward config rows: " . $configs . "." . PHP_EOL;

?>
