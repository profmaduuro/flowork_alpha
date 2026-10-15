<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "pay_schema.php";
require_login();
$page_title = "Payment Dashboard"; $active_page = "pay_dashboard";
$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
pay_schema($conn, $spid);
$my_role = pay_role($conn, $userid);

$today    = date('Y-m-d');
$date_f   = $conn->real_escape_string($_GET['date'] ?? $today);
$month_s  = date('Y-m-01');

// ── Is today closed? ──────────────────────────────────────────────────────
$day_closed = $conn->query("SELECT id FROM payment_day_close WHERE sell_date='$date_f' AND selling_pointid=$spid LIMIT 1")->num_rows > 0;

// ── Reconciliation figures ────────────────────────────────────────────────
$er  = $conn->query("SELECT amount FROM exchange_rate ORDER BY id DESC LIMIT 1")->fetch_assoc();
$zig = (float)($er['amount'] ?? 0);

// Total payable (all settled bales today)
$payable = $conn->query("
    SELECT COUNT(DISTINCT tg.id) growers,
           COALESCE(SUM(dtn.usd_net_value),0) net_usd,
           COALESCE(SUM(dtn.zim_net_value),0) net_zig
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
    WHERE g.selling_pointid=$spid AND DATE(bb.created_at)='$date_f' AND bb.stoporder_processed=1
")->fetch_assoc();

// Paid today (USD)
$paid_today = $conn->query("
    SELECT COUNT(*) cnt, COALESCE(SUM(ps.net_amount),0) total
    FROM payment_status ps
    JOIN transporter_growers tg ON tg.id=ps.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    WHERE g.selling_pointid=$spid AND ps.sell_date='$date_f'
      AND ps.currency='usd' AND ps.status IN ('paid','released')
")->fetch_assoc();

// Pending (not yet paid)
$pending_total = max(0, (float)$payable['net_usd'] - (float)$paid_today['total']);
$pending_growers = max(0, (int)$payable['growers'] - (int)$paid_today['cnt']);

// By method (today)
$by_method = $conn->query("
    SELECT ps.payment_method, COUNT(*) cnt, SUM(ps.net_amount) total
    FROM payment_status ps
    JOIN transporter_growers tg ON tg.id=ps.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    WHERE g.selling_pointid=$spid AND ps.sell_date='$date_f'
      AND ps.status IN ('paid','released') AND ps.currency='usd'
    GROUP BY ps.payment_method ORDER BY total DESC
")->fetch_all(MYSQLI_ASSOC);

// Batch status summary
$batch_summary = $conn->query("
    SELECT pb.status, COUNT(*) cnt, SUM(pbi_t.total) total
    FROM payment_batch pb
    LEFT JOIN (SELECT payment_batchid, SUM(net_amount) total FROM payment_batch_item GROUP BY payment_batchid) pbi_t ON pbi_t.payment_batchid=pb.id
    WHERE pb.sell_date='$date_f'
    GROUP BY pb.status
")->fetch_all(MYSQLI_ASSOC);
$batch_map = [];
foreach ($batch_summary as $b) $batch_map[$b['status']] = $b;

// Risk flags
$risk_flags = $conn->query("
    SELECT ps.risk_flags, g.grower_num, CONCAT(g.name,' ',g.surname) grower_name, ps.net_amount
    FROM payment_status ps
    JOIN transporter_growers tg ON tg.id=ps.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    WHERE g.selling_pointid=$spid AND ps.sell_date='$date_f' AND ps.risk_flags != ''
    ORDER BY ps.id DESC LIMIT 20
")->fetch_all(MYSQLI_ASSOC);

// Month totals
$month_totals = $conn->query("
    SELECT COALESCE(SUM(ps.net_amount),0) paid_usd, COUNT(DISTINCT ps.sell_date) days,
           COUNT(*) growers
    FROM payment_status ps
    JOIN transporter_growers tg ON tg.id=ps.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    WHERE g.selling_pointid=$spid AND ps.sell_date BETWEEN '$month_s' AND '$today'
      AND ps.currency='usd' AND ps.status IN ('paid','released')
")->fetch_assoc();

// Payment forecasting (pending from previous dates)
$overdue = $conn->query("
    SELECT COUNT(DISTINCT tg.id) growers, COALESCE(SUM(dtn.usd_net_value),0) net_usd,
           MIN(DATE(bb.created_at)) earliest_date
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
    LEFT JOIN payment_status ps ON ps.transporter_growersid=tg.id AND ps.sell_date=DATE(bb.created_at) AND ps.currency='usd'
    WHERE g.selling_pointid=$spid AND DATE(bb.created_at) < '$date_f'
      AND bb.stoporder_processed=1
      AND (ps.id IS NULL OR ps.status NOT IN ('paid','released'))
")->fetch_assoc();

// Performance: avg time to pay (days from sale to payment)
$avg_days = $conn->query("
    SELECT AVG(DATEDIFF(ps.paid_at, ps.sell_date)) avg_days
    FROM payment_status ps
    JOIN transporter_growers tg ON tg.id=ps.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    WHERE g.selling_pointid=$spid AND ps.currency='usd'
      AND ps.status IN ('paid','released') AND ps.paid_at IS NOT NULL
      AND ps.sell_date >= '$month_s'
")->fetch_assoc();


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

.pay-tab-panel {
    display: none;
}
.pay-tab-panel.active {
    display: block;
    animation: payDashFadeIn 0.25s ease-in-out;
}

@keyframes payDashFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.pay-centered-container {
    max-width: 900px;
    margin: 0 auto;
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Payment Dashboard</h2>
    <p><?=date('l d F Y',strtotime($date_f))?> &nbsp;·&nbsp;
       <span class="badge <?=$day_closed?'badge-red':'badge-green'?>" style="font-size:.65rem">
         <?=$day_closed?'Day Closed':'Day Open'?>
       </span>
    </p>
  </div>
  <div class="flex gap-2">
    <form method="GET" style="display:flex;gap:.4rem;align-items:flex-end">
      <input type="date" name="date" class="form-control" value="<?=htmlspecialchars((string)$date_f)?>" style="padding:.35rem .6rem" onchange="this.form.submit()">
    </form>
    <?php if(pay_can($my_role,'close_day') && !$day_closed): ?>
    <a href="pay_close.php?date=<?=htmlspecialchars((string)$date_f)?>" class="btn btn-ghost">🔒 Close Day</a>
    <?php endif; ?>
    <a href="pay_batches.php?date=<?=htmlspecialchars((string)$date_f)?>" class="btn btn-primary">Payment Batches</a>
  </div>
</div>

<?php if($day_closed): ?>
<div class="alert alert-gold" style="margin-bottom:1.25rem">
  🔒 Payment day is <strong>closed</strong> — records are locked. Contact admin to make changes.
</div>
<?php endif; ?>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
  <button class="premium-tab-btn active" data-tab="overview" onclick="switchPayDashTab('overview')">
      📊 Daily Overview
  </button>
  <button class="premium-tab-btn" data-tab="alerts" onclick="switchPayDashTab('alerts')">
      🚨 Alerts &amp; Risk
      <?php $total_alerts = (empty($risk_flags)?0:count($risk_flags)) + (((float)$overdue['net_usd'] > 0)?1:0); ?>
      <?php if($total_alerts > 0): ?>
      <span class="badge badge-red" style="font-size:.62rem; margin-left:4px"><?=$total_alerts?></span>
      <?php endif; ?>
  </button>
  <button class="premium-tab-btn" data-tab="actions" onclick="switchPayDashTab('actions')">
      ⚙ Quick Actions
  </button>
</div>

<!-- Tab Panel 1: Daily Overview -->
<div id="panel-overview" class="pay-tab-panel active">
  <!-- Recon KPIs -->
  <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
    <div class="kpi-card" style="--kpi-color:var(--gold)">
      <div class="kpi-label">Total Payable USD</div>
      <div class="kpi-value sm">$<?=number_format((float)$payable['net_usd'],2)?></div>
      <div class="kpi-sub"><?=(int)$payable['growers']?> growers</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--leaf)">
      <div class="kpi-label">Paid Today USD</div>
      <div class="kpi-value sm">$<?=number_format((float)$paid_today['total'],2)?></div>
      <div class="kpi-sub"><?=(int)$paid_today['cnt']?> growers</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
      <div class="kpi-label">Pending USD</div>
      <div class="kpi-value sm">$<?=number_format($pending_total,2)?></div>
      <div class="kpi-sub"><?=$pending_growers?> growers outstanding</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Month Paid (USD)</div>
      <div class="kpi-value sm">$<?=number_format((float)$month_totals['paid_usd'],0)?></div>
      <div class="kpi-sub"><?=$month_totals['days']?> pay days this month</div>
    </div>
  </div>

  <div class="grid-2 mb-3">
    <!-- Payments by Method -->
    <div class="card">
      <div class="card-head"><h3>Payments by Method</h3>
        <span class="badge badge-gray"><?=date('d M Y',strtotime($date_f))?></span>
      </div>
      <div class="card-body" style="padding:.75rem 1.15rem">
        <?php if(!empty($by_method)): ?>
          <?php $max_m = max(array_column($by_method,'total')) ?: 1; ?>
          <?php foreach($by_method as $m): $pct=round((float)$m['total']/$max_m*100); ?>
            <div style="margin-bottom:.65rem">
              <div style="display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:3px">
                <span class="fw-500"><?=htmlspecialchars($m['payment_method'])?></span>
                <span>$<?=number_format((float)$m['total'],2)?> &nbsp;·&nbsp; <?=$m['cnt']?> grower<?=$m['cnt']!=1?'s':''?></span>
              </div>
              <div style="height:7px;background:var(--border);border-radius:4px">
                <div style="width:<?=$pct?>%;height:7px;background:var(--gold);border-radius:4px"></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="text-center text-muted" style="padding:1.5rem">No payments made yet today.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Batch Status -->
    <div class="card">
      <div class="card-head"><h3>Batch Status</h3>
        <a href="pay_batches.php?date=<?=htmlspecialchars((string)$date_f)?>" class="btn btn-ghost btn-sm">View all</a>
      </div>
      <div class="card-body" style="padding:.75rem 1.15rem">
        <?php
        $batch_statuses = ['draft'=>['Draft','badge-gray'],'reviewed'=>['Reviewed','badge-gold'],'approved'=>['Approved','badge-blue'],'released'=>['Released','badge-green'],'locked'=>['Locked','badge-red']];
        foreach ($batch_statuses as $st=>[$label,$cls]):
          $b = $batch_map[$st] ?? null;
        ?>
          <div style="display:flex;align-items:center;gap:.75rem;padding:.45rem 0;border-bottom:1px solid var(--border)">
            <span class="badge <?=$cls?>" style="font-size:.65rem;min-width:70px;text-align:center"><?=$label?></span>
            <?php if($b): ?>
              <span style="font-size:.82rem;font-weight:500"><?=$b['cnt']?> batch<?=$b['cnt']!=1?'es':''?></span>
              <span class="td-muted" style="margin-left:auto;font-size:.78rem">$<?=number_format((float)$b['total'],2)?></span>
            <?php else: ?>
              <span class="td-muted" style="font-size:.78rem">—</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div style="padding-top:.75rem">
          <a href="pay_batches.php?date=<?=htmlspecialchars((string)$date_f)?>&action=new" class="btn btn-primary btn-sm" style="width:100%;text-align:center">
            + New Payment Batch
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Performance analytics -->
  <div class="card">
    <div class="card-head"><h3>Payment Performance</h3>
      <span class="badge badge-gray">This month</span>
    </div>
    <div class="card-body" style="padding:.75rem 1.15rem">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;max-width:600px;margin:0 auto">
        <div style="background:var(--cream-d);border-radius:var(--radius);padding:.75rem;text-align:center">
          <div style="font-size:.65rem;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:4px">Avg Days to Pay</div>
          <div style="font-size:1.6rem;font-family:'Bebas Neue',sans-serif;letter-spacing:.04em"><?=round((float)($avg_days['avg_days']??0),1)?></div>
          <div style="font-size:.68rem;color:var(--muted)">days from sale</div>
        </div>
        <div style="background:var(--cream-d);border-radius:var(--radius);padding:.75rem;text-align:center">
          <div style="font-size:.65rem;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:4px">Growers Paid</div>
          <div style="font-size:1.6rem;font-family:'Bebas Neue',sans-serif;letter-spacing:.04em"><?=(int)$month_totals['growers']?></div>
          <div style="font-size:.68rem;color:var(--muted)">this month</div>
        </div>
      </div>
      <div style="margin-top:1.5rem;display:flex;justify-content:center;gap:1rem;font-size:.78rem">
        <a href="pay_audit.php" class="btn btn-ghost btn-sm">📋 Full Audit Log</a>
        <a href="payments.php" class="btn btn-ghost btn-sm">Payment Register</a>
      </div>
    </div>
  </div>
</div>

<!-- Tab Panel 2: Alerts & Risk -->
<div id="panel-alerts" class="pay-tab-panel">
  <!-- Forecasting + Overdue -->
  <?php if((float)$overdue['net_usd'] > 0): ?>
    <div class="card mb-3" style="border-left:3px solid var(--danger)">
      <div class="card-head"><h3>⚠ Outstanding Payments (Previous Dates)</h3></div>
      <div class="card-body" style="padding:.75rem 1.15rem;display:flex;gap:1.5rem;flex-wrap:wrap;font-size:.82rem">
        <div><div class="kpi-label">Growers</div><div class="fw-500" style="font-size:1.1rem"><?=(int)$overdue['growers']?></div></div>
        <div><div class="kpi-label">Amount Outstanding</div><div class="fw-500 text-danger" style="font-size:1.1rem">$<?=number_format((float)$overdue['net_usd'],2)?></div></div>
        <div><div class="kpi-label">Earliest Unpaid Sale</div><div class="fw-500"><?=$overdue['earliest_date']?date('d M Y',strtotime($overdue['earliest_date'])):'—'?></div></div>
        <div style="margin-left:auto;align-self:center">
          <a href="payments.php?status=pending" class="btn btn-ghost">View Outstanding →</a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Risk flags -->
  <?php if(!empty($risk_flags)): ?>
    <div class="card mb-3" style="border-left:3px solid var(--danger)">
      <div class="card-head">
        <h3>🚨 Risk Alerts</h3>
        <span class="badge badge-red" style="font-size:.65rem"><?=count($risk_flags)?></span>
      </div>
      <div class="table-wrap"><table>
      <thead><tr><th>Grower</th><th>Risk Flags</th><th class="text-right">Amount</th></tr></thead>
      <tbody>
      <?php foreach($risk_flags as $rf): ?>
        <tr>
          <td><span class="badge badge-gold" style="font-size:.62rem"><?=htmlspecialchars($rf['grower_num'])?></span>
              <span style="font-size:.78rem;margin-left:4px"><?=htmlspecialchars($rf['grower_name'])?></span></td>
          <td>
            <?php foreach(explode(',', $rf['risk_flags']) as $f): $f=trim($f); if(!$f) continue; ?>
              <?php $flag_labels=['DUPLICATE_PAYMENT'=>['Duplicate payment','badge-red'],'BANK_ACCT_CHANGED_RECENTLY'=>['Bank account changed recently','badge-gold'],'AMOUNT_VARIANCE_GT_10PCT'=>['Amount variance >10%','badge-gold'],'NO_BANK_ACCOUNT'=>['No bank account','badge-gray']]; ?>
              <?php [$fl,$fc] = $flag_labels[$f] ?? [$f,'badge-gray']; ?>
              <span class="badge <?=$fc?>" style="font-size:.6rem;margin-right:3px"><?=$fl?></span>
            <?php endforeach; ?>
          </td>
          <td class="td-mono text-right fw-500">$<?=number_format((float)$rf['net_amount'],2)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      </table></div>
    </div>
  <?php endif; ?>

  <?php if(((float)$overdue['net_usd'] <= 0) && empty($risk_flags)): ?>
    <div class="card">
      <div class="card-body text-center text-muted" style="padding:3rem">
        <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem">🛡</div>
        No risk alerts or outstanding previous-date payments for today.
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Tab Panel 3: Quick Actions -->
<div id="panel-actions" class="pay-tab-panel">
  <div class="pay-centered-container" style="max-width: 500px;">
    <div class="card">
      <div class="card-head"><h3>Quick Actions</h3></div>
      <div class="card-body" style="padding:.75rem 1.15rem;display:flex;flex-direction:column;gap:.5rem">
        <a href="pay_batches.php?date=<?=htmlspecialchars((string)$date_f)?>&action=new" class="btn btn-primary" style="text-align:center">+ Create Payment Batch</a>
        <a href="payments.php?date=<?=htmlspecialchars((string)$date_f)?>" class="btn btn-ghost" style="text-align:center">📋 Payment Register</a>
        <a href="pay_batches.php?date=<?=htmlspecialchars((string)$date_f)?>" class="btn btn-ghost" style="text-align:center">📦 Manage Batches</a>
        <a href="pay_audit.php?date=<?=htmlspecialchars((string)$date_f)?>" class="btn btn-ghost" style="text-align:center">🔍 Audit Log</a>
        <?php if(pay_can($my_role,'close_day') && !$day_closed): ?>
          <a href="pay_close.php?date=<?=htmlspecialchars((string)$date_f)?>" class="btn btn-ghost" style="text-align:center;border-color:var(--danger);color:var(--danger)">🔒 Close Payment Day</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
function switchPayDashTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.pay-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_pay_dash_tab', tabId);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const defaultTab = sessionStorage.getItem('active_pay_dash_tab') || 'overview';
    switchPayDashTab(defaultTab);
});
</script>

<?php include "includes/layout_end.php"; ?>
