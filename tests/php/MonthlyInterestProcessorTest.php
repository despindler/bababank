<?php

require_once __DIR__ . "/../../site/backend/monthly_interest_processor.php";

function monthlyInterestTestWithRates($rates, callable $callback)
{
	$original = dbFetchAll("SELECT effective_period, rate FROM monthly_interest_rates ORDER BY effective_period");
	try {
		dbExecute("DELETE FROM monthly_interest_rates");
		foreach ($rates as $period => $rate) {
			dbExecute(
				"INSERT INTO monthly_interest_rates (effective_period, rate) VALUES (:period, :rate)",
				array("period" => $period, "rate" => $rate)
			);
		}
		$callback();
	} finally {
		dbExecute("DELETE FROM monthly_interest_rates");
		foreach ($original as $row) {
			dbExecute(
				"INSERT INTO monthly_interest_rates (effective_period, rate) VALUES (:period, :rate)",
				array("period" => $row["effective_period"], "rate" => $row["rate"])
			);
		}
	}
}

function monthlyInterestTestCustomer($label, $startPeriod = "2026-08-01")
{
	$username = "interest_" . strtolower($label) . "_" . bin2hex(random_bytes(4));
	dbExecute(
		"INSERT INTO customers (fullname, username, userpassword, boss, realm)
		VALUES (:fullname, :username, 'hash', 0, 991003)",
		array("fullname" => "Interest " . $label, "username" => $username)
	);
	$customer = (int) getDB()->lastInsertId();
	dbExecute(
		"INSERT INTO customer_interest_eligibility (customer, start_period)
		VALUES (:customer, :start_period)",
		array("customer" => $customer, "start_period" => $startPeriod)
	);
	return $customer;
}

function monthlyInterestTestTransaction($customer, $datetime, $amount, $approved = 1, $undone = 0)
{
	dbExecute(
		"INSERT INTO transactions (customer, datetime, amount, balance, kind, approved, undone)
		VALUES (:customer, :datetime, :amount, 0.00, 'manual', :approved, :undone)",
		array(
			"customer" => $customer,
			"datetime" => $datetime,
			"amount" => $amount,
			"approved" => $approved,
			"undone" => $undone,
		)
	);
	return (int) getDB()->lastInsertId();
}

function monthlyInterestTestCleanup($customers)
{
	if (count($customers) === 0) {
		return;
	}
	$placeholders = implode(",", array_fill(0, count($customers), "?"));
	$stmt = getDB()->prepare("DELETE FROM customers WHERE id IN (" . $placeholders . ")");
	$stmt->execute(array_values($customers));
}

function monthlyInterestTestAsOf($value)
{
	return new DateTimeImmutable($value, new DateTimeZone(MonthlyInterestPeriod::BUSINESS_TIMEZONE));
}

