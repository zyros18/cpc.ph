<?php
$isStudent = $user['role_name'] === 'Student';
if (!$isStudent) {
    require_roles('Admin', 'Officer');
}

$params = [];
$where = '1 = 1';
if ($isStudent) {
    $where = 'p.payer_user_id = :payer_id';
    $params['payer_id'] = $user['user_id'];
}
$paymentQuery = db()->prepare(
    "SELECT p.payment_id, p.penalty_id, p.payer_user_id, p.amount_paid, p.payment_reference, p.payment_status,
            p.payment_date, p.verified_at, pe.penalty_title, pe.student_user_id, s.full_name AS student_name,
            s.student_id, ch.channel_name, ch.channel_type, a.transaction_details, q.qr_token, q.expires_at, q.is_used
     FROM payments p JOIN penalties pe ON pe.penalty_id = p.penalty_id
     JOIN users s ON s.user_id = pe.student_user_id
     LEFT JOIN payment_channel_assignments a ON a.payment_id = p.payment_id
     LEFT JOIN payment_channels ch ON ch.channel_id = a.channel_id
     LEFT JOIN qr_codes q ON q.payment_id = p.payment_id
     WHERE {$where} ORDER BY p.created_at DESC"
);
$paymentQuery->execute($params);
$payments = $paymentQuery->fetchAll();

$unpaidPenalties = [];
$channels = [];
if ($isStudent) {
    $available = db()->prepare(
        "SELECT pe.penalty_id, pe.penalty_title, pe.amount FROM penalties pe
         WHERE pe.student_user_id = :student AND pe.status = 'unpaid'
         AND NOT EXISTS (
             SELECT 1 FROM payments p WHERE p.penalty_id = pe.penalty_id AND (
                 p.payment_status = 'verified'
                 OR (p.payment_status = 'pending' AND EXISTS (
                     SELECT 1 FROM qr_codes q
                     WHERE q.payment_id = p.payment_id AND q.is_used = 0 AND q.expires_at > NOW()
                 ))
             )
         )
         ORDER BY pe.created_at DESC"
    );
    $available->execute(['student' => $user['user_id']]);
    $unpaidPenalties = $available->fetchAll();
    $channels = db()->query('SELECT channel_id, channel_name, channel_type, details FROM payment_channels WHERE is_active = 1 ORDER BY channel_name')->fetchAll();
}

$pendingPayments = [];
if (!$isStudent) {
    $pendingPayments = db()->query(
        "SELECT p.payment_id, p.payment_reference, p.amount_paid, p.payment_date, p.payer_user_id,
                pe.penalty_title, s.full_name, s.student_id, ch.channel_name, a.transaction_details
         FROM payments p JOIN penalties pe ON pe.penalty_id = p.penalty_id
         JOIN users s ON s.user_id = pe.student_user_id
         LEFT JOIN payment_channel_assignments a ON a.payment_id = p.payment_id
         LEFT JOIN payment_channels ch ON ch.channel_id = a.channel_id
         WHERE p.payment_status = 'pending' ORDER BY p.payment_date"
    )->fetchAll();
}
?>
<header class="page-heading"><div><p class="eyebrow"><?= $isStudent ? 'Personal account' : 'Transactions' ?></p><h1><?= $isStudent ? 'My Payments' : 'Payments' ?></h1><p class="muted"><?= $isStudent ? 'Initiate and track payment requests for your penalties.' : 'Review pending requests and payment history.' ?></p></div></header>

