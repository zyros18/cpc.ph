<?php
require_roles('Admin', 'Officer');

function report_filters(): array
{
    $filters = [
        'student' => query_string('student'),
        'from' => query_string('from'),
        'to' => query_string('to'),
        'status' => query_string('status'),
        'channel' => query_string('channel'),
    ];
    foreach (['from', 'to'] as $field) {
        if ($filters[$field] !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$field]);
            $errors = DateTimeImmutable::getLastErrors();
            if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                $filters[$field] = '';
            }
        }
    }
    if (!in_array($filters['status'], ['', 'pending', 'verified', 'rejected'], true)) {
        $filters['status'] = '';
    }
    if ($filters['channel'] !== '' && !ctype_digit($filters['channel'])) {
        $filters['channel'] = '';
    }
    return $filters;
}

function report_query(array $filters, bool $countOnly = false): array
{
    $where = ['1 = 1'];
    $params = [];
    if ($filters['student'] !== '') {
        $where[] = '(s.full_name LIKE :student_name OR s.student_id LIKE :student_id)';
        $params['student_name'] = '%' . $filters['student'] . '%';
        $params['student_id'] = '%' . $filters['student'] . '%';
    }
    if ($filters['from'] !== '') {
        $where[] = 'p.payment_date >= :date_from';
        $params['date_from'] = $filters['from'] . ' 00:00:00';
    }
    if ($filters['to'] !== '') {
        $where[] = 'p.payment_date < :date_to';
        $params['date_to'] = (new DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
    }
    if ($filters['status'] !== '') {
        $where[] = 'p.payment_status = :status';
        $params['status'] = $filters['status'];
    }
    if ($filters['channel'] !== '') {
        $where[] = 'ch.channel_id = :channel_id';
        $params['channel_id'] = (int) $filters['channel'];
    }
    $select = $countOnly
        ? 'COUNT(*)'
        : 'p.payment_reference, p.amount_paid, p.payment_status, p.payment_date, p.verified_at,
           s.full_name AS student_name, s.student_id, pe.penalty_title, ch.channel_name';
    $sql = "SELECT {$select} FROM payments p JOIN penalties pe ON pe.penalty_id = p.penalty_id
            JOIN users s ON s.user_id = pe.student_user_id
            LEFT JOIN payment_channel_assignments a ON a.payment_id = p.payment_id
            LEFT JOIN payment_channels ch ON ch.channel_id = a.channel_id
            WHERE " . implode(' AND ', $where);
    if (!$countOnly) {
        $sql .= ' ORDER BY p.payment_date DESC';
    }
    return [$sql, $params];
}

function penalty_report_filters(): array
{
    $filters = [
        'student' => query_string('penalty_student'),
        'from' => query_string('penalty_from'),
        'to' => query_string('penalty_to'),
        'status' => query_string('penalty_status'),
    ];
    foreach (['from', 'to'] as $field) {
        if ($filters[$field] !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$field]);
            $errors = DateTimeImmutable::getLastErrors();
            if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                $filters[$field] = '';
            }
        }
    }
    if (!in_array($filters['status'], ['', 'unpaid', 'paid'], true)) {
        $filters['status'] = '';
    }
    return $filters;
}

function penalty_report_query(array $filters, bool $countOnly = false): array
{
    $where = [];
    $params = [];
    if ($filters['student'] !== '') {
        $where[] = '(s.full_name LIKE :student_name OR s.student_id LIKE :student_id)';
        $params['student_name'] = '%' . $filters['student'] . '%';
        $params['student_id'] = '%' . $filters['student'] . '%';
    }
    if ($filters['from'] !== '') {
        $where[] = 'p.created_at >= :date_from';
        $params['date_from'] = $filters['from'] . ' 00:00:00';
    }
    if ($filters['to'] !== '') {
        $where[] = 'p.created_at < :date_to';
        $params['date_to'] = (new DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
    }
    if ($filters['status'] !== '') {
        $where[] = 'p.status = :status';
        $params['status'] = $filters['status'];
    }
    $select = $countOnly
        ? 'COUNT(*)'
        : 'p.penalty_id, p.penalty_title, p.penalty_description, p.amount, p.status, p.created_at,
           s.full_name AS student_name, s.student_id, officer.full_name AS recorded_by';
    $sql = "SELECT {$select} FROM penalties p
            JOIN users s ON s.user_id = p.student_user_id
            JOIN users officer ON officer.user_id = p.recorded_by_user_id";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    if (!$countOnly) {
        $sql .= ' ORDER BY p.created_at DESC';
    }
    return [$sql, $params];
}

