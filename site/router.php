<?php

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$file = __DIR__ . str_replace("/", DIRECTORY_SEPARATOR, $path);

if ($path !== "/" && is_file($file)) {
	return false;
}

if (strpos($path, "/backend") === 0) {
	$_SERVER["SCRIPT_NAME"] = "/backend/backend.php";
	$_SERVER["SCRIPT_FILENAME"] = __DIR__ . DIRECTORY_SEPARATOR . "backend" . DIRECTORY_SEPARATOR . "backend.php";
	require $_SERVER["SCRIPT_FILENAME"];
	return true;
}

return false;

?>
