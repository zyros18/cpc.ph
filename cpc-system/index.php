<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/actions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post_action();
}

$user = require_login();
$page = $_GET['page'] ?? 'dashboard';
if (!is_string($page) || !role_can($user['role_name'], $page)) {
    http_response_code(403);
    exit('You are not authorized to access this page.');
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/app/pages/reports.php';
    if (query_string('kind') === 'penalties') {
        export_penalty_report_csv();
    }
    export_report_csv();
}

$viewFile = __DIR__ . '/app/pages/' . $page . '.php';
if (!is_file($viewFile)) {
    http_response_code(404);
    exit('Page not found.');
}
require __DIR__ . '/app/views/layout.php';
