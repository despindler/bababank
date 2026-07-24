<?php

function registerMonthlyInterestSchemaTests(TestRunner $runner)
{
	$runner->test("fresh schema contains monthly interest tables", function(TestRunner $test) {
		$names = array_map(function($row) {
			return $row["TABLE_NAME"];
		}, dbFetchAll(
			"SELECT TABLE_NAME
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME IN ('monthly_interest_rates', 'customer_interest_eligibility', 'monthly_interest_postings')
			ORDER BY TABLE_NAME"
		));
		$test->assertSame(array(
			"customer_interest_eligibility",
			"monthly_interest_postings",
			"monthly_interest_rates",
		), $names);
	});

	$runner->test("money columns use fixed decimal types", function(TestRunner $test) {
		$columns = dbFetchAll(
			"SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
			FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
			AND (
				(TABLE_NAME = 'transactions' AND COLUMN_NAME IN ('amount', 'balance'))
				OR
				(TABLE_NAME = 'reward_events' AND COLUMN_NAME IN ('amount', 'balance_before', 'balance_after'))
			)
			ORDER BY TABLE_NAME, COLUMN_NAME"
		);
		$test->assertSame(5, count($columns));
		foreach ($columns as $column) {
			$test->assertSame("decimal(15,2)", strtolower($column["COLUMN_TYPE"]));
		}
	});

	$runner->test("customer and period uniqueness is enforced", function(TestRunner $test) {
		$indexes = dbFetchAll(
			"SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list
			FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = 'monthly_interest_postings'
			GROUP BY INDEX_NAME, NON_UNIQUE"
		);
		$found = false;
		foreach ($indexes as $index) {
			if ($index["columns_list"] === "customer,period_start" && (int) $index["NON_UNIQUE"] === 0) {
				$found = true;
			}
		}
		$test->assertTrue($found, "Missing unique customer-period index.");
	});

	$runner->test("sanitized test seed has global cutover rate and no customers", function(TestRunner $test) {
		$test->assertSame(0, (int) dbFetchOne("SELECT COUNT(*) AS total FROM customers")["total"]);
		$rate = dbFetchOne(
			"SELECT effective_period, rate
			FROM monthly_interest_rates
			WHERE effective_period = '2026-08-01'"
		);
		$test->assertSame("2026-08-01", $rate["effective_period"]);
		$test->assertSame("0.00080000", $rate["rate"]);
	});

	$runner->test("schema rejects invalid period dates and negative values", function(TestRunner $test) {
		$test->assertThrows(PDOException::class, function() {
			dbExecute(
				"INSERT INTO monthly_interest_rates (effective_period, rate)
				VALUES ('2026-08-02', 0.001)"
			);
		});
		$test->assertThrows(PDOException::class, function() {
			dbExecute(
				"INSERT INTO monthly_interest_rates (effective_period, rate)
				VALUES ('2026-09-01', -0.001)"
			);
		});
	});

	$runner->test("database connection uses UTC session timestamps", function(TestRunner $test) {
		$row = dbFetchOne("SELECT @@session.time_zone AS session_timezone");
		$test->assertSame("+00:00", $row["session_timezone"]);
	});

	$runner->test("rate history selects the latest effective rate", function(TestRunner $test) {
		try {
			dbScheduleMonthlyInterestRate("2026-10", "0.0012");
			$before = dbMonthlyInterestRateForPeriod("2026-09");
			$after = dbMonthlyInterestRateForPeriod("2026-10");
			$test->assertSame("2026-08-01", $before["effective_period"]);
			$test->assertSame("0.00080000", $before["rate"]);
			$test->assertSame("2026-10-01", $after["effective_period"]);
			$test->assertSame("0.00120000", $after["rate"]);
		} finally {
			dbExecute("DELETE FROM monthly_interest_rates WHERE effective_period = '2026-10-01'");
		}
	});

	$runner->test("disabling monthly interest schedules a zero rate for the next period", function(TestRunner $test) {
		try {
			dbUpdateRewardConfig(
				array("reward_monthly_interest_enabled" => "false"),
				new DateTimeImmutable("2026-12-15 12:00:00", new DateTimeZone("Europe/Zurich"))
			);
			$disabled = dbMonthlyInterestRateForPeriod("2027-01");
			$test->assertSame("2027-01-01", $disabled["effective_period"]);
			$test->assertSame("0.00000000", $disabled["rate"]);

			dbUpdateRewardConfig(
				array("reward_monthly_interest_enabled" => "true"),
				new DateTimeImmutable("2026-12-15 12:00:00", new DateTimeZone("Europe/Zurich"))
			);
			$enabled = dbMonthlyInterestRateForPeriod("2027-01");
			$test->assertSame("0.00080000", $enabled["rate"]);
		} finally {
			dbExecute(
				"UPDATE reward_config
				SET config_value = 'true'
				WHERE config_key = 'reward_monthly_interest_enabled'"
			);
			dbExecute("DELETE FROM monthly_interest_rates WHERE effective_period = '2027-01-01'");
		}
	});

	$runner->test("archive and restore preserve an ineligible archived gap", function(TestRunner $test) {
		dbExecute(
			"INSERT INTO customers (fullname, username, userpassword, boss, realm)
			VALUES ('Eligibility Test', 'eligibility_test', 'hash', 0, 991002)"
		);
		$customer = (int) getDB()->lastInsertId();
		try {
			dbOpenInterestEligibility($customer, "2026-08");
			$archived = dbSoftDeleteCustomer(
				$customer,
				991002,
				new DateTimeImmutable("2026-09-15 12:00:00", new DateTimeZone("Europe/Zurich"))
			);
			$restored = dbRestoreCustomer(
				$customer,
				991002,
				new DateTimeImmutable("2026-11-15 12:00:00", new DateTimeZone("Europe/Zurich"))
			);
			$test->assertSame(true, $archived);
			$test->assertSame(true, $restored);

			$rows = dbFetchAll(
				"SELECT start_period, end_period
				FROM customer_interest_eligibility
				WHERE customer = :customer
				ORDER BY start_period",
				array("customer" => $customer)
			);
			$test->assertSame(array(
				array("start_period" => "2026-08-01", "end_period" => "2026-09-01"),
				array("start_period" => "2026-11-01", "end_period" => null),
			), $rows);
		} finally {
			dbExecute("DELETE FROM customers WHERE id = :customer", array("customer" => $customer));
		}
	});

	$runner->test("customer projection covers positive zero negative disabled and ineligible states", function(TestRunner $test) {
		$customerIds = array();
		$clock = new FixedMonthlyInterestClock(
			new DateTimeImmutable("2026-08-15 12:00:00", new DateTimeZone("Europe/Zurich"))
		);
		try {
			foreach (array("positive", "zero", "negative", "ineligible") as $suffix) {
				dbExecute(
					"INSERT INTO customers (fullname, username, userpassword, boss, realm)
					VALUES (:fullname, :username, 'hash', 0, 991003)",
					array(
						"fullname" => "Projection " . ucfirst($suffix),
						"username" => "projection_" . $suffix . "_" . random_int(100000, 999999),
					)
				);
				$customerIds[$suffix] = (int) getDB()->lastInsertId();
			}

			foreach (array("positive", "zero", "negative") as $suffix) {
				dbOpenInterestEligibility($customerIds[$suffix], "2026-08");
			}
			dbExecute(
				"INSERT INTO transactions (customer, amount, balance, approved, undone)
				VALUES (:customer, 125.00, 125.00, 1, 0)",
				array("customer" => $customerIds["positive"])
			);
			dbExecute(
				"INSERT INTO transactions (customer, amount, balance, approved, undone)
				VALUES (:customer, -25.00, -25.00, 1, 0)",
				array("customer" => $customerIds["negative"])
			);

			$positive = dbMonthlyInterestProjectionForCustomer(
				$customerIds["positive"],
				dbBalanceByCustomer($customerIds["positive"]),
				$clock
			);
			$test->assertSame("active", $positive["status"]);
			$test->assertSame(true, $positive["enabled"]);
			$test->assertSame("2026-08", $positive["period"]);
			$test->assertSame("125.00", $positive["balance_basis_estimate"]);
			$test->assertSame("0.0008", $positive["rate"]);
			$test->assertSame("0.08", $positive["rate_percent"]);
			$test->assertSame("0.10", $positive["estimated_amount"]);
			$test->assertSame("2026-09-01", $positive["posting_date"]);
			$test->assertSame("Europe/Zurich", $positive["timezone"]);

			$zero = dbMonthlyInterestProjectionForCustomer(
				$customerIds["zero"],
				dbBalanceByCustomer($customerIds["zero"]),
				$clock
			);
			$negative = dbMonthlyInterestProjectionForCustomer(
				$customerIds["negative"],
				dbBalanceByCustomer($customerIds["negative"]),
				$clock
			);
			$test->assertSame("0.00", $zero["estimated_amount"]);
			$test->assertSame("0.00", $negative["estimated_amount"]);
			$test->assertSame("-25.00", $negative["balance_basis_estimate"]);

			$ineligible = dbMonthlyInterestProjectionForCustomer(
				$customerIds["ineligible"],
				0,
				$clock
			);
			$test->assertSame("not_eligible", $ineligible["status"]);
			$test->assertSame(false, $ineligible["enabled"]);
			$test->assertSame(null, $ineligible["rate"]);
			$test->assertSame(null, $ineligible["estimated_amount"]);

			dbScheduleMonthlyInterestRate("2026-08", "0");
			$disabled = dbMonthlyInterestProjectionForCustomer(
				$customerIds["positive"],
				dbBalanceByCustomer($customerIds["positive"]),
				$clock
			);
			$test->assertSame("disabled", $disabled["status"]);
			$test->assertSame(false, $disabled["enabled"]);
			$test->assertSame("0.00", $disabled["estimated_amount"]);
		} finally {
			dbScheduleMonthlyInterestRate("2026-08", "0.0008");
			foreach ($customerIds as $customer) {
				dbExecute("DELETE FROM customers WHERE id = :customer", array("customer" => $customer));
			}
		}
	});
}

?>
