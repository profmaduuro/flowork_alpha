<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Dashboard";
$active_page = "dashboard";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);
$today = date('Y-m-d');


// Bulletproof query — returns [] on ANY failure, never crashes
function safe($conn, $sql) {
    if (!$conn) return [];
    $r = @$conn->query($sql);
    if (!$r || $r === true) return [];
    $row = @$r->fetch_assoc();
    return $row ?: [];
}
function safeAll($conn, $sql) {
    if (!$conn) return [];
    $r = @$conn->query($sql);
    if (!$r || $r === true) return [];
    return $r->fetch_all(MYSQLI_ASSOC) ?: [];
}

// Today's selling — don't filter by date strictly, use the start_of_day record
$sod    = safe($conn, "SELECT id FROM start_of_day WHERE description='$today' AND active=1 AND selling_pointid=$spid LIMIT 1");
$sodid  = (int)($sod['id'] ?? 0);

// Sales today — match by start_of_dayid if available, fallback to date
if ($sodid) {
    $sales = safe($conn, "SELECT COUNT(DISTINCT sb.id) bales, COALESCE(SUM(bm.mass),0) mass,
        COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) usd
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        WHERE (tk.start_of_dayid=$sodid OR DATE(sb.created_at)='$today')
          AND g.selling_pointid=$spid");
} else {
    $sales = safe($conn, "SELECT COUNT(DISTINCT sb.id) bales, COALESCE(SUM(bm.mass),0) mass,
        COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) usd
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        WHERE DATE(sb.created_at)='$today' AND g.selling_pointid=$spid");
}

