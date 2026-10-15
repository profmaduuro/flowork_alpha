<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

header('Content-Type: application/json');
$q = $conn->real_escape_string($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$spid = (int)($_SESSION['selling_pointid'] ?? 0);
$sql = "SELECT id, grower_num, name, surname FROM growers 
        WHERE selling_pointid = $spid 
          AND (grower_num LIKE '%$q%' OR name LIKE '%$q%' OR surname LIKE '%$q%') 
        LIMIT 10";

$res = $conn->query($sql);
$rows = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id' => $r['id'],
            'grower_num' => $r['grower_num'],
            'name' => htmlspecialchars($r['name'] . ' ' . $r['surname']),
        ];
    }
}
echo json_encode($rows);
