<?php

require_once __DIR__ . "/database.php";

function rewardEnabled($key)
{
	$config = dbRewardConfigValue("reward_" . strtolower($key) . "_enabled");
	if ($config !== null && $config !== "") {
		$config = strtolower(trim((string) $config));
		return in_array($config, array("1", "true", "yes", "on"), true);
	}
	return envBool("REWARD_" . strtoupper($key) . "_ENABLED", true);
}

function rewardRate($key, $fallback = "ACHIEVEMENT_INTEREST_RATE")
{
	$specific = strtoupper($key) . "_REWARD_RATE";
	$config = dbRewardConfigValue(strtolower($specific));
	if ($config !== null && $config !== "") {
		return (float) $config;
	}
	return envFloat($specific, envFloat($fallback, 0.0008));
}

function rewardMonthlyRate()
{
	$config = dbRewardConfigValue("monthly_interest_rate");
	if ($config !== null && $config !== "") {
		return (float) $config;
	}
	return envFloat("MONTHLY_INTEREST_RATE", 0.0008);
}

function rewardSavingsMilestoneStep()
{
	$config = dbRewardConfigValue("savings_milestone_step");
	if ($config !== null && (float) $config > 0) {
		return (float) $config;
	}
	return 100.0;
}

function rewardAmountForBalance($balance, $rate)
{
	$amount = round(max(0, (float) $balance) * (float) $rate, 2);
	return $amount > 0 ? $amount : 0;
}

function rewardCurrentPeriod()
{
	return date("Y-m");
}

function rewardToday()
{
	return date("Y-m-d");
}

function rewardsCreateEvent($event)
{
	return dbInsertRewardEvent($event);
}

function rewardsCreateInterestEvent($customer, $rewardKey, $title, $description, $triggerValue, $rate, $chestVariant)
{
	$balanceBefore = dbBalanceByCustomer($customer);
	$amount = rewardAmountForBalance($balanceBefore, $rate);
	if ($amount <= 0) {
		return null;
	}

	$transaction = dbInsertSystemTransaction($customer, $amount, "reward_interest", $title);
	$balanceAfter = dbBalanceByCustomer($customer);

	return rewardsCreateEvent(array(
		"customer" => (int) $customer,
		"reward_key" => $rewardKey,
		"reward_type" => "interest",
		"chest_variant" => $chestVariant,
		"title" => $title,
		"description" => $description,
		"trigger_value" => $triggerValue,
		"interest_rate" => $rate,
		"amount" => $amount,
		"balance_before" => $balanceBefore,
		"balance_after" => $balanceAfter,
		"transaction_id" => $transaction["id"],
	));
}

function rewardsCreateDepositEvent($movement)
{
	if (!rewardEnabled("DEPOSIT") || (float) $movement["amount"] <= 0) {
		return null;
	}

	return rewardsCreateEvent(array(
		"customer" => (int) $movement["customer"],
		"reward_key" => "deposit",
		"reward_type" => "money",
		"chest_variant" => "gold",
		"title" => "Einzahlung erhalten",
		"description" => "Geld ist in deinem Pocket gelandet.",
		"trigger_value" => null,
		"interest_rate" => null,
		"amount" => (float) $movement["amount"],
		"balance_before" => (float) $movement["balance_before"],
		"balance_after" => (float) $movement["balance_after"],
		"transaction_id" => (int) $movement["id"],
	));
}

function rewardsManualCounts($customer)
{
	return dbNofInAndOut($customer);
}

function rewardsInitializeCustomer($customer)
{
	dbSetRewardState((int) $customer, "savings_level", "0");
	dbSetRewardState((int) $customer, "input_lead_active", "0");
}

function rewardsEvaluateAchievementsForCustomer($customer)
{
	$customer = (int) $customer;
	$balance = dbBalanceByCustomer($customer);
	$step = rewardSavingsMilestoneStep();
	$currentSavingsLevel = (int) floor(max(0, $balance) / $step);
	$previousSavingsLevel = (int) dbRewardState($customer, "savings_level", 0);

	if ($currentSavingsLevel > $previousSavingsLevel && rewardEnabled("SAVINGS_MILESTONE")) {
		$rate = rewardRate("SAVINGS_MILESTONE");
		for ($level = $previousSavingsLevel + 1; $level <= $currentSavingsLevel; $level++) {
			rewardsCreateInterestEvent(
				$customer,
				"savings_milestone",
				"Level " . $level . " erreicht",
				"Du hast " . moneylessNumber($level * $step) . " erreicht und bekommst Zins.",
				(string) ($level * $step),
				$rate,
				"crystals"
			);
		}
	}
	dbSetRewardState($customer, "savings_level", $currentSavingsLevel);

	$counts = rewardsManualCounts($customer);
	$inputLeadActive = ((int) $counts["nofin"]) > ((int) $counts["nofout"]);
	$previousInputLead = dbRewardState($customer, "input_lead_active", "0") === "1";

	if (!$previousInputLead && $inputLeadActive && rewardEnabled("INPUT_LEAD")) {
		rewardsCreateInterestEvent(
			$customer,
			"input_lead",
			"Mehr Ein als Aus",
			"Deine Einzahlungen liegen vorne.",
			((int) $counts["nofin"]) . "/" . ((int) $counts["nofout"]),
			rewardRate("INPUT_LEAD"),
			"crystals"
		);
	}
	dbSetRewardState($customer, "input_lead_active", $inputLeadActive ? "1" : "0");
}

function moneylessNumber($value)
{
	if (floor($value) == $value) {
		return (string) (int) $value;
	}
	return number_format((float) $value, 2, ".", "");
}

function rewardsAfterManualMovement($movement)
{
	rewardsCreateDepositEvent($movement);
	rewardsEvaluateAchievementsForCustomer((int) $movement["customer"]);
	return dbBalanceByCustomer((int) $movement["customer"]);
}

function rewardsRunLazyMonthlyForCustomer($customer)
{
	$customer = (int) $customer;
	if (!rewardEnabled("MONTHLY_INTEREST")) {
		return;
	}

	$period = rewardCurrentPeriod();
	if (dbRewardState($customer, "monthly_interest_period", "") === $period) {
		return;
	}

	dbSetRewardState($customer, "monthly_interest_period", $period);
	$rate = rewardMonthlyRate();
	$event = rewardsCreateInterestEvent(
		$customer,
		"monthly_interest",
		"Monatszins",
		"Dein Pocket hat Zins bekommen.",
		$period,
		$rate,
		"gold"
	);

	if ($event !== null) {
		rewardsEvaluateAchievementsForCustomer($customer);
	}
}

function rewardsDailyQueueForCustomer($customer)
{
	$customer = (int) $customer;
	rewardsRunLazyMonthlyForCustomer($customer);

	$events = dbUnopenedRewardEvents($customer);
	if (count($events) > 0) {
		dbSetRewardState($customer, "last_daily_chest_date", rewardToday());
	}
	return $events;
}

?>
