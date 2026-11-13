<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

// ── AES DECRYPTION HELPERS (same pattern as file_manager_uploads.php) ───────
function so_get_keys($conn, $spid) {
    $spid = (int)$spid;
    $r = $conn->query("SELECT system_key, vector FROM stop_order_keys WHERE selling_pointid=$spid LIMIT 1");
    if (!$r || $r->num_rows === 0) return null;
    return $r->fetch_assoc();
}
function so_printable_ratio($s) {
    $n = min(200, strlen($s)); if (!$n) return 0;
    $c = 0; for ($i=0;$i<$n;$i++){$o=ord($s[$i]);if($o>=32&&$o<=126)$c++;} return $c/$n;
}
function so_try_aes($data, $key, $iv='') {
    if (!$key || strlen($key)<8) return false;
    $data = trim($data);
    if ($iv) {
        $pt = @openssl_decrypt($data,'AES256',$key,0,$iv);
        if ($pt!==false&&strlen($pt)>0&&so_printable_ratio($pt)>0.55) return $pt;
        $pt = @openssl_decrypt($data,'aes-256-cbc',$key,0,$iv);
        if ($pt!==false&&strlen($pt)>0&&so_printable_ratio($pt)>0.55) return $pt;
    }
    $raw = base64_decode($data,true);
    if ($raw===false||strlen($raw)<16) return false;
    $iv_pad = $iv ? str_pad(substr($iv,0,16),16,"\0") : str_repeat("\0",16);
    if ($iv) {
        $pt = @openssl_decrypt($raw,'AES-256-CBC',$key,OPENSSL_RAW_DATA,$iv_pad);
        if ($pt!==false&&strlen($pt)>0&&so_printable_ratio($pt)>0.55) return $pt;
    }
    if (preg_match('/^[0-9A-Fa-f]+$/',$key)&&strlen($key)%2===0) {
        $kb=hex2bin($key);$bits=min(256,strlen($kb)*8);
        if ($bits>=128){$kb=str_pad($kb,$bits/8,"\0");
            $pt=@openssl_decrypt($raw,"AES-{$bits}-CBC",$kb,OPENSSL_RAW_DATA,$iv_pad);
            if ($pt!==false&&strlen($pt)>0&&so_printable_ratio($pt)>0.55) return $pt;}
    }
    return false;
}
function so_decrypt($raw, $aes_key='', $aes_iv='') {
    if ($aes_key&&$aes_iv){$r=so_try_aes($raw,$aes_key,$aes_iv);if($r!==false)return $r;}
    if ($aes_key)          {$r=so_try_aes($raw,$aes_key,'');     if($r!==false)return $r;}
    if (!preg_match('/[^ -~\r\n\t]/',substr($raw,0,400))) return $raw;
    $dec=base64_decode(trim($raw),true);
    if ($dec!==false&&strlen($dec)>10&&!preg_match('/[^ -~\r\n\t]/',substr($dec,0,200))) return $dec;
    return false;
}
// TIMB fixed-width field lengths for stop order file
define('SO_FIELD_LENGTHS',[3,2,2,7,1,8,1,8,10,10,10,10,6,8,1,7]);
function so_parse_fixed($line,$lengths){
    $r=[];$p=0;foreach($lengths as $l){$r[]=substr($line,$p,$l);$p+=$l;}return $r;
}
function so_fmt_date($d){
    return strlen($d)===8?substr($d,0,4).'-'.substr($d,4,2).'-'.substr($d,6,2):null;
}
function so_parse_line($line){
    $line=trim($line);
    $line_len = strlen($line);
    // Try fixed-width if long enough
    if ($line_len >= 50){
        if ($line_len >= 115) {
            // TIMB 124-character format parsing
            $sale_id     = trim(substr($line, 0, 3));
            $gr_num      = trim(substr($line, 3, 9));
            $cred_no     = trim(substr($line, 12, 7));
            if (strlen($cred_no) > 2) {
                $cred_no = substr($cred_no, 0, -2);
            }
            $amt1        = (float)substr($line, 43, 20) / 10000.0;
            $amt2        = (float)substr($line, 64, 20) / 10000.0;
            $amt3        = (float)substr($line, 85, 20) / 10000.0;
            $pct         = (float)substr($line, 106, 7);
            $sos_raw     = substr($line, 113, 8);
            $type        = (int)substr($line, 121, 1);
            $serial      = trim(substr($line, 122));
            
            $rec_type    = 0;
            $gr_type     = '';
            $priority    = 1;
            $cred_ref    = '';
            $acct_no     = '';
        } else {
            // Original 94-character format parsing
            $f=so_parse_fixed($line,SO_FIELD_LENGTHS);
            $sale_id   =trim($f[0]??'');
            $rec_type  =(int)trim($f[1]??0);
            $gr_type   =trim($f[2]??'');
            $gr_num_p  =trim($f[3]??'');
            $suffix    =trim($f[4]??'');
            $cred_no   =trim($f[5]??'');
            $priority  =(int)trim($f[6]??0);
            $cred_ref  =trim($f[7]??'');
            $acct_no   =trim($f[8]??'');
            $amt1      =(float)trim($f[9]??0);
            $amt2      =(float)trim($f[10]??0);
            $amt3      =(float)trim($f[11]??0);
            $pct       =(float)trim($f[12]??0);
            $sos_raw   =trim($f[13]??'');
            $type      =(int)trim($f[14]??0);
            $serial    =trim($f[15]??'');
            $gr_num    =$gr_type.$gr_num_p.$suffix;
        }
        return compact('sale_id','rec_type','gr_type','gr_num','cred_no','priority',
                       'cred_ref','acct_no','amt1','amt2','amt3','pct','sos_raw','type','serial');
    }
    // Fallback: try CSV
    $cols=str_getcsv($line,',');
    if(count($cols)<2)$cols=str_getcsv($line,';');
    return [
        'sale_id'=>'','rec_type'=>1,'gr_type'=>'','gr_num'=>strtoupper(trim($cols[0]??'')),
        'cred_no'=>trim($cols[1]??''),'priority'=>1,'cred_ref'=>'','acct_no'=>'',
        'amt1'=>(float)trim($cols[2]??0),'amt2'=>(float)trim($cols[3]??0),'amt3'=>0,'pct'=>0,
        'sos_raw'=>trim($cols[4]??''),'type'=>0,'serial'=>''
    ];
}

