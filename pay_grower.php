<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "pay_schema.php";
require_login();
$page_title = "Grower Payment History"; $active_page = "pay_grower";
$userid = current_userid(); $spid = (int)($_SESSION['selling_pointid'] ?? 0);
pay_schema($conn, $spid);
$my_role = pay_role($conn, $userid);

// Detect optional grower_banking columns (may not exist on old installs)
$gb_cols = ['account_changed_at' => 'NULL', 'previous_account_num' => "''"];
$gb_sel_extra = '';
foreach ($gb_cols as $col => $default) {
    $ck = $conn->query("SHOW COLUMNS FROM grower_banking LIKE '$col'");
    if ($ck && $ck->num_rows > 0) {
        $gb_sel_extra .= ",gb.$col";
    } else {
        $gb_sel_extra .= ",$default AS $col";
        // Add the column for future use
        $type = $col === 'account_changed_at' ? 'TIMESTAMP NULL' : "VARCHAR(60) DEFAULT ''";
        @$conn->query("ALTER TABLE grower_banking ADD COLUMN $col $type");
    }
}

$search = $conn->real_escape_string(trim($_GET['q'] ?? ''));
$gid    = (int)($_GET['gid'] ?? 0);

// Find grower
$grower = null;
if ($gid) {
    $grower = $conn->query("SELECT g.*,gb.bank_name,gb.account_num,gb.account_name,gb.branch,gb.verified$gb_sel_extra FROM growers g LEFT JOIN grower_banking gb ON gb.growerid=g.id WHERE g.id=$gid AND g.selling_pointid=$spid LIMIT 1")->fetch_assoc();
}

// Search
$search_results = [];
if ($search && !$gid) {
    $sq = $conn->query("SELECT g.id,g.grower_num,g.name,g.surname FROM growers g WHERE g.selling_pointid=$spid AND (g.grower_num LIKE '%$search%' OR g.name LIKE '%$search%' OR g.surname LIKE '%$search%') LIMIT 20");
    $search_results = $sq ? $sq->fetch_all(MYSQLI_ASSOC) : [];
}

// Payment history for selected grower
$history = []; $history_totals = ['gross'=>0,'net'=>0,'paid'=>0,'pending'=>0];
if ($grower) {
    $hq = $conn->query("
        SELECT bb.id AS bbid, DATE(bb.created_at) sale_date,
               dt.usd_value gross_usd, dtn.usd_net_value net_usd,
               COALESCE(ps.status,'pending') status,
               ps.payment_method, ps.reference_number, ps.paid_at,
               ps.amount_paid, ps.risk_flags,
               dt.sold_bales, dt.mass
        FROM balanced_bales bb
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
        LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
        LEFT JOIN payment_status ps ON ps.transporter_growersid=tg.id AND ps.sell_date=DATE(bb.created_at) AND ps.currency='usd'
        WHERE tg.growerid=$gid AND bb.stoporder_processed=1
        ORDER BY bb.created_at DESC LIMIT 100
    ");
    while ($hq && $h = $hq->fetch_assoc()) {
        $h['gross'] = (float)($h['gross_usd']??0);
        $h['net']   = (float)($h['net_usd']??0);
        $history_totals['gross'] += $h['gross'];
        $history_totals['net']   += $h['net'];
        if (in_array($h['status'],['paid','released'])) $history_totals['paid'] += $h['net'];
        else $history_totals['pending'] += $h['net'];
        $history[] = $h;
    }
}
$status_colors=['pending'=>'badge-gray','partial'=>'badge-gold','reviewed'=>'badge-gold','approved'=>'badge-blue','released'=>'badge-green','paid'=>'badge-green','on_hold'=>'badge-red'];
include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Grower Payment History</h2>
  <?php if($grower):?><p><?=htmlspecialchars($grower['grower_num'].' — '.$grower['name'].' '.$grower['surname'])?></p><?php endif;?>
  </div>
  <a href="pay_dashboard.php" class="btn btn-ghost">← Dashboard</a>
</div>

<div class="card mb-3"><div class="card-body" style="padding:.65rem 1.15rem">
<form method="GET" style="display:flex;gap:.5rem;align-items:flex-end">
  <div class="form-group" style="margin:0;flex:1">
    <label style="font-size:.72rem">Search Grower</label>
    <input type="text" name="q" class="form-control" value="<?=htmlspecialchars($search)?>" placeholder="Grower # or name" style="padding:.35rem .6rem">
  </div>
  <button type="submit" class="btn btn-primary">Search</button>
  <?php if($gid):?><a href="pay_grower.php" class="btn btn-ghost">Clear</a><?php endif;?>
</form>
</div></div>

<?php if(!empty($search_results)): ?>
<div class="card mb-3"><div class="card-head"><h3>Search Results</h3></div>
<div class="table-wrap"><table>
<thead><tr><th>Grower #</th><th>Name</th><th></th></tr></thead><tbody>
<?php foreach($search_results as $r):?>
<tr>
  <td><span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($r['grower_num'])?></span></td>
  <td style="font-size:.82rem"><?=htmlspecialchars($r['name'].' '.$r['surname'])?></td>
  <td><a href="?gid=<?=$r['id']?>" class="btn btn-ghost btn-sm">View History</a></td>
</tr>
<?php endforeach;?>
</tbody></table></div></div>
<?php endif;?>

<?php if($grower):?>
<!-- Grower details + bank -->
<div class="grid-2 mb-3">
<div class="card">
<div class="card-head"><h3>Grower Details</h3></div>
<div class="card-body" style="padding:.75rem 1.15rem;font-size:.82rem">
<div class="form-row cols-2">
  <div><div class="kpi-label">Grower #</div><div class="fw-500"><?=htmlspecialchars($grower['grower_num'])?></div></div>
  <div><div class="kpi-label">Name</div><div class="fw-500"><?=htmlspecialchars($grower['name'].' '.$grower['surname'])?></div></div>
  <div><div class="kpi-label">Province</div><div><?=htmlspecialchars($grower['province']??'—')?></div></div>
  <div><div class="kpi-label">Phone</div><div><?=htmlspecialchars($grower['phone']??'—')?></div></div>
</div>
</div>
</div>
<div class="card">
<div class="card-head"><h3>Bank Account</h3>
  <?php if($grower['verified']):?>
  <span class="badge badge-green" style="font-size:.72rem">✓ Verified</span>
  <?php else:?>
  <span class="badge badge-red" style="font-size:.72rem">Unverified</span>
  <?php endif;?>
</div>
<div class="card-body" style="padding:.75rem 1.15rem;font-size:.82rem">
<?php if($grower['bank_name']??''):?>
<div class="form-row cols-2">
  <div><div class="kpi-label">Bank</div><div class="fw-500"><?=htmlspecialchars($grower['bank_name'])?></div></div>
  <div><div class="kpi-label">Account #</div><div class="td-mono"><?=htmlspecialchars($grower['account_num']??'—')?></div></div>
  <div><div class="kpi-label">Account Name</div><div><?=htmlspecialchars($grower['account_name']??'—')?></div></div>
  <div><div class="kpi-label">Branch</div><div><?=htmlspecialchars($grower['branch']??'—')?></div></div>
</div>
<?php if($grower['account_changed_at']):?>
<div style="margin-top:.5rem;padding:.4rem .6rem;background:rgba(192,57,43,.06);border-radius:var(--radius);font-size:.72rem;color:var(--danger)">
  ⚠ Account changed on <?=date('d M Y',strtotime($grower['account_changed_at']))?> (was <?=htmlspecialchars($grower['previous_account_num']??'unknown')?>)
</div>
<?php endif;?>
<?php else:?><div class="td-muted">No bank account recorded. <a href="banking.php" style="color:var(--gold)">Add banking details →</a></div><?php endif;?>
</div>
</div>
</div>

<!-- Payment totals -->
<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
  <div class="kpi-card"><div class="kpi-label">Sale Sessions</div><div class="kpi-value"><?=count($history)?></div></div>
  <div class="kpi-card" style="--kpi-color:var(--gold)"><div class="kpi-label">Gross USD</div><div class="kpi-value sm">$<?=number_format($history_totals['gross'],2)?></div></div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)"><div class="kpi-label">Total Paid</div><div class="kpi-value sm">$<?=number_format($history_totals['paid'],2)?></div></div>
  <div class="kpi-card" style="--kpi-color:var(--danger)"><div class="kpi-label">Outstanding</div><div class="kpi-value sm">$<?=number_format($history_totals['pending'],2)?></div></div>
