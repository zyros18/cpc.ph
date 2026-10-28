<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_role('student');

$penaltyId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$penaltyId || $penaltyId < 1) {
	http_response_code(404);
	exit('Payment record not found.');
}

$statement = database()->prepare(
	'SELECT p.title, p.status, p.payment_method, u.student_id, c.checkout_status
	 FROM penalties p
	 INNER JOIN users u ON u.id = p.student_id
	 LEFT JOIN paymongo_checkouts c ON c.penalty_id = p.id
	 WHERE p.id = :penalty_id AND p.student_id = :student_id
	 LIMIT 1'
);
$statement->execute(['penalty_id' => $penaltyId, 'student_id' => $_SESSION['user']['id']]);
$payment = $statement->fetch();
if (!$payment) {
	http_response_code(404);
	exit('Payment record not found.');
}

$cancelled = isset($_GET['cancelled']);
$paid = $payment['status'] === 'paid' && $payment['checkout_status'] === 'paid';
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Payment Status | CPC Penalty System</title>
	<style>
		:root { font-family: Inter, "Segoe UI", Arial, sans-serif; color: #0f172a; background: #f1f5f9; }
		* { box-sizing: border-box; }
		body { min-height: 100vh; display: grid; place-items: center; margin: 0; padding: 20px; background: #f1f5f9; }
		main { width: min(100%, 540px); padding: 30px; border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 10px 28px rgba(15, 23, 42, .06); }
		h1 { margin: 0 0 12px; font-size: 26px; }
		p { color: #64748b; font-size: 14px; line-height: 1.65; }
		.status { padding: 14px; border-radius: 9px; color: #92400e; background: #fef3c7; font-size: 14px; }
		.status.paid { color: #166534; background: #dcfce7; }
		a { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; padding: 0 16px; margin-top: 12px; border-radius: 8px; color: #fff; background: #2563eb; text-decoration: none; font-size: 13px; font-weight: 650; }
	</style>
</head>
<body>
	<main>
		<h1><?= $paid ? 'Payment confirmed' : ($cancelled ? 'Checkout not completed' : 'Payment processing') ?></h1>
		<p>Penalty: <?= htmlspecialchars($payment['title'], ENT_QUOTES, 'UTF-8') ?></p>
		<div class="status <?= $paid ? 'paid' : '' ?>" role="status">
			<?php if ($paid): ?>
				PayMongo confirmed the payment and your penalty is marked paid.
			<?php elseif ($cancelled): ?>
				No payment was confirmed. Your penalty remains pending; you can resume checkout or contact the CPC office.
			<?php else: ?>
				PayMongo has returned you to the CPC system. The payment will remain pending until the verified PayMongo webhook confirms it. Refresh your dashboard shortly to check the status.
			<?php endif; ?>
		</div>
		<a href="student_dashboard.php">Back to Dashboard</a>
	</main>
</body>
</html>
