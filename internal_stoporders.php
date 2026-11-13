<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "Internal Stop Orders";
$active_page = "internal_stoporders";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();
$seasonid    = (int)($_SESSION['seasonid'] ?? 0);
$today       = date('Y-m-d');
$now         = date('Y-m-d H:i:s');
$msg = $err  = '';

// ── Simple table — no FK constraints, direct grower link ──────────────────
$conn->query("CREATE TABLE IF NOT EXISTS internal_stoporders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    growerid INT NOT NULL,
    transporter_growersid INT,
    charge_type VARCHAR(60) NOT NULL DEFAULT 'other',
    description VARCHAR(200) NOT NULL DEFAULT '',
    amount DOUBLE NOT NULL DEFAULT 0,
    sell_date DATE NOT NULL,
    splitid INT DEFAULT 1,
    active INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_single') {
        $gid       = (int)($_POST['growerid'] ?? 0);
        $amt       = (float)($_POST['amount'] ?? 0);
        $charge    = $conn->real_escape_string(trim($_POST['charge_type'] ?? 'other'));
        $desc      = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $sd        = $conn->real_escape_string($_POST['sell_date'] ?? $today);

        if (!$gid || $amt <= 0) {
            $err = "Grower and amount are required.";
        } else {
            // Find grower's active transporter_growers record
            $tg_q = $conn->query("SELECT id, COALESCE(splitid,1) splitid
                FROM transporter_growers WHERE growerid=$gid
                ORDER BY id DESC LIMIT 1");
            $tgid    = 'NULL';
            $splitid = 1;
            if ($tg_q && $tg_q !== true && $tg_q->num_rows > 0) {
                $tg      = $tg_q->fetch_assoc();
                $tgid    = (int)$tg['id'];
                $splitid = max(1, (int)$tg['splitid']);
            }

            $conn->query("INSERT INTO internal_stoporders
                (userid, growerid, transporter_growersid, charge_type, description, amount, sell_date, splitid, active)
                VALUES ($userid, $gid, $tgid, '$charge', '$desc', $amt, '$sd', $splitid, 1)");

            if ($conn->insert_id) {
                audit_log($conn, $spid, $userid, 'internal_stoporders', 'add', (int)$conn->insert_id,
                    null, ['growerid'=>$gid,'amount'=>$amt]);
                $msg = "✓ Stop order of $".number_format($amt,2)." added.";
            } else {
                $err = "Save failed: ".$conn->error;
            }
        }
    }

    if ($action === 'bulk_import') {
        $charge   = $conn->real_escape_string(trim($_POST['charge_type'] ?? 'other'));
        $desc     = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $sd       = $conn->real_escape_string($_POST['sell_date'] ?? $today);
        $file     = $_FILES['csv_file'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) { $err = "Upload error."; }
        else {
            $handle = fopen($file['tmp_name'], 'r');
            $hdr    = array_map('strtolower', array_map('trim', fgetcsv($handle)));
            $imp = $skip = 0;
            while (($row = fgetcsv($handle)) !== false) {
                $d    = array_combine(array_slice($hdr,0,count($row)), array_slice($row,0,count($hdr)));
                $gnum = strtoupper(trim($d['grower_num'] ?? $d['grower'] ?? ''));
                $amt  = (float)($d['amount'] ?? 0);
                $rdesc= $conn->real_escape_string(trim($d['description'] ?? $desc));
                if (!$gnum || $amt <= 0) { $skip++; continue; }

                $gn  = $conn->real_escape_string($gnum);
                $gr  = $conn->query("SELECT id FROM growers WHERE grower_num='$gn' AND selling_pointid=$spid LIMIT 1");
                if (!$gr || $gr->num_rows === 0) { $skip++; continue; }
                $gid = (int)$gr->fetch_assoc()['id'];

                $tg_q = $conn->query("SELECT id, COALESCE(splitid,1) splitid FROM transporter_growers WHERE growerid=$gid ORDER BY id DESC LIMIT 1");
                $tgid = 'NULL'; $splitid = 1;
                if ($tg_q && $tg_q->num_rows > 0) {
                    $tg = $tg_q->fetch_assoc();
                    $tgid = (int)$tg['id']; $splitid = max(1,(int)$tg['splitid']);
                }

                $conn->query("INSERT INTO internal_stoporders
                    (userid,growerid,transporter_growersid,charge_type,description,amount,sell_date,splitid,active)
                    VALUES ($userid,$gid,$tgid,'$charge','$rdesc',$amt,'$sd',$splitid,1)");
                $conn->insert_id ? $imp++ : $skip++;
            }
            fclose($handle);
            $msg = "✓ Imported $imp stop orders. $skip skipped.";
        }
    }

    if ($action === 'remove') {
        $iso_id = (int)($_POST['iso_id'] ?? 0);
        if ($iso_id) {
            // Only this floor's stop orders (through the grower), and keep a record.
            $conn->query("UPDATE internal_stoporders iso JOIN growers g ON g.id = iso.growerid
                SET iso.active = 0 WHERE iso.id = $iso_id AND g.selling_pointid = $spid");
            if ($conn->affected_rows > 0) {
                audit_log($conn, $spid, $userid, 'internal_stoporders', 'cancel', $iso_id, ['active' => 1], ['active' => 0]);
                $msg = "Stop order removed.";
            } else {
                $err = "Stop order not found on this floor.";
            }
        }
    }
}

// ── Load active stop orders for this floor ────────────────────────────────
$iso_list = $conn->query("
    SELECT iso.id, iso.amount, iso.charge_type, iso.description,
           iso.sell_date, iso.splitid, iso.transporter_growersid,
           g.grower_num, g.name, g.surname
    FROM internal_stoporders iso
    JOIN growers g ON g.id=iso.growerid
    WHERE g.selling_pointid=$spid AND iso.active=1
    ORDER BY iso.sell_date DESC, g.grower_num
");
$iso_rows = ($iso_list && $iso_list !== true) ? $iso_list->fetch_all(MYSQLI_ASSOC) : [];

$total_amt    = array_sum(array_column($iso_rows, 'amount'));
$grower_count = count(array_unique(array_filter(array_column($iso_rows, 'grower_num'))));

// Charge type options
$charge_types = [
    'transport' => 'Transport',
    'canteen'   => 'Canteen',
    'advance'   => 'Grower Advance',
    'hessian'   => 'Hessian / Packaging',
    'levies'    => 'Levies',
    'loan'      => 'Loan Repayment',
    'other'     => 'Other',
];

$growers_q = $conn->query("SELECT id,grower_num,name,surname FROM growers
    WHERE selling_pointid=$spid ORDER BY grower_num LIMIT 500");
$growers = ($growers_q && $growers_q !== true) ? $growers_q->fetch_all(MYSQLI_ASSOC) : [];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left">
    <h2>Internal Stop Orders</h2>
    <p>Deductions applied to grower net payout — appear on sales sheets automatically</p>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1rem">
  <div class="kpi-card"><div class="kpi-label">Active Stop Orders</div><div class="kpi-value"><?=count($iso_rows)?></div></div>
  <div class="kpi-card"><div class="kpi-label">Growers Affected</div><div class="kpi-value text-gold"><?=$grower_count?></div></div>
  <div class="kpi-card"><div class="kpi-label">Total Deductions</div><div class="kpi-value text-danger">$<?=number_format($total_amt,2)?></div></div>
</div>

<div class="premium-tabs-nav" data-tabs="intso"<?= $err ? ' data-force="add"' : '' ?>>
  <button type="button" class="premium-tab-btn active" data-tab="list">📋 Active Stop Orders</button>
  <button type="button" class="premium-tab-btn" data-tab="add">➕ Add Stop Order</button>
  <button type="button" class="premium-tab-btn" data-tab="import">⬆ Bulk CSV Import</button>
</div>

<!-- Single entry -->
<div id="panel-add" class="tab-panel" data-tabs="intso">
<div class="card">
<div class="card-head"><h3>Add Stop Order</h3></div>
<div class="card-body">
<form method="POST"><input type="hidden" name="action" value="add_single">
<div class="form-group">
  <label>Grower *</label>
  <select name="growerid" class="form-control" required>
    <option value="">Select grower...</option>
    <?php foreach($growers as $g):?>
    <option value="<?=$g['id']?>"><?=htmlspecialchars($g['grower_num'].' — '.$g['name'].' '.$g['surname'])?></option>
    <?php endforeach;?>
  </select>
</div>
<div class="form-row cols-2">
  <div class="form-group">
    <label>Charge Type *</label>
    <select name="charge_type" class="form-control">
      <?php foreach($charge_types as $k=>$v):?>
      <option value="<?=$k?>"><?=htmlspecialchars($v)?></option>
      <?php endforeach;?>
    </select>
  </div>
  <div class="form-group">
    <label>Amount (USD) *</label>
    <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required placeholder="0.00">
  </div>
</div>
<div class="form-row cols-2">
  <div class="form-group">
    <label>Description / Reference</label>
    <input type="text" name="description" class="form-control" placeholder="e.g. Loan #1234">
  </div>
  <div class="form-group">
    <label>Sell Date</label>
    <input type="date" name="sell_date" class="form-control" value="<?=$today?>">
  </div>
</div>
<button type="submit" class="btn btn-primary" style="width:100%">✓ Save Stop Order</button>
</form>
</div></div>
</div><!-- /panel-add -->

<!-- Bulk CSV -->
<div id="panel-import" class="tab-panel" data-tabs="intso">
<div class="card">
<div class="card-head"><h3>Bulk CSV Import</h3></div>
<div class="card-body">
<p style="font-size:.72rem;color:var(--muted);margin-bottom:.65rem">CSV columns: <code>grower_num, amount, description</code></p>
<form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="bulk_import">
<div class="form-row cols-2">
  <div class="form-group">
    <label>Charge Type</label>
    <select name="charge_type" class="form-control">
      <?php foreach($charge_types as $k=>$v):?>
      <option value="<?=$k?>"><?=htmlspecialchars($v)?></option>
      <?php endforeach;?>
    </select>
  </div>
  <div class="form-group">
    <label>Sell Date</label>
    <input type="date" name="sell_date" class="form-control" value="<?=$today?>">
  </div>
</div>
<div class="form-group">
  <label>Default Description</label>
  <input type="text" name="description" class="form-control" placeholder="e.g. Agribank Loan">
</div>
<div class="form-group">
  <label>CSV File</label>
  <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
</div>
<button type="submit" class="btn btn-primary" style="width:100%">⬆ Import CSV</button>
</form>
</div></div>
</div><!-- /panel-import -->

<!-- Stop orders table -->
<div id="panel-list" class="tab-panel active" data-tabs="intso">
<div class="card">
<div class="card-head"><h3>Active Stop Orders</h3><span class="badge badge-gray"><?=count($iso_rows)?></span></div>
<div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
<thead><tr>
  <th>Grower</th><th>Type</th><th>Description</th>
  <th>Sell Date</th><th class="text-right">Amount</th><th></th>
</tr></thead>
<tbody>
<?php if(empty($iso_rows)):?>
<tr><td colspan="6" class="text-center text-muted" style="padding:2.5rem">
  No stop orders yet. Add one using the form on the left.
</td></tr>
<?php else: foreach($iso_rows as $r):?>
<tr>
  <td>
    <span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($r['grower_num'])?></span>
    <div style="font-size:.68rem;color:var(--muted)"><?=htmlspecialchars(trim($r['name'].' '.$r['surname']))?></div>
  </td>
  <td><span class="badge badge-gray" style="font-size:.72rem"><?=htmlspecialchars($charge_types[$r['charge_type']]??$r['charge_type'])?></span></td>
  <td style="font-size:.78rem"><?=htmlspecialchars($r['description'])?></td>
  <td style="font-size:.72rem"><?=htmlspecialchars($r['sell_date'])?></td>
  <td class="td-mono text-right" style="color:var(--danger);font-weight:600">-$<?=number_format((float)$r['amount'],2)?></td>
  <td>
    <form method="POST" style="display:inline" onsubmit="return confirm('Remove this stop order?')">
      <input type="hidden" name="action" value="remove">
      <input type="hidden" name="iso_id" value="<?=$r['id']?>">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--danger)">✕</button>
    </form>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table></div>
</div>
</div><!-- /panel-list -->

<?php include "includes/layout_end.php"; ?>