$page_title  = "Stop Orders";
$active_page = "stop_orders";

$userid   = current_userid();
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$seasonid = (int)($_SESSION['seasonid'] ?? 0);

$msg = $err = '';

// Ensure stop_order_keys table exists
$conn->query("CREATE TABLE IF NOT EXISTS stop_order_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    selling_pointid INT NOT NULL DEFAULT 0,
    system_key VARCHAR(512) NOT NULL DEFAULT '',
    vector VARCHAR(256) NOT NULL DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sk_sp (selling_pointid)
)");

// Check keys
$so_keys     = so_get_keys($conn, $spid);
$has_so_keys = !empty($so_keys['system_key']);

// TIMB preview from session
$timb_so_preview = $_SESSION['timb_so_preview'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add external stop order ──────────────────────────────────────────
    if ($action === 'add_so') {
        $grower_num   = $conn->real_escape_string(trim($_POST['grower_num'] ?? ''));
        $creditor_no  = $conn->real_escape_string(trim($_POST['creditor_no'] ?? ''));
        $creditor_ref = $conn->real_escape_string(trim($_POST['creditor_ref'] ?? ''));
        $account_no   = $conn->real_escape_string(trim($_POST['account_no'] ?? ''));
        $amount_1     = $conn->real_escape_string(trim($_POST['amount_1'] ?? '0'));
        $amount_2     = $conn->real_escape_string(trim($_POST['amount_2'] ?? '0'));
        $amount_3     = $conn->real_escape_string(trim($_POST['amount_3'] ?? '0'));
        $percent      = (int)($_POST['percent'] ?? 0);
        $priority     = (int)($_POST['priority'] ?? 1);
        $so_type      = $conn->real_escape_string(trim($_POST['type'] ?? ''));
        $grower_type  = $conn->real_escape_string(trim($_POST['grower_type'] ?? ''));
        $serial_no    = $conn->real_escape_string(trim($_POST['serial_no'] ?? ''));
        $sos_date     = $conn->real_escape_string(trim($_POST['sos_date'] ?? date('Y-m-d')));
        $sale_id      = $conn->real_escape_string(trim($_POST['sale_id'] ?? ''));
        $now          = date('Y-m-d H:i:s');

        $gr = find_grower_normalized($conn, $grower_num, $spid);
        if (!$gr) { $err = "Grower '$grower_num' not found at this selling point."; }
        else {
            $growerid = (int)$gr['id'];
            $unixT    = strtotime($sos_date);
            $fmt_date = date("d-M-y", $unixT);
            $sql = "INSERT INTO stoporders
                    (userid,selling_pointid,seasonid,growerid,sale_id,record_type,grower_type,
                     creditor_no,priority,creditor_ref,account_no,amount_1,amount_2,amount_3,
                     percent,sos_date,type,serial_no,created_at,formatted_sos_date)
                    VALUES
                    ($userid,$spid,$seasonid,$growerid,'$sale_id',1,'$grower_type',
                     '$creditor_no',$priority,'$creditor_ref','$account_no','$amount_1','$amount_2','$amount_3',
                     $percent,'$sos_date','$so_type','$serial_no','$now','$fmt_date')";
            if ($conn->query($sql)) $msg = "Stop order added for grower $grower_num.";
            else $err = "DB Error: " . $conn->error;
        }
    }

    // ── Add internal stop order ──────────────────────────────────────────
    if ($action === 'add_iso') {
        $desc        = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $creditor_num = (int)($_POST['creditor_number'] ?? 0);
        if (!$desc) { $err = "Description required."; }
        else {
            if ($conn->query("INSERT INTO internal_stop_order (userid,selling_pointid,description,creditor_number) VALUES ($userid,$spid,'$desc',$creditor_num)"))
                $msg = "Internal stop order '$desc' created.";
            else $err = $conn->error;
        }
    }

    // ── Add internal SO amount ───────────────────────────────────────────
    if ($action === 'add_iso_amount') {
        $isoid         = (int)($_POST['internal_stop_orderid'] ?? 0);
        $charge_typeid = (int)($_POST['charge_typeid'] ?? 0);
        $amount        = (float)($_POST['amount'] ?? 0);
        $priority      = (int)($_POST['priority'] ?? 1);
        $default_ded   = (int)($_POST['default_deduction'] ?? 0);
        if (!$isoid || !$charge_typeid || $amount <= 0) { $err = "All fields required."; }
        else {
            $conn->query("INSERT INTO internal_stop_order_amount (userid,internal_stop_orderid,seasonid,charge_typeid,amount,priority,default_deduction) VALUES ($userid,$isoid,$seasonid,$charge_typeid,$amount,$priority,$default_ded)");
            $msg = "Amount added.";
        }
    }

    // ── Assign internal SO to split ──────────────────────────────────────
    if ($action === 'assign_split') {
        $iso_amountid       = (int)($_POST['iso_amountid'] ?? 0);
        $transporter_growersid = (int)($_POST['transporter_growersid'] ?? 0);
        $splitsid           = (int)($_POST['splitsid'] ?? 1);
        $quantity           = (int)($_POST['quantity'] ?? 1);
        if (!$iso_amountid || !$transporter_growersid) { $err = "Select amount and grower group."; }
        else {
            $conn->query("INSERT INTO split_internal_stop_order (userid,internal_stop_order_amountid,transporter_growersid,splitsid,quantity) VALUES ($userid,$iso_amountid,$transporter_growersid,$splitsid,$quantity)");
            $msg = "Split assigned.";
        }
    }

    // ── Delete external stop order ───────────────────────────────────────
    if ($action === 'delete_so') {
        $soid = (int)($_POST['soid'] ?? 0);
        if ($soid) { $conn->query("DELETE FROM stoporders WHERE id=$soid AND selling_pointid=$spid"); $msg = "Stop order removed."; }
    }

    // ── TIMB Stop Order File Upload & Decrypt ─────────────────────────────
    if ($action === 'upload_timb_so') {
        header('Content-Type: application/json');
        if (empty($_FILES['timb_so_file']['tmp_name']) || $_FILES['timb_so_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error'=>'No file uploaded or upload error.']); exit;
        }
        $raw      = file_get_contents($_FILES['timb_so_file']['tmp_name']);
        $kp       = so_get_keys($conn, $spid);
        $aes_key  = $kp['system_key'] ?? '';
        $aes_iv   = $kp['vector']     ?? '';
        $decrypted = so_decrypt($raw, $aes_key, $aes_iv);
        if ($decrypted === false) {
            echo json_encode(['error'=>'Could not decrypt the file. '.
                ($aes_key?'The stored stop order key did not work. ':'No stop order key configured. ').
                'Go to Settings → TIMB File Keys to enter your key.']); exit;
        }
        $lines = array_values(array_filter(
            explode("\n", str_replace(["\r\n","\r"],"\n",$decrypted)),
            fn($l)=>trim($l)!==''
        ));
        // Get active season
        $_sea = $conn->query("SELECT id FROM seasons WHERE active=1 LIMIT 1");
        $seasonid_so = ($_sea&&$_sea!==true&&$_sea->num_rows)?(int)$_sea->fetch_assoc()['id']:$seasonid;
        $records = [];
        foreach ($lines as $line) {
            if (strlen(trim($line)) < 5) continue;
            // Skip header rows
            $fl = strtolower(trim($line));
            if (preg_match('/^(grower|sale|record|header)/i',$fl)) continue;
            $p = so_parse_line($line);
            if (!$p['gr_num']) continue;
            $grow = find_grower_normalized($conn, $p['gr_num'], $spid);
            $fmt_date = so_fmt_date($p['sos_raw']);
            
            // Look up creditor name
            $cred_no_e = $conn->real_escape_string($p['cred_no']);
            $cq = $conn->query("SELECT name, creditor_name FROM grower_creditors WHERE creditor_no = '$cred_no_e' LIMIT 1");
            $cname = '';
            if ($cq && $cq->num_rows > 0) {
                $c = $cq->fetch_assoc();
                $cname = trim($c['name'] ?: $c['creditor_name']);
            }
            
            $records[] = [
                'gr_num'    => $p['gr_num'],
                'grower_name'=> $grow ? trim($grow['name'].' '.($grow['surname']??'')) : '(not found)',
                'growerid'  => $grow ? (int)$grow['id'] : 0,
                'found'     => (bool)$grow,
                'cred_no'   => $p['cred_no'],
                'creditor_name' => $cname,
                'cred_ref'  => $p['cred_ref'],
                'acct_no'   => $p['acct_no'],
                'amt1'      => $p['amt1'],
                'amt2'      => $p['amt2'],
                'amt3'      => $p['amt3'],
                'pct'       => $p['pct'],
                'priority'  => $p['priority'],
                'sos_raw'   => $p['sos_raw'],
                'fmt_date'  => $fmt_date,
                'type'      => $p['type'],
                'serial'    => $p['serial'],
                'rec_type'  => $p['rec_type'],
                'gr_type'   => $p['gr_type'],
                'sale_id'   => $p['sale_id'],
                'seasonid'  => $seasonid_so,
            ];
        }
        $_SESSION['timb_so_preview'] = [
            'records'  => $records,
            'filename' => htmlspecialchars($_FILES['timb_so_file']['name']),
            'spid'     => $spid,
        ];
        echo json_encode(['ok'=>true,'count'=>count($records),
            'found'=>count(array_filter($records,fn($r)=>$r['found'])),
            'records'=>$records]);
        exit;
    }

    // ── Import TIMB Stop Orders (after preview) ────────────────────────────
    if ($action === 'import_timb_so') {
        header('Content-Type: application/json');
        $preview = $_SESSION['timb_so_preview'] ?? null;
        if (!$preview || ($preview['spid']??0) !== $spid) {
            echo json_encode(['error'=>'No preview data. Upload the file again.']); exit;
        }
        $imported=0; $skipped=0; $errors=[];
        $now_dt = date('Y-m-d H:i:s');
        foreach ($preview['records'] as $rec) {
            if (!$rec['found']||!$rec['growerid']){$skipped++;continue;}
            $gid    = (int)$rec['growerid'];
            $sid    = (int)$rec['seasonid'];
            $cn_e   = $conn->real_escape_string($rec['cred_no']);
            $cr_e   = $conn->real_escape_string($rec['cred_ref']);
            $an_e   = $conn->real_escape_string($rec['acct_no']);
            $sn_e   = $conn->real_escape_string($rec['serial']);
            $sd_e   = $conn->real_escape_string($rec['sos_raw']);
            $saleid = $conn->real_escape_string($rec['sale_id']);
            $gt_e   = $conn->real_escape_string($rec['gr_type']);
            $date_sql = $rec['fmt_date'] ? "'".$conn->real_escape_string($rec['fmt_date'])."'" : 'NULL';
            $prio   = (int)$rec['priority'];
            $rtype  = (int)$rec['rec_type'];
            $type   = (int)$rec['type'];
            $amt1   = (float)$rec['amt1'];
            $amt2   = (float)$rec['amt2'];
            $amt3   = (float)$rec['amt3'];
            $pct    = (float)$rec['pct'];
            // Skip duplicate (same grower + creditor + sos_date)
            $ex = $conn->query("SELECT id FROM stoporders WHERE growerid=$gid AND creditor_no='$cn_e' AND sos_date='$sd_e' AND selling_pointid=$spid LIMIT 1");
            if ($ex&&$ex!==true&&$ex->num_rows){$skipped++;continue;}
            $conn->query("INSERT INTO stoporders
                (userid,selling_pointid,seasonid,growerid,sale_id,record_type,grower_type,
                 creditor_no,priority,creditor_ref,account_no,amount_1,amount_2,amount_3,
                 percent,sos_date,formatted_sos_date,type,serial_no,active,created_at)
                VALUES ($userid,$spid,$sid,$gid,'$saleid',$rtype,'$gt_e',
                 '$cn_e',$prio,'$cr_e','$an_e',$amt1,$amt2,$amt3,
                 $pct,'$sd_e',$date_sql,$type,'$sn_e',1,'$now_dt')");
            if ($conn->error){$errors[]=$rec['gr_num'].': '.$conn->error;$skipped++;}
            else $imported++;
        }
        unset($_SESSION['timb_so_preview']);
        
        // Automatically run deductions for already balanced growers
        require_once "includes/stoporder_deduct_helper.php";
        $deducted_count = flowork_deduct_stoporders($conn, $spid);

        audit_log($conn,$spid,$userid,'stoporders','timb_file_import',null,null,
            ['imported'=>$imported,'skipped'=>$skipped,'deducted'=>$deducted_count]);
        
        $msg_str = "TIMB stop order import: $imported activated, $skipped skipped.";
        if ($deducted_count > 0) {
            $msg_str .= " Automatically applied and deducted stop orders for $deducted_count balanced grower record(s).";
        }
        
        echo json_encode(['ok'=>true,'imported'=>$imported,'skipped'=>$skipped,
            'msg'=>$msg_str . ($errors?' — Errors: '.implode('; ',array_slice($errors,0,3)):'')]);
        exit;
    }

    // ── Clear TIMB SO preview ─────────────────────────────────────────────
    if ($action === 'clear_timb_so') {
        unset($_SESSION['timb_so_preview']);
        header('Location: stop_orders.php?tab=external'); exit;
    }
}

