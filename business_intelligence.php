<?php
ob_start();
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

// Restrict to reports / admin rights
if (!has_right('reports') && !has_right('admin')) {
    $_SESSION['flash_err'] = "Access Denied: You do not have permissions for the Business Intelligence module.";
    header("Location: dashboard.php");
    exit;
}

$page_title = "Business Intelligence";
$active_page = "business_intelligence";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);
$yr = (int)date('Y');

// Helper function to query DB safely
function safe_query($conn, $sql) {
    $res = $conn->query($sql);
    return ($res && $res !== true) ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

// 1. Fetch offtaker commission percentage settings from selling_point table
$sp_info = $conn->query("SELECT offtaker_comm_pct FROM selling_point WHERE id = $spid LIMIT 1");
$offtaker_rate_default = 0.13;
if ($sp_info && $sp_info->num_rows > 0) {
    $offtaker_rate_default = (float)$sp_info->fetch_assoc()['offtaker_comm_pct'];
}

// 2. Aggregate Sales Stats (Turnover, Mass, Bales Count)
$sales_stats_q = safe_query($conn, "
    SELECT 
        COUNT(sb.id) as total_bales,
        COALESCE(SUM(bm.mass), 0) as total_mass_kg,
        COALESCE(SUM(CASE WHEN sb.price > 10 THEN sb.price / 100 * bm.mass ELSE sb.price * bm.mass END), 0) as total_usd
    FROM sold_bales sb
    JOIN tickets tk ON tk.id = sb.ticketsid
    JOIN bale_junus bj ON bj.id = tk.bale_junusid
    JOIN bale_mass bm ON bm.id = bj.bale_massid
    JOIN growers g ON g.id = bj.growerid
    WHERE g.selling_pointid = $spid AND YEAR(sb.created_at) = $yr
");

$stats = $sales_stats_q[0] ?? ['total_bales' => 0, 'total_mass_kg' => 0, 'total_usd' => 0];
$total_tonnage = (float)$stats['total_mass_kg'] / 1000;
$gross_usd = (float)$stats['total_usd'];
$avg_price_per_kg = $stats['total_mass_kg'] > 0 ? $gross_usd / $stats['total_mass_kg'] : 0;

// 3. Fetch Rejection Totals
$rejections_q = safe_query($conn, "
    SELECT 
        COALESCE(SUM(dt.sold_bales), 0) as total_sold,
        COALESCE(SUM(dt.rejected_bales), 0) as total_rejected
    FROM daily_totals dt
    JOIN balanced_bales bb ON bb.id = dt.balanced_balesid
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g ON g.id = tg.growerid
    WHERE g.selling_pointid = $spid AND (YEAR(bb.sell_date) = $yr OR YEAR(bb.created_at) = $yr)
");
$rej_stats = $rejections_q[0] ?? ['total_sold' => 0, 'total_rejected' => 0];
$total_rejections = (int)$rej_stats['total_rejected'];
$total_sold = (int)$rej_stats['total_sold'];
$total_auctions = $total_sold + $total_rejections;
$rejection_rate = $total_auctions > 0 ? ($total_rejections / $total_auctions) * 100 : 0;

// 4. Monthly Turnover & Commissions (Chart 1)
$monthly_data = safe_query($conn, "
    SELECT 
        MONTH(sb.created_at) as mo,
        COALESCE(SUM(bm.mass), 0) as mass_kg,
        COALESCE(SUM(CASE WHEN sb.price > 10 THEN sb.price / 100 * bm.mass ELSE sb.price * bm.mass END), 0) as usd
    FROM sold_bales sb
    JOIN tickets tk ON tk.id = sb.ticketsid
    JOIN bale_junus bj ON bj.id = tk.bale_junusid
    JOIN bale_mass bm ON bm.id = bj.bale_massid
    JOIN growers g ON g.id = bj.growerid
    WHERE g.selling_pointid = $spid AND YEAR(sb.created_at) = $yr
    GROUP BY MONTH(sb.created_at)
    ORDER BY mo
");

$months_labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$chart_months = [];
$chart_turnover = [];
$chart_commissions = []; // Standard commission (2.5% of turnover)
for ($m = 1; $m <= 12; $m++) {
    $val = 0;
    foreach ($monthly_data as $row) {
        if ((int)$row['mo'] === $m) {
            $val = (float)$row['usd'];
            break;
        }
    }
    $chart_months[] = $months_labels[$m - 1];
    $chart_turnover[] = $val;
    $chart_commissions[] = $val * 0.025; // 2.5% standard floor commission
}

// 5. Buyer Concentration Statistics (Market Share Table)
$buyer_shares = safe_query($conn, "
    SELECT 
        b.name as buyer_name,
        b.buyer_code,
        COUNT(sb.id) as bales_bought,
        COALESCE(SUM(bm.mass), 0) as total_mass_kg,
        COALESCE(SUM(CASE WHEN sb.price > 10 THEN sb.price / 100 * bm.mass ELSE sb.price * bm.mass END), 0) as total_usd,
        AVG(CASE WHEN sb.price > 10 THEN sb.price / 100 ELSE sb.price END) as avg_price_kg
    FROM sold_bales sb
    JOIN tickets tk ON tk.id = sb.ticketsid
    JOIN bale_junus bj ON bj.id = tk.bale_junusid
    JOIN bale_mass bm ON bm.id = bj.bale_massid
    JOIN growers g ON g.id = bj.growerid
    JOIN buyer_grades bg ON bg.id = sb.buyer_gradesid
    JOIN buyer b ON b.id = bg.buyerid
    WHERE g.selling_pointid = $spid AND YEAR(sb.created_at) = $yr
    GROUP BY b.id
    ORDER BY total_usd DESC
");

// 6. Aggregate Statutory Charges & Deductions
$statutory_charges = safe_query($conn, "
    SELECT 
        s.description as charge_name,
        COALESCE(SUM(dt.sold_bales), 0) as count_deducted,
        COALESCE(SUM(bbc.usd_value), 0) as total_usd
    FROM balanced_bales_charges bbc
    JOIN grower_charges gc ON gc.id = bbc.grower_chargeid
    JOIN statutory s ON s.id = gc.statutoryid
    JOIN balanced_bales bb ON bb.id = bbc.balanced_balesid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid = bb.id
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g ON g.id = tg.growerid
    WHERE g.selling_pointid = $spid AND (YEAR(bb.sell_date) = $yr OR YEAR(bb.created_at) = $yr)
    GROUP BY gc.statutoryid
    ORDER BY total_usd DESC
");

// 7. Total Stop Order Deductions
$stoporders_total_q = safe_query($conn, "
    SELECT 
        COALESCE(SUM(dt.sold_bales), 0) as count_deducted,
        COALESCE(SUM(bb.stoporder_processed), 0) as total_usd
    FROM balanced_bales bb
    LEFT JOIN daily_totals dt ON dt.balanced_balesid = bb.id
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g ON g.id = tg.growerid
    WHERE g.selling_pointid = $spid AND (YEAR(bb.sell_date) = $yr OR YEAR(bb.created_at) = $yr) AND bb.stoporder_processed > 0
");
$stoporder_total_val = (float)($stoporders_total_q[0]['total_usd'] ?? 0);

include "includes/layout.php";
?>
<style>
.bi-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1.25rem; margin-bottom:1.5rem; }
.bi-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; display:flex; flex-direction:column; }
.bi-card-head { padding:0.85rem 1.15rem; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
.bi-card-head h3 { font-size:0.92rem; margin:0; font-weight:600; color:var(--text-strong); }
.bi-card-body { padding:1.15rem; flex:1; }
.calc-input-group { background:rgba(200,149,42,0.04); border:1px dashed var(--gold); border-radius:8px; padding:1rem; margin-bottom:1rem; }
.calc-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem; font-size:0.85rem; }
.calc-row:last-child { margin-bottom:0; border-top:1px solid var(--border); padding-top:0.75rem; font-weight:600; }
.input-number-percent { width:80px; padding:4px 8px; border:1px solid var(--border); border-radius:4px; text-align:right; font-family:monospace; outline:none; background:var(--surface); color:var(--text); }
.input-number-percent:focus { border-color:var(--gold); }
.text-right { text-align:right; }
.td-mono { font-family:monospace; }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>💼 Business Management &amp; Intelligence</h2>
    <p>Comprehensive crop value aggregation, floor commission tracking, and dynamic incentive calculators.</p>
  </div>
</div>

<!-- BI KPI Strip -->
<div class="bi-kpi-grid">
  <div class="kpi-card" style="--kpi-color:var(--gold)">
    <div class="kpi-label">Gross Auction Turnover</div>
    <div class="kpi-value">$<?=number_format($gross_usd, 2)?></div>
    <div class="kpi-sub"><?=number_format($stats['total_bales'])?> bales sold YTD</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)">
    <div class="kpi-label">Est. Standard Commission (2.5%)</div>
    <div class="kpi-value">$<?=number_format($gross_usd * 0.025, 2)?></div>
    <div class="kpi-sub">Floor auction facilitation fee</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--indigo)">
    <div class="kpi-label">Total Volume Traded</div>
    <div class="kpi-value"><?=number_format($total_tonnage, 2)?> t</div>
    <div class="kpi-sub"><?=number_format($stats['total_mass_kg'], 2)?> kg total mass</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--danger)">
    <div class="kpi-label">Floor Rejection Rate</div>
    <div class="kpi-value"><?=number_format($rejection_rate, 2)?>%</div>
    <div class="kpi-sub"><?=number_format($total_rejections)?> rejected bales</div>
  </div>
</div>

<div class="grid-2" style="gap:1.5rem; margin-bottom:1.5rem;">
  <!-- Chart: Revenue and Commission -->
  <div class="bi-card">
    <div class="bi-card-head">
      <h3>📈 Revenue &amp; Commission Yield Trends</h3>
    </div>
    <div class="bi-card-body">
      <div style="position:relative; height:280px;">
        <canvas id="revenueChart"></canvas>
      </div>
    </div>
  </div>

  <!-- Offtaker Commission Calculator Card -->
  <div class="bi-card">
    <div class="bi-card-head">
      <h3>💰 Offtaker Commission Incentive Calculator</h3>
    </div>
    <div class="bi-card-body">
      <p style="font-size:0.78rem; color:var(--muted); margin-bottom:1rem;">
        An incentive earned by the selling point based on the total tonnage sold multiplied by a commission percentage or flat rate.
      </p>

      <div class="calc-input-group">
        <div class="calc-row">
          <span>Calculation Mode:</span>
          <select id="calcMode" class="form-control" style="width:170px; padding:3px 6px; font-size:0.8rem;" onchange="recalculateOfftaker()">
            <option value="cents_per_kg">Cents per Kilogram (c/kg)</option>
            <option value="percentage">Percentage of Sales Value</option>
            <option value="flat">Flat USD Rate per Tonne</option>
          </select>
        </div>
        <div class="calc-row" id="centsRow">
          <span>Commission Rate (cents/kg):</span>
          <div>
            <input type="number" step="0.0001" id="centsRate" class="input-number-percent" 
                   value="<?=number_format($offtaker_rate_default, 4)?>" oninput="recalculateOfftaker()"> ¢
          </div>
        </div>
        <div class="calc-row" id="pctRow" style="display:none;">
          <span>Commission Percentage:</span>
          <div>
            <input type="number" step="0.01" id="commPct" class="input-number-percent" 
                   value="1.50" oninput="recalculateOfftaker()"> %
          </div>
        </div>
        <div class="calc-row" id="flatRow" style="display:none;">
          <span>USD Rate per Tonne:</span>
          <div>
            $ <input type="number" step="0.50" id="flatRate" class="input-number-percent" 
                     value="15.00" oninput="recalculateOfftaker()"> / t
          </div>
        </div>
      </div>

      <div style="background:var(--cream-d); border-radius:8px; padding:1rem; font-size:0.85rem;">
        <div class="calc-row">
          <span>Total Tonnage Sold:</span>
          <span class="td-mono"><?=number_format($total_tonnage, 3)?> Tonnes</span>
        </div>
        <div class="calc-row">
          <span>Gross Sales Turnover:</span>
          <span class="td-mono">$<?=number_format($gross_usd, 2)?></span>
        </div>
        <div class="calc-row">
          <span>Offtaker Commission Incentive:</span>
          <span id="offtakerResult" class="td-mono text-success" style="font-size:1.1rem; font-weight:700;">$0.00</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="grid-2" style="gap:1.5rem; margin-bottom:1.5rem;">
  <!-- Statutory Charges Summary Table Card -->
  <div class="bi-card">
    <div class="bi-card-head">
      <h3>📋 Statutory Charges &amp; Deductions Summary</h3>
    </div>
    <div class="bi-card-body" style="padding:0; overflow-x:auto;">
      <table style="width:100%; border-collapse:collapse; font-size:0.8rem;">
        <thead>
          <tr style="background:var(--cream-d); border-bottom:1px solid var(--border);">
            <th style="padding:0.65rem 1rem; text-align:left;">Charge Description</th>
            <th style="padding:0.65rem 1rem; text-align:right;">Bales Charged</th>
            <th style="padding:0.65rem 1rem; text-align:right;">Total Deducted (USD)</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $grand_charges_total = 0;
          if (empty($statutory_charges) && $stoporder_total_val <= 0): ?>
            <tr><td colspan="3" style="padding:1.5rem; text-align:center; color:var(--muted);">No deductions or charges recorded.</td></tr>
          <?php else: ?>
            <?php foreach ($statutory_charges as $sc): 
              $grand_charges_total += (float)$sc['total_usd'];
            ?>
              <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:0.55rem 1rem; font-weight:500;"><?=htmlspecialchars($sc['charge_name'])?></td>
                <td style="padding:0.55rem 1rem; text-align:right;" class="td-mono"><?=number_format($sc['count_deducted'])?></td>
                <td style="padding:0.55rem 1rem; text-align:right;" class="td-mono">$<?=number_format($sc['total_usd'], 2)?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($stoporder_total_val > 0): 
              $grand_charges_total += $stoporder_total_val;
            ?>
              <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:0.55rem 1rem; font-weight:500;">Grower Stop Orders (Loans/Recovery)</td>
                <td style="padding:0.55rem 1rem; text-align:right;" class="td-mono"><?=number_format($stoporders_total_q[0]['count_deducted'])?></td>
                <td style="padding:0.55rem 1rem; text-align:right;" class="td-mono">$<?=number_format($stoporder_total_val, 2)?></td>
              </tr>
            <?php endif; ?>
            <tr style="background:var(--cream-d); font-weight:600;">
              <td style="padding:0.65rem 1rem;">Total Charges &amp; Deductions</td>
              <td style="padding:0.65rem 1rem;"></td>
              <td style="padding:0.65rem 1rem; text-align:right;" class="td-mono">$<?=number_format($grand_charges_total, 2)?></td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Revenues & Earnings Visualization Comparison Card -->
  <div class="bi-card">
    <div class="bi-card-head">
      <h3>📊 Revenues &amp; Earnings Comparison</h3>
    </div>
    <div class="bi-card-body">
      <div class="calc-row" style="margin-bottom:0.85rem;">
        <span>Standard Floor Commission (2.5%):</span>
        <span class="td-mono">$<?=number_format($gross_usd * 0.025, 2)?></span>
      </div>
      <div class="calc-row" style="margin-bottom:0.85rem;">
        <span>Offtaker Commission:</span>
        <span class="td-mono text-success" id="illustrationOfftakerVal">$0.00</span>
      </div>
      <div class="calc-row" style="border-top:1px solid var(--border); padding-top:0.85rem; font-weight:700; margin-bottom:1.25rem;">
        <span>Total Estimated Floor Revenue:</span>
        <span class="td-mono text-primary" id="illustrationTotalRevVal">$0.00</span>
      </div>

      <!-- Financial Comparison Bar Visualization -->
      <h4 style="font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--muted); margin-bottom:0.75rem;">Revenues vs. Charges Balance</h4>
      <div style="display:flex; flex-direction:column; gap:0.75rem;">
        <div>
          <div style="display:flex; justify-content:space-between; font-size:0.72rem; margin-bottom:0.25rem;">
            <span>Total Floor Revenue</span>
            <span id="progressFloorRevText">$0.00</span>
          </div>
          <div style="height:10px; background:var(--border); border-radius:5px; overflow:hidden;">
            <div id="progressFloorRevBar" style="height:100%; width:0%; background:var(--leaf); transition: width 0.3s ease;"></div>
          </div>
        </div>

        <div>
          <div style="display:flex; justify-content:space-between; font-size:0.72rem; margin-bottom:0.25rem;">
            <span>Total Grower Charges &amp; Deductions</span>
            <span>$<?=number_format($grand_charges_total, 2)?></span>
          </div>
          <div style="height:10px; background:var(--border); border-radius:5px; overflow:hidden;">
            <div id="progressChargesBar" style="height:100%; width:0%; background:var(--danger); transition: width 0.3s ease;"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="bi-card">
  <div class="bi-card-head">
    <h3>🛒 Buyer Purchasing Concentration &amp; Market Share</h3>
  </div>
  <div class="bi-card-body" style="padding:0; overflow-x:auto;">
    <table style="width:100%; border-collapse:collapse; font-size:0.8rem;">
      <thead>
        <tr style="background:var(--cream-d); border-bottom:1px solid var(--border);">
          <th style="padding:0.75rem 1rem; text-align:left;">Buyer Name</th>
          <th style="padding:0.75rem 1rem; text-align:left;">Code</th>
          <th style="padding:0.75rem 1rem; text-align:right;">Bales Bought</th>
          <th style="padding:0.75rem 1rem; text-align:right;">Weight (kg)</th>
          <th style="padding:0.75rem 1rem; text-align:right;">Total Spend (USD)</th>
          <th style="padding:0.75rem 1rem; text-align:right;">Avg Price ($/kg)</th>
          <th style="padding:0.75rem 1rem; text-align:left;">Market Share</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($buyer_shares)): ?>
          <tr><td colspan="7" style="padding:1.5rem; text-align:center; color:var(--muted);">No buyer sales recorded for this season.</td></tr>
        <?php else: ?>
          <?php 
          $grand_usd = array_sum(array_column($buyer_shares, 'total_usd')) ?: 1;
          foreach ($buyer_shares as $b): 
              $share_pct = ($b['total_usd'] / $grand_usd) * 100;
          ?>
            <tr style="border-bottom:1px solid var(--border);">
              <td style="padding:0.65rem 1rem; font-weight:500;"><?=htmlspecialchars($b['buyer_name'])?></td>
              <td style="padding:0.65rem 1rem;"><?=htmlspecialchars($b['buyer_code'])?></td>
              <td style="padding:0.65rem 1rem; text-align:right;" class="td-mono"><?=number_format($b['bales_bought'])?></td>
              <td style="padding:0.65rem 1rem; text-align:right;" class="td-mono"><?=number_format($b['total_mass_kg'], 2)?> kg</td>
              <td style="padding:0.65rem 1rem; text-align:right;" class="td-mono fw-500">$<?=number_format($b['total_usd'], 2)?></td>
              <td style="padding:0.65rem 1rem; text-align:right;" class="td-mono">$<?=number_format($b['avg_price_kg'], 2)?></td>
              <td style="padding:0.65rem 1rem;">
                <div style="display:flex; align-items:center; gap:0.5rem;">
                  <div style="flex:1; height:6px; background:var(--border); border-radius:3px; max-width:80px;">
                    <div style="height:6px; width:<?=$share_pct?>%; background:var(--gold); border-radius:3px;"></div>
                  </div>
                  <span><?=number_format($share_pct, 1)?>%</span>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
var totalMassKg = <?=json_encode((float)$stats['total_mass_kg'])?>;
var totalTonnage = <?=json_encode($total_tonnage)?>;
var grossUsd = <?=json_encode($gross_usd)?>;

function recalculateOfftaker() {
    var mode = document.getElementById('calcMode').value;
    var result = 0;

    // Toggle inputs visibility
    document.getElementById('centsRow').style.display = mode === 'cents_per_kg' ? 'flex' : 'none';
    document.getElementById('pctRow').style.display = mode === 'percentage' ? 'flex' : 'none';
    document.getElementById('flatRow').style.display = mode === 'flat' ? 'flex' : 'none';

    if (mode === 'cents_per_kg') {
        var cents = parseFloat(document.getElementById('centsRate').value) || 0;
        result = totalMassKg * (cents / 100);
    } else if (mode === 'percentage') {
        var pct = parseFloat(document.getElementById('commPct').value) || 0;
        result = grossUsd * (pct / 100);
    } else {
        var rate = parseFloat(document.getElementById('flatRate').value) || 0;
        result = totalTonnage * rate;
    }

    document.getElementById('offtakerResult').textContent = '$' + result.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    // Update illustration values dynamically
    var standardComm = grossUsd * 0.025;
    var totalFloorRevenue = standardComm + result;
    var grandChargesTotal = <?=json_encode($grand_charges_total)?>;

    document.getElementById('illustrationOfftakerVal').textContent = '$' + result.toLocaleString(undefined, {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
    document.getElementById('illustrationTotalRevVal').textContent = '$' + totalFloorRevenue.toLocaleString(undefined, {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
    document.getElementById('progressFloorRevText').textContent = '$' + totalFloorRevenue.toLocaleString(undefined, {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });

    // Update comparative progress bars
    var maxVal = Math.max(totalFloorRevenue, grandChargesTotal, 1);
    var floorRevPct = (totalFloorRevenue / maxVal) * 100;
    var chargesPct = (grandChargesTotal / maxVal) * 100;

    document.getElementById('progressFloorRevBar').style.width = floorRevPct + '%';
    document.getElementById('progressChargesBar').style.width = chargesPct + '%';
}

// Initial calculation run
window.addEventListener('DOMContentLoaded', function() {
    recalculateOfftaker();

    if (typeof Chart !== 'undefined') {
        var months = <?=json_encode($chart_months)?>;
        var turnover = <?=json_encode($chart_turnover)?>;
        var commission = <?=json_encode($chart_commissions)?>;

        new Chart(document.getElementById('revenueChart'), {
            type: 'bar',
            data: {
                labels: months,
                datasets: [
                    {
                        label: 'Gross Turnover (USD)',
                        data: turnover,
                        backgroundColor: 'rgba(200, 149, 42, 0.7)',
                        borderColor: '#c8952a',
                        borderWidth: 1,
                        borderRadius: 4
                    },
                    {
                        label: 'Standard Commission (2.5%)',
                        data: commission,
                        type: 'line',
                        borderColor: '#3d6b35',
                        backgroundColor: 'rgba(61, 107, 53, 0.1)',
                        borderWidth: 2,
                        tension: 0.35,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 10 } }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { 
                        ticks: {
                            callback: function(value) { return '$' + value.toLocaleString(); }
                        },
                        grid: { color: 'rgba(128, 128, 128, 0.08)' }
                    }
                }
            }
        });
    }
});
</script>

<?php include "includes/layout_end.php"; ?>
