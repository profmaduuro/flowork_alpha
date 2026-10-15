<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "audit.php";
require_login();
$page_title="Multi-Floor Management";$active_page="multi_floor";
$userid=current_userid();$msg=$err='';

// selling_point table
$conn->query("CREATE TABLE IF NOT EXISTS selling_point (
    id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,location VARCHAR(120) DEFAULT '',
    company_name VARCHAR(120) DEFAULT '',address TEXT DEFAULT '',phone VARCHAR(30) DEFAULT '',
    email VARCHAR(100) DEFAULT '',logo_path VARCHAR(255) DEFAULT '',
    active TINYINT DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
// Add missing columns to existing selling_point tables
$conn->query("ALTER TABLE selling_point ADD COLUMN IF NOT EXISTS active TINYINT DEFAULT 1");
$conn->query("ALTER TABLE selling_point ADD COLUMN IF NOT EXISTS company_name VARCHAR(120) DEFAULT ''");
$conn->query("ALTER TABLE selling_point ADD COLUMN IF NOT EXISTS location VARCHAR(120) DEFAULT ''");
$conn->query("ALTER TABLE selling_point ADD COLUMN IF NOT EXISTS phone VARCHAR(30) DEFAULT ''");
$conn->query("ALTER TABLE selling_point ADD COLUMN IF NOT EXISTS email VARCHAR(100) DEFAULT ''");

// Backward compatibility: migrate is_active state to active if is_active column exists
if ($conn->query("SHOW COLUMNS FROM selling_point LIKE 'is_active'")->num_rows > 0) {
    $conn->query("UPDATE selling_point SET active = is_active WHERE active <> is_active");
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token verification failed.");
    }
    $action=$_POST['action']??'';
    if($action==='add_floor'){
        $name=$conn->real_escape_string(trim($_POST['name']??''));
        $loc=$conn->real_escape_string(trim($_POST['location']??''));
        $comp=$conn->real_escape_string(trim($_POST['company_name']??''));
        $addr=$conn->real_escape_string(trim($_POST['address']??''));
        $ph=$conn->real_escape_string(trim($_POST['phone']??''));
        $em=$conn->real_escape_string(trim($_POST['email']??''));
        if(!$name){$err="Floor name required.";}
        else{
            $conn->query("INSERT INTO selling_point (name,location,company_name,address,phone,email) VALUES ('$name','$loc','$comp','$addr','$ph','$em')");
            $sid=$conn->insert_id;
            
            // Create a default active subscription for the new floor to allow immediate operations
            $far_future = date('Y-m-d', strtotime('+3650 days'));
            $conn->query("INSERT INTO flowork_subscriptions (selling_pointid, status, license_key, start_date, end_date) VALUES ($sid, 'Active', 'TRIAL-ACTIVE-LICENSE', CURDATE(), '$far_future') ON DUPLICATE KEY UPDATE status='Active'");
            
            audit_log($conn,0,$userid,'selling_point','create',$sid,null,['name'=>$name]);
            $msg="✓ Floor '$name' created (ID: $sid) and licensed. Users can now select it on login.";
        }
    }
    if($action==='edit_floor'){
        $fid=(int)($_POST['floor_id']??0);
        $name=$conn->real_escape_string(trim($_POST['name']??''));
        $loc=$conn->real_escape_string(trim($_POST['location']??''));
        $comp=$conn->real_escape_string(trim($_POST['company_name']??''));
        $addr=$conn->real_escape_string(trim($_POST['address']??''));
        $ph=$conn->real_escape_string(trim($_POST['phone']??''));
        $em=$conn->real_escape_string(trim($_POST['email']??''));
        $comm=round((float)($_POST['offtaker_comm_pct']??1.50), 2);
        
        if(!$fid){$err="Invalid floor ID.";}
        elseif(!$name){$err="Floor name required.";}
        else{
            $conn->query("UPDATE selling_point SET name='$name', location='$loc', company_name='$comp', address='$addr', phone='$ph', email='$em', offtaker_comm_pct=$comm WHERE id=$fid");
            audit_log($conn,0,$userid,'selling_point','edit',$fid,null,['name'=>$name]);
            $msg="✓ Floor '$name' details updated.";
        }
    }
    if($action==='delete_floor'){
        $fid=(int)($_POST['floor_id']??0);
        if(!$fid){$err="Invalid floor ID.";}
        elseif($fid===(int)($_SESSION['selling_pointid']??0)){$err="Cannot delete the currently active floor.";}
        else {
            // Check if there are growers, transporter_growers, or start_of_day records associated with this floor
            $growers_q = $conn->query("SELECT COUNT(*) c FROM growers WHERE selling_pointid=$fid")->fetch_assoc();
            $trans_q = $conn->query("SELECT COUNT(*) c FROM transporter_growers WHERE selling_pointid=$fid")->fetch_assoc();
            $sod_q = $conn->query("SELECT COUNT(*) c FROM start_of_day WHERE selling_pointid=$fid")->fetch_assoc();
            
            $total_records = (int)$growers_q['c'] + (int)$trans_q['c'] + (int)$sod_q['c'];
            if($total_records > 0){
                $err = "Cannot delete floor (ID: $fid) because it has $total_records operational records associated with it. Please disable it instead.";
            } else {
                // Delete floor and its subscriptions
                $conn->query("DELETE FROM flowork_subscriptions WHERE selling_pointid=$fid");
                $conn->query("DELETE FROM selling_point WHERE id=$fid");
                audit_log($conn,0,$userid,'selling_point','delete',$fid);
                $msg="✓ Floor deleted successfully.";
            }
        }
    }
    if($action==='toggle_floor'){
        $fid=(int)($_POST['floor_id']??0);$active=(int)($_POST['active']??1);
        $conn->query("UPDATE selling_point SET active=$active WHERE id=$fid");
        $msg="Floor status updated.";
    }
    if($action==='switch_floor'){
        $fid=(int)($_POST['floor_id']??0);
        $sp=$conn->query("SELECT * FROM selling_point WHERE id=$fid AND active=1 LIMIT 1")->fetch_assoc();
        if($sp){
            $_SESSION['selling_pointid']=$fid;$_SESSION['selling_point_name']=$sp['name'];
            header("Location: dashboard.php");exit;
        }else{$err="Floor not found or inactive.";}
    }
}

