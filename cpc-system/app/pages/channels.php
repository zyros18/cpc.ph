<?php
require_roles('Admin', 'Officer');
$channels = db()->query(
    'SELECT channel_id, channel_name, channel_type, details, is_active, created_at
     FROM payment_channels ORDER BY channel_name'
)->fetchAll();
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$editChannel = null;
foreach ($channels as $channel) {
    if ((int) $channel['channel_id'] === $editId) {
        $editChannel = $channel;
        break;
    }
}
?>
<header class="page-heading"><div><p class="eyebrow">Configuration</p><h1>Payment channels</h1><p class="muted">Manage the channels students can select for payment requests.</p></div></header>
<section class="panel">
    <h2><?= $editChannel ? 'Edit channel' : 'Add a channel' ?></h2>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_channel"><input type="hidden" name="return_to" value="index.php?page=channels">
        <?php if ($editChannel): ?><input type="hidden" name="channel_id" value="<?= (int) $editChannel['channel_id'] ?>"><?php endif; ?>
        <label>Channel name<input name="channel_name" maxlength="100" required value="<?= e($editChannel['channel_name'] ?? '') ?>"></label>
        <label>Type<select name="channel_type" required><option value="online" <?= ($editChannel['channel_type'] ?? '') === 'online' ? 'selected' : '' ?>>Online</option><option value="in_person" <?= ($editChannel['channel_type'] ?? '') === 'in_person' ? 'selected' : '' ?>>In person</option></select></label>
        <label class="span-2">Instructions / details<textarea name="details" rows="2" maxlength="500"><?= e($editChannel['details'] ?? '') ?></textarea></label>
        <div class="form-actions span-2"><button class="button primary" type="submit"><?= $editChannel ? 'Save channel' : 'Add channel' ?></button><?php if ($editChannel): ?><a class="button secondary" href="<?= e(url('index.php?page=channels')) ?>">Cancel</a><?php endif; ?></div>
    </form>
</section>
<section class="panel"><h2>Available channels</h2><div class="table-scroll"><table><thead><tr><th>Name</th><th>Type</th><th>Details</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php if (!$channels): ?><tr><td class="empty" colspan="5">No channels configured.</td></tr><?php endif; ?>
<?php foreach ($channels as $channel): ?><tr>
    <td><?= e($channel['channel_name']) ?></td><td><?= e(ucfirst(str_replace('_', ' ', $channel['channel_type']))) ?></td><td><?= e($channel['details'] ?? '—') ?></td>
    <td><span class="badge <?= $channel['is_active'] ? 'verified' : 'rejected' ?>"><?= $channel['is_active'] ? 'Active' : 'Inactive' ?></span></td>
    <td><div class="action-group"><a class="button small secondary" href="<?= e(url('index.php?page=channels&edit=' . (int) $channel['channel_id'])) ?>">Edit</a><form method="post" data-confirm="Change the active status for this channel?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_channel"><input type="hidden" name="channel_id" value="<?= (int) $channel['channel_id'] ?>"><input type="hidden" name="return_to" value="index.php?page=channels"><button class="button small <?= $channel['is_active'] ? 'danger' : 'success' ?>" type="submit"><?= $channel['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></div></td>
</tr><?php endforeach; ?>
</tbody></table></div></section>
