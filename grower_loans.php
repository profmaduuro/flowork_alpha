<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "Grower Loans & Advances";
$active_page = "grower_loans";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();
$msg = $err  = '';
$today       = date('Y-m-d');

$conn->query("CREATE TABLE IF NOT EXISTS grower_loans (
    id INT AUTO_INCREMENT PRIMARY KEY, selling_pointid INT NOT NULL, growerid INT NOT NULL,
    loan_type ENUM('advance','input_loan','emergency','other') DEFAULT 'advance',
    amount DECIMAL(14,2) NOT NULL, currency ENUM('usd','zig') DEFAULT 'usd',
    interest_rate DECIMAL(6,4) DEFAULT 0, issued_date DATE NOT NULL, due_date DATE DEFAULT NULL,
    description VARCHAR(255) DEFAULT '', status ENUM('active','partially_paid','fully_paid','written_off') DEFAULT 'active',
    total_repaid DECIMAL(14,2) DEFAULT 0, issued_by INT NOT NULL, notes TEXT DEFAULT '',
    gms_loan_id INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_grower(growerid), INDEX idx_sp(selling_pointid))");

$conn->query("CREATE TABLE IF NOT EXISTS loan_repayments (
    id INT AUTO_INCREMENT PRIMARY KEY, loan_id INT NOT NULL, amount DECIMAL(14,2) NOT NULL,
    repayment_date DATE NOT NULL, sell_date DATE DEFAULT NULL,
    method ENUM('sale_deduction','cash','bank_transfer','gms_sync','other') DEFAULT 'sale_deduction',
    reference VARCHAR(80) DEFAULT '', recorded_by INT NOT NULL, notes VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_loan(loan_id))");

// Download loan upload template
if (isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="grower_loans_template.csv"');
    echo "grower_num,loan_type,amount,currency,interest_rate,issued_date,due_date,description,notes\r\n";
    echo "ZW-0001234,advance,500.00,usd,0,".date('Y-m-d').",,Pre-season advance,\r\n";
    echo "ZW-0001235,input_loan,1200.00,usd,5,".date('Y-m-d').",".date('Y-m-d',strtotime('+6 months')).",Seed and fertiliser,\r\n";
    exit;
}

