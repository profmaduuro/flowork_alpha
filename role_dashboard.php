<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title  = "My Dashboard";
$active_page = "role_dashboard";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);
$user_role = $_SESSION['role'] ?? 'clerk';
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

function rdSafe($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_assoc()?:[];}
function rdAll($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_all(MYSQLI_ASSOC)?:[];}
$sf = $spid ? "AND g.selling_pointid=$spid" : "";
$today = date('Y-m-d');

// Common KPIs
$today_bales = rdSafe($conn,"SELECT COUNT(sb.id) c FROM sold_bales sb WHERE DATE(sb.created_at)='$today'");
$today_usd = rdSafe($conn,"SELECT COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) v
    FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN bale_mass bm ON bm.id=bj.bale_massid WHERE DATE(sb.created_at)='$today'");
$pending_balance = rdSafe($conn,"SELECT COUNT(tg.id) c FROM transporter_growers tg LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id WHERE tg.booked=1 AND bb.id IS NULL ".($spid?"AND tg.selling_pointid=$spid":""));
$weighed_today = rdSafe($conn,"SELECT COUNT(*) c FROM bale_mass WHERE DATE(created_at)='$today' ".($spid?"AND selling_pointid=$spid":""));
$open_disputes = rdSafe($conn,"SELECT COUNT(*) c FROM grade_disputes WHERE status='open' ".($spid?"AND selling_pointid=$spid":""));
$unbalanced = rdSafe($conn,"SELECT COUNT(tg.id) c FROM transporter_growers tg
    JOIN (SELECT DISTINCT bj.transporter_growersid FROM bale_junus bj JOIN tickets tk ON tk.id=bj.transporter_growersid) tkd ON tkd.transporter_growersid=tg.id
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id WHERE bb.id IS NULL");

// Recent activity
$recent_sales = rdAll($conn,"SELECT sb.id, tk.barcode, CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END price, bm.mass, sb.created_at
    FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN bale_mass bm ON bm.id=bj.bale_massid
    WHERE DATE(sb.created_at)='$today' ORDER BY sb.id DESC LIMIT 8");

// Role-specific data
$role_label = ['admin'=>'Administrator','manager'=>'Floor Manager','clerk'=>'Floor Clerk','finance'=>'Finance','scale'=>'Scale Operator'][$user_role] ?? ucfirst($user_role);
$role_color = ['admin'=>'#c8952a','manager'=>'#3d6b35','clerk'=>'#1a6b9a','finance'=>'#8e44ad','scale'=>'#e67e22'][$user_role] ?? 'var(--gold)';

// Greeting
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

include "includes/layout.php";
?>

<style>
.rd-hero { background:linear-gradient(135deg,<?=$role_color?>12,<?=$role_color?>05);border:1px solid <?=$role_color?>25;border-radius:var(--radius);padding:1.5rem;margin-bottom:1.5rem;position:relative;overflow:hidden; }
.rd-hero::after { content:'';position:absolute;top:-20px;right:-20px;width:120px;height:120px;border-radius:50%;background:<?=$role_color?>08; }
.rd-hero-greeting { font-family:'Bebas Neue',sans-serif;font-size:1.8rem;letter-spacing:.04em;color:var(--ink); }
.rd-hero-role { display:inline-flex;padding:3px 10px;border-radius:999px;font-size:.6rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;background:<?=$role_color?>15;color:<?=$role_color?>; }
.rd-widget-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem;margin-bottom:1.5rem; }
.rd-widget { background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:1rem;text-align:center;transition:all .2s; }
body.theme-dark .rd-widget { background:var(--ink-soft); }
.rd-widget:hover { transform:translateY(-2px);box-shadow:0 4px 16px rgba(0,0,0,.06); }
.rd-widget-icon { font-size:1.5rem;margin-bottom:.35rem; }
.rd-widget-val { font-family:'Bebas Neue',sans-serif;font-size:2rem;line-height:1; }
.rd-widget-lbl { font-size:.55rem;color:var(--muted);text-transform:uppercase;letter-spacing:.12em;font-weight:500;margin-top:.2rem; }
.rd-shortcut { display:flex;align-items:center;gap:.65rem;padding:.65rem .85rem;border-bottom:1px solid var(--border);text-decoration:none;color:var(--ink);transition:all .15s; }
.rd-shortcut:hover { background:var(--gold-bg);padding-left:1rem; }
.rd-shortcut-icon { width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;background:var(--cream-d);flex-shrink:0; }
.rd-shortcut-text { font-size:.82rem;font-weight:500; }
.rd-shortcut-sub { font-size:.65rem;color:var(--muted); }
</style>

<!-- Hero -->
<div class="rd-hero">
  <div class="rd-hero-greeting"><?=$greeting?>, <?=htmlspecialchars($user_name)?></div>
  <div style="margin-top:.3rem"><span class="rd-hero-role"><?=$role_label?></span></div>
  <div style="font-size:.78rem;color:var(--muted);margin-top:.5rem"><?=date('l, d F Y')?></div>
</div>

<!-- Context Widgets -->
<div class="rd-widget-grid">
  <div class="rd-widget">
    <div class="rd-widget-icon">📦</div>
    <div class="rd-widget-val" style="color:var(--gold)"><?=(int)($today_bales['c']??0)?></div>
    <div class="rd-widget-lbl">Bales Sold Today</div>
  </div>
  <div class="rd-widget">
    <div class="rd-widget-icon">💰</div>
    <div class="rd-widget-val" style="color:var(--leaf)">$<?=number_format((float)($today_usd['v']??0),0)?></div>
    <div class="rd-widget-lbl">USD Today</div>
  </div>
  <div class="rd-widget">
    <div class="rd-widget-icon">⚖️</div>
    <div class="rd-widget-val"><?=(int)($weighed_today['c']??0)?></div>
    <div class="rd-widget-lbl">Weighed Today</div>
  </div>
  <div class="rd-widget">
    <div class="rd-widget-icon">📋</div>
    <div class="rd-widget-val" style="color:<?=(int)($pending_balance['c']??0)>0?'var(--gold)':'var(--leaf)'?>"><?=(int)($pending_balance['c']??0)?></div>
    <div class="rd-widget-lbl">Pending Balance</div>
  </div>
  <div class="rd-widget">
    <div class="rd-widget-icon">⚠️</div>
    <div class="rd-widget-val" style="color:<?=(int)($open_disputes['c']??0)>0?'var(--danger)':'var(--leaf)'?>"><?=(int)($open_disputes['c']??0)?></div>
    <div class="rd-widget-lbl">Open Disputes</div>
  </div>
</div>

<div class="grid-2 mb-3">
  <!-- Quick Links -->
  <div class="card">
    <div class="card-head"><h3>⚡ Quick Actions</h3></div>
    <?php
    $shortcuts = [
        'admin' => [
            ['📊','analytics.php','Analytics','View floor analytics and trends'],
            ['👥','users.php','Manage Users','Add or edit system users'],
            ['⚙️','settings.php','Settings','System configuration'],
            ['🔐','audit_log.php','Audit Log','Review system changes'],
            ['💾','auto_backup.php','Backup','Database backup management'],
            ['🏥','system_health.php','System Health','Monitor system status'],
        ],
        'manager' => [
            ['📊','analytics.php','Analytics','Floor performance trends'],
            ['📋','eod_reports.php','EOD Reports','End-of-day summaries'],
            ['🔨','selling.php','Selling','Sale processing'],
            ['⚖️','balance.php','Balancing','Settlement processing'],
            ['⚠️','grade_disputes.php','Disputes','Grade dispute management'],
            ['📅','grower_booking.php','Booking','Grower booking'],
        ],
        'clerk' => [
            ['🔨','selling.php','Selling','Capture sales'],
            ['⚖️','scale.php','Scale','Weigh bales'],
            ['🎫','ticket_printing.php','Tickets','Print GDN tickets'],
            ['📋','junus.php','Junus','Group bales by grower'],
            ['📊','floor_summary.php','Floor Summary','View today\'s floor'],
            ['📦','dispatch.php','Dispatch','Dispatch bales'],
        ],
        'finance' => [
            ['💰','pay_dashboard.php','Pay Dashboard','Payment overview'],
            ['📑','payments.php','Payments','Payment register'],
            ['🏦','banking.php','Banking','Grower bank details'],
            ['⚖️','balance.php','Balancing','Settlement processing'],
            ['📊','reports.php','Reports','Financial reports'],
            ['💳','stop_orders.php','Stop Orders','Manage deductions'],
        ],
        'scale' => [
            ['⚖️','scale.php','Scale','Weigh bales'],
            ['📋','junus.php','Junus','Link bales to growers'],
            ['🎫','ticket_printing.php','Tickets','Print tickets'],
            ['📅','grower_booking.php','Booking','Check bookings'],
        ],
    ];
    $user_shortcuts = $shortcuts[$user_role] ?? $shortcuts['clerk'];
    ?>
    <?php foreach($user_shortcuts as $s): ?>
    <a href="<?=$s[1]?>" class="rd-shortcut">
      <div class="rd-shortcut-icon"><?=$s[0]?></div>
      <div>
        <div class="rd-shortcut-text"><?=$s[2]?></div>
        <div class="rd-shortcut-sub"><?=$s[3]?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Recent Activity -->
  <div class="card">
    <div class="card-head"><h3>🕐 Recent Sales</h3><a href="live_feed.php" class="btn btn-ghost btn-sm">Live Feed →</a></div>
    <div class="card-body">
      <?php if(!empty($recent_sales)): ?>
      <?php foreach($recent_sales as $rs): ?>
      <div style="display:flex;align-items:center;gap:.65rem;padding:.5rem 0;border-bottom:1px solid var(--border)">
        <div style="font-size:.9rem">📦</div>
        <div style="flex:1">
          <div style="font-size:.78rem;font-weight:500"><?=htmlspecialchars($rs['barcode'])?></div>
          <div style="font-size:.62rem;color:var(--muted)"><?=number_format((float)$rs['mass'],1)?>kg · <?=date('H:i',strtotime($rs['created_at']))?></div>
        </div>
        <div style="font-family:'Bebas Neue',sans-serif;font-size:1rem;color:var(--gold)">$<?=number_format((float)$rs['price'],2)?></div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div style="padding:2rem;text-align:center;color:var(--muted)">No sales yet today</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include "includes/layout_end.php"; ?>
