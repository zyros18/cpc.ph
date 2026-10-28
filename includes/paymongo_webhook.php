<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/paymongo.php';

function paymongo_webhook_fail(int $status, string $message): never
{
	http_response_code($status);
	header('Content-Type: text/plain; charset=utf-8');
	exit($message);
}

$webhookSecret = getenv('CPC_PAYMONGO_WEBHOOK_SECRET') ?: '';
if ($webhookSecret === '') {
	paymongo_webhook_fail(503, 'Webhook is not configured.');
}

$rawBody = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_X_PAYMONGO_SIGNATURE'] ?? '';
if (!is_string($rawBody) || $rawBody === '' || !is_string($signatureHeader)) {
	paymongo_webhook_fail(400, 'Invalid webhook request.');
}

if (!paymongo_webhook_signature_valid($rawBody, $signatureHeader, $webhookSecret)) {
	paymongo_webhook_fail(401, 'Webhook signature is invalid.');
}

try {
	$event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
	paymongo_webhook_fail(400, 'Invalid webhook payload.');
}

$eventData = is_array($event) ? ($event['data'] ?? []) : [];
if (!is_array($eventData)) {
	paymongo_webhook_fail(400, 'Invalid webhook event.');
}
if (($eventData['type'] ?? '') !== 'checkout_session.payment.paid') {
	http_response_code(200);
	exit('Event ignored.');
}

$session = $eventData['data'] ?? [];
$attributes = is_array($session) ? ($session['attributes'] ?? []) : [];
if (!is_array($attributes) || !is_array($session)) {
	paymongo_webhook_fail(400, 'Invalid checkout session.');
}
$sessionId = $session['id'] ?? '';
$reference = $attributes['reference_number'] ?? '';
$metadata = $attributes['metadata'] ?? [];
$metadataPenaltyId = is_array($metadata)
	? filter_var($metadata['penalty_id'] ?? null, FILTER_VALIDATE_INT)
	: false;
$paid = false;
$payments = $attributes['payments'] ?? [];
if (!is_array($payments)) {
	paymongo_webhook_fail(400, 'Invalid checkout payment data.');
}
foreach ($payments as $payment) {
	if (!is_array($payment)) {
		continue;
	}
	$paymentAttributes = $payment['attributes'] ?? [];
	if (!is_array($paymentAttributes)) {
		continue;
	}
	if (($paymentAttributes['status'] ?? '') === 'paid' &&
		($paymentAttributes['currency'] ?? '') === 'PHP' &&
		(int) ($paymentAttributes['net_amount'] ?? 0) > 0) {
		$paid = true;
		break;
	}
}

if (($event['data']['livemode'] ?? true) !== false || !is_string($sessionId) || $sessionId === '' ||
	!is_string($reference) || $reference === '' || !$metadataPenaltyId || !$paid) {
	paymongo_webhook_fail(400, 'Payment event is invalid or not a test-mode payment.');
}

try {
	$db = database();
	$db->beginTransaction();
	$checkoutQuery = $db->prepare(
		"SELECT c.checkout_id, c.penalty_id, c.amount, c.checkout_status, p.status, p.student_id
		 FROM paymongo_checkouts c
		 INNER JOIN penalties p ON p.id = c.penalty_id
		 WHERE c.paymongo_session_id = :session_id
		   AND c.reference_number = :reference
		   AND c.penalty_id = :penalty_id
		 FOR UPDATE"
	);
	$checkoutQuery->execute([
		'session_id' => $sessionId,
		'reference' => $reference,
		'penalty_id' => $metadataPenaltyId,
	]);
	$checkout = $checkoutQuery->fetch();
	if (!$checkout) {
		$db->rollBack();
		paymongo_webhook_fail(404, 'Checkout session not found.');
	}
	if ($checkout['checkout_status'] === 'paid' && $checkout['status'] === 'paid') {
		$db->commit();
		http_response_code(200);
		exit('Payment already recorded.');
	}

	$expectedNetAmount = paymongo_amount_centavos((string) $checkout['amount']);
	$paymentMatchesAmount = false;
	foreach ($payments as $payment) {
		if (!is_array($payment)) {
			continue;
		}
		$paymentAttributes = $payment['attributes'] ?? [];
		if (!is_array($paymentAttributes)) {
			continue;
		}
		if (($paymentAttributes['status'] ?? '') === 'paid' &&
			($paymentAttributes['currency'] ?? '') === 'PHP' &&
			(int) ($paymentAttributes['net_amount'] ?? -1) === $expectedNetAmount) {
			$paymentMatchesAmount = true;
			break;
		}
	}
	if (!$paymentMatchesAmount || $checkout['status'] !== 'pending') {
		$db->rollBack();
		paymongo_webhook_fail(409, 'Payment amount or penalty status does not match.');
	}

	$db->prepare(
		"UPDATE penalties
		 SET status = 'paid', paid_at = NOW()
		 WHERE id = :penalty_id AND status = 'pending'
		   AND payment_method = 'online' AND reference_number = :reference"
	)->execute(['penalty_id' => $checkout['penalty_id'], 'reference' => $reference]);
	if ((int) $db->query('SELECT ROW_COUNT()')->fetchColumn() !== 1) {
		$db->rollBack();
		paymongo_webhook_fail(409, 'Penalty status could not be updated.');
	}
	$db->prepare(
		"UPDATE paymongo_checkouts SET checkout_status = 'paid' WHERE checkout_id = :checkout_id"
	)->execute(['checkout_id' => $checkout['checkout_id']]);
	$db->commit();
	http_response_code(200);
	exit('Payment recorded.');
} catch (Throwable $exception) {
	if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
		$db->rollBack();
	}
	error_log('PayMongo webhook processing failed: ' . $exception->getMessage());
	paymongo_webhook_fail(500, 'Webhook processing failed.');
}
