<?php
/**
 * buyer_portal.php — Buyer Purchase History Portal
 * Standalone portal — no sidebar, own nav, separate session.
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/conn.php";

// ── Auth guard ────────────────────────────────────────────────────────────
if (empty($_SESSION['buyer_portal_user'])) {
    header('Location: buyer_login.php');
    exit;
}

$buyer_session = $_SESSION['buyer_portal_user'];
$buyerid       = (int)$buyer_session['buyerid'];
$buyer_uname   = htmlspecialchars($buyer_session['username'] ?? '');

// ── Get buyer name from DB ────────────────────────────────────────────────
$bn_q    = $conn->query("SELECT name FROM buyer WHERE id=$buyerid LIMIT 1");
$buyer_name = ($bn_q && $bn_q !== true && $bn_q->num_rows > 0)
    ? htmlspecialchars($bn_q->fetch_assoc()['name'])
    : $buyer_uname;

// ── Date range / filter ───────────────────────────────────────────────────
$quick = $_GET['q'] ?? '';
$today = date('Y-m-d');

// Season start: typically 1 April
$season_start_month = 4;
$year = (int)date('Y');
$season_start = ($year . '-' . str_pad($season_start_month, 2, '0', STR_PAD_LEFT) . '-01');
if (strtotime($today) < strtotime($season_start)) {
    $season_start = (($year - 1) . '-' . str_pad($season_start_month, 2, '0', STR_PAD_LEFT) . '-01');
}

switch ($quick) {
    case 'today':
        $date_from = $date_to = $today;
        break;
    case '7d':
        $date_from = date('Y-m-d', strtotime('-6 days'));
        $date_to   = $today;
        break;
    case 'month':
        $date_from = date('Y-m-01');
        $date_to   = $today;
        break;
    case 'season':
        $date_from = $season_start;
        $date_to   = $today;
        break;
    default:
        $date_from = $_GET['df'] ?? $season_start;
        $date_to   = $_GET['dt'] ?? $today;
}

// Sanitise dates
$date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) ? $date_from : $season_start;
$date_to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)   ? $date_to   : $today;
$df_esc    = $conn->real_escape_string($date_from);
$dt_esc    = $conn->real_escape_string($date_to);

// ── CSV export (must happen before any output) ───────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exp_q = $conn->query("
        SELECT
            DATE(sb.created_at)                                                 AS sale_date,
            sb.id                                                               AS sale_id,
            g.grower_num,
            CONCAT(g.name,' ',COALESCE(g.surname,''))                          AS grower_name,
            COALESCE(tg.description,'—')                                        AS grade,
            bm.mass,
            CASE WHEN sb.price > 10 THEN sb.price/100 ELSE sb.price END        AS price_per_kg,
            (CASE WHEN sb.price > 10 THEN sb.price/100 ELSE sb.price END)*bm.mass AS total_usd,
            sb.sale_code
        FROM sold_bales sb
        JOIN buyer_grades bg ON bg.id = sb.buyer_gradesid
        JOIN buyer b         ON b.id  = bg.buyerid
        JOIN tickets tk      ON tk.id = sb.ticketsid
        JOIN bale_junus bj   ON bj.id = tk.bale_junusid
        JOIN bale_mass bm    ON bm.id = bj.bale_massid
        JOIN growers g       ON g.id  = bj.growerid
        LEFT JOIN timb_grades tg ON tg.id = sb.timb_gradesid
        WHERE b.id = $buyerid
          AND DATE(sb.created_at) BETWEEN '$df_esc' AND '$dt_esc'
        ORDER BY sb.created_at DESC
        LIMIT 500
    ");
    $rows = ($exp_q && $exp_q !== true) ? $exp_q->fetch_all(MYSQLI_ASSOC) : [];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="flowork_purchases_' . $date_from . '_to_' . $date_to . '.csv"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['Sale Date','Sale ID','Grower#','Grower Name','Grade','Mass (kg)','Price $/kg','Total USD','Sale Code']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['sale_date'], $r['sale_id'], $r['grower_num'], $r['grower_name'],
            $r['grade'], number_format((float)$r['mass'],2),
            number_format((float)$r['price_per_kg'],4),
            number_format((float)$r['total_usd'],2),
            $r['sale_code'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// ── KPI — All-time totals ─────────────────────────────────────────────────
$kpi_all_q = $conn->query("
    SELECT
        COUNT(sb.id)                                                                   AS total_bales,
        COALESCE(SUM((CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END)*bm.mass),0) AS total_usd
    FROM sold_bales sb
    JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    JOIN buyer b         ON b.id=bg.buyerid
    JOIN tickets tk      ON tk.id=sb.ticketsid
    JOIN bale_junus bj   ON bj.id=tk.bale_junusid
    JOIN bale_mass bm    ON bm.id=bj.bale_massid
    WHERE b.id=$buyerid
");
$kpi_all = ($kpi_all_q && $kpi_all_q !== true) ? $kpi_all_q->fetch_assoc() : [];
$all_bales = (int)($kpi_all['total_bales'] ?? 0);
$all_usd   = (float)($kpi_all['total_usd'] ?? 0);

// ── KPI — This season (season_start to today) ─────────────────────────────
$ss_esc = $conn->real_escape_string($season_start);
$kpi_season_q = $conn->query("
    SELECT
        COUNT(sb.id)                                                                   AS season_bales,
        COALESCE(SUM((CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END)*bm.mass),0) AS season_usd,
        COALESCE(SUM(bm.mass),0)                                                       AS season_mass
    FROM sold_bales sb
    JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    JOIN buyer b         ON b.id=bg.buyerid
    JOIN tickets tk      ON tk.id=sb.ticketsid
    JOIN bale_junus bj   ON bj.id=tk.bale_junusid
    JOIN bale_mass bm    ON bm.id=bj.bale_massid
    WHERE b.id=$buyerid
      AND DATE(sb.created_at) BETWEEN '$ss_esc' AND '$today'
");
$kpi_season = ($kpi_season_q && $kpi_season_q !== true) ? $kpi_season_q->fetch_assoc() : [];
$season_bales = (int)($kpi_season['season_bales'] ?? 0);
$season_usd   = (float)($kpi_season['season_usd'] ?? 0);
$season_mass  = (float)($kpi_season['season_mass'] ?? 0);
$season_avg   = $season_mass > 0 ? $season_usd / $season_mass : 0;

// ── Purchase history (filtered) ───────────────────────────────────────────
$hist_q = $conn->query("
    SELECT
        DATE(sb.created_at)                                                     AS sale_date,
        sb.id                                                                   AS sale_id,
        g.grower_num,
        CONCAT(g.name,' ',COALESCE(g.surname,''))                              AS grower_name,
        COALESCE(tg.description,'—')                                            AS grade,
        bm.mass,
        CASE WHEN sb.price > 10 THEN sb.price/100 ELSE sb.price END            AS price_per_kg,
        (CASE WHEN sb.price > 10 THEN sb.price/100 ELSE sb.price END)*bm.mass AS total_usd,
        sb.sale_code
    FROM sold_bales sb
    JOIN buyer_grades bg ON bg.id = sb.buyer_gradesid
    JOIN buyer b         ON b.id  = bg.buyerid
    JOIN tickets tk      ON tk.id = sb.ticketsid
    JOIN bale_junus bj   ON bj.id = tk.bale_junusid
    JOIN bale_mass bm    ON bm.id = bj.bale_massid
    JOIN growers g       ON g.id  = bj.growerid
    LEFT JOIN timb_grades tg ON tg.id = sb.timb_gradesid
    WHERE b.id = $buyerid
      AND DATE(sb.created_at) BETWEEN '$df_esc' AND '$dt_esc'
    ORDER BY sb.created_at DESC
    LIMIT 500
");
$history = ($hist_q && $hist_q !== true) ? $hist_q->fetch_all(MYSQLI_ASSOC) : [];

// ── Grade breakdown (filtered) ────────────────────────────────────────────
$grade_q = $conn->query("
    SELECT
        COALESCE(tg.description,'Unknown')                                      AS grade,
        COUNT(sb.id)                                                            AS bale_count,
        COALESCE(SUM(bm.mass),0)                                               AS total_mass,
        COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) AS avg_price,
        COALESCE(SUM((CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END)*bm.mass),0) AS total_usd
    FROM sold_bales sb
    JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    JOIN buyer b         ON b.id=bg.buyerid
    JOIN tickets tk      ON tk.id=sb.ticketsid
    JOIN bale_junus bj   ON bj.id=tk.bale_junusid
    JOIN bale_mass bm    ON bm.id=bj.bale_massid
    LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    WHERE b.id=$buyerid
      AND DATE(sb.created_at) BETWEEN '$df_esc' AND '$dt_esc'
    GROUP BY COALESCE(tg.description,'Unknown')
    ORDER BY total_usd DESC
");
$grades = ($grade_q && $grade_q !== true) ? $grade_q->fetch_all(MYSQLI_ASSOC) : [];

// Compute proportions for grade bar
$grade_total_usd = array_sum(array_column($grades, 'total_usd')) ?: 1;
$grade_total_mass = array_sum(array_column($grades, 'total_mass'));

// Period totals
$period_bales = count($history);
$period_usd   = array_sum(array_column($history, 'total_usd'));
$period_mass  = array_sum(array_column($history, 'mass'));
$period_avg   = $period_mass > 0 ? $period_usd / $period_mass : 0;

// ── Helpers ───────────────────────────────────────────────────────────────
function fmtUSD($v) { return '$' . number_format((float)$v, 2); }
function fmtKg($v)  { return number_format((float)$v, 1) . ' kg'; }

// Build export URL preserving current filter
$export_url = 'buyer_portal.php?export=csv&df=' . urlencode($date_from) . '&dt=' . urlencode($date_to);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Buyer Portal — Flowork</title>
<meta name="description" content="Your tobacco purchase history and grade analytics — Flowork Buyer Portal.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --ink:     #1a1208;
    --ink2:    #231808;
    --gold:    #c8952a;
    --gold-l:  #e8b84b;
    --gold-d:  #92650e;
    --cream:   #f5f0e8;
    --muted:   rgba(245,240,232,0.4);
    --muted2:  rgba(245,240,232,0.6);
    --leaf:    #3d6b35;
    --danger:  #c03a2b;
    --border:  rgba(200,149,42,0.15);
    --card-bg: rgba(255,255,255,0.035);
    --radius:  10px;
}

html { scroll-behavior: smooth; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--ink);
    color: var(--cream);
    min-height: 100vh;
}

/* ── Ambient background ───────────────────────────────────────────────── */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse 900px 600px at 80% 0%, rgba(200,149,42,0.05) 0%, transparent 60%),
        radial-gradient(ellipse 600px 800px at 0% 100%, rgba(61,107,53,0.04) 0%, transparent 65%);
    pointer-events: none;
    z-index: 0;
}

