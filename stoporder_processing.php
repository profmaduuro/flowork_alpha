<?php
/**
 * stoporder_processing.php
 * Decrypts TIMB AES-encrypted stop order files, parses the fixed-width format,
 * and activates them in the stoporders table for processing during balancing.
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "Stop Order Processing";
$active_page = "stoporder_processing";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();
$msg = $err  = '';

// Ensure stoporders table exists with active flag
$conn->query("CREATE TABLE IF NOT EXISTS stoporders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    selling_pointid INT NOT NULL,
    growerid INT NOT NULL,
    sale_id VARCHAR(20) DEFAULT '',
    record_type INT DEFAULT 0,
    grower_type VARCHAR(5) DEFAULT '',
    creditor_no VARCHAR(20) DEFAULT '',
    priority INT DEFAULT 0,
    creditor_ref VARCHAR(30) DEFAULT '',
    account_no VARCHAR(30) DEFAULT '',
    amount_1 DECIMAL(12,2) DEFAULT 0,
    amount_2 DECIMAL(12,2) DEFAULT 0,
    amount_3 DECIMAL(12,2) DEFAULT 0,
    percent DECIMAL(8,4) DEFAULT 0,
    sos_date VARCHAR(12) DEFAULT '',
    formatted_sos_date DATE DEFAULT NULL,
    type INT DEFAULT 0,
    serial_no VARCHAR(20) DEFAULT '',
    seasonid INT DEFAULT 0,
    active TINYINT DEFAULT 1,
    processed TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_grower(growerid), INDEX idx_sp(selling_pointid)
)");

// Get encryption keys for this selling point
$keys_q = $conn->query("SELECT system_key, vector FROM stop_order_keys WHERE selling_pointid=$spid LIMIT 1");
$keys   = ($keys_q && $keys_q !== true) ? $keys_q->fetch_assoc() : null;

// Field lengths in the TIMB fixed-width format
$FIELD_LENGTHS = [3,2,2,7,1,8,1,8,10,10,10,10,6,8,1,7];

function decrypt_timb($ciphertext_b64, $key, $iv) {
    $ciphertext = base64_decode($ciphertext_b64);
    return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

function split_fixed($line, $lengths) {
    $result = []; $pos = 0;
    foreach ($lengths as $len) {
        $result[] = substr($line, $pos, $len);
        $pos += $len;
    }
    return $result;
}

function fmt_date($d) {
    // YYYYMMDD → YYYY-MM-DD
    return strlen($d)===8 ? substr($d,0,4).'-'.substr($d,4,2).'-'.substr($d,6,2) : null;
}

// ── Auto-import when arriving from File Manager ──────────────────────────────
if (!empty($_SESSION['so_raw_from_fm'])) {
    $decrypted = $_SESSION['so_raw_from_fm'];
    unset($_SESSION['so_raw_from_fm']); // consume once
    
    $lines = array_values(array_filter(explode("\n", str_replace("\r","",$decrypted)), fn($l)=>trim($l)!==''));
    $season_q = $conn->query("SELECT id FROM seasons WHERE active=1 LIMIT 1");
    $seasonid_fm = ($season_q&&$season_q!==true)?(int)($season_q->fetch_assoc()['id']??0):0;
    $now_str = date('Y-m-d H:i:s');
    $imported_fm = 0; $skipped_fm = 0; $errors_fm = [];
    
    // Detect if this is CSV format (first line has commas and looks like a header)
    $first_line = trim($lines[0] ?? '');
    $is_csv = (substr_count($first_line, ',') >= 3);
    
    if ($is_csv) {
        // ── CSV format parsing ───────────────────────────────────────────
        $hdr = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            $cols = str_getcsv($line, ',');
            if (count($cols) < 2) $cols = str_getcsv($line, ';');
            if (!$hdr) {
                $hdr = array_map(fn($h) => preg_replace('/[^a-z0-9]/', '', strtolower($h)), $cols);
                continue;
            }
            if (count($cols) < 2) { $skipped_fm++; continue; }
            $row = array_combine(array_slice($hdr, 0, count($cols)), array_slice($cols, 0, count($hdr)));

            $grower_num  = strtoupper(trim($row['growernumber'] ?? $row['grower'] ?? $row['growernum'] ?? $row['growerregistrationnumber'] ?? ''));
            $creditor_no = trim($row['creditornumber'] ?? $row['creditor'] ?? $row['creditornum'] ?? '');
            $creditor_ref= trim($row['creditorref'] ?? $row['reference'] ?? '');
            $amount_1    = (float)str_replace(',','',trim($row['amount1'] ?? $row['paidamount'] ?? $row['amount'] ?? 0));
            $amount_2    = (float)str_replace(',','',trim($row['amount2'] ?? 0));
            $amount_3    = (float)str_replace(',','',trim($row['amount3'] ?? 0));
            $priority    = (int)trim($row['priority'] ?? 1);
            $percent     = (float)trim($row['percentage'] ?? $row['percent'] ?? 0);
            $created_on  = trim($row['createdon'] ?? $row['sosdate'] ?? $row['date'] ?? date('Y-m-d'));
            $so_type     = trim($row['stopordertype'] ?? $row['type'] ?? '');
            $rec_type    = (int)trim($row['recordtype'] ?? 0);
            $season_str  = trim($row['season'] ?? '');
            $serial_no   = trim($row['timbfilename'] ?? $row['serialno'] ?? '');
            $account_no  = trim($row['accountno'] ?? $row['account'] ?? '');

            // Try to parse the date
            $formatted_date = null;
            if ($created_on) {
                $ts = strtotime($created_on);
                if ($ts !== false) $formatted_date = date('Y-m-d', $ts);
            }
            $date_sql = $formatted_date ? "'$formatted_date'" : 'NULL';
            $sos_date_raw = $formatted_date ? str_replace('-', '', $formatted_date) : $created_on;

            if (!$grower_num || $amount_1 <= 0) { $skipped_fm++; continue; }

            // Find grower
            $g = find_grower_normalized($conn, $grower_num, $spid);
            if (!$g) { $skipped_fm++; continue; }
            $growerid = (int)$g['id'];

            // Escape strings
            $cn_e = $conn->real_escape_string($creditor_no);
            $cr_e = $conn->real_escape_string($creditor_ref);
            $an_e = $conn->real_escape_string($account_no);
            $sn_e = $conn->real_escape_string($serial_no);
            $sd_e = $conn->real_escape_string($sos_date_raw);

            // Skip if duplicate
            $ex = $conn->query("SELECT id FROM stoporders WHERE growerid=$growerid AND creditor_no='$cn_e' AND sos_date='$sd_e' LIMIT 1");
            if ($ex && $ex->num_rows > 0) { $skipped_fm++; continue; }

            $conn->query("INSERT INTO stoporders
                (userid,selling_pointid,growerid,sale_id,record_type,grower_type,creditor_no,priority,
                 creditor_ref,account_no,amount_1,amount_2,amount_3,percent,sos_date,formatted_sos_date,
                 type,serial_no,seasonid,active,created_at)
                VALUES ($userid,$spid,$growerid,'','$rec_type','','$cn_e',$priority,
                 '$cr_e','$an_e',$amount_1,$amount_2,$amount_3,$percent,'$sd_e',$date_sql,
                 0,'$sn_e',$seasonid_fm,1,'$now_str')");
            if ($conn->error) { $errors_fm[] = "Row $grower_num: ".$conn->error; }
            else { $imported_fm++; }
        }
    } else {
        // ── Fixed-width format parsing ───────────────────────────────────
        foreach ($lines as $line) {
            $line = trim($line);
            $line_len = strlen($line);
            if ($line_len < 50) { $skipped_fm++; continue; }
            try {
                if ($line_len >= 115) {
                    // ── 124-character format parsing ──
                    $sale_id     = trim(substr($line, 0, 3));
                    $grower_num  = trim(substr($line, 3, 9));
                    $creditor_no = trim(substr($line, 12, 7));
                    if (strlen($creditor_no) > 2) {
                        $creditor_no = substr($creditor_no, 0, -2);
                    }
                    $amount_1    = (float)substr($line, 43, 20) / 10000.0;
                    $amount_2    = (float)substr($line, 64, 20) / 10000.0;
                    $amount_3    = (float)substr($line, 85, 20) / 10000.0;
                    $percent     = (float)substr($line, 106, 7);
                    $sos_date_raw= substr($line, 113, 8);
                    $type        = (int)substr($line, 121, 1);
                    $serial_no   = trim(substr($line, 122));
                    
                    $rec_type    = 0;
                    $grower_type = '';
                    $priority    = 1;
                    $creditor_ref= '';
                    $account_no  = '';
                } else {
                    // ── Original 94-character format parsing ──
                    $fields = split_fixed($line, $FIELD_LENGTHS);
                    $sale_id    = trim($fields[0]??'');
                    $rec_type   = (int)trim($fields[1]??0);
                    $grower_type= trim($fields[2]??'');
                    $grower_num_part = trim($fields[3]??'');
                    $suffix     = trim($fields[4]??'');
                    $creditor_no= trim($fields[5]??'');
                    $priority   = (int)trim($fields[6]??0);
                    $creditor_ref= trim($fields[7]??'');
                    $account_no = trim($fields[8]??'');
                    $amount_1   = (float)trim($fields[9]??0);
                    $amount_2   = (float)trim($fields[10]??0);
                    $amount_3   = (float)trim($fields[11]??0);
                    $percent    = (float)trim($fields[12]??0);
                    $sos_date_raw = trim($fields[13]??'');
                    $type       = (int)trim($fields[14]??0);
                    $serial_no  = trim($fields[15]??'');
                    
                    $grower_num = $grower_type.$grower_num_part.$suffix;
                }

                $formatted_date = fmt_date($sos_date_raw);
                $date_sql   = $formatted_date ? "'$formatted_date'" : 'NULL';

                // Find grower using normalized lookup
                $g = find_grower_normalized($conn, $grower_num, $spid);
                if (!$g) { $skipped_fm++; continue; }
                $growerid = (int)$g['id'];

                // Escape all string fields
                $sale_id_e = $conn->real_escape_string($sale_id);
                $gt_e      = $conn->real_escape_string($grower_type);
                $cn_e      = $conn->real_escape_string($creditor_no);
                $cr_e      = $conn->real_escape_string($creditor_ref);
                $an_e      = $conn->real_escape_string($account_no);
                $sn_e      = $conn->real_escape_string($serial_no);
                $sd_e      = $conn->real_escape_string($sos_date_raw);

                // Skip if already exists for this grower + creditor + date
                $ex = $conn->query("SELECT id FROM stoporders WHERE growerid=$growerid AND creditor_no='$cn_e' AND sos_date='$sd_e' LIMIT 1");
                if ($ex && $ex->num_rows > 0) { $skipped_fm++; continue; }

                $conn->query("INSERT INTO stoporders
                    (userid,selling_pointid,growerid,sale_id,record_type,grower_type,creditor_no,priority,
                     creditor_ref,account_no,amount_1,amount_2,amount_3,percent,sos_date,formatted_sos_date,
                     type,serial_no,seasonid,active,created_at)
                    VALUES ($userid,$spid,$growerid,'$sale_id_e',$rec_type,'$gt_e','$cn_e',$priority,
                     '$cr_e','$an_e',$amount_1,$amount_2,$amount_3,$percent,'$sd_e',$date_sql,
                     $type,'$sn_e',$seasonid_fm,1,'$now_str')");
                if ($conn->error) { $errors_fm[] = "Row error: ".$conn->error; }
                else { $imported_fm++; }
            } catch (Exception $e) {
                $errors_fm[] = $e->getMessage();
            }
        }
    }
    // Automatically run deductions for already balanced growers
    require_once "includes/stoporder_deduct_helper.php";
    $deducted_count_fm = flowork_deduct_stoporders($conn, $spid);

    audit_log($conn,$spid,$userid,'stoporders','import',null,null,['imported'=>$imported_fm,'skipped'=>$skipped_fm,'format'=>$is_csv?'csv':'fixed','deducted'=>$deducted_count_fm]);
    $msg = "✓ Processed from File Manager: $imported_fm stop orders activated. $skipped_fm skipped (duplicate or grower not found).";
    if ($deducted_count_fm > 0) {
        $msg .= " Automatically applied and deducted stop orders for $deducted_count_fm balanced grower record(s).";
    }
    if (!empty($errors_fm)) $msg .= " ".count($errors_fm)." errors.";
}

// Process file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'run_timb_deductions') {
        require_once "includes/stoporder_deduct_helper.php";
        $count = flowork_deduct_stoporders($conn, $spid);
        $msg = "✓ Processed stop order deductions: $count records successfully deducted from active balanced grower batches.";
        audit_log($conn, $spid, $userid, 'stoporders', 'deductions_run', null, null, ['deducted_count' => $count]);
    }

    if ($action === 'save_keys') {
        $key = $conn->real_escape_string(trim($_POST['system_key'] ?? ''));
        $iv  = $conn->real_escape_string(trim($_POST['vector'] ?? ''));
        if ($key && $iv) {
            $conn->query("INSERT INTO stop_order_keys (selling_pointid,system_key,vector) VALUES ($spid,'$key','$iv')
                ON DUPLICATE KEY UPDATE system_key='$key',vector='$iv'");
            $msg = "✓ Encryption keys saved.";
            $keys = ['system_key'=>$key,'vector'=>$iv];
        } else { $err = "Both key and vector are required."; }
    }

    if ($action === 'clear_unprocessed_deactivate') {
        $conn->query("UPDATE stoporders SET active=0 WHERE processed=0" . (($spid > 0) ? " AND selling_pointid=$spid" : ""));
        $msg = "✓ All unprocessed stop orders have been deactivated.";
    }

    if ($action === 'clear_unprocessed_delete') {
        $conn->query("DELETE FROM stoporders WHERE processed=0" . (($spid > 0) ? " AND selling_pointid=$spid" : ""));
        $msg = "✓ All unprocessed stop orders have been deleted.";
    }

    if ($action === 'clear_processed_delete') {
        $conn->query("DELETE FROM stoporders WHERE processed=1" . (($spid > 0) ? " AND selling_pointid=$spid" : ""));
        $msg = "✓ All processed stop orders have been deleted.";
    }

    if ($action === 'clear_all_delete') {
        $conn->query("DELETE FROM stoporders WHERE " . (($spid > 0) ? "selling_pointid=$spid" : "1=1"));
        $msg = "✓ All stop orders for this selling point have been deleted.";
    }



    if ($action === 'deactivate') {
        $sid = (int)($_POST['so_id'] ?? 0);
        $conn->query("UPDATE stoporders SET active=0 WHERE id=$sid" . (($spid > 0) ? " AND selling_pointid=$spid" : ""));
        $msg = "Stop order deactivated.";
    }

    if ($action === 'activate_all_pending') {
        $conn->query("UPDATE stoporders SET active=1 WHERE " . (($spid > 0) ? "selling_pointid=$spid AND " : "") . "processed=0");
        $msg = "✓ All unprocessed stop orders activated.";
    }
}

// Load stop orders
$f_status = $_GET['status'] ?? 'active';
$where_so = ($spid > 0) ? "so.selling_pointid=$spid" : "1=1";
if ($f_status === 'active')    $where_so .= " AND so.active=1";
if ($f_status === 'processed') $where_so .= " AND so.processed=1";

$sos_q = $conn->query("
    SELECT so.*, g.grower_num, g.name, g.surname,
           COALESCE(gc.name, gc.creditor_name, '') AS creditor_name
    FROM stoporders so
    JOIN growers g ON g.id=so.growerid
    LEFT JOIN grower_creditors gc ON gc.creditor_no=so.creditor_no
    WHERE $where_so
    ORDER BY so.formatted_sos_date DESC, g.grower_num
    LIMIT 200
");
$sos = ($sos_q&&$sos_q!==true)?$sos_q->fetch_all(MYSQLI_ASSOC):[];

$sp_cond_count = ($spid > 0) ? "selling_pointid=$spid" : "1=1";
$_r1 = $conn->query("SELECT COUNT(*) c FROM stoporders WHERE $sp_cond_count AND active=1 AND processed=0");
$active_count = $_r1 ? (int)($_r1->fetch_assoc()['c']??0) : 0;
$_r2 = $conn->query("SELECT COUNT(*) c FROM stoporders WHERE $sp_cond_count AND processed=1");
$processed_count = $_r2 ? (int)($_r2->fetch_assoc()['c']??0) : 0;
$_r3 = $conn->query("SELECT COALESCE(SUM(amount_1),0) t FROM stoporders WHERE $sp_cond_count AND active=1 AND processed=0");
$total_active_usd = $_r3 ? (float)($_r3->fetch_assoc()['t']??0) : 0;

// ── TODAY'S SELLING DAY: match active stop orders to growers selling today ──
$sell_today = date('Y-m-d');
$sell_today_alt = date('d-M-y');
// Get growers booked/selling today via transporter_growers
$today_matches_q = $conn->query("
    SELECT so.id so_id, so.creditor_no, so.creditor_ref, so.account_no,
           so.amount_1, so.amount_2, so.amount_3, so.percent, so.priority,
           so.formatted_sos_date, so.active, so.processed,
           g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
           tg.id tgid, COALESCE(tg.sell_date,'$sell_today') sell_date,
           tr.name transporter_name,
           bb.id bbid,
           COALESCE(dt.usd_value,0) gross_usd,
           COALESCE(dtn.usd_net_value,0) net_usd,
           CASE WHEN bb.id IS NOT NULL THEN 'balanced' ELSE 'pending' END balance_status,
           COALESCE(gc.name, gc.creditor_name, '') AS creditor_name
    FROM stoporders so
    JOIN growers g ON g.id=so.growerid
    LEFT JOIN grower_creditors gc ON gc.creditor_no=so.creditor_no
    JOIN transporter_growers tg ON tg.growerid=g.id
    JOIN transporter tr ON tr.id=tg.transporterid
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
    WHERE " . (($spid > 0) ? "so.selling_pointid=$spid" : "1=1") . "
      AND so.active=1
      AND so.processed=0
      AND (tg.sell_date='$sell_today' OR tg.sell_date='$sell_today_alt' OR tg.sell_date IS NULL)
    ORDER BY so.priority ASC, g.grower_num ASC
");
$today_matches = ($today_matches_q&&$today_matches_q!==true)?$today_matches_q->fetch_all(MYSQLI_ASSOC):[];
$today_match_count = count($today_matches);
$today_match_usd = array_sum(array_column($today_matches, 'amount_1'));

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left">
    <h2>Stop Order Processing</h2>
    <p>Import TIMB encrypted stop order files and activate deductions</p>
  </div>
  <div style="display:flex;gap:.5rem">
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="run_timb_deductions">
      <button type="submit" class="btn btn-primary btn-sm">⚡ Run Stop Order Deductions</button>
    </form>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="activate_all_pending">
      <button type="submit" class="btn btn-ghost btn-sm">✓ Activate All Pending</button>
    </form>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<style>
.sop-top-row {
  display: grid;
  grid-template-columns: 1.2fr 1fr;
  gap: 1.25rem;
  align-items: start;
  margin-bottom: 1.25rem;
}
.sop-kpi-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
}
@media(max-width: 900px) {
  .sop-top-row {
    grid-template-columns: 1fr;
  }
}
@keyframes sop-pulse { 0%,100%{opacity:1}50%{opacity:.3} }
</style>

<!-- Top Row: Encryption Keys Card & KPI Dashboard -->
<div class="sop-top-row">

  <!-- Encryption keys -->
  <div class="card">
    <div class="card-head">
      <h3>🔑 Encryption Keys</h3>
      <?php if($keys):?><span class="badge badge-green" style="font-size:.72rem">✓ Configured</span><?php else:?><span class="badge badge-red" style="font-size:.72rem">Not set</span><?php endif;?>
    </div>
    <div class="card-body" style="padding:1rem">
      <form method="POST">
        <input type="hidden" name="action" value="save_keys">
        <div class="form-row cols-2" style="margin-bottom:.8rem">
          <div class="form-group" style="margin-bottom:0">
            <label style="font-size:.72rem">System Key (AES-256 key)</label>
            <input type="text" name="system_key" class="form-control" style="padding:.4rem .6rem;font-size:.78rem;font-family:monospace" value="<?=htmlspecialchars($keys['system_key']??'')?>" placeholder="256-bit key" required>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label style="font-size:.72rem">Initialization Vector (IV)</label>
            <input type="text" name="vector" class="form-control" style="padding:.4rem .6rem;font-size:.78rem;font-family:monospace" value="<?=htmlspecialchars($keys['vector']??'')?>" placeholder="IV" required>
          </div>
        </div>
        <button type="submit" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center">Save Keys</button>
      </form>
    </div>
  </div>

  <!-- KPIs -->
  <div class="sop-kpi-grid">
    <div class="kpi-card" style="padding:.85rem;margin-bottom:0"><div class="kpi-label" style="font-size:.72rem;margin-bottom:.3rem">Active Stop Orders</div><div class="kpi-value text-gold" style="font-size:1.6rem"><?=number_format($active_count)?></div></div>
    <div class="kpi-card" style="padding:.85rem;margin-bottom:0"><div class="kpi-label" style="font-size:.72rem;margin-bottom:.3rem">Total Active USD</div><div class="kpi-value" style="font-size:1.6rem">$<?=number_format($total_active_usd,2)?></div></div>
    <div class="kpi-card" style="padding:.85rem;margin-bottom:0;--kpi-color:var(--danger)"><div class="kpi-label" style="font-size:.72rem;margin-bottom:.3rem">Today's Matches</div><div class="kpi-value" style="font-size:1.6rem;color:<?=$today_match_count>0?'var(--danger)':'var(--leaf)'?>"><?=$today_match_count?></div><div class="kpi-sub" style="font-size:.72rem;margin-top:.15rem">$<?=number_format($today_match_usd,2)?></div></div>
    <div class="kpi-card" style="padding:.85rem;margin-bottom:0"><div class="kpi-label" style="font-size:.72rem;margin-bottom:.3rem">Processed</div><div class="kpi-value text-leaf" style="font-size:1.6rem"><?=number_format($processed_count)?></div></div>
  </div>

</div>

<?php if($today_match_count > 0): ?>
<!-- ═══ TODAY'S SELLING DAY MATCHES ═══ -->
<div class="card mb-3" style="border-left:3px solid var(--danger)">
  <div class="card-head">
    <h3 style="display:flex;align-items:center;gap:.5rem">
      <span style="animation:sop-pulse 1.2s ease infinite">🔴</span>
      Today's Stop Orders — <?=date('d M Y')?>
    </h3>
    <span class="badge badge-red"><?=$today_match_count?> growers · $<?=number_format($today_match_usd,2)?></span>
  </div>
  <div style="padding:.5rem 1rem;background:rgba(192,57,43,.05);border-bottom:1px solid var(--border);font-size:.75rem;color:var(--muted)">
    These stop orders match growers who are <strong>selling today</strong>. They will be automatically deducted from the grower's net payout during balancing.
  </div>
  <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
  <thead><tr>
    <th>Priority</th><th>Grower</th><th>Creditor #</th><th>Ref</th>
    <th class="text-right">Amount USD</th><th class="text-right">Amount ZiG</th>
    <th>Transporter</th><th>Balance</th><th>SOS Date</th>
  </tr></thead>
  <tbody>
  <?php foreach($today_matches as $tm):
    $bal_badge = $tm['balance_status']==='balanced'
      ? '<span class="badge badge-green" style="font-size:.58rem">Balanced · $'.number_format((float)$tm['net_usd'],2).'</span>'
      : '<span class="badge badge-gold" style="font-size:.58rem">Pending</span>';
  ?>
  <tr>
    <td><span class="badge badge-blue"><?=(int)$tm['priority']?></span></td>
    <td>
      <span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($tm['grower_num'])?></span>
      <div style="font-size:.68rem"><?=htmlspecialchars($tm['grower_name'])?></div>
    </td>
    <td style="font-family:monospace;font-size:.72rem">
      <?=htmlspecialchars($tm['creditor_no'])?>
      <?php if(!empty($tm['creditor_name'])): ?>
        <div style="font-size:.65rem;color:var(--muted)"><?=htmlspecialchars($tm['creditor_name'])?></div>
      <?php endif; ?>
    </td>
    <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($tm['creditor_ref'])?></td>
    <td class="td-mono text-right" style="font-weight:600;color:var(--danger)">$<?=number_format((float)$tm['amount_1'],2)?></td>
    <td class="td-mono text-right"><?=(float)$tm['amount_2']>0?number_format((float)$tm['amount_2'],2):'—'?></td>
    <td style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($tm['transporter_name'])?></td>
    <td><?=$bal_badge?></td>
    <td style="font-size:.72rem"><?=$tm['formatted_sos_date']?date('d M Y',strtotime($tm['formatted_sos_date'])):'—'?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
  </table></div>
</div>
<?php endif; ?>

<!-- Stop order list -->
<div class="card">
  <div class="card-head" style="flex-wrap:wrap;gap:.75rem">
    <h3>Stop Orders</h3>
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
      <!-- Filters -->
      <div style="display:flex;gap:.35rem">
        <a href="?status=active" class="btn <?=$f_status==='active'?'btn-primary':'btn-ghost'?> btn-sm" style="font-size:.68rem">Active (<?=$active_count?>)</a>
        <a href="?status=processed" class="btn <?=$f_status==='processed'?'btn-primary':'btn-ghost'?> btn-sm" style="font-size:.68rem">Processed (<?=$processed_count?>)</a>
        <a href="?status=all" class="btn <?=$f_status==='all'?'btn-primary':'btn-ghost'?> btn-sm" style="font-size:.68rem">All</a>
      </div>
      
      <div style="width:1px;height:20px;background:var(--border)"></div>
      
      <!-- Actions -->
      <div style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap">
        <span style="font-size:.68rem;color:var(--muted)">Cleanup:</span>
        <form method="POST" style="display:inline" onsubmit="return confirm('Deactivate all unprocessed stop orders?');">
          <input type="hidden" name="action" value="clear_unprocessed_deactivate">
          <button type="submit" class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--gold)">Deactivate Pending</button>
        </form>
        <form method="POST" style="display:inline" onsubmit="return confirm('Permanently DELETE all unprocessed stop orders? This cannot be undone.');">
          <input type="hidden" name="action" value="clear_unprocessed_delete">
          <button type="submit" class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--danger)">Delete Pending</button>
        </form>
        <form method="POST" style="display:inline" onsubmit="return confirm('Permanently DELETE all processed stop orders? This cannot be undone.');">
          <input type="hidden" name="action" value="clear_processed_delete">
          <button type="submit" class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--danger)">Delete Processed</button>
        </form>
        <form method="POST" style="display:inline" onsubmit="return confirm('DANGER: Permanently DELETE ALL stop orders for this selling point? This cannot be undone.');">
          <input type="hidden" name="action" value="clear_all_delete">
          <button type="submit" class="btn btn-danger btn-sm" style="font-size:.65rem;padding:0.28rem 0.65rem;">Delete All</button>
        </form>
      </div>
    </div>
  </div>
  <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
  <thead><tr>
    <th>Grower</th><th>Creditor #</th><th>Ref</th>
    <th class="text-right">Amount 1</th><th class="text-right">Amount 2</th>
    <th>Date</th><th>Status</th><th></th>
  </tr></thead>
  <tbody>
  <?php if(empty($sos)):?>
  <tr><td colspan="8" class="text-center text-muted" style="padding:2rem">
    No stop orders. Import a TIMB file via File Manager to activate them.
  </td></tr>
  <?php else: foreach($sos as $so):?>
  <tr>
    <td>
      <span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($so['grower_num'])?></span>
      <div style="font-size:.68rem"><?=htmlspecialchars(trim($so['name'].' '.$so['surname']))?></div>
    </td>
    <td style="font-family:monospace;font-size:.72rem">
      <?=htmlspecialchars($so['creditor_no'])?>
      <?php if(!empty($so['creditor_name'])): ?>
        <div style="font-size:.65rem;color:var(--muted)"><?=htmlspecialchars($so['creditor_name'])?></div>
      <?php endif; ?>
    </td>
    <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($so['creditor_ref'])?></td>
    <td class="td-mono text-right">$<?=number_format((float)$so['amount_1'],2)?></td>
    <td class="td-mono text-right"><?=$so['amount_2']>0?'$'.number_format((float)$so['amount_2'],2):'—'?></td>
    <td style="font-size:.72rem"><?=$so['formatted_sos_date']?date('d M Y',strtotime($so['formatted_sos_date'])):'—'?></td>
    <td>
      <?php if($so['processed']):?>
        <span class="badge badge-green" style="font-size:.72rem">Processed</span>
      <?php elseif($so['active']):?>
        <span class="badge badge-gold" style="font-size:.72rem">Active</span>
      <?php else:?>
        <span class="badge badge-gray" style="font-size:.72rem">Inactive</span>
      <?php endif;?>
    </td>
    <td>
      <?php if($so['active'] && !$so['processed']):?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="deactivate">
        <input type="hidden" name="so_id" value="<?=$so['id']?>">
        <button class="btn btn-ghost btn-sm" style="font-size:.72rem;color:var(--danger)">✕</button>
      </form>
      <?php endif;?>
    </td>
  </tr>
  <?php endforeach;endif;?>
  </tbody>
  </table></div>
</div>

<?php include "includes/layout_end.php"; ?>
