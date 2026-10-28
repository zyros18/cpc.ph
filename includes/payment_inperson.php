<?php
require_once __DIR__ . '/auth.php';
require_role('student');

$studentUserId = (int) $_SESSION['user']['id'];
$penaltyId = filter_input(
	$_SERVER['REQUEST_METHOD'] === 'POST' ? INPUT_POST : INPUT_GET,
	'id',
	FILTER_VALIDATE_INT
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$penaltyId = filter_input(INPUT_POST, 'penalty_id', FILTER_VALIDATE_INT);
	$submittedToken = $_POST['csrf_token'] ?? '';
	$csrfToken = $_SESSION['inperson_payment_csrf_token'] ?? '';

	try {
		$db = database();
		if (!$penaltyId || !is_string($submittedToken) || !is_string($csrfToken) ||
			$csrfToken === '' || !hash_equals($csrfToken, $submittedToken)) {
			throw new RuntimeException('This payment form expired. Reload the page and try again.');
		}

		$paymentCheck = $db->prepare(
			"SELECT id, status
			 FROM penalties
			 WHERE id = :penalty_id AND student_id = :student_id
			 LIMIT 1"
		);
		$paymentCheck->execute([
			'penalty_id' => $penaltyId,
			'student_id' => $studentUserId,
		]);
		$penalty = $paymentCheck->fetch();

		if (!$penalty) {
			throw new RuntimeException('That in-person penalty could not be found.');
		}
		if ($penalty['status'] !== 'unpaid') {
			throw new RuntimeException('This penalty already has a payment request or has been paid.');
		}

		$update = $db->prepare(
			"UPDATE penalties
			 SET payment_method = 'in-person', status = 'pending'
			 WHERE id = :penalty_id AND student_id = :student_id
			   AND status = 'unpaid'"
		);
		$update->execute([
			'penalty_id' => $penaltyId,
			'student_id' => $studentUserId,
		]);

		if ($update->rowCount() !== 1) {
			throw new RuntimeException('This penalty status changed. Refresh the page and check its latest status.');
		}

		$_SESSION['inperson_payment_notice'] = 'Payment request submitted. Please pay at the CPC cashier office; the penalty will remain pending until staff confirms payment.';
	} catch (PDOException $exception) {
		error_log('In-person payment request failed: ' . $exception->getMessage());
		$_SESSION['inperson_payment_error'] = 'The payment request could not be submitted. Please try again.';
	} catch (RuntimeException $exception) {
		$_SESSION['inperson_payment_error'] = $exception->getMessage();
	}

	$_SESSION['inperson_payment_csrf_token'] = bin2hex(random_bytes(32));
	header('Location: payment_inperson.php?id=' . (int) $penaltyId);
	exit;
}

if (!$penaltyId || $penaltyId < 1) {
	http_response_code(404);
	exit('In-person penalty not found. Return to your dashboard and try again.');
}

$statement = database()->prepare(
	"SELECT p.id, p.title, p.amount, p.status, u.student_id
	 FROM penalties p
	 INNER JOIN users u ON u.id = p.student_id
	 WHERE p.id = :penalty_id AND p.student_id = :student_id
	 LIMIT 1"
);
$statement->execute([
	'penalty_id' => $penaltyId,
	'student_id' => $studentUserId,
]);
$penalty = $statement->fetch();

if (!$penalty) {
	http_response_code(404);
	exit('In-person penalty not found. Return to your dashboard and try again.');
}

