<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_role('student');

$studentId = (int) $_SESSION['user']['id'];
$paymentType = $_SERVER['REQUEST_METHOD'] === 'POST'
	? ($_POST['payment_type'] ?? 'penalty')
	: ($_GET['type'] ?? 'penalty');
if (!is_string($paymentType) || !in_array($paymentType, ['penalty', 'tuition'], true)) {
	http_response_code(400);
	exit('Invalid payment type.');
}
if ($paymentType === 'tuition') {
	ensure_tuition_balances_table();
}
$paymentId = filter_input(
	$_SERVER['REQUEST_METHOD'] === 'POST' ? INPUT_POST : INPUT_GET,
	$_SERVER['REQUEST_METHOD'] === 'POST'
		? ($paymentType === 'tuition' ? 'tuition_id' : 'penalty_id')
		: 'id',
	FILTER_VALIDATE_INT
);
if (empty($_SESSION['gcash_request_csrf_token'])) {
	$_SESSION['gcash_request_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['gcash_request_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$submittedToken = $_POST['csrf_token'] ?? '';

	try {
		if (!$paymentId || !is_string($submittedToken) ||
			!hash_equals($csrfToken, $submittedToken)) {
			throw new RuntimeException('Your form expired. Reload and try again.');
		}

		$referenceNumber = trim(
			is_string($_POST['reference_number'] ?? null) ? $_POST['reference_number'] : ''
		);
		$tuitionAmountInput = $_POST['payment_amount'] ?? '';
		if (!preg_match('/^[0-9]{13}$/D', $referenceNumber)) {
			throw new RuntimeException('Enter the 13-digit GCash transaction reference from your receipt.');
		}
		$tuitionPaymentCents = null;
		if ($paymentType === 'tuition') {
			$tuitionPaymentCents = is_string($tuitionAmountInput)
				? money_to_cents(trim($tuitionAmountInput))
				: null;
			if ($tuitionPaymentCents === null || $tuitionPaymentCents < 300000) {
				throw new RuntimeException('Tuition payments must be at least ₱3,000.00.');
			}
		}

		$db = database();
		$db->beginTransaction();
		if ($paymentType === 'tuition') {
			$paymentQuery = $db->prepare(
				'SELECT id, status, amount FROM tuition_balances
				 WHERE id = :payment_id AND student_id = :student_id
				 FOR UPDATE'
			);
		} else {
			$paymentQuery = $db->prepare(
				'SELECT id, status, amount FROM penalties
				 WHERE id = :payment_id AND student_id = :student_id
				 FOR UPDATE'
			);
		}
		$paymentQuery->execute(['payment_id' => $paymentId, 'student_id' => $studentId]);
		$payment = $paymentQuery->fetch();
		if (!$payment || $payment['status'] !== 'unpaid' || (float) $payment['amount'] <= 0) {
			throw new RuntimeException('This payment is not available for a GCash payment request.');
		}
		if ($paymentType === 'tuition') {
			$balanceCents = money_to_cents((string) $payment['amount']);
			if ($balanceCents === null || $balanceCents < 300000 || $tuitionPaymentCents > $balanceCents) {
				throw new RuntimeException('Tuition payments must be at least ₱3,000.00 and cannot exceed your current tuition balance.');
			}
			$tuitionPaymentAmount = intdiv($tuitionPaymentCents, 100) . '.' .
				str_pad((string) ($tuitionPaymentCents % 100), 2, '0', STR_PAD_LEFT);
		}

		$qrQuery = $db->query(
			'SELECT merchant_name, qr_image, qr_mime_type FROM gcash_merchant_settings WHERE settings_id = 1'
		);
		$merchant = $qrQuery->fetch();
		if (!$merchant || empty($merchant['qr_image'])) {
			throw new RuntimeException('The CPC GCash Business QR is not configured. Contact your teacher.');
		}

		if ($paymentType === 'tuition') {
			$update = $db->prepare(
				"UPDATE tuition_balances
				 SET pending_payment_amount = :payment_amount, payment_method = 'online',
				     status = 'pending', reference_number = :reference_number
				 WHERE id = :payment_id AND student_id = :student_id AND status = 'unpaid'"
			);
			$update->execute([
				'payment_amount' => $tuitionPaymentAmount,
				'reference_number' => $referenceNumber,
				'payment_id' => $paymentId,
				'student_id' => $studentId,
			]);
		} else {
			$update = $db->prepare(
				"UPDATE penalties
				 SET payment_method = 'online', status = 'pending', reference_number = :reference_number
				 WHERE id = :payment_id AND student_id = :student_id AND status = 'unpaid'"
			);
			$update->execute([
				'reference_number' => $referenceNumber,
				'payment_id' => $paymentId,
				'student_id' => $studentId,
			]);
		}
		if ($update->rowCount() !== 1) {
			throw new RuntimeException('The payment status changed. Refresh and check it again.');
		}

		if ($paymentType === 'penalty') {
			$db->prepare(
				"UPDATE paymongo_checkouts
				 SET paymongo_session_id = NULL, checkout_url = NULL, checkout_status = 'failed'
				 WHERE penalty_id = :penalty_id AND checkout_status IN ('creating', 'active')"
			)->execute(['penalty_id' => $paymentId]);
		}
		$db->commit();

		$_SESSION['notice'] = 'GCash payment request submitted. A teacher must verify the transaction before the ' .
			($paymentType === 'tuition' ? 'tuition balance' : 'penalty') . ' is marked paid.';
	} catch (RuntimeException $exception) {
		if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
			$db->rollBack();
		}
		$_SESSION['notice'] = $exception->getMessage();
	} catch (Throwable $exception) {
		if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
			$db->rollBack();
		}
		error_log('GCash payment request failed: ' . $exception->getMessage());
		$_SESSION['notice'] = 'Could not submit the GCash payment request. Please try again.';
	}

	$_SESSION['gcash_request_csrf_token'] = bin2hex(random_bytes(32));
	$redirectUrl = 'online_payment.php?id=' . (int) $paymentId;
	if ($paymentType === 'tuition') {
		$redirectUrl .= '&type=tuition';
	}
	header('Location: ' . $redirectUrl, true, 303);
	exit;
}

