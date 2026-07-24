<?php

require_once __DIR__ . "/../site/backend/database.php";
require_once __DIR__ . "/../site/backend/monthly_interest_processor.php";

$asOf = new DateTimeImmutable("now", MonthlyInterestPeriod::businessTimezone());
$customer = null;

foreach (array_slice($argv, 1) as $argument) {
	if (strpos($argument, "--as-of=") === 0) {
		$value = substr($argument, strlen("--as-of="));
		try {
			$asOf = new DateTimeImmutable($value, MonthlyInterestPeriod::businessTimezone());
		} catch (Exception $error) {
			fwrite(STDERR, "Invalid --as-of value." . PHP_EOL);
			exit(2);
		}
		continue;
	}
	if (strpos($argument, "--customer=") === 0) {
		$value = substr($argument, strlen("--customer="));
		if (!preg_match('/^[1-9][0-9]*$/', $value)) {
			fwrite(STDERR, "Invalid --customer value." . PHP_EOL);
			exit(2);
		}
		$customer = (int) $value;
		continue;
	}

	fwrite(STDERR, "Unknown argument: " . $argument . PHP_EOL);
	exit(2);
}

try {
	$processor = new MonthlyInterestProcessor(getDB());
	$result = $processor->processDue($asOf, $customer);
	echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
	fwrite(STDERR, "Monthly interest processing failed: " . $error->getMessage() . PHP_EOL);
	exit(1);
}

?>
