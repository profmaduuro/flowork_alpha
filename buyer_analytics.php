<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title  = "Buyer Analytics";
$active_page = "buyer_analytics";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);

function baSafe($conn, $sql) { if (!$conn) return []; $r = @$conn->query($sql); if (!$r || $r === true) return []; return $r->fetch_assoc() ?: []; }
function baAll($conn, $sql) { if (!$conn) return []; $r = @$conn->query($sql); if (!$r || $r === true) return []; return $r->fetch_all(MYSQLI_ASSOC) ?: []; }

$sp_filter = $spid ? "AND g.selling_pointid=$spid" : "";
$sp_filter2 = $spid ? "AND sp.id=$spid" : "";

// ── Overall buyer stats
$buyers_total = baSafe($conn, "SELECT COUNT(DISTINCT b.id) cnt FROM buyer b WHERE b.active=1 ".($spid?"AND b.selling_pointid=$spid":""));
$active_buyers = baSafe($conn, "SELECT COUNT(DISTINCT bg.buyerid) cnt FROM sold_bales sb
    JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    WHERE DATE(sb.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");

// ── Buyer Scorecards: top buyers by volume
$scorecards = baAll($conn, "SELECT b.id, b.name buyer_name, b.buyer_code,
        COUNT(DISTINCT sb.id) total_bales,
        COALESCE(SUM(bm.mass),0) total_mass,
        COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) total_usd,
        COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) avg_price,
        COUNT(DISTINCT DATE(sb.created_at)) active_days
    FROM sold_bales sb
    JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    JOIN buyer b ON b.id=bg.buyerid
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    WHERE b.active=1 $sp_filter
    GROUP BY b.id
    ORDER BY total_usd DESC
    LIMIT 20");

// ── Price trends last 14 days by top 5 buyers
$top5 = array_slice($scorecards, 0, 5);
$trend_data = [];
foreach ($top5 as $tb) {
    $bid = (int)$tb['id'];
    $rows = baAll($conn, "SELECT DATE(sb.created_at) day,
            COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) avg_price,
            COUNT(sb.id) bales
        FROM sold_bales sb
        JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        WHERE bg.buyerid=$bid AND sb.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY DATE(sb.created_at)
        ORDER BY day ASC");
    $trend_data[] = ['name' => $tb['buyer_name'], 'data' => $rows];
}

// ── NEW: Spend trend (cumulative USD per session day, last 14 selling days) for top 5 buyers
$spend_trend = [];
foreach ($top5 as $tb) {
    $bid = (int)$tb['id'];
    $rows = baAll($conn, "SELECT DATE(sb.created_at) day,
            COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) day_usd
        FROM sold_bales sb
        JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        WHERE bg.buyerid=$bid AND sb.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        GROUP BY DATE(sb.created_at)
        ORDER BY day ASC");
    // Keep only last 14 days with activity
    $rows = array_slice($rows, -14);
    $spend_trend[] = ['name' => $tb['buyer_name'], 'bid' => $bid, 'data' => $rows];
}

// ── Grade competition: how many buyers bid per TIMB grade
$grade_competition = baAll($conn, "SELECT tg.description grade_name,
        COUNT(DISTINCT bg.buyerid) buyer_count,
        COUNT(sb.id) bale_count,
        COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) avg_price,
        COALESCE(MIN(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) min_price,
        COALESCE(MAX(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) max_price
    FROM sold_bales sb
    JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    WHERE tg.active=1
    GROUP BY tg.id
    ORDER BY buyer_count ASC
    LIMIT 30");

// ── Buyer attendance (last 14 days)
$attendance = baAll($conn, "SELECT b.name buyer_name, b.buyer_code,
        DATE(sb.created_at) sell_day,
        COUNT(sb.id) bales_bought
    FROM sold_bales sb
    JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    JOIN buyer b ON b.id=bg.buyerid
    WHERE sb.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND b.active=1
    GROUP BY b.id, DATE(sb.created_at)
    ORDER BY b.name, sell_day DESC");

// Group attendance by buyer
$attendance_by_buyer = [];
foreach ($attendance as $a) {
    $attendance_by_buyer[$a['buyer_name']][] = $a;
}

// ── NEW: Grade Preference — top 3 grades per buyer (top 10 buyers)
$grade_prefs = [];
foreach (array_slice($scorecards, 0, 10) as $sc) {
    $bid = (int)$sc['id'];
    $rows = baAll($conn, "SELECT tg.description grade, COUNT(sb.id) cnt
        FROM sold_bales sb
        JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        JOIN timb_grades tg ON tg.id=sb.timb_gradesid
        WHERE bg.buyerid=$bid
        GROUP BY tg.id ORDER BY cnt DESC LIMIT 3");
    $total_b = (int)$sc['total_bales'] ?: 1;
    $grade_prefs[] = ['name' => $sc['buyer_name'], 'grades' => $rows, 'total' => $total_b];
}

// ── NEW: Avg price per buyer per grade (heatmap) — top 8 buyers, top 8 grades
$heatmap_buyers = array_slice($scorecards, 0, 8);
$heatmap_grades_raw = baAll($conn, "SELECT tg.description grade, COUNT(sb.id) cnt
    FROM sold_bales sb
    JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    GROUP BY tg.id ORDER BY cnt DESC LIMIT 8");
$heatmap_grade_names = array_column($heatmap_grades_raw, 'grade');

$heatmap_data = []; // [buyer_name][grade] = avg_price
$heatmap_all_prices = [];
foreach ($heatmap_buyers as $hb) {
    $bid = (int)$hb['id'];
    $rows = baAll($conn, "SELECT tg.description grade,
            COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) avg_p
        FROM sold_bales sb
        JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        JOIN timb_grades tg ON tg.id=sb.timb_gradesid
        WHERE bg.buyerid=$bid AND tg.description IN ('".implode("','", array_map('addslashes', $heatmap_grade_names))."')
        GROUP BY tg.id");
    $heatmap_data[$hb['buyer_name']] = [];
    foreach ($rows as $rv) {
        $heatmap_data[$hb['buyer_name']][$rv['grade']] = (float)$rv['avg_p'];
        $heatmap_all_prices[] = (float)$rv['avg_p'];
    }
}
$heat_min = $heatmap_all_prices ? min($heatmap_all_prices) : 0;
$heat_max = $heatmap_all_prices ? max(1, max($heatmap_all_prices)) : 1;

// ── NEW: Market share (total USD this session — last selling day with data)
$last_session = baSafe($conn, "SELECT MAX(DATE(sb.created_at)) d FROM sold_bales sb");
$last_day = $last_session['d'] ?? date('Y-m-d');
$market_share = baAll($conn, "SELECT b.name buyer_name,
        COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) session_usd
    FROM sold_bales sb
    JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    JOIN buyer b ON b.id=bg.buyerid
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    WHERE DATE(sb.created_at)='$last_day'
    GROUP BY b.id
    ORDER BY session_usd DESC");

include "includes/layout.php";
?>

<style>
.ba-score-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: .85rem; margin-bottom: 1.5rem; }
.ba-score-card { background:var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.15rem; position: relative; overflow: hidden; transition: all .2s; }
body.theme-dark .ba-score-card { background: var(--ink-soft); }
.ba-score-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.06); border-color: rgba(200,149,42,.25); }
.ba-score-card::before { content:''; position:absolute; top:0; left:0; width:3px; height:100%; background: var(--gold); }
.ba-score-rank { position:absolute; top:.75rem; right:.85rem; font-family:'DM Sans',sans-serif; font-size:1.53rem; color:rgba(200,149,42,.12); line-height:1;font-weight:600}
.ba-score-name { font-weight:500; font-size:.9rem; margin-bottom:.15rem; }
.ba-score-code { font-size:.78rem; color:var(--muted); margin-bottom:.75rem; }
.ba-score-stats { display:grid; grid-template-columns:1fr 1fr 1fr; gap:.5rem; }
.ba-stat-mini { text-align:center; }
.ba-stat-mini .val { font-family:'DM Sans',sans-serif; font-size:1.06rem; color:var(--text-strong); line-height:1;font-weight:600}
.ba-stat-mini .lbl { font-size:.78rem; color:var(--muted); font-weight:500; margin-top:.2rem; }
.ba-heat-row { display:flex; align-items:center; gap:.5rem; padding:.45rem 0; border-bottom:1px solid var(--border); }
.ba-heat-row:last-child { border-bottom:none; }
.ba-heat-grade { font-weight:500; font-size:.78rem; min-width:80px; }
.ba-heat-bar { flex:1; height:20px; border-radius:3px; background:var(--cream-d); overflow:hidden; position:relative; }
.ba-heat-fill { height:100%; border-radius:3px; transition: width .4s ease; }
.ba-heat-info { font-size:.68rem; color:var(--muted); min-width:130px; text-align:right; }
.ba-attend-grid { display:flex; flex-wrap:wrap; gap:4px; }
.ba-attend-dot { width:18px; height:18px; border-radius:3px; font-size:.72rem; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; }
@keyframes ba-slideIn { from { opacity:0; transform:translateX(-10px); } to { opacity:1; transform:translateX(0); } }
.ba-score-card { animation: ba-slideIn .4s ease both; }
.ba-score-card:nth-child(1) { animation-delay:.05s; }
.ba-score-card:nth-child(2) { animation-delay:.1s; }
.ba-score-card:nth-child(3) { animation-delay:.15s; }
.ba-score-card:nth-child(4) { animation-delay:.2s; }
.ba-score-card:nth-child(5) { animation-delay:.25s; }
.ba-score-card:nth-child(6) { animation-delay:.3s; }

/* Heatmap table */
.hm-table { width:100%; border-collapse:collapse; font-size:.72rem; }
.hm-table th { font-size:.78rem; font-weight:600; color:var(--muted); padding:.4rem .5rem; text-align:center; background:var(--surface); white-space:nowrap; border-bottom:1px solid var(--border); }
.hm-table th:first-child { text-align:left; min-width:100px; }
.hm-table td { padding:.35rem .5rem; text-align:center; font-variant-numeric:tabular-nums; font-size:.7rem; border-bottom:1px solid var(--border); }
.hm-table td:first-child { text-align:left; font-weight:500; font-size:.72rem; }
.hm-cell { display:block; padding:2px 4px; border-radius:3px; }

/* Grade preference badges */
.gp-badge { display:inline-flex; align-items:center; gap:3px; padding:2px 6px; border-radius:10px; font-size:.65rem; font-weight:500; margin:1px; }
</style>

<!-- Page Header -->
<div class="page-header">
  <div class="page-header-left">
    <h2>Buyer Analytics</h2>
    <p>Scorecards, spend trends, grade preferences &amp; market share</p>
  </div>
</div>

<!-- KPI Strip -->
<div class="kpi-grid">
  <div class="kpi-card" style="--kpi-color:var(--gold)">
    <div class="kpi-label">Registered Buyers</div>
    <div class="kpi-value"><?=(int)($buyers_total['cnt']??0)?></div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)">
    <div class="kpi-label">Active (30d)</div>
    <div class="kpi-value"><?=(int)($active_buyers['cnt']??0)?></div>
  </div>
  <div class="kpi-card" style="--kpi-color:#e8b84b">
    <div class="kpi-label">Total Volume</div>
    <div class="kpi-value sm"><?=number_format(array_sum(array_column($scorecards,'total_bales')))?></div>
    <div class="kpi-sub">bales (all buyers)</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--gold)">
    <div class="kpi-label">Total USD</div>
    <div class="kpi-value sm">$<?=number_format(array_sum(array_column($scorecards,'total_usd')),0)?></div>
    <div class="kpi-sub">season to date</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)">
    <div class="kpi-label">Last Session</div>
    <div class="kpi-value sm" style="font-size:1rem"><?=date('d M', strtotime($last_day))?></div>
    <div class="kpi-sub">$<?=number_format(array_sum(array_column($market_share,'session_usd')),0)?> traded</div>
  </div>
