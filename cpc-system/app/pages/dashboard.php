<?php
if ($user['role_name'] === 'Student') {
    $studentQuery = db()->prepare(
        'SELECT penalty_id, penalty_title, amount, status, created_at FROM penalties
         WHERE student_user_id = :student_id ORDER BY created_at DESC LIMIT 8'
    );
    $studentQuery->execute(['student_id' => $user['user_id']]);
    $ownPenalties = $studentQuery->fetchAll();
    $studentStats = db()->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'paid'), 0) AS paid_count,
                COALESCE(SUM(status = 'unpaid'), 0) AS unpaid_count,
                COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END), 0) AS due
         FROM penalties WHERE student_user_id = :student_id"
    );
    $studentStats->execute(['student_id' => $user['user_id']]);
    $ownStats = $studentStats->fetch();
    ?>
    <header class="page-heading"><div><p class="eyebrow">Personal overview</p><h1>Dashboard</h1><p class="muted">Your CPC penalty and payment summary.</p></div></header>
    <section class="stats-grid" aria-label="Your penalty summary">
        <?php foreach ([
            ['My penalties', (string) (int) $ownStats['total'], 'blue'],
            ['Paid', (string) (int) $ownStats['paid_count'], 'green'],
            ['Unpaid', (string) (int) $ownStats['unpaid_count'], 'amber'],
            ['Amount due', '₱' . number_format((float) $ownStats['due'], 2), 'amber'],
        ] as [$label, $value, $color]): ?><article class="stat-card"><p><?= e($label) ?></p><strong class="stat-<?= e($color) ?>"><?= e($value) ?></strong></article><?php endforeach; ?>
    </section>
    <section class="panel">
        <div class="panel-heading"><h2>My recent penalties</h2><a class="text-link" href="<?= e(url('index.php?page=payments')) ?>">Go to payments</a></div>
        <div class="table-scroll"><table><thead><tr><th>Penalty</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
        <?php if (!$ownPenalties): ?><tr><td class="empty" colspan="4">You have no penalties.</td></tr><?php endif; ?>
        <?php foreach ($ownPenalties as $penalty): ?><tr><td><?= e($penalty['penalty_title']) ?></td><td>₱<?= e(number_format((float) $penalty['amount'], 2)) ?></td><td><span class="badge <?= e($penalty['status']) ?>"><?= e(ucfirst($penalty['status'])) ?></span></td><td><?= e(date('M j, Y', strtotime($penalty['created_at']))) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php
    return;
}

$studentTotal = (int) db()->query(
    "SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Student'"
)->fetchColumn();
$penaltyTotal = (int) db()->query('SELECT COUNT(*) FROM penalties')->fetchColumn();
$unpaidTotal = (int) db()->query("SELECT COUNT(*) FROM penalties WHERE status = 'unpaid'")->fetchColumn();
$pendingPayments = (int) db()->query("SELECT COUNT(*) FROM payments WHERE payment_status = 'pending'")->fetchColumn();
$verifiedPayments = (int) db()->query("SELECT COUNT(*) FROM payments WHERE payment_status = 'verified'")->fetchColumn();
$collected = (string) db()->query("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE payment_status = 'verified'")->fetchColumn();

$recent = db()->query(
    'SELECT p.payment_reference, p.amount_paid, p.payment_status, p.payment_date,
            u.full_name AS student_name, pe.penalty_title
     FROM payments p JOIN users u ON u.user_id = p.payer_user_id
     JOIN penalties pe ON pe.penalty_id = p.penalty_id
     ORDER BY p.created_at DESC LIMIT 8'
)->fetchAll();
$recentPenalties = db()->query(
    'SELECT pe.penalty_title, pe.amount, pe.status, pe.created_at, u.full_name
     FROM penalties pe JOIN users u ON u.user_id = pe.student_user_id
     ORDER BY pe.created_at DESC LIMIT 6'
)->fetchAll();
?>
<header class="page-heading"><div><p class="eyebrow"><?= e($user['role_name']) ?> overview</p><h1>Dashboard</h1><p class="muted">Current CPC penalties and payment activity.</p></div></header>
<section class="stats-grid" aria-label="System summary">
    <?php foreach ([
        ['Total students', number_format($studentTotal), 'blue'],
        ['Total penalties', number_format($penaltyTotal), 'navy'],
        ['Unpaid penalties', number_format($unpaidTotal), 'amber'],
        ['Pending payments', number_format($pendingPayments), 'amber'],
        ['Verified payments', number_format($verifiedPayments), 'green'],
        ['Total collected', '₱' . number_format((float) $collected, 2), 'green'],
    ] as [$label, $value, $color]): ?>
        <article class="stat-card"><p><?= e($label) ?></p><strong class="stat-<?= e($color) ?>"><?= e($value) ?></strong></article>
    <?php endforeach; ?>
</section>
<section class="panel">
    <div class="panel-heading"><h2>Recent transactions</h2><a class="text-link" href="<?= e(url('index.php?page=payments')) ?>">View payments</a></div>
    <div class="table-scroll"><table><thead><tr><th>Reference</th><th>Student</th><th>Penalty</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
    <?php if (!$recent): ?><tr><td class="empty" colspan="6">No transactions recorded.</td></tr><?php endif; ?>
    <?php foreach ($recent as $row): ?>
        <tr>
            <td><?= e($row['payment_reference']) ?></td><td><?= e($row['student_name']) ?></td>
            <td><?= e($row['penalty_title']) ?></td><td>₱<?= e(number_format((float) $row['amount_paid'], 2)) ?></td>
            <td><span class="badge <?= e($row['payment_status']) ?>"><?= e(ucfirst($row['payment_status'])) ?></span></td>
            <td><?= e(date('M j, Y g:i A', strtotime($row['payment_date']))) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<section class="panel">
    <div class="panel-heading"><h2>Recent penalties</h2><a class="text-link" href="<?= e(url('index.php?page=penalties')) ?>">View penalties</a></div>
    <div class="table-scroll"><table><thead><tr><th>Student</th><th>Penalty</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>
    <?php if (!$recentPenalties): ?><tr><td class="empty" colspan="5">No penalties recorded.</td></tr><?php endif; ?>
    <?php foreach ($recentPenalties as $row): ?>
        <tr><td><?= e($row['full_name']) ?></td><td><?= e($row['penalty_title']) ?></td><td>₱<?= e(number_format((float) $row['amount'], 2)) ?></td><td><span class="badge <?= e($row['status']) ?>"><?= e(ucfirst($row['status'])) ?></span></td><td><?= e(date('M j, Y', strtotime($row['created_at']))) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
