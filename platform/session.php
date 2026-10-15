<?php
// Session for the platform (licensing) console.
//
// The console used to share the floor app's PHPSESSID, so a platform login and
// a floor login lived in the same session. It now uses its own cookie name,
// scoped to this folder, so the two are fully separate: signing in or out of
// one never affects the other.
if (session_status() === PHP_SESSION_NONE) {
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
           || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/platform/index.php')), '/') . '/';

    session_name('FLOWORK_PLATFORM');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $path,
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}
