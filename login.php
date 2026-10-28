<?php
require_once __DIR__ . '/includes/auth.php';
ensure_student_academic_fields();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $statement = database()->prepare(
            'SELECT id, username, password, full_name, student_id, cellphone, role, department, course, year_level, profile_picture
             FROM users
             WHERE username = :username OR student_id = :student_id
             LIMIT 1'
        );
        $statement->execute(['username' => $login, 'student_id' => $login]);
        $user = $statement->fetch();
    } catch (PDOException $exception) {
        $_SESSION['login_error'] = 'Unable to connect to the CPC database.';
        header('Location: login.php');
        exit;
    }

    if (!$user || !password_verify($password, $user['password'])) {
        $_SESSION['login_error'] = 'Incorrect username/student ID or password.';
        header('Location: login.php');
        exit;
    }

    session_regenerate_id(true);
    unset($user['password']);
    $_SESSION['user'] = $user;
    $dashboard = $user['role'] === 'teacher'
        ? 'includes/teachers_dashboard.php'
        : 'includes/student_dashboard.php';
    header('Location: ' . $dashboard);
    exit;
}

if (isset($_SESSION['user'])) {
    $dashboard = ($_SESSION['user']['role'] ?? '') === 'teacher'
        ? 'includes/teachers_dashboard.php'
        : 'includes/student_dashboard.php';
    header('Location: ' . $dashboard);
    exit;
}

