<?php
require_roles('Admin', 'Officer');
$search = query_string('q');
$where = "r.role_name = 'Student'";
$params = [];
if ($search !== '') {
    $where .= ' AND (u.full_name LIKE :search OR u.email LIKE :search OR u.student_id LIKE :search)';
    $params['search'] = '%' . $search . '%';
}
$count = db()->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE {$where}");
$count->execute($params);
$pager = pagination((int) $count->fetchColumn());
$query = db()->prepare(
    "SELECT u.user_id, u.student_id, u.full_name, u.email, u.phone_number, u.is_active, u.created_at
     FROM users u JOIN roles r ON r.role_id = u.role_id
     WHERE {$where} ORDER BY u.full_name LIMIT {$pager['limit']} OFFSET {$pager['offset']}"
);
$query->execute($params);
$students = $query->fetchAll();
?>
<header class="page-heading"><div><p class="eyebrow">Records</p><h1>Students</h1><p class="muted">Search student accounts and review their profile details.</p></div></header>
<section class="panel">
    <form class="toolbar" method="get"><input type="hidden" name="page" value="students"><label class="search-field">Search<input name="q" value="<?= e($search) ?>" placeholder="Name, email, or student ID"></label><button class="button primary" type="submit">Search</button></form>
    <div class="table-scroll"><table><thead><tr><th>Student ID</th><th>Full name</th><th>Email</th><th>Phone</th><th>Status</th><th>Created</th></tr></thead><tbody>
    <?php if (!$students): ?><tr><td class="empty" colspan="6">No students found.</td></tr><?php endif; ?>
    <?php foreach ($students as $student): ?>
        <tr><td><?= e($student['student_id'] ?? '—') ?></td><td><?= e($student['full_name']) ?></td><td><?= e($student['email']) ?></td><td><?= e($student['phone_number'] ?? '—') ?></td><td><span class="badge <?= $student['is_active'] ? 'verified' : 'rejected' ?>"><?= $student['is_active'] ? 'Active' : 'Inactive' ?></span></td><td><?= e(date('M j, Y', strtotime($student['created_at']))) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php require __DIR__ . '/../views/pagination.php'; ?>
</section>
