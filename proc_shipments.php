<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_once "includes/proc_schema.php";
require_login();

$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
proc_ensure_tables($conn);

// ── Packing-list PDF (must run before any HTML) ─────────────────────────────
if (isset($_GET['pl'])) {
    require_once "includes/pdf_table.php";
    $sid = (int)$_GET['pl'];
    $sr  = $conn->query("SELECT * FROM proc_shipments WHERE id=$sid LIMIT 1");
    $sh  = ($sr && $sr->num_rows) ? $sr->fetch_assoc() : null;
    if (!$sh) { http_response_code(404); exit("Shipment not found"); }
    $cr = $conn->query("SELECT c.carton_num,c.content_type,c.blend_code,c.net_mass,c.gross_mass,b.batch_num
                        FROM proc_cartons c LEFT JOIN proc_batches b ON b.id=c.batch_id
                        WHERE c.shipment_id=$sid ORDER BY c.carton_num");
    $rows = []; $netT = 0; $grossT = 0;
    if ($cr) while ($c = $cr->fetch_assoc()) {
        $netT += (float)$c['net_mass']; $grossT += (float)$c['gross_mass'];
        $rows[] = [$c['carton_num'], $c['batch_num'] ?? '', ucfirst($c['content_type']),
                   $c['blend_code'] ?: '-', number_format($c['net_mass'],1), number_format($c['gross_mass'],1)];
    }
    $meta = [
        'Shipment No.: '.$sh['shipment_no'].'        Status: '.strtoupper($sh['status']),
        'Container: '.($sh['container_no'] ?: '-').'        Vessel: '.($sh['vessel'] ?: '-'),
        'Consignee: '.($sh['consignee'] ?: '-'),
        'Destination: '.($sh['destination'] ?: '-').'        Booking Ref: '.($sh['booking_ref'] ?: '-'),
        'Date: '.date('Y-m-d').'        Cartons: '.count($rows),
    ];
    $cols = [['Carton',40,'L'],['Batch',150,'L'],['Content',250,'L'],['Blend',330,'L'],
             ['Net kg',470,'R'],['Gross kg',545,'R']];
    $sections = [[
        'heading' => 'CARTON MANIFEST',
        'cols'    => $cols,
        'rows'    => $rows,
        'totals'  => ['TOTAL — '.count($rows).' carton(s)','','','',number_format($netT,1),number_format($grossT,1)],
    ]];
    pdf_table_send('PACKING_LIST_'.$sh['shipment_no'].'.pdf', 'EXPORT PACKING LIST', $meta, $sections);
}

$page_title = "Export Shipments"; $active_page = "proc_shipments";
$msg = $err = '';

// ── POST actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $cont = $conn->real_escape_string(trim($_POST['container_no'] ?? ''));
        $ves  = $conn->real_escape_string(trim($_POST['vessel'] ?? ''));
        $cons = $conn->real_escape_string(trim($_POST['consignee'] ?? ''));
        $dest = $conn->real_escape_string(trim($_POST['destination'] ?? ''));
        $book = $conn->real_escape_string(trim($_POST['booking_ref'] ?? ''));
        $num  = proc_next_number($conn, 'proc_shipments', 'shipment_no', 'SH');
        $conn->query("INSERT INTO proc_shipments (shipment_no,container_no,vessel,consignee,destination,booking_ref,seasonid,created_by)
                      VALUES ('$num','$cont','$ves','$cons','$dest','$book',".proc_seasonid($conn).",$userid)");
        if ($conn->error) { $err = "Create failed: ".$conn->error; }
        else { audit_log($conn,$spid,$userid,'proc_shipments','create',$conn->insert_id,null,['no'=>$num]);
               header("Location: proc_shipments.php?id=".$conn->insert_id."&m=created"); exit; }
    }

    if ($action === 'add_carton') {
        $sid = (int)($_POST['shipment_id'] ?? 0);
        $cnum = $conn->real_escape_string(trim($_POST['carton_num'] ?? ''));
        $sr = $conn->query("SELECT status FROM proc_shipments WHERE id=$sid LIMIT 1");
        $sh = ($sr && $sr->num_rows) ? $sr->fetch_assoc() : null;
        if (!$sh)                              $err = "Shipment not found.";
        elseif ($sh['status'] !== 'loading')   $err = "Shipment is sealed — cannot add cartons.";
        else {
            $cr = $conn->query("SELECT id,status,shipment_id FROM proc_cartons WHERE carton_num='$cnum' LIMIT 1");
            $c = ($cr && $cr->num_rows) ? $cr->fetch_assoc() : null;
            if (!$c)                                                       $err = "Carton '$cnum' not found.";
            elseif ($c['status'] === 'shipped')                           $err = "Carton already shipped.";
            elseif (!empty($c['shipment_id']) && (int)$c['shipment_id'] !== $sid)
                                                                          $err = "Carton already on shipment #".$c['shipment_id'].".";
            else {
                $conn->query("UPDATE proc_cartons SET shipment_id=$sid,status='staged' WHERE id={$c['id']}");
                proc_shipment_refresh($conn,$sid);
                $msg = "Carton $cnum loaded.";
            }
        }
    }

    if ($action === 'remove_carton') {
        $sid = (int)($_POST['shipment_id'] ?? 0); $cid = (int)($_POST['carton_id'] ?? 0);
        $sr = $conn->query("SELECT status FROM proc_shipments WHERE id=$sid LIMIT 1");
        $sh = ($sr && $sr->num_rows) ? $sr->fetch_assoc() : null;
        if ($sh && $sh['status'] === 'loading') {
            $conn->query("UPDATE proc_cartons SET shipment_id=NULL,status='packed' WHERE id=$cid AND shipment_id=$sid AND status<>'shipped'");
            proc_shipment_refresh($conn,$sid);
            $msg = "Carton removed.";
        } else $err = "Cannot modify a sealed shipment.";
    }

    if ($action === 'seal' || $action === 'ship') {
        $sid = (int)($_POST['shipment_id'] ?? 0);
        $t = proc_shipment_refresh($conn,$sid);
        if ((int)$t['carton_count'] === 0) $err = "Shipment is empty.";
        elseif ($action === 'seal') {
            $conn->query("UPDATE proc_shipments SET status='sealed',sealed_at=NOW() WHERE id=$sid AND status='loading'");
            $msg = "Shipment sealed.";
        } else {
            $conn->query("UPDATE proc_cartons SET status='shipped' WHERE shipment_id=$sid");
            $conn->query("UPDATE proc_shipments SET status='shipped',shipped_at=NOW(),sealed_at=COALESCE(sealed_at,NOW()) WHERE id=$sid");
            audit_log($conn,$spid,$userid,'proc_shipments','ship',$sid,null,null);
            $msg = "Shipment dispatched for export.";
        }
    }
}
if (($_GET['m'] ?? '') === 'created') $msg = "Shipment created — load cartons below.";

