<?php

function authBoolEnv($key, $default)
{
	$value = envValue($key, null);
	if ($value === null || $value === '') {
		return $default;
	}

	$value = strtolower(trim($value));
	return in_array($value, array('1', 'true', 'yes', 'on'), true);
}

function authIsProduction()
{
	$environment = envValue('BABABANK_ENV', envValue('APP_ENV', 'development'));
	return strtolower($environment) === 'production';
}

function authStartSession()
{
	if (session_status() === PHP_SESSION_ACTIVE) {
		return;
	}

	$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off';
	$secure = authBoolEnv('SESSION_SECURE', authIsProduction() || $isHttps);
	$sameSite = envValue('SESSION_SAMESITE', 'Lax');

	session_name(envValue('SESSION_NAME', 'bababank_session'));
	session_set_cookie_params(array(
		'lifetime' => 0,
		'path' => '/',
		'domain' => '',
		'secure' => $secure,
		'httponly' => true,
		'samesite' => $sameSite,
	));
	session_start();
}

function authUserForSession($customer)
{
	return array(
		'id' => (int) $customer['id'],
		'fullname' => $customer['fullname'],
		'email' => isset($customer['email']) ? $customer['email'] : null,
		'display_name' => isset($customer['display_name']) ? $customer['display_name'] : null,
		'realm' => (int) $customer['realm'],
		'boss' => (int) $customer['boss'],
	);
}

function authUserForClient($user)
{
	if (!$user) {
		return null;
	}

	return array(
		'id' => (int) $user['id'],
		'fullname' => $user['fullname'],
		'email' => isset($user['email']) ? $user['email'] : null,
		'display_name' => isset($user['display_name']) ? $user['display_name'] : null,
		'realm' => (int) $user['realm'],
		'boss' => (int) $user['boss'],
	);
}

function authLoginCustomer($customer)
{
	authStartSession();
	session_regenerate_id(true);
	$_SESSION['user'] = authUserForSession($customer);
	return $_SESSION['user'];
}

function authCurrentUser()
{
	authStartSession();
	if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
		return null;
	}
	return $_SESSION['user'];
}

function authLogout()
{
	authStartSession();
	$_SESSION = array();

	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
	}

	session_destroy();
}

?>