// Season totals — all balanced batches regardless of stoporder_processed
$season = safe($conn, "SELECT COALESCE(SUM(dt.sold_bales),0) bales,
        COALESCE(SUM(dt.usd_value),0) usd,
        COUNT(DISTINCT bb.id) batches,
        COALESCE(SUM(dt.mass),0) mass
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    WHERE g.selling_pointid=$spid");

// Today's balanced totals
$today_bal = safe($conn, "SELECT COALESCE(SUM(dt.sold_bales),0) bales,
        COALESCE(SUM(dt.usd_value),0) usd,
        COUNT(DISTINCT bb.id) batches
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    WHERE g.selling_pointid=$spid AND DATE(bb.created_at)='$today'");

// Booked today
$booked_today = safe($conn, "SELECT COUNT(*) c FROM transporter_growers tg
    JOIN growers g ON g.id=tg.growerid
    WHERE ($spid=0 OR g.selling_pointid=$spid OR tg.selling_pointid=$spid) AND tg.booked=1
    AND (tg.sell_date='$today' OR DATE(tg.created_at)='$today')");

// Pending balance
$pending_bal = safe($conn, "SELECT COUNT(*) c FROM transporter_growers tg
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
    WHERE ($spid=0 OR g.selling_pointid=$spid OR tg.selling_pointid=$spid) AND tg.booked=1 AND bb.id IS NULL");

$er     = safe($conn, "SELECT amount FROM exchange_rate ORDER BY id DESC LIMIT 1");
$recent = safeAll($conn, "SELECT g.grower_num, CONCAT(g.name,' ',g.surname) grower_name,
        dt.sold_bales bales, dt.usd_value gross, dt.mass,
        COALESCE(dtn.usd_net_value,0) net_usd,
        bb.id bbid, bb.created_at bal_date
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
    WHERE g.selling_pointid=$spid
    ORDER BY bb.id DESC LIMIT 10");

// Booked growers list
$booked_list = safeAll($conn, "SELECT g.grower_num,
        CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
        tg.bales, tg.sell_date, t.name transporter,
        CASE WHEN bb.id IS NOT NULL THEN 1 ELSE 0 END is_balanced,
        bb.id bbid
    FROM transporter_growers tg
    JOIN growers g ON g.id=tg.growerid
    JOIN transporter t ON t.id=tg.transporterid
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
    WHERE ($spid=0 OR g.selling_pointid=$spid OR tg.selling_pointid=$spid) AND tg.booked=1
    ORDER BY is_balanced ASC, tg.sell_date DESC, g.grower_num
    LIMIT 50");

$zig_rate = (float)($er['amount'] ?? 0);
// Chart data: last 14 days sales
$chart_days = [];
$chart_bales = [];
$chart_usd = [];
$chart_q = $conn->query("
    SELECT DATE(bb.created_at) day,
           COALESCE(SUM(dt.sold_bales),0) bales,
           COALESCE(SUM(dt.usd_value),0) usd
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    WHERE (g.selling_pointid=$spid OR $spid=0)
      AND bb.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY DATE(bb.created_at)
    ORDER BY day ASC
");
if ($chart_q && $chart_q !== true) {
    while ($cr = $chart_q->fetch_assoc()) {
        $chart_days[]  = date('d M', strtotime($cr['day']));
        $chart_bales[] = (int)$cr['bales'];
        $chart_usd[]   = round((float)$cr['usd'], 2);
    }
}

// ── ANALYTICS CHARTS DATA ──────────────────────────────────────────────────

// Grade distribution (today) — doughnut chart
$grade_dist = [];
$gd_q = $conn->query("
    SELECT tg.description grade, COUNT(*) cnt, COALESCE(SUM(bm.mass),0) mass
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    WHERE DATE(sb.created_at)='$today' AND (g.selling_pointid=$spid OR $spid=0)
      AND tg.description IS NOT NULL
    GROUP BY tg.description ORDER BY cnt DESC LIMIT 12
");
if ($gd_q && $gd_q !== true) while ($r = $gd_q->fetch_assoc()) $grade_dist[] = $r;

// Buyer market share (today) — doughnut chart
$buyer_share = [];
$bs_q = $conn->query("
    SELECT b.name buyer, COUNT(*) cnt,
           COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) usd
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    LEFT JOIN buyer b ON b.id=bg.buyerid
    WHERE DATE(sb.created_at)='$today' AND (g.selling_pointid=$spid OR $spid=0)
      AND b.name IS NOT NULL
      AND NOT (LEFT(UPPER(TRIM(sb.sale_code)),1)='R' AND CHAR_LENGTH(TRIM(sb.sale_code))<=3)
    GROUP BY b.name ORDER BY usd DESC LIMIT 8
");
if ($bs_q && $bs_q !== true) while ($r = $bs_q->fetch_assoc()) $buyer_share[] = $r;

// Top growers by value (today) — horizontal bar chart
$top_growers = [];
$tg_q = $conn->query("
    SELECT g.grower_num, CONCAT(g.name,' ',g.surname) grower_name,
           COUNT(*) bales,
           COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) usd
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    WHERE DATE(sb.created_at)='$today' AND (g.selling_pointid=$spid OR $spid=0)
      AND NOT (LEFT(UPPER(TRIM(sb.sale_code)),1)='R' AND CHAR_LENGTH(TRIM(sb.sale_code))<=3)
    GROUP BY g.id ORDER BY usd DESC LIMIT 8
");
if ($tg_q && $tg_q !== true) while ($r = $tg_q->fetch_assoc()) $top_growers[] = $r;

// Rejection trend (last 14 days) — line chart
$rej_trend = [];
$rt_q = $conn->query("
    SELECT DATE(sb.created_at) day,
           COUNT(*) total,
           SUM(CASE WHEN LEFT(UPPER(TRIM(sb.sale_code)),1)='R' AND CHAR_LENGTH(TRIM(sb.sale_code))<=3 THEN 1 ELSE 0 END) rejected
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    WHERE (g.selling_pointid=$spid OR $spid=0)
      AND sb.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY DATE(sb.created_at) ORDER BY day ASC
");
if ($rt_q && $rt_q !== true) while ($r = $rt_q->fetch_assoc()) $rej_trend[] = $r;

// Price heatmap data: avg price by grade × buyer (today)
$price_heatmap = [];
$ph_q = $conn->query("
    SELECT tg.description grade, b.name buyer,
           AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END) avg_price,
           COUNT(*) cnt
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    LEFT JOIN buyer b ON b.id=bg.buyerid
    WHERE DATE(sb.created_at)='$today' AND (g.selling_pointid=$spid OR $spid=0)
      AND tg.description IS NOT NULL AND b.name IS NOT NULL
      AND NOT (LEFT(UPPER(TRIM(sb.sale_code)),1)='R' AND CHAR_LENGTH(TRIM(sb.sale_code))<=3)
    GROUP BY tg.description, b.name ORDER BY tg.description, b.name
");
if ($ph_q && $ph_q !== true) while ($r = $ph_q->fetch_assoc()) $price_heatmap[] = $r;

// Compute average price per kg today
$today_mass = (float)($sales['mass'] ?? 0);
$today_usd  = (float)($sales['usd'] ?? 0);
$avg_price  = $today_mass > 0 ? $today_usd / $today_mass : 0;

// Compute % of booked that are balanced
$booked_count  = (int)($booked_today['c'] ?? 0);
$pending_count = (int)($pending_bal['c'] ?? 0);
$balanced_pct  = $booked_count > 0 ? round((($booked_count - $pending_count) / $booked_count) * 100) : 0;

include "includes/layout.php";
?>

<style>
/* ═══════════════════════════════════════════════════════
   DASHBOARD 2026 — Modern Glassmorphic Dashboard
   ═══════════════════════════════════════════════════════ */

/* ── Animations ──────────────────────────────────────── */
@keyframes dash-fadeUp {
  from { opacity: 0; transform: translateY(24px); }
  to   { opacity: 1; transform: translateY(0); }
}
@keyframes dash-shimmer {
  0%   { background-position: -200% 0; }
  100% { background-position: 200% 0; }
}
@keyframes dash-pulse {
  0%, 100% { opacity: 1; }
  50%      { opacity: .6; }
}
@keyframes dash-glow {
  0%, 100% { box-shadow: 0 0 15px rgba(200,149,42,.15); }
  50%      { box-shadow: 0 0 30px rgba(200,149,42,.25); }
}
@keyframes dash-gradientShift {
  0%   { background-position: 0% 50%; }
  50%  { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}
@keyframes dash-countUp {
  from { opacity: 0; transform: scale(.85); }
  to   { opacity: 1; transform: scale(1); }
}
@keyframes dash-borderGlow {
  0%   { opacity: .5; }
  50%  { opacity: 1; }
  100% { opacity: .5; }
}
@keyframes dash-rotate {
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}

/* ── Dashboard Header ────────────────────────────────── */
.dash-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 1.75rem;
  animation: dash-fadeUp .5s ease both;
}
.dash-header-left h2 {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 2rem;
  letter-spacing: .1em;
  line-height: 1;
  background: linear-gradient(135deg, var(--gold), var(--gold-l), var(--leaf));
  background-size: 200% 200%;
  animation: dash-gradientShift 4s ease infinite;
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}
.dash-header-left .dash-subtitle {
  display: flex;
  align-items: center;
  gap: .6rem;
  font-size: .8rem;
  color: var(--muted);
  margin-top: .35rem;
}
.dash-live-dot {
  width: 8px; height: 8px;
  background: var(--leaf);
  border-radius: 50%;
  animation: dash-pulse 2s ease infinite;
  flex-shrink: 0;
}
.dash-clock {
  font-variant-numeric: tabular-nums;
  font-weight: 500;
  color: var(--gold);
  font-size: .78rem;
  letter-spacing: .04em;
}
.dash-actions {
  display: flex;
  gap: .5rem;
  flex-wrap: wrap;
}
.dash-actions .btn {
  border-radius: 8px;
  font-weight: 500;
  gap: 6px;
  transition: all .2s cubic-bezier(.4,0,.2,1);
}
.dash-actions .btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 16px rgba(0,0,0,.15);
}

/* ── KPI Grid (2026 Glass) ───────────────────────────── */
.dash-kpi-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: .75rem;
  margin-bottom: 1.5rem;
}
.dash-kpi {
  position: relative;
  border-radius: 12px;
  padding: 0.85rem 1rem 0.85rem 1.25rem;
  overflow: hidden;
  transition: all .25s cubic-bezier(.4,0,.2,1);
  animation: dash-fadeUp .5s ease both;
  background: #fff;
  border: 1px solid var(--border);
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
  display: flex;
  align-items: center;
  gap: 0.85rem;
}
body.theme-light .dash-kpi {
  background: #fff;
  border-color: rgba(26,18,8,.08);
  box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.dash-kpi:nth-child(1) { animation-delay: .05s; }
.dash-kpi:nth-child(2) { animation-delay: .10s; }
.dash-kpi:nth-child(3) { animation-delay: .15s; }
.dash-kpi:nth-child(4) { animation-delay: .20s; }
.dash-kpi:nth-child(5) { animation-delay: .25s; }
.dash-kpi:hover {
  transform: translateY(-2px);
  border-color: rgba(200,149,42,.25);
  box-shadow: 0 6px 20px rgba(0,0,0,.06), 0 0 0 1px rgba(200,149,42,.08);
}
/* Gradient accent line */
.dash-kpi::before {
  content: '';
  position: absolute;
  top: 0; left: 0; bottom: 0;
  width: 4px;
  background: var(--kpi-accent, linear-gradient(180deg, var(--gold), var(--gold-l)));
  opacity: .8;
  transition: opacity .2s;
}
.dash-kpi:hover::before { opacity: 1; }

.dash-kpi-icon {
  width: 38px; height: 38px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.15rem;
  flex-shrink: 0;
  background: var(--gold-bg);
  margin-bottom: 0;
}
.dash-kpi-label {
  font-size: .58rem;
  font-weight: 600;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: .2rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.dash-kpi-value {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.75rem;
  letter-spacing: .02em;
  line-height: 1.1;
  color: var(--ink);
  animation: dash-countUp .4s ease both;
}
.dash-kpi-value.sm { font-size: 1.4rem; }
.dash-kpi-sub {
  font-size: .68rem;
  color: var(--muted);
  margin-top: .3rem;
  display: flex;
  align-items: center;
  gap: .35rem;
}
.dash-kpi-sub .dash-tag {
  display: inline-flex;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: .58rem;
  font-weight: 600;
  letter-spacing: .05em;
}
.dash-tag-gold { background: var(--gold-bg); color: #92650e; }
.dash-tag-green { background: var(--leaf-l); color: #2d5226; }

/* ── Main Dashboard Grid ─────────────────────────────── */
.dash-grid {
  display: grid;
  grid-template-columns: 1.1fr .9fr;
  gap: 1rem;
}

/* ── Modern Card ─────────────────────────────────────── */
.dash-card {
  border-radius: 12px;
  overflow: hidden;
  animation: dash-fadeUp .5s ease both;
  background: #fff;
  border: 1px solid var(--border);
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
  transition: box-shadow .25s;
}
.dash-card:hover {
  box-shadow: 0 4px 20px rgba(0,0,0,.06);
}
.dash-card-head {
  padding: .85rem 1.15rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .75rem;
  border-bottom: 1px solid var(--border);
}
.dash-card-head h3 {
  font-size: .68rem;
  font-weight: 600;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--muted);
  display: flex;
  align-items: center;
  gap: .5rem;
}
.dash-card-head h3 .head-icon {
  width: 18px; height: 18px;
  opacity: .5;
  flex-shrink: 0;
}

/* ── Progress Ring (Booking Status) ──────────────────── */
.dash-progress-ring {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem 1.15rem;
}
.dash-ring-svg {
  width: 72px; height: 72px;
  flex-shrink: 0;
  transform: rotate(-90deg);
}
.dash-ring-bg {
  fill: none;
  stroke-width: 5;
  stroke: rgba(200,149,42,.1);
}
.dash-ring-fg {
  fill: none;
  stroke-width: 5;
  stroke-linecap: round;
  stroke: var(--gold);
  transition: stroke-dashoffset .8s cubic-bezier(.4,0,.2,1);
}
.dash-ring-text {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1rem;
  fill: var(--gold);
  transform: rotate(90deg);
  transform-origin: center;
}
.dash-ring-info {
  flex: 1;
}
.dash-ring-info .ring-label {
  font-size: .62rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .12em;
  color: var(--muted);
  margin-bottom: .3rem;
}
.dash-ring-info .ring-stat {
  font-size: .82rem;
  margin-bottom: .15rem;
}
.dash-ring-info .ring-stat strong {
  color: var(--gold);
  font-weight: 600;
}

/* ── Table Styles (enhanced) ─────────────────────────── */
.dash-table-wrap {
  overflow-x: auto;
  overflow-y: auto;
}
.dash-table-wrap table {
  width: 100%;
  border-collapse: collapse;
  font-size: .78rem;
}
.dash-table-wrap thead th {
  font-size: .58rem;
  font-weight: 600;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--muted);
  padding: .6rem .9rem;
  text-align: left;
  white-space: nowrap;
  position: sticky;
  top: 0;
  z-index: 2;
}
.dash-table-wrap thead th {
  background: var(--cream);
  border-bottom: 1px solid var(--border);
}
.dash-table-wrap tbody tr {
  transition: all .15s;
  border-bottom: 1px solid var(--border);
}
.dash-table-wrap tbody tr:last-child { border-bottom: none; }
.dash-table-wrap tbody tr:hover {
  background: #faf8f5;
}
.dash-table-wrap tbody td {
  padding: .55rem .9rem;
  vertical-align: middle;
}

/* ── Status Dots ─────────────────────────────────────── */
.status-dot {
  width: 7px; height: 7px;
  border-radius: 50%;
  display: inline-block;
  margin-right: 5px;
  vertical-align: middle;
}
.status-dot.balanced { background: var(--leaf); }
.status-dot.booked { background: var(--gold); animation: dash-pulse 2s ease infinite; }

/* ── Quick Links Grid (2026) ─────────────────────────── */
.dash-links-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .45rem;
  padding: .75rem;
}
.dash-link {
  display: flex;
  align-items: center;
  gap: .6rem;
  padding: .55rem .75rem;
  border-radius: 10px;
  text-decoration: none;
  color: var(--text-strong);
  font-size: .78rem;
  font-weight: 500;
  transition: all .2s cubic-bezier(.4,0,.2,1);
  border: 1px solid transparent;
  background: var(--cream-d);
}
.dash-link:hover {
  border-color: var(--gold);
  transform: translateX(3px);
  background: var(--gold-bg);
  color: var(--gold);
}
.dash-link-icon {
  width: 30px; height: 30px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: .95rem;
  flex-shrink: 0;
  background: var(--gold-bg);
}

/* ── Chart Card ──────────────────────────────────────── */
.dash-chart-wrap {
  padding: .85rem 1rem;
}

/* ── Badge Updates ───────────────────────────────────── */
.dash-badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: .6rem;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  white-space: nowrap;
}
.dash-badge-gold {
  background: var(--gold-bg);
  color: #92650e;
  border: 1px solid #e8c96a;
}
.dash-badge-green {
  background: var(--leaf-l);
  color: #2a5223;
  border: 1px solid #9bcb94;
}
.dash-badge-gray {
  background: var(--cream);
  color: var(--muted);
  border: 1px solid var(--border);
}

/* ── Average Price Indicator ──────────────────────────── */
.dash-avg-price {
  display: flex;
  align-items: center;
  gap: .45rem;
  padding: .65rem 1rem;
  border-top: 1px solid var(--border);
  font-size: .75rem;
}
.dash-avg-icon {
  font-size: .9rem;
}
.dash-avg-label {
  color: var(--muted);
  font-size: .62rem;
  text-transform: uppercase;
  letter-spacing: .1em;
  font-weight: 600;
}
.dash-avg-val {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.1rem;
  color: var(--gold);
  letter-spacing: .02em;
}

/* ── Responsive ──────────────────────────────────────── */
@media(max-width:1100px) {
  .dash-kpi-grid { grid-template-columns: repeat(3, 1fr); }
}
@media(max-width:900px) {
  .dash-kpi-grid { grid-template-columns: repeat(2, 1fr); }
  .dash-grid { grid-template-columns: 1fr; }
  .dash-header { flex-direction: column; align-items: flex-start; }
}
@media(max-width:600px) {
  .dash-kpi-grid { grid-template-columns: 1fr 1fr; }
  .dash-kpi-value { font-size: 1.6rem; }
  .dash-links-grid { grid-template-columns: 1fr; }
}
@media(max-width:400px) {
  .dash-kpi-grid { grid-template-columns: 1fr; }
}
/* ── Premium Capsule Tabs ────────────────────────────── */
.dash-tabs {
  display: inline-flex;
  gap: 6px;
  background: rgba(26,18,8,0.03);
  padding: 5px;
  border-radius: 12px;
  border: 1px solid rgba(26,18,8,0.06);
  margin-bottom: 1.5rem;
}
.tab-btn {
  background: transparent;
  border: none;
  padding: 8px 18px;
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--muted);
  border-radius: 9px;
  cursor: pointer;
  transition: all 0.2s cubic-bezier(.4,0,.2,1);
  display: flex;
  align-items: center;
  gap: 6px;
  font-family: inherit;
  outline: none;
}
.tab-btn:hover {
  color: var(--ink);
  background: rgba(26,18,8,0.02);
}
.tab-btn.active {
  background: #fff;
  color: var(--gold);
  box-shadow: 0 4px 12px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.02);
}
body.theme-dark .dash-tabs {
  background: rgba(255,255,255,0.03);
  border-color: rgba(255,255,255,0.08);
}
body.theme-dark .tab-btn:hover {
  color: var(--cream);
  background: rgba(255,255,255,0.02);
}
body.theme-dark .tab-btn.active {
  background: rgba(255,255,255,0.07);
  color: var(--gold-l);
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
body.theme-dark .dash-kpi,
body.theme-dark .dash-card {
  background: #1f1a14;
  border-color: rgba(245,240,232,0.10);
  box-shadow: 0 1px 3px rgba(0,0,0,.35);
}
/* --gold-bg is a light tint; on dark surfaces it needs a dark tint instead */
body.theme-dark .dash-kpi-icon,
body.theme-dark .dash-link-icon { background: rgba(200,149,42,.16); }
body.theme-dark .dash-link { background: rgba(245,240,232,.05); }
body.theme-dark .dash-link:hover { background: rgba(200,149,42,.16); }
.tab-section {
  animation: dash-fadeUp 0.4s ease both;
}
</style>

<!-- ══════════ DASHBOARD HEADER ══════════ -->
<div class="dash-header">
  <div class="dash-header-left">
    <h2>Dashboard</h2>
    <div class="dash-subtitle">
      <span class="dash-live-dot"></span>
      <span><?=date('l, d F Y')?></span>
      <span style="opacity:.3">·</span>
      <span><?=htmlspecialchars($_SESSION['selling_point_name']??'All Floors')?></span>
      <span style="opacity:.3">·</span>
      <span class="dash-clock" id="dash-clock"></span>
    </div>
  </div>
  <div class="dash-actions">
    <a href="grower_booking.php" class="btn btn-ghost btn-sm" style="border-radius:8px">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="12" height="12" rx="1"/><path d="M5 8h6M8 5v6"/></svg>
      Bookings
    </a>
    <a href="balance.php" class="btn btn-primary btn-sm" style="border-radius:8px">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1L1 5v6l7 4 7-4V5L8 1z"/></svg>
      Balance
    </a>
    <a href="selling.php" class="btn btn-gold btn-sm" style="border-radius:8px">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M8 4v4l3 2"/></svg>
      Selling Floor
    </a>
<!-- PWA Offline Warning Banner -->
<div id="pwa-offline-warning" style="display:none; align-items:center; gap:10px; background:rgba(192,57,43,0.1); border:1px solid rgba(192,57,43,0.25); color:var(--danger); padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:0.85rem; font-weight:500">
  <span style="font-size:1.1rem">⚠</span>
  <span>You are currently offline. Displaying cached dashboard data from the last online session. Some options or live sync features may be limited.</span>
</div>

<!-- ══════════ DASHBOARD CONTENT WRAPPER ══════════ -->
<div id="dashboard-content-wrapper">

<!-- ══════════ KPI STRIP ══════════ -->
<div class="dash-kpi-grid">

  <!-- Bales Sold Today -->
  <div class="dash-kpi" style="--kpi-accent: linear-gradient(180deg, var(--gold), var(--gold-l))">
    <div class="dash-kpi-icon">📦</div>
    <div style="display:flex; flex-direction:column; flex:1; min-width:0">
      <div class="dash-kpi-label">Bales Sold Today</div>
      <div class="dash-kpi-value" data-count="<?=(int)($sales['bales']??0)?>"><?=number_format((int)($sales['bales']??0))?></div>
      <div class="dash-kpi-sub">
        <span class="dash-tag dash-tag-gold"><?=number_format((float)($sales['mass']??0),0)?> kg</span>
      </div>
    </div>
  </div>

  <!-- Gross USD Today -->
  <div class="dash-kpi" style="--kpi-accent: linear-gradient(180deg, #e8b84b, #c8952a)">
    <div class="dash-kpi-icon">💵</div>
    <div style="display:flex; flex-direction:column; flex:1; min-width:0">
      <div class="dash-kpi-label">Gross USD Today</div>
      <div class="dash-kpi-value sm" data-count="<?=(float)($sales['usd']??0)?>">$<?=number_format((float)($sales['usd']??0),2)?></div>
      <div class="dash-kpi-sub">
        <?php if($zig_rate>0):?>
          <span class="dash-tag dash-tag-green">≈ <?=number_format((float)($sales['usd']??0)*$zig_rate,0)?> ZiG</span>
        <?php endif;?>
      </div>
    </div>
  </div>

  <!-- Balanced Today -->
  <div class="dash-kpi" style="--kpi-accent: linear-gradient(180deg, var(--leaf), #7cc576)">
    <div class="dash-kpi-icon">⚖️</div>
    <div style="display:flex; flex-direction:column; flex:1; min-width:0">
      <div class="dash-kpi-label">Balanced Today</div>
      <div class="dash-kpi-value" data-count="<?=(int)($today_bal['batches']??0)?>"><?=(int)($today_bal['batches']??0)?></div>
      <div class="dash-kpi-sub">
        <span class="dash-tag dash-tag-gold">$<?=number_format((float)($today_bal['usd']??0),0)?> gross</span>
      </div>
    </div>
  </div>

  <!-- Booked Today -->
  <div class="dash-kpi" style="--kpi-accent: linear-gradient(180deg, #c8952a, var(--leaf))">
    <div class="dash-kpi-icon">📋</div>
    <div style="display:flex; flex-direction:column; flex:1; min-width:0">
      <div class="dash-kpi-label">Booked Today</div>
      <div class="dash-kpi-value" data-count="<?=$booked_count?>"><?=$booked_count?></div>
      <div class="dash-kpi-sub">
        <span class="dash-tag dash-tag-gold"><?=$pending_count?> pending</span>
      </div>
    </div>
  </div>

  <!-- Season Totals -->
  <div class="dash-kpi" style="--kpi-accent: linear-gradient(180deg, var(--gold-l), var(--leaf), var(--gold))">
    <div class="dash-kpi-icon">🏆</div>
    <div style="display:flex; flex-direction:column; flex:1; min-width:0">
      <div class="dash-kpi-label">Season Total</div>
      <div class="dash-kpi-value sm">$<?=number_format((float)($season['usd']??0),0)?></div>
      <div class="dash-kpi-sub">
        <span class="dash-tag dash-tag-gold"><?=number_format((int)($season['bales']??0))?> bales</span>
        <span class="dash-tag dash-tag-green"><?=(int)($season['batches']??0)?> batches</span>
      </div>
    </div>
  </div>

</div>

<div class="dash-tabs no-print" style="margin-top:0.5rem">
  <button class="tab-btn active" data-tab="overview">⚡ Overview</button>
  <button class="tab-btn" data-tab="operations">📋 Operations</button>
  <button class="tab-btn" data-tab="analytics">📊 Analytics Suite</button>
</div>

<!-- ══════════ OVERVIEW TAB ══════════ -->
<div id="tab-overview" class="tab-section">

<!-- ══════════ MAIN GRID (OVERVIEW TAB) ══════════ -->
<div class="dash-grid" style="grid-template-columns: 0.95fr 1.05fr">

  <!-- Left: Progress & Actions -->
  <div style="display:flex;flex-direction:column;gap:1.25rem">
    <!-- Booking Progress Ring -->
    <div class="dash-card" style="animation-delay:.3s">
      <div class="dash-card-head">
        <h3>
          <svg class="head-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M8 4v4l3 2"/></svg>
          Today's Progress
        </h3>
      </div>
      <div class="dash-progress-ring">
        <?php
          $circumference = 2 * M_PI * 30;
          $offset = $circumference - ($balanced_pct / 100) * $circumference;
        ?>
        <svg class="dash-ring-svg" viewBox="0 0 72 72">
          <circle class="dash-ring-bg" cx="36" cy="36" r="30"/>
          <circle class="dash-ring-fg" cx="36" cy="36" r="30"
                  stroke-dasharray="<?=$circumference?>"
                  stroke-dashoffset="<?=$offset?>"/>
          <text class="dash-ring-text" x="36" y="40" text-anchor="middle"><?=$balanced_pct?>%</text>
        </svg>
        <div class="dash-ring-info">
          <div class="ring-label">Balancing Completion</div>
          <div class="ring-stat"><strong><?=$booked_count - $pending_count?></strong> of <strong><?=$booked_count?></strong> balanced</div>
          <div class="ring-stat" style="color:var(--muted);font-size:.72rem"><?=$pending_count?> still pending</div>
          <div style="margin-top:.5rem">
            <a href="balance.php" class="btn btn-ghost btn-sm" style="border-radius:8px;font-size:.62rem">Go to Balance →</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Quick Links -->
    <div class="dash-card" style="animation-delay:.4s">
      <div class="dash-card-head">
        <h3>
          <svg class="head-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2h10v12H3z"/><path d="M6 6h4M6 9h2"/></svg>
          Quick Actions
        </h3>
      </div>
      <div class="dash-links-grid">
        <?php foreach([
          ['grower_booking.php','📋','Grower Booking'],
          ['scale.php','⚖️','Scale / Weigh'],
          ['junus.php','🏷️','Bale Junus'],
          ['tickets.php','🎫','Print Tickets'],
          ['selling.php','🔨','Floor Selling'],
          ['balance.php','💰','Balance'],
          ['sales_sheets.php','📄','Sales Sheets'],
          ['creditor_portal.php','🏦','Creditors'],
          ['file_manager_uploads.php','📂','File Manager'],
          ['settings.php','⚙️','Settings'],
        ] as [$url,$icon,$label]):?>
        <a href="<?=$url?>" class="dash-link">
          <span class="dash-link-icon"><?=$icon?></span>
          <span><?=$label?></span>
        </a>
        <?php endforeach;?>
      </div>
    </div>
  </div>

  <!-- Right: Chart -->
  <div style="display:flex;flex-direction:column;gap:1.25rem">
    <!-- Performance Chart -->
    <?php if(!empty($chart_days)): ?>
    <div class="dash-card" style="animation-delay:.4s">
      <div class="dash-card-head">
        <h3>
          <svg class="head-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="1 12 5 7 9 10 15 3"/><line x1="1" y1="15" x2="15" y2="15"/></svg>
          14-Day Performance
        </h3>
      </div>
      <div class="dash-chart-wrap" style="padding:1.25rem;height:280px">
        <canvas id="perf-chart" style="width:100%;height:100%"></canvas>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div><!-- end dash-grid -->
</div><!-- end #tab-overview -->

<!-- ══════════ OPERATIONS TAB ══════════ -->
<div id="tab-operations" class="tab-section" style="display:none">
  <div class="dash-grid" style="grid-template-columns: 1.1fr 0.9fr">
    <!-- Left: Booked Growers -->
    <div style="display:flex;flex-direction:column;gap:1.25rem">
      <div class="dash-card" style="animation-delay:.3s">
        <div class="dash-card-head">
          <h3>
            <svg class="head-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5" r="3"/><path d="M2 14c0-3.3 2.7-6 6-6s6 2.7 6 6"/></svg>
            Booked Growers
          </h3>
          <div style="display:flex;align-items:center;gap:.5rem">
            <span class="dash-badge dash-badge-gray"><?=count($booked_list)?></span>
            <a href="grower_booking.php" class="btn btn-ghost btn-sm" style="border-radius:8px;font-size:.65rem">Manage →</a>
          </div>
        </div>
        <?php if(empty($booked_list)):?>
        <div style="padding:2.5rem 1rem;text-align:center;color:var(--muted);font-size:.82rem">
          <div style="font-size:2rem;margin-bottom:.5rem;opacity:.4">📋</div>
          No growers booked yet. <a href="grower_booking.php" style="color:var(--gold);text-decoration:underline">Go to Booking →</a>
        </div>
        <?php else:?>
        <div class="dash-table-wrap" style="max-height:480px">
          <table>
            <thead><tr>
              <th>Grower</th><th>Transporter</th>
              <th class="text-right">Bales</th><th>Sell Date</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach($booked_list as $i => $r):
              $is_bal = (int)$r['is_balanced'];
            ?>
            <tr style="<?=$is_bal?'opacity:.5':''?>">
              <td>
                <span class="dash-badge dash-badge-gold" style="font-size:.55rem"><?=htmlspecialchars($r['grower_num'])?></span>
                <span style="font-size:.75rem;margin-left:4px"><?=htmlspecialchars($r['grower_name'])?></span>
              </td>
              <td style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($r['transporter'])?></td>
              <td class="td-mono text-right" style="font-weight:600"><?=(int)$r['bales']?></td>
              <td style="font-size:.72rem"><?=htmlspecialchars($r['sell_date']??'')?></td>
              <td>
                <?php if($is_bal):?>
                  <span style="display:inline-flex;align-items:center;gap:4px">
                    <span class="status-dot balanced"></span>
                    <span style="font-size:.62rem;color:var(--leaf);font-weight:600;text-transform:uppercase;letter-spacing:.06em">Balanced</span>
                  </span>
                <?php else:?>
                  <span style="display:inline-flex;align-items:center;gap:4px">
                    <span class="status-dot booked"></span>
                    <span style="font-size:.62rem;color:var(--gold);font-weight:600;text-transform:uppercase;letter-spacing:.06em">Booked</span>
                  </span>
                <?php endif;?>
              </td>
            </tr>
            <?php endforeach;?>
            </tbody>
          </table>
        </div>
        <?php endif;?>
        <!-- Avg Price Footer -->
        <div class="dash-avg-price">
          <span class="dash-avg-icon">📊</span>
          <div>
            <div class="dash-avg-label">Avg Price /kg Today</div>
            <div class="dash-avg-val">$<?=number_format($avg_price, 2)?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Right: Recent Balances -->
    <div style="display:flex;flex-direction:column;gap:1.25rem">
      <div class="dash-card" style="animation-delay:.35s">
        <div class="dash-card-head">
          <h3>
            <svg class="head-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1L1 5v6l7 4 7-4V5L8 1z"/></svg>
            Recent Balances
          </h3>
        </div>
        <?php if(!empty($recent)):?>
        <div class="dash-table-wrap" style="max-height:540px">
          <table>
            <thead><tr>
              <th>Grower</th><th class="text-right">Bales</th>
              <th class="text-right">Gross</th><th class="text-right">Net USD</th>
              <th></th>
            </tr></thead>
            <tbody>
            <?php foreach($recent as $r):?>
            <tr>
              <td>
                <span class="dash-badge dash-badge-gold" style="font-size:.55rem"><?=htmlspecialchars($r['grower_num'])?></span>
                <span style="font-size:.73rem;margin-left:4px"><?=htmlspecialchars($r['grower_name'])?></span>
              </td>
              <td class="td-mono text-right" style="font-weight:500"><?=(int)($r['bales']??0)?></td>
              <td class="td-mono text-right">$<?=number_format((float)($r['gross']??0),2)?></td>
              <td class="td-mono text-right" style="color:var(--leaf);font-weight:600">
                $<?=number_format((float)($r['net_usd']??0),2)?>
              </td>
              <td>
                <?php if($r['bbid']):?>
                <a href="print_sheet.php?bbid=<?=$r['bbid']?>" target="_blank"
                   class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px">Sheet</a>
                <?php endif;?>
              </td>
            </tr>
            <?php endforeach;?>
            </tbody>
          </table>
        </div>
        <?php else:?>
        <div style="padding:2rem 1rem;text-align:center;color:var(--muted);font-size:.82rem">
          <div style="font-size:2rem;margin-bottom:.5rem;opacity:.4">⚖️</div>
          No balances yet.
        </div>
        <?php endif;?>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ ANALYTICS TAB ══════════ -->
<div id="tab-analytics" class="tab-section" style="display:none">

<!-- ══════════ ANALYTICS SUITE ══════════ -->
<div style="margin-top:0.5rem;animation:dash-fadeUp .6s ease both;animation-delay:.5s">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
    <div>
      <h3 style="font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:.1em;color:var(--ink);margin:0">
        📊 Analytics Suite
      </h3>
      <p style="font-size:.75rem;color:var(--muted);margin-top:2px">Today's floor performance breakdown · <?=date('d M Y')?></p>
    </div>
    <div style="display:flex;gap:.35rem">
      <button class="btn btn-ghost btn-sm" style="border-radius:8px;font-size:.65rem" onclick="exportAllCharts()">📸 Export All Charts</button>
    </div>
  </div>

  <!-- Row 1: Grade Distribution + Buyer Market Share -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">

    <!-- Grade Distribution Doughnut -->
    <div class="dash-card" id="card-grade-dist" style="animation-delay:.55s">
      <div class="dash-card-head">
        <h3>
          <svg class="head-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M8 2v6h6"/></svg>
          Grade Distribution
        </h3>
        <div style="display:flex;gap:.35rem;align-items:center">
          <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="exportChartCSV('gradeChart','grade_distribution')" title="Download CSV">📥 CSV</button>
          <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="exportChart('gradeChart','grade_distribution')" title="Download Image">📸</button>
          <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="printCard('card-grade-dist','Grade Distribution')" title="Print PDF">🖨 PDF</button>
        </div>
      </div>
      <?php if(!empty($grade_dist)):?>
      <div class="dash-chart-wrap" style="padding:1rem;display:flex;align-items:center;gap:1rem">
        <div style="flex:1;max-width:220px"><canvas id="gradeChart" height="200"></canvas></div>
        <div style="flex:1;font-size:.72rem;max-height:200px;overflow-y:auto">
          <?php foreach($grade_dist as $i=>$g):
            $colors = ['#c8952a','#3d6b35','#e8b84b','#7cc576','#b3781f','#4a8a3f','#d4a649','#5fa055','#9c7320','#6b9e5c','#f0c85a','#8db47a'];
            $c = $colors[$i % count($colors)];
          ?>
          <div style="display:flex;align-items:center;gap:6px;padding:3px 0;border-bottom:1px solid rgba(0,0,0,.04)">
            <span style="width:10px;height:10px;border-radius:3px;background:<?=$c?>;flex-shrink:0"></span>
            <span style="font-weight:600;flex:1"><?=htmlspecialchars($g['grade'])?></span>
            <span style="color:var(--muted);font-variant-numeric:tabular-nums"><?=$g['cnt']?> bales</span>
          </div>
          <?php endforeach;?>
        </div>
      </div>
      <?php else:?>
      <div style="padding:3rem;text-align:center;color:var(--muted);font-size:.82rem">
        <div style="font-size:2rem;margin-bottom:.5rem;opacity:.3">🎯</div>
        No grades recorded today
      </div>
      <?php endif;?>
    </div>

    <!-- Buyer Market Share Doughnut -->
    <div class="dash-card" id="card-buyer-share" style="animation-delay:.6s">
      <div class="dash-card-head">
        <h3>
          <svg class="head-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M8 2v6l4 4"/></svg>
          Buyer Market Share
        </h3>
        <div style="display:flex;gap:.35rem;align-items:center">
          <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="exportChartCSV('buyerChart','buyer_market_share')" title="Download CSV">📥 CSV</button>
          <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="exportChart('buyerChart','buyer_market_share')" title="Download Image">📸</button>
          <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="printCard('card-buyer-share','Buyer Market Share')" title="Print PDF">🖨 PDF</button>
        </div>
      </div>
      <?php if(!empty($buyer_share)):?>
      <div class="dash-chart-wrap" style="padding:1rem;display:flex;align-items:center;gap:1rem">
        <div style="flex:1;max-width:220px"><canvas id="buyerChart" height="200"></canvas></div>
        <div style="flex:1;font-size:.72rem;max-height:200px;overflow-y:auto">
          <?php
          $total_buyer_usd = array_sum(array_column($buyer_share,'usd'));
          foreach($buyer_share as $i=>$b):
            $buyerColors = ['#1a1208','#c8952a','#3d6b35','#e8b84b','#7cc576','#b3781f','#4a8a3f','#d4a649'];
            $bc = $buyerColors[$i % count($buyerColors)];
            $pct = $total_buyer_usd > 0 ? round((float)$b['usd']/$total_buyer_usd*100,1) : 0;
          ?>
          <div style="display:flex;align-items:center;gap:6px;padding:3px 0;border-bottom:1px solid rgba(0,0,0,.04)">
            <span style="width:10px;height:10px;border-radius:3px;background:<?=$bc?>;flex-shrink:0"></span>
            <span style="font-weight:600;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100px"><?=htmlspecialchars($b['buyer'])?></span>
            <span style="color:var(--muted);font-variant-numeric:tabular-nums"><?=$pct?>%</span>
            <span style="font-weight:500;font-variant-numeric:tabular-nums">$<?=number_format((float)$b['usd'],0)?></span>
          </div>
          <?php endforeach;?>
        </div>
      </div>
      <?php else:?>
      <div style="padding:3rem;text-align:center;color:var(--muted);font-size:.82rem">
        <div style="font-size:2rem;margin-bottom:.5rem;opacity:.3">🏢</div>
        No buyer data today
      </div>
      <?php endif;?>
    </div>
  </div>

  <!-- Row 2: Top Growers Leaderboard + Rejection Trend -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">

    <!-- Top Growers Leaderboard -->
    <div class="dash-card" id="card-grower-lead" style="animation-delay:.65s">
      <div class="dash-card-head">
        <h3>
          <svg class="head-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1l2 4 4.5.5-3.3 3.1.8 4.4L8 11l-4 2 .8-4.4L1.5 5.5 6 5z"/></svg>
          Top Growers — Leaderboard
        </h3>
        <div style="display:flex;gap:.35rem;align-items:center">
          <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="exportChartCSV('growerChart','grower_leaderboard')" title="Download CSV">📥 CSV</button>
          <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="exportChart('growerChart','grower_leaderboard')" title="Download Image">📸</button>
          <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="printCard('card-grower-lead','Top Growers Leaderboard')" title="Print PDF">🖨 PDF</button>
        </div>
      </div>
      <?php if(!empty($top_growers)):?>
      <div class="dash-chart-wrap" style="padding:1.25rem 1rem;height:240px">
        <canvas id="growerChart" style="width:100%;height:100%"></canvas>
      </div>
      <?php else:?>
      <div style="padding:3rem;text-align:center;color:var(--muted);font-size:.82rem">
        <div style="font-size:2rem;margin-bottom:.5rem;opacity:.3">🏆</div>
        No grower sales today
      </div>
      <?php endif;?>
    </div>

    <!-- Rejection Trend Line -->
    <div class="dash-card" id="card-rejection-trend" style="animation-delay:.7s">
      <div class="dash-card-head">
        <h3>
          <svg class="head-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="1 12 5 7 9 10 15 3"/><line x1="1" y1="15" x2="15" y2="15"/></svg>
          Rejection Trend (14 Days)
        </h3>
        <div style="display:flex;gap:.35rem;align-items:center">
          <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="exportChartCSV('rejChart','rejection_trend')" title="Download CSV">📥 CSV</button>
          <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="exportChart('rejChart','rejection_trend')" title="Download Image">📸</button>
          <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="printCard('card-rejection-trend','Rejection Trend')" title="Print PDF">🖨 PDF</button>
        </div>
      </div>
      <?php if(!empty($rej_trend)):?>
      <div class="dash-chart-wrap" style="padding:1.25rem 1rem;height:240px">
        <canvas id="rejChart" style="width:100%;height:100%"></canvas>
      </div>
      <?php else:?>
      <div style="padding:3rem;text-align:center;color:var(--muted);font-size:.82rem">
        <div style="font-size:2rem;margin-bottom:.5rem;opacity:.3">📉</div>
        No rejection data available
      </div>
      <?php endif;?>
    </div>
  </div>

  <!-- Row 3: Price Heatmap -->
  <?php if(!empty($price_heatmap)):
    // Build matrix
    $hm_grades = []; $hm_buyers = []; $hm_data = [];
    foreach($price_heatmap as $r) {
      $hm_grades[$r['grade']] = 1;
      $hm_buyers[$r['buyer']] = 1;
      $hm_data[$r['grade']][$r['buyer']] = ['price'=>(float)$r['avg_price'],'cnt'=>(int)$r['cnt']];
    }
    $hm_grades = array_keys($hm_grades);
    $hm_buyers = array_keys($hm_buyers);
    // Find min/max for color scale
    $all_prices = array_column($price_heatmap,'avg_price');
    $hm_min = $all_prices ? min(array_map('floatval',$all_prices)) : 0;
    $hm_max = $all_prices ? max(array_map('floatval',$all_prices)) : 1;
    $hm_range = max(0.01, $hm_max - $hm_min);
  ?>
  <div class="dash-card" id="card-price-heatmap" style="animation-delay:.75s">
    <div class="dash-card-head">
      <h3>
        <svg class="head-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="1" width="14" height="14" rx="1"/><line x1="1" y1="5" x2="15" y2="5"/><line x1="1" y1="9" x2="15" y2="9"/><line x1="5" y1="1" x2="5" y2="15"/><line x1="10" y1="1" x2="10" y2="15"/></svg>
        Price Heatmap — Grade × Buyer (Avg $/kg)
      </h3>
      <div style="display:flex;align-items:center;gap:.75rem">
        <div style="display:flex;align-items:center;gap:.5rem;font-size:.6rem;color:var(--muted)">
          <span>Low</span>
          <span style="display:inline-block;width:60px;height:8px;border-radius:4px;background:linear-gradient(90deg, var(--danger), var(--gold), var(--leaf))"></span>
          <span>High</span>
        </div>
        <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="exportHeatmapCSV()" title="Download CSV">📥 CSV</button>
        <button class="btn btn-ghost btn-sm" style="font-size:.55rem;padding:2px 8px;border-radius:6px" onclick="printCard('card-price-heatmap','Price Heatmap')" title="Print PDF">🖨 PDF</button>
      </div>
    </div>
    <div style="overflow-x:auto;padding:.75rem 1rem">
      <table style="width:100%;border-collapse:collapse;font-size:.72rem">
        <thead>
          <tr>
            <th style="padding:6px 8px;text-align:left;font-size:.6rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);border-bottom:2px solid var(--border)">Grade</th>
            <?php foreach($hm_buyers as $b):?>
            <th style="padding:6px 8px;text-align:center;font-size:.6rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);border-bottom:2px solid var(--border);white-space:nowrap;max-width:80px;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($b)?></th>
            <?php endforeach;?>
          </tr>
        </thead>
        <tbody>
          <?php foreach($hm_grades as $g):?>
          <tr>
            <td style="padding:5px 8px;font-weight:600;border-bottom:1px solid rgba(0,0,0,.04)">
              <span class="badge badge-gold" style="font-size:.58rem"><?=htmlspecialchars($g)?></span>
            </td>
            <?php foreach($hm_buyers as $b):
              $cell = $hm_data[$g][$b] ?? null;
              if($cell) {
                $intensity = ($cell['price'] - $hm_min) / $hm_range;
                if ($intensity < 0.35) {
                  $bg = "rgba(192, 57, 43, 0.06)";
                  $fg = "var(--danger)";
                } elseif ($intensity < 0.7) {
                  $bg = "rgba(200, 149, 42, 0.07)";
                  $fg = "var(--gold)";
                } else {
                  $bg = "rgba(61, 107, 53, 0.07)";
                  $fg = "var(--leaf)";
                }
              }
            ?>
            <td style="padding:5px 8px;text-align:center;border-bottom:1px solid rgba(0,0,0,.04);<?=$cell?"background:$bg":""?>">
              <?php if($cell):?>
              <div style="font-weight:700;color:<?=$fg?>;font-variant-numeric:tabular-nums">$<?=number_format($cell['price'],2)?></div>
              <div style="font-size:.55rem;color:var(--muted)"><?=$cell['cnt']?> bale<?=$cell['cnt']!=1?'s':''?></div>
              <?php else:?>
              <span style="color:var(--border)">—</span>
              <?php endif;?>
            </td>
            <?php endforeach;?>
          </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif;?>

</div><!-- end analytics suite -->
</div><!-- end #tab-analytics -->
</div><!-- end #dashboard-content-wrapper -->

<!-- ══════════ SCRIPTS ══════════ -->
<script>
// Live Clock
(function(){
  var el = document.getElementById('dash-clock');
  if (!el) return;
  function tick(){
    var d = new Date();
    el.textContent = d.toLocaleTimeString('en-GB', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
  }
  tick();
  setInterval(tick, 1000);
})();
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function(){
  if (typeof Chart === 'undefined') {
    console.warn('Chart.js is not defined (offline or CDN blocked).');
    return;
  }
  var cs = getComputedStyle(document.documentElement);
  var gold   = cs.getPropertyValue('--gold').trim() || '#c8952a';
  var leaf   = cs.getPropertyValue('--leaf').trim() || '#3d6b35';
  var gridC  = 'rgba(0,0,0,.04)';
  var tickC  = '#999';

  // 1. Overall Performance Chart (perf-chart)
  var perfCanvas = document.getElementById('perf-chart');
  if (perfCanvas) {
    var days   = <?= json_encode($chart_days ?? []) ?>;
    var bales  = <?= json_encode($chart_bales ?? []) ?>;
    var usd    = <?= json_encode($chart_usd ?? []) ?>;
    var ctx = perfCanvas.getContext('2d');

    var goldGrad = ctx.createLinearGradient(0, 0, 0, 260);
    goldGrad.addColorStop(0, gold + '55');
    goldGrad.addColorStop(1, gold + '05');

    var leafGrad = ctx.createLinearGradient(0, 0, 0, 260);
    leafGrad.addColorStop(0, leaf + '35');
    leafGrad.addColorStop(1, leaf + '03');

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: days,
        datasets: [
          {
            label: 'Bales Sold',
            data: bales,
            backgroundColor: goldGrad,
            borderColor: gold,
            borderWidth: 1.5,
            borderRadius: 6,
            borderSkipped: false,
            yAxisID: 'y1'
          },
          {
            label: 'Gross USD',
            data: usd,
            type: 'line',
            borderColor: leaf,
            backgroundColor: leafGrad,
            borderWidth: 2.5,
            pointRadius: 4,
            pointBackgroundColor: '#fff',
            pointBorderColor: leaf,
            pointBorderWidth: 2,
            pointHoverRadius: 6,
            pointHoverBackgroundColor: leaf,
            fill: true,
            tension: 0.4,
            yAxisID: 'y2'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        interaction: { mode: 'index', intersect: false },
        animation: {
          duration: 800,
          easing: 'easeOutQuart'
        },
        plugins: {
          legend: {
            labels: {
              font: { size: 11, family: "'DM Sans', sans-serif", weight: 500 },
              color: tickC,
              usePointStyle: true,
              pointStyle: 'rectRounded',
              padding: 16
            }
          },
          tooltip: {
            backgroundColor: 'rgba(255,255,255,.95)',
            titleColor: '#1a1208',
            bodyColor: '#333',
            borderColor: 'rgba(0,0,0,.08)',
            borderWidth: 1,
            cornerRadius: 8,
            padding: 12,
            titleFont: { size: 12, weight: 600 },
            bodyFont: { size: 12 },
            callbacks: {
              label: function(c) {
                if(c.datasetIndex===1) return ' $'+c.raw.toLocaleString('en',{minimumFractionDigits:2});
                return ' '+c.raw+' bales';
              }
            }
          }
        },
        scales: {
          x: {
            ticks: { font:{size:10, family:"'DM Sans'"}, color: tickC },
            grid: { display:false }
          },
          y1: {
            position: 'left',
            ticks: { font:{size:10}, color: gold+'aa' },
            grid: { color: gridC },
            title: { display:true, text:'Bales', font:{size:10, weight:600}, color: gold+'bb' }
          },
          y2: {
            position: 'right',
            ticks: {
              font:{size:10}, color: leaf+'aa',
              callback: function(v){ return '$'+v.toLocaleString('en',{maximumFractionDigits:0}); }
            },
            grid: { display:false },
            title: { display:true, text:'Gross USD', font:{size:10, weight:600}, color: leaf+'bb' }
          }
        }
      }
    });
  }

  // 2. Grade Distribution (gradeChart)
  var gradeCanvas = document.getElementById('gradeChart');
  if (gradeCanvas) {
    var gdData = <?= json_encode($grade_dist ?? []) ?>;
    var gdLabels = gdData.map(d => d.grade);
    var gdCounts = gdData.map(d => parseInt(d.cnt));
    var gdColors = ['#c8952a','#3d6b35','#e8b84b','#7cc576','#b3781f','#4a8a3f','#d4a649','#5fa055','#9c7320','#6b9e5c','#f0c85a','#8db47a'];
    new Chart(gradeCanvas, {
      type: 'doughnut',
      data: {
        labels: gdLabels,
        datasets: [{
          data: gdCounts,
          backgroundColor: gdColors,
          borderWidth: 1,
          borderColor: '#fff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(255,255,255,.95)',
            titleColor: '#1a1208',
            bodyColor: '#333',
            borderColor: 'rgba(0,0,0,.08)',
            borderWidth: 1,
            cornerRadius: 8,
            padding: 10,
            callbacks: {
              label: function(c) {
                return ' ' + c.label + ': ' + c.raw + ' bales';
              }
            }
          }
        },
        cutout: '65%'
      }
    });
  }

  // 3. Buyer Market Share (buyerChart)
  var buyerCanvas = document.getElementById('buyerChart');
  if (buyerCanvas) {
    var bsData = <?= json_encode($buyer_share ?? []) ?>;
    var bsLabels = bsData.map(d => d.buyer);
    var bsUsd = bsData.map(d => parseFloat(d.usd));
    var bsColors = ['#1a1208','#c8952a','#3d6b35','#e8b84b','#7cc576','#b3781f','#4a8a3f','#d4a649'];
    new Chart(buyerCanvas, {
      type: 'doughnut',
      data: {
        labels: bsLabels,
        datasets: [{
          data: bsUsd,
          backgroundColor: bsColors,
          borderWidth: 1,
          borderColor: '#fff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(255,255,255,.95)',
            titleColor: '#1a1208',
            bodyColor: '#333',
            borderColor: 'rgba(0,0,0,.08)',
            borderWidth: 1,
            cornerRadius: 8,
            padding: 10,
            callbacks: {
              label: function(c) {
                return ' ' + c.label + ': $' + c.raw.toLocaleString('en', {minimumFractionDigits: 0, maximumFractionDigits: 0});
              }
            }
          }
        },
        cutout: '65%'
      }
    });
  }

  // 4. Top Growers Leaderboard (growerChart)
  var growerCanvas = document.getElementById('growerChart');
  if (growerCanvas) {
    var tgData = <?= json_encode($top_growers ?? []) ?>;
    var tgLabels = tgData.map(d => d.grower_num + ' - ' + d.grower_name);
    var tgUsd = tgData.map(d => parseFloat(d.usd));
    new Chart(growerCanvas, {
      type: 'bar',
      data: {
        labels: tgLabels,
        datasets: [{
          label: 'Value (USD)',
          data: tgUsd,
          backgroundColor: 'rgba(61,107,53,0.15)',
          borderColor: '#3d6b35',
          borderWidth: 1.5,
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(255,255,255,.95)',
            titleColor: '#1a1208',
            bodyColor: '#333',
            borderColor: 'rgba(0,0,0,.08)',
            borderWidth: 1,
            cornerRadius: 8,
            padding: 10,
            callbacks: {
              label: function(c) {
                return ' $' + c.raw.toLocaleString('en', {minimumFractionDigits: 0, maximumFractionDigits: 0});
              }
            }
          }
        },
        scales: {
          x: {
            grid: { color: 'rgba(0,0,0,.04)' },
            ticks: {
              font: { size: 9 },
              callback: function(v) { return '$' + v.toLocaleString(); }
            }
          },
          y: {
            grid: { display: false },
            ticks: { font: { size: 9 }, color: '#333' }
          }
        }
      }
    });
  }

  // 5. Rejection Trend (rejChart)
  var rejCanvas = document.getElementById('rejChart');
  if (rejCanvas) {
    var rtData = <?= json_encode($rej_trend ?? []) ?>;
    var rtLabels = rtData.map(d => {
      var dt = new Date(d.day);
      return dt.toLocaleDateString('en-GB', {day:'numeric', month:'short'});
    });
    var rtRates = rtData.map(d => {
      var t = parseInt(d.total) || 0;
      var r = parseInt(d.rejected) || 0;
      return t > 0 ? parseFloat((r / t * 100).toFixed(1)) : 0;
    });
    new Chart(rejCanvas, {
      type: 'line',
      data: {
        labels: rtLabels,
        datasets: [{
          label: 'Rejection Rate (%)',
          data: rtRates,
          borderColor: '#d9534f',
          backgroundColor: 'rgba(217,83,79,0.05)',
          borderWidth: 2,
          pointRadius: 3.5,
          pointBackgroundColor: '#fff',
          pointBorderWidth: 1.5,
          fill: true,
          tension: 0.35
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(255,255,255,.95)',
            titleColor: '#1a1208',
            bodyColor: '#333',
            borderColor: 'rgba(0,0,0,.08)',
            borderWidth: 1,
            cornerRadius: 8,
            padding: 10,
            callbacks: {
              label: function(c) { return ' ' + c.raw + '%'; }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 9 } }
          },
          y: {
            grid: { color: 'rgba(0,0,0,.04)' },
            ticks: {
              font: { size: 9 },
              callback: function(v) { return v + '%'; }
            },
            min: 0,
            max: 100
          }
        }
      }
    });
  }
})();

// Export CSV from Chart.js data
function exportChartCSV(chartId, filename) {
  var chart = Chart.getChart(chartId);
  if (!chart) return;
  
  var labels = chart.data.labels;
  var dataset = chart.data.datasets[0];
  var data = dataset.data;
  var label = dataset.label || 'Value';
  
  var csv = 'Category,' + label + '\r\n';
  for (var i = 0; i < labels.length; i++) {
    csv += '"' + labels[i].toString().replace(/"/g, '""') + '",' + data[i] + '\r\n';
  }
  
  var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  var link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.setAttribute("download", filename + "_" + new Date().toISOString().slice(0,10) + ".csv");
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

// Export Heatmap Table from DOM to CSV
function exportHeatmapCSV() {
  var table = document.querySelector('#card-price-heatmap table');
  if (!table) return;
  
  var csv = [];
  var rows = table.querySelectorAll('tr');
  for (var i = 0; i < rows.length; i++) {
    var row = [], cols = rows[i].querySelectorAll('td, th');
    for (var j = 0; j < cols.length; j++) {
      var cleanText = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim();
      cleanText = cleanText.replace(/"/g, '""');
      row.push('"' + cleanText + '"');
    }
    csv.push(row.join(","));
  }
  
  var blob = new Blob([csv.join("\r\n")], { type: 'text/csv;charset=utf-8;' });
  var link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.setAttribute("download", "price_heatmap_" + new Date().toISOString().slice(0,10) + ".csv");
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

// Export PNG functionality
function exportChart(chartId, filename) {
  var canvas = document.getElementById(chartId);
  if (!canvas) return;
  
  var tempCanvas = document.createElement('canvas');
  tempCanvas.width = canvas.width;
  tempCanvas.height = canvas.height;
  var ctx = tempCanvas.getContext('2d');
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);
  ctx.drawImage(canvas, 0, 0);
  
  var url = tempCanvas.toDataURL('image/png');
  var a = document.createElement('a');
  a.href = url;
  a.download = filename + '_' + new Date().toISOString().slice(0,10) + '.png';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

function exportAllCharts() {
  var charts = [
    { id: 'gradeChart', name: 'grade_distribution' },
    { id: 'buyerChart', name: 'buyer_market_share' },
    { id: 'growerChart', name: 'grower_leaderboard' },
    { id: 'rejChart', name: 'rejection_trend' },
    { id: 'perf-chart', name: 'overall_performance' }
  ];
  charts.forEach(function(c) {
    if (document.getElementById(c.id)) {
      exportChart(c.id, c.name);
    }
  });
}

// Premium Dashboard Tab Switching & Caching
(function() {
    var wrapper = document.getElementById('dashboard-content-wrapper');
    var cacheKey = 'fw_pwa_dashboard_cache_' + '<?= $spid ?>';

    function bindTabEvents() {
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                var tabId = this.getAttribute('data-tab');
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b === this));
                document.querySelectorAll('.tab-section').forEach(sec => {
                    sec.style.display = sec.id === 'tab-' + tabId ? 'block' : 'none';
                });
                localStorage.setItem('fw_active_dashboard_tab', tabId);
            });
        });
    }

    function restoreTab() {
        var urlParams = new URLSearchParams(window.location.search);
        var tabParam = urlParams.get('tab');
        var savedTab = tabParam || localStorage.getItem('fw_active_dashboard_tab') || 'overview';
        if (tabParam) {
            localStorage.setItem('fw_active_dashboard_tab', tabParam);
        }
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-tab') === savedTab);
        });
        document.querySelectorAll('.tab-section').forEach(sec => {
            sec.style.display = sec.id === 'tab-' + savedTab ? 'block' : 'none';
        });
    }

    // Initialize events initially
    bindTabEvents();
    restoreTab();

    if (navigator.onLine) {
        // Online: Save current state to cache
        if (wrapper) {
            var dataToCache = {
                wrapperHtml: wrapper.innerHTML,
                timestamp: new Date().getTime()
            };
            localStorage.setItem(cacheKey, JSON.stringify(dataToCache));
        }
    } else {
        // Offline: Load from cache
        var cached = localStorage.getItem(cacheKey);
        if (cached) {
            try {
                var data = JSON.parse(cached);
                if (wrapper && data.wrapperHtml) {
                    wrapper.innerHTML = data.wrapperHtml;
                    // Rebind events to tab buttons since we replaced HTML
                    bindTabEvents();
                    restoreTab();
                }
                
                // Show cached timestamp
                var subtitle = document.querySelector('.dash-subtitle');
                if (subtitle) {
                    var cachedDate = new Date(data.timestamp);
                    var dateStr = cachedDate.toLocaleDateString() + ' ' + cachedDate.toLocaleTimeString();
                    subtitle.insertAdjacentHTML('beforeend', '<span style="opacity:.3">·</span><span style="color:var(--gold-l)">Offline View (Cached ' + dateStr + ')</span>');
                }
            } catch(e) {
                console.error('Failed to parse cached dashboard data', e);
            }
        }
    }
})();
</script>

<?php include "includes/layout_end.php"; ?>
