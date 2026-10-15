<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "audit.php";
require_login();
$page_title="Buyer Management";$active_page="buyer_management";
$spid=(int)($_SESSION['selling_pointid']??0);$userid=current_userid();
$msg=$err='';$today=date('Y-m-d');$yr=(int)date('Y');

$conn->query("CREATE TABLE IF NOT EXISTS buyer_contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,selling_pointid INT NOT NULL,buyerid INT NOT NULL,
    season_year INT NOT NULL,contract_number VARCHAR(60) DEFAULT '',
    credit_limit_usd DECIMAL(14,2) DEFAULT 0,credit_used_usd DECIMAL(14,2) DEFAULT 0,
    target_bales INT DEFAULT 0,target_mass_kg DECIMAL(10,2) DEFAULT 0,
    status ENUM('active','suspended','expired') DEFAULT 'active',
    notes TEXT DEFAULT '',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bc (selling_pointid,buyerid,season_year))");

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    if($action==='add_buyer_mark'){
        $name=$conn->real_escape_string(trim($_POST['name']??''));
        $code=$conn->real_escape_string(strtoupper(trim($_POST['buyer_code']??'')));
        if(!$name || !$code){$err="Name and code required.";}
        else{
            $dup=$conn->query("SELECT id FROM buyer WHERE buyer_code='$code' AND selling_pointid=$spid LIMIT 1");
            if($dup && $dup->num_rows){$err="Buyer mark '$code' already exists on this floor.";}
            else{
                // Retrieve remaining fields from the existing buyer to copy them over
                $orig_q=$conn->query("SELECT contact_name,phone,email,country,notes FROM buyer WHERE name='$name' AND selling_pointid=$spid LIMIT 1");
                $orig=($orig_q && $orig_q->num_rows)?$orig_q->fetch_assoc():[];
                $contact=$conn->real_escape_string($orig['contact_name']??'');
                $phone=$conn->real_escape_string($orig['phone']??'');
                $email=$conn->real_escape_string($orig['email']??'');
                $country=$conn->real_escape_string($orig['country']??'Zimbabwe');
                $notes=$conn->real_escape_string($orig['notes']??'');

                if($conn->query("INSERT INTO buyer (selling_pointid,name,buyer_code,contact_name,phone,email,country,notes) VALUES ($spid,'$name','$code','$contact','$phone','$email','$country','$notes')")) {
                    $bid=$conn->insert_id;
                    audit_log($conn,$spid,$userid,'buyer','create',$bid);
                    $msg="✓ Buyer mark '$code' added for '$name'.";
                    $sel_buyer=$bid; // Select the newly created mark row so it loads
                } else {
                    $err="DB Error: " . $conn->error;
                }
            }
        }
    }
    if($action==='save_buyer'){
        $bid=(int)($_POST['buyerid']??0);
        $name=$conn->real_escape_string(trim($_POST['name']??''));
        $code=$conn->real_escape_string(strtoupper(trim($_POST['buyer_code']??'')));
        $contact=$conn->real_escape_string(trim($_POST['contact_name']??''));
        $phone=$conn->real_escape_string(trim($_POST['phone']??''));
        $email=$conn->real_escape_string(trim($_POST['email']??''));
        $country=$conn->real_escape_string(trim($_POST['country']??'Zimbabwe'));
        $notes=$conn->real_escape_string(trim($_POST['notes']??''));
        if(!$name){$err="Buyer name required.";}
        else{
            if($bid){
                $conn->query("UPDATE buyer SET name='$name',buyer_code='$code',contact_name='$contact',phone='$phone',email='$email',country='$country',notes='$notes' WHERE id=$bid AND selling_pointid=$spid");
                audit_log($conn,$spid,$userid,'buyer','update',$bid);$msg="✓ Buyer updated.";
            }else{
                $conn->query("INSERT INTO buyer (selling_pointid,name,buyer_code,contact_name,phone,email,country,notes) VALUES ($spid,'$name','$code','$contact','$phone','$email','$country','$notes')");
                $bid=$conn->insert_id;audit_log($conn,$spid,$userid,'buyer','create',$bid);$msg="✓ Buyer '$name' created.";
            }
        }
    }
    if($action==='save_contract'){
        $bid=(int)($_POST['buyerid']??0);$yr_c=(int)($_POST['season_year']??$yr);
        $cnum=$conn->real_escape_string(trim($_POST['contract_number']??''));
        $limit=(float)($_POST['credit_limit_usd']??0);$tbales=(int)($_POST['target_bales']??0);
        $tmass=(float)($_POST['target_mass_kg']??0);
        $conn->query("INSERT INTO buyer_contracts (selling_pointid,buyerid,season_year,contract_number,credit_limit_usd,target_bales,target_mass_kg)
            VALUES ($spid,$bid,$yr_c,'$cnum',$limit,$tbales,$tmass)
            ON DUPLICATE KEY UPDATE contract_number='$cnum',credit_limit_usd=$limit,target_bales=$tbales,target_mass_kg=$tmass");
        audit_log($conn,$spid,$userid,'buyer_contracts','save',$bid);$msg="✓ Contract saved.";
    }
    if($action==='toggle_buyer'){
        $bid=(int)($_POST['buyerid']??0);$status=$conn->real_escape_string($_POST['status']??'active');
        $conn->query("UPDATE buyer SET active=".($status==='active'?1:0)." WHERE id=$bid AND selling_pointid=$spid");
        $msg="Buyer status updated.";
    }
}

