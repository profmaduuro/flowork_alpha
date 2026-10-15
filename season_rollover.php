<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "audit.php";
require_login();

$page_title  = "Season Rollover";
$active_page = "season_rollover";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();
$msg=$err='';

// Ensure seasons table columns exist (handles cases where seasons was created by setup_database.php)
$cols_check = $conn->query("SHOW COLUMNS FROM seasons");
$existing_cols = [];
if ($cols_check) {
    while ($c = $cols_check->fetch_assoc()) {
        $existing_cols[] = strtolower($c['Field']);
    }
}
if (!in_array('selling_pointid', $existing_cols)) {
    $conn->query("ALTER TABLE seasons ADD COLUMN selling_pointid INT NOT NULL DEFAULT 0");
}
if (!in_array('name', $existing_cols)) {
    $conn->query("ALTER TABLE seasons ADD COLUMN name VARCHAR(60) NOT NULL DEFAULT ''");
}
if (!in_array('year_start', $existing_cols)) {
    $conn->query("ALTER TABLE seasons ADD COLUMN year_start INT NOT NULL DEFAULT 0");
}
if (!in_array('year_end', $existing_cols)) {
    $conn->query("ALTER TABLE seasons ADD COLUMN year_end INT NOT NULL DEFAULT 0");
}
if (!in_array('status', $existing_cols)) {
    $conn->query("ALTER TABLE seasons ADD COLUMN status ENUM('upcoming','active','closed') DEFAULT 'upcoming'");
}
if (!in_array('open_date', $existing_cols)) {
    $conn->query("ALTER TABLE seasons ADD COLUMN open_date DATE DEFAULT NULL");
}
if (!in_array('close_date', $existing_cols)) {
    $conn->query("ALTER TABLE seasons ADD COLUMN close_date DATE DEFAULT NULL");
}
if (!in_array('notes', $existing_cols)) {
    $conn->query("ALTER TABLE seasons ADD COLUMN notes TEXT DEFAULT NULL");
}
if (!in_array('created_at', $existing_cols)) {
    $conn->query("ALTER TABLE seasons ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

$conn->query("CREATE TABLE IF NOT EXISTS season_growers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seasonid INT NOT NULL,
    growerid INT NOT NULL,
    selling_pointid INT NOT NULL,
    contract_number VARCHAR(60) DEFAULT '',
    crop_type VARCHAR(30) DEFAULT 'Virginia',
    expected_kg DECIMAL(10,2) DEFAULT 0,
    status ENUM('active','withdrawn','completed') DEFAULT 'active',
    rolled_from_seasonid INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sg (seasonid, growerid)
)");

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action==='create_season') {
        $name  = $conn->real_escape_string(trim($_POST['name']??''));
        $yr_s  = (int)($_POST['year_start']??date('Y'));
        $yr_e  = (int)($_POST['year_end']??$yr_s);
        $notes = $conn->real_escape_string(trim($_POST['notes']??''));
        if (!$name) { $err="Season name required."; }
        else {
            // Populate both name and description to stay fully compatible with setup.php!
            if ($conn->query("INSERT INTO seasons (selling_pointid,name,description,year_start,year_end,notes) VALUES ($spid,'$name','$name',$yr_s,$yr_e,'$notes')")) {
                audit_log($conn,$spid,$userid,'seasons','create',$conn->insert_id,null,['name'=>$name]);
                $msg = "✓ Season '$name' created.";
            } else {
                $err = "Database Error: " . $conn->error;
            }
        }
    }

    if ($action==='activate_season') {
        $sid = (int)($_POST['season_id']??0);
        $conn->query("UPDATE seasons SET status='closed' WHERE (selling_pointid=$spid OR selling_pointid=0) AND status='active'");
        $conn->query("UPDATE seasons SET status='active',open_date=CURDATE() WHERE id=$sid");
        audit_log($conn,$spid,$userid,'seasons','activate',$sid);
        $msg = "✓ Season activated.";
    }

    if ($action==='close_season') {
        $sid = (int)($_POST['season_id']??0);
        $conn->query("UPDATE seasons SET status='closed',close_date=CURDATE() WHERE id=$sid");
        audit_log($conn,$spid,$userid,'seasons','close',$sid);
        $msg = "✓ Season closed.";
    }

    if ($action==='rollover') {
        $from_sid = (int)($_POST['from_season']??0);
        $to_sid   = (int)($_POST['to_season']??0);
        $mode     = $_POST['rollover_mode'] ?? 'all';
        if (!$from_sid || !$to_sid) { $err="Select both seasons."; }
        else {
            if ($mode === 'with_sales') {
                $gids_q = $conn->query("
                    SELECT DISTINCT g.id FROM growers g
                    JOIN bale_junus bj ON bj.growerid=g.id
                    JOIN tickets tk ON tk.bale_junusid=bj.id
                    JOIN sold_bales sb ON sb.ticketsid=tk.id
                    WHERE g.selling_pointid=$spid
                    AND YEAR(sb.created_at)=(SELECT year_start FROM seasons WHERE id=$from_sid LIMIT 1)
                ");
            } elseif ($mode === 'active_only') {
                $gids_q = $conn->query("SELECT growerid AS id FROM season_growers WHERE seasonid=$from_sid AND status='active'");
            } else {
                $gids_q = $conn->query("SELECT id FROM growers WHERE selling_pointid=$spid AND active=1");
            }

            $rolled = 0;
            while ($gids_q && $r=$gids_q->fetch_assoc()) {
                $gid = (int)$r['id'];
                $src_q = $conn->query("SELECT * FROM season_growers WHERE seasonid=$from_sid AND growerid=$gid LIMIT 1");
                $src = ($src_q&&$src_q!==true)?($src_q->fetch_assoc()??[]):[];
                $crop = $conn->real_escape_string($src['crop_type']??'Virginia');
                $cnum = $conn->real_escape_string($src['contract_number']??'');
                $conn->query("INSERT IGNORE INTO season_growers (seasonid,growerid,selling_pointid,crop_type,contract_number,rolled_from_seasonid)
                    VALUES ($to_sid,$gid,$spid,'$crop','$cnum',$from_sid)");
                if ($conn->affected_rows > 0) $rolled++;
            }
            audit_log($conn,$spid,$userid,'season_growers','rollover',null,null,['from'=>$from_sid,'to'=>$to_sid,'count'=>$rolled]);
            $msg = "✓ Rolled over $rolled growers to new season.";
        }
    }
}

// Load seasons (support legacy and multi-floor seasons)
$seasons_q = $conn->query("SELECT s.*, COALESCE(NULLIF(s.name, ''), s.description) AS name, COUNT(sg.id) grower_count 
                           FROM seasons s 
                           LEFT JOIN season_growers sg ON sg.seasonid=s.id 
                           WHERE (s.selling_pointid=$spid OR s.selling_pointid=0) 
                           GROUP BY s.id 
                           ORDER BY s.year_start DESC");
$seasons = ($seasons_q&&$seasons_q!==true)?$seasons_q->fetch_all(MYSQLI_ASSOC):[];
$active_season = array_values(array_filter($seasons,fn($s)=>$s['status']==='active'))[0] ?? null;

// Total growers on floor
$total_growers = (int)($conn->query("SELECT COUNT(*) c FROM growers WHERE selling_pointid=$spid")->fetch_assoc()['c']??0);

$yr = date('Y');

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

.rollover-tab-panel {
    display: none;
}
.rollover-tab-panel.active {
    display: block;
    animation: rolloverFadeIn 0.25s ease-in-out;
}

@keyframes rolloverFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.rollover-form-container {
    max-width: 680px;
    margin: 0 auto;
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Season Rollover</h2>
    <p>Manage tobacco seasons and carry growers forward</p>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<?php if($active_season):?>
<div class="alert mb-3" style="background:rgba(61,107,53,.08);border-left:3px solid var(--leaf);padding:.75rem 1rem;font-size:.82rem">
  🟢 <strong>Active Season:</strong> <?=htmlspecialchars($active_season['name'])?> (<?=$active_season['year_start']?>)
  · <?=number_format($active_season['grower_count'])?> growers enrolled
</div>
<?php endif;?>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="list" onclick="switchRolloverTab('list')">
        📋 Season Registry
    </button>
    <button class="premium-tab-btn" data-tab="create" onclick="switchRolloverTab('create')">
        ➕ Create Season
    </button>
    <?php if(count($seasons) >= 2):?>
    <button class="premium-tab-btn" data-tab="roll" onclick="switchRolloverTab('roll')">
        ⟳ Roll Growers
    </button>
    <?php endif;?>
</div>

<!-- Tab Panel 1: Season Registry -->
<div id="panel-list" class="rollover-tab-panel active">
    <div class="card">
        <div class="card-head">
            <h3>All Seasons</h3>
            <span class="badge badge-gray"><?=count($seasons)?></span>
        </div>
        <?php if(empty($seasons)):?>
        <div class="card-body text-center text-muted">No seasons created yet.</div>
        <?php else:?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Season</th>
                        <th>Year</th>
                        <th class="text-right">Growers</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($seasons as $s):
                  $sc=['upcoming'=>'badge-gray','active'=>'badge-green','closed'=>'badge-red'];
                ?>
                <tr>
                  <td style="font-weight:500"><?=htmlspecialchars($s['name'])?></td>
                  <td class="td-mono"><?=$s['year_start']?></td>
                  <td class="td-mono text-right"><?=number_format((int)$s['grower_count'])?></td>
                  <td><span class="badge <?=$sc[$s['status']]?>" style="font-size:.62rem"><?=ucfirst($s['status'])?></span></td>
                  <td style="white-space:nowrap">
                    <?php if($s['status']==='upcoming'):?>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="action" value="activate_season">
                      <input type="hidden" name="season_id" value="<?=$s['id']?>">
                      <button class="btn btn-ghost btn-sm" style="font-size:.65rem">▶ Activate</button>
                    </form>
                    <?php elseif($s['status']==='active'):?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Close this season?')">
                      <input type="hidden" name="action" value="close_season">
                      <input type="hidden" name="season_id" value="<?=$s['id']?>">
                      <button class="btn btn-danger btn-sm" style="font-size:.65rem">■ Close</button>
                    </form>
                    <?php endif;?>
                  </td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
        <?php endif;?>
    </div>
</div>

<!-- Tab Panel 2: Create Season -->
<div id="panel-create" class="rollover-tab-panel">
    <div class="rollover-form-container">
        <div class="card">
            <div class="card-head">
                <h3>Create New Season</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="create_season">
                    <div class="form-row cols-2">
                      <div class="form-group">
                        <label>Season Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. 2026 Main Season" required>
                      </div>
                      <div class="form-group">
                        <label>Year</label>
                        <input type="number" name="year_start" class="form-control" value="<?=$yr+1?>" min="2020" max="2040">
                      </div>
                    </div>
                    <div class="form-group">
                      <label>Notes</label>
                      <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%">+ Create Season</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if(count($seasons) >= 2):?>
<!-- Tab Panel 3: Roll Growers -->
<div id="panel-roll" class="rollover-tab-panel">
    <div class="rollover-form-container">
        <div class="card">
            <div class="card-head">
                <h3>Roll Growers to New Season</h3>
            </div>
            <div class="card-body">
                <form method="POST" onsubmit="return confirm('Roll growers to the selected season?')">
                    <input type="hidden" name="action" value="rollover">
                    <div class="form-group">
                      <label>From Season</label>
                      <select name="from_season" class="form-control">
                        <?php foreach($seasons as $s):?>
                        <option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?> (<?=$s['grower_count']?> growers)</option>
                        <?php endforeach;?>
                      </select>
                    </div>
                    <div class="form-group">
                      <label>To Season</label>
                      <select name="to_season" class="form-control">
                        <?php foreach($seasons as $s):?>
                        <option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option>
                        <?php endforeach;?>
                      </select>
                    </div>
                    <div class="form-group">
                      <label>Which growers to roll</label>
                      <select name="rollover_mode" class="form-control">
                        <option value="all">All active growers on this floor</option>
                        <option value="active_only">Only those enrolled in source season</option>
                        <option value="with_sales">Only those who sold in source season</option>
                      </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%" data-progress-label="Rolling over...">⟳ Roll Growers Forward</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif;?>

<script>
function switchRolloverTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.rollover-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_rollover_tab', tabId);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_season' && $err): ?>
    const defaultTab = 'create';
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'rollover'): ?>
    const defaultTab = 'roll';
    <?php else: ?>
    const defaultTab = sessionStorage.getItem('active_rollover_tab') || 'list';
    <?php endif; ?>
    
    switchRolloverTab(defaultTab);
});
</script>

<?php include "includes/layout_end.php"; ?>
