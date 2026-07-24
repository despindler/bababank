<?php

final class MonthlyInterestDomainException extends InvalidArgumentException
{
}

interface MonthlyInterestClock
{
	public function now();
}

final class SystemMonthlyInterestClock implements MonthlyInterestClock
{
	public function now()
	{
		return new DateTimeImmutable("now");
	}
}

final class FixedMonthlyInterestClock implements MonthlyInterestClock
{
	private $now;

	public function __construct(DateTimeImmutable $now)
	{
		$this->now = $now;
	}

	public function now()
	{
		return $this->now;
	}
}

final class MonthlyInterestMoney
{
	private function __construct()
	{
	}

	public static function toCents($amount)
	{
		$decimal = self::decimalString($amount, "money amount");
		$negative = substr($decimal, 0, 1) === "-";
		if ($negative || substr($decimal, 0, 1) === "+") {
			$decimal = substr($decimal, 1);
		}

		$parts = explode(".", $decimal, 2);
		$whole = ltrim($parts[0], "0");
		$whole = $whole === "" ? "0" : $whole;
		$fraction = isset($parts[1]) ? $parts[1] : "";
		$centsDigits = str_pad(substr($fraction, 0, 2), 2, "0");

		if (strlen($whole) > 16) {
			throw new OverflowException("Money amount is too large.");
		}

		$cents = ((int) $whole * 100) + (int) $centsDigits;
		if (strlen($fraction) > 2 && (int) $fraction[2] >= 5) {
			$cents++;
		}

		return $negative ? -$cents : $cents;
	}

	public static function fromCents($cents)
	{
		$cents = (int) $cents;
		$negative = $cents < 0;
		$absolute = abs($cents);
		$value = intdiv($absolute, 100) . "." . str_pad((string) ($absolute % 100), 2, "0", STR_PAD_LEFT);
		return $negative ? "-" . $value : $value;
	}

	public static function interestCents($balanceCents, $rate)
	{
		$balanceCents = (int) $balanceCents;
		if ($balanceCents <= 0) {
			return 0;
		}

		$fraction = self::rateFraction($rate);
		$numerator = $fraction["numerator"];
		$denominator = $fraction["denominator"];
		if ($numerator === 0) {
			return 0;
		}
		if ($balanceCents > intdiv(PHP_INT_MAX, $numerator)) {
			throw new OverflowException("Interest calculation exceeds the supported integer range.");
		}

		$product = $balanceCents * $numerator;
		return intdiv($product + intdiv($denominator, 2), $denominator);
	}

	public static function interestAmount($balance, $rate)
	{
		return self::fromCents(self::interestCents(self::toCents($balance), $rate));
	}

	public static function normalizedRate($rate)
	{
		$fraction = self::rateFraction($rate);
		return self::fractionToDecimal($fraction["numerator"], $fraction["denominator"], 12);
	}

	public static function ratePercent($rate)
	{
		$fraction = self::rateFraction($rate);
		if ($fraction["numerator"] > intdiv(PHP_INT_MAX, 100)) {
			throw new OverflowException("Interest rate is too large.");
		}
		return self::fractionToDecimal($fraction["numerator"] * 100, $fraction["denominator"], 10);
	}

	private static function rateFraction($rate)
	{
		$decimal = self::decimalString($rate, "interest rate");
		if (substr($decimal, 0, 1) === "-") {
			throw new MonthlyInterestDomainException("Interest rate cannot be negative.");
		}
		if (substr($decimal, 0, 1) === "+") {
			$decimal = substr($decimal, 1);
		}

		$parts = explode(".", $decimal, 2);
		$fractionDigits = isset($parts[1]) ? rtrim($parts[1], "0") : "";
		if (strlen($fractionDigits) > 12) {
			throw new MonthlyInterestDomainException("Interest rate supports at most 12 decimal places.");
		}

		$whole = ltrim($parts[0], "0");
		$whole = $whole === "" ? "0" : $whole;
		$scale = strlen($fractionDigits);
		$denominator = $scale === 0 ? 1 : 10 ** $scale;
		$numeratorString = $whole . $fractionDigits;
		if (strlen($numeratorString) > 18) {
			throw new OverflowException("Interest rate is too large.");
		}

		$numerator = (int) $numeratorString;
		$divisor = self::greatestCommonDivisor($numerator, $denominator);

		return array(
			"numerator" => intdiv($numerator, $divisor),
			"denominator" => intdiv($denominator, $divisor),
		);
	}

	private static function fractionToDecimal($numerator, $denominator, $maximumDecimals)
	{
		$whole = intdiv($numerator, $denominator);
		$remainder = $numerator % $denominator;
		if ($remainder === 0) {
			return (string) $whole;
		}

		$digits = "";
		for ($i = 0; $i < $maximumDecimals && $remainder !== 0; $i++) {
			$remainder *= 10;
			$digits .= (string) intdiv($remainder, $denominator);
			$remainder %= $denominator;
		}

		return $whole . "." . rtrim($digits, "0");
	}

	private static function greatestCommonDivisor($left, $right)
	{
		$left = abs((int) $left);
		$right = abs((int) $right);
		if ($left === 0) {
			return $right === 0 ? 1 : $right;
		}
		while ($right !== 0) {
			$remainder = $left % $right;
			$left = $right;
			$right = $remainder;
		}
		return $left;
	}

