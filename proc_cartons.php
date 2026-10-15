<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_once "includes/proc_schema.php";
require_login();

$page_title = "Export Cartons"; $active_page = "proc_cartons";
$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$msg = $err = '';
proc_ensure_tables($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'pack') {
        $bid   = (int)($_POST['batch_id'] ?? 0);
        $type  = $_POST['content_type'] ?? 'lamina';
        $net   = (float)($_POST['net_mass'] ?? 0);
        $gross = (float)($_POST['gross_mass'] ?? 0);
        $blend = $conn->real_escape_string(strtoupper(trim($_POST['blend_code'] ?? '')));
        $dest  = $conn->real_escape_string(trim($_POST['destination'] ?? ''));
        $count = max(1, min(200, (int)($_POST['count'] ?? 1)));

        $bq = $conn->query("SELECT blend_code FROM proc_batches WHERE id=$bid LIMIT 1");
        $batch = ($bq && $bq->num_rows) ? $bq->fetch_assoc() : null;
        if (!$batch)                                   { $err = "Select a batch."; }
        elseif (!in_array($type, ['lamina','stem'], true)) { $err = "Invalid content type."; }
        elseif ($net <= 0)                             { $err = "Net mass must be greater than zero."; }
        elseif ($gross > 0 && $gross < $net)           { $err = "Gross mass cannot be less than net mass."; }
        else {
            if ($blend === '') $blend = $conn->real_escape_string($batch['blend_code']);
            $packed = [];
            for ($i = 0; $i < $count; $i++) {
                $num = proc_next_number($conn, 'proc_cartons', 'carton_num', 'CT');
                $conn->query("INSERT INTO proc_cartons (carton_num,batch_id,content_type,blend_code,net_mass,gross_mass,destination,packed_by)
                              VALUES ('$num',$bid,'$type','$blend',$net,$gross,'$dest',$userid)");
                if ($conn->error) { $err = "Pack failed: " . $conn->error; break; }
                $packed[] = $num;
            }
            if ($packed) {
                audit_log($conn,$spid,$userid,'proc_cartons','create',0,null,['cartons'=>$packed]);
                $msg = "✓ Packed " . count($packed) . " carton(s): " . $packed[0]
                     . (count($packed) > 1 ? " … " . end($packed) : "");
            }
        }
    }

    if ($action === 'status') {
        $cid = (int)($_POST['cid'] ?? 0);
        $to  = $_POST['to'] ?? '';
        if (in_array($to, ['packed','staged','shipped'], true)) {
            $conn->query("UPDATE proc_cartons SET status='$to' WHERE id=$cid");
            $msg = "Carton marked " . strtoupper($to) . ".";
        }
    }
}

// ── Filters + list ─────────────────────────────────────────────────────────
$f_status = $_GET['status'] ?? '';
$f_batch  = (int)($_GET['batch'] ?? 0);
$where = '1=1';
if (in_array($f_status, ['packed','staged','shipped'], true)) $where .= " AND c.status='$f_status'";
if ($f_batch > 0) $where .= " AND c.batch_id=$f_batch";

$rows_q = $conn->query("SELECT c.*, b.batch_num FROM proc_cartons c
    LEFT JOIN proc_batches b ON b.id=c.batch_id WHERE $where ORDER BY c.id DESC LIMIT 300");
$rows = ($rows_q && $rows_q !== true) ? $rows_q->fetch_all(MYSQLI_ASSOC) : [];

$tot_q = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(net_mass),0) m FROM proc_cartons c WHERE $where");
$tot = ($tot_q && $tot_q !== true) ? $tot_q->fetch_assoc() : ['n'=>0,'m'=>0];

// Batches for the pack form (not completed-only: pack can continue after completion)
$batches_q = $conn->query("SELECT id,batch_num,blend_code,status FROM proc_batches ORDER BY id DESC LIMIT 100");
$batch_opts = ($batches_q && $batches_q !== true) ? $batches_q->fetch_all(MYSQLI_ASSOC) : [];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Export Cartons</h2><p>Pack processed tobacco into export cartons and track them to shipment</p></div>
  <div style="display:flex;gap:.4rem">
    <a href="proc_shipments.php" class="btn btn-ghost btn-sm">Shipments →</a>
    <a href="processing.php" class="btn btn-ghost btn-sm">← Dashboard</a>
  </div>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="premium-tabs-nav" data-tabs="cartons"<?= $err ? ' data-force="pack"' : '' ?>>
  <button type="button" class="premium-tab-btn active" data-tab="register">📦 Carton Register</button>
  <button type="button" class="premium-tab-btn" data-tab="pack">➕ Pack Cartons</button>
</div>

