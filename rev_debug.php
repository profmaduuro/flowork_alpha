<?php
require_once __DIR__ . '/includes/admin_gate.php';
session_start();
require_once "includes/conn.php";
header('Content-Type: application/json');

$q = $conn->query("
    SELECT bb.id, bb.sale_batchid, bb.growerid, bb.transporter_growersid,
           g.grower_num, g.name, g.surname,
           COALESCE(dt.usd_value,0) gross,
           COALESCE(dt.sold_bales,0) bales,
           bb.created_at
    FROM balanced_bales bb
    JOIN growers g ON g.id=bb.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    ORDER BY bb.id DESC LIMIT 20
");
$rows = ($q && $q !== true) ? $q->fetch_all(MYSQLI_ASSOC) : [];
echo json_encode(['rows'=>$rows,'error'=>$conn->error,'total'=>count($rows)], JSON_PRETTY_PRINT);
