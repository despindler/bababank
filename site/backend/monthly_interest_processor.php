<?php

require_once __DIR__ . "/monthly_interest.php";

final class MonthlyInterestProcessor
{
	private $db;
	private $stepHook;

	public function __construct(PDO $db, ?callable $stepHook = null)
	{
		$this->db = $db;
		$this->stepHook = $stepHook;
	}

	public function processDue(DateTimeImmutable $asOf, $onlyCustomer = null, $dryRun = false)
	{
		$currentPeriod = MonthlyInterestPeriod::containing($asOf);
		$params = array("current_period" => $currentPeriod->key() . "-01");
		$customerFilter = "";
		if ($onlyCustomer !== null) {
			$customerFilter = " AND c.id = :customer";
			$params["customer"] = (int) $onlyCustomer;
		}

		$customers = $this->fetchAll(
			"SELECT DISTINCT c.id
			FROM customers c
			INNER JOIN customer_interest_eligibility e ON e.customer = c.id
			WHERE c.boss = 0
			AND e.start_period < :current_period" . $customerFilter . "
			ORDER BY c.id",
			$params
		);

		$result = array(
			"status" => "ok",
			"mode" => $dryRun ? "dry_run" : "apply",
			"as_of" => $asOf->format(DateTimeInterface::ATOM),
			"timezone" => MonthlyInterestPeriod::BUSINESS_TIMEZONE,
			"customers" => 0,
			"totals" => array(
				"created" => 0,
				"zero_settled" => 0,
				"already_settled" => 0,
				"skipped" => 0,
				"failed" => 0,
				"would_create" => 0,
				"would_zero_settle" => 0,
			),
			"results" => array(),
			"errors" => array(),
		);

		foreach ($customers as $customer) {
			$result["customers"]++;
			$customerId = (int) $customer["id"];
			try {
				$customerResult = $dryRun
					? $this->previewCustomer($customerId, $asOf)
					: $this->processCustomer($customerId, $asOf);
				$result["totals"]["already_settled"] += count($customerResult["already_settled"]);
				foreach ($customerResult["settlements"] as &$settlement) {
					if ($settlement["amount"] === "0.00") {
						$settlement["status"] = $dryRun ? "would_zero_settle" : "zero_settled";
						$key = $dryRun ? "would_zero_settle" : "zero_settled";
						$result["totals"][$key]++;
					} else {
						$settlement["status"] = $dryRun ? "would_create" : "created";
						$key = $dryRun ? "would_create" : "created";
						$result["totals"][$key]++;
					}
				}
				unset($settlement);
				foreach ($customerResult["already_settled"] as $period) {
					$customerResult["settlements"][] = array(
						"period" => $period,
						"status" => "already_settled",
					);
				}
				unset($customerResult["already_settled"]);
				$result["results"][] = $customerResult;
			} catch (Throwable $error) {
				$result["status"] = "failed";
				$failedSettlements = array();
				try {
					$preview = $this->previewCustomer($customerId, $asOf);
					foreach ($preview["settlements"] as $settlement) {
						$failedSettlements[] = array(
							"period" => $settlement["period"],
							"status" => "failed",
						);
					}
				} catch (Throwable $previewError) {
				}
				$result["totals"]["failed"] += max(1, count($failedSettlements));
				$result["errors"][] = array(
					"customer" => $customerId,
					"error" => get_class($error),
					"message" => $error->getMessage(),
				);
				$result["results"][] = array(
					"customer" => $customerId,
					"status" => "failed",
					"settlements" => $failedSettlements,
				);
			}
		}

		return $result;
	}

	public function previewCustomer($customer, DateTimeImmutable $asOf)
	{
		$customer = (int) $customer;
		$existingCustomer = $this->fetchOne(
			"SELECT id FROM customers WHERE id = :customer AND boss = 0",
			array("customer" => $customer)
		);
		if (!$existingCustomer) {
			throw new RuntimeException("Interest customer " . $customer . " does not exist or is not eligible.");
		}

		$currentPeriod = MonthlyInterestPeriod::containing($asOf);
		$periods = $this->eligibleClosedPeriods($customer, $currentPeriod);
		$settlements = array();
		$alreadySettled = array();
		$virtualCreditCents = 0;

		foreach ($periods as $period) {
			if ($this->postingExists($customer, $period)) {
				$alreadySettled[] = $period->key();
				continue;
			}
			$calculation = $this->calculatePeriod($customer, $period, $virtualCreditCents);
			$settlements[] = array(
				"posting" => null,
				"period" => $period->key(),
				"balance_basis" => $calculation["balance"],
				"rate" => $calculation["rate"],
				"amount" => $calculation["amount"],
				"effective_at" => $calculation["cutoff"],
				"transaction" => null,
				"reward_event" => null,
			);
			if ($calculation["amount_cents"] > 0) {
				$virtualCreditCents += $calculation["amount_cents"];
			}
		}

		return array(
			"customer" => $customer,
			"status" => "ok",
			"settlements" => $settlements,
			"already_settled" => $alreadySettled,
		);
	}

