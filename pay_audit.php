<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "pay_schema.php";
require_login();
$page_title = "Payment Audit Log"; $active_page = "pay_audit";
$userid = current_userid(); $spid = (int)($_SESSION['selling_pointid'] ?? 0);
pay_schema($conn, $spid);
$my_role = pay_role($conn, $userid);
if (!pay_can($my_role,'view_audit')) { echo "<p>Access denied.</p>"; exit; }

$date_from = $conn->real_escape_string($_GET['from'] ?? date('Y-m-d'));
$date_to   = $conn->real_escape_string($_GET['to']   ?? date('Y-m-d'));
$filter_action = $conn->real_escape_string($_GET['action'] ?? '');
$filter_risk   = !empty($_GET['risk_only']);

$where = "DATE(pl.logged_at) BETWEEN '$date_from' AND '$date_to'";
if ($filter_action) $where .= " AND pl.action='$filter_action'";
// risk_flag filter applied after column detection below

// Detect which column exists: entity_id (new) or payment_statusid (legacy)
$col_check = $conn->query("SHOW COLUMNS FROM payment_log LIKE 'entity_id'");
$id_col = ($col_check && $col_check->num_rows > 0) ? 'pl.entity_id' : 'pl.payment_statusid';

// Also check entity_type column
$et_check = $conn->query("SHOW COLUMNS FROM payment_log LIKE 'entity_type'");
$has_entity_type = ($et_check && $et_check->num_rows > 0);
$et_expr = $has_entity_type ? "pl.entity_type" : "'payment_status'";

// risk_flag column check
$rf_check = $conn->query("SHOW COLUMNS FROM payment_log LIKE 'risk_flag'");
$has_risk = ($rf_check && $rf_check->num_rows > 0);
if ($filter_risk && !$has_risk) $filter_risk = false; // can't filter if col missing
if ($filter_risk && $has_risk) $where .= " AND pl.risk_flag != ''";
$rf_sel = $has_risk ? "pl.risk_flag" : "'' AS risk_flag";
$et_sel = $has_entity_type ? "pl.entity_type" : "'payment_status' AS entity_type";

$logs = $conn->query("
    SELECT pl.id, pl.userid, pl.action, pl.currency, pl.amount,
           pl.payment_method, pl.reference_number, pl.notes, pl.logged_at,
           $et_sel, $rf_sel,
           $id_col AS entity_id,
           u.username,
           CASE $et_expr
             WHEN 'payment_status' THEN CONCAT(COALESCE(g.grower_num,''),' — ',COALESCE(g.name,''),' ',COALESCE(g.surname,''))
             WHEN 'payment_batch'  THEN CONCAT('Batch #',$id_col)
             ELSE CONCAT('Log #',pl.id)
           END AS entity_label,
           g.grower_num
    FROM payment_log pl
    JOIN users u ON u.id=pl.userid
    LEFT JOIN payment_status ps ON ps.id=$id_col AND $et_expr='payment_status'
    LEFT JOIN transporter_growers tg ON tg.id=ps.transporter_growersid
    LEFT JOIN growers g ON g.id=tg.growerid
    WHERE $where
    ORDER BY pl.logged_at DESC LIMIT 500
")->fetch_all(MYSQLI_ASSOC);

$action_colors = ['paid'=>'badge-green','batch_paid'=>'badge-green','created'=>'badge-blue','review_batch'=>'badge-gold','approve_batch'=>'badge-blue','release_batch'=>'badge-green','lock_batch'=>'badge-red','cancelled'=>'badge-red'];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Payment Audit Log</h2><p>Full history of all payment actions</p></div>
  <a href="pay_dashboard.php" class="btn btn-ghost">← Dashboard</a>
</div>

<div class="card mb-3"><div class="card-body" style="padding:.65rem 1.15rem">
<form method="GET" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
  <div class="form-group" style="margin:0"><label style="font-size:.62rem">From</label>
  <input type="date" name="from" class="form-control" value="<?=htmlspecialchars((string)$date_from)?>" style="padding:.35rem .6rem"></div>
  <div class="form-group" style="margin:0"><label style="font-size:.62rem">To</label>
  <input type="date" name="to" class="form-control" value="<?=htmlspecialchars((string)$date_to)?>" style="padding:.35rem .6rem"></div>
  <div class="form-group" style="margin:0"><label style="font-size:.62rem">Action</label>
  <select name="action" class="form-control" style="padding:.35rem .6rem" onchange="this.form.submit()">
    <option value="">All actions</option>
    <?php foreach(['created','paid','batch_paid','review_batch','approve_batch','release_batch','lock_batch'] as $a):?>
    <option value="<?=$a?>" <?=$filter_action===$a?'selected':''?>><?=str_replace('_',' ',ucfirst($a))?></option>
    <?php endforeach;?>
  </select></div>
  <label style="font-size:.75rem;display:flex;align-items:center;gap:4px;cursor:pointer;padding-bottom:2px">
    <input type="checkbox" name="risk_only" value="1" <?=$filter_risk?'checked':''?> onchange="this.form.submit()"> Risk flags only
  </label>
  <button type="submit" class="btn btn-primary btn-sm">Apply</button>
</form>
</div></div>

<div class="card"><div class="card-head"><h3>Audit Log</h3><span class="badge badge-gray"><?=count($logs)?></span></div>
<div class="table-wrap" style="max-height:640px;overflow-y:auto"><table>
<thead><tr>
  <th>Time</th><th>User</th><th>Action</th><th>Entity</th>
  <th>Currency</th><th class="text-right">Amount</th>
  <th>Method</th><th>Reference</th><th>Risk Flag</th><th>Notes</th>
</tr></thead><tbody>
<?php if(!empty($logs)): foreach($logs as $l): $ac=$action_colors[$l['action']]??'badge-gray';?>
<tr style="<?=$l['risk_flag']?'background:rgba(192,57,43,.04)':''?>">
  <td class="td-mono" style="font-size:.72rem;white-space:nowrap"><?=date('d M H:i:s',strtotime($l['logged_at']))?></td>
  <td style="font-size:.78rem"><?=htmlspecialchars($l['username'])?></td>
  <td><span class="badge <?=$ac?>" style="font-size:.6rem"><?=str_replace('_',' ',$l['action'])?></span></td>
  <td style="font-size:.75rem"><?=htmlspecialchars($l['entity_label']??($l['entity_type'].'#'.$l['entity_id']))?></td>
  <td><span class="badge badge-gray" style="font-size:.6rem"><?=strtoupper($l['currency'])?></span></td>
  <td class="td-mono text-right"><?=$l['amount']>0?'$'.number_format((float)$l['amount'],4):'—'?></td>
  <td style="font-size:.72rem"><?=htmlspecialchars($l['payment_method']??'')?></td>
  <td class="td-mono" style="font-size:.72rem"><?=htmlspecialchars($l['reference_number']??'')?></td>
  <td><?php if($l['risk_flag']):?>
    <span class="badge badge-red" style="font-size:.58rem"><?=htmlspecialchars($l['risk_flag'])?></span>
    <?php endif;?></td>
  <td class="td-muted" style="font-size:.72rem"><?=htmlspecialchars($l['notes']??'')?></td>
</tr>
<?php endforeach; else:?>
<tr><td colspan="10" class="text-center text-muted" style="padding:2rem">No audit records in this period.</td></tr>
<?php endif;?>
</tbody></table></div></div>
<?php include "includes/layout_end.php"; ?>
