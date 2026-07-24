<?php

function monthlyInterestCommandRun($arguments, $environmentOverrides = array())
{
	$command = array_merge(
		array(PHP_BINARY, __DIR__ . "/../../tools/post-monthly-interest.php"),
		$arguments
	);
	$descriptors = array(
		0 => array("pipe", "r"),
		1 => array("pipe", "w"),
		2 => array("pipe", "w"),
	);
	$pipes = array();
	$environment = array_merge(getenv(), $environmentOverrides);
	$process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), $environment);
	if (!is_resource($process)) {
		throw new RuntimeException("Unable to start monthly-interest command.");
	}
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);

	return array(
		"exit_code" => $exitCode,
		"stdout" => $stdout,
		"stderr" => $stderr,
		"json" => json_decode($stdout !== "" ? $stdout : $stderr, true),
	);
}

function registerMonthlyInterestCommandTests(TestRunner $runner)
{
	$runner->test("boundary retry and dry-run behavior is scheduler safe", function(TestRunner $test) {
		monthlyInterestTestWithRates(array("2026-08-01" => "0.01"), function() use ($test) {
			$customer = monthlyInterestTestCustomer("Scheduler");
			try {
				monthlyInterestTestTransaction($customer, "2026-08-01 10:00:00", "100.00");
				$processor = new MonthlyInterestProcessor(getDB());

				$before = $processor->processDue(monthlyInterestTestAsOf("2026-08-31 23:59:59"));
				$test->assertSame(0, $before["totals"]["created"]);
				$test->assertSame(0, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM monthly_interest_postings WHERE customer = :customer",
					array("customer" => $customer)
				)["total"]);

				$preview = $processor->processDue(
					monthlyInterestTestAsOf("2026-09-01 00:00:01"),
					null,
					true
				);
				$test->assertSame("dry_run", $preview["mode"]);
				$test->assertSame(1, $preview["totals"]["would_create"]);
				$test->assertSame("100.00", $preview["results"][0]["settlements"][0]["balance_basis"]);
				$test->assertSame(0, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM monthly_interest_postings WHERE customer = :customer",
					array("customer" => $customer)
				)["total"]);

				$after = $processor->processDue(monthlyInterestTestAsOf("2026-09-01 00:00:01"));
				$test->assertSame(1, $after["totals"]["created"]);
				$repeat = $processor->processDue(monthlyInterestTestAsOf("2026-09-01 00:00:01"));
				$test->assertSame(0, $repeat["totals"]["created"]);
				$test->assertSame(1, $repeat["totals"]["already_settled"]);
			} finally {
				monthlyInterestTestCleanup(array($customer));
			}
		});
	});

	$runner->test("dry-run simulates three-month compounding without persistent writes", function(TestRunner $test) {
		monthlyInterestTestWithRates(array("2026-08-01" => "0.1"), function() use ($test) {
			$customer = monthlyInterestTestCustomer("Preview");
			try {
				monthlyInterestTestTransaction($customer, "2026-08-01 10:00:00", "100.00");
				$processor = new MonthlyInterestProcessor(getDB());
				$preview = $processor->processDue(
					monthlyInterestTestAsOf("2026-11-15 12:00:00"),
					$customer,
					true
				);
				$test->assertSame(3, $preview["totals"]["would_create"]);
				$test->assertSame(
					array("100.00", "110.00", "121.00"),
					array_column($preview["results"][0]["settlements"], "balance_basis")
				);
				$test->assertSame(array("10.00", "11.00", "12.10"), array_column(
					$preview["results"][0]["settlements"],
					"amount"
				));
				$test->assertSame(0, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM monthly_interest_postings WHERE customer = :customer",
					array("customer" => $customer)
				)["total"]);
			} finally {
				monthlyInterestTestCleanup(array($customer));
			}
		});
	});

	$runner->test("one customer failure rolls back and reports while later customers continue", function(TestRunner $test) {
		monthlyInterestTestWithRates(array("2026-08-01" => "0.01"), function() use ($test) {
			$failedCustomer = monthlyInterestTestCustomer("Failure");
			$successfulCustomer = monthlyInterestTestCustomer("Continues");
			try {
				monthlyInterestTestTransaction($failedCustomer, "2026-08-01 10:00:00", "100.00");
				monthlyInterestTestTransaction($successfulCustomer, "2026-08-01 10:00:00", "100.00");
				$processor = new MonthlyInterestProcessor(getDB(), function($stage, $context) use ($failedCustomer) {
					if ($stage === "after_posting" && $context["customer"] === $failedCustomer) {
						throw new RuntimeException("Injected customer failure.");
					}
				});
				$result = $processor->processDue(monthlyInterestTestAsOf("2026-09-15 12:00:00"));
				$test->assertSame("failed", $result["status"]);
				$test->assertSame(1, $result["totals"]["failed"]);
				$test->assertSame(1, $result["totals"]["created"]);
				$test->assertSame($failedCustomer, $result["errors"][0]["customer"]);
				$test->assertSame(0, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM monthly_interest_postings WHERE customer = :customer",
					array("customer" => $failedCustomer)
				)["total"]);
				$test->assertSame(1, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM monthly_interest_postings WHERE customer = :customer",
					array("customer" => $successfulCustomer)
				)["total"]);
			} finally {
				monthlyInterestTestCleanup(array($failedCustomer, $successfulCustomer));
			}
		});
	});

	$runner->test("CLI rejects production as-of injection and skips overlapping runs", function(TestRunner $test) {
		$productionGuard = monthlyInterestCommandRun(
			array("--dry-run", "--as-of=2026-09-01T00:00:01+02:00"),
			array(
				"BABABANK_ENV_FILE" => "tests/fixtures/production-environment.env",
				"APP_ENV" => "production",
			)
		);
		$test->assertSame(2, $productionGuard["exit_code"], $productionGuard["stderr"]);
		$test->assertSame("invalid_command", $productionGuard["json"]["status"]);

		$lockName = "bababank_interest_" . substr(sha1(DB_HOST . "|" . DB_NAME), 0, 32);
		$lock = dbFetchOne("SELECT GET_LOCK(:lock_name, 0) AS acquired", array("lock_name" => $lockName));
		$test->assertSame(1, (int) $lock["acquired"]);
		try {
			$overlap = monthlyInterestCommandRun(array("--dry-run"));
			$test->assertSame(0, $overlap["exit_code"], $overlap["stderr"]);
			$test->assertSame("skipped", $overlap["json"]["status"]);
			$test->assertSame(1, $overlap["json"]["totals"]["skipped"]);
		} finally {
			dbFetchOne("SELECT RELEASE_LOCK(:lock_name) AS released", array("lock_name" => $lockName));
		}
	});

	$runner->test("CLI exits non-zero with structured unrecovered period failures", function(TestRunner $test) {
		monthlyInterestTestWithRates(array(), function() use ($test) {
			$customer = monthlyInterestTestCustomer("CliFailure");
			try {
				monthlyInterestTestTransaction($customer, "2026-08-01 10:00:00", "100.00");
				$result = monthlyInterestCommandRun(array(
					"--as-of=2026-09-01T00:00:01+02:00",
					"--customer=" . $customer,
				));
				$test->assertSame(1, $result["exit_code"], $result["stderr"] . $result["stdout"]);
				$test->assertSame("failed", $result["json"]["status"]);
				$test->assertSame(1, $result["json"]["totals"]["failed"]);
				$test->assertSame($customer, $result["json"]["errors"][0]["customer"]);
				$test->assertSame(0, (int) dbFetchOne(
					"SELECT COUNT(*) AS total FROM monthly_interest_postings WHERE customer = :customer",
					array("customer" => $customer)
				)["total"]);
			} finally {
				monthlyInterestTestCleanup(array($customer));
			}
		});
	});
}

?>
