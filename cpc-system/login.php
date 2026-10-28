<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

if (current_user() !== null) {
    redirect('index.php');
}

$error = '';
$oldEmail = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    if (!$email || !is_string($password)) {
        $error = 'Enter a valid email and password.';
    } else {
        try {
            $statement = db()->prepare(
                'SELECT u.user_id, u.password_hash, u.is_active, r.role_name
                 FROM users u JOIN roles r ON r.role_id = u.role_id
                 WHERE u.email = :email LIMIT 1'
            );
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();
            if (!$user || !(bool) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
                $error = 'Email or password is incorrect.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user'] = ['user_id' => (int) $user['user_id']];
                audit('auth.login', 'user', (int) $user['user_id']);
                redirect('index.php');
            }
        } catch (PDOException $exception) {
            error_log('CPC login database failure: ' . $exception->getMessage());
            $error = 'Unable to connect to the system database. Check the setup and try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | CPC Penalty System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="auth-page">
    <main class="auth-card">
        <div class="auth-brand"><span class="brand-mark">CPC</span><span>CPC Penalty System</span></div>
        <h1>Sign in</h1>
        <p class="muted">Use your school account to continue.</p>
        <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('login.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label>Email<input type="email" name="email" autocomplete="username" required maxlength="254" value="<?= e($oldEmail) ?>"></label>
            <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="button primary full" type="submit">Sign in</button>
        </form>
    </main>
</body>
</html>