</div>

<!-- Buyer Scorecards -->
<div class="card mb-3">
  <div class="card-head">
    <h3>🏆 Buyer Scorecards — Top <?=count($scorecards)?></h3>
    <span class="badge badge-gold">Ranked by USD Volume</span>
  </div>
</div>
<div class="ba-score-grid">
  <?php foreach ($scorecards as $i => $sc): 
    $avg_pkg = (float)$sc['total_mass'] > 0 ? (float)$sc['total_usd'] / (float)$sc['total_mass'] : 0;
  ?>
  <div class="ba-score-card">
    <div class="ba-score-rank">#<?=$i+1?></div>
    <div class="ba-score-name"><?=htmlspecialchars($sc['buyer_name'])?></div>
    <div class="ba-score-code"><?=htmlspecialchars($sc['buyer_code'] ?: '—')?></div>
    <div class="ba-score-stats">
      <div class="ba-stat-mini">
        <div class="val"><?=number_format((int)$sc['total_bales'])?></div>
        <div class="lbl">Bales</div>
      </div>
      <div class="ba-stat-mini">
        <div class="val" style="color:var(--gold)">$<?=number_format($avg_pkg,2)?></div>
        <div class="lbl">Avg $/kg</div>
      </div>
      <div class="ba-stat-mini">
        <div class="val" style="color:var(--leaf)"><?=(int)$sc['active_days']?></div>
        <div class="lbl">Active Days</div>
      </div>
    </div>
    <div style="margin-top:.65rem;padding-top:.5rem;border-top:1px solid var(--border);font-size:.72rem;color:var(--muted);display:flex;justify-content:space-between">
      <span><?=number_format((float)$sc['total_mass'],0)?> kg</span>
      <span style="font-weight:500;color:var(--gold)">$<?=number_format((float)$sc['total_usd'],0)?></span>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if(empty($scorecards)): ?>
  <div style="padding:3rem;text-align:center;color:var(--muted);grid-column:1/-1">
    <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem">📊</div>
    No buyer data available yet. Sales data will appear after bales are sold.
  </div>
  <?php endif; ?>
