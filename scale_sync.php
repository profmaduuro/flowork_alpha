<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title="Scale Sync";$active_page="scale_sync";
$spid=(int)($_SESSION['selling_pointid']??0);$today=date('Y-m-d');

// API endpoint for Android scale app to POST weighings
// Called as: scale_sync.php?api=1&token=XXX  POST JSON
if(isset($_GET['api'])){
    header('Content-Type: application/json');
    $conn->query("CREATE TABLE IF NOT EXISTS scale_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,selling_pointid INT NOT NULL,
        device_id VARCHAR(60) DEFAULT '',operator VARCHAR(60) DEFAULT '',
        temp_bacode VARCHAR(40) DEFAULT '',mass DECIMAL(10,2) NOT NULL,
        tare DECIMAL(10,2) DEFAULT 0,gross DECIMAL(10,2) DEFAULT 0,
        unit VARCHAR(10) DEFAULT 'kg',status ENUM('pending','processed','rejected') DEFAULT 'pending',
        raw_data TEXT DEFAULT '',synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_sp_status(selling_pointid,status))");

    // Verify token
    $tk_q=$conn->query("SELECT value FROM settings WHERE name='scale_api_token' LIMIT 1");
    $valid_token=($tk_q&&$tk_q!==true)?($tk_q->fetch_assoc()['value']??'flowork_scale'):null;
    if(($_GET['token']??'')!==$valid_token){http_response_code(401);echo json_encode(['error'=>'Unauthorized']);exit;}

    $method=$_SERVER['REQUEST_METHOD'];

    // POST: receive weighing from scale
    if($method==='POST'){
        $raw=file_get_contents('php://input');
        $data=@json_decode($raw,true)??$_POST;
        $sp=(int)($data['selling_pointid']??$spid);
        $dev=$conn->real_escape_string($data['device_id']??'');
        $op =$conn->real_escape_string($data['operator']??'');
        $bc =$conn->real_escape_string($data['temp_bacode']??$data['barcode']??'');
        $mass=(float)($data['mass']??$data['net_mass']??0);
        $tare=(float)($data['tare']??0);
        $gross=(float)($data['gross']??$mass+$tare);
        $unit=$conn->real_escape_string($data['unit']??'kg');
        $raw_esc=$conn->real_escape_string(substr($raw,0,2000));
        if($mass<=0){echo json_encode(['error'=>'Invalid mass']);exit;}
        $conn->query("INSERT INTO scale_queue (selling_pointid,device_id,operator,temp_bacode,mass,tare,gross,unit,raw_data)
            VALUES ($sp,'$dev','$op','$bc',$mass,$tare,$gross,'$unit','$raw_esc')");
        $qid=$conn->insert_id;
        // Auto-create bale_mass record if enabled
        $auto_q=$conn->query("SELECT value FROM settings WHERE name='scale_auto_create' LIMIT 1");
        $auto=($auto_q&&$auto_q!==true)?($auto_q->fetch_assoc()['value']??'0'):'0';
        $bm_id=null;
        if($auto==='1'&&$bc){
            $conn->query("INSERT IGNORE INTO bale_mass (temp_bacode,mass,selling_pointid) VALUES ('$bc',$mass,$sp)");
            $bm_id=$conn->insert_id;
            if($bm_id){$conn->query("UPDATE scale_queue SET status='processed',processed_at=NOW() WHERE id=$qid");}
        }
        echo json_encode(['ok'=>true,'queue_id'=>$qid,'bale_mass_id'=>$bm_id,'mass'=>$mass,'barcode'=>$bc]);exit;
    }

    // GET: fetch pending weighings for processing
    if($method==='GET'&&isset($_GET['fetch'])){
        $pending=$conn->query("SELECT * FROM scale_queue WHERE selling_pointid=$spid AND status='pending' ORDER BY id DESC LIMIT 50");
        $rows=($pending&&$pending!==true)?$pending->fetch_all(MYSQLI_ASSOC):[];
        echo json_encode(['pending'=>$rows,'count'=>count($rows)]);exit;
    }

    // GET: status
    $pending_c=$conn->query("SELECT COUNT(*) c FROM scale_queue WHERE selling_pointid=$spid AND status='pending'")->fetch_assoc()['c']??0;
    $today_c=$conn->query("SELECT COUNT(*) c FROM scale_queue WHERE selling_pointid=$spid AND DATE(synced_at)='$today'")->fetch_assoc()['c']??0;
    echo json_encode(['status'=>'ok','pending'=>(int)$pending_c,'today'=>(int)$today_c,'time'=>date('H:i:s')]);exit;
}

// ── Process pending weighings ──────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])){
    $action=$_POST['action']??'';
    if($action==='process'){
        $qid=(int)($_POST['queue_id']??0);
        $bc=$conn->real_escape_string(trim($_POST['barcode']??''));
        $mass=(float)($_POST['mass']??0);
        if(!$bc||$mass<=0){echo '<script>alert("Barcode and mass required");history.back();</script>';exit;}
        $conn->query("INSERT IGNORE INTO bale_mass (temp_bacode,mass,selling_pointid) VALUES ('$bc',$mass,$spid)");
        $bm_id=$conn->insert_id;
        if($bm_id)$conn->query("UPDATE scale_queue SET status='processed',processed_at=NOW() WHERE id=$qid AND selling_pointid=$spid");
        header("Location: scale_sync.php?processed=$bm_id");exit;
    }
    if($action==='reject'){
        $qid=(int)($_POST['queue_id']??0);
        $conn->query("UPDATE scale_queue SET status='rejected' WHERE id=$qid AND selling_pointid=$spid");
        header("Location: scale_sync.php");exit;
    }
    if($action==='save_token'){
        $tok=$conn->real_escape_string(trim($_POST['token']??''));
        $auto=$_POST['auto_create']==='1'?'1':'0';
        $conn->query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(80) PRIMARY KEY, value TEXT DEFAULT '')");
        $conn->query("INSERT INTO settings (name,value) VALUES ('scale_api_token','$tok') ON DUPLICATE KEY UPDATE value='$tok'");
        $conn->query("INSERT INTO settings (name,value) VALUES ('scale_auto_create','$auto') ON DUPLICATE KEY UPDATE value='$auto'");
        header("Location: scale_sync.php?saved=1");exit;
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS scale_queue (id INT AUTO_INCREMENT PRIMARY KEY,selling_pointid INT NOT NULL,device_id VARCHAR(60) DEFAULT '',operator VARCHAR(60) DEFAULT '',temp_bacode VARCHAR(40) DEFAULT '',mass DECIMAL(10,2) NOT NULL,tare DECIMAL(10,2) DEFAULT 0,gross DECIMAL(10,2) DEFAULT 0,unit VARCHAR(10) DEFAULT 'kg',status ENUM('pending','processed','rejected') DEFAULT 'pending',raw_data TEXT DEFAULT '',synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,processed_at TIMESTAMP NULL DEFAULT NULL,INDEX idx_sp_status(selling_pointid,status))");

$pending_q=$conn->query("SELECT * FROM scale_queue WHERE selling_pointid=$spid AND status='pending' ORDER BY id DESC LIMIT 50");
$pending=($pending_q&&$pending_q!==true)?$pending_q->fetch_all(MYSQLI_ASSOC):[];
$history_q=$conn->query("SELECT * FROM scale_queue WHERE selling_pointid=$spid AND status!='pending' ORDER BY id DESC LIMIT 30");
$history=($history_q&&$history_q!==true)?$history_q->fetch_all(MYSQLI_ASSOC):[];
$token_q=$conn->query("SELECT value FROM settings WHERE name='scale_api_token' LIMIT 1");
$api_token=($token_q&&$token_q!==true)?($token_q->fetch_assoc()['value']??'flowork_scale'):'flowork_scale';
$auto_q=$conn->query("SELECT value FROM settings WHERE name='scale_auto_create' LIMIT 1");
$auto_create=($auto_q&&$auto_q!==true)?($auto_q->fetch_assoc()['value']??'0'):'0';
$api_url=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']).'/scale_sync.php?api=1&token='.$api_token;

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

.sync-tab-panel {
    display: none;
}
.sync-tab-panel.active {
    display: block;
    animation: syncFadeIn 0.25s ease-in-out;
}

@keyframes syncFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.sync-form-container {
    max-width: 680px;
    margin: 0 auto;
}
</style>

<div class="page-header">
  <div class="page-header-left"><h2>Scale Sync</h2><p>Real-time weighing data from Android scale app</p></div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <?php if(isset($_GET['processed'])):?><span class="badge badge-green">✓ Bale created</span><?php endif;?>
    <?php if(isset($_GET['saved'])):?><span class="badge badge-green">✓ Settings saved</span><?php endif;?>
    <span id="live-badge" style="font-size:.72rem;color:var(--muted)">Live</span>
    <button onclick="pollQueue()" class="btn btn-ghost btn-sm">⟳ Refresh</button>
  </div>
</div>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="queue" onclick="switchSyncTab('queue')">
        ⚡ Pending Queue (<?=count($pending)?>)
    </button>
    <button class="premium-tab-btn" data-tab="history" onclick="switchSyncTab('history')">
        📋 Processed History
    </button>
    <button class="premium-tab-btn" data-tab="config" onclick="switchSyncTab('config')">
        ⚙ Android Integration
    </button>
</div>

<!-- Tab Panel 1: Pending Queue -->
<div id="panel-queue" class="sync-tab-panel active">
    <div class="sync-form-container">
        <div class="card mb-3" style="border-top:3px solid var(--gold)">
            <div class="card-head">
              <h3>⚡ Pending Weighings</h3>
              <span class="badge badge-gold" id="pending-count"><?=count($pending)?></span>
            </div>
            <?php if(empty($pending)):?>
            <div class="card-body text-center text-muted" style="padding:2rem">
              <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem">⚖</div>
              No pending weighings. Connect the Android scale app to start receiving data.
            </div>
            <?php else:?>
            <div id="pending-list">
            <?php foreach($pending as $p):?>
            <div style="padding:.85rem 1.15rem;border-bottom:1px solid var(--border)" id="q-<?=$p['id']?>">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
                <div style="display:flex;align-items:center;gap:.5rem">
                  <span style="font-size:1.4rem;font-weight:700;font-family:'Bebas Neue',sans-serif;color:var(--gold)"><?=number_format((float)$p['mass'],2)?> kg</span>
                  <?php if($p['temp_bacode']):?><span class="badge badge-gray" style="font-size:.65rem;font-family:monospace"><?=htmlspecialchars($p['temp_bacode'])?></span><?php endif;?>
                </div>
                <div style="font-size:.68rem;color:var(--muted)"><?=date('H:i:s',strtotime($p['synced_at']))?></div>
              </div>
              <div style="font-size:.72rem;color:var(--muted);margin-bottom:.65rem">
                Device: <?=htmlspecialchars($p['device_id'])?> <?=$p['operator']?' · '.$p['operator']:''?>
                <?=$p['tare']>0?' · Tare: '.$p['tare'].'kg':''?>
              </div>
              <form method="POST" style="display:flex;gap:.5rem;align-items:flex-end">
                <input type="hidden" name="action" value="process">
                <input type="hidden" name="queue_id" value="<?=$p['id']?>">
                <input type="hidden" name="mass" value="<?=$p['mass']?>">
                <div style="flex:1"><label style="font-size:.62rem;display:block;color:var(--muted);margin-bottom:2px">TIMB Barcode</label>
                  <input type="text" name="barcode" class="form-control" value="<?=htmlspecialchars($p['temp_bacode']??'')?>" placeholder="Enter TIMB barcode" required style="font-size:.8rem;padding:.38rem .6rem"></div>
                <button type="submit" class="btn btn-primary" style="padding:.38rem 1rem">✓ Create Bale</button>
                <form method="POST" style="display:inline"><input type="hidden" name="action" value="reject"><input type="hidden" name="queue_id" value="<?=$p['id']?>">
                  <button type="submit" class="btn btn-ghost" style="color:var(--danger);padding:.38rem 1rem">✕</button>
                </form>
              </form>
            </div>
            <?php endforeach;?>
            </div>
            <?php endif;?>
        </div>
    </div>
</div>

<!-- Tab Panel 2: Processed History -->
<div id="panel-history" class="sync-tab-panel">
    <div class="card">
        <div class="card-head"><h3>Recent History</h3><span class="badge badge-gray"><?=count($history)?></span></div>
        <div class="table-wrap" style="max-height:580px;overflow-y:auto"><table>
        <thead><tr><th>Time</th><th>Barcode</th><th class="text-right">Mass</th><th>Device</th><th>Status</th></tr></thead>
        <tbody>
        <?php if(empty($history)):?><tr><td colspan="5" class="text-center text-muted" style="padding:2rem">No history yet.</td></tr>
        <?php else:foreach($history as $h):$sc=['processed'=>'badge-green','rejected'=>'badge-red'];?>
        <tr>
          <td style="font-size:.7rem"><?=date('H:i:s',strtotime($h['synced_at']))?></td>
          <td style="font-family:monospace;font-size:.72rem"><?=htmlspecialchars($h['temp_bacode']??'—')?></td>
          <td class="td-mono text-right"><?=number_format((float)$h['mass'],2)?> kg</td>
          <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($h['device_id'])?></td>
          <td><span class="badge <?=$sc[$h['status']]??'badge-gray'?>" style="font-size:.58rem"><?=strtoupper($h['status'])?></span></td>
        </tr>
        <?php endforeach;endif;?>
        </tbody>
        </table></div>
    </div>
</div>

<!-- Tab Panel 3: Android Integration -->
<div id="panel-config" class="sync-tab-panel">
    <div class="sync-form-container">
        <div class="card">
            <div class="card-head"><h3>Android Integration</h3></div>
            <div class="card-body">
              <div style="font-size:.72rem;color:var(--muted);margin-bottom:.5rem">Configure this URL in your Android scale app:</div>
              <div style="background:var(--ink);color:var(--gold);padding:.6rem .85rem;border-radius:6px;font-family:monospace;font-size:.7rem;word-break:break-all;margin-bottom:.75rem"><?=htmlspecialchars($api_url)?></div>
              <div style="font-size:.7rem;color:var(--muted);margin-bottom:.75rem">POST JSON: <code>{"mass":25.4,"temp_bacode":"ZW001234","tare":0.5,"device_id":"SCALE01","operator":"John"}</code></div>
              <form method="POST">
                <input type="hidden" name="action" value="save_token">
                <div class="form-row cols-2">
                  <div class="form-group"><label>API Token</label><input type="text" name="token" class="form-control" value="<?=htmlspecialchars($api_token)?>" required></div>
                  <div class="form-group"><label>Auto-create bales</label>
                    <select name="auto_create" class="form-control">
                      <option value="0" <?=$auto_create==='0'?'selected':''?>>Manual (review queue)</option>
                      <option value="1" <?=$auto_create==='1'?'selected':''?>>Auto (instant creation)</option>
                    </select>
                  </div>
                </div>
                <button type="submit" class="btn btn-ghost" style="width:100%">Save Settings</button>
              </form>
            </div>
        </div>
    </div>
</div>

<script>
function switchSyncTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.sync-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_sync_tab', tabId);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    <?php if (isset($_GET['processed'])): ?>
    const defaultTab = 'history';
    <?php elseif (isset($_GET['saved'])): ?>
    const defaultTab = 'config';
    <?php else: ?>
    const defaultTab = sessionStorage.getItem('active_sync_tab') || 'queue';
    <?php endif; ?>
    
    switchSyncTab(defaultTab);
});

function pollQueue(){
    fetch('scale_sync.php?api=1&token=<?=urlencode($api_token)?>&fetch=1')
        .then(r=>r.json()).then(d=>{
            const countBadge = document.getElementById('pending-count');
            if (countBadge) countBadge.textContent=d.count;
            
            const liveBadge = document.getElementById('live-badge');
            if (liveBadge) liveBadge.textContent='Updated '+new Date().toLocaleTimeString();
            
            if(d.count > 0 && sessionStorage.getItem('active_sync_tab') === 'queue') {
                location.reload();
            }
        }).catch(()=>{});
}
setInterval(pollQueue,15000);
</script>

<?php include "includes/layout_end.php"; ?>
