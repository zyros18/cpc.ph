<?php
require_once __DIR__ . '/auth.php';
require_role('teacher');
ensure_tuition_balances_table();

$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'dashboard';
if (!in_array($view, ['dashboard', 'students', 'penalties'], true)) {
    $view = 'dashboard';
}

if (empty($_SESSION['teacher_dashboard_csrf_token'])) {
    $_SESSION['teacher_dashboard_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['teacher_dashboard_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $redirectView = 'penalties';

        if ($action === 'addPenalty') {
            $studentUserId = filter_input(INPUT_POST, 'student_user_id', FILTER_VALIDATE_INT);
            $title = trim($_POST['title'] ?? '');
            $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
            $departmentInput = $_POST['department'] ?? '';
            $department = is_string($departmentInput) ? $departmentInput : '';
            $yearLevelInput = $_POST['year_level'] ?? '';
            $yearLevel = is_string($yearLevelInput) ? $yearLevelInput : '';
            $submittedToken = $_POST['csrf_token'] ?? '';

            if (!$studentUserId || !is_string($submittedToken) ||
                !hash_equals($csrfToken, $submittedToken) ||
                $department === '' || strlen($department) > 30 ||
                !in_array($yearLevel, ['1st', '2nd', '3rd', '4th'], true) ||
                $title === '' || $amount === false || $amount <= 0) {
                throw new RuntimeException('Select a department, a year level from 1st to 4th, and a student from those choices.');
            }

            $yearCondition = 'year_level = :year_level';
            if ($department === 'CET') {
                $studentCheck = database()->prepare(
                    "SELECT id FROM users
                     WHERE id = :id AND role = 'student'
                       AND (department = 'CET' OR department IN ('IT', 'CE', 'EE')
                            OR course IN ('IT', 'CE', 'EE'))
                       AND {$yearCondition}"
                );
            } else {
                $studentCheck = database()->prepare(
                    "SELECT id FROM users
                     WHERE id = :id AND role = 'student' AND department = :department
                       AND {$yearCondition}"
                );
            }
            $studentCheckParameters = ['id' => $studentUserId];
            if ($department !== 'CET') {
                $studentCheckParameters['department'] = $department;
            }
            $studentCheckParameters['year_level'] = $yearLevel;
            $studentCheck->execute($studentCheckParameters);
            if (!$studentCheck->fetchColumn()) {
                throw new RuntimeException('The selected account is not a student. Please select a student again.');
            }

            $insert = database()->prepare(
                "INSERT INTO penalties (student_id, title, amount, status, payment_method)
                 VALUES (:student_id, :title, :amount, 'unpaid', NULL)"
            );
            $insert->execute([
                'student_id' => $studentUserId,
                'title' => $title,
                'amount' => $amount,
            ]);
            $_SESSION['dashboard_success'] = 'Penalty added. The student can choose Online or In-person payment from their dashboard.';
        } elseif ($action === 'updateTuitionBalance') {
            $redirectView = 'students';
            $studentUserId = filter_input(INPUT_POST, 'student_user_id', FILTER_VALIDATE_INT);
            $submittedToken = $_POST['csrf_token'] ?? '';
            $amount = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '';
            if (!$studentUserId || !is_string($submittedToken) ||
                !hash_equals($csrfToken, $submittedToken) ||
                !preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $amount)) {
                throw new RuntimeException('Select a student and enter a valid tuition balance.');
            }

            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
            $amountCents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
            $amount = intdiv($amountCents, 100) . '.' .
                str_pad((string) ($amountCents % 100), 2, '0', STR_PAD_LEFT);

            $db = database();
            $db->beginTransaction();
            $studentCheck = $db->prepare(
                "SELECT id FROM users WHERE id = :id AND role = 'student' FOR UPDATE"
            );
            $studentCheck->execute(['id' => $studentUserId]);
            if (!$studentCheck->fetchColumn()) {
                throw new RuntimeException('The selected account is not a student.');
            }

            $balanceQuery = $db->prepare(
                'SELECT id, status FROM tuition_balances WHERE student_id = :student_id FOR UPDATE'
            );
            $balanceQuery->execute(['student_id' => $studentUserId]);
            $balance = $balanceQuery->fetch();
            if ($balance && $balance['status'] === 'pending') {
                throw new RuntimeException('A tuition payment is awaiting verification. Review it before changing the balance.');
            }

            if ($balance) {
                $db->prepare(
                    "UPDATE tuition_balances
                     SET amount = :amount, paid_amount = NULL, status = 'unpaid',
                         payment_method = NULL, reference_number = NULL, paid_at = NULL
                     WHERE id = :id"
                )->execute(['amount' => $amount, 'id' => $balance['id']]);
            } else {
                $db->prepare(
                    "INSERT INTO tuition_balances (student_id, amount, status)
                     VALUES (:student_id, :amount, 'unpaid')"
                )->execute(['student_id' => $studentUserId, 'amount' => $amount]);
            }
            $db->commit();
            $_SESSION['dashboard_success'] = 'Tuition balance saved. The student can view and pay this server-stored amount.';
        } elseif ($action === 'reviewPaymentRequest') {
            $paymentType = $_POST['payment_type'] ?? 'penalty';
            $paymentIdField = $paymentType === 'tuition' ? 'tuition_id' : 'penalty_id';
            $paymentId = filter_input(INPUT_POST, $paymentIdField, FILTER_VALIDATE_INT);
            $decision = $_POST['decision'] ?? '';
            $submittedToken = $_POST['csrf_token'] ?? '';

            if (!$paymentId || !is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken) ||
                !in_array($decision, ['confirm', 'reject'], true) ||
                !in_array($paymentType, ['penalty', 'tuition'], true)) {
                throw new RuntimeException('This payment action is invalid or the form expired. Reload and try again.');
            }

            $db = database();
            $db->beginTransaction();
            if ($paymentType === 'tuition') {
                $requestQuery = $db->prepare(
                    'SELECT status, amount, pending_payment_amount
                     FROM tuition_balances WHERE id = :id FOR UPDATE'
                );
                $requestQuery->execute(['id' => $paymentId]);
                $request = $requestQuery->fetch();
                if (!$request || $request['status'] !== 'pending') {
                    throw new RuntimeException('That tuition payment request is no longer pending.');
                }

                if ($decision === 'confirm') {
                    $balanceCents = money_to_cents((string) $request['amount']);
                    $paymentCents = money_to_cents((string) $request['pending_payment_amount']);
                    if ($balanceCents === null || $paymentCents === null ||
                        $paymentCents < 300000 || $paymentCents > $balanceCents) {
                        throw new RuntimeException('The tuition payment amount is invalid. Reject the request and ask the student to submit it again.');
                    }
                    $remainingCents = $balanceCents - $paymentCents;
                    $remainingAmount = intdiv($remainingCents, 100) . '.' .
                        str_pad((string) ($remainingCents % 100), 2, '0', STR_PAD_LEFT);
                    $paymentAmount = intdiv($paymentCents, 100) . '.' .
                        str_pad((string) ($paymentCents % 100), 2, '0', STR_PAD_LEFT);
                    $update = $db->prepare(
                        "UPDATE tuition_balances
                         SET status = :next_status, paid_amount = :paid_amount,
                             amount = :remaining_amount, pending_payment_amount = NULL,
                             paid_at = NOW()
                         WHERE id = :id AND status = 'pending'"
                    );
                    $update->execute([
                        'next_status' => $remainingCents === 0 ? 'paid' : 'unpaid',
                        'paid_amount' => $paymentAmount,
                        'remaining_amount' => $remainingAmount,
                        'id' => $paymentId,
                    ]);
                    $successMessage = $remainingCents === 0
                        ? 'Tuition payment confirmed. The student balance is now zero.'
                        : 'Tuition payment confirmed. The remaining balance is ₱' . number_format($remainingCents / 100, 2) . '.';
                } else {
                    $update = $db->prepare(
                        "UPDATE tuition_balances
                         SET status = 'unpaid', pending_payment_amount = NULL, payment_method = NULL,
                             reference_number = NULL, paid_at = NULL
                         WHERE id = :id AND status = 'pending'"
                    );
                    $successMessage = 'Tuition payment request rejected. The student can submit a new request.';
                }
                if ($decision === 'reject') {
                    $update->execute(['id' => $paymentId]);
                }
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('The tuition request changed. Refresh and review its latest status.');
                }
                $db->commit();
                $_SESSION['dashboard_success'] = $successMessage;
                $redirectView = 'students';
            } else {
                $requestQuery = $db->prepare(
                    'SELECT status, payment_method FROM penalties WHERE id = :id FOR UPDATE'
                );
                $requestQuery->execute(['id' => $paymentId]);
                $request = $requestQuery->fetch();
                if (!$request || $request['status'] !== 'pending' ||
                    !in_array($request['payment_method'], ['online', 'in-person'], true)) {
                    throw new RuntimeException('That payment request is no longer pending.');
                }

                if ($decision === 'confirm') {
                    $update = $db->prepare(
                        "UPDATE penalties SET status = 'paid', paid_at = NOW()
                         WHERE id = :id AND status = 'pending'"
                    );
                    $successMessage = 'Payment confirmed. The penalty is now marked paid.';
                } else {
                    $update = $db->prepare(
                        "UPDATE penalties
                         SET status = 'unpaid', payment_method = NULL, reference_number = NULL, paid_at = NULL
                         WHERE id = :id AND status = 'pending'"
                    );
                    $successMessage = 'Payment request rejected. The student can submit a new payment request.';
                }
                $update->execute(['id' => $paymentId]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('The payment request changed. Refresh and review its latest status.');
                }

                $db->prepare(
                    "UPDATE paymongo_checkouts
                     SET paymongo_session_id = NULL, checkout_url = NULL, checkout_status = 'failed'
                     WHERE penalty_id = :penalty_id AND checkout_status IN ('creating', 'active')"
                )->execute(['penalty_id' => $paymentId]);
                $db->commit();
                $_SESSION['dashboard_success'] = $successMessage;
            }
        } elseif ($action === 'deletePayment') {
            $penaltyId = filter_input(INPUT_POST, 'penalty_id', FILTER_VALIDATE_INT);
            if (!$penaltyId) {
                throw new RuntimeException('Choose a valid penalty record to delete.');
            }

            $delete = database()->prepare('DELETE FROM penalties WHERE id = :id');
            $delete->execute(['id' => $penaltyId]);
            $_SESSION['dashboard_success'] = $delete->rowCount() ? 'Penalty record deleted.' : 'That penalty record no longer exists.';
        }
    } catch (RuntimeException $exception) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['dashboard_error'] = $exception->getMessage();
    } catch (Throwable $exception) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Teacher dashboard action failed: ' . $exception->getMessage());
        $_SESSION['dashboard_error'] = 'The penalty could not be saved. Please try again.';
    }

    header('Location: teachers_dashboard.php?view=' . rawurlencode($redirectView) . '#' .
        ($redirectView === 'students' ? 'students' : 'penalties'));
    exit;
}

