<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function text_length(string $value): int
{
    $length = preg_match_all('/./us', $value, $matches);
    return $length === false ? PHP_INT_MAX : $length;
}

function url(string $path = ''): string
{
    return app_base_url() . '/' . ltrim($path, '/');
}

function query_string(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Your form session expired. Reload the page and try again.');
    }
}

function csrf_valid(mixed $submitted): bool
{
    return is_string($submitted) && hash_equals(csrf_token(), $submitted);
}

function audit(string $action, string $entityType, ?int $entityId = null, array $details = []): void
{
    $statement = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details)
         VALUES (:user_id, :action, :entity_type, :entity_id, :details)'
    );
    $statement->execute([
        'user_id' => current_user()['user_id'] ?? null,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
    ]);
}

function page_number(): int
{
    return max(1, filter_input(INPUT_GET, 'page_num', FILTER_VALIDATE_INT) ?: 1);
}

function pagination(int $total, int $perPage = 20): array
{
    $page = page_number();
    return [
        'limit' => $perPage,
        'offset' => ($page - 1) * $perPage,
        'page' => $page,
        'pages' => max(1, (int) ceil($total / $perPage)),
        'total' => $total,
    ];
}
