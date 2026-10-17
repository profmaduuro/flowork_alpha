<?php
// Load environment variables if available
require_once __DIR__ . '/../../includes/env_loader.php';

// Match the main web app database selection: Docker env, XAMPP local, or live.
$_env_host = flowork_env('DB_HOST');
$_port = (int)flowork_env('DB_PORT', '3306');

if ($_env_host) {
    $_host = $_env_host;
    $_name = flowork_env('DB_NAME', 'king');
    $_user = flowork_env('DB_USER', 'flowork');
    $_pass = flowork_env('DB_PASS', '');
} else {
    // Local install? (APP_ENV, or the server's own address — never the Host header)
    $_is_local = flowork_is_local();
    $_host = 'localhost';
    $_name = $_is_local ? 'king' : 'coreafri_king';
    $_user = $_is_local ? 'root' : 'coreafri_king';
    $_pass = $_is_local ? '' : 'your_password';
}

function getDB(): mysqli {
    static $conn = null;
    if ($conn !== null) return $conn;
    global $_host, $_port, $_name, $_user, $_pass;
    $conn = new mysqli($_host, $_user, $_pass, $_name, $_port);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>flowork_show_errors() ? 'DB connection failed: '.$conn->connect_error : 'Service unavailable','data'=>null]);
        exit;
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
