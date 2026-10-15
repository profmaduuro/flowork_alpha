<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_once "includes/proc_schema.php";
require_login();

$page_title = "Processing Batches"; $active_page = "proc_batches";
$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$msg = $err = '';
proc_ensure_tables($conn);
$seasonid = proc_seasonid($conn);

function refresh_batch_totals(mysqli $c, int $bid): void {
    $c->query("UPDATE proc_batches SET
        bale_count = (SELECT COUNT(*) FROM proc_intake WHERE batch_id=$bid),
        input_mass = (SELECT COALESCE(SUM(mass),0) FROM proc_intake WHERE batch_id=$bid)
      WHERE id=$bid");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $blend = $conn->real_escape_string(trim($_POST['blend_code'] ?? ''));
        $desc  = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $num   = proc_next_number($conn, 'proc_batches', 'batch_num', 'PB');
        $conn->query("INSERT INTO proc_batches (batch_num,blend_code,description,seasonid,created_by)
                      VALUES ('$num','$blend','$desc',$seasonid,$userid)");
        if ($conn->error) { $err = "Batch creation failed: " . $conn->error; }
        else {
            audit_log($conn,$spid,$userid,'proc_batches','create',$conn->insert_id,null,['batch_num'=>$num]);
            header("Location: proc_batches.php?view=" . $conn->insert_id); exit;
        }
    }

    if ($action === 'add_bales') {
        $bid = (int)($_POST['batch_id'] ?? 0);
        $st  = $conn->query("SELECT status FROM proc_batches WHERE id=$bid LIMIT 1")->fetch_assoc();
        if (!$st) { $err = "Batch not found."; }
        elseif ($st['status'] === 'completed') { $err = "Batch already completed."; }
        else {
            $lines = preg_split('/[\s,;]+/', trim($_POST['barcodes'] ?? ''));
            $added = 0; $skipped = [];
            foreach ($lines as $raw) {
                $bc = $conn->real_escape_string(trim($raw));
                if ($bc === '') continue;
                $q = $conn->query("SELECT id,status FROM proc_intake WHERE barcode='$bc' LIMIT 1");
                $row = ($q && $q->num_rows) ? $q->fetch_assoc() : null;
                if (!$row)                         { $skipped[] = "$bc (not received)"; continue; }
                if ($row['status'] !== 'received') { $skipped[] = "$bc (already allocated)"; continue; }
                $conn->query("UPDATE proc_intake SET batch_id=$bid, status='allocated' WHERE id={$row['id']}");
                $added++;
            }
            refresh_batch_totals($conn, $bid);
            $msg = "✓ $added bale(s) added to batch.";
            if ($skipped) $err = "Skipped: " . implode(', ', array_slice($skipped, 0, 8)) . (count($skipped) > 8 ? '…' : '');
        }
    }

    if ($action === 'remove_bale') {
        $iid = (int)($_POST['iid'] ?? 0);
        $row = $conn->query("SELECT batch_id,status FROM proc_intake WHERE id=$iid LIMIT 1")->fetch_assoc();
        if ($row && $row['status'] === 'allocated') {
            $bid = (int)$row['batch_id'];
            $conn->query("UPDATE proc_intake SET batch_id=NULL, status='received' WHERE id=$iid");
            refresh_batch_totals($conn, $bid);
            $msg = "Bale returned to intake stock.";
        } else $err = "Bale cannot be removed (already processed?).";
    }

    if ($action === 'start') {
        $bid = (int)($_POST['batch_id'] ?? 0);
        $conn->query("UPDATE proc_batches SET status='running', started_at=NOW() WHERE id=$bid AND status='open'");
        $msg = $conn->affected_rows ? "✓ Batch started." : "";
        if (!$conn->affected_rows) $err = "Batch not found or not open.";
    }

    if ($action === 'output') {
        $bid  = (int)($_POST['batch_id'] ?? 0);
        $type = $_POST['output_type'] ?? '';
        $mass = (float)($_POST['mass'] ?? 0);
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
        $st = $conn->query("SELECT status FROM proc_batches WHERE id=$bid LIMIT 1")->fetch_assoc();
        if (!$st)                              { $err = "Batch not found."; }
        elseif ($st['status'] === 'completed') { $err = "Batch already completed."; }
        elseif (!in_array($type, ['lamina','stem','scrap','fines'], true)) { $err = "Invalid output type."; }
        elseif ($mass <= 0)                    { $err = "Mass must be greater than zero."; }
        else {
            $conn->query("INSERT INTO proc_outputs (batch_id,output_type,mass,notes,recorded_by)
                          VALUES ($bid,'$type',$mass,'$notes',$userid)");
            audit_log($conn,$spid,$userid,'proc_outputs','create',$conn->insert_id,null,['type'=>$type,'mass'=>$mass]);
            $msg = "✓ " . ucfirst($type) . " output recorded (" . number_format($mass,1) . " kg).";
        }
    }

    if ($action === 'delete_output') {
        $oid = (int)($_POST['oid'] ?? 0);
        $row = $conn->query("SELECT o.id,b.status FROM proc_outputs o JOIN proc_batches b ON b.id=o.batch_id WHERE o.id=$oid LIMIT 1")->fetch_assoc();
        if ($row && $row['status'] !== 'completed') { $conn->query("DELETE FROM proc_outputs WHERE id=$oid"); $msg = "Output entry removed."; }
        else $err = "Cannot remove output from a completed batch.";
    }

    if ($action === 'complete') {
        $bid = (int)($_POST['batch_id'] ?? 0);
        $conn->query("UPDATE proc_batches SET status='completed', completed_at=NOW() WHERE id=$bid AND status IN ('open','running')");
        if ($conn->affected_rows) {
            $conn->query("UPDATE proc_intake SET status='processed' WHERE batch_id=$bid");
            audit_log($conn,$spid,$userid,'proc_batches','complete',$bid);
            $msg = "✓ Batch completed.";
        } else $err = "Batch not found or already completed.";
    }
}

// ── Detail view? ───────────────────────────────────────────────────────────
$view = (int)($_GET['view'] ?? 0);
$batch = null;
if ($view) {
    $q = $conn->query("SELECT * FROM proc_batches WHERE id=$view LIMIT 1");
    $batch = ($q && $q !== true && $q->num_rows) ? $q->fetch_assoc() : null;
    if ($batch) {
        $stats   = proc_batch_stats($conn, $view);
        $bales_q = $conn->query("SELECT * FROM proc_intake WHERE batch_id=$view ORDER BY id");
        $bales   = ($bales_q && $bales_q !== true) ? $bales_q->fetch_all(MYSQLI_ASSOC) : [];
        $outs_q  = $conn->query("SELECT * FROM proc_outputs WHERE batch_id=$view ORDER BY id DESC");
        $outs    = ($outs_q && $outs_q !== true) ? $outs_q->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// List
$list_q = $conn->query("SELECT b.*,
    (SELECT COALESCE(SUM(mass),0) FROM proc_outputs o WHERE o.batch_id=b.id) output_mass,
    (SELECT COUNT(*) FROM proc_cartons c WHERE c.batch_id=b.id) carton_count
  FROM proc_batches b ORDER BY b.id DESC LIMIT 100");
$list = ($list_q && $list_q !== true) ? $list_q->fetch_all(MYSQLI_ASSOC) : [];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Processing Batches</h2><p>Blend runs — allocate bales, record outputs, complete with mass balance</p></div>
  <a href="processing.php" class="btn btn-ghost btn-sm">← Dashboard</a>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="premium-tabs-nav" data-tabs="batches"<?= $batch ? ' data-force="detail"' : ($err ? ' data-force="new"' : '') ?>>
  <?php if ($batch): ?>
  <button type="button" class="premium-tab-btn" data-tab="detail">🔬 <?= htmlspecialchars($batch['batch_num']) ?></button>
  <?php endif; ?>
  <button type="button" class="premium-tab-btn active" data-tab="list">📋 All Batches</button>
  <button type="button" class="premium-tab-btn" data-tab="new">➕ New Batch</button>
</div>

<?php if ($batch): ?>
<?php $st_badge = ['open'=>'badge-blue','running'=>'badge-gold','completed'=>'badge-green'][$batch['status']]; ?>
<!-- ── Batch detail ─────────────────────────────────────────────────────── -->
<div id="panel-detail" class="tab-panel" data-tabs="batches">
<div class="card" style="margin-bottom:1.25rem">
<div class="card-head">
  <h3><?= htmlspecialchars($batch['batch_num']) ?>
      <span class="badge <?=$st_badge?>" style="margin-left:.5rem"><?= strtoupper($batch['status']) ?></span></h3>
  <div>
    <?php if ($batch['status'] === 'open'): ?>
    <form method="POST" style="display:inline"><input type="hidden" name="action" value="start">
      <input type="hidden" name="batch_id" value="<?=$batch['id']?>">
      <button class="btn btn-gold btn-sm">▶ Start Run</button></form>
    <?php endif; ?>
    <?php if ($batch['status'] !== 'completed'): ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Complete batch <?=htmlspecialchars($batch['batch_num'],ENT_QUOTES)?>? Bales become PROCESSED and outputs lock.')">
      <input type="hidden" name="action" value="complete"><input type="hidden" name="batch_id" value="<?=$batch['id']?>">
      <button class="btn btn-primary btn-sm">✓ Complete Batch</button></form>
    <?php endif; ?>
    <a href="proc_cartons.php?batch=<?=$batch['id']?>" class="btn btn-ghost btn-sm">Pack Cartons →</a>
  </div>
</div>
<div class="card-body">
  <div class="kpi-grid" style="margin-bottom:0">
    <div class="kpi-card" style="--kpi-color:var(--leaf)">
      <div class="kpi-label">Input</div>
      <div class="kpi-value sm"><?= number_format($stats['input'],1) ?> kg</div>
      <div class="kpi-sub"><?= (int)$batch['bale_count'] ?> bales · blend <?= htmlspecialchars($batch['blend_code'] ?: '—') ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--gold)">
      <div class="kpi-label">Output</div>
      <div class="kpi-value sm"><?= number_format($stats['output_total'],1) ?> kg</div>
      <div class="kpi-sub">yield <?= $stats['yield_pct'] ?>% · loss <?= number_format($stats['waste'],1) ?> kg</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--info)">
      <div class="kpi-label">Lamina / Stem</div>
      <div class="kpi-value sm"><?= number_format($stats['outputs']['lamina'] ?? 0,0) ?> / <?= number_format($stats['outputs']['stem'] ?? 0,0) ?></div>
      <div class="kpi-sub">scrap <?= number_format($stats['outputs']['scrap'] ?? 0,1) ?> · fines <?= number_format($stats['outputs']['fines'] ?? 0,1) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--leaf)">
      <div class="kpi-label">Cartons</div>
      <div class="kpi-value sm"><?= $stats['cartons'] ?></div>
      <div class="kpi-sub"><?= number_format($stats['carton_mass'],1) ?> kg net packed</div>
    </div>
  </div>
</div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">
  <!-- Add bales -->
  <div class="card">
  <div class="card-head"><h3>Add Bales to Batch</h3></div>
  <div class="card-body">
    <?php if ($batch['status'] === 'completed'): ?>
      <p style="color:var(--muted);font-size:.82rem">Batch completed — bales locked.</p>
    <?php else: ?>
    <form method="POST">
      <input type="hidden" name="action" value="add_bales">
      <input type="hidden" name="batch_id" value="<?=$batch['id']?>">
      <div class="form-group">
        <label>Barcodes <span style="font-size:.65rem;color:var(--muted)">(scan or paste — one per line, spaces or commas also work)</span></label>
        <textarea name="barcodes" class="form-control" rows="5" required
                  placeholder="TEST-BALE-001&#10;TEST-BALE-002" style="font-family:monospace"></textarea>
      </div>
      <button class="btn btn-primary" style="width:100%">Add Bales</button>
    </form>
    <?php endif; ?>
  </div>
  </div>

  <!-- Record output -->
  <div class="card">
  <div class="card-head"><h3>Record Output</h3></div>
  <div class="card-body">
    <?php if ($batch['status'] === 'completed'): ?>
      <p style="color:var(--muted);font-size:.82rem">Batch completed — outputs locked.</p>
    <?php else: ?>
    <form method="POST">
      <input type="hidden" name="action" value="output">
      <input type="hidden" name="batch_id" value="<?=$batch['id']?>">
      <div class="form-row cols-2">
        <div class="form-group">
          <label>Type *</label>
          <select name="output_type" class="form-control" required>
            <option value="lamina">Lamina</option>
            <option value="stem">Stem</option>
            <option value="scrap">Scrap</option>
            <option value="fines">Fines / Dust</option>
          </select>
        </div>
        <div class="form-group">
          <label>Mass (kg) *</label>
          <input type="number" name="mass" class="form-control" step="0.01" min="0.01" required>
        </div>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <input type="text" name="notes" class="form-control" placeholder="optional — shift, line, remarks">
      </div>
      <button class="btn btn-gold" style="width:100%">Record Output</button>
    </form>
    <?php endif; ?>
  </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">
  <!-- Bales in batch -->
  <div class="card">
  <div class="card-head"><h3>Bales (<?= count($bales) ?>)</h3></div>
  <?php if (!$bales): ?><div class="card-body" style="color:var(--muted);font-size:.82rem">No bales allocated yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
  <thead><tr><th>Barcode</th><th>Grower</th><th>Grade</th><th style="text-align:right">kg</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($bales as $bl): ?>
  <tr>
    <td style="font-weight:600;font-size:.78rem"><?= htmlspecialchars($bl['barcode']) ?></td>
    <td><?= htmlspecialchars($bl['grower_num'] ?: '—') ?></td>
    <td><?= htmlspecialchars($bl['timb_grade'] ?: '—') ?></td>
    <td style="text-align:right"><?= number_format($bl['mass'],1) ?></td>
    <td>
      <?php if ($batch['status'] !== 'completed'): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="remove_bale"><input type="hidden" name="iid" value="<?=$bl['id']?>">
        <button class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--danger)" title="Return to intake">✕</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  </div>

  <!-- Outputs -->
  <div class="card">
  <div class="card-head"><h3>Output Entries (<?= count($outs) ?>)</h3></div>
  <?php if (!$outs): ?><div class="card-body" style="color:var(--muted);font-size:.82rem">No outputs recorded yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
  <thead><tr><th>Type</th><th style="text-align:right">kg</th><th>Notes</th><th>When</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($outs as $o): ?>
  <tr>
    <td style="font-weight:600"><?= ucfirst($o['output_type']) ?></td>
    <td style="text-align:right"><?= number_format($o['mass'],1) ?></td>
    <td style="font-size:.75rem"><?= htmlspecialchars($o['notes'] ?: '—') ?></td>
    <td style="font-size:.72rem;color:var(--muted)"><?= htmlspecialchars(substr($o['recorded_at'],0,16)) ?></td>
    <td>
      <?php if ($batch['status'] !== 'completed'): ?>
      <form method="POST" style="display:inline" onsubmit="return confirm('Remove this output entry?')">
        <input type="hidden" name="action" value="delete_output"><input type="hidden" name="oid" value="<?=$o['id']?>">
        <button class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--danger)">✕</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  </div>
