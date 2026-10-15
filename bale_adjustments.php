<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title = "Bale Adjustments"; $active_page = "bale_adjustments";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);
$userid = current_userid();
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $barcode = $conn->real_escape_string(trim($_POST['barcode'] ?? ''));
    $reason  = $conn->real_escape_string(trim($_POST['reason'] ?? ''));

    // Find the ticket/bale
    $tk_q = $conn->query("SELECT tk.id tkid, tk.bale_junusid bjid, bm.id bmid, bm.mass, sb.id sbid
        FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        WHERE tk.barcode='$barcode' AND g.selling_pointid=$spid LIMIT 1");
    $tk = ($tk_q&&$tk_q!==true&&$tk_q->num_rows>0)?$tk_q->fetch_assoc():null;

    if (!$tk && $barcode) { $err = "Bale not found."; }
    elseif ($tk) {
        if ($action === 'adjust_mass') {
            $new_mass = (float)($_POST['new_mass'] ?? 0);
            if ($new_mass <= 0) { $err = "Valid mass required."; }
            else {
                $old_mass = (float)$tk['mass'];
                $conn->query("UPDATE bale_mass SET mass=$new_mass WHERE id={$tk['bmid']}");
                audit_log($conn,$spid,$userid,'bale_mass','adjust',$tk['bmid'],['mass'=>$old_mass],['mass'=>$new_mass],$reason);
                $msg = "✓ Mass adjusted from {$old_mass}kg to {$new_mass}kg.";
            }
        }
        if ($action === 'void_bale') {
            // Remove junus entry — effectively removes bale from floor
            $conn->query("UPDATE bale_mass SET used=0 WHERE id={$tk['bmid']}");
            $conn->query("DELETE FROM bale_junus WHERE id={$tk['bjid']}");
            if ($tk['sbid']) $conn->query("DELETE FROM sold_bales WHERE id={$tk['sbid']}");
            $conn->query("DELETE FROM tickets WHERE id={$tk['tkid']}");
            audit_log($conn,$spid,$userid,'bale_junus','void',$tk['bjid'],null,null,$reason);
            $msg = "✓ Bale '$barcode' voided and removed.";
        }
        if ($action === 'remark_unsold') {
            if ($tk['sbid']) {
                $conn->query("DELETE FROM sold_bales WHERE id={$tk['sbid']}");
                audit_log($conn,$spid,$userid,'sold_bales','remark_unsold',$tk['sbid'],null,null,$reason);
                $msg = "✓ Bale '$barcode' marked as unsold.";
            } else { $err = "Bale is already unsold."; }
        }
    }
}

