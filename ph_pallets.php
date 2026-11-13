<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_once "includes/ph_schema.php";
require_login();

$page_title = "Pallets"; $active_page = "ph_pallets";
$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$msg = $err = '';
ph_ensure_tables($conn);

// AJAX: locate a bale (used by the dashboard locator too)
if (isset($_GET['ajax']) && $_GET['ajax']==='locate') {
    header('Content-Type: application/json');
    $loc = ph_locate_bale($conn, $_GET['q'] ?? '');
    if (!$loc) { echo json_encode(['ok'=>false,'error'=>'Bale not found in any packing house']); exit; }
    $loc['location_label'] = trim($loc['house_name'].($loc['house_location']?' · '.$loc['house_location']:'')
        .($loc['pallet_no']?' · Pallet '.$loc['pallet_no']:'').($loc['line_no']?' · Line '.$loc['line_no']:''));
    echo json_encode(['ok'=>true,'bale'=>$loc]); exit;
}

// Shared add-bale (12 + same grade rules)
function add_bale(mysqli $conn,$pid,$phbc,&$err){
    $phbcE=$conn->real_escape_string(trim($phbc));
    $p=$conn->query("SELECT * FROM ph_pallets WHERE id=$pid LIMIT 1"); if(!$p||!$p->num_rows){$err="Pallet not found.";return false;}
    $pallet=$p->fetch_assoc();
    if($pallet['status']==='dispatched'){$err="Pallet already dispatched.";return false;}
    if((int)$pallet['bale_count']>=PH_PALLET_CAPACITY){$err="Pallet is full (12 bales).";return false;}
    $b=$conn->query("SELECT * FROM ph_bales WHERE ph_barcode='$phbcE' LIMIT 1"); if(!$b||!$b->num_rows){$err="Bale '$phbc' not reclassified yet.";return false;}
    $bale=$b->fetch_assoc();
    if(!empty($bale['pallet_id'])){$err="Bale already on a pallet.";return false;}
    if($bale['status']==='dispatched'){$err="Bale already dispatched.";return false;}
    $pBg=(int)$pallet['buyer_gradesid']; $bBg=(int)$bale['buyer_gradesid'];
    if($pBg===0){$gE=$conn->real_escape_string($bale['buyer_grade']);$conn->query("UPDATE ph_pallets SET buyer_gradesid=$bBg,buyer_grade='$gE' WHERE id=$pid");}
    elseif($pBg!==$bBg){$err="Different buyer grade — pallet holds {$pallet['buyer_grade']}, bale is {$bale['buyer_grade']}.";return false;}
    $conn->query("UPDATE ph_bales SET pallet_id=$pid,status='palletised' WHERE id={$bale['id']}");
    $conn->query("UPDATE ph_pallets SET bale_count=bale_count+1 WHERE id=$pid");
    $nc=(int)$pallet['bale_count']+1;
    if($nc>=PH_PALLET_CAPACITY)$conn->query("UPDATE ph_pallets SET status='full' WHERE id=$pid");
    return $nc;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $pno=$conn->real_escape_string(trim($_POST['pallet_no'] ?? '')); $hid=(int)($_POST['house_id']??0); $lid=(int)($_POST['line_id']??0);
        if(!$pno||!$hid){$err="Pallet number and house are required.";}
        else{
            $ex=$conn->query("SELECT id FROM ph_pallets WHERE pallet_no='$pno' LIMIT 1");
            if($ex&&$ex->num_rows){$err="Pallet number '$pno' already exists.";}
            else{$lidSql=$lid>0?$lid:'NULL';$conn->query("INSERT INTO ph_pallets (pallet_no,house_id,line_id,created_by) VALUES ('$pno',$hid,$lidSql,$userid)");
                audit_log($conn,$spid,$userid,'ph_pallets','create',$conn->insert_id,null,['pallet'=>$pno]);
                header("Location: ph_pallets.php?view=".urlencode($pno)); exit;}
        }
    }
    if ($action === 'add_bale') {
        $pid=(int)($_POST['pallet_id']??0); $phbc=$_POST['ph_barcode']??'';
        $nc=add_bale($conn,$pid,$phbc,$err);
        if($nc!==false)$msg="✓ Bale added ($nc/12)".($nc>=12?" — pallet full":"");
    }
    if ($action === 'assign_line') {
        $pid=(int)($_POST['pallet_id']??0); $lid=(int)($_POST['line_id']??0);
        $conn->query("UPDATE ph_pallets SET line_id=$lid WHERE id=$pid"); $msg="✓ Pallet assigned to line.";
    }
}

