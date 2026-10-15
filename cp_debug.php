<?php
require_once __DIR__ . '/includes/admin_gate.php';
session_start();
require_once "includes/conn.php";
header('Content-Type: application/json');
$spid = (int)($_SESSION['selling_pointid'] ?? 0);
$total = $conn->query("SELECT COUNT(*) c FROM grower_creditors")->fetch_assoc()['c'];
$by_sp = $conn->query("SELECT selling_pointid, COUNT(*) c FROM grower_creditors GROUP BY selling_pointid ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);
$sample = $conn->query("SELECT id,creditor_no,name,creditor_name,selling_pointid,status FROM grower_creditors LIMIT 5")->fetch_all(MYSQLI_ASSOC);
echo json_encode(['session_spid'=>$spid,'total'=>$total,'by_sp'=>$by_sp,'sample'=>$sample],JSON_PRETTY_PRINT);