$view_id = (int)($_GET['id'] ?? 0);
include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Export Shipments</h2><p>Group export cartons into containers, seal, and dispatch for export</p></div>
  <a href="processing.php" class="btn btn-ghost btn-sm">← Dashboard</a>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<?php if ($view_id):
    $sr = $conn->query("SELECT * FROM proc_shipments WHERE id=$view_id LIMIT 1");
    $sh = ($sr && $sr->num_rows) ? $sr->fetch_assoc() : null;
    if (!$sh): ?>
        <div class="card"><div class="card-body">Shipment not found. <a href="proc_shipments.php">Back</a></div></div>
    <?php else:
        $loading = $sh['status'] === 'loading';
        $cl = $conn->query("SELECT c.id,c.carton_num,c.content_type,c.blend_code,c.net_mass,c.gross_mass,c.status,b.batch_num
                            FROM proc_cartons c LEFT JOIN proc_batches b ON b.id=c.batch_id
                            WHERE c.shipment_id=$view_id ORDER BY c.carton_num");
        $cartons = ($cl && $cl !== true) ? $cl->fetch_all(MYSQLI_ASSOC) : [];
        $sbadge = ['loading'=>'badge-gold','sealed'=>'badge-blue','shipped'=>'badge-green'][$sh['status']] ?? 'badge-gray';
    ?>
    <div class="card" style="margin-bottom:1.25rem">
      <div class="card-head">
        <h3><?=htmlspecialchars($sh['shipment_no'])?> <span class="badge <?=$sbadge?>"><?=strtoupper($sh['status'])?></span></h3>
        <div style="display:flex;gap:.4rem">
          <a href="proc_shipments.php?pl=<?=$view_id?>" class="btn btn-ghost btn-sm" target="_blank">Packing List PDF</a>
          <a href="proc_shipments.php" class="btn btn-ghost btn-sm">All Shipments</a>
        </div>
      </div>
      <div class="card-body">
        <div class="form-row cols-2" style="gap:.4rem 2rem">
          <div><strong>Container:</strong> <?=htmlspecialchars($sh['container_no']?:'—')?></div>
          <div><strong>Vessel:</strong> <?=htmlspecialchars($sh['vessel']?:'—')?></div>
          <div><strong>Consignee:</strong> <?=htmlspecialchars($sh['consignee']?:'—')?></div>
          <div><strong>Destination:</strong> <?=htmlspecialchars($sh['destination']?:'—')?></div>
          <div><strong>Booking Ref:</strong> <?=htmlspecialchars($sh['booking_ref']?:'—')?></div>
          <div><strong>Totals:</strong> <?=number_format((int)$sh['carton_count'])?> cartons ·
               <?=number_format((float)$sh['net_mass'],1)?> kg net / <?=number_format((float)$sh['gross_mass'],1)?> kg gross</div>
        </div>
      </div>
    </div>

    <?php if ($loading): ?>
    <div class="card" style="margin-bottom:1.25rem">
      <div class="card-head"><h3>Load Carton</h3></div>
      <div class="card-body">
        <form method="POST" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
          <input type="hidden" name="action" value="add_carton">
          <input type="hidden" name="shipment_id" value="<?=$view_id?>">
          <div class="form-group" style="margin:0;flex:1;min-width:220px">
            <label>Carton Number (scan / type)</label>
            <input type="text" name="carton_num" class="form-control" autofocus required placeholder="e.g. CT-2026-0001">
          </div>
          <button class="btn btn-primary">Add to Container</button>
        </form>
        <p style="font-size:.72rem;color:var(--muted);margin:.5rem 0 0">Only packed/staged cartons not already on another shipment can be loaded.</p>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head"><h3>Cartons in Container (<?=count($cartons)?>)</h3>
        <?php if ($loading && $cartons): ?>
        <div style="display:flex;gap:.4rem">
          <form method="POST" onsubmit="return confirm('Seal this shipment? No more cartons can be added.')">
            <input type="hidden" name="action" value="seal"><input type="hidden" name="shipment_id" value="<?=$view_id?>">
            <button class="btn btn-ghost btn-sm">Seal Container</button>
          </form>
          <form method="POST" onsubmit="return confirm('Dispatch this shipment for EXPORT? Cartons will be marked SHIPPED.')">
            <input type="hidden" name="action" value="ship"><input type="hidden" name="shipment_id" value="<?=$view_id?>">
            <button class="btn btn-primary btn-sm">Ship</button>
          </form>
        </div>
        <?php elseif ($sh['status']==='sealed'): ?>
          <form method="POST" onsubmit="return confirm('Dispatch this shipment for EXPORT? Cartons will be marked SHIPPED.')">
            <input type="hidden" name="action" value="ship"><input type="hidden" name="shipment_id" value="<?=$view_id?>">
            <button class="btn btn-primary btn-sm">Ship</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if (!$cartons): ?>
      <div class="card-body" style="color:var(--muted);font-size:.82rem">No cartons loaded yet.</div>
      <?php else: ?>
      <div class="table-wrap"><table>
      <thead><tr><th>Carton</th><th>Batch</th><th>Content</th><th>Blend</th>
      <th style="text-align:right">Net kg</th><th style="text-align:right">Gross kg</th><th>Status</th><?php if($loading):?><th></th><?php endif;?></tr></thead>
      <tbody>
      <?php foreach ($cartons as $c):
        $cb = ['packed'=>'badge-gold','staged'=>'badge-blue','shipped'=>'badge-green'][$c['status']] ?? 'badge-gray'; ?>
      <tr>
        <td style="font-weight:600"><?=htmlspecialchars($c['carton_num'])?></td>
        <td><?=htmlspecialchars($c['batch_num']?:'—')?></td>
        <td><?=ucfirst($c['content_type'])?></td>
        <td><?=htmlspecialchars($c['blend_code']?:'—')?></td>
        <td style="text-align:right"><?=number_format($c['net_mass'],1)?></td>
        <td style="text-align:right"><?=$c['gross_mass']>0?number_format($c['gross_mass'],1):'—'?></td>
        <td><span class="badge <?=$cb?>"><?=strtoupper($c['status'])?></span></td>
        <?php if($loading):?>
        <td><form method="POST" onsubmit="return confirm('Remove carton from container?')">
          <input type="hidden" name="action" value="remove_carton"><input type="hidden" name="shipment_id" value="<?=$view_id?>">
          <input type="hidden" name="carton_id" value="<?=$c['id']?>">
          <button class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--danger)">Remove</button>
        </form></td>
        <?php endif;?>
      </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>

    <?php endif; ?>

