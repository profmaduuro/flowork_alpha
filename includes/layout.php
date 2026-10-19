<?php
// includes/layout.php
$page_title  = $page_title  ?? 'Flowork';
$active_page = $active_page ?? '';
// Theme: read cookie, default dark
$theme = ($_COOKIE['flowork_theme'] ?? 'dark') === 'light' ? 'light' : 'dark';

// ── Dynamic base path — works on localhost/flowork AND live root installs ──
if (!defined('APP_BASE')) {
    // Detect the subfolder this file lives in, relative to doc root
    $script_dir  = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $doc_root    = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
    $sub         = str_replace($doc_root, '', $script_dir);
    // Walk up one level if we're inside an /includes subfolder
    $sub         = rtrim(preg_replace('#/includes$#', '', $sub), '/');
    define('APP_BASE', $sub); // e.g. '' on root, '/flowork' on localhost
}
// Handle theme toggle POST
if (!empty($_POST['toggle_theme'])) {
    $new_theme = $_POST['toggle_theme'] === 'light' ? 'light' : 'dark';
    setcookie('flowork_theme', $new_theme, time()+60*60*24*365, '/');
    $theme = $new_theme;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<?= csrf_js() ?>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title) ?> — Flowork</title>
<link rel="manifest" href="manifest.json">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="icon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#1a1208;--ink-soft:#2d2418;--gold:#c8952a;--gold-l:#e8b84b;--gold-bg:#fdf6e8;
  --cream:#f5f0e8;--cream-d:#ede6d6;--leaf:#3d6b35;--leaf-l:#edf4ec;
  --muted:#7a6e5f;--border:rgba(26,18,8,0.1);
  --sidebar-w:224px;--top-h:54px;
  --danger:#c0392b;--danger-bg:#fdf2f2;--info:#1a6b9a;--info-bg:#eef5fb;
  --radius:4px;
  /* Emphasised body text. Use this instead of --ink for text on a card/page
     surface: --ink is also the dark chrome background, so it cannot flip. */
  --text-strong:#1a1208;
}
body.theme-light{
  --ink:#1a1208;--ink-soft:#f0ebe0;--gold:#a07020;--gold-l:#b8841e;--gold-bg:#fdf6e8;
  --cream:#ffffff;--cream-d:#f5f0e8;--leaf:#2d5226;--leaf-l:#edf4ec;
  --muted:#5a5040;--border:rgba(26,18,8,0.12);
  --danger:#c0392b;--danger-bg:#fdf2f2;--info:#1a6b9a;--info-bg:#eef5fb;
  background:#f5f0e8;color:#1a1208;
}
body.theme-light .topbar{background:#2d2010;border-bottom-color:#a07020}
body.theme-light .sidebar{background:#2d2010;border-right-color:rgba(160,112,32,0.2)}
body.theme-light .card{background:#fff;border-color:rgba(26,18,8,0.1)}
body.theme-light .table-wrap table thead{background:#f5f0e8}
/* ── DARK THEME ───────────────────────────────────────────────────────────
   --ink and --cream are overloaded (chrome background AND light text), so they
   are deliberately NOT flipped here — the surfaces are overridden per rule
   instead. --muted / --border / --cream-d are single-purpose and safe to flip. */
body.theme-dark{
  --cream-d:#221c15;
  --muted:#a89d8d;
  --border:rgba(245,240,232,0.13);
  /* Accent colours are overwhelmingly used as TEXT (leaf 152 text vs 22 decorative
     backgrounds, danger 149 vs 10), so they are lightened for contrast on dark
     surfaces; the few background users are pinned below. */
  --leaf:#7fc97f;
  --danger:#e8705f;
  --info:#7fc3ef;
  --text-strong:#efe9dd;
  background:#15120e;color:#efe9dd;
}
body.theme-dark .btn-danger:hover{background:#c0392b;color:#fff}
/* a grey count badge sitting inside the gold active tab needs dark-on-gold */
body.theme-dark .premium-tab-btn.active .badge-gray{background:rgba(21,18,14,.22);color:#15120e;border-color:rgba(21,18,14,.28)}
body.theme-dark .card{background:#1f1a14;border-color:rgba(245,240,232,0.10)}
body.theme-dark .kpi-card{background:#1f1a14;border-color:rgba(245,240,232,0.10)}
body.theme-dark .kpi-card:hover{box-shadow:0 3px 14px rgba(0,0,0,0.45)}
body.theme-dark .kpi-value{color:#efe9dd}
body.theme-dark thead th{background:#191510;color:#a89d8d}
body.theme-dark tbody tr:hover{background:rgba(245,240,232,0.05)}
body.theme-dark .form-control{background:#15120e;color:#efe9dd}
body.theme-dark .form-control:focus{background:#1c1712}
body.theme-dark .btn-ghost{color:#ded6c8}
body.theme-dark .btn-primary{background:var(--gold);color:#15120e}
body.theme-dark .btn-primary:hover{background:var(--gold-l);color:#15120e}
body.theme-dark .badge-gray{background:rgba(245,240,232,0.08);color:#bdb3a3}
body.theme-dark .badge-gold{background:rgba(200,149,42,0.16);color:#e8b84b;border-color:rgba(200,149,42,0.42)}
body.theme-dark .badge-green{background:rgba(61,107,53,0.22);color:#93d189;border-color:rgba(61,107,53,0.5)}
body.theme-dark .badge-red{background:rgba(192,57,43,0.18);color:#f0a79d;border-color:rgba(192,57,43,0.5)}
body.theme-dark .badge-blue{background:rgba(26,107,154,0.20);color:#8ec7ec;border-color:rgba(26,107,154,0.5)}
body.theme-dark .alert-success{background:rgba(61,107,53,0.18);color:#93d189}
body.theme-dark .alert-danger{background:rgba(192,57,43,0.16);color:#f0a79d}
body.theme-dark .alert-info{background:rgba(26,107,154,0.18);color:#8ec7ec}
body.theme-dark .alert-gold{background:rgba(200,149,42,0.14);color:#e8b84b}
body.theme-dark .btn-danger{background:rgba(192,57,43,0.18);color:#f0a79d;border-color:rgba(192,57,43,0.5)}
body.theme-dark .text-muted{color:#a89d8d}
html,body{height:100%}
body{font-family:'DM Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:var(--cream);color:var(--ink);font-size:14px;line-height:1.5}
/* TOPBAR */
.topbar{position:fixed;top:0;left:0;right:0;height:var(--top-h);background:var(--ink);display:flex;align-items:center;padding:0 1.25rem;gap:1rem;z-index:100;border-bottom:2px solid var(--gold)}
.topbar-logo{font-family:'Bebas Neue',sans-serif;font-size:1.35rem;color:var(--cream);letter-spacing:0.12em;display:flex;align-items:center;gap:7px;text-decoration:none;flex-shrink:0}
.topbar-logo .dot{width:7px;height:7px;background:var(--gold);border-radius:50%;display:inline-block}
.topbar-season{background:rgba(200,149,42,0.15);border:1px solid rgba(200,149,42,0.3);color:var(--gold-l);font-size:0.68rem;letter-spacing:0.12em;text-transform:uppercase;padding:3px 10px;border-radius:2px}
.topbar-spacer{flex:1}
.topbar-floor{font-size:0.72rem;color:rgba(245,240,232,0.45);letter-spacing:0.05em}
.topbar-user{display:flex;align-items:center;gap:8px;color:var(--cream);font-size:0.8rem}
.topbar-avatar{width:26px;height:26px;background:var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.62rem;font-weight:500;color:var(--ink);text-transform:uppercase;flex-shrink:0}
.topbar-logout{color:rgba(245,240,232,0.4);text-decoration:none;font-size:0.72rem;padding:3px 8px;border:1px solid rgba(245,240,232,0.1);border-radius:2px;transition:all 0.15s}
.topbar-logout:hover{color:var(--cream);border-color:rgba(245,240,232,0.3)}
/* SIDEBAR */
.sidebar{position:fixed;top:var(--top-h);left:0;bottom:0;width:var(--sidebar-w);background:var(--ink-soft);overflow-y:auto;z-index:90;border-right:1px solid rgba(200,149,42,0.12);transition:width .22s ease}
/* Collapsed sidebar */
body.sb-collapsed .sidebar{width:48px;overflow:visible}
body.sb-collapsed .sidebar::-webkit-scrollbar{width:0}
body.sb-collapsed .nav-toggle-label,
body.sb-collapsed .nav-toggle-arrow,
body.sb-collapsed .nav-item span,
body.sb-collapsed .nav-children{display:none!important}
body.sb-collapsed .nav-item{padding:.52rem 0;justify-content:center;border-left:none}
body.sb-collapsed .nav-item svg{width:18px;height:18px;opacity:.7;margin:0}
body.sb-collapsed .nav-item:hover svg{opacity:1}
body.sb-collapsed .nav-item.active svg{opacity:1}
body.sb-collapsed .nav-item:hover::after{content:attr(data-label);position:absolute;left:54px;background:var(--ink);color:var(--cream);font-size:.72rem;padding:.3rem .65rem;border-radius:6px;white-space:nowrap;z-index:999;pointer-events:none;box-shadow:0 4px 12px rgba(0,0,0,.3)}
body.sb-collapsed .nav-item{position:relative}
body.sb-collapsed .nav-group{border:none}
body.sb-collapsed .main{margin-left:48px;transition:margin-left .22s ease}
body.sb-collapsed .topbar-brand{display:none}
.sidebar::-webkit-scrollbar{width:3px}
.sidebar::-webkit-scrollbar-thumb{background:rgba(200,149,42,0.2);border-radius:2px}
.nav-group{border-bottom:1px solid rgba(200,149,42,0.07)}
.nav-group:last-child{border-bottom:none}
.nav-toggle{display:flex;align-items:center;justify-content:space-between;padding:0.72rem 1.1rem 0.52rem;cursor:pointer;user-select:none;list-style:none;color:rgba(245,240,232,0.28);font-size:0.6rem;font-weight:500;letter-spacing:0.18em;text-transform:uppercase;transition:color 0.13s}
.nav-toggle:hover{color:rgba(245,240,232,0.5)}
.nav-toggle.has-active{color:var(--gold)!important}
.nav-toggle-arrow{width:10px;height:10px;flex-shrink:0;transition:transform 0.18s;opacity:0.5}
.nav-group.open .nav-toggle-arrow{transform:rotate(90deg)}
.nav-children{max-height:0;overflow:hidden;transition:max-height .25s ease;padding-bottom:0}
.nav-group.open .nav-children{max-height:600px;padding-bottom:0.2rem}
.nav-item{display:flex;align-items:center;gap:9px;padding:0.48rem 1.1rem 0.48rem 1.35rem;color:rgba(245,240,232,0.5);text-decoration:none;font-size:0.78rem;transition:all 0.13s;border-left:2px solid transparent}
.nav-item:hover{background:rgba(200,149,42,0.07);color:rgba(245,240,232,0.82);border-left-color:rgba(200,149,42,0.28)}
.nav-item.active{background:rgba(200,149,42,0.12);color:var(--gold-l);border-left-color:var(--gold);font-weight:500}
.nav-item svg{width:14px;height:14px;opacity:0.6;flex-shrink:0}
.nav-item.active svg{opacity:1}
/* MAIN */
.main{position:relative;margin-top:var(--top-h);margin-left:var(--sidebar-w);padding:1.75rem;min-height:calc(100vh - var(--top-h))}
/* PAGE HEADER */
.page-header{margin-bottom:1.75rem;display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.page-header-left h2{font-family:'Bebas Neue',sans-serif;font-size:1.75rem;letter-spacing:0.08em;line-height:1}
.page-header-left p{font-size:0.8rem;color:var(--muted);margin-top:0.25rem}
/* CARDS */
.card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.card-head{padding:0.85rem 1.15rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:0.75rem}
.card-head h3{font-size:0.72rem;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted)}
.card-body{padding:1.15rem}
/* TABS — shared segmented/capsule tab strip (see includes/flowork.js) */
.premium-tabs-nav{display:inline-flex;padding:4px;background:var(--cream-d);border-radius:8px;margin-bottom:1.5rem;gap:2px;border:1px solid var(--border);max-width:100%;overflow-x:auto;scrollbar-width:none}
.premium-tabs-nav::-webkit-scrollbar{display:none}
body.theme-dark .premium-tabs-nav{background:rgba(26,18,8,0.3)}
.premium-tab-btn{padding:0.6rem 1.2rem;background:transparent;border:none;border-radius:6px;font-family:inherit;font-size:0.82rem;font-weight:500;color:var(--muted);cursor:pointer;transition:all 0.2s cubic-bezier(0.4,0,0.2,1);display:flex;align-items:center;gap:0.5rem;user-select:none;white-space:nowrap;flex-shrink:0}
.premium-tab-btn:hover{color:var(--ink);background:rgba(26,18,8,0.04)}
body.theme-dark .premium-tab-btn:hover{color:var(--cream);background:rgba(255,255,255,0.04)}
.premium-tab-btn.active{background:var(--ink);color:var(--gold-l);box-shadow:0 4px 12px rgba(26,18,8,0.15);font-weight:600}
body.theme-light .premium-tab-btn.active{background:var(--ink);color:var(--gold-l)}
body.theme-dark .premium-tab-btn.active{background:var(--gold);color:var(--ink);box-shadow:0 4px 12px rgba(200,149,42,0.2)}
/* Inactive panels are parked off-screen rather than display:none so they keep a
   real width — a chart built inside a zero-width panel gets stuck at 0x0. */
.tab-panel{position:absolute;left:-99999px;top:0;width:100%;visibility:hidden;pointer-events:none}
.tab-panel.active{position:static;width:auto;visibility:visible;pointer-events:auto;animation:tabFadeIn 0.25s ease-in-out}
@keyframes tabFadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
/* KPI */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:0.9rem;margin-bottom:1.75rem}
.kpi-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:1.1rem;position:relative;overflow:hidden;transition:box-shadow 0.13s}
.kpi-card:hover{box-shadow:0 3px 12px rgba(0,0,0,0.05)}
.kpi-card::before{content:'';position:absolute;top:0;left:0;width:3px;height:100%;background:var(--kpi-color,var(--gold))}
.kpi-label{font-size:0.65rem;font-weight:500;letter-spacing:0.12em;text-transform:uppercase;color:var(--muted);margin-bottom:0.5rem}
.kpi-value{font-family:'Bebas Neue',sans-serif;font-size:2.1rem;letter-spacing:0.04em;color:var(--ink);line-height:1}
.kpi-value.sm{font-size:1.5rem}
.kpi-sub{font-size:0.7rem;color:var(--muted);margin-top:0.3rem}
/* TABLES */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:0.8rem}
thead th{font-size:0.62rem;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted);padding:0.6rem 0.9rem;text-align:left;border-bottom:1px solid var(--border);background:var(--cream);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background 0.1s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:#faf8f5}
tbody td{padding:0.6rem 0.9rem;vertical-align:middle}
.td-muted{color:var(--muted)}
.td-mono{font-variant-numeric:tabular-nums}
/* BADGES */
.badge{display:inline-flex;align-items:center;padding:2px 7px;border-radius:2px;font-size:0.65rem;font-weight:500;letter-spacing:0.06em;text-transform:uppercase;white-space:nowrap}
.badge-gold{background:var(--gold-bg);color:#92650e;border:1px solid #e8c96a}
.badge-green{background:var(--leaf-l);color:#2a5223;border:1px solid #9bcb94}
.badge-red{background:var(--danger-bg);color:var(--danger);border:1px solid #f5b8b2}
.badge-gray{background:var(--cream);color:var(--muted);border:1px solid var(--border)}
.badge-blue{background:var(--info-bg);color:var(--info);border:1px solid #aacde6}
/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:5px;padding:0.48rem 0.9rem;border-radius:var(--radius);font-family:'DM Sans',sans-serif;font-size:0.78rem;font-weight:500;cursor:pointer;transition:all 0.13s;border:none;text-decoration:none;white-space:nowrap}
.btn-primary{background:var(--ink);color:var(--gold-l)}
.btn-primary:hover{background:var(--gold);color:var(--ink)}
.btn-gold{background:var(--gold);color:var(--ink)}
.btn-gold:hover{background:var(--gold-l)}
.btn-ghost{background:transparent;color:var(--ink);border:1px solid var(--border)}
.btn-ghost:hover{border-color:var(--gold);color:var(--gold)}
.btn-danger{background:var(--danger-bg);color:var(--danger);border:1px solid #f5b8b2}
.btn-danger:hover{background:var(--danger);color:#fff}
.btn-sm{padding:0.28rem 0.65rem;font-size:0.72rem}
/* FORMS */
.form-row{display:grid;gap:0.9rem;margin-bottom:0.9rem}
.form-row.cols-2{grid-template-columns:1fr 1fr}
.form-row.cols-3{grid-template-columns:1fr 1fr 1fr}
.form-group{margin-bottom:0.9rem}
.form-group label{display:block;font-size:0.65rem;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted);margin-bottom:0.35rem}
.form-control{width:100%;padding:0.52rem 0.8rem;border:1.5px solid var(--border);border-radius:var(--radius);background:var(--cream);color:var(--ink);font-family:'DM Sans',sans-serif;font-size:0.82rem;outline:none;transition:border-color 0.13s,background 0.13s;-webkit-appearance:none}
.form-control:focus{border-color:var(--gold);background:#fff}
select.form-control{cursor:pointer}
/* ALERTS */
.alert{padding:0.7rem 0.95rem;border-radius:var(--radius);font-size:0.8rem;margin-bottom:0.9rem;border-left:3px solid transparent}
.alert-success{background:var(--leaf-l);border-color:var(--leaf);color:#2a5223}
.alert-danger{background:var(--danger-bg);border-color:var(--danger);color:var(--danger)}
.alert-info{background:var(--info-bg);border-color:var(--info);color:var(--info)}
.alert-gold{background:var(--gold-bg);border-color:var(--gold);color:#7a5010}
/* UTILS */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1.35rem}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.35rem}
.mt-1{margin-top:0.5rem}.mt-2{margin-top:1rem}.mt-3{margin-top:1.5rem}
.mb-2{margin-bottom:1rem}.mb-3{margin-bottom:1.5rem}
.text-right{text-align:right}.text-center{text-align:center}
.text-muted{color:var(--muted)}.text-gold{color:var(--gold)}.text-leaf{color:var(--leaf)}.text-danger{color:var(--danger)}
.fw-500{font-weight:500}.flex{display:flex}.flex-center{display:flex;align-items:center}
.flex-between{display:flex;align-items:center;justify-content:space-between}
.gap-2{gap:0.75rem}
/* ── RESPONSIVE ────────────────────────────────────────────────────────── */
/* Tablet: 600–900px */
@media(max-width:900px){
  :root{--sidebar-w:200px;--top-h:52px}
  #sb-desktop-toggle{display:none!important}
  #sb-mobile-toggle{display:flex!important}
  .ham-btn{display:flex!important}
  .sidebar{display:none;z-index:200}
  .sidebar.open{display:block}
  .main{margin-left:0;padding:1.25rem}
  .kpi-grid{grid-template-columns:repeat(2,1fr)}
  .grid-2,.grid-3{grid-template-columns:1fr}
  .form-row.cols-3{grid-template-columns:1fr 1fr}
  .page-header{flex-direction:column;align-items:flex-start;gap:0.75rem}
  .topbar-user span{display:none}
  .topbar-season{display:none}
}
/* Mobile: ≤600px */
@media(max-width:600px){
  :root{--top-h:50px}
  .main{padding:0.85rem}
  .kpi-grid{grid-template-columns:1fr 1fr}
  .kpi-value{font-size:1.65rem}
  .form-row.cols-2,.form-row.cols-3{grid-template-columns:1fr}
  thead th{padding:0.45rem 0.6rem;font-size:0.58rem}
  tbody td{padding:0.45rem 0.6rem;font-size:0.75rem}
  .btn{padding:0.5rem 0.75rem;font-size:0.75rem}
  .card-head{flex-wrap:wrap;gap:0.5rem}
  .card-head h3{font-size:0.68rem}
  .premium-tabs-nav{display:flex;width:100%;-webkit-overflow-scrolling:touch}
  .premium-tab-btn{padding:0.5rem 0.75rem;font-size:0.75rem}
  .table-wrap{-webkit-overflow-scrolling:touch}
  .topbar{padding:0 0.85rem;gap:0.5rem}
  .topbar-floor{display:none}
  .ham-btn{display:flex!important}
  .sidebar-close{display:block!important}
  .sidebar{position:fixed;top:0;bottom:0;padding-top:48px}
  .topbar-logout{padding:2px 6px;font-size:0.65rem}
}
/* Tiny: ≤400px */
@media(max-width:400px){
  .kpi-grid{grid-template-columns:1fr}
  .kpi-card{padding:0.85rem}
}

/* ═══════════════════════════════════════════════════════
   FLOWORK UX — Progress overlay, Toasts, Spinner
   ═══════════════════════════════════════════════════════ */

/* ── Keyframes ───────────────────────────────────────────── */
@keyframes fw-spin  { to { transform: rotate(360deg) } }
@keyframes fw-ring  { to { transform: rotate(360deg) } }
@keyframes fw-dot   { 0%,80%,100%{transform:scale(.45);opacity:.25} 40%{transform:scale(1);opacity:1} }
@keyframes fw-pop   { 0%{transform:scale(0)} 65%{transform:scale(1.18)} 100%{transform:scale(1)} }
@keyframes fw-check { 0%{stroke-dashoffset:30} 100%{stroke-dashoffset:0} }
@keyframes fw-slide { from{transform:translateX(110%);opacity:0} to{transform:translateX(0);opacity:1} }

/* ── Top progress bar ────────────────────────────────────── */
#fw-bar {
  position:fixed; top:0; left:0; height:3px; width:0%;
  z-index:99999; pointer-events:none; opacity:0;
  background:linear-gradient(90deg,#c8952a,#e8b84b,#3d6b35);
  border-radius:0 3px 3px 0;
}

/* ── Full-screen overlay ─────────────────────────────────── */
#fw-overlay {
  position:fixed; inset:0; z-index:99990;
  display:flex; align-items:center; justify-content:center;
  background:rgba(0,0,0,0); opacity:0; pointer-events:none;
  transition:background .22s ease, opacity .22s ease;
}
#fw-overlay.fw-on {
  background:rgba(10,7,3,.58); opacity:1; pointer-events:all;
}

/* ── Spinner card ────────────────────────────────────────── */
#fw-overlay .fw-card {
  background:#1c130a;
  border:1px solid rgba(200,149,42,.35);
  border-radius:14px;
  padding:32px 44px;
  display:flex; flex-direction:column; align-items:center; gap:16px;
  box-shadow:0 20px 60px rgba(0,0,0,.65);
  transform:scale(.8) translateY(20px);
  transition:transform .28s cubic-bezier(.34,1.56,.64,1);
}
#fw-overlay.fw-on .fw-card { transform:scale(1) translateY(0); }

/* ── Ring ────────────────────────────────────────────────── */
.fw-card .fw-ring-svg {
  width:58px; height:58px;
  animation:fw-ring .72s linear infinite;
  display:block;
}
.fw-ring-bg  { stroke:rgba(200,149,42,.15); fill:none; stroke-width:4; }
.fw-ring-arc { stroke:#c8952a; fill:none; stroke-width:4;
               stroke-linecap:round; stroke-dasharray:92 40; }

/* ── Bouncing dots ───────────────────────────────────────── */
.fw-card .fw-dots { display:flex; gap:8px; }
.fw-card .fw-dots b {
  display:block; width:8px; height:8px; border-radius:50%;
  background:#c8952a; animation:fw-dot 1.05s ease infinite;
}
.fw-card .fw-dots b:nth-child(2) { animation-delay:.17s; }
.fw-card .fw-dots b:nth-child(3) { animation-delay:.34s; }

/* ── Label ───────────────────────────────────────────────── */
.fw-card .fw-lbl {
  color:#e8b84b; font-size:13px; font-weight:500;
  font-family:'DM Sans',sans-serif; letter-spacing:.05em;
}

/* ── Toast wrapper ───────────────────────────────────────── */
#fw-toasts {
  position:fixed; bottom:20px; right:20px; z-index:99995;
  display:flex; flex-direction:column; gap:10px;
  align-items:flex-end; pointer-events:none;
}

/* ── Toast base ──────────────────────────────────────────── */
.fw-toast {
  display:flex; align-items:flex-start; gap:12px;
  min-width:290px; max-width:420px;
  padding:14px 16px; border-radius:10px;
  font-family:'DM Sans',sans-serif; font-size:13px;
  pointer-events:all; cursor:default;
  box-shadow:0 8px 30px rgba(0,0,0,.35), 0 2px 8px rgba(0,0,0,.18);
  border-left:5px solid transparent;
  animation:fw-slide .32s cubic-bezier(.4,0,.2,1) both;
}

/* light theme */
body.theme-light .fw-toast-success { background:#edf7ed; border-color:#3d6b35; color:#1a3318; }
body.theme-light .fw-toast-error   { background:#fdf2f2; border-color:#c03a2b; color:#4a1010; }
body.theme-light .fw-toast-warning { background:#fef9ec; border-color:#c8952a; color:#4a3000; }
body.theme-light .fw-toast-info    { background:#edf4fb; border-color:#1a6b9a; color:#082040; }

/* dark theme */
body.theme-dark  .fw-toast-success { background:#122012; border-color:#4caf50; color:#c0f0c0; }
body.theme-dark  .fw-toast-error   { background:#220e0e; border-color:#e53935; color:#f0c0c0; }
body.theme-dark  .fw-toast-warning { background:#1e1800; border-color:#e8b84b; color:#f0dfa0; }
body.theme-dark  .fw-toast-info    { background:#0a1828; border-color:#42a5f5; color:#c0dcf5; }

/* ── Toast icon circle ───────────────────────────────────── */
.fw-toast .fw-ico {
  width:26px; height:26px; border-radius:50%; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
  margin-top:1px; animation:fw-pop .35s cubic-bezier(.4,0,.2,1) both;
}
body.theme-light .fw-toast-success .fw-ico { background:#3d6b35; }
body.theme-light .fw-toast-error   .fw-ico { background:#c03a2b; }
body.theme-light .fw-toast-warning .fw-ico { background:#c8952a; }
body.theme-light .fw-toast-info    .fw-ico { background:#1a6b9a; }
body.theme-dark  .fw-toast-success .fw-ico { background:#388e3c; }
body.theme-dark  .fw-toast-error   .fw-ico { background:#d32f2f; }
body.theme-dark  .fw-toast-warning .fw-ico { background:#b8860b; }
body.theme-dark  .fw-toast-info    .fw-ico { background:#1565c0; }

.fw-toast .fw-ico svg { width:14px; height:14px; stroke:#fff; stroke-width:2.8;
  fill:none; stroke-linecap:round; stroke-linejoin:round; overflow:visible; }
.fw-check-path { stroke-dasharray:30; stroke-dashoffset:30;
  animation:fw-check .38s ease .1s forwards; }

/* ── Toast text ──────────────────────────────────────────── */
.fw-toast .fw-t-body { flex:1; min-width:0; }
.fw-toast .fw-t-type { font-size:10.5px; font-weight:700; letter-spacing:.08em;
  text-transform:uppercase; opacity:.65; margin-bottom:3px; }
.fw-toast .fw-t-msg  { font-size:13px; line-height:1.5; word-break:break-word; }

/* ── Toast close btn ─────────────────────────────────────── */
.fw-toast .fw-t-x {
  background:none; border:none; cursor:pointer; font-size:15px;
  opacity:.35; padding:0; line-height:1; flex-shrink:0;
  color:inherit; transition:opacity .15s; margin-top:-1px;
}
.fw-toast .fw-t-x:hover { opacity:.85; }

/* ── Spinner (button) ────────────────────────────────────── */
.fw-spinner {
  display:inline-block; width:13px; height:13px;
  border:2px solid rgba(255,255,255,.3); border-top-color:#fff;
  border-radius:50%; animation:fw-spin .65s linear infinite;
  vertical-align:middle;
}

</style>
<script>
// Polyfill to handle DOMContentLoaded and load listeners during dynamic page swaps
(function() {
    var originalAddEventListener = window.addEventListener;
    window.addEventListener = function(type, listener, options) {
        if (type === 'DOMContentLoaded' || type === 'load') {
            if (document.readyState === 'interactive' || document.readyState === 'complete') {
                setTimeout(listener, 1);
                return;
            }
        }
        return originalAddEventListener.apply(window, arguments);
    };
})();

function setTheme(t){
    if (t === 'toggle') {
        t = document.body.classList.contains('theme-dark') ? 'light' : 'dark';
    }
    document.body.classList.remove('theme-dark', 'theme-light');
    document.body.classList.add('theme-' + t);
    document.cookie='flowork_theme='+t+';path=/;max-age=31536000';
    var btn = document.getElementById('theme-toggle-btn');
    if (btn) {
        btn.innerHTML = (t === 'dark') ? '☀ Light' : '☾ Dark';
    }
    if(window.fw) fw.toast('Switched to '+(t==='dark'?'Dark':'Light')+' mode','info');
}
function toggleSidebarDesktop(){
  var collapsed = document.body.classList.toggle('sb-collapsed');
  try{ localStorage.setItem('sb_collapsed', collapsed ? '1' : '0'); }catch(e){}
}
function toggleSidebar(){
  var sb=document.getElementById('sidebar');
  var ov=document.getElementById('nav-overlay');
  var open=sb.classList.toggle('open');
  ov.style.display=open?'block':'none';
  document.body.style.overflow=open?'hidden':'';
}
function toggleNavGroup(el){
  var grp=el.closest('.nav-group');
  var willOpen=!grp.classList.contains('open');
  // Accordion: close all groups
  document.querySelectorAll('.nav-group.open').forEach(function(g){
    g.classList.remove('open');
  });
  // Open the clicked group if it was closed, and remember it
  if(willOpen){
    grp.classList.add('open');
    try{localStorage.setItem('nav_open_group', grp.dataset.group);}catch(e){}
  } else {
    try{localStorage.removeItem('nav_open_group');}catch(e){}
  }
}
document.addEventListener('DOMContentLoaded',function(){
  // Restore sidebar collapsed state
  try{
    if(localStorage.getItem('sb_collapsed')==='1'){
      document.body.classList.add('sb-collapsed');
    }
  }catch(e){}
  // Add data-label tooltips to nav items for collapsed mode
  document.querySelectorAll('.nav-item').forEach(function(a){
    var span = a.querySelector('span');
    if(span) a.setAttribute('data-label', span.textContent.trim());
  });
  // Determine which group to open:
  // 1) Group with the active page always wins
  // 2) Otherwise restore the last-opened group from localStorage
  var activeGroup=null;
  var savedGroup=null;
  try{savedGroup=localStorage.getItem('nav_open_group');}catch(e){}
  document.querySelectorAll('.nav-group').forEach(function(g){
    g.classList.remove('open');
    if(g.querySelector('.nav-item.active')){
      activeGroup=g;
    }
  });
  var groupToOpen=activeGroup;
  if(!groupToOpen && savedGroup){
    groupToOpen=document.querySelector('.nav-group[data-group="'+savedGroup+'"]');
  }
  if(groupToOpen) groupToOpen.classList.add('open');
  // On nav-item click, save which group it belongs to so it stays open after navigation
  document.querySelectorAll('.nav-item').forEach(function(a){
    a.addEventListener('click',function(){
      var parentGroup=this.closest('.nav-group');
      if(parentGroup){
        try{localStorage.setItem('nav_open_group', parentGroup.dataset.group);}catch(e){}
      }
      if(window.innerWidth<=900){
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('nav-overlay').style.display='none';
        document.body.style.overflow='';
      }
    });
  });
});
</script>
<?php $pay_page = str_starts_with($active_page ?? '', 'pay') || ($active_page ?? '') === 'payments'; ?>
<?php if($pay_page): ?>
<script src="<?= APP_BASE ?>/includes/pay_ui.js?v=4"></script>
<?php endif; ?>
<script>window.APP_BASE = '<?= APP_BASE ?>';</script>
<script src="<?= APP_BASE ?>/includes/flowork.js?v=8"></script>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= APP_BASE ?>/sw.js').catch(() => {});
}
</script>
</head>
<body class="theme-<?= $theme ?> page-<?= htmlspecialchars($active_page ?? '') ?>">
<!-- TOPBAR -->
<div class="topbar">
  <!-- Desktop: collapse/expand sidebar -->
  <button id="sb-desktop-toggle" onclick="toggleSidebarDesktop()" aria-label="Toggle sidebar"
          style="background:none;border:none;cursor:pointer;padding:4px;color:var(--cream);flex-shrink:0;display:flex;align-items:center;justify-content:center">
    <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <line x1="2" y1="5" x2="20" y2="5"/><line x1="2" y1="11" x2="20" y2="11"/><line x1="2" y1="17" x2="20" y2="17"/>
    </svg>
  </button>
  <!-- Mobile: open drawer -->
  <button class="ham-btn" id="sb-mobile-toggle" onclick="toggleSidebar()" aria-label="Menu"
          style="display:none;background:none;border:none;cursor:pointer;padding:4px;color:var(--cream);flex-shrink:0">
    <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <line x1="2" y1="5" x2="20" y2="5"/><line x1="2" y1="11" x2="20" y2="11"/><line x1="2" y1="17" x2="20" y2="17"/>
    </svg>
  </button>
  <a href="dashboard.php" class="topbar-logo"><span class="dot"></span> Flowork</a>
  <?php if (!empty($_SESSION['season_name'])): ?>
  <span class="topbar-season"><?= htmlspecialchars($_SESSION['season_name']) ?></span>
  <?php endif; ?>
  <div class="topbar-spacer"></div>
  <?php if (is_master_user()): ?>
  <!-- Master User Indicator Badge -->
  <div style="display:inline-flex; align-items:center; gap:5px; background:linear-gradient(135deg, rgba(200,149,42,0.25), rgba(241,196,15,0.15)); border:1px solid var(--gold); padding:3px 10px; border-radius:12px; font-size:0.7rem; font-weight:700; color:var(--gold-l); letter-spacing:0.04em; margin-right:6px" title="Logged in as Master User — Unrestricted Multi-Floor View">
    <span style="font-size:0.85rem">👑</span> MASTER USER
  </div>
  <?php elseif (is_dedicated_user()): ?>
  <!-- Dedicated User Floor Pill -->
  <div style="display:inline-flex; align-items:center; gap:5px; background:rgba(255,255,255,0.08); border:1px solid rgba(245,240,232,0.15); padding:3px 10px; border-radius:12px; font-size:0.7rem; color:var(--cream); margin-right:6px" title="Dedicated to this auction floor">
    <span style="font-size:0.75rem">🔒</span> Floor: <?= htmlspecialchars($_SESSION['selling_point_name'] ?? '') ?>
  </div>
  <?php endif; ?>

  <!-- Floor switcher -->
  <?php
  $accessible = $_SESSION['accessible_floors'] ?? [];
  $cur_spid   = (int)($_SESSION['selling_pointid'] ?? 0);
  if (count($accessible) > 1 && is_master_user()):
  ?>
  <form method="POST" action="switch_floor.php" style="display:flex;align-items:center;gap:6px">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <span style="font-size:0.65rem;color:rgba(245,240,232,0.35);letter-spacing:0.1em;text-transform:uppercase">Floor</span>
    <select name="selling_pointid" onchange="this.form.submit()"
            style="background:rgba(200,149,42,0.12);border:1px solid rgba(200,149,42,0.3);color:var(--gold-l);
                   font-size:0.72rem;padding:3px 6px;border-radius:2px;cursor:pointer;font-family:inherit;outline:none">
      <?php foreach ($accessible as $fl): ?>
      <option value="<?= $fl['id'] ?>" <?= $fl['id']==$cur_spid?'selected':'' ?>>
        <?= htmlspecialchars($fl['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php else: ?>
  <span class="topbar-floor"><?= htmlspecialchars($_SESSION['selling_point_name'] ?? '') ?></span>
  <?php endif; ?>
  <div class="topbar-user">
    <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['username'] ?? 'U', 0, 2)) ?></div>
    <?= htmlspecialchars($_SESSION['username'] ?? '') ?>
  </div>
  <!-- PWA Connection Status Pill -->
  <div id="pwa-status-pill" style="display:inline-flex; align-items:center; gap:6px; font-size:0.7rem; font-weight:500; background:rgba(61,107,53,0.12); border:1px solid rgba(61,107,53,0.25); color:var(--leaf); padding:3px 8px; border-radius:12px; margin-right:4px; letter-spacing:0.04em">
    <span style="width:6px; height:6px; background:currentColor; border-radius:50%; display:inline-block"></span>
    <span id="pwa-status-text">Online</span>
  </div>
  
  <!-- Offline Sync Queue Badge -->
  <div id="pwa-sync-queue-badge" style="display:none; align-items:center; gap:6px; font-size:0.7rem; font-weight:600; background:rgba(200,149,42,0.12); border:1px solid rgba(200,149,42,0.25); color:var(--gold-l); padding:3px 8px; border-radius:12px; margin-right:4px; letter-spacing:0.04em; cursor:pointer" onclick="triggerManualSync()" title="Click to force auto-sync">
    <span style="display:inline-flex;align-items:center;gap:3px">
      <svg width="10" height="10" viewBox="0 0 16 16" fill="currentColor" style="display:inline-block;width:10px;height:10px;flex-shrink:0;vertical-align:middle"><polygon points="9 1 2 9 7 9 6 15 14 6 8 6"/></svg> Queue:
    </span>
    <span id="pwa-sync-queue-count">0</span>
  </div>

  <!-- Command Palette Button -->
  <button type="button" onclick="toggleCommandPalette()" title="Open search/palette (Ctrl+K)"
          style="background:rgba(200,149,42,0.12);border:1px solid rgba(200,149,42,0.25);
                 color:var(--gold-l);font-size:0.72rem;padding:3px 8px;border-radius:2px;
                 cursor:pointer;font-family:inherit;letter-spacing:0.06em;display:inline-flex;align-items:center;gap:4px;margin-right:4px">
    <svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline-block;width:11px;height:11px;flex-shrink:0;vertical-align:middle"><circle cx="7" cy="7" r="5.5"/><line x1="11" y1="11" x2="15" y2="15"/></svg> Search <kbd style="font-size:0.55rem;background:rgba(255,255,255,0.15);padding:1px 3px;border-radius:2px;border:1px solid rgba(255,255,255,0.2)">Ctrl+K</kbd>
  </button>

  <button type="button" id="theme-toggle-btn" onclick="setTheme('toggle')" title="Toggle light/dark"
          style="background:rgba(200,149,42,0.12);border:1px solid rgba(200,149,42,0.25);
                 color:var(--gold-l);font-size:0.72rem;padding:3px 8px;border-radius:2px;
                 cursor:pointer;font-family:inherit;letter-spacing:0.06em;display:inline-flex;align-items:center;gap:4px">
    <?= $theme==='dark' ? '☀ Light' : '☾ Dark' ?>
  </button>
  <!-- SSE Real-time Notification Bell -->
  <div style="position:relative; display:inline-flex; align-items:center; cursor:pointer; margin-right:4px; padding:3px 8px; border:1px solid rgba(245,240,232,0.1); border-radius:2px" title="Real-time Notifications" onclick="resetBellCount()">
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline-block;width:14px;height:14px;flex-shrink:0;vertical-align:middle;color:var(--gold-l)"><path d="M8 1a3.5 3.5 0 0 0-3.5 3.5V8L3 11.5h10L11.5 8V4.5A3.5 3.5 0 0 0 8 1zM5.5 13a2.5 2.5 0 0 0 5 0h-5z"/></svg>
    <span id="navbar-bell-badge" style="display:none; background:var(--gold); color:var(--ink); font-size:.65rem; font-weight:700; padding:1px 5px; border-radius:10px; align-items:center; justify-content:center; line-height:1; margin-left:3px">0</span>
  </div>
  <script>
  function resetBellCount() {
    var badge = document.getElementById('navbar-bell-badge');
    if (badge) {
      badge.textContent = '0';
      badge.style.display = 'none';
    }
  }
  </script>
  <a href="logout.php" class="topbar-logout">Sign out</a>
</div>
<!-- SIDEBAR -->
<div id="nav-overlay" onclick="toggleSidebar()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:195"></div>
<div class="sidebar" id="sidebar">
  <button onclick="toggleSidebar()"
          style="display:none;position:absolute;top:10px;right:10px;background:none;border:none;
                 cursor:pointer;color:rgba(245,240,232,0.5);padding:4px;line-height:1"
          class="sidebar-close" aria-label="Close">
    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <line x1="3" y1="3" x2="15" y2="15"/><line x1="15" y1="3" x2="3" y2="15"/>
    </svg>
  </button>
  <?php $adm = (function_exists('is_admin') && is_admin()) || (int)($_SESSION['is_admin'] ?? 0) === 1; ?>

  <div class="nav-group" data-group="overview">
    <div class="nav-toggle <?= $active_page==='dashboard' ? 'has-active' : '' ?>"
         onclick="toggleNavGroup(this)">
      Overview
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <a href="management_dashboard.php" class="nav-item <?= $active_page==='management_dashboard'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/></svg>
    Management
  </a>
  <a href="dashboard.php" class="nav-item <?= $active_page==='dashboard'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/></svg>
    Dashboard
  </a>
    </div>
  </div>

  <?php $grp_receiving = in_array($active_page,['transporters','scale','junus','tickets','bale_adjustments','bales']); ?>
  <div class="nav-group" data-group="receiving">
    <div class="nav-toggle <?= $grp_receiving ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Receiving
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <a href="transporters.php" class="nav-item <?= $active_page==='transporters'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="5" width="10" height="8" rx="1"/><path d="M11 8l3 2v3h-3V8z"/><circle cx="4" cy="13" r="1.5"/><circle cx="12" cy="13" r="1.5"/></svg>
    Transporters
  </a>
  <a href="scale.php" class="nav-item <?= $active_page==='scale'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 5h10M8 1v2"/><ellipse cx="8" cy="5" rx="5" ry="2"/><path d="M4 5l-1 8h10L12 5"/></svg>
    Scale / Weigh
  </a>
  <a href="junus.php" class="nav-item <?= $active_page==='junus'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M2 8h8M2 12h5"/><circle cx="12" cy="11" r="2.5"/><path d="M11 11l1 1 1.5-1.5"/></svg>
    Bale Junus
  </a>
  <a href="tickets.php" class="nav-item <?= $active_page==='tickets'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="12" height="12" rx="1"/><path d="M5 6h6M5 9h4"/></svg>
    Ticket Printing
  </a>
  <a href="bale_adjustments.php" class="nav-item <?= $active_page==='bale_adjustments'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M11 2l3 3-8 8H3v-3L11 2z"/></svg>
    Bale Adjustments
  </a>
  <a href="bales.php" class="nav-item <?= $active_page==='bales'?'active':'' ?>" data-label="Bales">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="3" width="14" height="4" rx="1"/><rect x="1" y="9" width="14" height="4" rx="1"/><path d="M4 3v4M4 9v4M12 3v4M12 9v4"/></svg>
    Bales
  </a>
    </div>
  </div>

  <?php $grp_growers = in_array($active_page,['grower_registry','grower_booking','grower_import','season_rollover']); ?>
  <div class="nav-group" data-group="growers">
    <div class="nav-toggle <?= $grp_growers ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Grower Management
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <a href="grower_registry.php" class="nav-item <?= $active_page==='grower_registry'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5" r="3"/><path d="M2 14c0-3.3 2.7-6 6-6s6 2.7 6 6"/></svg>
    Grower Registry
  </a>
  <a href="grower_booking.php" class="nav-item <?= $active_page==='grower_booking'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="12" height="12" rx="1"/><path d="M5 8h6M8 5v6"/></svg>
    Grower Booking
  </a>
  <a href="grower_import.php" class="nav-item <?= $active_page==='grower_import'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 11v3h12v-3"/><path d="M8 2v9M5 8l3 3 3-3"/></svg>
    Bulk Import
  </a>
  <a href="season_rollover.php" class="nav-item <?= $active_page==='season_rollover'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8a5 5 0 1 1 10 0"/><path d="M13 8l-2-2 2-2"/></svg>
    Season Rollover
  </a>
    </div>
  </div>

  <?php $grp_floor = in_array($active_page,['floor_summary','bale_check','move_bales','locations','live_floor','floor_map','mobile_ops','scale_sync','floor_queue','floor_tv']); ?>
  <div class="nav-group" data-group="floor">
    <div class="nav-toggle <?= $grp_floor ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Floor
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <a href="mobile_ops.php" class="nav-item <?= $active_page==='mobile_ops'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="1" width="8" height="14" rx="1.5"/><circle cx="8" cy="12" r=".8" fill="currentColor" stroke="none"/></svg>
    Mobile Ops
  </a>
  <a href="scale_sync.php" class="nav-item <?= $active_page==='scale_sync'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2v3M4 8h8"/><circle cx="8" cy="10" r="4"/></svg>
    Scale Sync
  </a>
  <a href="floor_map.php" class="nav-item <?= $active_page==='floor_map'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/></svg>
    Floor Map
  </a>
  <a href="live_floor.php" class="nav-item <?= $active_page==='live_floor'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="3"/><circle cx="8" cy="8" r="6.5" stroke-dasharray="2 2"/></svg>
    Live Floor
  </a>
  <a href="floor_tv.php" class="nav-item <?= $active_page==='floor_tv'?'active':'' ?>" target="_blank">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="2" width="14" height="10" rx="1"/><path d="M5 14h6M8 12v2"/><circle cx="13" cy="5" r="1" fill="var(--leaf)" stroke="none"/></svg>
    TV Display <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:10px;height:10px;display:inline-block;vertical-align:middle;margin-left:3px;opacity:0.6"><path d="M9 1h6v6M15 1L8 8"/></svg>
  </a>
  <a href="floor_queue.php" class="nav-item <?= $active_page==='floor_queue'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M2 8h10M2 12h8"/><circle cx="13" cy="12" r="2"/><path d="M12 11v2h2"/></svg>
    Floor Queue
  </a>
  <a href="floor_summary.php" class="nav-item <?= $active_page==='floor_summary'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="1" width="14" height="14" rx="1"/><path d="M1 6h14M1 11h14M6 1v14"/></svg>
    Floor Summary
  </a>
  <a href="bale_check.php" class="nav-item <?= $active_page==='bale_check'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M5 8l2 2 4-4"/></svg>
    Bale Check
  </a>
  <a href="move_bales.php" class="nav-item <?= $active_page==='move_bales'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 8h12M10 4l4 4-4 4"/></svg>
    Move Bales
  </a>
  <a href="locations.php" class="nav-item <?= $active_page==='locations'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1C5.2 1 3 3.2 3 6c0 4 5 9 5 9s5-5 5-9c0-2.8-2.2-5-5-5z"/><circle cx="8" cy="6" r="1.5"/></svg>
    Locations
  </a>
    </div>
  </div>

  <?php 
  $show_sale_processing = has_right('floor') || has_right('accounts') || has_right('balance');
  if ($show_sale_processing):
      $grp_sale = in_array($active_page,['floor','selling','selling2','sales','balance','buyers','void_controls','re_offer','grade_disputes','buyer_management','live_feed','quality_control']); 
  ?>
  <div class="nav-group" data-group="sale_processing">
    <div class="nav-toggle <?= $grp_sale ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Sale Processing
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <?php if (has_right('floor') || has_right('accounts')): ?>
  <a href="selling.php" class="nav-item <?= in_array($active_page,['floor','selling'])?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M8 4v4l3 2"/></svg>
    Selling 1
  </a>
  <?php endif; ?>
  <?php if (has_right('accounts')): ?>
  <a href="selling2.php" class="nav-item <?= $active_page==='selling2'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8h10M9 4l4 4-4 4"/><path d="M3 4v8"/></svg>
    Selling 2
  </a>
  <a href="sales.php" class="nav-item <?= $active_page==='sales'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="10" rx="1.5"/><path d="M5 7h6M5 10h4"/></svg>
    Sales
  </a>
  <?php endif; ?>
  <?php if (has_right('floor')): ?>
  <a href="re_offer.php" class="nav-item <?= $active_page==='re_offer'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 8a6 6 0 1 1 12 0"/><path d="M14 8l-2-2-2 2"/><path d="M5 12h6"/></svg>
    Re-Offer
  </a>
  <a href="grade_disputes.php" class="nav-item <?= $active_page==='grade_disputes'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 3v6M8 11v1"/><circle cx="8" cy="8" r="6.5"/></svg>
    Grade Disputes
  </a>
  <a href="void_controls.php" class="nav-item <?= $active_page==='void_controls'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M5 8l2 2 4-4"/><line x1="11" y1="5" x2="5" y2="11" stroke="var(--danger)"/></svg>
    Void Controls
  </a>
  <?php endif; ?>
  <?php if (has_right('balance')): ?>
  <a href="balance.php" class="nav-item <?= $active_page==='balance'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1L1 5v6l7 4 7-4V5L8 1z"/></svg>
    Sale Balancing
  </a>
  <?php endif; ?>
  <?php if (has_right('floor')): ?>
  <a href="live_feed.php" class="nav-item <?= $active_page==='live_feed'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="2"/><path d="M4.5 4.5a5 5 0 000 7M11.5 4.5a5 5 0 010 7"/><path d="M2 2a9 9 0 000 12M14 2a9 9 0 010 12"/></svg>
    Live Feed
  </a>
  <a href="quality_control.php" class="nav-item <?= $active_page==='quality_control'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8l3 3 7-7"/><rect x="1" y="1" width="14" height="14" rx="2"/></svg>
    Quality Control
  </a>
  <?php endif; ?>
  <?php if (has_right('accounts')): ?>
  <a href="buyer_management.php" class="nav-item <?= $active_page==='buyer_management'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 13c0-3 2.5-5 6-5s6 2 6 5"/><circle cx="8" cy="5" r="3"/><path d="M13 7v4M11 9h4"/></svg>
    Buyer Management
  </a>
  <a href="buyers.php" class="nav-item <?= $active_page==='buyers'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2h4l2 4H4L6 2z"/><path d="M2 6h12v8H2z"/><path d="M6 10h4"/></svg>
    Buyers &amp; Grades
  </a>
  <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (has_right('stoporders')): ?>
  <?php $grp_so = in_array($active_page,['stoporder_processing','stoporder_verification','internal_stoporders','deductions','creditor_portal']); ?>
  <div class="nav-group" data-group="stop_orders">
    <div class="nav-toggle <?= $grp_so ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Stop Orders
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <a href="stoporder_processing.php" class="nav-item <?= $active_page==='stoporder_processing'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13 3H3v10h10V3z"/><path d="M6 7h4M6 10h2"/><path d="M9 1v4M7 1v4"/></svg>
    SO Processing
  </a>
  <a href="stoporder_verification.php" class="nav-item <?= $active_page==='stoporder_verification'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M5 8l2 2 4-4"/></svg>
    SO Verification
  </a>
  <a href="internal_stoporders.php" class="nav-item <?= $active_page==='internal_stoporders'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="12" height="12" rx="1"/><path d="M8 5v6M5 8h6"/></svg>
    Internal SOs
  </a>
  <a href="creditor_portal.php" class="nav-item <?= $active_page==='creditor_portal'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5" r="3"/><path d="M2 14c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M11 8l1.5 1.5-3 3"/></svg>
    Creditors
  </a>
  <a href="deductions.php" class="nav-item <?= $active_page==='deductions'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8h10M3 4h10M3 12h6"/></svg>
    Deductions
  </a>
    </div>
  </div>
  <?php endif; ?>

  <?php 
  $show_accounts = has_right('accounts') || has_right('payments') || has_right('balance') || has_right('growers');
  if ($show_accounts):
      $grp_acc = in_array($active_page,['purchase_summary','capture_internals','banking','payments','day_control','bank_file','exchange_rate','grower_loans','contract_farming','penalties']); 
  ?>
  <div class="nav-group" data-group="accounts">
    <div class="nav-toggle <?= $grp_acc ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Accounts
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <?php if (has_right('accounts')): ?>
  <a href="purchase_summary.php" class="nav-item <?= $active_page==='purchase_summary'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 12l3-4 3 3 4-5"/></svg>
    Purchase Summary
  </a>
  <a href="capture_internals.php" class="nav-item <?= $active_page==='capture_internals'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg>
    Capture Internals
  </a>
  <?php endif; ?>
  <?php if (has_right('growers') || has_right('accounts')): ?>
  <a href="grower_loans.php" class="nav-item <?= $active_page==='grower_loans'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v3l2 2"/><path d="M5 8h6" stroke-dasharray="2 1"/></svg>
    Loans &amp; Advances
  </a>
  <?php endif; ?>
  <?php if (has_right('accounts')): ?>
  <a href="exchange_rate.php" class="nav-item <?= $active_page==='exchange_rate'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 6h10M3 10h10"/><path d="M10 3l3 3-3 3M6 7l-3 3 3 3"/></svg>
    Exchange Rate
  </a>
  <?php endif; ?>
  <?php if (has_right('balance')): ?>
  <a href="day_control.php" class="nav-item <?= $active_page==='day_control'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 7h12"/><path d="M5 3V1M11 3V1"/></svg>
    Day Control
  </a>
  <?php endif; ?>
  <?php if (has_right('payments')): ?>
  <a href="bank_file.php" class="nav-item <?= $active_page==='bank_file'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 5h12v8a1 1 0 01-1 1H3a1 1 0 01-1-1V5z"/><path d="M2 5l2-3h8l2 3"/><path d="M6 9h4M8 7v4"/></svg>
    Bank File
  </a>
  <a href="banking.php" class="nav-item <?= $active_page==='banking'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 6h12M2 10h12M8 2l6 4H2L8 2z"/></svg>
    Banking
  </a>
  <?php /* "Payments" used to sit here too, pointing at the same payments.php as
           Payments → Payment Register. Removed so the page has one home in the nav. */ ?>
  <?php endif; ?>
  <?php if (has_right('accounts')): ?>
  <a href="contract_farming.php" class="nav-item <?= $active_page==='contract_farming'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2h7l3 3v9H3V2z"/><path d="M10 2v3h3"/><path d="M5 8h6M5 11h4"/></svg>
    Contracts
  </a>
  <a href="penalties.php" class="nav-item <?= $active_page==='penalties'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2l6 4v4l-6 4-6-4V6z"/><path d="M8 6v4M8 12v1"/></svg>
    Penalties & Incentives
  </a>
  <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (has_right('accounts')): ?>
  <?php $grp_settle = in_array($active_page,['sales_sheets','eod_reports']); ?>
  <div class="nav-group" data-group="settlement">
    <div class="nav-toggle <?= $grp_settle ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Settlement
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <a href="sales_sheets.php" class="nav-item <?= $active_page==='sales_sheets'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2h10v12H3z"/><path d="M6 6h4M6 9h2"/></svg>
    Sales Sheets
  </a>
  <a href="eod_reports.php" class="nav-item <?= $active_page==='eod_reports'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 12l3-4 3 2 4-6"/><circle cx="14" cy="3" r="1.5"/></svg>
    EOD Reports
  </a>
    </div>
  </div>
  <?php endif; ?>

  <?php if (has_right('dispatch')): ?>
  <?php $grp_log = in_array($active_page,['dispatch','dispatch_export','vehicles']); ?>
  <div class="nav-group" data-group="logistics">
    <div class="nav-toggle <?= $grp_log ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Logistics
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <a href="vehicles.php" class="nav-item <?= $active_page==='vehicles'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="5" width="14" height="8" rx="1"/><path d="M4 13v2M12 13v2"/><path d="M1 9h14"/><circle cx="4" cy="13" r="1.5"/><circle cx="12" cy="13" r="1.5"/></svg>
    Vehicles
  </a>
  <a href="dispatch.php" class="nav-item <?= $active_page==='dispatch'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 11h14M3 11V7l5-5 5 5v4"/><rect x="5" y="11" width="6" height="5"/></svg>
    Dispatch
  </a>
  <a href="dispatch_export.php" class="nav-item <?= $active_page==='dispatch_export'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1v9M5 7l3 3 3-3"/><path d="M2 12v2a1 1 0 001 1h10a1 1 0 001-1v-2"/></svg>
    Dispatch Export
  </a>
    </div>
  </div>
  <?php endif; ?>

  <?php if (has_right('floor')): ?>
  <?php $grp_proc = in_array($active_page,['processing','proc_intake','proc_batches','proc_cartons','proc_shipments']); ?>
  <div class="nav-group" data-group="processing">
    <div class="nav-toggle <?= $grp_proc ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Processing
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <a href="processing.php" class="nav-item <?= $active_page==='processing'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/></svg>
    Dashboard
  </a>
  <a href="proc_intake.php" class="nav-item <?= $active_page==='proc_intake'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1v9M5 7l3 3 3-3"/><path d="M2 12v2a1 1 0 001 1h10a1 1 0 001-1v-2"/></svg>
    Intake
  </a>
  <a href="proc_batches.php" class="nav-item <?= $active_page==='proc_batches'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M8 4v4l3 2"/></svg>
    Batches
  </a>
  <a href="proc_cartons.php" class="nav-item <?= $active_page==='proc_cartons'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 5l7-4 7 4v6l-7 4-7-4V5z"/><path d="M1 5l7 4 7-4M8 9v6"/></svg>
    Export Cartons
  </a>
  <a href="proc_shipments.php" class="nav-item <?= $active_page==='proc_shipments'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 11l1.5-5h11L15 11M1 11h14M1 11l1 3h12l1-3M5 6V3h6v3"/></svg>
    Shipments
  </a>
    </div>
  </div>

  <?php $grp_ph = in_array($active_page,['packhouse','ph_reclassify','ph_pallets','ph_dispatch']); ?>
  <div class="nav-group" data-group="packhouse">
    <div class="nav-toggle <?= $grp_ph ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Packing House
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <a href="packhouse.php" class="nav-item <?= $active_page==='packhouse'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/></svg>
    Dashboard
  </a>
  <a href="ph_reclassify.php" class="nav-item <?= $active_page==='ph_reclassify'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M2 8h12M2 12h8"/></svg>
    Reclassify
  </a>
  <a href="ph_pallets.php" class="nav-item <?= $active_page==='ph_pallets'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 5l7-4 7 4v6l-7 4-7-4V5z"/><path d="M1 5l7 4 7-4M8 9v6"/></svg>
    Pallets
  </a>
  <a href="ph_dispatch.php" class="nav-item <?= $active_page==='ph_dispatch'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 11h14M3 11V7l5-5 5 5v4"/><rect x="5" y="11" width="6" height="5"/></svg>
    Pallet Dispatch
  </a>
    </div>
  </div>
  <?php endif; ?>

  <?php if (has_right('payments')): ?>
  <?php $grp_pay = in_array($active_page,['payments','pay_dashboard','pay_batches','pay_audit','pay_close','pay_grower']); ?>
  <div class="nav-group" data-group="payments">
    <div class="nav-toggle <?= $grp_pay ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Payments
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <a href="pay_dashboard.php" class="nav-item <?= $active_page==='pay_dashboard'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/></svg>
    Pay Dashboard
  </a>
  <a href="payments.php" class="nav-item <?= $active_page==='payments'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="4" width="14" height="10" rx="1"/><path d="M1 8h14M5 12h2"/></svg>
    Payment Register
  </a>
  <a href="pay_batches.php" class="nav-item <?= $active_page==='pay_batches'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="12" height="12" rx="1"/><path d="M5 8h6M8 5v6"/></svg>
    Payment Batches
  </a>
  <a href="pay_grower.php" class="nav-item <?= $active_page==='pay_grower'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5" r="3"/><path d="M2 14c0-3.3 2.7-6 6-6s6 2.7 6 6"/></svg>
    Grower History
  </a>
  <a href="pay_audit.php" class="nav-item <?= $active_page==='pay_audit'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2h10v12H3z"/><path d="M6 6h4M6 9h2"/></svg>
    Audit Log
  </a>
  <a href="pay_close.php" class="nav-item <?= $active_page==='pay_close'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="7" width="10" height="8" rx="1"/><path d="M5 7V5a3 3 0 016 0v2"/></svg>
    Close Day
  </a>
    </div>
  </div>
  <?php endif; ?>

  <?php 
  $show_file_manager = has_right('admin') || has_right('reports') || has_right('floor') || has_right('growers');
  if ($show_file_manager):
      $grp_fm = in_array($active_page,['file_manager_uploads','timb_reports','timb_grower_registry','timb_registry_sync','timb_growermaster','timb_pricematrix','timb_grades_ws','timb_booking','print_labels']);
  ?>
  <div class="nav-group" data-group="file_manager">
    <div class="nav-toggle <?= $grp_fm ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      File Manager
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <?php if (has_right('admin')): ?>
  <a href="file_manager_uploads.php" class="nav-item <?= $active_page==='file_manager_uploads'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2h7l3 3v9H3V2z"/><path d="M10 2v3h3"/><path d="M8 7v5M5.5 9.5l2.5-2.5 2.5 2.5"/></svg>
    Uploads
  </a>
  <?php endif; ?>
  <?php if (has_right('reports') || has_right('admin')): ?>
  <a href="timb_reports.php" class="nav-item <?= $active_page==='timb_reports'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2h7l3 3v9H3V2z"/><path d="M10 2v3h3"/><path d="M5 9h6M5 11h4"/></svg>
    TIMB Reports
  </a>
  <?php endif; ?>
  <?php if (has_right('growers') || has_right('admin')): ?>
  <a href="timb_growermaster.php" class="nav-item <?= $active_page==='timb_growermaster'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5" r="2.5"/><path d="M3 13.5a5 5 0 0110 0"/><path d="M12.5 2.5l2 2-2 2"/></svg>
    TIMB Grower Master
  </a>
  <?php endif; ?>
  <?php if (has_right('accounts') || has_right('reports') || has_right('admin')): ?>
  <a href="timb_pricematrix.php" class="nav-item <?= $active_page==='timb_pricematrix'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 13l3.5-4 3 2.5L13 5"/><path d="M10 5h3v3"/></svg>
    TIMB Price Matrix
  </a>
  <?php endif; ?>
  <?php if (has_right('growers') || has_right('floor') || has_right('admin')): ?>
  <a href="timb_booking.php" class="nav-item <?= $active_page==='timb_booking'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 6.5h12M5.5 2v2.5M10.5 2v2.5"/></svg>
    TIMB Bookings
  </a>
  <?php endif; ?>
  <?php if (has_right('floor') || has_right('admin')): ?>
  <a href="timb_grades_ws.php" class="nav-item <?= $active_page==='timb_grades_ws'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2l1.8 3.9 4.2.5-3.1 2.9.8 4.2L8 11.5 4.3 13.5l.8-4.2L2 6.4l4.2-.5z"/></svg>
    TIMB Grades
  </a>
  <?php endif; ?>
  <?php if (has_right('admin')): ?>
  <a href="timb_registry_sync.php" class="nav-item <?= $active_page==='timb_registry_sync'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8a5 5 0 1 1 10 0"/><path d="M8 5v6M5 8l3 3 3-3"/></svg>
    TIMB Registry Sync
  </a>
  <?php endif; ?>
  <?php if (has_right('floor') || has_right('admin')): ?>
  <a href="print_labels.php" class="nav-item <?= $active_page==='print_labels'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="5" width="12" height="8" rx="1"/><path d="M5 5V3a1 1 0 011-1h4a1 1 0 011 1v2"/><path d="M5 10h6"/></svg>
    Print Labels
  </a>
  <?php endif; ?>
  <?php if (has_right('growers') || has_right('admin')): ?>
  <a href="timb_grower_registry.php" class="nav-item <?= $active_page==='timb_grower_registry'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 2h12v12H2z"/><path d="M5 5h6M5 8h4M5 11h3"/></svg>
    Registry &amp; Creditors
  </a>
  <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (has_right('reports')): ?>
  <?php $grp_rpt = in_array($active_page,['reports','season_compare','analytics','buyer_analytics','grower_performance','financial_forecast','price_forecast','pay_reconcile','pay_owed_recon','pay_daily_recon','business_intelligence']); ?>
  <div class="nav-group" data-group="reports">
    <div class="nav-toggle <?= $grp_rpt ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Reports
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <a href="business_intelligence.php" class="nav-item <?= $active_page==='business_intelligence'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 13h14M2 13V5l3-3 4 3 5-3v11H2z"/><rect x="5" y="7" width="2" height="4"/><rect x="9" y="5" width="2" height="6"/></svg>
    Business Intelligence
  </a>
  <a href="analytics.php" class="nav-item <?= $active_page==='analytics'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="1 12 5 7 9 10 15 3"/><line x1="1" y1="15" x2="15" y2="15"/></svg>
    Analytics
  </a>
  <a href="buyer_analytics.php" class="nav-item <?= $active_page==='buyer_analytics'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 14V8l4-2 4 3 4-6"/><circle cx="14" cy="3" r="1.5"/></svg>
    Buyer Analytics
  </a>
  <a href="grower_performance.php" class="nav-item <?= $active_page==='grower_performance'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 12V8M8 12V5M12 12V3"/><path d="M2 14h12"/></svg>
    Grower Performance
  </a>
  <a href="financial_forecast.php" class="nav-item <?= $active_page==='financial_forecast'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/><path d="M8 2v1M14 8h-1M8 14v-1M2 8h1"/></svg>
    Financial Forecast
  </a>
  <a href="season_compare.php" class="nav-item <?= $active_page==='season_compare'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 12V6l4-4 4 4 4-2v8"/><path d="M2 12h12"/></svg>
    Season Compare
  </a>
  <a href="price_forecast.php" class="nav-item <?= $active_page==='price_forecast'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 13l3-4 3 2 4-5"/><path d="M11 6l3-2" stroke-dasharray="1.5 1"/><circle cx="14" cy="4" r="1.5" fill="var(--gold)" stroke="none"/></svg>
    Price Forecast
  </a>
  <a href="pay_reconcile.php" class="nav-item <?= $active_page==='pay_reconcile'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="10" rx="1"/><path d="M5 8h3l1.5-2 1 4 1-2H13"/></svg>
    Bank Statement Match
  </a>
  <a href="pay_owed_recon.php" class="nav-item <?= $active_page==='pay_owed_recon'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2v12M3 5h10M3 5l-2 4h4zM13 5l-2 4h4z"/></svg>
    Paid vs Owed
  </a>
  <a href="pay_daily_recon.php" class="nav-item <?= $active_page==='pay_daily_recon'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2.5" width="12" height="11" rx="1.5"/><path d="M2 6h12M5 9l1.5 1.5L10 8"/></svg>
    Daily Reconciliation
  </a>
  <a href="reports.php" class="nav-item <?= $active_page==='reports'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="1" width="12" height="14" rx="1"/><path d="M5 5h6M5 8h6M5 11h4"/></svg>
    Reports
  </a>
    </div>
  </div>
  <?php endif; ?>

  <?php $grp_adm = in_array($active_page,['settings','users','backup','audit_log','auto_backup','timb_api','grower_portal','notifications','two_factor','sms_notify','system_health','multi_floor','clerk_performance','whatsapp_notify','document_vault','help_center','language','role_dashboard','email_reports','billing']); ?>
  <div class="nav-group" data-group="admin">
    <div class="nav-toggle <?= $grp_adm ? 'has-active' : '' ?>" onclick="toggleNavGroup(this)">
      Admin
      <svg class="nav-toggle-arrow" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2l4 3-4 3"/></svg>
    </div>
    <div class="nav-children">
  <?php if (has_right('admin')): ?>
  <a href="sms_notify.php" class="nav-item <?= $active_page==='sms_notify'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 3h12a1 1 0 011 1v7a1 1 0 01-1 1H3l-2 2V4a1 1 0 011-1z"/></svg>
    SMS Notify
  </a>
  <a href="whatsapp_notify.php" class="nav-item <?= $active_page==='whatsapp_notify'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 14l1.5-4A6 6 0 1112.5 5.5L2 14z"/><path d="M6 7c0 1.5 2 3 4 3"/></svg>
    WhatsApp Notify
  </a>
  <a href="document_vault.php" class="nav-item <?= $active_page==='document_vault'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="12" height="12" rx="1"/><path d="M2 6h12"/><path d="M6 6v8"/></svg>
    Document Vault
  </a>
  <?php endif; ?>
  <a href="language.php" class="nav-item <?= $active_page==='language'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M2 8h12M8 2c-2 2-2 4 0 6s2 4 0 6M8 2c2 2 2 4 0 6s-2 4 0 6"/></svg>
    Language
  </a>
  <a href="role_dashboard.php" class="nav-item <?= $active_page==='role_dashboard'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><path d="M12 10v4M10 12h4"/></svg>
    My Dashboard
  </a>
  <a href="help_center.php" class="nav-item <?= $active_page==='help_center'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M6 6a2 2 0 114 0c0 1-1.5 1.5-2 2v1M8 12v.5"/></svg>
    Help Center
  </a>
  <?php if (has_right('reports') || has_right('admin')): ?>
  <a href="clerk_performance.php" class="nav-item <?= $active_page==='clerk_performance'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2v12M4 6v8M12 4v10"/></svg>
    Clerk Performance
  </a>
  <?php endif; ?>
  <?php if (has_right('admin')): ?>
  <a href="system_health.php" class="nav-item <?= $active_page==='system_health'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 8h3l2-5 3 10 2-5h4"/></svg>
    System Health
  </a>
  <a href="sync_monitor.php" class="nav-item <?= $active_page==='sync_monitor'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8a5 5 0 1 1 10 0"/><path d="M8 5v6M5 8l3 3 3-3"/></svg>
    Sync &amp; Backup Monitor
  </a>
  <?php endif; ?>
  <?php if (has_right('admin') || has_right('floor')): ?>
  <a href="multi_floor.php" class="nav-item <?= $active_page==='multi_floor'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="3" width="6" height="10" rx="1"/><rect x="9" y="3" width="6" height="10" rx="1"/></svg>
    Multi-Floor
  </a>
  <?php endif; ?>
  <a href="notifications.php" class="nav-item <?= $active_page==='notifications'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2a5 5 0 0 1 5 5v2l1.5 2.5H1.5L3 9V7a5 5 0 0 1 5-5z"/><path d="M6 13a2 2 0 0 0 4 0"/></svg>
    Notifications
  </a>
  <a href="two_factor.php" class="nav-item <?= $active_page==='two_factor'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="7" width="10" height="8" rx="1.5"/><path d="M5 7V5a3 3 0 0 1 6 0v2"/><circle cx="8" cy="11" r="1" fill="currentColor" stroke="none"/></svg>
    2FA Security
  </a>
  <?php if (has_right('admin')): ?>
  <a href="audit_log.php" class="nav-item <?= $active_page==='audit_log'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M2 8h8M2 12h5"/><path d="M12 10l2 2 3-3" stroke-width="1.8"/></svg>
    Audit Log
  </a>
  <a href="settings.php" class="nav-item <?= $active_page==='settings'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="2"/><path d="M8 1v2M8 13v2M1 8h2M13 8h2M3.05 3.05l1.41 1.41M11.54 11.54l1.41 1.41M3.05 12.95l1.41-1.41M11.54 4.46l1.41-1.41"/></svg>
    Settings
  </a>
  <a href="billing.php" class="nav-item <?= $active_page==='billing'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="2" width="14" height="12" rx="1.5"/><path d="M5 6h6M5 10h3"/><circle cx="12" cy="10" r="1.5" fill="var(--gold)" stroke="none"/></svg>
    Billing &amp; License
  </a>
  <a href="users.php" class="nav-item <?= $active_page==='users'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="6" cy="5" r="2.5"/><path d="M1 14c0-2.8 2.2-5 5-5s5 2.2 5 5"/><path d="M11 7l1.5 1.5L15 6"/></svg>
    Users
  </a>
  <a href="auto_backup.php" class="nav-item <?= $active_page==='auto_backup'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1v9M5 7l3 3 3-3"/><path d="M2 12v2a1 1 0 001 1h10a1 1 0 001-1v-2"/><circle cx="11" cy="4" r="2.5" fill="var(--leaf)" stroke="none"/></svg>
    Auto Backup
  </a>
  <a href="timb_api.php" class="nav-item <?= $active_page==='timb_api'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 8h3l2-5 3 10 2-5h2"/></svg>
    TIMB API
  </a>
  <a href="grower_portal.php" class="nav-item <?= $active_page==='grower_portal'?'active':'' ?>" target="_blank">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5" r="3"/><path d="M2 14c0-3.3 2.7-6 6-6s6 2.7 6 6"/></svg>
    Grower Portal <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:10px;height:10px;display:inline-block;vertical-align:middle;margin-left:3px;opacity:0.6"><path d="M9 1h6v6M15 1L8 8"/></svg>
  </a>
  <a href="buyer_login.php" class="nav-item <?= $active_page==='buyer_login'?'active':'' ?>" target="_blank">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5" r="3"/><path d="M2 14c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M11 8l1.5 1.5L15 7"/></svg>
    Buyer Portal <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:10px;height:10px;display:inline-block;vertical-align:middle;margin-left:3px;opacity:0.6"><path d="M9 1h6v6M15 1L8 8"/></svg>
  </a>
  <a href="email_reports.php" class="nav-item <?= $active_page==='email_reports'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="4" width="12" height="9" rx="1"/><path d="M2 4l6 5 6-5"/></svg>
    Email Reports
  </a>
  <a href="backup.php" class="nav-item <?= $active_page==='backup'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1v8M5 6l3 3 3-3"/><path d="M2 11v2a1 1 0 001 1h10a1 1 0 001-1v-2"/></svg>
    Backup & Restore
  </a>
  <?php endif; ?>
    </div>
  </div>
  <!-- PWA Install Button -->
  <button id="pwa-install-btn" style="display:none; width:calc(100% - 24px); margin:12px; padding:10px 14px; background:rgba(200,149,42,0.15); border:1px dashed var(--gold); color:var(--gold-l); font-size:0.75rem; font-weight:600; border-radius:4px; cursor:pointer; text-align:center; transition:background 0.2s, color 0.2s; outline:none; font-family:inherit">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:11px;height:11px;display:inline-block;vertical-align:middle;margin-right:4px"><path d="M8 2v10M4 8l4 4 4-4M2 14h12"/></svg> Install Flowork App
  </button>
</div>

<!-- Real-Time SSE Listener -->
<script>
(function() {
  'use strict';
  
  // Track last seen notification ID to prevent double alert on page reload
  var lastIdKey = 'fw_sse_last_id';
  var lastSeenId = localStorage.getItem(lastIdKey) || 0;
  
  // Create EventSource connection
  var sseUrl = '<?= APP_BASE ?>/sse.php?last_id=' + lastSeenId;
  var source = new EventSource(sseUrl);
  
  source.onmessage = function(event) {
    try {
      var data = JSON.parse(event.data);
      if (data && data.id) {
        // Persist progress
        localStorage.setItem(lastIdKey, data.id);
        
        // Push toast alert
        if (typeof fw !== 'undefined' && typeof fw.toast === 'function') {
          var toastType = 'info';
          if (data.type === 'bale_weighed') toastType = 'success';
          else if (data.type === 'bale_sold') toastType = 'success';
          else if (data.type === 'rate_updated') toastType = 'warning';
          
          fw.toast('🔔 ' + data.message, toastType);
          
          // Chime
          playAlertChime();
        }
        
        // Bell icon badge increment
        var badge = document.getElementById('navbar-bell-badge');
        if (badge) {
          var cnt = parseInt(badge.textContent) || 0;
          badge.textContent = cnt + 1;
          badge.style.display = 'inline-flex';
        }
      }
    } catch(err) {}
  };
  
  function playAlertChime() {
    try {
      var ctx = new (window.AudioContext || window.webkitAudioContext)();
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(783.99, ctx.currentTime); // G5 note
      osc.frequency.exponentialRampToValueAtTime(1174.66, ctx.currentTime + 0.12); // D6 note
      gain.gain.setValueAtTime(0.12, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(ctx.currentTime);
      osc.stop(ctx.currentTime + 0.45);
    } catch(_) {}
  }
})();
</script>

<!-- ══ KEYBOARD SHORTCUT HELP OVERLAY ══ -->
<div id="kb-overlay" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.65);align-items:center;justify-content:center;backdrop-filter:blur(4px)">
  <div style="background:#1c130a;border:1px solid rgba(200,149,42,.35);border-radius:14px;padding:2rem;max-width:580px;width:90%;max-height:80vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.7)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem">
      <div style="font-family:'Bebas Neue',sans-serif;font-size:1.5rem;letter-spacing:.1em;color:var(--gold-l)"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:16px;height:16px;display:inline-block;vertical-align:middle;margin-right:6px;color:var(--gold-l)"><rect x="1" y="3" width="14" height="10" rx="1.5"/><path d="M4 6h1M7 6h1M10 6h1M11 6h1M4 9h1M11 9h1M6 9h4"/></svg> KEYBOARD SHORTCUTS</div>
      <button onclick="document.getElementById('kb-overlay').style.display='none'" style="background:none;border:1px solid rgba(245,240,232,.15);color:rgba(245,240,232,.5);border-radius:6px;padding:4px 10px;cursor:pointer;font-size:.8rem">ESC / Close</button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
      <?php
      $shortcuts = [
        ['?','Show this help'],
        ['Esc','Close modals / overlays'],
        ['G D','Go to Dashboard'],
        ['G S','Go to Selling Floor'],
        ['G B','Go to Balance'],
        ['G R','Go to Reports'],
        ['G A','Go to Analytics'],
        ['G L','Go to Live Floor'],
        ['G Q','Go to Floor Queue'],
        ['G P','Go to Price Forecast'],
        ['Ctrl+/','Focus search field'],
        ['F5','Refresh current view'],
      ];
      foreach($shortcuts as $s):
      ?>
      <div style="display:flex;align-items:center;gap:.75rem;padding:.45rem .65rem;border-radius:6px;background:rgba(200,149,42,.06);border:1px solid rgba(200,149,42,.1)">
        <kbd style="background:rgba(200,149,42,.2);color:var(--gold-l);border:1px solid rgba(200,149,42,.3);border-radius:4px;padding:2px 7px;font-family:monospace;font-size:.72rem;white-space:nowrap;flex-shrink:0"><?=htmlspecialchars($s[0])?></kbd>
        <span style="font-size:.75rem;color:rgba(245,240,232,.65)"><?=htmlspecialchars($s[1])?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:1.25rem;font-size:.65rem;color:rgba(245,240,232,.25);text-align:center">Press <kbd style="background:rgba(200,149,42,.15);color:var(--gold);border:1px solid rgba(200,149,42,.25);border-radius:3px;padding:1px 5px;font-family:monospace">?</kbd> anytime to toggle this panel</div>
  </div>
</div>
<script>
(function(){
  var kbOv = document.getElementById('kb-overlay');
  var navMap = {d:'dashboard.php',s:'selling.php',b:'balance.php',r:'reports.php',a:'analytics.php',l:'live_floor.php',q:'floor_queue.php',p:'price_forecast.php'};
  var gMode = false;
  document.addEventListener('keydown', function(e){
    var tag = (document.activeElement||{}).tagName||'';
    if(['INPUT','TEXTAREA','SELECT'].includes(tag)) return;
    var key = e.key.toLowerCase();
    if(key==='escape'){ kbOv.style.display='none'; gMode=false; return; }
    if(key==='?'||e.key==='?'){ kbOv.style.display = kbOv.style.display==='none'?'flex':'none'; return; }
    if(key==='g'){ gMode=true; setTimeout(function(){gMode=false;},2000); return; }
    if(gMode && navMap[key]){ window.location.href=window.APP_BASE+'/'+navMap[key]; gMode=false; return; }
  });
  kbOv.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
})();
</script>

<div class="main" id="main-content">
