<?php

putenv("BABABANK_ENV_FILE=" . (getenv("BABABANK_ENV_FILE") ?: ".env.test"));

require_once __DIR__ . "/../../site/backend/database.php";
require_once __DIR__ . "/../../site/backend/monthly_interest_processor.php";

$customer = isset($argv[1]) ? (int) $argv[1] : 0;
$asOf = isset($argv[2]) ? new DateTimeImmutable($argv[2]) : new DateTimeImmutable("now");
$holdMilliseconds = isset($argv[3]) ? max(0, (int) $argv[3]) : 0;

$processor = new MonthlyInterestProcessor(getDB(), function($stage) use ($holdMilliseconds) {
	if ($stage === "after_posting" && $holdMilliseconds > 0) {
		usleep($holdMilliseconds * 1000);
	}
});
$result = $processor->processCustomer($customer, $asOf);
echo json_encode($result) . PHP_EOL;

?>
