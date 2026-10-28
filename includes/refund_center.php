<?php
require_once __DIR__ . '/auth.php';
require_role('teacher');
ensure_refund_requests_table();

if (empty($_SESSION['refund_center_csrf_token'])) {
    $_SESSION['refund_center_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['refund_center_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
        $decision = $_POST['decision'] ?? '';
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!$requestId || !is_string($submittedToken) ||
            !hash_equals($csrfToken, $submittedToken)) {
            throw new RuntimeException('This review action is invalid or the form expired. Reload and try again.');
        }

        $db = database();
        if (in_array($decision, ['approve', 'reject'], true)) {
            $update = $db->prepare(
                "UPDATE refund_requests
                 SET status = :status, reviewed_by_user_id = :reviewer_id, reviewed_at = NOW()
                 WHERE id = :id AND status = 'pending'"
            );
            $update->execute([
                'status' => $decision === 'approve' ? 'approved' : 'rejected',
                'reviewer_id' => (int) $_SESSION['user']['id'],
                'id' => $requestId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('That request is no longer pending. Refresh to see its current status.');
            }

            $_SESSION['refund_center_notice'] = $decision === 'approve'
                ? 'Refund request approved. Send the refund manually, then record its transfer reference here.'
                : 'Refund request rejected.';
        } elseif ($decision === 'mark_refunded') {
            $transferReference = trim(
                is_string($_POST['transfer_reference'] ?? null) ? $_POST['transfer_reference'] : ''
            );
            if ($transferReference === '' || strlen($transferReference) > 100) {
                throw new RuntimeException('Enter the GCash transfer reference (up to 100 characters).');
            }

            $db->beginTransaction();
            $requestQuery = $db->prepare(
                'SELECT r.status, r.payout_number, p.status AS payment_status, p.amount
                 FROM refund_requests r
                 INNER JOIN penalties p ON p.id = r.penalty_id
                 WHERE r.id = :id
                 FOR UPDATE'
            );
            $requestQuery->execute(['id' => $requestId]);
            $refund = $requestQuery->fetch();
            if (!$refund || $refund['status'] !== 'approved') {
                throw new RuntimeException('Only an approved refund request can be marked refunded.');
            }
            if ($refund['payment_status'] !== 'paid') {
                throw new RuntimeException('The original payment must be verified as paid before recording a refund.');
            }
            if (empty($refund['payout_number'])) {
                throw new RuntimeException('This request has no student GCash payout number. Ask the student to submit it.');
            }

            $update = $db->prepare(
                "UPDATE refund_requests
                 SET status = 'refunded', refunded_amount = :amount,
                     refund_transfer_reference = :transfer_reference, refunded_at = NOW()
                 WHERE id = :id AND status = 'approved'"
            );
            $update->execute([
                'amount' => $refund['amount'],
                'transfer_reference' => $transferReference,
                'id' => $requestId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The refund request changed. Refresh and check its latest status.');
            }
            $db->commit();
            $_SESSION['refund_center_notice'] = 'Refund recorded as sent. The student can now see the amount and transfer reference.';
        } else {
            throw new RuntimeException('Choose a valid refund review action.');
        }
    } catch (RuntimeException $exception) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['refund_center_notice'] = $exception->getMessage();
    } catch (Throwable $exception) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Teacher refund review failed: ' . $exception->getMessage());
        $_SESSION['refund_center_notice'] = 'The refund request could not be reviewed. Please try again.';
    }

    header('Location: refund_center.php', true, 303);
    exit;
}