$teacher = $_SESSION['user'];
$studentStatement = database()->query(
    "SELECT u.id, u.username, u.full_name, u.student_id, u.cellphone, u.department, u.course, u.year_level,
            tb.id AS tuition_balance_id, tb.amount AS tuition_amount,
            tb.status AS tuition_status, tb.pending_payment_amount, tb.paid_amount,
            tb.reference_number AS tuition_reference
     FROM users u
     LEFT JOIN tuition_balances tb ON tb.student_id = u.id
     WHERE u.role = 'student'
     ORDER BY u.full_name"
);
$students = $studentStatement->fetchAll();
$departments = ['CET', 'CTHM', 'CASE', 'CRIM', 'SSG', 'CME', 'COL', 'CMA', 'SHS', 'JHS', 'ELEMENTARY'];
foreach ($students as $student) {
    $studentDepartment = $student['department'] ?? '';
    if ($studentDepartment !== '' && !in_array($studentDepartment, ['IT', 'CE', 'EE'], true) &&
        !in_array($studentDepartment, $departments, true)) {
        $departments[] = $studentDepartment;
    }
}
$statement = database()->query(
    'SELECT p.id, p.title, p.amount, p.status, p.payment_method, p.reference_number, u.full_name
     FROM penalties p
     INNER JOIN users u ON u.id = p.student_id
     ORDER BY p.created_at DESC'
);
$penalties = $statement->fetchAll();
$summaryStatement = database()->query(
    "SELECT COUNT(*) AS total_penalties,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_penalties,
            COALESCE(SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_penalties
     FROM penalties"
);
$summary = $summaryStatement->fetch();
$dashboardError = $_SESSION['dashboard_error'] ?? '';
$dashboardSuccess = $_SESSION['dashboard_success'] ?? '';
unset($_SESSION['dashboard_error'], $_SESSION['dashboard_success']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Teacher Dashboard | CPC Penalty System</title>
    <style>
        :root { font-family: Inter, "Segoe UI", Arial, sans-serif; color: #172033; background: #f1f5f9; font-synthesis: none; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; background: #f1f5f9; }
        .sidebar { position: fixed; inset: 0 auto 0 0; z-index: 20; display: flex; width: 264px; flex-direction: column; padding: 26px 18px 20px; background: #fff; border-right: 1px solid #e5eaf1; }
        .brand { display: flex; align-items: center; gap: 12px; margin: 0 8px 36px; color: #172033; font-size: 15px; font-weight: 750; line-height: 1.3; }
        .brand-mark { display: block; width: 40px; height: 40px; flex: 0 0 40px; object-fit: contain; border-radius: 12px; }
        .brand-name { display: block; }
        nav { display: flex; flex: 1; flex-direction: column; gap: 6px; }
        nav a { display: flex; min-height: 46px; align-items: center; gap: 12px; padding: 0 13px; border-radius: 9px; color: #5b6678; text-decoration: none; font-size: 14px; font-weight: 600; transition: background .15s ease, color .15s ease; }
        nav a svg { width: 19px; height: 19px; flex: 0 0 19px; }
        nav a:hover { color: #1d4ed8; background: #eff6ff; }
        nav a.active { position: relative; color: #fff; background: #2563eb; box-shadow: 0 5px 12px rgba(37, 99, 235, .18); }
        nav a.active::before { position: absolute; left: -18px; width: 4px; height: 24px; border-radius: 0 4px 4px 0; background: #2563eb; content: ""; }
        nav a.logout { margin-top: auto; color: #b42318; }
        nav a.logout:hover { color: #912018; background: #fff1f0; }
        .sidebar-footer { margin-top: auto; padding-top: 18px; border-top: 1px solid #edf0f4; }
        .sidebar-footer nav { flex: 0; }
        .profile { display: flex; align-items: center; gap: 10px; padding: 8px 5px 0; }
        .avatar { width: 38px; height: 38px; display: grid; flex: 0 0 38px; place-items: center; border-radius: 50%; color: #1e40af; background: #dbeafe; font-weight: 700; object-fit: cover; }
        .profile-name { overflow: hidden; margin: 0; color: #344054; font-size: 13px; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
        .mobile-header { display: none; }
        .content { width: min(100% - 264px, 1440px); min-width: 0; margin-left: 264px; padding: 42px clamp(24px, 4vw, 64px) 56px; }
        .page-header { margin-bottom: 28px; }
        h1 { margin: 0; color: #152238; font-size: clamp(27px, 3vw, 34px); font-weight: 750; letter-spacing: -.7px; }
        .panel { padding: 25px; margin-bottom: 24px; background: #fff; border: 1px solid #e7ecf2; border-radius: 14px; box-shadow: 0 4px 18px rgba(22, 34, 56, .04); }
        #penalties { padding: 29px; }
        .panel h2 { margin: 0 0 22px; color: #1d2939; font-size: 18px; font-weight: 700; letter-spacing: -.2px; }
        .summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 24px; }
        .summary-card { min-height: 112px; padding: 19px 20px; background: #fff; border: 1px solid #e7ecf2; border-radius: 13px; box-shadow: 0 4px 18px rgba(22, 34, 56, .035); }
        .summary-label { margin: 0 0 13px; color: #667085; font-size: 13px; font-weight: 600; }
        .summary-value { margin: 0; color: #172033; font-size: 27px; font-weight: 750; line-height: 1; }
        .summary-card.paid .summary-value { color: #16804a; }
        .summary-card.unpaid .summary-value { color: #d97706; }
        .message { display: flex; align-items: center; gap: 10px; padding: 13px 15px; margin: 0 0 18px; border: 1px solid #fecaca; border-radius: 9px; color: #991b1b; background: #fef2f2; font-size: 14px; }
        .message.success { border-color: #bbf7d0; color: #166534; background: #f0fdf4; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 19px 20px; }
        label { display: grid; gap: 8px; color: #344054; font-size: 13px; font-weight: 650; }
        input, select { width: 100%; height: 46px; padding: 0 13px; border: 1px solid #d0d5dd; border-radius: 8px; color: #172033; background: #fff; font: inherit; font-size: 14px; transition: border-color .15s ease, box-shadow .15s ease; }
        input[readonly] { color: #667085; background: #f8fafc; }
        input:focus, select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, .15); }
        .form-actions { display: flex; justify-content: flex-end; margin-top: 22px; }
        button { min-height: 42px; padding: 0 19px; border: 0; border-radius: 8px; color: #fff; background: #2563eb; font: inherit; font-size: 14px; font-weight: 650; cursor: pointer; transition: background .15s ease; }
        button:hover { background: #1d4ed8; }
        button:focus-visible, a:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
        .table-wrap { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 14px 12px; border-bottom: 1px solid #eef1f5; text-align: left; overflow-wrap: anywhere; }
        th { color: #667085; background: #f8fafc; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .55px; white-space: nowrap; }
        td { color: #344054; }
        tbody tr:last-child td { border-bottom: 0; }
        .delete-form, .review-form { margin: 0; }
        .review-actions { display: flex; flex-wrap: wrap; gap: 6px; }
        .tuition-form { display: grid; min-width: 150px; gap: 6px; }
        .tuition-form label { font-size: 11px; }
        .tuition-form input { height: 36px; padding: 0 8px; font-size: 13px; }
        .review-button { min-height: 32px; padding: 0 11px; font-size: 12px; }
        .review-button.confirm { color: #166534; background: #dcfce7; }
        .review-button.confirm:hover { color: #fff; background: #15803d; }
        .review-button.reject { color: #b42318; background: #fff1f0; }
        .review-button.reject:hover { color: #fff; background: #b42318; }
        .delete-button { min-height: 32px; padding: 0 11px; color: #b42318; background: #fff1f0; font-size: 12px; }
        .delete-button:hover { color: #fff; background: #b42318; }
        .status-badge { display: inline-flex; align-items: center; padding: 5px 9px; border-radius: 999px; color: #475467; background: #f2f4f7; font-size: 12px; font-weight: 650; text-transform: capitalize; }
        .status-badge.paid { color: #15803d; background: #dcfce7; }
        .status-badge.unpaid { color: #b45309; background: #fef3c7; }
        .status-badge.pending { color: #1d4ed8; background: #dbeafe; }
        .empty-state { padding: 24px 12px; color: #667085; text-align: center; }
        .sidebar-backdrop { display: none; }
        @media (max-width: 1050px) {
            .content { padding-right: 28px; padding-left: 28px; }
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 760px) {
            .mobile-header { position: sticky; top: 0; z-index: 15; display: flex; height: 64px; align-items: center; justify-content: space-between; padding: 0 18px; background: #fff; border-bottom: 1px solid #e5eaf1; }
            .mobile-brand { display: flex; align-items: center; gap: 9px; color: #172033; font-size: 14px; font-weight: 700; }
            .mobile-brand .brand-mark { width: 34px; height: 34px; flex-basis: 34px; border-radius: 10px; }
            .menu-toggle { display: grid; width: 40px; min-height: 40px; padding: 0; place-items: center; color: #344054; background: #f2f4f7; }
            .menu-toggle:hover { color: #172033; background: #e4e7ec; }
            .menu-toggle svg { width: 21px; height: 21px; }
            .sidebar { width: min(290px, 86vw); transform: translateX(-105%); transition: transform .2s ease; box-shadow: 12px 0 30px rgba(16, 24, 40, .12); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-backdrop { position: fixed; inset: 0; z-index: 19; display: none; width: 100%; height: 100%; min-height: 100%; padding: 0; border-radius: 0; background: rgba(16, 24, 40, .38); cursor: default; }
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
            .panel { padding: 19px 15px; margin-bottom: 17px; border-radius: 12px; }
            #penalties { padding: 21px 17px; }
            .panel h2 { margin-bottom: 18px; }
            .form-grid { grid-template-columns: 1fr; gap: 16px; }
            .form-actions button { width: 100%; }
            table, tbody, tr, td { display: block; width: 100%; }
            thead { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
            tbody { display: grid; gap: 11px; }
            tbody tr { padding: 8px 12px; border: 1px solid #e7ecf2; border-radius: 9px; background: #fff; }
            tbody td { display: grid; grid-template-columns: minmax(86px, 34%) minmax(0, 1fr); gap: 10px; padding: 9px 0; border-bottom: 1px solid #eef1f5; white-space: normal; }
            tbody td:last-child { border-bottom: 0; }
            tbody td::before { content: attr(data-label); color: #667085; font-size: 11px; font-weight: 700; text-transform: uppercase; }
            .empty-state { display: block; }
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
        <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation" aria-expanded="false" aria-controls="teacherSidebar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
    </header>
    <button class="sidebar-backdrop" id="sidebarBackdrop" type="button" aria-label="Close navigation"></button>
    <aside class="sidebar" id="teacherSidebar">
        <p class="brand">
            <img class="brand-mark" src="../cpc.logo.jpg" alt="Colegio de la Purisima Concepcion logo">
            <span class="brand-name">CPC Penalty System</span>
        </p>
        <nav aria-label="Teacher navigation">
            <a href="teachers_dashboard.php?view=dashboard" <?= $view === 'dashboard' ? 'class="active" aria-current="page"' : '' ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></svg>
                <span>CPC Teacher Panel</span>
            </a>
            <a href="teachers_dashboard.php?view=students" <?= $view === 'students' ? 'class="active" aria-current="page"' : '' ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span>Students</span>
            </a>
            <a href="teachers_dashboard.php?view=penalties" <?= $view === 'penalties' ? 'class="active" aria-current="page"' : '' ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h10l4 4v14H3V3h4Z"/><path d="M7 3v5h10V3M7 13h10M7 17h7"/></svg>
                <span>Penalty Records</span>
            </a>
            <a href="settings.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.4.8l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.4-.8l-1.7.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.7l-1.4-1.1 1.4-2.4 1.7.6a8 8 0 0 1 1.4-.8l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.4.8l1.7-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 1.7Z"/></svg>
                <span>Settings &amp; Profile</span>
            </a>
            <a href="gcash_settings.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M7 15h4"/></svg>
                <span>GCash Business QR</span>
            </a>
            <a href="refund_center.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg>
                <span>Refund Center</span>
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="profile">
                <?php $profilePicture = profile_picture_data_uri($teacher['profile_picture'] ?? null); ?>
                <?php if ($profilePicture): ?>
                    <img class="avatar" src="<?= htmlspecialchars($profilePicture, ENT_QUOTES, 'UTF-8') ?>" alt="Profile photo">
                <?php else: ?>
                    <div class="avatar"><?= htmlspecialchars(strtoupper(substr((string) ($teacher['full_name'] ?? 'T'), 0, 1))) ?></div>
                <?php endif; ?>
                <p class="profile-name"><?= htmlspecialchars((string) ($teacher['full_name'] ?? 'Teacher'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <nav aria-label="Account navigation">
                <a href="logout.php" class="logout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>
                    <span>Logout</span>
                </a>
            </nav>
        </div>
    </aside>
    <main class="content">
        <header class="page-header"><h1>Teacher Dashboard</h1></header>
        <?php if ($dashboardError): ?><p class="message" role="alert"><?= htmlspecialchars($dashboardError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($dashboardSuccess): ?><p class="message success" role="status"><?= htmlspecialchars($dashboardSuccess, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($view === 'dashboard'): ?>
        <section class="summary-grid" aria-label="Dashboard summary">
            <article class="summary-card"><p class="summary-label">Total Students</p><p class="summary-value"><?= count($students) ?></p></article>
            <article class="summary-card"><p class="summary-label">Total Penalties</p><p class="summary-value"><?= (int) $summary['total_penalties'] ?></p></article>
            <article class="summary-card paid"><p class="summary-label">Paid Penalties</p><p class="summary-value"><?= (int) $summary['paid_penalties'] ?></p></article>
            <article class="summary-card unpaid"><p class="summary-label">Unpaid Penalties</p><p class="summary-value"><?= (int) $summary['unpaid_penalties'] ?></p></article>
        </section>
        <?php endif; ?>
        <?php if (in_array($view, ['dashboard', 'penalties'], true)): ?>
        <section class="panel" aria-labelledby="add-penalty-title">
            <h2 id="add-penalty-title">Add Student Penalty</h2>
            <form id="penaltyForm" method="post">
                <input type="hidden" name="action" value="addPenalty">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-grid">
                    <label for="penaltyDepartment">Department
                        <select id="penaltyDepartment" name="department" required>
                            <option value="">Choose a department</option>
                            <?php foreach ($departments as $departmentOption): ?>
                                <option value="<?= htmlspecialchars($departmentOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($departmentOption, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label for="penaltyYearLevel">Year level
                        <select id="penaltyYearLevel" name="year_level" required disabled>
                            <option value="">Choose a department first</option>
                            <option value="1st">1st</option>
                            <option value="2nd">2nd</option>
                            <option value="3rd">3rd</option>
                            <option value="4th">4th</option>
                        </select>
                    </label>
                    <label for="penaltyStudentUserId">Student
                        <select id="penaltyStudentUserId" name="student_user_id" required disabled>
                            <option value="">Choose a student</option>
                            <?php foreach ($students as $student): ?>
                                <?php
                                $studentDepartment = (string) ($student['department'] ?? '');
                                $studentCourse = (string) ($student['course'] ?? '');
                                $isCetStudent = $studentDepartment === 'CET' ||
                                    in_array($studentDepartment, ['IT', 'CE', 'EE'], true) ||
                                    in_array($studentCourse, ['IT', 'CE', 'EE'], true);
                                $studentYear = (string) ($student['year_level'] ?? '');
                                $studentOptionLabel = $student['full_name'] . ' (' .
                                    ($student['student_id'] ?? 'No student ID') . ')';
                                if ($studentCourse !== '') {
                                    $studentOptionLabel .= ' - ' . $studentCourse;
                                }
                                if ($studentYear !== '') {
                                    $studentOptionLabel .= ' - ' . $studentYear;
                                }
                                ?>
                                <option value="<?= (int) $student['id'] ?>" data-department="<?= htmlspecialchars($studentDepartment, ENT_QUOTES, 'UTF-8') ?>" data-course="<?= htmlspecialchars($studentCourse, ENT_QUOTES, 'UTF-8') ?>" data-cet-student="<?= $isCetStudent ? '1' : '0' ?>" data-year-level="<?= htmlspecialchars($studentYear, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($studentOptionLabel, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label for="penaltyTitle">Penalty Title <input id="penaltyTitle" name="title" maxlength="150" required placeholder="Enter penalty title"></label>
                    <label for="penaltyAmount">Amount <input id="penaltyAmount" type="number" name="amount" min="0.01" step="0.01" required placeholder="0.00"></label>
                </div>
                <div class="form-actions"><button type="submit">Add Penalty</button></div>
            </form>
        </section>
        <?php endif; ?>
        <?php if (in_array($view, ['dashboard', 'students'], true)): ?>
        <section class="panel" id="students" aria-labelledby="students-title">
            <h2 id="students-title">Students</h2>
            <div class="table-wrap"><table><thead><tr><th>Student ID</th><th>Name</th><th>Username</th><th>Cellphone</th><th>Course</th><th>Department</th><th>Year level</th><th>Tuition Balance</th><th>Action</th></tr></thead><tbody>
            <?php if (empty($students)): ?>
                <tr><td class="empty-state" colspan="9">No student accounts found.</td></tr>
            <?php else: ?>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td data-label="Student ID"><?= htmlspecialchars($student['student_id'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-label="Name"><?= htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-label="Username"><?= htmlspecialchars($student['username'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-label="Cellphone"><?= htmlspecialchars($student['cellphone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-label="Course"><?= htmlspecialchars($student['course'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-label="Department"><?= htmlspecialchars($student['department'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-label="Year level"><?= htmlspecialchars($student['year_level'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-label="Tuition Balance">
                            <?php if ($student['tuition_status'] === 'paid'): ?>
                                ₱0.00 (Paid; last payment ₱<?= number_format((float) $student['paid_amount'], 2) ?>)
                            <?php elseif ($student['tuition_amount'] !== null): ?>
                                ₱<?= number_format((float) $student['tuition_amount'], 2) ?> (<?= htmlspecialchars(ucfirst((string) $student['tuition_status']), ENT_QUOTES, 'UTF-8') ?>)
                            <?php else: ?>
                                Not set
                            <?php endif; ?>
                        </td>
                        <td data-label="Tuition actions">
                            <?php if ($student['tuition_status'] === 'pending'): ?>
                                <p>Payment amount: ₱<?= number_format((float) $student['pending_payment_amount'], 2) ?></p>
                                <p>Balance before payment: ₱<?= number_format((float) $student['tuition_amount'], 2) ?></p>
                                <p>GCash reference: <?= htmlspecialchars($student['tuition_reference'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></p>
                                <div class="review-actions">
                                    <form class="review-form" method="post" onsubmit="return confirm('Confirm this tuition GCash payment?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="reviewPaymentRequest">
                                        <input type="hidden" name="payment_type" value="tuition">
                                        <input type="hidden" name="decision" value="confirm">
                                        <input type="hidden" name="tuition_id" value="<?= (int) $student['tuition_balance_id'] ?>">
                                        <button class="review-button confirm" type="submit">Confirm tuition payment</button>
                                    </form>
                                    <form class="review-form" method="post" onsubmit="return confirm('Reject this tuition payment request?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="reviewPaymentRequest">
                                        <input type="hidden" name="payment_type" value="tuition">
                                        <input type="hidden" name="decision" value="reject">
                                        <input type="hidden" name="tuition_id" value="<?= (int) $student['tuition_balance_id'] ?>">
                                        <button class="review-button reject" type="submit">Reject</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <form class="tuition-form" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="updateTuitionBalance">
                                    <input type="hidden" name="student_user_id" value="<?= (int) $student['id'] ?>">
                                    <label for="tuitionAmount<?= (int) $student['id'] ?>">Set balance (₱)</label>
                                    <input id="tuitionAmount<?= (int) $student['id'] ?>" type="number" name="amount" min="0" max="99999999.99" step="0.01" value="<?= $student['tuition_amount'] !== null ? htmlspecialchars((string) $student['tuition_amount'], ENT_QUOTES, 'UTF-8') : '' ?>" required>
                                    <button class="review-button" type="submit">Save balance</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody></table></div>
        </section>
        <?php endif; ?>
        <?php if (in_array($view, ['dashboard', 'penalties'], true)): ?>
        <section class="panel" id="penalties" aria-labelledby="records-title">
            <h2 id="records-title">Penalty Records</h2>
            <div class="table-wrap"><table><thead><tr><th>Student</th><th>Penalty</th><th>Amount</th><th>Payment Method</th><th>Reference Number</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php if (empty($penalties)): ?>
                <tr><td class="empty-state" colspan="7">No penalty records found.</td></tr>
            <?php else: ?>
                <?php foreach ($penalties as $penalty): ?>
                    <?php $statusClass = strtolower((string) $penalty['status']); ?>
                    <?php $isPaymentRequest = $penalty['status'] === 'pending' && in_array($penalty['payment_method'], ['online', 'in-person'], true); ?>
                    <tr>
                        <td data-label="Student"><?= htmlspecialchars($penalty['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-label="Penalty"><?= htmlspecialchars($penalty['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-label="Amount"><?= htmlspecialchars($penalty['amount'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-label="Payment Method"><?= htmlspecialchars($penalty['payment_method'] ? ucfirst($penalty['payment_method']) : 'Not selected', ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-label="Reference Number"><?= htmlspecialchars($penalty['reference_number'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td data-label="Status"><span class="status-badge <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst((string) $penalty['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td data-label="Action">
                            <?php if ($isPaymentRequest): ?>
                                <div class="review-actions">
                                    <form class="review-form" method="post" onsubmit="return confirm('Confirm that you verified this payment was received?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="reviewPaymentRequest">
                                        <input type="hidden" name="payment_type" value="penalty">
                                        <input type="hidden" name="decision" value="confirm">
                                        <input type="hidden" name="penalty_id" value="<?= (int) $penalty['id'] ?>">
                                        <button class="review-button confirm" type="submit">Confirm</button>
                                    </form>
                                    <form class="review-form" method="post" onsubmit="return confirm('Reject this payment request? The student can submit a new request.')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="reviewPaymentRequest">
                                        <input type="hidden" name="payment_type" value="penalty">
                                        <input type="hidden" name="decision" value="reject">
                                        <input type="hidden" name="penalty_id" value="<?= (int) $penalty['id'] ?>">
                                        <button class="review-button reject" type="submit">Reject</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                            <form class="delete-form" method="post" onsubmit="return confirm('Delete this payment record?')">
                                <input type="hidden" name="action" value="deletePayment">
                                <input type="hidden" name="penalty_id" value="<?= (int) $penalty['id'] ?>">
                                <button class="delete-button" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody></table></div>
        </section>
        <?php endif; ?>
    </main>
    <script>
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('teacherSidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const penaltyDepartment = document.getElementById('penaltyDepartment');
    const penaltyYearLevel = document.getElementById('penaltyYearLevel');
    const penaltyStudent = document.getElementById('penaltyStudentUserId');

    if (penaltyDepartment && penaltyYearLevel && penaltyStudent) {
        function studentMatchesDepartment(option, department) {
            return department === 'CET'
                ? option.dataset.cetStudent === '1'
                : option.dataset.department === department;
        }

        function updatePenaltyStudentOptions() {
            const department = penaltyDepartment.value;
            const yearLevel = penaltyYearLevel.value;
            let matchingStudents = 0;
            penaltyStudent.value = '';
            penaltyStudent.disabled = department === '' || yearLevel === '';

            Array.from(penaltyStudent.options).forEach(function (option, index) {
                if (index === 0) {
                    option.hidden = false;
                    option.textContent = department === ''
                        ? 'Choose a department first'
                        : (yearLevel === '' ? 'Choose a year/grade level first' : 'Choose a student');
                    return;
                }

                const matches = studentMatchesDepartment(option, department) &&
                    option.dataset.yearLevel === yearLevel;
                option.hidden = !matches;
                if (matches) {
                    matchingStudents++;
                }
            });

            if (department !== '' && yearLevel !== '' && matchingStudents === 0) {
                penaltyStudent.options[0].textContent = 'No students in this year level';
            }
        }

        penaltyDepartment.addEventListener('change', function () {
            const department = penaltyDepartment.value;
            const yearLevels = ['1st', '2nd', '3rd', '4th'];
            penaltyYearLevel.replaceChildren(new Option(
                department === '' ? 'Choose a department first' : 'Choose a year level',
                ''
            ));
            penaltyYearLevel.value = '';
            penaltyYearLevel.disabled = department === '';

            yearLevels.forEach(function (yearLevel) {
                penaltyYearLevel.add(new Option(yearLevel, yearLevel));
            });

            updatePenaltyStudentOptions();
        });
        penaltyYearLevel.addEventListener('change', updatePenaltyStudentOptions);
    }

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

    </script>
</body>
</html>
