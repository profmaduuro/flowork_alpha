<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_once "includes/ph_schema.php";
require_login();

$page_title = "Packing House"; $active_page = "packhouse";
$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$msg = $err = '';
ph_ensure_tables($conn);

// ── House / line setup actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_house') {
        $code = $conn->real_escape_string(strtoupper(trim($_POST['code'] ?? '')));
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $loc  = $conn->real_escape_string(trim($_POST['location'] ?? ''));
        $hid  = (int)($_POST['house_id'] ?? 0);
        if (!$code) { $err = "House code is required."; }
        elseif ($hid) {
            $conn->query("UPDATE ph_houses SET code='$code',name='$name',location='$loc' WHERE id=$hid");
            $msg = "✓ Packing house updated.";
        } else {
            $ex = $conn->query("SELECT id FROM ph_houses WHERE code='$code' LIMIT 1");
            if ($ex && $ex->num_rows) { $err = "House code '$code' already exists."; }
            else { $conn->query("INSERT INTO ph_houses (code,name,location) VALUES ('$code','$name','$loc')"); $msg = "✓ Packing house '$code' added."; }
        }
    }
    if ($action === 'add_line') {
        $hid = (int)($_POST['house_id'] ?? 0);
        $ln  = $conn->real_escape_string(trim($_POST['line_no'] ?? ''));
        if ($hid && $ln) {
            $conn->query("INSERT IGNORE INTO ph_lines (house_id,line_no) VALUES ($hid,'$ln')");
            $msg = "✓ Line $ln added.";
        }
    }
    if ($action === 'toggle_house') {
        $hid = (int)($_POST['house_id'] ?? 0);
        $conn->query("UPDATE ph_houses SET active=1-active WHERE id=$hid");
        $msg = "House status toggled.";
    }
}

// ── KPIs ────────────────────────────────────────────────────────────────────
$kpi = ['awaiting'=>0,'pallets'=>0,'full'=>0,'bales'=>0,'dispatches'=>0];
$kpi['awaiting']   = (int)$conn->query("SELECT COUNT(*) c FROM ph_bales WHERE status='reclassified'")->fetch_assoc()['c'];
$kpi['pallets']    = (int)$conn->query("SELECT COUNT(*) c FROM ph_pallets WHERE status IN ('open','full')")->fetch_assoc()['c'];
$kpi['full']       = (int)$conn->query("SELECT COUNT(*) c FROM ph_pallets WHERE status='full'")->fetch_assoc()['c'];
$kpi['bales']      = (int)$conn->query("SELECT COUNT(*) c FROM ph_bales WHERE status='palletised'")->fetch_assoc()['c'];
$kpi['dispatches'] = (int)$conn->query("SELECT COUNT(*) c FROM ph_dispatch WHERE status='dispatched'")->fetch_assoc()['c'];

$houses = $conn->query("SELECT h.*,
    (SELECT COUNT(*) FROM ph_lines l WHERE l.house_id=h.id AND l.active=1) line_count,
    (SELECT COUNT(*) FROM ph_pallets p WHERE p.house_id=h.id AND p.status IN ('open','full')) pallet_count
    FROM ph_houses h ORDER BY h.code");
$houses = ($houses && $houses !== true) ? $houses->fetch_all(MYSQLI_ASSOC) : [];

// Pallets per line, by house
$pallets_q = $conn->query("SELECT p.*, h.code house_code, l.line_no FROM ph_pallets p
    JOIN ph_houses h ON h.id=p.house_id LEFT JOIN ph_lines l ON l.id=p.line_id
    WHERE p.status IN ('open','full') ORDER BY h.code, l.line_no, p.pallet_no LIMIT 200");
$pallets = ($pallets_q && $pallets_q !== true) ? $pallets_q->fetch_all(MYSQLI_ASSOC) : [];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Packing House</h2><p>Reclassify → palletise → lines → dispatch to processing</p></div>
  <div>
    <a href="ph_reclassify.php" class="btn btn-ghost btn-sm">Reclassify</a>
    <a href="ph_pallets.php" class="btn btn-ghost btn-sm">Pallets</a>
    <a href="ph_dispatch.php" class="btn btn-primary btn-sm">Dispatch</a>
  </div>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="kpi-grid">
  <div class="kpi-card" style="--kpi-color:var(--gold)"><div class="kpi-label">Awaiting Pallet</div><div class="kpi-value"><?=number_format($kpi['awaiting'])?></div><div class="kpi-sub">reclassified, not palletised</div></div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)"><div class="kpi-label">Pallets in Store</div><div class="kpi-value"><?=number_format($kpi['pallets'])?></div><div class="kpi-sub"><?=number_format($kpi['full'])?> full</div></div>
  <div class="kpi-card" style="--kpi-color:var(--info)"><div class="kpi-label">Bales Palletised</div><div class="kpi-value"><?=number_format($kpi['bales'])?></div><div class="kpi-sub">in store on pallets</div></div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)"><div class="kpi-label">Dispatches</div><div class="kpi-value"><?=number_format($kpi['dispatches'])?></div><div class="kpi-sub">sent to processing</div></div>
</div>

