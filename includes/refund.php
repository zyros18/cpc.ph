<?php
require_once __DIR__ . '/auth.php';
require_role('student');
ensure_refund_requests_table();

$studentUserId = (int) $_SESSION['user']['id'];
$penaltyId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (empty($_SESSION['refund_request_csrf_token'])) {
    $_SESSION['refund_request_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['refund_request_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $penaltyId = filter_input(INPUT_POST, 'penalty_id', FILTER_VALIDATE_INT);
    $error = '';

    try {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
            throw new RuntimeException(
                'The server rejected the upload. Set upload_max_filesize to 10M and post_max_size to at least 12M in XAMPP php.ini, then restart Apache.'
            );
        }

        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!$penaltyId || !is_string($submittedToken) ||
            !hash_equals($csrfToken, $submittedToken)) {
            throw new RuntimeException('Your form expired. Reload and try again.');
        }

        $file = $_FILES['screenshot'] ?? null;
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size'])) {
            throw new RuntimeException('Choose a screenshot of your payment.');
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE ||
            (int) $file['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('The screenshot must be 10 MB or smaller.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('The screenshot could not be uploaded. Choose the file again.');
        }

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $imageInfo = @getimagesize($file['tmp_name']);
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($file['tmp_name']);
        if (!$imageInfo || !in_array($mimeType, $allowedMimeTypes, true) ||
            ($imageInfo['mime'] ?? '') !== $mimeType ||
            $imageInfo[0] > 6000 || $imageInfo[1] > 6000) {
            throw new RuntimeException('Upload a valid JPG, PNG, or WebP screenshot no larger than 6000 × 6000 pixels.');
        }

        $screenshot = file_get_contents($file['tmp_name']);
        if ($screenshot === false || $screenshot === '') {
            throw new RuntimeException('The screenshot could not be read. Choose another file.');
        }

        $referenceNumber = trim(
            is_string($_POST['reference_number'] ?? null) ? $_POST['reference_number'] : ''
        );
        if (strlen($referenceNumber) > 100) {
            throw new RuntimeException('The reference number must be 100 characters or fewer.');
        }

        $reason = trim(is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '');
        if ($reason === '' || strlen($reason) > 2000) {
            throw new RuntimeException('Explain why you are requesting a refund (up to 2,000 characters).');
        }

        $payoutNumber = trim(
            is_string($_POST['payout_number'] ?? null) ? $_POST['payout_number'] : ''
        );
        if (!preg_match('/^\+?[0-9][0-9\s()-]{6,28}$/D', $payoutNumber)) {
            throw new RuntimeException('Enter a valid GCash number, including its country code if needed.');
        }

        $db = database();
        $db->beginTransaction();
        $paymentQuery = $db->prepare(
            "SELECT p.id, p.status, p.payment_method
             FROM penalties p
             WHERE p.id = :penalty_id AND p.student_id = :student_id
             FOR UPDATE"
        );
        $paymentQuery->execute(['penalty_id' => $penaltyId, 'student_id' => $studentUserId]);
        $payment = $paymentQuery->fetch();
        if (!$payment || $payment['payment_method'] !== 'online' ||
            !in_array($payment['status'], ['pending', 'paid'], true)) {
            throw new RuntimeException('Only pending or paid online payments can be reported.');
        }

        $pendingQuery = $db->prepare(
            "SELECT id FROM refund_requests
             WHERE penalty_id = :penalty_id AND status = 'pending'
             LIMIT 1"
        );
        $pendingQuery->execute(['penalty_id' => $penaltyId]);
        if ($pendingQuery->fetchColumn()) {
            throw new RuntimeException('A refund request for this payment is already awaiting teacher review.');
        }

        $insert = $db->prepare(
            "INSERT INTO refund_requests
                (penalty_id, reference_number, reason, payout_number, screenshot, screenshot_mime_type)
             VALUES (:penalty_id, :reference_number, :reason, :payout_number, :screenshot, :mime_type)"
        );
        $insert->bindValue(':penalty_id', $penaltyId, PDO::PARAM_INT);
        $insert->bindValue(
            ':reference_number',
            $referenceNumber !== '' ? $referenceNumber : null,
            $referenceNumber !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $insert->bindValue(':reason', $reason);
        $insert->bindValue(':payout_number', $payoutNumber);
        $insert->bindValue(':screenshot', $screenshot, PDO::PARAM_LOB);
        $insert->bindValue(':mime_type', $mimeType);
        $insert->execute();
        $db->commit();

        $_SESSION['refund_notice'] = 'Your request was sent to the teacher for review. Approval records the decision; it does not transfer money automatically.';
        $_SESSION['refund_request_csrf_token'] = bin2hex(random_bytes(32));
        header('Location: refund.php?id=' . $penaltyId, true, 303);
        exit;
    } catch (RuntimeException $exception) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Student refund request failed: ' . $exception->getMessage());
        $error = 'The refund request could not be submitted. Please try again.';
    }

    $_SESSION['refund_notice'] = $error;
    header('Location: refund.php?id=' . (int) $penaltyId, true, 303);
    exit;
}

