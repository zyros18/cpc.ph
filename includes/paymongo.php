<?php
declare(strict_types=1);

function paymongo_secret_key(): string
{
	$key = getenv('CPC_PAYMONGO_SECRET_KEY') ?: '';
	if (!preg_match('/^sk_test_[A-Za-z0-9_-]+$/', $key)) {
		throw new RuntimeException('PayMongo test mode is not configured. Set CPC_PAYMONGO_SECRET_KEY to your test secret key.');
	}
	return $key;
}

function paymongo_base_url(): string
{
	$baseUrl = rtrim(getenv('CPC_APP_URL') ?: '', '/');
	if (!filter_var($baseUrl, FILTER_VALIDATE_URL) ||
		!in_array(strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
		throw new RuntimeException('Set CPC_APP_URL to the absolute URL of this CPC installation.');
	}
	return $baseUrl;
}

function paymongo_api_request(string $method, string $path, ?array $payload = null): array
{
	if (!function_exists('curl_init')) {
		throw new RuntimeException('PHP cURL is required to connect to PayMongo. Enable the cURL extension in XAMPP.');
	}

	$curl = curl_init('https://api.paymongo.com/v2/' . ltrim($path, '/'));
	if ($curl === false) {
		throw new RuntimeException('Could not initialize the PayMongo connection.');
	}

	$options = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CUSTOMREQUEST => strtoupper($method),
		CURLOPT_USERPWD => paymongo_secret_key() . ':',
		CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
		CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT => 25,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
	];
	if ($payload !== null) {
		$options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
	}
	curl_setopt_array($curl, $options);
	$responseBody = curl_exec($curl);
	$statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
	$error = curl_error($curl);
	curl_close($curl);

	if (!is_string($responseBody)) {
		error_log('PayMongo request failed: ' . $error);
		throw new RuntimeException('Could not connect to PayMongo. Check the server connection and try again.');
	}

	try {
		$response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
	} catch (JsonException $exception) {
		error_log('PayMongo returned invalid JSON with HTTP status ' . $statusCode);
		throw new RuntimeException('PayMongo returned an invalid response. Please try again.');
	}

	if ($statusCode < 200 || $statusCode >= 300) {
		error_log('PayMongo API request rejected with HTTP status ' . $statusCode);
		throw new RuntimeException('PayMongo could not start this payment. Please check the test-mode account configuration and try again.');
	}

	return $response;
}

function paymongo_amount_centavos(string $amount): int
{
	if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount)) {
		throw new RuntimeException('The penalty amount is invalid.');
	}
	[$pesos, $centavos] = array_pad(explode('.', $amount, 2), 2, '0');
	return ((int) $pesos * 100) + (int) str_pad($centavos, 2, '0');
}

function paymongo_webhook_signature_valid(string $rawBody, string $signatureHeader, string $secret): bool
{
	$signatureParts = [];
	foreach (explode(',', $signatureHeader) as $part) {
		[$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
		if ($key !== '' && $value !== '') {
			$signatureParts[$key] = $value;
		}
	}
	$timestamp = $signatureParts['t'] ?? '';
	$testSignature = $signatureParts['te'] ?? '';
	if (!preg_match('/^\d+$/', $timestamp) || $testSignature === '' || $secret === '') {
		return false;
	}
	return hash_equals(hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret), $testSignature);
}