</div>

<div class="premium-tabs-nav" data-tabs="buyeranalytics">
  <button type="button" class="premium-tab-btn active" data-tab="overview">📈 Spend &amp; Share</button>
  <button type="button" class="premium-tab-btn" data-tab="grades">⭐ Grades &amp; Pricing</button>
  <button type="button" class="premium-tab-btn" data-tab="competition">🔥 Competition</button>
  <button type="button" class="premium-tab-btn" data-tab="attendance">📅 Attendance</button>
</div>

<!-- NEW: Spend Trend + Market Share -->
<div id="panel-overview" class="tab-panel active" data-tabs="buyeranalytics">
<div class="grid-2 mb-3">
  <!-- Spend Trend Chart -->
  <div class="card">
    <div class="card-head">
      <h3>💸 Spend Trend — Top 5 Buyers (Last 14 Sessions)</h3>
      <span class="badge badge-gold">USD per day</span>
    </div>
    <div class="card-body">
      <canvas id="ba-spend-chart" height="220"></canvas>
    </div>
  </div>

  <!-- Market Share Doughnut -->
  <div class="card">
    <div class="card-head">
      <h3>🥧 Market Share — <?=date('d M Y', strtotime($last_day))?></h3>
      <span class="badge badge-gray">% of session USD</span>
    </div>
    <div class="card-body" style="display:flex;align-items:center;justify-content:center">
      <canvas id="ba-share-chart" height="220" style="max-width:320px"></canvas>
    </div>
  </div>
