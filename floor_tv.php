<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";

// Auth guard — redirect to login if no session
if (empty($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

$spid    = (int)($_SESSION['selling_pointid'] ?? 0);
$sp_name = htmlspecialchars($_SESSION['selling_point_name'] ?? 'Floor');
$today   = date('Y-m-d');

// ── AJAX ENDPOINT ─────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    function tv_val($conn, $sql) {
        $r = $conn->query($sql);
        return ($r && $r !== true) ? ($r->fetch_assoc() ?? []) : [];
    }
    function tv_all($conn, $sql) {
        $r = $conn->query($sql);
        return ($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    $d = $conn->real_escape_string($_GET['date'] ?? $today);

    // Summary stats
    $summary = tv_val($conn, "
        SELECT
          COUNT(DISTINCT bm.id)  total_bales,
          COUNT(DISTINCT sb.id)  sold,
          COUNT(DISTINCT tk.id)  ticketed,
          COALESCE(SUM(CASE WHEN sb.id IS NOT NULL THEN bm.mass ELSE 0 END),0) sold_mass,
          COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass
                             WHEN sb.price>0  THEN sb.price*bm.mass ELSE 0 END),0) usd_value,
          AVG(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END) avg_price,
          MAX(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END) hi_price,
          MIN(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END) lo_price,
          COUNT(DISTINCT g.id)   growers
        FROM bale_mass bm
        LEFT JOIN bale_junus bj ON bj.bale_massid=bm.id
        LEFT JOIN growers g ON g.id=bj.growerid AND g.selling_pointid=$spid
        LEFT JOIN tickets tk ON tk.bale_junusid=bj.id AND DATE(tk.created_at)='$d'
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        WHERE DATE(bm.created_at)='$d'
          AND (g.selling_pointid=$spid OR g.id IS NULL)
    ");

    // Recent sales — last 20
    $recent_sales = tv_all($conn, "
        SELECT g.grower_num, g.name, g.surname, bm.mass, sb.price,
               tg2.description grade, DATE_FORMAT(sb.created_at,'%H:%i') sold_at
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        LEFT JOIN timb_grades tg2 ON tg2.id=sb.timb_gradesid
        WHERE DATE(sb.created_at)='$d' AND g.selling_pointid=$spid
        ORDER BY sb.id DESC LIMIT 20
    ");

    // By buyer leaderboard
    $by_buyer = tv_all($conn, "
        SELECT COALESCE(NULLIF(b.buyer_code,''), b.name) buyer, b.buyer_code code,
               COUNT(sb.id) bales,
               COALESCE(SUM(bm.mass),0) mass,
               COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass
                                  ELSE sb.price*bm.mass END),0) usd
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        JOIN buyer b ON b.id=bg.buyerid
        WHERE DATE(sb.created_at)='$d' AND g.selling_pointid=$spid
        GROUP BY b.id ORDER BY usd DESC LIMIT 10
    ");

    // Grade distribution
    $grade_dist = tv_all($conn, "
        SELECT tg2.description grade, COUNT(sb.id) cnt,
               COALESCE(SUM(bm.mass),0) mass
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        LEFT JOIN timb_grades tg2 ON tg2.id=sb.timb_gradesid
        WHERE DATE(sb.created_at)='$d' AND g.selling_pointid=$spid
          AND tg2.description IS NOT NULL
        GROUP BY tg2.id ORDER BY cnt DESC LIMIT 8
    ");

    // Exchange rate
    $er_q = $conn->query("SELECT amount FROM exchange_rate WHERE selling_pointid=$spid AND active=1 ORDER BY id DESC LIMIT 1");
    $er   = ($er_q && $er_q !== true) ? (float)($er_q->fetch_assoc()['amount'] ?? 0) : 0;

    echo json_encode([
        'summary'      => $summary,
        'recent_sales' => $recent_sales,
        'by_buyer'     => $by_buyer,
        'grade_dist'   => $grade_dist,
        'er'           => $er,
        'time'         => date('H:i:s'),
        'date_label'   => date('l, d F Y'),
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FLOWORK · Live Auction Floor — <?= $sp_name ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#1a1208;
  --bg2:#211609;
  --bg3:#261c0e;
  --gold:#c8952a;
  --gold-l:#e8b84b;
  --gold-dim:#7a5510;
  --leaf:#3d6b35;
  --leaf-l:#5a9e4e;
  --cream:#f5f0e8;
  --cream-dim:rgba(245,240,232,0.55);
  --muted:rgba(245,240,232,0.28);
  --border:rgba(200,149,42,0.15);
  --border-s:rgba(200,149,42,0.08);
}
html,body{height:100%;overflow:hidden}
body{
  background:var(--bg);
  color:var(--cream);
  font-family:'DM Sans',sans-serif;
  font-size:16px;
  user-select:none;
}

/* ─── WATERMARK ─── */
.watermark{
  position:fixed;inset:0;
  display:flex;align-items:center;justify-content:center;
  pointer-events:none;z-index:0;
  font-family:'Bebas Neue',sans-serif;
  font-size:22vw;letter-spacing:.18em;
  color:rgba(200,149,42,0.022);
  white-space:nowrap;
}

/* ─── TICKER ─── */
.ticker-wrap{
  position:fixed;top:0;left:0;right:0;
  height:44px;background:var(--bg3);
  border-bottom:2px solid var(--gold);
  overflow:hidden;z-index:100;
  display:flex;align-items:center;
}
.ticker-label{
  font-family:'Bebas Neue',sans-serif;
  font-size:1.1rem;letter-spacing:.14em;
  color:var(--gold);padding:0 18px;
  border-right:1px solid var(--border);
  white-space:nowrap;flex-shrink:0;
}
.ticker-track{
  flex:1;overflow:hidden;position:relative;height:100%;
  display:flex;align-items:center;
}
.ticker-inner{
  display:flex;align-items:center;gap:48px;
  white-space:nowrap;
  animation:ticker-scroll 28s linear infinite;
  font-size:.8rem;letter-spacing:.04em;
}
@keyframes ticker-scroll{
  0%{transform:translateX(0)}
  100%{transform:translateX(-50%)}
}
.ticker-item{
  display:flex;align-items:center;gap:10px;
  padding:0 4px;
}
.t-grade{
  font-family:'Bebas Neue',sans-serif;font-size:1rem;
  color:var(--gold-l);letter-spacing:.1em;
}
.t-sep{color:var(--muted);font-size:.7rem}
.t-price{color:var(--leaf-l);font-weight:600}
.t-mass{color:var(--cream-dim);font-size:.75rem}
.t-grower{
  background:rgba(200,149,42,0.18);
  border:1px solid rgba(200,149,42,0.3);
  padding:1px 7px;border-radius:2px;
  font-size:.72rem;color:var(--gold-l);
  font-weight:500;letter-spacing:.06em;
}

/* ─── TOP BAR ─── */
.top-bar{
  position:fixed;top:44px;left:0;right:0;
  height:52px;background:rgba(26,18,8,0.95);
  border-bottom:1px solid var(--border-s);
  display:flex;align-items:center;
  padding:0 24px;gap:16px;z-index:90;
  backdrop-filter:blur(6px);
}
.logo{
  font-family:'Bebas Neue',sans-serif;
  font-size:1.6rem;letter-spacing:.18em;
  color:var(--cream);display:flex;align-items:center;gap:8px;
}
.logo-dot{width:9px;height:9px;background:var(--gold);border-radius:50%}
.floor-tag{
  background:rgba(200,149,42,0.14);
  border:1px solid rgba(200,149,42,0.28);
  color:var(--gold-l);font-size:.68rem;
  letter-spacing:.14em;text-transform:uppercase;
  padding:3px 12px;border-radius:2px;
}
.spacer{flex:1}
.live-badge{
  display:flex;align-items:center;gap:6px;
  font-size:.72rem;letter-spacing:.1em;color:var(--cream-dim);
}
.live-dot{
  width:9px;height:9px;border-radius:50%;
  background:#4caf50;
  box-shadow:0 0 0 0 rgba(76,175,80,.6);
  animation:live-pulse 2s infinite;
}
@keyframes live-pulse{
  0%{box-shadow:0 0 0 0 rgba(76,175,80,.6)}
  70%{box-shadow:0 0 0 10px rgba(76,175,80,0)}
  100%{box-shadow:0 0 0 0 rgba(76,175,80,0)}
}
.clock{
  font-family:'Bebas Neue',sans-serif;
  font-size:1.55rem;letter-spacing:.1em;color:var(--gold-l);
}

/* ─── MAIN LAYOUT ─── */
.page{
  position:fixed;
  top:96px;left:0;right:0;bottom:44px;
  display:grid;
  grid-template-columns:1fr 320px;
  grid-template-rows:auto 1fr;
  gap:0;z-index:10;
}

/* ─── KPI SECTION ─── */
.kpi-section{
  grid-column:1;grid-row:1;
  display:grid;grid-template-columns:repeat(3,1fr);
  gap:2px;
  background:var(--bg2);
  border-bottom:2px solid var(--border);
  border-right:1px solid var(--border);
}
.kpi-block{
  padding:20px 24px;
  display:flex;flex-direction:column;
  align-items:center;justify-content:center;
  text-align:center;
  border-right:1px solid var(--border-s);
  position:relative;overflow:hidden;
}
.kpi-block:last-child{border-right:none}
.kpi-block::before{
  content:'';position:absolute;bottom:0;left:0;right:0;
  height:3px;background:linear-gradient(90deg,var(--gold),var(--leaf));
  opacity:0.5;
}
.kpi-num{
  font-family:'Bebas Neue',sans-serif;
  font-size:8rem;line-height:1;
  letter-spacing:.02em;
  color:var(--gold-l);
  text-shadow:0 0 40px rgba(200,149,42,0.3);
  transition:all .5s ease;
}
.kpi-num.green{color:#5a9e4e;text-shadow:0 0 40px rgba(61,107,53,0.4)}
.kpi-lbl{
  font-size:.72rem;font-weight:500;
  letter-spacing:.18em;text-transform:uppercase;
  color:var(--muted);margin-top:4px;
}
.kpi-sub{
  font-size:.75rem;color:var(--gold-dim);
  margin-top:6px;height:18px;
  font-weight:500;letter-spacing:.04em;
}

/* ─── PROGRESS BAR ─── */
.progress-section{
  grid-column:1;grid-row:2;
  display:flex;flex-direction:column;
  padding:16px 20px 12px;
  background:var(--bg2);
  border-right:1px solid var(--border);
  gap:10px;overflow:hidden;
}
.progress-header{
  display:flex;align-items:center;justify-content:space-between;
}
.progress-title{
  font-size:.65rem;font-weight:500;
  letter-spacing:.18em;text-transform:uppercase;color:var(--muted);
}
.progress-label{font-size:.85rem;color:var(--cream-dim)}
.progress-bar-wrap{
  height:18px;background:rgba(255,255,255,0.04);
  border-radius:9px;overflow:hidden;
  border:1px solid var(--border-s);
  flex-shrink:0;
}
.progress-bar-fill{
  height:100%;
  background:linear-gradient(90deg,var(--gold),var(--leaf-l));
  border-radius:9px;
  transition:width 1s ease;
  display:flex;align-items:center;justify-content:flex-end;
  padding-right:8px;
}
.progress-pct{font-size:.6rem;font-weight:700;color:#fff}

/* ─── RECENT SALES TABLE ─── */
.sales-table{
  flex:1;overflow:hidden;
}
.sales-head{
  display:grid;
  grid-template-columns:52px 80px 1fr 64px 70px 80px;
  gap:8px;padding:4px 8px;
  font-size:.58rem;font-weight:500;letter-spacing:.14em;text-transform:uppercase;
  color:var(--muted);border-bottom:1px solid var(--border-s);
}
.sales-rows{
  overflow-y:auto;max-height:calc(100% - 28px);
}
.sales-rows::-webkit-scrollbar{width:3px}
.sales-rows::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
.sale-row{
  display:grid;
  grid-template-columns:52px 80px 1fr 64px 70px 80px;
  gap:8px;padding:6px 8px;
  border-bottom:1px solid var(--border-s);
  font-size:.78rem;align-items:center;
  transition:background .2s;
  animation:row-in .35s ease both;
}
.sale-row:nth-child(odd){background:rgba(255,255,255,0.018)}
.sale-row:hover{background:rgba(200,149,42,0.06)}
@keyframes row-in{from{opacity:0;transform:translateX(-12px)}to{opacity:1;transform:none}}
.sale-time{color:var(--muted);font-size:.68rem}
.sale-grower{
  font-family:'Bebas Neue',sans-serif;font-size:.92rem;
  color:var(--gold-l);letter-spacing:.06em;
}
.sale-name{color:var(--cream-dim);font-size:.72rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sale-grade{
  background:rgba(200,149,42,0.14);
  border:1px solid rgba(200,149,42,0.22);
  color:var(--gold-l);font-size:.6rem;
  padding:1px 5px;border-radius:2px;
  text-align:center;
}
.sale-mass{color:var(--cream-dim);font-size:.72rem;text-align:right}
.sale-price{color:var(--leaf-l);font-weight:600;text-align:right}

/* ─── RIGHT SIDEBAR ─── */
.right-panel{
  grid-column:2;grid-row:1/3;
  display:flex;flex-direction:column;
  border-left:1px solid var(--border);
  background:var(--bg2);
  overflow:hidden;
}

/* ─── BUYER LEADERBOARD ─── */
.panel-section{
  border-bottom:1px solid var(--border);
  flex:1;overflow:hidden;
  display:flex;flex-direction:column;
}
.panel-section:last-child{border-bottom:none}
.panel-head{
  padding:10px 14px;
  display:flex;align-items:center;gap:8px;
  border-bottom:1px solid var(--border-s);
  flex-shrink:0;
}
.panel-head h3{
  font-size:.6rem;font-weight:500;
  letter-spacing:.18em;text-transform:uppercase;
  color:var(--muted);
}
.buyer-rows{overflow-y:auto;flex:1}
.buyer-rows::-webkit-scrollbar{width:3px}
.buyer-rows::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
.buyer-row{
  display:flex;align-items:center;gap:8px;
  padding:7px 14px;
  border-bottom:1px solid var(--border-s);
  transition:background .15s;
}
.buyer-row:hover{background:rgba(200,149,42,0.05)}
.buyer-rank{
  font-family:'Bebas Neue',sans-serif;
  font-size:1.1rem;color:var(--gold-dim);
  width:22px;flex-shrink:0;text-align:center;
}
.buyer-rank.top{color:var(--gold)}
.buyer-info{flex:1;min-width:0}
.buyer-name{font-size:.78rem;color:var(--cream);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.buyer-code{font-size:.6rem;color:var(--muted)}
.buyer-stats{text-align:right;flex-shrink:0}
.buyer-usd{font-size:.82rem;font-weight:600;color:var(--leaf-l)}
.buyer-bales{font-size:.6rem;color:var(--muted)}

/* ─── GRADE DONUT ─── */
.grade-section{
  flex:1;overflow:hidden;display:flex;flex-direction:column;
}
.donut-wrap{
  flex:1;display:flex;flex-direction:column;
  align-items:center;justify-content:center;
  padding:12px;gap:10px;
}
.donut-svg{width:110px;height:110px;flex-shrink:0}
.grade-legend{
  width:100%;display:flex;flex-direction:column;gap:4px;
  overflow-y:auto;
}
.grade-legend::-webkit-scrollbar{width:0}
.legend-item{
  display:flex;align-items:center;gap:6px;
  font-size:.65rem;
}
.legend-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.legend-name{flex:1;color:var(--cream-dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.legend-pct{color:var(--gold);font-weight:600}

/* ─── STATS STRIP ─── */
.stats-strip{
  padding:8px 14px;
  display:flex;flex-direction:column;gap:6px;
  border-top:1px solid var(--border-s);
  flex-shrink:0;
}
.stat-row{display:flex;align-items:center;justify-content:space-between}
.stat-lbl{font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.stat-val{font-family:'Bebas Neue',sans-serif;font-size:1rem;letter-spacing:.06em;color:var(--gold-l)}

/* ─── FOOTER ─── */
.footer{
  position:fixed;bottom:0;left:0;right:0;height:44px;
  background:var(--bg3);border-top:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  padding:0 24px;z-index:100;
}
.footer-left{
  font-family:'Bebas Neue',sans-serif;
  font-size:1rem;letter-spacing:.18em;color:var(--cream-dim);
}
.footer-date{font-size:.7rem;color:var(--muted);letter-spacing:.06em}
.footer-right{font-size:.65rem;color:var(--muted);letter-spacing:.06em}

/* ─── NO DATA EMPTY STATE ─── */
.empty-state{
  display:flex;align-items:center;justify-content:center;
  flex:1;color:var(--muted);font-size:.75rem;letter-spacing:.08em;
}
</style>
</head>
<body>

<!-- WATERMARK -->
<div class="watermark">FLOWORK</div>

<!-- TICKER -->
<div class="ticker-wrap">
  <div class="ticker-label">● LIVE SALES</div>
  <div class="ticker-track">
    <div class="ticker-inner" id="ticker-inner">
      <div class="ticker-item"><span class="t-sep">Loading...</span></div>
      <div class="ticker-item"><span class="t-sep">Loading...</span></div>
    </div>
  </div>
</div>

<!-- TOP BAR -->
<div class="top-bar">
  <div class="logo"><span class="logo-dot"></span> FLOWORK</div>
  <div class="floor-tag"><?= $sp_name ?></div>
  <div class="spacer"></div>
  <div class="live-badge">
    <span class="live-dot"></span>
    LIVE &nbsp;·&nbsp; <span id="top-time">--:--:--</span>
  </div>
  <div class="clock" id="clock-display">--:--:--</div>
</div>

<!-- MAIN PAGE -->
<div class="page">

  <!-- KPI SECTION -->
  <div class="kpi-section">
    <div class="kpi-block">
      <div class="kpi-num" id="kpi-sold">—</div>
      <div class="kpi-lbl">Bales Sold</div>
      <div class="kpi-sub" id="kpi-sold-sub">&nbsp;</div>
    </div>
    <div class="kpi-block">
      <div class="kpi-num green" id="kpi-usd">—</div>
      <div class="kpi-lbl">USD Value</div>
      <div class="kpi-sub" id="kpi-usd-sub">&nbsp;</div>
    </div>
    <div class="kpi-block">
      <div class="kpi-num" id="kpi-avg">—</div>
      <div class="kpi-lbl">Avg $/kg</div>
      <div class="kpi-sub" id="kpi-avg-sub">&nbsp;</div>
    </div>
  </div>

  <!-- PROGRESS + SALES TABLE -->
  <div class="progress-section">
    <!-- Progress bar -->
    <div class="progress-header">
      <span class="progress-title">Floor Progress</span>
      <span class="progress-label" id="progress-label">—</span>
    </div>
    <div class="progress-bar-wrap">
      <div class="progress-bar-fill" id="progress-bar" style="width:0%">
        <span class="progress-pct" id="progress-pct"></span>
      </div>
    </div>

    <!-- Recent sales table -->
    <div class="sales-table">
      <div class="sales-head">
        <span>Time</span>
        <span>Grower #</span>
        <span>Name</span>
        <span>Grade</span>
        <span style="text-align:right">Mass</span>
        <span style="text-align:right">$/kg</span>
      </div>
      <div class="sales-rows" id="sales-rows">
        <div class="empty-state">Loading...</div>
      </div>
    </div>
  </div>

  <!-- RIGHT PANEL -->
  <div class="right-panel">

    <!-- Buyer Leaderboard -->
    <div class="panel-section" style="flex:1.4">
      <div class="panel-head">
        <h3>Buyer Leaderboard</h3>
      </div>
      <div class="buyer-rows" id="buyer-rows">
        <div class="empty-state" style="height:80px">Loading...</div>
      </div>
      <div class="stats-strip" id="stats-strip">
        <div class="stat-row">
          <span class="stat-lbl">Hi Price</span>
          <span class="stat-val" id="stat-hi">—</span>
        </div>
        <div class="stat-row">
          <span class="stat-lbl">Lo Price</span>
          <span class="stat-val" id="stat-lo">—</span>
        </div>
        <div class="stat-row">
          <span class="stat-lbl">Growers</span>
          <span class="stat-val" id="stat-growers">—</span>
        </div>
      </div>
    </div>

    <!-- Grade Donut -->
    <div class="panel-section grade-section">
      <div class="panel-head">
        <h3>Grade Distribution</h3>
      </div>
      <div class="donut-wrap">
        <svg class="donut-svg" viewBox="0 0 110 110" id="donut-svg">
          <circle cx="55" cy="55" r="40" fill="none" stroke="rgba(200,149,42,0.1)" stroke-width="18"/>
          <text x="55" y="55" text-anchor="middle" dominant-baseline="central"
                font-family="'Bebas Neue',sans-serif" font-size="11"
                fill="rgba(245,240,232,0.4)" id="donut-center">No Data</text>
        </svg>
        <div class="grade-legend" id="grade-legend"></div>
      </div>
    </div>

  </div>
</div>

<!-- FOOTER -->
<div class="footer">
  <div class="footer-left"><?= strtoupper($sp_name) ?></div>
  <div class="footer-date" id="footer-date"><?= date('l, d F Y') ?></div>
  <div class="footer-right">Auto-refreshes every 10 seconds</div>
</div>

<script>
var SPID = <?= $spid ?>;
var TODAY = '<?= $today ?>';
var ER = 0;
var isRefreshing = false;

// Grade palette
var GRADE_COLORS = [
  '#c8952a','#3d6b35','#e8b84b','#5a9e4e','#a07020',
  '#8bc34a','#ff9800','#2196f3','#9c27b0','#f44336'
];

// Clock
function updateClock() {
  var now = new Date();
  var h = String(now.getHours()).padStart(2,'0');
  var m = String(now.getMinutes()).padStart(2,'0');
  var s = String(now.getSeconds()).padStart(2,'0');
  var t = h+':'+m+':'+s;
  var el = document.getElementById('clock-display');
  if(el) el.textContent = t;
}
setInterval(updateClock, 1000);
updateClock();

// Format helpers
function fmt(n, dp, prefix) {
  if(n===null||n===undefined||n==='') return '—';
  var v = parseFloat(n);
  if(isNaN(v)) return '—';
  if(dp !== undefined) return (prefix||'') + v.toFixed(dp);
  return (prefix||'') + Math.round(v).toLocaleString('en');
}
function fmtK(n) {
  if(n===null||n===undefined) return '—';
  var v = parseFloat(n);
  if(isNaN(v)) return '—';
  if(v >= 1000000) return '$' + (v/1000000).toFixed(2) + 'M';
  if(v >= 1000) return '$' + (v/1000).toFixed(1) + 'K';
  return '$' + v.toFixed(0);
}
function fmtAvg(n) {
  if(n===null||n===undefined) return '—';
  var v = parseFloat(n);
  if(isNaN(v)) return '—';
  return '$' + v.toFixed(4);
}

// Build ticker HTML (doubled for seamless loop)
function buildTicker(sales) {
  if(!sales || !sales.length) return '<div class="ticker-item"><span class="t-sep">No sales yet today</span></div>';
  var last5 = sales.slice(0,5);
  var items = last5.map(function(r) {
    var p = parseFloat(r.price); if(p>10) p/=100;
    return '<div class="ticker-item">' +
      '<span class="t-grower">'+r.grower_num+'</span>' +
      '<span class="t-sep">·</span>' +
      '<span class="t-grade">'+(r.grade||'—')+'</span>' +
      '<span class="t-sep">·</span>' +
      '<span class="t-price">$'+p.toFixed(4)+'</span>' +
      '<span class="t-sep">·</span>' +
      '<span class="t-mass">'+parseFloat(r.mass).toFixed(1)+' kg</span>' +
      '</div>';
  }).join('');
  // Double for seamless loop
  return items + items;
}

// Build SVG donut (conic approach via stroke-dasharray)
function buildDonut(grades) {
  var svg = document.getElementById('donut-svg');
  var legend = document.getElementById('grade-legend');
  if(!grades || !grades.length) {
    svg.innerHTML = '<circle cx="55" cy="55" r="40" fill="none" stroke="rgba(200,149,42,0.1)" stroke-width="18"/>'+
      '<text x="55" y="55" text-anchor="middle" dominant-baseline="central" font-family="\'Bebas Neue\',sans-serif" font-size="11" fill="rgba(245,240,232,0.4)">No Data</text>';
    legend.innerHTML = '';
    return;
  }
  var total = grades.reduce(function(a,g){return a+(parseInt(g.cnt)||0)},0);
  if(!total){return;}

  var r = 40, cx = 55, cy = 55;
  var circumference = 2 * Math.PI * r;
  var offset = -circumference/4; // start at top
  var paths = '<circle cx="55" cy="55" r="40" fill="none" stroke="rgba(200,149,42,0.05)" stroke-width="18"/>';

  // Center label
  paths += '<text x="55" y="51" text-anchor="middle" dominant-baseline="central" font-family="\'Bebas Neue\',sans-serif" font-size="18" fill="'+GRADE_COLORS[0]+'">'+total+'</text>'+
            '<text x="55" y="65" text-anchor="middle" font-family="\'DM Sans\',sans-serif" font-size="7" fill="rgba(245,240,232,0.35)">BALES</text>';

  var legendHtml = '';
  grades.forEach(function(g, i) {
    var pct = parseInt(g.cnt)/total;
    var dash = circumference * pct;
    var gap  = circumference - dash;
    var color = GRADE_COLORS[i % GRADE_COLORS.length];
    var pctLabel = Math.round(pct*100);

    paths += '<circle cx="'+cx+'" cy="'+cy+'" r="'+r+'" fill="none"'+
      ' stroke="'+color+'" stroke-width="18"'+
      ' stroke-dasharray="'+dash.toFixed(2)+' '+gap.toFixed(2)+'"'+
      ' stroke-dashoffset="'+offset.toFixed(2)+'"'+
      ' style="transition:stroke-dasharray .8s ease"/>';

    offset -= dash;

    legendHtml += '<div class="legend-item">'+
      '<span class="legend-dot" style="background:'+color+'"></span>'+
      '<span class="legend-name">'+(g.grade||'Unknown')+'</span>'+
      '<span class="legend-pct">'+pctLabel+'%</span>'+
      '</div>';
  });

  svg.innerHTML = paths;
  legend.innerHTML = legendHtml;
}

// Main refresh
function refresh() {
  if(isRefreshing) return;
  isRefreshing = true;

  fetch('floor_tv.php?ajax=1&date='+TODAY+'&_='+Date.now())
    .then(function(r){return r.json();})
    .then(function(d) {
      isRefreshing = false;
      ER = parseFloat(d.er||0);

      // Clock sync
      var topTime = document.getElementById('top-time');
      if(topTime) topTime.textContent = d.time;

      var s = d.summary || {};
      var sold  = parseInt(s.sold||0);
      var total = parseInt(s.total_bales||0);
      var usd   = parseFloat(s.usd_value||0);
      var avg   = parseFloat(s.avg_price||0);

      // KPI — Bales Sold
      document.getElementById('kpi-sold').textContent = sold.toLocaleString('en');
      document.getElementById('kpi-sold-sub').textContent = 'of '+(s.ticketed||0)+' ticketed';

      // KPI — USD Value
      document.getElementById('kpi-usd').textContent = fmtK(usd);
      if(ER>0) document.getElementById('kpi-usd-sub').textContent = 'ZiG '+Math.round(usd*ER).toLocaleString('en');
      else document.getElementById('kpi-usd-sub').textContent = 'gross proceeds';

      // KPI — Avg $/kg
      document.getElementById('kpi-avg').textContent = avg>0 ? ('$'+avg.toFixed(4)) : '—';
      document.getElementById('kpi-avg-sub').innerHTML =
        'Hi: <span style="color:var(--gold-l)">$'+(s.hi_price?parseFloat(s.hi_price).toFixed(4):'—')+'</span>' +
        ' &nbsp; Lo: $'+(s.lo_price?parseFloat(s.lo_price).toFixed(4):'—');

      // Progress bar
      var pct = total > 0 ? Math.min(100, Math.round(sold/total*100)) : 0;
      document.getElementById('progress-bar').style.width = pct + '%';
      document.getElementById('progress-pct').textContent = pct>6 ? pct+'%' : '';
      document.getElementById('progress-label').textContent = sold+' of '+total+' bales sold ('+pct+'%)';

      // Sales rows
      var rows = document.getElementById('sales-rows');
      if(d.recent_sales && d.recent_sales.length) {
        rows.innerHTML = d.recent_sales.map(function(r, i) {
          var p = parseFloat(r.price); if(p>10) p/=100;
          return '<div class="sale-row" style="animation-delay:'+(i*0.03)+'s">'+
            '<span class="sale-time">'+r.sold_at+'</span>'+
            '<span class="sale-grower">'+r.grower_num+'</span>'+
            '<span class="sale-name">'+r.name+' '+(r.surname||'')+'</span>'+
            '<span class="sale-grade">'+(r.grade||'—')+'</span>'+
            '<span class="sale-mass">'+parseFloat(r.mass).toFixed(1)+' kg</span>'+
            '<span class="sale-price">$'+p.toFixed(4)+'</span>'+
            '</div>';
        }).join('');
      } else {
        rows.innerHTML = '<div class="empty-state">No sales yet today</div>';
      }

      // Ticker
      document.getElementById('ticker-inner').innerHTML = buildTicker(d.recent_sales);

      // Buyer leaderboard
      var buyerRows = document.getElementById('buyer-rows');
      if(d.by_buyer && d.by_buyer.length) {
        buyerRows.innerHTML = d.by_buyer.map(function(b, i) {
          return '<div class="buyer-row">'+
            '<span class="buyer-rank '+(i<3?'top':'')+'">'+  (i+1)+'</span>'+
            '<div class="buyer-info">'+
              '<div class="buyer-name">'+(b.buyer||b.code||'—')+'</div>'+
              '<div class="buyer-code">'+(b.code||'')+'</div>'+
            '</div>'+
            '<div class="buyer-stats">'+
              '<div class="buyer-usd">$'+fmt(b.usd,2)+'</div>'+
              '<div class="buyer-bales">'+b.bales+' bales</div>'+
            '</div>'+
            '</div>';
        }).join('');
      } else {
        buyerRows.innerHTML = '<div class="empty-state" style="height:80px">No buyer data</div>';
      }

      // Stats strip
      document.getElementById('stat-hi').textContent = s.hi_price ? '$'+parseFloat(s.hi_price).toFixed(4) : '—';
      document.getElementById('stat-lo').textContent = s.lo_price ? '$'+parseFloat(s.lo_price).toFixed(4) : '—';
      document.getElementById('stat-growers').textContent = s.growers || '0';

      // Grade donut
      buildDonut(d.grade_dist);

      // Footer date
      var fd = document.getElementById('footer-date');
      if(fd && d.date_label) fd.textContent = d.date_label;
    })
    .catch(function(e){ isRefreshing = false; console.error('TV refresh error:', e); });
}

// Start immediately, then every 10s
refresh();
setInterval(refresh, 10000);
</script>
</body>
</html>
