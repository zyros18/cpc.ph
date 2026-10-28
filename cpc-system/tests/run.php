<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    fwrite(STDOUT, "PASS: {$message}\n");
};

$assert(role_can('Admin', 'users'), 'Admin can access user management');
$assert(role_can('Officer', 'payments'), 'Officer can access payment review');
$assert(!role_can('Officer', 'users'), 'Officer cannot access admin user management');
$assert(!role_can('Student', 'reports'), 'Student cannot access reports');
$assert(role_can('Student', 'payments'), 'Student can access personal payments');
$assert(can_review_payment(10, 11), 'An officer can review another user payment');
$assert(!can_review_payment(10, 10), 'A user cannot review their own payment');

$hash = password_hash('correct horse battery staple', PASSWORD_DEFAULT);
$assert(password_verify('correct horse battery staple', $hash), 'Password hash verifies');
$assert(!password_verify('incorrect password', $hash), 'Incorrect password is rejected');
$token = csrf_token();
$assert((bool) preg_match('/^[a-f0-9]{64}$/', $token), 'CSRF token has sufficient random length');
$assert(csrf_valid($token), 'Current CSRF token is accepted');
$assert(!csrf_valid('invalid-token'), 'Invalid CSRF token is rejected');

$now = new DateTimeImmutable('2026-06-01 12:00:00');
$qrBase = ['is_used' => 0, 'expires_at' => '2026-06-01 12:05:00', 'payment_status' => 'pending'];
$assert(qr_token_state($qrBase, $now) === 'valid', 'Unexpired pending QR reference is valid');
$assert(qr_token_state(array_merge($qrBase, ['expires_at' => '2026-06-01 11:59:00']), $now) === 'expired', 'Expired QR reference is rejected');
$assert(qr_token_state(array_merge($qrBase, ['is_used' => 1]), $now) === 'used', 'Used QR reference cannot be reused');
$assert(qr_token_state(array_merge($qrBase, ['payment_status' => 'verified']), $now) === 'not_pending', 'Verified payment QR reference is invalidated');
$qrCode = new Endroid\QrCode\QrCode(
    data: app_absolute_url('qr.php?token=' . str_repeat('a', 64)),
    encoding: new Endroid\QrCode\Encoding\Encoding('UTF-8'),
    errorCorrectionLevel: Endroid\QrCode\ErrorCorrectionLevel::Low,
    size: 200,
    margin: 8
);
$qrSvg = (new Endroid\QrCode\Writer\SvgWriter())->write($qrCode)->getString();
$assert(str_contains($qrSvg, '<svg'), 'Payment reference QR code renders as SVG without GD');

