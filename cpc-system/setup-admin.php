<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/database.php';

$email = filter_var($argv[1] ?? '', FILTER_VALIDATE_EMAIL);
$name = trim((string) ($argv[2] ?? ''));
if (!$email || $name === '') {
    fwrite(STDERR, "Usage: php setup-admin.php admin@example.edu \"Administrator Name\"\n");
    exit(2);
}
fwrite(STDOUT, 'New admin password (minimum 12 characters): ');
$password = trim((string) fgets(STDIN));
if (strlen($password) < 12) {
    fwrite(STDERR, "\nPassword must contain at least 12 characters.\n");
    exit(2);
}
$pdo = db();
$roleQuery = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Admin'");
$roleQuery->execute();
$roleId = $roleQuery->fetchColumn();
if (!$roleId) {
    fwrite(STDERR, "Admin role is missing. Import database.sql first.\n");
    exit(1);
}
$insert = $pdo->prepare(
    'INSERT INTO users (role_id, full_name, email, password_hash) VALUES (:role_id, :name, :email, :hash)'
);
$insert->execute([
    'role_id' => $roleId,
    'name' => $name,
    'email' => $email,
    'hash' => password_hash($password, PASSWORD_DEFAULT),
]);
fwrite(STDOUT, "\nAdmin account created for {$email}.\n");
