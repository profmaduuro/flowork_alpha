<?php
/**
 * timb_booking.php — TIMB booking web service.
 *
 * Consumes:
 *   /bookingWebservices        query one grower's booking for a sale date
 *   /insertbookingWebservices  create a booking at TIMB
 *   /updatebookingWebservices  change one
 *   /deletebookingWebservices  remove one
 *
 * Uses the Booking & Registration credential set. Transport lives in
 * includes/timb_ws.php.
 *
 * Insert, update and delete change state on TIMB's system, so each is
 * confirmed explicitly before it is sent — nothing here syncs on its own.
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/timb_ws.php";
require_once "audit.php";
require_login();

$page_title  = "TIMB Bookings";
$active_page = "timb_booking";

$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$userid = current_userid();
$msg = $err = '';

$can_read  = has_right('growers') || has_right('floor') || has_right('admin');
$can_write = has_right('admin') || has_right('floor');

timb_ws_schema($conn);

$floor_code = timb_ws_floor_code($conn, $spid);
$today      = date('Y-m-d');

$found   = null;   // booking returned by a query
$pending = null;   // a write awaiting confirmation

/* ── Actions ───────────────────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $err = "Security token expired. Please retry.";
        $action = '';
    }
    if ($action === 'query' && !$can_read) { $err = "You do not have permission to query TIMB bookings."; $action = ''; }
    if (in_array($action, ['stage','confirm'], true) && !$can_write) {
        $err = "You do not have permission to change bookings at TIMB."; $action = '';
    }

    /* Query ------------------------------------------------------------ */
    if ($action === 'query') {
        $g = trim($_POST['grower'] ?? '');
        $d = $_POST['sale_date'] ?? $today;
        $res = timb_ws_booking_query($conn, $g, $d, $floor_code, ['spid'=>$spid, 'userid'=>$userid]);
        timb_ws_log_booking_op($conn, $spid, $userid, 'query',
            ['grower_num'=>$g, 'sell_date'=>(strtotime($d) ? date('Y-m-d', strtotime($d)) : null), 'floor'=>$floor_code], $res);

        if ($res['ok'] && $res['booking']) {
            $found = $res['booking'];
            $found['simulated'] = !empty($res['simulated']);
            $_SESSION['timb_bk_found'] = $found;
            $msg = "✓ TIMB booking " . $found['booking_id'] . " — " . $found['bales_booked'] . " bales for "
                 . ($found['sell_date'] ? date('d M Y', strtotime($found['sell_date'])) : $found['sell_date_raw'])
                 . (!empty($res['simulated']) ? " (simulated)" : "") . ".";
        } else {
            unset($_SESSION['timb_bk_found']);
            $err = "✕ " . $res['error'];
        }
    }

    /* Import a queried booking into booked_bales ----------------------- */
    if ($action === 'import' && $can_write) {
        $b = $_SESSION['timb_bk_found'] ?? null;
        if (!$b) {
            $err = "Nothing to import — run a query first.";
        } elseif (!empty($b['simulated'])) {
            $err = "That booking was simulated. It will not be written to Flowork.";
        } else {
            $ap = timb_ws_apply_booking($conn, $spid, $b, $userid);
            if ($ap['action'] === 'no_grower') {
                $err = "Grower {$b['grower_num']} is not registered at this selling point — add them first.";
            } else {
                audit_log($conn, $spid, $userid, 'booked_bales', $ap['action'], null, null,
                          ['grower'=>$b['grower_num'], 'booking_id'=>$b['booking_id'], 'bales'=>$b['bales_booked']],
                          'TIMB booking imported');
                $msg = "✓ Booking {$ap['action']} in Flowork (booked_bales #{$ap['id']}) with TIMB reference {$b['booking_id']}.";
            }
        }
    }

    /* Stage a write for confirmation ----------------------------------- */
    if ($action === 'stage') {
        $op = $_POST['op'] ?? '';
        if (!in_array($op, ['insert','update','delete'], true)) {
            $err = "Unknown operation.";
        } else {
            $pending = [
                'op'           => $op,
                'grower_num'   => trim($_POST['grower'] ?? ''),
                'sell_date'    => (strtotime($_POST['sale_date'] ?? '') ? date('Y-m-d', strtotime($_POST['sale_date'])) : ''),
                'booking_date' => (strtotime($_POST['booking_date'] ?? '') ? date('Y-m-d', strtotime($_POST['booking_date'])) : $today),
                'bales_booked' => (int)($_POST['bales_booked'] ?? 0),
                'reoffer'      => timb_ws_reoffer($_POST['reoffer'] ?? 'N'),
                'booked_by'    => trim($_POST['booked_by'] ?? ($_SESSION['username'] ?? '')),
                'floor'        => $floor_code,
                'bb_id'        => (int)($_POST['bb_id'] ?? 0),
            ];
            if ($pending['grower_num'] === '' || $pending['sell_date'] === '') {
                $err = "Grower number and sale date are required.";
                $pending = null;
            } elseif ($op !== 'delete' && $pending['bales_booked'] < 1) {
                $err = "Enter the number of bales.";
                $pending = null;
            }
        }
    }

    /* Send a confirmed write ------------------------------------------- */
    if ($action === 'confirm') {
        $op = $_POST['op'] ?? '';
        $b = [
            'grower_num'   => trim($_POST['grower'] ?? ''),
            'sell_date'    => trim($_POST['sale_date'] ?? ''),
            'booking_date' => trim($_POST['booking_date'] ?? $today),
            'bales_booked' => (int)($_POST['bales_booked'] ?? 0),
            'reoffer'      => timb_ws_reoffer($_POST['reoffer'] ?? 'N'),
            'booked_by'    => trim($_POST['booked_by'] ?? ''),
            'floor'        => $floor_code,
        ];
        $bb_id = (int)($_POST['bb_id'] ?? 0);

        if (!in_array($op, ['insert','update','delete'], true)) {
            $err = "Unknown operation.";
        } else {
            $res = timb_ws_booking_write($conn, $op, $b, ['spid'=>$spid, 'userid'=>$userid]);
            timb_ws_log_booking_op($conn, $spid, $userid, $op, $b, $res, $bb_id);
            audit_log($conn, $spid, $userid, 'timb_booking_ops', $op, null, null,
                      ['grower'=>$b['grower_num'], 'sale_date'=>$b['sell_date'], 'bales'=>$b['bales_booked'],
                       'ok'=>!empty($res['ok']), 'message'=>$res['message'] ?? ''],
                      'TIMB booking ' . $op);

            if ($res['ok']) {
                $msg = "✓ TIMB: " . ($res['message'] ?: "booking {$op} accepted")
                     . (!empty($res['simulated']) ? " (simulated — nothing was sent)" : "") . ".";
                // Reflect a delete locally only when TIMB really accepted it.
                if ($op === 'delete' && $bb_id > 0 && empty($res['simulated'])) {
                    $conn->query("UPDATE booked_bales SET booked_id='', datetime=NOW() WHERE id=$bb_id");
                    $msg .= " The Flowork booking kept its record; its TIMB reference was cleared.";
                }
            } else {
                $err = "✕ TIMB refused the {$op}: " . $res['error'];
            }
        }
    }
}