$floors_q=$conn->query("SELECT sp.*,COUNT(DISTINCT g.id) grower_count FROM selling_point sp LEFT JOIN growers g ON g.selling_pointid=sp.id GROUP BY sp.id ORDER BY sp.name");
$floors=($floors_q&&$floors_q!==true)?$floors_q->fetch_all(MYSQLI_ASSOC):[];
$current_spid=(int)($_SESSION['selling_pointid']??0);

$sel_fid  = (int)($_GET['edit'] ?? 0);
$sel_floor = null;
if ($sel_fid > 0) {
    foreach ($floors as $fl_item) {
        if ((int)$fl_item['id'] === $sel_fid) {
            $sel_floor = $fl_item;
            break;
        }
    }
}

include "includes/layout.php";
?>
<style>
/* Modal overlay */
.modal-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0, 0, 0, 0.65);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
}
.modal-overlay.show {
  opacity: 1;
  pointer-events: auto;
}

/* Modal container */
.modal-container {
  background: var(--cream);
  border: 1px solid var(--border);
  border-radius: 6px;
  width: 100%;
  max-width: 520px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
  transform: scale(0.95);
  transition: transform 0.2s ease;
  overflow: hidden;
}
.modal-overlay.show .modal-container {
  transform: scale(1);
}

.modal-header {
  padding: 0.85rem 1.15rem;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--ink);
  color: var(--gold-l);
}
.modal-header h3 {
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}
.modal-close {
  background: transparent;
  border: none;
  color: rgba(245, 240, 232, 0.5);
  font-size: 1.35rem;
  cursor: pointer;
  transition: color 0.15s;
  line-height: 1;
}
.modal-close:hover {
  color: var(--cream);
}
.modal-body {
  padding: 1.15rem;
}
.modal-body .form-group {
  margin-bottom: 0.85rem;
}
.modal-body .form-group label {
  display: block;
  font-size: 0.65rem;
  font-weight: 500;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 0.25rem;
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Multi-Floor Management</h2>
    <p>Manage multiple tobacco buying floors and company accounts</p>
  </div>
  <div style="display:flex;align-items:center;gap:1rem">
    <button class="btn btn-primary" onclick="openFloorModal()">+ Add New Floor</button>
    <span style="font-size:.75rem;color:var(--muted)">Active: <strong><?=htmlspecialchars($_SESSION['selling_point_name']??'None')?></strong></span>
  </div>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="premium-tabs-nav" data-tabs="multifloor">
  <button type="button" class="premium-tab-btn active" data-tab="floors">🏭 Registered Floors</button>
  <button type="button" class="premium-tab-btn" data-tab="help">❓ How Multi-Floor Works</button>
</div>

<div id="panel-floors" class="tab-panel active" data-tabs="multifloor">
<div class="card">
<div class="card-head"><h3>Registered Floors</h3><span class="badge badge-gray"><?=count($floors)?></span></div>
<div class="table-wrap"><table>
<thead><tr><th>Floor</th><th>Company</th><th>Location</th><th class="text-right">Growers</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php if(empty($floors)):?><tr><td colspan="6" class="text-center text-muted" style="padding:2rem">No floors yet. Create one below.</td></tr>
<?php else:foreach($floors as $f):$is_current=(int)$f['id']===$current_spid;?>
<tr style="<?=$is_current?'background:rgba(200,149,42,.06);border-left:3px solid var(--gold)':''?>">
  <td>
    <?php if($is_current):?><span class="badge badge-gold" style="font-size:.58rem">Active</span><?php endif;?>
    <strong style="font-size:.82rem"><?=htmlspecialchars($f['name'])?></strong>
  </td>
  <td style="font-size:.75rem"><?=htmlspecialchars($f['company_name']??'')?></td>
  <td style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($f['location']??'')?></td>
  <td class="td-mono text-right"><?=number_format((int)$f['grower_count'])?></td>
  <?php $is_active = (int)($f['active'] ?? 1); ?>
  <td><span class="badge <?=$is_active?'badge-green':'badge-gray'?>" style="font-size:.6rem"><?=$is_active?'Active':'Inactive'?></span></td>
  <td style="white-space:nowrap">
    <?php if(!$is_current && $is_active):?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="switch_floor"><input type="hidden" name="floor_id" value="<?=$f['id']?>">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--gold)">Switch →</button>
    </form>
    <?php endif;?>
    <a href="multi_floor.php?edit=<?=$f['id']?>" class="btn btn-ghost btn-sm" style="font-size:.65rem;display:inline-block">Edit</a>
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="toggle_floor"><input type="hidden" name="floor_id" value="<?=$f['id']?>"><input type="hidden" name="active" value="<?=$is_active?0:1?>">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem"><?=$is_active?'Disable':'Enable'?></button>
    </form>
    <?php if(!$is_current):?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Are you sure you want to delete this floor permanently? This action cannot be undone.');">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="delete_floor"><input type="hidden" name="floor_id" value="<?=$f['id']?>">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--danger)">Delete</button>
    </form>
    <?php endif;?>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table></div>