function registerMonthlyInterestProcessorTests(TestRunner $runner)
{
	$runner->test("posts an ordinary month from the exact pre-cutoff balance", function(TestRunner $test) {
		monthlyInterestTestWithRates(array("2026-08-01" => "0.01"), function() use ($test) {
			$customer = monthlyInterestTestCustomer("Cutoff");
			try {
				monthlyInterestTestTransaction($customer, "2026-08-31 21:59:59", "100.00");
				monthlyInterestTestTransaction($customer, "2026-08-31 22:00:00", "50.00");
				monthlyInterestTestTransaction($customer, "2026-08-31 20:00:00", "100.00", 0, 0);
				monthlyInterestTestTransaction($customer, "2026-08-31 20:00:00", "-20.00", 1, 1);

				$processor = new MonthlyInterestProcessor(getDB());
				$result = $processor->processCustomer($customer, monthlyInterestTestAsOf("2026-09-15 12:00:00"));
				$test->assertSame(1, count($result["settlements"]));
				$test->assertSame("100.00", $result["settlements"][0]["balance_basis"]);
				$test->assertSame("1.00", $result["settlements"][0]["amount"]);
				$test->assertSame("2026-08-31 22:00:00", $result["settlements"][0]["effective_at"]);

				$row = dbFetchOne(
					"SELECT p.period_start, t.kind, t.note, t.balance, r.trigger_value, r.amount
					FROM monthly_interest_postings p
					INNER JOIN transactions t ON t.id = p.transaction_id
					INNER JOIN reward_events r ON r.id = p.reward_event_id
					WHERE p.customer = :customer",
					array("customer" => $customer)
				);
				$test->assertSame("2026-08-01", $row["period_start"]);
				$test->assertSame("monthly_interest", $row["kind"]);
				$test->assertSame("Monatszins August 2026", $row["note"]);
				$test->assertSame("151.00", $row["balance"]);
				$test->assertSame("2026-08", $row["trigger_value"]);
				$test->assertSame("1.00", $row["amount"]);
			} finally {
				monthlyInterestTestCleanup(array($customer));
			}
		});
	});

	$runner->test("catches up three months oldest first with compounding and one chest each", function(TestRunner $test) {
		monthlyInterestTestWithRates(array("2026-08-01" => "0.1"), function() use ($test) {
			$customer = monthlyInterestTestCustomer("Catchup");
			try {
				monthlyInterestTestTransaction($customer, "2026-08-01 10:00:00", "100.00");
				$processor = new MonthlyInterestProcessor(getDB());
				$result = $processor->processCustomer($customer, monthlyInterestTestAsOf("2026-11-15 12:00:00"));
				$test->assertSame(array("2026-08", "2026-09", "2026-10"), array_column($result["settlements"], "period"));
				$test->assertSame(array("100.00", "110.00", "121.00"), array_column($result["settlements"], "balance_basis"));
				$test->assertSame(array("10.00", "11.00", "12.10"), array_column($result["settlements"], "amount"));
				$test->assertSame(3, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM reward_events WHERE customer = :customer",
					array("customer" => $customer)
				)["total"]);
				$test->assertSame("133.10", number_format((float) dbBalanceByCustomer($customer), 2, ".", ""));

				$repeat = $processor->processCustomer($customer, monthlyInterestTestAsOf("2026-11-15 12:00:00"));
				$test->assertSame(0, count($repeat["settlements"]));
				$test->assertSame(3, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM monthly_interest_postings WHERE customer = :customer",
					array("customer" => $customer)
				)["total"]);
			} finally {
				monthlyInterestTestCleanup(array($customer));
			}
		});
	});

	$runner->test("records zero and negative balances without money or chests", function(TestRunner $test) {
		monthlyInterestTestWithRates(array("2026-08-01" => "0.01"), function() use ($test) {
			$zero = monthlyInterestTestCustomer("Zero");
			$negative = monthlyInterestTestCustomer("Negative");
			try {
				monthlyInterestTestTransaction($negative, "2026-08-01 10:00:00", "-100.00");
				$processor = new MonthlyInterestProcessor(getDB());
				$result = $processor->processDue(monthlyInterestTestAsOf("2026-09-15 12:00:00"));
				$test->assertSame(2, $result["customers"]);
				$test->assertSame(2, $result["totals"]["zero_settled"]);
				$test->assertSame(0, $result["totals"]["created"]);
				$test->assertSame(0, $result["totals"]["failed"]);

				$postings = dbFetchAll(
					"SELECT customer, amount, transaction_id, reward_event_id
					FROM monthly_interest_postings
					WHERE customer IN (:zero, :negative)
					ORDER BY customer",
					array("zero" => $zero, "negative" => $negative)
				);
				$test->assertSame(2, count($postings));
				$test->assertSame("0.00", $postings[0]["amount"]);
				$test->assertSame(null, $postings[0]["transaction_id"]);
				$test->assertSame("0.00", $postings[1]["amount"]);
				$test->assertSame(null, $postings[1]["reward_event_id"]);
				$test->assertSame(0, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM reward_events WHERE customer IN (:zero, :negative)",
					array("zero" => $zero, "negative" => $negative)
				)["total"]);
			} finally {
				monthlyInterestTestCleanup(array($zero, $negative));
			}
		});
	});

	$runner->test("uses the effective rate for each consecutive period", function(TestRunner $test) {
		monthlyInterestTestWithRates(array(
			"2026-08-01" => "0.01",
			"2026-09-01" => "0.02",
		), function() use ($test) {
			$customer = monthlyInterestTestCustomer("Rates");
			try {
				monthlyInterestTestTransaction($customer, "2026-08-01 10:00:00", "100.00");
				$processor = new MonthlyInterestProcessor(getDB());
				$processor->processCustomer($customer, monthlyInterestTestAsOf("2026-10-15 12:00:00"));
				$rows = dbFetchAll(
					"SELECT period_start, interest_rate, balance_basis, amount
					FROM monthly_interest_postings
					WHERE customer = :customer
					ORDER BY period_start",
					array("customer" => $customer)
				);
				$test->assertSame(array(
					array("period_start" => "2026-08-01", "interest_rate" => "0.01000000", "balance_basis" => "100.00", "amount" => "1.00"),
					array("period_start" => "2026-09-01", "interest_rate" => "0.02000000", "balance_basis" => "101.00", "amount" => "2.02"),
				), $rows);
			} finally {
				monthlyInterestTestCleanup(array($customer));
			}
		});
	});

	$runner->test("skips archived periods after restoration", function(TestRunner $test) {
		monthlyInterestTestWithRates(array("2026-08-01" => "0.01"), function() use ($test) {
			$customer = monthlyInterestTestCustomer("Archive");
			try {
				monthlyInterestTestTransaction($customer, "2026-08-01 10:00:00", "100.00");
				dbExecute(
					"UPDATE customer_interest_eligibility SET end_period = '2026-09-01' WHERE customer = :customer",
					array("customer" => $customer)
				);
				dbExecute(
					"INSERT INTO customer_interest_eligibility (customer, start_period) VALUES (:customer, '2026-11-01')",
					array("customer" => $customer)
				);

				$processor = new MonthlyInterestProcessor(getDB());
				$processor->processCustomer($customer, monthlyInterestTestAsOf("2026-12-15 12:00:00"));
				$test->assertSame(array("2026-08-01", "2026-11-01"), array_column(dbFetchAll(
					"SELECT period_start FROM monthly_interest_postings
					WHERE customer = :customer ORDER BY period_start",
					array("customer" => $customer)
				), "period_start"));
			} finally {
				monthlyInterestTestCleanup(array($customer));
			}
		});
	});

	$runner->test("rolls back posting transaction reward and balance changes together", function(TestRunner $test) {
		monthlyInterestTestWithRates(array("2026-08-01" => "0.01"), function() use ($test) {
			$customer = monthlyInterestTestCustomer("Rollback");
			try {
				$manual = monthlyInterestTestTransaction($customer, "2026-08-01 10:00:00", "100.00");
				$processor = new MonthlyInterestProcessor(getDB(), function($stage) {
					if ($stage === "after_reward") {
						throw new RuntimeException("Injected failure.");
					}
				});
				$test->assertThrows(RuntimeException::class, function() use ($processor, $customer) {
					$processor->processCustomer($customer, monthlyInterestTestAsOf("2026-09-15 12:00:00"));
				});
				$test->assertSame(0, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM monthly_interest_postings WHERE customer = :customer",
					array("customer" => $customer)
				)["total"]);
				$test->assertSame(0, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM reward_events WHERE customer = :customer",
					array("customer" => $customer)
				)["total"]);
				$test->assertSame(1, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM transactions WHERE customer = :customer",
					array("customer" => $customer)
				)["total"]);
				$test->assertSame("0.00", dbFetchOne(
					"SELECT balance FROM transactions WHERE id = :id",
					array("id" => $manual)
				)["balance"]);
			} finally {
				monthlyInterestTestCleanup(array($customer));
			}
		});
	});

	$runner->test("concurrent processors and deletion retries preserve one payment", function(TestRunner $test) {
		monthlyInterestTestWithRates(array("2026-08-01" => "0.01"), function() use ($test) {
			$customer = monthlyInterestTestCustomer("Concurrent");
			try {
				monthlyInterestTestTransaction($customer, "2026-08-01 10:00:00", "100.00");
				$worker = __DIR__ . "/process-monthly-interest-worker.php";
				$descriptors = array(
					0 => array("pipe", "r"),
					1 => array("pipe", "w"),
					2 => array("pipe", "w"),
				);
				$pipesOne = array();
				$pipesTwo = array();
				$processOne = proc_open(
					array(PHP_BINARY, $worker, (string) $customer, "2026-09-15T12:00:00+02:00", "500"),
					$descriptors,
					$pipesOne,
					dirname(__DIR__, 2)
				);
				$processTwo = proc_open(
					array(PHP_BINARY, $worker, (string) $customer, "2026-09-15T12:00:00+02:00", "0"),
					$descriptors,
					$pipesTwo,
					dirname(__DIR__, 2)
				);
				fclose($pipesOne[0]);
				fclose($pipesTwo[0]);
				$outputOne = stream_get_contents($pipesOne[1]);
				$errorOne = stream_get_contents($pipesOne[2]);
				$outputTwo = stream_get_contents($pipesTwo[1]);
				$errorTwo = stream_get_contents($pipesTwo[2]);
				fclose($pipesOne[1]);
				fclose($pipesOne[2]);
				fclose($pipesTwo[1]);
				fclose($pipesTwo[2]);
				$exitOne = proc_close($processOne);
				$exitTwo = proc_close($processTwo);
				$test->assertSame(0, $exitOne, $errorOne . $outputOne);
				$test->assertSame(0, $exitTwo, $errorTwo . $outputTwo);

				$row = dbFetchOne(
					"SELECT p.transaction_id, t.undone
					FROM monthly_interest_postings p
					INNER JOIN transactions t ON t.id = p.transaction_id
					WHERE p.customer = :customer",
					array("customer" => $customer)
				);
				$test->assertSame(1, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM monthly_interest_postings WHERE customer = :customer",
					array("customer" => $customer)
				)["total"]);
				$test->assertSame(0, dbTransactionDelete((int) $row["transaction_id"]));
				$test->assertSame(0, (int) dbFetchOne(
					"SELECT undone FROM transactions WHERE id = :id",
					array("id" => $row["transaction_id"])
				)["undone"]);
			} finally {
				monthlyInterestTestCleanup(array($customer));
			}
		});
	});
}

?>
