<?php
require_once __DIR__ . '/includes/admin_gate.php';
require_once "includes/conn.php";
header('Content-Type: text/html');
?>
<pre style="font:13px monospace;background:#111;color:#9c9;padding:20px">
<?php
// Show all balanced batches first
echo "=== ALL BALANCED BATCHES ===\n";
$all = $conn->query("SELECT bb.id, bb.splitid, bb.transporter_growersid,
    g.grower_num, g.name,
    dt.mass, dt.usd_value, dt.sold_bales
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    ORDER BY bb.id DESC LIMIT 10");
while($r=$all->fetch_assoc()){
    echo "bbid={$r['id']} grower={$r['grower_num']} {$r['name']} split={$r['splitid']} mass={$r['mass']} usd={$r['usd_value']} sold={$r['sold_bales']}\n";
    echo "  -> http://localhost:8080/check_balance.php?id={$r['id']}\n";
}

$bbid = (int)($_GET['id'] ?? 0);
if(!$bbid){ echo "\n\nClick a link above or add ?id=N to URL\n"; exit; }

echo "\n=== BBID $bbid ===\n";
$dt=$conn->query("SELECT * FROM daily_totals WHERE balanced_balesid=$bbid")->fetch_assoc();
echo "daily_totals: mass={$dt['mass']} usd={$dt['usd_value']} sold={$dt['sold_bales']} rej={$dt['rejected_bales']} avg={$dt['average_price']}\n";

$bb=$conn->query("SELECT * FROM balanced_bales WHERE id=$bbid")->fetch_assoc();
$tgid=$bb['transporter_growersid']; $sid=$bb['splitid'];
echo "balanced_bales: tgid=$tgid split=$sid\n";

$bq=$conn->query("
    SELECT bm.mass, sb.price, sb.sale_code, tk.id tk_id, sb.id sold_id
    FROM bale_junus bj
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN tickets tk ON tk.bale_junusid=bj.id
    LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
    WHERE bj.transporter_growersid=$tgid AND bj.splitid=$sid
");
$gross=0;$mass=0;$sold=0;$rej=0;
echo "\nBales:\n";
while($b=$bq->fetch_assoc()){
    $sc=strtoupper(trim($b['sale_code']??''));
    $is_rej=in_array($sc,['R','CO','RO']);
    $p=(float)$b['price'];if($p>10)$p/=100;
    $val=$is_rej?0:$p*(float)$b['mass'];
    if($b['sold_id']&&!$is_rej){$gross+=$val;$mass+=(float)$b['mass'];$sold++;}
    if($is_rej)$rej++;
    echo "  mass={$b['mass']} price={$b['price']} sc='$sc' sold={$b['sold_id']} val=$val\n";
}
echo "\nExpected: gross=$gross mass=$mass sold=$sold rej=$rej\n";
echo "Stored  : gross={$dt['usd_value']} mass={$dt['mass']} sold={$dt['sold_bales']} rej={$dt['rejected_bales']}\n";
?>
</pre>