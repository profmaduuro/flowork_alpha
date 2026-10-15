<?php
require_once __DIR__ . '/../includes/admin_gate.php';
if (session_status() === PHP_SESSION_NONE) session_start();
// Set session variables to simulate the active user's environment if any
$_SESSION['selling_pointid'] = 1; // default to 1 for testing

require_once "includes/conn.php";

$spid = (int)($_SESSION['selling_pointid'] ?? 0);
$f_from = date('Y-m-d');
$f_to = date('Y-m-d');

$where = ["sa.selling_pointid=?", "DATE(sa.created_at) BETWEEN ? AND ?"];
$params = [$spid, $f_from, $f_to];
$types = "iss";

$w = implode(' AND ', $where);

// Helper function to execute prepared selects safely
function get_rows_prepared($conn, $sql, $params, $types) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = ($res && $res !== true) ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
    return [];
}

$rows = get_rows_prepared($conn, "SELECT sa.* FROM system_audit sa WHERE $w", $params, $types);
echo "Rows returned for today (SP: $spid): " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "ID: {$r['id']} | Created: {$r['created_at']} | Action: {$r['action']}\n";
}