if (!$paymentId || $paymentId < 1) {
	http_response_code(404);
	exit('Payment not found.');
}

if ($paymentType === 'tuition') {
	$statement = database()->prepare(
		"SELECT t.id, 'Tuition Balance' AS title, t.amount, t.pending_payment_amount,
		        t.status, t.payment_method, u.student_id
		 FROM tuition_balances t
		 INNER JOIN users u ON u.id = t.student_id
		 WHERE t.id = :payment_id AND t.student_id = :student_id
		 LIMIT 1"
	);
} else {
	$statement = database()->prepare(
		'SELECT p.id, p.title, p.amount, p.status, p.payment_method, u.student_id
		 FROM penalties p
		 INNER JOIN users u ON u.id = p.student_id
		 WHERE p.id = :payment_id AND p.student_id = :student_id
		 LIMIT 1'
	);
}
$statement->execute(['payment_id' => $paymentId, 'student_id' => $studentId]);
$penalty = $statement->fetch();
if (!$penalty) {
	http_response_code(404);
	exit('Payment not found.');
}
$merchantQuery = database()->query(
	'SELECT merchant_name, gcash_account_number, qr_image, qr_mime_type
	 FROM gcash_merchant_settings WHERE settings_id = 1'
);
$merchant = $merchantQuery->fetch() ?: null;
$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);
$isUnpaid = $penalty['status'] === 'unpaid';
$hasBalance = (float) $penalty['amount'] > 0;
$tuitionPaymentMax = $paymentType === 'tuition'
	? (float) $penalty['amount']
	: 0;