/* ══════════════════════════════════════════ TOP NAV ═══════════════════ */
.topnav {
    position: sticky;
    top: 0;
    z-index: 200;
    background: rgba(26,18,8,0.92);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--border);
    height: 58px;
    display: flex;
    align-items: center;
    padding: 0 1.5rem;
    gap: 1rem;
}

.nav-brand {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    text-decoration: none;
    flex-shrink: 0;
}

.nav-icon {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, var(--gold), var(--gold-d));
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.nav-wordmark {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.35rem;
    letter-spacing: 0.1em;
    color: var(--cream);
    line-height: 1;
}

.nav-wordmark span {
    font-family: 'DM Sans', sans-serif;
    font-size: 0.62rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--gold);
    display: block;
    font-weight: 500;
    margin-top: 1px;
}

.nav-spacer { flex: 1; }

.nav-user {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    font-size: 0.8rem;
    color: var(--muted2);
}

.nav-avatar {
    width: 30px;
    height: 30px;
    background: rgba(200,149,42,0.2);
    border: 1px solid rgba(200,149,42,0.3);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--gold);
    flex-shrink: 0;
}

.nav-logout {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.38rem 0.9rem;
    background: rgba(200,149,42,0.1);
    border: 1px solid rgba(200,149,42,0.25);
    border-radius: 6px;
    color: var(--gold);
    font-size: 0.78rem;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.2s, border-color 0.2s;
    white-space: nowrap;
}