// Loan statement PDF/print
if (isset($_GET['statement'])) {
    $lid = (int)$_GET['statement'];
    $loan_q = $conn->query("SELECT gl.*,g.grower_num,g.name,g.surname FROM grower_loans gl JOIN growers g ON g.id=gl.growerid WHERE gl.id=$lid AND gl.selling_pointid=$spid LIMIT 1");
    $loan = ($loan_q&&$loan_q!==true)?$loan_q->fetch_assoc():null;
    if (!$loan) { echo "Loan not found"; exit; }
    $reps_q = $conn->query("SELECT * FROM loan_repayments WHERE loan_id=$lid ORDER BY repayment_date");
    $reps   = ($reps_q&&$reps_q!==true)?$reps_q->fetch_all(MYSQLI_ASSOC):[];
    $sp_name = htmlspecialchars($_SESSION['selling_point_name']??'');
    header('Content-Type: text/html');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Loan Statement</title>
    <style>body{font-family:Arial,sans-serif;font-size:10px;max-width:680px;margin:20px auto}
    h1{font-size:14px}h2{font-size:11px;margin-top:0}
    table{width:100%;border-collapse:collapse}th,td{padding:4px 8px;border-bottom:1px solid #ddd;font-size:9px}
    th{background:#1a1208;color:#fff;text-align:left}.btn{padding:6px 14px;background:#c8952a;color:#fff;border:none;border-radius:4px;cursor:pointer;margin-bottom:12px}
    .no-print{} @media print{.no-print{display:none}} .tr{text-align:right}
    </style></head><body>
    <div class="no-print"><button class="btn" onclick="window.print()">🖨 Print Statement</button></div>
    <h1>'.$sp_name.' — LOAN STATEMENT</h1>
    <table style="margin-bottom:14px"><tr><td><b>Grower:</b> '.htmlspecialchars($loan['grower_num'].' — '.$loan['name'].' '.$loan['surname']).'</td>
    <td><b>Loan Type:</b> '.ucwords(str_replace('_',' ',$loan['loan_type'])).'</td></tr>
    <tr><td><b>Amount:</b> '.strtoupper($loan['currency']).' '.number_format($loan['amount'],2).'</td>
    <td><b>Interest Rate:</b> '.$loan['interest_rate'].'%</td></tr>
    <tr><td><b>Issued:</b> '.date('d M Y',strtotime($loan['issued_date'])).'</td>
    <td><b>Due:</b> '.($loan['due_date']?date('d M Y',strtotime($loan['due_date'])):'Open-ended').'</td></tr>
    <tr><td><b>Total Repaid:</b> '.strtoupper($loan['currency']).' '.number_format($loan['total_repaid'],2).'</td>
    <td><b>Outstanding:</b> <b>'.strtoupper($loan['currency']).' '.number_format(max(0,$loan['amount']-$loan['total_repaid']),2).'</b></td></tr>
    <tr><td colspan="2"><b>Description:</b> '.htmlspecialchars($loan['description']??'').'</td></tr></table>
    <h2>Repayment History</h2>
    <table><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="tr">Amount</th><th>Notes</th></tr></thead><tbody>';
    foreach($reps as $r){
        echo '<tr><td>'.date('d M Y',strtotime($r['repayment_date'])).'</td>
        <td>'.ucwords(str_replace('_',' ',$r['method'])).'</td>
        <td>'.htmlspecialchars($r['reference']).'</td>
        <td class="tr">'.strtoupper($loan['currency']).' '.number_format($r['amount'],2).'</td>
        <td>'.htmlspecialchars($r['notes']).'</td></tr>';
    }
    $outstanding = max(0,$loan['amount']-$loan['total_repaid']);
    echo '</tbody><tfoot><tr style="background:#f5f5f5;font-weight:700">
        <td colspan="3">Balance Outstanding</td>
        <td class="tr">'.strtoupper($loan['currency']).' '.number_format($outstanding,2).'</td><td></td></tr></tfoot></table>
    <div style="margin-top:20px;font-size:8px;color:#888">Generated: '.date('d M Y H:i').' · Flowork Floor Management System</div>
    <script>window.onload=()=>window.print()</script></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_loan') {
        $gid   = (int)($_POST['growerid'] ?? 0);
        $type  = $conn->real_escape_string($_POST['loan_type'] ?? 'advance');
        $amt   = (float)($_POST['amount'] ?? 0);
        $curr  = $_POST['currency']==='zig'?'zig':'usd';
        $rate  = (float)($_POST['interest_rate'] ?? 0);
        $dt    = $conn->real_escape_string($_POST['issued_date'] ?? $today);
        $due   = $conn->real_escape_string($_POST['due_date'] ?? '');
        $desc  = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
        if (!$gid||$amt<=0) { $err="Grower and amount required."; }
        else {
            $due_sql = $due?"'$due'":'NULL';
            $conn->query("INSERT INTO grower_loans (selling_pointid,growerid,loan_type,amount,currency,interest_rate,issued_date,due_date,description,issued_by,notes) VALUES ($spid,$gid,'$type',$amt,'$curr',$rate,'$dt',$due_sql,'$desc',$userid,'$notes')");
            audit_log($conn,$spid,$userid,'grower_loans','create',$conn->insert_id,null,['growerid'=>$gid,'amount'=>$amt]);
            $msg = "✓ Loan of ".strtoupper($curr)." ".number_format($amt,2)." recorded.";
        }
    }

    if ($action === 'bulk_import') {
        $file = $_FILES['loans_csv'] ?? null;
        if (!$file||$file['error']!==UPLOAD_ERR_OK) { $err="Upload error."; }
        else {
            $handle  = fopen($file['tmp_name'],'r');
            $header  = array_map(fn($h)=>strtolower(trim($h)), fgetcsv($handle));
            $imported= 0; $skipped=0;
            while(($row=fgetcsv($handle))!==false) {
                $d=array_combine(array_slice($header,0,count($row)),array_slice($row,0,count($header)));
                $d=array_map('trim',$d);
                $gnum=$conn->real_escape_string($d['grower_num']??'');
                $amt=(float)($d['amount']??0);
                if(!$gnum||$amt<=0){$skipped++;continue;}
                $g_q=$conn->query("SELECT id FROM growers WHERE grower_num='$gnum' AND selling_pointid=$spid LIMIT 1");
                $g=($g_q&&$g_q!==true)?$g_q->fetch_assoc():null;
                if(!$g){$skipped++;continue;}
                $gid=(int)$g['id'];
                $type=$conn->real_escape_string($d['loan_type']??'advance');
                $curr=($d['currency']??'usd')==='zig'?'zig':'usd';
                $rate=(float)($d['interest_rate']??0);
                $dt=$conn->real_escape_string(!empty($d['issued_date'])?$d['issued_date']:$today);
                $due_sql=!empty($d['due_date'])?"'".$conn->real_escape_string($d['due_date'])."'":'NULL';
                $desc=$conn->real_escape_string($d['description']??'');
                $notes=$conn->real_escape_string($d['notes']??'');
                $conn->query("INSERT INTO grower_loans (selling_pointid,growerid,loan_type,amount,currency,interest_rate,issued_date,due_date,description,issued_by,notes) VALUES ($spid,$gid,'$type',$amt,'$curr',$rate,'$dt',$due_sql,'$desc',$userid,'$notes')");
                $imported++;
            }
            fclose($handle);
            $msg="✓ Imported $imported loans. $skipped skipped.";
        }
    }

    if ($action === 'add_repayment') {
        $lid  = (int)($_POST['loan_id']??0);
        $amt  = (float)($_POST['amount']??0);
        $dt   = $conn->real_escape_string($_POST['repayment_date']??$today);
        $sd   = $conn->real_escape_string($_POST['sell_date']??'');
        $meth = $conn->real_escape_string($_POST['method']??'sale_deduction');
        $ref  = $conn->real_escape_string(trim($_POST['reference']??''));
        $notes= $conn->real_escape_string(trim($_POST['notes']??''));
        if(!$lid||$amt<=0){$err="Loan and amount required.";}
        elseif(!flowork_on_floor($conn,'loan',$lid,$spid)){$err="Loan not found on this floor.";}
        else {
            $sd_sql = $sd?"'$sd'":'NULL';
            $conn->query("INSERT INTO loan_repayments (loan_id,amount,repayment_date,sell_date,method,reference,recorded_by,notes) VALUES ($lid,$amt,'$dt',$sd_sql,'$meth','$ref',$userid,'$notes')");
            $conn->query("UPDATE grower_loans SET total_repaid=total_repaid+$amt WHERE id=$lid");
            $loan_q=$conn->query("SELECT amount,total_repaid FROM grower_loans WHERE id=$lid LIMIT 1");
            if($loan_q&&$r=$loan_q->fetch_assoc()){
                $status=$r['total_repaid']>=$r['amount']?'fully_paid':($r['total_repaid']>0?'partially_paid':'active');
                $conn->query("UPDATE grower_loans SET status='$status' WHERE id=$lid");
            }
            audit_log($conn,$spid,$userid,'loan_repayments','create',null,null,['loan_id'=>$lid,'amount'=>$amt]);
            $msg="✓ Repayment of $".number_format($amt,2)." recorded.";
        }
    }
}

$f_status=$conn->real_escape_string($_GET['status']??'');$f_search=$conn->real_escape_string(trim($_GET['q']??''));
$where_l="gl.selling_pointid=$spid";
if($f_status)$where_l.=" AND gl.status='$f_status'";
if($f_search)$where_l.=" AND (g.grower_num LIKE '%$f_search%' OR g.name LIKE '%$f_search%')";

$loans_q=$conn->query("SELECT gl.*,g.grower_num,g.name,g.surname,(gl.amount-gl.total_repaid) outstanding FROM grower_loans gl JOIN growers g ON g.id=gl.growerid WHERE $where_l ORDER BY gl.issued_date DESC LIMIT 300");
$loans=($loans_q&&$loans_q!==true)?$loans_q->fetch_all(MYSQLI_ASSOC):[];

$growers_q=$conn->query("SELECT id,grower_num,name,surname FROM growers WHERE selling_pointid=$spid ORDER BY grower_num LIMIT 500");
$growers=($growers_q&&$growers_q!==true)?$growers_q->fetch_all(MYSQLI_ASSOC):[];

$STATUS_COLORS=['active'=>'badge-gold','partially_paid'=>'badge-blue','fully_paid'=>'badge-green','written_off'=>'badge-red'];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Grower Loans &amp; Advances</h2><p>Issue, track and bulk-import loans · Auto-updates on repayment</p></div>
  <div style="display:flex;gap:.35rem">
    <a href="?template=1" class="btn btn-ghost btn-sm">⬇ CSV Template</a>
    <a href="?export=csv" class="btn btn-ghost btn-sm">⬇ Export</a>
  </div>
</div>

<?php
if(isset($_GET['export'])){
    ob_clean();header('Content-Type: text/csv');header('Content-Disposition: attachment; filename="grower_loans_'.date('Ymd').'.csv"');
    echo "Grower #,Name,Type,Amount,Currency,Issued,Due,Status,Repaid,Outstanding\r\n";
    foreach($loans as $l){echo implode(',', ['"'.$l['grower_num'].'"','"'.trim($l['name'].' '.$l['surname']).'"',$l['loan_type'],$l['amount'],$l['currency'],$l['issued_date'],$l['due_date']??'',$l['status'],$l['total_repaid'],max(0,$l['outstanding'])])."\r\n";}
    exit;
}
?>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1rem">
  <div class="kpi-card"><div class="kpi-label">Total Issued</div><div class="kpi-value">$<?=number_format(array_sum(array_column($loans,'amount')),2)?></div><div class="kpi-sub"><?=count($loans)?> loans</div></div>
  <div class="kpi-card"><div class="kpi-label">Total Repaid</div><div class="kpi-value text-leaf">$<?=number_format(array_sum(array_column($loans,'total_repaid')),2)?></div></div>
  <div class="kpi-card"><div class="kpi-label">Outstanding</div><div class="kpi-value text-gold">$<?=number_format(max(0,array_sum(array_column($loans,'outstanding'))),2)?></div></div>
  <div class="kpi-card"><div class="kpi-label">Recovery Rate</div><div class="kpi-value"><?php $tot_iss=array_sum(array_column($loans,'amount'));echo $tot_iss>0?round(array_sum(array_column($loans,'total_repaid'))/$tot_iss*100,1).'%':'—';?></div></div>
</div>

<div class="premium-tabs-nav" data-tabs="loans">
  <button type="button" class="premium-tab-btn active" data-tab="register">📋 Loan Register</button>
  <button type="button" class="premium-tab-btn" data-tab="issue">➕ Issue Loan</button>
  <button type="button" class="premium-tab-btn" data-tab="import">⬆ Bulk Upload</button>
</div>

<div id="panel-register" class="tab-panel active" data-tabs="loans">

<div class="card">
<div class="card-head"><h3>Loan Register</h3>
  <div style="display:flex;gap:.35rem">
    <form method="GET" style="display:flex;gap:.35rem">
      <input type="text" name="q" value="<?=htmlspecialchars($f_search)?>" placeholder="Search..." class="form-control" style="font-size:.75rem;padding:.3rem .55rem;width:120px">
      <select name="status" class="form-control" style="font-size:.75rem;padding:.3rem .5rem">
        <option value="">All</option>
        <?php foreach(['active','partially_paid','fully_paid','written_off'] as $s):?><option value="<?=$s?>" <?=$f_status===$s?'selected':''?>><?=ucwords(str_replace('_',' ',$s))?></option><?php endforeach;?>
      </select>
      <button type="submit" class="btn btn-ghost btn-sm">Go</button>
    </form>
  </div>
</div>
<div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
<thead><tr><th>Grower</th><th>Type</th><th class="text-right">Amount</th><th class="text-right">Repaid</th><th class="text-right">Outstanding</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php foreach($loans as $l): $out=max(0,(float)$l['outstanding']);?>
<tr>
  <td><span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($l['grower_num'])?></span><div style="font-size:.7rem"><?=htmlspecialchars(trim($l['name'].' '.$l['surname']))?></div></td>
  <td><span class="badge badge-gray" style="font-size:.72rem"><?=strtoupper(str_replace('_',' ',$l['loan_type']))?></span></td>
  <td class="td-mono text-right"><?=$l['currency']==='zig'?'ZiG ':'$'?><?=number_format((float)$l['amount'],2)?></td>
  <td class="td-mono text-right text-leaf"><?=$l['currency']==='zig'?'ZiG ':'$'?><?=number_format((float)$l['total_repaid'],2)?></td>
  <td class="td-mono text-right <?=$out>0?'text-gold':''?>"><?=$out>0?('$'.number_format($out,2)):'—'?></td>
  <td><span class="badge <?=$STATUS_COLORS[$l['status']]?>" style="font-size:.72rem"><?=ucwords(str_replace('_',' ',$l['status']))?></span></td>
  <td style="white-space:nowrap">
    <a href="?statement=<?=$l['id']?>" target="_blank" class="btn btn-ghost btn-sm" style="font-size:.72rem" title="Statement">📄</a>
    <button class="btn btn-ghost btn-sm" style="font-size:.72rem" onclick="showRepay(<?=$l['id']?>,'<?=htmlspecialchars(trim($l['grower_num'].' '.trim($l['name'].' '.$l['surname'])),ENT_QUOTES)?>',<?=number_format($out,2,'.','')?>)">+Pay</button>
  </td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
</div>
</div><!-- /panel-register -->

<div id="panel-issue" class="tab-panel" data-tabs="loans">
<div class="card">
<div class="card-head"><h3>Issue New Loan / Advance</h3></div>
<div class="card-body">
<form method="POST"><input type="hidden" name="action" value="add_loan">
<div class="form-group"><label>Grower</label><select name="growerid" class="form-control" required><option value="">Select...</option><?php foreach($growers as $g):?><option value="<?=$g['id']?>"><?=htmlspecialchars($g['grower_num'].' — '.$g['name'].' '.$g['surname'])?></option><?php endforeach;?></select></div>
<div class="form-row cols-2">
  <div class="form-group"><label>Type</label><select name="loan_type" class="form-control"><option value="advance">Season Advance</option><option value="input_loan">Input Loan</option><option value="emergency">Emergency</option><option value="other">Other</option></select></div>
  <div class="form-group"><label>Currency</label><select name="currency" class="form-control"><option value="usd">USD</option><option value="zig">ZiG</option></select></div>
</div>
<div class="form-row cols-2">
  <div class="form-group"><label>Amount *</label><input type="number" name="amount" class="form-control" step="0.01" min="0.01" required placeholder="0.00"></div>
  <div class="form-group"><label>Interest %</label><input type="number" name="interest_rate" class="form-control" step="0.01" value="0"></div>
</div>
<div class="form-row cols-2">
  <div class="form-group"><label>Issued Date</label><input type="date" name="issued_date" class="form-control" value="<?=$today?>"></div>
  <div class="form-group"><label>Due Date</label><input type="date" name="due_date" class="form-control"></div>
</div>
<div class="form-group"><label>Description</label><input type="text" name="description" class="form-control" placeholder="e.g. Pre-season advance for inputs"></div>
<button type="submit" class="btn btn-primary" style="width:100%">Issue Loan</button>
</form>
</div>
</div>
</div><!-- /panel-issue -->

<div id="panel-import" class="tab-panel" data-tabs="loans">
<div class="card">
<div class="card-head"><h3>Bulk Upload CSV</h3></div>
<div class="card-body">
<form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="bulk_import">
<div class="form-group"><label>CSV File</label><input type="file" name="loans_csv" accept=".csv,.txt" class="form-control" required></div>
<div style="font-size:.7rem;color:var(--muted);margin-bottom:.5rem">Columns: grower_num, amount, loan_type, currency, interest_rate, issued_date, due_date, description</div>
<button type="submit" class="btn btn-primary" style="width:100%" data-progress-label="Importing loans...">Bulk Import</button>
</form>
</div>
</div>
</div><!-- /panel-import -->

<!-- Repayment modal -->
<div id="repay-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);align-items:center;justify-content:center">
<div style="background:var(--surface);border-radius:10px;padding:1.5rem;max-width:420px;width:90%;border:1px solid var(--border)">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <h3 id="repay-title" style="font-size:.95rem;margin:0">Record Repayment</h3>
    <button onclick="document.getElementById('repay-modal').style.display='none'" class="btn btn-ghost btn-sm">✕</button>
  </div>
  <form method="POST"><input type="hidden" name="action" value="add_repayment"><input type="hidden" name="loan_id" id="repay-lid">
  <div class="form-group"><label>Amount</label><input type="number" name="amount" id="repay-amt" class="form-control" step="0.01" min="0.01" required></div>
  <div class="form-row cols-2">
    <div class="form-group"><label>Date</label><input type="date" name="repayment_date" class="form-control" value="<?=$today?>"></div>
    <div class="form-group"><label>Method</label><select name="method" class="form-control"><option value="sale_deduction">Sale Deduction</option><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="other">Other</option></select></div>
  </div>
  <div class="form-row cols-2">
    <div class="form-group"><label>Sell Date (if deduction)</label><input type="date" name="sell_date" class="form-control"></div>
    <div class="form-group"><label>Reference</label><input type="text" name="reference" class="form-control"></div>
  </div>
  <button type="submit" class="btn btn-primary" style="width:100%">Record Repayment</button>
  </form>
</div>
</div>
<script>
function showRepay(id,name,outstanding){
    document.getElementById('repay-lid').value=id;
    document.getElementById('repay-title').textContent='Repayment — '+name;
    document.getElementById('repay-amt').value=outstanding>0?outstanding:'';
    document.getElementById('repay-modal').style.display='flex';
}
document.getElementById('repay-modal').addEventListener('click',function(e){if(e.target===this)this.style.display='none';});
</script>

<?php include "includes/layout_end.php"; ?>