$sel_buyer=(int)($_GET['id']??0);

// Load buyers with season stats
$buyers_q=$conn->query("
    SELECT b.*,
        bc.credit_limit_usd,bc.credit_used_usd,bc.target_bales,bc.contract_number,bc.status contract_status,
        COUNT(DISTINCT sb.id) bales_yr,
        COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd_yr,
        COALESCE(SUM(bm.mass),0) mass_yr
    FROM buyer b
    LEFT JOIN buyer_contracts bc ON bc.buyerid=b.id AND bc.selling_pointid=$spid AND bc.season_year=$yr
    LEFT JOIN buyer_grades bg ON bg.buyerid=b.id
    LEFT JOIN sold_bales sb ON sb.buyer_gradesid=bg.id AND YEAR(sb.created_at)=$yr
    LEFT JOIN tickets tk ON tk.id=sb.ticketsid
    LEFT JOIN bale_junus bj ON bj.id=tk.bale_junusid
    LEFT JOIN bale_mass bm ON bm.id=bj.bale_massid
    LEFT JOIN growers g ON g.id=bj.growerid AND g.selling_pointid=$spid
    WHERE b.selling_pointid=$spid
    GROUP BY b.id ORDER BY usd_yr DESC
");
$buyers=($buyers_q&&$buyers_q!==true)?$buyers_q->fetch_all(MYSQLI_ASSOC):[];

$sel=null;
foreach($buyers as $b){if((int)$b['id']===$sel_buyer){$sel=$b;break;}}

// Grade breakdown for selected buyer
$grades=[];
if($sel){
    $bid_s=(int)$sel['id'];
    $gr_q=$conn->query("SELECT bg.description grade,COUNT(sb.id) bales,COALESCE(SUM(bm.mass),0) mass,AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END) avg_price,SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END) usd FROM buyer_grades bg LEFT JOIN sold_bales sb ON sb.buyer_gradesid=bg.id AND YEAR(sb.created_at)=$yr LEFT JOIN tickets tk ON tk.id=sb.ticketsid LEFT JOIN bale_junus bj ON bj.id=tk.bale_junusid LEFT JOIN bale_mass bm ON bm.id=bj.bale_massid LEFT JOIN growers g ON g.id=bj.growerid AND g.selling_pointid=$spid WHERE bg.buyerid=$bid_s GROUP BY bg.id ORDER BY usd DESC");
    $grades=($gr_q&&$gr_q!==true)?$gr_q->fetch_all(MYSQLI_ASSOC):[];
}

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Buyer Management</h2><p>Buyer profiles, contracts and credit limits — <?=$yr?> season</p></div>
  <a href="buyers.php" class="btn btn-ghost btn-sm">→ Buyer Grades</a>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>


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

.buyer-tab-panel {
    display: none;
}
.buyer-tab-panel.active {
    display: block;
    animation: buyerFadeIn 0.25s ease-in-out;
}

@keyframes buyerFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.buyer-centered-container {
    max-width: 720px;
    margin: 0 auto;
}
</style>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
  <button class="premium-tab-btn active" data-tab="buyers" onclick="switchBuyerTab('buyers')">
      👥 Buyers Registry
  </button>
  <button class="premium-tab-btn" data-tab="contracts" onclick="switchBuyerTab('contracts')">
      ✍️ Contracts &amp; Credit
  </button>
  <button class="premium-tab-btn" data-tab="grades" onclick="switchBuyerTab('grades')">
      📊 Grade Breakdown
  </button>
</div>

<!-- Tab Panel 1: Buyers Registry -->
<div id="panel-buyers" class="buyer-tab-panel active">
  <div class="grid-2 mb-3" style="align-items: start; gap: 1rem;">
    <!-- Buyer list -->
    <div class="card">
      <div class="card-head"><h3>Buyers</h3><span class="badge badge-gray"><?=count($buyers)?></span></div>
      <div class="table-wrap" style="max-height:480px;overflow-y:auto"><table>
      <thead><tr><th>Buyer</th><th class="text-right"><?=$yr?> Bales</th><th class="text-right"><?=$yr?> USD</th><th>Contract</th><th></th></tr></thead>
      <tbody>
      <?php if(empty($buyers)):?><tr><td colspan="5" class="text-center text-muted" style="padding:1.5rem">No buyers yet.</td></tr>
      <?php else:foreach($buyers as $b):$lim=(float)($b['credit_limit_usd']??0);$used=(float)($b['usd_yr']??0);$pct=$lim>0?min(100,round($used/$lim*100)):0;?>
      <tr style="<?=(int)$b['id']===$sel_buyer?'background:rgba(200,149,42,.06);border-left:3px solid var(--gold)':''?>">
        <td>
          <a href="?id=<?=$b['id']?>" style="font-weight:500;color:var(--text-strong);text-decoration:none" onclick="sessionStorage.setItem('active_buyer_tab', 'contracts');"><?=htmlspecialchars($b['name'])?></a>
          <div style="font-size:.65rem;color:var(--muted)"><?=htmlspecialchars($b['buyer_code']??'')?></div>
        </td>
        <td class="td-mono text-right"><?=number_format((int)($b['bales_yr']??0))?></td>
        <td class="td-mono text-right fw-500">$<?=number_format((float)($b['usd_yr']??0),0)?></td>
        <td>
          <?php if($lim>0):?>
          <div style="font-size:.68rem;color:var(--muted);margin-bottom:2px">Credit: <?=$pct?>%</div>
          <div style="height:4px;background:var(--cream-d);border-radius:2px;width:80px">
            <div style="height:4px;width:<?=$pct?>%;background:<?=$pct>=90?'var(--danger)':'var(--gold)'?>;border-radius:2px"></div>
          </div>
          <?php else:?><span style="font-size:.68rem;color:var(--muted)">No contract</span><?php endif;?>
        </td>
        <td><a href="?id=<?=$b['id']?>" class="btn btn-ghost btn-sm" style="font-size:.65rem;" onclick="sessionStorage.setItem('active_buyer_tab', 'contracts');">Edit</a></td>
      </tr>
      <?php endforeach;endif;?>
      </tbody>
      </table></div>
    </div>

    <!-- Add buyer form -->
    <div class="card">
      <div class="card-head">
        <h3><?=$sel?'Edit: '.htmlspecialchars($sel['name']):'Add New Buyer'?></h3>
        <div style="display:flex;gap:.4rem">
          <?php if($sel):?>
            <button type="button" class="btn btn-gold btn-sm" onclick="toggleAddMarkForm()">+ Add Mark</button>
            <a href="buyer_management.php" class="btn btn-ghost btn-sm">+ New</a>
          <?php endif;?>
        </div>
      </div>
      <div class="card-body">

      <?php if($sel):?>
      <div id="add-mark-form-section" style="display:none;background:#fdf9f0;border:1px solid #e8d8a0;padding:1rem;margin-bottom:1.15rem;border-radius:var(--radius)">
        <form method="POST">
          <input type="hidden" name="action" value="add_buyer_mark">
          <input type="hidden" name="name" value="<?=htmlspecialchars($sel['name'])?>">
          <div style="font-weight:600;font-size:.78rem;color:#9a7830;margin-bottom:.55rem">Add New Buyer Mark for <?=htmlspecialchars($sel['name'])?></div>
          <div style="display:flex;gap:.5rem;align-items:flex-end">
            <div class="form-group" style="margin:0;flex:1">
              <label style="font-size:.68rem">New Mark / Code</label>
              <input type="text" name="buyer_code" class="form-control" style="text-transform:uppercase;font-family:monospace" placeholder="e.g. PA3" required>
            </div>
            <button type="submit" class="btn btn-gold btn-sm">Add Mark</button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="toggleAddMarkForm()">Cancel</button>
          </div>
        </form>
      </div>
      <?php endif;?>

      <form method="POST">
      <input type="hidden" name="action" value="save_buyer">
      <input type="hidden" name="buyerid" value="<?=$sel?$sel['id']:0?>">
      <div class="form-row cols-2">
        <div class="form-group"><label>Buyer Name *</label><input type="text" name="name" class="form-control" value="<?=htmlspecialchars($sel['name']??'')?>" required placeholder="e.g. BAT Zimbabwe"></div>
        <div class="form-group"><label>Buyer Code</label><input type="text" name="buyer_code" class="form-control" value="<?=htmlspecialchars($sel['buyer_code']??'')?>" style="text-transform:uppercase" placeholder="BAT"></div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label>Contact Person</label><input type="text" name="contact_name" class="form-control" value="<?=htmlspecialchars($sel['contact_name']??'')?>"></div>
        <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" value="<?=htmlspecialchars($sel['phone']??'')?>" placeholder="+263..."></div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" value="<?=htmlspecialchars($sel['email']??'')?>"></div>
        <div class="form-group"><label>Country</label><input type="text" name="country" class="form-control" value="<?=htmlspecialchars($sel['country']??'Zimbabwe')?>"></div>
      </div>
      <div class="form-group"><label>Notes</label><input type="text" name="notes" class="form-control" value="<?=htmlspecialchars($sel['notes']??'')?>"></div>
      <button type="submit" class="btn btn-primary" style="width:100%">Save Buyer</button>
      </form>
      </div>
    </div>
  </div>
</div>

<!-- Tab Panel 2: Contracts & Credit -->
<div id="panel-contracts" class="buyer-tab-panel">
  <div class="buyer-centered-container">
    <?php if($sel):?>
      <!-- Season stats -->
      <div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1rem">
        <div class="kpi-card"><div class="kpi-label"><?=$yr?> Bales</div><div class="kpi-value"><?=number_format((int)($sel['bales_yr']??0))?></div></div>
        <div class="kpi-card"><div class="kpi-label"><?=$yr?> Mass</div><div class="kpi-value"><?=number_format((float)($sel['mass_yr']??0),0)?> kg</div></div>
        <div class="kpi-card"><div class="kpi-label"><?=$yr?> USD</div><div class="kpi-value">$<?=number_format((float)($sel['usd_yr']??0),0)?></div></div>
      </div>

      <!-- Credit limit -->
      <?php $lim=(float)($sel['credit_limit_usd']??0);$used=(float)($sel['usd_yr']??0);$pct=$lim>0?min(100,round($used/$lim*100)):0;?>
      <?php if($lim>0):?>
      <div class="card mb-3" style="border-top:3px solid <?=$pct>=90?'var(--danger)':'var(--gold)'?>">
      <div class="card-head"><h3>Credit Utilisation</h3><span class="badge <?=$pct>=90?'badge-red':'badge-gold'?>"><?=$pct?>%</span></div>
      <div class="card-body">
        <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:.4rem">
          <span>Used: <strong>$<?=number_format($used,0)?></strong></span>
          <span>Limit: <strong>$<?=number_format($lim,0)?></strong></span>
          <span>Available: <strong>$<?=number_format(max(0,$lim-$used),0)?></strong></span>
        </div>
        <div style="height:10px;background:var(--cream-d);border-radius:5px;overflow:hidden">
          <div style="height:10px;width:<?=$pct?>%;background:<?=$pct>=90?'var(--danger)':'var(--gold)'?>;border-radius:5px;transition:width .6s"></div>
        </div>
      </div>
      </div>
      <?php endif;?>

      <!-- Season contract -->
      <div class="card mb-3">
      <div class="card-head"><h3>Season Contract — <?=$yr?></h3></div>
      <div class="card-body">
      <form method="POST">
      <input type="hidden" name="action" value="save_contract">
      <input type="hidden" name="buyerid" value="<?=$sel['id']?>">
      <input type="hidden" name="season_year" value="<?=$yr?>">
      <div class="form-row cols-2">
        <div class="form-group"><label>Contract Number</label><input type="text" name="contract_number" class="form-control" value="<?=htmlspecialchars($sel['contract_number']??'')?>"></div>
        <div class="form-group"><label>Credit Limit (USD)</label><input type="number" name="credit_limit_usd" class="form-control" step="1000" value="<?=$sel['credit_limit_usd']??0?>"></div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label>Target Bales</label><input type="number" name="target_bales" class="form-control" value="<?=$sel['target_bales']??0?>"></div>
        <div class="form-group"><label>Target Mass (kg)</label><input type="number" name="target_mass_kg" class="form-control" step="100" value="<?=$sel['target_mass_kg']??0?>"></div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">Save Contract</button>
      </form>
      </div>
      </div>
    <?php else:?>
      <div class="card"><div class="card-body text-center text-muted" style="padding:3rem">
        <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem">🏢</div>
        Select a buyer from the Buyers Registry tab to view their profile and manage contracts
      </div></div>
    <?php endif;?>
  </div>
</div>

<!-- Tab Panel 3: Grade Breakdown -->
<div id="panel-grades" class="buyer-tab-panel">
  <div class="buyer-centered-container">
    <?php if($sel):?>
      <!-- Grade breakdown -->
      <div class="card">
      <div class="card-head"><h3>Grade Breakdown — <?=$yr?></h3></div>
      <div class="table-wrap">
      <?php if(!empty($grades)):?>
      <table>
      <thead><tr><th>Grade</th><th class="text-right">Bales</th><th class="text-right">Mass kg</th><th class="text-right">Avg $/kg</th><th class="text-right">USD</th></tr></thead>
      <tbody>
      <?php foreach($grades as $g):?>
      <tr>
        <td><span class="badge badge-gold" style="font-size:.62rem"><?=htmlspecialchars($g['grade'])?></span></td>
        <td class="td-mono text-right"><?=number_format((int)$g['bales'])?></td>
        <td class="td-mono text-right"><?=number_format((float)$g['mass'],0)?></td>
        <td class="td-mono text-right">$<?=number_format((float)$g['avg_price'],4)?></td>
        <td class="td-mono text-right fw-500">$<?=number_format((float)$g['usd'],0)?></td>
      </tr>
      <?php endforeach;?>
      </tbody>
      </table>
      <?php else:?>
        <div style="padding:2.5rem; text-align:center; color:var(--muted)">No grade sales breakdown recorded for this season yet.</div>
      <?php endif;?>
      </div>
      </div>
    <?php else:?>
      <div class="card"><div class="card-body text-center text-muted" style="padding:3rem">
        <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem">🏢</div>
        Select a buyer from the Buyers Registry tab to view their profile and grade breakdown analysis
      </div></div>
    <?php endif;?>
  </div>
</div>

<script>
function toggleAddMarkForm() {
    var sec = document.getElementById('add-mark-form-section');
    if(sec) {
        sec.style.display = sec.style.display === 'none' ? 'block' : 'none';
        if(sec.style.display === 'block') {
            sec.querySelector('input[name="buyer_code"]').focus();
        }
    }
}

function switchBuyerTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.buyer-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_buyer_tab', tabId);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const hasId = params.has('id');
    let defaultTab = sessionStorage.getItem('active_buyer_tab');
    if (hasId && !defaultTab) {
        defaultTab = 'contracts';
    }
    if (!defaultTab) {
        defaultTab = 'buyers';
    }
    switchBuyerTab(defaultTab);
});
</script>
<?php include "includes/layout_end.php";?>