.nav-logout:hover {
    background: rgba(200,149,42,0.18);
    border-color: rgba(200,149,42,0.45);
}

/* ══════════════════════════════════════════ LAYOUT ════════════════════ */
.wrap {
    max-width: 1160px;
    margin: 0 auto;
    padding: 1.75rem 1.25rem 4rem;
    position: relative;
    z-index: 1;
}

/* ══════════════════════════════════════════ KPI STRIP ═════════════════ */
.kpi-strip {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 1px;
    background: var(--border);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 1.5rem;
}

.kpi-card {
    background: var(--card-bg);
    padding: 1.1rem 1.25rem;
    text-align: center;
    position: relative;
    overflow: hidden;
    transition: background 0.2s;
}

.kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--gold), transparent);
    opacity: 0;
    transition: opacity 0.2s;
}

.kpi-card:hover { background: rgba(200,149,42,0.06); }
.kpi-card:hover::before { opacity: 1; }

.kpi-val {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.9rem;
    color: var(--gold);
    letter-spacing: 0.04em;
    line-height: 1;
    margin-bottom: 0.3rem;
}

.kpi-val.accent-green { color: #6dd36d; }
.kpi-val.accent-cream { color: var(--cream); }

.kpi-lbl {
    font-size: 0.62rem;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--muted);
    margin-bottom: 0.2rem;
}