/* ── Page data ─────────────────────────────────────────────────────────── */

$mock_active = timb_ws_is_mock($conn, 'booking');
$found = $found ?: ($_SESSION['timb_bk_found'] ?? null);

// Local bookings, newest first, with their TIMB reference state.
$local_q = $conn->query("
    SELECT bb.id, bb.sell_date, bb.book_date, bb.bales_booked, bb.booked_id, bb.reoffer, bb.booked_by,
           g.grower_num, g.name, g.surname
    FROM booked_bales bb
    JOIN growers g ON g.id = bb.growerid
    WHERE g.selling_pointid = $spid
    ORDER BY bb.sell_date DESC, bb.id DESC LIMIT 60");
$local = ($local_q && $local_q !== true) ? $local_q->fetch_all(MYSQLI_ASSOC) : [];

$ops_q = $conn->query("SELECT o.*, u.username FROM timb_booking_ops o
                       LEFT JOIN users u ON u.id=o.userid
                       WHERE o.selling_pointid=$spid ORDER BY o.id DESC LIMIT 60");
$ops = ($ops_q && $ops_q !== true) ? $ops_q->fetch_all(MYSQLI_ASSOC) : [];

$log_q = $conn->query("SELECT l.*, u.username FROM timb_service_log l
                       LEFT JOIN users u ON u.id=l.userid
                       WHERE l.selling_pointid=$spid AND l.service LIKE 'booking%'
                       ORDER BY l.id DESC LIMIT 40");
$logs = ($log_q && $log_q !== true) ? $log_q->fetch_all(MYSQLI_ASSOC) : [];

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>TIMB Bookings</h2>
    <p>Query and maintain grower bookings on T.I.M.B.</p>
  </div>
  <div>
    <span class="badge <?= $mock_active ? 'badge-gold' : 'badge-green' ?>">
      <?= $mock_active ? '◌ Simulation mode' : '● Live — booking credentials' ?>
    </span>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<?php if(!$floor_code):?>
<div class="alert alert-danger">
  <strong>No floor code set for this selling point.</strong> Every booking call must carry it
  (TIMB's <code>floor</code> parameter). Set it on the selling point before using this page.
</div>
<?php endif;?>

<?php if($mock_active):?>
<div class="alert alert-gold">
  <strong>Simulation mode.</strong> No Booking &amp; Registration credentials are configured, so nothing
  reaches TIMB and simulated bookings are not written into Flowork.
  Set them under <a href="timb_growermaster.php?tab=settings">TIMB settings</a> to go live.
</div>
<?php endif;?>

<?php if($pending): /* ── Confirmation step ──────────────────────────── */ ?>
<div class="card" style="border:2px solid var(--<?= $pending['op']==='delete' ? 'danger' : 'gold' ?>)">
  <div class="card-head">
    <h3><?= $pending['op']==='delete' ? '⚠ Confirm deletion at TIMB' : 'Confirm ' . htmlspecialchars($pending['op']) . ' at TIMB' ?></h3>
  </div>
  <div class="card-body">
    <p style="font-size:.82rem;margin-bottom:1rem">
      <?php if($pending['op']==='delete'):?>
        This <strong>removes the grower's booking from TIMB's system</strong>. It affects the regulator's
        records, not just Flowork, and cannot be undone from here.
      <?php else:?>
        This writes to TIMB's booking system under floor <strong><?=htmlspecialchars($floor_code)?></strong>.
      <?php endif;?>
    </p>
    <div class="table-wrap"><table>
      <tbody>
        <tr><th style="width:180px">Grower</th><td class="td-mono"><?=htmlspecialchars($pending['grower_num'])?></td></tr>
        <tr><th>Sale date</th><td class="td-mono"><?=htmlspecialchars(date('d M Y', strtotime($pending['sell_date'])))?>
            <span style="color:var(--muted);font-size:.72rem">→ sent as <?=htmlspecialchars(timb_ws_timb_date($conn,$pending['sell_date']))?></span></td></tr>
        <tr><th>Booking date</th><td class="td-mono"><?=htmlspecialchars(date('d M Y', strtotime($pending['booking_date'])))?></td></tr>
        <tr><th>Bales</th><td class="td-mono"><?=(int)$pending['bales_booked']?></td></tr>
        <tr><th>Reoffer</th><td class="td-mono"><?=htmlspecialchars($pending['reoffer'])?></td></tr>
        <tr><th>Booked by</th><td class="td-mono"><?=htmlspecialchars($pending['booked_by'])?></td></tr>
        <tr><th>Floor</th><td class="td-mono"><?=htmlspecialchars($floor_code)?></td></tr>
      </tbody>
    </table></div>
    <form method="POST" style="margin-top:1rem;display:flex;gap:.5rem">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="confirm">
      <input type="hidden" name="op" value="<?=htmlspecialchars($pending['op'])?>">
      <input type="hidden" name="grower" value="<?=htmlspecialchars($pending['grower_num'])?>">
      <input type="hidden" name="sale_date" value="<?=htmlspecialchars($pending['sell_date'])?>">
      <input type="hidden" name="booking_date" value="<?=htmlspecialchars($pending['booking_date'])?>">
      <input type="hidden" name="bales_booked" value="<?=(int)$pending['bales_booked']?>">
      <input type="hidden" name="reoffer" value="<?=htmlspecialchars($pending['reoffer'])?>">
      <input type="hidden" name="booked_by" value="<?=htmlspecialchars($pending['booked_by'])?>">
      <input type="hidden" name="bb_id" value="<?=(int)$pending['bb_id']?>">
      <button class="btn <?= $pending['op']==='delete' ? 'btn-danger' : 'btn-primary' ?>">
        Yes — send <?=htmlspecialchars($pending['op'])?> to TIMB
      </button>
      <a href="timb_booking.php" class="btn btn-ghost">Cancel</a>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="premium-tabs-nav" data-tabs="timbbk">
  <button type="button" class="premium-tab-btn" data-tab="query">🔍 Query Booking</button>
  <button type="button" class="premium-tab-btn" data-tab="manage">✎ Create / Change</button>
  <button type="button" class="premium-tab-btn" data-tab="local">📋 Flowork Bookings <span class="badge badge-gray"><?=count($local)?></span></button>
  <button type="button" class="premium-tab-btn" data-tab="ops">🕘 Operations</button>
  <button type="button" class="premium-tab-btn" data-tab="log">🧾 Service Log</button>
</div>

<!-- ══ Query ═══════════════════════════════════════════════════════════ -->
<div id="panel-query" class="tab-panel active" data-tabs="timbbk">
<div class="card">
  <div class="card-head"><h3>Query a grower's booking</h3><span class="badge badge-gray">POST /bookingWebservices</span></div>
  <div class="card-body">
    <form method="POST" class="form-row" style="align-items:flex-end;gap:.5rem">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="query">
      <div class="form-group" style="margin-bottom:0;flex:1">
        <label>Grower number</label>
        <input type="text" name="grower" class="form-control" placeholder="V122512" style="text-transform:uppercase"
               value="<?=htmlspecialchars($_POST['grower'] ?? '')?>" required>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label>Sale date</label>
        <input type="date" name="sale_date" class="form-control" value="<?=htmlspecialchars($_POST['sale_date'] ?? $today)?>" required>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label>Floor</label>
        <input type="text" class="form-control" value="<?=htmlspecialchars($floor_code ?: '— not set —')?>" disabled style="width:110px">
      </div>
      <button class="btn btn-primary" <?= ($can_read && $floor_code) ? '' : 'disabled' ?>>Query TIMB</button>
    </form>
    <p style="font-size:.72rem;color:var(--muted);margin-top:.75rem">
      TIMB serve bookings one grower at a time — there is no bulk query.
    </p>
  </div>
</div>

<?php if($found):?>
<div class="card">
  <div class="card-head">
    <h3>Booking at TIMB</h3>
    <span class="badge <?= !empty($found['simulated']) ? 'badge-gold' : 'badge-green' ?>">
      <?= !empty($found['simulated']) ? 'SIMULATED' : 'from TIMB' ?>
    </span>
  </div>
  <div class="table-wrap"><table>
    <tbody>
      <tr><th style="width:180px">Booking ID</th><td class="td-mono"><strong><?=htmlspecialchars($found['booking_id'])?></strong></td></tr>
      <tr><th>Grower</th><td class="td-mono"><?=htmlspecialchars($found['grower_num'])?></td></tr>
      <tr><th>Bales booked</th><td class="td-mono"><?=(int)$found['bales_booked']?></td></tr>
      <tr><th>Sale date</th><td class="td-mono"><?=htmlspecialchars($found['sell_date'] ?: $found['sell_date_raw'])?></td></tr>
      <tr><th>Booking date</th><td class="td-mono"><?=htmlspecialchars($found['booking_date'])?></td></tr>
      <tr><th>Booked by</th><td class="td-mono"><?=htmlspecialchars($found['booked_by'])?></td></tr>
      <tr><th>Floor</th><td class="td-mono"><?=htmlspecialchars($found['floor'])?></td></tr>
      <tr><th>Reoffer</th><td class="td-mono"><?=htmlspecialchars($found['reoffer'])?></td></tr>
    </tbody>
  </table></div>
  <div class="card-body" style="border-top:1px solid var(--border)">
    <?php if(!empty($found['simulated'])):?>
      <p style="font-size:.78rem;color:var(--muted);margin:0">
        Simulated bookings are not written into Flowork.
      </p>
    <?php else:?>
      <form method="POST">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="import">
        <button class="btn btn-primary" <?=$can_write?'':'disabled'?>>Import into Flowork</button>
        <span style="font-size:.75rem;color:var(--muted);margin-left:.5rem">
          Matches on grower and sale date; an existing booking keeps its record and gains TIMB's reference.
        </span>
      </form>
    <?php endif;?>
  </div>
</div>
<?php endif;?>
</div><!-- /panel-query -->

<!-- ══ Manage ══════════════════════════════════════════════════════════ -->
<div id="panel-manage" class="tab-panel" data-tabs="timbbk">
<div class="card">
  <div class="card-head"><h3>Create or change a booking at TIMB</h3>
    <span class="badge badge-gray">insert / update / delete</span></div>
  <div class="card-body">
    <div class="alert alert-info" style="font-size:.75rem;margin-bottom:1rem">
      These write to TIMB's system. Every one shows you exactly what will be sent and waits for
      confirmation first — nothing is pushed automatically.
    </div>
    <form method="POST">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="stage">
      <div class="form-row">
        <div class="form-group">
          <label>Grower number</label>
          <input type="text" name="grower" class="form-control" style="text-transform:uppercase" placeholder="V255368" required>
        </div>
        <div class="form-group">
          <label>Sale date</label>
          <input type="date" name="sale_date" class="form-control" value="<?=htmlspecialchars($today)?>" required>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Booking date</label>
          <input type="date" name="booking_date" class="form-control" value="<?=htmlspecialchars($today)?>">
        </div>
        <div class="form-group">
          <label>Bales booked</label>
          <input type="number" name="bales_booked" class="form-control" min="0" value="0">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Reoffer</label>
          <select name="reoffer" class="form-control">
            <option value="N">N — new offering</option>
            <option value="Y">Y — reoffer</option>
          </select>
        </div>
        <div class="form-group">
          <label>Booked by</label>
          <input type="text" name="booked_by" class="form-control" value="<?=htmlspecialchars($_SESSION['username'] ?? '')?>">
        </div>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button name="op" value="insert" class="btn btn-primary" <?=($can_write&&$floor_code)?'':'disabled'?>>Insert at TIMB</button>
        <button name="op" value="update" class="btn btn-ghost"   <?=($can_write&&$floor_code)?'':'disabled'?>>Update at TIMB</button>
        <button name="op" value="delete" class="btn btn-danger"  <?=($can_write&&$floor_code)?'':'disabled'?>>Delete at TIMB</button>
      </div>
    </form>
  </div>
</div>
</div><!-- /panel-manage -->

<!-- ══ Local bookings ══════════════════════════════════════════════════ -->
<div id="panel-local" class="tab-panel" data-tabs="timbbk">
<div class="card">
  <div class="card-head"><h3>Bookings held in Flowork</h3><span class="badge badge-gray"><?=count($local)?> shown</span></div>
  <?php if(!$local):?>
    <div class="card-body text-center text-muted">No bookings at this selling point.</div>
  <?php else:?>
  <div class="table-wrap" style="max-height:600px;overflow-y:auto"><table>
    <thead><tr>
      <th>Grower</th><th>Name</th><th class="text-right">Bales</th><th>Sale date</th>
      <th>TIMB reference</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach($local as $b):
      $ref = trim((string)$b['booked_id']);
      $is_manual = ($ref === '' || stripos($ref, 'MAN-') === 0);
    ?>
      <tr>
        <td class="td-mono"><strong><?=htmlspecialchars($b['grower_num'])?></strong></td>
        <td style="font-size:.78rem"><?=htmlspecialchars(trim($b['name'].' '.$b['surname']))?></td>
        <td class="td-mono text-right"><?=(int)$b['bales_booked']?></td>
        <td class="td-mono" style="font-size:.75rem"><?=htmlspecialchars($b['sell_date'])?></td>
        <td class="td-mono" style="font-size:.72rem">
          <?php if($ref===''):?>
            <span style="color:var(--muted)">— none —</span>
          <?php elseif($is_manual):?>
            <?=htmlspecialchars($ref)?> <span class="badge badge-gold" style="font-size:.55rem">local</span>
          <?php else:?>
            <?=htmlspecialchars($ref)?>
          <?php endif;?>
        </td>
        <td>
          <?php if($is_manual):?>
          <form method="POST" style="display:inline">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="query">
            <input type="hidden" name="grower" value="<?=htmlspecialchars($b['grower_num'])?>">
            <input type="hidden" name="sale_date" value="<?=htmlspecialchars($b['sell_date'])?>">
            <button class="btn btn-ghost btn-sm" style="font-size:.65rem" <?=($can_read&&$floor_code)?'':'disabled'?>>Get TIMB ref</button>
          </form>
          <?php endif;?>
        </td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <div class="card-body" style="border-top:1px solid var(--border);font-size:.72rem;color:var(--muted)">
    A <span class="badge badge-gold" style="font-size:.55rem">local</span> reference was generated in Flowork,
    not issued by TIMB. Query the booking to replace it with the real <code>bookingID</code>.
  </div>
  <?php endif;?>
</div>
</div><!-- /panel-local -->

<!-- ══ Operations ══════════════════════════════════════════════════════ -->
<div id="panel-ops" class="tab-panel" data-tabs="timbbk">
<div class="card">
  <div class="card-head"><h3>Booking operations</h3><span class="badge badge-gray"><?=count($ops)?> recorded</span></div>
  <?php if(!$ops):?>
    <div class="card-body text-center text-muted">Nothing yet.</div>
  <?php else:?>
  <div class="table-wrap" style="max-height:600px;overflow-y:auto"><table>
    <thead><tr><th>When</th><th>Operation</th><th>Grower</th><th>Sale date</th><th class="text-right">Bales</th>
      <th>Outcome</th><th>User</th></tr></thead>
    <tbody>
    <?php foreach($ops as $o):?>
      <tr>
        <td style="font-size:.7rem;color:var(--muted);white-space:nowrap"><?=htmlspecialchars(date('d M H:i', strtotime($o['created_at'])))?></td>
        <td><span class="badge <?= $o['operation']==='delete'?'badge-red':($o['operation']==='query'?'badge-gray':'badge-blue') ?>"
              style="font-size:.6rem"><?=htmlspecialchars(strtoupper($o['operation']))?></span>
            <?= $o['simulated'] ? ' <span class="badge badge-gold" style="font-size:.55rem">sim</span>' : '' ?></td>
        <td class="td-mono" style="font-size:.75rem"><?=htmlspecialchars($o['grower_num'])?></td>
        <td class="td-mono" style="font-size:.72rem"><?=htmlspecialchars((string)$o['sale_date'])?></td>
        <td class="td-mono text-right" style="font-size:.72rem"><?=(int)$o['bales_booked']?></td>
        <td style="font-size:.72rem">
          <?= $o['ok'] ? '<span class="badge badge-green">OK</span>' : '<span class="badge badge-red">FAIL</span>' ?>
          <?php if($o['message']):?>
            <div style="font-size:.66rem;color:<?= $o['ok']?'var(--muted)':'var(--danger)' ?>;margin-top:.2rem">
              <?=htmlspecialchars($o['message'])?></div>
          <?php endif;?>
          <?php if($o['booking_id']):?>
            <div style="font-size:.66rem;color:var(--muted);font-family:monospace"><?=htmlspecialchars($o['booking_id'])?></div>
          <?php endif;?>
        </td>
        <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars((string)($o['username'] ?? ''))?></td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?php endif;?>
</div>
</div><!-- /panel-ops -->

<!-- ══ Service log ═════════════════════════════════════════════════════ -->
<div id="panel-log" class="tab-panel" data-tabs="timbbk">
<div class="card">
  <div class="card-head"><h3>Booking service log</h3><span class="badge badge-gray">last <?=count($logs)?> calls</span></div>
  <?php if(!$logs):?>
    <div class="card-body text-center text-muted">No calls yet.</div>
  <?php else:?>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Service</th><th>Result</th><th class="text-right">HTTP</th><th class="text-right">Time</th><th>User</th><th></th></tr></thead>
    <tbody>
    <?php foreach($logs as $l):?>
      <tr>
        <td style="font-size:.7rem;color:var(--muted);white-space:nowrap"><?=htmlspecialchars(date('d M H:i:s', strtotime($l['created_at'])))?></td>
        <td style="font-size:.72rem"><?=htmlspecialchars($l['service'])?><?= $l['simulated'] ? ' <span class="badge badge-gold" style="font-size:.58rem">sim</span>' : '' ?></td>
        <td style="font-size:.72rem">
          <?php if($l['ok']):?><span class="badge badge-green">OK</span>
          <?php else:?><span class="badge badge-red">FAIL</span>
            <div style="font-size:.66rem;color:var(--danger);margin-top:.25rem"><?=htmlspecialchars((string)$l['error_message'])?></div>
          <?php endif;?>
        </td>
        <td class="td-mono text-right" style="font-size:.72rem"><?=(int)$l['http_code']?></td>
        <td class="td-mono text-right" style="font-size:.72rem"><?=(int)$l['duration_ms']?>ms<?= (int)$l['attempts']>1 ? ' ·'.(int)$l['attempts'].'×' : '' ?></td>
        <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars((string)($l['username'] ?? ''))?></td>
        <td><button type="button" class="btn btn-ghost btn-sm" style="font-size:.65rem"
                    onclick="var d=document.getElementById('bklog<?=(int)$l['id']?>');d.hidden=!d.hidden">raw</button></td>
      </tr>
      <tr id="bklog<?=(int)$l['id']?>" hidden>
        <td colspan="7" style="background:var(--cream-d);padding:.75rem">
          <div style="font-size:.68rem;color:var(--muted);margin-bottom:.25rem">REQUEST — <?=htmlspecialchars((string)$l['url'])?></div>
          <pre style="font-size:.68rem;white-space:pre-wrap;word-break:break-all;margin:0 0 .6rem"><?=htmlspecialchars((string)$l['request_params'])?></pre>
          <div style="font-size:.68rem;color:var(--muted);margin-bottom:.25rem">RESPONSE</div>
          <pre style="font-size:.68rem;white-space:pre-wrap;word-break:break-all;max-height:280px;overflow:auto;margin:0"><?=htmlspecialchars((string)$l['response_body'])?></pre>
        </td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?php endif;?>
</div>
</div><!-- /panel-log -->

<?php include "includes/layout_end.php"; ?>
