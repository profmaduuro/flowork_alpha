<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "audit.php";
require_login();

$page_title  = "Re-Offer / Second Pass";
$active_page = "re_offer";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();
$msg=$err='';
$today = date('Y-m-d');
$date_f = $conn->real_escape_string($_GET['date'] ?? $today);

$conn->query("CREATE TABLE IF NOT EXISTS re_offer_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    selling_pointid INT NOT NULL,
    sold_balesid INT NOT NULL,
    original_price DECIMAL(10,4) DEFAULT 0,
    re_offer_price DECIMAL(10,4) DEFAULT 0,
    re_offer_date DATE NOT NULL,
    status ENUM('offered','sold','withdrawn') DEFAULT 'offered',
    new_buyer_gradesid INT DEFAULT NULL,
    processed_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sp (selling_pointid), INDEX idx_sb (sold_balesid)
)");

// AJAX: search bale by barcode
if (isset($_GET['search_barcode'])) {
    header('Content-Type: application/json');
    $bc = $conn->real_escape_string(trim($_GET['search_barcode']));
    $b_q = $conn->query("
        SELECT sb.id sold_id, sb.price, sb.sale_code,
               tk.barcode, bm.mass, bm.temp_bacode timb_bc,
               g.grower_num, g.name, g.surname,
               tg.description grade, b.name buyer_name
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        WHERE (tk.barcode='$bc' OR bm.temp_bacode='$bc') AND g.selling_pointid=$spid LIMIT 1
    ");
    $b = ($b_q&&$b_q!==true)?$b_q->fetch_assoc():null;
    echo json_encode($b ?: ['error'=>'Bale not found']);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action==='re_offer') {
        $sbids = array_map('intval', explode(',', $_POST['sold_bale_ids']??''));
        $new_price = (float)($_POST['new_price']??0);
        $new_bgid  = (int)($_POST['buyer_grade_id']??0);
        $re_date   = $conn->real_escape_string($_POST['re_offer_date']??$today);
        $added = 0;
        foreach ($sbids as $sbid) {
            if (!$sbid) continue;
            // Get original price
            $orig_q = $conn->query("SELECT price FROM sold_bales WHERE id=$sbid LIMIT 1");
            $orig = ($orig_q&&$orig_q!==true)?$orig_q->fetch_assoc():[];
            $orig_price = (float)($orig['price']??0);
            if ($orig_price>10) $orig_price/=100;
            // Update sold bale with new price/buyer
            if ($new_price > 0) {
                $store_price = $new_price; // Store as-is (< 10 means per-kg)
                $conn->query("UPDATE sold_bales SET price=$store_price".($new_bgid?" ,buyer_gradesid=$new_bgid":"")." WHERE id=$sbid");
            }
            $conn->query("INSERT IGNORE INTO re_offer_log (selling_pointid,sold_balesid,original_price,re_offer_price,re_offer_date,status,new_buyer_gradesid,processed_by)
                VALUES ($spid,$sbid,$orig_price,$new_price,'$re_date','sold',$new_bgid,$userid)");
            audit_log($conn,$spid,$userid,'sold_bales','re_offer',$sbid,['price'=>$orig_price],['price'=>$new_price]);
            $added++;
        }
        $msg = "✓ Re-offered and updated $added bale(s) with new price $".number_format($new_price,4)."/kg.";
    }
}

// Unsold bales today (ticketed but not sold)
$unsold_q = $conn->query("
    SELECT tk.id ticket_id, tk.barcode, bm.mass, bm.temp_bacode timb_bc,
           g.grower_num, g.name, g.surname,
           bl.code loc_code, tg.description grade
    FROM tickets tk
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    LEFT JOIN transporter_growers tg2 ON tg2.id=bj.transporter_growersid
    LEFT JOIN transporter t ON t.id=tg2.transporterid
    LEFT JOIN bale_locations bl ON bl.id=t.location_id
    LEFT JOIN timb_grades tg ON tg.id=(SELECT timb_gradesid FROM sold_bales WHERE ticketsid=tk.id LIMIT 1)
    WHERE DATE(tk.created_at)='$date_f' AND g.selling_pointid=$spid
      AND NOT EXISTS (SELECT 1 FROM sold_bales WHERE ticketsid=tk.id)
    ORDER BY g.grower_num, tk.barcode
");
$unsold = ($unsold_q&&$unsold_q!==true)?$unsold_q->fetch_all(MYSQLI_ASSOC):[];

// Sold bales (for re-pricing)
$sold_q = $conn->query("
    SELECT sb.id sold_id, sb.price, sb.sale_code,
           tk.barcode, bm.mass, bm.temp_bacode timb_bc,
           g.grower_num, g.name, g.surname,
           tg.description grade, b.name buyer_name
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    LEFT JOIN buyer b ON b.id=bg.buyerid
    WHERE DATE(sb.created_at)='$date_f' AND g.selling_pointid=$spid
    ORDER BY g.grower_num
");
$sold = ($sold_q&&$sold_q!==true)?$sold_q->fetch_all(MYSQLI_ASSOC):[];

// Buyers for form
$buyers_q = $conn->query("SELECT bg.id, b.name buyer, bg.description grade FROM buyer_grades bg JOIN buyer b ON b.id=bg.buyerid WHERE b.selling_pointid=$spid ORDER BY b.name, bg.description LIMIT 200");
$buyer_grades = ($buyers_q&&$buyers_q!==true)?$buyers_q->fetch_all(MYSQLI_ASSOC):[];

// Re-offer history today
$history_q = $conn->query("
    SELECT rl.*, tk.barcode, g.grower_num, u.username,
           tg.description as orig_grade,
           bg.description as new_grade
    FROM re_offer_log rl
    JOIN sold_bales sb ON sb.id=rl.sold_balesid
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    LEFT JOIN buyer_grades bg ON bg.id=rl.new_buyer_gradesid
    LEFT JOIN users u ON u.id=rl.processed_by
    WHERE rl.selling_pointid=$spid AND rl.re_offer_date='$date_f'
    ORDER BY rl.created_at DESC LIMIT 100
");
$history = ($history_q&&$history_q!==true)?$history_q->fetch_all(MYSQLI_ASSOC):[];

// --- Real-time Re-offer Analytics ---
// 1. Funnel calculations
// Rejected bales count (bales sold with sale_code = 'R' or price = 0 or price empty)
$funnel_rejected_q = $conn->query("
    SELECT COUNT(*) 
    FROM sold_bales sb 
    JOIN tickets tk ON tk.id=sb.ticketsid 
    JOIN bale_junus bj ON bj.id=tk.bale_junusid 
    JOIN growers g ON g.id=bj.growerid 
    WHERE DATE(sb.created_at)='$date_f' AND g.selling_pointid=$spid 
      AND (sb.sale_code='R' OR sb.price='0' OR sb.price='' OR sb.price IS NULL)
");
$funnel_rejected = $funnel_rejected_q ? (int)$funnel_rejected_q->fetch_row()[0] : 0;
// Add unsold ticket count as they are also re-offer candidates
$funnel_rejected += count($unsold);

// Re-offered today count
$funnel_reoffered_q = $conn->query("
    SELECT COUNT(DISTINCT sold_balesid) 
    FROM re_offer_log 
    WHERE selling_pointid=$spid AND re_offer_date='$date_f'
");
$funnel_reoffered = $funnel_reoffered_q ? (int)$funnel_reoffered_q->fetch_row()[0] : 0;

// Re-offered & sold today count
$funnel_sold_q = $conn->query("
    SELECT COUNT(DISTINCT sold_balesid) 
    FROM re_offer_log 
    WHERE selling_pointid=$spid AND re_offer_date='$date_f' AND status='sold'
");
$funnel_sold = $funnel_sold_q ? (int)$funnel_sold_q->fetch_row()[0] : 0;

// 2. Average price delta (first offer vs final achieved price)
$price_delta_q = $conn->query("
    SELECT AVG(original_price) as avg_orig, AVG(re_offer_price) as avg_new 
    FROM re_offer_log 
    WHERE selling_pointid=$spid AND re_offer_date='$date_f' AND re_offer_price > 0
");
$price_delta_data = $price_delta_q ? $price_delta_q->fetch_assoc() : [];
$avg_orig = (float)($price_delta_data['avg_orig'] ?? 0);
$avg_new  = (float)($price_delta_data['avg_new'] ?? 0);
$avg_delta = $avg_new - $avg_orig;

// 3. Top grades re-offered
$grade_breakdown_q = $conn->query("
    SELECT tg.description as grade, COUNT(*) as cnt 
    FROM re_offer_log rl 
    JOIN sold_bales sb ON sb.id=rl.sold_balesid 
    LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid 
    WHERE rl.selling_pointid=$spid AND rl.re_offer_date='$date_f' 
    GROUP BY tg.description 
    ORDER BY cnt DESC 
    LIMIT 4
");
$grade_breakdown = $grade_breakdown_q ? $grade_breakdown_q->fetch_all(MYSQLI_ASSOC) : [];

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

.reoffer-tab-panel {
    display: none;
}
.reoffer-tab-panel.active {
    display: block;
    animation: reofferFadeIn 0.25s ease-in-out;
}

@keyframes reofferFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.reoffer-form-container {
    max-width: 600px;
    margin: 0 auto;
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Re-Offer / Second Pass</h2>
    <p>Re-offer rejected or unsold bales at revised prices</p>
  </div>
  <form method="GET" style="display:flex;gap:.5rem;align-items:flex-end">
    <input type="date" name="date" class="form-control" value="<?=htmlspecialchars((string)$date_f)?>" style="font-size:.8rem;padding:.35rem .6rem">
    <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
  </form>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- Stats & Analytics Grid -->
<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1rem">
  <div class="kpi-card">
    <div class="kpi-label">Today's Sales Volume</div>
    <div class="kpi-value"><?=count($sold)?></div>
    <div class="kpi-sub">processed sold bales</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--gold)">
    <div class="kpi-label">Re-offers Today</div>
    <div class="kpi-value text-gold"><?=count($history)?></div>
    <div class="kpi-sub">second-pass attempts</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)">
    <div class="kpi-label">Avg Price Delta</div>
    <div class="kpi-value sm text-leaf"><?= $avg_delta >= 0 ? '+' : '' ?>$<?=number_format($avg_delta, 4)?></div>
    <div class="kpi-sub">avg price increase / kg</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Unsold / Re-offer Pool</div>
    <div class="kpi-value sm"><?=count($unsold)?></div>
    <div class="kpi-sub">bales ready for re-offer</div>
  </div>
</div>

<!-- Advanced Analytics Strip -->
<div class="grid-3" style="gap:1rem;margin-bottom:1.5rem">
  <!-- Conversion Funnel Card -->
  <div class="card" style="padding:1.15rem">
    <h4 style="margin:0 0 .75rem 0;font-size:.85rem;text-transform:uppercase;letter-spacing:.05em;color:var(--gold)">🌪️ Re-offer Conversion Funnel</h4>
    <div style="display:flex;justify-content:space-between;align-items:center;background:var(--cream-d);padding:.6rem 1rem;border-radius:6px;margin-bottom:.5rem">
      <div>
        <div style="font-size:.72rem;color:var(--muted)">1. REJECTED</div>
        <div style="font-size:1.1rem;font-weight:600"><?= $funnel_rejected ?></div>
      </div>
      <div style="color:var(--muted);font-size:.8rem">→</div>
      <div>
        <div style="font-size:.72rem;color:var(--muted)">2. RE-OFFERED</div>
        <div style="font-size:1.1rem;font-weight:600;color:var(--gold)"><?= $funnel_reoffered ?></div>
      </div>
      <div style="color:var(--muted);font-size:.8rem">→</div>
      <div>
        <div style="font-size:.72rem;color:var(--muted)">3. RE-SOLD</div>
        <div style="font-size:1.1rem;font-weight:600;color:var(--leaf)"><?= $funnel_sold ?></div>
      </div>
    </div>
    <div style="font-size:.7rem;color:var(--muted);text-align:right">
      Conversion Rate: <strong style="color:var(--leaf)"><?= $funnel_reoffered > 0 ? round(($funnel_sold / $funnel_reoffered) * 100, 1) : 0 ?>%</strong>
    </div>
  </div>

  <!-- Price Improvement Card -->
  <div class="card" style="padding:1.15rem">
    <h4 style="margin:0 0 .75rem 0;font-size:.85rem;text-transform:uppercase;letter-spacing:.05em;color:var(--gold)">📈 Price Improvement Delta</h4>
    <div style="display:flex;justify-content:space-between;align-items:center;height:55px">
      <div style="flex:1;text-align:center;border-right:1px solid var(--border)">
        <div style="font-size:.72rem;color:var(--muted)">AVG ORIGINAL PRICE</div>
        <div style="font-size:.9rem;font-weight:600">$<?= number_format($avg_orig, 4) ?></div>
      </div>
      <div style="flex:1;text-align:center">
        <div style="font-size:.72rem;color:var(--muted)">AVG RE-OFFERED PRICE</div>
        <div style="font-size:.9rem;font-weight:600;color:var(--leaf)">$<?= number_format($avg_new, 4) ?></div>
      </div>
    </div>
    <div style="font-size:.7rem;color:var(--muted);text-align:center;margin-top:.4rem;padding-top:.4rem;border-top:1px solid var(--border)">
      Net delta impact: <strong class="<?= $avg_delta >= 0 ? 'text-leaf' : 'text-danger' ?>"><?= $avg_delta >= 0 ? '+$' : '-$' ?><?= number_format(abs($avg_delta), 4) ?> / kg</strong>
    </div>
  </div>

  <!-- Top Re-offer Grades Card -->
  <div class="card" style="padding:1.15rem">
    <h4 style="margin:0 0 .5rem 0;font-size:.85rem;text-transform:uppercase;letter-spacing:.05em;color:var(--gold)">🌿 Top Re-offered Grades</h4>
    <?php if(empty($grade_breakdown)): ?>
      <div style="font-size:.75rem;color:var(--muted);text-align:center;padding:1rem 0">No re-offers processed today.</div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:.35rem;margin-top:.4rem">
        <?php 
        $max_cnt = max(array_column($grade_breakdown, 'cnt') ?: [1]);
        foreach($grade_breakdown as $gb):
          $pct = ($gb['cnt'] / $max_cnt) * 100;
        ?>
          <div style="font-size:.7rem;display:flex;align-items:center;justify-content:space-between">
            <span style="font-weight:500"><?= htmlspecialchars($gb['grade'] ?: 'Unknown') ?></span>
            <div style="display:flex;align-items:center;gap:.5rem;flex:1;margin-left:1rem;justify-content:flex-end">
              <div style="height:6px;background:var(--border);border-radius:3px;flex:1;max-width:80px;position:relative;overflow:hidden">
                <div style="position:absolute;top:0;left:0;height:100%;background:var(--gold);width:<?= $pct ?>%"></div>
              </div>
              <span class="badge badge-gold" style="font-size:.72rem;padding:1px 5px"><?= $gb['cnt'] ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="console" onclick="switchReofferTab('console')">
        📋 Re-price Console
    </button>
    <button class="premium-tab-btn" data-tab="unsold" onclick="switchReofferTab('unsold')">
        🔍 Unsold Pool (<?=count($unsold)?>)
    </button>
    <button class="premium-tab-btn" data-tab="history" onclick="switchReofferTab('history')">
        📜 Audit History
    </button>
</div>

<!-- Tab Panel 1: Re-price Console -->
<div id="panel-console" class="reoffer-tab-panel active">
    <div class="reoffer-form-container">
        <!-- Barcode lookup -->
        <div class="card mb-3">
            <div class="card-head"><h3>🔍 Bale Lookup by Barcode</h3></div>
            <div class="card-body">
              <div style="display:flex;gap:.5rem">
                <input type="text" id="bc-search" class="form-control" placeholder="Scan or enter barcode" autofocus>
                <button onclick="searchBale()" class="btn btn-ghost">Search</button>
              </div>
              <div id="bc-result" style="margin-top:.75rem;display:none;background:var(--cream-d);border-radius:6px;padding:.75rem;font-size:.8rem"></div>
            </div>
        </div>

        <!-- Re-price sold bales -->
        <div class="card">
            <div class="card-head"><h3>Re-price / Update Sold Bales</h3></div>
            <div class="card-body">
                <form method="POST" onsubmit="return confirm('Update prices for selected bales?')">
                <input type="hidden" name="action" value="re_offer">
                <input type="hidden" name="re_offer_date" value="<?=htmlspecialchars((string)$date_f)?>">
                <div class="form-group">
                  <label>Sold Bale IDs (comma-separated)</label>
                  <input type="text" name="sold_bale_ids" class="form-control" id="re-bale-ids" placeholder="e.g. 123,124,125" required>
                </div>
                <div class="form-row cols-2">
                  <div class="form-group">
                    <label>New Price $/kg</label>
                    <input type="number" name="new_price" class="form-control" step="0.0001" min="0.0001" required placeholder="0.0000">
                  </div>
                  <div class="form-group">
                    <label>New Buyer Grade (optional)</label>
                    <select name="buyer_grade_id" class="form-control">
                      <option value="0">Keep existing buyer</option>
                      <?php foreach($buyer_grades as $bg):?>
                      <option value="<?=$bg['id']?>"><?=htmlspecialchars($bg['buyer'].' — '.$bg['grade'])?></option>
                      <?php endforeach;?>
                    </select>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%" data-progress-label="Updating prices...">Apply New Price</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 2: Unsold Pool -->
<div id="panel-unsold" class="reoffer-tab-panel">
    <div class="card">
        <div class="card-head">
          <h3>Unsold Bales — <?=date('d M Y',strtotime($date_f))?></h3>
          <span class="badge badge-gold"><?=count($unsold)?></span>
        </div>
        <?php if(empty($unsold)):?>
        <div class="card-body text-center text-muted" style="padding:2rem">No unsold bales today.</div>
        <?php else:?>
        <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
            <table>
                <thead>
                    <tr>
                        <th>Barcode</th>
                        <th>Grower</th>
                        <th class="text-right">Mass</th>
                        <th>Grade</th>
                        <th>Loc</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($unsold as $u):?>
                <tr style="cursor:pointer" onclick="addToReOffer(<?=js_arg($u['barcode'])?>,<?=js_arg($u['ticket_id'])?>)">
                  <td style="font-family:monospace;font-size:.72rem"><?=htmlspecialchars($u['barcode'])?></td>
                  <td><span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($u['grower_num'])?></span></td>
                  <td class="td-mono text-right"><?=number_format((float)$u['mass'],1)?></td>
                  <td style="font-size:.7rem"><?=htmlspecialchars($u['grade']??'—')?></td>
                  <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($u['loc_code']??'')?></td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
        <?php endif;?>
    </div>
</div>

<!-- Tab Panel 3: Audit History -->
<div id="panel-history" class="reoffer-tab-panel">
    <div class="card">
        <div class="card-head"><h3>Re-offer History Today</h3></div>
        <?php if(empty($history)):?>
        <div class="card-body text-center text-muted" style="padding:2rem">No re-offers processed today.</div>
        <?php else:?>
        <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
            <table>
                <thead>
                    <tr>
                        <th>Barcode</th>
                        <th>Grower</th>
                        <th>Original Price/Grade</th>
                        <th>Re-offer Price/Grade</th>
                        <th class="text-right">Price Delta</th>
                        <th>Status</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($history as $h):
                  $delta = (float)$h['re_offer_price'] - (float)$h['original_price'];
                  $delta_color = $delta > 0 ? '#3d6b35' : ($delta < 0 ? '#c0392b' : 'var(--muted)');
                  $delta_sign = $delta > 0 ? '+' : '';
                  
                  $status_color = 'badge-gold';
                  if ($h['status'] === 'sold') $status_color = 'badge-green';
                  if ($h['status'] === 'withdrawn') $status_color = 'badge-red';
                ?>
                <tr>
                  <td style="font-family:monospace;font-size:.72rem"><?=htmlspecialchars($h['barcode'])?></td>
                  <td style="font-size:.72rem">
                    <span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($h['grower_num'])?></span>
                  </td>
                  <td style="font-size:.72rem">
                    <div style="font-weight:500">$<?=number_format((float)$h['original_price'],4)?></div>
                    <div style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($h['orig_grade']??'—')?></div>
                  </td>
                  <td style="font-size:.72rem">
                    <div style="font-weight:500;color:var(--leaf)">$<?=number_format((float)$h['re_offer_price'],4)?></div>
                    <div style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($h['new_grade']??'—')?></div>
                  </td>
                  <td class="td-mono text-right" style="color:<?=$delta_color?>;font-weight:600">
                    <?=$delta_sign?>$<?=number_format($delta,4)?>
                  </td>
                  <td>
                    <span class="badge <?=$status_color?>" style="font-size:.72rem"><?=strtoupper($h['status'])?></span>
                  </td>
                  <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($h['username']??'—')?></td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
        <?php endif;?>
    </div>
</div>

<script>
function switchReofferTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.reoffer-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_reoffer_tab', tabId);
        
        if (tabId === 'console') {
            setTimeout(() => {
                const el = document.getElementById('bc-search');
                if (el) el.focus();
            }, 50);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $msg): ?>
    const defaultTab = 'history';
    <?php else: ?>
    const defaultTab = sessionStorage.getItem('active_reoffer_tab') || 'console';
    <?php endif; ?>
    
    switchReofferTab(defaultTab);
});

async function searchBale() {
    var bc = document.getElementById('bc-search').value.trim();
    if(!bc) return;
    var res = document.getElementById('bc-result');
    res.style.display='block'; res.textContent='Searching...';
    try {
        var d = await fw.get('re_offer.php',{search_barcode:bc});
        if(d.error) { res.textContent=d.error; return; }
        var p = parseFloat(d.price); if(p>10) p/=100;
        res.innerHTML='<strong>Sold Bale #'+d.sold_id+'</strong> · '+d.grower_num+' '+d.name+' '+d.surname+
            '<br>Grade: '+d.grade+' · Mass: '+d.mass+' kg · Price: $'+p.toFixed(4)+'/kg · Buyer: '+(d.buyer_name||'—')+
            '<br><button class="btn btn-ghost btn-sm" style="margin-top:.4rem;font-size:.7rem" onclick="document.getElementById(\'re-bale-ids\').value=\''+d.sold_id+'\'; switchReofferTab(\'console\')">Use this ID →</button>';
    } catch(e) { res.textContent='Error searching.'; }
}

function addToReOffer(bc,tid){
    document.getElementById('bc-search').value=bc;
    switchReofferTab('console');
    searchBale();
}
</script>

<?php include "includes/layout_end.php"; ?>
