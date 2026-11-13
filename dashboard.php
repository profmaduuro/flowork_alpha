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

// Sale day — the floor's open selling day (start_of_day active=1, description = sell date),
// else today. ?date=Y-m-d shows another day. Every figure below follows $sel_date.
$open_sod = safe($conn, "SELECT id, description, seasonid FROM start_of_day WHERE active=1 AND selling_pointid=$spid ORDER BY id DESC LIMIT 1");
$is_date  = fn($d) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
$open_day = $is_date((string)($open_sod['description'] ?? '')) ? $open_sod['description'] : '';
$sel_date = trim((string)($_GET['date'] ?? ''));
if (!$is_date($sel_date)) $sel_date = $open_day ?: $today;
$sod      = safe($conn, "SELECT id, seasonid FROM start_of_day WHERE description='$sel_date' AND selling_pointid=$spid ORDER BY active DESC, id DESC LIMIT 1");
$sodid    = (int)($sod['id'] ?? 0);
$trend_from = date('Y-m-d', strtotime($sel_date.' -13 days'));

// A sold_bales row with an R code of up to 3 characters is a rejection, not a sale
$not_rej = "NOT (LEFT(UPPER(TRIM(sb.sale_code)),1)='R' AND CHAR_LENGTH(TRIM(sb.sale_code))<=3)";
// Balanced batches belong to the day they were sold (theirs, else the delivery's), not the day the batch was saved
$bb_day  = "COALESCE(bb.sell_date, tg.sell_date, DATE(bb.created_at))";
// Deliveries belong to their booked sell date
$tg_day  = "COALESCE(tg.sell_date, DATE(tg.created_at))";

// Sales on the sale day — counted by the day each bale was sold
$sales = safe($conn, "SELECT COUNT(DISTINCT sb.id) bales, COALESCE(SUM(bm.mass),0) mass,
    COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) usd
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    WHERE DATE(sb.created_at)='$sel_date' AND $not_rej
      AND g.selling_pointid=$spid");

