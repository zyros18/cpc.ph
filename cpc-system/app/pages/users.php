<?php
require_roles('Admin');
$roles = db()->query('SELECT role_id, role_name FROM roles ORDER BY role_id')->fetchAll();
$roleDetails = db()->query('SELECT role_id, role_name, role_description FROM roles ORDER BY role_id')->fetchAll();
$users = db()->query(
    'SELECT u.user_id, u.role_id, u.student_id, u.full_name, u.email, u.phone_number, u.is_active, u.created_at, r.role_name
     FROM users u JOIN roles r ON r.role_id = u.role_id ORDER BY u.created_at DESC LIMIT 200'
)->fetchAll();
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$editUser = null;
foreach ($users as $record) {
    if ((int) $record['user_id'] === $editId) {
        $editUser = $record;
        break;
    }
}
?>
<header class="page-heading"><div><p class="eyebrow">Administration</p><h1>User management</h1><p class="muted">Create accounts, update roles, and deactivate access.</p></div></header>
<section class="panel">
    <h2><?= $editUser ? 'Edit user' : 'Create user' ?></h2>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $editUser ? 'update_user' : 'create_user' ?>"><input type="hidden" name="return_to" value="index.php?page=users">
        <?php if ($editUser): ?><input type="hidden" name="user_id" value="<?= (int) $editUser['user_id'] ?>"><?php endif; ?>
        <label>Full name<input name="full_name" maxlength="150" required value="<?= e($editUser['full_name'] ?? '') ?>"></label>
        <label>Email<input type="email" name="email" maxlength="254" required value="<?= e($editUser['email'] ?? '') ?>"></label>
        <label>Role<select name="role_id" required><?php foreach ($roles as $role): ?><option value="<?= (int) $role['role_id'] ?>" <?= (int) ($editUser['role_id'] ?? 0) === (int) $role['role_id'] ? 'selected' : '' ?>><?= e($role['role_name']) ?></option><?php endforeach; ?></select></label>
        <label>Student ID <span class="muted">(required for students)</span><input name="student_id" maxlength="50" value="<?= e($editUser['student_id'] ?? '') ?>"></label>
        <label>Phone number<input name="phone_number" maxlength="30" value="<?= e($editUser['phone_number'] ?? '') ?>"></label>
        <label><?= $editUser ? 'New password (optional)' : 'Initial password' ?><input type="password" name="password" minlength="10" <?= $editUser ? '' : 'required' ?> autocomplete="new-password"></label>
        <?php if ($editUser): ?><label class="checkbox-label"><input type="checkbox" name="is_active" value="1" <?= $editUser['is_active'] ? 'checked' : '' ?>> Account active</label><?php endif; ?>
        <div class="form-actions span-2"><button class="button primary" type="submit"><?= $editUser ? 'Save changes' : 'Create account' ?></button><?php if ($editUser): ?><a class="button secondary" href="<?= e(url('index.php?page=users')) ?>">Cancel</a><?php endif; ?></div>
    </form>
</section>
<section class="panel"><div class="panel-heading"><h2>Accounts</h2><span class="muted">Showing up to 200 recent accounts</span></div>
    <div class="table-scroll"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Student ID</th><th>Status</th><th>Created</th><th>Manage</th></tr></thead><tbody>
    <?php foreach ($users as $record): ?><tr><td><?= e($record['full_name']) ?></td><td><?= e($record['email']) ?></td><td><?= e($record['role_name']) ?></td><td><?= e($record['student_id'] ?? '—') ?></td><td><span class="badge <?= $record['is_active'] ? 'verified' : 'rejected' ?>"><?= $record['is_active'] ? 'Active' : 'Inactive' ?></span></td><td><?= e(date('M j, Y', strtotime($record['created_at']))) ?></td><td><a class="button small secondary" href="<?= e(url('index.php?page=users&edit=' . (int) $record['user_id'])) ?>">Edit</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<section class="panel">
    <h2>Role descriptions</h2>
    <p class="muted">The system roles are fixed because each one has a defined permission set. Update their descriptions here.</p>
    <div class="role-list">
        <?php foreach ($roleDetails as $role): ?><form method="post" class="role-row">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_role"><input type="hidden" name="role_id" value="<?= (int) $role['role_id'] ?>"><input type="hidden" name="return_to" value="index.php?page=users">
            <strong><?= e($role['role_name']) ?></strong><input name="role_description" maxlength="255" value="<?= e($role['role_description'] ?? '') ?>" aria-label="<?= e($role['role_name']) ?> role description"><button class="button small secondary" type="submit">Save</button>
        </form><?php endforeach; ?>
    </div>
</section>
