<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_role('teacher');

if (empty($_SESSION['gcash_settings_csrf_token'])) {
	$_SESSION['gcash_settings_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['gcash_settings_csrf_token'];
$message = '';
$isError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$submittedToken = $_POST['csrf_token'] ?? '';
	$action = $_POST['action'] ?? '';

	try {
		if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
			throw new RuntimeException('Your form expired. Reload this page and try again.');
		}

		$db = database();
		if ($action === 'save') {
			$merchantName = trim(is_string($_POST['merchant_name'] ?? null) ? $_POST['merchant_name'] : '');
			$accountNumber = trim(is_string($_POST['gcash_account_number'] ?? null) ? $_POST['gcash_account_number'] : '');
			if ($merchantName === '' || strlen($merchantName) > 150 ||
				strlen($accountNumber) > 30 ||
				($accountNumber !== '' && !preg_match('/^[0-9+() -]+$/', $accountNumber))) {
				throw new RuntimeException('Enter a valid business display name and optional GCash account number.');
			}

			$existingQuery = $db->query(
				'SELECT qr_image, qr_mime_type FROM gcash_merchant_settings WHERE settings_id = 1'
			);
			$existing = $existingQuery->fetch() ?: null;
			$image = $existing['qr_image'] ?? null;
			$mimeType = $existing['qr_mime_type'] ?? null;

			$file = $_FILES['merchant_qr'] ?? null;
			if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
				if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
					!isset($file['tmp_name'], $file['size']) ||
					!is_uploaded_file($file['tmp_name']) ||
					$file['size'] < 1 || $file['size'] > 5 * 1024 * 1024) {
					throw new RuntimeException('Upload a valid GCash Business QR image smaller than 5 MB.');
				}

				$imageInfo = @getimagesize($file['tmp_name']);
				$allowedMimeTypes = ['image/jpeg', 'image/png'];
				if (!$imageInfo || !in_array($imageInfo['mime'] ?? '', $allowedMimeTypes, true) ||
					$imageInfo[0] > 6000 || $imageInfo[1] > 6000) {
					throw new RuntimeException('The QR must be a valid JPEG or PNG image no larger than 6000 × 6000 pixels.');
				}
				$image = file_get_contents($file['tmp_name']);
				$mimeType = $imageInfo['mime'];
				if (!is_string($image) || $image === '') {
					throw new RuntimeException('Could not read the QR image. Please choose it again.');
				}
			}

			if (!is_string($image) || $image === '' || !is_string($mimeType)) {
				throw new RuntimeException('Upload your official GCash Business QR image before saving.');
			}

			$statement = $db->prepare(
				'INSERT INTO gcash_merchant_settings
					(settings_id, merchant_name, gcash_account_number, qr_image, qr_mime_type, updated_by_user_id)
				 VALUES (1, :merchant_name, :account_number, :qr_image, :mime_type, :updated_by)
				 ON DUPLICATE KEY UPDATE merchant_name = VALUES(merchant_name),
					gcash_account_number = VALUES(gcash_account_number),
					qr_image = VALUES(qr_image), qr_mime_type = VALUES(qr_mime_type),
					updated_by_user_id = VALUES(updated_by_user_id)'
			);
			$statement->bindValue(':merchant_name', $merchantName);
			$statement->bindValue(':account_number', $accountNumber === '' ? null : $accountNumber);
			$statement->bindValue(':qr_image', $image, PDO::PARAM_LOB);
			$statement->bindValue(':mime_type', $mimeType);
			$statement->bindValue(':updated_by', (int) $_SESSION['user']['id'], PDO::PARAM_INT);
			$statement->execute();
			$message = 'GCash Business QR settings saved.';
		} elseif ($action === 'remove') {
			$delete = $db->prepare('DELETE FROM gcash_merchant_settings WHERE settings_id = 1');
			$delete->execute();
			$message = 'GCash Business QR removed.';
		} else {
			throw new RuntimeException('Choose a valid settings action.');
		}
	} catch (RuntimeException $exception) {
		$message = $exception->getMessage();
		$isError = true;
	} catch (Throwable $exception) {
		error_log('GCash merchant QR settings failed: ' . $exception->getMessage());
		$message = 'Could not save GCash Business QR settings. Please try again.';
		$isError = true;
	}

	$_SESSION['gcash_settings_csrf_token'] = bin2hex(random_bytes(32));
	$_SESSION['gcash_settings_flash'] = ['message' => $message, 'error' => $isError];
	header('Location: gcash_settings.php', true, 303);
	exit;
}