// Recent bales for reference
$bales_q = $conn->query("
    SELECT tk.barcode, bm.temp_bacode, bm.mass, g.grower_num,
           CASE WHEN sb.id IS NOT NULL THEN 'Sold' ELSE 'Unsold' END status,
           CASE WHEN bb.id IS NOT NULL THEN 'Balanced' ELSE 'Not balanced' END bal_status
    FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=bj.transporter_growersid
    WHERE g.selling_pointid=$spid AND DATE(tk.datetime)=CURDATE()
    ORDER BY tk.id DESC LIMIT 50
");
$bales = ($bales_q&&$bales_q!==true)?$bales_q->fetch_all(MYSQLI_ASSOC):[];

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

.adj-tab-panel {
    display: none;
}
.adj-tab-panel.active {
    display: block;
    animation: adjFadeIn 0.25s ease-in-out;
}

@keyframes adjFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.adj-console-container {
    max-width: 540px;
    margin: 0 auto;
}
</style>

<div class="page-header">
    <div class="page-header-left">
        <h2>Bale Adjustments</h2>
        <p>Void bales, adjust masses and correct errors</p>
    </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="alert mb-3" style="background:rgba(200,149,42,.08);border-left:3px solid var(--gold);font-size:.8rem;padding:.65rem 1rem">
  ⚠ Adjustments are permanent and audited. Use void to remove bales, mass adjust to correct scale errors.
</div>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="adjust" onclick="switchAdjTab('adjust')">
        ⚖️ Adjust Mass
    </button>
    <button class="premium-tab-btn" data-tab="void" onclick="switchAdjTab('void')">
        🚫 Void Bale
    </button>
    <button class="premium-tab-btn" data-tab="unsold" onclick="switchAdjTab('unsold')">
        🔄 Mark Unsold
    </button>
    <button class="premium-tab-btn" data-tab="log" onclick="switchAdjTab('log')">
        📋 Today's Bales
    </button>
</div>

<!-- Tab Panel 1: Adjust Mass -->
<div id="panel-adjust" class="adj-tab-panel active">
    <div class="adj-console-container">
        <div class="card">
            <div class="card-head"><h3>Adjust Mass</h3></div>
            <div class="card-body">
                <form method="POST">
                <input type="hidden" name="action" value="adjust_mass">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Floor Barcode</label>
                        <input type="text" name="barcode" class="form-control" required placeholder="Scan ticket barcode" style="font-family:monospace">
                    </div>
                    <div class="form-group">
                        <label>New Mass (kg)</label>
                        <input type="number" name="new_mass" class="form-control" step="0.01" min="0.1" required placeholder="0.00">
                    </div>
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <input type="text" name="reason" class="form-control" required placeholder="e.g. Scale error correction">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%">Adjust Mass</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 2: Void Bale -->
<div id="panel-void" class="adj-tab-panel">
    <div class="adj-console-container">
        <div class="card">
            <div class="card-head"><h3>Void Bale</h3></div>
            <div class="card-body">
                <div class="alert" style="background:#fce4ec;border-left:3px solid var(--danger);font-size:.78rem;padding:.5rem .75rem;margin-bottom:.75rem">⚠ This permanently removes the bale from the system.</div>
                <form method="POST" onsubmit="return confirm('Permanently void this bale?')">
                <input type="hidden" name="action" value="void_bale">
                <div class="form-group">
                    <label>Floor Barcode</label>
                    <input type="text" name="barcode" class="form-control" required placeholder="Scan ticket barcode" style="font-family:monospace">
                </div>
                <div class="form-group">
                    <label>Reason *</label>
                    <input type="text" name="reason" class="form-control" required placeholder="e.g. Duplicate ticket, wrong grower">
                </div>
                <button type="submit" class="btn btn-danger" style="width:100%">Void Bale</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 3: Mark Unsold -->
<div id="panel-unsold" class="adj-tab-panel">
    <div class="adj-console-container">
        <div class="card">
            <div class="card-head"><h3>Mark as Unsold</h3></div>
            <div class="card-body">
                <form method="POST">
                <input type="hidden" name="action" value="remark_unsold">
                <div class="form-group">
                    <label>Floor Barcode</label>
                    <input type="text" name="barcode" class="form-control" required placeholder="Scan ticket barcode" style="font-family:monospace">
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <input type="text" name="reason" class="form-control" required placeholder="e.g. Buyer withdrew, re-offer">
                </div>
                <button type="submit" class="btn btn-ghost" style="width:100%">Mark Unsold</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 4: Today's Bales Log -->
<div id="panel-log" class="adj-tab-panel">
    <div class="card">
        <div class="card-head"><h3>Today's Bales</h3><span class="badge badge-gray"><?=count($bales)?></span></div>
        <div class="table-wrap" style="max-height:560px;overflow-y:auto">
            <table>
                <thead><tr><th>Floor BC</th><th>TIMB BC</th><th>Grower</th><th class="text-right">Mass</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach($bales as $b):?>
                <tr>
                    <td style="font-family:monospace;font-size:.72rem"><?=htmlspecialchars($b['barcode'])?></td>
                    <td style="font-family:monospace;font-size:.7rem"><?=htmlspecialchars($b['temp_bacode'])?></td>
                    <td><span class="badge badge-gold" style="font-size:.6rem"><?=htmlspecialchars($b['grower_num'])?></span></td>
                    <td class="td-mono text-right"><?=number_format((float)$b['mass'],2)?></td>
                    <td>
                        <span class="badge <?=$b['status']==='Sold'?'badge-green':'badge-gold'?>" style="font-size:.58rem"><?=$b['status']?></span>
                        <?php if($b['bal_status']==='Balanced'):?><span class="badge badge-blue" style="font-size:.58rem;margin-left:2px">Bal</span><?php endif;?>
                    </td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function switchAdjTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.adj-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_adj_tab', tabId);
    }
}

// Restore active tab
document.addEventListener('DOMContentLoaded', () => {
    const activeTab = sessionStorage.getItem('active_adj_tab') || 'adjust';
    switchAdjTab(activeTab);
});
</script>

<?php include "includes/layout_end.php"; ?>
