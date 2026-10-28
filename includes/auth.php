<?php
require_once __DIR__ . '/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function require_role(string $role): void
{
    ensure_student_academic_fields();
    $user = $_SESSION['user'] ?? null;

    if (!is_array($user) || empty($user['id'])) {
        header('Location: ../login.php');
        exit;
    }

    $statement = database()->prepare('SELECT id, username, full_name, student_id, cellphone, role, department, course, year_level, profile_picture FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => (int) $user['id']]);
    $currentUser = $statement->fetch();

    if (!$currentUser || !in_array($currentUser['role'], ['student', 'teacher'], true)) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: ../login.php');
        exit;
    }

    $_SESSION['user'] = $currentUser;

    if ($currentUser['role'] !== $role) {
        $destination = $currentUser['role'] === 'teacher' ? 'teachers_dashboard.php' : 'student_dashboard.php';
        header('Location: ' . $destination);
        exit;
    }
}

function require_login(): void
{
    ensure_student_academic_fields();
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || empty($user['id'])) {
        header('Location: ../login.php');
        exit;
    }

    $statement = database()->prepare(
        'SELECT id, username, full_name, student_id, cellphone, role, department, course, year_level, profile_picture
        FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => (int) $user['id']]);
    $currentUser = $statement->fetch();

    if (!$currentUser) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: ../login.php');
        exit;
    }

    $_SESSION['user'] = $currentUser;
}

function profile_picture_data_uri(?string $image): ?string
{
    if ($image === null || $image === '') {
        return null;
    }

    $imageInfo = @getimagesizefromstring($image);
    $mimeType = $imageInfo['mime'] ?? '';
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if (!in_array($mimeType, $allowedTypes, true)) {
        return null;
    }

    return 'data:' . $mimeType . ';base64,' . base64_encode($image);
}