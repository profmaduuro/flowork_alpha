<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "audit.php";
require_login();

$page_title  = "Vehicle Register";
$active_page = "vehicles";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
// If spid not set in session, get first available selling point
if (!$spid) {
    $_sp_q = $conn->query("SELECT id FROM selling_point WHERE active=1 ORDER BY id LIMIT 1");
    if ($_sp_q && $_sp_q !== true && $_sp_q->num_rows > 0) {
        $spid = (int)$_sp_q->fetch_assoc()['id'];
        $_SESSION['selling_pointid'] = $spid;
    }
}
$userid      = current_userid();
$msg=$err='';

$conn->query("CREATE TABLE IF NOT EXISTS vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    selling_pointid INT NOT NULL,
    reg_number VARCHAR(30) NOT NULL,
    make VARCHAR(50) DEFAULT '',
    model VARCHAR(50) DEFAULT '',
    type ENUM('truck','flatbed','pickup','van','other') DEFAULT 'truck',
    capacity_bales INT DEFAULT 0,
    capacity_kg DECIMAL(10,2) DEFAULT 0,
    driver_name VARCHAR(80) DEFAULT '',
    driver_phone VARCHAR(20) DEFAULT '',
    status ENUM('available','on_route','maintenance','retired') DEFAULT 'available',
    notes TEXT DEFAULT '',
    last_used DATE DEFAULT NULL,
    last_gps_lat DECIMAL(10,6) DEFAULT NULL,
    last_gps_lng DECIMAL(10,6) DEFAULT NULL,
    last_gps_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_reg (selling_pointid, reg_number)
)");

// Migrate vehicles saved with selling_pointid=0 to current floor
$conn->query("UPDATE vehicles SET selling_pointid=$spid WHERE selling_pointid=0 AND $spid>0");

$conn->query("CREATE TABLE IF NOT EXISTS vehicle_trips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicleid INT NOT NULL,
    dispatch_sessionid INT DEFAULT NULL,
    origin VARCHAR(120) DEFAULT '',
    destination VARCHAR(120) DEFAULT '',
    departed_at TIMESTAMP NULL DEFAULT NULL,
    arrived_at TIMESTAMP NULL DEFAULT NULL,
    bales_count INT DEFAULT 0,
    mass_kg DECIMAL(10,2) DEFAULT 0,
    driver_name VARCHAR(80) DEFAULT '',
    notes TEXT DEFAULT '',
    status ENUM('loading','departed','arrived','cancelled') DEFAULT 'loading',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vehicle (vehicleid)
)");

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action==='add_vehicle') {
        $reg   = $conn->real_escape_string(strtoupper(trim($_POST['reg_number']??'')));
        $make  = $conn->real_escape_string(trim($_POST['make']??''));
        $model = $conn->real_escape_string(trim($_POST['model']??''));
        $type  = $conn->real_escape_string($_POST['type']??'truck');
        $cap_b = (int)($_POST['capacity_bales']??0);
        $cap_k = (float)($_POST['capacity_kg']??0);
        $driver= $conn->real_escape_string(trim($_POST['driver_name']??''));
        $phone = $conn->real_escape_string(trim($_POST['driver_phone']??''));
        $notes = $conn->real_escape_string(trim($_POST['notes']??''));
        if (!$reg) { $err = "Registration number required."; }
        else {
            $conn->query("INSERT INTO vehicles (selling_pointid,reg_number,make,model,type,capacity_bales,capacity_kg,driver_name,driver_phone,notes)
                VALUES ($spid,'$reg','$make','$model','$type',$cap_b,$cap_k,'$driver','$phone','$notes')
                ON DUPLICATE KEY UPDATE make='$make',model='$model',type='$type',capacity_bales=$cap_b,capacity_kg=$cap_k,
                    driver_name='$driver',driver_phone='$phone',notes='$notes'");
            audit_log($conn,$spid,$userid,'vehicles','create',$conn->insert_id,null,['reg'=>$reg]);
            $msg = "✓ Vehicle $reg saved.";
        }
    }

    if ($action==='update_status') {
        $vid    = (int)($_POST['vehicle_id']??0);
        $status = $conn->real_escape_string($_POST['status']??'available');
        $conn->query("UPDATE vehicles SET status='$status' WHERE id=$vid AND selling_pointid=$spid");
        audit_log($conn,$spid,$userid,'vehicles','update',$vid,null,['status'=>$status]);
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success'=>true,'msg'=>'Status updated.']);
            exit;
        }
        $msg = "Vehicle status updated.";
    }

    if ($action==='log_trip') {
        $vid   = (int)($_POST['vehicle_id']??0);
        $dest  = $conn->real_escape_string(trim($_POST['destination']??''));
        $orig  = $conn->real_escape_string(trim($_POST['origin']??$_SESSION['selling_point_name']??''));
        $bales = (int)($_POST['bales_count']??0);
        $mass  = (float)($_POST['mass_kg']??0);
        $driver= $conn->real_escape_string(trim($_POST['driver_name']??''));
        $dsid  = (int)($_POST['dispatch_sessionid']??0) ?: 'NULL';
        $conn->query("INSERT INTO vehicle_trips (vehicleid,dispatch_sessionid,origin,destination,bales_count,mass_kg,driver_name,status,departed_at)
            VALUES ($vid,".($dsid==='NULL'?'NULL':$dsid).",'$orig','$dest',$bales,$mass,'$driver','departed',NOW())");
        $conn->query("UPDATE vehicles SET status='on_route',last_used=CURDATE() WHERE id=$vid AND selling_pointid=$spid");
        audit_log($conn,$spid,$userid,'vehicle_trips','create',$conn->insert_id,null,['vehicle'=>$vid,'dest'=>$dest]);
        $msg = "✓ Trip logged. Vehicle marked as on route.";
    }

    if ($action==='arrive') {
        $tripid = (int)($_POST['trip_id']??0);
        $vid    = (int)($_POST['vehicle_id']??0);
        $conn->query("UPDATE vehicle_trips SET status='arrived',arrived_at=NOW() WHERE id=$tripid");
        $conn->query("UPDATE vehicles SET status='available' WHERE id=$vid AND selling_pointid=$spid");
        $msg = "✓ Vehicle marked as arrived and available.";
    }
}

