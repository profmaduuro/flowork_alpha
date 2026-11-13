<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "Void & Reversal Controls";
$active_page = "void_controls";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();
$msg=$err='';

// Create void log table
$conn->query("CREATE TABLE IF NOT EXISTS void_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    selling_pointid INT NOT NULL,
    void_type ENUM('ticket','sold_bale','balance','dispatch_item','payment') NOT NULL,
    record_id INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    authorised_by INT NOT NULL,
    reversed_by INT NOT NULL,
    original_data JSON DEFAULT NULL,
    status ENUM('pending','approved','rejected','completed') DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actioned_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_sp (selling_pointid), INDEX idx_type (void_type)
)");

// Handle POST actions
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    // Request void of a ticket
    if ($action==='void_ticket') {
        $tkid   = (int)($_POST['ticket_id'] ?? 0);
        $reason = $conn->real_escape_string(trim($_POST['reason'] ?? ''));
        if (!$tkid || !$reason) { $err = "Ticket ID and reason are required."; }
        else {
            // Verify ticket belongs to this floor
            $tk = $conn->query("
                SELECT tk.*, bm.mass, bm.temp_bacode AS timb_bc, g.grower_num, g.name, g.surname,
                       sb.id AS sold_id, sb.price
                FROM tickets tk
                JOIN bale_junus bj ON bj.id=tk.bale_junusid
                JOIN bale_mass bm ON bm.id=bj.bale_massid
                JOIN growers g ON g.id=bj.growerid
                LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
                WHERE tk.id=$tkid AND g.selling_pointid=$spid LIMIT 1
            ")->fetch_assoc();
            if (!$tk) { $err = "Ticket not found or not authorised."; }
            else {
                $orig = json_encode($tk);
                $orig_esc = $conn->real_escape_string($orig);
                $conn->query("INSERT INTO void_log (selling_pointid,void_type,record_id,reason,authorised_by,reversed_by,original_data,status)
                    VALUES ($spid,'ticket',$tkid,'$reason',$userid,$userid,'$orig_esc','pending')");
                $vlog_id = $conn->insert_id;
                audit_log($conn,$spid,$userid,'void_log','request',$vlog_id,null,['ticket_id'=>$tkid,'reason'=>$reason]);
                $msg = "✓ Void request #$vlog_id submitted for Ticket #$tkid. Awaiting approval.";
            }
        }
    }

    // Approve a void request
    if ($action==='approve_void') {
        $vid = (int)($_POST['void_id'] ?? 0);
        $vr  = $conn->query("SELECT * FROM void_log WHERE id=$vid AND selling_pointid=$spid LIMIT 1")->fetch_assoc();
        if (!$vr) { $err = "Void request not found."; }
        elseif ($vr['status']!=='pending') { $err = "This request is already ".$vr['status']."."; }
        else {
            $now = date('Y-m-d H:i:s');
            if ($vr['void_type']==='ticket') {
                $tkid = (int)$vr['record_id'];
                // Remove sold_bale first if exists
                $sb = $conn->query("SELECT id FROM sold_bales WHERE ticketsid=$tkid LIMIT 1")->fetch_assoc();
                if ($sb) {
                    $sbid = (int)$sb['id'];
                    audit_log($conn,$spid,$userid,'sold_bales','void',$sbid,['ticketsid'=>$tkid],null,$vr['reason']);
                    $conn->query("DELETE FROM sold_bales WHERE id=$sbid");
                    $conn->query("DELETE FROM dispatch_items WHERE sold_balesid=$sbid");
                }
                audit_log($conn,$spid,$userid,'tickets','void',$tkid,['id'=>$tkid],null,$vr['reason']);
                $conn->query("DELETE FROM tickets WHERE id=$tkid");
            }
            if ($vr['void_type']==='sold_bale') {
                $sbid = (int)$vr['record_id'];
                audit_log($conn,$spid,$userid,'sold_bales','void',$sbid,null,null,$vr['reason']);
                $conn->query("DELETE FROM sold_bales WHERE id=$sbid");
                $conn->query("DELETE FROM dispatch_items WHERE sold_balesid=$sbid");
            }
            $conn->query("UPDATE void_log SET status='completed',authorised_by=$userid,actioned_at='$now' WHERE id=$vid");
            audit_log($conn,$spid,$userid,'void_log','approve',$vid,['status'=>'pending'],['status'=>'completed']);
            $msg = "✓ Void #$vid approved and executed.";
        }
    }

    // Reject a void request
    if ($action==='reject_void') {
        $vid = (int)($_POST['void_id'] ?? 0);
        $conn->query("UPDATE void_log SET status='rejected',authorised_by=$userid,actioned_at=NOW() WHERE id=$vid AND selling_pointid=$spid");
        audit_log($conn,$spid,$userid,'void_log','reject',$vid);
        $msg = "Void request #$vid rejected.";
    }
}

// Load data
$today = date('Y-m-d');
$f_date = $conn->real_escape_string($_GET['date'] ?? $today);

// Pending void requests
$pending = $conn->query("
    SELECT vl.*, u.username reversed_by_name
    FROM void_log vl LEFT JOIN users u ON u.id=vl.reversed_by
    WHERE vl.selling_pointid=$spid AND vl.status='pending'
    ORDER BY vl.requested_at DESC
");
$pending_rows = ($pending&&$pending!==true)?$pending->fetch_all(MYSQLI_ASSOC):[];

// Recent void history
$history = $conn->query("
    SELECT vl.*, u.username auth_name
    FROM void_log vl LEFT JOIN users u ON u.id=vl.authorised_by
    WHERE vl.selling_pointid=$spid AND vl.status IN ('completed','rejected')
      AND DATE(vl.actioned_at)='$f_date'
    ORDER BY vl.actioned_at DESC LIMIT 50
");
$history_rows = ($history&&$history!==true)?$history->fetch_all(MYSQLI_ASSOC):[];

// Recent tickets for lookup
$recent_tickets = $conn->query("
    SELECT tk.id, tk.barcode, tk.created_at,
           g.grower_num, g.name, g.surname, bm.mass,
           sb.id AS sold_id, sb.price
    FROM tickets tk
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
    WHERE DATE(tk.created_at)='$f_date' AND g.selling_pointid=$spid
    ORDER BY tk.id DESC LIMIT 50
");
$tickets = ($recent_tickets&&$recent_tickets!==true)?$recent_tickets->fetch_all(MYSQLI_ASSOC):[];

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

.void-tab-panel {
    display: none;
}
.void-tab-panel.active {
    display: block;
    animation: voidFadeIn 0.25s ease-in-out;
}

@keyframes voidFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.void-form-container {
    max-width: 680px;
    margin: 0 auto;
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Void & Reversal Controls</h2>
    <p>Request, approve and log ticket and sale reversals</p>
  </div>
  <form method="GET" style="display:flex;gap:.5rem;align-items:flex-end">
    <input type="date" name="date" class="form-control" value="<?=htmlspecialchars((string)$f_date)?>" style="font-size:.8rem;padding:.35rem .6rem">
    <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
  </form>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="approvals" onclick="switchVoidTab('approvals')">
        ⏳ Void Approvals (<?=count($pending_rows)?>)
    </button>
    <button class="premium-tab-btn" data-tab="tickets" onclick="switchVoidTab('tickets')">
        🎫 Recent Tickets (<?=count($tickets)?>)
    </button>
    <button class="premium-tab-btn" data-tab="history" onclick="switchVoidTab('history')">
        📜 Audit Log
    </button>
</div>

<!-- Tab Panel 1: Void Approvals & Forms -->
<div id="panel-approvals" class="void-tab-panel active">
    <div class="void-form-container">
        <!-- Pending approval cards -->
        <?php if(!empty($pending_rows)):?>
        <div class="card mb-3" style="border-top:3px solid var(--gold)">
            <div class="card-head">
              <h3>⏳ Pending Approval</h3>
              <span class="badge badge-gold"><?=count($pending_rows)?></span>
            </div>
            <div class="table-wrap"><table>
            <thead><tr><th>#</th><th>Type</th><th>Record</th><th>Reason</th><th>Requested by</th><th></th></tr></thead>
            <tbody>
            <?php foreach($pending_rows as $vr):?>
            <tr>
              <td class="td-mono" style="font-size:.72rem">#<?=$vr['id']?></td>
              <td><span class="badge badge-gray" style="font-size:.72rem"><?=strtoupper($vr['void_type'])?></span></td>
              <td class="td-mono" style="font-size:.75rem"><?=$vr['record_id']?></td>
              <td style="font-size:.75rem;max-width:180px"><?=htmlspecialchars($vr['reason'])?></td>
              <td style="font-size:.72rem"><?=htmlspecialchars($vr['reversed_by_name']??'—')?></td>
              <td style="white-space:nowrap">
                <form method="POST" style="display:inline" onsubmit="return confirm('APPROVE this void? This permanently deletes the record.')">
                  <input type="hidden" name="action" value="approve_void">
                  <input type="hidden" name="void_id" value="<?=$vr['id']?>">
                  <button class="btn btn-danger btn-sm" style="font-size:.65rem">✓ Approve</button>
                </form>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="reject_void">
                  <input type="hidden" name="void_id" value="<?=$vr['id']?>">
                  <button class="btn btn-ghost btn-sm" style="font-size:.65rem">✕ Reject</button>
                </form>
              </td>
            </tr>
            <?php endforeach;?>
            </tbody>
            </table></div>
        </div>
        <?php endif;?>

        <!-- Request ticket void form -->
        <div class="card">
            <div class="card-head"><h3>Request Void / Reversal</h3></div>
            <div class="card-body">
              <form method="POST">
              <input type="hidden" name="action" value="void_ticket">
              <div class="form-group">
                <label>Ticket ID to Void</label>
                <input type="number" name="ticket_id" class="form-control" placeholder="Enter ticket ID" required min="1" id="void-ticket-id">
                <div style="font-size:.68rem;color:var(--muted);margin-top:3px">
                  This will remove the ticket and its sale (if sold). The bale returns to junus state.
                </div>
              </div>
              <div class="form-group">
                <label>Reason for Void *</label>
                <input type="text" name="reason" class="form-control" placeholder="e.g. Wrong grower assigned, Duplicate ticket" required>
              </div>
              <button type="submit" class="btn btn-danger" style="width:100%"
                      onclick="return confirm('Submit void request for this ticket?')">
                Submit Void Request
              </button>
              </form>
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 2: Today's tickets reference -->
<div id="panel-tickets" class="void-tab-panel">
    <div class="card">
        <div class="card-head">
          <h3>Today's Tickets</h3>
          <input type="text" data-search="#tickets-tbl" placeholder="Search..." class="form-control" style="font-size:.72rem;padding:.28rem .55rem;width:140px">
        </div>
        <?php if(empty($tickets)):?>
        <div class="card-body text-center text-muted" style="padding:2rem">No tickets generated today.</div>
        <?php else:?>
        <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
        <table id="tickets-tbl">
        <thead><tr>
          <th>ID</th><th>Barcode</th><th>Grower</th>
          <th class="text-right">Mass</th><th>Status</th><th>Time</th>
        </tr></thead>
        <tbody>
        <?php foreach($tickets as $tk):?>
        <tr style="cursor:pointer" onclick="selectVoidTicket('<?=$tk['id']?>')">
          <td class="td-mono" style="font-size:.72rem"><?=$tk['id']?></td>
          <td style="font-family:monospace;font-size:.72rem"><?=htmlspecialchars($tk['barcode'])?></td>
          <td>
            <span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($tk['grower_num'])?></span>
            <span style="font-size:.72rem;margin-left:3px"><?=htmlspecialchars($tk['name'].' '.$tk['surname'])?></span>
          </td>
          <td class="td-mono text-right"><?=number_format((float)$tk['mass'],1)?></td>
          <td>
            <?php if($tk['sold_id']):?>
              <span class="badge badge-green" style="font-size:.72rem">Sold $<?=number_format((float)$tk['price']>10?(float)$tk['price']/100:(float)$tk['price'],4)?></span>
            <?php else:?>
              <span class="badge badge-gray" style="font-size:.72rem">Unsold</span>
            <?php endif;?>
          </td>
          <td style="font-size:.68rem;color:var(--muted)"><?=date('H:i',strtotime($tk['created_at']))?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
        </table>
        </div>
        <?php endif;?>
    </div>
</div>

<!-- Tab Panel 3: Void History -->
<div id="panel-history" class="void-tab-panel">
    <div class="card">
        <div class="card-head"><h3>Void History</h3></div>
        <?php if(empty($history_rows)):?>
        <div class="card-body text-center text-muted" style="padding:2rem">No voids recorded for <?=date('d M Y', strtotime($f_date))?>.</div>
        <?php else:?>
        <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
        <thead><tr><th>#</th><th>Type</th><th>Record</th><th>Reason</th><th>Status</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach($history_rows as $h):?>
        <tr>
          <td class="td-mono" style="font-size:.7rem">#<?=$h['id']?></td>
          <td><span class="badge badge-gray" style="font-size:.72rem"><?=strtoupper($h['void_type'])?></span></td>
          <td class="td-mono" style="font-size:.7rem"><?=$h['record_id']?></td>
          <td style="font-size:.72rem"><?=htmlspecialchars($h['reason'])?></td>
          <td>
            <span class="badge <?=$h['status']==='completed'?'badge-red':'badge-gray'?>" style="font-size:.72rem">
              <?=strtoupper($h['status'])?>
            </span>
          </td>
          <td style="font-size:.7rem"><?=htmlspecialchars($h['auth_name']??'—')?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
        </table></div>
        <?php endif;?>
    </div>
</div>

<script>
function switchVoidTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.void-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_void_tab', tabId);
        
        if (tabId === 'approvals') {
            setTimeout(() => {
                const el = document.getElementById('void-ticket-id');
                if (el) el.focus();
            }, 50);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // If pending voids exist, go to approvals. Otherwise check session storage.
    <?php if (!empty($pending_rows)): ?>
    const defaultTab = 'approvals';
    <?php else: ?>
    const defaultTab = sessionStorage.getItem('active_void_tab') || 'approvals';
    <?php endif; ?>
    
    switchVoidTab(defaultTab);
});

function selectVoidTicket(tkid) {
    const el = document.getElementById('void-ticket-id');
    if (el) {
        el.value = tkid;
        switchVoidTab('approvals');
        el.focus();
    }
}
</script>

<?php include "includes/layout_end.php"; ?>
