<?php
/**
 * grower_portal.php — Public grower self-service portal
 * Growers look up their full season history using grower number + national ID
 */
mysqli_report(MYSQLI_REPORT_OFF);
require_once "includes/conn.php";

// ── Lookup ─────────────────────────────────────────────────────────────────
$grower_num  = $conn->real_escape_string(trim($_POST['grower_num']  ?? $_GET['g'] ?? ''));
$national_id = $conn->real_escape_string(trim($_POST['national_id'] ?? $_GET['n'] ?? ''));
$season_yr   = (int)($_GET['yr'] ?? date('Y'));
$searched    = false;
$grower      = null;
$sell_days   = [];
$season_summary = [];
$payment_history = [];
$all_years   = [];

if ($grower_num) {
    $searched = true;
    $id_clause = $national_id
        ? "AND LOWER(REPLACE(REPLACE(g.national_id,' ',''),'-',''))=LOWER(REPLACE(REPLACE('$national_id',' ',''),'-',''))"
        : '';

    $g_q = $conn->query("
        SELECT g.*, bl.code loc_code, bl.name loc_name,
               t.name transporter_name
        FROM growers g
        LEFT JOIN transporter_growers tg ON tg.growerid=g.id
        LEFT JOIN transporter t ON t.id=tg.transporterid
        LEFT JOIN bale_locations bl ON bl.id=t.location_id
        WHERE g.grower_num='$grower_num' $id_clause
        LIMIT 1
    ");
    $grower = ($g_q && $g_q !== true) ? $g_q->fetch_assoc() : null;

    if ($grower) {
        $gid = (int)$grower['id'];

        // Years this grower has sold
        $yr_q = $conn->query("
            SELECT DISTINCT YEAR(DATE(sb.created_at)) yr
            FROM sold_bales sb
            JOIN tickets tk ON tk.id=sb.ticketsid
            JOIN bale_junus bj ON bj.id=tk.bale_junusid
            JOIN growers g ON g.id=bj.growerid
            WHERE g.id=$gid
            ORDER BY yr DESC
        ");
        $all_years = ($yr_q && $yr_q !== true) ? array_column($yr_q->fetch_all(MYSQLI_ASSOC),'yr') : [];
        if (!in_array($season_yr, $all_years) && !empty($all_years)) $season_yr = $all_years[0];

        // ── Per selling-day breakdown ─────────────────────────────────────
        $days_q = $conn->query("
            SELECT
                DATE(sb.created_at)                                           AS sell_date,
                COUNT(DISTINCT sb.id)                                         AS bales_sold,
                COUNT(DISTINCT tk.id)                                         AS bales_ticketed,
                COALESCE(SUM(bm.mass),0)                                      AS total_mass,
                COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass
                               ELSE sb.price*bm.mass END),0)                  AS gross_usd,
                COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100
                               WHEN sb.price>0 THEN sb.price END),0)          AS avg_price,
                COALESCE(MAX(CASE WHEN sb.price>10 THEN sb.price/100
                               ELSE sb.price END),0)                          AS hi_price,
                COALESCE(MIN(CASE WHEN sb.price>10 THEN sb.price/100
                               WHEN sb.price>0.001 THEN sb.price END),0)      AS lo_price,
                GROUP_CONCAT(DISTINCT tg.description ORDER BY tg.description) AS grades,
                GROUP_CONCAT(DISTINCT b.name ORDER BY b.name)                 AS buyers
            FROM sold_bales sb
            JOIN tickets tk ON tk.id=sb.ticketsid
            JOIN bale_junus bj ON bj.id=tk.bale_junusid
            JOIN bale_mass bm ON bm.id=bj.bale_massid
            JOIN growers g ON g.id=bj.growerid
            LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
            LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
            LEFT JOIN buyer b ON b.id=bg.buyerid
            WHERE g.id=$gid AND YEAR(DATE(sb.created_at))=$season_yr
            GROUP BY DATE(sb.created_at)
            ORDER BY sell_date DESC
        ");
        $sell_days = ($days_q && $days_q !== true) ? $days_q->fetch_all(MYSQLI_ASSOC) : [];

        // ── Per-day deductions ────────────────────────────────────────────
        foreach ($sell_days as &$day) {
            $d = $conn->real_escape_string($day['sell_date']);
            
            // Get balanced batch for grower on this date
            $bb_q = $conn->query("
                SELECT bb.id AS bbid, dtn.usd_net_value
                FROM balanced_bales bb
                JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
                LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid = bb.id
                WHERE bb.growerid = $gid
                  AND COALESCE(tg.sell_date, DATE(bb.created_at)) = '$d'
                LIMIT 1
            ");
            $bb_row = ($bb_q && $bb_q !== true) ? $bb_q->fetch_assoc() : null;
            if ($bb_row) {
                $bbid = (int)$bb_row['bbid'];
                $net_usd = (float)$bb_row['usd_net_value'];
                
                $ext_so_res = $conn->query("SELECT COALESCE(SUM(amount_deducted), 0) FROM stoporder_deductions WHERE balanced_balesid = $bbid");
                $timb_ded = ($ext_so_res && $ext_so_res !== true) ? (float)$ext_so_res->fetch_row()[0] : 0.0;
                
                $int_so_res = $conn->query("SELECT COALESCE(SUM(amount_deducted), 0) FROM internal_stop_order_deducted_amounts WHERE balanced_balesid = $bbid");
                $int_ded = ($int_so_res && $int_so_res !== true) ? (float)$int_so_res->fetch_row()[0] : 0.0;
            } else {
                $timb_ded = 0.0;
                $int_ded = 0.0;
                $net_usd = (float)$day['gross_usd'];
            }
            
            $day['timb_ded']   = $timb_ded;
            $day['int_ded']    = $int_ded;
            $day['total_ded']  = $timb_ded + $int_ded;
            $day['net_usd']    = $net_usd;

            // Payment status for this day
            $ps_q = $conn->query("
                SELECT currency, status, net_amount, paid_at
                FROM payment_status ps
                JOIN transporter_growers tg ON tg.id=ps.transporter_growersid
                WHERE tg.growerid=$gid AND ps.sell_date='$d'
                ORDER BY currency
            ");
            $day['payments'] = ($ps_q && $ps_q !== true) ? $ps_q->fetch_all(MYSQLI_ASSOC) : [];
        }
        unset($day);

        // ── Season summary ────────────────────────────────────────────────
        $season_summary = [
            'sell_days'   => count($sell_days),
            'bales_sold'  => array_sum(array_column($sell_days,'bales_sold')),
            'total_mass'  => array_sum(array_column($sell_days,'total_mass')),
            'gross_usd'   => array_sum(array_column($sell_days,'gross_usd')),
            'total_ded'   => array_sum(array_column($sell_days,'total_ded')),
            'net_usd'     => array_sum(array_column($sell_days,'net_usd')),
        ];
        $total_mass = $season_summary['total_mass'] ?: 1;
        $season_summary['avg_price'] = $season_summary['gross_usd'] / $total_mass;

        // ── Payment history (all confirmed payments) ──────────────────────
        $pay_q = $conn->query("
            SELECT ps.sell_date, ps.currency, ps.status,
                   ps.gross_amount, ps.net_amount, ps.paid_at,
                   u.username paid_by_name
            FROM payment_status ps
            JOIN transporter_growers tg ON tg.id=ps.transporter_growersid
            LEFT JOIN users u ON u.id=ps.paid_by
            WHERE tg.growerid=$gid AND YEAR(ps.sell_date)=$season_yr
            ORDER BY ps.sell_date DESC, ps.currency
        ");
        $payment_history = ($pay_q && $pay_q !== true) ? $pay_q->fetch_all(MYSQLI_ASSOC) : [];

        // ── Exchange rate ─────────────────────────────────────────────────
        $er_q = $conn->query("SELECT amount FROM exchange_rate WHERE active=1 ORDER BY id DESC LIMIT 1");
        $zig_rate = ($er_q && $er_q !== true) ? (float)($er_q->fetch_assoc()['amount'] ?? 0) : 0;
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────
function m($v,$dp=2){ return '$'.number_format((float)$v,$dp); }
function kg($v){ return number_format((float)$v,1).' kg'; }
$STATUS_BADGE = [
    'paid'     => ['bg'=>'#e8f5e9','color'=>'#2e7d32','label'=>'Paid'],
    'pending'  => ['bg'=>'#fff8e1','color'=>'#f57f17','label'=>'Pending'],
    'on_hold'  => ['bg'=>'#fce4ec','color'=>'#c62828','label'=>'On Hold'],
    'partial'  => ['bg'=>'#e3f2fd','color'=>'#1565c0','label'=>'Partial'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Grower Results Portal</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --gold:#c8952a;--leaf:#3d6b35;--ink:#1a1208;--cream:#faf8f4;
  --muted:#888;--border:#e8e0d0;--danger:#c03a2b;--radius:8px;
}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#f0ebe0;color:var(--ink);min-height:100vh}

/* ── Header ─────────────────────────────── */
.hdr{background:var(--ink);padding:.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.hdr-brand{display:flex;align-items:center;gap:.75rem}
.hdr-leaf{width:36px;height:36px;background:var(--leaf);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.hdr h1{font-size:1.1rem;color:#e8b84b;font-weight:700;letter-spacing:.04em;margin-bottom:1px}
.hdr p{font-size:.7rem;color:rgba(232,184,75,.5)}

/* ── Container ──────────────────────────── */
.wrap{max-width:900px;margin:0 auto;padding:1.25rem 1rem 3rem}

/* ── Cards ──────────────────────────────── */
.card{background:#fff;border-radius:var(--radius);box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;margin-bottom:1.1rem}
.card-hd{padding:.75rem 1.1rem;background:var(--ink);color:#f0ebe0;display:flex;justify-content:space-between;align-items:center;gap:.75rem}
.card-hd h2{font-size:.9rem;font-weight:600;margin:0}
.card-body{padding:1.1rem}

/* ── Form ───────────────────────────────── */
.form-group{margin-bottom:.85rem}
label{display:block;font-size:.75rem;font-weight:600;color:#555;margin-bottom:.3rem}
input,select{width:100%;padding:.55rem .8rem;border:1.5px solid var(--border);border-radius:6px;font-size:.9rem;outline:none;background:#fff;color:var(--ink)}
input:focus,select:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(200,149,42,.12)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.6rem 1.4rem;border-radius:6px;border:none;cursor:pointer;font-size:.88rem;font-weight:600;text-decoration:none;transition:background .15s}
.btn-primary{background:var(--gold);color:#fff}.btn-primary:hover{background:#b8851a}
.btn-ghost{background:transparent;border:1.5px solid var(--border);color:var(--ink)}.btn-ghost:hover{background:var(--cream)}
.btn-sm{padding:.35rem .85rem;font-size:.78rem}

/* ── KPI grid ───────────────────────────── */
.kpi-grid{display:grid;gap:.6rem;margin-bottom:1rem}
.kpi-grid-4{grid-template-columns:repeat(4,1fr)}
.kpi-grid-3{grid-template-columns:repeat(3,1fr)}
.kpi-grid-2{grid-template-columns:repeat(2,1fr)}
.kpi{background:#faf8f4;border:1px solid var(--border);border-radius:6px;padding:.75rem .9rem;text-align:center}
.kpi-val{font-size:1.35rem;font-weight:700;color:var(--gold);margin-bottom:2px;line-height:1.15}
.kpi-lbl{font-size:.6rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
.kpi-sub{font-size:.65rem;color:var(--muted);margin-top:1px}
.kpi-net .kpi-val{color:var(--leaf)}
.kpi-danger .kpi-val{color:var(--danger)}

/* ── Season tab bar ─────────────────────── */
.yr-tabs{display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:1rem}
.yr-tab{padding:.35rem .9rem;border-radius:20px;font-size:.78rem;font-weight:600;text-decoration:none;border:1.5px solid var(--border);color:var(--muted);background:#fff;transition:all .15s}
.yr-tab.active{background:var(--gold);border-color:var(--gold);color:#fff}

/* ── Table ──────────────────────────────── */
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.8rem;min-width:600px}
th{background:#faf8f4;padding:.5rem .85rem;text-align:left;font-size:.65rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);border-bottom:2px solid var(--border);white-space:nowrap}
td{padding:.45rem .85rem;border-bottom:1px solid #f5f0e8;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr.total-row td{background:#faf8f4;font-weight:700;border-top:2px solid var(--border)}
.tr{text-align:right}
.mono{font-family:'Courier New',monospace;font-size:.78rem}
.fw{font-weight:600}

/* ── Badges ─────────────────────────────── */
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.62rem;font-weight:600;white-space:nowrap}
.badge-gold{background:#fef3d8;color:#8b6200}
.badge-green{background:#e8f5e9;color:#2e7d32}
.badge-gray{background:#f5f5f5;color:#555}
.badge-red{background:#fce4ec;color:#c62828}
.badge-blue{background:#e3f2fd;color:#1565c0}

/* ── Selling-day accordion ──────────────── */
.day-row{cursor:pointer;transition:background .1s}
.day-row:hover td{background:#fdf9f3}
.day-row.expanded td{background:#fffbf0;font-weight:500}
.day-detail{display:none;background:#fdf9f3}
.day-detail td{padding:.6rem .85rem .85rem}

/* ── Payment status pill ────────────────── */
.pay-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.62rem;font-weight:600}

/* ── Summary net box ────────────────────── */
.net-box{background:linear-gradient(135deg,#1a2e1a,#2d4a2d);border-radius:var(--radius);padding:1.1rem 1.25rem;color:#fff;display:flex;flex-wrap:wrap;gap:1.5rem;align-items:flex-end}
.net-box .lbl{font-size:.65rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.5);margin-bottom:3px}
.net-box .val{font-size:1.8rem;font-weight:700;color:#6dd36d;line-height:1}
.net-box .val-zig{font-size:1.2rem;font-weight:600;color:#a0d4a0}
.net-box .note{font-size:.7rem;color:rgba(255,255,255,.4);margin-top:4px}

/* ── Alert ──────────────────────────────── */
.alert{padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:.83rem;border-left:4px solid}
.alert-warn{background:#fff8e1;border-color:var(--gold);color:#5a3800}
.alert-err {background:#fce4ec;border-color:var(--danger);color:#5a0f0f}

/* ── Print ──────────────────────────────── */
@media print{
  .hdr{background:#000!important;-webkit-print-color-adjust:exact}
  .no-print{display:none!important}
  .card{box-shadow:none;border:1px solid #ccc}
  .day-detail{display:table-row!important}
}
@media(max-width:600px){
  .kpi-grid-4{grid-template-columns:repeat(2,1fr)}
  .kpi-grid-3{grid-template-columns:repeat(3,1fr)}
  .net-box{flex-direction:column;gap:.75rem}
}
</style>
<link rel="manifest" href="manifest.json">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="icon.svg">
</head>
<body>

<div class="hdr">
  <div class="hdr-brand">
    <div class="hdr-leaf">🌿</div>
    <div>
      <h1>Grower Results Portal</h1>
      <p>Check your tobacco sale results &amp; payment history</p>
    </div>
  </div>
  <div style="display:flex; align-items:center; gap:.5rem; flex-wrap:wrap">
    <!-- PWA Connection Status Pill -->
    <div id="pwa-status-pill" style="display:inline-flex; align-items:center; gap:6px; font-size:0.7rem; font-weight:600; background:rgba(61,107,53,0.15); border:1px solid rgba(61,107,53,0.3); color:#81c784; padding:4px 10px; border-radius:12px; height:fit-content">
      <span style="width:6px; height:6px; background:currentColor; border-radius:50%; display:inline-block"></span>
      <span id="pwa-status-text">Online</span>
    </div>
    <?php if($grower):?>
    <div class="no-print" style="display:flex;gap:.5rem">
      <button onclick="window.print()" class="btn btn-ghost btn-sm" style="color:#e8b84b;border-color:rgba(232,184,75,.3)">🖨 Print</button>
    </div>
    <?php endif;?>
  </div>
</div>

<div class="wrap">
  <!-- PWA Offline Warning Banner -->
  <div id="pwa-offline-warning" style="display:none; align-items:center; gap:10px; background:rgba(192,57,43,0.1); border:1px solid rgba(192,57,43,0.25); color:var(--danger); padding:12px 16px; border-radius:var(--radius); margin-bottom:20px; font-size:0.85rem; font-weight:500">
    <span style="font-size:1.1rem">⚠</span>
    <span>You are currently offline. Displaying cached dashboard data from the last online session.</span>
  </div>

<!-- ── Lookup form ──────────────────────────────────────────────────── -->
<div class="card no-print">
<div class="card-hd"><h2>Look up your sales record</h2></div>
<div class="card-body">
<form method="POST" action="grower_portal.php">
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;align-items:flex-end">
    <div class="form-group" style="margin:0">
      <label>Grower Number *</label>
      <input type="text" name="grower_num" value="<?=htmlspecialchars($grower_num)?>"
             placeholder="e.g. ZW-0001234" required autofocus>
    </div>
    <div class="form-group" style="margin:0">
      <label>National ID (for verification)</label>
      <input type="text" name="national_id" value="<?=htmlspecialchars($national_id)?>"
             placeholder="e.g. 63-123456A78">
    </div>
    <div>
      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:1.5rem">View My Record</button>
    </div>
  </div>
  <div style="font-size:.7rem;color:var(--muted);margin-top:.6rem">
    Your national ID is used only for identity verification and is not stored in this session.
  </div>
</form>
</div>
</div>

<?php if($searched && !$grower): ?>
<div class="alert alert-err">
  ⚠ No grower found with that number<?=$national_id?' and national ID combination':''?>.
  Please check your details or contact the floor office.
</div>
<?php endif; ?>

<?php if($grower): ?>

<!-- ── Grower identity card ─────────────────────────────────────────── -->
<div class="card">
<div class="card-hd" style="background:#1a2e1a">
  <div>
    <h2 style="font-size:1.05rem"><?=htmlspecialchars($grower['name'].' '.$grower['surname'])?></h2>
    <div style="font-size:.7rem;color:rgba(255,255,255,.4);margin-top:2px">Verified grower account</div>
  </div>
  <span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($grower['grower_num'])?></span>
</div>
<div class="card-body" style="display:flex;flex-wrap:wrap;gap:1rem;font-size:.8rem">
  <?php foreach([
    ['Province',    $grower['province']??''],
    ['Farm',        $grower['farm_name']??''],
    ['Location',    ($grower['loc_code']??'').($grower['loc_name']?' — '.$grower['loc_name']:'')],
    ['Transporter', $grower['transporter_name']??''],
  ] as [$lbl,$val]): if($val): ?>
  <div><span style="color:var(--muted)"><?=$lbl?>:</span> <strong><?=htmlspecialchars($val)?></strong></div>
  <?php endif; endforeach; ?>
</div>
</div>

<?php if(empty($all_years)): ?>
<div class="alert alert-warn">No sales records found for this grower.</div>
<?php else: ?>

<!-- ── Season/year selector ─────────────────────────────────────────── -->
<div class="yr-tabs no-print">
  <?php foreach($all_years as $yr): ?>
  <a href="?g=<?=urlencode($grower_num)?>&n=<?=urlencode($national_id)?>&yr=<?=$yr?>"
     class="yr-tab <?=$yr===$season_yr?'active':''?>"><?=$yr?> Season</a>
  <?php endforeach; ?>
</div>

<!-- ── Season summary KPIs ──────────────────────────────────────────── -->
<div class="kpi-grid kpi-grid-4">
  <div class="kpi">
    <div class="kpi-val"><?=$season_summary['sell_days']?></div>
    <div class="kpi-lbl">Selling Days</div>
  </div>
  <div class="kpi">
    <div class="kpi-val"><?=number_format($season_summary['bales_sold'])?></div>
    <div class="kpi-lbl">Total Bales Sold</div>
    <div class="kpi-sub"><?=kg($season_summary['total_mass'])?></div>
  </div>
  <div class="kpi">
    <div class="kpi-val"><?=m($season_summary['gross_usd'])?></div>
    <div class="kpi-lbl">Gross Proceeds</div>
    <div class="kpi-sub">Avg <?=m($season_summary['avg_price'],4)?>/kg</div>
  </div>
  <div class="kpi kpi-danger">
    <div class="kpi-val"><?=m($season_summary['total_ded'])?></div>
    <div class="kpi-lbl">Total Deductions</div>
    <div class="kpi-sub">Stop orders</div>
  </div>
</div>

<!-- ── Net payable box ───────────────────────────────────────────────── -->
<div class="net-box" style="margin-bottom:1.1rem">
  <div>
    <div class="lbl">Net Payable USD — <?=$season_yr?> Season</div>
    <div class="val"><?=m($season_summary['net_usd'])?></div>
    <div class="note">Gross <?=m($season_summary['gross_usd'])?> minus <?=m($season_summary['total_ded'])?> deductions</div>
  </div>
  <?php if(!empty($zig_rate) && $zig_rate > 0): ?>
  <div>
    <div class="lbl">Net Payable ZiG</div>
    <div class="val-zig">ZiG <?=number_format($season_summary['net_usd']*$zig_rate,2)?></div>
    <div class="note">@ <?=number_format($zig_rate,4)?> exchange rate</div>
  </div>
  <?php endif; ?>
  <?php
  $total_paid_usd = array_sum(array_map(fn($p)=>$p['status']==='paid'&&$p['currency']==='usd'?(float)$p['net_amount']:0,$payment_history));
  $total_paid_zig = array_sum(array_map(fn($p)=>$p['status']==='paid'&&$p['currency']==='zig'?(float)$p['net_amount']:0,$payment_history));
  if($total_paid_usd > 0 || $total_paid_zig > 0): ?>
  <div>
    <div class="lbl">Payments Confirmed</div>
    <?php if($total_paid_usd > 0): ?>
    <div style="font-size:1rem;font-weight:600;color:#a0d4a0"><?=m($total_paid_usd)?> USD</div>
    <?php endif; ?>
    <?php if($total_paid_zig > 0): ?>
    <div style="font-size:.9rem;font-weight:600;color:#a0d4a0">ZiG <?=number_format($total_paid_zig,2)?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ── Season Analytics Charts (PWA Offline Friendly) ── -->
<?php if (!empty($sell_days)): 
    // Order chronologically for the chart
    $chart_data = array_reverse($sell_days);
    $num_days = count($chart_data);
    
    $max_bales = 1;
    $max_price = 1.0;
    foreach ($chart_data as $cd) {
        if ($cd['bales_sold'] > $max_bales) $max_bales = $cd['bales_sold'];
        $avg_p = $cd['bales_sold'] > 0 ? $cd['gross_usd'] / ($cd['total_mass'] ?: 1) : 0;
        if ($avg_p > $max_price) $max_price = $avg_p;
    }
    // Add 15% padding to chart top limits
    $max_bales = ceil($max_bales * 1.15);
    $max_price = $max_price * 1.15;
?>
<div class="card no-print">
    <div class="card-hd">
        <h2>Season Analytics &amp; Price Trends</h2>
        <span style="font-size:0.7rem;opacity:0.7">Interactive Dual-Axis View</span>
    </div>
    <div class="card-body" style="background:#faf8f4;padding:1.5rem">
        <div style="position:relative;width:100%;height:0;padding-bottom:35%">
            <svg viewBox="0 0 800 280" style="position:absolute;top:0;left:0;width:100%;height:100%">
                <!-- Chart Styles -->
                <style>
                    .grid-line { stroke: rgba(26,18,8,0.06); stroke-width: 1; }
                    .bar-rect { fill: rgba(200,149,42,0.15); stroke: var(--gold); stroke-width: 1.5; rx: 4px; transition: fill 0.2s, stroke-width 0.2s; }
                    .bar-rect:hover { fill: rgba(200,149,42,0.3); stroke-width: 2.5; }
                    .trend-line { stroke: var(--leaf); stroke-width: 3; fill: none; stroke-linecap: round; stroke-linejoin: round; }
                    .trend-area { fill: rgba(61,107,53,0.06); }
                    .data-dot { fill: var(--leaf); stroke: #fff; stroke-width: 2; r: 5px; transition: r 0.2s, stroke-width 0.2s; }
                    .data-dot:hover { r: 8px; stroke-width: 3; }
                    .axis-text { font-family: sans-serif; font-size: 10px; fill: var(--muted); }
                    .legend-text { font-family: sans-serif; font-size: 11px; font-weight: 500; fill: var(--ink); }
                </style>

                <!-- Grid & Y-Axis helper lines -->
                <?php for($i=0; $i<=4; $i++): 
                    $y = 20 + $i * 55;
                    $val_bal = round($max_bales * (1 - $i/4));
                    $val_prc = round($max_price * (1 - $i/4), 2);
                ?>
                    <line x1="60" y1="<?=$y?>" x2="740" y2="<?=$y?>" class="grid-line"/>
                    <!-- Left Y-Axis (Bales) -->
                    <text x="45" y="<?=$y+4?>" text-anchor="end" class="axis-text"><?=$val_bal?></text>
                    <!-- Right Y-Axis (Avg Price) -->
                    <text x="755" y="<?=$y+4?>" text-anchor="start" class="axis-text">$<?=number_format($val_prc, 2)?></text>
                <?php endfor; ?>

                <!-- X-Axis Line -->
                <line x1="60" y1="240" x2="740" y2="240" stroke="rgba(26,18,8,0.2)" stroke-width="1.5"/>

                <!-- Plotting Data -->
                <?php 
                $points = [];
                $bar_width = min(36, 680 / ($num_days ?: 1) * 0.4);
                
                foreach ($chart_data as $idx => $d) {
                    $x = 60 + ($num_days > 1 ? $idx * (680 / ($num_days - 1)) : 340);
                    $bales = (int)$d['bales_sold'];
                    $avg_p = $bales > 0 ? $d['gross_usd'] / ($d['total_mass'] ?: 1) : 0;
                    
                    // Bar coordinates
                    $bar_h = ($bales / $max_bales) * 220;
                    $bar_y = 240 - $bar_h;
                    
                    // Line/Area coordinates
                    $line_y = 240 - ($avg_p / $max_price) * 220;
                    $points[] = "$x,$line_y";
                    
                    // Draw Bar
                    ?>
                    <rect x="<?=$x - $bar_width/2?>" y="<?=$bar_y?>" width="<?=$bar_width?>" height="<?=$bar_h?>" class="bar-rect">
                        <title>Bales Sold: <?=$bales?> (<?=$d['total_mass']?> kg)</title>
                    </rect>
                    
                    <!-- X-Axis Label -->
                    <text x="<?=$x?>" y="258" text-anchor="middle" class="axis-text"><?=date('d M', strtotime($d['sell_date']))?></text>
                <?php } ?>

                <!-- Draw Price Area & Trend Line -->
                <?php if ($num_days > 1) { 
                    $area_path = "M" . $chart_data[0]['x'] . ",240 " . implode(" ", $points) . " L" . $x . ",240 Z";
                    // Build path coordinate string
                    $path_str = "M" . implode(" L", $points);
                ?>
                    <!-- Area under price trend -->
                    <path d="M 60 240 L <?=implode(" L ", $points)?> L <?=$x?> 240 Z" class="trend-area"/>
                    <!-- Trend line -->
                    <path d="<?=$path_str?>" class="trend-line"/>
                <?php } ?>

                <!-- Draw Data Dots for Trend Line -->
                <?php foreach ($chart_data as $idx => $d) {
                    $x = 60 + ($num_days > 1 ? $idx * (680 / ($num_days - 1)) : 340);
                    $bales = (int)$d['bales_sold'];
                    $avg_p = $bales > 0 ? $d['gross_usd'] / ($d['total_mass'] ?: 1) : 0;
                    $line_y = 240 - ($avg_p / $max_price) * 220;
                ?>
                    <circle cx="<?=$x?>" cy="<?=$line_y?>" class="data-dot">
                        <title>Avg Price: $<?=number_format($avg_p, 4)?>/kg</title>
                    </circle>
                <?php } ?>
            </svg>
        </div>
        
        <!-- Legend -->
        <div style="display:flex;justify-content:center;gap:1.5rem;margin-top:10px">
            <div style="display:flex;align-items:center;gap:6px">
                <span style="display:inline-block;width:12px;height:12px;background:rgba(200,149,42,0.15);border:1.5px solid var(--gold);border-radius:3px"></span>
                <span class="legend-text">Bales Sold (Left Axis)</span>
            </div>
            <div style="display:flex;align-items:center;gap:6px">
                <span style="display:inline-block;width:16px;height:0;border-top:3px solid var(--leaf)"></span>
                <span style="display:inline-block;width:8px;height:8px;background:var(--leaf);border:1.5px solid #fff;border-radius:50%;margin-left:-12px"></span>
                <span class="legend-text">Average Price USD/kg (Right Axis)</span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Selling day breakdown table ──────────────────────────────────── -->
<div class="card">
<div class="card-hd">
  <h2>Selling Day Breakdown — <?=$season_yr?> Season</h2>
  <span class="badge badge-gold" style="font-size:.68rem"><?=$season_summary['sell_days']?> days</span>
</div>
<div class="tbl-wrap">
<table>
<thead><tr>
  <th>Selling Date</th>
  <th class="tr">Bales</th>
  <th class="tr">Mass (kg)</th>
  <th class="tr">Avg $/kg</th>
  <th class="tr">Hi $/kg</th>
  <th class="tr">Gross USD</th>
  <th class="tr">Deductions</th>
  <th class="tr">Net USD</th>
  <th>Payment</th>
  <th class="no-print"></th>
</tr></thead>
<tbody>
<?php if(empty($sell_days)): ?>
<tr><td colspan="10" style="text-align:center;padding:2rem;color:var(--muted)">
  No sales recorded for the <?=$season_yr?> season.
</td></tr>
<?php else: foreach($sell_days as $i=>$day):
  $rowid = 'day-'.$i;
  // Determine payment display
  $pay_usd = array_filter($day['payments'], fn($p)=>$p['currency']==='usd');
  $pay_zig = array_filter($day['payments'], fn($p)=>$p['currency']==='zig');
  $usd_status = !empty($pay_usd) ? array_values($pay_usd)[0]['status'] : 'pending';
  $zig_status = !empty($pay_zig) ? array_values($pay_zig)[0]['status'] : null;
  $status_cfg = $STATUS_BADGE[$usd_status] ?? $STATUS_BADGE['pending'];
?>
<tr class="day-row" onclick="toggleDay('<?=$rowid?>')" id="<?=$rowid?>-hdr">
  <td>
    <strong><?=date('d F Y',strtotime($day['sell_date']))?></strong>
    <div style="font-size:.65rem;color:var(--muted)"><?=date('l',strtotime($day['sell_date']))?></div>
  </td>
  <td class="tr mono fw"><?=number_format((int)$day['bales_sold'])?></td>
  <td class="tr mono"><?=number_format((float)$day['total_mass'],1)?></td>
  <td class="tr mono"><?=m((float)$day['avg_price'],4)?></td>
  <td class="tr mono" style="color:var(--leaf)"><?=m((float)$day['hi_price'],4)?></td>
  <td class="tr mono fw"><?=m((float)$day['gross_usd'])?></td>
  <td class="tr mono" style="color:var(--danger)"><?=$day['total_ded']>0?m($day['total_ded']):'—'?></td>
  <td class="tr mono fw" style="color:var(--leaf)"><?=m($day['net_usd'])?></td>
  <td>
    <span class="pay-pill" style="background:<?=$status_cfg['bg']?>;color:<?=$status_cfg['color']?>">
      <?=$status_cfg['label']?>
    </span>
  </td>
  <td class="tr no-print" style="color:var(--muted);font-size:.72rem">▾</td>
</tr>

<!-- Expanded day detail -->
<tr class="day-detail" id="<?=$rowid?>-det">
<td colspan="10">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;padding:.5rem 0">

    <!-- Bale detail for this day -->
    <div>
      <div style="font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.5rem">Bale Detail</div>
      <?php
      $bales_q = $conn->query("
          SELECT bm.temp_bacode timb_bc, bm.mass,
                 tg.description grade, b.name buyer,
                 CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END price,
                 CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END usd
          FROM sold_bales sb
          JOIN tickets tk ON tk.id=sb.ticketsid
          JOIN bale_junus bj ON bj.id=tk.bale_junusid
          JOIN bale_mass bm ON bm.id=bj.bale_massid
          JOIN growers g ON g.id=bj.growerid
          LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
          LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
          LEFT JOIN buyer b ON b.id=bg.buyerid
          WHERE g.id=$gid AND DATE(sb.created_at)='{$conn->real_escape_string($day['sell_date'])}'
          ORDER BY bm.temp_bacode
      ");
      $bales_detail = ($bales_q&&$bales_q!==true)?$bales_q->fetch_all(MYSQLI_ASSOC):[];
      if(!empty($bales_detail)): ?>
      <table style="font-size:.73rem;min-width:0">
      <thead><tr>
        <th>TIMB BC</th><th>Grade</th><th class="tr">Mass</th><th class="tr">$/kg</th><th class="tr">Value</th>
      </tr></thead>
      <tbody>
      <?php foreach($bales_detail as $bl): ?>
      <tr>
        <td class="mono" style="font-size:.68rem"><?=htmlspecialchars($bl['timb_bc'])?></td>
        <td><span class="badge badge-gold" style="font-size:.58rem"><?=htmlspecialchars($bl['grade']??'—')?></span></td>
        <td class="tr"><?=number_format((float)$bl['mass'],1)?></td>
        <td class="tr" style="color:var(--leaf)">$<?=number_format((float)$bl['price'],4)?></td>
        <td class="tr fw">$<?=number_format((float)$bl['usd'],2)?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      </table>
      <?php else: ?>
      <div style="color:var(--muted);font-size:.75rem">No bale detail available.</div>
      <?php endif; ?>
    </div>

    <!-- Deductions + payments -->
    <div>
      <?php if($day['total_ded'] > 0): ?>
      <div style="font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.5rem">Deductions</div>
      <div style="font-size:.8rem;margin-bottom:.75rem">
        <?php if($day['timb_ded']>0): ?>
        <div style="display:flex;justify-content:space-between;padding:3px 0">
          <span>TIMB Stop Orders</span>
          <span style="color:var(--danger);font-weight:600"><?=m($day['timb_ded'])?></span>
        </div>
        <?php endif; ?>
        <?php if($day['int_ded']>0): ?>
        <div style="display:flex;justify-content:space-between;padding:3px 0">
          <span>Internal Deductions</span>
          <span style="color:var(--danger);font-weight:600"><?=m($day['int_ded'])?></span>
        </div>
        <?php endif; ?>
        <div style="display:flex;justify-content:space-between;padding:4px 0;border-top:1px solid var(--border);margin-top:4px;font-weight:700">
          <span>Net Payable</span>
          <span style="color:var(--leaf)"><?=m($day['net_usd'])?></span>
        </div>
      </div>
      <?php endif; ?>

      <div style="font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.5rem">Payment Status</div>
      <?php if(!empty($day['payments'])): foreach($day['payments'] as $p):
        $pc = $STATUS_BADGE[$p['status']] ?? $STATUS_BADGE['pending'];
      ?>
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.35rem;font-size:.78rem">
        <span class="pay-pill" style="background:<?=$pc['bg']?>;color:<?=$pc['color']?>"><?=$pc['label']?></span>
        <span style="font-weight:600"><?=strtoupper($p['currency'])?></span>
        <span style="color:var(--leaf);font-weight:700"><?=$p['currency']==='zig'?'ZiG ':'$'?><?=number_format((float)$p['net_amount'],2)?></span>
        <?php if($p['paid_at']): ?>
        <span style="color:var(--muted);font-size:.68rem">· <?=date('d M Y',strtotime($p['paid_at']))?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; else: ?>
      <div style="color:var(--muted);font-size:.75rem">No payment recorded yet for this day.</div>
      <?php endif; ?>

      <?php if(!empty($day['grades'])): ?>
      <div style="margin-top:.75rem;font-size:.72rem;color:var(--muted)">
        Grades: <?=htmlspecialchars($day['grades'])?>
        <?php if($day['buyers']): ?> · Buyers: <?=htmlspecialchars($day['buyers'])?><?php endif;?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</td>
</tr>
<?php endforeach; ?>

<!-- Season totals row -->
<tr class="total-row">
  <td><strong><?=$season_yr?> Season Total</strong></td>
  <td class="tr mono"><?=number_format($season_summary['bales_sold'])?></td>
  <td class="tr mono"><?=number_format($season_summary['total_mass'],1)?></td>
  <td class="tr mono"><?=m($season_summary['avg_price'],4)?></td>
  <td class="tr">—</td>
  <td class="tr mono"><?=m($season_summary['gross_usd'])?></td>
  <td class="tr mono" style="color:var(--danger)"><?=$season_summary['total_ded']>0?m($season_summary['total_ded']):'—'?></td>
  <td class="tr mono" style="color:var(--leaf)"><?=m($season_summary['net_usd'])?></td>
  <td colspan="2">
    <?php if($total_paid_usd > 0): ?>
    <span class="pay-pill" style="background:#e8f5e9;color:#2e7d32">Paid <?=m($total_paid_usd)?></span>
    <?php endif; ?>
  </td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<!-- ── Payment history ───────────────────────────────────────────────── -->
<?php if(!empty($payment_history)): ?>
<div class="card">
<div class="card-hd">
  <h2>Payment History — <?=$season_yr?> Season</h2>
  <span class="badge" style="background:rgba(255,255,255,.1);color:#e8b84b;font-size:.68rem"><?=count($payment_history)?> records</span>
</div>
<div class="tbl-wrap"><table>
<thead><tr>
  <th>Selling Date</th><th>Currency</th>
  <th class="tr">Gross</th><th class="tr">Net Paid</th>
  <th>Status</th><th>Date Paid</th>
</tr></thead>
<tbody>
<?php
$pay_totals = ['usd_paid'=>0,'zig_paid'=>0,'usd_pend'=>0];
foreach($payment_history as $p):
    $pc = $STATUS_BADGE[$p['status']] ?? $STATUS_BADGE['pending'];
    if($p['status']==='paid'&&$p['currency']==='usd') $pay_totals['usd_paid']+=(float)$p['net_amount'];
    if($p['status']==='paid'&&$p['currency']==='zig') $pay_totals['zig_paid']+=(float)$p['net_amount'];
    if($p['status']==='pending') $pay_totals['usd_pend']+=(float)$p['net_amount'];
?>
<tr>
  <td><?=date('d M Y',strtotime($p['sell_date']))?></td>
  <td><span class="badge <?=$p['currency']==='usd'?'badge-blue':'badge-gold'?>" style="font-size:.62rem"><?=strtoupper($p['currency'])?></span></td>
  <td class="tr mono"><?=$p['currency']==='zig'?'ZiG ':'$'?><?=number_format((float)$p['gross_amount'],2)?></td>
  <td class="tr mono fw" style="color:<?=$p['status']==='paid'?'var(--leaf)':'inherit'?>"><?=$p['currency']==='zig'?'ZiG ':'$'?><?=number_format((float)$p['net_amount'],2)?></td>
  <td><span class="pay-pill" style="background:<?=$pc['bg']?>;color:<?=$pc['color']?>"><?=$pc['label']?></span></td>
  <td style="font-size:.75rem;color:var(--muted)"><?=$p['paid_at']?date('d M Y H:i',strtotime($p['paid_at'])):'—'?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr style="background:#faf8f4;font-weight:700">
  <td colspan="3" style="padding:.55rem .85rem">Season Payment Summary</td>
  <td class="tr mono" style="color:var(--leaf)">
    <?php if($pay_totals['usd_paid']>0): ?>Paid $<?=number_format($pay_totals['usd_paid'],2)?> USD<?php endif;?>
    <?php if($pay_totals['zig_paid']>0): ?><br>Paid ZiG <?=number_format($pay_totals['zig_paid'],2)?><?php endif;?>
  </td>
  <td colspan="2">
    <?php if($pay_totals['usd_pend']>0): ?>
    <span class="pay-pill" style="background:#fff8e1;color:#f57f17">Pending $<?=number_format($pay_totals['usd_pend'],2)?></span>
    <?php endif;?>
  </td>
</tr>
</tfoot>
</table></div>
</div>
<?php endif; ?>

<?php endif; // all_years ?>
<?php endif; // grower ?>

<div style="text-align:center;padding:1.5rem 0 .5rem;font-size:.7rem;color:var(--muted)">
  Flowork Floor Management System · Data is for information purposes only
</div>

</div><!-- /wrap -->

<script>
function toggleDay(id) {
    var det = document.getElementById(id+'-det');
    var hdr = document.getElementById(id+'-hdr');
    var isOpen = det.style.display === 'table-row';
    det.style.display = isOpen ? 'none' : 'table-row';
    hdr.classList.toggle('expanded', !isOpen);
    // Update arrow
    var arrow = hdr.querySelector('.no-print');
    if(arrow) arrow.textContent = isOpen ? '▾' : '▴';
}
// Auto-expand today's row on load
document.addEventListener('DOMContentLoaded', function() {
    var rows = document.querySelectorAll('.day-row');
    if(rows.length === 1) toggleDay(rows[0].id.replace('-hdr',''));
});

// ── PWA Integration (Service Worker, Connection Status & Offline Cache) ──

// 1. Register Service Worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('sw.js').then(function(reg) {
            console.log('Grower Portal service worker registered with scope:', reg.scope);
        }).catch(function(err) {
            console.log('Grower Portal service worker registration failed:', err);
        });
    });
}

// 2. Connection Status Pill & Offline Caching
(function() {
    var pill = document.getElementById('pwa-status-pill');
    var txt = document.getElementById('pwa-status-text');
    var warn = document.getElementById('pwa-offline-warning');
    var wrap = document.querySelector('.wrap');
    var isGrowerLoaded = <?= $grower ? 'true' : 'false' ?>;
    var cacheKey = 'fw_pwa_grower_portal_cache';

    function updateStatus() {
        if (!pill || !txt) return;
        if (navigator.onLine) {
            pill.style.background = 'rgba(61,107,53,0.15)';
            pill.style.borderColor = 'rgba(61,107,53,0.3)';
            pill.style.color = '#81c784';
            txt.textContent = 'Online';
            if (warn) warn.style.display = 'none';

            // Cache current verified view
            if (isGrowerLoaded && wrap) {
                var cacheData = {
                    html: wrap.innerHTML,
                    timestamp: new Date().getTime()
                };
                localStorage.setItem(cacheKey, JSON.stringify(cacheData));
            }
        } else {
            pill.style.background = 'rgba(192,57,43,0.15)';
            pill.style.borderColor = 'rgba(192,57,43,0.3)';
            pill.style.color = '#e57373';
            txt.textContent = 'Offline';
            if (warn) warn.style.display = 'flex';

            // Load from cache if current view is not loaded
            if (!isGrowerLoaded) {
                var cached = localStorage.getItem(cacheKey);
                if (cached) {
                    try {
                        var data = JSON.parse(cached);
                        if (wrap && data.html) {
                            wrap.innerHTML = data.html;
                            // Re-get new warning banner and show it
                            var newWarn = document.getElementById('pwa-offline-warning');
                            if (newWarn) newWarn.style.display = 'flex';
                            
                            // Append offline notice
                            var header = document.querySelector('.hdr p');
                            if (header) {
                                var cachedDate = new Date(data.timestamp);
                                header.innerHTML = '<span style="color:#e57373">Offline View (Cached ' + cachedDate.toLocaleDateString() + ' ' + cachedDate.toLocaleTimeString() + ')</span>';
                            }
                        }
                    } catch(e) {
                        console.error('Failed to parse cached grower portal data', e);
                    }
                }
            }
        }
    }

    window.addEventListener('online', updateStatus);
    window.addEventListener('offline', updateStatus);
    updateStatus(); // run initial check
})();
</script>

</body>
</html>
