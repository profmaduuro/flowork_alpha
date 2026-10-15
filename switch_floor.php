<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token verification failed.");
    }
    
    // Prohibit dedicated users from switching to other floors
    if (is_dedicated_user()) {
        $_SESSION['flash_err'] = "Access Denied: You are dedicated to " . htmlspecialchars($_SESSION['selling_point_name'] ?? 'your floor') . " and prohibited from switching to other floors.";
        $ref = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
        header("Location: $ref");
        exit;
    }

    $new_spid = (int)($_POST['selling_pointid'] ?? 0);
    if ($new_spid > 0) {
        // Verify user can access this floor
        $accessible = $_SESSION['accessible_floors'] ?? [];
        $allowed = array_filter($accessible, fn($f) => (int)$f['id'] === $new_spid);
        if (!empty($allowed) || is_master_user()) {
            $stmt = $conn->prepare("SELECT id, name FROM selling_point WHERE id=? AND active=1 LIMIT 1");
            $stmt->bind_param("i", $new_spid);
            $stmt->execute();
            $sp_q = $stmt->get_result();
            $sp = ($sp_q && $sp_q->num_rows > 0) ? $sp_q->fetch_assoc() : null;
            $stmt->close();
            
            if ($sp) {
                $_SESSION['selling_pointid']    = (int)$sp['id'];
                $_SESSION['selling_point_name'] = $sp['name'];
            }
        }
    }
}
$ref = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
header("Location: $ref");
exit;