<?php if ($isStudent): ?>
    <section class="panel">
        <h2>Start a payment</h2>
        <?php if (!$unpaidPenalties): ?><p class="muted">There are no unpaid penalties available for payment.</p>
        <?php elseif (!$channels): ?><p class="muted">No active payment channels are available. Contact an officer.</p>
        <?php else: ?>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="initiate_payment"><input type="hidden" name="return_to" value="index.php?page=payments">
                <label>Unpaid penalty<select name="penalty_id" required><option value="">Select penalty</option><?php foreach ($unpaidPenalties as $penalty): ?><option value="<?= (int) $penalty['penalty_id'] ?>"><?= e($penalty['penalty_title'] . ' — ₱' . number_format((float) $penalty['amount'], 2)) ?></option><?php endforeach; ?></select></label>
                <fieldset class="span-2 channel-fieldset">
                    <legend>Choose a payment channel</legend>
                    <div class="channel-options">
                        <?php foreach ($channels as $channel): ?>
                            <label class="channel-option">
                                <input type="radio" name="channel_id" value="<?= (int) $channel['channel_id'] ?>" required>
                                <span class="channel-copy">
                                    <strong><?= e($channel['channel_name']) ?></strong>
                                    <small><?= e(ucwords(str_replace('_', ' ', $channel['channel_type']))) ?></small>
                                    <?php if (!empty($channel['details'])): ?><small class="channel-details"><?= e($channel['details']) ?></small><?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <label class="span-2">Transaction reference or note <input name="transaction_details" maxlength="500" placeholder="Optional; enter an external reference if you already paid"></label>
                <p class="help-text span-2">Creating a request generates a payment QR reference. Scanning does not transfer money; a CPC officer must verify the transaction before your penalty is marked paid.</p>
                <div class="form-actions span-2"><button class="button primary" type="submit">Create payment request</button></div>
            </form>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="panel">
        <div class="panel-heading"><h2>Pending verification</h2><span class="badge pending"><?= count($pendingPayments) ?> pending</span></div>
        <div class="table-scroll"><table><thead><tr><th>Reference</th><th>Student</th><th>Penalty</th><th>Amount</th><th>Channel</th><th>Transaction details</th><th>Received</th><th>Review</th></tr></thead><tbody>
        <?php if (!$pendingPayments): ?><tr><td class="empty" colspan="8">No payments waiting for verification.</td></tr><?php endif; ?>
        <?php foreach ($pendingPayments as $pending): ?>
            <tr>
                <td><?= e($pending['payment_reference']) ?></td><td><?= e($pending['full_name'] . ' (' . ($pending['student_id'] ?? '—') . ')') ?></td>
                <td><?= e($pending['penalty_title']) ?></td><td>₱<?= e(number_format((float) $pending['amount_paid'], 2)) ?></td>
                <td><?= e($pending['channel_name'] ?? '—') ?></td><td><?= e($pending['transaction_details'] ?? '—') ?></td>
                <td><?= e(date('M j, Y g:i A', strtotime($pending['payment_date']))) ?></td>
                <td><div class="action-group">
                    <form method="post" data-confirm="Verify this payment and mark its penalty paid?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="verify_payment"><input type="hidden" name="payment_id" value="<?= (int) $pending['payment_id'] ?>"><input type="hidden" name="return_to" value="index.php?page=payments"><button class="button small success" type="submit">Verify</button></form>
                    <form method="post" data-confirm="Reject this payment? The student can submit a new request."><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reject_payment"><input type="hidden" name="payment_id" value="<?= (int) $pending['payment_id'] ?>"><input type="hidden" name="return_to" value="index.php?page=payments"><button class="button small danger" type="submit">Reject</button></form>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
<?php endif; ?>

<section class="panel">
    <h2><?= $isStudent ? 'Payment history' : 'All payment records' ?></h2>
    <div class="table-scroll"><table><thead><tr><th>Reference</th><?php if (!$isStudent): ?><th>Student</th><?php endif; ?><th>Penalty</th><th>Amount</th><th>Channel</th><th>Status</th><th>Date</th><?php if ($isStudent): ?><th>QR reference</th><?php endif; ?></tr></thead><tbody>
    <?php if (!$payments): ?><tr><td class="empty" colspan="<?= $isStudent ? 7 : 6 ?>">No payment history.</td></tr><?php endif; ?>
    <?php foreach ($payments as $payment): ?>
        <tr>
            <td><?= e($payment['payment_reference']) ?></td>
            <?php if (!$isStudent): ?><td><?= e($payment['student_name'] . ' (' . ($payment['student_id'] ?? '—') . ')') ?></td><?php endif; ?>
            <td><?= e($payment['penalty_title']) ?></td><td>₱<?= e(number_format((float) $payment['amount_paid'], 2)) ?></td>
            <td><?= e($payment['channel_name'] ?? '—') ?></td>
            <td><span class="badge <?= e($payment['payment_status']) ?>"><?= e(ucfirst($payment['payment_status'])) ?></span></td>
            <td><?= e(date('M j, Y g:i A', strtotime($payment['payment_date']))) ?></td>
            <?php if ($isStudent): ?><td>
                <?php if ($payment['payment_status'] === 'pending' && $payment['qr_token'] && !$payment['is_used'] && strtotime($payment['expires_at'] ?? '') > time()): ?>
                    <?php if (($payment['channel_type'] ?? '') === 'online'): ?>
                        <details class="payment-qr">
                            <summary class="button primary">Online Payment · Show QR</summary>
                            <div class="payment-qr-preview">
                                <img src="<?= e(url('qr-image.php?id=' . (int) $payment['payment_id'])) ?>" alt="QR code for payment reference <?= e($payment['payment_reference']) ?>">
                                <a class="text-link" href="<?= e(url('qr.php?token=' . rawurlencode($payment['qr_token']))) ?>">Open payment instructions</a>
                            </div>
                        </details>
                    <?php else: ?>
                        <a class="text-link" href="<?= e(url('qr-image.php?id=' . (int) $payment['payment_id'])) ?>">Show QR</a>
                        <a class="text-link" href="<?= e(url('qr.php?token=' . rawurlencode($payment['qr_token']))) ?>">Open reference</a>
                    <?php endif; ?>
                    <small class="subtext">Expires <?= e(date('M j, g:i A', strtotime($payment['expires_at']))) ?></small>
                <?php else: ?><span class="muted">Not available</span><?php endif; ?>
            </td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
