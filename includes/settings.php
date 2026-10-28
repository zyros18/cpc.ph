<?php
require_once __DIR__ . '/auth.php';
require_login();

$user = $_SESSION['user'];

if (empty($_SESSION['settings_csrf_token'])) {
	$_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['settings_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	try {
		$submittedToken = $_POST['csrf_token'] ?? '';
		if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
			throw new RuntimeException('Your form session expired. Reload this page and try again.');
		}

		$action = $_POST['action'] ?? '';
		if ($action === 'update_profile') {
			$usernameInput = $_POST['username'] ?? '';
			$username = is_string($usernameInput) ? trim($usernameInput) : '';
			$fullName = trim($_POST['full_name'] ?? '');
			$cellphone = trim($_POST['cellphone'] ?? '');
			$role = $user['role'] ?? '';
			$uploadedImage = null;

			if ($username === '' || strlen($username) > 100 ||
				$fullName === '' || strlen($fullName) > 150 ||
				$cellphone === '' || strlen($cellphone) > 30) {
				throw new RuntimeException('Enter a username, full name, and cellphone number within the allowed lengths.');
			}
			$usernameCheck = database()->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
			$usernameCheck->execute(['username' => $username, 'id' => (int) $user['id']]);
			if ($usernameCheck->fetchColumn()) {
				throw new RuntimeException('That username is already in use. Choose another one.');
			}

			if ($role === 'student') {
				$yearLevelInput = $_POST['year_level'] ?? '';
				$yearLevel = is_string($yearLevelInput) ? $yearLevelInput : '';
				$department = (string) ($user['department'] ?? '');
				if (!in_array($yearLevel, student_year_levels($department), true)) {
					throw new RuntimeException('Choose a valid year or grade level for your department.');
				}
			} else {
				$yearLevel = null;
			}

			if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
				$file = $_FILES['profile_picture'];
				if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024 ||
					!is_uploaded_file($file['tmp_name'])) {
					throw new RuntimeException('Choose an image smaller than 5 MB.');
				}

				$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
				$allowedMimeTypes = [
					'jpg' => 'image/jpeg',
					'jpeg' => 'image/jpeg',
					'png' => 'image/png',
					'gif' => 'image/gif',
					'webp' => 'image/webp',
				];
				if (!isset($allowedMimeTypes[$extension])) {
					throw new RuntimeException('Upload a JPEG, PNG, GIF, or WebP image.');
				}

				$imageInfo = @getimagesize($file['tmp_name']);
				if (!$imageInfo || ($imageInfo['mime'] ?? '') !== $allowedMimeTypes[$extension] ||
					$imageInfo[0] > 6000 || $imageInfo[1] > 6000) {
					throw new RuntimeException('Upload a valid JPEG, PNG, GIF, or WebP image no larger than 6000 × 6000 pixels.');
				}

				$uploadedImage = file_get_contents($file['tmp_name']);
				$imageInfo = $uploadedImage === false ? false : @getimagesizefromstring($uploadedImage);
				if (!$uploadedImage || !$imageInfo || ($imageInfo['mime'] ?? '') !== $allowedMimeTypes[$extension]) {
					throw new RuntimeException('The selected image could not be read. Choose another file.');
				}
			}

			$sql = 'UPDATE users SET username = :username, full_name = :full_name,
				cellphone = :cellphone, year_level = :year_level';
			if ($uploadedImage !== null) {
				$sql .= ', profile_picture = :picture';
			}
			$sql .= ' WHERE id = :id';
			$statement = database()->prepare($sql);
			$statement->bindValue(':username', $username);
			$statement->bindValue(':full_name', $fullName);
			$statement->bindValue(':cellphone', $cellphone);
			$statement->bindValue(':year_level', $yearLevel);
			$statement->bindValue(':id', (int) $user['id'], PDO::PARAM_INT);
			if ($uploadedImage !== null) {
				$statement->bindValue(':picture', $uploadedImage, PDO::PARAM_LOB);
			}
			$statement->execute();

			$_SESSION['user']['username'] = $username;
			$_SESSION['user']['full_name'] = $fullName;
			$_SESSION['user']['cellphone'] = $cellphone;
			$_SESSION['user']['year_level'] = $yearLevel;
			if ($uploadedImage !== null) {
				$_SESSION['user']['profile_picture'] = $uploadedImage;
			}
			$_SESSION['settings_success'] = $uploadedImage !== null
				? 'Profile details and photo saved.'
				: 'Profile details saved.';
		} elseif ($action === 'remove_profile_picture') {
			$statement = database()->prepare('UPDATE users SET profile_picture = NULL WHERE id = :id');
			$statement->execute(['id' => (int) $user['id']]);
			$_SESSION['user']['profile_picture'] = null;
			$_SESSION['settings_success'] = 'Profile photo removed.';
		} else {
			throw new RuntimeException('Choose a valid profile action.');
		}
	} catch (RuntimeException $exception) {
		$_SESSION['settings_error'] = $exception->getMessage();
	} catch (PDOException $exception) {
		$_SESSION['settings_error'] = $exception->getCode() === '23000'
			? 'That username is already in use. Choose another one.'
			: 'The profile could not be saved. Please try again.';
	} catch (Throwable $exception) {
		error_log('Profile settings update failed: ' . $exception->getMessage());
		$_SESSION['settings_error'] = 'The requested settings could not be saved.';
	}

	$_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
	header('Location: settings.php');
	exit;
}

