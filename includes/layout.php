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
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_BASE ?>/includes/layout.css?v=9">
<!-- When the pointer rests on a sidebar link, the browser renders that page in
     the background (prerender), so the click shows it at once instead of
     loading it. Only sidebar links: other links can run GET actions behind a
     confirm() and must not be fetched early. Logout, export/download and
     new-tab links are left out. The browser keeps at most a couple at a time. -->
<script type="speculationrules">
{"prerender":[{"where":{"and":[
  {"selector_matches":".sidebar a.nav-item, a.topbar-logo"},
  {"not":{"selector_matches":"[target=_blank]"}},
  {"not":{"href_matches":"*logout*"}},
  {"not":{"href_matches":"*export*"}},
  {"not":{"href_matches":"*download*"}},
  {"not":{"href_matches":"*dl=1*"}}
]},"eagerness":"moderate"}]}
</script>
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
        var lbl = (t === 'dark') ? 'Switch to light mode' : 'Switch to dark mode';
        btn.setAttribute('aria-label', lbl); btn.title = lbl;
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
    a.setAttribute('data-label', a.textContent.replace(/\s+/g,' ').trim());
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
<script src="<?= APP_BASE ?>/includes/flowork.js?v=10"></script>
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
  <button type="button" class="tb-burger" id="sb-desktop-toggle" onclick="toggleSidebarDesktop()" aria-label="Collapse or expand the menu" title="Collapse menu">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
  </button>
  <!-- Mobile: open drawer -->
  <button type="button" class="tb-burger ham-btn" id="sb-mobile-toggle" onclick="toggleSidebar()" aria-label="Open menu" style="display:none">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
  </button>
  <a href="dashboard.php" class="topbar-logo"><span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="#E2B04A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 20A7 7 0 0 1 4 13c0-5 4-9 16-9 0 12-4 16-9 16z"/><path d="M4 20c4-4 7-6 11-8"/></svg></span>Flowork</a>
  <?php if (!empty($_SESSION['season_name'])): ?>
  <span class="topbar-season"><?= htmlspecialchars($_SESSION['season_name']) ?></span>
  <?php endif; ?>
  <div class="topbar-spacer"></div>
  <?php if (is_master_user()): ?>
  <span class="tb-chip gold tb-hide-md" title="Logged in as Master User — unrestricted multi-floor view">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5z"/></svg>
    Master user
  </span>
  <?php elseif (is_dedicated_user()): ?>
  <span class="tb-chip tb-hide-md" title="Dedicated to this auction floor">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
    Floor: <?= htmlspecialchars($_SESSION['selling_point_name'] ?? '') ?>
  </span>
  <?php endif; ?>

  <!-- Floor switcher -->
  <?php
  $accessible = $_SESSION['accessible_floors'] ?? [];
  $cur_spid   = (int)($_SESSION['selling_pointid'] ?? 0);
  if (count($accessible) > 1 && is_master_user()):
  ?>
  <form method="POST" action="switch_floor.php" class="tb-hide-sm" style="display:flex;align-items:center;margin:0">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <select name="selling_pointid" onchange="this.form.submit()" class="tb-select" aria-label="Floor">
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

  <!-- PWA Connection Status Pill -->
  <span id="pwa-status-pill" class="tb-chip ok tb-hide-sm"><span class="dotc"></span><span id="pwa-status-text">Online</span></span>

  <!-- Offline Sync Queue Badge -->
  <button type="button" id="pwa-sync-queue-badge" class="tb-btn" style="display:none" onclick="triggerManualSync()" title="Click to force auto-sync">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
    Queue: <span id="pwa-sync-queue-count">0</span>
  </button>

  <!-- Command Palette Button -->
  <button type="button" class="tb-btn tb-hide-sm" onclick="toggleCommandPalette()" title="Open search (Ctrl+K)">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    Search <kbd>Ctrl K</kbd>
  </button>

  <button type="button" id="theme-toggle-btn" class="tb-btn tb-icon" onclick="setTheme('toggle')"
          aria-label="<?= $theme==='dark' ? 'Switch to light mode' : 'Switch to dark mode' ?>" title="<?= $theme==='dark' ? 'Switch to light mode' : 'Switch to dark mode' ?>">
    <svg class="theme-ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
    <svg class="theme-ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
  </button>
  <!-- SSE Real-time Notification Bell -->
  <button type="button" class="tb-btn tb-icon" title="Real-time notifications" aria-label="Notifications" onclick="resetBellCount()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
    <span id="navbar-bell-badge" class="tb-badge">0</span>
  </button>
  <script>
  function resetBellCount() {
    var badge = document.getElementById('navbar-bell-badge');
    if (badge) {
      badge.textContent = '0';
      badge.style.display = 'none';
    }
  }
  </script>
  <div class="topbar-user">
    <div class="topbar-avatar" aria-hidden="true"><?= strtoupper(substr($_SESSION['username'] ?? 'U', 0, 2)) ?></div>
    <span class="tb-name"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
  </div>
  <a href="logout.php" class="topbar-logout">Sign out</a>
