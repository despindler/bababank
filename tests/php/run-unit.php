<?php

require_once __DIR__ . "/TestRunner.php";
require_once __DIR__ . "/MonthlyInterestDomainTest.php";

$runner = new TestRunner();
registerMonthlyInterestDomainTests($runner);
exit($runner->finish());

?>
