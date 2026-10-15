<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_once "includes/proc_schema.php";
require_login();

$page_title = "Processing Intake"; $active_page = "proc_intake";
$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$msg = $err = '';
proc_ensure_tables($conn);
$seasonid = proc_seasonid($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'receive') {
        $barcode = $conn->real_escape_string(trim($_POST['barcode'] ?? ''));
        $mass    = (float)($_POST['mass'] ?? 0);
        $grade   = $conn->real_escape_string(trim($_POST['timb_grade'] ?? ''));
        $grower  = $conn->real_escape_string(trim($_POST['grower_num'] ?? ''));
        $source  = $conn->real_escape_string(trim($_POST['source'] ?? ''));
        if (!$barcode)     { $err = "Bale barcode is required."; }
        elseif ($mass <= 0) { $err = "Mass must be greater than zero."; }
        else {
            $ex = $conn->query("SELECT id FROM proc_intake WHERE barcode='$barcode' LIMIT 1");
            if ($ex && $ex->num_rows > 0) { $err = "Bale '$barcode' was already received."; }
            else {
                $conn->query("INSERT INTO proc_intake (barcode,grower_num,timb_grade,mass,source,seasonid,received_by)
                              VALUES ('$barcode','$grower','$grade',$mass,'$source',$seasonid,$userid)");
                if ($conn->error) { $err = "Intake failed: " . $conn->error; }
                else {
                    audit_log($conn,$spid,$userid,'proc_intake','create',$conn->insert_id,null,['barcode'=>$barcode,'mass'=>$mass]);
                    $msg = "✓ Bale '$barcode' received (" . number_format($mass,1) . " kg).";
                }
            }
        }
    }

    if ($action === 'remove') {
        $iid = (int)($_POST['iid'] ?? 0);
        $row = $conn->query("SELECT status,barcode FROM proc_intake WHERE id=$iid LIMIT 1")->fetch_assoc();
        if (!$row)                            { $err = "Record not found."; }
        elseif ($row['status'] !== 'received') { $err = "Cannot remove — bale already allocated to a batch."; }
        else {
            $conn->query("DELETE FROM proc_intake WHERE id=$iid");
            audit_log($conn,$spid,$userid,'proc_intake','delete',$iid,null,['barcode'=>$row['barcode']]);
            $msg = "Bale '{$row['barcode']}' removed from intake.";
        }
    }
}

// ── List with filters ──────────────────────────────────────────────────────
$f_status = $_GET['status'] ?? '';
$f_q      = trim($_GET['q'] ?? '');
$where = '1=1';
if (in_array($f_status, ['received','allocated','processed'], true)) $where .= " AND i.status='$f_status'";
if ($f_q !== '') { $q = $conn->real_escape_string($f_q); $where .= " AND (i.barcode LIKE '%$q%' OR i.grower_num LIKE '%$q%')"; }

$rows_q = $conn->query("SELECT i.*, b.batch_num FROM proc_intake i
    LEFT JOIN proc_batches b ON b.id=i.batch_id
    WHERE $where ORDER BY i.id DESC LIMIT 300");
$rows = ($rows_q && $rows_q !== true) ? $rows_q->fetch_all(MYSQLI_ASSOC) : [];

$tot_q = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(mass),0) m FROM proc_intake i WHERE $where");
$tot = ($tot_q && $tot_q !== true) ? $tot_q->fetch_assoc() : ['n'=>0,'m'=>0];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Processing Intake</h2><p>Receive bales at the processing plant — scan, weigh, record</p></div>
  <a href="processing.php" class="btn btn-ghost btn-sm">← Dashboard</a>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- Receive form -->
<div class="card" style="margin-bottom:1.25rem">
<div class="card-head"><h3>Receive Bale</h3></div>
<div class="card-body">
<form method="POST">
<input type="hidden" name="action" value="receive">
<div class="form-row cols-2">
  <div class="form-group">
    <label>Bale Barcode *</label>
    <input type="text" name="barcode" class="form-control" required autofocus
           placeholder="Scan or type barcode" style="font-weight:600;font-size:1rem">
  </div>
  <div class="form-group">
    <label>Mass (kg) *</label>
    <input type="number" name="mass" class="form-control" step="0.01" min="0.01" required placeholder="e.g. 98.5">
  </div>
</div>
<div class="form-row cols-2">
  <div class="form-group">
    <label>TIMB Grade</label>
    <input type="text" name="timb_grade" class="form-control" placeholder="e.g. L1">
  </div>
  <div class="form-group">
    <label>Grower Number</label>
    <input type="text" name="grower_num" class="form-control" placeholder="optional">
  </div>
</div>
<div class="form-group">
  <label>Source</label>
  <input type="text" name="source" class="form-control" placeholder="e.g. floor name, supplier, transfer ref">
</div>
<button type="submit" class="btn btn-primary" style="width:100%">Receive Bale</button>
</form>
</div>
</div>

<!-- Register -->
<div class="card">
<div class="card-head">
  <h3>Intake Register — <?= number_format((int)$tot['n']) ?> bales · <?= number_format((float)$tot['m'],1) ?> kg</h3>
  <form method="GET" style="display:flex;gap:.5rem">
    <select name="status" class="form-control" style="width:auto;font-size:.75rem" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <option value="received"  <?=$f_status==='received'?'selected':''?>>Received</option>
      <option value="allocated" <?=$f_status==='allocated'?'selected':''?>>Allocated</option>
      <option value="processed" <?=$f_status==='processed'?'selected':''?>>Processed</option>
    </select>
    <input type="text" name="q" class="form-control" style="width:160px;font-size:.75rem"
           placeholder="Search barcode/grower" value="<?=htmlspecialchars($f_q)?>">
    <button class="btn btn-ghost btn-sm">Filter</button>
  </form>
</div>
<?php if (!$rows): ?>
<div class="card-body" style="color:var(--muted);font-size:.82rem">No intake records match.</div>
<?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th>Barcode</th><th>Grower</th><th>Grade</th><th style="text-align:right">Mass kg</th>
<th>Source</th><th>Status</th><th>Batch</th><th>Received</th><th></th></tr></thead>
<tbody>
<?php foreach ($rows as $r):
    $badge = ['received'=>'badge-blue','allocated'=>'badge-gold','processed'=>'badge-green'][$r['status']] ?? 'badge-gray'; ?>
<tr>
  <td style="font-weight:600"><?= htmlspecialchars($r['barcode']) ?></td>
  <td><?= htmlspecialchars($r['grower_num'] ?: '—') ?></td>
  <td><?= htmlspecialchars($r['timb_grade'] ?: '—') ?></td>
  <td style="text-align:right"><?= number_format($r['mass'],1) ?></td>
  <td style="font-size:.75rem"><?= htmlspecialchars($r['source'] ?: '—') ?></td>
  <td><span class="badge <?=$badge?>"><?= strtoupper($r['status']) ?></span></td>
  <td><?= htmlspecialchars($r['batch_num'] ?? '—') ?></td>
  <td style="font-size:.72rem;color:var(--muted)"><?= htmlspecialchars(substr($r['received_at'],0,16)) ?></td>
  <td>
    <?php if ($r['status'] === 'received'): ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Remove bale <?=htmlspecialchars($r['barcode'],ENT_QUOTES)?> from intake?')">
      <input type="hidden" name="action" value="remove"><input type="hidden" name="iid" value="<?=$r['id']?>">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--danger)">✕</button>
    </form>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div>
<?php include "includes/layout_end.php"; ?>