</div>

</div><!-- /panel-overview -->

<!-- NEW: Grade Preference Table -->
<div id="panel-grades" class="tab-panel" data-tabs="buyeranalytics">
<div class="card mb-3">
  <div class="card-head">
    <h3>⭐ Grade Preference — Top 10 Buyers</h3>
    <span class="badge badge-gray">Top 3 grades per buyer</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Buyer</th>
        <th>Top Grade #1</th>
        <th>Top Grade #2</th>
        <th>Top Grade #3</th>
        <th class="text-right">Total Bales</th>
      </tr></thead>
      <tbody>
      <?php
      $GP_COLORS = ['#c8952a','#3d6b35','#1a6b9a'];
      foreach ($grade_prefs as $gp):
          $total = max(1, $gp['total']);
      ?>
      <tr>
        <td style="font-weight:500;font-size:.8rem"><?=htmlspecialchars($gp['name'])?></td>
        <?php for($gi=0; $gi<3; $gi++):
            $g = $gp['grades'][$gi] ?? null;
            $col = $GP_COLORS[$gi];
        ?>
        <td>
          <?php if($g): ?>
          <span class="gp-badge" style="background:<?=$col?>20;color:<?=$col?>;border:1px solid <?=$col?>40">
            <?=htmlspecialchars($g['grade'])?>
            <span style="opacity:.7"><?=round((int)$g['cnt']/$total*100)?>%</span>
          </span>
          <?php else: ?>
          <span style="color:var(--muted);font-size:.65rem">—</span>
          <?php endif; ?>
        </td>
        <?php endfor; ?>
        <td class="td-mono text-right"><?=number_format($total)?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- NEW: Avg Price Heatmap (buyers × grades) -->