$error = $_SESSION['login_error'] ?? '';
$success = $_SESSION['register_success'] ?? '';
unset($_SESSION['login_error'], $_SESSION['register_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CPC Penalty System Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            margin: 0;
            padding: clamp(12px, 4vw, 32px);
            min-height: 100vh;
            min-height: 100svh;
            display: grid;
            place-items: center;
            background-color: #1e293b;
            background-image:
                linear-gradient(rgba(15, 23, 42, 0.12), rgba(15, 23, 42, 0.12)),
                url("Designer.png");
            background-position: center;
            background-size: cover;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }
        .login-box {
            width: min(100%, 420px);
            border: 1px solid rgba(255, 255, 255, 0.22);
            background: rgba(12, 31, 59, 0.86);
            -webkit-backdrop-filter: blur(14px);
            backdrop-filter: blur(14px);
            border-radius: 20px;
            box-shadow: 0 24px 60px rgba(2, 12, 27, 0.35);
            padding: clamp(1.25rem, 5vw, 2rem);
            color: #f8fafc;
        }
        .login-box h2 { color: #fff; font-weight: 700; letter-spacing: -0.02em; }
        .login-box .login-subtitle { color: #cbd5e1 !important; }
        .login-box .account-prompt { color: #e2e8f0 !important; }
        .login-box .form-label { color: #e2e8f0; font-weight: 600; }
        .login-box .form-control {
            min-height: 46px;
            border-color: rgba(203, 213, 225, 0.65);
            border-radius: 9px;
            background-color: rgba(255, 255, 255, 0.97);
        }
        .login-box .form-control:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 0.2rem rgba(96, 165, 250, 0.25);
        }
        .login-box .btn-primary {
            border-color: #60a5fa;
            background: #2563eb;
            font-weight: 650;
        }
        .login-box .btn-primary:hover { border-color: #3b82f6; background: #1d4ed8; }
        .login-box .create-account-link {
            border-color: rgba(191, 219, 254, 0.7);
            color: #dbeafe;
        }
        .login-box .create-account-link:hover {
            color: #0f172a;
            background: #dbeafe;
        }
        .logo-container {
            text-align: center;
            margin-bottom: 1.25rem;
        }
        .logo {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: block;
            object-fit: contain;
            padding: 5px;
            margin: 0 auto 0.85rem;
            background: #fff;
            box-shadow: 0 6px 18px rgba(2, 12, 27, 0.3);
        }
        .password-field { position: relative; }
        .password-field .form-control { padding-right: 46px; }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 8px;
            display: grid;
            width: 34px;
            height: 34px;
            padding: 0;
            place-items: center;
            transform: translateY(-50%);
            border: 0;
            border-radius: 6px;
            color: #667085;
            background: transparent;
        }
        .password-toggle:hover { color: #344054; background: #f2f4f7; }
        .password-toggle:focus-visible { outline: 2px solid #86b7fe; outline-offset: 1px; }
        .password-toggle svg { width: 19px; height: 19px; }
        @media (max-width: 600px) {
            body {
                background-image:
                    linear-gradient(rgba(15, 23, 42, 0.35), rgba(15, 23, 42, 0.35)),
                    url("Designer.png");
                background-attachment: scroll;
                background-position: center;
            }
            .login-box {
                border-radius: 14px;
            }
            .login-box h2 {
                font-size: 1.2rem;
            }
            .login-box .form-label {
                margin-bottom: 0.3rem;
                font-size: 0.82rem;
            }
            .login-box .form-control {
                min-height: 40px;
                padding: 0.45rem 0.65rem;
                font-size: 0.85rem;
            }
            .login-box .password-field .form-control {
                padding-right: 42px;
            }
            .login-box .password-toggle {
                right: 5px;
                width: 32px;
                height: 32px;
            }
            .logo-container {
                margin-bottom: 1.1rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="logo-container">
            <img src="cpc.logo.jpg" alt="Colegio de la Purisima Concepcion logo" class="logo">
            <h2 class="text-center mb-1">CPC Penalty System</h2>
        </div>
        <p class="login-subtitle text-center mb-4">Student/Teacher Login</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form id="loginForm" method="post" action="login.php">
            <div class="mb-3">
                <label for="username" class="form-label">Username or Student ID</label>
                <input type="text" class="form-control" id="username" name="username" placeholder="Enter your username or student ID" autocomplete="username" required>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="password-field">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" autocomplete="current-password" required>
                    <button class="password-toggle" id="passwordToggle" type="button" aria-label="Show password" aria-pressed="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>

        <div class="mt-4 text-center">
            <p class="account-prompt mb-2">Don't have an account?</p>
            <a href="includes/views/create.php" class="btn btn-outline-primary btn-sm create-account-link">Create Account</a>
        </div>
    </div>
    <script>
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('passwordToggle');
        const usernameInput = document.getElementById('username');
        const rememberedUsernameKey = 'cpcPenaltyRememberedUsername';

        try {
            const rememberedUsername = window.localStorage.getItem(rememberedUsernameKey);
            if (rememberedUsername) {
                usernameInput.value = rememberedUsername;
            }

            usernameInput.addEventListener('input', function () {
                const username = usernameInput.value.trim();
                if (username) {
                    window.localStorage.setItem(rememberedUsernameKey, username);
                } else {
                    window.localStorage.removeItem(rememberedUsernameKey);
                }
            });
        } catch (error) {
            console.warn('Could not access saved login username:', error);
        }

        usernameInput.addEventListener('change', function () {
            try {
                const username = usernameInput.value.trim();
                if (username) {
                    window.localStorage.setItem(rememberedUsernameKey, username);
                } else {
                    window.localStorage.removeItem(rememberedUsernameKey);
                }
            } catch (error) {
                console.warn('Could not save login username:', error);
            }
        });

        usernameInput.form.addEventListener('submit', function () {
            try {
                const username = usernameInput.value.trim();
                if (username) {
                    window.localStorage.setItem(rememberedUsernameKey, username);
                } else {
                    window.localStorage.removeItem(rememberedUsernameKey);
                }
            } catch (error) {
                console.warn('Could not save login username:', error);
            }
        });

        passwordToggle.addEventListener('click', function () {
            const shouldShow = passwordInput.type === 'password';
            passwordInput.type = shouldShow ? 'text' : 'password';
            passwordToggle.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
            passwordToggle.setAttribute('aria-pressed', String(shouldShow));
        });
    </script>
</body>
</html>
</html>