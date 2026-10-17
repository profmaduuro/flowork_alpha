<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Contract Farming";
$active_page = "contract_farming";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);
$userid = current_userid();

$msg = $err = "";

// ── 1. AUTO-CREATE TABLES IF NOT EXISTS ──────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS cf_contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  selling_pointid INT NOT NULL,
  growerid INT NOT NULL,
  season VARCHAR(20) DEFAULT '',
  contract_date DATE NOT NULL,
  expected_bales INT DEFAULT 0,
  expected_mass DECIMAL(10,2) DEFAULT 0,
  input_value_usd DECIMAL(14,2) DEFAULT 0,
  status ENUM('active','completed','defaulted','cancelled') DEFAULT 'active',
  notes TEXT DEFAULT '',
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sp (selling_pointid),
  INDEX idx_grower (growerid)
)");

$conn->query("CREATE TABLE IF NOT EXISTS cf_inputs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  input_type VARCHAR(60) DEFAULT '',
  description VARCHAR(255) DEFAULT '',
  quantity DECIMAL(10,2) DEFAULT 0,
  unit VARCHAR(20) DEFAULT '',
  unit_cost_usd DECIMAL(10,4) DEFAULT 0,
  total_usd DECIMAL(14,2) DEFAULT 0,
  issued_date DATE NOT NULL,
  issued_by INT NOT NULL,
  INDEX idx_contract (contract_id)
)");

