<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$token = $_GET['token'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit('Payment reference not found.');
}
$statement = db()->prepare(
    'SELECT q.is_used, q.expires_at, p.payment_status, p.payment_reference, p.amount_paid,
            ch.channel_name, ch.details AS channel_details
     FROM qr_codes q JOIN payments p ON p.payment_id = q.payment_id
     LEFT JOIN payment_channel_assignments a ON a.payment_id = p.payment_id
     LEFT JOIN payment_channels ch ON ch.channel_id = a.channel_id
     WHERE q.qr_token = :token LIMIT 1'
);
$statement->execute(['token' => $token]);
$reference = $statement->fetch();
if (!$reference) {
    http_response_code(404);
    exit('Payment reference not found.');
}
$state = qr_token_state($reference);
header('Cache-Control: no-store, max-age=0');
if ($state !== 'valid') {
    http_response_code($state === 'expired' ? 410 : 409);
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Payment reference | CPC</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>"></head>
<body class="auth-page">
    <main class="auth-card">
        <div class="auth-brand"><span class="brand-mark">CPC</span><span>CPC Penalty System</span></div>
        <?php if ($state !== 'valid'): ?>
            <h1>Reference unavailable</h1>
            <p class="muted"><?= e(match ($state) { 'expired' => 'This QR payment reference has expired.', 'used' => 'This QR payment reference has already been used.', default => 'This payment is no longer pending.' }) ?></p>
        <?php else: ?>
            <h1>Payment reference</h1>
            <p class="alert info">This page and its QR code are references only. Scanning does not transfer money. Use the selected payment channel, then wait for CPC staff to verify the transaction.</p>
            <dl class="reference-list">
                <div><dt>Reference</dt><dd><?= e($reference['payment_reference']) ?></dd></div>
                <div><dt>Amount</dt><dd>₱<?= e(number_format((float) $reference['amount_paid'], 2)) ?></dd></div>
                <div><dt>Channel</dt><dd><?= e($reference['channel_name'] ?? 'Payment channel') ?></dd></div>
                <?php if (!empty($reference['channel_details'])): ?><div><dt>Instructions</dt><dd><?= e($reference['channel_details']) ?></dd></div><?php endif; ?>
                <div><dt>Status</dt><dd>Awaiting verification</dd></div>
                <div><dt>Expires</dt><dd><?= e(date('M j, Y g:i A', strtotime($reference['expires_at']))) ?></dd></div>
            </dl>
        <?php endif; ?>
    </main>
</body>
</html>
