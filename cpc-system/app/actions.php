<?php
declare(strict_types=1);

function post_string(string $key, int $maxLength = 500): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException('Invalid form input.');
    }
    $value = trim($value);
    if (text_length($value) > $maxLength) {
        throw new InvalidArgumentException('A submitted field is too long.');
    }
    return $value;
}

function post_password(string $key): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || text_length($value) > 200) {
        throw new InvalidArgumentException('Invalid password input.');
    }
    return $value;
}

function post_positive_int(string $key): int
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
    if (!is_int($value) || $value < 1) {
        throw new InvalidArgumentException('Select a valid record.');
    }
    return $value;
}

function post_amount(string $key): string
{
    $value = post_string($key, 20);
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $value) || (float) $value <= 0) {
        throw new InvalidArgumentException('Enter a valid amount greater than zero.');
    }
    return number_format((float) $value, 2, '.', '');
}

function handle_post_action(): never
{
    verify_csrf();
    try {
        $action = post_string('action', 60);
        switch ($action) {
            case 'create_user': action_create_user(); break;
            case 'update_user': action_update_user(); break;
            case 'update_role': action_update_role(); break;
            case 'create_penalty': action_create_penalty(); break;
            case 'update_penalty': action_update_penalty(); break;
            case 'initiate_payment': action_initiate_payment(); break;
            case 'verify_payment': action_review_payment('verified'); break;
            case 'reject_payment': action_review_payment('rejected'); break;
            case 'save_channel': action_save_channel(); break;
            case 'toggle_channel': action_toggle_channel(); break;
            default: throw new InvalidArgumentException('Unknown action.');
        }
    } catch (InvalidArgumentException $exception) {
        flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000') {
            flash('error', 'That email, student ID, channel name, or payment record is already in use.');
        } else {
            error_log('CPC database action failed: ' . $exception->getMessage());
            flash('error', 'The request could not be completed. Check the database configuration and try again.');
        }
    } catch (Throwable $exception) {
        error_log('CPC request failed: ' . $exception->getMessage());
        flash('error', 'The request could not be completed. Try again or contact an administrator.');
    }
    $returnTo = $_POST['return_to'] ?? 'index.php';
    if (!is_string($returnTo) || !preg_match('/^index\.php(?:\?page=(dashboard|students|penalties|payments|channels|users|reports))?$/', $returnTo)) {
        $returnTo = 'index.php';
    }
    redirect($returnTo);
}

function action_create_user(): void
{
    require_roles('Admin');
    $name = post_string('full_name', 150);
    $email = filter_var(post_string('email', 254), FILTER_VALIDATE_EMAIL);
    $password = post_password('password');
    $phone = post_string('phone_number', 30);
    $studentId = post_string('student_id', 50);
    $roleId = post_positive_int('role_id');
    if ($name === '' || !$email || text_length($password) < 10) {
        throw new InvalidArgumentException('Enter a name, valid email, and a password of at least 10 characters.');
    }
    $role = db()->prepare('SELECT role_name FROM roles WHERE role_id = :id');
    $role->execute(['id' => $roleId]);
    $roleName = $role->fetchColumn();
    if (!$roleName) {
        throw new InvalidArgumentException('Select a valid role.');
    }
    if ($roleName === 'Student' && $studentId === '') {
        throw new InvalidArgumentException('A student ID is required for student accounts.');
    }
    if ($roleName !== 'Student') {
        $studentId = '';
    }
    $insert = db()->prepare(
        'INSERT INTO users (role_id, student_id, full_name, email, password_hash, phone_number)
         VALUES (:role_id, :student_id, :full_name, :email, :password_hash, :phone_number)'
    );
    $insert->execute([
        'role_id' => $roleId,
        'student_id' => $studentId === '' ? null : $studentId,
        'full_name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'phone_number' => $phone === '' ? null : $phone,
    ]);
    audit('user.created', 'user', (int) db()->lastInsertId(), ['role' => $roleName]);
    flash('success', 'Account created.');
}

