<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "Banking — Grower Accounts";
$active_page = "banking";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();
$msg = $err  = '';

// Ensure grower_banking table supports both USD and ZiG
$conn->query("CREATE TABLE IF NOT EXISTS grower_banking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    growerid INT NOT NULL,
    selling_pointid INT NOT NULL DEFAULT 0,
    currency ENUM('usd','zig') DEFAULT 'usd',
    bank_name VARCHAR(80) DEFAULT '',
    branch VARCHAR(60) DEFAULT '',
    account_num VARCHAR(40) DEFAULT '',
    account_name VARCHAR(100) DEFAULT '',
    swift_code VARCHAR(20) DEFAULT '',
    mobile_number VARCHAR(30) DEFAULT '',
    is_primary TINYINT DEFAULT 0,
    verified TINYINT DEFAULT 0,
    notes VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_grower_spid_curr (growerid, selling_pointid, currency),
    INDEX idx_grower (growerid)
)");

// ── Schema migrations (idempotent) ───────────────────────────────────────
// Helper: add a column only if it is missing
function _gb_add_col($conn, $col, $def) {
    $r = $conn->query("SHOW COLUMNS FROM grower_banking LIKE '$col'");
    if ($r && $r->num_rows === 0)
        $conn->query("ALTER TABLE grower_banking ADD COLUMN $col $def");
}

_gb_add_col($conn, 'selling_pointid', "INT NOT NULL DEFAULT 0 AFTER growerid");
_gb_add_col($conn, 'currency',        "ENUM('usd','zig') NOT NULL DEFAULT 'usd' AFTER selling_pointid");
_gb_add_col($conn, 'bank_name',       "VARCHAR(80)  NOT NULL DEFAULT ''");
_gb_add_col($conn, 'branch',          "VARCHAR(60)  NOT NULL DEFAULT ''");
_gb_add_col($conn, 'account_num',     "VARCHAR(40)  NOT NULL DEFAULT ''");
_gb_add_col($conn, 'account_name',    "VARCHAR(100) NOT NULL DEFAULT ''");
_gb_add_col($conn, 'swift_code',      "VARCHAR(20)  NOT NULL DEFAULT ''");
_gb_add_col($conn, 'mobile_number',   "VARCHAR(30)  NOT NULL DEFAULT ''");
_gb_add_col($conn, 'is_primary',      "TINYINT NOT NULL DEFAULT 0");
_gb_add_col($conn, 'verified',        "TINYINT NOT NULL DEFAULT 0");
_gb_add_col($conn, 'notes',           "VARCHAR(255) NOT NULL DEFAULT ''");

// Migrate old unique key (growerid, currency) → (growerid, selling_pointid, currency)
// so each selling floor keeps independent banking records per grower.
$_ck_old = $conn->query("SHOW INDEX FROM grower_banking WHERE Key_name='uk_grower_curr'");
if ($_ck_old && $_ck_old->num_rows > 0)
    $conn->query("ALTER TABLE grower_banking DROP INDEX uk_grower_curr");

// Add the correct unique key if not already present
$_ck_new = $conn->query("SHOW INDEX FROM grower_banking WHERE Key_name='uk_grower_spid_curr'");
if ($_ck_new && $_ck_new->num_rows === 0)
    $conn->query("ALTER TABLE grower_banking ADD UNIQUE KEY uk_grower_spid_curr (growerid, selling_pointid, currency)");