</div>
</div><!-- /panel-floors -->

<!-- Modal Overlay & Container -->
<div id="floor-modal" class="modal-overlay" onclick="if(event.target===this) closeFloorModal()">
  <div class="modal-container">
    <div class="modal-header">
      <h3><?= $sel_floor ? 'Edit Floor Details' : 'Add New Floor' ?></h3>
      <button class="modal-close" onclick="closeFloorModal()">&times;</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="<?= $sel_floor ? 'edit_floor' : 'add_floor' ?>">
        <?php if ($sel_floor): ?>
          <input type="hidden" name="floor_id" value="<?= (int)$sel_floor['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
          <label>Floor Name *</label>
          <input type="text" name="name" class="form-control" required placeholder="e.g. Harare Floor — Auction Floors" value="<?= htmlspecialchars($sel_floor['name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Company Name</label>
          <input type="text" name="company_name" class="form-control" placeholder="e.g. Core Africa Group" value="<?= htmlspecialchars($sel_floor['company_name'] ?? '') ?>">
        </div>
        <div class="form-row cols-2">
          <div class="form-group">
            <label>Location</label>
            <input type="text" name="location" class="form-control" placeholder="e.g. Harare" value="<?= htmlspecialchars($sel_floor['location'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" class="form-control" placeholder="+263..." value="<?= htmlspecialchars($sel_floor['phone'] ?? '') ?>">
          </div>
        </div>
        <div class="form-row cols-2">
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($sel_floor['email'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Offtaker Commission %</label>
            <input type="number" step="0.01" name="offtaker_comm_pct" class="form-control" placeholder="1.50" value="<?= htmlspecialchars($sel_floor['offtaker_comm_pct'] ?? '1.50') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Address</label>
          <textarea name="address" class="form-control" rows="2" style="resize:vertical"><?= htmlspecialchars($sel_floor['address'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%"><?= $sel_floor ? 'Save Changes' : 'Create Floor' ?></button>
        <?php if ($sel_floor): ?>
          <div style="text-align:center;margin-top:0.75rem">
            <button type="button" class="btn btn-ghost btn-sm" style="width:100%" onclick="closeFloorModal()">Cancel</button>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

<div id="panel-help" class="tab-panel" data-tabs="multifloor">
<div class="card">
<div class="card-head"><h3>How Multi-Floor Works</h3></div>
<div class="card-body" style="font-size:.8rem;color:var(--muted);display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;text-align:center">
  <div><div style="font-size:1.5rem;margin-bottom:.35rem">🏭</div><div style="font-weight:600;color:var(--ink);margin-bottom:.25rem">One Database</div>All floors share the same MySQL database. Data is isolated by selling_pointid on every table.</div>
  <div><div style="font-size:1.5rem;margin-bottom:.35rem">👤</div><div style="font-weight:600;color:var(--ink);margin-bottom:.25rem">User Assignment</div>Users can be assigned to one or multiple floors. They select their active floor at login.</div>
  <div><div style="font-size:1.5rem;margin-bottom:.35rem">🔄</div><div style="font-weight:600;color:var(--ink);margin-bottom:.25rem">Switch Floors</div>Authorised users can switch between floors using the Switch button above or from the top bar.</div>
</div>
</div>
</div><!-- /panel-help -->

<script>
function openFloorModal() {
    document.getElementById('floor-modal').classList.add('show');
}
function closeFloorModal() {
    document.getElementById('floor-modal').classList.remove('show');
    if (window.location.search.indexOf('edit=') !== -1) {
        window.location.href = 'multi_floor.php';
    }
}

// Auto-open modal in edit mode
<?php if ($sel_floor): ?>
window.addEventListener('DOMContentLoaded', function() {
    openFloorModal();
});
<?php endif; ?>
</script>

<?php include "includes/layout_end.php";?>
