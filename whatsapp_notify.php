<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title  = "WhatsApp Notifications";
$active_page = "whatsapp_notify";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);

function waSafe($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_assoc()?:[];}
function waAll($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_all(MYSQLI_ASSOC)?:[];}

// Recent notification log
$recent_log = waAll($conn,"SELECT nl.*, g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name
    FROM notification_log nl
    LEFT JOIN growers g ON nl.recipient LIKE CONCAT('%',g.phone,'%') OR nl.recipient LIKE CONCAT('%',g.grower_num,'%')
    WHERE nl.type IN ('whatsapp','sms')
    ".($spid?"AND nl.selling_pointid=$spid":"")."
    ORDER BY nl.sent_at DESC LIMIT 30");

$wa_stats = waSafe($conn,"SELECT
    SUM(CASE WHEN type='whatsapp' THEN 1 ELSE 0 END) wa_total,
    SUM(CASE WHEN type='whatsapp' AND status='sent' THEN 1 ELSE 0 END) wa_sent,
    SUM(CASE WHEN type='sms' THEN 1 ELSE 0 END) sms_total,
    SUM(CASE WHEN type='sms' AND status='sent' THEN 1 ELSE 0 END) sms_sent
    FROM notification_log WHERE 1=1 ".($spid?"AND selling_pointid=$spid":""));

// Grower phone coverage
$phone_coverage = waSafe($conn,"SELECT COUNT(*) total,
    SUM(CASE WHEN phone IS NOT NULL AND phone != '' AND LENGTH(phone)>=9 THEN 1 ELSE 0 END) with_phone
    FROM growers WHERE active=1 ".($spid?"AND selling_pointid=$spid":""));
$coverage_pct = (int)($phone_coverage['total']??0) > 0 ? round(((int)($phone_coverage['with_phone']??0) / (int)$phone_coverage['total']) * 100) : 0;

// Message templates
$templates = [
    ['name'=>'Sale Complete','emoji'=>'💰','msg'=>'Hello {grower_name}, your {bales} bales sold for ${usd_total} on {sell_date}. Grade avg: {avg_grade}. Net payout: ${net_amount}. Thank you!','vars'=>'grower_name, bales, usd_total, sell_date, avg_grade, net_amount'],
    ['name'=>'Booking Confirmed','emoji'=>'📋','msg'=>'Hi {grower_name}, booking confirmed for {sell_date}. {bales} bales via {transporter}. Please ensure bales arrive by 6AM. Ref: {booking_ref}','vars'=>'grower_name, sell_date, bales, transporter, booking_ref'],
    ['name'=>'Payment Ready','emoji'=>'🏦','msg'=>'{grower_name}, your payment of ${net_amount} ({currency}) has been processed. Bank ref: {bank_ref}. Allow 24-48hrs for clearing.','vars'=>'grower_name, net_amount, currency, bank_ref'],
    ['name'=>'Daily Summary','emoji'=>'📊','msg'=>'Floor Report {date}: {bales_sold} bales, ${total_usd} gross, Avg ${avg_price}/kg. Top grade: {top_grade}. Next selling: {next_date}.','vars'=>'date, bales_sold, total_usd, avg_price, top_grade, next_date'],
    ['name'=>'Grade Dispute','emoji'=>'⚠️','msg'=>'{grower_name}, a grade dispute has been raised for barcode {barcode}. Original: {orig_grade} → Disputed: {new_grade}. We will notify you of the outcome.','vars'=>'grower_name, barcode, orig_grade, new_grade'],
];

include "includes/layout.php";
?>

<style>
.wa-template-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:.85rem;margin-bottom:1.5rem; }
.wa-template { background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:1rem;position:relative;transition:all .2s;cursor:default; }
body.theme-dark .wa-template { background:var(--ink-soft); }
.wa-template:hover { border-color:rgba(37,211,102,.3);box-shadow:0 4px 16px rgba(37,211,102,.08); }
.wa-template-head { display:flex;align-items:center;gap:.5rem;margin-bottom:.65rem; }
.wa-template-emoji { font-size:1.3rem; }
.wa-template-name { font-weight:500;font-size:.85rem; }
.wa-template-msg { background:rgba(37,211,102,.05);border:1px solid rgba(37,211,102,.12);border-radius:8px;padding:.65rem;font-size:.72rem;color:var(--ink);line-height:1.5;position:relative; }
.wa-template-msg::before { content:'';position:absolute;top:8px;left:-6px;width:12px;height:12px;background:rgba(37,211,102,.05);border-left:1px solid rgba(37,211,102,.12);border-bottom:1px solid rgba(37,211,102,.12);transform:rotate(45deg); }
.wa-template-vars { font-size:.6rem;color:var(--muted);margin-top:.5rem; }
.wa-config-box { background:linear-gradient(135deg,rgba(37,211,102,.06),rgba(37,211,102,.02));border:1px solid rgba(37,211,102,.2);border-radius:var(--radius);padding:1.25rem; }
.wa-status-dot { width:8px;height:8px;border-radius:50%;display:inline-block; }
.wa-status-sent { background:#25D366; }
.wa-status-failed { background:var(--danger); }
.wa-status-sim { background:var(--gold); }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>WhatsApp Notifications</h2>
    <p>Message templates, delivery tracking, grower communication</p>
  </div>
  <a href="sms_notify.php" class="btn btn-ghost btn-sm">SMS Notify →</a>
</div>

<div class="kpi-grid">
  <div class="kpi-card" style="--kpi-color:#25D366"><div class="kpi-label">WhatsApp Sent</div><div class="kpi-value"><?=(int)($wa_stats['wa_sent']??0)?></div><div class="kpi-sub">of <?=(int)($wa_stats['wa_total']??0)?> total</div></div>
  <div class="kpi-card" style="--kpi-color:var(--gold)"><div class="kpi-label">SMS Sent</div><div class="kpi-value"><?=(int)($wa_stats['sms_sent']??0)?></div><div class="kpi-sub">of <?=(int)($wa_stats['sms_total']??0)?> total</div></div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)"><div class="kpi-label">Phone Coverage</div><div class="kpi-value"><?=$coverage_pct?>%</div><div class="kpi-sub"><?=(int)($phone_coverage['with_phone']??0)?> of <?=(int)($phone_coverage['total']??0)?> growers</div></div>
</div>

<!-- API Config -->
<div class="wa-config-box mb-3">
  <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem">
    <span style="font-size:1.5rem">📱</span>
    <div>
      <div style="font-weight:500;font-size:.88rem">WhatsApp Business API</div>
      <div style="font-size:.72rem;color:var(--muted)">Configure your WhatsApp Business API credentials in Settings to enable WhatsApp notifications.</div>
    </div>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem">
    <a href="settings.php" class="btn btn-gold btn-sm">Configure API Keys →</a>
    <span class="badge badge-gray">Status: Awaiting Configuration</span>
  </div>
</div>

<!-- Message Templates -->
<div class="card mb-3">
  <div class="card-head"><h3>📝 Message Templates</h3><span class="badge badge-gold"><?=count($templates)?> templates</span></div>
</div>
<div class="wa-template-grid">
  <?php foreach($templates as $t): ?>
  <div class="wa-template">
    <div class="wa-template-head">
      <span class="wa-template-emoji"><?=$t['emoji']?></span>
      <span class="wa-template-name"><?=$t['name']?></span>
    </div>
    <div class="wa-template-msg"><?=htmlspecialchars($t['msg'])?></div>
    <div class="wa-template-vars">Variables: <?=htmlspecialchars($t['vars'])?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Recent Log -->
<div class="card">
  <div class="card-head"><h3>📋 Notification Log</h3></div>
  <div class="table-wrap" style="max-height:400px;overflow-y:auto">
    <table>
      <thead><tr><th>Type</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Sent</th></tr></thead>
      <tbody>
      <?php foreach($recent_log as $l):
        $type_icon = $l['type']==='whatsapp' ? '📱' : '💬';
        $st_class = $l['status']==='sent'?'wa-status-sent':($l['status']==='failed'?'wa-status-failed':'wa-status-sim');
      ?>
      <tr>
        <td><?=$type_icon?> <span style="font-size:.72rem;text-transform:uppercase"><?=htmlspecialchars($l['type'])?></span></td>
        <td style="font-size:.75rem"><?=htmlspecialchars($l['recipient'])?><?php if(!empty($l['grower_name'])): ?><br><span style="font-size:.6rem;color:var(--muted)"><?=htmlspecialchars($l['grower_name'])?></span><?php endif; ?></td>
        <td style="font-size:.72rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($l['subject']??'')?></td>
        <td><span class="wa-status-dot <?=$st_class?>"></span> <span style="font-size:.68rem"><?=htmlspecialchars($l['status'])?></span></td>
        <td style="font-size:.7rem;color:var(--muted)"><?=date('d M H:i',strtotime($l['sent_at']))?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($recent_log)): ?>
      <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">No notifications sent yet</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include "includes/layout_end.php"; ?>
