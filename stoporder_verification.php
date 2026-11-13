<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title = "SO Verification"; $active_page = "stoporder_verification";
$userid = current_userid(); $spid = (int)($_SESSION['selling_pointid'] ?? 0);
$is_admin = (int)($_SESSION['is_admin'] ?? 0) === 1 || ($_SESSION['role'] ?? '') === 'admin';
$msg = $err = '';

// ── Check tables ─────────────────────────────────────────────────────────
$has_iso = $conn->query("SHOW TABLES LIKE 'internal_stoporders'")->num_rows > 0;
$has_ext = $conn->query("SHOW TABLES LIKE 'stoporders'")->num_rows > 0;

if ($has_ext) {
    $cols = [];
    $res = $conn->query("DESCRIBE stoporders");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $cols[] = strtolower($r['Field']);
        }
    }
    if (!in_array('active', $cols)) {
        $conn->query("ALTER TABLE stoporders ADD COLUMN active TINYINT DEFAULT 1");
    }
    if (!in_array('processed', $cols)) {
        $conn->query("ALTER TABLE stoporders ADD COLUMN processed TINYINT DEFAULT 0");
    }
}

// ── POST actions ──────────────────────────────────────────────────────────
// Stop orders are money owed to creditors, so every change is limited to the
// user's floor (through the grower) and written to the audit log.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$is_admin) {
        $err = "Access Denied: Only administrators can modify stop orders.";
    } else {
        $action = $_POST['action'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);
        $src    = $_POST['src'] ?? 'timb'; // 'timb', 'internal', or 'ext'

        // source => [table, amount column, text column, form field for the text, label]
        $so_sources = ['timb' => ['stoporders_timb', 'amount', 'reference', 'reference', 'Stop order']];
        if ($has_ext) $so_sources['ext']      = ['stoporders', 'amount_1', 'creditor_ref', 'reference', 'Stop order'];
        if ($has_iso) $so_sources['internal'] = ['internal_stoporders', 'amount', 'description', 'description', 'Internal SO'];

        if (isset($so_sources[$src]) && in_array($action, ['edit_so', 'cancel_so', 'restore_so', 'delete_so'], true)) {
            [$tbl, $amt_col, $txt_col, $txt_field, $label] = $so_sources[$src];
            $row = $conn->query("SELECT so.* FROM `$tbl` so JOIN growers g ON g.id = so.growerid
                WHERE so.id = $id AND g.selling_pointid = $spid LIMIT 1");
            $row = ($row && $row !== true) ? $row->fetch_assoc() : null;
            if (!$row) {
                $err = "$label not found on this floor.";
            } elseif ($action === 'edit_so') {
                $amt = (float)($_POST['amount'] ?? 0);
                $txt = trim($_POST[$txt_field] ?? '');
                if ($amt <= 0) {
                    $err = "Amount must be greater than 0.";
                } else {
                    $txt_e = $conn->real_escape_string($txt);
                    $conn->query("UPDATE `$tbl` SET `$amt_col`=$amt, `$txt_col`='$txt_e' WHERE id=$id");
                    audit_log($conn, $spid, $userid, $tbl, 'update', $id,
                        [$amt_col => $row[$amt_col], $txt_col => $row[$txt_col]], [$amt_col => $amt, $txt_col => $txt]);
                    $msg = "$label updated.";
                }
            } elseif ($action === 'cancel_so' || $action === 'restore_so') {
                $active = $action === 'restore_so' ? 1 : 0;
                $conn->query("UPDATE `$tbl` SET active=$active WHERE id=$id");
                audit_log($conn, $spid, $userid, $tbl, $active ? 'restore' : 'cancel', $id,
                    ['active' => $row['active']], ['active' => $active]);
                $msg = "$label " . ($active ? 'restored' : 'cancelled') . ".";
            } else {
                $conn->query("DELETE FROM `$tbl` WHERE id=$id");
                audit_log($conn, $spid, $userid, $tbl, 'delete', $id, $row, null);
                $msg = "$label deleted.";
            }
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────
$search       = $conn->real_escape_string(trim($_GET['q']    ?? ''));
$date_f       = $conn->real_escape_string(trim($_GET['date'] ?? ''));
$show_inactive= !empty($_GET['show_inactive']);
$filter_type  = $_GET['type'] ?? 'all'; // all | timb | ext | internal
$edit_id      = (int)($_GET['edit'] ?? 0);
$edit_src     = $_GET['edit_src'] ?? 'timb';

// ── Load TIMB stop orders (stoporders_timb) ───────────────────────────────
$timb_where = ($spid > 0) ? "g.selling_pointid=$spid" : "1=1";
if ($search) $timb_where .= " AND (g.grower_num LIKE '%$search%' OR g.name LIKE '%$search%' OR st.reference LIKE '%$search%')";
if ($date_f) $timb_where .= " AND st.sell_date='$date_f'";
if (!$show_inactive) $timb_where .= " AND st.active=1";

$timb_rows = [];
$timb_q = $conn->query("
    SELECT st.id, st.amount, st.reference, st.sell_date, st.active,
           g.grower_num, CONCAT(g.name,' ',g.surname) AS grower_name
    FROM stoporders_timb st
    JOIN growers g ON g.id = st.growerid
    WHERE $timb_where
    ORDER BY st.sell_date DESC, g.grower_num ASC
");
while ($timb_q && $r = $timb_q->fetch_assoc()) {
    $r['src']        = 'timb';
    $r['type_label'] = 'TIMB Global';
    $r['description']= $r['reference'] ?? '';
    $r['charge_type']= '';
    $timb_rows[] = $r;
}

// ── Load decrypted external stop orders (stoporders table) ────────────────
$ext_rows = [];
if ($has_ext) {
    $ext_where = ($spid > 0) ? "g.selling_pointid=$spid" : "1=1";
    if ($search) $ext_where .= " AND (g.grower_num LIKE '%$search%' OR g.name LIKE '%$search%' OR so.creditor_no LIKE '%$search%' OR so.creditor_ref LIKE '%$search%')";
    if ($date_f) $ext_where .= " AND (so.formatted_sos_date='$date_f' OR so.sos_date='$date_f')";
    if (!$show_inactive) $ext_where .= " AND so.active=1";

    $ext_q = $conn->query("
        SELECT so.id, so.amount_1 AS amount, so.creditor_ref AS reference,
               so.formatted_sos_date AS sell_date, so.active, so.processed,
               so.creditor_no, so.priority,
               g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) AS grower_name
        FROM stoporders so
        JOIN growers g ON g.id = so.growerid
        WHERE $ext_where
        ORDER BY so.formatted_sos_date DESC, g.grower_num ASC
    ");
    while ($ext_q && $r = $ext_q->fetch_assoc()) {
        $r['src']        = 'ext';
        $r['type_label'] = 'TIMB Decrypted';
        $r['description']= trim(($r['creditor_no']??'').' '.($r['reference']??''));
        $r['charge_type']= '';
        $ext_rows[] = $r;
    }
}

// ── Load internal stop orders ─────────────────────────────────────────────
$iso_rows = [];
if ($has_iso) {
    $iso_where = ($spid > 0) ? "g.selling_pointid=$spid" : "1=1";
    if ($search) $iso_where .= " AND (g.grower_num LIKE '%$search%' OR g.name LIKE '%$search%' OR iso.description LIKE '%$search%' OR iso.charge_type LIKE '%$search%')";
    if ($date_f) $iso_where .= " AND iso.sell_date='$date_f'";
    if (!$show_inactive) $iso_where .= " AND iso.active=1";

    $iso_q = $conn->query("
        SELECT iso.id, iso.amount, iso.sell_date, iso.active,
               iso.description, iso.charge_type, iso.splitid,
               g.grower_num, CONCAT(g.name,' ',g.surname) AS grower_name
        FROM internal_stoporders iso
        JOIN growers g ON g.id = iso.growerid
        WHERE $iso_where
        ORDER BY iso.sell_date DESC, g.grower_num ASC
    ");
    while ($iso_q && $r = $iso_q->fetch_assoc()) {
        $r['src']        = 'internal';
        $r['type_label'] = 'Internal';
        $r['reference']  = '';
        $iso_rows[] = $r;
    }
}

// ── Merge & filter by type ────────────────────────────────────────────────
$all_rows = [];
if ($filter_type === 'all' || $filter_type === 'timb')     $all_rows = array_merge($all_rows, $timb_rows);
if ($filter_type === 'all' || $filter_type === 'ext')      $all_rows = array_merge($all_rows, $ext_rows);
if ($filter_type === 'all' || $filter_type === 'internal') $all_rows = array_merge($all_rows, $iso_rows);

// Sort merged: sell_date DESC, grower_num ASC
usort($all_rows, function($a, $b) {
    $d = strcmp($b['sell_date'] ?? '', $a['sell_date'] ?? '');
    return $d !== 0 ? $d : strcmp($a['grower_num'], $b['grower_num']);
});

// ── KPI totals ────────────────────────────────────────────────────────────
$timb_cond = ($spid > 0) ? "g.selling_pointid=$spid" : "1=1";
$timb_total_res = $conn->query("SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) total FROM stoporders_timb st JOIN growers g ON g.id=st.growerid WHERE $timb_cond AND st.active=1");
$timb_total_q   = ($timb_total_res && $timb_total_res !== true) ? $timb_total_res->fetch_assoc() : ['cnt'=>0,'total'=>0];

$ext_cond = ($spid > 0) ? "g.selling_pointid=$spid" : "1=1";
$ext_total_res  = $has_ext ? $conn->query("SELECT COUNT(*) cnt, COALESCE(SUM(amount_1),0) total FROM stoporders so JOIN growers g ON g.id=so.growerid WHERE $ext_cond AND so.active=1") : null;
$ext_total_q    = ($ext_total_res && $ext_total_res !== true) ? $ext_total_res->fetch_assoc() : ['cnt'=>0,'total'=>0];

$iso_cond = ($spid > 0) ? "g.selling_pointid=$spid" : "1=1";
$iso_total_res  = $has_iso ? $conn->query("SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) total FROM internal_stoporders iso JOIN growers g ON g.id=iso.growerid WHERE $iso_cond AND iso.active=1") : null;
$iso_total_q    = ($iso_total_res && $iso_total_res !== true) ? $iso_total_res->fetch_assoc() : ['cnt'=>0,'total'=>0];

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Stop Order Verification</h2>
    <p>View, edit and cancel TIMB global and internal stop orders</p>
  </div>
  <a href="stoporder_processing.php" class="btn btn-ghost">+ Import from TIMB</a>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- KPI strip -->
<div class="kpi-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.25rem">
  <div class="kpi-card" style="--kpi-color:var(--danger)">
    <div class="kpi-label">TIMB Global SOs</div>
    <div class="kpi-value"><?=(int)$timb_total_q['cnt']?></div>
    <div class="kpi-sub">$<?=number_format((float)$timb_total_q['total'],2)?></div>
  </div>
  <div class="kpi-card" style="--kpi-color:#8e44ad">
    <div class="kpi-label">TIMB Decrypted</div>
    <div class="kpi-value"><?=(int)$ext_total_q['cnt']?></div>
    <div class="kpi-sub">$<?=number_format((float)$ext_total_q['total'],2)?></div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--gold)">
    <div class="kpi-label">Internal SOs</div>
    <div class="kpi-value"><?=(int)$iso_total_q['cnt']?></div>
    <div class="kpi-sub">$<?=number_format((float)$iso_total_q['total'],2)?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Combined Total</div>
    <div class="kpi-value sm">$<?=number_format((float)$timb_total_q['total']+(float)$ext_total_q['total']+(float)$iso_total_q['total'],2)?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Showing</div>
    <div class="kpi-value"><?=count($all_rows)?></div>
    <div class="kpi-sub">with current filters</div>
  </div>
</div>

<!-- Filter bar -->
<div class="card mb-3">
<div class="card-body" style="padding:0.65rem 1.15rem">
<form method="GET" style="display:flex;gap:0.5rem;align-items:flex-end;flex-wrap:wrap">
  <div class="form-group" style="margin:0;flex:1;min-width:160px">
    <label style="font-size:.72rem">Search</label>
    <input type="text" name="q" class="form-control" value="<?=htmlspecialchars($search)?>"
           placeholder="Grower #, name, description…" style="padding:0.35rem 0.6rem">
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.72rem">Sale Date</label>
    <input type="date" name="date" class="form-control" value="<?=htmlspecialchars($date_f)?>"
           style="padding:0.35rem 0.6rem" onchange="this.form.submit()">
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.72rem">Type</label>
    <select name="type" class="form-control" style="padding:0.35rem 0.6rem" onchange="this.form.submit()">
      <option value="all"      <?=$filter_type==='all'?'selected':''?>>All types</option>
      <option value="timb"     <?=$filter_type==='timb'?'selected':''?>>TIMB Global only</option>
      <option value="ext"      <?=$filter_type==='ext'?'selected':''?>>TIMB Decrypted only</option>
      <option value="internal" <?=$filter_type==='internal'?'selected':''?>>Internal only</option>
    </select>
  </div>
  <div style="display:flex;align-items:flex-end;gap:0.5rem">
    <label style="font-size:0.72rem;display:flex;align-items:center;gap:4px;color:var(--muted);cursor:pointer;margin-bottom:0;padding-bottom:2px">
      <input type="checkbox" name="show_inactive" value="1"
             <?=$show_inactive?'checked':''?> onchange="this.form.submit()"> Show cancelled
    </label>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    <?php if($search||$date_f||$filter_type!=='all'||$show_inactive):?>
    <a href="stoporder_verification.php" class="btn btn-ghost btn-sm">Reset</a>
    <?php endif;?>
  </div>
</form>
</div>
</div>

<!-- Main table -->
<div class="card">
<div class="card-head">
  <h3>Stop Orders</h3>
  <span class="badge badge-gray"><?=count($all_rows)?></span>
</div>
<div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
<thead><tr>
  <th>Type</th>
  <th>Grower #</th>
  <th>Grower Name</th>
  <th>Description / Charge</th>
  <th class="text-right">Amount (USD)</th>
  <th>Sale Date</th>
  <th>Status</th>
  <th style="min-width:160px"></th>
</tr></thead>
<tbody>
<?php if(!empty($all_rows)): foreach($all_rows as $r):
  $is_edit  = ($edit_id == $r['id'] && $edit_src === $r['src']);
  $is_timb  = ($r['src'] === 'timb');
  $is_ext   = ($r['src'] === 'ext');
  $is_active= (bool)$r['active'];
  $is_processed = !empty($r['processed']);
  $qs       = "?".http_build_query(array_filter(['q'=>$search,'date'=>$date_f,'type'=>$filter_type,'show_inactive'=>$show_inactive?1:null]));
?>
<tr style="<?=$is_edit?'background:var(--gold-bg)':(!$is_active?'opacity:.55':'')?>">

  <!-- TYPE BADGE -->
  <td>
    <?php if($is_timb): ?>
    <span class="badge" style="background:var(--surface-2);border:1px solid rgba(192,57,43,.3);
          color:var(--danger);font-size:.72rem;white-space:nowrap">
      🌐 TIMB
    </span>
    <?php elseif($is_ext): ?>
    <span class="badge" style="background:var(--info-tint);border:1px solid rgba(142,68,173,.3);
          color:var(--info-text);font-size:.72rem;white-space:nowrap">
      🔓 Decrypted
    </span>
    <?php else: ?>
    <span class="badge" style="background:var(--gold-bg);border:1px solid rgba(200,149,42,.3);
          color:var(--warn-text);font-size:.72rem;white-space:nowrap">
      🏠 Internal
    </span>
    <?php endif; ?>
  </td>

  <!-- GROWER -->
  <td><span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($r['grower_num'])?></span></td>
  <td style="font-size:.82rem;font-weight:500"><?=htmlspecialchars($r['grower_name'])?></td>

  <!-- DESCRIPTION -->
  <td style="font-size:.78rem">
    <?php if($is_timb): ?>
      <span class="td-muted"><?=htmlspecialchars($r['reference'] ?? '—')?></span>
    <?php else: ?>
      <div><?=htmlspecialchars($r['description'] ?? '—')?></div>
      <?php if($r['charge_type']): ?>
      <div style="font-size:.68rem;color:var(--muted)"><?=htmlspecialchars($r['charge_type'])?></div>
      <?php endif; ?>
    <?php endif; ?>
  </td>

  <!-- AMOUNT — inline edit form or display -->
  <td class="text-right">
    <?php if($is_edit): ?>
    <form method="POST" style="display:flex;gap:4px;justify-content:flex-end;align-items:center">
      <input type="hidden" name="action" value="edit_so">
      <input type="hidden" name="id" value="<?=$r['id']?>">
      <input type="hidden" name="src" value="<?=$r['src']?>">
      <input type="number" name="amount" value="<?=$r['amount']?>" step="0.0001" min="0.0001" required
             style="width:90px;text-align:right;font-size:.82rem;padding:2px 4px;
                    border:1.5px solid var(--gold);border-radius:3px;font-family:monospace" autofocus>
      <?php if($is_timb): ?>
      <input type="text" name="reference" value="<?=htmlspecialchars($r['reference']??'')?>" placeholder="Ref"
             style="width:100px;font-size:.75rem;padding:2px 4px;border:1px solid var(--border);border-radius:3px">
      <?php else: ?>
      <input type="text" name="description" value="<?=htmlspecialchars($r['description']??'')?>" placeholder="Description"
             style="width:110px;font-size:.75rem;padding:2px 4px;border:1px solid var(--border);border-radius:3px">
      <?php endif; ?>
      <button type="submit" class="btn btn-gold btn-sm" style="padding:2px 8px">✓</button>
      <a href="stoporder_verification.php<?=$qs?>" class="btn btn-ghost btn-sm" style="padding:2px 6px">✕</a>
    </form>
    <?php else: ?>
    <span class="td-mono fw-500">$<?=number_format((float)$r['amount'],4)?></span>
    <?php endif; ?>
  </td>

  <!-- SELL DATE -->
  <td class="td-mono" style="font-size:.75rem">
    <?=$r['sell_date']?date('d M Y',strtotime($r['sell_date'])):'—'?>
  </td>

  <!-- STATUS -->
  <td>
    <?php if($is_processed): ?>
    <span class="badge" style="background:#e8daef;border:1px solid rgba(142,68,173,.3);color:#6c3483;font-size:.72rem">Processed</span>
    <?php elseif($is_active): ?>
    <span class="badge badge-green" style="font-size:.72rem">Active</span>
    <?php else: ?>
    <span class="badge badge-red" style="font-size:.72rem">Cancelled</span>
    <?php endif; ?>
  </td>

  <!-- ACTIONS -->
  <td style="white-space:nowrap" onclick="event.stopPropagation()">
    <?php if($is_admin): ?>
      <?php if(!$is_edit): ?>
      <a href="?edit=<?=$r['id']?>&edit_src=<?=$r['src']?><?=substr($qs,1)?'&'.substr($qs,1):''?>"
         class="btn btn-ghost btn-sm" title="Edit">✏</a>
      <?php endif; ?>

      <?php if($is_active): ?>
      <form method="POST" style="display:inline"
            onsubmit="return confirm('Cancel this stop order?')">
        <input type="hidden" name="action" value="cancel_so">
        <input type="hidden" name="id"     value="<?=$r['id']?>">
        <input type="hidden" name="src"    value="<?=$r['src']?>">
        <button class="btn btn-ghost btn-sm">Cancel</button>
      </form>
      <?php else: ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="restore_so">
        <input type="hidden" name="id"     value="<?=$r['id']?>">
        <input type="hidden" name="src"    value="<?=$r['src']?>">
        <button class="btn btn-ghost btn-sm">Restore</button>
      </form>
      <?php endif; ?>

      <form method="POST" style="display:inline"
            onsubmit="return confirm('Permanently delete this stop order?')">
        <input type="hidden" name="action" value="delete_so">
        <input type="hidden" name="id"     value="<?=$r['id']?>">
        <input type="hidden" name="src"    value="<?=$r['src']?>">
        <button class="btn btn-danger btn-sm">✕</button>
      </form>
    <?php else: ?>
      <span style="color:var(--muted);font-size:.72rem;font-style:italic">🔒 View Only</span>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8" class="text-center text-muted" style="padding:2rem">
  No stop orders found matching the current filters.
</td></tr>
<?php endif; ?>
</tbody>

<!-- Totals footer -->
<?php if(!empty($all_rows)):
  $visible_total = array_sum(array_column(array_filter($all_rows, fn($r)=>$r['active']), 'amount'));
?>
<tfoot>
<tr style="background:var(--surface-2);color:var(--text-muted)">
  <td colspan="4" style="padding:.5rem .9rem;font-weight:500;font-size:.78rem">
    TOTAL — <?=count($all_rows)?> order(s)
    &nbsp;·&nbsp;
    <?=count(array_filter($all_rows,fn($r)=>$r['src']==='timb'))?> TIMB
    &nbsp;·&nbsp;
    <?=count(array_filter($all_rows,fn($r)=>$r['src']==='ext'))?> Decrypted
    &nbsp;·&nbsp;
    <?=count(array_filter($all_rows,fn($r)=>$r['src']==='internal'))?> Internal
  </td>
  <td class="td-mono text-right fw-500" style="padding:.5rem .9rem;color:var(--gold-l)">
    $<?=number_format($visible_total,4)?>
  </td>
  <td colspan="3"></td>
</tr>
</tfoot>
<?php endif; ?>
</table></div></div>

<?php include "includes/layout_end.php"; ?>
