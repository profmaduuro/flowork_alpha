<?php
/**
 * Issue (or replace) the API v2 key for one floor. Command line only:
 *
 *   php maintenance/api_key_v2.php <floor id>            issue / replace the key
 *   php maintenance/api_key_v2.php <floor id> --revoke   remove the key
 *
 * The key is printed once and only its SHA-256 hash is stored, in
 * settings.api_key_v2_sp<floor id>. Give it to the integration over a secure
 * channel; if it is lost, issue a new one.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['DOCUMENT_ROOT']   = dirname(__DIR__, 2);
require dirname(__DIR__) . '/includes/conn.php';

$spid = (int)($argv[1] ?? 0);
if ($spid <= 0) { fwrite(STDERR, "Usage: php maintenance/api_key_v2.php <floor id> [--revoke]\n"); exit(2); }

$fl = $conn->query("SELECT name FROM selling_point WHERE id=$spid LIMIT 1");
$floor = ($fl && $fl !== true) ? $fl->fetch_assoc() : null;
if (!$floor) { fwrite(STDERR, "No floor with id $spid.\n"); exit(1); }

$name = 'api_key_v2_sp' . $spid;
if (in_array('--revoke', $argv, true)) {
    $stmt = $conn->prepare("DELETE FROM settings WHERE name=?");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    echo "API v2 key for floor $spid ({$floor['name']}) revoked.\n";
    exit(0);
}

$key  = bin2hex(random_bytes(32));
$hash = hash('sha256', $key);
$stmt = $conn->prepare("INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
$stmt->bind_param('ss', $name, $hash);
if (!$stmt->execute()) { fwrite(STDERR, "Could not save the key: {$stmt->error}\n"); exit(1); }

echo "API v2 key for floor $spid ({$floor['name']}). Shown once; any previous key no longer works:\n\n$key\n\n";
echo "Send it in the X-API-Key header.\n";
