<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

// Always show errors so we know what's wrong
ini_set('display_errors', 1);
error_reporting(E_ALL);

$page_title  = "Reports";
$active_page = "reports";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$is_admin    = !empty($_SESSION['rights']['admin']);
$today       = date('Y-m-d');

// ── Filters ────────────────────────────────────────────────────────────────
$date_from  = $conn->real_escape_string($_GET['from']  ?? date('Y-m-01'));
$date_to    = $conn->real_escape_string($_GET['to']    ?? $today);
$filter_loc = (int)($_GET['locid']   ?? 0);
$filter_bid = (int)($_GET['buyerid'] ?? 0);

// Non-admins locked to their floor
$filter_spid = $is_admin ? (int)($_GET['spid'] ?? $spid) : $spid;
if (!$filter_spid) $filter_spid = $spid;

$sp_scope      = "g.selling_pointid = $filter_spid";
$loc_clause    = $filter_loc ? "AND t.location_id = $filter_loc" : "";
$buyer_clause  = $filter_bid ? "AND b.id = $filter_bid" : "";

// ── Safe query helper ──────────────────────────────────────────────────────
function qry($conn, $sql) {
    $r = $conn->query($sql);
    if ($r === false) {
        error_log("reports.php SQL error: " . $conn->error);
        return null;
    }
    return $r;
}
function qrow($conn, $sql) {
    $r = qry($conn, $sql);
    return $r ? ($r->fetch_assoc() ?? []) : [];
}
function qall($conn, $sql) {
    $r = qry($conn, $sql);
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

// ── Check which optional tables exist ─────────────────────────────────────
$has_locs = $conn->query("SHOW TABLES LIKE 'bale_locations'")->num_rows > 0;
$has_dtn  = $conn->query("SHOW TABLES LIKE 'daily_totals_net_amount'")->num_rows > 0;

$net_col  = $has_dtn ? "SUM(dtn.usd_net_value)" : "SUM(dt.usd_value)";
$net_join = $has_dtn ? "LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid = bb.id" : "";
$loc_join = $has_locs
    ? "JOIN transporter t ON t.id = tg.transporterid LEFT JOIN bale_locations bl ON bl.id = t.location_id"
    : "JOIN transporter t ON t.id = tg.transporterid";
$bl_sel   = $has_locs ? ", bl.code loc_code, bl.name loc_name" : ", NULL loc_code, NULL loc_name";
$bl_where = ($has_locs && $filter_loc) ? "AND t.location_id = $filter_loc" : "";

// ── Grand totals ───────────────────────────────────────────────────────────
$gt = qrow($conn, "
    SELECT
        COUNT(DISTINCT bb.id)           AS batches,
        COALESCE(SUM(dt.sold_bales), 0) AS bales,
        COALESCE(SUM(dt.mass), 0)       AS mass,
        COALESCE(SUM(dt.usd_value), 0)  AS gross_usd,
        COALESCE($net_col, 0)           AS net_usd
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g              ON g.id  = tg.growerid
    $loc_join
    LEFT JOIN daily_totals dt   ON dt.balanced_balesid = bb.id
    $net_join
    WHERE bb.stoporder_processed = 1
      AND $sp_scope
      $bl_where
      AND DATE(dt.created_at) BETWEEN '$date_from' AND '$date_to'
");

// ── Daily trend ────────────────────────────────────────────────────────────
$daily_rows = qall($conn, "
    SELECT
        DATE(dt.created_at)             AS sale_date,
        COUNT(DISTINCT bb.id)           AS batches,
        COALESCE(SUM(dt.sold_bales), 0) AS bales,
        COALESCE(SUM(dt.mass), 0)       AS mass,
        COALESCE(SUM(dt.usd_value), 0)  AS gross_usd
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g              ON g.id  = tg.growerid
    $loc_join
    LEFT JOIN daily_totals dt   ON dt.balanced_balesid = bb.id
    WHERE bb.stoporder_processed = 1
      AND $sp_scope
      $bl_where
      AND DATE(dt.created_at) BETWEEN '$date_from' AND '$date_to'
    GROUP BY DATE(dt.created_at)
    ORDER BY sale_date ASC
");

// ── By buyer ───────────────────────────────────────────────────────────────
$buyer_rows = qall($conn, "
    SELECT
        COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name, b.buyer_code,
        COUNT(sb.id)                 AS bales,
        COALESCE(SUM(bm.mass), 0)   AS mass,
        COALESCE(SUM(
            CASE WHEN sb.price > 10 THEN sb.price/100 ELSE sb.price END
            * bm.mass
        ), 0) AS gross_usd,
        AVG(CASE WHEN sb.price > 10 THEN sb.price/100 ELSE sb.price END) AS avg_price
    FROM sold_bales sb
    JOIN tickets tk       ON tk.id  = sb.ticketsid
    JOIN bale_junus bj    ON bj.id  = tk.bale_junusid
    JOIN bale_mass bm     ON bm.id  = bj.bale_massid
    JOIN growers g        ON g.id   = bj.growerid
    JOIN buyer_grades bg  ON bg.id  = sb.buyer_gradesid
    JOIN buyer b          ON b.id   = bg.buyerid
    JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
    JOIN transporter t    ON t.id   = tg.transporterid
    WHERE $sp_scope
      $bl_where
      $buyer_clause
      AND DATE(sb.created_at) BETWEEN '$date_from' AND '$date_to'
    GROUP BY b.id
    ORDER BY gross_usd DESC
");

// ── By TIMB grade ──────────────────────────────────────────────────────────
$grade_rows = qall($conn, "
    SELECT
        tg2.description AS grade,
        COUNT(sb.id)                AS bales,
        COALESCE(SUM(bm.mass), 0)  AS mass,
        AVG(CASE WHEN sb.price > 10 THEN sb.price/100 ELSE sb.price END) AS avg_price,
        MAX(CASE WHEN sb.price > 10 THEN sb.price/100 ELSE sb.price END) AS max_price
    FROM sold_bales sb
    JOIN tickets tk    ON tk.id  = sb.ticketsid
    JOIN bale_junus bj ON bj.id  = tk.bale_junusid
    JOIN bale_mass bm  ON bm.id  = bj.bale_massid
    JOIN growers g     ON g.id   = bj.growerid
    JOIN timb_grades tg2 ON tg2.id = sb.timb_gradesid
    WHERE $sp_scope
      AND DATE(sb.created_at) BETWEEN '$date_from' AND '$date_to'
    GROUP BY tg2.id
    ORDER BY bales DESC
    LIMIT 20
");

// ── By province ────────────────────────────────────────────────────────────
$prov_rows = qall($conn, "
    SELECT
        COALESCE(NULLIF(TRIM(g.province), ''), 'Unknown') AS province,
        COALESCE(SUM(dt.sold_bales), 0) AS bales,
        COALESCE(SUM(dt.usd_value), 0)  AS gross_usd
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g              ON g.id  = tg.growerid
    LEFT JOIN daily_totals dt   ON dt.balanced_balesid = bb.id
    WHERE bb.stoporder_processed = 1
      AND $sp_scope
      AND DATE(dt.created_at) BETWEEN '$date_from' AND '$date_to'
    GROUP BY province
    ORDER BY gross_usd DESC
    LIMIT 12
");

// ── By location ────────────────────────────────────────────────────────────
$loc_rows = [];
if ($has_locs) {
    $loc_rows = qall($conn, "
        SELECT
            bl.code, bl.name loc_name, bl.subcontractor,
            COUNT(DISTINCT bb.id)           AS batches,
            COALESCE(SUM(dt.sold_bales), 0) AS bales,
            COALESCE(SUM(dt.mass), 0)       AS mass,
            COALESCE(SUM(dt.usd_value), 0)  AS gross_usd
        FROM bale_locations bl
        LEFT JOIN transporter t  ON t.location_id = bl.id
        LEFT JOIN transporter_growers tg ON tg.transporterid = t.id
        LEFT JOIN balanced_bales bb ON bb.transporter_growersid = tg.id
        LEFT JOIN daily_totals dt   ON dt.balanced_balesid = bb.id
        LEFT JOIN transporter_growers tg2 ON tg2.id = bb.transporter_growersid
        LEFT JOIN growers g ON g.id = tg2.growerid
        WHERE bl.selling_pointid = $filter_spid
          AND (bb.id IS NULL OR bb.stoporder_processed = 1)
          AND (dt.id IS NULL OR DATE(dt.created_at) BETWEEN '$date_from' AND '$date_to')
        GROUP BY bl.id
        ORDER BY gross_usd DESC
    ");
}

// ── Top growers ────────────────────────────────────────────────────────────
$grower_rows = qall($conn, "
    SELECT
        g.grower_num, g.name, g.surname, g.province
        $bl_sel,
        COUNT(DISTINCT bb.id)           AS batches,
        COALESCE(SUM(dt.sold_bales), 0) AS bales,
        COALESCE(SUM(dt.mass), 0)       AS mass,
        COALESCE(SUM(dt.usd_value), 0)  AS gross_usd,
        COALESCE($net_col, 0)           AS net_usd,
        COALESCE(AVG(dt.average_price), 0) AS avg_price
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g              ON g.id  = tg.growerid
    $loc_join
    LEFT JOIN daily_totals dt   ON dt.balanced_balesid = bb.id
    $net_join
    WHERE bb.stoporder_processed = 1
      AND $sp_scope
      $bl_where
      AND DATE(dt.created_at) BETWEEN '$date_from' AND '$date_to'
    GROUP BY g.id
    ORDER BY gross_usd DESC
    LIMIT 200
");

// ── Filter dropdowns ───────────────────────────────────────────────────────
$all_sp   = qall($conn, "SELECT id, name FROM selling_point WHERE active = 1 ORDER BY name");
$all_locs = $has_locs
    ? qall($conn, "SELECT id, code, name FROM bale_locations WHERE selling_pointid = $filter_spid AND active = 1 ORDER BY code")
    : [];
$all_buyers = qall($conn, "SELECT id, name FROM buyer WHERE selling_pointid = $filter_spid ORDER BY name");

// ── Season total for context ───────────────────────────────────────────────
$season = qrow($conn, "
    SELECT COALESCE(SUM(dt.sold_bales), 0) AS bales,
           COALESCE(SUM(dt.usd_value), 0)  AS gross_usd
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g              ON g.id  = tg.growerid
    LEFT JOIN daily_totals dt   ON dt.balanced_balesid = bb.id
    WHERE bb.stoporder_processed = 1 AND $sp_scope
");

// ── Computed values ────────────────────────────────────────────────────────
$gt_bales   = (int)($gt['bales']    ?? 0);
$gt_mass    = (float)($gt['mass']   ?? 0);
$gt_gross   = (float)($gt['gross_usd'] ?? 0);
$gt_net     = (float)($gt['net_usd']   ?? 0);
$gt_batches = (int)($gt['batches']  ?? 0);
$avg_price  = $gt_mass > 0 ? $gt_gross / $gt_mass : 0;

// ── Chart data arrays ──────────────────────────────────────────────────────
$daily_labels = $daily_usd = $daily_bales = $daily_mass = [];
foreach ($daily_rows as $d) {
    $daily_labels[] = date('d M', strtotime($d['sale_date']));
    $daily_usd[]    = round((float)$d['gross_usd'], 2);
    $daily_bales[]  = (int)$d['bales'];
    $daily_mass[]   = round((float)$d['mass'], 0);
}

$buyer_labels = $buyer_usd = $buyer_bales = [];
foreach ($buyer_rows as $b) {
    $buyer_labels[] = $b['buyer_name'];
    $buyer_usd[]    = round((float)$b['gross_usd'], 2);
    $buyer_bales[]  = (int)$b['bales'];
}

$grade_labels = $grade_bales = $grade_price = [];
foreach ($grade_rows as $gr) {
    $grade_labels[] = $gr['grade'];
    $grade_bales[]  = (int)$gr['bales'];
    $grade_price[]  = round((float)$gr['avg_price'], 4);
}

$prov_labels = $prov_usd = [];
foreach ($prov_rows as $p) {
    $prov_labels[] = $p['province'];
    $prov_usd[]    = round((float)$p['gross_usd'], 2);
}

$loc_chart_labels = $loc_chart_usd = $loc_chart_bales = [];
foreach ($loc_rows as $l) {
    if ((float)$l['gross_usd'] <= 0) continue;
    $loc_chart_labels[] = $l['code'];
    $loc_chart_usd[]    = round((float)$l['gross_usd'], 2);
    $loc_chart_bales[]  = (int)$l['bales'];
}

// ── Exports ────────────────────────────────────────────────────────────────
$export = $_GET['export'] ?? '';
$etype  = $_GET['etype']  ?? 'growers';

if ($export === 'print') {
    $sp_name = htmlspecialchars($_SESSION['selling_point_name'] ?? 'Flowork');
    ?><!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>Sales Report — <?=htmlspecialchars((string)$date_from)?> to <?=htmlspecialchars((string)$date_to)?></title>
    <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Segoe UI',sans-serif;font-size:10px;color:#000;padding:12mm}
    h1{font-size:15px;font-weight:700;margin-bottom:2px}
    h2{font-size:9px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;
       margin:14px 0 4px;padding-bottom:3px;border-bottom:1px solid #ccc;color:#444}
    .hdr{display:flex;justify-content:space-between;margin-bottom:10px;
         padding-bottom:8px;border-bottom:2px solid #000}
    .kpi-row{display:flex;gap:14px;margin-bottom:10px;flex-wrap:wrap}
    .kpi{background:#f5f5f5;padding:5px 9px;border-radius:3px;min-width:90px}
    .kpi-l{font-size:7.5px;text-transform:uppercase;letter-spacing:.06em;color:#666;margin-bottom:1px}
    .kpi-v{font-size:13px;font-weight:700}
    table{width:100%;border-collapse:collapse;font-size:9px;margin-bottom:12px}
    th{background:#1a1208;color:#fff;padding:4px 6px;text-align:left;
       font-size:8px;letter-spacing:.05em;text-transform:uppercase}
    td{padding:3px 6px;border-bottom:1px solid #e0e0e0}
    tr:nth-child(even) td{background:#f9f9f9}
    .tr{text-align:right}.bold{font-weight:700}
    tfoot td{font-weight:700;border-top:2px solid #000;background:#f0f0f0;padding:4px 6px}
    @media print{body{padding:8mm}}
    </style></head><body>
    <div class="hdr">
      <div><h1>Sales Report</h1><div style="font-size:9px;color:#555"><?=$sp_name?></div></div>
      <div style="text-align:right;font-size:9px">
        <div><?=$date_from===$date_to?date('d F Y',strtotime($date_from)):$date_from.' → '.$date_to?></div>
        <div>Generated: <?=date('d M Y H:i')?></div>
      </div>
    </div>
    <div class="kpi-row">
      <div class="kpi"><div class="kpi-l">Bales</div><div class="kpi-v"><?=number_format($gt_bales)?></div></div>
      <div class="kpi"><div class="kpi-l">Mass kg</div><div class="kpi-v"><?=number_format($gt_mass,0)?></div></div>
      <div class="kpi"><div class="kpi-l">Gross USD</div><div class="kpi-v">$<?=number_format($gt_gross,2)?></div></div>
      <div class="kpi"><div class="kpi-l">Net USD</div><div class="kpi-v">$<?=number_format($gt_net,2)?></div></div>
      <div class="kpi"><div class="kpi-l">Avg $/kg</div><div class="kpi-v">$<?=number_format($avg_price,4)?></div></div>
      <div class="kpi"><div class="kpi-l">Sell Days</div><div class="kpi-v"><?=count($daily_labels)?></div></div>
    </div>
    <?php if(!empty($buyer_rows)):?>
    <h2>By Buyer</h2>
    <table><thead><tr><th>Buyer</th><th>Code</th><th class="tr">Bales</th>
    <th class="tr">Mass kg</th><th class="tr">Gross USD</th><th class="tr">Avg $/kg</th><th class="tr">% Share</th>
    </tr></thead><tbody>
    <?php $gb=array_sum(array_column($buyer_rows,'gross_usd'))?:1;
    foreach($buyer_rows as $b):$pct=round((float)$b['gross_usd']/$gb*100,1);?>
    <tr><td class="bold"><?=htmlspecialchars($b['buyer_name'])?></td>
    <td><?=htmlspecialchars($b['buyer_code']??'')?></td>
    <td class="tr"><?=number_format((int)$b['bales'])?></td>
    <td class="tr"><?=number_format((float)$b['mass'],0)?></td>
    <td class="tr bold">$<?=number_format((float)$b['gross_usd'],2)?></td>
    <td class="tr">$<?=number_format((float)$b['avg_price'],4)?></td>
    <td class="tr"><?=$pct?>%</td></tr>
    <?php endforeach;?></tbody>
    <tfoot><tr><td colspan="2">TOTAL</td>
    <td class="tr"><?=number_format($gt_bales)?></td>
    <td class="tr"><?=number_format($gt_mass,0)?></td>
    <td class="tr">$<?=number_format($gt_gross,2)?></td>
    <td></td><td class="tr">100%</td></tr></tfoot></table>
    <?php endif;?>
    <?php if(!empty($grade_rows)):?>
    <h2>By TIMB Grade</h2>
    <table><thead><tr><th>Grade</th><th class="tr">Bales</th><th class="tr">Mass kg</th>
    <th class="tr">Avg $/kg</th><th class="tr">Max $/kg</th></tr></thead><tbody>
    <?php foreach($grade_rows as $gr):?>
    <tr><td><?=htmlspecialchars($gr['grade'])?></td>
    <td class="tr"><?=number_format((int)$gr['bales'])?></td>
    <td class="tr"><?=number_format((float)$gr['mass'],0)?></td>
    <td class="tr">$<?=number_format((float)$gr['avg_price'],4)?></td>
    <td class="tr">$<?=number_format((float)$gr['max_price'],4)?></td></tr>
    <?php endforeach;?></tbody></table>
    <?php endif;?>
    <h2>Top Growers (top 100)</h2>
    <table><thead><tr><th>#</th><th>Grower #</th><th>Name</th><th>Province</th>
    <?php if($has_locs):?><th>Loc</th><?php endif;?>
    <th class="tr">Bales</th><th class="tr">Mass kg</th>
    <th class="tr">Gross USD</th><th class="tr">Net USD</th><th class="tr">$/kg</th>
    </tr></thead><tbody>
    <?php $rk=0;foreach(array_slice($grower_rows,0,100) as $g):$rk++;
    $ap=(float)$g['avg_price'];if($ap>10)$ap/=100;?>
    <tr><td><?=$rk?></td><td class="bold"><?=htmlspecialchars($g['grower_num'])?></td>
    <td><?=htmlspecialchars(trim($g['name'].' '.$g['surname']))?></td>
    <td><?=htmlspecialchars($g['province']??'')?></td>
    <?php if($has_locs):?><td><?=htmlspecialchars($g['loc_code']??'')?></td><?php endif;?>
    <td class="tr"><?=number_format((int)$g['bales'])?></td>
    <td class="tr"><?=number_format((float)$g['mass'],0)?></td>
    <td class="tr bold">$<?=number_format((float)$g['gross_usd'],2)?></td>
    <td class="tr">$<?=number_format((float)$g['net_usd'],2)?></td>
    <td class="tr">$<?=number_format($ap,4)?></td></tr>
    <?php endforeach;?></tbody></table>
    <script>window.onload=()=>window.print()</script>
    </body></html><?php exit;
}

if ($export === 'csv') {
    $fname = 'flowork_'.$etype.'_'.$date_from.'_'.$date_to.'.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fname.'"');

    switch ($etype) {
        case 'buyers':
            echo "Buyer,Code,Bales,Mass kg,Gross USD,Avg \$/kg,% Share\r\n";
            $gb = array_sum(array_column($buyer_rows,'gross_usd')) ?: 1;
            foreach ($buyer_rows as $b) {
                echo implode(',', [
                    '"'.addslashes($b['buyer_name']).'"',
                    '"'.addslashes($b['buyer_code']??'').'"',
                    (int)$b['bales'],
                    number_format((float)$b['mass'],0),
                    number_format((float)$b['gross_usd'],2),
                    number_format((float)$b['avg_price'],4),
                    round((float)$b['gross_usd']/$gb*100,1).'%',
                ])."\r\n";
            }
            break;
        case 'grades':
            echo "Grade,Bales,Mass kg,Avg \$/kg,Max \$/kg\r\n";
            foreach ($grade_rows as $gr) {
                echo implode(',', [
                    '"'.addslashes($gr['grade']).'"',
                    (int)$gr['bales'],
                    number_format((float)$gr['mass'],0),
                    number_format((float)$gr['avg_price'],4),
                    number_format((float)$gr['max_price'],4),
                ])."\r\n";
            }
            break;
        case 'locations':
            echo "Code,Location,Subcontractor,Batches,Bales,Mass kg,Gross USD\r\n";
            foreach ($loc_rows as $l) {
                echo implode(',', [
                    '"'.addslashes($l['code']).'"',
                    '"'.addslashes($l['loc_name']).'"',
                    '"'.addslashes($l['subcontractor']??'').'"',
                    (int)$l['batches'], (int)$l['bales'],
                    number_format((float)$l['mass'],0),
                    number_format((float)$l['gross_usd'],2),
                ])."\r\n";
            }
            break;
        case 'provinces':
            echo "Province,Bales,Gross USD\r\n";
            foreach ($prov_rows as $p) {
                echo implode(',', [
                    '"'.addslashes($p['province']).'"',
                    (int)$p['bales'],
                    number_format((float)$p['gross_usd'],2),
                ])."\r\n";
            }
            break;
        case 'daily':
            echo "Date,Batches,Bales,Mass kg,Gross USD\r\n";
            foreach ($daily_rows as $d) {
                echo implode(',', [
                    $d['sale_date'], (int)$d['batches'],
                    (int)$d['bales'],
                    number_format((float)$d['mass'],0),
                    number_format((float)$d['gross_usd'],2),
                ])."\r\n";
            }
            break;
        case 'summary':
            echo "SALES REPORT SUMMARY\r\n";
            echo "Period,\"$date_from to $date_to\"\r\n";
            echo "Generated,\"".date('d M Y H:i')."\"\r\n\r\n";
            echo "OVERVIEW\r\n";
            echo "Bales Sold,".number_format($gt_bales)."\r\n";
            echo "Total Mass kg,".number_format($gt_mass,0)."\r\n";
            echo "Gross USD,".number_format($gt_gross,2)."\r\n";
            echo "Net USD,".number_format($gt_net,2)."\r\n";
            echo "Avg \$/kg,".number_format($avg_price,4)."\r\n";
            echo "Sell Days,".count($daily_labels)."\r\n\r\n";
            echo "BY BUYER\r\n";
            echo "Buyer,Bales,Gross USD,% Share\r\n";
            $gb = array_sum(array_column($buyer_rows,'gross_usd')) ?: 1;
            foreach ($buyer_rows as $b) {
                echo '"'.addslashes($b['buyer_name']).'",'.(int)$b['bales'].',';
                echo number_format((float)$b['gross_usd'],2).',';
                echo round((float)$b['gross_usd']/$gb*100,1)."% \r\n";
            }
            echo "\r\nBY TIMB GRADE\r\n";
            echo "Grade,Bales,Mass kg,Avg \$/kg\r\n";
            foreach ($grade_rows as $gr) {
                echo '"'.addslashes($gr['grade']).'",'.(int)$gr['bales'].',';
                echo number_format((float)$gr['mass'],0).','.number_format((float)$gr['avg_price'],4)."\r\n";
            }
            echo "\r\nTOP GROWERS\r\n";
            echo "Grower #,Name,Province,Bales,Gross USD,Net USD,Avg \$/kg\r\n";
            foreach ($grower_rows as $g) {
                $ap=(float)$g['avg_price'];if($ap>10)$ap/=100;
                echo $g['grower_num'].','.'"'.trim($g['name'].' '.$g['surname']).'",'.'"'.($g['province']??'').'",'.(int)$g['bales'].',';
                echo number_format((float)$g['gross_usd'],2).','.number_format((float)$g['net_usd'],2).','.number_format($ap,4)."\r\n";
            }
            break;
        default: // growers
            echo "Grower #,Name,Province,Location,Batches,Bales,Mass kg,Gross USD,Net USD,Avg \$/kg\r\n";
            foreach ($grower_rows as $r) {
                $avg = (float)$r['avg_price']; if ($avg > 10) $avg /= 100;
                echo implode(',', [
                    '"'.$r['grower_num'].'"',
                    '"'.trim($r['name'].' '.$r['surname']).'"',
                    '"'.($r['province']??'').'"',
                    '"'.($r['loc_code']??'').'"',
                    $r['batches'], $r['bales'],
                    number_format((float)$r['mass'],0),
                    number_format((float)$r['gross_usd'],2),
                    number_format((float)$r['net_usd'],2),
                    number_format($avg,4),
                ])."\r\n";
            }
    }
    exit;
}

include "includes/layout.php";
?>

<!-- Filter bar -->
<div class="card mb-3">
<div class="card-body" style="padding:.7rem 1.15rem">
<form method="GET" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">

<?php if ($is_admin && !empty($all_sp)): ?>
<div class="form-group" style="margin:0">
  <label style="font-size:.62rem">Floor</label>
  <select name="spid" class="form-control" style="font-size:.8rem;padding:.38rem .55rem" onchange="this.form.submit()">
    <?php foreach ($all_sp as $s): ?>
    <option value="<?=$s['id']?>" <?=$filter_spid==$s['id']?'selected':''?>><?=htmlspecialchars($s['name'])?></option>
    <?php endforeach; ?>
  </select>
</div>
<?php endif; ?>

<?php if (!empty($all_locs)): ?>
<div class="form-group" style="margin:0">
  <label style="font-size:.62rem">Location</label>
  <select name="locid" class="form-control" style="font-size:.8rem;padding:.38rem .55rem" onchange="this.form.submit()">
    <option value="0">All locations</option>
    <?php foreach ($all_locs as $l): ?>
    <option value="<?=$l['id']?>" <?=$filter_loc==$l['id']?'selected':''?>><?=htmlspecialchars($l['code'].' — '.$l['name'])?></option>
    <?php endforeach; ?>
  </select>
</div>
<?php endif; ?>

<?php if (!empty($all_buyers)): ?>
<div class="form-group" style="margin:0">
  <label style="font-size:.62rem">Buyer</label>
  <select name="buyerid" class="form-control" style="font-size:.8rem;padding:.38rem .55rem" onchange="this.form.submit()">
    <option value="0">All buyers</option>
    <?php foreach ($all_buyers as $b): ?>
    <option value="<?=$b['id']?>" <?=$filter_bid==$b['id']?'selected':''?>><?=htmlspecialchars($b['name'])?></option>
    <?php endforeach; ?>
  </select>
</div>
<?php endif; ?>

<div class="form-group" style="margin:0">
  <label style="font-size:.62rem">From</label>
  <input type="date" name="from" class="form-control" value="<?=htmlspecialchars((string)$date_from)?>" style="font-size:.8rem;padding:.38rem .55rem">
</div>
<div class="form-group" style="margin:0">
  <label style="font-size:.62rem">To</label>
  <input type="date" name="to" class="form-control" value="<?=htmlspecialchars((string)$date_to)?>" style="font-size:.8rem;padding:.38rem .55rem">
</div>

<div style="display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap">
  <button type="submit" class="btn btn-primary">Apply</button>
  <a href="reports.php" class="btn btn-ghost">Reset</a>
  <a href="export_all.php?page=reports&from=<?=htmlspecialchars((string)$date_from)?>&to=<?=htmlspecialchars((string)$date_to)?>&spid=<?=$filter_spid?>&locid=<?=$filter_loc?>&buyerid=<?=$filter_bid?>"
     class="btn btn-primary" title="Download all reports as a ZIP file">
    📦 Export All ZIP
  </a>
  <div style="position:relative" id="exp-wrap">
    <button type="button" class="btn btn-ghost" onclick="var m=document.getElementById('exp-menu');m.style.display=m.style.display==='none'?'block':'none'"
            style="display:flex;align-items:center;gap:5px">
      ⬇ Export <span style="font-size:.65rem;opacity:.6">▾</span>
    </button>
    <div id="exp-menu" style="display:none;position:absolute;top:calc(100% + 4px);right:0;
         background:var(--cream);border:1px solid var(--border);border-radius:var(--radius);
         box-shadow:0 6px 20px rgba(0,0,0,.15);min-width:220px;z-index:999;overflow:hidden">
      <div style="padding:.4rem .75rem;font-size:.62rem;font-weight:600;letter-spacing:.08em;
                  text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);
                  background:var(--cream-d)">CSV Downloads</div>
      <?php
      $ep = 'from='.urlencode($date_from).'&to='.urlencode($date_to).'&spid='.$filter_spid.'&locid='.$filter_loc.'&buyerid='.$filter_bid;
      $opts = [
        ['growers',   '👤 Growers',            'Top 200 growers by revenue'],
        ['buyers',    '🏢 By Buyer',            'Revenue split by buyer'],
        ['grades',    '🌿 By TIMB Grade',       'Volume and pricing by grade'],
        ['locations', '📍 By Location',         'Performance by location'],
        ['provinces', '🗺  By Province',         'Revenue by province'],
        ['daily',     '📅 Daily Breakdown',     'Day-by-day trend'],
        ['summary',   '📊 Full Summary',        'All sections in one file'],
      ];
      foreach ($opts as [$et,$lbl,$desc]):?>
      <a href="?<?=$ep?>&export=csv&etype=<?=$et?>"
         style="display:block;padding:.5rem .85rem;text-decoration:none;color:var(--ink);
                font-size:.8rem;border-bottom:1px solid var(--border)"
         onmouseover="this.style.background='var(--cream-d)'"
         onmouseout="this.style.background=''">
        <?=$lbl?>
        <span style="display:block;font-size:.65rem;color:var(--muted)"><?=$desc?></span>
      </a>
      <?php endforeach;?>
      <div style="padding:.4rem .75rem;font-size:.62rem;font-weight:600;letter-spacing:.08em;
                  text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);
                  background:var(--cream-d)">Print</div>
      <a href="?<?=$ep?>&export=print" target="_blank"
         style="display:block;padding:.5rem .85rem;text-decoration:none;color:var(--ink);font-size:.8rem"
         onmouseover="this.style.background='var(--cream-d)'"
         onmouseout="this.style.background=''">
        🖨 Print / Save as PDF
        <span style="display:block;font-size:.65rem;color:var(--muted)">Full report — opens print dialog</span>
      </a>
    </div>
  </div>
</div>
<script>
document.addEventListener('click',function(e){
  var w=document.getElementById('exp-wrap');
  if(w&&!w.contains(e.target))document.getElementById('exp-menu').style.display='none';
});
</script>
</form>
</div>
</div>

<!-- KPI strip -->
<div class="kpi-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.5rem">
<div class="kpi-card" style="--kpi-color:var(--gold)">
  <div class="kpi-label">Bales Sold</div>
  <div class="kpi-value"><?=number_format($gt_bales)?></div>
  <?php if((int)($season['bales']??0) > $gt_bales): ?>
  <div class="kpi-sub">Season: <?=number_format((int)$season['bales'])?></div>
  <?php endif; ?>
</div>
<div class="kpi-card">
  <div class="kpi-label">Total Mass</div>
  <div class="kpi-value sm"><?=number_format($gt_mass,0)?> <span style="font-size:.7rem">kg</span></div>
</div>
<div class="kpi-card" style="--kpi-color:var(--gold)">
  <div class="kpi-label">Gross USD</div>
  <div class="kpi-value sm">$<?=number_format($gt_gross,0)?></div>
  <?php if((float)($season['gross_usd']??0) > $gt_gross): ?>
  <div class="kpi-sub">Season: $<?=number_format((float)$season['gross_usd'],0)?></div>
  <?php endif; ?>
</div>
<div class="kpi-card" style="--kpi-color:var(--leaf)">
  <div class="kpi-label">Net USD</div>
  <div class="kpi-value sm">$<?=number_format($gt_net,0)?></div>
  <div class="kpi-sub"><?=$gt_gross>0?round($gt_net/$gt_gross*100,1).'% of gross':'—'?></div>
</div>
<div class="kpi-card">
  <div class="kpi-label">Avg $/kg</div>
  <div class="kpi-value sm">$<?=number_format($avg_price,3)?></div>
  <div class="kpi-sub"><?=count($daily_rows)?> sell days</div>
</div>
</div>

<?php if (empty($daily_rows)): ?>
<div class="card">
  <div class="card-body text-center text-muted" style="padding:3rem">
    <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem">📊</div>
    No data found for the selected period and filters.
  </div>
</div>
<?php include "includes/layout_end.php"; exit; ?>
<?php endif; ?>

<?php $has_gp = !empty($grade_rows) || !empty($prov_rows); ?>
<div class="premium-tabs-nav" data-tabs="reports">
  <button type="button" class="premium-tab-btn active" data-tab="overview">📈 Overview</button>
  <?php if($has_gp): ?>
  <button type="button" class="premium-tab-btn" data-tab="grades">🍂 Grades &amp; Provinces</button>
  <?php endif; ?>
  <button type="button" class="premium-tab-btn" data-tab="locations">📍 Locations &amp; Growers</button>
  <button type="button" class="premium-tab-btn" data-tab="buyers">🏢 Buyer Summary</button>
</div>

<!-- Row 1: Daily trend + Buyer donut -->
<div id="panel-overview" class="tab-panel active" data-tabs="reports">
<div class="grid-2 mb-3">

<div class="card" id="card-trend">
<div class="card-head">
  <h3>Daily Revenue Trend</h3>
  <div style="display:flex;gap:.5rem;align-items:center">
    <div class="flex gap-1" style="margin-right:.25rem">
      <button onclick="setTrend('usd')"   id="btn-usd"   class="btn btn-sm btn-primary"  style="font-size:.68rem">USD</button>
      <button onclick="setTrend('bales')" id="btn-bales" class="btn btn-sm btn-ghost" style="font-size:.68rem">Bales</button>
      <button onclick="setTrend('mass')"  id="btn-mass"  class="btn btn-sm btn-ghost" style="font-size:.68rem">Mass</button>
    </div>
    <a href="?<?=$ep?>&export=csv&etype=daily" class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 7px">📥 CSV</a>
    <button type="button" onclick="printCard('card-trend', 'Daily Revenue Trend')" class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 7px">🖨 PDF</button>
  </div>
</div>
<div style="padding:.85rem 1.15rem 1rem"><canvas id="chartTrend" height="220"></canvas></div>
</div>

<div class="card" id="card-buyer">
<div class="card-head">
  <h3>Revenue by Buyer</h3>
  <div style="display:flex;gap:.35rem;align-items:center">
    <a href="?<?=$ep?>&export=csv&etype=buyers" class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 7px">📥 CSV</a>
    <button type="button" onclick="printCard('card-buyer', 'Revenue by Buyer')" class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 7px">🖨 PDF</button>
  </div>
</div>
<div style="padding:.85rem 1.15rem 1rem;display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:center">
  <canvas id="chartBuyer" height="200"></canvas>
  <div id="buyer-legend" style="font-size:.72rem;line-height:2"></div>
</div>
</div>
</div>

</div><!-- /panel-overview -->

<!-- Row 2: Grade + Province -->
<?php if($has_gp): ?>
<div id="panel-grades" class="tab-panel" data-tabs="reports">
<div class="grid-2 mb-3">

<?php if (!empty($grade_rows)): ?>
<div class="card" id="card-grade">
<div class="card-head">
  <h3>Bales by TIMB Grade</h3>
  <div style="display:flex;gap:.5rem;align-items:center">
    <div class="flex gap-1" style="margin-right:.25rem">
      <button onclick="setGrade('bales')" id="gbtn-bales" class="btn btn-sm btn-primary" style="font-size:.68rem">Volume</button>
      <button onclick="setGrade('price')" id="gbtn-price" class="btn btn-sm btn-ghost"   style="font-size:.68rem">Avg Price</button>
    </div>
    <a href="?<?=$ep?>&export=csv&etype=grades" class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 7px">📥 CSV</a>
    <button type="button" onclick="printCard('card-grade', 'Bales by TIMB Grade')" class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 7px">🖨 PDF</button>
  </div>
</div>
<div style="padding:.85rem 1.15rem 1rem"><canvas id="chartGrade" height="240"></canvas></div>
</div>
<?php endif; ?>

<?php if (!empty($prov_rows)): ?>
<div class="card" id="card-province">
<div class="card-head">
  <h3>Revenue by Province</h3>
  <div style="display:flex;gap:.35rem;align-items:center">
    <a href="?<?=$ep?>&export=csv&etype=provinces" class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 7px">📥 CSV</a>
    <button type="button" onclick="printCard('card-province', 'Revenue by Province')" class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 7px">🖨 PDF</button>
  </div>
</div>
<div style="padding:.85rem 1.15rem 1rem;display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:center">
  <canvas id="chartProv" height="200"></canvas>
  <div id="prov-legend" style="font-size:.72rem;line-height:2"></div>
</div>
</div>
<?php endif; ?>
</div>

</div><!-- /panel-grades -->
<?php endif; ?>

<!-- Row 3: Location + Top growers -->
<div id="panel-locations" class="tab-panel" data-tabs="reports">
<div class="grid-2 mb-3">

<?php if (!empty($loc_rows)): ?>
<div class="card" id="card-location">
<div class="card-head">
  <h3>Location Performance</h3>
  <div style="display:flex;gap:.35rem;align-items:center">
    <a href="?<?=$ep?>&export=csv&etype=locations" class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 7px">📥 CSV</a>
    <button type="button" onclick="printCard('card-location', 'Location Performance')" class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 7px">🖨 PDF</button>
  </div>
</div>
<?php if (!empty($loc_chart_usd)): ?>
<div style="padding:.85rem 1.15rem .5rem"><canvas id="chartLoc" height="130"></canvas></div>
<?php endif; ?>
<div class="table-wrap" style="max-height:280px;overflow-y:auto"><table>
<thead><tr>
  <th>Loc</th><th>Name</th>
  <th class="text-right">Bales</th><th class="text-right">Mass kg</th>
  <th class="text-right">Gross USD</th>
</tr></thead>
<tbody>
<?php $loc_total = array_sum(array_column($loc_rows,'gross_usd')) ?: 1;
foreach ($loc_rows as $l): ?>
<tr>
  <td><span style="font-family:'Bebas Neue',sans-serif;font-size:1.05rem;color:var(--gold)"><?=htmlspecialchars($l['code'])?></span></td>
  <td style="font-size:.78rem">
    <div><?=htmlspecialchars($l['loc_name'])?></div>
    <?php if($l['subcontractor']):?><div class="td-muted" style="font-size:.68rem"><?=htmlspecialchars($l['subcontractor'])?></div><?php endif;?>
  </td>
  <td class="td-mono text-right"><?=number_format((int)$l['bales'])?></td>
  <td class="td-mono text-right"><?=number_format((float)$l['mass'],0)?></td>
  <td class="td-mono text-right fw-500">$<?=number_format((float)$l['gross_usd'],0)?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
</div>
<?php endif; ?>

<!-- Top growers -->
<div class="card" id="card-growers">
<div class="card-head">
  <h3>Top Growers</h3>
  <div style="display:flex;gap:.35rem;align-items:center">
    <a href="?from=<?=htmlspecialchars((string)$date_from)?>&to=<?=htmlspecialchars((string)$date_to)?>&spid=<?=$filter_spid?>&locid=<?=$filter_loc?>&export=csv&etype=growers"
       class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 7px">📥 CSV</a>
    <button type="button" onclick="printCard('card-growers', 'Top Growers')" class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 7px">🖨 PDF</button>
  </div>
</div>
<div class="table-wrap" style="max-height:400px;overflow-y:auto"><table>
<thead><tr>
  <th>#</th><th>Grower</th><th>Prov</th>
  <?php if($has_locs):?><th>Loc</th><?php endif;?>
  <th class="text-right">Bales</th>
  <th class="text-right">Gross USD</th>
  <th class="text-right">Net USD</th>
  <th class="text-right">$/kg</th>
</tr></thead>
<tbody>
<?php $rk=0; foreach ($grower_rows as $g): $rk++;
  $ap = (float)$g['avg_price']; if ($ap > 10) $ap /= 100;
?>
<tr>
  <td class="td-muted" style="font-size:.7rem"><?=$rk?></td>
  <td>
    <span class="badge badge-gold" style="font-size:.62rem"><?=htmlspecialchars($g['grower_num'])?></span>
    <div style="font-size:.75rem;margin-top:1px"><?=htmlspecialchars(trim($g['name'].' '.$g['surname']))?></div>
  </td>
  <td class="td-muted" style="font-size:.72rem"><?=htmlspecialchars($g['province']??'—')?></td>
  <?php if($has_locs):?><td style="font-family:'Bebas Neue',sans-serif;color:var(--gold);font-size:.9rem"><?=htmlspecialchars($g['loc_code']??'—')?></td><?php endif;?>
  <td class="td-mono text-right"><?=number_format((int)$g['bales'])?></td>
  <td class="td-mono text-right fw-500">$<?=number_format((float)$g['gross_usd'],0)?></td>
  <td class="td-mono text-right">$<?=number_format((float)$g['net_usd'],0)?></td>
  <td class="td-mono text-right">$<?=number_format($ap,3)?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
</div>
</div><!-- /grid -->
</div><!-- /panel-locations -->

<!-- Buyer table -->
<div id="panel-buyers" class="tab-panel" data-tabs="reports">
<div class="card mb-3">
<div class="card-head"><h3>Buyer Summary</h3></div>
<div class="table-wrap"><table>
<thead><tr>
  <th>Buyer</th><th>Code</th>
  <th class="text-right">Bales</th><th class="text-right">Mass kg</th>
  <th class="text-right">Gross USD</th><th class="text-right">Avg $/kg</th>
  <th class="text-right">% Share</th>
</tr></thead>
<tbody>
<?php $grand_b = array_sum(array_column($buyer_rows,'gross_usd')) ?: 1;
foreach ($buyer_rows as $b):
  $ap_b = (float)$b['avg_price'];
  $pct  = round((float)$b['gross_usd']/$grand_b*100,1);
?>
<tr>
  <td class="fw-500" style="font-size:.82rem"><?=htmlspecialchars($b['buyer_name'])?></td>
  <td class="td-mono" style="font-size:.72rem"><?=htmlspecialchars($b['buyer_code']??'—')?></td>
  <td class="td-mono text-right"><?=number_format((int)$b['bales'])?></td>
  <td class="td-mono text-right"><?=number_format((float)$b['mass'],0)?></td>
  <td class="td-mono text-right fw-500">$<?=number_format((float)$b['gross_usd'],0)?></td>
  <td class="td-mono text-right">$<?=number_format($ap_b,3)?></td>
  <td class="text-right">
    <div style="display:flex;align-items:center;gap:5px;justify-content:flex-end">
      <div style="width:60px;height:5px;background:var(--border);border-radius:3px">
        <div style="width:<?=min(100,$pct)?>%;height:5px;background:var(--gold);border-radius:3px"></div>
      </div>
      <span style="font-size:.72rem;min-width:32px;text-align:right"><?=$pct?>%</span>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
</div>
</div><!-- /panel-buyers -->

<!-- Charts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#7a6e5f';
Chart.defaults.plugins.legend.display = false;

var isDark = document.body.classList.contains('theme-dark');
var grid   = isDark ? 'rgba(255,255,255,.06)' : 'rgba(26,18,8,.07)';
var tick   = isDark ? 'rgba(255,255,255,.35)' : '#7a6e5f';
var sc     = { grid:{color:grid,drawBorder:false}, ticks:{color:tick} };

var COLORS = ['rgba(200,149,42,.82)','rgba(61,107,53,.82)','rgba(26,107,154,.82)',
              'rgba(192,57,43,.82)','rgba(138,86,164,.82)','rgba(211,84,0,.82)',
              'rgba(23,143,121,.82)','rgba(44,62,80,.82)','rgba(155,89,182,.82)',
              'rgba(52,152,219,.82)','rgba(241,196,15,.82)','rgba(231,76,60,.82)'];

// ── Daily trend ──────────────────────────────────────────────────────────
var trendLabels = <?=json_encode($daily_labels)?>;
var trendData = {
    usd:   <?=json_encode($daily_usd)?>,
    bales: <?=json_encode($daily_bales)?>,
    mass:  <?=json_encode($daily_mass)?>
};
var trendMode = 'usd';
var trendChart = new Chart(document.getElementById('chartTrend'), {
    type: 'bar',
    data: {
        labels: trendLabels,
        datasets: [{
            data: trendData.usd,
            backgroundColor: 'rgba(200,149,42,.7)',
            borderColor: 'rgba(200,149,42,1)',
            borderWidth: 1, borderRadius: 3
        },{
            type: 'line', data: trendData.usd,
            borderColor: 'rgba(61,107,53,.9)', borderWidth: 2,
            pointRadius: 3, tension: .3, fill: false, yAxisID: 'y'
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display:false }, tooltip: { callbacks: {
            label: ctx => trendMode==='usd' ? '$'+ctx.parsed.y.toLocaleString('en',{minimumFractionDigits:2})
                        : trendMode==='mass' ? ctx.parsed.y.toLocaleString()+' kg'
                        : ctx.parsed.y.toLocaleString()+' bales'
        }}},
        scales: { x: sc, y: {...sc, beginAtZero:true} }
    }
});
function setTrend(mode) {
    trendMode = mode;
    ['usd','bales','mass'].forEach(m => {
        var b = document.getElementById('btn-'+m);
        b.className = 'btn btn-sm ' + (m===mode?'btn-primary':'btn-ghost');
        b.style.fontSize = '.68rem';
    });
    trendChart.data.datasets[0].data = trendData[mode];
    trendChart.data.datasets[1].data = trendData[mode];
    trendChart.update();
}

// ── Buyer donut ──────────────────────────────────────────────────────────
<?php if (!empty($buyer_rows)): ?>
var buyerLabels = <?=json_encode($buyer_labels)?>;
var buyerUsd    = <?=json_encode($buyer_usd)?>;
new Chart(document.getElementById('chartBuyer'), {
    type: 'doughnut',
    data: { labels: buyerLabels, datasets: [{
        data: buyerUsd, backgroundColor: COLORS, borderWidth: 2,
        borderColor: isDark ? '#2d2418' : '#fff'
    }]},
    options: { responsive:true, maintainAspectRatio:false, cutout:'60%',
               plugins:{ legend:{display:false}, tooltip:{callbacks:{
                   label: ctx => ' $'+ctx.parsed.toLocaleString('en',{minimumFractionDigits:2})
               }}}}
});
var legEl = document.getElementById('buyer-legend');
var buyerTotal = buyerUsd.reduce((a,b)=>a+b,0)||1;
buyerLabels.forEach(function(lbl,i){
    var pct = Math.round(buyerUsd[i]/buyerTotal*1000)/10;
    legEl.innerHTML += '<div style="display:flex;align-items:center;gap:5px;line-height:1.7">'
        + '<span style="width:9px;height:9px;border-radius:50%;background:'+COLORS[i]+';flex-shrink:0"></span>'
        + '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:110px" title="'+lbl+'">'+lbl+'</span>'
        + '<span style="margin-left:auto;font-weight:500">'+pct+'%</span></div>';
});
<?php endif; ?>

// ── Grade chart ──────────────────────────────────────────────────────────
<?php if (!empty($grade_rows)): ?>
var gradeBales = <?=json_encode($grade_bales)?>;
var gradePrice = <?=json_encode($grade_price)?>;
var gradeChart = new Chart(document.getElementById('chartGrade'), {
    type: 'bar',
    data: { labels: <?=json_encode($grade_labels)?>, datasets: [{
        data: gradeBales,
        backgroundColor: 'rgba(26,107,154,.75)',
        borderColor: 'rgba(26,107,154,1)',
        borderWidth:1, borderRadius:3
    }]},
    options: { responsive:true, maintainAspectRatio:false, indexAxis:'y',
               plugins:{legend:{display:false}},
               scales:{x:{...sc,beginAtZero:true},y:{...sc}} }
});
function setGrade(mode) {
    ['bales','price'].forEach(m => {
        var b = document.getElementById('gbtn-'+m);
        b.className = 'btn btn-sm '+(m===mode?'btn-primary':'btn-ghost');
        b.style.fontSize='.68rem';
    });
    gradeChart.data.datasets[0].data = mode==='bales' ? gradeBales : gradePrice;
    gradeChart.data.datasets[0].backgroundColor = mode==='bales' ? 'rgba(26,107,154,.75)' : 'rgba(200,149,42,.75)';
    gradeChart.data.datasets[0].borderColor = mode==='bales' ? 'rgba(26,107,154,1)' : 'rgba(200,149,42,1)';
    gradeChart.update();
}
<?php endif; ?>

// ── Province donut ───────────────────────────────────────────────────────
<?php if (!empty($prov_rows)): ?>
var provUsd = <?=json_encode($prov_usd)?>;
var provLabels = <?=json_encode($prov_labels)?>;
new Chart(document.getElementById('chartProv'), {
    type:'doughnut',
    data:{ labels:provLabels, datasets:[{
        data:provUsd, backgroundColor:COLORS, borderWidth:2,
        borderColor: isDark?'#2d2418':'#fff'
    }]},
    options:{responsive:true,maintainAspectRatio:false,cutout:'60%',
             plugins:{legend:{display:false},tooltip:{callbacks:{
                 label:ctx=>' $'+ctx.parsed.toLocaleString('en',{minimumFractionDigits:2})
             }}}}
});
var provLeg = document.getElementById('prov-legend');
var provTotal = provUsd.reduce((a,b)=>a+b,0)||1;
provLabels.forEach(function(lbl,i){
    var pct=Math.round(provUsd[i]/provTotal*1000)/10;
    provLeg.innerHTML+='<div style="display:flex;align-items:center;gap:5px;line-height:1.7">'
        +'<span style="width:9px;height:9px;border-radius:50%;background:'+COLORS[i]+';flex-shrink:0"></span>'
        +'<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:110px" title="'+lbl+'">'+lbl+'</span>'
        +'<span style="margin-left:auto;font-weight:500">'+pct+'%</span></div>';
});
<?php endif; ?>

// ── Location bar ─────────────────────────────────────────────────────────
<?php if (!empty($loc_chart_usd)): ?>
new Chart(document.getElementById('chartLoc'), {
    type:'bar',
    data:{ labels:<?=json_encode($loc_chart_labels)?>, datasets:[{
        data:<?=json_encode($loc_chart_usd)?>,
        backgroundColor:'rgba(200,149,42,.7)', borderColor:'rgba(200,149,42,1)',
        borderWidth:1, borderRadius:3
    }]},
    options:{responsive:true,maintainAspectRatio:false,
             plugins:{legend:{display:false}},
             scales:{x:{...sc},y:{...sc,beginAtZero:true,
               ticks:{...sc.ticks,callback:v=>'$'+v.toLocaleString()}}}}
});
<?php endif; ?>
</script>

<?php include "includes/layout_end.php"; ?>