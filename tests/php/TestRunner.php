<?php

final class TestRunner
{
	private $passed = 0;
	private $failed = 0;

	public function test($name, callable $callback)
	{
		try {
			$callback($this);
			$this->passed++;
			echo "PASS " . $name . PHP_EOL;
		} catch (Throwable $error) {
			$this->failed++;
			echo "FAIL " . $name . PHP_EOL;
			echo "     " . $error->getMessage() . PHP_EOL;
		}
	}

	public function assertSame($expected, $actual, $message = "")
	{
		if ($expected !== $actual) {
			$detail = "Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ".";
			throw new RuntimeException($message !== "" ? $message . " " . $detail : $detail);
		}
	}

	public function assertTrue($actual, $message = "")
	{
		if ($actual !== true) {
			throw new RuntimeException($message !== "" ? $message : "Expected true.");
		}
	}

	public function assertThrows($expectedClass, callable $callback)
	{
		try {
			$callback();
		} catch (Throwable $error) {
			if ($error instanceof $expectedClass) {
				return;
			}
			throw new RuntimeException("Expected " . $expectedClass . ", got " . get_class($error) . ".");
		}

		throw new RuntimeException("Expected " . $expectedClass . " to be thrown.");
	}

	public function finish()
	{
		echo PHP_EOL . $this->passed . " passed, " . $this->failed . " failed." . PHP_EOL;
		return $this->failed === 0 ? 0 : 1;
	}
}

?>
