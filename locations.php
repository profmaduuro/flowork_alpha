<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title = "Bale Locations"; $active_page = "locations";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);
if (!$spid) {
    $r = $conn->query("SELECT id FROM selling_point WHERE active=1 ORDER BY id LIMIT 1");
    if ($r && $r !== true && $r->num_rows > 0) { $spid = (int)$r->fetch_assoc()['id']; $_SESSION['selling_pointid']=$spid; }
}
$userid = current_userid();
$msg = $err = '';

$conn->query("CREATE TABLE IF NOT EXISTS bale_locations (
    id INT AUTO_INCREMENT PRIMARY KEY, selling_pointid INT NOT NULL,
    code VARCHAR(20) NOT NULL, name VARCHAR(100) DEFAULT '',
    subcontractor VARCHAR(100) DEFAULT '', is_primary TINYINT DEFAULT 0,
    active TINYINT DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

// Also migrate any selling_pointid=0 locations to current floor
if ($spid) $conn->query("UPDATE bale_locations SET selling_pointid=$spid WHERE selling_pointid=0");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id   = (int)($_POST['loc_id'] ?? 0);
        $code = $conn->real_escape_string(strtoupper(trim($_POST['code'] ?? '')));
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $sub  = $conn->real_escape_string(trim($_POST['subcontractor'] ?? ''));
        $prim = isset($_POST['is_primary']) ? 1 : 0;
        if (!$code) { $err = "Location code is required."; }
        else {
            if ($prim) $conn->query("UPDATE bale_locations SET is_primary=0 WHERE selling_pointid=$spid");
            if ($id) {
                $conn->query("UPDATE bale_locations SET code='$code',name='$name',subcontractor='$sub',is_primary=$prim WHERE id=$id AND selling_pointid=$spid");
                $msg = "✓ Location '$code' updated.";
            } else {
                // Check duplicate code
                $ex = $conn->query("SELECT id FROM bale_locations WHERE code='$code' AND selling_pointid=$spid LIMIT 1");
                if ($ex && $ex->num_rows > 0) { $err = "Location code '$code' already exists."; }
                else {
                    $conn->query("INSERT INTO bale_locations (selling_pointid,code,name,subcontractor,is_primary) VALUES ($spid,'$code','$name','$sub',$prim)");
                    audit_log($conn,$spid,$userid,'bale_locations','create',$conn->insert_id,null,['code'=>$code]);
                    $msg = "✓ Location '$code' added.";
                }
            }
        }
    }
    if ($action === 'toggle') {
        $lid = (int)($_POST['lid'] ?? 0);
        $conn->query("UPDATE bale_locations SET active=1-active WHERE id=$lid AND selling_pointid=$spid");
        $msg = "Location status toggled.";
    }
    if ($action === 'delete') {
        $lid = (int)($_POST['lid'] ?? 0);
        // Only delete if no transporters assigned
        $used = $conn->query("SELECT COUNT(*) c FROM transporter WHERE location_id=$lid")->fetch_assoc()['c'] ?? 0;
        if ($used > 0) { $err = "Cannot delete — $used transporter(s) assigned to this location."; }
        else { $conn->query("DELETE FROM bale_locations WHERE id=$lid AND selling_pointid=$spid"); $msg="Location deleted."; }
    }
}