$pdo = db();
$pdo->beginTransaction();
try {
    $adminRole = $pdo->query("SELECT role_id FROM roles WHERE role_name = 'Admin'")->fetchColumn();
    $studentRole = $pdo->query("SELECT role_id FROM roles WHERE role_name = 'Student'")->fetchColumn();
    $channelId = $pdo->query("SELECT channel_id FROM payment_channels WHERE channel_name = 'GCash'")->fetchColumn();
    if (!$adminRole || !$studentRole || !$channelId) {
        throw new RuntimeException('Import database.sql and seed data before running database tests.');
    }
    $suffix = bin2hex(random_bytes(8));
    $userInsert = $pdo->prepare(
        'INSERT INTO users (role_id, student_id, full_name, email, password_hash)
         VALUES (:role, :student_id, :name, :email, :password_hash)'
    );
    $userInsert->execute([
        'role' => $adminRole,
        'student_id' => null,
        'name' => 'Automated test officer',
        'email' => 'test-officer-' . $suffix . '@example.invalid',
        'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $officerId = (int) $pdo->lastInsertId();
    $userInsert->execute([
        'role' => $studentRole,
        'student_id' => 'TEST-' . strtoupper($suffix),
        'name' => 'Automated test student',
        'email' => 'test-student-' . $suffix . '@example.invalid',
        'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $studentId = (int) $pdo->lastInsertId();
    $penaltyInsert = $pdo->prepare(
        'INSERT INTO penalties (student_user_id, recorded_by_user_id, penalty_title, amount)
         VALUES (:student, :officer, :title, 2.50)'
    );
    $penaltyInsert->execute(['student' => $studentId, 'officer' => $officerId, 'title' => 'Automated test penalty']);
    $penaltyId = (int) $pdo->lastInsertId();
    $invalidAmountRejected = false;
    try {
        $invalidPenalty = $pdo->prepare(
            'INSERT INTO penalties (student_user_id, recorded_by_user_id, penalty_title, amount)
             VALUES (:student, :officer, :title, 0)'
        );
        $invalidPenalty->execute(['student' => $studentId, 'officer' => $officerId, 'title' => 'Invalid amount']);
    } catch (PDOException) {
        $invalidAmountRejected = true;
    }
    $assert($invalidAmountRejected, 'Database rejects non-positive penalty amounts');
    $paymentInsert = $pdo->prepare(
        'INSERT INTO payments (penalty_id, payer_user_id, amount_paid, payment_reference)
         VALUES (:penalty, :student, 2.50, :reference)'
    );
    $paymentInsert->execute(['penalty' => $penaltyId, 'student' => $studentId, 'reference' => 'TEST-' . $suffix]);
    $paymentId = (int) $pdo->lastInsertId();
    $tokenValue = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO qr_codes (payment_id, qr_token, qr_image_path, expires_at)
         VALUES (:payment, :token, :path, DATE_ADD(NOW(), INTERVAL 5 MINUTE))'
    )->execute(['payment' => $paymentId, 'token' => $tokenValue, 'path' => 'qr-image.php?id=' . $paymentId]);
    $pdo->prepare(
        'INSERT INTO payment_channel_assignments (payment_id, channel_id, transaction_details)
         VALUES (:payment, :channel, :details)'
    )->execute(['payment' => $paymentId, 'channel' => $channelId, 'details' => 'Automated test']);

    $duplicateRejected = false;
    try {
        $paymentInsert->execute(['penalty' => $penaltyId, 'student' => $studentId, 'reference' => 'TEST-DUP-' . $suffix]);
    } catch (PDOException $exception) {
        $duplicateRejected = (string) $exception->getCode() === '23000';
    }
    $assert($duplicateRejected, 'Database prevents duplicate payment rows for one penalty');

    $renderPage = static function (string $pageFile, int $actorId): string {
        $_SESSION['user'] = ['user_id' => $actorId];
        $user = require_login();
        ob_start();
        require $pageFile;
        return (string) ob_get_clean();
    };
    $pages = [
        'dashboard' => 'dashboard.php',
        'students' => 'students.php',
        'penalties' => 'penalties.php',
        'payments' => 'payments.php',
        'channels' => 'channels.php',
        'users' => 'users.php',
        'reports' => 'reports.php',
    ];
    foreach ($pages as $pageName => $fileName) {
        $html = $renderPage(dirname(__DIR__) . '/app/pages/' . $fileName, $officerId);
        $assert($html !== '', 'Admin ' . $pageName . ' page renders');
    }
    $studentDashboard = $renderPage(dirname(__DIR__) . '/app/pages/dashboard.php', $studentId);
    $assert(str_contains($studentDashboard, 'Automated test penalty'), 'Student dashboard renders own penalty data');
    $studentPayments = $renderPage(dirname(__DIR__) . '/app/pages/payments.php', $studentId);
    $assert(str_contains($studentPayments, 'TEST-' . $suffix), 'Student payment page renders personal payment history');

    $pdo->prepare("UPDATE payments SET payment_status = 'rejected', verified_by_user_id = :officer, verified_at = NOW() WHERE payment_id = :id")
        ->execute(['officer' => $officerId, 'id' => $paymentId]);
    $pdo->prepare('UPDATE qr_codes SET is_used = 1, used_at = NOW() WHERE payment_id = :id')->execute(['id' => $paymentId]);
    $rejectedState = $pdo->prepare('SELECT p.payment_status, pe.status FROM payments p JOIN penalties pe ON pe.penalty_id = p.penalty_id WHERE p.payment_id = :id');
    $rejectedState->execute(['id' => $paymentId]);
    $rejected = $rejectedState->fetch();
    $assert($rejected['payment_status'] === 'rejected' && $rejected['status'] === 'unpaid', 'Rejected payment leaves the penalty unpaid');
    $pdo->prepare("UPDATE payments SET payment_status = 'pending', verified_by_user_id = NULL, verified_at = NULL WHERE payment_id = :id")
        ->execute(['id' => $paymentId]);
    $pdo->prepare('UPDATE qr_codes SET is_used = 0, used_at = NULL WHERE payment_id = :id')->execute(['id' => $paymentId]);

    $pdo->prepare("UPDATE payments SET payment_status = 'verified', verified_by_user_id = :officer, verified_at = NOW() WHERE payment_id = :id")
        ->execute(['officer' => $officerId, 'id' => $paymentId]);
    $pdo->prepare("UPDATE penalties SET status = 'paid' WHERE penalty_id = :id")->execute(['id' => $penaltyId]);
    $state = $pdo->prepare(
        'SELECT p.payment_status, pe.status FROM payments p JOIN penalties pe ON pe.penalty_id = p.penalty_id
         WHERE p.payment_id = :id'
    );
    $state->execute(['id' => $paymentId]);
    $verified = $state->fetch();
    $assert($verified['payment_status'] === 'verified' && $verified['status'] === 'paid', 'Verified payment and paid penalty update together');
    $pdo->rollBack();
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

fwrite(STDOUT, "All {$checks} checks passed.\n");
