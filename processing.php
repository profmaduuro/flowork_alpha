<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/proc_schema.php";
require_login();

$page_title = "Processing Dashboard"; $active_page = "processing";
proc_ensure_tables($conn);

// ── KPIs ────────────────────────────────────────────────────────────────────
$kpi = [
    'intake_bales' => 0, 'intake_mass' => 0.0,
    'active_batches' => 0, 'processed_mass' => 0.0,
    'cartons_ready' => 0, 'carton_mass' => 0.0,
];
$r = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(mass),0) m FROM proc_intake WHERE status='received'");
if ($r && $r !== true) { $row = $r->fetch_assoc(); $kpi['intake_bales']=(int)$row['n']; $kpi['intake_mass']=(float)$row['m']; }
$r = $conn->query("SELECT COUNT(*) n FROM proc_batches WHERE status IN ('open','running')");
if ($r && $r !== true) $kpi['active_batches'] = (int)$r->fetch_assoc()['n'];
$r = $conn->query("SELECT COALESCE(SUM(input_mass),0) m FROM proc_batches WHERE status='completed'");
if ($r && $r !== true) $kpi['processed_mass'] = (float)$r->fetch_assoc()['m'];
$r = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(net_mass),0) m FROM proc_cartons WHERE status IN ('packed','staged')");
if ($r && $r !== true) { $row = $r->fetch_assoc(); $kpi['cartons_ready']=(int)$row['n']; $kpi['carton_mass']=(float)$row['m']; }

// Season-wide yield
$yield = ['input'=>0.0,'output'=>0.0];
$r = $conn->query("SELECT COALESCE(SUM(b.input_mass),0) i,
                     (SELECT COALESCE(SUM(o.mass),0) FROM proc_outputs o
                       JOIN proc_batches pb ON pb.id=o.batch_id WHERE pb.status='completed') o
                   FROM proc_batches b WHERE b.status='completed'");
if ($r && $r !== true) { $row = $r->fetch_assoc(); $yield['input']=(float)$row['i']; $yield['output']=(float)$row['o']; }
$yield_pct = $yield['input'] > 0 ? round($yield['output']/$yield['input']*100, 1) : 0;

// Recent batches with output mass
$batches_q = $conn->query("SELECT b.*,
    (SELECT COALESCE(SUM(mass),0) FROM proc_outputs o WHERE o.batch_id=b.id) output_mass,
    (SELECT COUNT(*) FROM proc_cartons c WHERE c.batch_id=b.id) carton_count
  FROM proc_batches b ORDER BY b.id DESC LIMIT 10");
$batches = ($batches_q && $batches_q !== true) ? $batches_q->fetch_all(MYSQLI_ASSOC) : [];

// Recent cartons
$cartons_q = $conn->query("SELECT c.*, b.batch_num FROM proc_cartons c
  LEFT JOIN proc_batches b ON b.id=c.batch_id ORDER BY c.id DESC LIMIT 10");
$cartons = ($cartons_q && $cartons_q !== true) ? $cartons_q->fetch_all(MYSQLI_ASSOC) : [];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Processing Dashboard</h2><p>Green leaf intake → threshing → export cartons</p></div>
  <div>
    <a href="proc_intake.php" class="btn btn-ghost btn-sm">Receive Bales</a>
    <a href="proc_batches.php" class="btn btn-ghost btn-sm">Batches</a>
    <a href="proc_cartons.php" class="btn btn-ghost btn-sm">Pack Cartons</a>
    <a href="proc_shipments.php" class="btn btn-primary btn-sm">Shipments</a>
  </div>
</div>

<div class="kpi-grid">
  <div class="kpi-card" style="--kpi-color:var(--leaf)">
    <div class="kpi-label">Unallocated Stock</div>
    <div class="kpi-value"><?= number_format($kpi['intake_bales']) ?></div>
    <div class="kpi-sub"><?= number_format($kpi['intake_mass'],1) ?> kg awaiting batching</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--gold)">
    <div class="kpi-label">Active Batches</div>
    <div class="kpi-value"><?= number_format($kpi['active_batches']) ?></div>
    <div class="kpi-sub">open or running</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--info)">
    <div class="kpi-label">Processed (Season)</div>
    <div class="kpi-value sm"><?= number_format($kpi['processed_mass'],0) ?> kg</div>
    <div class="kpi-sub">yield <?= $yield_pct ?>%</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)">
    <div class="kpi-label">Export-Ready Cartons</div>
    <div class="kpi-value"><?= number_format($kpi['cartons_ready']) ?></div>
    <div class="kpi-sub"><?= number_format($kpi['carton_mass'],0) ?> kg net packed</div>
  </div>