function export_report_csv(): never
{
    $filters = report_filters();
    [$sql, $params] = report_query($filters);
    $statement = db()->prepare($sql);
    $statement->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cpc-payment-report-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to create CSV export.');
    }
    fputcsv($output, ['Payment reference', 'Student', 'Student ID', 'Penalty', 'Amount paid', 'Status', 'Channel', 'Payment date', 'Verified at']);
    while ($row = $statement->fetch()) {
        $values = [
            $row['payment_reference'], $row['student_name'], $row['student_id'], $row['penalty_title'],
            $row['amount_paid'], $row['payment_status'], $row['channel_name'], $row['payment_date'], $row['verified_at'],
        ];
        foreach ($values as &$value) {
            if (is_string($value) && preg_match('/^[\s]*[=+\-@]/', $value)) {
                $value = "'" . $value;
            }
        }
        unset($value);
        fputcsv($output, $values);
    }
    fclose($output);
    exit;
}

function export_penalty_report_csv(): never
{
    $filters = penalty_report_filters();
    [$sql, $params] = penalty_report_query($filters);
    $statement = db()->prepare($sql);
    $statement->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cpc-penalty-report-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to create CSV export.');
    }
    fputcsv($output, ['Penalty ID', 'Student', 'Student ID', 'Penalty', 'Description', 'Amount', 'Status', 'Recorded by', 'Created at']);
    while ($row = $statement->fetch()) {
        $values = [
            $row['penalty_id'], $row['student_name'], $row['student_id'], $row['penalty_title'],
            $row['penalty_description'], $row['amount'], $row['status'], $row['recorded_by'], $row['created_at'],
        ];
        foreach ($values as &$value) {
            if (is_string($value) && preg_match('/^[\s]*[=+\-@]/', $value)) {
                $value = "'" . $value;
            }
        }
        unset($value);
        fputcsv($output, $values);
    }
    fclose($output);
    exit;
}

