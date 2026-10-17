<?php
require_once dirname(__DIR__) . '/includes/admin_gate.php';
session_start();
require_once dirname(__DIR__) . "/includes/conn.php";
echo "<pre style='font:12px monospace;background:#111;color:#9c9;padding:20px'>";

$spid = (int)($_SESSION['selling_pointid'] ?? 0);
echo "session spid=$spid\n\n";

// Show booked_bales columns
$cols = $conn->query("SHOW COLUMNS FROM booked_bales");
echo "=== booked_bales columns ===\n";
if($cols&&$cols!==true) while($r=$cols->fetch_assoc()) echo "  {$r['Field']} ({$r['Type']}) default=".($r['Default']??'NULL')."\n";

// Show sample rows
$bb = $conn->query("SELECT * FROM booked_bales ORDER BY id DESC LIMIT 5");
echo "\n=== booked_bales sample ===\n";
if($bb&&$bb!==true) while($r=$bb->fetch_assoc()) { foreach($r as $k=>$v) echo "  $k=$v\n"; echo "---\n"; }
else echo "  empty or error: ".$conn->error."\n";

// Show the fixed query result
echo "\n=== Fixed booking query ===\n";
$q = $conn->query("
    SELECT tg.id tgid, tg.booked, tg.bales, tg.selling_pointid tg_spid,
           COALESCE(tg.sell_date,'') sell_dt,
           g.id growerid, g.grower_num, g.name, g.selling_pointid g_spid,
           t.name transporter_name,
           CASE WHEN bal.id IS NOT NULL THEN 1 ELSE 0 END is_balanced
    FROM transporter_growers tg
    JOIN growers g ON g.id=tg.growerid
    JOIN transporter t ON t.id=tg.transporterid
    LEFT JOIN balanced_bales bal ON bal.transporter_growersid=tg.id
    WHERE ($spid=0 OR g.selling_pointid=$spid OR tg.selling_pointid=$spid)
    ORDER BY tg.booked DESC, g.grower_num
    LIMIT 15
");
if($q&&$q!==true) while($r=$q->fetch_assoc())
    echo "  tgid={$r['tgid']} grower={$r['grower_num']} booked={$r['booked']} tg_spid={$r['tg_spid']} g_spid={$r['g_spid']} balanced={$r['is_balanced']}\n";
else echo "  error: ".$conn->error."\n";

echo "</pre>";