</div>
<!-- SIDEBAR -->
<div id="nav-overlay" onclick="toggleSidebar()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:195"></div>
<div class="sidebar" id="sidebar">
  <button type="button" onclick="toggleSidebar()" class="sidebar-close" aria-label="Close menu">
    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
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
      $grp_sale = in_array($active_page,['floor','selling','selling2','sales','balance','bale_import','buyers','void_controls','re_offer','grade_disputes','buyer_management','live_feed','quality_control']); 
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
  <a href="bale_import.php" class="nav-item <?= $active_page==='bale_import'?'active':'' ?>">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 2h7l3 3v9H3z"/><path d="M8 7v5M6 10l2 2 2-2"/></svg>
    Bale File Import
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
  <button type="button" id="pwa-install-btn" style="display:none">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 2v10M4 8l4 4 4-4M2 14h12"/></svg> Install Flowork app
  </button>
</div>

<!-- Real-Time SSE Listener -->
<script>
(function() {
  'use strict';
  
  // Track last seen notification ID to prevent double alert on page reload
  var lastIdKey = 'fw_sse_last_id';
  var lastSeenId = localStorage.getItem(lastIdKey) || 0;
  
  // Create EventSource connection. Hidden tabs close it so background tabs
  // don't keep calling the server; it reopens when the tab is shown again.
  var source = null;
  function openStream() {
    if (source) return;
    var sseUrl = '<?= APP_BASE ?>/sse.php?last_id=' + (localStorage.getItem(lastIdKey) || lastSeenId);
    source = new EventSource(sseUrl);
    source.onmessage = onStreamMessage;
  }
  function closeStream() {
    if (source) { source.close(); source = null; }
  }
  document.addEventListener('visibilitychange', function() {
    if (document.hidden) closeStream(); else openStream();
  });

  function onStreamMessage(event) {
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
  }
  if (!document.hidden) openStream();

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
<div id="kb-overlay" role="dialog" aria-modal="true" aria-labelledby="kb-title">
  <div class="kb-panel">
    <div class="kb-head">
      <h2 id="kb-title">Keyboard shortcuts</h2>
      <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('kb-overlay').style.display='none'">Close</button>
    </div>
    <div class="kb-grid">
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
      <div class="kb-row"><kbd><?=htmlspecialchars($s[0])?></kbd><span><?=htmlspecialchars($s[1])?></span></div>
      <?php endforeach; ?>
    </div>
    <div class="kb-foot">Press <kbd>?</kbd> anytime to open or close this panel</div>
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
<script>
// Keep the sidebar's scroll position from page to page.
(function(){
  var sb = document.getElementById('sidebar');
  if (!sb) return;
  try { var y = sessionStorage.getItem('fw_sb_scroll'); if (y) sb.scrollTop = +y; } catch(e){}
  window.addEventListener('pagehide', function(){
    try { sessionStorage.setItem('fw_sb_scroll', sb.scrollTop); } catch(e){}
  });
})();
</script>

<div class="main" id="main-content">
