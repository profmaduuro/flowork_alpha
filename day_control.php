<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "Day Control";
$active_page = "day_control";
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$userid   = current_userid();
$seasonid = (int)($_SESSION['seasonid'] ?? 0);
$msg = $err = '';
$today = date('Y-m-d');

// ── Ensure start_of_day is the authoritative table ─────────────────────────
// The whole system (tickets, balance, exchange_rate) references start_of_day.id
// We do NOT use a separate selling_days table — that conflicts with start_of_day

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $sell_date = $conn->real_escape_string($_POST['sell_date'] ?? $today);
    $now       = date('Y-m-d H:i:s');

    // ── Open selling day ─────────────────────────────────────────────────
    if ($action === 'open_day') {
        if (!$seasonid) { $err = "No active season set. Go to Settings → Seasons first."; }
        else {
            // Check if already open for this date
            $ex = $conn->query("SELECT id FROM start_of_day
                WHERE description='$sell_date' AND seasonid=$seasonid AND selling_pointid=$spid AND active=1 LIMIT 1");
            if ($ex && $ex->num_rows > 0) {
                $err = "Selling day $sell_date is already open.";
            } else {
                // Close any previously active days for this season and floor
                // (usually only one active at a time, but deactivate old ones)
                $conn->query("UPDATE start_of_day SET active=0
                    WHERE seasonid=$seasonid AND selling_pointid=$spid AND active=1 AND description<>'$sell_date'");

                // Create start_of_day record with selling_pointid
                $conn->query("INSERT INTO start_of_day (userid, seasonid, selling_pointid, description, active, datetime)
                    VALUES ($userid, $seasonid, $spid, '$sell_date', 1, '$now')");
                $sod_id = (int)$conn->insert_id;

                if ($sod_id) {
                    $_SESSION['start_of_dayid'] = $sod_id;
                    audit_log($conn,$spid,$userid,'start_of_day','open',$sod_id,null,
                        ['date'=>$sell_date,'seasonid'=>$seasonid]);
                    $msg = "✓ Selling day <strong>$sell_date</strong> opened (ID: $sod_id). Set the ZiG exchange rate below.";
                } else {
                    $err = "Failed to open day: " . $conn->error;
                }
            }
        }
    }

    // ── Close selling day ────────────────────────────────────────────────
    if ($action === 'close_day') {
        $sod_id = (int)($_POST['sod_id'] ?? 0);
        if ($sod_id) {
            $conn->query("UPDATE start_of_day SET active=0 WHERE id=$sod_id AND seasonid=$seasonid AND selling_pointid=$spid");
            audit_log($conn,$spid,$userid,'start_of_day','close',$sod_id);
            $msg = "✓ Selling day closed.";
        }
    }

    // ── Reopen selling day ───────────────────────────────────────────────
    if ($action === 'reopen_day') {
        $sod_id = (int)($_POST['sod_id'] ?? 0);
        if ($sod_id) {
            // Close all other active days first
            $conn->query("UPDATE start_of_day SET active=0 WHERE seasonid=$seasonid AND active=1 AND selling_pointid=$spid");
            $conn->query("UPDATE start_of_day SET active=1 WHERE id=$sod_id AND selling_pointid=$spid");
            $_SESSION['start_of_dayid'] = $sod_id;
            audit_log($conn,$spid,$userid,'start_of_day','reopen',$sod_id);
            $msg = "✓ Selling day reopened.";
        }
    }

    // ── Set exchange rate ────────────────────────────────────────────────
    if ($action === 'set_rate') {
        $rate   = (float)($_POST['rate'] ?? 0);
        $sod_id = (int)($_POST['sod_id'] ?? 0);
        if ($rate <= 0) { $err = "Enter a valid exchange rate greater than 0."; }
        elseif (!$sod_id) { $err = "No selling day selected. Open a day first."; }
        else {
            // Deactivate previous rates for this start_of_day
            $conn->query("UPDATE exchange_rate SET active=0 WHERE start_of_dayid=$sod_id AND selling_pointid=$spid");

            // Insert new rate — matching real schema: userid, seasonid, start_of_dayid, selling_pointid, amount, active, datetime
            $conn->query("INSERT INTO exchange_rate (userid, seasonid, start_of_dayid, selling_pointid, amount, active, datetime)
                VALUES ($userid, $seasonid, $sod_id, $spid, $rate, 1, '$now')");
            $er_id = (int)$conn->insert_id;

            if ($er_id) {
                audit_log($conn,$spid,$userid,'exchange_rate','set',$er_id,null,
                    ['rate'=>$rate,'sod_id'=>$sod_id]);
                $msg = "✓ Exchange rate set: <strong>1 USD = $rate ZiG</strong> for this selling day.";
            } else {
                $err = "Failed to set rate: " . $conn->error;
            }
        }
    }
}

// ── Load current state ─────────────────────────────────────────────────────
// Active selling day
$active_sod_q = $conn->query("SELECT sd.*, er.amount zig_rate, er.id er_id
    FROM start_of_day sd
    LEFT JOIN exchange_rate er ON er.start_of_dayid=sd.id AND er.active=1 AND er.selling_pointid=$spid
    WHERE sd.seasonid=$seasonid AND sd.active=1 AND sd.selling_pointid=$spid
    ORDER BY sd.id DESC LIMIT 1");
$active_sod = ($active_sod_q && $active_sod_q !== true) ? $active_sod_q->fetch_assoc() : null;

// Recent selling days (last 14)
$history_q = $conn->query("SELECT sd.*, er.amount zig_rate
    FROM start_of_day sd
    LEFT JOIN exchange_rate er ON er.start_of_dayid=sd.id AND er.active=1 AND er.selling_pointid=$spid
    WHERE sd.seasonid=$seasonid AND sd.selling_pointid=$spid
    ORDER BY sd.id DESC LIMIT 14");
$history = ($history_q && $history_q !== true) ? $history_q->fetch_all(MYSQLI_ASSOC) : [];

// Latest exchange rate
$latest_rate_q = $conn->query("SELECT er.amount, sd.description sell_date
    FROM exchange_rate er
    JOIN start_of_day sd ON sd.id=er.start_of_dayid AND sd.selling_pointid=er.selling_pointid
    WHERE er.active=1 AND sd.seasonid=$seasonid AND sd.selling_pointid=$spid
    ORDER BY er.id DESC LIMIT 1");
$latest_rate = ($latest_rate_q && $latest_rate_q !== true) ? $latest_rate_q->fetch_assoc() : null;

// Active season
$season_q = $conn->query("SELECT description FROM seasons WHERE id=$seasonid LIMIT 1");
$season_name = ($season_q && $season_q !== true && $season_q->num_rows > 0)
    ? $season_q->fetch_assoc()['description'] : 'No active season';

// Sync session with active sod
if ($active_sod) {
    $_SESSION['start_of_dayid'] = (int)$active_sod['id'];
}

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left">
    <h2>Day Control</h2>
    <p>Open/close selling days and set the ZiG exchange rate · Season: <strong><?=htmlspecialchars($season_name)?></strong></p>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=$msg?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<?php if(!$seasonid):?>
<div class="alert" style="background:rgba(200,149,42,.1);border-left:3px solid var(--gold);padding:.85rem 1rem">
  ⚠ No active season. Go to <a href="settings.php?tab=seasons">Settings → Seasons</a> and activate one first.
</div>
<?php endif;?>

<!-- Status strip -->
<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
  <div class="kpi-card">
    <div class="kpi-label">Today</div>
    <div class="kpi-value" style="font-size:1rem"><?=date('d M Y')?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Selling Day Status</div>
    <?php if($active_sod):?>
    <div class="kpi-value text-leaf" style="font-size:1rem">● OPEN</div>
    <div class="kpi-sub"><?=htmlspecialchars($active_sod['description'])?> · ID <?=$active_sod['id']?></div>
    <?php else:?>
    <div class="kpi-value text-muted" style="font-size:1rem">● CLOSED</div>
    <?php endif;?>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">ZiG Rate (active)</div>
    <?php if($latest_rate):?>
    <div class="kpi-value">$<?=number_format((float)$latest_rate['amount'],4)?></div>
    <div class="kpi-sub">1 USD = <?=number_format((float)$latest_rate['amount'],2)?> ZiG</div>
    <?php else:?>
    <div class="kpi-value text-muted">Not set</div>
    <?php endif;?>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Season</div>
    <div class="kpi-value" style="font-size:.9rem"><?=htmlspecialchars($season_name)?></div>
  </div>
</div>

<div class="premium-tabs-nav" data-tabs="daycontrol">
  <button type="button" class="premium-tab-btn active" data-tab="day">📅 Selling Day</button>
  <button type="button" class="premium-tab-btn" data-tab="rate">💱 ZiG Rate</button>
  <button type="button" class="premium-tab-btn" data-tab="history">📋 History</button>
</div>

<div id="panel-day" class="tab-panel active" data-tabs="daycontrol">

<!-- Open new day -->
<?php if(!$active_sod || $active_sod['description'] !== $today):?>
<div class="card mb-3">
<div class="card-head"><h3>📅 Open Selling Day</h3></div>
<div class="card-body">
<form method="POST">
<input type="hidden" name="action" value="open_day">
<div class="form-row cols-2" style="align-items:flex-end">
  <div class="form-group" style="margin:0">
    <label>Selling Date</label>
    <input type="date" name="sell_date" class="form-control" value="<?=$today?>">
  </div>
  <button type="submit" class="btn btn-primary" style="padding:.65rem 1.25rem">
    ▶ Open Day
  </button>
</div>
</form>
</div>
</div>
<?php endif;?>

<!-- Close / manage active day -->
<?php if($active_sod):?>
<div class="card">
<div class="card-head"><h3>🔒 Manage Active Day</h3></div>
<div class="card-body" style="display:flex;gap:.5rem;flex-wrap:wrap">
<form method="POST" style="display:inline" onsubmit="return confirm('Close selling day <?=htmlspecialchars($active_sod['description'])?>?')">
  <input type="hidden" name="action" value="close_day">
  <input type="hidden" name="sod_id" value="<?=$active_sod['id']?>">
  <button type="submit" class="btn btn-danger">🔒 Close Day</button>
</form>
<div style="font-size:.78rem;color:var(--muted);align-self:center">
  Active since ID <?=$active_sod['id']?> · Date: <?=htmlspecialchars($active_sod['description'])?>
  <?=$active_sod['zig_rate']?' · Rate: '.number_format((float)$active_sod['zig_rate'],4).' ZiG/USD':'<span style="color:var(--danger)"> · ⚠ No rate set</span>'?>
</div>
</div>
</div>
<?php endif;?>
</div><!-- /panel-day -->

<!-- Set exchange rate -->
<div id="panel-rate" class="tab-panel" data-tabs="daycontrol">
<div class="card">
<div class="card-head"><h3>💱 Set ZiG Exchange Rate</h3></div>
<div class="card-body">
<?php if(!$active_sod):?>
<div class="alert" style="background:rgba(200,149,42,.08);border-left:3px solid var(--gold);font-size:.8rem;padding:.65rem .85rem;margin-bottom:.75rem">
  Open a selling day first before setting the rate.
</div>
<?php endif;?>
<form method="POST">
<input type="hidden" name="action" value="set_rate">
<input type="hidden" name="sod_id" value="<?=$active_sod?$active_sod['id']:0?>">
<div class="form-row cols-2" style="align-items:flex-end">
  <div class="form-group" style="margin:0">
    <label>1 USD = <span style="color:var(--gold)">? ZiG</span></label>
    <input type="number" name="rate" class="form-control" step="0.0001" min="0.0001"
           value="<?=$active_sod&&$active_sod['zig_rate']?number_format((float)$active_sod['zig_rate'],4,'.',''): ($latest_rate?number_format((float)$latest_rate['amount'],4,'.',''): '')?>"
           placeholder="e.g. 36.5000" required <?=!$active_sod?'disabled':''?>>
  </div>
  <button type="submit" class="btn btn-primary" style="padding:.65rem 1.25rem" <?=!$active_sod?'disabled':''?>>
    ✓ Set Rate
  </button>
</div>
<div style="font-size:.7rem;color:var(--muted);margin-top:.4rem">
  This rate is used for all balance calculations today. Linked to Start of Day ID: <strong><?=$active_sod?$active_sod['id']:'—'?></strong>
</div>
</form>
</div>
</div>
</div><!-- /panel-rate -->

<!-- Day history -->
<div id="panel-history" class="tab-panel" data-tabs="daycontrol">
<div class="card">
<div class="card-head"><h3>📋 Selling Day History</h3><span class="badge badge-gray">Last 14</span></div>
<div class="table-wrap"><table>
<thead><tr>
  <th>Date</th><th>ID</th><th class="text-right">ZiG Rate</th><th>Status</th><th></th>
</tr></thead>
<tbody>
<?php if(empty($history)):?>
<tr><td colspan="5" class="text-center text-muted" style="padding:2rem">No selling days yet. Open one to begin.</td></tr>
<?php else: foreach($history as $h):
  $is_active = (int)$h['active'] === 1;
?>
<tr style="<?=$is_active?'background:rgba(61,107,53,.06);border-left:3px solid var(--leaf)':''?>">
  <td style="font-weight:<?=$is_active?600:400?>">
    <?=htmlspecialchars($h['description'])?>
    <?=$is_active?'<span class="badge badge-green" style="font-size:.58rem;margin-left:4px">OPEN</span>':''?>
  </td>
  <td class="td-mono" style="font-size:.72rem"><?=$h['id']?></td>
  <td class="td-mono text-right">
    <?=$h['zig_rate']
      ? number_format((float)$h['zig_rate'],4).' ZiG'
      : '<span style="color:var(--danger);font-size:.7rem">Not set</span>'?>
  </td>
  <td><span class="badge <?=$is_active?'badge-green':'badge-gray'?>" style="font-size:.6rem">
    <?=$is_active?'Open':'Closed'?>
  </span></td>
  <td>
    <?php if(!$is_active):?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="reopen_day">
      <input type="hidden" name="sod_id" value="<?=$h['id']?>">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem">Reopen</button>
    </form>
    <!-- Quick rate set for historical day -->
    <form method="POST" style="display:inline;margin-left:4px" onsubmit="return confirm('Set rate for this day?')">
      <input type="hidden" name="action" value="set_rate">
      <input type="hidden" name="sod_id" value="<?=$h['id']?>">
      <input type="number" name="rate" step="0.0001" min="0.001" placeholder="Rate"
             style="width:70px;font-size:.68rem;padding:2px 5px;border:1px solid var(--border);border-radius:4px"
             value="<?=$h['zig_rate']?number_format((float)$h['zig_rate'],4,'.',''):''?>">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem">Set</button>
    </form>
    <?php endif;?>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table></div>
</div>
</div>

<?php include "includes/layout_end.php"; ?>