// ── 2. AJAX ENDPOINT: LOAD CONTRACT DETAILS ──────────────────────────────
if (isset($_GET['get_contract_details'])) {
    header('Content-Type: application/json');
    $cid = (int)$_GET['get_contract_details'];
    if (!flowork_on_floor($conn, 'contract', $cid, (int)($_SESSION['selling_pointid'] ?? 0))) {
        echo json_encode(['error' => 'Contract not found on this floor']); exit;
    }
    
    // Contract & Grower
    $c_q = $conn->query("
        SELECT c.*, g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
               g.farm_name, g.province, g.district, g.phone
        FROM cf_contracts c
        JOIN growers g ON g.id=c.growerid
        WHERE c.id=$cid AND c.selling_pointid=$spid LIMIT 1
    ");
    $c = ($c_q && $c_q !== true) ? $c_q->fetch_assoc() : null;
    
    if (!$c) {
        echo json_encode(['error' => 'Contract not found']);
        exit;
    }
    
    // Inputs
    $inputs_q = $conn->query("
        SELECT ci.*, u.username as issued_by_name
        FROM cf_inputs ci
        LEFT JOIN users u ON u.id=ci.issued_by
        WHERE ci.contract_id=$cid
        ORDER BY ci.issued_date DESC
    ");
    $inputs = ($inputs_q && $inputs_q !== true) ? $inputs_q->fetch_all(MYSQLI_ASSOC) : [];
    
    // Deliveries from balanced bales
    $del_q = $conn->query("
        SELECT COALESCE(SUM(dt.mass), 0) delivered_mass,
               COALESCE(SUM(dt.usd_value), 0) delivered_usd,
               COALESCE(COUNT(bb.id), 0) deliveries_count
        FROM balanced_bales bb
        JOIN daily_totals dt ON dt.balanced_balesid=bb.id
        WHERE bb.growerid={$c['growerid']}
    ");
    $del = ($del_q && $del_q !== true) ? $del_q->fetch_assoc() : ['delivered_mass'=>0,'delivered_usd'=>0,'deliveries_count'=>0];
    
    // Loan repayments (settlement amount)
    $repay_q = $conn->query("
        SELECT COALESCE(SUM(lr.amount), 0) total_repaid
        FROM grower_loans gl
        JOIN loan_repayments lr ON lr.loan_id=gl.id
        WHERE gl.growerid={$c['growerid']} AND gl.loan_type='input_loan'
    ");
    $repay = ($repay_q && $repay_q !== true) ? $repay_q->fetch_assoc() : ['total_repaid'=>0];
    
    echo json_encode([
        'contract' => $c,
        'inputs' => $inputs,
        'deliveries' => $del,
        'repayments' => $repay
    ]);
    exit;
}

// ── 3. POST ACTION HANDLERS ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_grower_contract') {
        $growerid           = (int)($_POST['growerid'] ?? 0);
        $season             = $conn->real_escape_string($_POST['season'] ?? '');
        $expected_bales     = (int)($_POST['expected_bales'] ?? 0);
        $expected_mass      = (float)($_POST['expected_mass'] ?? 0);
        $initial_inputs_val = (float)($_POST['initial_inputs_val'] ?? 0);
        $notes              = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
        
        if (!$growerid || !$season || !$expected_mass) {
            $err = "❌ Grower, season, and expected mass are required.";
        } else {
            // Check active contract duplicates
            $chk = $conn->query("SELECT id FROM cf_contracts WHERE growerid=$growerid AND season='$season' AND status='active' LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $err = "❌ Grower already has an active contract for the $season season.";
            } else {
                $contract_date = date('Y-m-d');
                $ins = $conn->query("
                    INSERT INTO cf_contracts (selling_pointid, growerid, season, contract_date, expected_bales, expected_mass, input_value_usd, status, notes, created_by)
                    VALUES ($spid, $growerid, '$season', '$contract_date', $expected_bales, $expected_mass, $initial_inputs_val, 'active', '$notes', $userid)
                ");
                $cid = (int)$conn->insert_id;
                if ($cid) {
                    if ($initial_inputs_val > 0) {
                        // Log input item
                        $conn->query("
                            INSERT INTO cf_inputs (contract_id, input_type, description, quantity, unit, unit_cost_usd, total_usd, issued_date, issued_by)
                            VALUES ($cid, 'Seeds/Fertilizer/Other', 'Initial Contract Inputs Package', 1, 'package', $initial_inputs_val, $initial_inputs_val, '$contract_date', $userid)
                        ");
                        
                        // Link to grower loans
                        $conn->query("
                            INSERT INTO grower_loans (selling_pointid, growerid, loan_type, amount, currency, interest_rate, issued_date, description, status, total_repaid, issued_by)
                            VALUES ($spid, $growerid, 'input_loan', $initial_inputs_val, 'usd', 0, '$contract_date', 'Contract Inputs ($season)', 'active', 0, $userid)
                        ");
                    }
                    $msg = "✓ Successfully created grower contract and registered loans.";
                } else {
                    $err = "❌ Failed to save contract: " . $conn->error;
                }
            }
        }
    }
    
    if ($action === 'add_contract_input') {
        $cid       = (int)($_POST['contract_id'] ?? 0);
        $type      = $conn->real_escape_string($_POST['input_type'] ?? 'Other');
        $desc      = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $qty       = (float)($_POST['quantity'] ?? 0);
        $unit      = $conn->real_escape_string($_POST['unit'] ?? 'pcs');
        $cost      = (float)($_POST['unit_cost_usd'] ?? 0);
        $total     = $qty * $cost;
        
        if (!$cid || !$qty || !$cost) {
            $err = "❌ Invalid input parameters.";
        } elseif (!flowork_on_floor($conn, 'contract', $cid, $spid)) {
            $err = "❌ Contract not found on this floor.";
        } else {
            $issued_date = date('Y-m-d');
            $ins = $conn->query("
                INSERT INTO cf_inputs (contract_id, input_type, description, quantity, unit, unit_cost_usd, total_usd, issued_date, issued_by)
                VALUES ($cid, '$type', '$desc', $qty, '$unit', $cost, $total, '$issued_date', $userid)
            ");
            if ($ins) {
                // Update contract total inputs
                $conn->query("UPDATE cf_contracts SET input_value_usd = input_value_usd + $total WHERE id=$cid");
                
                // Update loan balance
                $c_q = $conn->query("SELECT growerid, season FROM cf_contracts WHERE id=$cid LIMIT 1");
                if ($c_q && $c_q !== true) {
                    $c = $c_q->fetch_assoc();
                    $growerid = (int)$c['growerid'];
                    $season = $c['season'];
                    
                    $loan_check = $conn->query("
                        SELECT id FROM grower_loans 
                        WHERE growerid=$growerid AND loan_type='input_loan' 
                          AND description LIKE '%Contract Inputs ($season)%' 
                          AND status IN ('active','partially_paid') LIMIT 1
                    ");
                    if ($loan_check && $loan_check->num_rows > 0) {
                        $loan_id = (int)$loan_check->fetch_assoc()['id'];
                        $conn->query("UPDATE grower_loans SET amount = amount + $total WHERE id=$loan_id");
                    } else {
                        $conn->query("
                            INSERT INTO grower_loans (selling_pointid, growerid, loan_type, amount, currency, interest_rate, issued_date, description, status, total_repaid, issued_by)
                            VALUES ($spid, $growerid, 'input_loan', $total, 'usd', 0, '$issued_date', 'Contract Inputs ($season)', 'active', 0, $userid)
                        ");
                    }
                }
                $msg = "✓ Input item issued and grower loan updated.";
            } else {
                $err = "❌ Failed to issue input item.";
            }
        }
    }
    
    if ($action === 'update_status') {
        $cid    = (int)($_POST['contract_id'] ?? 0);
        $status = $conn->real_escape_string($_POST['status'] ?? 'active');
        if ($cid && !flowork_on_floor($conn, 'contract', $cid, $spid)) {
            $err = "❌ Contract not found on this floor.";
        } elseif ($cid) {
            $conn->query("UPDATE cf_contracts SET status='$status' WHERE id=$cid");
            $msg = "✓ Contract status updated to " . strtoupper($status);
        }
    }
}

// ── 4. LOAD ORIGINAL TABS DATA ───────────────────────────────────────────
function cfSafe($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_assoc()?:[];}
function cfAll($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_all(MYSQLI_ASSOC)?:[];}
$sf = $spid ? "AND bc.selling_pointid=$spid" : "";

// Buyer contracts overview
$contracts = cfAll($conn,"SELECT bc.*, b.name buyer_name, b.buyer_code,
    COALESCE((SELECT SUM(bm.mass) FROM sold_bales sb JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN bale_mass bm ON bm.id=bj.bale_massid WHERE bg.buyerid=bc.buyerid),0) actual_mass,
    COALESCE((SELECT COUNT(sb.id) FROM sold_bales sb JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid WHERE bg.buyerid=bc.buyerid),0) actual_bales
    FROM buyer_contracts bc
    JOIN buyer b ON b.id=bc.buyerid
    WHERE 1=1 $sf
    ORDER BY bc.status ASC, bc.season_year DESC");

// Grower season registrations
$grower_contracts = cfAll($conn,"SELECT sg.*, g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
    g.farm_name, g.province,
    COALESCE((SELECT SUM(dt.mass) FROM balanced_bales bb JOIN transporter_growers tg ON tg.id=bb.transporter_growersid JOIN daily_totals dt ON dt.balanced_balesid=bb.id WHERE tg.growerid=sg.growerid),0) delivered_kg
    FROM season_growers sg
    JOIN growers g ON g.id=sg.growerid
    WHERE 1=1 ".($spid?"AND sg.selling_pointid=$spid":"")."
    ORDER BY sg.status ASC, g.grower_num ASC
    LIMIT 50");

// KPIs original
$bc_stats = cfSafe($conn,"SELECT COUNT(*) total,
    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) active_cnt,
    COALESCE(SUM(target_mass_kg),0) target_kg,
    COALESCE(SUM(credit_limit_usd),0) total_credit
    FROM buyer_contracts WHERE 1=1 $sf");
$sg_stats = cfSafe($conn,"SELECT COUNT(*) total,
    COALESCE(SUM(expected_kg),0) expected_kg
    FROM season_growers WHERE 1=1 ".($spid?"AND selling_pointid=$spid":""));

// ── 5. LOAD NEW GROWER CONTRACTS DATA ────────────────────────────────────
$grower_contracts_new = cfAll($conn, "
    SELECT c.*, g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
           g.farm_name, g.province,
           COALESCE((
               SELECT SUM(dt.mass) 
               FROM balanced_bales bb 
               JOIN daily_totals dt ON dt.balanced_balesid=bb.id 
               WHERE bb.growerid=c.growerid
           ), 0) delivered_mass,
           COALESCE((
               SELECT SUM(lr.amount)
               FROM grower_loans gl
               JOIN loan_repayments lr ON lr.loan_id=gl.id
               WHERE gl.growerid=c.growerid AND gl.loan_type='input_loan'
           ), 0) total_repaid
    FROM cf_contracts c
    JOIN growers g ON g.id=c.growerid
    WHERE c.selling_pointid=$spid
    ORDER BY c.contract_date DESC
");

// KPIs for New Grower Contracts tab
$total_contracts_new = count($grower_contracts_new);
$total_inputs_new    = array_sum(array_column($grower_contracts_new, 'input_value_usd'));
$total_delivered_new = array_sum(array_column($grower_contracts_new, 'delivered_mass'));
$total_repaid_new    = array_sum(array_column($grower_contracts_new, 'total_repaid'));
$settlement_rate     = $total_inputs_new > 0 ? ($total_repaid_new / $total_inputs_new) * 100 : 0;

$all_growers = cfAll($conn, "SELECT id, grower_num, name, surname FROM growers WHERE selling_pointid=$spid AND active=1 ORDER BY grower_num ASC");

include "includes/layout.php";
?>

<style>
.cf-contract-card { background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:1.1rem;position:relative;overflow:hidden;transition:all .2s; }
body.theme-dark .cf-contract-card { background:var(--ink-soft); }
.cf-contract-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.06); }
.cf-contract-card::before { content:'';position:absolute;top:0;left:0;width:100%;height:3px;background:var(--gold); }
.cf-progress { height:8px;background:var(--cream-d);border-radius:4px;overflow:hidden;margin-top:.5rem; }
.cf-progress-fill { height:100%;border-radius:4px;transition:width .6s ease; }
.cf-status { display:inline-flex;padding:2px 8px;border-radius:999px;font-size:.6rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase; }
.cf-s-active { background:rgba(61,107,53,.12);color:#3d6b35; }
.cf-s-suspended { background:rgba(200,149,42,.12);color:#92650e; }
.cf-s-expired { background:var(--cream-d);color:var(--muted); }
.cf-s-completed { background:rgba(26,107,154,.1);color:#1a6b9a; }
.cf-s-withdrawn { background:rgba(192,57,43,.1);color:#c0392b; }

/* Custom Styling for the new tabs and elements */
.tabs-nav { display:flex;gap:.5rem;margin-bottom:1.5rem;border-bottom:1px solid var(--border);padding-bottom:.5rem }
.tab-btn.active { color:var(--gold);border-bottom:2px solid var(--gold); }
.tab-content { display:none; }
.lifecycle-step { width:32px;height:32px;border-radius:50%;background:var(--border);color:var(--muted);display:flex;align-items:center;justify-content:center;font-weight:600;margin:0 auto .4rem; }
.lifecycle-step.active { background:var(--gold);color:#fff; }
.lifecycle-step.completed { background:var(--leaf);color:#fff; }
body.theme-dark .lifecycle-step.completed { color:#14110d; }

/* Modal overlays */
.modal-overlay { display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.7);z-index:999;backdrop-filter:blur(4px); }
.modal-wrapper { position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:90%;max-width:900px;background:#fff;border-radius:var(--radius);overflow:hidden;box-shadow:0 12px 32px rgba(0,0,0,.3);border:1px solid var(--border); }
body.theme-dark .modal-wrapper { background:var(--ink-soft); }
.modal-header { padding:1.2rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center; }
.modal-body { padding:1.5rem;max-height:80vh;overflow-y:auto; }
.modal-close-btn { font-size:1.5rem;color:var(--muted);background:none;border:none;cursor:pointer; }
.modal-close-btn:hover { color:var(--danger); }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Contract Farming</h2>
    <p>Buyer contracts, grower season registrations, fulfillment tracking</p>
  </div>
</div>

<!-- Tabs Bar Navigation -->
<div class="tabs-nav">
  <button class="btn btn-ghost tab-btn active" onclick="switchTab('buyer-contracts', this)">📋 Buyer Contracts</button>
  <button class="btn btn-ghost tab-btn" onclick="switchTab('grower-registrations', this)">🌿 Grower Registrations</button>
  <button class="btn btn-ghost tab-btn" onclick="switchTab('grower-contracts-new', this)">📜 Grower Contracts (New)</button>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- ── TAB 1: BUYER CONTRACTS ────────────────────────────────────────────── -->
<div id="tab-buyer-contracts" class="tab-content" style="display:block">
  <div class="kpi-grid" style="margin-bottom:1.5rem">
    <div class="kpi-card" style="--kpi-color:var(--gold)"><div class="kpi-label">Buyer Contracts</div><div class="kpi-value"><?=(int)($bc_stats['total']??0)?></div><div class="kpi-sub"><?=(int)($bc_stats['active_cnt']??0)?> active</div></div>
    <div class="kpi-card" style="--kpi-color:var(--leaf)"><div class="kpi-label">Target Mass</div><div class="kpi-value sm"><?=number_format((float)($bc_stats['target_kg']??0),0)?></div><div class="kpi-sub">kg contracted</div></div>
    <div class="kpi-card" style="--kpi-color:#e8b84b"><div class="kpi-label">Credit Allocated</div><div class="kpi-value sm">$<?=number_format((float)($bc_stats['total_credit']??0),0)?></div></div>
    <div class="kpi-card" style="--kpi-color:var(--gold)"><div class="kpi-label">Season Growers</div><div class="kpi-value"><?=(int)($sg_stats['total']??0)?></div><div class="kpi-sub"><?=number_format((float)($sg_stats['expected_kg']??0),0)?> kg expected</div></div>
  </div>

  <div class="card mb-3">
    <div class="card-head"><h3>📋 Buyer Contracts Overview</h3><span class="badge badge-gold"><?=count($contracts)?></span></div>
    <div class="table-wrap" style="max-height:400px;overflow-y:auto">
      <table>
        <thead><tr><th>Buyer</th><th>Contract #</th><th>Season</th><th class="text-right">Target Bales</th><th class="text-right">Target kg</th><th class="text-right">Actual kg</th><th>Fulfillment</th><th>Credit</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($contracts as $c):
          $pct = (float)$c['target_mass_kg'] > 0 ? ((float)$c['actual_mass'] / (float)$c['target_mass_kg']) * 100 : 0;
          $pct = min(100, $pct);
          $sclass = 'cf-s-'.($c['status']??'active');
          $bar_color = $pct >= 80 ? 'var(--leaf)' : ($pct >= 40 ? 'var(--gold)' : 'var(--danger)');
        ?>
        <tr>
          <td><div style="font-weight:500"><?=htmlspecialchars($c['buyer_name'])?></div><div style="font-size:.6rem;color:var(--muted)"><?=htmlspecialchars($c['buyer_code']??'')?></div></td>
          <td class="td-mono" style="font-size:.72rem"><?=htmlspecialchars($c['contract_number']??'—')?></td>
          <td class="td-mono"><?=(int)$c['season_year']?></td>
          <td class="td-mono text-right"><?=number_format((int)$c['target_bales'])?></td>
          <td class="td-mono text-right"><?=number_format((float)$c['target_mass_kg'],0)?></td>
          <td class="td-mono text-right" style="font-weight:500"><?=number_format((float)$c['actual_mass'],0)?></td>
          <td style="min-width:100px">
            <div style="display:flex;align-items:center;gap:6px">
              <div class="cf-progress" style="flex:1"><div class="cf-progress-fill" style="width:<?=$pct?>%;background:<?=$bar_color?>"></div></div>
              <span style="font-size:.62rem;font-weight:500;min-width:32px;text-align:right"><?=round($pct)?>%</span>
            </div>
          </td>
          <td class="td-mono text-right">$<?=number_format((float)$c['credit_limit_usd'],0)?></td>
          <td><span class="cf-status <?=$sclass?>"><?=htmlspecialchars($c['status']??'active')?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($contracts)): ?>
        <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--muted)">No buyer contracts configured. Use Buyer Management to set up contracts.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── TAB 2: GROWER REGISTRATIONS ────────────────────────────────────────── -->
<div id="tab-grower-registrations" class="tab-content">
  <div class="kpi-grid" style="margin-bottom:1.5rem">
    <div class="kpi-card" style="--kpi-color:var(--gold)"><div class="kpi-label">Registered Growers</div><div class="kpi-value"><?=(int)($sg_stats['total']??0)?></div></div>
    <div class="kpi-card" style="--kpi-color:var(--leaf)"><div class="kpi-label">Total Expected crop</div><div class="kpi-value sm"><?=number_format((float)($sg_stats['expected_kg']??0),0)?> kg</div></div>
  </div>

  <div class="card">
    <div class="card-head"><h3>🌿 Grower Season Registrations</h3><span class="badge badge-gold"><?=count($grower_contracts)?></span></div>
    <div class="table-wrap" style="max-height:400px;overflow-y:auto">
      <table>
        <thead><tr><th>Grower</th><th>Farm</th><th>Crop</th><th class="text-right">Expected kg</th><th class="text-right">Delivered kg</th><th>Progress</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($grower_contracts as $gc):
          $gpct = (float)$gc['expected_kg'] > 0 ? ((float)$gc['delivered_kg'] / (float)$gc['expected_kg']) * 100 : 0;
          $gpct = min(100, $gpct);
          $gsclass = 'cf-s-'.($gc['status']??'active');
        ?>
        <tr>
          <td><div style="font-weight:500"><?=htmlspecialchars($gc['grower_name'])?></div><div style="font-size:.6rem;color:var(--muted)"><?=htmlspecialchars($gc['grower_num'])?></div></td>
          <td style="font-size:.75rem;color:var(--muted)"><?=htmlspecialchars($gc['farm_name']??'')?></td>
          <td style="font-size:.75rem"><?=htmlspecialchars($gc['crop_type']??'Virginia')?></td>
          <td class="td-mono text-right"><?=number_format((float)$gc['expected_kg'],0)?></td>
          <td class="td-mono text-right" style="font-weight:500"><?=number_format((float)$gc['delivered_kg'],0)?></td>
          <td style="min-width:90px">
            <div style="display:flex;align-items:center;gap:6px">
              <div class="cf-progress" style="flex:1"><div class="cf-progress-fill" style="width:<?=$gpct?>%;background:<?=$gpct>=80?'var(--leaf)':($gpct>=40?'var(--gold)':'var(--danger)')?>"></div></div>
              <span style="font-size:.62rem;font-weight:500"><?=round($gpct)?>%</span>
            </div>
          </td>
          <td><span class="cf-status <?=$gsclass?>"><?=htmlspecialchars($gc['status']??'active')?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($grower_contracts)): ?>
        <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--muted)">No season registrations found. Use Season Rollover to register growers.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── TAB 3: GROWER CONTRACTS (NEW) ────────────────────────────────────── -->
<div id="tab-grower-contracts-new" class="tab-content">
  <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.5rem">
    <div class="kpi-card" style="--kpi-color:var(--gold)">
      <div class="kpi-label">Grower Contracts</div>
      <div class="kpi-value"><?=$total_contracts_new?></div>
      <div class="kpi-sub">Total registered contracts</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--gold)">
      <div class="kpi-label">Inputs Issued</div>
      <div class="kpi-value sm">$<?=number_format($total_inputs_new,2)?></div>
      <div class="kpi-sub">USD loan values</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--leaf)">
      <div class="kpi-label">Delivered Mass</div>
      <div class="kpi-value sm"><?=number_format($total_delivered_new,1)?> kg</div>
      <div class="kpi-sub">from balanced bales</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--leaf)">
      <div class="kpi-label">Settlement Rate</div>
      <div class="kpi-value sm"><?=number_format($settlement_rate,1)?>%</div>
      <div class="kpi-sub">recovered from sales</div>
    </div>
  </div>

  <div class="grid-3" style="align-items:start;gap:1.5rem;grid-template-columns:2fr 1fr">
    <!-- Left Column: Grower Contracts List -->
    <div class="card">
      <div class="card-head"><h3>📜 Grower Contracts Checklist</h3><span class="badge badge-gold"><?=$total_contracts_new?></span></div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Grower</th>
              <th>Season</th>
              <th class="text-right">Expected Mass</th>
              <th class="text-right">Inputs Value</th>
              <th class="text-right">Delivered Mass</th>
              <th>Lifecycle Stage</th>
              <th>Status</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($grower_contracts_new as $c):
              $prog_inputs = $c['input_value_usd'] > 0;
              $prog_delivery = $c['delivered_mass'] > 0;
              $prog_settled = ($c['status'] === 'completed' || ($c['input_value_usd'] > 0 && $c['total_repaid'] >= $c['input_value_usd']));
            ?>
            <tr>
              <td>
                <div style="font-weight:600"><?=htmlspecialchars($c['grower_name'])?></div>
                <div style="font-size:.65rem;color:var(--muted)"><?=htmlspecialchars($c['grower_num'])?> · <?=htmlspecialchars($c['farm_name']??'No Farm')?></div>
              </td>
              <td class="td-mono"><?=htmlspecialchars($c['season'])?></td>
              <td class="td-mono text-right"><?=number_format((float)$c['expected_mass'],1)?> kg</td>
              <td class="td-mono text-right">$<?=number_format((float)$c['input_value_usd'],2)?></td>
              <td class="td-mono text-right" style="font-weight:600;color:var(--leaf)"><?=number_format((float)$c['delivered_mass'],1)?> kg</td>
              <td>
                <div style="display:flex;gap:4px;font-size:.6rem">
                  <span class="badge badge-green" style="font-size:.55rem;padding:2px 4px" title="Agreement Complete">AGR</span>
                  <span class="badge <?=$prog_inputs?'badge-green':'badge-gray'?>" style="font-size:.55rem;padding:2px 4px" title="Inputs Issued">INP</span>
                  <span class="badge <?=$prog_delivery?'badge-green':'badge-gray'?>" style="font-size:.55rem;padding:2px 4px" title="Deliveries Received">DEL</span>
                  <span class="badge <?=$prog_settled?'badge-green':'badge-gray'?>" style="font-size:.55rem;padding:2px 4px" title="Contract Fully Settled">SET</span>
                </div>
              </td>
              <td>
                <span class="cf-status cf-s-<?=htmlspecialchars($c['status'])?>"><?=htmlspecialchars($c['status'])?></span>
              </td>
              <td class="text-center">
                <button onclick="openContractDetails(<?=$c['id']?>)" class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:.3rem .5rem">🔍 View Details</button>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($grower_contracts_new)): ?>
            <tr><td colspan="8" style="text-align:center;padding:3rem;color:var(--muted)">No registered grower contracts found. Register a grower contract on the right.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Right Column: Create Grower Contract Form -->
    <div class="card">
      <div class="card-head"><h3>🆕 Register Grower Contract</h3></div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="action" value="create_grower_contract">
          
          <div class="form-group">
            <label>Select Grower *</label>
            <select name="growerid" class="form-control" required style="font-size:.8rem">
              <option value="">-- Choose Grower --</option>
              <?php foreach($all_growers as $g): ?>
              <option value="<?=$g['id']?>"><?=htmlspecialchars($g['grower_num'])?> — <?=htmlspecialchars($g['name'].' '.$g['surname'])?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-row cols-2">
            <div class="form-group">
              <label>Season *</label>
              <input type="text" name="season" class="form-control" value="2025/2026" required placeholder="e.g. 2025/2026">
            </div>
            <div class="form-group">
              <label>Expected Bales</label>
              <input type="number" name="expected_bales" class="form-control" value="30" min="1">
            </div>
          </div>

          <div class="form-row cols-2">
            <div class="form-group">
              <label>Expected Mass (kg) *</label>
              <input type="number" name="expected_mass" class="form-control" value="3600" min="1" step="0.01" required>
            </div>
            <div class="form-group">
              <label>Initial Inputs Value ($)</label>
              <input type="number" name="initial_inputs_val" class="form-control" value="800.00" min="0" step="0.01">
              <div style="font-size:.58rem;color:var(--muted);margin-top:2px">Auto-registers as a grower loan.</div>
            </div>
          </div>

          <div class="form-group">
            <label>Contract Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Agreement clauses, specific crop type..." style="resize:vertical"></textarea>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem" data-progress-label="Registering contract...">Save Contract</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ── OVERLAY MODAL: CONTRACT DETAILS & INPUTS TRACKER ────────────────────── -->
<div id="contract-details-modal" class="modal-overlay">
  <div class="modal-wrapper">
    <div class="modal-header">
      <h3 style="margin:0" id="m-grower-header">Contract Details — Grower Name</h3>
      <button onclick="closeContractDetails()" class="modal-close-btn">&times;</button>
    </div>
    <div class="modal-body">
      
      <!-- Lifecycle Step progress bar -->
      <div style="display:flex;justify-content:space-between;margin-bottom:2rem;position:relative;padding:0 2rem">
        <div style="position:absolute;top:16px;left:4rem;right:4rem;height:3px;background:var(--border);z-index:1"></div>
        <div id="m-line-prog" style="position:absolute;top:16px;left:4rem;width:0%;height:3px;background:var(--leaf);z-index:2;transition:width .4s ease"></div>
        
        <div style="text-align:center;z-index:3;width:80px">
          <div id="m-step-1" class="lifecycle-step">1</div>
          <div style="font-size:.65rem;font-weight:600">Agreement</div>
        </div>
        <div style="text-align:center;z-index:3;width:80px">
          <div id="m-step-2" class="lifecycle-step">2</div>
          <div style="font-size:.65rem;font-weight:600">Inputs</div>
        </div>
        <div style="text-align:center;z-index:3;width:80px">
          <div id="m-step-3" class="lifecycle-step">3</div>
          <div style="font-size:.65rem;font-weight:600">Delivery</div>
        </div>
        <div style="text-align:center;z-index:3;width:80px">
          <div id="m-step-4" class="lifecycle-step">4</div>
          <div style="font-size:.65rem;font-weight:600">Settlement</div>
        </div>
      </div>

      <div class="grid-2" style="align-items:start;gap:1.5rem;grid-template-columns:1.2fr 1fr">
        <!-- Left Side: Inputs list & Add Input form -->
        <div>
          <div class="card mb-3" style="padding:.75rem">
            <h4 style="margin:0 0 .5rem 0;font-size:.85rem;color:var(--gold)">🌱 Issued Inputs & Support Log</h4>
            <div class="table-wrap" style="max-height:180px;overflow-y:auto">
              <table style="font-size:.75rem">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Cost</th>
                    <th class="text-right">Total</th>
                  </tr>
                </thead>
                <tbody id="m-inputs-body">
                  <tr><td colspan="6" style="text-align:center;color:var(--muted)">No inputs issued.</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Add Input Form -->
          <div class="card" style="padding:.75rem">
            <h4 style="margin:0 0 .5rem 0;font-size:.85rem;color:var(--gold)">➕ Issue Additional Input Item</h4>
            <form method="POST">
              <input type="hidden" name="action" value="add_contract_input">
              <input type="hidden" name="contract_id" id="m-add-input-contract-id">
              
              <div class="form-row cols-2" style="margin-bottom:.4rem">
                <div class="form-group" style="margin:0">
                  <label style="font-size:.6rem">Input Type</label>
                  <select name="input_type" class="form-control" style="font-size:.7rem;padding:.3rem .5rem">
                    <option value="Fertilizer">Fertilizer (Compound D / AN)</option>
                    <option value="Seed">Tobacco Seeds</option>
                    <option value="Chemicals">Crop Chemicals / Pesticides</option>
                    <option value="Equipment">Hessian Bales / Twine / Paper</option>
                    <option value="Cash Advance">Emergency Cash Advance</option>
                    <option value="Other">Other / Logistics Support</option>
                  </select>
                </div>
                <div class="form-group" style="margin:0">
                  <label style="font-size:.6rem">Description *</label>
                  <input type="text" name="description" class="form-control" style="font-size:.7rem;padding:.3rem .5rem" required placeholder="e.g. 5x 50kg AN fertilizer">
                </div>
              </div>

              <div class="form-row cols-3" style="margin-bottom:.4rem">
                <div class="form-group" style="margin:0">
                  <label style="font-size:.6rem">Quantity</label>
                  <input type="number" name="quantity" id="m-add-qty" class="form-control" style="font-size:.7rem;padding:.3rem .5rem" value="1.00" min="0.01" step="0.01" oninput="recalcCost()">
                </div>
                <div class="form-group" style="margin:0">
                  <label style="font-size:.6rem">Unit</label>
                  <input type="text" name="unit" class="form-control" style="font-size:.7rem;padding:.3rem .5rem" value="bags" placeholder="e.g. bags, kg, liters">
                </div>
                <div class="form-group" style="margin:0">
                  <label style="font-size:.6rem">Unit Cost (USD)</label>
                  <input type="number" name="unit_cost_usd" id="m-add-cost" class="form-control" style="font-size:.7rem;padding:.3rem .5rem" value="35.00" min="0.01" step="0.01" oninput="recalcCost()">
                </div>
              </div>

              <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.5rem">
                <div style="font-size:.68rem;color:var(--muted)">Total Line Value: <strong style="color:var(--gold)" id="m-add-total-preview">$35.00</strong></div>
                <button type="submit" class="btn btn-primary btn-sm" style="font-size:.68rem;padding:.3rem .8rem">Issue Input Item</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Right Side: Recoverable Loan Reconciliation -->
        <div>
          <div class="card mb-3" style="padding:.75rem">
            <h4 style="margin:0 0 .5rem 0;font-size:.85rem;color:var(--gold)">⚖️ Deliveries & Loan Settlement</h4>
            
            <div style="display:flex;flex-direction:column;gap:.4rem">
              
              <!-- Progress indicator expected vs delivered -->
              <div>
                <div style="display:flex;justify-content:between;font-size:.68rem;color:var(--muted)">
                  <span>Delivery Fulfillment: <strong id="m-del-mass-preview">0.0 kg</strong> delivered</span>
                  <span style="flex:1;text-align:right">Expected: <strong id="m-expected-mass-preview">3,600 kg</strong></span>
                </div>
                <div class="cf-progress" style="margin-top:2px"><div id="m-del-mass-prog" class="cf-progress-fill" style="width:0%;background:var(--leaf)"></div></div>
              </div>

              <!-- Financial reconciliation rows -->
              <div style="display:flex;justify-content:space-between;font-size:.75rem;padding:.3rem 0;border-bottom:1px solid var(--border)">
                <span style="color:var(--muted)">Total Issued Loan (USD):</span>
                <strong id="m-loan-issued">$0.00</strong>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:.75rem;padding:.3rem 0;border-bottom:1px solid var(--border)">
                <span style="color:var(--muted)">Delivered Tobacco Value:</span>
                <strong style="color:var(--leaf)" id="m-delivered-val">$0.00</strong>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:.75rem;padding:.3rem 0;border-bottom:1px solid var(--border)">
                <span style="color:var(--muted)">Recovered/Deducted Amount:</span>
                <strong style="color:var(--leaf)" id="m-loan-repaid">$0.00</strong>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:.8rem;padding:.4rem 0;font-weight:600">
                <span>Outstanding Loan Balance:</span>
                <strong id="m-loan-outstanding" style="color:var(--danger)">$0.00</strong>
              </div>
            </div>
          </div>

          <!-- Actions -->
          <div class="card" style="padding:.75rem">
            <h4 style="margin:0 0 .5rem 0;font-size:.85rem;color:var(--gold)">⚙️ Update Contract Status</h4>
            <form method="POST" style="display:flex;gap:.5rem">
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="contract_id" id="m-update-status-contract-id">
              <select name="status" id="m-status-select" class="form-control" style="font-size:.75rem;flex:1;padding:.3rem .5rem">
                <option value="active">Active (Inputs/Deliveries ongoing)</option>
                <option value="completed">Completed (Bales delivered & loan repaid)</option>
                <option value="defaulted">Defaulted (Grower broke terms / side-marketed)</option>
                <option value="cancelled">Cancelled</option>
              </select>
              <button type="submit" class="btn btn-ghost btn-sm" style="font-size:.75rem">Update</button>
            </form>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
// Switch tabs logic
function switchTab(tabId, btn) {
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(b => {
        b.classList.remove('active', 'btn-primary');
        b.classList.add('btn-ghost');
    });
    
    document.getElementById('tab-' + tabId).style.display = 'block';
    btn.classList.remove('btn-ghost');
    btn.classList.add('active', 'btn-primary');
}

// Recalculate cost cost in sub-form
function recalcCost() {
    var q = parseFloat(document.getElementById('m-add-qty').value) || 0;
    var c = parseFloat(document.getElementById('m-add-cost').value) || 0;
    document.getElementById('m-add-total-preview').textContent = '$' + (q * c).toFixed(2);
}

// Open details details overlay
async function openContractDetails(cid) {
    var modal = document.getElementById('contract-details-modal');
    modal.style.display = 'block';
    
    try {
        var d = await fw.get('contract_farming.php', { get_contract_details: cid });
        if(d.error) {
            alert(d.error);
            closeContractDetails();
            return;
        }
        
        // 1. Set Contract & ID fields
        document.getElementById('m-grower-header').textContent = 'Contract Details — ' + d.contract.grower_name + ' (' + d.contract.grower_num + ')';
        document.getElementById('m-add-input-contract-id').value = cid;
        document.getElementById('m-update-status-contract-id').value = cid;
        document.getElementById('m-status-select').value = d.contract.status;
        
        // 2. Load Inputs
        var tbody = document.getElementById('m-inputs-body');
        tbody.innerHTML = '';
        if(d.inputs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--muted)">No inputs issued.</td></tr>';
        } else {
            d.inputs.forEach(i => {
                var row = document.createElement('tr');
                row.innerHTML = '<td>' + i.issued_date + '</td>' +
                                '<td><span class="badge badge-gold" style="font-size:.58rem">' + i.input_type + '</span></td>' +
                                '<td>' + i.description + '</td>' +
                                '<td class="td-mono text-right">' + parseFloat(i.quantity).toFixed(1) + ' ' + i.unit + '</td>' +
                                '<td class="td-mono text-right">$' + parseFloat(i.unit_cost_usd).toFixed(2) + '</td>' +
                                '<td class="td-mono text-right" style="font-weight:600">$' + parseFloat(i.total_usd).toFixed(2) + '</td>';
                tbody.appendChild(row);
            });
        }
        
        // 3. Financial Settlement calculations
        var expected_mass = parseFloat(d.contract.expected_mass) || 0;
        var delivered_mass = parseFloat(d.deliveries.delivered_mass) || 0;
        var delivered_usd = parseFloat(d.deliveries.delivered_usd) || 0;
        
        var issued_loan = parseFloat(d.contract.input_value_usd) || 0;
        var repaid_loan = parseFloat(d.repayments.total_repaid) || 0;
        var outstanding = Math.max(0, issued_loan - repaid_loan);
        
        document.getElementById('m-del-mass-preview').textContent = delivered_mass.toLocaleString() + ' kg';
        document.getElementById('m-expected-mass-preview').textContent = expected_mass.toLocaleString() + ' kg';
        
        var del_pct = expected_mass > 0 ? (delivered_mass / expected_mass) * 100 : 0;
        document.getElementById('m-del-mass-prog').style.width = Math.min(100, del_pct) + '%';
        
        document.getElementById('m-loan-issued').textContent = '$' + issued_loan.toFixed(2);
        document.getElementById('m-delivered-val').textContent = '$' + delivered_usd.toFixed(2);
        document.getElementById('m-loan-repaid').textContent = '$' + repaid_loan.toFixed(2);
        document.getElementById('m-loan-outstanding').textContent = '$' + outstanding.toFixed(2);
        
        // 4. Render Step indicators
        // 4 Stages: Agreement (1), Inputs (2), Delivery (3), Settlement (4)
        var step1 = document.getElementById('m-step-1');
        var step2 = document.getElementById('m-step-2');
        var step3 = document.getElementById('m-step-3');
        var step4 = document.getElementById('m-step-4');
        var lineProg = document.getElementById('m-line-prog');
        
        // Reset steps
        [step1, step2, step3, step4].forEach(s => {
            s.classList.remove('active', 'completed');
            s.textContent = s.id.replace('m-step-', '');
        });
        
        // Step 1: Agreement
        step1.classList.add('completed');
        step1.innerHTML = '✓';
        var active_count = 1;
        
        // Step 2: Inputs
        if (issued_loan > 0) {
            step2.classList.add('completed');
            step2.innerHTML = '✓';
            active_count = 2;
        } else {
            step2.classList.add('active');
        }
        
        // Step 3: Delivery
        if (delivered_mass > 0) {
            step3.classList.add('completed');
            step3.innerHTML = '✓';
            active_count = 3;
        } else {
            if (active_count === 2) step3.classList.add('active');
        }
        
        // Step 4: Settlement
        if (d.contract.status === 'completed' || (issued_loan > 0 && repaid_loan >= issued_loan)) {
            step4.classList.add('completed');
            step4.innerHTML = '✓';
            active_count = 4;
        } else {
            if (active_count === 3) step4.classList.add('active');
        }
        
        // Line progress width calculation
        var prog_width = 0;
        if (active_count === 2) prog_width = 33;
        else if (active_count === 3) prog_width = 66;
        else if (active_count === 4) prog_width = 100;
        lineProg.style.width = prog_width + '%';
        
    } catch(e) {
        alert("Failed to load contract details.");
        closeContractDetails();
    }
}

// Close details modal
function closeContractDetails() {
    document.getElementById('contract-details-modal').style.display = 'none';
}
</script>

<?php include "includes/layout_end.php"; ?>
