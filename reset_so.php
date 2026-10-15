<?php
require_once __DIR__ . '/includes/admin_gate.php';
session_start();
require_once "includes/conn.php";
echo "<pre style='font:13px monospace;background:#111;color:#9c9;padding:20px'>";

$spid = (int)($_SESSION['selling_pointid'] ?? 0);

// Count current state
$tot = $conn->query("SELECT COUNT(*) c FROM balanced_bales")->fetch_assoc()['c'];
$p1  = $conn->query("SELECT COUNT(*) c FROM balanced_bales WHERE stoporder_processed=1")->fetch_assoc()['c'];
$p0  = $conn->query("SELECT COUNT(*) c FROM balanced_bales WHERE stoporder_processed=0")->fetch_assoc()['c'];

echo "Total balanced batches : $tot\n";
echo "stoporder_processed=1  : $p1\n";
echo "stoporder_processed=0  : $p0\n\n";

if(isset($_GET['reset'])){
    $conn->query("UPDATE balanced_bales SET stoporder_processed=0");
    echo "✓ Reset all to stoporder_processed=0\n";
    echo "Reload balance.php — Run Stop Orders button should now appear.\n";
} else {
    echo "Visit <a href='reset_so.php?reset=1'>reset_so.php?reset=1</a> to reset all batches to pending.\n";
}
echo "</pre>";
