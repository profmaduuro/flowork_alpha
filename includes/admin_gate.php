<?php
// Gate for maintenance, debug, and setup scripts in the web root.
// Allows: CLI, requests from the server machine itself (localhost),
// a valid setup key (?key=YOUR_KEY), or a logged-in admin session.
// Everyone else gets a 403.

// Load environment variables if available
require_once __DIR__ . '/env_loader.php';

if (PHP_SAPI === 'cli') return;

if (session_status() === PHP_SESSION_NONE) @session_start();

// How long a successful key unlock lasts. It used to be permanent for the
// life of the session, which turned one key disclosure into standing access.
if (!defined('SETUP_GRANT_SECONDS')) define('SETUP_GRANT_SECONDS', 1800); // 30 min

// 1. Allow if setup/maintenance was authorised recently in this session
if (!empty($_SESSION['setup_authorized_at'])
    && (time() - (int)$_SESSION['setup_authorized_at']) < SETUP_GRANT_SECONDS) {
    return;
}
unset($_SESSION['setup_authorized'], $_SESSION['setup_authorized_at']);

// 2. Allow if a valid setup key is supplied.
//
//    There is deliberately NO fallback value here. This previously defaulted to
//    a key committed to the source tree and published in .env.example, so with
//    no .env present every gated maintenance script in the web root — including
//    ones that drop tables — was reachable by anyone who had seen the code.
//    With SETUP_KEY unset, key access is simply switched off; localhost and a
//    logged-in admin (below) still get through, so this cannot lock anyone out.
$setup_key = getenv('SETUP_KEY');
if ($setup_key === false || $setup_key === '') {
    $setup_key = $_ENV['SETUP_KEY'] ?? $_SERVER['SETUP_KEY'] ?? '';
}
if (is_string($setup_key) && strlen($setup_key) >= 16 && !empty($_GET['key'])
    && hash_equals($setup_key, (string)$_GET['key'])) {
    session_regenerate_id(true);
    $_SESSION['setup_authorized']    = true;
    $_SESSION['setup_authorized_at'] = time();
    return;
}

// 3. Localhost stays open for easy local development/recovery
if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) return;

// 4. Allow if standard admin session exists
if (!empty($_SESSION['user_id']) && !empty($_SESSION['is_admin'])) return;

http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo "403 - This maintenance tool requires an administrator login or valid setup key.";
exit;