<div class="card mb-3">
  <div class="card-head">
    <h3>🌡 Avg Price Heatmap — Buyers × Grades ($/kg)</h3>
    <span class="badge badge-gray">Color = price level</span>
  </div>
  <div class="card-body" style="overflow-x:auto">
    <?php if(!empty($heatmap_grade_names) && !empty($heatmap_buyers)): ?>
    <table class="hm-table">
      <thead>
        <tr>
          <th>Buyer</th>
          <?php foreach($heatmap_grade_names as $gn): ?>
          <th><?=htmlspecialchars($gn)?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach($heatmap_buyers as $hb):
          $bname = $hb['buyer_name'];
      ?>
      <tr>
        <td><?=htmlspecialchars($bname)?></td>
        <?php foreach($heatmap_grade_names as $gn):
            $price = $heatmap_data[$bname][$gn] ?? null;
            if ($price !== null && $price > 0):
                $norm = $heat_max > $heat_min ? ($price - $heat_min) / ($heat_max - $heat_min) : 0.5;
                // Color: low=blue, mid=gold, high=green
                $r_c = (int)(26 + $norm * (61 - 26));
                $g_c = (int)(107 + $norm * (149 - 107));
                $b_c = (int)(53  + $norm * (42 - 53));
                $bg = "rgba($r_c,$g_c,$b_c,".number_format(0.15 + $norm * 0.5, 2).")";
                $text = $norm > 0.6 ? '#2a5223' : ($norm < 0.3 ? '#0a3060' : '#5a3800');
            endif;
        ?>
        <td>
          <?php if($price !== null && $price > 0): ?>
          <span class="hm-cell" style="background:<?=$bg?>;color:<?=$text?>">$<?=number_format($price,2)?></span>
          <?php else: ?>
          <span style="color:var(--muted);font-size:.65rem">—</span>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div style="padding:2rem;text-align:center;color:var(--muted)">No data available for heatmap</div>
    <?php endif; ?>
  </div>
</div>

</div><!-- /panel-grades -->

