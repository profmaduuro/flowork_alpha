<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title = "Purchase Summary"; $active_page = "purchase_summary";
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$export = $_GET['export'] ?? '';

// ── Filters ───────────────────────────────────────────────────────────────
$date_from = $conn->real_escape_string(trim($_GET['from'] ?? date('Y-m-d')));
$date_to   = $conn->real_escape_string(trim($_GET['to']   ?? date('Y-m-d')));
// Clamp: to >= from
if ($date_to < $date_from) $date_to = $date_from;
$range_label = $date_from === $date_to
    ? date('d F Y', strtotime($date_from))
    : date('d M Y', strtotime($date_from)).' – '.date('d M Y', strtotime($date_to));

// ── Company name ──────────────────────────────────────────────────────────
$co_q    = $conn->query("SELECT param_value FROM parameters WHERE param_key='company_name' LIMIT 1");
$company = $co_q ? ($co_q->fetch_assoc()['param_value'] ?? 'Flowork') : 'Flowork';

// ── Exchange rate (latest in range) ───────────────────────────────────────
$er  = $conn->query("SELECT amount FROM exchange_rate ORDER BY id DESC LIMIT 1")->fetch_assoc();
$zig = (float)($er['amount'] ?? 0);

// Helper: normalise price stored as USD/kg (may be ×100 in old records)
// We detect by value: if > 10 assume cents/kg, divide by 100
$price_expr = "CASE WHEN sb.price > 10 THEN sb.price/100 ELSE sb.price END";