	private function postingExists($customer, MonthlyInterestPeriod $period)
	{
		return $this->fetchOne(
			"SELECT id
			FROM monthly_interest_postings
			WHERE customer = :customer
			AND period_start = :period_start",
			array(
				"customer" => $customer,
				"period_start" => $period->key() . "-01",
			)
		) !== null;
	}

	public function processCustomer($customer, DateTimeImmutable $asOf)
	{
		$customer = (int) $customer;
		$currentPeriod = MonthlyInterestPeriod::containing($asOf);
		$this->db->beginTransaction();

		try {
			$lockedCustomer = $this->fetchOne(
				"SELECT id
				FROM customers
				WHERE id = :customer
				AND boss = 0
				FOR UPDATE",
				array("customer" => $customer)
			);
			if (!$lockedCustomer) {
				throw new RuntimeException("Interest customer " . $customer . " does not exist or is not eligible.");
			}

			$periods = $this->eligibleClosedPeriods($customer, $currentPeriod);
			$settlements = array();
			$alreadySettled = array();
			foreach ($periods as $period) {
				if ($this->postingExists($customer, $period)) {
					$alreadySettled[] = $period->key();
					continue;
				}

				$settlements[] = $this->settlePeriod($customer, $period);
			}

			$this->db->commit();
			return array(
				"customer" => $customer,
				"status" => "ok",
				"settlements" => $settlements,
				"already_settled" => $alreadySettled,
			);
		} catch (Throwable $error) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $error;
		}
	}

	private function eligibleClosedPeriods($customer, MonthlyInterestPeriod $currentPeriod)
	{
		$rows = $this->fetchAll(
			"SELECT start_period, end_period
			FROM customer_interest_eligibility
			WHERE customer = :customer
			AND start_period < :current_period
			ORDER BY start_period",
			array(
				"customer" => $customer,
				"current_period" => $currentPeriod->key() . "-01",
			)
		);

		$periods = array();
		foreach ($rows as $row) {
			$period = MonthlyInterestPeriod::fromKey(substr($row["start_period"], 0, 7));
			$end = $row["end_period"] === null
				? $currentPeriod
				: MonthlyInterestPeriod::fromKey(substr($row["end_period"], 0, 7));

			while ($period->compare($end) < 0 && $period->compare($currentPeriod) < 0) {
				$periods[$period->key()] = $period;
				$period = $period->next();
				if (count($periods) > 2400) {
					throw new OverflowException("Interest period range is unexpectedly large.");
				}
			}
		}

		ksort($periods);
		return array_values($periods);
	}

	private function settlePeriod($customer, MonthlyInterestPeriod $period)
	{
		$calculation = $this->calculatePeriod($customer, $period);
		$cutoff = $calculation["cutoff"];
		$balanceCents = $calculation["balance_cents"];
		$amountCents = $calculation["amount_cents"];
		$balance = $calculation["balance"];
		$rate = $calculation["rate"];
		$amount = $calculation["amount"];

		$this->execute(
			"INSERT INTO monthly_interest_postings (
				customer, period_start, balance_basis, interest_rate, amount, effective_at
			) VALUES (
				:customer, :period_start, :balance_basis, :interest_rate, :amount, :effective_at
			)",
			array(
				"customer" => $customer,
				"period_start" => $period->key() . "-01",
				"balance_basis" => $balance,
				"interest_rate" => $rate,
				"amount" => $amount,
				"effective_at" => $cutoff,
			)
		);
		$posting = (int) $this->db->lastInsertId();
		$this->notify("after_posting", array(
			"customer" => $customer,
			"posting" => $posting,
			"period" => $period->key(),
		));

		$transaction = null;
		$rewardEvent = null;
		if ($amountCents > 0) {
			$title = "Monatszins " . self::germanMonth($period) . " " . substr($period->key(), 0, 4);
			$this->execute(
				"INSERT INTO transactions (
					customer, datetime, amount, balance, kind, note, approved, undone
				) VALUES (
					:customer, :effective_at, :amount, 0.00, 'monthly_interest', :note, 1, 0
				)",
				array(
					"customer" => $customer,
					"effective_at" => $cutoff,
					"amount" => $amount,
					"note" => $title,
				)
			);
			$transaction = (int) $this->db->lastInsertId();
			$this->recalculateBalances($customer);
			$this->notify("after_transaction", array(
				"customer" => $customer,
				"transaction" => $transaction,
				"period" => $period->key(),
			));

			$balanceAfter = MonthlyInterestMoney::fromCents($balanceCents + $amountCents);
			$this->execute(
				"INSERT INTO reward_events (
					customer, reward_key, reward_type, chest_variant, title, description,
					trigger_value, interest_rate, amount, balance_before, balance_after,
					transaction_id, earned_at
				) VALUES (
					:customer, 'monthly_interest', 'interest', 'gold', :title, :description,
					:trigger_value, :interest_rate, :amount, :balance_before, :balance_after,
					:transaction_id, :earned_at
				)",
				array(
					"customer" => $customer,
					"title" => $title,
					"description" => "Dein Pocket hat den Monatszins erhalten.",
					"trigger_value" => $period->key(),
					"interest_rate" => $rate,
					"amount" => $amount,
					"balance_before" => $balance,
					"balance_after" => $balanceAfter,
					"transaction_id" => $transaction,
					"earned_at" => $cutoff,
				)
			);
			$rewardEvent = (int) $this->db->lastInsertId();
			$this->notify("after_reward", array(
				"customer" => $customer,
				"reward_event" => $rewardEvent,
				"period" => $period->key(),
			));

			$this->execute(
				"UPDATE monthly_interest_postings
				SET transaction_id = :transaction_id,
					reward_event_id = :reward_event_id
				WHERE id = :posting",
				array(
					"transaction_id" => $transaction,
					"reward_event_id" => $rewardEvent,
					"posting" => $posting,
				)
			);
		}

		return array(
			"posting" => $posting,
			"period" => $period->key(),
			"balance_basis" => $balance,
			"rate" => $rate,
			"amount" => $amount,
			"effective_at" => $cutoff,
			"transaction" => $transaction,
			"reward_event" => $rewardEvent,
		);
	}

	private function calculatePeriod($customer, MonthlyInterestPeriod $period, $virtualCreditCents = 0)
	{
		$rateRow = $this->fetchOne(
			"SELECT rate
			FROM monthly_interest_rates
			WHERE effective_period <= :period_start
			ORDER BY effective_period DESC
			LIMIT 1",
			array("period_start" => $period->key() . "-01")
		);
		if (!$rateRow) {
			throw new RuntimeException("No monthly interest rate applies to " . $period->key() . ".");
		}

		$cutoff = $period->cutoffUtc()->format("Y-m-d H:i:s");
		$balanceRow = $this->fetchOne(
			"SELECT COALESCE(SUM(amount), 0.00) AS balance
			FROM transactions
			WHERE customer = :customer
			AND approved = 1
			AND undone = 0
			AND datetime < :cutoff",
			array(
				"customer" => $customer,
				"cutoff" => $cutoff,
			)
		);
		$balanceCents = MonthlyInterestMoney::toCents($balanceRow["balance"]) + (int) $virtualCreditCents;
		$rate = MonthlyInterestMoney::normalizedRate($rateRow["rate"]);
		$amountCents = MonthlyInterestMoney::interestCents($balanceCents, $rate);
		$balance = MonthlyInterestMoney::fromCents($balanceCents);
		$amount = MonthlyInterestMoney::fromCents($amountCents);

		return array(
			"balance_cents" => $balanceCents,
			"amount_cents" => $amountCents,
			"balance" => $balance,
			"rate" => $rate,
			"amount" => $amount,
			"cutoff" => $cutoff,
		);
	}

	private function recalculateBalances($customer)
	{
		$rows = $this->fetchAll(
			"SELECT id, amount
			FROM transactions
			WHERE customer = :customer
			AND approved = 1
			AND undone = 0
			ORDER BY datetime, id",
			array("customer" => $customer)
		);

		$balanceCents = 0;
		foreach ($rows as $row) {
			$balanceCents += MonthlyInterestMoney::toCents($row["amount"]);
			$this->execute(
				"UPDATE transactions
				SET balance = :balance
				WHERE id = :id",
				array(
					"balance" => MonthlyInterestMoney::fromCents($balanceCents),
					"id" => (int) $row["id"],
				)
			);
		}
	}

	private function notify($stage, $context)
	{
		if ($this->stepHook !== null) {
			call_user_func($this->stepHook, $stage, $context);
		}
	}

	private function fetchAll($sql, $params = array())
	{
		$stmt = $this->execute($sql, $params);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	private function fetchOne($sql, $params = array())
	{
		$stmt = $this->execute($sql, $params);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	private function execute($sql, $params = array())
	{
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		return $stmt;
	}

	private static function germanMonth(MonthlyInterestPeriod $period)
	{
		$months = array(
			1 => "Januar",
			2 => "Februar",
			3 => "März",
			4 => "April",
			5 => "Mai",
			6 => "Juni",
			7 => "Juli",
			8 => "August",
			9 => "September",
			10 => "Oktober",
			11 => "November",
			12 => "Dezember",
		);
		return $months[(int) substr($period->key(), 5, 2)];
	}
}

?>