.kpi-sub {
    font-size: 0.65rem;
    color: var(--muted);
}

/* ══════════════════════════════════════════ FILTER BAR ════════════════ */
.filter-bar {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    flex-wrap: wrap;
    margin-bottom: 1.25rem;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 0.85rem 1.1rem;
}

.filter-label {
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--muted);
    flex-shrink: 0;
}

.quick-btns { display: flex; gap: 0.4rem; flex-wrap: wrap; }

.qbtn {
    padding: 0.35rem 0.85rem;
    border-radius: 999px;
    border: 1px solid rgba(200,149,42,0.2);
    background: transparent;
    color: var(--muted2);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s;
    white-space: nowrap;
}

.qbtn:hover, .qbtn.active {
    background: rgba(200,149,42,0.15);
    border-color: var(--gold);
    color: var(--gold-l);
}

.filter-sep {
    width: 1px;
    height: 24px;
    background: var(--border);
    flex-shrink: 0;
}

.date-inputs { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }

.date-inputs input[type=date] {
    padding: 0.38rem 0.75rem;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(200,149,42,0.2);
    border-radius: 6px;
    color: var(--cream);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.8rem;
    outline: none;
    transition: border-color 0.2s;
    color-scheme: dark;
}

.date-inputs input[type=date]:focus {
    border-color: var(--gold);
    box-shadow: 0 0 0 2px rgba(200,149,42,0.15);
}

.date-label { font-size: 0.72rem; color: var(--muted); }

.btn-apply {
    padding: 0.38rem 0.9rem;
    background: var(--gold);
    color: var(--ink);
    border: none;
    border-radius: 6px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: filter 0.15s, transform 0.1s;
}

.btn-apply:hover { filter: brightness(1.1); transform: translateY(-1px); }

/* ══════════════════════════════════════════ SECTION CARD ══════════════ */
.section-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 1.25rem;
}

.section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid var(--border);
    background: rgba(200,149,42,0.04);
    gap: 0.75rem;
    flex-wrap: wrap;
}

.section-head h2 {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.05rem;
    letter-spacing: 0.06em;
    color: var(--cream);
    font-weight: 400;
}

.section-head .badge {
    font-size: 0.65rem;
    font-weight: 600;
    padding: 2px 10px;
    border-radius: 999px;
    background: rgba(200,149,42,0.15);
    border: 1px solid rgba(200,149,42,0.3);
    color: var(--gold);
    white-space: nowrap;
}

.section-body { padding: 1.1rem 1.25rem; }

/* ══════════════════════════════════════════ TABLE ═════════════════════ */
.tbl-wrap { overflow-x: auto; }

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
    min-width: 650px;
}

th {
    padding: 0.55rem 0.85rem;
    text-align: left;
    font-size: 0.62rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
    background: rgba(200,149,42,0.04);
}

td {
    padding: 0.55rem 0.85rem;
    border-bottom: 1px solid rgba(200,149,42,0.07);
    vertical-align: middle;
}

tbody tr:last-child td { border-bottom: none; }

tbody tr:hover td { background: rgba(200,149,42,0.04); }

.tfoot-row td {
    border-top: 1px solid var(--border);
    font-weight: 600;
    background: rgba(200,149,42,0.05);
    padding-top: 0.65rem;
    padding-bottom: 0.65rem;
}

