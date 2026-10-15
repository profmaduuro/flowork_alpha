<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title  = "Financial Forecast";
$active_page = "financial_forecast";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);

function ffSafe($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_assoc()?:[];}
function ffAll($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_all(MYSQLI_ASSOC)?:[];}
$sf = $spid ? "AND g.selling_pointid=$spid" : "";

// Exchange rate
$er = ffSafe($conn,"SELECT amount FROM exchange_rate ORDER BY id DESC LIMIT 1");
$zig_rate = (float)($er['amount'] ?? 0);

// Avg value per bale this season
$avg_val = ffSafe($conn,"SELECT COALESCE(AVG(dt.usd_value / GREATEST(dt.sold_bales,1)),0) avg_bale_val,
    COALESCE(AVG(dt.average_price),0) avg_price
    FROM daily_totals dt JOIN balanced_bales bb ON bb.id=dt.balanced_balesid
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid WHERE dt.sold_bales>0 $sf");

// Pending booked bales (not balanced yet)
$pending = ffSafe($conn,"SELECT COUNT(tg.id) growers, COALESCE(SUM(tg.bales),0) bales
    FROM transporter_growers tg JOIN growers g ON g.id=tg.growerid
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
    WHERE tg.booked=1 AND bb.id IS NULL $sf");

$proj_usd = (int)($pending['bales']??0) * (float)($avg_val['avg_bale_val']??0);
$proj_zig = $proj_usd * $zig_rate;

// Stop order liability
$so_liability = ffSafe($conn,"SELECT COALESCE(SUM(CASE WHEN so.amount_1>0 THEN so.amount_1 ELSE 0 END),0) fixed,
    COUNT(so.id) cnt
    FROM stoporders so JOIN growers g ON g.id=so.growerid
    WHERE so.active=1 AND so.processed=0 $sf");
$iso_liability = ffSafe($conn,"SELECT COALESCE(SUM(isa.amount),0) total, COUNT(isa.id) cnt
    FROM internal_stop_order_amount isa
    JOIN internal_stop_order iso ON iso.id=isa.internal_stop_orderid
    WHERE isa.active=1 AND isa.processed=0 ".($spid?"AND iso.selling_pointid=$spid":""));

// Commission projection (3% default on gross)
$comm_rate = 0.03;
$season_gross = ffSafe($conn,"SELECT COALESCE(SUM(dt.usd_value),0) u FROM daily_totals dt
    JOIN balanced_bales bb ON bb.id=dt.balanced_balesid
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid WHERE 1=1 $sf");
$season_comm = (float)($season_gross['u']??0) * $comm_rate;
$proj_comm = $proj_usd * $comm_rate;

// Weekly cash flow (last 8 weeks)
$weekly_flow = ffAll($conn,"SELECT YEARWEEK(bb.created_at,1) wk,
    MIN(DATE(bb.created_at)) week_start,
    COALESCE(SUM(dt.usd_value),0) gross,
    COALESCE(SUM(dtn.usd_net_value),0) net,
    COALESCE(SUM(dt.sold_bales),0) bales
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
    WHERE bb.created_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK) $sf
    GROUP BY wk ORDER BY wk ASC");

// Deductions breakdown this season
$deductions = ffAll($conn,"SELECT s.description charge_name,
    COALESCE(SUM(bbc.usd_value),0) total_usd
    FROM balanced_bales_charges bbc
    JOIN grower_charges gc ON gc.id=bbc.grower_chargeid
    JOIN statutory s ON s.id=gc.statutoryid
    JOIN balanced_bales bb ON bb.id=bbc.balanced_balesid
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    WHERE 1=1 $sf
    GROUP BY s.id ORDER BY total_usd DESC");

include "includes/layout.php";
?>

<style>
.ff-proj-card { background:linear-gradient(135deg,rgba(200,149,42,.08),rgba(61,107,53,.05)); border:1px solid rgba(200,149,42,.2); border-radius:var(--radius); padding:1.25rem; }
body.theme-dark .ff-proj-card { background:linear-gradient(135deg,rgba(200,149,42,.1),rgba(61,107,53,.06)); }
.ff-proj-title { font-size:.6rem;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem; }
.ff-proj-val { font-family:'Bebas Neue',sans-serif;font-size:2.2rem;color:var(--gold);line-height:1; }
.ff-proj-sub { font-size:.72rem;color:var(--muted);margin-top:.25rem; }
.ff-liability { background:var(--danger-bg); border:1px solid #f5b8b2; border-radius:var(--radius); padding:1.25rem; }
.ff-liability .ff-proj-val { color:var(--danger); }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Financial Forecast</h2>
    <p>Cash flow projections, stop order liabilities, commission analysis</p>
  </div>
</div>

<!-- Projection Cards -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));margin-bottom:1.5rem">
  <div class="ff-proj-card">
    <div class="ff-proj-title">Projected Payout (Booked)</div>
    <div class="ff-proj-val">$<?=number_format($proj_usd,0)?></div>
    <div class="ff-proj-sub"><?=(int)($pending['bales']??0)?> bales × $<?=number_format((float)($avg_val['avg_bale_val']??0),2)?>/bale</div>
    <?php if($zig_rate>0): ?><div class="ff-proj-sub">≈ <?=number_format($proj_zig,0)?> ZiG</div><?php endif; ?>
  </div>
  <div class="ff-proj-card">
    <div class="ff-proj-title">Season Gross Revenue</div>
    <div class="ff-proj-val">$<?=number_format((float)($season_gross['u']??0),0)?></div>
    <div class="ff-proj-sub">all balanced batches</div>
  </div>
  <div class="ff-proj-card">
    <div class="ff-proj-title">Season Commission (<?=round($comm_rate*100)?>%)</div>
    <div class="ff-proj-val" style="color:var(--leaf)">$<?=number_format($season_comm,0)?></div>
    <div class="ff-proj-sub">+ $<?=number_format($proj_comm,0)?> projected</div>
  </div>
  <div class="ff-liability">
    <div class="ff-proj-title" style="color:var(--danger)">Stop Order Liability</div>
    <div class="ff-proj-val">$<?=number_format((float)($so_liability['fixed']??0),0)?></div>
    <div class="ff-proj-sub"><?=(int)($so_liability['cnt']??0)?> external SOs pending</div>
  </div>
  <div class="ff-liability">
    <div class="ff-proj-title" style="color:var(--danger)">Internal SO Liability</div>
    <div class="ff-proj-val">$<?=number_format((float)($iso_liability['total']??0),0)?></div>
    <div class="ff-proj-sub"><?=(int)($iso_liability['cnt']??0)?> internal SOs pending</div>
  </div>
</div>

<div class="premium-tabs-nav" data-tabs="forecast">
  <button type="button" class="premium-tab-btn active" data-tab="flow">📈 Cash Flow &amp; Deductions</button>
  <button type="button" class="premium-tab-btn" data-tab="summary">📋 Weekly Summary</button>
</div>

<div id="panel-flow" class="tab-panel active" data-tabs="forecast">
<div class="grid-2 mb-3">
  <!-- Weekly Cash Flow Chart -->
  <div class="card">
    <div class="card-head"><h3>📈 Weekly Cash Flow (8 Weeks)</h3></div>
    <div class="card-body"><canvas id="ff-weekly-chart" height="200"></canvas></div>
  </div>

  <!-- Deductions Breakdown -->
  <div class="card">
    <div class="card-head"><h3>💰 Deductions Breakdown</h3></div>
    <div class="card-body">
      <?php if(!empty($deductions)): ?>
      <?php $max_ded = max(array_column($deductions,'total_usd')?:[1]); ?>
      <?php foreach($deductions as $d):
        $pct = $max_ded > 0 ? ((float)$d['total_usd'] / $max_ded) * 100 : 0;
      ?>
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem">
        <div style="min-width:120px;font-size:.75rem;font-weight:500"><?=htmlspecialchars($d['charge_name'])?></div>
        <div style="flex:1;height:16px;background:var(--cream-d);border-radius:3px;overflow:hidden">
          <div style="width:<?=$pct?>%;height:100%;background:var(--gold);border-radius:3px;transition:width .4s"></div>
        </div>
        <div style="min-width:80px;text-align:right;font-size:.72rem;font-weight:500;color:var(--gold)">$<?=number_format((float)$d['total_usd'],0)?></div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div style="padding:2rem;text-align:center;color:var(--muted)">No deductions recorded yet</div>
      <?php endif; ?>
    </div>
  </div>
</div>

</div><!-- /panel-flow -->

<!-- Weekly Flow Table -->
<div id="panel-summary" class="tab-panel" data-tabs="forecast">
<div class="card">
  <div class="card-head"><h3>📋 Weekly Summary</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Week Starting</th><th class="text-right">Bales</th><th class="text-right">Gross USD</th><th class="text-right">Net USD</th><th class="text-right">Deductions</th></tr></thead>
      <tbody>
      <?php foreach($weekly_flow as $w):
        $ded = (float)$w['gross'] - (float)$w['net'];
      ?>
      <tr>
        <td><?=date('d M Y',strtotime($w['week_start']))?></td>
        <td class="td-mono text-right"><?=number_format((int)$w['bales'])?></td>
        <td class="td-mono text-right" style="font-weight:500">$<?=number_format((float)$w['gross'],2)?></td>
        <td class="td-mono text-right" style="color:var(--leaf)">$<?=number_format((float)$w['net'],2)?></td>
        <td class="td-mono text-right" style="color:var(--danger)">$<?=number_format($ded,2)?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($weekly_flow)): ?>
      <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:2rem">No weekly data yet</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div><!-- /panel-summary -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
const wk = <?=json_encode($weekly_flow)?>;
if (wk.length && document.getElementById('ff-weekly-chart')) {
    new Chart(document.getElementById('ff-weekly-chart'), {
        type: 'bar',
        data: {
            labels: wk.map(w => { const d=new Date(w.week_start); return d.getDate()+' '+d.toLocaleString('en',{month:'short'}); }),
            datasets: [
                { label:'Gross USD', data:wk.map(w=>parseFloat(w.gross)), backgroundColor:'rgba(200,149,42,0.5)', borderRadius:4 },
                { label:'Net USD', data:wk.map(w=>parseFloat(w.net)), backgroundColor:'rgba(61,107,53,0.5)', borderRadius:4 }
            ]
        },
        options: { responsive:true, plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:10}}}},
            scales:{ y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.04)'}}, x:{grid:{display:false}} } }
    });
}
</script>

<?php include "includes/layout_end.php"; ?>