$filters = report_filters();
[$reportSql, $reportParams] = report_query($filters);
$countStatement = db()->prepare(report_query($filters, true)[0]);
$countStatement->execute($reportParams);
$pager = pagination((int) $countStatement->fetchColumn());
$reportStatement = db()->prepare($reportSql . " LIMIT {$pager['limit']} OFFSET {$pager['offset']}");
$reportStatement->execute($reportParams);
$reportRows = $reportStatement->fetchAll();
$penaltyFilters = penalty_report_filters();
[$penaltySql, $penaltyParams] = penalty_report_query($penaltyFilters);
$penaltyCount = db()->prepare(penalty_report_query($penaltyFilters, true)[0]);
$penaltyCount->execute($penaltyParams);
$penaltyPager = pagination((int) $penaltyCount->fetchColumn());
$penaltyStatement = db()->prepare($penaltySql . " LIMIT {$penaltyPager['limit']} OFFSET {$penaltyPager['offset']}");
$penaltyStatement->execute($penaltyParams);
$penaltyRows = $penaltyStatement->fetchAll();
$channels = db()->query('SELECT channel_id, channel_name FROM payment_channels ORDER BY channel_name')->fetchAll();
$totalVerified = db()->prepare(
    "SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p
     JOIN penalties pe ON pe.penalty_id = p.penalty_id
     JOIN users s ON s.user_id = pe.student_user_id
     LEFT JOIN payment_channel_assignments a ON a.payment_id = p.payment_id
     LEFT JOIN payment_channels ch ON ch.channel_id = a.channel_id
     WHERE p.payment_status = 'verified'"
);
$totalVerified->execute($reportParams);
$filteredCollected = (float) $totalVerified->fetchColumn();
?>
<header class="page-heading"><div><p class="eyebrow">Analytics</p><h1>Reports</h1><p class="muted">Filter penalty payments and export matching transactions.</p></div></header>
<section class="panel">
    <form method="get" class="filter-grid">
        <input type="hidden" name="page" value="reports">
        <label>Student<input name="student" value="<?= e($filters['student']) ?>" placeholder="Name or student ID"></label>
        <label>From<input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
        <label>To<input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
        <label>Status<select name="status"><option value="">All</option><?php foreach (['pending', 'verified', 'rejected'] as $status): ?><option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select></label>
        <label>Channel<select name="channel"><option value="">All channels</option><?php foreach ($channels as $channel): ?><option value="<?= (int) $channel['channel_id'] ?>" <?= $filters['channel'] === (string) $channel['channel_id'] ? 'selected' : '' ?>><?= e($channel['channel_name']) ?></option><?php endforeach; ?></select></label>
        <div class="filter-actions"><button class="button primary" type="submit">Apply filters</button><a class="button secondary" href="<?= e(url('index.php?page=reports')) ?>">Clear</a></div>
    </form>
    <div class="report-total">Verified collected for these filters: <strong>₱<?= e(number_format($filteredCollected, 2)) ?></strong></div>
    <div class="panel-heading"><h2>Payment history</h2><a class="button secondary" href="<?= e(url('index.php?' . http_build_query(array_merge(['page' => 'reports', 'export' => 'csv'], $filters)))) ?>">Export CSV</a></div>
    <div class="table-scroll"><table><thead><tr><th>Reference</th><th>Student</th><th>Student ID</th><th>Penalty</th><th>Amount</th><th>Status</th><th>Channel</th><th>Date</th></tr></thead><tbody>
    <?php if (!$reportRows): ?><tr><td class="empty" colspan="8">No payments match these filters.</td></tr><?php endif; ?>
    <?php foreach ($reportRows as $row): ?><tr><td><?= e($row['payment_reference']) ?></td><td><?= e($row['student_name']) ?></td><td><?= e($row['student_id'] ?? '—') ?></td><td><?= e($row['penalty_title']) ?></td><td>₱<?= e(number_format((float) $row['amount_paid'], 2)) ?></td><td><span class="badge <?= e($row['payment_status']) ?>"><?= e(ucfirst($row['payment_status'])) ?></span></td><td><?= e($row['channel_name'] ?? '—') ?></td><td><?= e(date('M j, Y g:i A', strtotime($row['payment_date']))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php require __DIR__ . '/../views/pagination.php'; ?>
</section>
<section class="panel">
    <div class="panel-heading"><h2>Penalty report</h2>
        <a class="button secondary" href="<?= e(url('index.php?' . http_build_query(array_merge(['page' => 'reports', 'export' => 'csv', 'kind' => 'penalties'], [
            'penalty_student' => $penaltyFilters['student'],
            'penalty_from' => $penaltyFilters['from'],
            'penalty_to' => $penaltyFilters['to'],
            'penalty_status' => $penaltyFilters['status'],
        ])))) ?>">Export penalties CSV</a>
    </div>
    <form method="get" class="filter-grid">
        <input type="hidden" name="page" value="reports">
        <label>Student<input name="penalty_student" value="<?= e($penaltyFilters['student']) ?>" placeholder="Name or student ID"></label>
        <label>From<input type="date" name="penalty_from" value="<?= e($penaltyFilters['from']) ?>"></label>
        <label>To<input type="date" name="penalty_to" value="<?= e($penaltyFilters['to']) ?>"></label>
        <label>Status<select name="penalty_status"><option value="">All</option><option value="unpaid" <?= $penaltyFilters['status'] === 'unpaid' ? 'selected' : '' ?>>Unpaid</option><option value="paid" <?= $penaltyFilters['status'] === 'paid' ? 'selected' : '' ?>>Paid</option></select></label>
        <div class="filter-actions"><button class="button primary" type="submit">Filter penalties</button></div>
    </form>
    <div class="table-scroll"><table><thead><tr><th>Student</th><th>Student ID</th><th>Penalty</th><th>Amount</th><th>Status</th><th>Recorded by</th><th>Created</th></tr></thead><tbody>
    <?php if (!$penaltyRows): ?><tr><td class="empty" colspan="7">No penalties match these filters.</td></tr><?php endif; ?>
    <?php foreach ($penaltyRows as $row): ?><tr><td><?= e($row['student_name']) ?></td><td><?= e($row['student_id'] ?? '—') ?></td><td><?= e($row['penalty_title']) ?></td><td>₱<?= e(number_format((float) $row['amount'], 2)) ?></td><td><span class="badge <?= e($row['status']) ?>"><?= e(ucfirst($row['status'])) ?></span></td><td><?= e($row['recorded_by']) ?></td><td><?= e(date('M j, Y', strtotime($row['created_at']))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php $pager = $penaltyPager; require __DIR__ . '/../views/pagination.php'; ?>
</section>