<!-- Grade Competition Heatmap + Price Trend Chart -->
<div id="panel-competition" class="tab-panel" data-tabs="buyeranalytics">
<div class="grid-2 mb-3">
  <div class="card">
    <div class="card-head">
      <h3>🔥 Grade Competition</h3>
      <span class="badge badge-gray">Buyers per Grade</span>
    </div>
    <div class="card-body" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
      <?php 
      $max_buyers = max(array_column($grade_competition, 'buyer_count') ?: [1]);
      foreach ($grade_competition as $gc):
        $pct = $max_buyers > 0 ? ((int)$gc['buyer_count'] / $max_buyers) * 100 : 0;
        $color = (int)$gc['buyer_count'] <= 1 ? '#c0392b' : ((int)$gc['buyer_count'] <= 2 ? '#e8b84b' : '#3d6b35');
      ?>
      <div class="ba-heat-row">
        <div class="ba-heat-grade"><?=htmlspecialchars($gc['grade_name'])?></div>
        <div class="ba-heat-bar">
          <div class="ba-heat-fill" style="width:<?=$pct?>%;background:<?=$color?>"></div>
        </div>
        <div class="ba-heat-info">
          <strong><?=(int)$gc['buyer_count']?></strong> buyers · <?=(int)$gc['bale_count']?> bales
        </div>
      </div>
      <?php endforeach; ?>
      <?php if(empty($grade_competition)): ?>
      <div style="padding:2rem;text-align:center;color:var(--muted)">No grade data available</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Price Trend Chart -->
  <div class="card">
    <div class="card-head">
      <h3>📈 Price Trends — Top 5 Buyers (14d)</h3>
    </div>
    <div class="card-body">
      <canvas id="ba-trend-chart" height="200"></canvas>
    </div>
  </div>
</div>

</div><!-- /panel-competition -->

<!-- Buyer Attendance -->
<div id="panel-attendance" class="tab-panel" data-tabs="buyeranalytics">
<div class="card mb-3">
  <div class="card-head">
    <h3>📅 Buyer Attendance — Last 14 Days</h3>
    <span class="badge badge-gold"><?=count($attendance_by_buyer)?> active buyers</span>
  </div>
  <div class="card-body">
    <?php if(!empty($attendance_by_buyer)): ?>
    <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
      <table>
        <thead><tr><th>Buyer</th><th>Active Days</th><th>Total Bales</th><th>Activity</th></tr></thead>
        <tbody>
        <?php foreach ($attendance_by_buyer as $bname => $days): 
          $total_b = array_sum(array_column($days, 'bales_bought'));
        ?>
        <tr>
          <td style="font-weight:500"><?=htmlspecialchars($bname)?></td>
          <td class="td-mono"><?=count($days)?></td>
          <td class="td-mono"><?=number_format($total_b)?></td>
          <td>
            <div class="ba-attend-grid">
              <?php foreach ($days as $d): 
                $intensity = min(255, 80 + (int)$d['bales_bought'] * 8);
                $bg = "rgba(61,107,53,".($intensity/255).")";
              ?>
              <div class="ba-attend-dot" style="background:<?=$bg?>" title="<?=htmlspecialchars($d['sell_day'])?>: <?=(int)$d['bales_bought']?> bales">
                <?=(int)$d['bales_bought']?>
              </div>
              <?php endforeach; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div style="padding:2rem;text-align:center;color:var(--muted)">No attendance data yet</div>
    <?php endif; ?>
  </div>
</div>
</div><!-- /panel-attendance -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const CHART_COLORS = ['#c8952a','#3d6b35','#e8b84b','#1a6b9a','#c0392b'];

