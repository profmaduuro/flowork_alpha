<?php
require_once __DIR__ . '/../includes/admin_gate.php';
require_once "includes/conn.php";
$res = $conn->query("SELECT NOW() db_now, CURDATE() db_today");
$db = $res ? $res->fetch_assoc() : [];
echo "DB NOW:   " . ($db['db_now'] ?? '—') . "\n";
echo "DB TODAY: " . ($db['db_today'] ?? '—') . "\n";
echo "PHP NOW:  " . date('Y-m-d H:i:s') . "\n";
echo "PHP TODAY:" . date('Y-m-d') . "\n";
