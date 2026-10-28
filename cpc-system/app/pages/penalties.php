<?php
require_roles('Admin', 'Officer');
$students = db()->query(
    "SELECT u.user_id, u.student_id, u.full_name FROM users u JOIN roles r ON r.role_id = u.role_id
     WHERE r.role_name = 'Student' AND u.is_active = 1 ORDER BY u.full_name"
)->fetchAll();
$search = query_string('q');
$status = query_string('status');
$where = '1 = 1';
$params = [];
if ($search !== '') {
    $where .= ' AND (s.full_name LIKE :name_search OR s.student_id LIKE :id_search OR p.penalty_title LIKE :title_search)';
    $params['name_search'] = '%' . $search . '%';
    $params['id_search'] = '%' . $search . '%';
    $params['title_search'] = '%' . $search . '%';
}
if (in_array($status, ['unpaid', 'paid'], true)) {
    $where .= ' AND p.status = :status';
    $params['status'] = $status;
}
$count = db()->prepare("SELECT COUNT(*) FROM penalties p JOIN users s ON s.user_id = p.student_user_id WHERE {$where}");
$count->execute($params);
$pager = pagination((int) $count->fetchColumn());
$query = db()->prepare(
    "SELECT p.penalty_id, p.student_user_id, p.penalty_title, p.penalty_description, p.amount, p.status, p.created_at,
            EXISTS (SELECT 1 FROM payments px WHERE px.penalty_id = p.penalty_id AND px.payment_status IN ('pending','verified')) AS payment_locked,
            s.full_name, s.student_id, u.full_name AS recorded_by
     FROM penalties p JOIN users s ON s.user_id = p.student_user_id
     JOIN users u ON u.user_id = p.recorded_by_user_id
     WHERE {$where} ORDER BY p.created_at DESC LIMIT {$pager['limit']} OFFSET {$pager['offset']}"
);
$query->execute($params);
$penalties = $query->fetchAll();
?>
<header class="page-heading"><div><p class="eyebrow">Management</p><h1>Penalties</h1><p class="muted">Issue and manage student penalty records.</p></div></header>
<section class="panel">
    <h2>Record a penalty</h2>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_penalty"><input type="hidden" name="return_to" value="index.php?page=penalties">
        <label>Student<select name="student_user_id" required><option value="">Select student</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['user_id'] ?>"><?= e($student['full_name'] . ' (' . ($student['student_id'] ?: 'no ID') . ')') ?></option><?php endforeach; ?></select></label>
        <label>Title<input name="penalty_title" maxlength="150" required></label>
        <label>Amount (₱)<input type="number" name="amount" min="0.01" max="99999999.99" step="0.01" required></label>
        <label class="span-2">Description<textarea name="penalty_description" rows="3" maxlength="5000"></textarea></label>
        <div class="form-actions span-2"><button class="button primary" type="submit">Create penalty</button></div>
    </form>
</section>
<section class="panel">
    <div class="panel-heading"><h2>Penalty records</h2>
        <form class="toolbar compact" method="get"><input type="hidden" name="page" value="penalties"><input name="q" value="<?= e($search) ?>" placeholder="Search"><select name="status"><option value="">All statuses</option><option value="unpaid" <?= $status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option><option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option></select><button class="button secondary" type="submit">Filter</button></form>
    </div>
    <div class="table-scroll"><table><thead><tr><th>Student</th><th>Penalty</th><th>Amount</th><th>Status</th><th>Recorded by</th><th>Created</th><th>Edit</th></tr></thead><tbody>
    <?php if (!$penalties): ?><tr><td class="empty" colspan="7">No penalties match this search.</td></tr><?php endif; ?>
    <?php foreach ($penalties as $penalty): ?>
        <tr>
            <td><?= e($penalty['full_name'] . ' (' . ($penalty['student_id'] ?: '—') . ')') ?></td>
            <td><?= e($penalty['penalty_title']) ?><small class="subtext"><?= e($penalty['penalty_description'] ?? '') ?></small></td>
            <td>₱<?= e(number_format((float) $penalty['amount'], 2)) ?></td>
            <td><span class="badge <?= e($penalty['status']) ?>"><?= e(ucfirst($penalty['status'])) ?></span></td>
            <td><?= e($penalty['recorded_by']) ?></td><td><?= e(date('M j, Y', strtotime($penalty['created_at']))) ?></td>
            <td>
                <?php if ($penalty['status'] === 'unpaid' && !(bool) $penalty['payment_locked']): ?><details class="inline-edit"><summary>Edit</summary><form method="post" class="stack-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_penalty"><input type="hidden" name="return_to" value="index.php?page=penalties"><input type="hidden" name="penalty_id" value="<?= (int) $penalty['penalty_id'] ?>">
                    <label>Title<input name="penalty_title" maxlength="150" value="<?= e($penalty['penalty_title']) ?>" required></label><label>Amount<input type="number" name="amount" min="0.01" step="0.01" value="<?= e($penalty['amount']) ?>" required></label><label>Description<textarea name="penalty_description" rows="2"><?= e($penalty['penalty_description'] ?? '') ?></textarea></label><button class="button primary" type="submit">Save</button>
                </form></details><?php else: ?><span class="muted">Locked</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php require __DIR__ . '/../views/pagination.php'; ?>
</section>
