<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "audit.php";
require_login();
$page_title="TIMB Registry Sync";$active_page="timb_registry_sync";
$spid=(int)($_SESSION['selling_pointid']??0);$userid=current_userid();
$msg=$err='';

$conn->query("CREATE TABLE IF NOT EXISTS timb_grower_registry (
    id INT AUTO_INCREMENT PRIMARY KEY,selling_pointid INT NOT NULL,grower_num VARCHAR(40) NOT NULL,
    name VARCHAR(80) DEFAULT '',surname VARCHAR(80) DEFAULT '',national_id VARCHAR(40) DEFAULT '',
    province VARCHAR(60) DEFAULT '',district_name VARCHAR(60) DEFAULT '',area VARCHAR(60) DEFAULT '',
    farm_name VARCHAR(80) DEFAULT '',crop_type VARCHAR(30) DEFAULT 'Virginia',
    registration_date DATE DEFAULT NULL,status ENUM('active','suspended','cancelled') DEFAULT 'active',
    timb_verified TINYINT DEFAULT 0,last_synced TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tgr (selling_pointid,grower_num),INDEX idx_sp(selling_pointid))");

$conn->query("CREATE TABLE IF NOT EXISTS timb_sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,selling_pointid INT NOT NULL,sync_type VARCHAR(40) DEFAULT '',
    records_fetched INT DEFAULT 0,records_matched INT DEFAULT 0,records_new INT DEFAULT 0,
    records_updated INT DEFAULT 0,status ENUM('success','failed','partial') DEFAULT 'success',
    notes TEXT DEFAULT '',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';

    if($action==='import_csv'){
        $file=$_FILES['timb_csv']??null;
        if(!$file||$file['error']!==UPLOAD_ERR_OK){$err="Upload error.";}
        else{
            $handle=fopen($file['tmp_name'],'r');
            $header=array_map(fn($h)=>strtolower(trim(preg_replace('/\s+/','_',$h))),fgetcsv($handle));
            $imported=0;$matched=0;
            while(($row=fgetcsv($handle))!==false){
                if(count($row)<2)continue;
                $d=array_combine(array_slice($header,0,count($row)),array_slice($row,0,count($header)));
                $d=array_map('trim',$d);
                $gnum=$conn->real_escape_string($d['grower_num']??$d['grower_number']??'');
                if(!$gnum)continue;
                $nm=$conn->real_escape_string($d['name']??$d['first_name']??'');
                $sn=$conn->real_escape_string($d['surname']??$d['last_name']??'');
                $nid=$conn->real_escape_string($d['national_id']??'');
                $prov=$conn->real_escape_string($d['province']??'');
                $dist=$conn->real_escape_string($d['district_name']??$d['district']??'');
                $area=$conn->real_escape_string($d['area']??'');
                $farm=$conn->real_escape_string($d['farm_name']??'');
                $crop=$conn->real_escape_string($d['crop_type']??'Virginia');
                $regdate=$conn->real_escape_string($d['registration_date']??'');
                $regdate_sql=$regdate?"'$regdate'":'NULL';
                $conn->query("INSERT INTO timb_grower_registry (selling_pointid,grower_num,name,surname,national_id,province,district_name,area,farm_name,crop_type,registration_date,timb_verified)
                    VALUES ($spid,'$gnum','$nm','$sn','$nid','$prov','$dist','$area','$farm','$crop',$regdate_sql,1)
                    ON DUPLICATE KEY UPDATE name='$nm',surname='$sn',national_id=IF('$nid'!='','$nid',national_id),
                    province='$prov',district_name='$dist',area='$area',farm_name='$farm',timb_verified=1,last_synced=NOW()");
                $imported++;
                // Match to growers table
                if($nid){$conn->query("UPDATE growers SET national_id='$nid',province=IF('$prov'!='','$prov',province),farm_name=IF('$farm'!='','$farm',farm_name) WHERE grower_num='$gnum' AND selling_pointid=$spid");if($conn->affected_rows>0)$matched++;}
            }
            fclose($handle);
            $conn->query("INSERT INTO timb_sync_log (selling_pointid,sync_type,records_fetched,records_matched,records_new,status) VALUES ($spid,'csv_import',$imported,$matched,$imported-$matched,'success')");
            audit_log($conn,$spid,$userid,'timb_grower_registry','import',null,null,['count'=>$imported]);
            $msg="✓ Imported $imported TIMB records. Matched $matched to existing growers.";
        }
    }

    if($action==='verify_grower'){
        $gid=(int)($_POST['growerid']??0);
        $g=$conn->query("SELECT grower_num,national_id FROM growers WHERE id=$gid AND selling_pointid=$spid LIMIT 1")->fetch_assoc();
        if(!$g){$err="Grower not found.";}
        else{
            $gnum=$conn->real_escape_string($g['grower_num']);
            $reg=$conn->query("SELECT * FROM timb_grower_registry WHERE grower_num='$gnum' AND selling_pointid=$spid LIMIT 1")->fetch_assoc();
            if($reg){
                $conn->query("UPDATE growers SET province=IF(province='' OR province IS NULL,'{$conn->real_escape_string($reg['province'])}',province),farm_name=IF(farm_name='' OR farm_name IS NULL,'{$conn->real_escape_string($reg['farm_name'])}',farm_name) WHERE id=$gid");
                $msg="✓ Grower verified against TIMB registry.";
            }else{$err="Grower number '".$g['grower_num']."' not found in TIMB registry. Upload an updated TIMB file.";}
        }
    }
}

// Stats
$registry_count=$conn->query("SELECT COUNT(*) c FROM timb_grower_registry WHERE selling_pointid=$spid")->fetch_assoc()['c']??0;
$verified_count=$conn->query("SELECT COUNT(*) c FROM timb_grower_registry WHERE selling_pointid=$spid AND timb_verified=1")->fetch_assoc()['c']??0;
$grower_count=$conn->query("SELECT COUNT(*) c FROM growers WHERE selling_pointid=$spid")->fetch_assoc()['c']??0;

// Unmatched growers (in floor but not in TIMB registry)
$unmatched_q=$conn->query("SELECT g.grower_num,g.name,g.surname,g.national_id FROM growers g LEFT JOIN timb_grower_registry tgr ON tgr.grower_num=g.grower_num AND tgr.selling_pointid=$spid WHERE g.selling_pointid=$spid AND tgr.id IS NULL LIMIT 50");
$unmatched=($unmatched_q&&$unmatched_q!==true)?$unmatched_q->fetch_all(MYSQLI_ASSOC):[];

$sync_log_q=$conn->query("SELECT * FROM timb_sync_log WHERE selling_pointid=$spid ORDER BY id DESC LIMIT 10");
$sync_log=($sync_log_q&&$sync_log_q!==true)?$sync_log_q->fetch_all(MYSQLI_ASSOC):[];

// Registry sample
$registry_q=$conn->query("SELECT tgr.*,CASE WHEN g.id IS NOT NULL THEN 1 ELSE 0 END matched FROM timb_grower_registry tgr LEFT JOIN growers g ON g.grower_num=tgr.grower_num AND g.selling_pointid=$spid WHERE tgr.selling_pointid=$spid ORDER BY tgr.last_synced DESC LIMIT 100");
$registry=($registry_q&&$registry_q!==true)?$registry_q->fetch_all(MYSQLI_ASSOC):[];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>TIMB Registry Sync</h2><p>Import and verify growers against the TIMB grower registry</p></div>
  <a href="?template=1" class="btn btn-ghost btn-sm">⬇ Template</a>
</div>
<?php if(isset($_GET['template'])){header('Content-Type: text/csv');header('Content-Disposition: attachment; filename="timb_registry_template.csv"');echo "grower_num,name,surname,national_id,province,district_name,area,farm_name,crop_type,registration_date\r\n";echo "ZW-0001234,John,Moyo,63-123456A78,Mashonaland West,Zvimba,Banket,Moyo Farm,Virginia,2024-01-15\r\n";exit;}?>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1rem">
  <div class="kpi-card"><div class="kpi-label">TIMB Registry</div><div class="kpi-value"><?=number_format($registry_count)?></div><div class="kpi-sub">records loaded</div></div>
  <div class="kpi-card"><div class="kpi-label">Verified</div><div class="kpi-value text-leaf"><?=number_format($verified_count)?></div><div class="kpi-sub">TIMB matched</div></div>
  <div class="kpi-card"><div class="kpi-label">Floor Growers</div><div class="kpi-value"><?=number_format($grower_count)?></div></div>
  <div class="kpi-card"><div class="kpi-label">Unmatched</div><div class="kpi-value <?=count($unmatched)?'text-gold':''?>"><?=count($unmatched)?></div><div class="kpi-sub">not in TIMB file</div></div>
</div>

<div class="premium-tabs-nav" data-tabs="timbsync">
  <button type="button" class="premium-tab-btn active" data-tab="registry">📋 Registry</button>
  <button type="button" class="premium-tab-btn" data-tab="import">⬆ Import CSV</button>
  <button type="button" class="premium-tab-btn" data-tab="unmatched">⚠ Unmatched<?php if(!empty($unmatched)):?> <span class="badge badge-gold" style="font-size:.58rem"><?=count($unmatched)?></span><?php endif;?></button>
  <button type="button" class="premium-tab-btn" data-tab="history">🕘 Sync History</button>
</div>

<div id="panel-import" class="tab-panel" data-tabs="timbsync">
<div class="card">
<div class="card-head"><h3>Import TIMB Registry CSV</h3></div>
<div class="card-body">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" value="import_csv">
<div class="form-group"><label>TIMB Grower Registry CSV</label><input type="file" name="timb_csv" accept=".csv,.txt" class="form-control" required></div>
<div style="background:var(--cream-d);border-radius:6px;padding:.65rem;font-size:.72rem;color:var(--muted);margin-bottom:.75rem">
  Required columns: grower_num, name, surname<br>Optional: national_id, province, district_name, area, farm_name, crop_type, registration_date<br>
  Existing records are updated. Matched growers have their details enriched automatically.
</div>
<button type="submit" class="btn btn-primary" style="width:100%" data-progress-label="Importing TIMB data...">Import TIMB Registry</button>
</form>
</div>
</div>

</div><!-- /panel-import -->

<div id="panel-unmatched" class="tab-panel" data-tabs="timbsync">
<?php if(!empty($unmatched)):?>
<div class="card">
<div class="card-head"><h3>⚠ Unmatched Floor Growers</h3><span class="badge badge-gold"><?=count($unmatched)?></span></div>
<div class="card-body" style="font-size:.75rem;color:var(--muted);margin-bottom:.5rem">These growers are registered on the floor but not found in your TIMB registry file.</div>
<div class="table-wrap" style="max-height:280px;overflow-y:auto"><table>
<thead><tr><th>Grower #</th><th>Name</th><th>National ID</th></tr></thead>
<tbody>
<?php foreach($unmatched as $u):?>
<tr>
  <td><span class="badge badge-gold" style="font-size:.6rem"><?=htmlspecialchars($u['grower_num'])?></span></td>
  <td style="font-size:.78rem"><?=htmlspecialchars($u['name'].' '.$u['surname'])?></td>
  <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($u['national_id']??'—')?></td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
</div>
<?php else:?>
<div class="card"><div class="card-body text-center text-muted" style="padding:2rem">
  ✓ All floor growers matched against the TIMB registry.
</div></div>
<?php endif;?>
</div><!-- /panel-unmatched -->

<div id="panel-registry" class="tab-panel active" data-tabs="timbsync">
<div class="card">
<div class="card-head"><h3>Registry — Last 100</h3><span class="badge badge-gray"><?=$registry_count?> total</span></div>
<div class="table-wrap" style="max-height:380px;overflow-y:auto"><table>
<thead><tr><th>Grower #</th><th>Name</th><th>Province</th><th>TIMB</th><th>Floor</th></tr></thead>
<tbody>
<?php if(empty($registry)):?><tr><td colspan="5" class="text-center text-muted" style="padding:1.5rem">No registry data. Import a TIMB CSV file.</td></tr>
<?php else:foreach($registry as $r):?>
<tr>
  <td><span class="badge badge-gold" style="font-size:.6rem"><?=htmlspecialchars($r['grower_num'])?></span></td>
  <td style="font-size:.75rem"><?=htmlspecialchars($r['name'].' '.$r['surname'])?></td>
  <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($r['province'])?></td>
  <td><?=$r['timb_verified']?'<span class="badge badge-green" style="font-size:.58rem">✓ Verified</span>':'<span class="badge badge-gray" style="font-size:.58rem">Pending</span>'?></td>
  <td><?=$r['matched']?'<span class="badge badge-green" style="font-size:.58rem">Matched</span>':'<span class="badge badge-red" style="font-size:.58rem">Not on floor</span>'?></td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table></div>
</div>

</div><!-- /panel-registry -->

<div id="panel-history" class="tab-panel" data-tabs="timbsync">
<div class="card">
<div class="card-head"><h3>Sync History</h3></div>
<div class="table-wrap"><table>
<thead><tr><th>Date</th><th>Type</th><th class="text-right">Fetched</th><th class="text-right">Matched</th><th>Status</th></tr></thead>
<tbody>
<?php if(empty($sync_log)):?><tr><td colspan="5" class="text-center text-muted" style="padding:1.5rem">No sync history yet.</td></tr>
<?php else:foreach($sync_log as $sl):?>
<tr>
  <td style="font-size:.72rem"><?=date('d M H:i',strtotime($sl['created_at']))?></td>
  <td style="font-size:.7rem;color:var(--muted)"><?=str_replace('_',' ',$sl['sync_type'])?></td>
  <td class="td-mono text-right"><?=$sl['records_fetched']?></td>
  <td class="td-mono text-right"><?=$sl['records_matched']?></td>
  <td><span class="badge <?=$sl['status']==='success'?'badge-green':'badge-red'?>" style="font-size:.58rem"><?=strtoupper($sl['status'])?></span></td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table></div>
</div>
</div><!-- /panel-history -->
<?php include "includes/layout_end.php";?>