if (empty($_SESSION['inperson_payment_csrf_token'])) {
	$_SESSION['inperson_payment_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['inperson_payment_csrf_token'];
$notice = $_SESSION['inperson_payment_notice'] ?? '';
$error = $_SESSION['inperson_payment_error'] ?? '';
unset($_SESSION['inperson_payment_notice'], $_SESSION['inperson_payment_error']);
$isUnpaid = $penalty['status'] === 'unpaid';
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>In-person Payment | CPC Penalty System</title>
	<style>
		:root { font-family: Inter, "Segoe UI", Arial, sans-serif; color: #0f172a; background: #f1f5f9; }
		* { box-sizing: border-box; }
		body { min-height: 100vh; margin: 0; padding: 32px 16px; background: #f1f5f9; }
		main { width: min(100%, 620px); margin: 0 auto; }
		.back-link { display: inline-block; margin: 0 0 18px; color: #2563eb; font-size: 14px; font-weight: 650; text-decoration: none; }
		.back-link:hover { text-decoration: underline; }
		.card { padding: clamp(22px, 5vw, 34px); border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 10px 28px rgba(15, 23, 42, .06); }
		h1 { margin: 0; color: #0f172a; font-size: clamp(24px, 5vw, 30px); }
		.intro { margin: 9px 0 24px; color: #64748b; font-size: 14px; line-height: 1.6; }
		.alert { padding: 13px 15px; margin: 0 0 18px; border: 1px solid #fecaca; border-radius: 9px; color: #b91c1c; background: #fef2f2; font-size: 14px; line-height: 1.5; }
		.alert.success { border-color: #bbf7d0; color: #15803d; background: #f0fdf4; }
		dl { display: grid; grid-template-columns: 1fr auto; gap: 15px 20px; padding: 20px; margin: 0; border: 1px solid #e2e8f0; border-radius: 11px; background: #f8fafc; }
		dt { color: #64748b; font-size: 13px; }
		dd { margin: 0; color: #0f172a; font-size: 14px; font-weight: 650; text-align: right; }
		.amount { color: #1d4ed8; font-size: 20px; }
		.steps { padding: 16px 18px; margin: 20px 0; border-radius: 10px; color: #334155; background: #eff6ff; font-size: 14px; line-height: 1.6; }
		.steps strong { color: #1e3a8a; }
		.actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
		.button { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; padding: 0 17px; border: 1px solid #cbd5e1; border-radius: 9px; color: #0f172a; background: #fff; font: inherit; font-size: 14px; font-weight: 650; text-decoration: none; cursor: pointer; }
		.button:hover { background: #f8fafc; }
		.button.primary { border-color: #2563eb; color: #fff; background: #2563eb; }
		.button.primary:hover { border-color: #1d4ed8; background: #1d4ed8; }
		.button:focus-visible { outline: 3px solid #60a5fa; outline-offset: 2px; }
		.status { display: inline-block; padding: 5px 10px; border-radius: 999px; color: #92400e; background: #fef3c7; font-size: 12px; font-weight: 700; text-transform: capitalize; }
		.status.paid { color: #166534; background: #dcfce7; }
		@media (max-width: 480px) { body { padding: 20px 12px; } .actions .button { width: 100%; } }
	</style>
</head>
<body>
	<main>
		<a class="back-link" href="student_dashboard.php">&larr; Back to Dashboard</a>
		<section class="card" aria-labelledby="page-title">
			<h1 id="page-title">In-person Payment</h1>
			<p class="intro">Review the penalty and submit a payment request before paying at the CPC cashier office.</p>

			<?php if ($error !== ''): ?>
				<div class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
			<?php endif; ?>
			<?php if ($notice !== ''): ?>
				<div class="alert success" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
			<?php endif; ?>

			<dl>
				<dt>Penalty</dt><dd><?= htmlspecialchars($penalty['title'], ENT_QUOTES, 'UTF-8') ?></dd>
				<dt>Student ID</dt><dd><?= htmlspecialchars($penalty['student_id'] ?? '—', ENT_QUOTES, 'UTF-8') ?></dd>
				<dt>Amount</dt><dd class="amount">₱<?= number_format((float) $penalty['amount'], 2) ?></dd>
				<dt>Status</dt><dd><span class="status <?= $penalty['status'] === 'paid' ? 'paid' : '' ?>"><?= htmlspecialchars(ucfirst($penalty['status']), ENT_QUOTES, 'UTF-8') ?></span></dd>
			</dl>

			<div class="steps">
				<strong>How it works:</strong> Submit the request, then pay the amount at the CPC cashier office. This page does not transfer money. Staff must confirm your payment before the penalty is marked as paid.
			</div>

			<div class="actions">
				<?php if ($isUnpaid): ?>
					<form method="post" action="payment_inperson.php?id=<?= (int) $penalty['id'] ?>" onsubmit="return confirm('Submit this payment request? You will still need to pay at the CPC cashier office.');">
						<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
						<input type="hidden" name="penalty_id" value="<?= (int) $penalty['id'] ?>">
						<button class="button primary" type="submit">Submit Payment Request</button>
					</form>
				<?php endif; ?>
				<a class="button" href="student_dashboard.php">Back to Dashboard</a>
			</div>
		</section>
	</main>
</body>
</html>
