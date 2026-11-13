<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "audit.php";
require_login();

$page_title  = "Grade Disputes";
$active_page = "grade_disputes";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();
$msg=$err='';

$conn->query("CREATE TABLE IF NOT EXISTS grade_disputes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    selling_pointid INT NOT NULL,
    sold_balesid INT NOT NULL,
    growerid INT NOT NULL,
    original_grade VARCHAR(40) DEFAULT '',
    disputed_grade VARCHAR(40) DEFAULT '',
    original_price DECIMAL(10,4) DEFAULT 0,
    disputed_price DECIMAL(10,4) DEFAULT 0,
    reason TEXT NOT NULL,
    status ENUM('open','under_review','approved','rejected','resolved') DEFAULT 'open',
    raised_by INT NOT NULL,
    reviewed_by INT DEFAULT NULL,
    resolution_notes TEXT DEFAULT '',
    final_grade VARCHAR(40) DEFAULT '',
    final_price DECIMAL(10,4) DEFAULT 0,
    raised_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_sp (selling_pointid), INDEX idx_sb (sold_balesid)
)");

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action==='raise_dispute') {
        $sbid   = (int)($_POST['sold_balesid']??0);
        $reason = $conn->real_escape_string(trim($_POST['reason']??''));
        $disp_grade = $conn->real_escape_string(trim($_POST['disputed_grade']??''));
        $disp_price = (float)($_POST['disputed_price']??0);
        if (!$sbid||!$reason) { $err="Bale and reason required."; }
        else {
            // Get bale info
            $b_q = $conn->query("
                SELECT sb.id, sb.price, sb.timb_gradesid, tg.description grade,
                       g.id gid, g.grower_num, bm.temp_bacode timb_bc
                FROM sold_bales sb
                JOIN tickets tk ON tk.id=sb.ticketsid
                JOIN bale_junus bj ON bj.id=tk.bale_junusid
                JOIN bale_mass bm ON bm.id=bj.bale_massid
                JOIN growers g ON g.id=bj.growerid
                LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
                WHERE sb.id=$sbid AND g.selling_pointid=$spid LIMIT 1
            ");
            $b = ($b_q&&$b_q!==true)?$b_q->fetch_assoc():null;
            if (!$b) { $err="Bale not found."; }
            else {
                $orig_price = (float)$b['price'] > 10 ? (float)$b['price']/100 : (float)$b['price'];
                $conn->query("INSERT INTO grade_disputes (selling_pointid,sold_balesid,growerid,original_grade,disputed_grade,original_price,disputed_price,reason,raised_by,status)
                    VALUES ($spid,$sbid,{$b['gid']},'".($b['grade']??'')."','$disp_grade',$orig_price,$disp_price,'$reason',$userid,'open')");
                audit_log($conn,$spid,$userid,'grade_disputes','raise',$conn->insert_id,null,['sold_balesid'=>$sbid]);
                $msg = "✓ Grade dispute #".$conn->insert_id." raised for bale $sbid.";
            }
        }
    }

    if ($action==='review') {
        $did    = (int)($_POST['dispute_id']??0);
        $status = $conn->real_escape_string($_POST['status']??'under_review');
        $notes  = $conn->real_escape_string(trim($_POST['resolution_notes']??''));
        $fg     = $conn->real_escape_string(trim($_POST['final_grade']??''));
        $fp     = (float)($_POST['final_price']??0);
        $resolved_sql = in_array($status,['approved','rejected','resolved'])?"NOW()":'NULL';
        $conn->query("UPDATE grade_disputes SET status='$status',reviewed_by=$userid,
            resolution_notes='$notes',final_grade='$fg',final_price=$fp,resolved_at=$resolved_sql
            WHERE id=$did AND selling_pointid=$spid");
        // If approved — update the sold_bale price/grade
        if ($status==='approved' && $fp > 0) {
            $d_q = $conn->query("SELECT sold_balesid FROM grade_disputes WHERE id=$did LIMIT 1");
            $d   = ($d_q&&$d_q!==true)?$d_q->fetch_assoc():null;
            if ($d) {
                $conn->query("UPDATE sold_bales SET price=".($fp)." WHERE id={$d['sold_balesid']}");
            }
        }
        audit_log($conn,$spid,$userid,'grade_disputes','review',$did,['status'=>'open'],['status'=>$status]);
        $msg = "✓ Dispute #$did updated to ".strtoupper($status).".";
    }
}

$f_status = $conn->real_escape_string($_GET['status'] ?? '');
$where = "d.selling_pointid=$spid";
if ($f_status) $where .= " AND d.status='$f_status'";

$disputes_q = $conn->query("
    SELECT d.*, g.grower_num, g.name, g.surname,
           u1.username raised_by_name, u2.username reviewed_by_name,
           bm.temp_bacode timb_bc, bm.mass
    FROM grade_disputes d
    JOIN growers g ON g.id=d.growerid
    JOIN sold_bales sb ON sb.id=d.sold_balesid
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    LEFT JOIN users u1 ON u1.id=d.raised_by
    LEFT JOIN users u2 ON u2.id=d.reviewed_by
    WHERE $where
    ORDER BY d.raised_at DESC LIMIT 100
");
$disputes = ($disputes_q&&$disputes_q!==true)?$disputes_q->fetch_all(MYSQLI_ASSOC):[];

// Grades for form
$grades_q = $conn->query("SELECT id,description FROM timb_grades ORDER BY description");
$grades   = ($grades_q&&$grades_q!==true)?$grades_q->fetch_all(MYSQLI_ASSOC):[];

$STATUS_COLORS = [
    'open'=>'badge-gold','under_review'=>'badge-blue',
    'approved'=>'badge-green','rejected'=>'badge-red','resolved'=>'badge-gray'
];
$open_count = count(array_filter($disputes,fn($d)=>in_array($d['status'],['open','under_review'])));

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

.dispute-tab-panel {
    display: none;
}
.dispute-tab-panel.active {
    display: block;
    animation: disputeFadeIn 0.25s ease-in-out;
}

@keyframes disputeFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.dispute-form-container {
    max-width: 680px;
    margin: 0 auto;
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Grade Disputes</h2>
    <p>Raise, review and resolve grade and price disputes</p>
  </div>
  <?php if($open_count):?>
  <span class="badge badge-gold" style="font-size:.78rem">⚠ <?=$open_count?> open dispute<?=$open_count>1?'s':''?></span>
  <?php endif;?>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="list" onclick="switchDisputeTab('list')">
        📋 Disputes List (<?=count($disputes)?>)
    </button>
    <button class="premium-tab-btn" data-tab="raise" onclick="switchDisputeTab('raise')">
        ➕ Raise Dispute
    </button>
</div>

<!-- Tab Panel 1: Disputes List -->
<div id="panel-list" class="dispute-tab-panel active">
    <!-- Filter Card -->
    <div class="card mb-3" style="padding:.6rem 1rem">
        <form method="GET" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
          <label style="font-size:.75rem;color:var(--muted);margin-right:.5rem">Filter Status:</label>
          <select name="status" class="form-control" style="font-size:.8rem;padding:.35rem .55rem;width:auto;display:inline-block">
            <option value="">All statuses</option>
            <?php foreach(['open','under_review','approved','rejected','resolved'] as $s):?>
            <option value="<?=$s?>" <?=$f_status===$s?'selected':''?>><?=ucwords(str_replace('_',' ',$s))?></option>
            <?php endforeach;?>
          </select>
          <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
          <a href="grade_disputes.php" class="btn btn-ghost btn-sm">All</a>
        </form>
    </div>

    <!-- Disputes List Card -->
    <div class="card">
        <div class="card-head"><h3>Disputes</h3><span class="badge badge-gray"><?=count($disputes)?></span></div>
        <div style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
        <?php if(empty($disputes)):?>
        <div class="card-body text-center text-muted" style="padding:2.5rem">No disputes found.</div>
        <?php else: foreach($disputes as $d): ?>
        <div style="padding:.85rem 1.15rem;border-bottom:1px solid var(--border)">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;flex-wrap:wrap;gap:.35rem">
            <div style="display:flex;align-items:center;gap:.5rem">
              <span style="font-size:.72rem;color:var(--muted)">#<?=$d['id']?></span>
              <span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($d['grower_num'])?></span>
              <span style="font-size:.78rem;font-weight:500"><?=htmlspecialchars(trim($d['name'].' '.$d['surname']))?></span>
              <span class="badge badge-gray" style="font-size:.72rem;font-family:monospace"><?=htmlspecialchars($d['timb_bc'])?></span>
            </div>
            <span class="badge <?=$STATUS_COLORS[$d['status']]?>" style="font-size:.72rem"><?=strtoupper(str_replace('_',' ',$d['status']))?></span>
          </div>
          <div style="font-size:.75rem;color:var(--muted);margin-bottom:.5rem;display:flex;gap:1rem;flex-wrap:wrap">
            <span>Original: <strong><?=htmlspecialchars($d['original_grade'])?></strong> @ $<?=number_format((float)$d['original_price'],4)?>/kg</span>
            <?php if($d['disputed_grade']):?><span>→ Disputed: <strong style="color:var(--gold)"><?=htmlspecialchars($d['disputed_grade'])?></strong><?php if($d['disputed_price']>0): ?> @ $<?=number_format((float)$d['disputed_price'],4)?>/kg<?php endif;?></span><?php endif;?>
            <span>Mass: <?=number_format((float)$d['mass'],1)?> kg</span>
          </div>
          <div style="font-size:.78rem;background:var(--cream-d);padding:.5rem .75rem;border-radius:4px;margin-bottom:.5rem;border-left:2px solid var(--border)">
            <?=htmlspecialchars($d['reason'])?>
          </div>
          <?php if($d['resolution_notes']&&$d['status']!=='open'):?>
          <div style="font-size:.72rem;color:var(--muted);margin-bottom:.5rem">
            Resolution: <?=htmlspecialchars($d['resolution_notes'])?>
            <?php if($d['final_grade']):?> · Final grade: <strong><?=htmlspecialchars($d['final_grade'])?></strong><?php endif;?>
          </div>
          <?php endif;?>
          <?php if(in_array($d['status'],['open','under_review'])): ?>
          <details style="margin-top:.5rem">
            <summary class="btn btn-ghost btn-sm" style="cursor:pointer;display:inline-block;font-size:.72rem">Review / Resolve</summary>
            <form method="POST" style="margin-top:.75rem;background:var(--surface);padding:.75rem;border-radius:6px;border:1px solid var(--border)">
              <input type="hidden" name="action" value="review">
              <input type="hidden" name="dispute_id" value="<?=$d['id']?>">
              <div class="form-row cols-2" style="margin-bottom:.5rem">
                <div class="form-group" style="margin:0">
                  <label style="font-size:.65rem">Decision</label>
                  <select name="status" class="form-control" style="font-size:.8rem">
                    <option value="under_review">Under Review</option>
                    <option value="approved">Approved — Update Price/Grade</option>
                    <option value="rejected">Rejected</option>
                    <option value="resolved">Resolved (no change)</option>
                  </select>
                </div>
                <div class="form-group" style="margin:0">
                  <label style="font-size:.65rem">Final Grade (if changed)</label>
                  <select name="final_grade" class="form-control" style="font-size:.8rem">
                    <option value="">No change</option>
                    <?php foreach($grades as $g):?>
                    <option value="<?=htmlspecialchars($g['description'])?>"><?=htmlspecialchars($g['description'])?></option>
                    <?php endforeach;?>
                  </select>
                </div>
              </div>
              <div class="form-row cols-2" style="margin-bottom:.5rem">
                <div class="form-group" style="margin:0">
                  <label style="font-size:.65rem">Final Price $/kg (0 = no change)</label>
                  <input type="number" name="final_price" class="form-control" style="font-size:.8rem" step="0.0001" value="0" min="0">
                </div>
                <div></div>
              </div>
              <div class="form-group" style="margin-bottom:.5rem">
                <label style="font-size:.65rem">Resolution Notes</label>
                <input type="text" name="resolution_notes" class="form-control" style="font-size:.8rem" placeholder="Notes on the decision">
              </div>
              <button type="submit" class="btn btn-primary btn-sm" style="font-size:.72rem">Submit Decision</button>
            </form>
          </details>
          <?php endif;?>
          <div style="font-size:.65rem;color:var(--muted);margin-top:.4rem">
            Raised by <?=htmlspecialchars($d['raised_by_name']??'—')?> · <?=date('d M Y H:i',strtotime($d['raised_at']))?>
            <?php if($d['reviewed_by_name']):?> · Reviewed by <?=htmlspecialchars($d['reviewed_by_name'])?><?php endif;?>
          </div>
        </div>
        <?php endforeach;endif;?>
        </div>
    </div>
</div>

<!-- Tab Panel 2: Raise Dispute Form -->
<div id="panel-raise" class="dispute-tab-panel">
    <div class="dispute-form-container">
        <div class="card mb-3">
            <div class="card-head"><h3>Raise New Dispute</h3></div>
            <div class="card-body">
                <form method="POST">
                <input type="hidden" name="action" value="raise_dispute">
                <div class="form-group">
                  <label>Sale Bale ID</label>
                  <input type="number" name="sold_balesid" class="form-control" placeholder="Enter sold bale ID" required min="1" id="disp-bale-id">
                  <div style="font-size:.68rem;color:var(--muted);margin-top:3px">Find the ID from EOD reports or TIMB reports</div>
                </div>
                <div class="form-row cols-2">
                  <div class="form-group">
                    <label>Disputed Grade</label>
                    <select name="disputed_grade" class="form-control">
                      <option value="">Select correct grade</option>
                      <?php foreach($grades as $g):?>
                      <option value="<?=htmlspecialchars($g['description'])?>"><?=htmlspecialchars($g['description'])?></option>
                      <?php endforeach;?>
                    </select>
                  </div>
                  <div class="form-group">
                    <label>Disputed Price $/kg</label>
                    <input type="number" name="disputed_price" class="form-control" step="0.0001" min="0" placeholder="0.0000">
                  </div>
                </div>
                <div class="form-group">
                  <label>Reason for Dispute *</label>
                  <textarea name="reason" class="form-control" rows="3" placeholder="Explain why the grade/price is disputed..." required style="resize:vertical"></textarea>
                </div>
                <button type="submit" class="btn btn-danger" style="width:100%">Raise Dispute</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function switchDisputeTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.dispute-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_dispute_tab', tabId);
        
        if (tabId === 'raise') {
            setTimeout(() => {
                const el = document.getElementById('disp-bale-id');
                if (el) el.focus();
            }, 50);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    <?php if ($err && isset($_POST['action']) && $_POST['action'] === 'raise_dispute'): ?>
    const defaultTab = 'raise';
    <?php else: ?>
    const defaultTab = sessionStorage.getItem('active_dispute_tab') || 'list';
    <?php endif; ?>
    
    switchDisputeTab(defaultTab);
});
</script>

<?php include "includes/layout_end.php"; ?>