// Load vehicles
$veh_where = $spid > 0 ? "v.selling_pointid=$spid" : "1=1";
$vehicles_q = $conn->query("SELECT v.id, v.reg_number, v.make, v.model, v.type,
    v.capacity_bales, v.capacity_kg, v.driver_name, v.driver_phone,
    v.status, v.notes, v.last_used, v.last_gps_lat, v.last_gps_lng,
    COUNT(vt.id) total_trips, MAX(vt.departed_at) last_trip
    FROM vehicles v
    LEFT JOIN vehicle_trips vt ON vt.vehicleid=v.id
    WHERE $veh_where
    GROUP BY v.id, v.reg_number, v.make, v.model, v.type,
    v.capacity_bales, v.capacity_kg, v.driver_name, v.driver_phone,
    v.status, v.notes, v.last_used, v.last_gps_lat, v.last_gps_lng
    ORDER BY v.status, v.reg_number");
$vehicles = ($vehicles_q&&$vehicles_q!==true)?$vehicles_q->fetch_all(MYSQLI_ASSOC):[];

// Active trips
$active_trips = $conn->query("SELECT vt.*, v.reg_number
    FROM vehicle_trips vt JOIN vehicles v ON v.id=vt.vehicleid
    WHERE v.selling_pointid=$spid AND vt.status IN ('loading','departed')
    ORDER BY vt.departed_at DESC");
$trips = ($active_trips&&$active_trips!==true)?$active_trips->fetch_all(MYSQLI_ASSOC):[];

// Open dispatch sessions for trip form
$dsessions = $conn->query("SELECT id, destination, dispatch_date FROM dispatch_sessions WHERE selling_pointid=$spid AND status='open' ORDER BY id DESC LIMIT 20");
$dses = ($dsessions&&$dsessions!==true)?$dsessions->fetch_all(MYSQLI_ASSOC):[];

$STATUS_COLORS = ['available'=>'badge-green','on_route'=>'badge-gold','maintenance'=>'badge-gray','retired'=>'badge-red'];
$TYPE_ICONS    = ['truck'=>'🚛','flatbed'=>'🚚','pickup'=>'🛻','van'=>'🚐','other'=>'🚗'];

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Vehicle Register</h2>
    <p>Manage trucks, drivers and dispatch trips</p>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- Active trips -->
<?php if(!empty($trips)):?>
<div class="card mb-3" style="border-top:3px solid var(--gold)">
<div class="card-head">
  <h3>🚛 Vehicles Currently Active</h3>
  <span class="badge badge-gold"><?=count($trips)?> on route</span>
</div>
<div class="table-wrap"><table>
<thead><tr><th>Vehicle</th><th>Destination</th><th>Bales</th><th>Departed</th><th>Driver</th><th></th></tr></thead>
<tbody>
<?php foreach($trips as $t):?>
<tr>
  <td><strong><?=htmlspecialchars($t['reg_number'])?></strong></td>
  <td><?=htmlspecialchars($t['destination'])?></td>
  <td class="td-mono"><?=number_format((int)$t['bales_count'])?></td>
  <td style="font-size:.75rem"><?=$t['departed_at']?date('d M H:i',strtotime($t['departed_at'])):'—'?></td>
  <td style="font-size:.75rem"><?=htmlspecialchars($t['driver_name'])?></td>
  <td>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="arrive">
      <input type="hidden" name="trip_id" value="<?=$t['id']?>">
      <input type="hidden" name="vehicle_id" value="<?=$t['vehicleid']?>">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem">✓ Mark Arrived</button>
    </form>
  </td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
</div>
<?php endif;?>

<div class="premium-tabs-nav" data-tabs="vehicles">
  <button type="button" class="premium-tab-btn active" data-tab="fleet">🚐 Fleet Register</button>
  <button type="button" class="premium-tab-btn" data-tab="trip">🛣 Log Trip</button>
  <button type="button" class="premium-tab-btn" data-tab="add">➕ Add / Update Vehicle</button>
</div>

<!-- Vehicle list -->
<div id="panel-fleet" class="tab-panel active" data-tabs="vehicles">
<div class="card">
<div class="card-head">
  <h3>Fleet Register</h3>
  <span class="badge badge-gray"><?=count($vehicles)?> vehicles</span>
</div>
<div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
<thead><tr><th>Reg</th><th>Type</th><th>Driver</th><th>Cap</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php if(empty($vehicles)):?>
<tr><td colspan="6" class="text-center text-muted" style="padding:2rem">No vehicles registered yet.</td></tr>
<?php else: foreach($vehicles as $v):?>
<tr>
  <td style="font-weight:600"><?=htmlspecialchars($v['reg_number'])?></td>
  <td><?=($TYPE_ICONS[$v['type']]??'🚗').' '.htmlspecialchars($v['make'].' '.$v['model'])?></td>
  <td style="font-size:.72rem"><?=htmlspecialchars($v['driver_name'])?></td>
  <td style="font-size:.7rem;color:var(--muted)"><?=$v['capacity_bales']?$v['capacity_bales'].' bales':''?></td>
  <td>
    <select class="form-control" style="font-size:.65rem;padding:.2rem .4rem"
      onchange="updateStatus(<?=$v['id']?>,this.value)">
      <?php foreach(['available','on_route','maintenance','retired'] as $s):?>
      <option value="<?=$s?>" <?=$v['status']===$s?'selected':''?>><?=ucfirst(str_replace('_',' ',$s))?></option>
      <?php endforeach;?>
    </select>
  </td>
  <td>
    <span class="badge <?=$STATUS_COLORS[$v['status']]?>" style="font-size:.72rem"><?=$v['status']==='on_route'?'On Route':ucfirst($v['status'])?></span>
  </td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table></div>
</div>

</div><!-- /panel-fleet -->

<!-- Log trip -->
<div id="panel-trip" class="tab-panel" data-tabs="vehicles">
<?php if(!empty($vehicles)):?>
<div class="card">
<div class="card-head"><h3>Log Dispatch Trip</h3></div>
<div class="card-body">
  <form method="POST">
  <input type="hidden" name="action" value="log_trip">
  <div class="form-row cols-2">
    <div class="form-group">
      <label>Vehicle</label>
      <select name="vehicle_id" class="form-control" required>
        <option value="">Select vehicle</option>
        <?php foreach($vehicles as $v): if($v['status']==='available'||$v['status']==='on_route'):?>
        <option value="<?=$v['id']?>"><?=htmlspecialchars($v['reg_number'].' — '.$v['driver_name'])?></option>
        <?php endif; endforeach;?>
      </select>
    </div>
    <div class="form-group">
      <label>Destination</label>
      <input type="text" name="destination" class="form-control" placeholder="e.g. Boka Factory" required>
    </div>
  </div>
  <div class="form-row cols-2">
    <div class="form-group">
      <label>Bales</label>
      <input type="number" name="bales_count" class="form-control" placeholder="0" min="0">
    </div>
    <div class="form-group">
      <label>Mass (kg)</label>
      <input type="number" name="mass_kg" class="form-control" placeholder="0" step="0.1" min="0">
    </div>
  </div>
  <?php if(!empty($dses)):?>
  <div class="form-group">
    <label>Link to Dispatch Session (optional)</label>
    <select name="dispatch_sessionid" class="form-control">
      <option value="">None</option>
      <?php foreach($dses as $ds):?>
      <option value="<?=$ds['id']?>">#<?=$ds['id']?> — <?=htmlspecialchars($ds['destination'])?> (<?=$ds['dispatch_date']?>)</option>
      <?php endforeach;?>
    </select>
  </div>
  <?php endif;?>
  <button type="submit" class="btn btn-primary" style="width:100%">🚛 Log Trip — Mark Departed</button>
  </form>
</div>
</div>
<?php else:?>
<div class="card"><div class="card-body text-center text-muted" style="padding:2rem">
  Add a vehicle first — trips are logged against a registered vehicle.
</div></div>
<?php endif;?>
</div><!-- /panel-trip -->

<!-- Add vehicle -->
<div id="panel-add" class="tab-panel" data-tabs="vehicles">
<div class="card">
<div class="card-head"><h3>Add / Update Vehicle</h3></div>
<div class="card-body">
  <form method="POST">
  <input type="hidden" name="action" value="add_vehicle">
  <div class="form-row cols-2">
    <div class="form-group">
      <label>Reg Number *</label>
      <input type="text" name="reg_number" class="form-control" placeholder="e.g. ABC 1234" required style="text-transform:uppercase">
    </div>
    <div class="form-group">
      <label>Type</label>
      <select name="type" class="form-control">
        <?php foreach(['truck','flatbed','pickup','van','other'] as $t):?>
        <option value="<?=$t?>"><?=ucfirst($t)?></option>
        <?php endforeach;?>
      </select>
    </div>
  </div>
  <div class="form-row cols-2">
    <div class="form-group">
      <label>Make</label>
      <input type="text" name="make" class="form-control" placeholder="e.g. Isuzu">
    </div>
    <div class="form-group">
      <label>Model</label>
      <input type="text" name="model" class="form-control" placeholder="e.g. NPR">
    </div>
  </div>
  <div class="form-row cols-2">
    <div class="form-group">
      <label>Capacity (bales)</label>
      <input type="number" name="capacity_bales" class="form-control" placeholder="200" min="0">
    </div>
    <div class="form-group">
      <label>Capacity (kg)</label>
      <input type="number" name="capacity_kg" class="form-control" placeholder="10000" step="50" min="0">
    </div>
  </div>
  <div class="form-row cols-2">
    <div class="form-group">
      <label>Default Driver</label>
      <input type="text" name="driver_name" class="form-control" placeholder="Full name">
    </div>
    <div class="form-group">
      <label>Driver Phone</label>
      <input type="text" name="driver_phone" class="form-control" placeholder="+263...">
    </div>
  </div>
  <button type="submit" class="btn btn-primary" style="width:100%">Save Vehicle</button>
  </form>
</div>
</div>
</div><!-- /panel-add -->

<script>
function updateStatus(vid, status) {
    fw.post('vehicles.php', { action:'update_status', vehicle_id:vid, status:status })
        .then(function(r){ fw.toast(r.msg||'Updated','success'); })
        .catch(function(){ fw.toast('Update failed','error'); });
}
</script>

<?php include "includes/layout_end.php"; ?>
