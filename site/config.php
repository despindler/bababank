<?php

function loadEnvFile($path, $override = false)
{
	if (!is_readable($path)) {
		return;
	}

	$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($lines === false) {
		return;
	}

	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || strpos($line, '#') === 0) {
			continue;
		}

		if (strpos($line, 'export ') === 0) {
			$line = trim(substr($line, 7));
		}

		$separator = strpos($line, '=');
		if ($separator === false) {
			continue;
		}

		$key = trim(substr($line, 0, $separator));
		$value = trim(substr($line, $separator + 1));

		if ($key === '') {
			continue;
		}

		$first = substr($value, 0, 1);
		$last = substr($value, -1);
		if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
			$value = substr($value, 1, -1);
		}

		if ($override || getenv($key) === false) {
			putenv($key . '=' . $value);
			$_ENV[$key] = $value;
			$_SERVER[$key] = $value;
		}
	}
}

function envValue($key, $default = null)
{
	$value = getenv($key);
	if ($value !== false) {
		return $value;
	}
	if (array_key_exists($key, $_ENV)) {
		return $_ENV[$key];
	}
	if (array_key_exists($key, $_SERVER)) {
		return $_SERVER[$key];
	}
	return $default;
}

function requiredEnvValue($key)
{
	$value = envValue($key);
	if ($value === null || $value === '') {
		throw new RuntimeException('Missing required environment variable: ' . $key);
	}
	return $value;
}

$appRoot = __DIR__;
$projectRoot = dirname(__DIR__);
$envRoots = array_values(array_unique(array($appRoot, $projectRoot)));
$envFile = envValue('BABABANK_ENV_FILE');

if ($envFile !== null && $envFile !== '') {
	if (!preg_match('/^([A-Za-z]:)?[\/\\\\]/', $envFile)) {
		foreach ($envRoots as $root) {
			$candidate = $root . DIRECTORY_SEPARATOR . $envFile;
			if (is_readable($candidate)) {
				$envFile = $candidate;
				break;
			}
		}
	}
	loadEnvFile($envFile, true);
} else {
	foreach ($envRoots as $root) {
		$candidate = $root . DIRECTORY_SEPARATOR . '.env';
		if (is_readable($candidate)) {
			loadEnvFile($candidate);
			break;
		}
	}

	$environment = envValue('BABABANK_ENV', envValue('APP_ENV'));
	if ($environment !== null && $environment !== '') {
		foreach ($envRoots as $root) {
			$candidate = $root . DIRECTORY_SEPARATOR . '.env.' . $environment;
			if (is_readable($candidate)) {
				loadEnvFile($candidate, true);
				break;
			}
		}
	}
}

define('DB_HOST', requiredEnvValue('DB_HOST'));
define('DB_NAME', requiredEnvValue('DB_NAME'));
define('DB_USER', requiredEnvValue('DB_USER'));
define('DB_PASSWORD', envValue('DB_PASSWORD', ''));

?>