</div>

<div class="card" style="margin-bottom:1.25rem">
<div class="card-head"><h3>Recent Batches</h3><a href="proc_batches.php" class="btn btn-ghost btn-sm">View All</a></div>
<?php if (!$batches): ?>
<div class="card-body" style="color:var(--muted);font-size:.82rem">No batches yet — receive bales, then create a batch.</div>
<?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th>Batch</th><th>Blend</th><th>Status</th><th style="text-align:right">Bales</th>
<th style="text-align:right">Input kg</th><th style="text-align:right">Output kg</th>
<th style="text-align:right">Yield</th><th style="text-align:right">Cartons</th></tr></thead>
<tbody>
<?php foreach ($batches as $b):
    $y = $b['input_mass'] > 0 ? round($b['output_mass']/$b['input_mass']*100,1) : 0;
    $badge = ['open'=>'badge-blue','running'=>'badge-gold','completed'=>'badge-green'][$b['status']] ?? 'badge-gray'; ?>
<tr>
  <td><a href="proc_batches.php?view=<?=$b['id']?>" style="font-weight:600;color:var(--gold)"><?= htmlspecialchars($b['batch_num']) ?></a></td>
  <td><?= htmlspecialchars($b['blend_code'] ?: '—') ?></td>
  <td><span class="badge <?=$badge?>"><?= strtoupper($b['status']) ?></span></td>
  <td style="text-align:right"><?= (int)$b['bale_count'] ?></td>
  <td style="text-align:right"><?= number_format($b['input_mass'],1) ?></td>
  <td style="text-align:right"><?= number_format($b['output_mass'],1) ?></td>
  <td style="text-align:right"><?= $y ?>%</td>
  <td style="text-align:right"><?= (int)$b['carton_count'] ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div>

<div class="card">
<div class="card-head"><h3>Recent Cartons</h3><a href="proc_cartons.php" class="btn btn-ghost btn-sm">View All</a></div>
<?php if (!$cartons): ?>
<div class="card-body" style="color:var(--muted);font-size:.82rem">No cartons packed yet.</div>
<?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th>Carton</th><th>Batch</th><th>Content</th><th>Blend</th>
<th style="text-align:right">Net kg</th><th>Status</th><th>Destination</th><th>Packed</th></tr></thead>
<tbody>
<?php foreach ($cartons as $c):
    $badge = ['packed'=>'badge-gold','staged'=>'badge-blue','shipped'=>'badge-green'][$c['status']] ?? 'badge-gray'; ?>
<tr>
  <td style="font-weight:600"><?= htmlspecialchars($c['carton_num']) ?></td>
  <td><?= htmlspecialchars($c['batch_num'] ?? '—') ?></td>
  <td><?= ucfirst($c['content_type']) ?></td>
  <td><?= htmlspecialchars($c['blend_code'] ?: '—') ?></td>
  <td style="text-align:right"><?= number_format($c['net_mass'],1) ?></td>
  <td><span class="badge <?=$badge?>"><?= strtoupper($c['status']) ?></span></td>
  <td><?= htmlspecialchars($c['destination'] ?: '—') ?></td>
  <td style="font-size:.72rem;color:var(--muted)"><?= htmlspecialchars(substr($c['packed_at'],0,16)) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div>
<?php include "includes/layout_end.php"; ?>