// ── Load data ─────────────────────────────────────────────────────────────
$ext_sos = $conn->query("
    SELECT so.*, g.grower_num, g.name, g.surname,
           COALESCE(gc.name, gc.creditor_name, '') AS creditor_name
    FROM stoporders so
    JOIN growers g ON g.id=so.growerid
    LEFT JOIN grower_creditors gc ON gc.creditor_no=so.creditor_no
    WHERE so.selling_pointid=$spid AND so.seasonid=$seasonid
    ORDER BY so.priority ASC, so.id DESC
    LIMIT 200
");

$int_sos = $conn->query("
    SELECT iso.*, COUNT(isoa.id) AS amount_count
    FROM internal_stop_order iso
    LEFT JOIN internal_stop_order_amount isoa ON isoa.internal_stop_orderid=iso.id
    WHERE iso.selling_pointid=$spid
    GROUP BY iso.id
    ORDER BY iso.id DESC
");

$charge_types = $conn->query("SELECT * FROM charge_type ORDER BY id");

// For split assignment: grower groups
$tg_groups = $conn->query("
    SELECT tg.id, tg.bales, g.grower_num, g.name, g.surname, t.name AS transporter_name
    FROM transporter_growers tg
    JOIN growers g ON g.id=tg.growerid
    JOIN transporter t ON t.id=tg.transporterid
    WHERE t.seasonid=$seasonid AND g.selling_pointid=$spid
    ORDER BY g.grower_num
");

// Creditors for reference
$creditors = $conn->query("SELECT * FROM grower_creditors ORDER BY creditor_name LIMIT 100");

$active_tab = $_GET['tab'] ?? 'external';

include "includes/layout.php";
?>

<div class="page-header">
    <div class="page-header-left"><h2>Stop Orders</h2>
    <p>External creditor deductions and internal stop orders</p></div>
    <div class="flex gap-2">
        <a href="?tab=external" class="btn <?= $active_tab==='external'?'btn-primary':'btn-ghost' ?>">External SOs</a>
        <a href="?tab=internal" class="btn <?= $active_tab==='internal'?'btn-primary':'btn-ghost' ?>">Internal SOs</a>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($active_tab === 'external'): ?>
<!-- ═══════════════════════ EXTERNAL STOP ORDERS ═══════════════════════ -->

<!-- ── TIMB STOP ORDER FILE IMPORT ──────────────────────────────────── -->
<div class="card mb-3" style="border-left:3px solid <?=$has_so_keys?'var(--leaf)':'var(--gold)'?>">
  <div class="card-head">
    <h3 style="display:flex;align-items:center;gap:.5rem">
      <span>🔓</span> Import TIMB Stop Order File
    </h3>
    <div style="display:flex;align-items:center;gap:.75rem">
      <?php if(!$has_so_keys):?>
      <span style="font-size:.72rem;color:var(--gold)">⚠ No keys &mdash;
        <a href="settings.php" style="color:var(--gold);font-weight:600">Settings → TIMB File Keys</a>
      </span>
      <?php else:?>
      <span class="badge badge-green" style="font-size:.72rem">✓ Keys Configured</span>
      <?php endif;?>
      <button class="btn btn-ghost btn-sm" onclick="toggleSoUpload()" id="so-upload-toggle">
        <?=isset($timb_so_preview)?'↑ Hide':'↓ Upload File'?>
      </button>
    </div>
  </div>

  <!-- Upload form -->
  <div id="so-upload-area" style="<?=isset($timb_so_preview)?'display:none':'display:block'?>;padding:1rem 1.25rem;border-bottom:1px solid var(--border)">
    <p style="font-size:.75rem;color:var(--muted);margin-bottom:.75rem">
      Upload an encrypted TIMB stop order file (<code>.encry_en</code>, <code>.txt</code>, <code>.v</code>).
      The system will decrypt it using your stored keys and show a preview before importing.
      You can also upload via <a href="file_manager_uploads.php">File Manager</a>.
    </p>
    <div style="display:flex;align-items:flex-end;gap:.75rem;flex-wrap:wrap">
      <div class="form-group" style="flex:1;min-width:220px;margin:0">
        <label>TIMB Stop Order File</label>
        <input type="file" id="timb-so-file" class="form-control"
               accept=".encry_en,.txt,.v,.V,.enc,.dat"
               onchange="document.getElementById('so-upload-btn').disabled=!this.files[0]">
      </div>
      <button class="btn btn-primary btn-sm" onclick="uploadTimbSo()" id="so-upload-btn"
              style="margin-bottom:2px" disabled>
        🔓 Decrypt &amp; Preview
      </button>
    </div>
    <div id="so-upload-msg" style="margin-top:.5rem;font-size:.78rem"></div>
  </div>

  <!-- Preview table -->
  <?php if(isset($timb_so_preview)):?>
  <div id="so-preview-area">
    <div style="padding:.65rem 1.25rem;background:rgba(200,149,42,.08);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
      <span style="font-size:.78rem">
        📝 File: <strong><?=$timb_so_preview['filename']?></strong> &nbsp;&middot;&nbsp;
        <strong><?=count($timb_so_preview['records'])?></strong> records &nbsp;&middot;&nbsp;
        <strong style="color:var(--leaf)"><?=count(array_filter($timb_so_preview['records'],fn($r)=>$r['found']))?></strong> matched &nbsp;&middot;&nbsp;
        <strong style="color:var(--danger)"><?=count(array_filter($timb_so_preview['records'],fn($r)=>!$r['found']))?></strong> not found
      </span>
      <button class="btn btn-primary btn-sm" onclick="importTimbSo()" id="so-import-btn">
        ✓ Import &amp; Activate All Matched
      </button>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="clear_timb_so">
        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)">✕ Clear</button>
      </form>
      <span id="so-import-msg" style="font-size:.78rem;color:var(--leaf)"></span>
    </div>
    <div class="table-wrap" style="max-height:380px;overflow-y:auto">
    <table>
    <thead><tr>
      <th>Grower #</th><th>Grower Name</th>
      <th>Creditor #</th><th>Ref</th><th>Account</th>
      <th class="text-right">Amt 1 (USD)</th>
      <th class="text-right">Amt 2 (ZiG)</th>
      <th>Priority</th><th>SOS Date</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach($timb_so_preview['records'] as $pr):?>
    <tr style="<?=!$pr['found']?'opacity:.5':'';?>">
      <td><span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($pr['gr_num'])?></span></td>
      <td style="font-size:.72rem"><?=htmlspecialchars($pr['grower_name'])?></td>
      <td style="font-family:monospace;font-size:.7rem">
        <?=htmlspecialchars($pr['cred_no'])?>
        <?php if(!empty($pr['creditor_name'])): ?>
          <div style="font-size:.65rem;color:var(--muted)"><?=htmlspecialchars($pr['creditor_name'])?></div>
        <?php endif; ?>
      </td>
      <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($pr['cred_ref'])?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($pr['acct_no'])?></td>
      <td class="td-mono text-right">$<?=number_format((float)$pr['amt1'],2)?></td>
      <td class="td-mono text-right"><?=$pr['amt2']>0?number_format((float)$pr['amt2'],2):'&mdash;'?></td>
      <td class="td-mono"><?=(int)$pr['priority']?></td>
      <td style="font-size:.7rem"><?=htmlspecialchars($pr['fmt_date']??$pr['sos_raw'])?></td>
      <td>
        <?php if($pr['found']):?><span class="badge badge-green" style="font-size:.72rem">✓ Matched</span>
        <?php else:?><span class="badge badge-gray" style="font-size:.72rem">✕ Not found</span>
        <?php endif;?>
      </td>
    </tr>
    <?php endforeach;?>
    <?php if(empty($timb_so_preview['records'])):?>
    <tr><td colspan="10" class="text-center text-muted" style="padding:1.5rem">No records parsed from file.</td></tr>
    <?php endif;?>
    </tbody>
    </table>
    </div>
  </div>
  <?php else:?>
  <div id="so-preview-area" style="display:none"></div>
  <?php endif;?>
</div>

<div class="card mb-3">
    <div class="card-head"><h3>Add External Stop Order</h3>
    <button class="btn btn-ghost btn-sm" onclick="toggleForm('eso-form')">Toggle Form</button></div>
    <div id="eso-form">
    <div class="card-body">
    <form method="POST">
        <input type="hidden" name="action" value="add_so">
        <div class="form-row cols-3">
            <div class="form-group">
                <label>Grower Number *</label>
                <input type="text" name="grower_num" class="form-control" required placeholder="G001234">
            </div>
            <div class="form-group">
                <label>Grower Type</label>
                <input type="text" name="grower_type" class="form-control" placeholder="e.g. Contract">
            </div>
            <div class="form-group">
                <label>Priority</label>
                <input type="number" name="priority" class="form-control" value="1" min="1">
            </div>
        </div>
        <div class="form-row cols-3">
            <div class="form-group">
                <label>Creditor No *</label>
                <input type="text" name="creditor_no" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Creditor Ref</label>
                <input type="text" name="creditor_ref" class="form-control">
            </div>
            <div class="form-group">
                <label>Account No</label>
                <input type="text" name="account_no" class="form-control">
            </div>
        </div>
        <div class="form-row cols-3">
            <div class="form-group">
                <label>Amount 1 (USD)</label>
                <input type="text" name="amount_1" class="form-control" value="0">
            </div>
            <div class="form-group">
                <label>Amount 2 (ZiG)</label>
                <input type="text" name="amount_2" class="form-control" value="0">
            </div>
            <div class="form-group">
                <label>Amount 3</label>
                <input type="text" name="amount_3" class="form-control" value="0">
            </div>
        </div>
        <div class="form-row cols-3">
            <div class="form-group">
                <label>Type</label>
                <select name="type" class="form-control">
                    <option value="S">S — Standard</option>
                    <option value="P">P — Percentage</option>
                    <option value="F">F — Fixed</option>
                </select>
            </div>
            <div class="form-group">
                <label>Percent</label>
                <input type="number" name="percent" class="form-control" value="0" min="0" max="100">
            </div>
            <div class="form-group">
                <label>SOS Date</label>
                <input type="date" name="sos_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
        </div>
        <div class="form-row cols-2">
            <div class="form-group">
                <label>Sale ID</label>
                <input type="text" name="sale_id" class="form-control">
            </div>
            <div class="form-group">
                <label>Serial No</label>
                <input type="text" name="serial_no" class="form-control">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Add Stop Order</button>
    </form>
    </div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h3>External Stop Orders</h3>
        <span class="badge badge-gray"><?= $ext_sos->num_rows ?> records</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Priority</th>
                    <th>Grower</th>
                    <th>Creditor No</th>
                    <th>Ref</th>
                    <th>Account</th>
                    <th class="text-right">Amt 1 (USD)</th>
                    <th class="text-right">Amt 2 (ZiG)</th>
                    <th>Type</th>
                    <th>SOS Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($ext_sos->num_rows > 0): while ($r = $ext_sos->fetch_assoc()): ?>
            <tr>
                <td><span class="badge badge-blue"><?= $r['priority'] ?></span></td>
                <td>
                    <div class="fw-500 text-gold"><?= htmlspecialchars($r['grower_num']) ?></div>
                    <div class="td-muted" style="font-size:0.7rem"><?= htmlspecialchars($r['name'].' '.$r['surname']) ?></div>
                </td>
                <td class="td-muted">
                    <?= htmlspecialchars($r['creditor_no']) ?>
                    <?php if(!empty($r['creditor_name'])): ?>
                        <div style="font-size:0.65rem;color:var(--muted)"><?= htmlspecialchars($r['creditor_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="td-muted" style="font-size:0.75rem"><?= htmlspecialchars($r['creditor_ref']) ?></td>
                <td class="td-muted" style="font-size:0.75rem"><?= htmlspecialchars($r['account_no']) ?></td>
                <td class="td-mono text-right">$<?= number_format((float)$r['amount_1'], 2) ?></td>
                <td class="td-mono text-right"><?= number_format((float)$r['amount_2'], 2) ?></td>
                <td><span class="badge badge-gray"><?= htmlspecialchars($r['type']) ?></span></td>
                <td class="td-muted" style="font-size:0.75rem"><?= htmlspecialchars($r['formatted_sos_date']) ?></td>
                <td>
                    <form method="POST" onsubmit="return confirm('Delete this stop order?')">
                        <input type="hidden" name="action" value="delete_so">
                        <input type="hidden" name="soid" value="<?= $r['id'] ?>">
                        <button class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="10" class="text-center text-muted" style="padding:2rem">No external stop orders.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- ═══════════════════════ INTERNAL STOP ORDERS ═══════════════════════ -->

<div class="grid-2" style="align-items:start">

<div>
    <!-- Create internal SO -->
    <div class="card mb-3">
        <div class="card-head"><h3>New Internal Stop Order</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="add_iso">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Description *</label>
                        <input type="text" name="description" class="form-control" required placeholder="e.g. Loan Recovery">
                    </div>
                    <div class="form-group">
                        <label>Creditor Number</label>
                        <input type="number" name="creditor_number" class="form-control" value="0">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Create</button>
            </form>
        </div>
    </div>

    <!-- Internal SO list -->
    <div class="card">
        <div class="card-head"><h3>Internal Stop Orders</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Description</th><th>Creditor #</th><th class="text-right">Amounts</th><th></th></tr></thead>
                <tbody>
                <?php $int_sos->data_seek(0); if ($int_sos->num_rows > 0): while ($r = $int_sos->fetch_assoc()): ?>
                <tr style="<?= ($_GET['iso'] ?? 0)==$r['id']?'background:var(--gold-bg)':'' ?>">
                    <td class="fw-500"><?= htmlspecialchars($r['description']) ?></td>
                    <td class="td-muted"><?= $r['creditor_number'] ?></td>
                    <td class="text-right"><span class="badge badge-gray"><?= $r['amount_count'] ?></span></td>
                    <td><a href="?tab=internal&iso=<?= $r['id'] ?>" class="btn btn-ghost btn-sm">Manage</a></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="4" class="text-center text-muted" style="padding:2rem">No internal stop orders.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Right: amounts + split assignment -->
<div>
<?php
$sel_iso = (int)($_GET['iso'] ?? 0);
$sel_iso_data = $sel_iso ? $conn->query("SELECT * FROM internal_stop_order WHERE id=$sel_iso")->fetch_assoc() : null;
$iso_amounts = $sel_iso ? $conn->query("SELECT isoa.*, ct.description AS ct_desc FROM internal_stop_order_amount isoa JOIN charge_type ct ON ct.id=isoa.charge_typeid WHERE isoa.internal_stop_orderid=$sel_iso ORDER BY isoa.priority") : null;
?>
<?php if ($sel_iso_data): ?>

    <!-- Add amount -->
    <div class="card mb-3">
        <div class="card-head">
            <h3><?= htmlspecialchars($sel_iso_data['description']) ?> — Amounts</h3>
            <button class="btn btn-gold btn-sm" onclick="toggleForm('amt-form')">+ Amount</button>
        </div>
        <div id="amt-form" style="display:none;padding:1rem 1.25rem;border-bottom:1px solid var(--border);background:var(--surface)">
            <form method="POST">
                <input type="hidden" name="action" value="add_iso_amount">
                <input type="hidden" name="internal_stop_orderid" value="<?= $sel_iso ?>">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Charge Type</label>
                        <select name="charge_typeid" class="form-control" required>
                            <option value="">— Select —</option>
                            <?php $charge_types->data_seek(0); while ($r = $charge_types->fetch_assoc()): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['description']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (USD)</label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Priority</label>
                        <input type="number" name="priority" class="form-control" value="1" min="1">
                    </div>
                    <div class="form-group">
                        <label>Default Deduction</label>
                        <select name="default_deduction" class="form-control">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary">Add Amount</button>
                    <button type="button" class="btn btn-ghost" onclick="toggleForm('amt-form')">Cancel</button>
                </div>
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Type</th><th class="text-right">Amount</th><th>Priority</th><th>Default</th></tr></thead>
                <tbody>
                <?php if ($iso_amounts && $iso_amounts->num_rows > 0): while ($r = $iso_amounts->fetch_assoc()): ?>
                <tr>
                    <td><span class="badge badge-gray"><?= htmlspecialchars($r['ct_desc']) ?></span></td>
                    <td class="td-mono text-right fw-500">$<?= number_format((float)$r['amount'], 2) ?></td>
                    <td class="td-mono"><?= $r['priority'] ?></td>
                    <td><?= $r['default_deduction'] ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-gray">No</span>' ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="4" class="text-center text-muted" style="padding:1.5rem">No amounts yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Assign to grower splits -->
    <div class="card">
        <div class="card-head"><h3>Assign to Grower Splits</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="assign_split">
                <div class="form-group mb-2">
                    <label>Amount *</label>
                    <select name="iso_amountid" class="form-control" required>
                        <option value="">— Select amount —</option>
                        <?php if ($iso_amounts) { $iso_amounts->data_seek(0); while ($r = $iso_amounts->fetch_assoc()): ?>
                        <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['ct_desc']) ?> — $<?= number_format((float)$r['amount'],2) ?></option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <div class="form-group mb-2">
                    <label>Grower Group *</label>
                    <select name="transporter_growersid" class="form-control" required>
                        <option value="">— Select grower group —</option>
                        <?php $tg_groups->data_seek(0); while ($r = $tg_groups->fetch_assoc()): ?>
                        <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['grower_num']) ?> — <?= htmlspecialchars($r['transporter_name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Split #</label>
                        <input type="number" name="splitsid" class="form-control" value="1" min="1">
                    </div>
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" name="quantity" class="form-control" value="1" min="1">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Assign Split</button>
            </form>
        </div>
    </div>

<?php else: ?>
<div class="card"><div class="card-body text-center text-muted" style="padding:3rem">
    <div style="font-size:2rem;margin-bottom:0.5rem">🛑</div>Select an internal stop order to manage amounts and assignments.
</div></div>
<?php endif; ?>
</div>
</div>

<?php endif; ?>

<script>
function toggleForm(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// ── TIMB Stop Order File ──────────────────────────────────────────────
function toggleSoUpload(){
    var area = document.getElementById('so-upload-area');
    var btn  = document.getElementById('so-upload-toggle');
    var open = area.style.display !== 'none';
    area.style.display = open ? 'none' : 'block';
    btn.textContent = open ? '↓ Upload File' : '↑ Hide';
}

function xe(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

async function uploadTimbSo(){
    var fileInput = document.getElementById('timb-so-file');
    var msgEl     = document.getElementById('so-upload-msg');
    var btn       = document.getElementById('so-upload-btn');
    if (!fileInput.files[0]) { msgEl.innerHTML='<span style="color:var(--danger)">Select a file first.</span>'; return; }
    btn.disabled=true; btn.textContent='🔓 Decrypting…';
    msgEl.innerHTML='<span style="color:var(--muted)">Uploading and decrypting…</span>';
    var fd = new FormData();
    fd.append('action','upload_timb_so');
    fd.append('timb_so_file', fileInput.files[0]);
    try {
        var resp = await fetch('stop_orders.php', {method:'POST', body:fd});
        var d    = await resp.json();
        btn.disabled=false; btn.textContent='🔓 Decrypt & Preview';
        if (d.error){
            msgEl.innerHTML='<span style="color:var(--danger)">✕ '+xe(d.error)+'</span>';
            return;
        }
        fwReload();
    } catch(e){
        btn.disabled=false; btn.textContent='🔓 Decrypt & Preview';
        msgEl.innerHTML='<span style="color:var(--danger)">✕ '+xe(e.message)+'</span>';
    }
}

async function importTimbSo(){
    var btn   = document.getElementById('so-import-btn');
    var msgEl = document.getElementById('so-import-msg');
    if (!confirm('Import and activate all matched stop orders from this TIMB file?')) return;
    btn.disabled=true; btn.textContent='Importing…';
    var fd = new FormData();
    fd.append('action','import_timb_so');
    try {
        var resp = await fetch('stop_orders.php', {method:'POST', body:fd});
        var d    = await resp.json();
        btn.disabled=false; btn.textContent='✓ Import & Activate All Matched';
        if (d.error){ alert(d.error); return; }
        msgEl.textContent = '✓ '+d.msg;
        if(window.fw&&fw.toast) fw.toast(d.msg,'success');
        setTimeout(fwReload, 1300);
    } catch(e){
        btn.disabled=false;
        alert(e.message);
    }
}
</script>

<?php include "includes/layout_end.php"; ?>