// Price Trend Chart (existing)
const trendData = <?=json_encode($trend_data)?>;
if (trendData.length > 0 && document.getElementById('ba-trend-chart')) {
    const allDays = [...new Set(trendData.flatMap(t => t.data.map(d => d.day)))].sort();
    const datasets = trendData.map((t, i) => ({
        label: t.name,
        data: allDays.map(day => {
            const match = t.data.find(d => d.day === day);
            return match ? parseFloat(match.avg_price) : null;
        }),
        borderColor: CHART_COLORS[i % CHART_COLORS.length],
        backgroundColor: CHART_COLORS[i % CHART_COLORS.length] + '18',
        tension: 0.3,
        borderWidth: 2,
        pointRadius: 3,
        fill: false,
        spanGaps: true
    }));
    new Chart(document.getElementById('ba-trend-chart'), {
        type: 'line',
        data: { labels: allDays.map(d => { const dt=new Date(d); return dt.getDate()+' '+dt.toLocaleString('en',{month:'short'}); }), datasets },
        options: {
            responsive: true,
            plugins: { legend: { position:'bottom', labels:{boxWidth:12,font:{size:10}} } },
            scales: {
                y: { beginAtZero: false, title:{display:true,text:'$/kg',font:{size:10}}, grid:{color:'rgba(0,0,0,.04)'} },
                x: { grid:{display:false} }
            }
        }
    });
}

// NEW: Spend Trend Chart (line, cumulative USD per session day)
const spendData = <?=json_encode($spend_trend)?>;
if (spendData.length > 0 && document.getElementById('ba-spend-chart')) {
    const allSpendDays = [...new Set(spendData.flatMap(t => t.data.map(d => d.day)))].sort();
    const spendDatasets = spendData.map((t, i) => ({
        label: t.name,
        data: allSpendDays.map(day => {
            const match = t.data.find(d => d.day === day);
            return match ? parseFloat(match.day_usd) : null;
        }),
        borderColor: CHART_COLORS[i % CHART_COLORS.length],
        backgroundColor: CHART_COLORS[i % CHART_COLORS.length] + '15',
        tension: 0.35,
        borderWidth: 2.5,
        pointRadius: 4,
        pointHoverRadius: 6,
        fill: true,
        spanGaps: true
    }));
    new Chart(document.getElementById('ba-spend-chart'), {
        type: 'line',
        data: {
            labels: allSpendDays.map(d => { const dt=new Date(d); return dt.getDate()+' '+dt.toLocaleString('en',{month:'short'}); }),
            datasets: spendDatasets
        },
        options: {
            responsive: true,
            interaction: { mode:'index', intersect:false },
            plugins: {
                legend: { position:'bottom', labels:{boxWidth:12,font:{size:10}} },
                tooltip: { callbacks: { label: ctx => ' '+ctx.dataset.label+': $'+Math.round(ctx.raw||0).toLocaleString() } }
            },
            scales: {
                y: { beginAtZero:true, ticks:{callback:v=>'$'+Math.round(v/1000)+'k',font:{size:10}}, grid:{color:'rgba(0,0,0,.04)'} },
                x: { grid:{display:false}, ticks:{font:{size:10}} }
            }
        }
    });
}

// NEW: Market Share Doughnut
const shareData = <?=json_encode($market_share)?>;
if (shareData.length > 0 && document.getElementById('ba-share-chart')) {
    const shareColors = ['#c8952a','#3d6b35','#e8b84b','#1a6b9a','#c0392b','#8e44ad','#2c3e50','#e67e22','#16a085','#d35400'];
    const totalShare = shareData.reduce((s, r) => s + parseFloat(r.session_usd), 0) || 1;
    new Chart(document.getElementById('ba-share-chart'), {
        type: 'doughnut',
        data: {
            labels: shareData.map(r => r.buyer_name),
            datasets: [{
                data: shareData.map(r => parseFloat(r.session_usd)),
                backgroundColor: shareColors.slice(0, shareData.length),
                borderWidth: 2,
                borderColor: '#fff',
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            cutout: '62%',
            plugins: {
                legend: { position:'right', labels:{boxWidth:10,font:{size:10}} },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const pct = (ctx.raw / totalShare * 100).toFixed(1);
                            return ' '+ctx.label+': $'+Math.round(ctx.raw).toLocaleString()+' ('+pct+'%)';
                        }
                    }
                }
            }
        }
    });
}
</script>

<?php include "includes/layout_end.php"; ?>
