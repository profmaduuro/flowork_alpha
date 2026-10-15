<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_once "includes/ph_schema.php";
require_login();

$page_title = "Reclassify"; $active_page = "ph_reclassify";
$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$msg = $err = '';
ph_ensure_tables($conn);

// AJAX: look up a floor bale before reclassifying (preview the grade)
if (isset($_GET['ajax']) && $_GET['ajax']==='floor') {
    header('Content-Type: application/json');
    $f = ph_lookup_floor_bale($conn, $_GET['q'] ?? '');
    echo json_encode($f ? ['ok'=>true,'bale'=>$f] : ['ok'=>false,'error'=>'Not found or not sold']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '')==='reclassify') {
    $phbc = $conn->real_escape_string(trim($_POST['ph_barcode'] ?? ''));
    $src  = trim($_POST['source_barcode'] ?? '');
    $hid  = (int)($_POST['house_id'] ?? 0);
    if (!$phbc || !$src || !$hid) { $err = "Bale barcode, packing-house barcode and house are all required."; }
    else {
        $ex = $conn->query("SELECT id FROM ph_bales WHERE ph_barcode='$phbc' LIMIT 1");
        if ($ex && $ex->num_rows) { $err = "Packing-house barcode '$phbc' already used."; }
        else {
            $floor = ph_lookup_floor_bale($conn, $src);
            if (!$floor) { $err = "Bale '$src' not found or not sold (no buyer grade)."; }
            else {
                $srcE=$conn->real_escape_string($src); $gE=$conn->real_escape_string($floor['buyer_grade']??'');
                $grE=$conn->real_escape_string($floor['grower_num']??''); $sb=(int)($floor['sold_balesid']??0);
                $bg=(int)($floor['buyer_gradesid']??0); $mass=(float)($floor['mass']??0);
                $conn->query("INSERT INTO ph_bales (ph_barcode,source_barcode,sold_balesid,buyer_gradesid,buyer_grade,grower_num,mass,house_id,reclassified_by)
                    VALUES ('$phbc','$srcE',$sb,$bg,'$gE','$grE',$mass,$hid,$userid)");
                audit_log($conn,$spid,$userid,'ph_bales','reclassify',$conn->insert_id,null,['ph'=>$phbc,'grade'=>$floor['buyer_grade']]);
                $msg = "✓ Reclassified $phbc — grade {$floor['buyer_grade']}, grower {$floor['grower_num']}, ".number_format($mass,0)." kg.";
            }
        }
    }
}

$houses = $conn->query("SELECT id,code,name FROM ph_houses WHERE active=1 ORDER BY code");
$houses = ($houses && $houses!==true) ? $houses->fetch_all(MYSQLI_ASSOC) : [];
$recent = $conn->query("SELECT pb.*, h.code house_code FROM ph_bales pb JOIN ph_houses h ON h.id=pb.house_id ORDER BY pb.id DESC LIMIT 25");
$recent = ($recent && $recent!==true) ? $recent->fetch_all(MYSQLI_ASSOC) : [];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Reclassify Bales</h2><p>Scan the floor bale, capture its packing-house barcode — grade is inherited from the sale</p></div>
  <a href="packhouse.php" class="btn btn-ghost btn-sm">← Packing House</a>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="card" style="margin-bottom:1.25rem">
<div class="card-body">
<form method="POST">
<input type="hidden" name="action" value="reclassify">
<div class="form-row cols-2">
  <div class="form-group">
    <label>Floor Bale Barcode *</label>
    <input name="source_barcode" id="src" class="form-control" required autofocus placeholder="Scan floor barcode" style="font-family:monospace" onblur="preview()">
    <div id="gradePreview" style="font-size:.78rem;margin-top:.35rem"></div>
  </div>
  <div class="form-group">
    <label>Packing-House Barcode *</label>
    <input name="ph_barcode" class="form-control" required placeholder="Scan pre-printed label" style="font-family:monospace">
  </div>
</div>
<div class="form-group">
  <label>Packing House *</label>
  <select name="house_id" class="form-control" required>
    <option value="">— select —</option>
    <?php foreach($houses as $h):?><option value="<?=$h['id']?>"><?=htmlspecialchars($h['code'].' — '.$h['name'])?></option><?php endforeach;?>
  </select>
</div>
<button class="btn btn-primary" style="width:100%">Reclassify Bale</button>
</form>
</div>
</div>

<div class="card">
<div class="card-head"><h3>Recently Reclassified</h3></div>
<?php if(!$recent):?><div class="card-body" style="color:var(--muted);font-size:.82rem">None yet.</div>
<?php else:?>
<div class="table-wrap"><table>
<thead><tr><th>PH Barcode</th><th>Floor Barcode</th><th>House</th><th>Grade</th><th>Grower</th><th class="text-right">kg</th><th>Status</th></tr></thead>
<tbody>
<?php foreach($recent as $r): $bd=['reclassified'=>'badge-gold','palletised'=>'badge-green','dispatched'=>'badge-gray'][$r['status']]??'badge-gray';?>
<tr>
  <td style="font-weight:600"><?=htmlspecialchars($r['ph_barcode'])?></td>
  <td style="font-size:.75rem"><?=htmlspecialchars($r['source_barcode'])?></td>
  <td><span class="badge badge-blue" style="font-size:.6rem"><?=htmlspecialchars($r['house_code'])?></span></td>
  <td><?=htmlspecialchars($r['buyer_grade']?:'—')?></td>
  <td><?=htmlspecialchars($r['grower_num']?:'—')?></td>
  <td class="text-right"><?=number_format($r['mass'],0)?></td>
  <td><span class="badge <?=$bd?>"><?=strtoupper($r['status'])?></span></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</div>

<script>
function preview(){
  var bc=document.getElementById('src').value.trim();
  var box=document.getElementById('gradePreview');
  if(!bc){box.innerHTML='';return;}
  fetch('ph_reclassify.php?ajax=floor&q='+encodeURIComponent(bc))
   .then(function(r){return r.json();})
   .then(function(d){
     if(d.ok){box.innerHTML='<span style="color:var(--leaf)">✓ Grade <b>'+esc(d.bale.buyer_grade)+'</b> · '+esc(d.bale.grower_num)+' · '+parseFloat(d.bale.mass).toFixed(0)+' kg</span>';}
     else{box.innerHTML='<span style="color:var(--danger)">✗ '+esc(d.error)+'</span>';}
   }).catch(function(){});
}
function esc(s){return String(s==null?'':s).replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});}
</script>
<?php include "includes/layout_end.php"; ?>
