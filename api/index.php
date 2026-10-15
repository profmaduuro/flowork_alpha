<?php
// Always output JSON — never raw PHP errors
ob_start();
require_once __DIR__ . '/../includes/env_loader.php'; // flowork_show_errors()
ini_set('display_errors', 0);
error_reporting(0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Selling-Point');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

try {
    $dir = __DIR__;

    // Load config
    if (!file_exists($dir . '/config/db.php')) {
        throw new Exception('config/db.php not found at: ' . $dir . '/config/db.php');
    }
    require_once $dir . '/config/db.php';

    if (!file_exists($dir . '/config/response.php')) {
        throw new Exception('config/response.php not found');
    }
    require_once $dir . '/config/response.php';

    // Global API Rate Limiting (100 requests per minute per IP)
    $db = getDB();
    require_once $dir . '/../includes/rate_limiter.php';
    if (!check_rate_limit($db, 'api_general', 100, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please slow down.', 'data' => null]);
        exit;
    }

    $module = $_GET['module'] ?? '';

    $routes = [
        'auth'        => 'modules/auth.php',
        'sync'        => 'modules/lookup.php',
        'growers'     => 'modules/growers.php',
        'scale'       => 'modules/scale.php',
        'selling'     => 'modules/selling.php',
        'tickets'     => 'modules/tickets.php',
        'balance'     => 'modules/balance.php',
        'stoporders'  => 'modules/stoporders.php',
        'payments'    => 'modules/payments.php',
        'lookup'      => 'modules/lookup.php',
        'transporter' => 'modules/transporter.php',
        'transporters'=> 'modules/transporter.php',
        'floor'       => 'modules/floor.php',
        'dispatch'    => 'modules/dispatch.php',
        'settings'    => 'modules/settings.php',
        'processing'  => 'modules/processing.php',
        'packhouse'   => 'modules/packhouse.php',
    ];

    if (empty($module)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'No module specified', 'data' => null]);
        exit;
    }

    if (!isset($routes[$module])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => "Unknown module: '$module'", 'data' => null]);
        exit;
    }

    $path = $dir . '/' . $routes[$module];

    if (!file_exists($path)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => "Module not implemented: '$module'", 'data' => null]);
        exit;
    }

    ob_end_clean();
    require $path;

} catch (Throwable $e) {
    ob_end_clean();
    error_log('API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => flowork_show_errors()
            ? 'Server error: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')'
            : 'Server error',
        'data'    => null
    ]);
    exit;
}
