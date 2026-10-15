<?php
require_once __DIR__ . '/../includes/admin_gate.php';
require_once "includes/conn.php";
$q = $conn->query("SELECT * FROM system_audit ORDER BY id DESC LIMIT 10");
$rows = $q ? $q->fetch_all(MYSQLI_ASSOC) : [];
echo "COUNT: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "ID: {$r['id']} | SP: {$r['selling_pointid']} | Created: {$r['created_at']} | Action: {$r['action']}\n";
}