<!-- Bale locator -->
<div class="card" style="margin-bottom:1.25rem">
<div class="card-head"><h3>Locate a Bale</h3></div>
<div class="card-body">
  <div style="display:flex;gap:.5rem">
    <input id="locBc" class="form-control" placeholder="Scan or type bale barcode (floor or packing-house)…" style="flex:1;font-family:monospace" onkeydown="if(event.key==='Enter')locate()">
    <button class="btn btn-primary" onclick="locate()">🔍 Locate</button>
  </div>
  <div id="locResult" style="margin-top:.75rem;font-size:.9rem"></div>
</div>
</div>

<div style="display:grid;grid-template-columns:1.2fr 1fr;gap:1.25rem">
  <!-- Pallets per line -->
  <div class="card">
  <div class="card-head"><h3>Pallets in Store</h3></div>
  <?php if(!$pallets):?><div class="card-body" style="color:var(--muted);font-size:.82rem">No pallets in store. Start at Reclassify, then Pallets.</div>
  <?php else:?>
  <div class="table-wrap"><table>
  <thead><tr><th>House</th><th>Line</th><th>Pallet</th><th>Buyer Grade</th><th class="text-right">Bales</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach($pallets as $p): $bd=['open'=>'badge-gold','full'=>'badge-green','dispatched'=>'badge-gray'][$p['status']]??'badge-gray';?>
  <tr>
    <td><span class="badge badge-blue" style="font-size:.72rem"><?=htmlspecialchars($p['house_code'])?></span></td>
    <td><?=htmlspecialchars($p['line_no']?:'—')?></td>
    <td><a href="ph_pallets.php?view=<?=htmlspecialchars($p['pallet_no'])?>" style="font-weight:600;color:var(--gold)"><?=htmlspecialchars($p['pallet_no'])?></a></td>
    <td><?=htmlspecialchars($p['buyer_grade']?:'—')?></td>
    <td class="text-right"><?=(int)$p['bale_count']?>/12</td>
    <td><span class="badge <?=$bd?>"><?=strtoupper($p['status'])?></span></td>
  </tr>
  <?php endforeach;?>
  </tbody></table></div>
  <?php endif;?>
  </div>

  <!-- Houses & lines setup -->
  <div class="card">
  <div class="card-head"><h3>Packing Houses</h3></div>
  <div class="table-wrap"><table>
  <thead><tr><th>Code</th><th>Name / Location</th><th class="text-right">Lines</th><th></th></tr></thead>
  <tbody>
  <?php foreach($houses as $h):?>
  <tr style="<?=$h['active']?'':'opacity:.5'?>">
    <td style="font-weight:600"><?=htmlspecialchars($h['code'])?></td>
    <td style="font-size:.78rem"><?=htmlspecialchars($h['name'])?><?=$h['location']?'<br><span style="color:var(--muted);font-size:.7rem">'.htmlspecialchars($h['location']).'</span>':''?></td>
    <td class="text-right"><?=(int)$h['line_count']?></td>
    <td>
      <form method="POST" style="display:inline-flex;gap:.25rem;align-items:center">
        <input type="hidden" name="action" value="add_line"><input type="hidden" name="house_id" value="<?=$h['id']?>">
        <input name="line_no" class="form-control" style="width:48px;font-size:.7rem;padding:2px 6px" placeholder="+L">
        <button class="btn btn-ghost btn-sm" style="font-size:.72rem">Add line</button>
      </form>
    </td>
  </tr>
  <?php endforeach;?>
  </tbody></table></div>
  <div class="card-body">
    <form method="POST" class="form-row cols-2" style="margin:0">
      <input type="hidden" name="action" value="save_house">
      <div class="form-group" style="margin:0"><input name="code" class="form-control" placeholder="Code (e.g. TPZ)" style="text-transform:uppercase" required></div>
      <div class="form-group" style="margin:0"><input name="name" class="form-control" placeholder="Name"></div>
      <div class="form-group" style="margin:0"><input name="location" class="form-control" placeholder="Location (e.g. Willovale)"></div>
      <div class="form-group" style="margin:0"><button class="btn btn-primary" style="width:100%">Add House</button></div>
    </form>
  </div>
  </div>
</div>

<script>
function locate(){
  var bc=document.getElementById('locBc').value.trim();
  var box=document.getElementById('locResult');
  if(!bc){box.innerHTML='';return;}
  box.innerHTML='<span style="color:var(--muted)">Searching…</span>';
  fetch('ph_pallets.php?ajax=locate&q='+encodeURIComponent(bc))
   .then(function(r){return r.json();})
   .then(function(d){
     if(d.ok){
       var x=d.bale;
       box.innerHTML='<div style="padding:.75rem 1rem;background:var(--leaf-l);border-radius:8px;border:1px solid #9bcb94">'
         +'<div style="font-weight:700;color:var(--ok-text);font-size:1rem">📍 '+esc(x.location_label)+'</div>'
         +'<div style="font-size:.8rem;color:var(--muted);margin-top:.3rem">Grade <b>'+esc(x.buyer_grade)+'</b> · Grower '+esc(x.grower_num)+' · '+parseFloat(x.mass).toFixed(0)+' kg · '+esc(x.status)+'</div></div>';
     } else { box.innerHTML='<span style="color:var(--danger)">'+esc(d.error||'Not found')+'</span>'; }
   }).catch(function(e){box.innerHTML='<span style="color:var(--danger)">Error</span>';});
}
function esc(s){return String(s==null?'':s).replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});}
</script>
<?php include "includes/layout_end.php"; ?>
