<?php

class GoogleAuthFailedException extends Exception
{
	private $errorCode;

	public function __construct($errorCode, $message)
	{
		parent::__construct($message);
		$this->errorCode = $errorCode;
	}

	public function getErrorCode()
	{
		return $this->errorCode;
	}
}

class GoogleIdTokenVerifier
{
	private $clientId;
	private $jwksUrl;

	public function __construct($clientId, $jwksUrl)
	{
		$this->clientId = $clientId;
		$this->jwksUrl = $jwksUrl;
	}

	public function verify($credential)
	{
		if (!$this->clientId) {
			throw new GoogleAuthFailedException("GOOGLE_LOGIN_NOT_CONFIGURED", "Google login is not configured.");
		}
		if (!$credential) {
			throw new GoogleAuthFailedException("GOOGLE_CREDENTIAL_REQUIRED", "Google credential is required.");
		}
		if (!function_exists("openssl_verify")) {
			throw new GoogleAuthFailedException("GOOGLE_OPENSSL_UNAVAILABLE", "OpenSSL is required for Google login.");
		}

		$parts = explode(".", $credential);
		if (count($parts) !== 3) {
			throw new GoogleAuthFailedException("INVALID_GOOGLE_TOKEN", "Google credential is invalid.");
		}

		$header = $this->decodeJsonPart($parts[0]);
		$payload = $this->decodeJsonPart($parts[1]);

		if (!isset($header["alg"]) || $header["alg"] !== "RS256") {
			throw new GoogleAuthFailedException("INVALID_GOOGLE_TOKEN", "Google credential uses an unsupported algorithm.");
		}
		if (!isset($header["kid"]) || $header["kid"] === "") {
			throw new GoogleAuthFailedException("INVALID_GOOGLE_TOKEN", "Google credential is missing a key id.");
		}

		$this->verifyClaims($payload);
		$this->verifySignature($credential, $parts, $header["kid"]);

		return array(
			"sub" => (string) $payload["sub"],
			"email" => (string) $payload["email"],
			"email_verified" => true,
			"name" => isset($payload["name"]) ? (string) $payload["name"] : "",
		);
	}