.tr { text-align: right; }
.tc { text-align: center; }
.mono { font-family: 'Courier New', monospace; font-size: 0.78rem; }
.fw  { font-weight: 600; }

.gold-text  { color: var(--gold); }
.green-text { color: #6dd36d; }
.muted-text { color: var(--muted); }

/* Grade badge */
.grade-pill {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 999px;
    font-size: 0.65rem;
    font-weight: 600;
    background: rgba(200,149,42,0.12);
    border: 1px solid rgba(200,149,42,0.25);
    color: var(--gold-l);
    white-space: nowrap;
}

/* ══════════════════════════════════════════ GRADE BREAKDOWN ═══════════ */
.grade-row {
    display: grid;
    grid-template-columns: 90px 1fr 80px 90px 100px;
    align-items: center;
    gap: 1rem;
    padding: 0.65rem 0;
    border-bottom: 1px solid rgba(200,149,42,0.07);
}

.grade-row:last-child { border-bottom: none; }

.grade-row-label {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--cream);
}

.grade-bar-wrap {
    height: 7px;
    background: rgba(255,255,255,0.06);
    border-radius: 999px;
    overflow: hidden;
}

.grade-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--gold-d), var(--gold-l));
    border-radius: 999px;
    transition: width 0.6s cubic-bezier(0.4,0,0.2,1);
}

.grade-meta {
    font-size: 0.72rem;
    color: var(--muted);
    text-align: right;
    white-space: nowrap;
}

/* ══════════════════════════════════════════ EXPORT BUTTON ═════════════ */
.btn-export {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.42rem 1rem;
    background: transparent;
    border: 1px solid rgba(200,149,42,0.3);
    border-radius: 6px;
    color: var(--gold);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.78rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s, border-color 0.2s;
    white-space: nowrap;
}

.btn-export:hover {
    background: rgba(200,149,42,0.12);
    border-color: var(--gold);
}

/* ══════════════════════════════════════════ EMPTY STATE ═══════════════ */
.empty-state {
    text-align: center;
    padding: 2.5rem 1rem;
    color: var(--muted);
}

.empty-state svg { margin-bottom: 0.75rem; opacity: 0.35; }

.empty-state p { font-size: 0.85rem; }