</div>
</div><!-- /panel-detail -->
<?php endif; // detail ?>

<!-- ── Create + list ─────────────────────────────────────────────────────── -->
<div id="panel-new" class="tab-panel" data-tabs="batches">
<div class="card">
<div class="card-head"><h3>New Batch</h3></div>
<div class="card-body">
<form method="POST">
<input type="hidden" name="action" value="create">
<div class="form-row cols-2">
  <div class="form-group">
    <label>Blend Code</label>
    <input type="text" name="blend_code" class="form-control" placeholder="e.g. FCV-A" style="text-transform:uppercase">
  </div>
  <div class="form-group">
    <label>Description</label>
    <input type="text" name="description" class="form-control" placeholder="e.g. Lemon lamina run — week 24">
  </div>
</div>
<button class="btn btn-primary" style="width:100%">Create Batch</button>
</form>
</div>
</div>
</div><!-- /panel-new -->

<div id="panel-list" class="tab-panel active" data-tabs="batches">
<div class="card">
<div class="card-head"><h3>All Batches</h3></div>
<?php if (!$list): ?>
<div class="card-body" style="color:var(--muted);font-size:.82rem">No batches yet.</div>
<?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th>Batch</th><th>Blend</th><th>Description</th><th>Status</th>
<th style="text-align:right">Bales</th><th style="text-align:right">Input kg</th>
<th style="text-align:right">Output kg</th><th style="text-align:right">Yield</th>
<th style="text-align:right">Cartons</th><th>Created</th></tr></thead>
<tbody>
<?php foreach ($list as $b):
    $y = $b['input_mass'] > 0 ? round($b['output_mass']/$b['input_mass']*100,1) : 0;
    $badge = ['open'=>'badge-blue','running'=>'badge-gold','completed'=>'badge-green'][$b['status']] ?? 'badge-gray'; ?>
<tr>
  <td><a href="?view=<?=$b['id']?>" style="font-weight:600;color:var(--gold)"><?= htmlspecialchars($b['batch_num']) ?></a></td>
  <td><?= htmlspecialchars($b['blend_code'] ?: '—') ?></td>
  <td style="font-size:.75rem"><?= htmlspecialchars($b['description'] ?: '—') ?></td>
  <td><span class="badge <?=$badge?>"><?= strtoupper($b['status']) ?></span></td>
  <td style="text-align:right"><?= (int)$b['bale_count'] ?></td>
  <td style="text-align:right"><?= number_format($b['input_mass'],1) ?></td>
  <td style="text-align:right"><?= number_format($b['output_mass'],1) ?></td>
  <td style="text-align:right"><?= $y ?>%</td>
  <td style="text-align:right"><?= (int)$b['carton_count'] ?></td>
  <td style="font-size:.72rem;color:var(--muted)"><?= htmlspecialchars(substr($b['created_at'],0,16)) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div>
</div><!-- /panel-list -->
<?php include "includes/layout_end.php"; ?>
