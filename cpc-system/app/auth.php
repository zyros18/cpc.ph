<?php
declare(strict_types=1);

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }

    $statement = db()->prepare(
        'SELECT u.user_id, u.role_id, u.student_id, u.full_name, u.email, u.phone_number, r.role_name
         FROM users u INNER JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = :user_id AND u.is_active = 1 LIMIT 1'
    );
    $statement->execute(['user_id' => (int) $user['user_id']]);
    $freshUser = $statement->fetch();
    if (!$freshUser) {
        $_SESSION = [];
        session_regenerate_id(true);
        redirect('login.php');
    }

    $_SESSION['user'] = $freshUser;
    return $freshUser;
}

function require_roles(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role_name'], $roles, true)) {
        http_response_code(403);
        exit('You are not authorized to access this page.');
    }
    return $user;
}

function role_can(string $role, string $page): bool
{
    $access = [
        'Admin' => ['dashboard', 'students', 'penalties', 'payments', 'channels', 'users', 'reports'],
        'Officer' => ['dashboard', 'students', 'penalties', 'payments', 'channels', 'reports'],
        'Student' => ['dashboard', 'payments'],
    ];
    return in_array($page, $access[$role] ?? [], true);
}

function can_review_payment(int $reviewerUserId, int $payerUserId): bool
{
    return $reviewerUserId > 0 && $reviewerUserId !== $payerUserId;
}

function qr_token_state(array $record, ?DateTimeImmutable $now = null): string
{
    if ((bool) ($record['is_used'] ?? false)) {
        return 'used';
    }
    if (new DateTimeImmutable((string) $record['expires_at']) <= ($now ?? new DateTimeImmutable())) {
        return 'expired';
    }
    if (($record['payment_status'] ?? '') !== 'pending') {
        return 'not_pending';
    }
    return 'valid';
}