if (!$penaltyId || $penaltyId < 1) {
    http_response_code(404);
    exit('Payment not found.');
}

$paymentQuery = database()->prepare(
    'SELECT p.id, p.title, p.amount, p.status, p.payment_method, p.reference_number
     FROM penalties p
     WHERE p.id = :penalty_id AND p.student_id = :student_id
     LIMIT 1'
);
$paymentQuery->execute(['penalty_id' => $penaltyId, 'student_id' => $studentUserId]);
$payment = $paymentQuery->fetch();
if (!$payment) {
    http_response_code(404);
    exit('Payment not found.');
}
if ($payment['payment_method'] !== 'online' ||
    !in_array($payment['status'], ['pending', 'paid'], true)) {
    http_response_code(400);
    exit('Only pending or paid online payments can be reported.');
}

$requestsQuery = database()->prepare(
    'SELECT id, reference_number, reason, payout_number, status, created_at, reviewed_at,
            refunded_amount, refund_transfer_reference, refunded_at
     FROM refund_requests
     WHERE penalty_id = :penalty_id
     ORDER BY created_at DESC'
);
$requestsQuery->execute(['penalty_id' => $penaltyId]);
$requests = $requestsQuery->fetchAll();
$hasPendingRequest = false;
$hasApprovedRequest = false;
$hasRefundedRequest = false;
foreach ($requests as $request) {
    if ($request['status'] === 'pending') {
        $hasPendingRequest = true;
    } elseif ($request['status'] === 'approved') {
        $hasApprovedRequest = true;
    } elseif ($request['status'] === 'refunded') {
        $hasRefundedRequest = true;
    }
}
$canSubmitRequest = !$hasPendingRequest && !$hasApprovedRequest && !$hasRefundedRequest;
$notice = $_SESSION['refund_notice'] ?? '';
unset($_SESSION['refund_notice']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Report Payment Issue | CPC Penalty System</title>
    <style>
        :root { font-family: Inter, "Segoe UI", Arial, sans-serif; color: #172033; background: #f1f5f9; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; padding: 30px 15px; background: #f1f5f9; }
        main { width: min(100%, 680px); margin: 0 auto; }
        .card { padding: clamp(21px, 5vw, 34px); border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 10px 28px rgba(15, 23, 42, .06); }
        h1 { margin: 0 0 10px; font-size: clamp(25px, 5vw, 30px); }
        p { color: #475569; line-height: 1.6; }
        .notice { padding: 12px 14px; margin: 16px 0; border: 1px solid #bfdbfe; border-radius: 9px; color: #1e40af; background: #eff6ff; }
        dl { display: grid; grid-template-columns: 1fr auto; gap: 12px 18px; padding: 16px; margin: 20px 0; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
        dt { color: #64748b; } dd { margin: 0; font-weight: 650; text-align: right; }
        label { display: grid; gap: 7px; margin: 16px 0; color: #334155; font-size: 14px; font-weight: 650; }
        input, textarea { width: 100%; padding: 11px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; }
        textarea { resize: vertical; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
        .button { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; padding: 0 16px; border: 1px solid #2563eb; border-radius: 9px; color: #fff; background: #2563eb; font: inherit; font-weight: 650; text-decoration: none; cursor: pointer; }
        .button.secondary { border-color: #cbd5e1; color: #0f172a; background: #fff; }
        table { width: 100%; margin-top: 12px; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        @media (max-width: 520px) { dl { grid-template-columns: 1fr; gap: 5px; } dd { margin-bottom: 8px; text-align: left; } .actions .button { width: 100%; } }
    </style>
</head>
<body>
    <main>
        <section class="card" aria-labelledby="page-title">
            <h1 id="page-title">Report a payment reference issue</h1>
            <p>Submit your GCash receipt screenshot, the correct reference number if available, your reason, and the GCash number where you want the refund sent. A teacher will review your request and manually send any approved refund. This form does not automatically transfer money.</p>
            <?php if ($notice !== ''): ?><div class="notice" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <dl>
                <dt>Penalty</dt><dd><?= htmlspecialchars($payment['title'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Amount</dt><dd>₱<?= number_format((float) $payment['amount'], 2) ?></dd>
                <dt>Payment status</dt><dd><?= htmlspecialchars(ucfirst($payment['status']), ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Reference on record</dt><dd><?= htmlspecialchars($payment['reference_number'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>

            <?php if ($canSubmitRequest): ?>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="penalty_id" value="<?= (int) $payment['id'] ?>">
                    <label for="reference_number">Correct GCash reference number (if available)
                        <input id="reference_number" name="reference_number" maxlength="100" autocomplete="off">
                    </label>
                    <label for="reason">Why are you requesting a refund?
                        <textarea id="reason" name="reason" maxlength="2000" rows="5" required></textarea>
                    </label>
                    <label for="payout_number">Your GCash number for the refund
                        <input id="payout_number" name="payout_number" type="tel" inputmode="tel" maxlength="30" autocomplete="tel" placeholder="e.g. 09XXXXXXXXX" required>
                    </label>
                    <label for="screenshot">Payment screenshot (JPG, PNG, or WebP; up to 10 MB)
                        <input id="screenshot" name="screenshot" type="file" accept="image/jpeg,image/png,image/webp" required>
                    </label>
                    <div class="actions">
                        <button class="button" type="submit">Send to Teacher for Review</button>
                        <a class="button secondary" href="student_dashboard.php">Back to Dashboard</a>
                    </div>
                </form>
            <?php else: ?>
                <div class="notice">
                    <?php if ($hasPendingRequest): ?>
                        A request for this payment is already waiting for teacher review.
                    <?php elseif ($hasApprovedRequest): ?>
                        Your refund request was approved. The teacher must send the refund and record its transfer reference.
                    <?php else: ?>
                        A refund was already recorded for this payment.
                    <?php endif; ?>
                </div>
                <a class="button secondary" href="student_dashboard.php">Back to Dashboard</a>
            <?php endif; ?>

            <?php if ($requests): ?>
                <h2>Request history</h2>
                <table>
                    <thead><tr><th>Submitted</th><th>GCash payout number</th><th>Reference provided</th><th>Reason</th><th>Status</th><th>Refund details</th></tr></thead>
                    <tbody>
                    <?php foreach ($requests as $request): ?>
                        <tr>
                            <td><?= htmlspecialchars($request['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($request['payout_number'] ?? 'Not provided', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($request['reference_number'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= nl2br(htmlspecialchars($request['reason'] ?? 'No reason provided', ENT_QUOTES, 'UTF-8')) ?></td>
                            <td><?= htmlspecialchars(ucfirst($request['status']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?php if ($request['status'] === 'refunded'): ?>₱<?= number_format((float) $request['refunded_amount'], 2) ?> sent on <?= htmlspecialchars($request['refunded_at'] ?? '', ENT_QUOTES, 'UTF-8') ?> (reference: <?= htmlspecialchars($request['refund_transfer_reference'] ?? '', ENT_QUOTES, 'UTF-8') ?>)<?php else: ?>—<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