	private function decodeJsonPart($part)
	{
		$json = $this->base64UrlDecode($part);
		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw new GoogleAuthFailedException("INVALID_GOOGLE_TOKEN", "Google credential is invalid.");
		}
		return $data;
	}

	private function verifyClaims($payload)
	{
		if (!isset($payload["sub"]) || $payload["sub"] === "") {
			throw new GoogleAuthFailedException("INVALID_GOOGLE_TOKEN", "Google credential is missing a subject.");
		}
		if (!isset($payload["aud"]) || !$this->audienceMatches($payload["aud"])) {
			throw new GoogleAuthFailedException("GOOGLE_AUDIENCE_MISMATCH", "Google credential audience does not match this application.");
		}
		if (!isset($payload["iss"]) || !in_array($payload["iss"], array("accounts.google.com", "https://accounts.google.com"), true)) {
			throw new GoogleAuthFailedException("INVALID_GOOGLE_ISSUER", "Google credential issuer is invalid.");
		}
		if (!isset($payload["exp"]) || time() >= (int) $payload["exp"]) {
			throw new GoogleAuthFailedException("GOOGLE_TOKEN_EXPIRED", "Google credential has expired.");
		}
		if (!isset($payload["email"]) || $payload["email"] === "") {
			throw new GoogleAuthFailedException("INVALID_GOOGLE_TOKEN", "Google credential is missing an email address.");
		}
		$emailVerified = isset($payload["email_verified"]) && ($payload["email_verified"] === true || $payload["email_verified"] === "true" || $payload["email_verified"] === 1);
		if (!$emailVerified) {
			throw new GoogleAuthFailedException("GOOGLE_EMAIL_NOT_VERIFIED", "Google email is not verified.");
		}
	}

	private function audienceMatches($audience)
	{
		if (is_array($audience)) {
			foreach ($audience as $current) {
				if (hash_equals($this->clientId, (string) $current)) {
					return true;
				}
			}
			return false;
		}

		return hash_equals($this->clientId, (string) $audience);
	}

	private function verifySignature($credential, $parts, $kid)
	{
		$jwks = $this->loadJwks();
		$key = null;
		foreach ($jwks["keys"] as $current) {
			if (isset($current["kid"]) && hash_equals($kid, (string) $current["kid"])) {
				$key = $current;
				break;
			}
		}
		if (!$key) {
			throw new GoogleAuthFailedException("INVALID_GOOGLE_SIGNATURE", "Google signing key was not found.");
		}

		$publicKey = $this->jwkToPem($key);
		$signature = $this->base64UrlDecode($parts[2]);
		$signedData = $parts[0] . "." . $parts[1];
		$verified = openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256);

		if ($verified !== 1) {
			throw new GoogleAuthFailedException("INVALID_GOOGLE_SIGNATURE", "Google credential signature is invalid.");
		}
	}

	private function loadJwks()
	{
		if (!$this->jwksUrl) {
			throw new GoogleAuthFailedException("GOOGLE_KEYS_UNAVAILABLE", "Google JWKS URL is not configured.");
		}

		$json = $this->fetchUrl($this->jwksUrl);
		if ($json === false) {
			throw new GoogleAuthFailedException("GOOGLE_KEYS_UNAVAILABLE", "Google public keys are unavailable.");
		}

		$jwks = json_decode($json, true);
		if (!is_array($jwks) || !isset($jwks["keys"]) || !is_array($jwks["keys"])) {
			throw new GoogleAuthFailedException("GOOGLE_KEYS_UNAVAILABLE", "Google public keys are invalid.");
		}

		return $jwks;
	}

	private function fetchUrl($url)
	{
		if (ini_get("allow_url_fopen")) {
			$context = stream_context_create(array(
				"http" => array(
					"timeout" => 5,
					"header" => "User-Agent: BaBaBank/1.0\r\n",
				),
			));
			$response = @file_get_contents($url, false, $context);
			if ($response !== false) {
				return $response;
			}
		}

		if (function_exists("curl_init")) {
			$curl = curl_init($url);
			curl_setopt_array($curl, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => 10,
				CURLOPT_USERAGENT => "BaBaBank/1.0",
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
			));
			$response = curl_exec($curl);
			$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
			curl_close($curl);

			if ($response !== false && $status >= 200 && $status < 300) {
				return $response;
			}
		}

		return false;
	}

	private function jwkToPem($key)
	{
		if (isset($key["x5c"][0]) && $key["x5c"][0] !== "") {
			return "-----BEGIN CERTIFICATE-----\n" . chunk_split($key["x5c"][0], 64, "\n") . "-----END CERTIFICATE-----\n";
		}

		if (!isset($key["n"]) || !isset($key["e"])) {
			throw new GoogleAuthFailedException("INVALID_GOOGLE_SIGNATURE", "Google public key is invalid.");
		}

		$modulus = $this->base64UrlDecode($key["n"]);
		$exponent = $this->base64UrlDecode($key["e"]);
		$rsaPublicKey = $this->asn1Sequence(
			$this->asn1Integer($modulus) .
			$this->asn1Integer($exponent)
		);
		$publicKeyInfo = $this->asn1Sequence(
			$this->asn1Sequence(
				$this->asn1ObjectIdentifier("1.2.840.113549.1.1.1") .
				$this->asn1Null()
			) .
			$this->asn1BitString($rsaPublicKey)
		);

		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($publicKeyInfo), 64, "\n") . "-----END PUBLIC KEY-----\n";
	}

	private function base64UrlDecode($value)
	{
		$padded = strtr($value, "-_", "+/");
		$padding = strlen($padded) % 4;
		if ($padding > 0) {
			$padded .= str_repeat("=", 4 - $padding);
		}
		$decoded = base64_decode($padded, true);
		if ($decoded === false) {
			throw new GoogleAuthFailedException("INVALID_GOOGLE_TOKEN", "Google credential is invalid.");
		}
		return $decoded;
	}

	private function asn1Length($length)
	{
		if ($length < 128) {
			return chr($length);
		}

		$bytes = "";
		while ($length > 0) {
			$bytes = chr($length & 0xff) . $bytes;
			$length >>= 8;
		}

		return chr(0x80 | strlen($bytes)) . $bytes;
	}

	private function asn1Sequence($value)
	{
		return chr(0x30) . $this->asn1Length(strlen($value)) . $value;
	}

	private function asn1Integer($value)
	{
		if (ord($value[0]) > 0x7f) {
			$value = chr(0x00) . $value;
		}
		return chr(0x02) . $this->asn1Length(strlen($value)) . $value;
	}

	private function asn1BitString($value)
	{
		$value = chr(0x00) . $value;
		return chr(0x03) . $this->asn1Length(strlen($value)) . $value;
	}

	private function asn1Null()
	{
		return chr(0x05) . chr(0x00);
	}

	private function asn1ObjectIdentifier($oid)
	{
		$parts = array_map("intval", explode(".", $oid));
		$value = chr(40 * $parts[0] + $parts[1]);
		for ($i = 2; $i < count($parts); $i++) {
			$current = $parts[$i];
			$encoded = chr($current & 0x7f);
			$current >>= 7;
			while ($current > 0) {
				$encoded = chr(0x80 | ($current & 0x7f)) . $encoded;
				$current >>= 7;
			}
			$value .= $encoded;
		}
		return chr(0x06) . $this->asn1Length(strlen($value)) . $value;
	}
}

function googleAuthConfig()
{
	$clientId = envValue("GOOGLE_CLIENT_ID", "");
	return array(
		"success" => true,
		"result" => true,
		"google_client_id" => $clientId !== "" ? $clientId : null,
	);
}

function googleTokenVerifier()
{
	return new GoogleIdTokenVerifier(
		envValue("GOOGLE_CLIENT_ID", ""),
		envValue("GOOGLE_JWKS_URL", "https://www.googleapis.com/oauth2/v3/certs")
	);
}

?>
