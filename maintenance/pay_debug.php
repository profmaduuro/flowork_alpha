<?php
require_once dirname(__DIR__) . '/includes/admin_gate.php';
session_start();
require_once dirname(__DIR__) . "/includes/conn.php";
echo "<pre style='font:12px monospace;background:#111;color:#9c9;padding:20px'>";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);
echo "spid=$spid\n\n";

// Check what's in each table
echo "balanced_bales: ".$conn->query("SELECT COUNT(*) c FROM balanced_bales")->fetch_assoc()['c']."\n";
echo "daily_totals: ".$conn->query("SELECT COUNT(*) c FROM daily_totals")->fetch_assoc()['c']."\n";
echo "daily_totals_net_amount: ".$conn->query("SELECT COUNT(*) c FROM daily_totals_net_amount")->fetch_assoc()['c']."\n";
echo "payment_status: ".$conn->query("SELECT COUNT(*) c FROM payment_status")->fetch_assoc()['c']."\n\n";

// Simple join test
$q = $conn->query("
    SELECT bb.id bbid, bb.created_at,
           tg.id tgid, g.grower_num, g.selling_pointid g_spid,
           COALESCE(dt.usd_value,0) gross,
           COALESCE(dtn.usd_net_value,0) net_usd
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
    ORDER BY bb.id DESC LIMIT 10
");
echo "=== balanced_bales with joins ===\n";
if($q && $q!==true) while($r=$q->fetch_assoc())
    echo "  bbid={$r['bbid']} grower={$r['grower_num']} g_spid={$r['g_spid']} gross={$r['gross']} net={$r['net_usd']} date={$r['created_at']}\n";
else echo "  ERROR: ".$conn->error."\n";

// Test grower_banking
echo "\ngrower_banking columns: ";
$cols=$conn->query("SHOW COLUMNS FROM grower_banking");
if($cols&&$cols!==true) { while($c=$cols->fetch_assoc()) echo $c['Field'].", "; } else echo "TABLE MISSING";
echo "\n";
echo "</pre>";
