<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = 'Price Forecast';
$active_page = 'price_forecast';
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$yr          = (int)date('Y');
$today       = date('Y-m-d');
$day90_ago   = date('Y-m-d', strtotime('-90 days'));
$day30_ago   = date('Y-m-d', strtotime('-30 days'));
$day14_ago   = date('Y-m-d', strtotime('-14 days'));
$day7_ago    = date('Y-m-d', strtotime('-7 days'));

// ── Helper ───────────────────────────────────────────────────────────────────
function qAll($c, $sql) {
    if (!$c) return [];
    $r = @$c->query($sql);
    if (!$r || $r === true) return [];
    return $r->fetch_all(MYSQLI_ASSOC) ?: [];
}
function qOne($c, $sql) {
    $rows = qAll($c, $sql);
    return $rows[0] ?? [];
}

// ── Linear regression ────────────────────────────────────────────────────────
function linear_regression(array $y): array {
    $n = count($y);
    if ($n < 2) return ['slope' => 0, 'intercept' => $y[0] ?? 0];
    $sumX = $sumY = $sumXY = $sumX2 = 0;
    foreach ($y as $i => $v) {
        $sumX  += $i;   $sumY  += $v;
        $sumXY += $i * $v; $sumX2 += $i * $i;
    }
    $denom    = ($n * $sumX2 - $sumX * $sumX);
    $slope    = $denom ? ($n * $sumXY - $sumX * $sumY) / $denom : 0;
    $intercept = ($sumY - $slope * $sumX) / $n;
    return ['slope' => $slope, 'intercept' => $intercept];
}

// Normalise price (some stored as cents)
$price_expr = "CASE WHEN sb.price > 10 THEN sb.price/100 ELSE sb.price END";

// ── KPI: today / 7d / 30d / season avg price ─────────────────────────────────
$kpi_today  = qOne($conn, "SELECT AVG($price_expr) v FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    WHERE DATE(sb.created_at)='$today' AND g.selling_pointid=$spid AND sb.price>0");
$kpi_7d     = qOne($conn, "SELECT AVG($price_expr) v FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    WHERE sb.created_at>='$day7_ago' AND g.selling_pointid=$spid AND sb.price>0");
$kpi_30d    = qOne($conn, "SELECT AVG($price_expr) v FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    WHERE sb.created_at>='$day30_ago' AND g.selling_pointid=$spid AND sb.price>0");
$kpi_season = qOne($conn, "SELECT AVG($price_expr) v FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    WHERE YEAR(sb.created_at)=$yr AND g.selling_pointid=$spid AND sb.price>0");

$p_today  = round((float)($kpi_today['v']  ?? 0), 4);
$p_7d     = round((float)($kpi_7d['v']    ?? 0), 4);
$p_30d    = round((float)($kpi_30d['v']   ?? 0), 4);
$p_season = round((float)($kpi_season['v'] ?? 0), 4);