/* ══════════════════════════════════════════ RESPONSIVE ════════════════ */
@media (max-width: 860px) {
    .kpi-strip { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 600px) {
    .kpi-strip { grid-template-columns: repeat(2, 1fr); }
    .grade-row { grid-template-columns: 70px 1fr 65px; }
    .grade-row > :nth-child(4), .grade-row > :nth-child(5) { display: none; }
    .topnav { padding: 0 1rem; }
    .wrap { padding: 1rem 0.75rem 3rem; }
    .nav-user .nav-name { display: none; }
}

/* ══════════════════════════════════════════ ANIMATIONS ════════════════ */
@keyframes countUp {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}

.kpi-val { animation: countUp 0.5s ease both; }
.kpi-card:nth-child(1) .kpi-val { animation-delay: 0.05s; }
.kpi-card:nth-child(2) .kpi-val { animation-delay: 0.1s; }
.kpi-card:nth-child(3) .kpi-val { animation-delay: 0.15s; }
.kpi-card:nth-child(4) .kpi-val { animation-delay: 0.2s; }
.kpi-card:nth-child(5) .kpi-val { animation-delay: 0.25s; }

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

.section-card { animation: fadeInUp 0.4s ease both; }
.section-card:nth-child(2) { animation-delay: 0.08s; }
.section-card:nth-child(3) { animation-delay: 0.14s; }
.section-card:nth-child(4) { animation-delay: 0.2s; }
</style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════ TOP NAV ════ -->
<nav class="topnav" role="navigation" aria-label="Buyer portal navigation">
  <a href="buyer_portal.php" class="nav-brand" aria-label="Flowork Buyer Portal home">
    <div class="nav-icon" aria-hidden="true">
      <svg width="16" height="16" viewBox="0 0 20 20" fill="none">
        <path d="M10 2C7 2 5 4.5 5 7.5c0 2 1.2 3.8 3 4.8L10 16l2-3.7c1.8-1 3-2.8 3-4.8C15 4.5 13 2 10 2z" fill="#1a1208"/>
      </svg>
    </div>
    <div class="nav-wordmark">
      FLOWORK
      <span>Buyer Portal</span>
    </div>
  </a>

  <div class="nav-spacer"></div>

  <div class="nav-user">
    <div class="nav-avatar" aria-hidden="true"><?= strtoupper(substr($buyer_uname, 0, 1)) ?></div>
    <span class="nav-name" style="font-size:0.82rem;color:var(--muted2)"><?= $buyer_name ?></span>
    <a href="buyer_login.php?logout=1" class="nav-logout" id="btn-logout">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 2h4v12h-4M6 11l4-3-4-3"/></svg>
      Logout
    </a>
  </div>
</nav>

<!-- ══════════════════════════════════════════════════════ PAGE BODY ══ -->
<div class="wrap">

<!-- ── KPI STRIP ──────────────────────────────────────────────────────── -->
<div class="kpi-strip" role="region" aria-label="Key performance indicators">

  <div class="kpi-card">
    <div class="kpi-lbl">All-Time Bales</div>
    <div class="kpi-val" id="kpi-allbales"><?= number_format($all_bales) ?></div>
    <div class="kpi-sub">purchased</div>
  </div>

  <div class="kpi-card">
    <div class="kpi-lbl">All-Time Spend</div>
    <div class="kpi-val accent-cream" id="kpi-allusd"><?= fmtUSD($all_usd) ?></div>
    <div class="kpi-sub">total USD</div>
  </div>

  <div class="kpi-card">
    <div class="kpi-lbl">This Season Bales</div>
    <div class="kpi-val" id="kpi-seabales"><?= number_format($season_bales) ?></div>
    <div class="kpi-sub"><?= fmtKg($season_mass) ?></div>
  </div>

  <div class="kpi-card">
    <div class="kpi-lbl">This Season Spend</div>
    <div class="kpi-val accent-green" id="kpi-seausd"><?= fmtUSD($season_usd) ?></div>
    <div class="kpi-sub">gross USD</div>
  </div>

  <div class="kpi-card">
    <div class="kpi-lbl">Avg $/kg (Season)</div>
    <div class="kpi-val" id="kpi-avg"><?= '$' . number_format($season_avg, 4) ?></div>
    <div class="kpi-sub">per kilogram</div>
  </div>

</div>

<!-- ── DATE FILTER BAR ────────────────────────────────────────────────── -->
<div class="filter-bar" role="search" aria-label="Date range filter">
  <span class="filter-label">Filter:</span>

  <div class="quick-btns">
    <a href="buyer_portal.php?q=today"  class="qbtn <?= $quick==='today'  ?'active':'' ?>">Today</a>
    <a href="buyer_portal.php?q=7d"     class="qbtn <?= $quick==='7d'     ?'active':'' ?>">Last 7 Days</a>
    <a href="buyer_portal.php?q=month"  class="qbtn <?= $quick==='month'  ?'active':'' ?>">This Month</a>
    <a href="buyer_portal.php?q=season" class="qbtn <?= $quick==='season' ?'active':'' ?>">This Season</a>
  </div>

  <div class="filter-sep"></div>

  <form method="GET" action="buyer_portal.php" id="date-filter-form">
    <div class="date-inputs">
      <span class="date-label">From</span>
      <input type="date" name="df" id="filter-from"
             value="<?= htmlspecialchars($date_from) ?>"
             aria-label="From date">
      <span class="date-label">To</span>
      <input type="date" name="dt" id="filter-to"
             value="<?= htmlspecialchars($date_to) ?>"
             aria-label="To date">
      <button type="submit" class="btn-apply">Apply</button>
    </div>
  </form>

  <div class="nav-spacer" style="flex:1"></div>

  <a href="<?= htmlspecialchars($export_url) ?>" class="btn-export" id="btn-export" aria-label="Download CSV">
    <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v8M5 7l3 3 3-3M2 12v2h12v-2"/></svg>
    Export CSV
  </a>
</div>

<!-- ── PURCHASE HISTORY TABLE ─────────────────────────────────────────── -->
<div class="section-card" role="region" aria-labelledby="hist-heading">
  <div class="section-head">
    <h2 id="hist-heading">Purchase History</h2>
    <div style="display:flex;align-items:center;gap:0.65rem;flex-wrap:wrap">
      <span class="badge"><?= number_format($period_bales) ?> bales</span>
      <span class="badge" style="color:var(--gold-l)"><?= fmtUSD($period_usd) ?></span>
      <span style="font-size:0.65rem;color:var(--muted)"><?= htmlspecialchars($date_from) ?> → <?= htmlspecialchars($date_to) ?></span>
    </div>
  </div>

  <?php if (empty($history)): ?>
  <div class="empty-state">
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
      <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
    </svg>
    <p>No purchases found for the selected date range.</p>
  </div>
  <?php else: ?>
  <div class="tbl-wrap">
    <table aria-label="Purchase history table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Batch #</th>
          <th>Grower #</th>
          <th>Grower Name</th>
          <th>Grade</th>
          <th class="tr">Mass (kg)</th>
          <th class="tr">$/kg</th>
          <th class="tr">Total USD</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($history as $row): ?>
        <tr>
          <td style="white-space:nowrap;font-size:0.78rem;color:var(--muted2)">
            <?= date('d M Y', strtotime($row['sale_date'])) ?>
          </td>
          <td class="mono" style="font-size:0.72rem;color:var(--muted)">
            <?= htmlspecialchars($row['sale_code'] ?? $row['sale_id']) ?>
          </td>
          <td class="mono fw" style="font-size:0.75rem">
            <?= htmlspecialchars($row['grower_num']) ?>
          </td>
          <td style="font-size:0.78rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= htmlspecialchars($row['grower_name']) ?>
          </td>
          <td>
            <span class="grade-pill"><?= htmlspecialchars($row['grade']) ?></span>
          </td>
          <td class="tr mono"><?= number_format((float)$row['mass'], 1) ?></td>
          <td class="tr mono gold-text">$<?= number_format((float)$row['price_per_kg'], 4) ?></td>
          <td class="tr mono fw green-text"><?= fmtUSD($row['total_usd']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="tfoot-row">
          <td colspan="5" style="font-size:0.75rem;color:var(--muted)">
            Period Total<?= count($history) >= 500 ? ' (capped at 500 rows)' : '' ?>
          </td>
          <td class="tr mono"><?= number_format($period_mass, 1) ?></td>
          <td class="tr mono gold-text">
            $<?= $period_mass > 0 ? number_format($period_usd / $period_mass, 4) : '—' ?>
            <span style="font-size:0.6rem;color:var(--muted);display:block;font-weight:400">avg $/kg</span>
          </td>
          <td class="tr mono fw green-text"><?= fmtUSD($period_usd) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── GRADE BREAKDOWN ────────────────────────────────────────────────── -->
<div class="section-card" role="region" aria-labelledby="grade-heading">
  <div class="section-head">
    <h2 id="grade-heading">Grade Breakdown</h2>
    <?php if (!empty($grades)): ?>
    <span class="badge"><?= count($grades) ?> grades</span>
    <?php endif; ?>
  </div>

  <?php if (empty($grades)): ?>
  <div class="empty-state">
    <p>No grade data for the selected period.</p>
  </div>
  <?php else: ?>
  <div class="section-body">

    <!-- Table header -->
    <div class="grade-row" style="padding-bottom:0.4rem;margin-bottom:0.25rem;border-bottom:1px solid var(--border)">
      <span style="font-size:0.62rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted)">Grade</span>
      <span style="font-size:0.62rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted)">Proportion</span>
      <span style="font-size:0.62rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);text-align:right">Bales</span>
      <span style="font-size:0.62rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);text-align:right">Avg $/kg</span>
      <span style="font-size:0.62rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);text-align:right">Total USD</span>
    </div>

    <?php
    $grade_colors = [
      'linear-gradient(90deg,#c8952a,#e8b84b)',
      'linear-gradient(90deg,#3d6b35,#6dd36d)',
      'linear-gradient(90deg,#2a5fc8,#4b88e8)',
      'linear-gradient(90deg,#c82a6f,#e84baa)',
      'linear-gradient(90deg,#7a2ac8,#b04be8)',
      'linear-gradient(90deg,#c87a2a,#e8b44b)',
    ];
    foreach ($grades as $gi => $gr):
        $pct = ($grade_total_usd > 0) ? round($gr['total_usd'] / $grade_total_usd * 100, 1) : 0;
        $bar_color = $grade_colors[$gi % count($grade_colors)];
    ?>
    <div class="grade-row">
      <div class="grade-row-label">
        <span class="grade-pill"><?= htmlspecialchars($gr['grade']) ?></span>
      </div>
      <div>
        <div class="grade-bar-wrap" title="<?= $pct ?>%">
          <div class="grade-bar-fill"
               data-width="<?= $pct ?>"
               style="width:0%;background:<?= $bar_color ?>"></div>
        </div>
        <div style="font-size:0.62rem;color:var(--muted);margin-top:3px"><?= $pct ?>%</div>
      </div>
      <div class="grade-meta"><?= number_format((int)$gr['bale_count']) ?> bales</div>
      <div class="grade-meta gold-text">$<?= number_format((float)$gr['avg_price'], 4) ?></div>
      <div class="grade-meta fw green-text"><?= fmtUSD($gr['total_usd']) ?></div>
    </div>
    <?php endforeach; ?>

    <!-- Grade totals -->
    <div class="grade-row" style="border-top:1px solid var(--border);padding-top:0.6rem;margin-top:0.25rem;font-weight:600">
      <span style="font-size:0.75rem;color:var(--cream)">Total</span>
      <span></span>
      <span class="grade-meta fw"><?= number_format(array_sum(array_column($grades,'bale_count'))) ?> bales</span>
      <span class="grade-meta fw gold-text">
        $<?= $grade_total_mass > 0 ? number_format(array_sum(array_column($grades,'total_usd')) / $grade_total_mass, 4) : '—' ?>
      </span>
      <span class="grade-meta fw green-text"><?= fmtUSD(array_sum(array_column($grades,'total_usd'))) ?></span>
    </div>

  </div>
  <?php endif; ?>
</div>

<!-- Footer -->
<div style="text-align:center;padding:1rem 0 0.5rem;font-size:0.65rem;color:rgba(245,240,232,0.15);letter-spacing:0.08em">
  FLOWORK &copy; <?= date('Y') ?> &middot; Core Africa Group &middot; Data for information purposes only
</div>

</div><!-- /wrap -->

<script>
// ── Animate grade bars on load ────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    var fills = document.querySelectorAll('.grade-bar-fill[data-width]');
    setTimeout(function () {
        fills.forEach(function (el) {
            el.style.width = el.getAttribute('data-width') + '%';
        });
    }, 120);
});