$tuitionMinimumMet = $paymentType !== 'tuition' || $tuitionPaymentMax >= 3000;
$canSubmitPayment = $isUnpaid && $hasBalance && $tuitionMinimumMet;
$paymentLabel = $paymentType === 'tuition' ? 'tuition balance' : 'penalty';
$backUrl = $paymentType === 'tuition' ? 'tuition.php' : 'student_dashboard.php';
$qrConfigured = $merchant && !empty($merchant['qr_image']);
$merchantQrDataUri = $qrConfigured
	? 'data:' . $merchant['qr_mime_type'] . ';base64,' . base64_encode($merchant['qr_image'])
	: '';
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>GCash Payment | CPC Penalty System</title>
	<style>
		:root { font-family: Inter, "Segoe UI", Arial, sans-serif; color: #0f172a; background: #f1f5f9; }
		* { box-sizing: border-box; }
		body { min-height: 100vh; margin: 0; padding: 30px 15px; background: #f1f5f9; }
		main { width: min(100%, 650px); margin: 0 auto; }
		.card { padding: clamp(21px, 5vw, 34px); border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 10px 28px rgba(15, 23, 42, .06); }
		h1 { margin: 0; color: #0f172a; font-size: clamp(25px, 5vw, 30px); }
		.intro { margin: 9px 0 22px; color: #64748b; font-size: 14px; line-height: 1.6; }
		.notice { padding: 12px 14px; margin-bottom: 18px; border: 1px solid #bfdbfe; border-radius: 9px; color: #1e40af; background: #eff6ff; font-size: 14px; line-height: 1.5; text-align: center; }
		.payment-status { padding: 14px; margin: 16px 0; border: 1px solid #bbf7d0; border-radius: 9px; color: #166534; background: #f0fdf4; font-weight: 650; text-align: center; }
		.payment-status.unpaid { border-color: #fde68a; color: #92400e; background: #fffbeb; }
		.payment-status.pending { border-color: #bfdbfe; color: #1e40af; background: #eff6ff; }
		dl { display: grid; grid-template-columns: 1fr auto; gap: 13px 18px; padding: 17px; margin: 0 0 20px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
		dt { color: #64748b; font-size: 13px; }
		dd { margin: 0; color: #0f172a; font-size: 14px; font-weight: 650; text-align: right; }
		.amount { color: #1d4ed8; font-size: 20px; }
		.merchant { padding: 20px; margin-bottom: 20px; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center; }
		.merchant h2 { margin: 0 0 7px; font-size: 17px; }
		.merchant-name { margin: 0 0 14px; color: #475569; font-weight: 650; }
		.qr { display: block; width: min(100%, 310px); max-height: 380px; object-fit: contain; padding: 10px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
		.qr-open { display: block; max-width: 100%; padding: 0; margin: 0 auto; border: 0; border-radius: 10px; background: transparent; cursor: zoom-in; }
		.qr-open:focus-visible { outline: 3px solid #60a5fa; outline-offset: 4px; }
		.qr-dialog { width: min(92vw, 600px); max-width: none; padding: 20px; border: 1px solid #cbd5e1; border-radius: 14px; background: #fff; box-shadow: 0 20px 60px rgba(15, 23, 42, .25); }
		.qr-dialog::backdrop { background: rgba(15, 23, 42, .72); }
		.qr-dialog img { display: block; width: 100%; max-height: 75vh; object-fit: contain; }
		.qr-dialog-close { display: block; min-height: 40px; padding: 0 14px; margin: 0 0 12px auto; border: 1px solid #cbd5e1; border-radius: 8px; color: #0f172a; background: #fff; font: inherit; cursor: pointer; }
		.account-copy { display: inline-flex; min-height: 34px; align-items: center; padding: 0 10px; margin-left: 6px; border: 1px solid #cbd5e1; border-radius: 7px; color: #0f172a; background: #fff; font: inherit; font-size: 12px; font-weight: 650; cursor: pointer; }
		.copy-status { min-height: 1.4em; margin: 6px 0 0; color: #166534; font-size: 13px; }
		.instructions { padding: 14px 16px; margin: 18px 0; border-radius: 9px; color: #334155; background: #eff6ff; font-size: 13px; line-height: 1.6; text-align: left; }
		.instructions ol { padding-left: 20px; margin: 7px 0 0; }
		label { display: grid; gap: 7px; margin-top: 16px; color: #334155; font-size: 13px; font-weight: 650; text-align: left; }
		input { width: 100%; min-height: 44px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; font-size: 14px; }
		input:focus { outline: none; border-color: #60a5fa; box-shadow: 0 0 0 3px rgba(96, 165, 250, .2); }
		.actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
		.button { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; padding: 0 16px; border: 1px solid #2563eb; border-radius: 9px; color: #fff; background: #2563eb; font: inherit; font-size: 14px; font-weight: 650; text-decoration: none; cursor: pointer; }
		.button.secondary { border-color: #cbd5e1; color: #0f172a; background: #fff; }
		.button:disabled { opacity: .65; cursor: not-allowed; }
		@media (max-width: 480px) { body { padding: 18px 11px; } .actions .button { width: 100%; } }
	</style>
</head>
<body>
	<main>
		<section class="card" aria-labelledby="page-title">
			<h1 id="page-title">Pay <?= $paymentType === 'tuition' ? 'Tuition Balance' : 'Penalty' ?> with GCash</h1>
			<p class="intro">Scan the official CPC GCash Business QR with your GCash app, then request teacher verification after sending the payment.</p>
			<?php if ($notice !== ''): ?><div class="notice" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
			<dl>
				<dt><?= $paymentType === 'tuition' ? 'Tuition' : 'Penalty' ?></dt><dd><?= htmlspecialchars($penalty['title'], ENT_QUOTES, 'UTF-8') ?></dd>
				<dt>Student ID</dt><dd><?= htmlspecialchars($penalty['student_id'] ?? '—', ENT_QUOTES, 'UTF-8') ?></dd>
				<dt><?= $paymentType === 'tuition' ? 'Balance due' : 'Amount' ?></dt><dd class="amount">₱<?= number_format((float) $penalty['amount'], 2) ?></dd>
				<?php if ($paymentType === 'tuition' && $penalty['status'] === 'pending'): ?>
					<dt>Payment requested</dt><dd class="amount">₱<?= number_format((float) ($penalty['pending_payment_amount'] ?? 0), 2) ?></dd>
				<?php endif; ?>
				<dt>Status</dt><dd><?= htmlspecialchars(ucfirst($penalty['status']), ENT_QUOTES, 'UTF-8') ?></dd>
			</dl>
			<?php if ($penalty['status'] === 'paid'): ?>
				<div class="payment-status">Payment confirmed — this <?= htmlspecialchars($paymentLabel, ENT_QUOTES, 'UTF-8') ?> is paid.</div>
			<?php elseif ($penalty['status'] === 'pending' && $penalty['payment_method'] === 'online'): ?>
				<div class="payment-status pending">Payment request submitted. Your <?= htmlspecialchars($paymentLabel, ENT_QUOTES, 'UTF-8') ?> is not updated until a teacher verifies the GCash payment.</div>
			<?php elseif ($isUnpaid): ?>
				<div class="payment-status unpaid"><?= $hasBalance ? ($paymentType === 'tuition' ? ($tuitionMinimumMet ? 'Enter a payment of at least ₱3,000.00. Higher amounts are accepted up to your current balance.' : 'Your current balance is below the ₱3,000.00 minimum payment. Contact your teacher for assistance.') : 'Complete payment and request teacher verification below.') : 'No balance is currently due.' ?></div>
			<?php endif; ?>

			<?php if ($qrConfigured): ?>
				<section class="merchant" aria-label="CPC GCash Business payment QR">
					<h2>Official CPC GCash Business QR</h2>
					<p class="merchant-name"><?= htmlspecialchars($merchant['merchant_name'], ENT_QUOTES, 'UTF-8') ?></p>
					<button class="qr-open" id="openQr" type="button" aria-label="Enlarge the GCash payment QR">
						<img class="qr" src="<?= htmlspecialchars($merchantQrDataUri, ENT_QUOTES, 'UTF-8') ?>" alt="GCash Business QR for <?= htmlspecialchars($merchant['merchant_name'], ENT_QUOTES, 'UTF-8') ?>">
					</button>
					<dialog class="qr-dialog" id="qrDialog" aria-label="Enlarged GCash payment QR">
						<button class="qr-dialog-close" id="closeQr" type="button">Close</button>
						<img src="<?= htmlspecialchars($merchantQrDataUri, ENT_QUOTES, 'UTF-8') ?>" alt="Enlarged GCash Business QR for <?= htmlspecialchars($merchant['merchant_name'], ENT_QUOTES, 'UTF-8') ?>">
					</dialog>
					<?php if (!empty($merchant['gcash_account_number'])): ?>
						<p class="merchant-name">GCash Business account:
							<strong id="gcashAccountNumber"><?= htmlspecialchars($merchant['gcash_account_number'], ENT_QUOTES, 'UTF-8') ?></strong>
							<button class="account-copy" id="copyAccountNumber" type="button">Copy account number</button>
						</p>
						<p class="copy-status" id="copyStatus" aria-live="polite"></p>
					<?php endif; ?>
					<?php if ($canSubmitPayment): ?>
						<div class="instructions">
							<strong>Payment steps</strong>
							<ol>
								<li>Open GCash on another device and scan this merchant QR.</li>
								<li>Check that the recipient is CPC, pay <?php if ($paymentType === 'tuition'): ?>the amount entered below (at least ₱3,000.00 and no more than your balance)<?php else: ?>exactly ₱<?= number_format((float) $penalty['amount'], 2) ?><?php endif; ?>, then request teacher verification.</li>
							</ol>
						</div>
					<?php endif; ?>

					<?php if ($canSubmitPayment): ?>
						<form method="post" action="online_payment.php?id=<?= (int) $penalty['id'] ?><?= $paymentType === 'tuition' ? '&amp;type=tuition' : '' ?>">
							<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
							<input type="hidden" name="<?= $paymentType === 'tuition' ? 'tuition_id' : 'penalty_id' ?>" value="<?= (int) $penalty['id'] ?>">
							<input type="hidden" name="payment_type" value="<?= htmlspecialchars($paymentType, ENT_QUOTES, 'UTF-8') ?>">
							<p class="instructions">Keep your GCash receipt. This request does not mark the <?= htmlspecialchars($paymentLabel, ENT_QUOTES, 'UTF-8') ?> as paid; a teacher must verify the payment in the official GCash Business account.</p>
							<?php if ($paymentType === 'tuition'): ?>
								<label for="paymentAmount">Amount to pay (₱; minimum ₱3,000.00)
									<input id="paymentAmount" name="payment_amount" type="number" min="3000.00" max="<?= htmlspecialchars(number_format($tuitionPaymentMax, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" step="0.01" value="3000.00" required>
								</label>
							<?php endif; ?>
							<label for="referenceNumber">GCash transaction reference number (13 digits)
								<input id="referenceNumber" name="reference_number" type="text" inputmode="numeric" pattern="[0-9]{13}" minlength="13" maxlength="13" title="Enter exactly 13 digits." autocomplete="off" required>
							</label>
							<div class="actions"><button class="button" type="submit">I Have Paid — Request Verification</button><a class="button secondary" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">Back</a></div>
						</form>
					<?php elseif ($penalty['status'] === 'pending' && $penalty['payment_method'] === 'online'): ?>
						<a class="button secondary" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">Back</a>
					<?php elseif ($penalty['status'] === 'paid'): ?>
						<a class="button secondary" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">Back</a>
					<?php elseif ($isUnpaid && !$hasBalance): ?>
						<a class="button secondary" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">Back</a>
					<?php else: ?>
						<a class="button secondary" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">Back</a>
					<?php endif; ?>
				</section>
			<?php else: ?>
				<div class="notice">The CPC GCash Business QR has not been added yet. Please contact your teacher before making a payment.</div>
				<a class="button secondary" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">Back</a>
			<?php endif; ?>
			<p class="intro">Never share your GCash MPIN, password, or one-time password.</p>
		</section>
	</main>
	<script>
		const qrDialog = document.getElementById('qrDialog');
		const openQr = document.getElementById('openQr');
		const closeQr = document.getElementById('closeQr');

		if (qrDialog && openQr && closeQr) {
			openQr.addEventListener('click', function () {
				qrDialog.showModal();
			});
			closeQr.addEventListener('click', function () {
				qrDialog.close();
				openQr.focus();
			});
			qrDialog.addEventListener('click', function (event) {
				if (event.target === qrDialog) {
					qrDialog.close();
					openQr.focus();
				}
			});
		}

		const copyAccountNumber = document.getElementById('copyAccountNumber');
		if (copyAccountNumber) {
			copyAccountNumber.addEventListener('click', async function () {
				const accountNumber = document.getElementById('gcashAccountNumber').textContent.trim();
				const copyStatus = document.getElementById('copyStatus');
				try {
					await navigator.clipboard.writeText(accountNumber);
					copyStatus.textContent = 'GCash account number copied.';
				} catch (error) {
					copyStatus.textContent = 'Could not copy automatically. Please select and copy the account number above.';
				}
			});
		}
	</script>
</body>
</html>