// Reload after save — no filter so all show
$locs_q = $conn->query("
    SELECT l.*,
           COUNT(DISTINCT t.id) truck_count
    FROM bale_locations l
    LEFT JOIN transporter t ON t.location_id=l.id
    WHERE l.selling_pointid=$spid
    GROUP BY l.id
    ORDER BY l.is_primary DESC, l.code ASC
");
$locs = ($locs_q && $locs_q !== true) ? $locs_q->fetch_all(MYSQLI_ASSOC) : [];

// Selected for editing
$sel_id = (int)($_GET['edit'] ?? 0);
$sel_loc = null;
foreach ($locs as $l) { if ((int)$l['id'] === $sel_id) { $sel_loc = $l; break; } }

include "includes/layout.php";
?>

<style>
/* Premium Segmented / Capsule Tab Strip */
.premium-tabs-nav {
    display: inline-flex;
    padding: 4px;
    background: var(--cream-d);
    border-radius: 8px;
    margin-bottom: 1.5rem;
    gap: 2px;
    border: 1px solid var(--border);
}
body.theme-dark .premium-tabs-nav {
    background: rgba(26,18,8,0.3);
}
.premium-tab-btn {
    padding: 0.6rem 1.2rem;
    background: transparent;
    border: none;
    border-radius: 6px;
    font-family: inherit;
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--muted);
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    user-select: none;
}
.premium-tab-btn:hover {
    color: var(--ink);
    background: rgba(26, 18, 8, 0.04);
}
body.theme-dark .premium-tab-btn:hover {
    color: var(--cream);
    background: rgba(255, 255, 255, 0.04);
}
.premium-tab-btn.active {
    background: var(--ink);
    color: var(--gold-l);
    box-shadow: 0 4px 12px rgba(26, 18, 8, 0.15);
    font-weight: 600;
}
body.theme-light .premium-tab-btn.active {
    background: var(--ink);
    color: var(--gold-l);
}
body.theme-dark .premium-tab-btn.active {
    background: var(--gold);
    color: var(--ink);
    box-shadow: 0 4px 12px rgba(200, 149, 42, 0.2);
}

.loc-tab-panel {
    display: none;
}
.loc-tab-panel.active {
    display: block;
    animation: locFadeIn 0.25s ease-in-out;
}

@keyframes locFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.loc-form-container {
    max-width: 680px;
    margin: 0 auto;
}
</style>

<div class="page-header">
  <div class="page-header-left"><h2>Bale Locations</h2><p>Manage floor bays and locations for transporters · Floor <?=$spid?></p></div>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>
<?php if(!$spid):?>
<div class="alert alert-danger">No selling point set in session. Please <a href="login.php">log in</a> again.</div>
<?php else:?>

<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1rem">
<div class="kpi-card"><div class="kpi-label">Total Locations</div><div class="kpi-value"><?=count($locs)?></div></div>
<div class="kpi-card"><div class="kpi-label">Active</div><div class="kpi-value text-leaf"><?=count(array_filter($locs,fn($l)=>$l['active']==1))?></div></div>
<div class="kpi-card"><div class="kpi-label">With Trucks</div><div class="kpi-value"><?=count(array_filter($locs,fn($l)=>$l['truck_count']>0))?></div></div>
</div>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="list" onclick="switchLocTab('list')">
        📋 Locations Registry
    </button>
    <button class="premium-tab-btn" data-tab="form" onclick="switchLocTab('form')">
        <?= $sel_loc ? '✍ Edit Location' : '➕ Add Location' ?>
    </button>
</div>