// Season to date — balanced batches from the season's first selling day up to the sale day
$season_id   = (int)($sod['seasonid'] ?? $open_sod['seasonid'] ?? 0);
$season_from = $season_id ? (safe($conn, "SELECT MIN(description) d FROM start_of_day WHERE seasonid=$season_id AND selling_pointid IN ($spid,0)")['d'] ?? '') : '';
$season_cond = $is_date((string)$season_from) ? "$bb_day BETWEEN '$season_from' AND '$sel_date'" : "$bb_day <= '$sel_date'";
$season = safe($conn, "SELECT COALESCE(SUM(dt.sold_bales),0) bales,
        COALESCE(SUM(dt.usd_value),0) usd,
        COUNT(DISTINCT bb.id) batches,
        COALESCE(SUM(dt.mass),0) mass
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    WHERE g.selling_pointid=$spid AND $season_cond");

// Balanced for the sale day
$today_bal = safe($conn, "SELECT COALESCE(SUM(dt.sold_bales),0) bales,
        COALESCE(SUM(dt.usd_value),0) usd,
        COUNT(DISTINCT bb.id) batches
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    WHERE g.selling_pointid=$spid AND $bb_day='$sel_date'");

// Exchange rate set in Day Control for the sale day, else this floor's latest active rate
$er = safe($conn, "SELECT er.amount FROM exchange_rate er
    JOIN start_of_day sd ON sd.id=er.start_of_dayid
    WHERE sd.description='$sel_date' AND sd.selling_pointid IN ($spid,0)
    ORDER BY sd.selling_pointid DESC, er.active DESC, er.id DESC LIMIT 1")
   ?: safe($conn, "SELECT amount FROM exchange_rate WHERE selling_pointid=$spid AND active=1 ORDER BY id DESC LIMIT 1");
$recent = safeAll($conn, "SELECT g.grower_num, CONCAT(g.name,' ',g.surname) grower_name,
        dt.sold_bales bales, dt.usd_value gross, dt.mass,
        COALESCE(dtn.usd_net_value,0) net_usd,
        bb.id bbid, bb.created_at bal_date
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
    WHERE g.selling_pointid=$spid AND $bb_day='$sel_date'
    ORDER BY bb.id DESC LIMIT 10");

// Booked growers for the sale day — one row per grower. A grower attached to the
// same day more than once (e.g. under a duplicate transporter) still counts once,
// with the bales from their booking (booked_bales), else from the delivery.
$booked_list = safeAll($conn, "SELECT g.grower_num,
        CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
        COALESCE(MAX(CAST(NULLIF(TRIM(b.bales_booked),'') AS UNSIGNED)), MAX(tg.bales)) bales,
        '$sel_date' sell_date,
        GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') transporter,
        MAX(bb.id) IS NOT NULL is_balanced,
        MAX(bb.id) bbid
    FROM transporter_growers tg
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN transporter t ON t.id=tg.transporterid
    LEFT JOIN booked_bales b ON b.growerid=tg.growerid AND b.sell_date='$sel_date'
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
    WHERE ($spid=0 OR g.selling_pointid=$spid OR tg.selling_pointid=$spid) AND tg.booked=1
      AND $tg_day='$sel_date'
    GROUP BY tg.growerid
    ORDER BY is_balanced ASC, g.grower_num");

$zig_rate = (float)($er['amount'] ?? 0);
// Chart data: the 14 selling days up to the sale day
$chart_days = [];
$chart_bales = [];
$chart_usd = [];
$chart_q = $conn->query("
    SELECT $bb_day day,
           COALESCE(SUM(dt.sold_bales),0) bales,
           COALESCE(SUM(dt.usd_value),0) usd
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    WHERE (g.selling_pointid=$spid OR $spid=0)
      AND $bb_day BETWEEN '$trend_from' AND '$sel_date'
    GROUP BY $bb_day
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

// Grade distribution (sale day) — doughnut chart
$grade_dist = [];
$gd_q = $conn->query("
    SELECT tg.description grade, COUNT(*) cnt, COALESCE(SUM(bm.mass),0) mass
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    WHERE DATE(sb.created_at)='$sel_date' AND (g.selling_pointid=$spid OR $spid=0)
      AND tg.description IS NOT NULL AND $not_rej
    GROUP BY tg.description ORDER BY cnt DESC LIMIT 12
");
if ($gd_q && $gd_q !== true) while ($r = $gd_q->fetch_assoc()) $grade_dist[] = $r;

// Buyer market share (sale day) — doughnut chart
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
    WHERE DATE(sb.created_at)='$sel_date' AND (g.selling_pointid=$spid OR $spid=0)
      AND b.name IS NOT NULL
      AND $not_rej
    GROUP BY b.name ORDER BY usd DESC LIMIT 8
");
if ($bs_q && $bs_q !== true) while ($r = $bs_q->fetch_assoc()) $buyer_share[] = $r;

// Top growers by value (sale day) — horizontal bar chart
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
    WHERE DATE(sb.created_at)='$sel_date' AND (g.selling_pointid=$spid OR $spid=0)
      AND $not_rej
    GROUP BY g.id ORDER BY usd DESC LIMIT 8
");
if ($tg_q && $tg_q !== true) while ($r = $tg_q->fetch_assoc()) $top_growers[] = $r;

// Rejection trend (14 days up to the sale day) — line chart
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
      AND DATE(sb.created_at) BETWEEN '$trend_from' AND '$sel_date'
    GROUP BY DATE(sb.created_at) ORDER BY day ASC
");
if ($rt_q && $rt_q !== true) while ($r = $rt_q->fetch_assoc()) $rej_trend[] = $r;

// Price heatmap data: avg price by grade × buyer (sale day)
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
    WHERE DATE(sb.created_at)='$sel_date' AND (g.selling_pointid=$spid OR $spid=0)
      AND tg.description IS NOT NULL AND b.name IS NOT NULL
      AND $not_rej
    GROUP BY tg.description, b.name ORDER BY tg.description, b.name
");
if ($ph_q && $ph_q !== true) while ($r = $ph_q->fetch_assoc()) $price_heatmap[] = $r;

// Average price per kg on the sale day
$today_mass = (float)($sales['mass'] ?? 0);
$today_usd  = (float)($sales['usd'] ?? 0);
$avg_price  = $today_mass > 0 ? $today_usd / $today_mass : 0;

// % of the day's booked growers that are balanced
$booked_count  = count($booked_list);
$booked_bales  = array_sum(array_map('intval', array_column($booked_list, 'bales')));
$pending_count = count(array_filter($booked_list, fn($r) => !(int)$r['is_balanced']));
$balanced_pct  = $booked_count > 0 ? round((($booked_count - $pending_count) / $booked_count) * 100) : 0;

include "includes/layout.php";
?>

<style>
/* ═══════════════════════════════════════════════════════
   DASHBOARD — uses the shared tokens in includes/layout.css
   (--surface, --line, --text, --text-muted, --accent…), so
   light and dark mode need no overrides here.
   ═══════════════════════════════════════════════════════ */
@keyframes dash-fadeUp { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
@keyframes dash-pulse  { 0%, 100% { opacity: 1; } 50% { opacity: .45; } }

/* ── Header ──────────────────────────────────────────── */
.dash-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.dash-header-left h2 { font-size: 1.45rem; font-weight: 600; letter-spacing: -0.01em; line-height: 1.2; color: var(--text); }
.dash-subtitle { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem .65rem; font-size: .84rem; color: var(--text-muted); margin-top: .35rem; }
.dash-subtitle .sep { color: var(--text-muted); }
.dash-subtitle .open { color: var(--ok-text); font-weight: 500; }
.dash-subtitle form { display: inline-flex; gap: .4rem; align-items: center; margin: 0; }
.dash-subtitle input[type=date] { min-height: 34px; padding: .25rem .6rem; width: auto; font-size: .85rem; }
.dash-live-dot { width: 8px; height: 8px; background: var(--ok-text); border-radius: 50%; animation: dash-pulse 2s ease infinite; flex-shrink: 0; }
.dash-clock { font-variant-numeric: tabular-nums; font-weight: 500; color: var(--text-soft); }
.dash-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
.dash-actions .btn svg { width: 16px; height: 16px; }

/* ── KPI strip ───────────────────────────────────────── */
.dash-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.dash-kpi { display: flex; align-items: flex-start; gap: .85rem; padding: 1.1rem 1.15rem; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--line); box-shadow: var(--shadow-sm); transition: box-shadow .15s; animation: dash-fadeUp .35s ease both; }
.dash-kpi:hover { box-shadow: var(--shadow-md); }
.dash-kpi-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; background: var(--accent-tint); color: var(--accent-text); }
.dash-kpi-icon svg { width: 19px; height: 19px; }
.dash-kpi-icon.ok { background: var(--ok-tint); color: var(--ok-text); }
.dash-kpi-label { font-size: .76rem; font-weight: 500; color: var(--text-muted); margin-bottom: .3rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dash-kpi-value { font-size: 1.5rem; font-weight: 600; letter-spacing: -0.01em; line-height: 1.1; color: var(--text); font-variant-numeric: tabular-nums; }
.dash-kpi-value.sm { font-size: 1.25rem; }
.dash-kpi-sub { font-size: .74rem; color: var(--text-muted); margin-top: .45rem; display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; }
.dash-tag { display: inline-flex; padding: 1px 8px; border-radius: 999px; font-size: .76rem; font-weight: 500; font-variant-numeric: tabular-nums; white-space: nowrap; }
.dash-tag-gold { background: var(--warn-tint); color: var(--warn-text); }
.dash-tag-green { background: var(--ok-tint); color: var(--ok-text); }

/* ── Tabs (same look as the shared segmented tabs) ──── */
.dash-tabs { display: inline-flex; gap: 2px; padding: 3px; border-radius: 10px; background: var(--surface-3); border: 1px solid var(--line); margin-bottom: 1.25rem; max-width: 100%; overflow-x: auto; }
.tab-btn { height: 34px; padding: 0 .95rem; border: none; border-radius: 7px; background: transparent; color: var(--text-soft); font: 500 .8rem 'DM Sans', sans-serif; cursor: pointer; display: flex; align-items: center; gap: 6px; white-space: nowrap; transition: background .15s, color .15s, box-shadow .15s; }
.tab-btn svg { width: 15px; height: 15px; }
.tab-btn:hover { color: var(--text); }
.tab-btn.active { background: var(--surface); color: var(--text); font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,.10); }
body.theme-dark .tab-btn.active { background: var(--line-strong); }
.tab-section { animation: dash-fadeUp .25s ease both; }

/* ── Grid & cards ────────────────────────────────────── */
.dash-grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 1rem; }
.dash-col { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
.dash-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
.dash-card { border-radius: var(--radius-lg); overflow: hidden; background: var(--surface); border: 1px solid var(--line); box-shadow: var(--shadow-sm); }
.dash-card-head { padding: .9rem 1.15rem; display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; border-bottom: 1px solid var(--line); }
.dash-card-head h3 { font-size: .88rem; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: .5rem; }
.dash-card-head h3 .head-icon { width: 17px; height: 17px; color: var(--text-muted); flex-shrink: 0; }
.dash-card-tools { display: flex; gap: .35rem; align-items: center; flex-wrap: wrap; }
.dash-empty { padding: 2.5rem 1rem; text-align: center; color: var(--text-muted); font-size: .9rem; }
.dash-empty svg { width: 28px; height: 28px; display: block; margin: 0 auto .6rem; opacity: .55; }
.dash-empty a { color: var(--accent-text); font-weight: 500; }
.dash-section-head { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 1rem; flex-wrap: wrap; gap: .5rem; }
.dash-section-head h3 { font-size: 1.15rem; font-weight: 600; color: var(--text); }
.dash-section-head p { font-size: .85rem; color: var(--text-muted); margin-top: 2px; }

/* ── Progress ring ───────────────────────────────────── */
.dash-progress-ring { display: flex; align-items: center; gap: 1.1rem; padding: 1.1rem 1.15rem; }
.dash-ring-svg { width: 76px; height: 76px; flex-shrink: 0; transform: rotate(-90deg); }
.dash-ring-bg { fill: none; stroke-width: 6; stroke: var(--surface-3); }
.dash-ring-fg { fill: none; stroke-width: 6; stroke-linecap: round; stroke: var(--accent); transition: stroke-dashoffset .8s ease; }
.dash-ring-text { font: 600 15px 'DM Sans', sans-serif; fill: var(--text); transform: rotate(90deg); transform-origin: center; }
.dash-ring-info { flex: 1; }
.dash-ring-info .ring-label { font-size: .82rem; font-weight: 500; color: var(--text-muted); margin-bottom: .25rem; }
.dash-ring-info .ring-stat { font-size: .92rem; margin-bottom: .1rem; }
.dash-ring-info .ring-stat strong { color: var(--text); font-weight: 600; }
.dash-ring-info .ring-sub { color: var(--text-muted); font-size: .85rem; }

/* ── Tables ──────────────────────────────────────────── */
.dash-table-wrap { overflow: auto; }
.dash-table-wrap table { width: 100%; border-collapse: collapse; font-size: .8rem; }
.dash-table-wrap thead th { font-size: .72rem; font-weight: 500; color: var(--text-muted); padding: .5rem .8rem; text-align: left; white-space: nowrap; position: sticky; top: 0; z-index: 2; background: var(--surface-2); border-bottom: 1px solid var(--line); }
.dash-table-wrap thead th.text-right { text-align: right; }
.dash-table-wrap tbody tr { border-bottom: 1px solid var(--line); }
.dash-table-wrap tbody tr:last-child { border-bottom: none; }
.dash-table-wrap tbody tr:hover { background: var(--surface-2); }
.dash-table-wrap tbody td { padding: .42rem .8rem; vertical-align: middle; line-height: 1.35; }
.dash-table-wrap tbody td { white-space: nowrap; }
/* Two tables side by side need room; below that they stack */
@media (max-width: 1280px) { .dash-grid.dash-ops { grid-template-columns: 1fr; } }
.dash-grower { display: flex; flex-direction: column; line-height: 1.3; }
.dash-grower .name { font-weight: 500; color: var(--text); }
.dash-grower .num { font-size: .74rem; color: var(--text-muted); font-variant-numeric: tabular-nums; }
tr.is-done { color: var(--text-muted); }
tr.is-done .dash-grower .name { color: var(--text-soft); font-weight: 400; }

/* ── Status pills ────────────────────────────────────── */
.dash-status { display: inline-flex; align-items: center; gap: 6px; padding: 2px 9px; border-radius: 999px; font-size: .76rem; font-weight: 500; white-space: nowrap; }
.dash-status.balanced { background: var(--ok-tint); color: var(--ok-text); }
.dash-status.booked { background: var(--warn-tint); color: var(--warn-text); }
.dash-status .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.dash-status.booked .dot { animation: dash-pulse 2s ease infinite; }
.dash-badge { display: inline-flex; align-items: center; padding: 1px 9px; border-radius: 999px; font-size: .76rem; font-weight: 500; white-space: nowrap; background: var(--surface-3); color: var(--text-soft); font-variant-numeric: tabular-nums; }

/* ── Quick actions ───────────────────────────────────── */
.dash-links-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; padding: .85rem; }
.dash-link { display: flex; align-items: center; gap: .65rem; min-height: 44px; padding: .45rem .7rem; border-radius: var(--radius); text-decoration: none; color: var(--text); font-size: .82rem; font-weight: 500; background: var(--surface-2); border: 1px solid var(--line); transition: background .15s, border-color .15s; }
.dash-link:hover { background: var(--accent-tint); border-color: var(--accent); color: var(--text); }
.dash-link-icon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; background: var(--accent-tint); color: var(--accent-text); }
.dash-link-icon svg { width: 16px; height: 16px; }

/* ── Chart areas ─────────────────────────────────────── */
.dash-chart-wrap { padding: 1rem 1.15rem; }
.dash-legend { flex: 1; font-size: .85rem; max-height: 210px; overflow-y: auto; }
.dash-legend-row { display: flex; align-items: center; gap: 8px; padding: 5px 0; border-bottom: 1px solid var(--line); }
.dash-legend-row:last-child { border-bottom: none; }
.dash-legend-row .sw { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.dash-legend-row .lbl { font-weight: 500; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dash-legend-row .val { color: var(--text-muted); font-variant-numeric: tabular-nums; white-space: nowrap; }

/* ── Average price footer ────────────────────────────── */
.dash-avg-price { display: flex; align-items: center; gap: .6rem; padding: .75rem 1.15rem; border-top: 1px solid var(--line); }
.dash-avg-price svg { width: 18px; height: 18px; color: var(--text-muted); }
.dash-avg-label { color: var(--text-muted); font-size: .82rem; font-weight: 500; }
.dash-avg-val { font-size: 1.1rem; font-weight: 600; color: var(--text); font-variant-numeric: tabular-nums; }

/* ── Price heatmap ───────────────────────────────────── */
.dash-heatmap { width: 100%; border-collapse: collapse; font-size: .85rem; }
.dash-heatmap th { padding: .55rem .6rem; text-align: center; font-size: .78rem; font-weight: 500; color: var(--text-muted); border-bottom: 1px solid var(--line); white-space: nowrap; max-width: 110px; overflow: hidden; text-overflow: ellipsis; }
.dash-heatmap th:first-child { text-align: left; }
.dash-heatmap td { padding: .45rem .6rem; text-align: center; border-bottom: 1px solid var(--line); }
.dash-heatmap td:first-child { text-align: left; }
.dash-heatmap .hm-price { font-weight: 600; font-variant-numeric: tabular-nums; }
.dash-heatmap .hm-cnt { font-size: .75rem; color: var(--text-muted); }
.dash-heatmap .hm-low  { background: var(--bad-tint); }  .dash-heatmap .hm-low .hm-price  { color: var(--bad-text); }
.dash-heatmap .hm-mid  { background: var(--warn-tint); } .dash-heatmap .hm-mid .hm-price  { color: var(--warn-text); }
.dash-heatmap .hm-high { background: var(--ok-tint); }   .dash-heatmap .hm-high .hm-price { color: var(--ok-text); }
.dash-heat-key { display: flex; align-items: center; gap: .5rem; font-size: .78rem; color: var(--text-muted); }
.dash-heat-key span.bar { display: inline-block; width: 64px; height: 8px; border-radius: 4px; background: linear-gradient(90deg, var(--bad-text), var(--accent), var(--ok-text)); }

/* ── Offline banner ──────────────────────────────────── */
#pwa-offline-warning { display: none; align-items: center; gap: 10px; background: var(--bad-tint); color: var(--bad-text); padding: .75rem 1rem; border-radius: var(--radius); margin-bottom: 1.25rem; font-size: .875rem; font-weight: 500; }
#pwa-offline-warning svg { width: 18px; height: 18px; flex-shrink: 0; }

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 900px) {
  .dash-grid, .dash-row-2 { grid-template-columns: 1fr !important; }
  .dash-header { flex-direction: column; align-items: flex-start; }
}
@media (max-width: 600px) {
  .dash-kpi-grid { grid-template-columns: 1fr 1fr; gap: .65rem; }
  .dash-kpi { flex-direction: column; gap: .55rem; padding: .9rem; }
  .dash-kpi-icon { width: 32px; height: 32px; }
  .dash-kpi-value { font-size: 1.35rem; }
  .dash-kpi-value.sm { font-size: 1.15rem; }
  .dash-links-grid { grid-template-columns: 1fr; }
  .dash-subtitle .sep { display: none; }
  .dash-kpi-label { white-space: normal; }
  .dash-chart-split { flex-direction: column; align-items: stretch !important; }
  .dash-chart-split > div:first-child { max-width: 220px !important; margin: 0 auto; }
}
@media (max-width: 330px) { .dash-kpi-grid { grid-template-columns: 1fr; } }
</style>

<?php
/** Line icons for the dashboard (24px grid, stroke follows the text colour). */
function dash_icon(string $name, string $class = ''): string {
    $p = [
        'box'      => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'dollar'   => '<path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'scale'    => '<path d="M12 3v18M6 21h12M5 7h14"/><path d="m5 7-3 7a3 3 0 0 0 6 0zM19 7l-3 7a3 3 0 0 0 6 0z"/>',
        'clipboard'=> '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 12h6M9 16h4"/>',
        'trophy'   => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'tag'      => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8" r="1"/>',
        'ticket'   => '<path d="M3 8a2 2 0 0 0 0 4v5h18v-5a2 2 0 0 1 0-4V3H3z"/><path d="M13 3v4M13 11v2M13 17v0"/>',
        'gavel'    => '<path d="m14 13-7.5 7.5a2.1 2.1 0 0 1-3-3L11 10"/><path d="m16 16 6-6M8 8l6-6M9 7l8 8M21 11l-8-8"/>',
        'file'     => '<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>',
        'bank'     => '<path d="M3 21h18M5 21V10M19 21V10M9 21V10M15 21V10M2 10l10-7 10 7z"/>',
        'folder'   => '<path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>',
        'bolt'     => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>',
        'cube'     => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/>',
        'trend'    => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'pie'      => '<path d="M21 12A9 9 0 1 1 12 3v9z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15z"/>',
        'star'     => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
        'grid'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
        'printer'  => '<path d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2"/><path d="M6 14h12v7H6z"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'alert'    => '<path d="M12 3 2 21h20z"/><path d="M12 10v5M12 18h.01"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
    ];
    $c = $class !== '' ? ' class="' . $class . '"' : '';
    return '<svg' . $c . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
/** The export buttons on an analytics card: icon-only, labelled for screen readers. */
function dash_tools(string $chart, string $file, string $card, string $title, bool $image = true): string {
    $b = fn($onclick, $label, $icon) => '<button type="button" class="btn btn-ghost btn-sm btn-icon" onclick="' . $onclick . '" title="' . $label . '" aria-label="' . $label . '">' . dash_icon($icon) . '</button>';
    $t = htmlspecialchars($title, ENT_QUOTES);
    $h = '<div class="dash-card-tools">';
    $h .= $b("exportChartCSV('$chart','$file')", "Download $t as CSV", 'download');
    if ($image) $h .= $b("exportChart('$chart','$file')", "Download $t as an image", 'image');
    $h .= $b("printCard('$card','$t')", "Print $t or save as PDF", 'printer');
    return $h . '</div>';
}
?>
<!-- ══════════ DASHBOARD HEADER ══════════ -->
<div class="dash-header">
  <div class="dash-header-left">
    <h2>Dashboard</h2>
    <div class="dash-subtitle">
      <span class="dash-live-dot" aria-hidden="true"></span>
      <span>Sale day <?=date('l, d F Y', strtotime($sel_date))?><?php if($sel_date===$open_day):?> · <span class="open">Open</span><?php elseif($open_day===''&&$sel_date===$today):?> · no selling day open<?php endif;?></span>
      <span class="sep" aria-hidden="true">·</span>
      <span><?=htmlspecialchars($_SESSION['selling_point_name']??'All Floors')?></span>
      <form method="GET">
        <input type="date" name="date" value="<?=$sel_date?>" class="form-control" onchange="this.form.submit()" aria-label="Sale day">
        <?php if($sel_date!==($open_day ?: $today)):?>
        <a href="dashboard.php" class="btn btn-ghost btn-sm"><?=$open_day?'Open day':'Today'?></a>
        <?php endif;?>
      </form>
      <span class="sep" aria-hidden="true">·</span>
      <span class="dash-clock" id="dash-clock"></span>
    </div>
  </div>
  <div class="dash-actions">
    <a href="grower_booking.php" class="btn btn-ghost"><?=dash_icon('calendar')?> Bookings</a>
    <a href="selling.php" class="btn btn-ghost"><?=dash_icon('gavel')?> Selling floor</a>
    <a href="balance.php" class="btn btn-primary"><?=dash_icon('scale')?> Balance</a>
  </div>
</div>

<!-- PWA Offline Warning Banner -->
<div id="pwa-offline-warning" role="status">
  <?=dash_icon('alert')?>
  <span>You are offline. This is the dashboard as it was at your last online visit; live updates and some options are unavailable.</span>
</div>

<!-- ══════════ DASHBOARD CONTENT WRAPPER ══════════ -->
<div id="dashboard-content-wrapper">

<!-- ══════════ KPI STRIP ══════════ -->
<div class="dash-kpi-grid">
  <div class="dash-kpi">
    <div class="dash-kpi-icon"><?=dash_icon('box')?></div>
    <div style="min-width:0;flex:1">
      <div class="dash-kpi-label">Bales sold</div>
      <div class="dash-kpi-value" data-count="<?=(int)($sales['bales']??0)?>"><?=number_format((int)($sales['bales']??0))?></div>
      <div class="dash-kpi-sub"><?=number_format((float)($sales['mass']??0),0)?> kg</div>
    </div>
  </div>

  <div class="dash-kpi">
    <div class="dash-kpi-icon"><?=dash_icon('dollar')?></div>
    <div style="min-width:0;flex:1">
      <div class="dash-kpi-label">Gross USD</div>
      <div class="dash-kpi-value sm" data-count="<?=(float)($sales['usd']??0)?>">$<?=number_format((float)($sales['usd']??0),2)?></div>
      <div class="dash-kpi-sub">
        <?php if($zig_rate>0):?>≈ ZiG <?=number_format((float)($sales['usd']??0)*$zig_rate,0)?><?php else:?>No ZiG rate set<?php endif;?>
      </div>
    </div>
  </div>

  <div class="dash-kpi">
    <div class="dash-kpi-icon ok"><?=dash_icon('scale')?></div>
    <div style="min-width:0;flex:1">
      <div class="dash-kpi-label">Balanced</div>
      <div class="dash-kpi-value" data-count="<?=(int)($today_bal['batches']??0)?>"><?=(int)($today_bal['batches']??0)?></div>
      <div class="dash-kpi-sub">$<?=number_format((float)($today_bal['usd']??0),0)?> gross</div>
    </div>
  </div>

  <div class="dash-kpi">
    <div class="dash-kpi-icon"><?=dash_icon('clipboard')?></div>
    <div style="min-width:0;flex:1">
      <div class="dash-kpi-label">Booked bales</div>
      <div class="dash-kpi-value" data-count="<?=$booked_bales?>"><?=number_format($booked_bales)?></div>
      <div class="dash-kpi-sub">
        <span><?=$booked_count?> growers</span>
        <?php if($pending_count>0):?><span class="dash-tag dash-tag-gold"><?=$pending_count?> pending</span><?php endif;?>
      </div>
    </div>
  </div>

  <div class="dash-kpi">
    <div class="dash-kpi-icon ok"><?=dash_icon('trophy')?></div>
    <div style="min-width:0;flex:1">
      <div class="dash-kpi-label">Season to date</div>
      <div class="dash-kpi-value sm">$<?=number_format((float)($season['usd']??0),0)?></div>
      <div class="dash-kpi-sub"><?=number_format((int)($season['bales']??0))?> bales · <?=(int)($season['batches']??0)?> batches</div>
    </div>
  </div>
</div>

<div class="dash-tabs no-print" role="tablist" aria-label="Dashboard sections">
  <button type="button" class="tab-btn active" data-tab="overview" role="tab"><?=dash_icon('bolt')?> Overview</button>
  <button type="button" class="tab-btn" data-tab="operations" role="tab"><?=dash_icon('list')?> Operations</button>
  <button type="button" class="tab-btn" data-tab="analytics" role="tab"><?=dash_icon('chart')?> Analytics</button>
</div>

<!-- ══════════ OVERVIEW TAB ══════════ -->
<div id="tab-overview" class="tab-section">
<div class="dash-grid" style="grid-template-columns:.95fr 1.05fr">

  <div class="dash-col">
    <div class="dash-card">
      <div class="dash-card-head">
        <h3><?=dash_icon('clock','head-icon')?> Sale day progress</h3>
      </div>
      <div class="dash-progress-ring">
        <?php
          $circumference = 2 * M_PI * 30;
          $offset = $circumference - ($balanced_pct / 100) * $circumference;
        ?>
        <svg class="dash-ring-svg" viewBox="0 0 72 72" role="img" aria-label="<?=$balanced_pct?>% balanced">
          <circle class="dash-ring-bg" cx="36" cy="36" r="30"/>
          <circle class="dash-ring-fg" cx="36" cy="36" r="30"
                  stroke-dasharray="<?=$circumference?>"
                  stroke-dashoffset="<?=$offset?>"/>
          <text class="dash-ring-text" x="36" y="41" text-anchor="middle"><?=$balanced_pct?>%</text>
        </svg>
        <div class="dash-ring-info">
          <div class="ring-label">Balancing</div>
          <div class="ring-stat"><strong><?=$booked_count - $pending_count?></strong> of <strong><?=$booked_count?></strong> growers balanced</div>
          <div class="ring-sub"><?=$pending_count?> still pending</div>
          <div style="margin-top:.6rem">
            <a href="balance.php" class="btn btn-ghost btn-sm">Go to Balance <?=dash_icon('arrow')?></a>
          </div>
        </div>
      </div>
    </div>

    <div class="dash-card">
      <div class="dash-card-head">
        <h3><?=dash_icon('bolt','head-icon')?> Quick actions</h3>
      </div>
      <div class="dash-links-grid">
        <?php foreach([
          ['grower_booking.php','calendar','Grower booking'],
          ['scale.php','scale','Scale / weigh'],
          ['junus.php','tag','Bale junus'],
          ['tickets.php','ticket','Print tickets'],
          ['selling.php','gavel','Floor selling'],
          ['balance.php','dollar','Balance'],
          ['sales_sheets.php','file','Sales sheets'],
          ['creditor_portal.php','bank','Creditors'],
          ['file_manager_uploads.php','folder','File manager'],
          ['settings.php','gear','Settings'],
        ] as [$url,$icon,$label]):?>
        <a href="<?=$url?>" class="dash-link">
          <span class="dash-link-icon"><?=dash_icon($icon)?></span>
          <span><?=$label?></span>
        </a>
        <?php endforeach;?>
      </div>
    </div>
  </div>

  <div class="dash-col">
    <?php if(!empty($chart_days)): ?>
    <div class="dash-card">
      <div class="dash-card-head">
        <h3><?=dash_icon('trend','head-icon')?> Last 14 selling days</h3>
      </div>
      <div class="dash-chart-wrap" style="height:300px">
        <canvas id="perf-chart" style="width:100%;height:100%"></canvas>
      </div>
    </div>
    <?php else: ?>
    <div class="dash-card">
      <div class="dash-card-head"><h3><?=dash_icon('trend','head-icon')?> Last 14 selling days</h3></div>
      <div class="dash-empty"><?=dash_icon('chart')?>No balanced sales in the 14 days up to this sale day.</div>
    </div>
    <?php endif; ?>
  </div>

</div>
</div><!-- end #tab-overview -->

<!-- ══════════ OPERATIONS TAB ══════════ -->
<div id="tab-operations" class="tab-section" style="display:none">
  <div class="dash-grid dash-ops">
    <div class="dash-col">
      <div class="dash-card">
        <div class="dash-card-head">
          <h3><?=dash_icon('user','head-icon')?> Booked growers <span class="dash-badge"><?=count($booked_list)?></span></h3>
          <a href="grower_booking.php" class="btn btn-ghost btn-sm">Manage bookings <?=dash_icon('arrow')?></a>
        </div>
        <?php if(empty($booked_list)):?>
        <div class="dash-empty"><?=dash_icon('clipboard')?>No growers booked for this sale day. <a href="grower_booking.php">Go to booking</a></div>
        <?php else:?>
        <div class="dash-table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem)">
          <table>
            <thead><tr>
              <th>Grower</th><th>Transporter</th>
              <th class="text-right">Bales</th><th>Sell date</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach($booked_list as $i => $r):
              $is_bal = (int)$r['is_balanced'];
            ?>
            <tr class="<?=$is_bal?'is-done':''?>">
              <td><span class="dash-grower"><span class="name"><?=htmlspecialchars($r['grower_name'])?></span><span class="num"><?=htmlspecialchars($r['grower_num'])?></span></span></td>
              <td class="td-muted"><?=htmlspecialchars($r['transporter'] ?? '')?></td>
              <td class="td-mono text-right" style="font-weight:600"><?=(int)$r['bales']?></td>
              <td class="td-muted"><?=htmlspecialchars($r['sell_date']??'')?></td>
              <td>
                <?php if($is_bal):?>
                  <span class="dash-status balanced"><span class="dot"></span>Balanced</span>
                <?php else:?>
                  <span class="dash-status booked"><span class="dot"></span>Booked</span>
                <?php endif;?>
              </td>
            </tr>
            <?php endforeach;?>
            </tbody>
          </table>
        </div>
        <?php endif;?>
        <div class="dash-avg-price">
          <?=dash_icon('chart')?>
          <div>
            <div class="dash-avg-label">Average price per kg</div>
            <div class="dash-avg-val">$<?=number_format($avg_price, 2)?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="dash-col">
      <div class="dash-card">
        <div class="dash-card-head">
          <h3><?=dash_icon('scale','head-icon')?> Recent balances</h3>
        </div>
        <?php if(!empty($recent)):?>
        <div class="dash-table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem)">
          <table>
            <thead><tr>
              <th>Grower</th><th class="text-right">Bales</th>
              <th class="text-right">Gross</th><th class="text-right">Net USD</th>
              <th><span class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Sheet</span></th>
            </tr></thead>
            <tbody>
            <?php foreach($recent as $r):?>
            <tr>
              <td><span class="dash-grower"><span class="name"><?=htmlspecialchars($r['grower_name'])?></span><span class="num"><?=htmlspecialchars($r['grower_num'])?></span></span></td>
              <td class="td-mono text-right"><?=(int)($r['bales']??0)?></td>
              <td class="td-mono text-right">$<?=number_format((float)($r['gross']??0),2)?></td>
              <td class="td-mono text-right" style="color:var(--ok-text);font-weight:600">$<?=number_format((float)($r['net_usd']??0),2)?></td>
              <td class="text-right">
                <?php if($r['bbid']):?>
                <a href="print_sheet.php?bbid=<?=$r['bbid']?>" target="_blank" class="btn btn-ghost btn-sm">Sheet</a>
                <?php endif;?>
              </td>
            </tr>
            <?php endforeach;?>
            </tbody>
          </table>
        </div>
        <?php else:?>
        <div class="dash-empty"><?=dash_icon('scale')?>Nothing balanced for this sale day yet.</div>
        <?php endif;?>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ ANALYTICS TAB ══════════ -->
<div id="tab-analytics" class="tab-section" style="display:none">
  <div class="dash-section-head">
    <div>
      <h3>Analytics</h3>
      <p>Floor performance for <?=date('d M Y', strtotime($sel_date))?></p>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" onclick="exportAllCharts()"><?=dash_icon('image')?> Export all charts</button>
  </div>

  <div class="dash-row-2">
    <div class="dash-card" id="card-grade-dist">
      <div class="dash-card-head">
        <h3><?=dash_icon('pie','head-icon')?> Grade distribution</h3>
        <?=dash_tools('gradeChart','grade_distribution','card-grade-dist','Grade Distribution')?>
      </div>
      <?php if(!empty($grade_dist)):?>
      <div class="dash-chart-wrap dash-chart-split" style="display:flex;align-items:center;gap:1rem">
        <div style="flex:1;max-width:220px"><canvas id="gradeChart" height="200"></canvas></div>
        <div class="dash-legend">
          <?php foreach($grade_dist as $i=>$g):
            $colors = ['#c8952a','#3d6b35','#e8b84b','#7cc576','#b3781f','#4a8a3f','#d4a649','#5fa055','#9c7320','#6b9e5c','#f0c85a','#8db47a'];
            $c = $colors[$i % count($colors)];
          ?>
          <div class="dash-legend-row">
            <span class="sw" style="background:<?=$c?>"></span>
            <span class="lbl"><?=htmlspecialchars($g['grade'])?></span>
            <span class="val"><?=$g['cnt']?> bales</span>
          </div>
          <?php endforeach;?>
        </div>
      </div>
      <?php else:?>
      <div class="dash-empty"><?=dash_icon('pie')?>No grades recorded on this sale day.</div>
      <?php endif;?>
    </div>

    <div class="dash-card" id="card-buyer-share">
      <div class="dash-card-head">
        <h3><?=dash_icon('pie','head-icon')?> Buyer market share</h3>
        <?=dash_tools('buyerChart','buyer_market_share','card-buyer-share','Buyer Market Share')?>
      </div>
      <?php if(!empty($buyer_share)):?>
      <div class="dash-chart-wrap dash-chart-split" style="display:flex;align-items:center;gap:1rem">
        <div style="flex:1;max-width:220px"><canvas id="buyerChart" height="200"></canvas></div>
        <div class="dash-legend">
          <?php
          $total_buyer_usd = array_sum(array_column($buyer_share,'usd'));
          foreach($buyer_share as $i=>$b):
            $buyerColors = ['#6b5a45','#c8952a','#3d6b35','#e8b84b','#7cc576','#b3781f','#4a8a3f','#d4a649'];
            $bc = $buyerColors[$i % count($buyerColors)];
            $pct = $total_buyer_usd > 0 ? round((float)$b['usd']/$total_buyer_usd*100,1) : 0;
          ?>
          <div class="dash-legend-row">
            <span class="sw" style="background:<?=$bc?>"></span>
            <span class="lbl"><?=htmlspecialchars($b['buyer'])?></span>
            <span class="val"><?=$pct?>%</span>
            <span style="font-weight:500;font-variant-numeric:tabular-nums">$<?=number_format((float)$b['usd'],0)?></span>
          </div>
          <?php endforeach;?>
        </div>
      </div>
      <?php else:?>
      <div class="dash-empty"><?=dash_icon('pie')?>No buyer data on this sale day.</div>
      <?php endif;?>
    </div>
  </div>

  <div class="dash-row-2">
    <div class="dash-card" id="card-grower-lead">
      <div class="dash-card-head">
        <h3><?=dash_icon('star','head-icon')?> Top growers</h3>
        <?=dash_tools('growerChart','grower_leaderboard','card-grower-lead','Top Growers Leaderboard')?>
      </div>
      <?php if(!empty($top_growers)):?>
      <div class="dash-chart-wrap" style="height:310px">
        <canvas id="growerChart" style="width:100%;height:100%"></canvas>
      </div>
      <?php else:?>
      <div class="dash-empty"><?=dash_icon('star')?>No grower sales on this sale day.</div>
      <?php endif;?>
    </div>

    <div class="dash-card" id="card-rejection-trend">
      <div class="dash-card-head">
        <h3><?=dash_icon('trend','head-icon')?> Rejections, last 14 days</h3>
        <?=dash_tools('rejChart','rejection_trend','card-rejection-trend','Rejection Trend')?>
      </div>
      <?php if(!empty($rej_trend)):?>
      <div class="dash-chart-wrap" style="height:310px">
        <canvas id="rejChart" style="width:100%;height:100%"></canvas>
      </div>
      <?php else:?>
      <div class="dash-empty"><?=dash_icon('trend')?>No rejection data available.</div>
      <?php endif;?>
    </div>
  </div>

  <?php if(!empty($price_heatmap)):
    $hm_grades = []; $hm_buyers = []; $hm_data = [];
    foreach($price_heatmap as $r) {
      $hm_grades[$r['grade']] = 1;
      $hm_buyers[$r['buyer']] = 1;
      $hm_data[$r['grade']][$r['buyer']] = ['price'=>(float)$r['avg_price'],'cnt'=>(int)$r['cnt']];
    }
    $hm_grades = array_keys($hm_grades);
    $hm_buyers = array_keys($hm_buyers);
    $all_prices = array_column($price_heatmap,'avg_price');
    $hm_min = $all_prices ? min(array_map('floatval',$all_prices)) : 0;
    $hm_max = $all_prices ? max(array_map('floatval',$all_prices)) : 1;
    $hm_range = max(0.01, $hm_max - $hm_min);
  ?>
  <div class="dash-card" id="card-price-heatmap">
    <div class="dash-card-head">
      <h3><?=dash_icon('grid','head-icon')?> Average price by grade and buyer ($/kg)</h3>
      <div class="dash-card-tools">
        <span class="dash-heat-key"><span>Low</span><span class="bar"></span><span>High</span></span>
        <button type="button" class="btn btn-ghost btn-sm btn-icon" onclick="exportHeatmapCSV()" title="Download the price table as CSV" aria-label="Download the price table as CSV"><?=dash_icon('download')?></button>
        <button type="button" class="btn btn-ghost btn-sm btn-icon" onclick="printCard('card-price-heatmap','Price Heatmap')" title="Print the price table or save as PDF" aria-label="Print the price table or save as PDF"><?=dash_icon('printer')?></button>
      </div>
    </div>
    <div style="overflow-x:auto;padding:.75rem 1rem">
      <table class="dash-heatmap">
        <thead>
          <tr>
            <th scope="col">Grade</th>
            <?php foreach($hm_buyers as $b):?>
            <th scope="col" title="<?=htmlspecialchars($b)?>"><?=htmlspecialchars($b)?></th>
            <?php endforeach;?>
          </tr>
        </thead>
        <tbody>
          <?php foreach($hm_grades as $g):?>
          <tr>
            <td><span class="badge badge-gold"><?=htmlspecialchars($g)?></span></td>
            <?php foreach($hm_buyers as $b):
              $cell = $hm_data[$g][$b] ?? null;
              $band = '';
              if($cell) {
                $intensity = ($cell['price'] - $hm_min) / $hm_range;
                $band = $intensity < 0.35 ? 'hm-low' : ($intensity < 0.7 ? 'hm-mid' : 'hm-high');
              }
            ?>
            <td class="<?=$band?>">
              <?php if($cell):?>
              <div class="hm-price">$<?=number_format($cell['price'],2)?></div>
              <div class="hm-cnt"><?=$cell['cnt']?> bale<?=$cell['cnt']!=1?'s':''?></div>
              <?php else:?>
              <span style="color:var(--text-muted)">—</span>
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
  // Colours come from the theme tokens on <body>, so the charts match light and dark mode
  var cs = getComputedStyle(document.body);
  function tok(n, d){ return (cs.getPropertyValue(n) || '').trim() || d; }
  var gold   = tok('--accent', '#c9a04a');
  var leaf   = tok('--ok-text', '#2f5a28');
  var gridC  = tok('--line', '#e7e2d8');
  var tickC  = tok('--text-muted', '#6b6253');
  var textC  = tok('--text', '#1f1a14');
  var surfC  = tok('--surface', '#ffffff');
  Chart.defaults.font.family = "'DM Sans', sans-serif";
  Chart.defaults.font.size = 12;
  Chart.defaults.color = tickC;
  Chart.defaults.borderColor = gridC;

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
            pointBackgroundColor: surfC,
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
            backgroundColor: surfC,
            titleColor: textC,
            bodyColor: textC,
            borderColor: gridC,
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
            ticks: { font:{size:11}, color: tickC },
            grid: { display:false }
          },
          y1: {
            position: 'left',
            ticks: { font:{size:11}, color: gold+'aa' },
            grid: { color: gridC },
            title: { display:true, text:'Bales', font:{size:10, weight:600}, color: gold+'bb' }
          },
          y2: {
            position: 'right',
            ticks: {
              font:{size:11}, color: leaf+'aa',
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
          borderColor: surfC
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: surfC,
            titleColor: textC,
            bodyColor: textC,
            borderColor: gridC,
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
    var bsColors = ['#6b5a45','#c8952a','#3d6b35','#e8b84b','#7cc576','#b3781f','#4a8a3f','#d4a649'];
    new Chart(buyerCanvas, {
      type: 'doughnut',
      data: {
        labels: bsLabels,
        datasets: [{
          data: bsUsd,
          backgroundColor: bsColors,
          borderWidth: 1,
          borderColor: surfC
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: surfC,
            titleColor: textC,
            bodyColor: textC,
            borderColor: gridC,
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
    // Two short lines (name, then grower number) so long names are not cut off at the axis
    var tgLabels = tgData.map(function(d) {
      var n = String(d.grower_name || '').trim();
      if (n.length > 20) n = n.slice(0, 19) + '…';
      return [n, d.grower_num];
    });
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
            backgroundColor: surfC,
            titleColor: textC,
            bodyColor: textC,
            borderColor: gridC,
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
            grid: { color: gridC },
            ticks: {
              font: { size: 11 },
              callback: function(v) { return '$' + v.toLocaleString(); }
            }
          },
          y: {
            grid: { display: false },
            ticks: { font: { size: 11 }, color: tickC, autoSkip: false }
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
          pointBackgroundColor: surfC,
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
            backgroundColor: surfC,
            titleColor: textC,
            bodyColor: textC,
            borderColor: gridC,
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
            ticks: { font: { size: 11 } }
          },
          y: {
            grid: { color: gridC },
            ticks: {
              font: { size: 11 },
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
