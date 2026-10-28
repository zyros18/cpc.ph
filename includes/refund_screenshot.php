<?php
require_once __DIR__ . '/auth.php';
require_role('teacher');
ensure_refund_requests_table();

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$requestId || $requestId < 1) {
    http_response_code(404);
    exit('Screenshot not found.');
}

$statement = database()->prepare(
    'SELECT screenshot, screenshot_mime_type
     FROM refund_requests
     WHERE id = :id
     LIMIT 1'
);
$statement->execute(['id' => $requestId]);
$request = $statement->fetch();
$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!$request || !in_array($request['screenshot_mime_type'], $allowedMimeTypes, true)) {
    http_response_code(404);
    exit('Screenshot not found.');
}

header('Content-Type: ' . $request['screenshot_mime_type']);
header('Content-Length: ' . strlen($request['screenshot']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $request['screenshot'];