<!-- Tab Panel 1: Locations List -->
<div id="panel-list" class="loc-tab-panel active">
    <div class="card">
        <div class="card-head"><h3>Locations</h3><span class="badge badge-gray"><?=count($locs)?></span></div>
        <?php if(empty($locs)):?>
        <div class="card-body text-center text-muted" style="padding:2.5rem">
          No locations added yet. Create your first floor location.
        </div>
        <?php else:?>
        <div class="table-wrap"><table>
        <thead><tr>
          <th>Code</th><th>Name</th><th>Subcontractor</th>
          <th class="text-right">Trucks</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach($locs as $l):?>
        <tr style="<?=(int)$l['id']===$sel_id?'background:rgba(200,149,42,.07);border-left:3px solid var(--gold)':''?>">
          <td>
            <strong style="font-size:.88rem"><?=htmlspecialchars($l['code'])?></strong>
            <?=$l['is_primary']?'<span class="badge badge-gold" style="font-size:.55rem;margin-left:4px">Primary</span>':''?>
          </td>
          <td style="font-size:.78rem"><?=htmlspecialchars($l['name'])?></td>
          <td style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($l['subcontractor'])?></td>
          <td class="td-mono text-right"><?=$l['truck_count']?></td>
          <td><span class="badge <?=$l['active']?'badge-green':'badge-gray'?>" style="font-size:.6rem"><?=$l['active']?'Active':'Inactive'?></span></td>
          <td style="white-space:nowrap">
            <a href="?edit=<?=$l['id']?>" class="btn btn-ghost btn-sm" style="font-size:.65rem">Edit</a>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle"><input type="hidden" name="lid" value="<?=$l['id']?>">
              <button class="btn btn-ghost btn-sm" style="font-size:.65rem"><?=$l['active']?'Disable':'Enable'?></button>
            </form>
            <?php if($l['truck_count']==0):?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete location <?=htmlspecialchars($l['code'],ENT_QUOTES)?>?')">
              <input type="hidden" name="action" value="delete"><input type="hidden" name="lid" value="<?=$l['id']?>">
              <button class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--danger)">✕</button>
            </form>
            <?php endif;?>
          </td>
        </tr>
        <?php endforeach;?>
        </tbody>
        </table></div>
        <?php endif;?>
    </div>
</div>

<!-- Tab Panel 2: Add/Edit Form -->
<div id="panel-form" class="loc-tab-panel">
    <div class="loc-form-container">
        <div class="card">
            <div class="card-head">
              <h3><?=$sel_loc?'Edit: '.htmlspecialchars($sel_loc['code']):'Add Location'?></h3>
              <?php if($sel_loc):?><a href="locations.php" class="btn btn-ghost btn-sm">+ New</a><?php endif;?>
            </div>
            <div class="card-body">
                <form method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="loc_id" value="<?=$sel_loc?$sel_loc['id']:0?>">
                <div class="form-row cols-2">
                  <div class="form-group">
                    <label>Code * <span style="font-size:.65rem;color:var(--muted)">(e.g. A1, LOC-01)</span></label>
                    <input type="text" name="code" class="form-control" value="<?=htmlspecialchars($sel_loc['code']??'')?>"
                           placeholder="e.g. A1" required style="text-transform:uppercase;font-weight:600;font-size:1rem" id="loc-code-input">
                  </div>
                  <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="name" class="form-control" value="<?=htmlspecialchars($sel_loc['name']??'')?>" placeholder="Bay A Row 1">
                  </div>
                </div>
                <div class="form-group">
                  <label>Subcontractor</label>
                  <input type="text" name="subcontractor" class="form-control" value="<?=htmlspecialchars($sel_loc['subcontractor']??'')?>" placeholder="Subcontractor name (if applicable)">
                </div>
                <div class="form-group" style="background:var(--cream-d);border-radius:6px;padding:.6rem .85rem">
                  <label style="cursor:pointer;font-size:.82rem;display:flex;align-items:center;gap:.5rem">
                    <input type="checkbox" name="is_primary" <?=($sel_loc['is_primary']??0)?'checked':''?>>
                    <span>Primary location <span style="font-size:.68rem;color:var(--muted)">(default for new transporters)</span></span>
                  </label>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem">
                  <?=$sel_loc?'Update Location':'Add Location'?>
                </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function switchLocTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.loc-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_loc_tab', tabId);
        
        if (tabId === 'form') {
            setTimeout(() => {
                const el = document.getElementById('loc-code-input');
                if (el) el.focus();
            }, 50);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    <?php if ($sel_loc || ($err && isset($_POST['action']) && $_POST['action'] === 'save')): ?>
    const defaultTab = 'form';
    <?php else: ?>
    const defaultTab = sessionStorage.getItem('active_loc_tab') || 'list';
    <?php endif; ?>
    
    switchLocTab(defaultTab);
});
</script>

<?php endif;?>
<?php include "includes/layout_end.php"; ?>
