<?php

putenv("BABABANK_ENV_FILE=" . (getenv("BABABANK_ENV_FILE") ?: ".env.test"));

require_once __DIR__ . "/TestRunner.php";
require_once __DIR__ . "/../../site/backend/database.php";
require_once __DIR__ . "/MonthlyInterestSchemaTest.php";
require_once __DIR__ . "/MonthlyInterestProcessorTest.php";

$runner = new TestRunner();
registerMonthlyInterestSchemaTests($runner);
registerMonthlyInterestProcessorTests($runner);
exit($runner->finish());

?>
