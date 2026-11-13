<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();
$page_title="File Uploads";$active_page="file_manager_uploads";
$userid=current_userid();$spid=(int)($_SESSION['selling_pointid']??0);
$msg=$err='';

$conn->query("CREATE TABLE IF NOT EXISTS file_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL,
    file_type VARCHAR(30) NOT NULL,
    original_filename VARCHAR(255) DEFAULT '',
    direction ENUM('upload','generated') DEFAULT 'upload',
    sell_date DATE NULL, records_count INT DEFAULT 0,
    status VARCHAR(20) DEFAULT 'pending',
    notes TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(file_type), INDEX(sell_date)
)");

// Ensure growers table has unique key needed for ON DUPLICATE KEY UPDATE during import
// Try adding it — will fail silently if already exists
$conn->query("ALTER TABLE growers ADD UNIQUE KEY uk_grower_floor (grower_num, selling_pointid)");
// Migrate any orphan growers (selling_pointid=0) to current floor
if ($spid) $conn->query("UPDATE growers SET selling_pointid=$spid WHERE selling_pointid=0");

// Same decrypt helpers from stoporder_processing.php / grower_booking.php
// ── AES key storage (stored in DB parameters table) ──────────────────
function get_timb_keys($conn, $selling_pointid, $type='booking'){
    // Keys are stored per selling point in booking_keys / stop_order_keys tables
    $table = ($type === 'stoporder') ? 'stop_order_keys' : 'booking_keys';
    $spid  = (int)$selling_pointid;
    $r = $conn->query("SELECT system_key, vector FROM $table WHERE selling_pointid=$spid LIMIT 1");
    if (!$r || $r->num_rows === 0) return null;
    return $r->fetch_assoc();  // ['system_key'=>..., 'vector'=>...]
}

function fm_key_bytes($key_str) {
    if (preg_match('/^[0-9A-Fa-f]+$/', $key_str) && strlen($key_str) % 2 === 0) {
        $raw = hex2bin($key_str);
        $len = strlen($raw);
        if ($len <= 16) return str_pad($raw, 16, "");
        if ($len <= 24) return str_pad($raw, 24, "");
        return substr($raw, 0, 32);
    }
    $len = strlen($key_str);
    if ($len <= 16) return str_pad($key_str, 16, "");
    if ($len <= 24) return str_pad($key_str, 24, "");
    return substr($key_str, 0, 32);
}

function fm_try_aes($ciphertext_b64, $key, $iv='') {
    if (!$key || strlen($key) < 8) return false;
    $data = trim($ciphertext_b64);

    // ── PRIMARY: Exact TIMB pattern ──────────────────────────────────────
    // From import_booking_from_timb.php + import_stop_orders_from_timb.php:
    //   openssl_decrypt($ciphertext, 'AES256', $key, 0, $iv)
    // options=0 → PHP decodes the base64 internally; key+iv used as raw strings
    if ($iv) {
        $pt = @openssl_decrypt($data, 'AES256', $key, 0, $iv);
        if ($pt !== false && strlen($pt) > 0 && sum_printable($pt) > 0.55) return $pt;

        $pt = @openssl_decrypt($data, 'aes-256-cbc', $key, 0, $iv);
        if ($pt !== false && strlen($pt) > 0 && sum_printable($pt) > 0.55) return $pt;
    }

    // ── FALLBACK: raw data mode (pre-decoded base64) ─────────────────────
    $raw = base64_decode($data, true);
    if ($raw === false || strlen($raw) < 16) return false;

    $iv_pad = $iv ? str_pad(substr($iv,0,16), 16, "\0") : str_repeat("\0", 16);

    // Try key as-is (string bytes) → AES-256
    if ($iv) {
        $pt = @openssl_decrypt($raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv_pad);
        if ($pt !== false && strlen($pt) > 0 && sum_printable($pt) > 0.55) return $pt;
    }

    // Try key as hex string → decode to raw bytes
    if (preg_match('/^[0-9A-Fa-f]+$/', $key) && strlen($key) % 2 === 0) {
        $kb = hex2bin($key);
        $bits = min(256, strlen($kb) * 8);
        if ($bits >= 128) {
            $kb = str_pad($kb, $bits/8, "\0");
            $pt = @openssl_decrypt($raw, "AES-{$bits}-CBC", $kb, OPENSSL_RAW_DATA, $iv_pad);
            if ($pt !== false && strlen($pt) > 0 && sum_printable($pt) > 0.55) return $pt;
        }
    }

    return false;
}


function sum_printable($s){
    $n=min(200,strlen($s));if(!$n)return 0;
    $cnt=0;for($i=0;$i<$n;$i++){$o=ord($s[$i]);if($o>=32&&$o<=126)$cnt++;}
    return $cnt/$n;
}

