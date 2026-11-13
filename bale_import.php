<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/bale_import.php";
require_login();

$page_title  = "Bale File Import";
$active_page = "bale_import";

$userid   = current_userid();
$seasonid = (int)($_SESSION['seasonid'] ?? 0);
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$today    = date('Y-m-d');

bale_import_ensure_tables($conn);
$fields = bale_import_fields();

// ── CSV template ──────────────────────────────────────────────────────────
// ── JSON template: same fields, as a list of bale records ─────────────
if (($_GET['template'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="bale_import_template.json"');
    echo json_encode(['bales' => [
        ['grower_num'=>'V123456','barcode'=>'650074101A','mass'=>112,'price'=>2.85,'timb_grade'=>'L1O','buyer'=>'BA1','buyer_grade'=>'','sale_code'=>'','sell_date'=>$today,'scale_barcode'=>'VL0076901','lot'=>1,'split'=>1],
        ['grower_num'=>'V123456','barcode'=>'650074102B','mass'=>98,'price'=>null,'timb_grade'=>'','buyer'=>'','buyer_grade'=>'','sale_code'=>'R','sell_date'=>$today,'scale_barcode'=>'VL0076902','lot'=>2,'split'=>1],
    ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bale_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['grower_num','barcode','mass','price','timb_grade','buyer','buyer_grade','sale_code','sell_date','scale_barcode','lot','split']);
    fputcsv($out, ['V123456','650074101A','112','2.85','L1O','BA1','','',$today,'VL0076901','1','1']);
    fputcsv($out, ['V123456','650074102B','98','','','','','R',$today,'VL0076902','2','1']);
    fclose($out);
    exit;
}

$msg = $_SESSION['bale_import_flash'] ?? '';
$err = '';
unset($_SESSION['bale_import_flash']);

/** The uploaded file waiting to be imported, if it is still on disk. */
function bale_import_pending(): ?array {
    $p = $_SESSION['bale_import'] ?? null;
    if (!$p || !is_file($p['path'] ?? '')) { unset($_SESSION['bale_import']); return null; }
    return $p;
}
function bale_import_discard(): void {
    $p = $_SESSION['bale_import'] ?? null;
    if ($p && is_file($p['path'] ?? '')) @unlink($p['path']);
    unset($_SESSION['bale_import']);
}

$action = $_POST['action'] ?? '';

// ── Upload ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    $f = $_FILES['bale_file'] ?? null;
    $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $err = 'Choose a file to upload.';
    } elseif ($f['size'] > BALE_IMPORT_MAX_BYTES) {
        $err = 'The file is larger than 5 MB. Split it into smaller files.';
    } elseif (!in_array($ext, ['csv', 'txt', 'tsv', 'json', 'v'], true)) {
        $err = 'Upload a CSV file (.csv, .txt or .tsv), a JSON file (.json) or a TIMB bale file (.v). From Excel, use Save As → CSV.';
    } else {
        bale_import_discard();
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flowork_bale_import_' . bin2hex(random_bytes(12)) . ($ext === 'json' ? '.json' : '.csv');
        if (!move_uploaded_file($f['tmp_name'], $path)) {
            $err = 'The upload could not be saved. Try again.';
        } else {
            $_SESSION['bale_import'] = [
                'path' => $path, 'filename' => basename($f['name']), 'sha1' => sha1_file($path),
                'source' => trim($_POST['source'] ?? ''),
            ];
            header('Location: bale_import.php'); exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'discard') {
    bale_import_discard();
    header('Location: bale_import.php'); exit;
}

// ── Undo an import ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'undo') {
    $r = bale_import_reverse($conn, (int)($_POST['import_id'] ?? 0), $spid, $userid);
    if (!empty($r['ok'])) {
        $_SESSION['bale_import_flash'] = "Import #" . (int)$_POST['import_id'] . " undone — {$r['bales']} bale(s) in {$r['deliveries']} delivery(ies) removed.";
        header('Location: bale_import.php'); exit;
    }
    $err = $r['error'];
}

// ── Check / import the pending file ───────────────────────────────────────
$pending = bale_import_pending();
$file = null; $map = null; $check = null;
$opt = [
    'source'            => $pending['source'] ?? '',
    'default_sell_date' => '',
    'zig_rate'          => '',
    'default_buyer'     => 0,      // buyer for sold bales the file gives none (e.g. TIMB bale files)
];
// Buyers on this floor, for "Buyer for these bales"
$_bq = $conn->query("SELECT id, TRIM(buyer_code) code, TRIM(name) name FROM buyer
    WHERE active=1 AND (selling_pointid=$spid OR selling_pointid=0) ORDER BY name");
$buyer_opts = ($_bq && $_bq !== true) ? $_bq->fetch_all(MYSQLI_ASSOC) : [];
// Transporters on this floor, for the delivery note the imported growers go onto
$_tq = $conn->query("SELECT id, TRIM(name) name, TRIM(COALESCE(truck_num,'')) truck FROM transporter
    WHERE selling_pointid=$spid AND active=1 AND seasonid=" . (int)$seasonid . " AND name NOT LIKE 'Import:%' ORDER BY name");
$transporter_opts = ($_tq && $_tq !== true) ? $_tq->fetch_all(MYSQLI_ASSOC) : [];
// Bale locations on this floor: EOD reports filter bales by their transporter's location
$_lq = $conn->query("SELECT id, TRIM(code) code, TRIM(name) name FROM bale_locations WHERE selling_pointid=$spid AND active=1 ORDER BY code");
$loc_opts = ($_lq && $_lq !== true) ? $_lq->fetch_all(MYSQLI_ASSOC) : [];
$opt['location'] = $loc_opts ? (int)$loc_opts[0]['id'] : 0;
$opt['transporter'] = '0';      // '0' = import transporter, 'new', or a transporter id
$opt['new_tp_name'] = ''; $opt['new_tp_truck'] = '';
$tp_label = 'Import transporter';
// The import only brings the sale in; the floor balances it on Sale Balancing,
// then prints sales sheets and runs EOD reports from what balancing stored.
if ($pending) {
    $file = bale_import_read_file($pending['path']);
    if (isset($file['error'])) { $err = $file['error']; bale_import_discard(); $pending = null; $file = null; }
}
if ($pending) {
    $map = bale_import_automap($file['headers']);
    $_sd = $conn->query("SELECT description FROM start_of_day WHERE selling_pointid=$spid AND active=1 ORDER BY id DESC LIMIT 1");
    $opt['default_sell_date'] = ($_sd && $_sd !== true && $_sd->num_rows) ? $_sd->fetch_assoc()['description'] : $today;
    $_lr = $conn->query("SELECT er.amount FROM exchange_rate er JOIN start_of_day sd ON sd.id=er.start_of_dayid
        WHERE sd.selling_pointid=$spid AND er.amount>0 ORDER BY sd.description DESC, er.active DESC, er.id DESC LIMIT 1");
    $latest_rate = ($_lr && $_lr !== true && $_lr->num_rows) ? (float)$_lr->fetch_assoc()['amount'] : 0;
    // A TIMB file name carries the sell date (VLBL0522.v = 22 May); the file may carry the ZiG rate
    $fn_date = bale_import_date_from_filename($pending['filename'] ?? '');
    if ($fn_date) $opt['default_sell_date'] = $fn_date;
    if (!empty($file['rate'])) $opt['zig_rate'] = number_format((float)$file['rate'], 4, '.', '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['check', 'import'], true)) {
        foreach ($fields as $key => $_) {
            $i = (int)($_POST['map'][$key] ?? -1);
            $map[$key] = ($i >= 0 && $i < count($file['headers'])) ? $i : -1;
        }
        $opt['source']            = trim($_POST['source'] ?? '');
        $opt['default_sell_date'] = bale_import_parse_date($_POST['default_sell_date'] ?? '') ?? '';
        $opt['zig_rate']          = trim($_POST['zig_rate'] ?? '');
        $opt['default_buyer']     = (int)($_POST['default_buyer'] ?? 0);
        $buyer_label = '';
        foreach ($buyer_opts as $bo) if ((int)$bo['id'] === $opt['default_buyer']) $buyer_label = $bo['code'] !== '' ? $bo['code'] : $bo['name'];
        if ($buyer_label === '') $opt['default_buyer'] = 0;   // not a buyer on this floor
        $opt['location'] = (int)($_POST['location'] ?? 0);
        if (!in_array($opt['location'], array_map('intval', array_column($loc_opts, 'id')), true)) $opt['location'] = 0;
        $opt['transporter']  = (string)($_POST['transporter'] ?? '0');
        $opt['new_tp_name']  = trim($_POST['new_tp_name'] ?? '');
        $opt['new_tp_truck'] = trim($_POST['new_tp_truck'] ?? '');
        $tp_id = 0; $tp_err = '';
        if ($opt['transporter'] === 'new') {
            if ($opt['new_tp_name'] === '' || $opt['new_tp_truck'] === '') $tp_err = 'Enter the new transporter\'s name and truck number.';
            else $tp_label = $opt['new_tp_name'] . ' (' . $opt['new_tp_truck'] . ', new)';
        } elseif ((int)$opt['transporter'] > 0) {
            foreach ($transporter_opts as $to) if ((int)$to['id'] === (int)$opt['transporter']) {
                $tp_id = (int)$to['id']; $tp_label = $to['name'] . ($to['truck'] !== '' ? " ({$to['truck']})" : '');
            }
            if (!$tp_id) { $opt['transporter'] = '0'; $tp_err = 'Choose the transporter again; that one is not on this floor.'; }
        }

        $check = bale_import_check($conn, $spid, $file['rows'], $map, [
            'default_sell_date'   => $opt['default_sell_date'],
            'zig_rate'            => (float)$opt['zig_rate'],
            'default_buyerid'     => $opt['default_buyer'],
            'default_buyer_label' => $buyer_label,
        ]);
        $sha = $conn->real_escape_string($pending['sha1']);
        $dup = $conn->query("SELECT id, created_at FROM bale_imports WHERE file_sha1='$sha' AND selling_pointid=$spid AND status='imported' LIMIT 1");
        if ($dup && $dup !== true && $dup->num_rows) {
            $d = $dup->fetch_assoc();
            array_unshift($check['errors'], [0, "This exact file was already imported (import #{$d['id']}, {$d['created_at']}). Undo that import first to bring it in again."]);
        }
        if ($tp_err) array_unshift($check['errors'], [0, $tp_err]);
        if (!$seasonid) array_unshift($check['errors'], [0, 'No active season. Activate one in Settings → Seasons first.']);
        if (!$spid)     array_unshift($check['errors'], [0, 'Choose a floor first.']);

        if ($action === 'import' && !$check['errors']) {
            $r = bale_import_commit($conn, $spid, $seasonid, $userid, $check,
                ['source' => $opt['source'], 'filename' => $pending['filename'], 'sha1' => $pending['sha1'],
                 'transporterid' => $tp_id,
                 'locationid' => $opt['location'],
                 'new_transporter' => $opt['transporter'] === 'new' ? ['name' => $opt['new_tp_name'], 'truck' => $opt['new_tp_truck']] : null]);
            if (!empty($r['ok'])) {
                bale_import_discard();
                $t = $check['totals'];
                $flash = "Import #{$r['import_id']}: {$t['bales']} bale(s) for {$t['deliveries']} delivery(ies) imported";
                $flash .= ". The growers and their bale counts are on <a href=\"grower_booking.php\">Booking</a> and on the "
                        . htmlspecialchars($tp_label, ENT_QUOTES) . " delivery note in <a href=\"transporters.php\">Transporters</a>"
                        . "; the bales are ready to balance on <a href=\"balance.php\">Sale Balancing</a>.";
                $_SESSION['bale_import_flash'] = $flash;
                header('Location: bale_import.php'); exit;
            }
            $err = $r['error'];
        }
    }
}

// ── History ───────────────────────────────────────────────────────────────
$hq = $conn->query("SELECT bi.*, u.username FROM bale_imports bi LEFT JOIN users u ON u.id=bi.userid
    WHERE bi.selling_pointid=$spid ORDER BY bi.id DESC LIMIT 30");
$history = ($hq && $hq !== true) ? $hq->fetch_all(MYSQLI_ASSOC) : [];

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left">
    <h2>Bale File Import</h2>
    <p>Bring in bales sold on another system and produce their sales sheets and EOD reports here</p>
  </div>
  <div class="page-header-right">
    <a href="bale_import.php?template=1" class="btn btn-ghost">⬇ CSV template</a>
    <a href="bale_import.php?template=json" class="btn btn-ghost">⬇ JSON template</a>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=$msg?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=$h($err)?></div><?php endif;?>

<?php if(!$pending):?>
<!-- Step 1: upload -->
<div class="card mb-3">
<div class="card-head"><h3>1 · Upload bale file</h3></div>
<div class="card-body">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" value="upload">
<div class="form-row cols-2" style="align-items:flex-end">
  <div class="form-group" style="margin:0">
    <label>Bale file (CSV, JSON or TIMB bale file .v)</label>
    <input type="file" name="bale_file" class="form-control" accept=".csv,.txt,.tsv,.json,.v,text/csv,application/json" required>
  </div>
  <div class="form-group" style="margin:0">
    <label>Source system / floor</label>
    <input type="text" name="source" class="form-control" maxlength="80" placeholder="e.g. Floor B receiving system">
  </div>
</div>
<button type="submit" class="btn btn-primary" style="margin-top:.85rem">Upload and map columns →</button>
</form>
<div style="font-size:.75rem;color:var(--muted);margin-top:1rem;line-height:1.6">
  Each row is one bale. Needed: <strong>grower number</strong>, <strong>ticket barcode</strong>, <strong>mass</strong>;
  and for sold bales <strong>price</strong> (USD/kg, or cents), <strong>TIMB grade</strong> and <strong>buyer code</strong>.
  Rejected bales carry sale code R, CO or RO and need no price.
  Optional: buyer grade (defaults to the TIMB grade), sell date, scale/TIMB tag, lot, group, split, sales rep.
  Column names do not have to match: you choose which column is which in the next step.
  Comma, semicolon, tab and pipe separated files are all read.
  <br>JSON files: a list of bale records, e.g. <code>[{"grower_num":"V123456","barcode":"650074101A","mass":112,"price":2.85,…}]</code>,
  or the same list under a key such as <code>"bales"</code> or <code>"data"</code>. Each record's fields become the columns to match;
  nested fields such as <code>{"grower":{"num":…}}</code> show as <code>grower_num</code>.
  <br>TIMB bale files (<code>.v</code>, e.g. <code>VLBL0522.v</code>) are read as they are: the sell date is taken from the file name,
  the ZiG rate from the file, and you choose the buyer, since the file has none.
</div>
</div>
</div>
<?php else:?>
<!-- Step 2: map and check -->
<form method="POST" id="importForm">
<div class="card mb-3">
<div class="card-head">
  <h3>2 · Map columns and check</h3>
  <span class="badge badge-gray"><?=$h($pending['filename'])?> · <?=count($file['rows'])?> row(s)<?= ($file['format'] ?? '') === 'timb_bl' ? ' · TIMB bale file' : '' ?></span>
</div>
<div class="card-body">
<?php foreach (($file['notes'] ?? []) as $fnote): ?>
<div class="alert alert-info" style="margin-bottom:.85rem"><?=$h($fnote)?></div>
<?php endforeach; ?>
<div class="form-row cols-3" style="margin-bottom:1rem">
  <div class="form-group" style="margin:0">
    <label>Source system / floor</label>
    <input type="text" name="source" class="form-control" maxlength="80" value="<?=$h($opt['source'])?>">
  </div>
  <div class="form-group" style="margin:0">
    <label>Sell date (rows without one)</label>
    <input type="date" name="default_sell_date" class="form-control" max="<?=$today?>" value="<?=$h($opt['default_sell_date'])?>">
  </div>
  <div class="form-group" style="margin:0">
    <label>ZiG rate for days not open on this floor</label>
    <input type="number" name="zig_rate" class="form-control" step="0.0001" min="0" placeholder="<?=$latest_rate?'Latest: '.number_format($latest_rate,4,'.',''):'Only if needed'?>" value="<?=$h($opt['zig_rate'])?>">
    <div style="font-size:.68rem;color:var(--muted);margin-top:.25rem">Only used for sell dates not opened in Day Control.</div>
  </div>
</div>
<div class="form-row cols-3" style="margin-bottom:1rem">
  <div class="form-group" style="margin:0">
    <label for="bi-buyer">Buyer for these bales<?= ($file['format'] ?? '') === 'timb_bl' ? ' *' : '' ?></label>
    <select name="default_buyer" id="bi-buyer" class="form-control">
      <option value="0"><?= ($file['format'] ?? '') === 'timb_bl' ? 'Choose the buyer…' : 'Use the buyer column in the file' ?></option>
      <?php foreach ($buyer_opts as $bo): ?>
      <option value="<?=(int)$bo['id']?>" <?=(int)$opt['default_buyer']===(int)$bo['id']?'selected':''?>><?=$h($bo['name'])?><?= $bo['code'] !== '' ? ' (' . $h($bo['code']) . ')' : '' ?></option>
      <?php endforeach; ?>
    </select>
    <div style="font-size:.68rem;color:var(--muted);margin-top:.25rem">Used for every sold bale that has no buyer in the file.</div>
  </div>
  <div class="form-group" style="margin:0">
    <label for="bi-tp">Transporter (delivery note)</label>
    <select name="transporter" id="bi-tp" class="form-control"
            onchange="document.getElementById('bi-tp-new').style.display=this.value==='new'?'':'none'">
      <option value="0">Import transporter</option>
      <?php foreach ($transporter_opts as $to): ?>
      <option value="<?=(int)$to['id']?>" <?=$opt['transporter']===(string)$to['id']?'selected':''?>><?=$h($to['name'])?><?= $to['truck'] !== '' ? ' (' . $h($to['truck']) . ')' : '' ?></option>
      <?php endforeach; ?>
      <option value="new" <?=$opt['transporter']==='new'?'selected':''?>>+ New transporter…</option>
    </select>
    <div style="font-size:.68rem;color:var(--muted);margin-top:.25rem">The growers go onto this transporter's delivery note in Receiving. Growers already on a booked delivery note stay on it.</div>
  </div>
  <?php if ($loc_opts): ?>
  <div class="form-group" style="margin:0">
    <label for="bi-loc">Location</label>
    <select name="location" id="bi-loc" class="form-control">
      <option value="0">— none —</option>
      <?php foreach ($loc_opts as $lo): ?>
      <option value="<?=(int)$lo['id']?>" <?=(int)$opt['location']===(int)$lo['id']?'selected':''?>><?=$h($lo['code'])?> · <?=$h($lo['name'])?></option>
      <?php endforeach; ?>
    </select>
    <div style="font-size:.68rem;color:var(--muted);margin-top:.25rem">For the import or new transporter. EOD reports filtered by location find the bales here.</div>
  </div>
  <?php endif; ?>
  <div class="form-group" id="bi-tp-new" style="margin:0;<?=$opt['transporter']==='new'?'':'display:none'?>">
    <label>New transporter</label>
    <div style="display:flex;gap:.4rem">
      <input type="text" name="new_tp_name" class="form-control" placeholder="Name" maxlength="100" value="<?=$h($opt['new_tp_name'])?>">
      <input type="text" name="new_tp_truck" class="form-control" placeholder="Truck no." maxlength="100" value="<?=$h($opt['new_tp_truck'])?>" style="max-width:9rem">
    </div>
  </div>
</div>
<p style="font-size:.8rem;color:var(--muted);margin:0 0 1rem">Importing brings the bales in, ready to balance on Sale Balancing. Print sales sheets and run EOD reports after balancing.</p>

<div class="table-wrap"><table>
<thead><tr><th>Field</th><th>Column in the file</th><th>First rows</th></tr></thead>
<tbody>
<?php foreach($fields as $key => [$label, $req]):
  $i = $map[$key]; ?>
<tr>
  <td><?=$h($label)?><?php if($req==='always'):?> <span style="color:var(--danger)">*</span><?php elseif($req==='sold'):?> <span style="color:var(--gold)" title="Needed for sold bales">*</span><?php endif;?></td>
  <td>
    <select name="map[<?=$key?>]" class="form-control" style="min-width:180px">
      <option value="-1">— not in file —</option>
      <?php foreach($file['headers'] as $ci => $hd):?>
      <option value="<?=$ci?>" <?=$i===$ci?'selected':''?>><?=$h($hd !== '' ? $hd : 'Column '.($ci+1))?></option>
      <?php endforeach;?>
    </select>
  </td>
  <td class="td-mono" style="font-size:.72rem;color:var(--muted)">
    <?php if($i >= 0) echo $h(implode(' · ', array_map(fn($r) => $r[1][$i] ?? '', array_slice($file['rows'], 0, 3))));?>
  </td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
<div style="font-size:.7rem;color:var(--muted);margin-top:.4rem"><span style="color:var(--danger)">*</span> always needed · <span style="color:var(--gold)">*</span> needed for sold bales</div>

<div style="display:flex;gap:.5rem;margin-top:1rem;flex-wrap:wrap">
  <button type="submit" name="action" value="check" class="btn btn-primary">Check file</button>
  <?php if($check && !$check['errors']):?>
  <button type="submit" name="action" value="import" class="btn btn-primary" style="background:var(--leaf)"
    onclick="return confirm('Import <?=$check['totals']['bales']?> bale(s) for <?=$check['totals']['deliveries']?> delivery(ies)?')">
    ✓ Import <?=$check['totals']['bales']?> bale(s)
  </button>
  <?php endif;?>
  <button type="submit" name="action" value="discard" class="btn btn-ghost" formnovalidate>Cancel</button>
</div>
</div>
</div>
</form>

<?php if($check):
  $t = $check['totals']; ?>
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-bottom:1.25rem">
  <div class="kpi-card"><div class="kpi-label">Bales ready</div><div class="kpi-value"><?=number_format($t['bales'] ?? 0)?></div>
    <div class="kpi-sub"><?=number_format($t['rejected'] ?? 0)?> rejected</div></div>
  <div class="kpi-card"><div class="kpi-label">Deliveries</div><div class="kpi-value"><?=number_format($t['deliveries'] ?? 0)?></div>
    <div class="kpi-sub">one per grower per sell date</div></div>
  <div class="kpi-card"><div class="kpi-label">Mass sold</div><div class="kpi-value"><?=number_format($t['mass'] ?? 0, 0)?> kg</div></div>
  <div class="kpi-card"><div class="kpi-label">Gross value</div><div class="kpi-value">$<?=number_format($t['usd'] ?? 0, 2)?></div></div>
  <div class="kpi-card"><div class="kpi-label">Problems</div>
    <div class="kpi-value <?=$check['errors']?'':'text-leaf'?>" style="<?=$check['errors']?'color:var(--danger)':''?>"><?=count($check['errors'])?></div>
    <div class="kpi-sub"><?=$check['errors']?'fix the file and upload again':'ready to import'?></div></div>
</div>

<?php if($check['errors']):?>
<div class="card mb-3">
<div class="card-head"><h3>Problems — nothing will be imported until these are fixed</h3><span class="badge badge-red"><?=count($check['errors'])?></span></div>
<div class="table-wrap" style="max-height:360px;overflow:auto"><table>
<thead><tr><th style="width:80px"><?= (($file['unit'] ?? 'line') === 'record') ? 'Record' : 'Line' ?></th><th>Problem</th></tr></thead>
<tbody>
<?php foreach(array_slice($check['errors'], 0, 300) as [$line, $e]):?>
<tr><td class="td-mono"><?=$line ?: '—'?></td><td><?=$h($e)?></td></tr>
<?php endforeach;?>
<?php if(count($check['errors']) > 300):?><tr><td></td><td class="text-muted">… and <?=count($check['errors'])-300?> more</td></tr><?php endif;?>
</tbody>
</table></div>
</div>
<?php endif;?>

<?php if($check['notes']):?>
<div class="alert" style="background:rgba(200,149,42,.08);border-left:3px solid var(--gold);padding:.75rem 1rem;font-size:.82rem">
  <?php foreach($check['notes'] as $n):?><div>• <?=$h($n)?></div><?php endforeach;?>
</div>
<?php endif;?>

<?php if($check['deliveries']):?>
<div class="card mb-3">
<div class="card-head"><h3>Growers going to Booking and Receiving</h3></div>
<div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow:auto"><table>
<thead><tr><th>Grower</th><th>Name</th><th>Sell date</th><th class="text-right">Bales</th><th class="text-right">Rejected</th>
  <th class="text-right">Mass (kg)</th><th class="text-right">Gross USD</th><th>Booking</th><th>Delivery note</th></tr></thead>
<tbody>
<?php foreach($check['deliveries'] as $dv):?>
<tr>
  <td class="td-mono"><?=$h($dv['grower_num'])?></td>
  <td><?=$h($dv['grower_name'])?></td>
  <td><?=$h($dv['sell_date'])?></td>
  <td class="text-right"><?=$dv['bales']?><?=count($dv['splits'])>1?' <span class="text-muted" style="font-size:.7rem">('.count($dv['splits']).' splits)</span>':''?></td>
  <td class="text-right"><?=$dv['rejected']?></td>
  <td class="text-right td-mono"><?=number_format($dv['mass'],0)?></td>
  <td class="text-right td-mono"><?=number_format($dv['usd'],2)?></td>
  <td><?=$dv['booking'] ? '<span class="badge badge-green">Booked · bales updated</span>' : '<span class="badge badge-gray">Will book</span>'?></td>
  <td><?=$dv['delivery'] ? '<span class="badge badge-green">Booked note</span>' : $h($tp_label)?></td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
</div>
<?php endif;?>
<?php endif; // check ?>
<?php endif; // pending ?>

<!-- History -->
<div class="card">
<div class="card-head"><h3>Imports on this floor</h3><span class="badge badge-gray">Last 30</span></div>
<div class="table-wrap"><table>
<thead><tr><th>#</th><th>When</th><th>Source</th><th>File</th><th>Sell date(s)</th>
  <th class="text-right">Bales</th><th class="text-right">Deliveries</th><th class="text-right">Balanced</th>
  <th class="text-right">Gross USD</th><th>By</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php if(!$history):?>
<tr><td colspan="12" class="text-center text-muted" style="padding:2rem">No bale files imported yet.</td></tr>
<?php else: foreach($history as $r):
  $live = $r['status'] === 'imported'; ?>
<tr style="<?=$live?'':'opacity:.55'?>">
  <td class="td-mono"><?=(int)$r['id']?></td>
  <td style="font-size:.75rem"><?=$h($r['created_at'])?></td>
  <td><?=$h($r['source_system'] ?: '—')?></td>
  <td style="font-size:.75rem"><?=$h($r['filename'])?></td>
  <td style="font-size:.75rem"><?=$h($r['sell_dates'])?></td>
  <td class="text-right"><?=(int)$r['bales']?></td>
  <td class="text-right"><?=(int)$r['deliveries']?></td>
  <td class="text-right"><?=(int)$r['balanced']?></td>
  <td class="text-right td-mono"><?=number_format((float)$r['total_usd'],2)?></td>
  <td style="font-size:.75rem"><?=$h($r['username'] ?? '')?></td>
  <td><span class="badge <?=$live?'badge-green':'badge-gray'?>"><?=$live?'Imported':'Undone'?></span></td>
  <td>
    <?php if($live):?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Undo import #<?=(int)$r['id']?>? Its balanced deliveries are reversed and its <?=(int)$r['bales']?> bale(s) removed.')">
      <input type="hidden" name="action" value="undo">
      <input type="hidden" name="import_id" value="<?=(int)$r['id']?>">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem">Undo</button>
    </form>
    <?php endif;?>
  </td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table></div>
</div>

<?php include "includes/layout_end.php"; ?>