$statement = database()->query(
    'SELECT r.id, r.penalty_id, r.reference_number, r.reason, r.payout_number, r.screenshot_mime_type,
            r.status, r.created_at, r.reviewed_at, r.refunded_amount,
            r.refund_transfer_reference, r.refunded_at,
            p.title, p.amount, p.status AS payment_status, p.reference_number AS recorded_reference,
            u.full_name, u.student_id, u.cellphone
     FROM refund_requests r
     INNER JOIN penalties p ON p.id = r.penalty_id
     INNER JOIN users u ON u.id = p.student_id
     ORDER BY (r.status = \'pending\') DESC, r.created_at DESC'
);
$requests = $statement->fetchAll();
$notice = $_SESSION['refund_center_notice'] ?? '';
unset($_SESSION['refund_center_notice']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Teacher Refund Center | CPC Penalty System</title>
    <style>
        :root { font-family: Inter, "Segoe UI", Arial, sans-serif; color: #172033; background: #f1f5f9; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; padding: 28px 16px; background: #f1f5f9; }
        main { width: min(100%, 1100px); margin: 0 auto; }
        header, .request { padding: 22px; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; box-shadow: 0 8px 24px rgba(15, 23, 42, .05); }
        header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
        h1, h2, p { margin-top: 0; }
        h1 { margin-bottom: 5px; font-size: 28px; }
        h2 { margin-bottom: 14px; font-size: 19px; }
        .muted { color: #64748b; }
        .notice { padding: 12px 14px; margin-bottom: 18px; border: 1px solid #bfdbfe; border-radius: 9px; color: #1e40af; background: #eff6ff; }
        .request { display: grid; grid-template-columns: minmax(0, 1fr) minmax(240px, 360px); gap: 20px; margin-bottom: 16px; }
        dl { display: grid; grid-template-columns: 170px 1fr; gap: 9px 14px; margin: 0 0 18px; }
        dt { color: #64748b; } dd { margin: 0; overflow-wrap: anywhere; font-weight: 600; }
        .proof-button { display: block; padding: 0; border: 0; border-radius: 9px; background: transparent; cursor: zoom-in; }
        .proof { display: block; width: 140px; height: 110px; object-fit: cover; border: 1px solid #e2e8f0; border-radius: 9px; background: #f8fafc; }
        .proof-hint { margin: 7px 0 0; color: #64748b; font-size: 13px; }
        .image-modal { width: min(94vw, 1100px); max-width: none; max-height: 94vh; padding: 18px; border: 0; border-radius: 12px; background: #0f172a; }
        .image-modal::backdrop { background: rgba(15, 23, 42, .85); }
        .image-modal img { display: block; max-width: 100%; max-height: calc(94vh - 70px); margin: 0 auto; object-fit: contain; }
        .image-modal button { display: block; margin: 0 0 12px auto; padding: 7px 12px; border: 1px solid #64748b; border-radius: 7px; color: #fff; background: #1e293b; font: inherit; cursor: pointer; }
        .status { display: inline-block; padding: 4px 9px; border-radius: 999px; background: #f1f5f9; font-size: 13px; font-weight: 700; }
        .status.pending { color: #1d4ed8; background: #eff6ff; }
        .actions { display: flex; gap: 9px; }
        .button { min-height: 40px; padding: 0 14px; border: 0; border-radius: 8px; color: #fff; background: #15803d; font: inherit; font-weight: 650; cursor: pointer; }
        .button.reject { background: #b42318; }
        .actions form { margin: 0; }
        .payout-form { display: grid; max-width: 460px; gap: 10px; }
        .payout-form label { display: grid; gap: 6px; color: #334155; font-size: 13px; font-weight: 650; }
        .payout-form input { min-height: 40px; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; }
        a { color: #1d4ed8; }
        @media (max-width: 700px) { .request { grid-template-columns: 1fr; } dl { grid-template-columns: 1fr; gap: 4px; } dd { margin-bottom: 8px; } }
    </style>
</head>
<body>
    <main>
        <header>
            <div><h1>Teacher Refund Center</h1><p class="muted">Review requests, send approved refunds manually through GCash, then record the transfer.</p></div>
            <a href="teachers_dashboard.php">Back to Teacher Dashboard</a>
        </header>
        <?php if ($notice !== ''): ?><p class="notice" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if (!$requests): ?>
            <section class="request"><p>No refund requests have been submitted.</p></section>
        <?php else: ?>
            <?php foreach ($requests as $request): ?>
                <article class="request">
                    <section>
                        <h2>Request #<?= (int) $request['id'] ?> — <?= htmlspecialchars($request['full_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <dl>
                            <dt>Student name</dt><dd><?= htmlspecialchars($request['full_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Student ID</dt><dd><?= htmlspecialchars($request['student_id'] ?? '—', ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Student contact number</dt><dd><?= htmlspecialchars($request['cellphone'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>GCash refund number</dt><dd><?= htmlspecialchars($request['payout_number'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Penalty</dt><dd><?= htmlspecialchars($request['title'], ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Refund amount</dt><dd>₱<?= number_format((float) $request['amount'], 2) ?> (full penalty amount)</dd>
                            <dt>Payment status</dt><dd><?= htmlspecialchars(ucfirst($request['payment_status']), ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Reference on payment</dt><dd><?= htmlspecialchars($request['recorded_reference'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Reference in request</dt><dd><?= htmlspecialchars($request['reference_number'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Student's reason</dt><dd><?= nl2br(htmlspecialchars($request['reason'] ?? 'No reason provided', ENT_QUOTES, 'UTF-8')) ?></dd>
                            <dt>Submitted</dt><dd><?= htmlspecialchars($request['created_at'], ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt>Review status</dt><dd><span class="status <?= $request['status'] === 'pending' ? 'pending' : '' ?>"><?= htmlspecialchars(ucfirst($request['status']), ENT_QUOTES, 'UTF-8') ?></span></dd>
                        </dl>
                        <?php if ($request['status'] === 'pending'): ?>
                            <div class="actions">
                                <form method="post" onsubmit="return confirm('Approve this request? This only records the review decision; it does not transfer money.')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <input type="hidden" name="decision" value="approve">
                                    <button class="button" type="submit">Approve</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Reject this refund request?')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <input type="hidden" name="decision" value="reject">
                                    <button class="button reject" type="submit">Reject</button>
                                </form>
                            </div>
                        <?php elseif ($request['status'] === 'approved'): ?>
                            <?php if ($request['payment_status'] === 'paid' && !empty($request['payout_number'])): ?>
                                <form class="payout-form" method="post" onsubmit="return confirm('Only submit after you have sent the refund to the student through GCash.')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <input type="hidden" name="decision" value="mark_refunded">
                                    <label for="transferReference<?= (int) $request['id'] ?>">GCash refund transfer reference
                                        <input id="transferReference<?= (int) $request['id'] ?>" name="transfer_reference" maxlength="100" required>
                                    </label>
                                    <button class="button" type="submit">Mark Refund as Sent</button>
                                </form>
                            <?php else: ?>
                                <p class="muted">Refund can be recorded only after the original payment is verified as paid and a student GCash payout number is available.</p>
                            <?php endif; ?>
                        <?php elseif ($request['status'] === 'refunded'): ?>
                            <p><strong>Refund sent:</strong> ₱<?= number_format((float) $request['refunded_amount'], 2) ?></p>
                            <p><strong>Transfer reference:</strong> <?= htmlspecialchars($request['refund_transfer_reference'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                            <p><strong>Sent at:</strong> <?= htmlspecialchars($request['refunded_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </section>
                    <section>
                        <h2>Payment screenshot</h2>
                        <button class="proof-button" type="button" data-image-url="refund_screenshot.php?id=<?= (int) $request['id'] ?>" aria-label="Enlarge payment screenshot for request <?= (int) $request['id'] ?>">
                            <img class="proof" src="refund_screenshot.php?id=<?= (int) $request['id'] ?>" alt="Payment screenshot uploaded by the student">
                        </button>
                        <p class="proof-hint">Click the screenshot to enlarge.</p>
                    </section>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
    <dialog class="image-modal" id="imageModal" aria-label="Enlarged payment screenshot">
        <button type="button" id="closeImageModal">Close</button>
        <img id="enlargedImage" alt="Enlarged payment screenshot">
    </dialog>
    <script>
        const imageModal = document.getElementById('imageModal');
        const enlargedImage = document.getElementById('enlargedImage');
        document.querySelectorAll('.proof-button').forEach(function (button) {
            button.addEventListener('click', function () {
                enlargedImage.src = button.dataset.imageUrl;
                imageModal.showModal();
            });
        });
        document.getElementById('closeImageModal').addEventListener('click', function () {
            imageModal.close();
            enlargedImage.removeAttribute('src');
        });
        imageModal.addEventListener('click', function (event) {
            if (event.target === imageModal) {
                imageModal.close();
                enlargedImage.removeAttribute('src');
            }
        });
        imageModal.addEventListener('close', function () {
            enlargedImage.removeAttribute('src');
        });
    </script>
</body>
</html>
