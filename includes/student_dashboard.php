<?php
require_once __DIR__ . '/auth.php';
require_role('student');
ensure_tuition_balances_table();

$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'dashboard';
if (!in_array($view, ['dashboard', 'penalties', 'tuition'], true)) {
    $view = 'dashboard';
}

$userId = (int) $_SESSION['user']['id'];
$statement = database()->prepare(
    'SELECT p.id, p.title, u.student_id, p.amount, p.payment_method AS payment_type, p.status
     FROM penalties p
     INNER JOIN users u ON u.id = p.student_id
     WHERE p.student_id = :user_id
     ORDER BY p.created_at DESC'
);
$statement->execute(['user_id' => $userId]);
$penalties = $statement->fetchAll();
$tuitionStatement = database()->prepare(
    'SELECT id, amount, pending_payment_amount, paid_amount, status, payment_method, reference_number, paid_at
     FROM tuition_balances WHERE student_id = :student_id LIMIT 1'
);
$tuitionStatement->execute(['student_id' => $userId]);
$tuitionAccount = $tuitionStatement->fetch() ?: null;
$tuitionBalance = $tuitionAccount && $tuitionAccount['status'] === 'paid'
    ? 0.0
    : (float) ($tuitionAccount['amount'] ?? 0);
$paidPenalties = 0;
$outstandingPenalties = 0;
$outstandingAmount = 0.0;
foreach ($penalties as $penalty) {
    if (($penalty['status'] ?? '') === 'paid') {
        $paidPenalties++;
    } else {
        $outstandingPenalties++;
        $outstandingAmount += (float) $penalty['amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | CPC Penalty System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            cursor: pointer;
            border: 2px solid white;
        }
        .profile-avatar.default {
            background: #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        .navbar-brand-custom {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .profile-menu-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 14px;
            transition: opacity 0.3s ease;
        }
        .profile-menu-btn:hover {
            opacity: 0.8;
            color: #f0f0f0;
        }
        @media (max-width: 575.98px) {
            .navbar .container {
                flex-wrap: wrap;
                gap: 0.5rem;
                padding-top: 0.4rem;
                padding-bottom: 0.4rem;
            }
            .navbar-brand {
                max-width: 48%;
                margin-right: auto;
                white-space: normal;
                font-size: 1rem;
            }
            .profile-menu-btn {
                max-width: 100%;
                text-align: left;
            }
            .profile-menu-btn span {
                max-width: 38vw;
                overflow-wrap: anywhere;
            }
            .container.mt-4 {
                padding-right: 12px;
                padding-left: 12px;
            }
            .card-body {
                overflow: visible;
            }
            .penalty-table,
            .penalty-table tbody,
            .penalty-table tr,
            .penalty-table td {
                display: block;
                width: 100%;
            }
            .penalty-table {
                table-layout: fixed;
            }
            .penalty-table thead {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border: 0;
            }
            .penalty-table tbody {
                display: grid;
                gap: 12px;
                padding: 12px;
            }
            .penalty-table tbody tr {
                padding: 10px 12px;
                border: 1px solid #e5eaf1;
                border-radius: 6px;
                background: #fff;
            }
            .penalty-table tbody td {
                display: grid;
                grid-template-columns: minmax(86px, 34%) minmax(0, 1fr);
                gap: 8px;
                padding: 8px 0;
                border-bottom: 1px solid #eef1f5;
                overflow-wrap: anywhere;
            }
            .penalty-table tbody td:last-child {
                border-bottom: 0;
            }
            .penalty-table tbody td::before {
                content: attr(data-label);
                color: #69778d;
                font-size: 0.7rem;
                font-weight: 700;
                text-transform: uppercase;
            }
            .penalty-table tbody td[colspan] {
                display: block;
                text-align: center;
            }
            .penalty-table tbody td[colspan]::before {
                content: none;
            }
            .penalty-table td .btn {
                justify-self: start;
                white-space: normal;
            }
        }
        :root {
            font-family: Inter, "Segoe UI", Arial, sans-serif;
            color: #172033;
            background: #f1f5f9;
            font-synthesis: none;
        }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; background: #f1f5f9 !important; }
        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            z-index: 20;
            display: flex;
            width: 264px;
            flex-direction: column;
            padding: 26px 18px 20px;
            background: #fff;
            border-right: 1px solid #e5eaf1;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 8px 36px;
            color: #172033;
            font-size: 15px;
            font-weight: 750;
            line-height: 1.3;
        }
        .brand-mark {
            display: block;
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            object-fit: contain;
            border-radius: 12px;
        }
        .sidebar-nav { display: flex; flex: 1; flex-direction: column; gap: 6px; }
        .sidebar-footer .sidebar-nav { flex: 0; }
        .sidebar-nav a,
        .sidebar-nav button {
            display: flex;
            width: 100%;
            min-height: 46px;
            align-items: center;
            gap: 12px;
            padding: 0 13px;
            border: 0;
            border-radius: 9px;
            color: #5b6678;
            background: transparent;
            text-align: left;
            text-decoration: none;
            font: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s ease, color .15s ease;
        }
        .sidebar-nav a svg,
        .sidebar-nav button svg { width: 19px; height: 19px; flex: 0 0 19px; }
        .sidebar-nav a:hover,
        .sidebar-nav button:hover { color: #1d4ed8; background: #eff6ff; }
        .sidebar-nav a.active {
            position: relative;
            color: #fff;
            background: #2563eb;
            box-shadow: 0 5px 12px rgba(37, 99, 235, .18);
        }
        .sidebar-nav a.active::before {
            position: absolute;
            left: -18px;
            width: 4px;
            height: 24px;
            border-radius: 0 4px 4px 0;
            background: #2563eb;
            content: "";
        }
        .sidebar-nav a.logout { margin-top: auto; color: #b42318; }
        .sidebar-nav a.logout:hover { color: #912018; background: #fff1f0; }
        .sidebar-footer { margin-top: auto; padding-top: 18px; border-top: 1px solid #edf0f4; }
        .profile { display: flex; align-items: center; gap: 10px; padding: 8px 5px 0; }
        .profile-avatar {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            border: 0;
            border-radius: 50%;
            color: #1e40af;
            background: #dbeafe;
            font-size: 16px;
            font-weight: 700;
            object-fit: cover;
        }
        .profile-name {
            overflow: hidden;
            margin: 0;
            color: #344054;
            font-size: 13px;
            font-weight: 650;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .mobile-header { display: none; }
        .menu-toggle { display: none; }
        .content {
            width: min(100% - 264px, 1440px);
            min-width: 0;
            margin-left: 264px;
            padding: 42px clamp(24px, 4vw, 64px) 56px;
        }
        .page-header { margin-bottom: 28px; }
        h1 { margin: 0; color: #152238; font-size: clamp(27px, 3vw, 34px); font-weight: 750; letter-spacing: -.7px; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .summary-card {
            min-height: 112px;
            padding: 19px 20px;
            background: #fff;
            border: 1px solid #e7ecf2;
            border-radius: 13px;
            box-shadow: 0 4px 18px rgba(22, 34, 56, .035);
        }
        .summary-label { margin: 0 0 13px; color: #667085; font-size: 13px; font-weight: 600; }
        .summary-value { margin: 0; color: #172033; font-size: 27px; font-weight: 750; line-height: 1; }
        .summary-card.paid .summary-value { color: #16804a; }
        .summary-card.outstanding .summary-value { color: #d97706; }
        .notice {
            padding: 13px 15px;
            margin: 0 0 20px;
            border: 1px solid #bbf7d0;
            border-radius: 9px;
            color: #166534;
            background: #f0fdf4;
            font-size: 14px;
        }
        .panel {
            margin-bottom: 24px;
            overflow: hidden;
            background: #fff;
            border: 1px solid #e7ecf2;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(22, 34, 56, .04);
        }
        #penalties {
            width: calc(100% + 16px);
            margin-left: -8px;
        }
        .dashboard-footer {
            display: grid;
            justify-items: center;
            gap: 14px;
            margin-top: 28px;
        }
        .developer-button {
            display: inline-flex;
            min-width: 160px;
            min-height: 44px;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            border: 1px solid #98a2b3;
            border-radius: 8px;
            color: #475467;
            background: transparent;
            font: inherit;
            font-size: 14px;
            font-weight: 600;
        }
        .developer-button:hover,
        .developer-button:focus-visible {
            color: #464feb;
            border-color: #464feb;
        }
        .developer-description {
            width: min(100%, 800px);
            padding: 20px 24px;
            border: 1px solid #e6e6e6;
            border-radius: 8px;
            color: #344054;
            background: transparent;
            font-size: 14px;
            line-height: 1.7;
        }
        .developer-description p { margin: 0; }
        .panel-header { padding: 22px 25px 18px; border-bottom: 1px solid #eef1f5; }
        .panel-header h2 { margin: 0; color: #1d2939; font-size: 18px; font-weight: 700; letter-spacing: -.2px; }
        .table-wrap { width: 100%; overflow-x: auto; }
        .penalty-table { width: 100%; margin: 0; border-collapse: collapse; font-size: 14px; }
        .penalty-table th,
        .penalty-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #eef1f5;
            text-align: left;
            overflow-wrap: anywhere;
            vertical-align: middle;
        }
        .penalty-table th {
            color: #667085;
            background: #f8fafc;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .55px;
            white-space: nowrap;
        }
        .penalty-table td { color: #344054; }
        .penalty-id {
            display: inline-flex;
            min-width: 30px;
            min-height: 24px;
            align-items: center;
            justify-content: center;
            padding: 2px 7px;
            border: 1px solid #e6eaf0;
            border-radius: 6px;
            color: #667085;
            background: #f8fafc;
            font-size: 12px;
            line-height: 1;
        }
        .penalty-table tbody tr:last-child td { border-bottom: 0; }
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            color: #b45309;
            background: #fef3c7;
            font-size: 12px;
            font-weight: 650;
        }
        .status-badge.paid { color: #15803d; background: #dcfce7; }
        .payment-action {
            display: inline-flex;
            min-height: 34px;
            align-items: center;
            justify-content: center;
            padding: 0 11px;
            border: 0;
            border-radius: 7px;
            color: #fff;
            background: #2563eb;
            text-decoration: none;
            font-size: 12px;
            font-weight: 650;
            white-space: nowrap;
        }
        .payment-action:hover { color: #fff; background: #1d4ed8; }
        .payment-options { display: flex; flex-wrap: wrap; gap: 7px; }
        .payment-status-message { display: block; margin: 0 0 9px; text-align: center; }
        .payment-status-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 7px; }
        .payment-status-actions .payment-action { white-space: normal; text-align: center; }
        .payment-status-message.paid { color: #15803d; font-weight: 700; }
        .payment-status-message.unpaid { color: #b45309; font-weight: 650; }
        .payment-status-message.pending { color: #1d4ed8; font-weight: 650; }
        .payment-action.secondary { color: #1d4ed8; background: #eff6ff; }
        .payment-action.secondary:hover { color: #fff; background: #1d4ed8; }
        .completed-label { color: #667085; font-size: 13px; }
        .empty-state { padding: 26px 16px !important; color: #667085 !important; text-align: center !important; }
        .sidebar-backdrop { display: none; }
        button:focus-visible, a:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
        @media (max-width: 1050px) {
            .content { padding-right: 28px; padding-left: 28px; }
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 760px) {
            #penalties {
                width: 100%;
                margin-left: 0;
            }
            .mobile-header {
                position: sticky;
                top: 0;
                z-index: 15;
                display: flex;
                height: 64px;
                align-items: center;
                justify-content: space-between;
                padding: 0 18px;
                background: #fff;
                border-bottom: 1px solid #e5eaf1;
            }
            .mobile-brand { display: flex; align-items: center; gap: 9px; color: #172033; font-size: 14px; font-weight: 700; }
            .mobile-brand .brand-mark { width: 34px; height: 34px; flex-basis: 34px; border-radius: 10px; }
            .menu-toggle {
                display: grid;
                width: 40px;
                min-height: 40px;
                padding: 0;
                place-items: center;
                color: #344054;
                background: #f2f4f7;
            }
            .menu-toggle:hover { color: #172033; background: #e4e7ec; }
            .menu-toggle svg { width: 21px; height: 21px; }
            .sidebar {
                width: min(290px, 86vw);
                transform: translateX(-105%);
                transition: transform .2s ease;
                box-shadow: 12px 0 30px rgba(16, 24, 40, .12);
            }
            .sidebar.open { transform: translateX(0); }
            .sidebar-backdrop {
                position: fixed;
                inset: 0;
                z-index: 19;
                display: none;
                width: 100%;
                height: 100%;
                padding: 0;
                border-radius: 0;
                background: rgba(16, 24, 40, .38);
                cursor: default;
            }
            .sidebar-backdrop.visible { display: block; }
            .content { width: 100%; margin-left: 0; padding: 30px 20px 44px; }
            .page-header { margin-bottom: 22px; }
        }
        @media (max-width: 560px) {
            .content { padding: 24px 14px 36px; }
            .summary-grid { gap: 10px; }
            .summary-card { min-height: 96px; padding: 15px 13px; }
            .summary-label { margin-bottom: 11px; font-size: 12px; }
            .summary-value { font-size: 24px; }
            .panel-header { padding: 19px 15px 16px; }
            .penalty-table,
            .penalty-table tbody,
            .penalty-table tr,
            .penalty-table td { display: block; width: 100%; }
            .penalty-table thead {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border: 0;
            }
            .penalty-table tbody { display: grid; gap: 11px; padding: 12px; }
            .penalty-table tbody tr { padding: 8px 12px; border: 1px solid #e7ecf2; border-radius: 9px; background: #fff; }
            .penalty-table tbody td {
                display: grid;
                grid-template-columns: minmax(86px, 34%) minmax(0, 1fr);
                gap: 10px;
                padding: 9px 0;
                border-bottom: 1px solid #eef1f5;
                white-space: normal;
            }
            .penalty-table tbody td:last-child { border-bottom: 0; }
            .penalty-table tbody td::before {
                content: attr(data-label);
                color: #667085;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
            }
            .penalty-table tbody td[colspan] { display: block; text-align: center; }
            .penalty-table tbody td[colspan]::before { content: none; }
            .payment-action { justify-self: start; white-space: normal; }
            .payment-options { gap: 6px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>
    <header class="mobile-header">
        <div class="mobile-brand">
            <img class="brand-mark" src="../cpc.logo.jpg" alt="Colegio de la Purisima Concepcion logo">
            <span>CPC Penalty System</span>
        </div>
        <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation" aria-expanded="false" aria-controls="studentSidebar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
    </header>
    <button class="sidebar-backdrop" id="sidebarBackdrop" type="button" aria-label="Close navigation"></button>
    <aside class="sidebar" id="studentSidebar">
        <p class="brand">
            <img class="brand-mark" src="../cpc.logo.jpg" alt="Colegio de la Purisima Concepcion logo">
            <span>CPC Penalty System</span>
        </p>
        <nav class="sidebar-nav" aria-label="Student navigation">
            <a href="student_dashboard.php?view=dashboard" <?= $view === 'dashboard' ? 'class="active" aria-current="page"' : '' ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></svg>
                <span>Student Dashboard</span>
            </a>
            <a href="student_dashboard.php?view=penalties" <?= $view === 'penalties' ? 'class="active" aria-current="page"' : '' ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h10l4 4v14H3V3h4Z"/><path d="M7 3v5h10V3M7 13h10M7 17h7"/></svg>
                <span>My Penalties</span>
            </a>
            <a href="student_dashboard.php?view=tuition" <?= $view === 'tuition' ? 'class="active" aria-current="page"' : '' ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/></svg>
                <span>Tuition Balance</span>
            </a>
            <a href="settings.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.4.8l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.4-.8l-1.7.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.7l-1.4-1.1 1.4-2.4 1.7.6a8 8 0 0 1 1.4-.8l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.4.8l1.7-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 1.7Z"/></svg>
                <span>Settings &amp; Profile</span>
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="profile">
                <?php $profilePicture = profile_picture_data_uri($_SESSION['user']['profile_picture'] ?? null); ?>
                <?php if ($profilePicture): ?>
                    <img src="<?= htmlspecialchars($profilePicture, ENT_QUOTES, 'UTF-8') ?>" alt="Profile photo" class="profile-avatar">
                <?php else: ?>
                    <div class="profile-avatar default"><?= htmlspecialchars(strtoupper(substr((string) ($_SESSION['user']['full_name'] ?? 'S'), 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <p class="profile-name"><?= htmlspecialchars($_SESSION['user']['full_name'] ?? 'Student', ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <nav class="sidebar-nav" aria-label="Account navigation">
                <a href="logout.php" class="logout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>
                    <span>Logout</span>
                </a>
            </nav>
        </div>
    </aside>

    <main class="content">
        <header class="page-header"><h1><?= $view === 'tuition' ? 'Tuition Balance' : ($view === 'penalties' ? 'My Penalties' : 'Student Dashboard') ?></h1></header>
        <?php if ($view === 'dashboard'): ?>
        <section class="summary-grid" aria-label="Penalty summary">
            <article class="summary-card"><p class="summary-label">Total Penalties</p><p class="summary-value"><?= count($penalties) ?></p></article>
            <article class="summary-card paid"><p class="summary-label">Paid</p><p class="summary-value"><?= $paidPenalties ?></p></article>
            <article class="summary-card outstanding"><p class="summary-label">Outstanding</p><p class="summary-value"><?= $outstandingPenalties ?></p></article>
            <article class="summary-card outstanding"><p class="summary-label">Penalty Amount Due</p><p class="summary-value">₱<?= number_format($outstandingAmount, 2) ?></p></article>
        </section>
        <?php endif; ?>

        <?php if (!empty($_SESSION['notice'])): ?>
            <div class="notice" role="status"><?= htmlspecialchars($_SESSION['notice'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php unset($_SESSION['notice']); ?>
        <?php endif; ?>

        <?php if ($view === 'penalties'): ?>
        <section class="panel" id="penalties" aria-labelledby="penalties-heading">
            <header class="panel-header"><h2 id="penalties-heading">My Penalties</h2></header>
            <div class="table-wrap">
                <table class="penalty-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Penalty</th>
                            <th>Student ID</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($penalties)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">No penalties found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($penalties as $penalty): ?>
                                <tr>
                                    <td data-label="ID"><span class="penalty-id"><?= (int) $penalty['id'] ?></span></td>
                                    <td data-label="Penalty"><?= htmlspecialchars($penalty['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td data-label="Student ID"><?= htmlspecialchars($penalty['student_id'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td data-label="Amount">₱<?= number_format((float) $penalty['amount'], 2) ?></td>
                                    <?php
                                        $paymentStatus = $penalty['status'] ?? 'unpaid';
                                        $isPaid = $paymentStatus === 'paid';
                                        $canChoosePaymentMethod = $paymentStatus === 'unpaid';
                                    ?>
                                    <td data-label="Payment Method">
                                        <?= $canChoosePaymentMethod
                                            ? 'Choose below'
                                            : htmlspecialchars(ucfirst((string) ($penalty['payment_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td data-label="Status"><span class="status-badge <?= $isPaid ? 'paid' : '' ?>"><?= htmlspecialchars(ucfirst((string) ($penalty['status'] ?? 'unpaid')), ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td data-label="Choose Payment Method">
                                        <?php if ($canChoosePaymentMethod): ?>
                                            <div class="payment-status-message unpaid">Not paid yet — choose a payment method.</div>
                                            <div class="payment-options payment-status-actions">
                                                <a href="online_payment.php?id=<?= (int) $penalty['id'] ?>" class="payment-action">Pay with GCash QR</a>
                                                <a href="payment_inperson.php?id=<?= (int) $penalty['id'] ?>" class="payment-action secondary">Request in-person payment</a>
                                            </div>
                                        <?php elseif (!$isPaid): ?>
                                            <?php if (($penalty['payment_type'] ?? '') === 'online'): ?>
                                                <span class="completed-label payment-status-message pending">GCash payment request sent — awaiting teacher verification. Not marked paid yet.</span>
                                                <div class="payment-status-actions">
                                                    <a href="online_payment.php?id=<?= (int) $penalty['id'] ?>" class="payment-action secondary">View GCash account and payment details</a>
                                                    <a href="refund.php?id=<?= (int) $penalty['id'] ?>" class="payment-action secondary">Report wrong reference / request refund</a>
                                                </div>
                                            <?php else: ?>
                                                <span class="completed-label payment-status-message pending">In-person payment request sent — awaiting teacher confirmation. Not marked paid yet.</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="completed-label payment-status-message paid">Payment confirmed — paid.</span>
                                            <?php if (($penalty['payment_type'] ?? '') === 'online'): ?>
                                                <div class="payment-status-actions">
                                                    <a href="online_payment.php?id=<?= (int) $penalty['id'] ?>" class="payment-action secondary">View GCash account details</a>
                                                    <a href="refund.php?id=<?= (int) $penalty['id'] ?>" class="payment-action secondary">Report wrong reference / request refund</a>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php elseif ($view === 'tuition'): ?>
        <section class="panel" aria-labelledby="tuition-heading">
            <header class="panel-header"><h2 id="tuition-heading">My Tuition Balance</h2></header>
            <p>Your tuition amount is managed by a teacher and is read-only in your account.</p>
            <?php if ($tuitionAccount): ?>
                <div class="summary-grid" aria-label="Tuition balance">
                    <article class="summary-card outstanding">
                        <p class="summary-label">Current balance due</p>
                        <p class="summary-value">₱<?= number_format($tuitionBalance, 2) ?></p>
                    </article>
                    <article class="summary-card">
                        <p class="summary-label">Status</p>
                        <p class="summary-value"><?= htmlspecialchars(ucfirst($tuitionAccount['status']), ENT_QUOTES, 'UTF-8') ?></p>
                    </article>
                </div>
                <?php if ($tuitionAccount['status'] === 'unpaid' && $tuitionBalance > 0): ?>
                    <div class="payment-status-actions">
                        <?php if ($tuitionBalance >= 3000): ?>
                            <a class="payment-action" href="online_payment.php?type=tuition&amp;id=<?= (int) $tuitionAccount['id'] ?>">Pay tuition (minimum ₱3,000) with GCash</a>
                        <?php else: ?>
                            <p class="payment-status-message pending">Tuition payments require at least ₱3,000.00. Contact your teacher for assistance with this balance.</p>
                        <?php endif; ?>
                    </div>
                <?php elseif ($tuitionAccount['status'] === 'pending'): ?>
                    <p class="payment-status-message pending">GCash payment request sent — awaiting teacher verification.</p>
                    <p>Payment amount awaiting verification: ₱<?= number_format((float) $tuitionAccount['pending_payment_amount'], 2) ?></p>
                    <p>Current balance: ₱<?= number_format($tuitionBalance, 2) ?></p>
                    <p>Reference number: <?= htmlspecialchars($tuitionAccount['reference_number'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></p>
                <?php elseif ($tuitionAccount['status'] === 'paid'): ?>
                    <p class="payment-status-message paid">Tuition payment confirmed.</p>
                    <p>Last payment: ₱<?= number_format((float) $tuitionAccount['paid_amount'], 2) ?></p>
                    <p>Reference number: <?= htmlspecialchars($tuitionAccount['reference_number'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></p>
                    <p>Paid at: <?= htmlspecialchars($tuitionAccount['paid_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if ($tuitionAccount['status'] === 'unpaid' && (float) ($tuitionAccount['paid_amount'] ?? 0) > 0): ?>
                    <p>Last verified payment: ₱<?= number_format((float) $tuitionAccount['paid_amount'], 2) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <p>Your teacher has not entered a tuition balance yet.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <footer class="dashboard-footer">
            <button class="developer-button" id="developerButton" type="button" aria-expanded="false" aria-controls="developerDescription">Developer &lt;&gt;</button>
            <section class="developer-description" id="developerDescription" aria-label="Developer information" hidden>
                <p><strong>Zyros Ivan Villanueva</strong> is a Software Developer specializing in software engineering, web development, application design, system integration, and cloud-based solutions. Skilled in modern programming languages, database management, API development, version control, testing, debugging, and software maintenance, he contributes to the development of scalable, secure, and efficient software systems. His responsibilities include designing and implementing applications, optimizing system performance, conducting code reviews, resolving technical issues, and collaborating with project stakeholders to deliver high-quality technology solutions. This identification serves as recognition of his role and professional capabilities as a Software Developer and confirms authorization to perform software development, testing, deployment, and maintenance activities within an organizational or professional environment.
            
            members:
        kaycee Quimpoo
        Mariel V Gerverro
        King Arthur Acuna
        Angelica Gererro</p>
            </section>
        </footer>
    </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('studentSidebar');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');
            const developerButton = document.getElementById('developerButton');
            const developerDescription = document.getElementById('developerDescription');

            developerButton.addEventListener('click', function () {
                const isExpanded = developerButton.getAttribute('aria-expanded') === 'true';
                developerButton.setAttribute('aria-expanded', String(!isExpanded));
                developerDescription.hidden = isExpanded;
            });

            function setNavigationOpen(isOpen) {
                sidebar.classList.toggle('open', isOpen);
                sidebarBackdrop.classList.toggle('visible', isOpen);
                menuToggle.setAttribute('aria-expanded', String(isOpen));
                menuToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
            }

            menuToggle.addEventListener('click', function () {
                setNavigationOpen(!sidebar.classList.contains('open'));
            });
            sidebarBackdrop.addEventListener('click', function () {
                setNavigationOpen(false);
            });
            sidebar.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    setNavigationOpen(false);
                });
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    setNavigationOpen(false);
                }
            });
        });
    </script>
</body>
</html>