<?php else: // ── LIST + CREATE ──────────────────────────────────────────────── ?>
<div class="premium-tabs-nav" data-tabs="shipments"<?= $err ? ' data-force="new"' : '' ?>>
  <button type="button" class="premium-tab-btn active" data-tab="list">🚢 Shipments</button>
  <button type="button" class="premium-tab-btn" data-tab="new">➕ New Shipment</button>
</div>

<div id="panel-new" class="tab-panel" data-tabs="shipments">
<div class="card">
  <div class="card-head"><h3>New Export Shipment</h3></div>
  <div class="card-body">
  <form method="POST">
    <input type="hidden" name="action" value="create">
    <div class="form-row cols-2">
      <div class="form-group"><label>Container No.</label><input type="text" name="container_no" class="form-control" placeholder="e.g. MSKU1234567"></div>
      <div class="form-group"><label>Vessel</label><input type="text" name="vessel" class="form-control" placeholder="e.g. Maersk Cadiz"></div>
    </div>
    <div class="form-row cols-2">
      <div class="form-group"><label>Consignee</label><input type="text" name="consignee" class="form-control" placeholder="buyer / importer"></div>
      <div class="form-group"><label>Destination</label><input type="text" name="destination" class="form-control" placeholder="e.g. Shanghai"></div>
    </div>
    <div class="form-group"><label>Booking Reference</label><input type="text" name="booking_ref" class="form-control" placeholder="shipping line booking ref"></div>
    <button class="btn btn-primary" style="width:100%">Create Shipment</button>
  </form>
  </div>