// ── Top 6 grades by volume (last 30 days) ────────────────────────────────────
$top_grades_q = qAll($conn, "SELECT tg.description grade, COUNT(sb.id) vol
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    WHERE sb.created_at>='$day30_ago' AND g.selling_pointid=$spid AND sb.price>0
    GROUP BY tg.id ORDER BY vol DESC LIMIT 6");
$top_grades = array_column($top_grades_q, 'grade');

// ── Daily avg price per grade — last 30 days ──────────────────────────────────
$grade_daily_rows = [];
if (!empty($top_grades)) {
    $grades_in = implode(',', array_map(fn($g) => "'".addslashes($g)."'", $top_grades));
    $grade_daily_rows = qAll($conn, "SELECT DATE(sb.created_at) dt,
        tg.description grade,
        AVG($price_expr) avg_p
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN growers g ON g.id=bj.growerid
        JOIN timb_grades tg ON tg.id=sb.timb_gradesid
        WHERE sb.created_at>='$day30_ago' AND g.selling_pointid=$spid
          AND sb.price>0 AND tg.description IN ($grades_in)
        GROUP BY DATE(sb.created_at), tg.id
        ORDER BY dt ASC, grade ASC");
}

// Build chart datasets per grade
$date_labels_set = [];
$grade_series    = [];
foreach ($grade_daily_rows as $row) {
    $date_labels_set[$row['dt']] = true;
    $grade_series[$row['grade']][$row['dt']] = round((float)$row['avg_p'], 4);
}
$all_dates = array_keys($date_labels_set);
sort($all_dates);

// ── Grade forecast table (last 30d data → 14d MA → regression → 7d forecast) ─
$grade_forecast_raw = qAll($conn, "SELECT tg.description grade,
    DATE(sb.created_at) dt,
    AVG($price_expr) avg_p,
    COUNT(sb.id) bales
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    WHERE YEAR(sb.created_at)=$yr AND g.selling_pointid=$spid AND sb.price>0
    GROUP BY tg.id, DATE(sb.created_at)
    ORDER BY grade ASC, dt ASC");

// Aggregate per grade
$grade_data = [];
foreach ($grade_forecast_raw as $r) {
    $grade_data[$r['grade']][] = (float)$r['avg_p'];
}

$grade_forecast = [];
foreach ($grade_data as $grade => $prices) {
    $n      = count($prices);
    $cur    = end($prices);
    // 14-day MA (take last 14 values)
    $ma14_vals = array_slice($prices, -14);
    $ma14 = count($ma14_vals) ? array_sum($ma14_vals) / count($ma14_vals) : $cur;
    // Linear regression
    $reg = linear_regression($prices);
    $slope = $reg['slope'];
    $intercept = $reg['intercept'];
    // 7-day forecast
    $fore7_vals = [];
    for ($i = 1; $i <= 7; $i++) {
        $fore7_vals[] = max(0, $intercept + $slope * ($n + $i - 1));
    }
    $fore_min = min($fore7_vals);
    $fore_max = max($fore7_vals);
    $fore_mid = array_sum($fore7_vals) / 7;
    $trend    = $slope > 0.0001 ? 'up' : ($slope < -0.0001 ? 'down' : 'flat');
    $grade_forecast[] = [
        'grade'    => $grade,
        'current'  => round($cur, 4),
        'ma14'     => round($ma14, 4),
        'slope'    => $slope,
        'fore_min' => round($fore_min, 4),
        'fore_max' => round($fore_max, 4),
        'fore_mid' => round($fore_mid, 4),
        'trend'    => $trend,
        'n'        => $n,
    ];
}
usort($grade_forecast, fn($a,$b) => $b['n'] <=> $a['n']);
$grade_forecast = array_slice($grade_forecast, 0, 12);

// Projected lines for chart (next 7 days per grade, based on top 6)
$project_dates = [];
for ($i = 1; $i <= 7; $i++) {
    $project_dates[] = date('Y-m-d', strtotime("+$i days"));
}

$chart_projection = []; // grade => [date => forecasted_price]
foreach ($grade_forecast as $gf) {
    if (!in_array($gf['grade'], $top_grades)) continue;
    $prices = $grade_data[$gf['grade']];
    $n      = count($prices);
    $reg    = linear_regression($prices);
    foreach ($project_dates as $di => $pd) {
        $chart_projection[$gf['grade']][$pd] = max(0, round($reg['intercept'] + $reg['slope'] * ($n + $di), 4));
    }
}

// ── Buyer price comparison ───────────────────────────────────────────────────
$buyer_week = qAll($conn, "SELECT b.name buyer,
    AVG($price_expr) avg_p,
    COUNT(sb.id) bales
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    LEFT JOIN buyer b ON b.id=bg.buyerid
    WHERE sb.created_at>='$day7_ago' AND g.selling_pointid=$spid AND sb.price>0
      AND b.name IS NOT NULL
      AND NOT (LEFT(UPPER(TRIM(sb.sale_code)),1)='R' AND CHAR_LENGTH(TRIM(sb.sale_code))<=3)
    GROUP BY b.id ORDER BY bales DESC LIMIT 12");

$buyer_month = [];
if (!empty($buyer_week)) {
    $bnames = implode(',', array_map(fn($r) => "'".addslashes($r['buyer'])."'", $buyer_week));
    $month_rows = qAll($conn, "SELECT b.name buyer,
        AVG($price_expr) avg_p
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN growers g ON g.id=bj.growerid
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        WHERE sb.created_at>='$day30_ago' AND g.selling_pointid=$spid AND sb.price>0
          AND b.name IN ($bnames)
          AND NOT (LEFT(UPPER(TRIM(sb.sale_code)),1)='R' AND CHAR_LENGTH(TRIM(sb.sale_code))<=3)
        GROUP BY b.id");
    foreach ($month_rows as $r) $buyer_month[$r['buyer']] = (float)$r['avg_p'];
}
$floor_avg = $p_30d ?: 0;

// ── Rejection trend — last 14 days ──────────────────────────────────────────
$rej_trend = qAll($conn, "SELECT DATE(sb.created_at) dt,
    COUNT(*) total,
    SUM(CASE WHEN LEFT(UPPER(TRIM(sb.sale_code)),1)='R' AND CHAR_LENGTH(TRIM(sb.sale_code))<=3 THEN 1 ELSE 0 END) rejected
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    WHERE sb.created_at>='$day14_ago' AND g.selling_pointid=$spid
    GROUP BY DATE(sb.created_at) ORDER BY dt ASC");

// ── Build chart JS data ───────────────────────────────────────────────────────
$colors6 = ['#c8952a','#3d6b35','#e8b84b','#6a9b5f','#1a6b9a','#c0392b'];
$chart_datasets = [];
foreach ($top_grades as $gi => $grade) {
    $data_points = [];
    foreach ($all_dates as $d) {
        $data_points[] = $grade_series[$grade][$d] ?? null;
    }
    $chart_datasets[] = [
        'label' => $grade,
        'data'  => $data_points,
        'borderColor' => $colors6[$gi] ?? '#888',
        'backgroundColor' => 'transparent',
        'tension' => 0.35,
        'pointRadius' => 2,
        'borderWidth' => 2,
        'spanGaps' => true,
    ];
    // Build robust projection data array
    $last_real_idx  = count($all_dates) - 1;
    $last_real_val  = $grade_series[$grade][$all_dates[$last_real_idx]] ?? null;
    $projection_data = array_fill(0, max(0, count($all_dates) - 1), null);
    $projection_data[count($all_dates) - 1] = $last_real_val;
    foreach ($project_dates as $di => $pd) {
        $projection_data[count($all_dates) + $di] = $chart_projection[$grade][$pd] ?? null;
    }

    $chart_datasets[] = [
        'label' => $grade . ' (forecast)',
        'data'  => $projection_data,
        'borderColor'     => $colors6[$gi] ?? '#888',
        'backgroundColor' => 'transparent',
        'borderDash'      => [6, 4],
        'tension'         => 0.35,
        'pointRadius'     => 0,
        'borderWidth'     => 1.5,
        'spanGaps'        => true,
    ];
}

$all_chart_dates = array_merge($all_dates, $project_dates);

include "includes/layout.php";
?>
<style>
/* ── Price Forecast styles ─────────────────────────────────────────── */
@keyframes pf-fadeUp { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }

.pf-kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.9rem;margin-bottom:1.75rem}
.pf-kpi{background:#fff;border:1px solid var(--border);border-radius:10px;padding:1.1rem 1.15rem;
  position:relative;overflow:hidden;transition:box-shadow .18s,transform .18s;animation:pf-fadeUp .45s ease both}
.pf-kpi:hover{box-shadow:0 6px 20px rgba(0,0,0,.07);transform:translateY(-2px)}
.pf-kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:var(--pf-accent,linear-gradient(90deg,var(--gold),var(--gold-l)));opacity:.9}
.pf-kpi-label{font-size:.6rem;font-weight:600;letter-spacing:.14em;text-transform:uppercase;
  color:var(--muted);margin-bottom:.55rem}
.pf-kpi-value{font-family:'Bebas Neue',sans-serif;font-size:2.1rem;letter-spacing:.04em;line-height:1;color:var(--ink)}
.pf-kpi-sub{font-size:.7rem;color:var(--muted);margin-top:.4rem;display:flex;align-items:center;gap:.4rem}
.pf-delta{display:inline-flex;align-items:center;gap:2px;font-size:.65rem;font-weight:600;
  padding:2px 6px;border-radius:4px}
.pf-delta-up{background:var(--leaf-l);color:#2a5223}
.pf-delta-down{background:var(--danger-bg);color:var(--danger)}
.pf-delta-flat{background:var(--cream);color:var(--muted)}

.pf-card{background:#fff;border:1px solid var(--border);border-radius:10px;
  overflow:hidden;margin-bottom:1.25rem;animation:pf-fadeUp .5s ease both}
.pf-card-head{padding:.85rem 1.15rem;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.pf-card-head h3{font-size:.7rem;font-weight:600;letter-spacing:.12em;
  text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:.5rem}
.pf-card-body{padding:1rem 1.15rem}

.pf-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem}

/* Table enhancements */
.pf-table{width:100%;border-collapse:collapse;font-size:.8rem}
.pf-table thead th{font-size:.62rem;font-weight:600;letter-spacing:.1em;
  text-transform:uppercase;color:var(--muted);padding:.6rem .85rem;
  text-align:left;border-bottom:1px solid var(--border);background:var(--cream);white-space:nowrap}
.pf-table tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
.pf-table tbody tr:last-child{border-bottom:none}
.pf-table tbody tr:hover{background:#faf8f5}
.pf-table tbody td{padding:.58rem .85rem;vertical-align:middle}
.td-num{font-variant-numeric:tabular-nums;font-feature-settings:"tnum"}
.td-r{text-align:right}

/* Trend arrows */
.trend-up{color:var(--leaf);font-size:1rem;font-weight:700}
.trend-down{color:var(--danger);font-size:1rem;font-weight:700}
.trend-flat{color:var(--muted);font-size:1rem}

/* Forecast range pill */
.fore-range{display:inline-flex;align-items:center;gap:4px;font-size:.72rem;
  padding:2px 8px;border-radius:12px;font-weight:500}
.fore-range-up{background:var(--leaf-l);color:#2a5223}
.fore-range-down{background:var(--danger-bg);color:var(--danger)}
.fore-range-flat{background:var(--cream);color:var(--muted);border:1px solid var(--border)}

/* Rejection mini chart */
.rej-bar-row{display:flex;align-items:center;gap:.6rem;margin-bottom:.45rem;font-size:.75rem}
.rej-bar-date{width:68px;flex-shrink:0;color:var(--muted);font-size:.65rem}
.rej-bar-track{flex:1;height:8px;background:var(--cream-d);border-radius:4px;overflow:hidden}
.rej-bar-fill{height:8px;border-radius:4px;transition:width .3s}
.rej-bar-pct{width:36px;text-align:right;flex-shrink:0;font-weight:500}

/* Buyer delta */
.buyer-above{color:var(--leaf);font-weight:600}
.buyer-below{color:var(--danger);font-weight:600}
.buyer-at{color:var(--muted)}

@media(max-width:900px){
  .pf-kpi-grid{grid-template-columns:repeat(2,1fr)}
  .pf-grid-2{grid-template-columns:1fr}
}
@media(max-width:600px){
  .pf-kpi-grid{grid-template-columns:1fr 1fr}
  .pf-kpi-value{font-size:1.65rem}
}
</style>

<!-- PAGE HEADER -->
<div class="page-header">
  <div class="page-header-left">
    <h2>Price Forecast</h2>
    <p>Statistical price trends, grade forecasts, and buyer comparisons — <?= date('d F Y') ?></p>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="analytics.php" class="btn btn-ghost btn-sm">← Analytics</a>
    <a href="financial_forecast.php" class="btn btn-ghost btn-sm">Financial Forecast</a>
  </div>
</div>

<!-- ══════ KPI STRIP ══════ -->
<div class="pf-kpi-grid">
<?php
$pairs = [
  ['Today\'s Avg Price',   $p_today,  $p_season,  'gold',   '📊'],
  ['7-Day Avg Price',      $p_7d,     $p_season,  'gold',   '📅'],
  ['30-Day Avg Price',     $p_30d,    $p_season,  'leaf',   '📆'],
  ['Season Avg Price',     $p_season, 0,          'info',   '🏆'],
];
$accentMap = ['gold'=>'linear-gradient(90deg,var(--gold),var(--gold-l))',
              'leaf'=>'linear-gradient(90deg,var(--leaf),#7cc576)',
              'info'=>'linear-gradient(90deg,var(--info),#42a5f5)'];
foreach ($pairs as $i => [$label, $val, $base, $col, $ico]):
    $style = "animation-delay:".($i*0.07)."s;--pf-accent:".$accentMap[$col];
    $delta = $base > 0 ? $val - $base : 0;
    $delta_pct = $base > 0 ? round(($delta / $base) * 100, 1) : 0;
    $cls = $delta > 0.0001 ? 'pf-delta-up' : ($delta < -0.0001 ? 'pf-delta-down' : 'pf-delta-flat');
    $arrow = $delta > 0.0001 ? '↑' : ($delta < -0.0001 ? '↓' : '→');
?>
  <div class="pf-kpi" style="<?= $style ?>">
    <div class="pf-kpi-label"><?= $ico ?> <?= $label ?></div>
    <div class="pf-kpi-value">$<?= number_format($val, 4) ?></div>
    <?php if ($i < 3 && $base > 0): ?>
    <div class="pf-kpi-sub">
      <span class="pf-delta <?= $cls ?>"><?= $arrow ?> <?= abs($delta_pct) ?>% vs season</span>
    </div>
    <?php elseif ($i === 3): ?>
    <div class="pf-kpi-sub"><span style="color:var(--muted)">Full year baseline</span></div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<!-- ══════ GRADE PRICE TREND CHART ══════ -->
<div class="pf-card" style="animation-delay:.1s">
  <div class="pf-card-head">
    <h3>
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" style="width:14px;height:14px;opacity:.5"><polyline points="1 12 5 7 9 10 15 3"/><line x1="1" y1="15" x2="15" y2="15"/></svg>
      Grade Price Trend — Last 30 Days + 7-Day Forecast
    </h3>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <?php foreach ($top_grades as $gi => $grade): ?>
      <span style="display:inline-flex;align-items:center;gap:4px;font-size:.65rem">
        <span style="width:10px;height:3px;border-radius:2px;background:<?= $colors6[$gi] ?? '#888' ?>;display:inline-block"></span>
        <?= htmlspecialchars($grade) ?>
      </span>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="pf-card-body" style="padding:.75rem 1rem">
    <div style="position:relative;height:280px">
      <canvas id="gradeTrendChart"></canvas>
    </div>
    <p style="font-size:.65rem;color:var(--muted);margin-top:.5rem;text-align:center">
      Solid lines = actual daily avg price &nbsp;|&nbsp; Dashed lines = 7-day linear regression projection
    </p>
  </div>
</div>

<!-- ══════ BUYER COMPARISON + GRADE FORECAST TABLES ══════ -->
<div class="pf-grid-2" style="margin-bottom:1.25rem">

  <!-- Buyer Price Comparison -->
  <div class="pf-card" style="animation-delay:.15s">
    <div class="pf-card-head">
      <h3>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" style="width:14px;height:14px;opacity:.5"><path d="M2 13c0-3 2.5-5 6-5s6 2 6 5"/><circle cx="8" cy="5" r="3"/></svg>
        Buyer Price Comparison
      </h3>
      <span class="badge badge-gray" style="font-size:.6rem">Floor avg: $<?= number_format($floor_avg, 4) ?>/kg</span>
    </div>
    <div class="table-wrap">
      <table class="pf-table">
        <thead>
          <tr>
            <th>Buyer</th>
            <th class="td-r">This Week</th>
            <th class="td-r">This Month</th>
            <th class="td-r">Δ vs Floor</th>
            <th class="td-r">Bales</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($buyer_week)): ?>
          <tr><td colspan="5" class="text-center text-muted" style="padding:1.5rem">No buyer data available</td></tr>
          <?php else: foreach ($buyer_week as $b):
            $bname   = $b['buyer'];
            $bavg    = round((float)$b['avg_p'], 4);
            $bmonth  = round((float)($buyer_month[$bname] ?? 0), 4);
            $delta   = $floor_avg > 0 ? round($bavg - $floor_avg, 4) : 0;
            $dcls    = $delta > 0.0001 ? 'buyer-above' : ($delta < -0.0001 ? 'buyer-below' : 'buyer-at');
            $darrow  = $delta > 0.0001 ? '↑' : ($delta < -0.0001 ? '↓' : '→');
          ?>
          <tr>
            <td style="font-weight:500"><?= htmlspecialchars($bname) ?></td>
            <td class="td-num td-r">$<?= number_format($bavg, 4) ?></td>
            <td class="td-num td-r td-muted"><?= $bmonth > 0 ? '$'.number_format($bmonth, 4) : '—' ?></td>
            <td class="td-num td-r"><span class="<?= $dcls ?>"><?= $darrow ?> $<?= number_format(abs($delta), 4) ?></span></td>
            <td class="td-num td-r td-muted"><?= number_format((int)$b['bales']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Grade Forecast Table -->
  <div class="pf-card" style="animation-delay:.2s">
    <div class="pf-card-head">
      <h3>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" style="width:14px;height:14px;opacity:.5"><path d="M8 2v12M2 8h12"/><circle cx="14" cy="3" r="1.5"/></svg>
        Grade Forecast — 7-Day Projection
      </h3>
      <span class="badge badge-gold" style="font-size:.6rem">Linear Regression</span>
    </div>
    <div class="table-wrap">
      <table class="pf-table">
        <thead>
          <tr>
            <th>Grade</th>
            <th class="td-r">Current</th>
            <th class="td-r">14d MA</th>
            <th>Trend</th>
            <th>7-Day Range</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($grade_forecast)): ?>
          <tr><td colspan="5" class="text-center text-muted" style="padding:1.5rem">Insufficient data</td></tr>
          <?php else: foreach ($grade_forecast as $gf):
            $tclass = $gf['trend'] === 'up' ? 'trend-up' : ($gf['trend'] === 'down' ? 'trend-down' : 'trend-flat');
            $tarrow = $gf['trend'] === 'up' ? '↑' : ($gf['trend'] === 'down' ? '↓' : '→');
            $rfore  = $gf['trend'] === 'up' ? 'fore-range-up' : ($gf['trend'] === 'down' ? 'fore-range-down' : 'fore-range-flat');
          ?>
          <tr>
            <td><span class="badge badge-gold" style="font-size:.6rem"><?= htmlspecialchars($gf['grade']) ?></span></td>
            <td class="td-num td-r fw-500">$<?= number_format($gf['current'], 4) ?></td>
            <td class="td-num td-r td-muted">$<?= number_format($gf['ma14'], 4) ?></td>
            <td><span class="<?= $tclass ?>"><?= $tarrow ?></span></td>
            <td>
              <span class="fore-range <?= $rfore ?>">
                $<?= number_format($gf['fore_min'], 4) ?> – $<?= number_format($gf['fore_max'], 4) ?>
              </span>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ══════ REJECTION RATE TREND ══════ -->
<div class="pf-card" style="animation-delay:.25s">
  <div class="pf-card-head">
    <h3>
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" style="width:14px;height:14px;opacity:.5"><circle cx="8" cy="8" r="6.5"/><line x1="5" y1="5" x2="11" y2="11" stroke="var(--danger)" stroke-width="1.5"/><line x1="11" y1="5" x2="5" y2="11" stroke="var(--danger)" stroke-width="1.5"/></svg>
      Rejection Rate Trend — Last 14 Days
    </h3>
    <?php
    $total_rej  = array_sum(array_column($rej_trend, 'rejected'));
    $total_all  = array_sum(array_column($rej_trend, 'total')) ?: 1;
    $overall_pct = round($total_rej / $total_all * 100, 1);
    ?>
    <span class="pf-delta <?= $overall_pct > 5 ? 'pf-delta-down' : 'pf-delta-up' ?>">
      <?= $overall_pct ?>% overall
    </span>
  </div>
  <div class="pf-card-body">
    <?php if (empty($rej_trend)): ?>
      <p class="text-muted text-center" style="padding:1rem">No rejection data available for the last 14 days.</p>
    <?php else:
      $max_rej_pct = 0;
      foreach ($rej_trend as $r) {
          $pct = $r['total'] > 0 ? ($r['rejected'] / $r['total'] * 100) : 0;
          if ($pct > $max_rej_pct) $max_rej_pct = $pct;
      }
      $max_rej_pct = max($max_rej_pct, 1);
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.5rem">
      <?php foreach ($rej_trend as $r):
        $pct  = $r['total'] > 0 ? round($r['rejected'] / $r['total'] * 100, 1) : 0;
        $bar  = round($pct / $max_rej_pct * 100);
        $bcol = $pct > 10 ? 'var(--danger)' : ($pct > 5 ? '#e8b84b' : 'var(--leaf)');
      ?>
      <div class="rej-bar-row">
        <span class="rej-bar-date"><?= date('d M', strtotime($r['dt'])) ?></span>
        <div class="rej-bar-track">
          <div class="rej-bar-fill" style="width:<?= $bar ?>%;background:<?= $bcol ?>"></div>
        </div>
        <span class="rej-bar-pct" style="color:<?= $bcol ?>"><?= $pct ?>%</span>
        <span style="color:var(--muted);font-size:.65rem;width:70px;flex-shrink:0">
          <?= (int)$r['rejected'] ?>/<?= (int)$r['total'] ?> bales
        </span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function() {
  var labels   = <?= json_encode($all_chart_dates) ?>;
  var rawDates = <?= json_encode($all_dates) ?>;
  var datasets = <?= json_encode($chart_datasets) ?>;

  // Fix null→null, do not interpolate across projection boundary
  var ctx = document.getElementById('gradeTrendChart');
  if (!ctx) return;

  // Light / dark aware grid colour
  var isDark  = document.body.classList.contains('theme-dark');
  var gridCol = isDark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.05)';
  var tickCol = isDark ? 'rgba(255,255,255,.4)' : 'rgba(0,0,0,.45)';

  // Mark the cutoff line between actual & forecast
  var cutoffPlugin = {
    id: 'cutoff',
    afterDraw(chart) {
      var meta = chart.getDatasetMeta(0);
      if (!meta.data.length) return;
      var lastReal = rawDates.length - 1;
      if (lastReal < 0 || lastReal >= meta.data.length) return;
      var x = meta.data[lastReal].x;
      var ctx2 = chart.ctx;
      ctx2.save();
      ctx2.setLineDash([5, 3]);
      ctx2.strokeStyle = 'rgba(200,149,42,.35)';
      ctx2.lineWidth   = 1;
      ctx2.beginPath();
      ctx2.moveTo(x, chart.chartArea.top);
      ctx2.lineTo(x, chart.chartArea.bottom);
      ctx2.stroke();
      ctx2.restore();
      ctx2.fillStyle = 'rgba(200,149,42,.6)';
      ctx2.font = '9px DM Sans, sans-serif';
      ctx2.fillText('Forecast →', x + 4, chart.chartArea.top + 12);
    }
  };

  new Chart(ctx, {
    type: 'line',
    plugins: [cutoffPlugin],
    data: {
      labels: labels,
      datasets: datasets
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          labels: {
            font: { size: 10, family: 'DM Sans' },
            boxWidth: 12,
            filter: function(item) {
              return !item.text.includes('(forecast)');
            }
          }
        },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              if (ctx.parsed.y === null) return null;
              return ctx.dataset.label + ': $' + ctx.parsed.y.toFixed(4) + '/kg';
            }
          }
        }
      },
      scales: {
        x: {
          ticks: {
            maxRotation: 45,
            font: { size: 9 },
            color: tickCol,
            maxTicksLimit: 20,
            callback: function(val, idx) {
              var d = labels[idx];
              if (!d) return '';
              return d.slice(5); // MM-DD
            }
          },
          grid: { color: gridCol }
        },
        y: {
          ticks: {
            callback: function(v) { return '$' + v.toFixed(4); },
            font: { size: 9 },
            color: tickCol
          },
          grid: { color: gridCol }
        }
      }
    }
  });
})();
</script>

<?php include "includes/layout_end.php"; ?>
