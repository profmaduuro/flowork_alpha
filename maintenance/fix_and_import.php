<?php
require_once dirname(__DIR__) . '/includes/admin_gate.php';
// docker cp fix_and_import.php flowork_app:/var/www/html/fix_and_import.php
// visit http://localhost:8080/fix_and_import.php
session_start();
require_once dirname(__DIR__) . "/includes/conn.php";
ini_set('max_execution_time', 300);

$userid = 1;
$spid   = (int)($_SESSION['selling_pointid'] ?? 3);

echo "<pre style='font:12px monospace;background:#111;color:#9c9;padding:20px'>";
echo "spid=$spid\n\n";

// Get existing columns
$existing = [];
$cols = $conn->query("SHOW COLUMNS FROM grower_creditors");
while ($c = $cols->fetch_assoc()) $existing[] = $c['Field'];
echo "Existing columns: ".implode(', ', $existing)."\n\n";

// Add only missing columns (check first, no IF NOT EXISTS)
$needed = [
    'creditor_ref'   => "VARCHAR(100) NOT NULL DEFAULT ''",
    'address1'       => "VARCHAR(200) NOT NULL DEFAULT ''",
    'address2'       => "VARCHAR(200) NOT NULL DEFAULT ''",
    'address3'       => "VARCHAR(200) NOT NULL DEFAULT ''",
    'old_creditor_no'=> "VARCHAR(50) NOT NULL DEFAULT ''",
];
foreach ($needed as $col => $def) {
    if (!in_array($col, $existing)) {
        $conn->query("ALTER TABLE grower_creditors ADD COLUMN `$col` $def");
        echo ($conn->error ? "❌ ADD $col: {$conn->error}\n" : "✓ Added: $col\n");
    } else {
        echo "  skip $col (exists)\n";
    }
}

echo "\nColumns now: ";
$cols = $conn->query("SHOW COLUMNS FROM grower_creditors")->fetch_all(MYSQLI_ASSOC);
echo implode(', ', array_column($cols,'Field'))."\n\n";

// Upload form or import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['f']['tmp_name'])) {
    $csv   = file_get_contents($_FILES['f']['tmp_name']);
    $lines = explode("\n", str_replace(["\r\n","\r"], "\n", $csv));
    $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
    echo "Lines: ".count($lines)."\n";

    $hdr = array_map(fn($h) => preg_replace('/[^a-z0-9]/','',strtolower($h)),
                     str_getcsv($lines[0], ','));
    echo "Headers: ".implode(', ',$hdr)."\n\n";

    $ins=0; $upd=0; $skip=0; $errs=[];
    foreach (array_slice($lines,1) as $i => $line) {
        $line=trim($line); if(!$line){$skip++;continue;}
        $c=str_getcsv($line,',');
        if(count($c)<2){$skip++;continue;}
        $row=array_combine(array_slice($hdr,0,count($c)),array_slice($c,0,count($hdr)));

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
        
        if(!$cn){$skip++;continue;}
        
        $cn  = $conn->real_escape_string(substr($cn,0,49));
        $cnm = $conn->real_escape_string(substr($cnm,0,99));
        $cr  = $conn->real_escape_string(substr($cr,0,99));
        $ac  = $conn->real_escape_string(substr($ac,0,79));
        $a1  = $conn->real_escape_string(substr($a1,0,199));
        $a2  = $conn->real_escape_string(substr($a2,0,199));
        $a3  = $conn->real_escape_string(substr($a3,0,199));
        $ct  = $conn->real_escape_string(substr($ct,0,99));
        $bt  = $conn->real_escape_string(substr($bt,0,99));
        $ocn = $conn->real_escape_string(substr($ocn,0,49));
        $dn  = $conn->real_escape_string(substr($cnm?:("Creditor $cn"),0,99));

        $conn->query("INSERT INTO grower_creditors
            (userid,selling_pointid,name,creditor_no,creditor_name,
             creditor_type,business_type,creditor_ref,account_num,
             address1,address2,address3,old_creditor_no,
             cell_phone,bank_name,email,status)
            VALUES ($userid,$spid,'$dn','$cn','$cnm',
             '$ct','$bt','$cr','$ac',
             '$a1','$a2','$a3','$ocn',
             '','','','active')
            ON DUPLICATE KEY UPDATE
                selling_pointid=$spid,
                name=IF('$dn'!='' AND '$dn'!='nil','$dn',name),
                creditor_name=IF('$cnm'!='' AND '$cnm'!='nil','$cnm',creditor_name),
                creditor_type=IF('$ct'!='','$ct',creditor_type),
                business_type=IF('$bt'!='','$bt',business_type),
                creditor_ref=IF('$cr'!='' AND '$cr'!='nil','$cr',creditor_ref),
                account_num=IF('$ac'!='' AND '$ac'!='nil','$ac',account_num)");

        if($conn->error && count($errs)<3) $errs[]="Row $i ($cn): ".$conn->error;
        if($conn->affected_rows===1) $ins++;
        elseif($conn->affected_rows===2) $upd++;
    }

    echo "✓ DONE: ins=$ins upd=$upd skip=$skip\n";
    if($errs){echo "\nErrors:\n";foreach($errs as $e)echo "  $e\n";}
    echo "Total rows now: ".$conn->query("SELECT COUNT(*) c FROM grower_creditors")->fetch_assoc()['c']."\n";
} else {
    echo "</pre>";
    echo "<form method='POST' enctype='multipart/form-data' style='padding:20px;background:#1a1a1a'>";
    echo "<p style='color:#9c9;font-family:monospace'>Upload creditorfile CSV to import:</p>";
    echo "<input type='file' name='f' accept='.csv' style='color:#ccc'>&nbsp;";
    echo "<button type='submit' style='padding:8px 20px;background:#2d6b25;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px'>Import Now</button>";
    echo "</form><pre style='font:12px monospace;background:#111;color:#9c9;padding:20px 20px 0'>";
}
echo "</pre>";