</div>
</div><!-- /panel-new -->

<?php
$sl = $conn->query("SELECT * FROM proc_shipments ORDER BY id DESC LIMIT 200");
$ships = ($sl && $sl !== true) ? $sl->fetch_all(MYSQLI_ASSOC) : [];
?>
<div id="panel-list" class="tab-panel active" data-tabs="shipments">
<div class="card">
  <div class="card-head"><h3>Shipments</h3></div>
  <?php if (!$ships): ?>
  <div class="card-body" style="color:var(--muted);font-size:.82rem">No shipments yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
  <thead><tr><th>Shipment</th><th>Container</th><th>Destination</th>
  <th style="text-align:right">Cartons</th><th style="text-align:right">Net kg</th><th>Status</th><th>Created</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($ships as $s):
    $sb = ['loading'=>'badge-gold','sealed'=>'badge-blue','shipped'=>'badge-green'][$s['status']] ?? 'badge-gray'; ?>
  <tr>
    <td style="font-weight:600"><a href="proc_shipments.php?id=<?=$s['id']?>" style="color:var(--gold)"><?=htmlspecialchars($s['shipment_no'])?></a></td>
    <td><?=htmlspecialchars($s['container_no']?:'—')?></td>
    <td><?=htmlspecialchars($s['destination']?:'—')?></td>
    <td style="text-align:right"><?=(int)$s['carton_count']?></td>
    <td style="text-align:right"><?=number_format((float)$s['net_mass'],1)?></td>
    <td><span class="badge <?=$sb?>"><?=strtoupper($s['status'])?></span></td>
    <td style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars(substr($s['created_at'],0,16))?></td>
    <td><a href="proc_shipments.php?id=<?=$s['id']?>" class="btn btn-ghost btn-sm" style="font-size:.65rem">Open</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
</div><!-- /panel-list -->
<?php endif; ?>
<?php include "includes/layout_end.php"; ?>
