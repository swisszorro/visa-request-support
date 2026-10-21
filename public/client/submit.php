<?php

declare(strict_types=1);

/**
 * Server-side proxy of the client form: checks access + CSRF, then forwards the upload to the
 * visa service with the connection data from config/client.php. The API key stays on the server.
 */

use BWC\Visa\ClientPortal;

require __DIR__ . '/../../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, 'POST only.');
}

try {
    $cfg = ClientPortal::load();
} catch (\RuntimeException $e) {
    respond(503, $e->getMessage());
}

$auth = ClientPortal::authenticate($cfg, ClientPortal::authorizationHeader());
if ($auth === 'unconfigured') {
    respond(503, 'Access protection is not configured (config/client.php: users).');
}
if ($auth !== 'ok') {
    header('WWW-Authenticate: Basic realm="BWC Visa Client", charset="UTF-8"');
    respond(401, 'Login required.');
}

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
if (!ClientPortal::csrfValid((string) ($_POST['csrf'] ?? ''))) {
    respond(403, 'Session expired - please reload the page.');
}
session_write_close(); // do not hold the session lock while the (long) analysis runs

// ---- text fields ----------------------------------------------------------
$fields = [];
foreach (['name', 'firstname', 'address', 'zip', 'city', 'country', 'federation', 'email'] as $f) {
    $fields[$f] = trim((string) ($_POST[$f] ?? ''));
}
$missing = array_keys(array_filter(
    array_intersect_key($fields, array_flip(['name', 'firstname', 'address', 'zip', 'city', 'country', 'federation'])),
    static fn ($v) => $v === ''
));
if ($missing !== []) {
    respond(400, 'Please fill in: ' . implode(', ', $missing));
}
if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    respond(400, 'The e-mail address is invalid.');
}
if (!empty($_POST['send_email'])) {
    if ($fields['email'] === '') {
        respond(400, 'An e-mail address is required to send the letter by e-mail.');
    }
    $fields['send_email'] = '1';
    $fields['mail_to'] = $fields['email'];
}

// ---- files ----------------------------------------------------------------
$files = [];
$excel = $_FILES['excel'] ?? null;
if (!is_array($excel) || ($excel['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $excel['tmp_name'])) {
    respond(400, 'Please upload the Excel file (or the upload failed).');
}
if (strtolower(pathinfo((string) $excel['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
    respond(400, 'The Excel file must have the extension .xlsx.');
}
$files[] = ['field' => 'excel', 'path' => (string) $excel['tmp_name'], 'name' => basename((string) $excel['name'])];

$imgs = $_FILES['images'] ?? null;
$count = 0;
if (is_array($imgs) && is_array($imgs['name'] ?? null)) {
    foreach ($imgs['name'] as $i => $name) {
        $err = $imgs['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $imgs['tmp_name'][$i])) {
            respond(400, 'Upload failed: ' . basename((string) $name) . ' (too large or interrupted).');
        }
        $count++;
        $files[] = ['field' => 'images[]', 'path' => (string) $imgs['tmp_name'][$i], 'name' => basename((string) $name)];
    }
}
if ($count === 0) {
    respond(400, 'Please upload at least one passport image or PDF.');
}
if ($count > $cfg['max_images']) {
    respond(400, "Too many files: {$count} (max. {$cfg['max_images']}).");
}

@set_time_limit($cfg['timeout'] + 30);
$res = ClientPortal::forward($cfg, $fields, $files);
http_response_code($res['status']);
echo $res['body'];
