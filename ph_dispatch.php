<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_once "includes/ph_schema.php";
require_login();

$page_title = "Pallet Dispatch"; $active_page = "ph_dispatch";
$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$msg = $err = '';
ph_ensure_tables($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $hid=(int)($_POST['house_id']??0); $veh=$conn->real_escape_string(trim($_POST['vehicle']??''));
        $dest=$conn->real_escape_string(trim($_POST['destination']??'Processing'));
        if(!$hid){$err="Select a packing house.";}
        else{$no=ph_next_dispatch_no($conn);
            $conn->query("INSERT INTO ph_dispatch (dispatch_no,house_id,vehicle,destination,dispatched_by) VALUES ('$no',$hid,'$veh','$dest',$userid)");
            header("Location: ph_dispatch.php?id=".$conn->insert_id); exit;}
    }
    if ($action === 'add_pallet') {
        $did=(int)($_POST['dispatch_id']??0); $pno=$conn->real_escape_string(trim($_POST['pallet_no']??''));
        $pr=$conn->query("SELECT id,status FROM ph_pallets WHERE pallet_no='$pno' LIMIT 1");
        if(!$pr||!$pr->num_rows){$err="Pallet '$pno' not found.";}
        else{$p=$pr->fetch_assoc();
            if($p['status']==='dispatched'){$err="Pallet '$pno' already dispatched.";}
            else{$conn->query("INSERT IGNORE INTO ph_dispatch_pallets (dispatch_id,pallet_id) VALUES ($did,{$p['id']})");
                $msg=$conn->affected_rows?"✓ Pallet $pno added.":"Pallet already on this dispatch.";}
        }
    }
    if ($action === 'remove_pallet') {
        $did=(int)($_POST['dispatch_id']??0); $pid=(int)($_POST['pallet_id']??0);
        $conn->query("DELETE FROM ph_dispatch_pallets WHERE dispatch_id=$did AND pallet_id=$pid"); $msg="Pallet removed.";
    }
    if ($action === 'send') {
        $did=(int)($_POST['dispatch_id']??0);
        $pids=[];$pr=$conn->query("SELECT pallet_id FROM ph_dispatch_pallets WHERE dispatch_id=$did");
        while($pr&&$row=$pr->fetch_assoc())$pids[]=(int)$row['pallet_id'];
        if(!$pids){$err="No pallets on this dispatch.";}
        else{$idl=implode(',',$pids);
            $bc=(int)$conn->query("SELECT COUNT(*) n FROM ph_bales WHERE pallet_id IN ($idl)")->fetch_assoc()['n'];
            $handed=ph_handoff_to_processing($conn,$pids);   // create proc_intake rows
            $conn->query("UPDATE ph_pallets SET status='dispatched' WHERE id IN ($idl)");
            $conn->query("UPDATE ph_bales SET status='dispatched' WHERE pallet_id IN ($idl)");
            $conn->query("UPDATE ph_dispatch SET status='dispatched',dispatched_at=NOW(),pallet_count=".count($pids).",bale_count=$bc WHERE id=$did");
            audit_log($conn,$spid,$userid,'ph_dispatch','send',$did,null,['pallets'=>count($pids),'bales'=>$bc]);
            $msg="✓ Dispatched ".count($pids)." pallet(s), $bc bale(s) — $handed now at the processing plant.";}
    }
}

