<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title = "Move Bales"; $active_page = "move_bales";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);
$userid = current_userid();
$msg = $err = '';

// AJAX barcode lookup
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $bc = $conn->real_escape_string(trim($_GET['barcode'] ?? ''));
    if (!$bc) { echo json_encode(['error'=>'No barcode']); exit; }
    $r = $conn->query("
        SELECT tk.id ticketid, tk.barcode, bm.temp_bacode timb_bc, bm.mass,
               g.grower_num, g.name, g.surname,
               fs.row fs_row, fs.id fs_id,
               sb.id sold_id,
               CASE WHEN bb.id IS NOT NULL THEN 1 ELSE 0 END is_balanced
        FROM tickets tk
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        LEFT JOIN floor_summary fs ON fs.ticketsid=tk.id
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        LEFT JOIN balanced_bales bb ON bb.transporter_growersid=bj.transporter_growersid
        WHERE tk.barcode='$bc' AND g.selling_pointid=$spid LIMIT 1
    ");
    echo json_encode(($r&&$r!==true&&$r->num_rows>0)?$r->fetch_assoc():['error'=>'Bale not found']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $barcode = $conn->real_escape_string(trim($_POST['barcode'] ?? ''));
    $new_loc = $conn->real_escape_string(trim($_POST['new_location'] ?? ''));
    $new_row = $conn->real_escape_string(trim($_POST['new_row'] ?? ''));
    $reason  = $conn->real_escape_string(trim($_POST['reason'] ?? ''));

    // Find ticket
    $tk_q = $conn->query("SELECT tk.id FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN growers g ON g.id=bj.growerid WHERE tk.barcode='$barcode' AND g.selling_pointid=$spid LIMIT 1");
    $tk   = ($tk_q&&$tk_q!==true)?$tk_q->fetch_assoc():null;
    if (!$tk) { $err = "Bale not found."; }
    else {
        $tkid = (int)$tk['id'];
        // Update or insert floor_summary row
        $ex = $conn->query("SELECT id FROM floor_summary WHERE ticketsid=$tkid LIMIT 1");
        if ($ex && $ex->num_rows > 0) {
            $fsid = (int)$ex->fetch_assoc()['id'];
            $conn->query("UPDATE floor_summary SET `row`='$new_row', created_at=NOW() WHERE id=$fsid");
        } else {
            $conn->query("INSERT INTO floor_summary (userid,ticketsid,`row`,created_at) VALUES ($userid,$tkid,'$new_row',NOW())");
        }
        audit_log($conn,$spid,$userid,'floor_summary','move',$tkid,null,['barcode'=>$barcode,'new_row'=>$new_row,'reason'=>$reason]);
        $msg = "✓ Bale '$barcode' moved to row '$new_row'.";
    }
}

// Recent moves
$recent_q = $conn->query("
    SELECT tk.barcode, bm.temp_bacode timb_bc, fs.row, bm.mass, g.grower_num, DATE(fs.datetime) move_date
    FROM floor_summary fs
    JOIN tickets tk ON tk.id=fs.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    WHERE g.selling_pointid=$spid
    ORDER BY fs.datetime DESC LIMIT 50
");
$recent = ($recent_q&&$recent_q!==true)?$recent_q->fetch_all(MYSQLI_ASSOC):[];

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
    background:var(--primary-bg);
    color:var(--primary-text);
    box-shadow: 0 4px 12px rgba(26, 18, 8, 0.15);
    font-weight: 600;
}
body.theme-light .premium-tab-btn.active {
    background:var(--primary-bg);
    color:var(--primary-text);
}
body.theme-dark .premium-tab-btn.active {
    background: var(--gold);
    color: var(--ink);
    box-shadow: 0 4px 12px rgba(200, 149, 42, 0.2);
}

.move-tab-panel {
    display: none;
}
.move-tab-panel.active {
    display: block;
    animation: moveFadeIn 0.25s ease-in-out;
}

@keyframes moveFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.move-form-container {
    max-width: 600px;
    margin: 0 auto;
}
</style>

<div class="page-header"><div class="page-header-left"><h2>Move Bales</h2><p>Update bale floor position / row assignment</p></div></div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="entry" onclick="switchMoveTab('entry')">
        📦 Move Bale
    </button>
    <button class="premium-tab-btn" data-tab="history" onclick="switchMoveTab('history')">
        📋 Recent Moves
    </button>
</div>

<!-- Tab Panel 1: Move Bale Form -->
<div id="panel-entry" class="move-tab-panel active">
    <div class="move-form-container">
        <div class="card mb-3">
            <div class="card-head"><h3>Move Bale</h3></div>
            <div class="card-body">
                <div style="margin-bottom:.75rem">
                  <label style="font-size:.68rem;color:var(--muted);display:block;margin-bottom:3px">Scan Ticket Barcode</label>
                  <input type="text" id="bc-lookup" class="form-control" placeholder="Scan or type barcode..." style="font-size:1rem;font-family:monospace" onkeydown="if(event.key==='Enter'){event.preventDefault();lookupBale()}" autofocus>
                  <div id="bale-info" style="margin-top:.5rem;font-size:.78rem;color:var(--muted)"></div>
                </div>
                <form method="POST" id="move-form">
                    <input type="hidden" name="barcode" id="f-barcode">
                    <div class="form-row cols-2">
                        <div class="form-group"><label>New Row/Position</label><input type="text" name="new_row" id="f-row" class="form-control" placeholder="e.g. B4" style="text-transform:uppercase" required></div>
                        <div class="form-group"><label>Location Code</label><input type="text" name="new_location" id="f-loc" class="form-control" placeholder="e.g. A1"></div>
                    </div>
                    <div class="form-group"><label>Reason</label><input type="text" name="reason" class="form-control" placeholder="e.g. Re-graded, misplaced"></div>
                    <button type="submit" class="btn btn-primary" style="width:100%">Move Bale</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 2: Recent Moves -->
<div id="panel-history" class="move-tab-panel">
    <div class="card">
        <div class="card-head">
            <h3>Recent Moves</h3>
        </div>
        <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
            <table>
                <thead>
                    <tr>
                        <th>Grower</th>
                        <th>TIMB BC</th>
                        <th>Floor BC</th>
                        <th class="text-right">Mass</th>
                        <th>Row</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($recent)):?>
                <tr><td colspan="6" class="text-center text-muted" style="padding:2rem">No moves yet.</td></tr>
                <?php else:foreach($recent as $r):?>
                <tr>
                    <td><span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($r['grower_num'])?></span></td>
                    <td style="font-family:monospace;font-size:.7rem"><?=htmlspecialchars($r['timb_bc'])?></td>
                    <td style="font-family:monospace;font-size:.7rem"><?=htmlspecialchars($r['barcode'])?></td>
                    <td class="td-mono text-right"><?=number_format((float)$r['mass'],2)?></td>
                    <td><strong><?=htmlspecialchars($r['row'])?></strong></td>
                    <td style="font-size:.68rem;color:var(--muted)"><?=$r['move_date']?></td>
                </tr>
                <?php endforeach;endif;?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function switchMoveTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.move-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_move_tab', tabId);
        
        if (tabId === 'entry') {
            setTimeout(() => {
                const el = document.getElementById('bc-lookup');
                if (el) el.focus();
            }, 50);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $msg): ?>
    const defaultTab = 'history';
    <?php else: ?>
    const defaultTab = sessionStorage.getItem('active_move_tab') || 'entry';
    <?php endif; ?>
    
    switchMoveTab(defaultTab);
});

async function lookupBale() {
    var bc = document.getElementById('bc-lookup').value.trim();
    if (!bc) return;
    var r = await fw.get('move_bales.php', {ajax:1, barcode:bc});
    var info = document.getElementById('bale-info');
    if (r.error) { info.style.color='var(--danger)'; info.textContent=r.error; return; }
    info.style.color='var(--leaf)';
    info.textContent='✓ '+r.grower_num+' — '+r.name+' '+r.surname+' · '+r.mass+' kg · Current row: '+(r.fs_row||'—');
    document.getElementById('f-barcode').value = bc;
    document.getElementById('f-row').focus();
}
</script>

<?php include "includes/layout_end.php"; ?>
