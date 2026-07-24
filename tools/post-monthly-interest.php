<?php

require_once __DIR__ . "/../site/backend/database.php";
require_once __DIR__ . "/../site/backend/monthly_interest_processor.php";

function monthlyInterestCliWrite($payload, $stderr = false)
{
	$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
	fwrite($stderr ? STDERR : STDOUT, $json);
}

function monthlyInterestCliFail($message, $exitCode)
{
	monthlyInterestCliWrite(array(
		"status" => "invalid_command",
		"message" => $message,
	), true);
	exit($exitCode);
}

function monthlyInterestCliIsTestEnvironment()
{
	$environment = strtolower((string) envValue("APP_ENV", envValue("BABABANK_ENV", "")));
	$envFile = (string) envValue("BABABANK_ENV_FILE", "");
	return $environment === "test" || strtolower(basename($envFile)) === ".env.test";
}

$asOf = new DateTimeImmutable("now", MonthlyInterestPeriod::businessTimezone());
$asOfInjected = false;
$customer = null;
$dryRun = false;

foreach (array_slice($argv, 1) as $argument) {
	if ($argument === "--dry-run") {
		$dryRun = true;
		continue;
	}
	if ($argument === "--help") {
		echo "Usage: php tools/post-monthly-interest.php [--dry-run] [--customer=ID] [--as-of=ISO-8601]" . PHP_EOL;
		echo "--as-of is accepted only when APP_ENV=test or .env.test is explicitly selected." . PHP_EOL;
		exit(0);
	}
	if (strpos($argument, "--as-of=") === 0) {
		$value = substr($argument, strlen("--as-of="));
		try {
			$asOf = new DateTimeImmutable($value, MonthlyInterestPeriod::businessTimezone());
		} catch (Exception $error) {
			monthlyInterestCliFail("Invalid --as-of value.", 2);
		}
		$asOfInjected = true;
		continue;
	}
	if (strpos($argument, "--customer=") === 0) {
		$value = substr($argument, strlen("--customer="));
		if (!preg_match('/^[1-9][0-9]*$/', $value)) {
			monthlyInterestCliFail("Invalid --customer value.", 2);
		}
		$customer = (int) $value;
		continue;
	}

	monthlyInterestCliFail("Unknown argument: " . $argument, 2);
}

if ($asOfInjected && !monthlyInterestCliIsTestEnvironment()) {
	monthlyInterestCliFail("--as-of is restricted to the test environment.", 2);
}

$lockName = "bababank_interest_" . substr(sha1(DB_HOST . "|" . DB_NAME), 0, 32);
try {
	$db = getDB();
	$lock = dbFetchOne("SELECT GET_LOCK(:lock_name, 0) AS acquired", array("lock_name" => $lockName));
} catch (Throwable $error) {
	monthlyInterestCliWrite(array(
		"status" => "failed",
		"mode" => $dryRun ? "dry_run" : "apply",
		"as_of" => $asOf->format(DateTimeInterface::ATOM),
		"timezone" => MonthlyInterestPeriod::BUSINESS_TIMEZONE,
		"message" => $error->getMessage(),
	), true);
	exit(1);
}

if (!$lock || (int) $lock["acquired"] !== 1) {
	monthlyInterestCliWrite(array(
		"status" => "skipped",
		"mode" => $dryRun ? "dry_run" : "apply",
		"as_of" => $asOf->format(DateTimeInterface::ATOM),
		"timezone" => MonthlyInterestPeriod::BUSINESS_TIMEZONE,
		"reason" => "another_monthly_interest_command_is_running",
		"totals" => array(
			"created" => 0,
			"zero_settled" => 0,
			"already_settled" => 0,
			"skipped" => 1,
			"failed" => 0,
			"would_create" => 0,
			"would_zero_settle" => 0,
		),
	));
	exit(0);
}

$exitCode = 0;
try {
	$processor = new MonthlyInterestProcessor($db);
	$result = $processor->processDue($asOf, $customer, $dryRun);
	monthlyInterestCliWrite($result);
	if ($result["totals"]["failed"] > 0) {
		$exitCode = 1;
	}
} catch (Throwable $error) {
	monthlyInterestCliWrite(array(
		"status" => "failed",
		"mode" => $dryRun ? "dry_run" : "apply",
		"as_of" => $asOf->format(DateTimeInterface::ATOM),
		"timezone" => MonthlyInterestPeriod::BUSINESS_TIMEZONE,
		"message" => $error->getMessage(),
	), true);
	$exitCode = 1;
} finally {
	try {
		dbFetchOne("SELECT RELEASE_LOCK(:lock_name) AS released", array("lock_name" => $lockName));
	} catch (Throwable $ignored) {
	}
}

exit($exitCode);

?>