</div>

<!-- History table -->
<div class="card">
<div class="card-head"><h3>Payment History</h3>
<span class="badge badge-gray"><?=count($history)?> sessions</span>
</div>
<div class="table-wrap"><table>
<thead><tr>
  <th>Sale Date</th><th class="text-right">Bales</th><th class="text-right">Mass</th>
  <th class="text-right">Gross USD</th><th class="text-right">Net USD</th>
  <th>Status</th><th>Method</th><th>Reference</th><th>Paid At</th><th>Risk</th>
</tr></thead><tbody>
<?php foreach($history as $h):
  $sc=$status_colors[$h['status']]??'badge-gray';
  $flags=array_filter(explode(',',$h['risk_flags']??''));
?>
<tr>
  <td class="fw-500" style="font-size:.82rem"><?=date('d M Y',strtotime($h['sale_date']))?></td>
  <td class="td-mono text-right"><?=(int)$h['sold_bales']?></td>
  <td class="td-mono text-right"><?=number_format((float)$h['mass'],0)?></td>
  <td class="td-mono text-right">$<?=number_format($h['gross'],2)?></td>
  <td class="td-mono text-right fw-500">$<?=number_format($h['net'],2)?></td>
  <td><span class="badge <?=$sc?>" style="font-size:.72rem"><?=ucfirst($h['status'])?></span></td>
  <td class="td-muted" style="font-size:.75rem"><?=htmlspecialchars($h['payment_method']??'—')?></td>
  <td class="td-mono" style="font-size:.72rem"><?=htmlspecialchars($h['reference_number']??'—')?></td>
  <td class="td-muted" style="font-size:.72rem"><?=$h['paid_at']?date('d M H:i',strtotime($h['paid_at'])):'—'?></td>
  <td><?php foreach($flags as $f): if(!trim($f)) continue; ?><span title="<?=htmlspecialchars(trim($f))?>" style="font-size:.9rem">⚠</span><?php endforeach;?></td>
</tr>
<?php endforeach;?>
</tbody>
<tfoot><tr style="background:var(--cream-d)">
  <td class="fw-500" colspan="3" style="padding:.45rem .9rem">TOTAL</td>
  <td class="td-mono text-right fw-500" style="padding:.45rem .9rem">$<?=number_format($history_totals['gross'],2)?></td>
  <td class="td-mono text-right fw-500" style="padding:.45rem .9rem">$<?=number_format($history_totals['net'],2)?></td>
  <td colspan="5"></td>
</tr></tfoot>
</table></div>
</div>
<?php endif;?>
<?php include "includes/layout_end.php"; ?>