$statement = database()->query(
	'SELECT merchant_name, gcash_account_number, qr_image, qr_mime_type, updated_at
	 FROM gcash_merchant_settings WHERE settings_id = 1'
);
$settings = $statement->fetch() ?: null;
$flash = $_SESSION['gcash_settings_flash'] ?? null;
unset($_SESSION['gcash_settings_flash']);
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>GCash Business QR Setup | CPC Penalty System</title>
	<style>
		:root { font-family: Inter, "Segoe UI", Arial, sans-serif; color: #0f172a; background: #f1f5f9; }
		* { box-sizing: border-box; }
		body { min-height: 100vh; margin: 0; padding: 30px 15px; background: #f1f5f9; }
		main { width: min(100%, 700px); margin: 0 auto; }
		.card { padding: clamp(21px, 5vw, 34px); border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 10px 28px rgba(15, 23, 42, .06); }
		h1 { margin: 0; font-size: clamp(24px, 5vw, 30px); }
		.intro { margin: 9px 0 22px; color: #64748b; font-size: 14px; line-height: 1.6; }
		.alert { padding: 12px 14px; margin-bottom: 18px; border: 1px solid #bbf7d0; border-radius: 9px; color: #166534; background: #f0fdf4; font-size: 14px; }
		.alert.error { border-color: #fecaca; color: #b91c1c; background: #fef2f2; }
		.notice { padding: 14px 16px; margin: 18px 0; border-radius: 9px; color: #1e3a8a; background: #eff6ff; font-size: 13px; line-height: 1.6; }
		label { display: grid; gap: 7px; margin: 17px 0; color: #334155; font-size: 13px; font-weight: 650; }
		input { width: 100%; min-height: 44px; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; font-size: 14px; }
		input[type="file"] { height: auto; background: #fff; }
		input:focus { outline: none; border-color: #60a5fa; box-shadow: 0 0 0 3px rgba(96, 165, 250, .2); }
		.preview { display: block; width: min(100%, 300px); max-height: 340px; object-fit: contain; padding: 10px; margin: 14px auto; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
		.actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
		.button { display: inline-flex; min-height: 43px; align-items: center; justify-content: center; padding: 0 16px; border: 1px solid #2563eb; border-radius: 8px; color: #fff; background: #2563eb; font: inherit; font-size: 13px; font-weight: 650; text-decoration: none; cursor: pointer; }
		.button.secondary { border-color: #cbd5e1; color: #0f172a; background: #fff; }
		.button.danger { border-color: #fecaca; color: #b91c1c; background: #fff; }
		@media (max-width: 480px) { body { padding: 18px 11px; } .actions .button { width: 100%; } }
	</style>
</head>
<body>
	<main>
		<section class="card" aria-labelledby="page-title">
			<h1 id="page-title">GCash Business QR Setup</h1>
			<p class="intro">Add the official merchant QR students will scan for CPC penalty payments. The image is stored in the CPC database and shown only on the authenticated student payment page.</p>
			<?php if (is_array($flash)): ?>
				<div class="alert <?= !empty($flash['error']) ? 'error' : '' ?>" role="<?= !empty($flash['error']) ? 'alert' : 'status' ?>"><?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
			<?php endif; ?>
			<?php if ($settings): ?>
				<p><strong>Current merchant:</strong> <?= htmlspecialchars($settings['merchant_name'], ENT_QUOTES, 'UTF-8') ?>
					<?php if (!empty($settings['gcash_account_number'])): ?> · <?= htmlspecialchars($settings['gcash_account_number'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
				</p>
				<img class="preview" src="data:<?= htmlspecialchars($settings['qr_mime_type'], ENT_QUOTES, 'UTF-8') ?>;base64,<?= base64_encode($settings['qr_image']) ?>" alt="Current GCash Business QR">
			<?php else: ?>
				<div class="notice">No merchant QR is configured yet. Upload the official GCash Business QR provided by your GCash merchant account. Do not upload a personal wallet QR unless it is authorized for CPC collections.</div>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data">
				<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
				<input type="hidden" name="action" value="save">
				<label for="merchantName">Business display name
					<input id="merchantName" name="merchant_name" maxlength="150" value="<?= htmlspecialchars($settings['merchant_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="organization" required>
				</label>
				<label for="gcashAccountNumber">GCash Business account number (optional)
					<input id="gcashAccountNumber" name="gcash_account_number" maxlength="30" value="<?= htmlspecialchars($settings['gcash_account_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>" inputmode="tel" autocomplete="off">
				</label>
				<label for="merchantQr">Official GCash Business QR image (JPEG or PNG, max 5 MB)
					<input id="merchantQr" name="merchant_qr" type="file" accept="image/jpeg,image/png,.jpg,.jpeg,.png" <?= $settings ? '' : 'required' ?>>
				</label>
				<div class="actions">
					<button class="button" type="submit">Save GCash Business QR</button>
					<a class="button secondary" href="teachers_dashboard.php#penalties">Back to Teacher Dashboard</a>
				</div>
			</form>

			<?php if ($settings): ?>
				<form method="post" class="actions" onsubmit="return confirm('Remove the CPC GCash Business QR? Students will no longer be able to submit GCash requests.');">
					<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
					<input type="hidden" name="action" value="remove">
					<button class="button danger" type="submit">Remove QR</button>
				</form>
			<?php endif; ?>
			<p class="notice">Only upload a QR officially issued for CPC collections. Never upload or request an MPIN, password, or one-time code. Student-submitted GCash references still require staff verification.</p>
		</section>
	</main>
</body>
</html>