$did = (int)($_GET['id'] ?? 0);
$dispatch = null;
if ($did) {
    $q=$conn->query("SELECT d.*,h.code house_code,h.name house_name FROM ph_dispatch d JOIN ph_houses h ON h.id=d.house_id WHERE d.id=$did LIMIT 1");
    $dispatch=($q&&$q!==true&&$q->num_rows)?$q->fetch_assoc():null;
    if($dispatch){
        $dp=$conn->query("SELECT p.*,l.line_no FROM ph_dispatch_pallets dp JOIN ph_pallets p ON p.id=dp.pallet_id
            LEFT JOIN ph_lines l ON l.id=p.line_id WHERE dp.dispatch_id=$did ORDER BY p.pallet_no");
        $dpallets=($dp&&$dp!==true)?$dp->fetch_all(MYSQLI_ASSOC):[];
    }
}

$houses=$conn->query("SELECT id,code,name FROM ph_houses WHERE active=1 ORDER BY code");
$houses=($houses&&$houses!==true)?$houses->fetch_all(MYSQLI_ASSOC):[];
$recent=$conn->query("SELECT d.*,h.code house_code FROM ph_dispatch d JOIN ph_houses h ON h.id=d.house_id ORDER BY d.id DESC LIMIT 25");
$recent=($recent&&$recent!==true)?$recent->fetch_all(MYSQLI_ASSOC):[];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Pallet Dispatch</h2><p>Load whole pallets onto a truck and send them to processing</p></div>
  <a href="packhouse.php" class="btn btn-ghost btn-sm">← Packing House</a>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<?php if($dispatch): $sent=$dispatch['status']==='dispatched';?>
<div class="card" style="margin-bottom:1.25rem">
<div class="card-head">
  <h3><?=htmlspecialchars($dispatch['dispatch_no'])?> <span class="badge <?=$sent?'badge-green':'badge-gold'?>" style="margin-left:.4rem"><?=strtoupper($dispatch['status'])?></span></h3>
  <div style="font-size:.8rem;color:var(--muted)"><?=htmlspecialchars($dispatch['house_code'])?> → <?=htmlspecialchars($dispatch['destination'])?><?=$dispatch['vehicle']?' · '.htmlspecialchars($dispatch['vehicle']):''?></div>
</div>
<div class="card-body">
  <?php if(!$sent):?>
  <form method="POST" style="display:flex;gap:.4rem;align-items:flex-end;margin-bottom:1rem">
    <input type="hidden" name="action" value="add_pallet"><input type="hidden" name="dispatch_id" value="<?=$did?>">
    <div class="form-group" style="margin:0;flex:1"><label>Scan / type pallet number to load</label><input name="pallet_no" class="form-control" autofocus placeholder="e.g. 1001"></div>
    <button class="btn btn-primary">Load Pallet</button>
  </form>
  <?php endif;?>
  <table style="width:100%">
  <thead><tr><th>Pallet</th><th>Line</th><th>Grade</th><th class="text-right">Bales</th><?php if(!$sent):?><th></th><?php endif;?></tr></thead>
  <tbody>
  <?php $tb=0; foreach($dpallets as $p): $tb+=(int)$p['bale_count'];?>
  <tr><td style="font-weight:600"><?=htmlspecialchars($p['pallet_no'])?></td><td><?=htmlspecialchars($p['line_no']?:'—')?></td>
  <td><?=htmlspecialchars($p['buyer_grade']?:'—')?></td><td class="text-right"><?=(int)$p['bale_count']?></td>
  <?php if(!$sent):?><td><form method="POST" style="display:inline"><input type="hidden" name="action" value="remove_pallet"><input type="hidden" name="dispatch_id" value="<?=$did?>"><input type="hidden" name="pallet_id" value="<?=$p['id']?>"><button class="btn btn-ghost btn-sm" style="color:var(--danger);font-size:.65rem">✕</button></form></td><?php endif;?></tr>
  <?php endforeach;?>
  <?php if(!$dpallets):?><tr><td colspan="5" class="text-center" style="color:var(--muted);padding:1rem">No pallets loaded yet.</td></tr><?php endif;?>
  </tbody>
  <tfoot><tr><td colspan="3"><strong><?=count($dpallets)?> pallet(s)</strong></td><td class="text-right"><strong><?=$tb?> bales</strong></td><?php if(!$sent):?><td></td><?php endif;?></tr></tfoot>
  </table>
  <?php if(!$sent && $dpallets):?>
  <form method="POST" style="margin-top:1rem" onsubmit="return confirm('Dispatch <?=count($dpallets)?> pallet(s) to processing? Pallets and bales will be marked dispatched.')">
    <input type="hidden" name="action" value="send"><input type="hidden" name="dispatch_id" value="<?=$did?>">
    <button class="btn btn-primary" style="width:100%">🚚 Dispatch to Processing</button>
  </form>
  <?php endif;?>
</div>
</div>
<?php endif;?>

<div class="card" style="margin-bottom:1.25rem">
<div class="card-head"><h3>New Dispatch</h3></div>
<div class="card-body">
<form method="POST" class="form-row cols-2" style="margin:0">
  <input type="hidden" name="action" value="create">
  <div class="form-group" style="margin:0"><label>Packing House *</label><select name="house_id" class="form-control" required><option value="">—</option><?php foreach($houses as $h):?><option value="<?=$h['id']?>"><?=htmlspecialchars($h['code'])?></option><?php endforeach;?></select></div>
  <div class="form-group" style="margin:0"><label>Vehicle / Truck</label><input name="vehicle" class="form-control" placeholder="e.g. ADZ1234"></div>
  <div class="form-group" style="margin:0;grid-column:1/-1"><button class="btn btn-primary" style="width:100%">Start Dispatch</button></div>
</form>
</div>
</div>

<div class="card">
<div class="card-head"><h3>Recent Dispatches</h3></div>
<?php if(!$recent):?><div class="card-body" style="color:var(--muted);font-size:.82rem">None yet.</div>
<?php else:?>
<div class="table-wrap"><table>
<thead><tr><th>Dispatch</th><th>House</th><th>Vehicle</th><th class="text-right">Pallets</th><th class="text-right">Bales</th><th>Status</th><th>When</th></tr></thead>
<tbody>
<?php foreach($recent as $d):?>
<tr>
  <td><a href="?id=<?=$d['id']?>" style="font-weight:600;color:var(--gold)"><?=htmlspecialchars($d['dispatch_no'])?></a></td>
  <td><span class="badge badge-blue" style="font-size:.72rem"><?=htmlspecialchars($d['house_code'])?></span></td>
  <td style="font-size:.78rem"><?=htmlspecialchars($d['vehicle']?:'—')?></td>
  <td class="text-right"><?=(int)$d['pallet_count']?></td><td class="text-right"><?=(int)$d['bale_count']?></td>
  <td><span class="badge <?=$d['status']==='dispatched'?'badge-green':'badge-gold'?>"><?=strtoupper($d['status'])?></span></td>
  <td style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($d['dispatched_at']?substr($d['dispatched_at'],0,16):'—')?></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</div>
<?php include "includes/layout_end.php"; ?>
