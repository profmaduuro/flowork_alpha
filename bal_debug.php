<?php
require_once __DIR__ . '/includes/admin_gate.php';
// docker cp bal_debug3.php flowork_app:/var/www/html/bal_debug3.php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/conn.php";

echo "<style>body{font:12px monospace;background:#111;color:#ddd;padding:15px}
.ok{color:#6ec97a}.bad{color:#f08080}.warn{color:#e8c87a}
h3{color:#e8c87a;margin:15px 0 5px}
pre{background:#000;padding:8px;color:#9c9;font-size:11px;white-space:pre-wrap;margin:0}
</style>";

$spid = (int)($_SESSION['selling_pointid'] ?? 0);
echo "<h2 style='color:#e8c87a'>Raw Query Debug — spid=$spid</h2>";

// Run each query and show EXACT result + error
function run($conn, $label, $sql) {
    echo "<h3>$label</h3>";
    $q = $conn->query($sql);
    if (!$q || $q === true) {
        echo "<p class='bad'>ERROR: ".htmlspecialchars($conn->error)."</p>";
        echo "<pre>".htmlspecialchars($sql)."</pre>";
        return [];
    }
    $rows = $q->fetch_all(MYSQLI_ASSOC);
    echo "<p class='".($rows?'ok':'warn')."'>".count($rows)." row(s)</p>";
    if ($rows) echo "<pre>".htmlspecialchars(json_encode($rows[0], JSON_PRETTY_PRINT))."</pre>";
    return $rows;
}

run($conn, "1. transporter_growers (no filter)", 
    "SELECT tg.id, tg.growerid, tg.junused_bales, tg.bales FROM transporter_growers tg LIMIT 5");

run($conn, "2. with grower join (no filter)",
    "SELECT tg.id, g.grower_num, g.selling_pointid, tg.junused_bales 
     FROM transporter_growers tg JOIN growers g ON g.id=tg.growerid LIMIT 5");

run($conn, "3. with spid filter (spid=$spid)",
    "SELECT tg.id, g.grower_num, g.selling_pointid, tg.junused_bales 
     FROM transporter_growers tg JOIN growers g ON g.id=tg.growerid
     WHERE (g.selling_pointid=$spid OR $spid=0) LIMIT 5");

run($conn, "4. with transporter join",
    "SELECT tg.id, g.grower_num, tr.name tname
     FROM transporter_growers tg 
     JOIN growers g ON g.id=tg.growerid
     JOIN transporter tr ON tr.id=tg.transporterid
     WHERE (g.selling_pointid=$spid OR $spid=0) LIMIT 5");

run($conn, "5. with bale_junus LEFT JOIN + GROUP BY",
    "SELECT tg.id, g.grower_num, COUNT(DISTINCT bj.id) bj_count
     FROM transporter_growers tg 
     JOIN growers g ON g.id=tg.growerid
     JOIN transporter tr ON tr.id=tg.transporterid
     LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
     WHERE (g.selling_pointid=$spid OR $spid=0)
     GROUP BY tg.id, g.grower_num
     LIMIT 5");

run($conn, "6. add balanced_bales LEFT JOIN",
    "SELECT tg.id, g.grower_num, COUNT(DISTINCT bj.id) bj_count, bb.id bbid
     FROM transporter_growers tg 
     JOIN growers g ON g.id=tg.growerid
     JOIN transporter tr ON tr.id=tg.transporterid
     LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
     LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
     WHERE (g.selling_pointid=$spid OR $spid=0)
     GROUP BY tg.id, g.grower_num, bb.id
     HAVING COUNT(DISTINCT bj.id)>0 OR bb.id IS NOT NULL
     LIMIT 5");

run($conn, "7. add selling_point LEFT JOIN",
    "SELECT tg.id, g.grower_num, COALESCE(sp.floor_code,'VL') floor_code
     FROM transporter_growers tg 
     JOIN growers g ON g.id=tg.growerid
     JOIN transporter tr ON tr.id=tg.transporterid
     LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
     LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
     LEFT JOIN selling_point sp ON sp.id=g.selling_pointid
     WHERE (g.selling_pointid=$spid OR $spid=0)
     GROUP BY tg.id, g.grower_num, sp.floor_code, bb.id
     HAVING COUNT(DISTINCT bj.id)>0 OR bb.id IS NOT NULL
     LIMIT 5");

run($conn, "8. add daily_totals LEFT JOINs",
    "SELECT tg.id, g.grower_num, COALESCE(dt.usd_value,0) gross
     FROM transporter_growers tg 
     JOIN growers g ON g.id=tg.growerid
     JOIN transporter tr ON tr.id=tg.transporterid
     LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
     LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
     LEFT JOIN selling_point sp ON sp.id=g.selling_pointid
     LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
     LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
     LEFT JOIN exchange_rate er ON er.id=dt.exchange_rateid
     WHERE (g.selling_pointid=$spid OR $spid=0)
     GROUP BY tg.id, g.grower_num, sp.floor_code, bb.id,
              dt.usd_value, dt.zim_value, dtn.usd_net_value, dtn.zim_net_value, er.amount
     HAVING COUNT(DISTINCT bj.id)>0 OR bb.id IS NOT NULL
     LIMIT 5");

// Check if bbc table exists before adding
$has_bbc = $conn->query("SHOW TABLES LIKE 'balanced_bales_charges'")->num_rows > 0;
echo "<p class='".($has_bbc?'ok':'warn')."'>balanced_bales_charges: ".($has_bbc?'EXISTS':'MISSING')."</p>";

if ($has_bbc) {
    run($conn, "9. add bbc subquery",
        "SELECT tg.id, g.grower_num, COALESCE(bbc.usd_charges,0) usd_ded
         FROM transporter_growers tg 
         JOIN growers g ON g.id=tg.growerid
         JOIN transporter tr ON tr.id=tg.transporterid
         LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
         LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
         LEFT JOIN selling_point sp ON sp.id=g.selling_pointid
         LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
         LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
         LEFT JOIN exchange_rate er ON er.id=dt.exchange_rateid
         LEFT JOIN (SELECT balanced_balesid, SUM(usd_value) usd_charges, SUM(zim_value) zim_charges
                    FROM balanced_bales_charges GROUP BY balanced_balesid) bbc
              ON bbc.balanced_balesid=bb.id
         WHERE (g.selling_pointid=$spid OR $spid=0)
         GROUP BY tg.id, g.grower_num, sp.floor_code, bb.id,
                  dt.usd_value, dt.zim_value, dtn.usd_net_value, dtn.zim_net_value, er.amount,
                  bbc.usd_charges, bbc.zim_charges
         HAVING COUNT(DISTINCT bj.id)>0 OR bb.id IS NOT NULL
         LIMIT 5");
}

$has_ps = $conn->query("SHOW TABLES LIKE 'payment_status'")->num_rows > 0;
echo "<p class='".($has_ps?'ok':'warn')."'>payment_status: ".($has_ps?'EXISTS':'MISSING')."</p>";

if ($has_ps) {
    run($conn, "10. add payment_status JOIN (currency lowercase)",
        "SELECT tg.id, g.grower_num, COALESCE(ps_u.status,'') usd_status
         FROM transporter_growers tg 
         JOIN growers g ON g.id=tg.growerid
         JOIN transporter tr ON tr.id=tg.transporterid
         LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
         LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
         LEFT JOIN payment_status ps_u ON ps_u.transporter_growersid=tg.id AND ps_u.currency='usd'
         WHERE (g.selling_pointid=$spid OR $spid=0)
         GROUP BY tg.id, g.grower_num, bb.id, ps_u.status
         HAVING COUNT(DISTINCT bj.id)>0 OR bb.id IS NOT NULL
         LIMIT 5");
}

$has_gb = $conn->query("SHOW TABLES LIKE 'grower_banking'")->num_rows > 0;
echo "<p class='".($has_gb?'ok':'warn')."'>grower_banking: ".($has_gb?'EXISTS':'MISSING')."</p>";

if ($has_gb) {
    run($conn, "11. add grower_banking JOIN (currency lowercase)",
        "SELECT tg.id, g.grower_num, COALESCE(gb_u.bank_name,'') bank
         FROM transporter_growers tg 
         JOIN growers g ON g.id=tg.growerid
         JOIN transporter tr ON tr.id=tg.transporterid
         LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
         LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
         LEFT JOIN grower_banking gb_u ON gb_u.growerid=g.id AND gb_u.currency='usd'
         WHERE (g.selling_pointid=$spid OR $spid=0)
         GROUP BY tg.id, g.grower_num, bb.id, gb_u.bank_name
         HAVING COUNT(DISTINCT bj.id)>0 OR bb.id IS NOT NULL
         LIMIT 5");
}

echo "<hr><p style='color:#555'>Delete bal_debug3.php when done.</p>";
