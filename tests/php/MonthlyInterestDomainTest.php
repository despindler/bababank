<?php

require_once __DIR__ . "/../../site/backend/monthly_interest.php";

function registerMonthlyInterestDomainTests(TestRunner $runner)
{
	$runner->test("calculates interest for a positive balance", function(TestRunner $test) {
		$test->assertSame("0.08", MonthlyInterestMoney::interestAmount("100.00", "0.0008"));
	});

	$runner->test("returns zero interest for zero and negative balances", function(TestRunner $test) {
		$test->assertSame("0.00", MonthlyInterestMoney::interestAmount("0.00", "0.0008"));
		$test->assertSame("0.00", MonthlyInterestMoney::interestAmount("-200.00", "0.0008"));
	});

	$runner->test("rounds an amount below half a cent down", function(TestRunner $test) {
		$test->assertSame("0.00", MonthlyInterestMoney::interestAmount("6.24", "0.0008"));
	});

	$runner->test("rounds an exact half cent up", function(TestRunner $test) {
		$test->assertSame("0.01", MonthlyInterestMoney::interestAmount("6.25", "0.0008"));
	});

	$runner->test("rounds ordinary interest once to cents", function(TestRunner $test) {
		$test->assertSame("0.10", MonthlyInterestMoney::interestAmount("125.00", "0.0008"));
		$test->assertSame("15.24", MonthlyInterestMoney::interestAmount("1234.56", "0.012345"));
	});

	$runner->test("rejects negative and malformed rates", function(TestRunner $test) {
		$test->assertThrows(MonthlyInterestDomainException::class, function() {
			MonthlyInterestMoney::interestAmount("100.00", "-0.01");
		});
		$test->assertThrows(MonthlyInterestDomainException::class, function() {
			MonthlyInterestMoney::interestAmount("100.00", "8e-4");
		});
	});

	$runner->test("normalizes rates and percentage display without floats", function(TestRunner $test) {
		$test->assertSame("0.0008", MonthlyInterestMoney::normalizedRate("0.00080000"));
		$test->assertSame("0.08", MonthlyInterestMoney::ratePercent("0.0008"));
	});

	$runner->test("moves from January to February", function(TestRunner $test) {
		$period = MonthlyInterestPeriod::fromKey("2026-01");
		$test->assertSame("2026-02", $period->next()->key());
		$test->assertSame("2026-02-01", $period->postingDate());
	});

	$runner->test("moves from December into the next year", function(TestRunner $test) {
		$period = MonthlyInterestPeriod::fromKey("2026-12");
		$test->assertSame("2027-01", $period->next()->key());
		$test->assertSame("2027-01-01", $period->postingDate());
	});

	$runner->test("handles leap-year February", function(TestRunner $test) {
		$period = MonthlyInterestPeriod::fromKey("2028-02");
		$test->assertSame("2028-03-01", $period->postingDate());
		$test->assertSame("2028-02-29 23:00:00", $period->cutoffUtc()->format("Y-m-d H:i:s"));
	});

	$runner->test("converts Zurich spring cutoff to UTC", function(TestRunner $test) {
		$period = MonthlyInterestPeriod::fromKey("2026-03");
		$test->assertSame("2026-03-31 22:00:00", $period->cutoffUtc()->format("Y-m-d H:i:s"));
	});

	$runner->test("converts Zurich autumn cutoff to UTC", function(TestRunner $test) {
		$period = MonthlyInterestPeriod::fromKey("2026-10");
		$test->assertSame("2026-10-31 23:00:00", $period->cutoffUtc()->format("Y-m-d H:i:s"));
	});

	$runner->test("enumerates three missed closed periods oldest first", function(TestRunner $test) {
		$asOf = new DateTimeImmutable("2026-04-15 12:00:00", new DateTimeZone("Europe/Zurich"));
		$periods = MonthlyInterestSchedule::closedPeriods("2026-01", $asOf);
		$test->assertSame(array("2026-01", "2026-02", "2026-03"), array_map(function($period) {
			return $period->key();
		}, $periods));
	});

	$runner->test("does not include the open period", function(TestRunner $test) {
		$asOf = new DateTimeImmutable("2026-01-31 23:59:59", new DateTimeZone("Europe/Zurich"));
		$test->assertSame(array(), MonthlyInterestSchedule::closedPeriods("2026-01", $asOf));
	});

	$runner->test("selects the historical rate effective for each period", function(TestRunner $test) {
		$history = array(
			array("effective_period" => "2026-01", "rate" => "0.0008"),
			array("effective_period" => "2026-04", "rate" => "0.0010"),
		);
		$test->assertSame("0.0008", MonthlyInterestRateSchedule::rateForPeriod("2026-03", $history));
		$test->assertSame("0.001", MonthlyInterestRateSchedule::rateForPeriod("2026-04", $history));
	});

	$runner->test("rejects a period without an effective rate", function(TestRunner $test) {
		$test->assertThrows(MonthlyInterestDomainException::class, function() {
			MonthlyInterestRateSchedule::rateForPeriod("2025-12", array(
				array("effective_period" => "2026-01", "rate" => "0.0008"),
			));
		});
	});

	$runner->test("builds a deterministic customer projection", function(TestRunner $test) {
		$clock = new FixedMonthlyInterestClock(
			new DateTimeImmutable("2026-07-24 09:30:00", new DateTimeZone("Europe/Zurich"))
		);
		$test->assertSame(array(
			"enabled" => true,
			"period" => "2026-07",
			"balance_basis_estimate" => "125.00",
			"rate" => "0.0008",
			"rate_percent" => "0.08",
			"estimated_amount" => "0.10",
			"posting_date" => "2026-08-01",
			"timezone" => "Europe/Zurich",
			"is_estimate" => true,
		), MonthlyInterestProjection::build("125.00", "0.0008", $clock));
	});

	$runner->test("builds a projection for an explicitly selected future period", function(TestRunner $test) {
		$projection = MonthlyInterestProjection::buildForPeriod(
			"125.00",
			"0.0008",
			MonthlyInterestPeriod::fromKey("2026-08")
		);
		$test->assertSame("2026-08", $projection["period"]);
		$test->assertSame("0.10", $projection["estimated_amount"]);
		$test->assertSame("2026-09-01", $projection["posting_date"]);
	});

	$runner->test("builds a disabled projection without projected money", function(TestRunner $test) {
		$clock = new FixedMonthlyInterestClock(
			new DateTimeImmutable("2026-07-24 09:30:00", new DateTimeZone("UTC"))
		);
		$projection = MonthlyInterestProjection::build("125.00", "0.0008", $clock, false);
		$test->assertSame(false, $projection["enabled"]);
		$test->assertSame("0.00", $projection["estimated_amount"]);
		$test->assertSame("2026-08-01", $projection["posting_date"]);
	});

	$runner->test("fixed clock returns the same immutable instant", function(TestRunner $test) {
		$instant = new DateTimeImmutable("2026-07-24T07:30:00Z");
		$clock = new FixedMonthlyInterestClock($instant);
		$test->assertTrue($clock->now() === $instant);
	});
}

?>
