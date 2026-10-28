<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$user = require_login();
$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$paymentId || $paymentId < 1) {
    http_response_code(404);
    exit('QR reference not found.');
}
$statement = db()->prepare(
    'SELECT p.payment_id, p.payment_status, p.payer_user_id, q.qr_token, q.is_used, q.expires_at
     FROM payments p JOIN qr_codes q ON q.payment_id = p.payment_id WHERE p.payment_id = :id'
);
$statement->execute(['id' => $paymentId]);
$record = $statement->fetch();
if (!$record || ($user['role_name'] === 'Student' && (int) $record['payer_user_id'] !== (int) $user['user_id'])) {
    http_response_code(404);
    exit('QR reference not found.');
}
if (!in_array($user['role_name'], ['Admin', 'Officer', 'Student'], true)) {
    http_response_code(403);
    exit('Not authorized.');
}
if (qr_token_state($record) !== 'valid') {
    http_response_code(410);
    exit('This QR payment reference has expired or is no longer valid.');
}
if (!class_exists(\Endroid\QrCode\Writer\SvgWriter::class)) {
    http_response_code(503);
    exit('QR image support is not installed. Run composer install and reload.');
}
$qr = new \Endroid\QrCode\QrCode(
    data: app_absolute_url('qr.php?token=' . rawurlencode($record['qr_token'])),
    encoding: new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
    errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Low,
    size: 320,
    margin: 10
);
$image = (new \Endroid\QrCode\Writer\SvgWriter())->write($qr);
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $image->getString();