function action_update_user(): void
{
    $actor = require_roles('Admin');
    $id = post_positive_int('user_id');
    $name = post_string('full_name', 150);
    $email = filter_var(post_string('email', 254), FILTER_VALIDATE_EMAIL);
    $phone = post_string('phone_number', 30);
    $studentId = post_string('student_id', 50);
    $roleId = post_positive_int('role_id');
    if ($name === '' || !$email) {
        throw new InvalidArgumentException('Enter a name and valid email.');
    }
    $role = db()->prepare('SELECT role_name FROM roles WHERE role_id = :id');
    $role->execute(['id' => $roleId]);
    $roleName = $role->fetchColumn();
    if (!$roleName || ($roleName === 'Student' && $studentId === '')) {
        throw new InvalidArgumentException('Select a valid role and provide the student ID when needed.');
    }
    if ($roleName !== 'Student') {
        $studentId = '';
    }
    if ($id === (int) $actor['user_id'] && ($roleName !== 'Admin' || !isset($_POST['is_active']))) {
        throw new InvalidArgumentException('You cannot deactivate your own administrator account or remove your own admin role.');
    }
    $password = post_password('password');
    $params = [
        'user_id' => $id,
        'role_id' => $roleId,
        'student_id' => $studentId === '' ? null : $studentId,
        'full_name' => $name,
        'email' => $email,
        'phone_number' => $phone === '' ? null : $phone,
    ];
    $passwordSql = '';
    if ($password !== '') {
        if (text_length($password) < 10) {
            throw new InvalidArgumentException('A new password must contain at least 10 characters.');
        }
        $passwordSql = ', password_hash = :password_hash';
        $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    $params['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    $update = db()->prepare(
        'UPDATE users SET role_id = :role_id, student_id = :student_id, full_name = :full_name,
         email = :email, phone_number = :phone_number, is_active = :is_active' . $passwordSql .
        ' WHERE user_id = :user_id'
    );
    $update->execute($params);
    audit('user.updated', 'user', $id);
    flash('success', 'Account updated.');
}

function action_update_role(): void
{
    require_roles('Admin');
    $roleId = post_positive_int('role_id');
    $description = post_string('role_description', 255);
    $statement = db()->prepare('UPDATE roles SET role_description = :description WHERE role_id = :id');
    $statement->execute(['description' => $description === '' ? null : $description, 'id' => $roleId]);
    if ($statement->rowCount() === 0) {
        $exists = db()->prepare('SELECT role_id FROM roles WHERE role_id = :id');
        $exists->execute(['id' => $roleId]);
        if (!$exists->fetchColumn()) {
            throw new InvalidArgumentException('Role not found.');
        }
    }
    audit('role.description_updated', 'role', $roleId);
    flash('success', 'Role description saved.');
}

function action_create_penalty(): void
{
    $actor = require_roles('Admin', 'Officer');
    $studentId = post_positive_int('student_user_id');
    $title = post_string('penalty_title', 150);
    $description = post_string('penalty_description', 5000);
    $amount = post_amount('amount');
    if ($title === '') {
        throw new InvalidArgumentException('Enter a penalty title.');
    }
    $student = db()->prepare(
        "SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = :id AND r.role_name = 'Student' AND u.is_active = 1"
    );
    $student->execute(['id' => $studentId]);
    if (!$student->fetchColumn()) {
        throw new InvalidArgumentException('Select an active student account.');
    }
    $insert = db()->prepare(
        "INSERT INTO penalties (student_user_id, recorded_by_user_id, penalty_title, penalty_description, amount)
         VALUES (:student_id, :recorder_id, :title, :description, :amount)"
    );
    $insert->execute([
        'student_id' => $studentId,
        'recorder_id' => $actor['user_id'],
        'title' => $title,
        'description' => $description === '' ? null : $description,
        'amount' => $amount,
    ]);
    audit('penalty.created', 'penalty', (int) db()->lastInsertId(), ['student_user_id' => $studentId]);
    flash('success', 'Penalty recorded.');
}

function action_update_penalty(): void
{
    require_roles('Admin', 'Officer');
    $id = post_positive_int('penalty_id');
    $title = post_string('penalty_title', 150);
    $description = post_string('penalty_description', 5000);
    $amount = post_amount('amount');
    if ($title === '') {
        throw new InvalidArgumentException('Enter a penalty title.');
    }
    $db = db();
    $db->beginTransaction();
    try {
        $existing = $db->prepare("SELECT penalty_id FROM penalties WHERE penalty_id = :id AND status = 'unpaid' FOR UPDATE");
        $existing->execute(['id' => $id]);
        if (!$existing->fetchColumn()) {
            throw new InvalidArgumentException('That unpaid penalty could not be updated.');
        }
        $payment = $db->prepare('SELECT payment_status FROM payments WHERE penalty_id = :id LIMIT 1 FOR UPDATE');
        $payment->execute(['id' => $id]);
        $paymentStatus = $payment->fetchColumn();
        if (in_array($paymentStatus, ['pending', 'verified'], true)) {
            throw new InvalidArgumentException('A penalty with a payment request can no longer be edited.');
        }
        $update = $db->prepare(
            'UPDATE penalties SET penalty_title = :title, penalty_description = :description, amount = :amount
             WHERE penalty_id = :id'
        );
        $update->execute([
            'title' => $title,
            'description' => $description === '' ? null : $description,
            'amount' => $amount,
            'id' => $id,
        ]);
        audit('penalty.updated', 'penalty', $id);
        $db->commit();
        flash('success', 'Penalty updated.');
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function action_initiate_payment(): void
{
    $student = require_roles('Student');
    $penaltyId = post_positive_int('penalty_id');
    $channelId = post_positive_int('channel_id');
    $transactionDetails = post_string('transaction_details', 500);
    $db = db();
    $db->beginTransaction();
    try {
        $penaltyQuery = $db->prepare(
            "SELECT penalty_id, student_user_id, amount, status FROM penalties WHERE penalty_id = :id FOR UPDATE"
        );
        $penaltyQuery->execute(['id' => $penaltyId]);
        $penalty = $penaltyQuery->fetch();
        if (!$penalty || (int) $penalty['student_user_id'] !== (int) $student['user_id'] || $penalty['status'] !== 'unpaid') {
            throw new InvalidArgumentException('This penalty is not available for payment.');
        }
        $channelQuery = $db->prepare('SELECT channel_id FROM payment_channels WHERE channel_id = :id AND is_active = 1 FOR UPDATE');
        $channelQuery->execute(['id' => $channelId]);
        if (!$channelQuery->fetchColumn()) {
            throw new InvalidArgumentException('Select an active payment channel.');
        }
        $paymentQuery = $db->prepare('SELECT * FROM payments WHERE penalty_id = :id FOR UPDATE');
        $paymentQuery->execute(['id' => $penaltyId]);
        $payment = $paymentQuery->fetch();
        $expiredRequest = false;
        if ($payment && $payment['payment_status'] === 'pending') {
            $qrCheck = $db->prepare('SELECT expires_at, is_used FROM qr_codes WHERE payment_id = :id');
            $qrCheck->execute(['id' => $payment['payment_id']]);
            $existingQr = $qrCheck->fetch();
            $expiredRequest = !$existingQr || (bool) $existingQr['is_used'] ||
                new DateTimeImmutable($existingQr['expires_at']) <= new DateTimeImmutable();
        }
        if ($payment && $payment['payment_status'] !== 'rejected' && !$expiredRequest) {
            throw new InvalidArgumentException('A payment is already pending or verified for this penalty.');
        }
        $reference = 'CPC-' . strtoupper(bin2hex(random_bytes(10)));
        if ($payment) {
            $paymentId = (int) $payment['payment_id'];
            $update = $db->prepare(
                "UPDATE payments SET payer_user_id = :payer, amount_paid = :amount, payment_reference = :reference,
                 payment_status = 'pending', payment_date = NOW(), verified_by_user_id = NULL, verified_at = NULL
                 WHERE payment_id = :id"
            );
            $update->execute([
                'payer' => $student['user_id'],
                'amount' => $penalty['amount'],
                'reference' => $reference,
                'id' => $paymentId,
            ]);
            $db->prepare('UPDATE qr_codes SET is_used = 1, used_at = NOW() WHERE payment_id = :id')->execute(['id' => $paymentId]);
        } else {
            $insert = $db->prepare(
                "INSERT INTO payments (penalty_id, payer_user_id, amount_paid, payment_reference)
                 VALUES (:penalty, :payer, :amount, :reference)"
            );
            $insert->execute([
                'penalty' => $penaltyId,
                'payer' => $student['user_id'],
                'amount' => $penalty['amount'],
                'reference' => $reference,
            ]);
            $paymentId = (int) $db->lastInsertId();
        }
        $ttl = max(5, min(1440, (int) app_config('qr_ttl_minutes', '30')));
        $expires = (new DateTimeImmutable())->modify('+' . $ttl . ' minutes')->format('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(32));
        $imagePath = 'qr-image.php?id=' . $paymentId;
        $db->prepare('DELETE FROM payment_channel_assignments WHERE payment_id = :payment_id')
            ->execute(['payment_id' => $paymentId]);
        $db->prepare(
            'INSERT INTO qr_codes (payment_id, qr_token, qr_image_path, expires_at, is_used)
             VALUES (:payment_id, :token, :path, :expires, 0)
             ON DUPLICATE KEY UPDATE qr_token = VALUES(qr_token), qr_image_path = VALUES(qr_image_path),
             expires_at = VALUES(expires_at), is_used = 0, used_at = NULL'
        )->execute([
            'payment_id' => $paymentId,
            'token' => $token,
            'path' => $imagePath,
            'expires' => $expires,
        ]);
        $db->prepare(
            'INSERT INTO payment_channel_assignments (payment_id, channel_id, transaction_details)
             VALUES (:payment_id, :channel_id, :details)
             ON DUPLICATE KEY UPDATE transaction_details = VALUES(transaction_details), created_at = CURRENT_TIMESTAMP'
        )->execute([
            'payment_id' => $paymentId,
            'channel_id' => $channelId,
            'details' => $transactionDetails === '' ? null : $transactionDetails,
        ]);
        audit('payment.initiated', 'payment', $paymentId, ['channel_id' => $channelId]);
        $db->commit();
        flash('success', 'Payment request created. This QR is a payment reference only; it does not transfer money.');
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function action_review_payment(string $decision): void
{
    $officer = require_roles('Admin', 'Officer');
    $paymentId = post_positive_int('payment_id');
    $note = post_string('review_note', 500);
    $db = db();
    $db->beginTransaction();
    try {
        $query = $db->prepare(
            'SELECT p.payment_id, p.payment_status, p.payer_user_id, p.penalty_id
             FROM payments p WHERE p.payment_id = :id FOR UPDATE'
        );
        $query->execute(['id' => $paymentId]);
        $payment = $query->fetch();
        if (!$payment || $payment['payment_status'] !== 'pending') {
            throw new InvalidArgumentException('This payment is no longer pending review.');
        }
        if (!can_review_payment((int) $officer['user_id'], (int) $payment['payer_user_id'])) {
            throw new InvalidArgumentException('You cannot review your own payment.');
        }
        $update = $db->prepare(
            "UPDATE payments SET payment_status = :status, verified_by_user_id = :verifier, verified_at = NOW()
             WHERE payment_id = :id AND payment_status = 'pending'"
        );
        $update->execute(['status' => $decision, 'verifier' => $officer['user_id'], 'id' => $paymentId]);
        if ($update->rowCount() !== 1) {
            throw new InvalidArgumentException('This payment is no longer pending review.');
        }
        if ($decision === 'verified') {
            $db->prepare("UPDATE penalties SET status = 'paid' WHERE penalty_id = :id")->execute(['id' => $payment['penalty_id']]);
        }
        $db->prepare('UPDATE qr_codes SET is_used = 1, used_at = NOW() WHERE payment_id = :id')
            ->execute(['id' => $paymentId]);
        audit('payment.' . $decision, 'payment', $paymentId, ['note' => $note]);
        $db->commit();
        flash('success', $decision === 'verified' ? 'Payment verified and penalty marked paid.' : 'Payment rejected.');
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function action_save_channel(): void
{
    require_roles('Admin', 'Officer');
    $id = filter_input(INPUT_POST, 'channel_id', FILTER_VALIDATE_INT);
    $name = post_string('channel_name', 100);
    $type = post_string('channel_type', 20);
    $details = post_string('details', 500);
    if ($name === '' || !in_array($type, ['online', 'in_person'], true)) {
        throw new InvalidArgumentException('Enter a channel name and valid channel type.');
    }
    if ($id) {
        $statement = db()->prepare(
            'UPDATE payment_channels SET channel_name = :name, channel_type = :type, details = :details WHERE channel_id = :id'
        );
        $statement->execute(['name' => $name, 'type' => $type, 'details' => $details ?: null, 'id' => $id]);
        audit('channel.updated', 'payment_channel', $id);
    } else {
        $statement = db()->prepare(
            'INSERT INTO payment_channels (channel_name, channel_type, details) VALUES (:name, :type, :details)'
        );
        $statement->execute(['name' => $name, 'type' => $type, 'details' => $details ?: null]);
        audit('channel.created', 'payment_channel', (int) db()->lastInsertId());
    }
    flash('success', 'Payment channel saved.');
}

function action_toggle_channel(): void
{
    require_roles('Admin', 'Officer');
    $id = post_positive_int('channel_id');
    $statement = db()->prepare('UPDATE payment_channels SET is_active = 1 - is_active WHERE channel_id = :id');
    $statement->execute(['id' => $id]);
    if ($statement->rowCount() !== 1) {
        throw new InvalidArgumentException('Payment channel not found.');
    }
    audit('channel.toggled', 'payment_channel', $id);
    flash('success', 'Payment channel status updated.');
}