function fm_decrypt($raw, $aes_key='', $aes_iv='') {
    // Primary: DB-backed key + IV pair
    if ($aes_key && $aes_iv) {
        $result = fm_try_aes($raw, $aes_key, $aes_iv);
        if ($result !== false) return $result;
    }
    // Fallback: key only
    if ($aes_key) {
        $result = fm_try_aes($raw, $aes_key, '');
        if ($result !== false) return $result;
    }
    // Already plain text?
    $lines = explode("
", str_replace(["
","
"], "
", $raw));
    $first = trim($lines[0] ?? '');
    if (strlen($first) > 5 && !preg_match('/[^ -~]/', substr($raw, 0, 200))) return $raw;
    // Legacy XOR/base64
    $decoded = base64_decode(trim($raw), true);
    if ($decoded !== false && strlen($decoded) > 10) {
        $t = trim($decoded);
        if (!preg_match('/[^ -~
	]/', substr($t, 0, 200))) return $t;
    }
    $xored = '';
    for ($i = 0; $i < strlen($raw); $i++) $xored .= chr(ord($raw[$i]) ^ 0x3A);
    if (!preg_match('/[^ -~
	]/', substr($xored, 0, 200))) return $xored;
    if ($decoded !== false) {
        $x = '';
        for ($i = 0; $i < strlen($decoded); $i++) $x .= chr(ord($decoded[$i]) ^ 0x3A);
        if (!preg_match('/[^ -~
	]/', substr($x, 0, 200))) return $x;
    }
    return false; // Cannot decrypt
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';

    if($action==='save_aes_key'){
        $conn->query("CREATE TABLE IF NOT EXISTS parameters (param_key VARCHAR(60) PRIMARY KEY, param_value TEXT DEFAULT '')");
        $key_booking = $conn->real_escape_string(trim($_POST['key_booking']??''));
        $iv_booking  = $conn->real_escape_string(trim($_POST['iv_booking']??''));
        $key_so      = $conn->real_escape_string(trim($_POST['key_so']??''));
        $iv_so       = $conn->real_escape_string(trim($_POST['iv_so']??''));
        $saved = [];
        if(strlen($key_booking)>=8){
            $conn->query("INSERT INTO parameters (param_key,param_value) VALUES ('timb_aes_key_booking','$key_booking') ON DUPLICATE KEY UPDATE param_value='$key_booking'");
            $saved[] = 'Booking key saved';
        }
        if(strlen($iv_booking)>=8){
            $conn->query("INSERT INTO parameters (param_key,param_value) VALUES ('timb_aes_iv_booking','$iv_booking') ON DUPLICATE KEY UPDATE param_value='$iv_booking'");
        }
        if(strlen($key_so)>=8){
            $conn->query("INSERT INTO parameters (param_key,param_value) VALUES ('timb_aes_key_so','$key_so') ON DUPLICATE KEY UPDATE param_value='$key_so'");
            $saved[] = 'Stop order key saved';
        }
        if(strlen($iv_so)>=8){
            $conn->query("INSERT INTO parameters (param_key,param_value) VALUES ('timb_aes_iv_so','$iv_so') ON DUPLICATE KEY UPDATE param_value='$iv_so'");
        }
        if($saved) $msg = implode(' · ', $saved).'. Try uploading again.';
        else $err = 'Enter at least one key (minimum 8 characters).';
    }

    if($action==='upload_file'){
        $file_type=$conn->real_escape_string(trim($_POST['file_type']??''));
        $sell_date=$conn->real_escape_string(trim($_POST['sell_date']??date('Y-m-d')));

        if(empty($_FILES['upload_file']['tmp_name'])||$_FILES['upload_file']['error']!==UPLOAD_ERR_OK){
            $err="No file uploaded or upload error.";
        } elseif(!$file_type){
            $err="Select a file type.";
        } else {
            $raw=file_get_contents($_FILES['upload_file']['tmp_name']);
            $orig_name=$conn->real_escape_string($_FILES['upload_file']['name']);

            // These file types are plain CSV — skip decryption entirely
            $plain_types = ['creditor_schedule','grower_register','stoporder_csv','other'];
            $is_plain    = in_array($file_type, $plain_types);

            // For creditor schedule — import directly without storing in session
            if($file_type === 'creditor_schedule'){
                $decrypted = $raw;
                $lines = explode("\n", str_replace(["\r\n","\r"], "\n", $decrypted));
                $lines = array_values(array_filter($lines, fn($l)=>trim($l)!==''));
                $hdr = null; $ins = 0; $upd = 0; $skip = 0;

                // Ensure table and all columns exist
                $conn->query("CREATE TABLE IF NOT EXISTS grower_creditors (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    userid INT NOT NULL DEFAULT 0,
                    selling_pointid INT DEFAULT 0,
                    name VARCHAR(120) DEFAULT '',
                    creditor_no VARCHAR(100) NOT NULL,
                    creditor_name VARCHAR(200) DEFAULT '',
                    creditor_type VARCHAR(100) DEFAULT '',
                    business_type VARCHAR(100) DEFAULT '',
                    cell_phone VARCHAR(60) DEFAULT '',
                    bank_name VARCHAR(80) DEFAULT '',
                    account_num VARCHAR(80) DEFAULT '',
                    address1 VARCHAR(200) DEFAULT '',
                    address2 VARCHAR(200) DEFAULT '',
                    address3 VARCHAR(200) DEFAULT '',
                    creditor_ref VARCHAR(100) DEFAULT '',
                    old_creditor_no VARCHAR(50) DEFAULT '',
                    email VARCHAR(100) DEFAULT '',
                    status ENUM('active','suspended','closed') DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                // Add extra columns if missing (migration)
                $extra_cols = [
                    "address1 VARCHAR(200) DEFAULT ''",
                    "address2 VARCHAR(200) DEFAULT ''",
                    "address3 VARCHAR(200) DEFAULT ''",
                    "creditor_ref VARCHAR(100) DEFAULT ''",
                    "old_creditor_no VARCHAR(50) DEFAULT ''",
                    "business_type VARCHAR(100) DEFAULT ''",
                    "cell_phone VARCHAR(60) DEFAULT ''",
                    "bank_name VARCHAR(80) DEFAULT ''",
                    "account_num VARCHAR(80) DEFAULT ''",
                    "email VARCHAR(100) DEFAULT ''"
                ];
                foreach($extra_cols as $col_def){
                    $conn->query("ALTER TABLE grower_creditors ADD COLUMN IF NOT EXISTS $col_def");
                }

                // Ensure unique key is simple (creditor_no only)
                $fk_q = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='grower_creditors' AND REFERENCED_TABLE_NAME IS NOT NULL");
                if ($fk_q && $fk_q !== true) {
                    while ($fk = $fk_q->fetch_assoc()) {
                        $conn->query("ALTER TABLE grower_creditors DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
                    }
                }
                $ix_q = $conn->query("SHOW INDEX FROM grower_creditors WHERE Non_unique=0 AND Key_name!='PRIMARY' AND Key_name!='uk_cred_no'");
                if ($ix_q && $ix_q !== true) {
                    $dropped=[];
                    while ($ix=$ix_q->fetch_assoc()) {
                        if(!in_array($ix['Key_name'],$dropped)){
                            $conn->query("ALTER TABLE grower_creditors DROP INDEX `{$ix['Key_name']}`");
                            $dropped[]=$ix['Key_name'];
                        }
                    }
                }
                $chk = $conn->query("SHOW INDEX FROM grower_creditors WHERE Key_name='uk_cred_no'");
                if (!$chk || $chk->num_rows === 0) {
                    $conn->query("ALTER TABLE grower_creditors ADD UNIQUE KEY uk_cred_no (creditor_no(50))");
                }

                foreach($lines as $line){
                    $line=trim($line); if(!$line) continue;
                    $cols=str_getcsv($line,',');
                    if(count($cols)<2) $cols=str_getcsv($line,';');
                    if(!$hdr){
                        $hdr=array_map(fn($h)=>preg_replace('/[^a-z0-9]/','',strtolower($h)),$cols);
                        continue;
                    }
                    if(count($cols)<2){$skip++;continue;}
                    $row=array_combine(array_slice($hdr,0,count($cols)),array_slice($cols,0,count($hdr)));

                    $cn  = strtoupper(trim($row['creditorno'] ?? $row['creditornum'] ?? $row['creditorcode'] ?? $row['creditornumber'] ?? $row['no'] ?? ''));
                    $cnm = trim($row['creditorname'] ?? $row['name'] ?? $row['companyname'] ?? '');
                    $cr  = trim($row['creditorref'] ?? $row['ref'] ?? '');
                    $ac  = trim($row['creditoraccountno'] ?? $row['accountnum'] ?? $row['accountnumber'] ?? $row['account'] ?? '');
                    $a1  = trim($row['creditoraddress1'] ?? $row['address1'] ?? $row['address'] ?? '');
                    $a2  = trim($row['creditoraddress2'] ?? $row['address2'] ?? '');
                    $a3  = trim($row['creditoraddress3'] ?? $row['address3'] ?? '');
                    $ct  = trim($row['creditortype'] ?? $row['type'] ?? '');
                    $ocn = trim($row['oldcreditorno'] ?? $row['oldno'] ?? '');
                    $bt  = trim($row['businesstype'] ?? $row['business'] ?? '');
                    $dn  = $cnm ?: "Creditor $cn";

                    if(!$cn){$skip++;continue;}

                    $cn  = $conn->real_escape_string(substr($cn,0,49));
                    $dn  = $conn->real_escape_string(substr(trim($dn),0,119));
                    $cnm = $conn->real_escape_string(substr($cnm,0,199));
                    $cr  = $conn->real_escape_string(substr($cr,0,99));
                    $ac  = $conn->real_escape_string(substr($ac,0,79));
                    $a1  = $conn->real_escape_string(substr($a1,0,199));
                    $a2  = $conn->real_escape_string(substr($a2,0,199));
                    $a3  = $conn->real_escape_string(substr($a3,0,199));
                    $ct  = $conn->real_escape_string(substr($ct,0,99));
                    $bt  = $conn->real_escape_string(substr($bt,0,99));
                    $ocn = $conn->real_escape_string(substr($ocn,0,49));

                    $conn->query("INSERT INTO grower_creditors
                        (userid,selling_pointid,name,creditor_no,creditor_name,creditor_type,
                         business_type,creditor_ref,account_num,address1,address2,address3,
                         old_creditor_no,status)
                        VALUES ($userid,$spid,'$dn','$cn','$cnm','$ct',
                         '$bt','$cr','$ac','$a1','$a2','$a3','$ocn','active')
                        ON DUPLICATE KEY UPDATE
                            selling_pointid=$spid,
                            name=IF('$dn'!='' AND '$dn' NOT IN ('nil'),'$dn',name),
                            creditor_name=IF('$cnm'!='' AND '$cnm' NOT IN ('nil',' '),'$cnm',creditor_name),
                            creditor_type=IF('$ct'!='','$ct',creditor_type),
                            business_type=IF('$bt'!='','$bt',business_type),
                            account_num=IF('$ac'!='' AND '$ac' NOT IN ('nil'),'$ac',account_num),
                            address1=IF('$a1'!='' AND '$a1' NOT IN ('nil','  '),'$a1',address1),
                            creditor_ref=IF('$cr'!='' AND '$cr' NOT IN ('nil'),'$cr',creditor_ref)");
                    if($conn->affected_rows===1) $ins++;
                    elseif($conn->affected_rows===2) $upd++;
                }

                $conn->query("INSERT INTO file_uploads (userid,file_type,original_filename,direction,sell_date,records_count,status)
                    VALUES ($userid,'creditor_schedule','$orig_name','upload','$sell_date',".($ins+$upd).",'imported')");
                audit_log($conn,$spid,$userid,'grower_creditors','bulk_import',null,null,['new'=>$ins,'updated'=>$upd,'skipped'=>$skip]);
                $msg = "✓ Creditor schedule imported: <strong>$ins</strong> new, <strong>$upd</strong> updated, <strong>$skip</strong> skipped.";
                // No session storage needed — done!
                goto upload_done;
            }

            if($is_plain){
                $decrypted = $raw; // treat as-is
            } else {
                // TIMB files may be encrypted — attempt decryption
                $key_type = ($file_type === 'timb_stoporder') ? 'stoporder' : 'booking';
                $key_pair = get_timb_keys($conn, $spid, $key_type);
                $aes_key  = $key_pair['system_key'] ?? '';
                $aes_iv   = $key_pair['vector']     ?? '';
                $decrypted = fm_decrypt($raw, $aes_key, $aes_iv);
            }

            if($decrypted===false){
                // Could not decrypt — store raw for key-entry prompt
                $enc_b64=base64_encode($raw);
                $_SESSION['fm_encrypted']=['type'=>$file_type,'raw_b64'=>$enc_b64,'orig'=>$orig_name,'sell_date'=>$sell_date];
                $err="Could not decrypt <strong>".htmlspecialchars($orig_name)."</strong>. "
                    .(($aes_key??'')?"The stored AES key did not work. ":"No AES key stored yet. ")
                    ."Enter the TIMB decryption key below.";
            } else {
                unset($_SESSION['fm_encrypted']);
                $lines=explode("\n",str_replace(["\r\n","\r"],"\n",$decrypted));
                $lines=array_filter($lines,fn($l)=>trim($l)!=='');
                $record_count=count($lines);
                // For large files, save to temp file instead of session to avoid session size limits
                $tmp_dir = sys_get_temp_dir();
                $tmp_file = $tmp_dir.'/fm_upload_'.session_id().'.csv';
                file_put_contents($tmp_file, $decrypted);
                $_SESSION['fm_upload']=['type'=>$file_type,'tmp_file'=>$tmp_file,'orig'=>$orig_name,'sell_date'=>$sell_date,'record_count'=>$record_count];
                // Keep lines in session only for small files (preview)
                if ($record_count <= 500) {
                    $_SESSION['fm_upload']['lines'] = array_values($lines);
                } else {
                    $_SESSION['fm_upload']['lines'] = array_values(array_slice($lines,0,10)); // preview only
                }
                $conn->query("CREATE TABLE IF NOT EXISTS file_uploads (id INT AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL, file_type VARCHAR(30) NOT NULL, original_filename VARCHAR(255) DEFAULT '', direction ENUM('upload','generated') DEFAULT 'upload', sell_date DATE NULL, records_count INT DEFAULT 0, status VARCHAR(20) DEFAULT 'pending', notes TEXT DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(file_type), INDEX(sell_date))");
                $conn->query("INSERT INTO file_uploads (userid,file_type,original_filename,direction,sell_date,records_count,status) VALUES ($userid,'$file_type','$orig_name','upload','$sell_date',$record_count,'decoded')");
                $msg="File decoded: $record_count line(s). Review below.";
            }
        }
    }
    upload_done:

    // Retry decrypt with manually entered key
    if($action==='retry_decrypt'&&isset($_SESSION['fm_encrypted'])){
        $manual_key=trim($_POST['manual_key']??'');
        $enc=$_SESSION['fm_encrypted'];
        $raw=base64_decode($enc['raw_b64']);
        $manual_iv = trim($_POST['manual_iv'] ?? '');
        $result = fm_try_aes($raw, $manual_key, $manual_iv);
        if ($result !== false) {
            // Save the working key+IV to the correct table for this selling point
            $safe_key = $conn->real_escape_string($manual_key);
            $safe_iv  = $conn->real_escape_string($manual_iv);
            $key_type_retry = ($enc['type'] === 'timb_stoporder') ? 'stop_order_keys' : 'booking_keys';
            $existing_r = $conn->query("SELECT id FROM $key_type_retry WHERE selling_pointid=$spid LIMIT 1")->fetch_assoc();
            if ($existing_r) {
                $conn->query("UPDATE $key_type_retry SET system_key='$safe_key',vector='$safe_iv',datetime=NOW() WHERE selling_pointid=$spid");
            } else {
                $conn->query("INSERT INTO $key_type_retry (selling_pointid,system_key,vector) VALUES ($spid,'$safe_key','$safe_iv')");
            }
            $lines=explode("\n",str_replace(["\r\n","\r"],"\n",$result));
            $lines=array_filter($lines,fn($l)=>trim($l)!=='');
            $orig_name=$conn->real_escape_string($enc['orig']);
            $sell_date=$conn->real_escape_string($enc['sell_date']);
            $record_count=count($lines);
            $_SESSION['fm_upload']=['type'=>$enc['type'],'lines'=>array_values($lines),'orig'=>$enc['orig'],'sell_date'=>$enc['sell_date'],'decoded'=>$result];
            unset($_SESSION['fm_encrypted']);
            $msg="Decrypted with provided key ($record_count lines). Key saved for future uploads.";
        } else {
            $err="That key did not decrypt the file. Try a different key or encoding.";
        }
    }

    if($action==='import_booking'&&isset($_SESSION['fm_upload'])&&$_SESSION['fm_upload']['type']==='timb_booking'){
        // Re-use grower_booking import logic
        header('Location: grower_booking.php');exit;
    }

    if($action==='import_stoporders'&&isset($_SESSION['fm_upload'])&&$_SESSION['fm_upload']['type']==='timb_stoporder'){
        // Redirect to stop order processing with the decoded content
        header('Location: stoporder_processing.php');exit;
    }

    if($action==='import_grower_register'&&isset($_SESSION['fm_upload'])){
        $tmp_file_gr = $_SESSION['fm_upload']['tmp_file'] ?? '';
        if ($tmp_file_gr && file_exists($tmp_file_gr)) {
            $raw_gr = file_get_contents($tmp_file_gr);
            $all_gr = explode("\n", str_replace(["\r\n","\r"],"\n",$raw_gr));
            $lines  = array_values(array_filter($all_gr, fn($l)=>trim($l)!==''));
        } else {
            $lines=$_SESSION['fm_upload']['lines']??[];
        }
        $hdr=null;$ins=0;$upd=0;$skip=0;
        foreach($lines as $line){
            $line=trim($line);if(!$line)continue;
            $cols=str_getcsv($line,',');if(count($cols)<2)$cols=str_getcsv($line,';');
            if(!$hdr){
                // Normalise header — strip spaces, underscores, case, dots, BOM
                $hdr=array_map(fn($h)=>preg_replace('/[^a-z0-9]/','',strtolower(trim($h))),$cols);
                continue;
            }
            $row=array_combine(array_slice($hdr,0,count($cols)),array_slice($cols,0,count($hdr)));
            // TIMB-aware column mapping (handles GROWER NO., SURNAME, NAME etc)
            $gn      =$conn->real_escape_string(strtoupper(trim($row['growerno']??$row['growernum']??$row['grower_num']??$row['grower']??'')));
            $name    =$conn->real_escape_string(trim($row['name']??$row['firstname']??''));
            $surname =$conn->real_escape_string(trim($row['surname']??$row['lastname']??''));
            $nid     =$conn->real_escape_string(trim($row['nationalid']??$row['idnumber']??$row['national_id']??''));
            $phone   =$conn->real_escape_string(trim($row['telno']??$row['phone']??$row['contact']??$row['cellphone']??''));
            $province=$conn->real_escape_string(trim($row['province']??''));
            $farm    =$conn->real_escape_string(trim($row['farmname']??$row['farm_name']??$row['farm']??''));
            $area    =$conn->real_escape_string(trim($row['area']??''));
            $district=$conn->real_escape_string(trim($row['districtname']??$row['district_name']??$row['district']??''));
            $crop    =$conn->real_escape_string(trim($row['croptype']??$row['crop_type']??$row['crop']??'Virginia'));
            $cnum    =$conn->real_escape_string(trim($row['contractnumber']??$row['contract_number']??$row['contract']??''));
            $contr   =$conn->real_escape_string(trim($row['contractor']??''));
            $addr1   =$conn->real_escape_string(trim($row['postaladdress1']??$row['address1']??$row['address']??''));
            $notes   =$conn->real_escape_string(trim($row['notes']??$row['note']??''));
            if(!$gn){$skip++;continue;} // name can be blank for initials-only rows
            if(!$name && !$surname){$skip++;continue;}
            // Use ON DUPLICATE KEY UPDATE — same logic as grower_import.php
            // Unique key: (grower_num, selling_pointid)
            $conn->query("INSERT INTO growers
                (userid,selling_pointid,grower_num,name,surname,national_id,phone,province,
                 farm_name,area,district,crop_type,contract_number,contractor,notes,
                 address1,address2,dry_land_ha,irr_land_ha,dryland,created_at,datetime)
                VALUES ($userid,$spid,'$gn','$name','$surname','$nid','$phone','$province',
                 '$farm','$area','$district','$crop','$cnum','$contr','$notes',
                 '$addr1','',0,0,0,NOW(),NOW())
                ON DUPLICATE KEY UPDATE
                    name=IF('$name'!='','$name',name),
                    surname=IF('$surname'!='','$surname',surname),
                    national_id=IF('$nid'!='','$nid',national_id),
                    phone=IF('$phone'!='','$phone',phone),
                    province=IF('$province'!='','$province',province),
                    farm_name=IF('$farm'!='','$farm',farm_name),
                    area=IF('$area'!='','$area',area),
                    district=IF('$district'!='','$district',district),
                    crop_type=IF('$crop'!='' AND '$crop'!='Virginia','$crop',crop_type),
                    contract_number=IF('$cnum'!='','$cnum',contract_number),
                    contractor=IF('$contr'!='','$contr',contractor),
                    notes=IF('$notes'!='','$notes',notes)");
            if($conn->affected_rows === 1) $ins++;
            elseif($conn->affected_rows === 2) $upd++;
            // affected_rows=0 means no change, =1 new insert, =2 update
        }
        unset($_SESSION['fm_upload']);
        audit_log($conn,$spid,$userid,'growers','fm_import',null,null,['new'=>$ins,'updated'=>$upd,'skipped'=>$skip]);
        // Migrate any growers still with selling_pointid=0 to this floor
        if ($spid) $conn->query("UPDATE growers SET selling_pointid=$spid WHERE selling_pointid=0 AND userid=$userid");
        // Store flash for grower_registry page
        $_SESSION['gr_flash'] = "✓ File Manager import: $ins new growers added, $upd updated, $skip skipped.";
        // Redirect to grower registry so user can immediately verify
        header("Location: grower_registry.php?fm_sync=1");
        exit;
    }

    if($action==='import_creditor_schedule' && isset($_SESSION['fm_upload']) && $_SESSION['fm_upload']['type']==='creditor_schedule'){
        // Read from temp file if available (for large files that exceed session size)
        $tmp_file = $_SESSION['fm_upload']['tmp_file'] ?? '';
        if ($tmp_file && file_exists($tmp_file)) {
            $raw_content = file_get_contents($tmp_file);
            $all_lines   = explode("\n", str_replace(["\r\n","\r"], "\n", $raw_content));
            $lines       = array_values(array_filter($all_lines, fn($l)=>trim($l)!==''));
        } else {
            $lines = $_SESSION['fm_upload']['lines'] ?? [];
        }
        $hdr = null; $ins = 0; $upd = 0; $skip = 0;

        // Ensure table + unique key exist
        $conn->query("CREATE TABLE IF NOT EXISTS grower_creditors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            userid INT NOT NULL DEFAULT 0,
            selling_pointid INT DEFAULT 0,
            name VARCHAR(120) DEFAULT '',
            creditor_no VARCHAR(100) NOT NULL,
            creditor_name VARCHAR(200) DEFAULT '',
            creditor_type VARCHAR(100) DEFAULT '',
            business_type VARCHAR(100) DEFAULT '',
            cell_phone VARCHAR(60) DEFAULT '',
            bank_name VARCHAR(80) DEFAULT '',
            account_num VARCHAR(80) DEFAULT '',
            address1 VARCHAR(200) DEFAULT '',
            address2 VARCHAR(200) DEFAULT '',
            address3 VARCHAR(200) DEFAULT '',
            creditor_ref VARCHAR(100) DEFAULT '',
            old_creditor_no VARCHAR(50) DEFAULT '',
            email VARCHAR(100) DEFAULT '',
            status ENUM('active','suspended','closed') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        // Add extra columns if missing (migration)
        $extra_cols = [
            "address1 VARCHAR(200) DEFAULT ''",
            "address2 VARCHAR(200) DEFAULT ''",
            "address3 VARCHAR(200) DEFAULT ''",
            "creditor_ref VARCHAR(100) DEFAULT ''",
            "old_creditor_no VARCHAR(50) DEFAULT ''"
        ];
        foreach($extra_cols as $col_def){
            $conn->query("ALTER TABLE grower_creditors ADD COLUMN IF NOT EXISTS $col_def");
        }
        // Unique key is already fixed by fix_creditor_key2.php — just verify it exists
        $uk_check = $conn->query("SHOW INDEX FROM grower_creditors WHERE Key_name='uk_cred_no'");
        if (!$uk_check || $uk_check->num_rows === 0) {
            // Drop any conflicting unique keys first
            $fk_q = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='grower_creditors' AND REFERENCED_TABLE_NAME IS NOT NULL");
            if ($fk_q && $fk_q !== true) {
                while ($fk = $fk_q->fetch_assoc()) {
                    $conn->query("ALTER TABLE grower_creditors DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
                }
            }
            $ix_q = $conn->query("SHOW INDEX FROM grower_creditors WHERE Non_unique=0 AND Key_name!='PRIMARY'");
            $dropped = [];
            if ($ix_q && $ix_q !== true) {
                while ($ix = $ix_q->fetch_assoc()) {
                    if (!in_array($ix['Key_name'], $dropped)) {
                        $conn->query("ALTER TABLE grower_creditors DROP INDEX `{$ix['Key_name']}`");
                        $dropped[] = $ix['Key_name'];
                    }
                }
            }
            $conn->query("ALTER TABLE grower_creditors ADD UNIQUE KEY uk_cred_no (creditor_no(50))");
        }

        foreach($lines as $line){
            $line = trim($line); if(!$line) continue;
            $cols = str_getcsv($line, ',');
            if(count($cols) < 2) $cols = str_getcsv($line, ';');

            if(!$hdr){
                // Normalise: lowercase, strip spaces/dots/special chars
                $hdr = array_map(fn($h) => strtolower(trim(preg_replace('/[^a-z0-9]/', '', strtolower($h)))), $cols);
                continue;
            }

            if(count($cols) < 2){ $skip++; continue; }
            $row = array_combine(array_slice($hdr, 0, count($cols)), array_slice($cols, 0, count($hdr)));

            // ── Map TIMB creditor file columns ──────────────────────────
            // Normalised keys from TIMB file:
            // creditorno, creditorref, creditoraccountno, creditorname,
            // creditoraddress1, creditoraddress2, creditoraddress3,
            // creditortype, oldcreditorno, businesstype

            $creditor_no   = strtoupper(trim($row['creditorno'] ?? $row['creditornum'] ?? $row['creditorcode'] ?? $row['no'] ?? ''));
            $creditor_name = trim($row['creditorname'] ?? $row['name'] ?? $row['companyname'] ?? '');
            $creditor_ref  = trim($row['creditorref'] ?? $row['ref'] ?? '');
            $account_num   = trim($row['creditoraccountno'] ?? $row['accountnum'] ?? $row['accountnumber'] ?? $row['account'] ?? '');
            $address1      = trim($row['creditoraddress1'] ?? $row['address1'] ?? $row['address'] ?? '');
            $address2      = trim($row['creditoraddress2'] ?? $row['address2'] ?? '');
            $address3      = trim($row['creditoraddress3'] ?? $row['address3'] ?? '');
            $creditor_type = trim($row['creditortype'] ?? $row['type'] ?? '');
            $old_cred_no   = trim($row['oldcreditorno'] ?? $row['oldno'] ?? '');
            $business_type = trim($row['businesstype'] ?? $row['business'] ?? '');
            $cell_phone    = trim($row['creditorref'] && preg_match('/[0-9]{7,}/', $row['creditorref']??'') ? ($row['creditorref']??'') : ($row['cellphone'] ?? $row['phone'] ?? $row['tel'] ?? ''));
            $email         = trim($row['email'] ?? $row['emailaddress'] ?? '');
            $bank_name     = trim($row['bankname'] ?? $row['bank'] ?? '');

            // Use creditor_name as the display name
            $display_name  = $creditor_name ?: $creditor_no;

            if(!$creditor_no){ $skip++; continue; }
            // Skip rows where creditor name is blank/whitespace/nil
            if(!$creditor_name || strtolower(trim($creditor_name)) === 'nil' || trim($creditor_name) === ''){
                // Still import — use creditor_no as name placeholder
                $display_name = 'Creditor ' . $creditor_no;
            }

            $cn  = $conn->real_escape_string($creditor_no);
            $dn  = $conn->real_escape_string(substr(trim($display_name), 0, 119));
            $cnm = $conn->real_escape_string(substr(trim($creditor_name), 0, 199));
            $ct  = $conn->real_escape_string(substr($creditor_type, 0, 99));
            $bt  = $conn->real_escape_string(substr($business_type, 0, 99));
            $ph  = $conn->real_escape_string(substr($cell_phone, 0, 59));
            $bk  = $conn->real_escape_string(substr($bank_name, 0, 79));
            $ac  = $conn->real_escape_string(substr($account_num, 0, 79));
            $a1  = $conn->real_escape_string(substr($address1, 0, 199));
            $a2  = $conn->real_escape_string(substr($address2, 0, 199));
            $a3  = $conn->real_escape_string(substr($address3, 0, 199));
            $cr  = $conn->real_escape_string(substr($creditor_ref, 0, 99));
            $ocn = $conn->real_escape_string(substr($old_cred_no, 0, 49));
            $em  = $conn->real_escape_string(substr($email, 0, 99));

            $conn->query("INSERT INTO grower_creditors
                (userid, selling_pointid, name, creditor_no, creditor_name, creditor_type,
                 business_type, cell_phone, bank_name, account_num,
                 address1, address2, address3, creditor_ref, old_creditor_no, email, status)
                VALUES ($userid, $spid, '$dn', '$cn', '$cnm', '$ct',
                 '$bt', '$ph', '$bk', '$ac',
                 '$a1', '$a2', '$a3', '$cr', '$ocn', '$em', 'active')
                ON DUPLICATE KEY UPDATE
                    selling_pointid= $spid,
                    name           = IF('$dn'  != '' AND '$dn'  NOT IN ('nil',''), '$dn',  name),
                    creditor_name  = IF('$cnm' != '' AND '$cnm' NOT IN ('nil',''), '$cnm', creditor_name),
                    creditor_type  = IF('$ct'  != '', '$ct',  creditor_type),
                    business_type  = IF('$bt'  != '', '$bt',  business_type),
                    cell_phone     = IF('$ph'  != '' AND '$ph' NOT IN ('nil',''), '$ph', cell_phone),
                    account_num    = IF('$ac'  != '' AND '$ac' NOT IN ('nil',''), '$ac', account_num),
                    address1       = IF('$a1'  != '' AND '$a1' NOT IN ('nil',''), '$a1', address1),
                    address2       = IF('$a2'  != '' AND '$a2' NOT IN ('nil',''), '$a2', address2),
                    address3       = IF('$a3'  != '' AND '$a3' NOT IN ('nil',''), '$a3', address3),
                    creditor_ref   = IF('$cr'  != '' AND '$cr' NOT IN ('nil',''), '$cr', creditor_ref),
                    old_creditor_no= IF('$ocn' != '' AND '$ocn' != '0', '$ocn', old_creditor_no)");

            if ($conn->error) error_log("creditor_import row $cn: " . $conn->error);
            if($conn->affected_rows === 1) $ins++;
            elseif($conn->affected_rows === 2) $upd++;
        }

        if (!empty($_SESSION['fm_upload']['tmp_file']) && file_exists($_SESSION['fm_upload']['tmp_file'])) {
            @unlink($_SESSION['fm_upload']['tmp_file']);
        }
        unset($_SESSION['fm_upload']);
        audit_log($conn, $spid, $userid, 'grower_creditors', 'fm_import', null, null,
            ['new'=>$ins, 'updated'=>$upd, 'skipped'=>$skip]);
        $msg = "Creditor schedule imported: <strong>$ins</strong> new, <strong>$upd</strong> updated, <strong>$skip</strong> skipped.";
    }

    if($action==='import_stoporder_csv' && isset($_SESSION['fm_upload']) && $_SESSION['fm_upload']['type']==='stoporder_csv'){
        // Read from temp file if available
        $tmp_file = $_SESSION['fm_upload']['tmp_file'] ?? '';
        if($tmp_file && file_exists($tmp_file)){
            $raw = file_get_contents($tmp_file);
            $all = explode("\n", str_replace(["\r\n","\r"],"\n",$raw));
            $lines = array_values(array_filter($all, fn($l)=>trim($l)!==''));
        } else {
            $lines = $_SESSION['fm_upload']['lines'] ?? [];
        }

        $hdr=null; $ins=0; $upd=0; $skip=0; $errs=[];

        // Ensure columns exist in internal_stop_order_amount (shared with Creditor portal)
        require_once __DIR__ . '/includes/stoporder_schema.php';
        flowork_ensure_stoporder_amount_cols($conn);

        $_sea = $conn->query("SELECT id FROM seasons WHERE active=1 LIMIT 1");
        $seasonid = ($_sea&&$_sea!==true&&$_sea->num_rows)?(int)$_sea->fetch_assoc()['id']:0;

        foreach($lines as $line){
            $line=trim($line); if(!$line) continue;
            $cols=str_getcsv($line,',');
            if(!$hdr){
                $hdr=array_map(fn($h)=>preg_replace('/[^a-z0-9]/','',strtolower($h)),$cols);
                continue;
            }
            if(count($cols)<2){$skip++;continue;}
            $row=array_combine(array_slice($hdr,0,count($cols)),array_slice($cols,0,count($hdr)));

            // Map TIMB stop order CSV columns
            // Headers: Amount1, Amount2, Amount3, Created on, Creditor, Creditor Number,
            //          Grower Number, Name, Paid Amount, Percentage, Priority, Record Type,
            //          Season, Stop Order Type, TIMB File Name
            $grower_num  = strtoupper(trim($row['growernumber']??$row['grower']??''));
            $creditor_no = trim($row['creditornumber']??$row['creditor']??'');
            $paid_amount = (float)trim($row['paidamount']??0);
            $priority    = (int)trim($row['priority']??1);
            $created_on  = trim($row['createdon']??date('Y-m-d'));
            $sell_date   = date('Y-m-d', strtotime($created_on)) ?: date('Y-m-d');
            $timb_file   = trim($row['timbfilename']??'');

            if(!$grower_num || $paid_amount <= 0){$skip++;continue;}

            // Find grower
            $grow = find_grower_normalized($conn, $grower_num, $spid);
            if(!$grow){$skip++;continue;}
            $growerid = (int)$grow['id'];

            // Get creditor name for description
            $cno_e = $conn->real_escape_string($creditor_no);
            $cname = $creditor_no;
            if($creditor_no){
                $cq = $conn->query("SELECT COALESCE(NULLIF(name,''),creditor_name,'') nm
                    FROM grower_creditors WHERE creditor_no='$cno_e' LIMIT 1");
                if($cq&&$cq!==true&&$cq->num_rows) {
                    $nm = $cq->fetch_assoc()['nm'];
                    if($nm) $cname=$nm;
                }
            }
            $desc_e  = $conn->real_escape_string(substr($cname?:"TIMB SO",0,199));
            $sd_e    = $conn->real_escape_string($sell_date);
            $ref_e   = $conn->real_escape_string(substr($timb_file,0,99));

            // Find or create internal_stop_order for this creditor + selling point
            $iso_q = $conn->query("SELECT id FROM internal_stop_order
                WHERE creditor_number='$cno_e'
                AND (selling_pointid=$spid OR selling_pointid=0) LIMIT 1");
            $iso_row = ($iso_q&&$iso_q!==true&&$iso_q->num_rows)?$iso_q->fetch_assoc():null;
            if($iso_row){
                $iso_id=(int)$iso_row['id'];
                // Ensure it's linked to this selling point
                $conn->query("UPDATE internal_stop_order SET selling_pointid=$spid,
                    description='$desc_e' WHERE id=$iso_id");
            } else {
                $conn->query("INSERT INTO internal_stop_order
                    (userid,selling_pointid,description,creditor_number,charge_typeid)
                    VALUES ($userid,$spid,'$desc_e','$cno_e',1)");
                $iso_id=(int)$conn->insert_id;
            }
            if(!$iso_id){$skip++;continue;}

            // Check for existing amount record for this grower + SO + sell_date
            $chk = $conn->query("SELECT id FROM internal_stop_order_amount
                WHERE internal_stop_orderid=$iso_id
                AND growerid=$growerid
                AND sell_date='$sd_e' LIMIT 1");
            if($chk&&$chk!==true&&$chk->num_rows){
                $eid=(int)$chk->fetch_assoc()['id'];
                $conn->query("UPDATE internal_stop_order_amount SET
                    amount=$paid_amount, priority=$priority,
                    reference='$ref_e', processed=0, active=1
                    WHERE id=$eid");
                $upd++;
            } else {
                $conn->query("INSERT INTO internal_stop_order_amount
                    (userid,internal_stop_orderid,growerid,seasonid,
                     charge_typeid,amount,priority,sell_date,active,reference,processed)
                    VALUES ($userid,$iso_id,$growerid,$seasonid,
                     1,$paid_amount,$priority,'$sd_e',1,'$ref_e',0)");
                if($conn->error){ $errs[]="$grower_num: ".$conn->error; $skip++; }
                else $ins++;
            }
        }

        if(!empty($_SESSION['fm_upload']['tmp_file'])&&file_exists($_SESSION['fm_upload']['tmp_file']))
            @unlink($_SESSION['fm_upload']['tmp_file']);
        unset($_SESSION['fm_upload']);
        audit_log($conn,$spid,$userid,'internal_stop_order_amount','timb_so_import',null,null,
            ['new'=>$ins,'updated'=>$upd,'skipped'=>$skip]);
        $msg="TIMB Stop order import: <strong>$ins</strong> new, <strong>$upd</strong> updated, <strong>$skip</strong> skipped."
            .($errs?" &mdash; Errors: ".htmlspecialchars(implode('; ',array_slice($errs,0,3))):'');
    }

    if($action==='clear_upload'){
        if (!empty($_SESSION['fm_upload']['tmp_file']) && file_exists($_SESSION['fm_upload']['tmp_file'])) {
            @unlink($_SESSION['fm_upload']['tmp_file']);
        }
        unset($_SESSION['fm_upload']);
    }
}

$fm_upload=$_SESSION['fm_upload']??null;
$fm_encrypted=$_SESSION['fm_encrypted']??null;
$bk_row = get_timb_keys($conn, $spid, 'booking');
$sk_row = get_timb_keys($conn, $spid, 'stoporder');
$stored_key_booking = $bk_row['system_key'] ?? '';
$stored_key_so      = $sk_row['system_key'] ?? '';
$stored_aes_key     = $stored_key_booking ?: $stored_key_so;

// Recent uploads
$conn->query("CREATE TABLE IF NOT EXISTS file_uploads (id INT AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL, file_type VARCHAR(30) NOT NULL, original_filename VARCHAR(255) DEFAULT '', direction ENUM('upload','generated') DEFAULT 'upload', sell_date DATE NULL, records_count INT DEFAULT 0, status VARCHAR(20) DEFAULT 'pending', notes TEXT DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(file_type), INDEX(sell_date))");
$history=$conn->query("SELECT fu.*,u.username FROM file_uploads fu JOIN users u ON u.id=fu.userid WHERE fu.direction='upload' ORDER BY fu.created_at DESC LIMIT 30");

include "includes/layout.php";
?>

<style>
.ft-card{display:flex;align-items:center;gap:.65rem;padding:.7rem .9rem;
    border:1px solid var(--border);border-radius:var(--radius);
    background:var(--cream-d);cursor:pointer;transition:border-color .12s,background .12s;
    user-select:none}
.ft-card:hover{border-color:var(--gold);background:var(--surface-2)}
body.theme-dark .ft-card:hover{background:rgba(200,149,42,.12)}
.ft-card.selected{border-color:var(--gold);background:var(--gold-bg);border-width:2px}
.ft-icon{width:30px;height:30px;border-radius:5px;display:flex;align-items:center;
    justify-content:center;font-size:.95rem;flex-shrink:0}
.ft-label{font-size:.82rem;font-weight:500;line-height:1.2}
.ft-sub{font-size:.67rem;color:var(--muted);margin-top:1px}
.prev-line{padding:1.5px 0;border-bottom:1px solid rgba(200,149,42,.06);
    font-size:.7rem;word-break:break-all;color:#e8c96a;font-family:monospace}
.prev-line:last-child{border-bottom:none}
.key-row{display:flex;align-items:center;gap:.5rem;font-size:.78rem}
.key-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
</style>

<div class="page-header">
    <div class="page-header-left">
        <h2>File Manager</h2>
        <p>Upload &amp; decode TIMB files — booking, stop orders, grower register</p>
    </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=$err?></div><?php endif;?>

<?php
$file_types = [
    'timb_booking'      => ['icon'=>'📋','label'=>'TIMB Booking',      'sub'=>'.encry_en or CSV','color'=>'#c8952a'],
    'timb_stoporder'    => ['icon'=>'🛑','label'=>'TIMB Stop Order',   'sub'=>'.encry_en or CSV','color'=>'#c03a2b'],
    'grower_register'   => ['icon'=>'👥','label'=>'Grower Register',   'sub'=>'groreg*.csv',    'color'=>'#3d6b35'],
    'creditor_schedule' => ['icon'=>'🏦','label'=>'Creditor Schedule', 'sub'=>'creditorfile*.csv','color'=>'#1a6b9a'],
    'stoporder_csv'     => ['icon'=>'📄','label'=>'Stop Order CSV',    'sub'=>'Plain decoded CSV','color'=>'#8a3a2a'],
    'other'             => ['icon'=>'📁','label'=>'Other',             'sub'=>'Any file',        'color'=>'#7a6e5f'],
];
$type_labels = array_map(fn($v)=>$v['label'], $file_types);
$sel_type = $_POST['file_type'] ?? '';
?>

<!-- ════════════════════════════════════════════════════════════════
     ENCRYPTED FILE PROMPT
════════════════════════════════════════════════════════════════ -->
<?php if($fm_encrypted): ?>
<div class="card mb-3" style="border-left:4px solid var(--danger)">
<div class="card-head">
    <div style="display:flex;align-items:center;gap:.5rem">
        <span style="font-size:1.1rem">🔐</span>
        <div>
            <div class="fw-500" style="color:var(--danger)">Encrypted file — key required</div>
            <div style="font-size:.72rem;color:var(--muted);margin-top:1px"><?=htmlspecialchars($fm_encrypted['orig'])?></div>
        </div>
    </div>
    <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="clear_upload">
        <button class="btn btn-ghost btn-sm">✕ Dismiss</button>
    </form>
</div>
<div class="card-body">
    <p style="font-size:.82rem;margin-bottom:1rem;color:var(--muted)">
        <?= $stored_aes_key
            ? 'The stored AES key did not match. Enter the correct key below.'
            : 'No decryption key stored yet. Enter the TIMB key to unlock this file.' ?>
        Keys can be saved permanently in
        <a href="settings.php" style="color:var(--gold)">Settings → TIMB Keys</a>.
    </p>
    <form method="POST">
    <input type="hidden" name="action" value="retry_decrypt">
    <div class="form-row cols-2">
        <div class="form-group" style="margin-bottom:0">
            <label>System Key</label>
            <input type="text" name="manual_key" class="form-control" autofocus autocomplete="off"
                   style="font-family:monospace;font-size:.82rem"
                   placeholder="e.g. 40B977F5C9D2CE83B12EE5D45B7EA626">
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label>IV / Vector</label>
            <input type="text" name="manual_iv" class="form-control" autocomplete="off"
                   style="font-family:monospace;font-size:.82rem"
                   placeholder="e.g. 9839ff6f82e27155">
        </div>
    </div>
    <div class="flex gap-2" style="margin-top:.75rem">
        <button type="submit" class="btn btn-primary">🔓 Try Key</button>
        <span style="font-size:.72rem;color:var(--muted);align-self:center">
            Or export unencrypted CSV from the TIMB portal.
        </span>
    </div>
    </form>
</div>
</div>
<?php endif;?>

<!-- ════════════════════════════════════════════════════════════════
     DECODED FILE PREVIEW
════════════════════════════════════════════════════════════════ -->
<?php if($fm_upload): ?>
<div class="card mb-3" style="border-left:4px solid var(--leaf)">
<div class="card-head" style="background:var(--surface-2);color:var(--text)">
    <div style="display:flex;align-items:center;gap:.5rem">
        <span>✓</span>
        <div>
            <div class="fw-500"><?=htmlspecialchars($fm_upload['orig'])?></div>
            <div style="font-size:.7rem;color:rgba(245,240,232,.45);margin-top:1px">
                <?=count($fm_upload['lines'])?> lines &nbsp;·&nbsp;
                <?=htmlspecialchars($type_labels[$fm_upload['type']]??$fm_upload['type'])?>
            </div>
        </div>
    </div>
    <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="clear_upload">
        <button class="btn btn-ghost btn-sm" style="color:rgba(245,240,232,.55)">✕ Clear</button>
    </form>
</div>
<div style="background:#1a1208;padding:.65rem 1rem;max-height:160px;overflow-y:auto;
            border-bottom:1px solid rgba(200,149,42,.1)">
    <?php foreach(array_slice($fm_upload['lines'],0,10) as $line): ?>
    <div class="prev-line"><?=htmlspecialchars($line)?></div>
    <?php endforeach;
    if(count($fm_upload['lines'])>10): ?>
    <div style="color:rgba(232,184,75,.3);font-family:monospace;font-size:.67rem;padding-top:3px">
        … +<?=count($fm_upload['lines'])-10?> more lines
    </div>
    <?php endif; ?>
</div>
<div style="padding:.75rem 1.15rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
<?php if($fm_upload['type']==='timb_booking'):
    // 'decoded' is only set via retry_decrypt; for normal uploads use tmp_file
    $_timb_bk_raw = $fm_upload['decoded'] ?? (isset($fm_upload['tmp_file'])&&file_exists($fm_upload['tmp_file'])?file_get_contents($fm_upload['tmp_file']):'');
    $_SESSION['timb_raw_from_fm'] = $_timb_bk_raw;?>
    <div style="flex:1;font-size:.8rem;color:var(--muted)">Booking file decoded. Proceed to Grower Booking to match and import.</div>
    <a href="grower_booking.php" class="btn btn-primary">&rarr; Grower Booking</a>
<?php elseif($fm_upload['type']==='timb_stoporder'):
    $_timb_so_raw = $fm_upload['decoded'] ?? (isset($fm_upload['tmp_file'])&&file_exists($fm_upload['tmp_file'])?file_get_contents($fm_upload['tmp_file']):'');
    $_SESSION['so_raw_from_fm'] = $_timb_so_raw;?>
    <div style="flex:1;font-size:.8rem;color:var(--muted)">Stop order file decoded. Proceed to SO Processing to import.</div>
    <a href="stoporder_processing.php" class="btn btn-primary">&rarr; SO Processing</a>
<?php elseif($fm_upload['type']==='grower_register'):?>
    <div style="flex:1;font-size:.8rem;color:var(--muted)">
        Grower register — <?=count($fm_upload['lines'])-1?> growers ready to import. New growers will be added, existing updated. You will be redirected to Grower Registry.
    </div>
    <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="import_grower_register">
        <button class="btn btn-primary" onclick="return confirm('Import <?=count($fm_upload['lines'])-1?> growers?')">
            ✓ Import Growers
        </button>
    </form>
<?php elseif($fm_upload['type']==='creditor_schedule'):?>
    <div style="flex:1;font-size:.8rem;color:var(--muted)">
        Creditor schedule decoded — <?=count($fm_upload['lines'])-1?> records ready to import.
        New creditors will be added, existing updated by creditor number.
    </div>
    <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="import_creditor_schedule">
        <button class="btn btn-primary" onclick="return confirm('Import <?=count($fm_upload['lines'])-1?> creditor records?')">
            &#10003; Import Creditors
        </button>
    </form>
<?php elseif($fm_upload['type']==='stoporder_csv'):?>
    <div style="flex:1;font-size:.8rem;color:var(--muted)">
        Stop order file decoded — <?=count($fm_upload['lines'])-1?> records ready to import.
        Growers will be matched by grower number and stop orders created/updated.
    </div>
    <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="import_stoporder_csv">
        <button class="btn btn-primary" onclick="return confirm('Import <?=count($fm_upload['lines'])-1?> stop order records?')">
            &#10003; Import Stop Orders
        </button>
    </form>
<?php else:?>
    <div style="flex:1;font-size:.8rem;color:var(--muted)">File decoded successfully.</div>
    <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="clear_upload">
        <button class="btn btn-ghost">Dismiss</button>
    </form>
<?php endif;?>
</div>
</div>
<?php endif;?>

<!-- ════════════════════════════════════════════════════════════════
     UPLOAD FORM  +  SIDEBAR
════════════════════════════════════════════════════════════════ -->
<div class="premium-tabs-nav" data-tabs="uploads">
  <button type="button" class="premium-tab-btn active" data-tab="upload">⬆ Upload File</button>
  <button type="button" class="premium-tab-btn" data-tab="history">🗂 Upload History</button>
  <button type="button" class="premium-tab-btn" data-tab="keys">🔑 Encryption Keys</button>
  <button type="button" class="premium-tab-btn" data-tab="formats">📄 Accepted Formats</button>
</div>

<!-- Upload form card -->
<div id="panel-upload" class="tab-panel active" data-tabs="uploads">
<div class="card">
<div class="card-head"><h3>Upload File</h3></div>
<div class="card-body">

<!-- File type grid -->
<div style="font-size:.78rem;font-weight:500;color:var(--muted);margin-bottom:.65rem">File type</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.45rem;margin-bottom:1.15rem">
<?php foreach($file_types as $val=>$ft): ?>
<label class="ft-card <?=$sel_type===$val?'selected':''?>" onclick="pickType('<?=$val?>', this)">
    <div class="ft-icon" style="background:<?=$ft['color']?>22"><?=$ft['icon']?></div>
    <div>
        <div class="ft-label"><?=$ft['label']?></div>
        <div class="ft-sub"><?=$ft['sub']?></div>
    </div>
</label>
<?php endforeach;?>
</div>

<form method="POST" enctype="multipart/form-data" id="up-form">
<input type="hidden" name="action" value="upload_file">
<input type="hidden" name="file_type" id="ftype" value="">

<div style="font-size:.78rem;font-weight:500;color:var(--muted);margin-bottom:.65rem;margin-top:.25rem">File &amp; date</div>
<div class="form-row cols-2">
    <div class="form-group">
        <label>Sell Date</label>
        <input type="date" name="sell_date" class="form-control" value="<?=date('Y-m-d')?>">
    </div>
    <div class="form-group">
        <label>File *</label>
        <input type="file" name="upload_file" id="finput" class="form-control" required>
    </div>
</div>

<div id="up-submit" style="display:none;margin-top:.5rem">
    <button type="submit" class="btn btn-primary" style="width:100%;padding:.65rem">
        ⬆ Upload &amp; Decode
    </button>
</div>
<div id="up-hint" style="font-size:.78rem;color:var(--muted);padding:.4rem 0">
    Select a file type above, then choose a file.
</div>
</form>
</div>
</div>

</div><!-- /panel-upload -->

<!-- Encryption key status -->
<div id="panel-keys" class="tab-panel" data-tabs="uploads">
<div class="card">
<div class="card-head">
    <h3>Encryption Keys</h3>
    <?php if($stored_aes_key): ?>
    <span class="badge badge-green" style="font-size:.72rem">✓ Key stored</span>
    <?php else: ?>
    <span class="badge badge-red"   style="font-size:.72rem">No key set</span>
    <?php endif;?>
</div>
<div class="card-body" style="padding:.75rem 1.15rem">
    <div class="key-row" style="margin-bottom:.5rem">
        <div class="key-dot" style="background:<?=$stored_key_booking?'var(--leaf)':'var(--danger)'?>"></div>
        <span>Booking key: <?=$stored_key_booking
            ? '<strong style="color:var(--leaf)">Set</strong> <span style="font-family:monospace;font-size:.68rem;color:var(--muted)">('.substr($stored_key_booking,0,8).'…)</span>'
            : '<span style="color:var(--danger)">Not set</span>'?></span>
    </div>
    <div class="key-row" style="margin-bottom:.85rem">
        <div class="key-dot" style="background:<?=$stored_key_so?'var(--leaf)':'var(--danger)'?>"></div>
        <span>Stop order key: <?=$stored_key_so
            ? '<strong style="color:var(--leaf)">Set</strong> <span style="font-family:monospace;font-size:.68rem;color:var(--muted)">('.substr($stored_key_so,0,8).'…)</span>'
            : '<span style="color:var(--danger)">Not set</span>'?></span>
    </div>
    <a href="settings.php" class="btn btn-ghost btn-sm" style="width:100%;text-align:center">
        Manage Keys in Settings →
    </a>
</div>
</div>

</div><!-- /panel-keys -->

<!-- Accepted formats -->
<div id="panel-formats" class="tab-panel" data-tabs="uploads">
<div class="card">
<div class="card-head"><h3>Accepted Formats</h3></div>
<div class="card-body" style="padding:.65rem 1rem">
<div style="display:flex;flex-direction:column;gap:.55rem">
<?php
$hints=['timb_booking'=>'Encrypted or plain CSV from TIMB portal','timb_stoporder'=>'Encrypted or plain CSV from TIMB portal','grower_register'=>'Imported into grower registry table','creditor_schedule'=>'Imported into creditor schedule table','stoporder_csv'=>'Plain decoded TIMB stop order CSV','other'=>'Decoded and previewed only'];
foreach($file_types as $val=>$ft):?>
<div style="display:flex;align-items:flex-start;gap:.5rem">
    <span style="font-size:.9rem;flex-shrink:0"><?=$ft['icon']?></span>
    <div>
        <div style="font-size:.78rem;font-weight:500"><?=$ft['label']?></div>
        <div style="font-size:.67rem;color:var(--muted)"><?=$ft['sub']?> — <?=$hints[$val]??''?></div>
    </div>
</div>
<?php endforeach;?>
</div>
</div>
</div>
</div><!-- /panel-formats -->

<!-- ════════════════════════════════════════════════════════════════
     UPLOAD HISTORY
════════════════════════════════════════════════════════════════ -->
<div id="panel-history" class="tab-panel" data-tabs="uploads">
<div class="card">
<div class="card-head">
    <h3>Upload History</h3>
    <span class="badge badge-gray"><?=$history?$history->num_rows:0?></span>
</div>
<div class="table-wrap"><table>
<thead><tr>
    <th>File</th><th>Type</th><th>Sell Date</th>
    <th class="text-right">Records</th><th>Status</th>
    <th>By</th><th>When</th>
</tr></thead>
<tbody>
<?php if($history&&$history->num_rows>0): while($r=$history->fetch_assoc()):?>
<tr>
    <td><code style="font-size:.72rem"><?=htmlspecialchars($r['original_filename'])?></code></td>
    <td><span class="badge badge-gray" style="font-size:.72rem"><?=htmlspecialchars($type_labels[$r['file_type']]??$r['file_type'])?></span></td>
    <td class="td-muted" style="font-size:.75rem"><?=$r['sell_date']?date('d M Y',strtotime($r['sell_date'])):'—'?></td>
    <td class="td-mono text-right"><?=number_format((int)$r['records_count'])?></td>
    <td><span class="badge <?=$r['status']==='decoded'?'badge-green':'badge-gray'?>" style="font-size:.72rem"><?=htmlspecialchars($r['status'])?></span></td>
    <td class="td-muted" style="font-size:.72rem"><?=htmlspecialchars($r['username'])?></td>
    <td class="td-muted" style="font-size:.72rem"><?=date('d M H:i',strtotime($r['created_at']))?></td>
</tr>
<?php endwhile;else:?>
<tr><td colspan="7" class="text-center text-muted" style="padding:2rem">No files uploaded yet.</td></tr>
<?php endif;?>
</tbody></table></div>
</div>
</div><!-- /panel-history -->

<script>
function pickType(val, el) {
    document.getElementById('ftype').value = val;
    document.querySelectorAll('.ft-card').forEach(function(c){c.classList.remove('selected');});
    el.classList.add('selected');
    document.getElementById('up-submit').style.display = 'block';
    document.getElementById('up-hint').style.display   = 'none';
    document.getElementById('finput').click();
}
document.addEventListener('DOMContentLoaded', function(){
    var ft = document.getElementById('ftype');
    if (ft && ft.value) {
        document.getElementById('up-submit').style.display = 'block';
        document.getElementById('up-hint').style.display   = 'none';
    }
    document.getElementById('finput').addEventListener('change', function(){
        if (this.files.length && document.getElementById('ftype').value)
            document.getElementById('up-submit').style.display = 'block';
    });
});
</script>

<?php include "includes/layout_end.php"; ?>