	private static function decimalString($value, $label)
	{
		if (is_int($value)) {
			$value = (string) $value;
		} elseif (is_float($value)) {
			if (!is_finite($value)) {
				throw new MonthlyInterestDomainException(ucfirst($label) . " must be finite.");
			}
			$value = rtrim(rtrim(sprintf("%.12F", $value), "0"), ".");
		} elseif (is_string($value)) {
			$value = trim($value);
		} else {
			throw new MonthlyInterestDomainException(ucfirst($label) . " must be numeric.");
		}

		if (!preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/', $value)) {
			throw new MonthlyInterestDomainException(ucfirst($label) . " must be a plain decimal number.");
		}

		return $value;
	}
}

final class MonthlyInterestPeriod
{
	const BUSINESS_TIMEZONE = "Europe/Zurich";

	private $start;

	private function __construct(DateTimeImmutable $start)
	{
		$this->start = $start;
	}

	public static function fromKey($key, ?DateTimeZone $timezone = null)
	{
		$timezone = $timezone ?: self::businessTimezone();
		if (!is_string($key) || !preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $key)) {
			throw new MonthlyInterestDomainException("Interest period must use YYYY-MM.");
		}

		$start = DateTimeImmutable::createFromFormat("!Y-m", $key, $timezone);
		if (!$start || $start->format("Y-m") !== $key) {
			throw new MonthlyInterestDomainException("Interest period is invalid.");
		}

		return new self($start);
	}

	public static function containing(DateTimeImmutable $instant, ?DateTimeZone $timezone = null)
	{
		$timezone = $timezone ?: self::businessTimezone();
		return self::fromKey($instant->setTimezone($timezone)->format("Y-m"), $timezone);
	}

	public static function businessTimezone()
	{
		return new DateTimeZone(self::BUSINESS_TIMEZONE);
	}

	public function key()
	{
		return $this->start->format("Y-m");
	}

	public function startLocal()
	{
		return $this->start;
	}

	public function next()
	{
		return new self($this->start->modify("+1 month"));
	}

	public function previous()
	{
		return new self($this->start->modify("-1 month"));
	}

	public function postingDate()
	{
		return $this->next()->startLocal()->format("Y-m-d");
	}

	public function cutoffUtc()
	{
		return $this->next()->startLocal()->setTimezone(new DateTimeZone("UTC"));
	}

	public function compare(MonthlyInterestPeriod $other)
	{
		return strcmp($this->key(), $other->key());
	}
}

final class MonthlyInterestSchedule
{
	private function __construct()
	{
	}

	public static function closedPeriods($eligibleFrom, DateTimeImmutable $asOf, ?DateTimeZone $timezone = null)
	{
		$timezone = $timezone ?: MonthlyInterestPeriod::businessTimezone();
		$current = MonthlyInterestPeriod::containing($asOf, $timezone);
		$period = MonthlyInterestPeriod::fromKey($eligibleFrom, $timezone);
		$closed = array();

		while ($period->compare($current) < 0) {
			$closed[] = $period;
			$period = $period->next();
			if (count($closed) > 2400) {
				throw new OverflowException("Interest period range is unexpectedly large.");
			}
		}

		return $closed;
	}
}

final class MonthlyInterestRateSchedule
{
	private function __construct()
	{
	}

	public static function rateForPeriod($periodKey, $history)
	{
		$period = MonthlyInterestPeriod::fromKey($periodKey);
		if (!is_array($history) || count($history) === 0) {
			throw new MonthlyInterestDomainException("Interest rate history is empty.");
		}

		$rates = array();
		foreach ($history as $entry) {
			if (!is_array($entry) || !isset($entry["effective_period"]) || !array_key_exists("rate", $entry)) {
				throw new MonthlyInterestDomainException("Interest rate history entry is invalid.");
			}
			$effective = MonthlyInterestPeriod::fromKey($entry["effective_period"]);
			$key = $effective->key();
			if (array_key_exists($key, $rates)) {
				throw new MonthlyInterestDomainException("Interest rate history contains a duplicate effective period.");
			}
			$rates[$key] = MonthlyInterestMoney::normalizedRate($entry["rate"]);
		}
		ksort($rates);

		$selected = null;
		foreach ($rates as $effectiveKey => $rate) {
			if (strcmp($effectiveKey, $period->key()) > 0) {
				break;
			}
			$selected = $rate;
		}
		if ($selected === null) {
			throw new MonthlyInterestDomainException("No interest rate applies to period " . $period->key() . ".");
		}

		return $selected;
	}
}

final class MonthlyInterestProjection
{
	private function __construct()
	{
	}

	public static function build($balance, $rate, MonthlyInterestClock $clock, $enabled = true)
	{
		$now = $clock->now();
		if (!($now instanceof DateTimeImmutable)) {
			throw new MonthlyInterestDomainException("Monthly interest clock must return DateTimeImmutable.");
		}

		return self::buildForPeriod(
			$balance,
			$rate,
			MonthlyInterestPeriod::containing($now),
			$enabled
		);
	}

	public static function buildForPeriod($balance, $rate, MonthlyInterestPeriod $period, $enabled = true)
	{
		$balanceCents = MonthlyInterestMoney::toCents($balance);
		$amountCents = $enabled ? MonthlyInterestMoney::interestCents($balanceCents, $rate) : 0;

		return array(
			"enabled" => (bool) $enabled,
			"period" => $period->key(),
			"balance_basis_estimate" => MonthlyInterestMoney::fromCents($balanceCents),
			"rate" => MonthlyInterestMoney::normalizedRate($rate),
			"rate_percent" => MonthlyInterestMoney::ratePercent($rate),
			"estimated_amount" => MonthlyInterestMoney::fromCents($amountCents),
			"posting_date" => $period->postingDate(),
			"timezone" => MonthlyInterestPeriod::BUSINESS_TIMEZONE,
			"is_estimate" => true,
		);
	}
}

?>