// ── Animated number counter for KPIs ─────────────────────────────────────
function animateCounter(el, rawVal, prefix, suffix, decimals) {
    var start = 0;
    var end   = parseFloat(rawVal);
    var dur   = 900;
    var step  = 16;
    var steps = Math.ceil(dur / step);
    var inc   = end / steps;
    var cur   = 0;
    var timer = setInterval(function () {
        cur += inc;
        if (cur >= end) { cur = end; clearInterval(timer); }
        el.textContent = prefix + cur.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + suffix;
    }, step);
}

window.addEventListener('load', function () {
    // KPI counters
    var kpis = [
        { id: 'kpi-allbales', val: <?= $all_bales ?>,    pre: '',  suf: '', dec: 0 },
        { id: 'kpi-allusd',   val: <?= $all_usd ?>,      pre: '$', suf: '', dec: 2 },
        { id: 'kpi-seabales', val: <?= $season_bales ?>, pre: '',  suf: '', dec: 0 },
        { id: 'kpi-seausd',   val: <?= $season_usd ?>,   pre: '$', suf: '', dec: 2 },
        { id: 'kpi-avg',      val: <?= $season_avg ?>,   pre: '$', suf: '', dec: 4 },
    ];
    kpis.forEach(function (k) {
        var el = document.getElementById(k.id);
        if (el) animateCounter(el, k.val, k.pre, k.suf, k.dec);
    });
});

// ── Date filter: auto-submit on date change, clear quick param ────────────
document.querySelectorAll('#filter-from, #filter-to').forEach(function (inp) {
    inp.addEventListener('change', function () {
        // Remove ?q= quick button param when custom dates used
        document.querySelectorAll('.qbtn').forEach(function (b) { b.classList.remove('active'); });
    });
});
</script>
</body>
</html>