<!-- Pack form -->
<div id="panel-pack" class="tab-panel" data-tabs="cartons">
<div class="card">
<div class="card-head"><h3>Pack Cartons</h3></div>
<div class="card-body">
<form method="POST">
<input type="hidden" name="action" value="pack">
<div class="form-row cols-2">
  <div class="form-group">
    <label>Batch *</label>
    <select name="batch_id" class="form-control" required>
      <option value="">— select batch —</option>
      <?php foreach ($batch_opts as $b): ?>
      <option value="<?=$b['id']?>" <?=$f_batch===(int)$b['id']?'selected':''?>>
        <?= htmlspecialchars($b['batch_num']) ?><?= $b['blend_code'] ? ' · '.htmlspecialchars($b['blend_code']) : '' ?> (<?=$b['status']?>)
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>Content *</label>
    <select name="content_type" class="form-control" required>
      <option value="lamina">Lamina</option>
      <option value="stem">Stem</option>
    </select>
  </div>
</div>
<div class="form-row cols-2">
  <div class="form-group">
    <label>Net Mass per Carton (kg) *</label>
    <input type="number" name="net_mass" class="form-control" step="0.01" min="0.01" required placeholder="e.g. 200">
  </div>
  <div class="form-group">
    <label>Gross Mass per Carton (kg)</label>
    <input type="number" name="gross_mass" class="form-control" step="0.01" min="0" placeholder="optional — net + carton tare">
  </div>
</div>
<div class="form-row cols-2">
  <div class="form-group">
    <label>Blend Code <span style="font-size:.65rem;color:var(--muted)">(blank = inherit from batch)</span></label>
    <input type="text" name="blend_code" class="form-control" style="text-transform:uppercase" placeholder="e.g. FCV-A">
  </div>
  <div class="form-group">
    <label>Number of Cartons</label>
    <input type="number" name="count" class="form-control" min="1" max="200" value="1">
  </div>
</div>
<div class="form-group">
  <label>Destination</label>
  <input type="text" name="destination" class="form-control" placeholder="e.g. buyer, port, container ref">
</div>
<button class="btn btn-primary" style="width:100%">Pack Carton(s)</button>
</form>
</div>
</div>
</div><!-- /panel-pack -->

<!-- Register -->
<div id="panel-register" class="tab-panel active" data-tabs="cartons">
<div class="card">
<div class="card-head">
  <h3>Carton Register — <?= number_format((int)$tot['n']) ?> cartons · <?= number_format((float)$tot['m'],1) ?> kg net</h3>
  <form method="GET" style="display:flex;gap:.5rem">
    <?php if ($f_batch): ?><input type="hidden" name="batch" value="<?=$f_batch?>"><?php endif; ?>
    <select name="status" class="form-control" style="width:auto;font-size:.75rem" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <option value="packed"  <?=$f_status==='packed'?'selected':''?>>Packed</option>
      <option value="staged"  <?=$f_status==='staged'?'selected':''?>>Staged</option>
      <option value="shipped" <?=$f_status==='shipped'?'selected':''?>>Shipped</option>
    </select>
  </form>
</div>
<?php if (!$rows): ?>
<div class="card-body" style="color:var(--muted);font-size:.82rem">No cartons match.</div>
<?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th>Carton</th><th>Batch</th><th>Content</th><th>Blend</th>
<th style="text-align:right">Net kg</th><th style="text-align:right">Gross kg</th>
<th>Status</th><th>Destination</th><th>Packed</th><th></th></tr></thead>
<tbody>
<?php foreach ($rows as $c):
    $badge = ['packed'=>'badge-gold','staged'=>'badge-blue','shipped'=>'badge-green'][$c['status']] ?? 'badge-gray'; ?>
<tr>
  <td style="font-weight:600"><?= htmlspecialchars($c['carton_num']) ?></td>
  <td><?= htmlspecialchars($c['batch_num'] ?? '—') ?></td>
  <td><?= ucfirst($c['content_type']) ?></td>
  <td><?= htmlspecialchars($c['blend_code'] ?: '—') ?></td>
  <td style="text-align:right"><?= number_format($c['net_mass'],1) ?></td>
  <td style="text-align:right"><?= $c['gross_mass'] > 0 ? number_format($c['gross_mass'],1) : '—' ?></td>
  <td><span class="badge <?=$badge?>"><?= strtoupper($c['status']) ?></span></td>
  <td style="font-size:.75rem"><?= htmlspecialchars($c['destination'] ?: '—') ?></td>
  <td style="font-size:.72rem;color:var(--muted)"><?= htmlspecialchars(substr($c['packed_at'],0,16)) ?></td>
  <td style="white-space:nowrap">
    <?php if ($c['status'] === 'packed'): ?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="status"><input type="hidden" name="cid" value="<?=$c['id']?>">
      <input type="hidden" name="to" value="staged">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem">Stage</button>
    </form>
    <?php elseif ($c['status'] === 'staged'): ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Mark carton <?=htmlspecialchars($c['carton_num'],ENT_QUOTES)?> as SHIPPED?')">
      <input type="hidden" name="action" value="status"><input type="hidden" name="cid" value="<?=$c['id']?>">
      <input type="hidden" name="to" value="shipped">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem">Ship</button>
    </form>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="status"><input type="hidden" name="cid" value="<?=$c['id']?>">
      <input type="hidden" name="to" value="packed">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--muted)">Unstage</button>
    </form>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div>
</div><!-- /panel-register -->
<?php include "includes/layout_end.php"; ?>
