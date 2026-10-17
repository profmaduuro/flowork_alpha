<?php
require_once dirname(__DIR__) . '/includes/admin_gate.php';
// Direct creditor import test - bypasses everything
// docker cp cred_direct.php flowork_app:/var/www/html/cred_direct.php
// visit http://localhost:8080/cred_direct.php
session_start();
require_once dirname(__DIR__) . "/includes/conn.php";

ini_set('max_execution_time', 300);
ini_set('display_errors', 1);
error_reporting(E_ALL);

$userid = 1;
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);

echo "<pre style='font:12px monospace;background:#111;color:#ccc;padding:20px'>";
echo "spid=$spid\n\n";

// Step 1: check table
$q = $conn->query("SHOW TABLES LIKE 'grower_creditors'");
if (!$q || $q->num_rows === 0) {
    echo "❌ grower_creditors does not exist!\n";
    exit;
}
echo "✓ grower_creditors exists\n";
echo "  rows: ".$conn->query("SELECT COUNT(*) c FROM grower_creditors")->fetch_assoc()['c']."\n";

// Step 2: check/fix unique key
echo "\nIndexes:\n";
$idx = $conn->query("SHOW INDEX FROM grower_creditors");
while ($r = $idx->fetch_assoc()) {
    echo "  {$r['Key_name']} ({$r['Column_name']}) unique=".($r['Non_unique']==0?'YES':'NO')."\n";
}

// Step 3: read uploaded file from $_FILES or hardcode path for test
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['f']['tmp_name'])) {
    $csv = file_get_contents($_FILES['f']['tmp_name']);
} else {
    echo "\n<form method='POST' enctype='multipart/form-data'>";
    echo "<input type='file' name='f'> ";
    echo "<button type='submit'>Upload & Import Now</button>";
    echo "</form>";
    echo "\nUpload the creditor CSV above to test direct import.\n";
    echo "</pre>";
    exit;
}

$lines = explode("\n", str_replace(["\r\n","\r"], "\n", $csv));
$lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
echo "\nLines in file: ".count($lines)."\n";

// Step 4: parse header
$hdr_raw = str_getcsv($lines[0], ',');
$hdr = array_map(fn($h) => preg_replace('/[^a-z0-9]/', '', strtolower($h)), $hdr_raw);
echo "Headers: ".implode(', ', $hdr)."\n";
echo "Sample row: ".implode(' | ', str_getcsv($lines[1]??'', ','))."\n\n";

// Step 5: test insert one row
$test = str_getcsv($lines[1]??'', ',');
$row  = array_combine(array_slice($hdr,0,count($test)), array_slice($test,0,count($hdr)));
$cn   = $conn->real_escape_string(strtoupper(trim($row['creditorno']??'')));
$cnm  = $conn->real_escape_string(trim($row['creditorname']??''));
$ct   = $conn->real_escape_string(trim($row['creditortype']??''));
$bt   = $conn->real_escape_string(trim($row['businesstype']??''));
$dn   = $cnm ?: "Creditor $cn";
$dn   = $conn->real_escape_string(substr($dn,0,119));

echo "Test insert: creditor_no='$cn' name='$dn'\n";

$sql = "INSERT INTO grower_creditors (userid,selling_pointid,name,creditor_no,creditor_name,creditor_type,business_type,status)
        VALUES ($userid,$spid,'$dn','$cn','$cnm','$ct','$bt','active')
        ON DUPLICATE KEY UPDATE creditor_name=IF('$cnm'!='','$cnm',creditor_name)";
$conn->query($sql);
echo "Result: affected_rows={$conn->affected_rows} error=".($conn->error?:'none')."\n\n";

// Step 6: bulk import all rows
echo "Starting bulk import...\n";
$ins=0; $upd=0; $skip=0; $errs=[];
foreach (array_slice($lines, 1) as $i => $line) {
    $line = trim($line); if (!$line) continue;
    $cols = str_getcsv($line, ',');
    if (count($cols) < 2) { $skip++; continue; }
    $row  = array_combine(array_slice($hdr,0,count($cols)), array_slice($cols,0,count($hdr)));

    $cn  = strtoupper(trim($row['creditorno']??''));
    if (!$cn) { $skip++; continue; }
    $cnm = trim($row['creditorname']??'');
    $cr  = trim($row['creditorref']??'');
    $ac  = trim($row['creditoraccountno']??'');
    $a1  = trim($row['creditoraddress1']??'');
    $a2  = trim($row['creditoraddress2']??'');
    $a3  = trim($row['creditoraddress3']??'');
    $ct  = trim($row['creditortype']??'');
    $bt  = trim($row['businesstype']??'');
    $ocn = trim($row['oldcreditorno']??'');
    $dn  = $cnm ?: "Creditor $cn";

    $cn  = $conn->real_escape_string(substr($cn,0,49));
    $dn  = $conn->real_escape_string(substr($dn,0,119));
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
         business_type,creditor_ref,account_num,address1,address2,address3,old_creditor_no,status)
        VALUES ($userid,$spid,'$dn','$cn','$cnm','$ct',
         '$bt','$cr','$ac','$a1','$a2','$a3','$ocn','active')
        ON DUPLICATE KEY UPDATE
            selling_pointid=$spid,
            name=IF('$dn'!='' AND '$dn'!='nil','$dn',name),
            creditor_type=IF('$ct'!='','$ct',creditor_type),
            business_type=IF('$bt'!='','$bt',business_type)");

    if ($conn->error && count($errs)<5) $errs[] = "Row $i ($cn): ".$conn->error;
    if ($conn->affected_rows===1) $ins++;
    elseif ($conn->affected_rows===2) $upd++;
}

echo "Done: ins=$ins upd=$upd skip=$skip\n";
if ($errs) { echo "\nErrors:\n"; foreach($errs as $e) echo "  $e\n"; }
echo "\nTotal rows now: ".$conn->query("SELECT COUNT(*) c FROM grower_creditors")->fetch_assoc()['c']."\n";
echo "</pre>";
