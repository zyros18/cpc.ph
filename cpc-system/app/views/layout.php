<?php
$navByRole = [
    'Admin' => ['dashboard' => 'Dashboard', 'students' => 'Students', 'penalties' => 'Penalties', 'payments' => 'Payments', 'channels' => 'Channels', 'users' => 'Users', 'reports' => 'Reports'],
    'Officer' => ['dashboard' => 'Dashboard', 'students' => 'Students', 'penalties' => 'Penalties', 'payments' => 'Payments', 'channels' => 'Channels', 'reports' => 'Reports'],
    'Student' => ['dashboard' => 'Dashboard', 'payments' => 'My Payments'],
];
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(ucfirst($page)) ?> | CPC Penalty System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    <script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
</head>
<body>
    <header class="topbar">
        <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation" aria-expanded="false" aria-controls="sidebar">☰</button>
        <a class="topbar-brand" href="<?= e(url('index.php')) ?>">CPC Penalty System</a>
        <div class="topbar-user"><span class="role-pill"><?= e($user['role_name']) ?></span><span><?= e($user['full_name']) ?></span></div>
    </header>
    <button class="sidebar-backdrop" id="sidebarBackdrop" type="button" aria-label="Close navigation"></button>
    <aside class="sidebar" id="sidebar">
        <div class="brand"><span class="brand-mark">CPC</span><span>Student Penalty<br>Payment System</span></div>
        <nav class="side-nav" aria-label="Main navigation">
            <?php foreach ($navByRole[$user['role_name']] as $navPage => $label): ?>
                <a href="<?= e(url('index.php?page=' . $navPage)) ?>" class="<?= $page === $navPage ? 'active' : '' ?>" <?= $page === $navPage ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon" aria-hidden="true"><?= e(substr($label, 0, 1)) ?></span><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <form class="logout-form" method="post" action="<?= e(url('logout.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="logout-link"><span class="nav-icon" aria-hidden="true">↪</span><span>Sign out</span></button>
        </form>
    </aside>
    <main class="main-content">
        <?php if (is_array($flashMessage)): ?>
            <div class="alert <?= e($flashMessage['type'] ?? 'info') ?>" role="<?= ($flashMessage['type'] ?? '') === 'error' ? 'alert' : 'status' ?>"><?= e($flashMessage['message'] ?? '') ?></div>
        <?php endif; ?>
        <?php require $viewFile; ?>
    </main>
</body>
</html>