// View a specific pallet
$view = trim($_GET['view'] ?? '');
$pallet = null;
if ($view!=='') {
    $vE=$conn->real_escape_string($view);
    $q=$conn->query("SELECT p.*,h.code house_code,h.name house_name,h.location house_location,l.line_no FROM ph_pallets p
        JOIN ph_houses h ON h.id=p.house_id LEFT JOIN ph_lines l ON l.id=p.line_id WHERE p.pallet_no='$vE' LIMIT 1");
    $pallet=($q&&$q!==true&&$q->num_rows)?$q->fetch_assoc():null;
    if($pallet){
        $bl=$conn->query("SELECT * FROM ph_bales WHERE pallet_id={$pallet['id']} ORDER BY id");
        $pbales=($bl&&$bl!==true)?$bl->fetch_all(MYSQLI_ASSOC):[];
        $lq=$conn->query("SELECT id,line_no FROM ph_lines WHERE house_id={$pallet['house_id']} AND active=1 ORDER BY line_no");
        $plines=($lq&&$lq!==true)?$lq->fetch_all(MYSQLI_ASSOC):[];
    }
}

$houses=$conn->query("SELECT id,code,name FROM ph_houses WHERE active=1 ORDER BY code");
$houses=($houses&&$houses!==true)?$houses->fetch_all(MYSQLI_ASSOC):[];
$list=$conn->query("SELECT p.*,h.code house_code,l.line_no FROM ph_pallets p JOIN ph_houses h ON h.id=p.house_id
    LEFT JOIN ph_lines l ON l.id=p.line_id ORDER BY p.id DESC LIMIT 100");
$list=($list&&$list!==true)?$list->fetch_all(MYSQLI_ASSOC):[];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Pallets</h2><p>12 bales of one buyer grade per pallet · search a pallet to see its bales</p></div>
  <div>
    <form method="GET" style="display:inline-flex;gap:.4rem">
      <input name="view" class="form-control btn-sm" placeholder="Search pallet no." value="<?=htmlspecialchars($view)?>" style="width:140px">
      <button class="btn btn-ghost btn-sm">Search</button>
    </form>
    <a href="packhouse.php" class="btn btn-ghost btn-sm">← Packing House</a>
  </div>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>
<?php if($view!=='' && !$pallet):?><div class="alert alert-danger">Pallet '<?=htmlspecialchars($view)?>' not found.</div><?php endif;?>

<?php if($pallet): $bd=['open'=>'badge-gold','full'=>'badge-green','dispatched'=>'badge-gray'][$pallet['status']]??'badge-gray';?>
<div class="card" style="margin-bottom:1.25rem">
<div class="card-head">
  <h3>Pallet <?=htmlspecialchars($pallet['pallet_no'])?> <span class="badge <?=$bd?>" style="margin-left:.4rem"><?=strtoupper($pallet['status'])?></span></h3>
  <div style="font-size:.8rem;color:var(--muted)"><?=htmlspecialchars($pallet['house_code'])?> · <?=htmlspecialchars($pallet['house_location']?:$pallet['house_name'])?> · Line <?=htmlspecialchars($pallet['line_no']?:'—')?> · <?=htmlspecialchars($pallet['buyer_grade']?:'(grade set by first bale)')?></div>
</div>
<div class="card-body">
  <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
    <?php if($pallet['status']!=='dispatched'):?>
    <form method="POST" style="display:flex;gap:.4rem;align-items:flex-end">
      <input type="hidden" name="action" value="add_bale"><input type="hidden" name="pallet_id" value="<?=$pallet['id']?>">
      <div class="form-group" style="margin:0"><label>Scan bale into pallet (<?=(int)$pallet['bale_count']?>/12)</label>
        <input name="ph_barcode" class="form-control" placeholder="Packing-house barcode" style="font-family:monospace" <?=(int)$pallet['bale_count']>=12?'disabled':'autofocus'?>></div>
      <button class="btn btn-primary" <?=(int)$pallet['bale_count']>=12?'disabled':''?>>Add</button>
    </form>
    <form method="POST" style="display:flex;gap:.4rem;align-items:flex-end">
      <input type="hidden" name="action" value="assign_line"><input type="hidden" name="pallet_id" value="<?=$pallet['id']?>">
      <div class="form-group" style="margin:0"><label>Line</label>
        <select name="line_id" class="form-control"><option value="">—</option>
        <?php foreach($plines as $l):?><option value="<?=$l['id']?>" <?=$l['id']==$pallet['line_id']?'selected':''?>>Line <?=htmlspecialchars($l['line_no'])?></option><?php endforeach;?>
        </select></div>
      <button class="btn btn-ghost btn-sm">Set Line</button>
    </form>
    <?php endif;?>
  </div>
  <table style="margin-top:1rem;width:100%">
  <thead><tr><th>#</th><th>PH Barcode</th><th>Floor Barcode</th><th>Grade</th><th>Grower</th><th class="text-right">kg</th></tr></thead>
  <tbody>
  <?php foreach($pbales as $i=>$b):?>
  <tr><td style="color:var(--muted)"><?=$i+1?></td><td style="font-weight:600"><?=htmlspecialchars($b['ph_barcode'])?></td>
  <td style="font-size:.75rem"><?=htmlspecialchars($b['source_barcode'])?></td><td><?=htmlspecialchars($b['buyer_grade'])?></td>
  <td><?=htmlspecialchars($b['grower_num'])?></td><td class="text-right"><?=number_format($b['mass'],0)?></td></tr>
  <?php endforeach;?>
  <?php if(!$pbales):?><tr><td colspan="6" class="text-center" style="color:var(--muted);padding:1rem">No bales on this pallet yet.</td></tr><?php endif;?>
  </tbody></table>
</div>
</div>
<?php endif;?>

<!-- Create pallet -->
<div class="card" style="margin-bottom:1.25rem">
<div class="card-head"><h3>New Pallet</h3></div>
<div class="card-body">
<form method="POST" class="form-row cols-2" style="margin:0">
  <input type="hidden" name="action" value="create">
  <div class="form-group" style="margin:0"><label>Pallet Number (from tag) *</label><input name="pallet_no" class="form-control" required placeholder="e.g. 1001"></div>
  <div class="form-group" style="margin:0"><label>House *</label><select name="house_id" class="form-control" required><option value="">—</option><?php foreach($houses as $h):?><option value="<?=$h['id']?>"><?=htmlspecialchars($h['code'])?></option><?php endforeach;?></select></div>
  <div class="form-group" style="margin:0;grid-column:1/-1"><button class="btn btn-primary" style="width:100%">Create Pallet</button></div>
</form>
</div>
</div>

<div class="card">
<div class="card-head"><h3>All Pallets</h3></div>
<?php if(!$list):?><div class="card-body" style="color:var(--muted);font-size:.82rem">No pallets yet.</div>
<?php else:?>
<div class="table-wrap"><table>
<thead><tr><th>Pallet</th><th>House</th><th>Line</th><th>Buyer Grade</th><th class="text-right">Bales</th><th>Status</th></tr></thead>
<tbody>
<?php foreach($list as $p): $bd=['open'=>'badge-gold','full'=>'badge-green','dispatched'=>'badge-gray'][$p['status']]??'badge-gray';?>
<tr>
  <td><a href="?view=<?=htmlspecialchars($p['pallet_no'])?>" style="font-weight:600;color:var(--gold)"><?=htmlspecialchars($p['pallet_no'])?></a></td>
  <td><span class="badge badge-blue" style="font-size:.72rem"><?=htmlspecialchars($p['house_code'])?></span></td>
  <td><?=htmlspecialchars($p['line_no']?:'—')?></td>
  <td><?=htmlspecialchars($p['buyer_grade']?:'—')?></td>
  <td class="text-right"><?=(int)$p['bale_count']?>/12</td>
  <td><span class="badge <?=$bd?>"><?=strtoupper($p['status'])?></span></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</div>
<?php include "includes/layout_end.php"; ?>
