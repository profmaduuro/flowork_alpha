<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "pay_schema.php";
require_login();
$page_title = "Close Payment Day"; $active_page = "pay_close";
$userid = current_userid(); $spid = (int)($_SESSION['selling_pointid'] ?? 0);
pay_schema($conn, $spid);
$my_role = pay_role($conn, $userid);
if (!pay_can($my_role,'close_day')) { echo "<p>Access denied. Manager or Admin required.</p>"; exit; }
$msg = $err = '';

$date_f = $conn->real_escape_string($_GET['date'] ?? date('Y-m-d'));
$already_closed = $conn->query("SELECT id FROM payment_day_close WHERE sell_date='$date_f' AND selling_pointid=$spid LIMIT 1")->num_rows > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already_closed) {
    $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    $confirm = $_POST['confirm'] ?? '';
    if ($confirm !== 'CLOSE') { $err = "Type CLOSE to confirm."; }
    else {
        $totals = $conn->query("
            SELECT COUNT(DISTINCT ps.transporter_growersid) growers,
                   SUM(CASE WHEN ps.currency='usd' AND ps.status IN ('paid','released') THEN ps.amount_paid ELSE 0 END) paid_usd,
                   SUM(CASE WHEN ps.currency='zig' AND ps.status IN ('paid','released') THEN ps.amount_paid ELSE 0 END) paid_zig
            FROM payment_status ps
            JOIN transporter_growers tg ON tg.id=ps.transporter_growersid
            JOIN growers g ON g.id=tg.growerid
            WHERE g.selling_pointid=$spid AND ps.sell_date='$date_f'
        ")->fetch_assoc();
        $pusd=(float)($totals['paid_usd']??0); $pzig=(float)($totals['paid_zig']??0); $gc=(int)($totals['growers']??0);
        $conn->query("INSERT INTO payment_day_close (sell_date,selling_pointid,closed_by,total_paid_usd,total_paid_zig,grower_count,notes) VALUES ('$date_f',$spid,$userid,$pusd,$pzig,$gc,'$notes')");
        // Lock all batches for this date
        $conn->query("UPDATE payment_batch SET status='locked',locked_at=NOW() WHERE sell_date='$date_f' AND status IN ('draft','reviewed','approved','released')");
        pay_log($conn,'payment_batch',0,$userid,'day_closed','usd',$pusd,'','','Day closed: '.$date_f);
        $msg = "Payment day ".date('d M Y',strtotime($date_f))." closed. All records locked.";
    }
}

// Load summary
$summary = $conn->query("
    SELECT
      COUNT(DISTINCT tg.id) growers_payable,
      COALESCE(SUM(dtn.usd_net_value),0) total_payable_usd,
      COALESCE(SUM(dtn.zim_net_value),0) total_payable_zig
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
    WHERE g.selling_pointid=$spid AND DATE(bb.created_at)='$date_f' AND bb.stoporder_processed=1
")->fetch_assoc();

$paid = $conn->query("
    SELECT COUNT(DISTINCT ps.transporter_growersid) cnt,
           SUM(CASE WHEN ps.currency='usd' THEN ps.net_amount ELSE 0 END) usd,
           SUM(CASE WHEN ps.currency='zig' THEN ps.net_amount ELSE 0 END) zig
    FROM payment_status ps
    JOIN transporter_growers tg ON tg.id=ps.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    WHERE g.selling_pointid=$spid AND ps.sell_date='$date_f' AND ps.status IN ('paid','released')
")->fetch_assoc();

$open_batches = (int)$conn->query("SELECT COUNT(*) c FROM payment_batch WHERE sell_date='$date_f' AND status NOT IN ('released','locked')")->fetch_assoc()['c'];
$pending_growers = max(0,(int)$summary['growers_payable']-(int)$paid['cnt']);
$pending_usd = max(0,(float)$summary['total_payable_usd']-(float)$paid['usd']);

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Close Payment Day</h2>
  <p><?=date('l d F Y',strtotime($date_f))?></p></div>
  <a href="pay_dashboard.php?date=<?=htmlspecialchars((string)$date_f)?>" class="btn btn-ghost">← Dashboard</a>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>
<?php if($already_closed):?>
<div class="alert alert-gold">🔒 This payment day was already closed. Records are locked.</div>
<?php else:?>

<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
  <div class="kpi-card"><div class="kpi-label">Growers Payable</div><div class="kpi-value"><?=(int)$summary['growers_payable']?></div></div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)"><div class="kpi-label">Total Paid USD</div><div class="kpi-value sm">$<?=number_format((float)$paid['usd'],2)?></div><div class="kpi-sub"><?=(int)$paid['cnt']?> growers</div></div>
  <div class="kpi-card" style="--kpi-color:<?=$pending_growers>0?'var(--danger)':'var(--leaf)'?>">
    <div class="kpi-label">Pending Growers</div><div class="kpi-value"><?=$pending_growers?></div>
    <div class="kpi-sub">$<?=number_format($pending_usd,2)?> outstanding</div>
  </div>
  <div class="kpi-card" style="--kpi-color:<?=$open_batches>0?'var(--danger)':'var(--leaf)'?>">
    <div class="kpi-label">Open Batches</div><div class="kpi-value"><?=$open_batches?></div>
    <div class="kpi-sub"><?=$open_batches>0?'Release before closing':'All released ✓'?></div>
  </div>
</div>

<?php if($pending_growers>0||$open_batches>0):?>
<div class="card mb-3" style="border-left:3px solid var(--danger)">
<div class="card-body" style="padding:.75rem 1.15rem;font-size:.82rem">
  <div class="fw-500" style="color:var(--danger);margin-bottom:.5rem">⚠ Action required before closing</div>
  <?php if($open_batches>0):?><div>• <strong><?=$open_batches?> batch<?=$open_batches!=1?'es':''?></strong> still in draft/reviewed/approved — release them first</div><?php endif;?>
  <?php if($pending_growers>0):?><div>• <strong><?=$pending_growers?> grower<?=$pending_growers!=1?'s':''?></strong> still unpaid — $<?=number_format($pending_usd,2)?> outstanding</div><?php endif;?>
</div>
</div>
<?php endif;?>

<div class="card" style="max-width:540px">
<div class="card-head" style="background:var(--ink);color:var(--cream)"><h3>Confirm Day Close</h3></div>
<div class="card-body">
<p style="font-size:.82rem;margin-bottom:1rem;color:var(--muted)">
  Closing the payment day will <strong>lock all batches</strong> and prevent any further payment edits for <?=date('d M Y',strtotime($date_f))?>. This action cannot be undone without admin intervention.
</p>
<form method="POST">
  <div class="form-group">
    <label>Notes <span style="font-size:.68rem;color:var(--muted)">(optional — e.g. reason for unpaid growers)</span></label>
    <input type="text" name="notes" class="form-control" placeholder="e.g. 3 growers deferred to next day">
  </div>
  <div class="form-group">
    <label>Type <strong>CLOSE</strong> to confirm *</label>
    <input type="text" name="confirm" class="form-control" placeholder="CLOSE" style="font-family:monospace;font-size:1rem;letter-spacing:.1em" required>
  </div>
  <button type="submit" class="btn btn-primary" style="background:var(--danger);border-color:var(--danger)">
    🔒 Close Payment Day
  </button>
</form>
</div>
</div>
<?php endif;?>
<?php include "includes/layout_end.php"; ?>