$user = $_SESSION['user'];
$error = $_SESSION['settings_error'] ?? '';
$success = $_SESSION['settings_success'] ?? '';
unset($_SESSION['settings_error'], $_SESSION['settings_success']);
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Settings &amp; Profile | CPC Penalty System</title>
	<style>
		:root { font-family: Inter, "Segoe UI", Arial, sans-serif; color: #0f172a; background: #f1f5f9; font-synthesis: none; }
		* { box-sizing: border-box; }
		body { min-height: 100vh; margin: 0; background: #f1f5f9; }
		.sidebar { position: fixed; inset: 0 auto 0 0; z-index: 20; display: flex; width: 264px; flex-direction: column; padding: 26px 18px 20px; background: #fff; border-right: 1px solid #e2e8f0; }
		.brand { display: flex; align-items: center; gap: 12px; margin: 0 8px 36px; color: #0f172a; font-size: 15px; font-weight: 750; line-height: 1.3; }
		.brand-mark { display: block; width: 40px; height: 40px; flex: 0 0 40px; object-fit: contain; border-radius: 12px; }
		.side-nav { display: flex; flex: 1; flex-direction: column; gap: 6px; }
		.side-nav a { position: relative; display: flex; min-height: 46px; align-items: center; gap: 12px; padding: 0 13px; border-radius: 9px; color: #64748b; text-decoration: none; font-size: 14px; font-weight: 600; transition: background .15s ease, color .15s ease; }
		.side-nav a svg { width: 19px; height: 19px; flex: 0 0 19px; }
		.side-nav a:hover { color: #1d4ed8; background: #eff6ff; }
		.side-nav a.active { color: #fff; background: #2563eb; box-shadow: 0 5px 12px rgba(37, 99, 235, .18); }
		.side-nav a.active::before { position: absolute; left: -18px; width: 4px; height: 24px; border-radius: 0 4px 4px 0; background: #2563eb; content: ""; }
		.logout-link { margin-top: auto; color: #dc2626 !important; }
		.logout-link:hover { background: #fef2f2 !important; }
		.mobile-header { display: none; }
		.sidebar-backdrop { display: none; }
		.content { width: min(100% - 264px, 1200px); margin: 0 auto 0 264px; padding: 42px clamp(24px, 5vw, 70px) 56px; }
		.breadcrumb { margin: 0 0 10px; color: #64748b; font-size: 13px; }
		.breadcrumb a { color: #2563eb; text-decoration: none; }
		.page-header { margin-bottom: 24px; }
		h1 { margin: 0; color: #0f172a; font-size: clamp(27px, 3vw, 34px); font-weight: 750; letter-spacing: -.6px; }
		.subtitle { margin: 8px 0 0; color: #64748b; font-size: 14px; }
		.profile-card { overflow: hidden; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 8px 28px rgba(15, 23, 42, .05); }
		.alert { display: flex; align-items: flex-start; gap: 10px; padding: 13px 16px; margin: 20px 24px 0; border: 1px solid #fecaca; border-radius: 10px; color: #b91c1c; background: #fef2f2; font-size: 14px; line-height: 1.5; }
		.alert.success { border-color: #bbf7d0; color: #15803d; background: #f0fdf4; }
		.alert-icon { flex: 0 0 auto; font-size: 17px; line-height: 1.2; }
		.photo-section { display: flex; flex-direction: column; align-items: center; padding: 28px 24px 26px; text-align: center; }
		.avatar { display: grid; width: 132px; height: 132px; place-items: center; overflow: hidden; border: 4px solid #eff6ff; border-radius: 50%; color: #1d4ed8; background: #dbeafe; font-size: 40px; font-weight: 700; object-fit: cover; }
		.photo-name { margin: 14px 0 7px; color: #0f172a; font-size: 19px; font-weight: 700; }
		.role-badge { display: inline-flex; align-items: center; padding: 5px 10px; border-radius: 999px; color: #1d4ed8; background: #eff6ff; font-size: 11px; font-weight: 700; text-transform: capitalize; }
		.photo-controls { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 18px; }
		.photo-controls form { margin: 0; }
		.button { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; gap: 8px; padding: 0 16px; border: 1px solid transparent; border-radius: 9px; color: #fff; background: #2563eb; text-decoration: none; font: inherit; font-size: 13px; font-weight: 650; cursor: pointer; transition: background .15s ease, border-color .15s ease, color .15s ease; }
		.button:hover { color: #fff; background: #1d4ed8; }
		.button:disabled { opacity: .7; cursor: wait; }
		.button.secondary { color: #0f172a; background: #fff; border-color: #cbd5e1; }
		.button.secondary:hover { color: #0f172a; background: #f8fafc; border-color: #94a3b8; }
		.button.danger-outline { color: #dc2626; background: #fff; border-color: #fecaca; }
		.button.danger-outline:hover { color: #b91c1c; background: #fef2f2; border-color: #fca5a5; }
		.file-picker { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
		.file-picker:focus-visible + .button { outline: 3px solid #60a5fa; outline-offset: 2px; }
		.photo-hint { margin: 11px 0 0; color: #64748b; font-size: 12px; }
		.form-section { padding: 25px 28px 28px; border-top: 1px solid #e2e8f0; }
		.section-heading { margin: 0 0 20px; color: #0f172a; font-size: 17px; font-weight: 700; }
		.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 19px 20px; }
		.field { display: grid; min-width: 0; gap: 8px; color: #334155; font-size: 13px; font-weight: 650; }
		.field input, .field select { width: 100%; height: 46px; padding: 0 13px; border: 1px solid #cbd5e1; border-radius: 9px; color: #0f172a; background-color: #fff; font: inherit; font-size: 14px; font-weight: 400; transition: border-color .15s ease, box-shadow .15s ease; }
		.field input[readonly] { color: #475569; background: #f8fafc; }
		.field input:focus, .field select:focus { outline: none; border-color: #60a5fa; box-shadow: 0 0 0 3px rgba(96, 165, 250, .2); }
		.form-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 11px; padding-top: 22px; margin-top: 23px; border-top: 1px solid #e2e8f0; }
		.form-actions .button { min-width: 145px; }
		.button:focus-visible, .side-nav a:focus-visible { outline: 3px solid #60a5fa; outline-offset: 2px; }
		@media (max-width: 900px) {
			.content { padding-right: 28px; padding-left: 28px; }
		}
		@media (max-width: 760px) {
			.mobile-header { position: sticky; top: 0; z-index: 15; display: flex; height: 62px; align-items: center; justify-content: space-between; padding: 0 18px; background: #fff; border-bottom: 1px solid #e2e8f0; }
			.mobile-brand { display: flex; align-items: center; gap: 9px; color: #0f172a; font-size: 14px; font-weight: 700; }
			.mobile-brand .brand-mark { width: 34px; height: 34px; flex-basis: 34px; border-radius: 10px; }
			.menu-toggle { display: grid; width: 40px; height: 40px; place-items: center; border: 0; border-radius: 8px; color: #334155; background: #f1f5f9; font-size: 20px; cursor: pointer; }
			.sidebar { width: min(290px, 86vw); transform: translateX(-105%); transition: transform .2s ease; box-shadow: 12px 0 30px rgba(15, 23, 42, .12); }
			.sidebar.open { transform: translateX(0); }
			.sidebar-backdrop { position: fixed; inset: 0; z-index: 19; display: none; width: 100%; height: 100%; padding: 0; border: 0; background: rgba(15, 23, 42, .38); }
			.sidebar-backdrop.visible { display: block; }
			.content { width: 100%; margin: 0; padding: 28px 20px 42px; }
		}
		@media (max-width: 560px) {
			.content { padding: 23px 13px 32px; }
			.page-header { margin-bottom: 18px; }
			.alert { margin: 14px 14px 0; }
			.photo-section { padding: 23px 15px 21px; }
			.avatar { width: 122px; height: 122px; }
			.form-section { padding: 21px 16px 18px; }
			.form-grid { grid-template-columns: 1fr; gap: 15px; }
			.form-actions { justify-content: stretch; }
			.form-actions .button { flex: 1 1 100%; }
		}
		@media (prefers-reduced-motion: reduce) {
			*, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; }
		}
	</style>
</head>
<body>
	<?php
		$role = $user['role'] ?? '';
		$dashboardUrl = $role === 'teacher' ? 'teachers_dashboard.php' : 'student_dashboard.php';
		$profilePicture = profile_picture_data_uri($user['profile_picture'] ?? null);
		$fullName = (string) ($user['full_name'] ?? '');
		$initial = preg_match('/^\s*(\p{L}|\p{N})/u', $fullName, $matches) ? strtoupper($matches[1]) : 'U';
	?>
	<header class="mobile-header">
		<div class="mobile-brand">
			<img class="brand-mark" src="../cpc.logo.jpg" alt="Colegio de la Purisima Concepcion logo">
			<span>CPC Penalty System</span>
		</div>
		<button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation" aria-expanded="false" aria-controls="settingsSidebar">☰</button>
	</header>
	<button class="sidebar-backdrop" id="sidebarBackdrop" type="button" aria-label="Close navigation"></button>
	<aside class="sidebar" id="settingsSidebar">
		<p class="brand">
			<img class="brand-mark" src="../cpc.logo.jpg" alt="Colegio de la Purisima Concepcion logo">
			<span>CPC Penalty System</span>
		</p>
		<nav class="side-nav" aria-label="Main navigation">
			<?php if ($role === 'teacher'): ?>
				<a href="teachers_dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></svg><span>Teacher Dashboard</span></a>
				<a href="teachers_dashboard.php?view=students"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg><span>Students</span></a>
				<a href="teachers_dashboard.php?view=penalties"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h10l4 4v14H3V3h4Z"/><path d="M7 3v5h10V3M7 13h10M7 17h7"/></svg><span>Penalty Records</span></a>
				<a href="teachers_dashboard.php#messages"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/></svg><span>Messages</span></a>
			<?php else: ?>
				<a href="student_dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></svg><span>Student Dashboard</span></a>
				<a href="student_dashboard.php#penalties"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h10l4 4v14H3V3h4Z"/><path d="M7 3v5h10V3M7 13h10M7 17h7"/></svg><span>My Penalties</span></a>
				<a href="tuition.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/></svg><span>Tuition Balance</span></a>
				<a href="student_dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/></svg><span>Messages</span></a>
			<?php endif; ?>
			<a href="settings.php" class="active" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.4.8l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.4-.8l-1.7.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.7l-1.4-1.1 1.4-2.4 1.7.6a8 8 0 0 1 1.4-.8l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.4.8l1.7-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 1.7Z"/></svg><span>Settings &amp; Profile</span></a>
			<a class="logout-link" href="logout.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg><span>Logout</span></a>
		</nav>
	</aside>

	<main class="content">
		<p class="breadcrumb"><a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>">Dashboard</a> / Settings &amp; Profile</p>
		<header class="page-header">
			<h1>Settings &amp; Profile</h1>
			<p class="subtitle">Manage your personal information and account details.</p>
		</header>
		<section class="profile-card" aria-label="Profile settings">
			<?php if ($error): ?>
				<div class="alert" role="alert"><span class="alert-icon" aria-hidden="true">!</span><span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span></div>
			<?php endif; ?>
			<?php if ($success): ?>
				<div class="alert success" role="status"><span class="alert-icon" aria-hidden="true">✓</span><span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span></div>
			<?php endif; ?>
			<form id="settingsForm" method="post" action="settings.php" enctype="multipart/form-data">
				<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
				<input type="hidden" name="action" value="update_profile">
				<section class="photo-section" aria-label="Profile photo">
					<?php if ($profilePicture): ?>
						<img id="profileAvatar" class="avatar" src="<?= htmlspecialchars($profilePicture, ENT_QUOTES, 'UTF-8') ?>" alt="Profile photo">
					<?php else: ?>
						<div id="profileAvatar" class="avatar" role="img" aria-label="Profile initials"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></div>
					<?php endif; ?>
					<p class="photo-name" id="profileNamePreview"><?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></p>
					<span class="role-badge"><?= htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8') ?></span>
					<div class="photo-controls">
						<input class="file-picker" id="profilePictureInput" type="file" name="profile_picture" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp" aria-label="Choose a profile photo">
						<label class="button" for="profilePictureInput">Change photo</label>
						<?php if ($profilePicture): ?>
							<button class="button danger-outline" type="submit" form="removePhotoForm">Remove photo</button>
						<?php endif; ?>
					</div>
					<p class="photo-hint" id="photoHint">JPEG, PNG, GIF, or WebP. Maximum 5 MB.</p>
				</section>
				<section class="form-section" aria-labelledby="personalInfoTitle">
					<h2 class="section-heading" id="personalInfoTitle">Personal Information</h2>
					<div class="form-grid">
						<label class="field" for="username">Username<input id="username" name="username" maxlength="100" value="<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required></label>
						<label class="field" for="fullName">Full Name<input id="fullName" name="full_name" maxlength="150" value="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>" autocomplete="name" required></label>
						<?php if ($role === 'student'): ?>
							<label class="field" for="studentId">Student ID<input id="studentId" value="<?= htmlspecialchars($user['student_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly aria-readonly="true"></label>
						<?php endif; ?>
						<label class="field" for="cellphone">Cellphone Number<input id="cellphone" type="tel" name="cellphone" maxlength="30" value="<?= htmlspecialchars($user['cellphone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="tel" required></label>
						<label class="field" for="role">Role<input id="role" value="<?= htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8') ?>" readonly></label>
						<?php if ($role === 'student'): ?>
							<label class="field" for="course">Course<input id="course" value="<?= htmlspecialchars($user['course'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly aria-readonly="true"></label>
							<label class="field" for="department">Department<input id="department" value="<?= htmlspecialchars($user['department'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly aria-readonly="true"></label>
							<label class="field" for="year_level">Year level
								<select id="year_level" name="year_level" required>
									<option value="">Select year/grade level</option>
									<?php foreach (student_year_levels((string) ($user['department'] ?? '')) as $yearLevelOption): ?>
										<option value="<?= htmlspecialchars($yearLevelOption, ENT_QUOTES, 'UTF-8') ?>" <?= (($user['year_level'] ?? '') === $yearLevelOption) ? 'selected' : '' ?>><?= htmlspecialchars($yearLevelOption, ENT_QUOTES, 'UTF-8') ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						<?php endif; ?>
					</div>
					<div class="form-actions">
						<a class="button secondary" href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>">Back to Dashboard</a>
						<button class="button" id="saveButton" type="submit">Save Changes</button>
					</div>
				</section>
			</form>
		</section>
		<form id="removePhotoForm" method="post" action="settings.php" data-confirm="Remove your profile photo?">
			<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
			<input type="hidden" name="action" value="remove_profile_picture">
		</form>
	</main>
<script>
	document.addEventListener('DOMContentLoaded', function () {
		const sidebar = document.getElementById('settingsSidebar');
		const menuToggle = document.getElementById('menuToggle');
		const backdrop = document.getElementById('sidebarBackdrop');
		const photoInput = document.getElementById('profilePictureInput');
		const saveButton = document.getElementById('saveButton');
		const photoHint = document.getElementById('photoHint');
		const settingsForm = document.getElementById('settingsForm');
		let previewUrl = null;

		function setMenuOpen(isOpen) {
			sidebar.classList.toggle('open', isOpen);
			backdrop.classList.toggle('visible', isOpen);
			menuToggle.setAttribute('aria-expanded', String(isOpen));
			menuToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
		}

		menuToggle.addEventListener('click', function () {
			setMenuOpen(!sidebar.classList.contains('open'));
		});
		backdrop.addEventListener('click', function () { setMenuOpen(false); });
		sidebar.querySelectorAll('a').forEach(function (link) {
			link.addEventListener('click', function () { setMenuOpen(false); });
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') setMenuOpen(false);
		});

		photoInput.addEventListener('change', function () {
			if (!this.files || !this.files.length) return;
			const file = this.files[0];
			const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
			if (!allowedTypes.includes(file.type) || file.size > 5 * 1024 * 1024) {
				this.value = '';
				photoHint.textContent = 'Choose a JPEG, PNG, GIF, or WebP image smaller than 5 MB.';
				photoHint.setAttribute('role', 'alert');
				return;
			}
			if (previewUrl) URL.revokeObjectURL(previewUrl);
			previewUrl = URL.createObjectURL(file);
			const currentAvatar = document.getElementById('profileAvatar');
			const preview = document.createElement('img');
			preview.id = 'profileAvatar';
			preview.className = 'avatar';
			preview.alt = 'Selected profile photo preview';
			preview.src = previewUrl;
			currentAvatar.replaceWith(preview);
			photoHint.textContent = file.name + ' selected. Save changes to upload.';
			photoHint.removeAttribute('role');
		});

		document.getElementById('fullName').addEventListener('input', function () {
			document.getElementById('profileNamePreview').textContent = this.value;
		});
		settingsForm.addEventListener('submit', function () {
			saveButton.disabled = true;
			saveButton.textContent = 'Saving…';
		});
		document.querySelectorAll('form[data-confirm]').forEach(function (form) {
			form.addEventListener('submit', function (event) {
				if (!window.confirm(form.dataset.confirm)) event.preventDefault();
			});
		});
	});
</script>
</body>
</html>