// Download template
if (isset($_GET['template'])) {
    $currency_t = ($_GET['template'] ?? '') === 'zig' ? 'zig' : 'usd';
    header('Content-Type: text/csv');
    header("Content-Disposition: attachment; filename=\"banking_template_{$currency_t}.csv\"");
    if ($currency_t === 'zig') {
        echo "grower_num,account_name,bank_name,branch,account_num,mobile_number,notes\r\n";
        echo "ZW-0001234,John Moyo,CBZ Bank,Harare Main,1234567890,,\r\n";
        echo "ZW-0001235,Mary Chikwanda,CABS,Bulawayo,0987654321,,ZiG account\r\n";
    } else {
        echo "grower_num,account_name,bank_name,branch,account_num,swift_code,mobile_number,notes\r\n";
        echo "ZW-0001234,John Moyo,CBZ Bank,Harare Main,1234567890,CBZWZWHAXXX,,\r\n";
        echo "ZW-0001235,Mary Chikwanda,Stanbic,Avondale,0987654321,SBICZWHX,,USD account\r\n";
    }
    exit;
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Save single account
    if ($action === 'save_account') {
        $gid      = (int)($_POST['growerid'] ?? 0);
        $currency = ($_POST['currency'] ?? '') === 'zig' ? 'zig' : 'usd';
        $bank     = $conn->real_escape_string(trim($_POST['bank_name']     ?? ''));
        $branch   = $conn->real_escape_string(trim($_POST['branch']        ?? ''));
        $acnum    = $conn->real_escape_string(trim($_POST['account_num']   ?? ''));
        $acname   = $conn->real_escape_string(trim($_POST['account_name']  ?? ''));
        $swift    = $conn->real_escape_string(trim($_POST['swift_code']    ?? ''));
        $mobile   = $conn->real_escape_string(trim($_POST['mobile_number'] ?? ''));
        $notes    = $conn->real_escape_string(trim($_POST['notes']         ?? ''));
        if (!$gid)   { $err = "Please select a grower."; }
        elseif (!$acname && !$acnum) { $err = "Account name or account number is required."; }
        else {
            $conn->query("INSERT INTO grower_banking
                (growerid, selling_pointid, currency, bank_name, branch, account_num,
                 account_name, swift_code, mobile_number, notes, is_primary)
                VALUES ($gid, $spid, '$currency', '$bank', '$branch', '$acnum',
                        '$acname', '$swift', '$mobile', '$notes', 1)
                ON DUPLICATE KEY UPDATE
                    selling_pointid = $spid,
                    bank_name       = '$bank',
                    branch          = '$branch',
                    account_num     = '$acnum',
                    account_name    = '$acname',
                    swift_code      = '$swift',
                    mobile_number   = '$mobile',
                    notes           = '$notes'");
            if ($conn->error) {
                $err = "Could not save account: " . $conn->error;
            } else {
                audit_log($conn, $spid, $userid, 'grower_banking', 'save', $gid, null,
                          ['currency' => $currency, 'bank' => $bank, 'account_num' => $acnum]);
                $msg = "✓ " . strtoupper($currency) . " account saved for grower ID $gid.";
            }
        }
    }

    // Bulk CSV import
    if ($action === 'import_csv') {
        $currency = $_POST['import_currency'] === 'zig' ? 'zig' : 'usd';
        $file     = $_FILES['banking_csv'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) { $err = "Upload error."; }
        else {
            $handle  = fopen($file['tmp_name'],'r');
            $header  = array_map(fn($h)=>strtolower(trim($h)), fgetcsv($handle));
            $imported = 0; $skipped = 0;
            while (($row = fgetcsv($handle)) !== false) {
                $d = array_combine(array_slice($header,0,count($row)), array_slice($row,0,count($header)));
                $d = array_map('trim', $d);
                $gnum = $conn->real_escape_string($d['grower_num'] ?? '');
                if (!$gnum) { $skipped++; continue; }
                // Find grower
                $g_q = $conn->query("SELECT id FROM growers WHERE grower_num='$gnum' AND selling_pointid=$spid LIMIT 1");
                $g   = ($g_q&&$g_q!==true)?$g_q->fetch_assoc():null;
                if (!$g) { $skipped++; continue; }
                $gid   = (int)$g['id'];
                $bank  = $conn->real_escape_string($d['bank_name'] ?? '');
                $branch= $conn->real_escape_string($d['branch'] ?? '');
                $acnum = $conn->real_escape_string($d['account_num'] ?? '');
                $acname= $conn->real_escape_string($d['account_name'] ?? '');
                $swift = $conn->real_escape_string($d['swift_code'] ?? '');
                $mobile= $conn->real_escape_string($d['mobile_number'] ?? '');
                $notes = $conn->real_escape_string($d['notes'] ?? '');
                $conn->query("INSERT INTO grower_banking
                    (growerid, selling_pointid, currency, bank_name, branch, account_num,
                     account_name, swift_code, mobile_number, notes, is_primary)
                    VALUES ($gid, $spid, '$currency', '$bank', '$branch', '$acnum',
                            '$acname', '$swift', '$mobile', '$notes', 1)
                    ON DUPLICATE KEY UPDATE
                        selling_pointid = $spid,
                        bank_name       = '$bank',
                        branch          = '$branch',
                        account_num     = '$acnum',
                        account_name    = '$acname',
                        swift_code      = '$swift',
                        mobile_number   = '$mobile',
                        notes           = '$notes'");
                if (!$conn->error) $imported++; else $skipped++;
            }
            fclose($handle);
            audit_log($conn,$spid,$userid,'grower_banking','bulk_import',null,null,['currency'=>$currency,'count'=>$imported]);
            $msg = "✓ Imported $imported ".strtoupper($currency)." accounts. $skipped rows skipped (grower not found).";
        }
    }
}

$f_currency = ($_GET['currency'] ?? 'usd') === 'zig' ? 'zig' : 'usd';
$f_search   = $conn->real_escape_string(trim($_GET['q'] ?? ''));

// Load accounts — show this floor's records; if spid>0 also show global (spid=0) records
// so admin-captured data is visible to floor users.
$_sp_filter = $spid > 0 ? "(gb.selling_pointid=$spid OR gb.selling_pointid=0)" : "1=1";
$where = "$_sp_filter AND gb.currency='".($f_currency==='zig'?'zig':'usd')."'";
if ($f_search) $where .= " AND (g.grower_num LIKE '%$f_search%' OR g.name LIKE '%$f_search%' OR gb.account_num LIKE '%$f_search%')";

$accounts_q = $conn->query("
    SELECT gb.*, g.grower_num, g.name, g.surname, g.phone
    FROM grower_banking gb
    JOIN growers g ON g.id=gb.growerid
    WHERE $where
    ORDER BY g.grower_num
    LIMIT 300
");
$accounts = ($accounts_q&&$accounts_q!==true)?$accounts_q->fetch_all(MYSQLI_ASSOC):[];

// Stats
$_r = $conn->query("SELECT COUNT(*) c FROM grower_banking WHERE selling_pointid=$spid AND currency='usd'");
$usd_count = $_r ? (int)($_r->fetch_assoc()['c']??0) : 0;
$_r = $conn->query("SELECT COUNT(*) c FROM grower_banking WHERE selling_pointid=$spid AND currency='zig'");
$zig_count = $_r ? (int)($_r->fetch_assoc()['c']??0) : 0;
$_r = $conn->query("SELECT COUNT(*) c FROM growers WHERE selling_pointid=$spid");
$total_gr  = $_r ? (int)($_r->fetch_assoc()['c']??0) : 0;

// Growers for form — include this floor and also unassigned (selling_pointid=0)
$_gr_sp = $spid > 0 ? "(g.selling_pointid=$spid OR g.selling_pointid=0 OR g.selling_pointid IS NULL)" : "1=1";
$growers_q = $conn->query("SELECT g.id, g.grower_num, g.name, g.surname FROM growers g
    WHERE $_gr_sp ORDER BY g.grower_num LIMIT 1000");
$growers   = ($growers_q&&$growers_q!==true)?$growers_q->fetch_all(MYSQLI_ASSOC):[];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left">
    <h2>Banking — Grower Accounts</h2>
    <p>Capture and manage USD and ZiG bank accounts per grower</p>
  </div>
  <div style="display:flex;gap:.35rem">
    <a href="?template=usd" class="btn btn-ghost btn-sm">⬇ USD Template</a>
    <a href="?template=zig" class="btn btn-ghost btn-sm">⬇ ZiG Template</a>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- Stats -->
<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1rem">
  <div class="kpi-card"><div class="kpi-label">Total Growers</div><div class="kpi-value"><?=number_format($total_gr)?></div></div>
  <div class="kpi-card"><div class="kpi-label">USD Accounts</div><div class="kpi-value"><?=number_format($usd_count)?></div><div class="kpi-sub"><?=$total_gr>0?round($usd_count/$total_gr*100).'%':'—'?> coverage</div></div>
  <div class="kpi-card"><div class="kpi-label">ZiG Accounts</div><div class="kpi-value"><?=number_format($zig_count)?></div><div class="kpi-sub"><?=$total_gr>0?round($zig_count/$total_gr*100).'%':'—'?> coverage</div></div>
  <div class="kpi-card"><div class="kpi-label">No Account</div><div class="kpi-value text-gold"><?=max(0,$total_gr-max($usd_count,$zig_count))?></div><div class="kpi-sub">missing</div></div>
</div>

<!-- Currency tabs -->
<div style="display:flex;gap:.35rem;margin-bottom:1rem">
  <a href="?currency=usd<?=$f_search?"&q=".urlencode($f_search):''?>" class="btn <?=$f_currency==='usd'?'btn-primary':'btn-ghost'?> btn-sm">🏦 USD Accounts</a>
  <a href="?currency=zig<?=$f_search?"&q=".urlencode($f_search):''?>" class="btn <?=$f_currency==='zig'?'btn-primary':'btn-ghost'?> btn-sm">💰 ZiG Accounts</a>
</div>

<div class="premium-tabs-nav" data-tabs="banking"<?= $err ? ' data-force="add"' : '' ?>>
  <button type="button" class="premium-tab-btn active" data-tab="list">🏦 Accounts</button>
  <button type="button" class="premium-tab-btn" data-tab="add">➕ Add / Update</button>
  <button type="button" class="premium-tab-btn" data-tab="import">⬆ Bulk Import</button>
</div>

<!-- Account list -->
<div id="panel-list" class="tab-panel active" data-tabs="banking">
<div class="card">
<div class="card-head">
  <h3><?=strtoupper($f_currency)?> Accounts</h3>
  <div style="display:flex;gap:.35rem">
    <form method="GET" style="display:flex;gap:.35rem">
      <input type="hidden" name="currency" value="<?=htmlspecialchars((string)$f_currency)?>">
      <input type="text" name="q" class="form-control" value="<?=htmlspecialchars($f_search)?>" placeholder="Search grower..." style="font-size:.75rem;padding:.3rem .55rem;width:150px">
      <button type="submit" class="btn btn-ghost btn-sm">Go</button>
    </form>
    <span class="badge badge-gray"><?=count($accounts)?></span>
  </div>
</div>
<div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
<thead><tr>
  <th>Grower</th><th>Bank</th><th>Account #</th><th>Account Name</th>
  <?php if($f_currency==='usd'):?><th>SWIFT</th><?php endif;?>
  <th>Mobile</th><th>Status</th><th></th>
</tr></thead>
<tbody>
<?php if(empty($accounts)):?>
<tr><td colspan="8" class="text-center text-muted" style="padding:2rem">
  No <?=strtoupper($f_currency)?> accounts captured yet. Add one using the form →
</td></tr>
<?php else: foreach($accounts as $a):
  // Build JS-safe data attributes for inline edit
  $js = "editAccount("
      . (int)$a['growerid'] . ","
      . "'" . addslashes($a['currency'])       . "',"
      . "'" . addslashes($a['bank_name'])      . "',"
      . "'" . addslashes($a['branch'])         . "',"
      . "'" . addslashes($a['account_num'])    . "',"
      . "'" . addslashes($a['account_name'])   . "',"
      . "'" . addslashes($a['swift_code'])     . "',"
      . "'" . addslashes($a['mobile_number'])  . "',"
      . "'" . addslashes($a['notes'])          . "'"
      . ")";
?>
<tr style="cursor:pointer" onclick="<?=$js?>" title="Click to edit">
  <td>
    <span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($a['grower_num'])?></span>
    <div style="font-size:.7rem"><?=htmlspecialchars(trim($a['name'].' '.$a['surname']))?></div>
  </td>
  <td style="font-size:.78rem"><?=htmlspecialchars($a['bank_name'])?></td>
  <td style="font-family:monospace;font-size:.75rem"><?=htmlspecialchars($a['account_num'])?></td>
  <td style="font-size:.75rem"><?=htmlspecialchars($a['account_name'])?></td>
  <?php if($f_currency==='usd'):?>
  <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($a['swift_code'])?></td>
  <?php endif;?>
  <td style="font-size:.72rem"><?=htmlspecialchars($a['mobile_number'])?></td>
  <td><?=$a['verified']?'<span class="badge badge-green" style="font-size:.58rem">✓ Verified</span>':'<span class="badge badge-gray" style="font-size:.58rem">Pending</span>'?></td>
  <td style="text-align:center"><span style="font-size:.65rem;color:var(--muted)">✏</span></td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table></div>
</div>

</div><!-- /panel-list -->

<!-- CSV import -->
<div id="panel-import" class="tab-panel" data-tabs="banking">
<div class="card">
<div class="card-head"><h3>Bulk Import via CSV</h3></div>
<div class="card-body">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" value="import_csv">
<div class="form-row cols-2">
  <div class="form-group">
    <label>Currency Type</label>
    <select name="import_currency" class="form-control">
      <option value="usd" <?=$f_currency==='usd'?'selected':''?>>USD Accounts</option>
      <option value="zig" <?=$f_currency==='zig'?'selected':''?>>ZiG Accounts</option>
    </select>
  </div>
  <div class="form-group">
    <label>CSV File</label>
    <input type="file" name="banking_csv" accept=".csv,.txt" class="form-control" required>
  </div>
</div>
<div style="font-size:.7rem;color:var(--muted);margin-bottom:.65rem">
  USD: grower_num, account_name, bank_name, branch, account_num, swift_code, mobile_number<br>
  ZiG: grower_num, account_name, bank_name, branch, account_num, mobile_number
</div>
<button type="submit" class="btn btn-primary" style="width:100%" data-progress-label="Importing accounts...">Import CSV</button>
</form>
</div>
</div>
</div><!-- /panel-import -->

<!-- Add single account -->
<div id="panel-add" class="tab-panel" data-tabs="banking">
<div class="card">
<div class="card-head"><h3>Add / Update Account</h3></div>
<div class="card-body">
<form method="POST">
<input type="hidden" name="action" value="save_account">
<div class="form-group">
  <label>Currency</label>
  <select name="currency" class="form-control" id="curr-select" onchange="toggleSwift(this.value)">
    <option value="usd" <?=$f_currency==='usd'?'selected':''?>>USD</option>
    <option value="zig" <?=$f_currency==='zig'?'selected':''?>>ZiG</option>
  </select>
</div>
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
  <div class="form-group"><label>Account Name</label><input type="text" name="account_name" class="form-control" placeholder="As on bank records" required></div>
  <div class="form-group"><label>Bank Name</label><input type="text" name="bank_name" class="form-control" placeholder="e.g. CBZ Bank"></div>
</div>
<div class="form-row cols-2">
  <div class="form-group"><label>Branch</label><input type="text" name="branch" class="form-control" placeholder="e.g. Harare Main"></div>
  <div class="form-group"><label>Account Number</label><input type="text" name="account_num" class="form-control" placeholder="Account #" required></div>
</div>
<div class="form-row cols-2">
  <div class="form-group" id="swift-group"><label>SWIFT Code (USD)</label><input type="text" name="swift_code" class="form-control" placeholder="e.g. CBZWZWHAXXX"></div>
  <div class="form-group"><label>Mobile Number</label><input type="text" name="mobile_number" class="form-control" placeholder="+263..."></div>
</div>
<div class="form-group"><label>Notes</label><input type="text" name="notes" class="form-control" placeholder="Any additional info"></div>
<button type="submit" class="btn btn-primary" style="width:100%">Save Account</button>
</form>
</div>
</div>
</div><!-- /panel-add -->

<script>
function toggleSwift(curr) {
    var sg = document.getElementById('swift-group');
    if (sg) sg.style.display = curr === 'zig' ? 'none' : '';
}
toggleSwift(document.getElementById('curr-select').value);

// ── Inline edit: pre-fill the Add/Update form from a row click ────────────
function editAccount(growerid, currency, bank, branch, acnum, acname, swift, mobile, notes) {
    // The form lives in the Add / Update tab — switch to it first
    switchTab('add', 'banking');

    // Set currency select & toggle SWIFT field
    var cs = document.getElementById('curr-select');
    cs.value = currency;
    toggleSwift(currency);

    // Set grower select
    var gs = document.querySelector('select[name="growerid"]');
    if (gs) gs.value = growerid;

    // Fill text fields
    var set = function(name, val) {
        var el = document.querySelector('form [name="' + name + '"]');
        if (el) el.value = val;
    };
    set('bank_name',     bank);
    set('branch',        branch);
    set('account_num',   acnum);
    set('account_name',  acname);
    set('swift_code',    swift);
    set('mobile_number', mobile);
    set('notes',         notes);

    // Scroll the form into view and highlight it briefly
    var form = document.querySelector('#panel-add .card');
    if (form) {
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        form.style.outline = '2px solid var(--gold)';
        setTimeout(function(){ form.style.outline = ''; }, 1800);
    }
}
</script>

<?php include "includes/layout_end.php"; ?>