// ── Grand totals ──────────────────────────────────────────────────────────
$summary = $conn->query("
    SELECT
        COUNT(DISTINCT sb.id)                              AS bales_sold,
        COUNT(DISTINCT bj.id)                              AS bales_junus,
        COALESCE(SUM(bm.mass),0)                           AS total_mass,
        COALESCE(SUM($price_expr * bm.mass),0)             AS total_usd,
        MAX($price_expr)                                   AS high_price,
        MIN($price_expr)                                   AS low_price,
        AVG($price_expr)                                   AS avg_price,
        COUNT(DISTINCT g.id)                               AS growers,
        COUNT(DISTINCT bg.buyerid)                         AS buyers
    FROM sold_bales sb
    JOIN tickets tk ON tk.id = sb.ticketsid
    JOIN bale_junus bj ON bj.id = tk.bale_junusid
    JOIN bale_mass bm ON bm.id = bj.bale_massid
    JOIN growers g ON g.id = bj.growerid
    JOIN buyer_grades bg ON bg.id = sb.buyer_gradesid
    WHERE DATE(sb.created_at) BETWEEN '$date_from' AND '$date_to'
      AND g.selling_pointid = $spid
")->fetch_assoc();

$total_usd      = (float)($summary['total_usd'] ?? 0);
$total_zig      = $zig > 0 ? $total_usd * $zig : 0;
$bales_sold     = (int)($summary['bales_sold'] ?? 0);
$bales_laid     = (int)($summary['bales_junus'] ?? 0);
$bales_rejected = max(0, $bales_laid - $bales_sold);
$avg_price      = (float)($summary['avg_price'] ?? 0);

// ── By buyer ──────────────────────────────────────────────────────────────
$by_buyer = $conn->query("
    SELECT COALESCE(NULLIF(b.buyer_code,''), b.name) AS buyer_name, b.buyer_code,
           COUNT(sb.id) bales,
           COALESCE(SUM(bm.mass),0) mass,
           COALESCE(SUM($price_expr * bm.mass),0) value_usd,
           AVG($price_expr) avg_price,
           MAX($price_expr) max_price
    FROM sold_bales sb
    JOIN tickets tk ON tk.id = sb.ticketsid
    JOIN bale_junus bj ON bj.id = tk.bale_junusid
    JOIN bale_mass bm ON bm.id = bj.bale_massid
    JOIN growers g ON g.id = bj.growerid
    JOIN buyer_grades bg ON bg.id = sb.buyer_gradesid
    JOIN buyer b ON b.id = bg.buyerid
    WHERE DATE(sb.created_at) BETWEEN '$date_from' AND '$date_to'
      AND g.selling_pointid = $spid
    GROUP BY b.id ORDER BY value_usd DESC
")->fetch_all(MYSQLI_ASSOC);

// ── By grade ──────────────────────────────────────────────────────────────
$by_grade = $conn->query("
    SELECT tg.description AS grade,
           COUNT(sb.id) bales,
           COALESCE(SUM(bm.mass),0) mass,
           AVG($price_expr) avg_price,
           MAX($price_expr) max_price,
           MIN($price_expr) min_price
    FROM sold_bales sb
    JOIN tickets tk ON tk.id = sb.ticketsid
    JOIN bale_junus bj ON bj.id = tk.bale_junusid
    JOIN bale_mass bm ON bm.id = bj.bale_massid
    JOIN growers g ON g.id = bj.growerid
    JOIN timb_grades tg ON tg.id = sb.timb_gradesid
    WHERE DATE(sb.created_at) BETWEEN '$date_from' AND '$date_to'
      AND g.selling_pointid = $spid
    GROUP BY tg.id ORDER BY avg_price DESC
")->fetch_all(MYSQLI_ASSOC);

// ── By grower (top 50) ────────────────────────────────────────────────────
$by_grower = $conn->query("
    SELECT g.grower_num, CONCAT(g.name,' ',g.surname) grower_name,
           g.province,
           COUNT(sb.id) bales,
           COALESCE(SUM(bm.mass),0) mass,
           COALESCE(SUM($price_expr * bm.mass),0) value_usd,
           AVG($price_expr) avg_price
    FROM sold_bales sb
    JOIN tickets tk ON tk.id = sb.ticketsid
    JOIN bale_junus bj ON bj.id = tk.bale_junusid
    JOIN bale_mass bm ON bm.id = bj.bale_massid
    JOIN growers g ON g.id = bj.growerid
    WHERE DATE(sb.created_at) BETWEEN '$date_from' AND '$date_to'
      AND g.selling_pointid = $spid
    GROUP BY g.id ORDER BY value_usd DESC LIMIT 100
")->fetch_all(MYSQLI_ASSOC);

// ── Daily breakdown ───────────────────────────────────────────────────────
$by_day = $conn->query("
    SELECT DATE(sb.created_at) sale_date,
           COUNT(sb.id) bales,
           COALESCE(SUM(bm.mass),0) mass,
           COALESCE(SUM($price_expr * bm.mass),0) value_usd,
           AVG($price_expr) avg_price,
           COUNT(DISTINCT g.id) growers
    FROM sold_bales sb
    JOIN tickets tk ON tk.id = sb.ticketsid
    JOIN bale_junus bj ON bj.id = tk.bale_junusid
    JOIN bale_mass bm ON bm.id = bj.bale_massid
    JOIN growers g ON g.id = bj.growerid
    WHERE DATE(sb.created_at) BETWEEN '$date_from' AND '$date_to'
      AND g.selling_pointid = $spid
    GROUP BY DATE(sb.created_at) ORDER BY sale_date ASC
")->fetch_all(MYSQLI_ASSOC);

// ── CSV EXPORT ─────────────────────────────────────────────────────────────
if ($export === 'csv_summary') {
    $fn = "purchase_summary_".str_replace('-','',$date_from)."_".str_replace('-','',$date_to).".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$fn\"");
    echo "Purchase Summary — $range_label\r\n\r\n";
    echo "GRAND TOTALS\r\n";
    echo "Bales Sold,Bales Laid,Bales Rejected,Total Mass (kg),Total USD,Total ZiG,High Price,Low Price,Avg Price,Growers,Buyers\r\n";
    echo implode(',', [$bales_sold,$bales_laid,$bales_rejected,
        number_format((float)$summary['total_mass'],1),
        number_format($total_usd,4), number_format($total_zig,2),
        number_format((float)$summary['high_price'],4),
        number_format((float)$summary['low_price'],4),
        number_format($avg_price,4),
        $summary['growers'],$summary['buyers']])."\r\n\r\n";

    echo "BY BUYER\r\nBuyer,Code,Bales,Mass (kg),Value USD,Avg Price,Max Price\r\n";
    foreach ($by_buyer as $r)
        echo implode(',', ['"'.$r['buyer_name'].'"','"'.$r['buyer_code'].'"',
            $r['bales'],number_format((float)$r['mass'],1),
            number_format((float)$r['value_usd'],4),
            number_format((float)$r['avg_price'],4),
            number_format((float)$r['max_price'],4)])."\r\n";

    echo "\r\nBY TIMB GRADE\r\nGrade,Bales,Mass (kg),Avg Price,Max Price,Min Price\r\n";
    foreach ($by_grade as $r)
        echo implode(',', ['"'.$r['grade'].'"',
            $r['bales'],number_format((float)$r['mass'],1),
            number_format((float)$r['avg_price'],4),
            number_format((float)$r['max_price'],4),
            number_format((float)$r['min_price'],4)])."\r\n";

    echo "\r\nBY GROWER (TOP 100)\r\nGrower #,Name,Province,Bales,Mass (kg),Value USD,Avg Price\r\n";
    foreach ($by_grower as $r)
        echo implode(',', ['"'.$r['grower_num'].'"','"'.$r['grower_name'].'"','"'.$r['province'].'"',
            $r['bales'],number_format((float)$r['mass'],1),
            number_format((float)$r['value_usd'],4),
            number_format((float)$r['avg_price'],4)])."\r\n";

    if (count($by_day) > 1) {
        echo "\r\nDAILY BREAKDOWN\r\nDate,Bales,Mass (kg),Value USD,Avg Price,Growers\r\n";
        foreach ($by_day as $r)
            echo implode(',', [date('d M Y',strtotime($r['sale_date'])),
                $r['bales'],number_format((float)$r['mass'],1),
                number_format((float)$r['value_usd'],4),
                number_format((float)$r['avg_price'],4),
                $r['growers']])."\r\n";
    }
    exit;
}

// ── PDF EXPORT ─────────────────────────────────────────────────────────────
if ($export === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Purchase Summary — <?=$range_label?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;font-size:8.5px;color:#111;padding:12px}
.hdr{display:flex;justify-content:space-between;align-items:flex-start;
     margin-bottom:10px;padding-bottom:8px;border-bottom:2px solid #1a1208}
.hdr h1{font-size:16px;font-weight:700;color:#1a1208;letter-spacing:.05em}
.hdr h2{font-size:9px;color:#666;margin-top:2px}
.tag{background:#c8952a;color:#fff;font-size:7.5px;font-weight:700;
     padding:2px 6px;border-radius:2px;text-transform:uppercase;letter-spacing:.1em}
.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-bottom:10px}
.kpi{background:#f5f0e8;padding:7px 9px;border-radius:3px}
.kpi label{display:block;font-size:6.5px;font-weight:700;letter-spacing:.12em;
           text-transform:uppercase;color:#7a6e5f;margin-bottom:2px}
.kpi span{font-size:11px;font-weight:700}
.kpi sub{font-size:7px;color:#7a6e5f;display:block;margin-top:1px}
h3{font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
   color:#1a1208;margin:10px 0 5px;border-bottom:1px solid #ede6d6;padding-bottom:3px}
table{width:100%;border-collapse:collapse;margin-bottom:10px;font-size:7.5px}
thead th{background:#1a1208;color:#e8b84b;font-weight:700;letter-spacing:.08em;
         text-transform:uppercase;padding:3.5px 5px;text-align:left}
thead th.right{text-align:right}
tbody td{padding:3px 5px;border-bottom:1px solid #ede6d6}
tbody tr:nth-child(even){background:#faf8f5}
tfoot td{background:#ede6d6;font-weight:700;padding:3.5px 5px;border-top:1.5px solid #1a1208}
.right{text-align:right}.mono{font-family:monospace}
.badge{background:#fdf6e8;border:1px solid #e8c96a;padding:1px 4px;
       border-radius:2px;font-weight:700;font-size:7px}
@media print{body{padding:0}@page{margin:9mm 8mm;size:A4 landscape}}
</style></head><body>

<div class="hdr">
  <div><h1><?=htmlspecialchars($company)?></h1><h2>Purchase Summary Report</h2></div>
  <div style="text-align:right">
    <span class="tag">Purchase Summary</span>
    <div style="font-size:7.5px;color:#666;margin-top:4px">
      Period: <?=$range_label?><br>Printed: <?=date('d M Y H:i')?>
    </div>
  </div>
</div>

<div class="kpis">
  <div class="kpi"><label>Bales Sold</label><span><?=number_format($bales_sold)?></span><sub><?=number_format((float)$summary['total_mass'],0)?> kg</sub></div>
  <div class="kpi"><label>Gross USD</label><span>$<?=number_format($total_usd,2)?></span><sub><?=$bales_sold>0?'$'.number_format($total_usd/$bales_sold,2).'/bale':'—'?></sub></div>
  <div class="kpi"><label>Gross ZiG</label><span><?=$zig>0?'ZiG '.number_format($total_zig,0):'—'?></span><sub>Rate: <?=$zig?></sub></div>
  <div class="kpi"><label>Avg Price</label><span>$<?=number_format($avg_price,4)?>/kg</span><sub>High $<?=number_format((float)$summary['high_price'],4)?></sub></div>
  <div class="kpi"><label>Growers / Buyers</label><span><?=$summary['growers']?> / <?=$summary['buyers']?></span><sub>Rejected: <?=$bales_rejected?></sub></div>
</div>

<?php if(count($by_day)>1): ?>
<h3>Daily Breakdown</h3>
<table><thead><tr>
  <th>Date</th><th class="right">Bales</th><th class="right">Mass (kg)</th>
  <th class="right">Value USD</th><th class="right">Avg $/kg</th><th class="right">Growers</th>
</tr></thead><tbody>
<?php foreach($by_day as $d): ?>
<tr>
  <td><?=date('d M Y',strtotime($d['sale_date']))?></td>
  <td class="mono right"><?=number_format((int)$d['bales'])?></td>
  <td class="mono right"><?=number_format((float)$d['mass'],0)?></td>
  <td class="mono right fw-500">$<?=number_format((float)$d['value_usd'],2)?></td>
  <td class="mono right">$<?=number_format((float)$d['avg_price'],4)?></td>
  <td class="mono right"><?=(int)$d['growers']?></td>
</tr>
<?php endforeach; ?>
</tbody><tfoot><tr>
  <td>TOTAL</td><td class="right"><?=number_format($bales_sold)?></td>
  <td class="right"><?=number_format((float)$summary['total_mass'],0)?></td>
  <td class="right">$<?=number_format($total_usd,2)?></td>
  <td class="right">$<?=number_format($avg_price,4)?></td><td></td>
</tr></tfoot></table>
<?php endif; ?>

<h3>Sales by Buyer</h3>
<table><thead><tr>
  <th>Buyer</th><th>Code</th><th class="right">Bales</th>
  <th class="right">Mass (kg)</th><th class="right">Value USD</th>
  <th class="right">Avg $/kg</th><th class="right">Max $/kg</th><th class="right">% of Total</th>
</tr></thead><tbody>
<?php foreach($by_buyer as $r): $pct=$total_usd>0?round((float)$r['value_usd']/$total_usd*100,1):0; ?>
<tr>
  <td class="fw-500"><?=htmlspecialchars($r['buyer_name'])?></td>
  <td class="mono"><?=htmlspecialchars($r['buyer_code'])?></td>
  <td class="mono right"><?=number_format((int)$r['bales'])?></td>
  <td class="mono right"><?=number_format((float)$r['mass'],0)?></td>
  <td class="mono right">$<?=number_format((float)$r['value_usd'],2)?></td>
  <td class="mono right">$<?=number_format((float)$r['avg_price'],4)?></td>
  <td class="mono right">$<?=number_format((float)$r['max_price'],4)?></td>
  <td class="mono right"><?=$pct?>%</td>
</tr>
<?php endforeach; ?>
</tbody><tfoot><tr>
  <td colspan="2">TOTAL</td>
  <td class="right"><?=number_format($bales_sold)?></td>
  <td class="right"><?=number_format((float)$summary['total_mass'],0)?></td>
  <td class="right">$<?=number_format($total_usd,2)?></td>
  <td class="right">$<?=number_format($avg_price,4)?></td>
  <td colspan="2"></td>
</tr></tfoot></table>

<h3>Sales by TIMB Grade</h3>
<table><thead><tr>
  <th>Grade</th><th class="right">Bales</th><th class="right">Mass (kg)</th>
  <th class="right">Avg $/kg</th><th class="right">Max $/kg</th><th class="right">Min $/kg</th>
</tr></thead><tbody>
<?php foreach($by_grade as $r): ?>
<tr>
  <td><span class="badge"><?=htmlspecialchars($r['grade'])?></span></td>
  <td class="mono right"><?=number_format((int)$r['bales'])?></td>
  <td class="mono right"><?=number_format((float)$r['mass'],0)?></td>
  <td class="mono right">$<?=number_format((float)$r['avg_price'],4)?></td>
  <td class="mono right">$<?=number_format((float)$r['max_price'],4)?></td>
  <td class="mono right">$<?=number_format((float)$r['min_price'],4)?></td>
</tr>
<?php endforeach; ?>
</tbody></table>

<h3>Top Growers by Revenue</h3>
<table><thead><tr>
  <th>Grower #</th><th>Name</th><th>Province</th>
  <th class="right">Bales</th><th class="right">Mass (kg)</th>
  <th class="right">Value USD</th><th class="right">Avg $/kg</th>
</tr></thead><tbody>
<?php $seq=0; foreach($by_grower as $r): $seq++; ?>
<tr>
  <td><span class="badge"><?=htmlspecialchars($r['grower_num'])?></span></td>
  <td><?=htmlspecialchars($r['grower_name'])?></td>
  <td style="color:#666"><?=htmlspecialchars($r['province'])?></td>
  <td class="mono right"><?=number_format((int)$r['bales'])?></td>
  <td class="mono right"><?=number_format((float)$r['mass'],0)?></td>
  <td class="mono right">$<?=number_format((float)$r['value_usd'],2)?></td>
  <td class="mono right">$<?=number_format((float)$r['avg_price'],4)?></td>
</tr>
<?php endforeach; ?>
</tbody></table>

<script>window.print();</script>
</body></html>
<?php
    exit;
}

// ── HTML PAGE ─────────────────────────────────────────────────────────────
include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Purchase Summary</h2>
    <p><?=$range_label?> &nbsp;·&nbsp; <?=htmlspecialchars($_SESSION['selling_point_name']??'')?></p>
  </div>
  <div class="flex gap-2">
    <a href="?from=<?=htmlspecialchars((string)$date_from)?>&to=<?=htmlspecialchars((string)$date_to)?>&export=csv_summary"
       class="btn btn-ghost">⬇ CSV</a>
    <a href="?from=<?=htmlspecialchars((string)$date_from)?>&to=<?=htmlspecialchars((string)$date_to)?>&export=pdf"
       class="btn btn-ghost" target="_blank">🖨 PDF</a>
  </div>
</div>

<!-- Filter bar -->
<div class="card mb-3">
<div class="card-body" style="padding:0.65rem 1.15rem">
<form method="GET" style="display:flex;gap:0.5rem;align-items:flex-end;flex-wrap:wrap">
  <div class="form-group" style="margin:0">
    <label style="font-size:.62rem">From</label>
    <input type="date" name="from" class="form-control" value="<?=htmlspecialchars((string)$date_from)?>" style="padding:.35rem .6rem">
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.62rem">To</label>
    <input type="date" name="to" class="form-control" value="<?=htmlspecialchars((string)$date_to)?>" style="padding:.35rem .6rem">
  </div>
  <div style="display:flex;gap:.4rem;align-items:flex-end">
    <button type="submit" class="btn btn-primary">Apply</button>
    <a href="?from=<?=date('Y-m-d')?>&to=<?=date('Y-m-d')?>" class="btn btn-ghost">Today</a>
    <a href="?from=<?=date('Y-m-01')?>&to=<?=date('Y-m-d')?>" class="btn btn-ghost">Month</a>
  </div>
  <div style="margin-left:auto;display:flex;gap:.4rem;align-items:flex-end">
    <a href="?from=<?=htmlspecialchars((string)$date_from)?>&to=<?=htmlspecialchars((string)$date_to)?>&export=csv_summary" class="btn btn-ghost btn-sm">⬇ CSV</a>
    <a href="?from=<?=htmlspecialchars((string)$date_from)?>&to=<?=htmlspecialchars((string)$date_to)?>&export=pdf" class="btn btn-ghost btn-sm" target="_blank">🖨 PDF</a>
  </div>
</form>
</div>
</div>

<!-- KPI strip -->
<div class="kpi-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.25rem">
  <div class="kpi-card" style="--kpi-color:var(--gold)">
    <div class="kpi-label">Bales Sold</div>
    <div class="kpi-value"><?=number_format($bales_sold)?></div>
    <div class="kpi-sub"><?=$bales_rejected?> unsold / <?=number_format((float)$summary['total_mass'],0)?> kg</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--gold)">
    <div class="kpi-label">Gross USD</div>
    <div class="kpi-value sm">$<?=number_format($total_usd,0)?></div>
    <div class="kpi-sub"><?=$bales_sold>0?'$'.number_format($total_usd/$bales_sold,2).'/bale':'—'?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Gross ZiG</div>
    <div class="kpi-value sm"><?=$zig>0?'ZiG '.number_format($total_zig,0):'—'?></div>
    <div class="kpi-sub"><?=$zig>0?'Rate: '.$zig:'No exchange rate'?></div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)">
    <div class="kpi-label">Avg Price</div>
    <div class="kpi-value sm">$<?=number_format($avg_price,4)?></div>
    <div class="kpi-sub">High $<?=number_format((float)$summary['high_price'],4)?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Growers / Buyers</div>
    <div class="kpi-value sm"><?=$summary['growers']?> / <?=$summary['buyers']?></div>
    <div class="kpi-sub">Low $<?=number_format((float)$summary['low_price'],4)?>/kg</div>
  </div>
</div>

<?php $has_daily = count($by_day) > 1; ?>
<div class="premium-tabs-nav" data-tabs="purchase">
  <?php if($has_daily): ?>
  <button type="button" class="premium-tab-btn active" data-tab="daily">📅 Daily Breakdown</button>
  <?php endif; ?>
  <button type="button" class="premium-tab-btn<?=$has_daily?'':' active'?>" data-tab="buyer">🏢 By Buyer</button>
  <button type="button" class="premium-tab-btn" data-tab="grade">🍂 By Grade</button>
  <button type="button" class="premium-tab-btn" data-tab="growers">🌾 Top Growers</button>
</div>

<!-- Daily breakdown (multi-day only) -->
<?php if($has_daily): ?>
<div id="panel-daily" class="tab-panel active" data-tabs="purchase">
<div class="card">
<div class="card-head"><h3>Daily Breakdown</h3>
<span class="badge badge-gray"><?=count($by_day)?> days</span>
</div>
<div class="table-wrap"><table>
<thead><tr>
  <th>Date</th>
  <th class="text-right">Bales</th>
  <th class="text-right">Mass (kg)</th>
  <th class="text-right">Value USD</th>
  <th class="text-right">Avg $/kg</th>
  <th class="text-right">Growers</th>
  <th class="text-right">% of Period</th>
</tr></thead>
<tbody>
<?php foreach($by_day as $d):
  $pct = $total_usd>0?round((float)$d['value_usd']/$total_usd*100,1):0; ?>
<tr>
  <td class="fw-500" style="font-size:.82rem"><?=date('D d M Y',strtotime($d['sale_date']))?></td>
  <td class="td-mono text-right"><?=number_format((int)$d['bales'])?></td>
  <td class="td-mono text-right"><?=number_format((float)$d['mass'],0)?></td>
  <td class="td-mono text-right fw-500">$<?=number_format((float)$d['value_usd'],2)?></td>
  <td class="td-mono text-right">$<?=number_format((float)$d['avg_price'],4)?></td>
  <td class="td-mono text-right"><?=(int)$d['growers']?></td>
  <td class="text-right">
    <div style="display:flex;align-items:center;gap:5px;justify-content:flex-end">
      <div style="width:50px;height:5px;background:var(--border);border-radius:3px">
        <div style="width:<?=min(100,$pct)?>%;height:5px;background:var(--gold);border-radius:3px"></div>
      </div>
      <span style="font-size:.72rem"><?=$pct?>%</span>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr style="background:var(--ink);color:var(--cream)">
  <td style="padding:.5rem .9rem;font-weight:500">TOTAL</td>
  <td class="td-mono text-right" style="padding:.5rem .9rem"><?=number_format($bales_sold)?></td>
  <td class="td-mono text-right" style="padding:.5rem .9rem"><?=number_format((float)$summary['total_mass'],0)?></td>
  <td class="td-mono text-right fw-500" style="padding:.5rem .9rem">$<?=number_format($total_usd,2)?></td>
  <td class="td-mono text-right" style="padding:.5rem .9rem">$<?=number_format($avg_price,4)?></td>
  <td colspan="2"></td>
</tr></tfoot>
</table></div></div>
</div><!-- /panel-daily -->
<?php endif; ?>

<!-- By buyer -->
<div id="panel-buyer" class="tab-panel<?=$has_daily?'':' active'?>" data-tabs="purchase">
<div class="card">
<div class="card-head"><h3>Sales by Buyer</h3>
<span class="badge badge-gray"><?=count($by_buyer)?> buyers</span>
</div>
<div class="table-wrap"><table>
<thead><tr>
  <th>Buyer</th>
  <th class="text-right">Bales</th>
  <th class="text-right">Mass (kg)</th>
  <th class="text-right">Value USD</th>
  <th class="text-right">Avg $/kg</th>
  <th class="text-right">% Share</th>
</tr></thead>
<tbody>
<?php if(!empty($by_buyer)): foreach($by_buyer as $r):
  $pct=$total_usd>0?round((float)$r['value_usd']/$total_usd*100,1):0; ?>
<tr>
  <td>
    <div class="fw-500" style="font-size:.82rem"><?=htmlspecialchars($r['buyer_name'])?></div>
    <div class="td-muted" style="font-size:.68rem"><?=htmlspecialchars($r['buyer_code'])?></div>
  </td>
  <td class="td-mono text-right"><?=number_format((int)$r['bales'])?></td>
  <td class="td-mono text-right"><?=number_format((float)$r['mass'],0)?></td>
  <td class="td-mono text-right fw-500">$<?=number_format((float)$r['value_usd'],2)?></td>
  <td class="td-mono text-right">$<?=number_format((float)$r['avg_price'],4)?></td>
  <td class="text-right">
    <div style="display:flex;align-items:center;gap:4px;justify-content:flex-end">
      <div style="width:42px;height:5px;background:var(--border);border-radius:3px">
        <div style="width:<?=min(100,$pct)?>%;height:5px;background:var(--gold);border-radius:3px"></div>
      </div>
      <span style="font-size:.7rem"><?=$pct?>%</span>
    </div>
  </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="6" class="text-center text-muted" style="padding:1.5rem">No sales in this period.</td></tr>
<?php endif; ?>
</tbody>
<tfoot><tr style="background:var(--cream-d)">
  <td class="fw-500" style="padding:.45rem .9rem">Total</td>
  <td class="td-mono text-right" style="padding:.45rem .9rem"><?=number_format($bales_sold)?></td>
  <td class="td-mono text-right" style="padding:.45rem .9rem"><?=number_format((float)$summary['total_mass'],0)?></td>
  <td class="td-mono text-right fw-500" style="padding:.45rem .9rem">$<?=number_format($total_usd,2)?></td>
  <td class="td-mono text-right" style="padding:.45rem .9rem">$<?=number_format($avg_price,4)?></td>
  <td></td>
</tr></tfoot>
</table></div></div>
</div><!-- /panel-buyer -->

<!-- By grade -->
<div id="panel-grade" class="tab-panel" data-tabs="purchase">
<div class="card">
<div class="card-head"><h3>Sales by TIMB Grade</h3>
<span class="badge badge-gray"><?=count($by_grade)?> grades</span>
</div>
<div class="table-wrap"><table>
<thead><tr>
  <th>Grade</th>
  <th class="text-right">Bales</th>
  <th class="text-right">Mass (kg)</th>
  <th class="text-right">Avg $/kg</th>
  <th class="text-right">Max $/kg</th>
  <th class="text-right">Min $/kg</th>
</tr></thead>
<tbody>
<?php if(!empty($by_grade)): foreach($by_grade as $r): ?>
<tr>
  <td><span class="badge badge-gold" style="font-size:.68rem"><?=htmlspecialchars($r['grade'])?></span></td>
  <td class="td-mono text-right"><?=number_format((int)$r['bales'])?></td>
  <td class="td-mono text-right"><?=number_format((float)$r['mass'],0)?></td>
  <td class="td-mono text-right fw-500">$<?=number_format((float)$r['avg_price'],4)?></td>
  <td class="td-mono text-right" style="color:var(--leaf)">$<?=number_format((float)$r['max_price'],4)?></td>
  <td class="td-mono text-right" style="color:var(--danger)">$<?=number_format((float)$r['min_price'],4)?></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="6" class="text-center text-muted" style="padding:1.5rem">No grades found.</td></tr>
<?php endif; ?>
</tbody>
</table></div></div>
</div><!-- /panel-grade -->

<!-- Grower table -->
<div id="panel-growers" class="tab-panel" data-tabs="purchase">
<div class="card">
<div class="card-head">
  <h3>Top Growers by Revenue</h3>
  <div class="flex gap-2">
    <span class="badge badge-gray"><?=count($by_grower)?></span>
    <a href="?from=<?=htmlspecialchars((string)$date_from)?>&to=<?=htmlspecialchars((string)$date_to)?>&export=csv_summary" class="btn btn-ghost btn-sm">⬇ CSV</a>
  </div>
</div>
<div class="table-wrap" style="max-height:480px;overflow-y:auto"><table>
<thead><tr>
  <th>#</th>
  <th>Grower #</th>
  <th>Name</th>
  <th>Province</th>
  <th class="text-right">Bales</th>
  <th class="text-right">Mass (kg)</th>
  <th class="text-right">Value USD</th>
  <th class="text-right">Avg $/kg</th>
  <th class="text-right">% Share</th>
</tr></thead>
<tbody>
<?php $seq=0; foreach($by_grower as $r):
  $seq++;
  $pct = $total_usd>0?round((float)$r['value_usd']/$total_usd*100,2):0; ?>
<tr>
  <td class="td-muted" style="font-size:.7rem"><?=$seq?></td>
  <td><span class="badge badge-gold" style="font-size:.62rem"><?=htmlspecialchars($r['grower_num'])?></span></td>
  <td style="font-size:.82rem;font-weight:500"><?=htmlspecialchars($r['grower_name'])?></td>
  <td class="td-muted" style="font-size:.75rem"><?=htmlspecialchars($r['province'])?></td>
  <td class="td-mono text-right"><?=number_format((int)$r['bales'])?></td>
  <td class="td-mono text-right"><?=number_format((float)$r['mass'],0)?></td>
  <td class="td-mono text-right fw-500">$<?=number_format((float)$r['value_usd'],2)?></td>
  <td class="td-mono text-right">$<?=number_format((float)$r['avg_price'],4)?></td>
  <td class="text-right">
    <div style="display:flex;align-items:center;gap:4px;justify-content:flex-end">
      <div style="width:42px;height:5px;background:var(--border);border-radius:3px">
        <div style="width:<?=min(100,$pct*5)?>%;height:5px;background:var(--leaf);border-radius:3px"></div>
      </div>
      <span style="font-size:.7rem"><?=$pct?>%</span>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr style="background:var(--ink);color:var(--cream)">
  <td colspan="4" style="padding:.5rem .9rem;font-weight:500">TOTAL — <?=count($by_grower)?> growers shown</td>
  <td class="td-mono text-right" style="padding:.5rem .9rem"><?=number_format($bales_sold)?></td>
  <td class="td-mono text-right" style="padding:.5rem .9rem"><?=number_format((float)$summary['total_mass'],0)?></td>
  <td class="td-mono text-right fw-500" style="padding:.5rem .9rem">$<?=number_format($total_usd,2)?></td>
  <td class="td-mono text-right" style="padding:.5rem .9rem">$<?=number_format($avg_price,4)?></td>
  <td></td>
</tr></tfoot>
</table></div></div>
</div><!-- /panel-growers -->

<?php include "includes/layout_end.php"; ?>
