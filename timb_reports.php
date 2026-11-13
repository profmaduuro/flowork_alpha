<?php
mysqli_report(MYSQLI_REPORT_OFF);
ob_start();
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "TIMB Reports";
$active_page = "timb_reports";
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$userid   = current_userid();
$today    = date('Y-m-d');
$msg = $err = '';
$tab = $_GET['tab'] ?? 'dates'; // 'dates' or 'log'

// ── Parameters ────────────────────────────────────────────────────────────────
$floor_code_q  = $conn->query("SELECT param_value FROM parameters WHERE param_key='timb_floor_code' LIMIT 1");
$FLOOR_CODE    = ($floor_code_q && $floor_code_q !== true && $floor_code_q->num_rows)
                 ? strtoupper(trim($floor_code_q->fetch_assoc()['param_value'])) : 'VL';

$sp_code_q     = $conn->query("SELECT param_value FROM parameters WHERE param_key='timb_sp_code' LIMIT 1");
$SP_CODE       = ($sp_code_q && $sp_code_q !== true && $sp_code_q->num_rows)
                 ? strtoupper(trim($sp_code_q->fetch_assoc()['param_value'])) : 'PA2';

$charge_code_q = $conn->query("SELECT param_value FROM parameters WHERE param_key='timb_charge_code' LIMIT 1");
$CHARGE_CODE   = ($charge_code_q && $charge_code_q !== true && $charge_code_q->num_rows)
                 ? trim($charge_code_q->fetch_assoc()['param_value']) : '61631';

$DEDUCT_TYPE   = '06';

// ── Ensure tables exist ───────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS file_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL,
    file_type VARCHAR(30) NOT NULL, original_filename VARCHAR(255) DEFAULT '',
    direction ENUM('upload','generated') DEFAULT 'generated',
    sell_date DATE NULL, records_count INT DEFAULT 0,
    status VARCHAR(20) DEFAULT 'ok', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS timb_submission_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    selling_pointid INT NOT NULL,
    sell_date DATE NOT NULL,
    submission_type VARCHAR(40) DEFAULT 'daily_sales',
    bales_count INT DEFAULT 0,
    payload_size_kb INT DEFAULT 0,
    status ENUM('success','failed','pending') DEFAULT 'pending',
    response_code VARCHAR(20) DEFAULT '',
    api_response TEXT DEFAULT '',
    reference_number VARCHAR(80) DEFAULT '',
    submitted_by INT DEFAULT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sp_date (selling_pointid, sell_date)
)");

// TIMB API credentials fetcher
function get_timb_credentials($conn) {
    $settings_q = $conn->query("SELECT name, value FROM settings WHERE name LIKE 'timb_%' LIMIT 20");
    $settings = [];
    if ($settings_q && $settings_q !== true) {
        while ($s = $settings_q->fetch_assoc()) $settings[$s['name']] = $s['value'];
    }
    return [
        'url'      => $settings['timb_api_url'] ?? '',
        'username' => $settings['timb_username'] ?? '',
        'password' => $settings['timb_password'] ?? '',
        'floor_id' => $settings['timb_floor_id'] ?? ''
    ];
}

// ── Helper functions for fixed-length formatting ─────────────────────────────
function pad_right($s, $len, $char = ' ') { return substr(str_pad($s, $len, $char), 0, $len); }
function pad_left($s, $len, $char = '0')  { return substr(str_pad($s, $len, $char, STR_PAD_LEFT), -$len); }
function fmt_mass($m)    { return sprintf('%05.1f', (float)$m); }
function fmt_price($p)   { return pad_left(round($p * 100000), 8); }
function fmt_exch($e)    { return pad_left(round($e * 100000), 14); }
function fmt_usd($v)     { return pad_left(round($v * 10000), 13); }
function fmt_zig($v)     { return pad_left(round($v * 10000), 16); }
function fmt_seq($n)     { return pad_left($n, 8); }
function fmt_6($n)       { return pad_left($n, 6); }
function fmt_mass6($m)   { return pad_left(round($m), 6); }
function fmt_mass7($m)   { return sprintf('%07.1f', (float)$m); }

// Build daily sales payload
function build_daily_payload($conn, $spid, $date_f) {
    $bales_q = $conn->query("
        SELECT bm.temp_bacode AS timb_bc, bm.mass,
               g.grower_num, g.national_id, g.name, g.surname,
               tgd.description AS grade, sb.sale_code, sb.price,
               CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END usd_value,
               b.buyer_code, b.name buyer_name
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        LEFT JOIN timb_grades tgd ON tgd.id=sb.timb_gradesid
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        WHERE DATE(sb.created_at)='$date_f' AND g.selling_pointid=$spid
        ORDER BY g.grower_num, bm.temp_bacode
    ");
    $bales = ($bales_q&&$bales_q!==true)?$bales_q->fetch_all(MYSQLI_ASSOC):[];
    return ['date'=>$date_f,'bale_count'=>count($bales),'bales'=>$bales];
}

// Push daily sales payload to TIMB API
function push_to_timb_api($conn, $spid, $date_f, $userid) {
    $creds = get_timb_credentials($conn);
    $payload = build_daily_payload($conn, $spid, $date_f);
    $bcount = (int)$payload['bale_count'];

    if ($bcount === 0) {
        return ['success' => false, 'message' => 'No sold bales found on this date.'];
    }

    $json_payload = json_encode($payload);
    $payload_size = (int)(strlen($json_payload) / 1024);

    if (empty($creds['url']) || stripos($creds['url'], 'mock') !== false) {
        // Simulation / Mock mode
        usleep(300000);
        $success = ($bcount !== 13); // Simulate fail on exactly 13 bales
        $resp_code = $success ? 'MOCK-200' : 'MOCK-422';
        $ref_num = $success ? 'MOCK-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 8)) : '';
        $message = $success ? 'Simulated: Submission accepted by TIMB Gateway.' : 'Simulated Validation Fail: Bales contain mismatched TIMB grade classifications.';
    } else {
        // Real curl push
        $ch = curl_init($creds['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json_payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($creds['username'] . ':' . $creds['password']),
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $success = false;
            $resp_code = 'CURL_ERR';
            $ref_num = '';
            $message = $err;
        } else {
            $success = ($http_code >= 200 && $http_code < 300);
            $resp_code = (string)$http_code;
            $data = @json_decode($resp, true);
            $message = $data['message'] ?? $resp;
            $ref_num = $data['reference'] ?? $data['ref'] ?? '';
        }
    }

    $status = $success ? 'success' : 'failed';
    $msg_esc = $conn->real_escape_string($message);
    $code_esc = $conn->real_escape_string($resp_code);
    $ref_esc = $conn->real_escape_string($ref_num);

    $conn->query("INSERT INTO timb_submission_log
        (selling_pointid, sell_date, bales_count, payload_size_kb, status, response_code, api_response, reference_number, submitted_by)
        VALUES ($spid, '$date_f', $bcount, $payload_size, '$status', '$code_esc', '$msg_esc', '$ref_esc', $userid)");

    if ($success && $ref_num) {
        $conn->query("CREATE TABLE IF NOT EXISTS timb_submissions (id INT AUTO_INCREMENT PRIMARY KEY)"); // Ensure fallback settings table exists
        $conn->query("INSERT INTO timb_submissions (selling_pointid, sell_date, submission_type, bales_count, payload_size_kb, status, response_code, response_message, submitted_by, submitted_at, reference_number)
            VALUES ($spid, '$date_f', 'daily_sales', $bcount, $payload_size, 'accepted', '$code_esc', '$msg_esc', $userid, NOW(), '$ref_esc')");
    }

    return ['success' => $success, 'message' => $message, 'reference' => $ref_num];
}

// ── File generation (GET download) ───────────────────────────────────────────
if (!empty($_GET['generate']) && !empty($_GET['date'])) {
    ob_end_clean();
    $gen_req  = preg_replace('/[^A-Z]/', '', strtoupper($_GET['generate']));
    $gen_all  = ($gen_req === 'ALL');
    $date_raw = $conn->real_escape_string(trim($_GET['date']));
    $mmdd     = date('md', strtotime($date_raw));
    $zip_files = [];

    // Exchange rate
    $er = $conn->query("SELECT amount FROM exchange_rate WHERE active=1 ORDER BY id DESC LIMIT 1");
    if (!$er || !$er->num_rows)
        $er = $conn->query("SELECT amount FROM exchange_rate ORDER BY id DESC LIMIT 1");
    $exch = ($er && $er !== true && $er->num_rows) ? (float)$er->fetch_assoc()['amount'] : 1;

    // Load bales for this date
    $bales_q = $conn->query("
        SELECT tk.barcode, bm.temp_bacode AS timb_bc,
               g.grower_num,
               bj.lot, bj.bale_group, bj.splitid,
               bm.mass,
               COALESCE(sb.price, 0)       AS price,
               COALESCE(sb.sale_code, '')   AS sale_code,
               COALESCE(tg_d.description, '') AS timb_grade
         FROM tickets tk
         JOIN bale_junus bj  ON bj.id  = tk.bale_junusid
         JOIN bale_mass bm   ON bm.id  = bj.bale_massid
         JOIN growers g      ON g.id   = bj.growerid
         JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
         LEFT JOIN sold_bales sb    ON sb.ticketsid = tk.id
         LEFT JOIN timb_grades tg_d ON tg_d.id = sb.timb_gradesid
         WHERE COALESCE(DATE(sb.created_at), DATE(tk.created_at)) = '$date_raw'
           AND g.selling_pointid = $spid
         ORDER BY g.grower_num ASC, bj.lot ASC, bj.bale_group ASC
    ");
    $all_bales = ($bales_q && $bales_q !== true) ? $bales_q->fetch_all(MYSQLI_ASSOC) : [];

    foreach ($all_bales as &$b) {
        $raw = (float)$b['price'];
        $b['price_per_kg'] = $raw > 10 ? $raw / 100 : $raw;
        $b['usd_value']    = $b['mass'] * $b['price_per_kg'];
        $b['zig_value']    = $b['usd_value'] * $exch;
        $b['is_rejected']  = (strtoupper(substr(trim($b['sale_code']),0,1)) === 'R'
                               && strlen(trim($b['sale_code'])) <= 3);
    }
    unset($b);

    foreach (($gen_all ? ['BL','SA','RE','DE','CS','DS'] : [$gen_req]) as $type) {
    $filename = "{$FLOOR_CODE}{$type}{$mmdd}." . ($type === 'CS' ? 'V' : 'v');
    $lines = [];

    // BL — Bale file
    if ($type === 'BL') {
        $seq = $tot_laid = $tot_sold = $tot_rej = 0;
        $tot_laid_mass = $tot_sold_mass = $tot_rej_mass = $tot_usd = 0;
        foreach ($all_bales as $b) {
            $seq++;
            $is_rej = $b['is_rejected'];
            $line  = 'A';
            $line .= str_pad(substr($b['barcode'], 0, 10), 10);
            $line .= str_pad($b['grower_num'], 7);
            $line .= '  ';
            $line .= pad_right($b['timb_grade'], 5);
            $line .= fmt_seq($seq);
            $line .= fmt_mass($b['mass']);
            $line .= '+';
            $line .= fmt_price($b['price_per_kg']);
            $line .= fmt_exch($exch);
            $line .= fmt_usd($b['usd_value']);
            $line .= '+';
            $line .= fmt_zig($b['zig_value']);
            $line .= '+';
            $line .= '    ';
            $line .= ($is_rej ? '*P' : 'NP');
            $line .= $SP_CODE;
            $line .= '  P  ';
            $lines[] = $line;
            $tot_laid++; $tot_laid_mass += $b['mass'];
            if ($is_rej) { $tot_rej++; $tot_rej_mass += $b['mass']; }
            else         { $tot_sold++; $tot_sold_mass += $b['mass']; $tot_usd += $b['usd_value']; }
        }
        $lines[] = sprintf('TOTAL : %d(1) : %.1f :  :ms %.1f :mr %.1f : %.1f',
            $tot_laid, $tot_laid_mass, $tot_sold_mass, $tot_rej_mass, $tot_usd * 100);
    }

    // RE — Rejection file
    elseif ($type === 'RE') {
        $seq = 0;
        foreach ($all_bales as $b) {
            $seq++;
            if (!$b['is_rejected']) continue;
            $lines[] = pad_right($b['timb_grade'], 2)
                     . '      '
                     . pad_left($seq, 7)
                     . pad_left(round($b['mass']), 6);
        }
        $lines[] = 'TOTAL';
    }

    // DE — Deduction file
    elseif ($type === 'DE') {
        $by_grower = [];
        foreach ($all_bales as $b) {
            if ($b['is_rejected']) continue;
            $gn = $b['grower_num'];
            $by_grower[$gn] = ($by_grower[$gn] ?? 0) + $b['zig_value'];
        }
        foreach ($by_grower as $grower_num => $zig_total) {
            $grower_str = str_pad($grower_num, 7);
            $amt_str    = pad_left(round($zig_total * 1000), 20);
            $lines[]    = 'A' . $DEDUCT_TYPE . $grower_str . '  ' . $CHARGE_CODE . '          ' . $amt_str . '+00';
        }
        $lines[] = 'TOTAL';
    }

    // CS — Cumulative summary
    elseif ($type === 'CS') {
        $dates_q = $conn->query("
            SELECT DISTINCT COALESCE(DATE(sb.created_at), DATE(tk.created_at)) AS sell_date
            FROM tickets tk
            LEFT JOIN sold_bales sb ON sb.ticketsid = tk.id
            JOIN bale_junus bj ON bj.id = tk.bale_junusid
            JOIN growers g      ON g.id  = bj.growerid
            WHERE g.selling_pointid = $spid
            ORDER BY sell_date ASC
        ");
        if ($dates_q && $dates_q !== true) {
            while ($dr = $dates_q->fetch_assoc()) {
                $d      = $dr['sell_date'];
                $d_mmdd = date('md', strtotime($d));
                $day_q  = $conn->query("
                    SELECT
                        COUNT(*) AS laid,
                        SUM(bm.mass) AS laid_mass,
                        SUM(CASE WHEN sb.id IS NOT NULL AND NOT (LEFT(UPPER(TRIM(COALESCE(sb.sale_code,''))),1)='R'
                                  AND CHAR_LENGTH(TRIM(COALESCE(sb.sale_code,'')))<=3) THEN 1 ELSE 0 END) AS sold,
                        SUM(CASE WHEN sb.id IS NOT NULL AND NOT (LEFT(UPPER(TRIM(COALESCE(sb.sale_code,''))),1)='R'
                                  AND CHAR_LENGTH(TRIM(COALESCE(sb.sale_code,'')))<=3) THEN bm.mass ELSE 0 END) AS sold_mass,
                        SUM(CASE WHEN sb.id IS NULL OR (LEFT(UPPER(TRIM(COALESCE(sb.sale_code,''))),1)='R'
                                  AND CHAR_LENGTH(TRIM(COALESCE(sb.sale_code,'')))<=3) THEN 1 ELSE 0 END) AS rejected,
                        SUM(CASE WHEN sb.id IS NULL OR (LEFT(UPPER(TRIM(COALESCE(sb.sale_code,''))),1)='R'
                                  AND CHAR_LENGTH(TRIM(COALESCE(sb.sale_code,'')))<=3) THEN bm.mass ELSE 0 END) AS rej_mass,
                        SUM(CASE WHEN sb.id IS NOT NULL THEN sb.price * bm.mass ELSE 0 END) AS gross_usd_raw
                    FROM tickets tk
                    JOIN bale_junus bj ON bj.id  = tk.bale_junusid
                    JOIN bale_mass bm  ON bm.id  = bj.bale_massid
                    JOIN growers g     ON g.id   = bj.growerid
                    LEFT JOIN sold_bales sb ON sb.ticketsid = tk.id
                    WHERE COALESCE(DATE(sb.created_at), DATE(tk.created_at)) = '$d' AND g.selling_pointid = $spid
                ")->fetch_assoc();
                $gross_raw = (float)($day_q['gross_usd_raw'] ?? 0);
                $gross_usd = $gross_raw > ((int)($day_q['sold']??0) * 10) ? $gross_raw / 100 : $gross_raw;
                $sold  = (int)($day_q['sold']      ?? 0);
                $rej   = (int)($day_q['rejected']  ?? 0);
                $laid  = (int)($day_q['laid']       ?? 0);
                $sm    = (float)($day_q['sold_mass'] ?? 0);
                $rm    = (float)($day_q['rej_mass']  ?? 0);
                $line  = fmt_6($laid) . fmt_6($rej) . fmt_mass7($rm)
                       . fmt_6($sold) . fmt_mass7($sm)
                       . pad_left(round($gross_usd), 13)
                       . $FLOOR_CODE . '  ' . $d_mmdd;
                $lines[] = $line;
            }
        }
    }

    // SA — Sale file
    elseif ($type === 'SA') {
        $seen = [];
        foreach ($all_bales as $b) {
            $gn = trim($b['grower_num']);
            if ($gn && !isset($seen[$gn])) $seen[$gn] = true;
        }
        ksort($seen);
        $lines = array_keys($seen);
        $lines[] = 'TOTAL';
    }

    // DS — Daily states
    elseif ($type === 'DS') {
        $tot_laid = $tot_sold = $tot_rej = 0;
        $tot_sold_mass = $tot_usd = $tot_zig = 0;
        $growers_set = [];
        foreach ($all_bales as $b) {
            $tot_laid++;
            $growers_set[$b['grower_num']] = 1;
            if ($b['is_rejected']) { $tot_rej++; }
            else {
                $tot_sold++;
                $tot_sold_mass += $b['mass'];
                $tot_usd       += $b['usd_value'];
                $tot_zig       += $b['zig_value'];
            }
        }
        $line  = pad_right($SP_CODE, 5);
        $line .= fmt_6($tot_laid);
        $line .= fmt_6($tot_rej);
        $line .= fmt_6($tot_sold);
        $line .= pad_left(round($tot_sold_mass * 10), 6);
        $line .= pad_left(round($tot_usd * 1000), 16);
        $line .= pad_left(round($tot_zig * 1000), 16);
        $line .= pad_left(count($growers_set), 2);
        $line .= pad_left(0, 2) . pad_left(0, 2) . pad_left(0, 2);
        $line .= $FLOOR_CODE;
        $lines[] = $line;
        $lines[] = 'TOTAL';
    }

    if (!empty($lines)) {
        $fn_esc   = $conn->real_escape_string($filename);
        $ty_esc   = $conn->real_escape_string($type);
        $conn->query("INSERT INTO file_uploads (userid,file_type,original_filename,direction,sell_date,records_count)
                      VALUES ($userid,'TIMB_$ty_esc','$fn_esc','generated','$date_raw',".count($lines).")");
        $content = implode("\r\n", $lines) . "\r\n";
        if ($gen_all) { $zip_files[$filename] = $content; continue; }
        header('Content-Type: text/plain; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-cache');
        echo $content;
        exit;
    } else {
        $err = "No data found for $date_raw. Ensure bales have been sold first.";
    }
    } // end foreach type

    if ($gen_all && $zip_files) {
        $tmp = tempnam(sys_get_temp_dir(), 'timb');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
            foreach ($zip_files as $n => $c) $zip->addFromString($n, $c);
            $zip->close();
            header('Content-Type: application/zip');
            header("Content-Disposition: attachment; filename=\"{$FLOOR_CODE}{$mmdd}.zip\"");
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: no-cache');
            readfile($tmp);
            unlink($tmp);
            exit;
        }
        $err = "Could not create ZIP archive.";
    }
}

// ── AJAX: date statistics ─────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'date_stats') {
    ob_end_clean(); header('Content-Type: application/json');
    $date = $conn->real_escape_string($_GET['date'] ?? '');
    if (!$date) { echo json_encode(['error' => 'No date']); exit; }

    $sq = $conn->query("
        SELECT COUNT(DISTINCT g.id)  AS growers,
               COUNT(DISTINCT tk.id) AS tickets,
               COALESCE(SUM(bm.mass), 0) AS total_mass,
               COUNT(DISTINCT sb.id) AS sold,
               COUNT(DISTINCT CASE WHEN LEFT(UPPER(TRIM(COALESCE(sb.sale_code,''))),1)='R'
                                    AND CHAR_LENGTH(TRIM(COALESCE(sb.sale_code,'')))<=3
                               THEN sb.id END) AS rejected
        FROM tickets tk
        JOIN bale_junus bj ON bj.id = tk.bale_junusid
        JOIN bale_mass bm  ON bm.id = bj.bale_massid
        JOIN growers g     ON g.id  = bj.growerid
        LEFT JOIN sold_bales sb ON sb.ticketsid = tk.id
        WHERE g.selling_pointid = $spid
          AND COALESCE(DATE(sb.created_at), DATE(tk.created_at)) = '$date'
    ");
    $stats = ($sq && $sq !== true) ? $sq->fetch_assoc() : [];

    // Exchange rate
    $er = $conn->query("SELECT amount FROM exchange_rate WHERE active=1 ORDER BY id DESC LIMIT 1");
    if (!$er || !$er->num_rows)
        $er = $conn->query("SELECT amount FROM exchange_rate ORDER BY id DESC LIMIT 1");
    $exch = ($er && $er !== true && $er->num_rows) ? (float)$er->fetch_assoc()['amount'] : 0;

    // Balanced growers for this date
    $bq = $conn->query("
        SELECT COUNT(DISTINCT bb.id) balanced
        FROM balanced_bales bb
        JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
        WHERE tg.sell_date = '$date'
          AND (SELECT selling_pointid FROM growers WHERE id=tg.growerid LIMIT 1) = $spid
    ");
    $bal = ($bq && $bq !== true && $bq->num_rows) ? (int)$bq->fetch_assoc()['balanced'] : 0;

    echo json_encode([
        'ok'         => true,
        'growers'    => (int)($stats['growers']     ?? 0),
        'tickets'    => (int)($stats['tickets']     ?? 0),
        'sold'       => (int)($stats['sold']         ?? 0),
        'rejected'   => (int)($stats['rejected']     ?? 0),
        'total_mass' => round((float)($stats['total_mass'] ?? 0), 1),
        'balanced'   => $bal,
        'exch_rate'  => $exch,
    ]);
    exit;
}

// ── AJAX: push daily sales directly to TIMB API ───────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'push_api') {
    ob_end_clean(); header('Content-Type: application/json');
    $date = $conn->real_escape_string($_POST['date'] ?? '');
    if (!$date) { echo json_encode(['ok'=>false, 'message'=>'Invalid date.']); exit; }

    $res = push_to_timb_api($conn, $spid, $date, $userid);
    echo json_encode([
        'ok'      => $res['success'],
        'message' => $res['message'],
        'ref'     => $res['reference'] ?? ''
    ]);
    exit;
}

// ── AJAX: retry failed submission ─────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'retry_submission') {
    ob_end_clean(); header('Content-Type: application/json');
    $lid = (int)($_POST['log_id'] ?? 0);
    $log_q = $conn->query("SELECT * FROM timb_submission_log WHERE id=$lid AND selling_pointid=$spid LIMIT 1");
    $log = ($log_q && $log_q !== true) ? $log_q->fetch_assoc() : null;

    if (!$log) { echo json_encode(['ok'=>false, 'message'=>'Submission record not found.']); exit; }

    $res = push_to_timb_api($conn, $spid, $log['sell_date'], $userid);
    if ($res['success']) {
        // Delete or update the old failed record status
        $conn->query("UPDATE timb_submission_log SET status='success', response_code='RETRY-200', api_response='".$conn->real_escape_string($res['message'])."', reference_number='".$conn->real_escape_string($res['reference'])."' WHERE id=$lid");
    }
    echo json_encode([
        'ok'      => $res['success'],
        'message' => $res['message'],
        'ref'     => $res['reference'] ?? ''
    ]);
    exit;
}

// ── Export Submission Log History as CSV ──────────────────────────────────────
if (isset($_GET['export_history_csv'])) {
    ob_end_clean();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="timb_submissions_history_'.date('Ymd').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Sell Date', 'Type', 'Bales Count', 'Size (KB)', 'Status', 'Response Code', 'TIMB Message', 'Reference Number', 'Submitted At']);

    $hist_q = $conn->query("SELECT * FROM timb_submission_log WHERE selling_pointid=$spid ORDER BY id DESC LIMIT 500");
    while ($hist_q && $r = $hist_q->fetch_assoc()) {
        fputcsv($out, [
            $r['id'], $r['sell_date'], $r['submission_type'], $r['bales_count'],
            $r['payload_size_kb'], strtoupper($r['status']), $r['response_code'],
            $r['api_response'], $r['reference_number'], $r['submitted_at']
        ]);
    }
    fclose($out);
    exit;
}

// ── CSV exports (POST) ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']      ?? '';
    $rpt_date = $conn->real_escape_string($_POST['report_date'] ?? $today);

    if ($action === 'export_daily_sales') {
        ob_end_clean();
        $rows_q = $conn->query("
            SELECT g.grower_num, g.name, g.surname, g.national_id,
                   bm.temp_bacode timb_bc, tk.barcode floor_bc, bm.mass,
                   sb.price, tg2.description timb_grade, bg.description buyer_grade,
                   b.buyer_code, b.name buyer_name, DATE(sb.created_at) sell_date
            FROM tickets tk
            JOIN bale_junus bj  ON bj.id = tk.bale_junusid
            JOIN bale_mass bm   ON bm.id = bj.bale_massid
            JOIN growers g      ON g.id  = bj.growerid
            JOIN sold_bales sb  ON sb.ticketsid = tk.id
            JOIN timb_grades tg2 ON tg2.id = sb.timb_gradesid
            JOIN buyer_grades bg ON bg.id  = sb.buyer_gradesid
            JOIN buyer b         ON b.id   = bg.buyerid
            WHERE g.selling_pointid = $spid AND DATE(sb.created_at) = '$rpt_date'
            ORDER BY g.grower_num, bm.temp_bacode
        ");
        header('Content-Type: text/csv');
        header("Content-Disposition: attachment; filename=\"TIMB_DAILY_SALES_{$rpt_date}.csv\"");
        echo "GROWER_NUM,NAME,SURNAME,NATIONAL_ID,TIMB_BARCODE,FLOOR_BARCODE,MASS_KG,PRICE,TIMB_GRADE,BUYER_GRADE,BUYER_CODE,BUYER_NAME,SELL_DATE\r\n";
        while ($rows_q && $r = $rows_q->fetch_assoc())
            echo implode(',', array_map(fn($v) => '"'.str_replace('"','""',(string)$v).'"', array_values($r))) . "\r\n";
        exit;
    }

    if ($action === 'export_origination') {
        ob_end_clean();
        $rows_q = $conn->query("
            SELECT g.grower_num, g.name, g.surname, g.national_id, g.province, g.district, g.farm_name,
                   COUNT(DISTINCT bj.id) total_bales, SUM(bm.mass) total_mass
            FROM growers g
            JOIN transporter_growers tg ON tg.growerid = g.id
            JOIN bale_junus bj  ON bj.transporter_growersid = tg.id
            JOIN bale_mass bm   ON bm.id = bj.bale_massid
            WHERE g.selling_pointid = $spid
            GROUP BY g.id ORDER BY g.grower_num
        ");
        header('Content-Type: text/csv');
        header("Content-Disposition: attachment; filename=\"TIMB_ORIGINATION_{$rpt_date}.csv\"");
        echo "GROWER_NUM,NAME,SURNAME,NATIONAL_ID,PROVINCE,DISTRICT,FARM_NAME,TOTAL_BALES,TOTAL_MASS_KG\r\n";
        while ($rows_q && $r = $rows_q->fetch_assoc())
            echo implode(',', array_map(fn($v) => '"'.str_replace('"','""',(string)$v).'"', array_values($r))) . "\r\n";
        exit;
    }
}

// ── Load sell dates for table ─────────────────────────────────────────────────
$sell_dates_q = $conn->query("
    SELECT COALESCE(DATE(sb.created_at), DATE(tk.created_at)) AS sell_date,
           COUNT(DISTINCT tk.id)      AS tickets,
           COUNT(DISTINCT sb.id)      AS sold,
           COUNT(DISTINCT g.id)       AS growers,
           COALESCE(SUM(bm.mass), 0)  AS total_mass
    FROM tickets tk
    JOIN bale_junus bj ON bj.id = tk.bale_junusid
    JOIN bale_mass bm  ON bm.id = bj.bale_massid
    JOIN growers g     ON g.id  = bj.growerid
    LEFT JOIN sold_bales sb ON sb.ticketsid = tk.id
    WHERE g.selling_pointid = $spid
    GROUP BY COALESCE(DATE(sb.created_at), DATE(tk.created_at))
    ORDER BY sell_date DESC
    LIMIT 60
");
$sell_dates = ($sell_dates_q && $sell_dates_q !== true) ? $sell_dates_q->fetch_all(MYSQLI_ASSOC) : [];

// Season KPIs (across all dates)
$kpi_total_dates   = count($sell_dates);
$kpi_total_tickets = array_sum(array_column($sell_dates, 'tickets'));
$kpi_total_sold    = array_sum(array_column($sell_dates, 'sold'));
$kpi_total_mass    = array_sum(array_column($sell_dates, 'total_mass'));

// Load submission logs
$logs_q = $conn->query("
    SELECT sl.*, u.username
    FROM timb_submission_log sl
    LEFT JOIN users u ON u.id = sl.submitted_by
    WHERE sl.selling_pointid = $spid
    ORDER BY sl.id DESC
    LIMIT 100
");
$submission_logs = ($logs_q && $logs_q !== true) ? $logs_q->fetch_all(MYSQLI_ASSOC) : [];

include "includes/layout.php";
?>

<style>
/* ── Tabs navigation ── */
.tab-bar { display:flex; gap:0; border-bottom:1px solid var(--border); margin-bottom:1.25rem; }
.tab-btn { padding:.55rem 1.1rem; font-size:.75rem; font-weight:500; cursor:pointer; border:none; background:none; color:var(--muted); border-bottom:2px solid transparent; transition:all .13s; letter-spacing:.04em; }
.tab-btn.active { color:var(--gold); border-bottom-color:var(--gold); }
.tab-btn:hover:not(.active) { color:var(--text-strong); }
.tab-pane { display:none; }
.tab-pane.active { display:block; }

/* ── Sell dates table ── */
#dates-table tbody tr { cursor:pointer; transition:background .1s; }
#dates-table tbody tr:hover td { background:rgba(200,149,42,.07); }
#dates-table td { font-size:.74rem; padding:.45rem .7rem; white-space:nowrap; }
#dates-table th { font-size:.72rem; padding:.42rem .7rem; white-space:nowrap; }
.sold-pct { display:inline-block; width:48px; height:5px; border-radius:3px;
            background:var(--border); overflow:hidden; vertical-align:middle; margin-right:4px; }
.sold-pct-fill { height:100%; border-radius:3px; background:var(--leaf); transition:width .3s; }

/* ── TIMB modal ── */
#tmo {
  display:none; position:fixed; inset:0; z-index:9000;
  background:rgba(10,7,3,.72); backdrop-filter:blur(3px);
  overflow-y:auto; padding:2rem 1rem;
  animation:tmo-in .18s ease;
}
@keyframes tmo-in{from{opacity:0}to{opacity:1}}
#tmb {
  background:var(--surface); border-radius:14px;
  max-width:860px; margin:0 auto;
  box-shadow:0 24px 64px rgba(0,0,0,.4), 0 0 0 1px rgba(200,149,42,.12);
  overflow:hidden; animation:tmb-in .2s cubic-bezier(.16,1,.3,1);
}
@keyframes tmb-in{from{opacity:0;transform:translateY(16px) scale(.98)}to{opacity:1;transform:none}}
.tm-head {
  background:linear-gradient(135deg,#1a1208 0%,#2d2010 100%);
  padding:1rem 1.3rem; display:flex; align-items:center; gap:.85rem; flex-wrap:wrap;
  border-bottom:2px solid rgba(200,149,42,.25);
}
#tm-date { font-family:'DM Sans',sans-serif; font-size:1.23rem; color:var(--accent-text); line-height:1;font-weight:600}
#tm-sub  { font-size:.67rem; color:var(--text-muted); margin-top:.18rem; }
.tm-chip { display:flex; flex-direction:column; align-items:center; background:rgba(255,255,255,.06);
           border:1px solid rgba(200,149,42,.18); border-radius:8px; padding:.3rem .65rem; min-width:54px; }
.tm-chip-l { font-size:.78rem; color:var(--text-muted); }
.tm-chip-v { font-size:.9rem; font-weight:700; color:var(--accent-text); line-height:1.2; }
.tm-chip-v.g { color:#5dbf6a; }
.tm-chip-v.w { color:#f0ebe0; }
#tm-close { background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15);
             color:rgba(245,240,232,.65); width:30px; height:30px; border-radius:7px;
             font-size:1.1rem; cursor:pointer; flex-shrink:0; line-height:1; transition:.15s; }
#tm-close:hover { background:rgba(255,255,255,.2); color:#fff; }

/* Report cards grid */
.report-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; padding:1rem 1.3rem; }
@media(max-width:600px){ .report-grid{grid-template-columns:1fr 1fr;} }
.report-card { border:1px solid var(--line); border-radius:10px; padding:.75rem .9rem;
               background:var(--surface-2); transition:border-color .15s, box-shadow .15s; }
.report-card:hover { border-color:#c8952a; box-shadow:0 2px 10px rgba(200,149,42,.12); }
.report-card-code { font-family:'DM Sans',sans-serif; font-size:1.1rem; color:var(--accent-text); line-height:1;font-weight:600}
.report-card-name { font-size:.78rem; font-weight:600; color:var(--text); margin:.2rem 0 .18rem; }
.report-card-desc { font-size:.66rem; color:#8a7050; line-height:1.35; min-height:2.5em; }
.report-card-file { font-size:.72rem; font-family:monospace; color:#aaa; margin:.35rem 0 .55rem; }

/* CSV section */
.csv-section { padding:.65rem 1.3rem; border-top:1px solid var(--line); background:var(--surface-2); }
.csv-section-title { font-size:.78rem; font-weight:700; color:#9a7830; margin-bottom:.5rem; }

/* Footer */
.tm-foot { padding:.75rem 1.3rem; background:var(--surface-2); border-top:2px solid var(--line);
            display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; }
.tm-settings { font-size:.66rem; color:#aaa; }
.tm-settings strong { color:#8a6820; }

/* Spinner */
.tm-spinner { text-align:center; padding:2rem; color:#aaa; font-size:.82rem; }

/* Toast */
#tr-toast { position:fixed; bottom:1.5rem; left:50%; transform:translateX(-50%);
             background:#1a1208; color:#f0ebe0; padding:.55rem 1.1rem; border-radius:8px;
             font-size:.8rem; font-weight:500; box-shadow:0 6px 20px rgba(0,0,0,.35);
             z-index:9999; display:none; white-space:nowrap; }

/* KPIs */
.ksi{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.85rem}
.ksc{flex:1;min-width:105px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:.55rem .8rem}
.ksl{font-size:.78rem;color:var(--muted);}
.ksv{font-size:1.05rem;font-weight:700;line-height:1.2;margin-top:.1rem;color:var(--gold)}
.ksv.g{color:var(--leaf)}.ksv.w{color:var(--text)}
.kss{font-size:.72rem;color:var(--muted);margin-top:.03rem}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>TIMB Reports &amp; Submissions</h2>
    <p>Generate end-of-day files &amp; push daily sales transactions directly to TIMB API
    </p>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err)  ?></div><?php endif; ?>

<!-- KPIs -->
<div class="ksi">
  <div class="ksc">
    <div class="ksl">Sell Dates</div>
    <div class="ksv w"><?= $kpi_total_dates ?></div>
    <div class="kss">in history</div>
  </div>
  <div class="ksc">
    <div class="ksl">Total Tickets</div>
    <div class="ksv w"><?= number_format($kpi_total_tickets) ?></div>
    <div class="kss">all dates</div>
  </div>
  <div class="ksc">
    <div class="ksl">Sold Bales</div>
    <div class="ksv g"><?= number_format($kpi_total_sold) ?></div>
    <div class="kss">with sale record</div>
  </div>
  <div class="ksc">
    <div class="ksl">Total Mass</div>
    <div class="ksv"><?= number_format($kpi_total_mass, 0) ?> kg</div>
    <div class="kss">all dates</div>
  </div>
  <div class="ksc">
    <div class="ksl">Floor Code</div>
    <div class="ksv w" style="font-family:monospace;letter-spacing:.08em"><?= htmlspecialchars($FLOOR_CODE) ?></div>
    <div class="kss">TIMB identifier</div>
  </div>
</div>

<!-- Tabs Bar -->
<div class="tab-bar">
  <button class="tab-btn <?=$tab==='dates'?'active':''?>" onclick="switchTab('dates')">🗂 Sell Dates &amp; Files</button>
  <button class="tab-btn <?=$tab==='log'?'active':''?>" onclick="switchTab('log')">📤 API Submission Log</button>
</div>

<!-- ════════════════════ TAB: SELL DATES & FILES ════════════════════ -->
<div class="tab-pane <?=$tab==='dates'?'active':''?>" id="tab-dates">
  <div class="card">
    <div class="card-head">
      <h3>Sell Dates</h3>
      <div style="display:flex;align-items:center;gap:.5rem">
        <input type="text" id="date-search" placeholder="Filter dates…" oninput="filterDates(this.value)"
               class="form-control" style="width:180px;font-size:.8rem;padding:.3rem .6rem">
        <span class="badge badge-gray"><?= $kpi_total_dates ?></span>
      </div>
    </div>
    <div class="table-wrap">
    <table id="dates-table">
      <thead><tr>
        <th>#</th>
        <th>Date</th>
        <th>Day</th>
        <th class="text-right">Growers</th>
        <th class="text-right">Tickets</th>
        <th class="text-right">Sold</th>
        <th class="text-right">Mass kg</th>
        <th>Coverage</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php if (empty($sell_dates)): ?>
      <tr><td colspan="9" class="text-center text-muted" style="padding:2.5rem">
        No sell date data found. Ensure bales have been received and sold.
      </td></tr>
      <?php else: $seq = 0; foreach ($sell_dates as $r): $seq++;
          $mmdd    = date('md', strtotime($r['sell_date']));
          $pct     = $r['tickets'] > 0 ? min(100, round($r['sold'] / $r['tickets'] * 100)) : 0;
          $srch    = $r['sell_date'] . ' ' . strtolower(date('D d M Y', strtotime($r['sell_date'])));
      ?>
      <tr data-date="<?= $r['sell_date'] ?>"
          data-tickets="<?= (int)$r['tickets'] ?>"
          data-sold="<?= (int)$r['sold'] ?>"
          data-growers="<?= (int)$r['growers'] ?>"
          data-mass="<?= round((float)$r['total_mass'], 1) ?>"
          data-mmdd="<?= $mmdd ?>"
          data-search="<?= htmlspecialchars($srch, ENT_QUOTES) ?>"
          onclick="openDate(this)">
        <td class="td-muted" style="font-size:.68rem"><?= $seq ?></td>
        <td class="fw-500" style="color:var(--text-strong)"><?= date('d M Y', strtotime($r['sell_date'])) ?></td>
        <td style="color:var(--muted);font-size:.72rem"><?= date('D', strtotime($r['sell_date'])) ?></td>
        <td class="td-mono text-right"><?= (int)$r['growers'] ?></td>
        <td class="td-mono text-right"><?= number_format((int)$r['tickets']) ?></td>
        <td class="td-mono text-right" style="color:var(--leaf);font-weight:600"><?= number_format((int)$r['sold']) ?></td>
        <td class="td-mono text-right"><?= number_format((float)$r['total_mass'], 0) ?></td>
        <td style="min-width:80px">
          <div style="display:flex;align-items:center;gap:5px">
            <div class="sold-pct"><div class="sold-pct-fill" style="width:<?= $pct ?>%"></div></div>
            <span style="font-size:.67rem;color:var(--muted)"><?= $pct ?>%</span>
          </div>
        </td>
        <td style="text-align:right;padding-right:.9rem;color:var(--muted);font-size:.8rem">&#8250;</td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ════════════════════ TAB: SUBMISSION LOG ════════════════════ -->
<div class="tab-pane <?=$tab==='log'?'active':''?>" id="tab-log">
  <div class="card">
    <div class="card-head">
      <h3>TIMB API Submission History</h3>
      <div style="display:flex;gap:.5rem;align-items:center">
        <a href="?export_history_csv=1" class="btn btn-ghost btn-sm">⬇ Export History CSV</a>
        <span class="badge badge-gray"><?= count($submission_logs) ?> entries</span>
      </div>
    </div>
    <div class="table-wrap">
      <table id="logs-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Sell Date</th>
            <th>Bales</th>
            <th>Payload Size</th>
            <th>Status</th>
            <th>Resp Code</th>
            <th>TIMB Message</th>
            <th>Reference Number</th>
            <th>Submitted By</th>
            <th>Time</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($submission_logs)): ?>
          <tr><td colspan="11" class="text-center text-muted" style="padding:2.5rem">No API submissions recorded yet. Use the modal to push sales data.</td></tr>
          <?php else: foreach($submission_logs as $log):
            $st = $log['status'];
            $st_badge = $st === 'success' ? 'badge-green' : ($st === 'pending' ? 'badge-gold' : 'badge-red');
          ?>
          <tr id="log-row-<?=$log['id']?>">
            <td class="td-muted td-mono"><?=$log['id']?></td>
            <td class="fw-500"><?=date('d M Y', strtotime($log['sell_date']))?></td>
            <td class="td-mono"><?=number_format($log['bales_count'])?></td>
            <td class="td-muted"><?=$log['payload_size_kb']?> KB</td>
            <td><span class="badge <?=$st_badge?>"><?=strtoupper($st)?></span></td>
            <td class="td-mono fw-500"><?=$log['response_code'] ?: '—'?></td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($log['api_response'])?>">
              <?=htmlspecialchars($log['api_response'])?>
            </td>
            <td class="td-mono" style="font-size:.7rem;color:var(--muted)"><?=$log['reference_number'] ?: '—'?></td>
            <td style="font-size:.7rem"><?=htmlspecialchars($log['username'] ?: 'System')?></td>
            <td class="td-muted" style="font-size:.7rem"><?=date('d M H:i', strtotime($log['submitted_at']))?></td>
            <td>
              <?php if($st === 'failed'): ?>
              <button class="btn btn-primary btn-sm" onclick="retrySubmission(<?=$log['id']?>)" style="font-size:.65rem;padding:2px 7px">
                ⟳ Retry
              </button>
              <?php else: ?>
              <span class="td-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- DATE DETAIL MODAL                                                           -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div id="tmo" onclick="if(event.target===this)closeDate()">
<div id="tmb">

  <!-- Header -->
  <div class="tm-head">
    <div style="flex:1;min-width:0">
      <div id="tm-date">--</div>
      <div id="tm-sub">--</div>
    </div>
    <div id="tm-chips" style="display:flex;gap:.4rem;flex-wrap:wrap"></div>
    <button id="tm-close" onclick="closeDate()">&times;</button>
  </div>

  <!-- Body: report cards -->
  <div id="tm-body">
    <div class="tm-spinner">Loading…</div>
  </div>

  <!-- Footer -->
  <div class="tm-foot">
    <button class="btn btn-gold btn-sm" onclick="pushToTIMBAPI()" id="tm-push-api">
      📤 Push Directly to TIMB API
    </button>
    <button class="btn btn-primary btn-sm" onclick="generateAll()" id="tm-gen-all">
      &#8659; Generate All 6 Files
    </button>
    <button class="btn btn-ghost btn-sm" style="margin-left:auto" onclick="closeDate()">Close</button>
    <div class="tm-settings">
      Floor: <strong><?= htmlspecialchars($FLOOR_CODE) ?></strong> &nbsp;·&nbsp;
      SP: <strong><?= htmlspecialchars($SP_CODE) ?></strong> &nbsp;·&nbsp;
      Charge: <strong><?= htmlspecialchars($CHARGE_CODE) ?></strong>
    </div>
  </div>

</div>
</div>

<!-- Toast -->
<div id="tr-toast"></div>

<script>
// ── Report type definitions ──
var REPORT_TYPES = [
    { code:'BL', name:'Bale File',          ext:'.v',
      desc:'All bales — barcode, grade, mass, price, USD &amp; ZiG values' },
    { code:'SA', name:'Sale File',          ext:'.v',
      desc:'Growers on sale this date — one V-number per line' },
    { code:'RE', name:'Rejection File',      ext:'.v',
      desc:'Bales carrying a rejection sale code' },
    { code:'DE', name:'Deduction File',      ext:'.v',
      desc:'RBZ and stop order deductions per grower in ZiG' },
    { code:'CS', name:'Cumulative Summary',  ext:'.V',
      desc:'All sell dates to date — bales, mass, USD values' },
    { code:'DS', name:'Daily States File',   ext:'.v',
      desc:'Day totals — laid, sold, rejected, USD, ZiG' },
];

var FLOOR_CODE = '<?= htmlspecialchars($FLOOR_CODE) ?>';
var _curDate = '';
var _curMmdd = '';

// Tab switcher
function switchTab(t) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-'+t).classList.add('active');
  document.querySelectorAll('.tab-btn').forEach(b => { if(b.textContent.toLowerCase().includes(t.substring(0,4))) b.classList.add('active'); });
  // Update URL without reload
  var url = new URL(window.location);
  url.searchParams.set('tab', t);
  history.replaceState(null, '', url);
}

// ── Utilities ──
function toast(msg) {
    var t = document.getElementById('tr-toast');
    t.textContent = msg;
    t.style.display = 'block';
    clearTimeout(t._tmr);
    t._tmr = setTimeout(function(){ t.style.display='none'; }, 2600);
}
function filterDates(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#dates-table tbody tr[data-date]').forEach(function(tr){
        var s = tr.dataset.search || '';
        tr.style.display = (!q || s.includes(q)) ? '' : 'none';
    });
}

// ── Open date modal ──
async function openDate(tr) {
    _curDate = tr.dataset.date;
    _curMmdd = tr.dataset.mmdd;

    // Seed from table data immediately
    document.getElementById('tm-date').textContent =
        new Date(_curDate + 'T12:00:00').toLocaleDateString('en-GB', {weekday:'long',day:'numeric',month:'long',year:'numeric'}).toUpperCase();
    document.getElementById('tm-sub').textContent = 'MMDD: ' + _curMmdd + '  ·  Loading stats…';
    document.getElementById('tm-chips').innerHTML = '';
    renderChips({
        tickets: tr.dataset.tickets, sold: tr.dataset.sold,
        growers: tr.dataset.growers, total_mass: tr.dataset.mass,
    });
    document.getElementById('tm-body').innerHTML = '<div class="tm-spinner">Loading…</div>';
    document.getElementById('tmo').style.display = 'block';

    // Load full stats via AJAX
    try {
        var r  = await fetch(location.pathname + '?ajax=date_stats&date=' + _curDate,
                             { headers:{'X-Requested-With':'XMLHttpRequest'} });
        var d  = await r.json();
        if (d.ok) {
            renderChips(d);
            document.getElementById('tm-sub').textContent =
                'MMDD: ' + _curMmdd
                + (d.exch_rate ? '  ·  Rate: ' + d.exch_rate.toLocaleString('en', {minimumFractionDigits:2,maximumFractionDigits:2}) + ' ZiG/USD' : '');
        }
    } catch(e) {}

    renderReportBody();
}

function renderChips(d) {
    var chips = [
        { l:'Tickets',  v: (d.tickets||0),                 cls:'' },
        { l:'Sold',     v: (d.sold||0),                    cls:'g' },
        { l:'Growers',  v: (d.growers||0),                 cls:'w' },
        { l:'Mass kg',  v: parseFloat(d.total_mass||0).toLocaleString('en',{maximumFractionDigits:0}), cls:'w' },
    ];
    if (d.exch_rate)
        chips.push({ l:'ZiG/USD', v: parseFloat(d.exch_rate).toFixed(2), cls:'' });
    document.getElementById('tm-chips').innerHTML = chips.map(function(c){
        return '<div class="tm-chip">'
             + '<div class="tm-chip-l">' + c.l + '</div>'
             + '<div class="tm-chip-v ' + c.cls + '">' + c.v + '</div>'
             + '</div>';
    }).join('');
}

function renderReportBody() {
    var html = '<div class="report-grid">';
    REPORT_TYPES.forEach(function(rt) {
        var fn = '<?= htmlspecialchars($FLOOR_CODE) ?>' + rt.code + _curMmdd + rt.ext;
        var dl = '?generate=' + rt.code + '&date=' + _curDate;
        html += '<div class="report-card">'
              + '<div class="report-card-code">' + rt.code + '</div>'
              + '<div class="report-card-name">' + rt.name + '</div>'
              + '<div class="report-card-desc">' + rt.desc + '</div>'
              + '<div class="report-card-file">' + fn + '</div>'
              + '<a href="' + dl + '" class="btn btn-primary btn-sm" style="width:100%;text-align:center;font-size:.74rem"'
              + '   onclick="toast(\'Generating ' + rt.code + ' — ' + rt.name + '…\')">'
              + '&#8659; Download</a>'
              + '</div>';
    });
    html += '</div>';

    // CSV exports section
    html += '<div class="csv-section">'
          + '<div class="csv-section-title">Additional CSV Exports</div>'
          + '<form method="POST" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">'
          + '<input type="hidden" name="report_date" value="' + _curDate + '">'
          + '<button name="action" value="export_daily_sales" class="btn btn-ghost btn-sm"'
          + '   onclick="toast(\'Exporting Daily Sales CSV…\')">'
          + '&#8659; Daily Sales Return (CSV)</button>'
          + '<button name="action" value="export_origination" class="btn btn-ghost btn-sm"'
          + '   onclick="toast(\'Exporting Origination CSV…\')">'
          + '&#8659; Origination Report (CSV)</button>'
          + '</form>'
          + '</div>';

    document.getElementById('tm-body').innerHTML = html;
}

function closeDate() {
    document.getElementById('tmo').style.display = 'none';
    _curDate = ''; _curMmdd = '';
}

// Push to TIMB API directly
function pushToTIMBAPI() {
    if(!confirm("Push daily sales for " + _curDate + " directly to TIMB API?")) return;
    var btn = document.getElementById('tm-push-api');
    btn.disabled = true;
    btn.innerHTML = '<span class="fw-spinner"></span> Pushing…';

    var fd = new FormData();
    fd.append('date', _curDate);

    fetch('timb_reports.php?ajax=push_api', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        btn.innerHTML = '📤 Push Directly to TIMB API';
        if (d.ok) {
            alert('✓ TIMB Submission Successful!\nReference ID: ' + d.ref + '\nMessage: ' + d.message);
            closeDate();
            location.href = '?tab=log';
        } else {
            alert('✕ TIMB API Submission Failed:\n' + d.message);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '📤 Push Directly to TIMB API';
        alert('✕ Connection Error: Handshake timed out contacting TIMB.');
    });
}

// Retry a failed submission from log history
function retrySubmission(logId) {
    if(!confirm("Retry TIMB submission for this record?")) return;
    var row = document.getElementById('log-row-' + logId);
    var btn = row.querySelector('button');
    btn.disabled = true;
    btn.textContent = '…';

    var fd = new FormData();
    fd.append('log_id', logId);

    fetch('timb_reports.php?ajax=retry_submission', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            toast('✓ Retry Successful: Reference ' + d.ref);
            setTimeout(() => location.reload(), 1000);
        } else {
            btn.disabled = false;
            btn.textContent = '⟳ Retry';
            alert('✕ Submission failed again:\n' + d.message);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = '⟳ Retry';
        alert('✕ Connection Error.');
    });
}

// Generate all 6 files as a single ZIP (browsers block multiple automatic downloads)
function generateAll() {
    var btn = document.getElementById('tm-gen-all');
    btn.disabled = true;
    btn.textContent = 'Generating…';
    var a = document.createElement('a');
    a.href = '?generate=ALL&date=' + encodeURIComponent(_curDate) + '&_=' + Date.now();
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    toast('Downloading all 6 files as one ZIP');
    setTimeout(function(){
        btn.disabled = false;
        btn.innerHTML = '&#8659; Generate All 6 Files';
    }, 4000);
}
</script>

<?php include "includes/layout_end.php"; ?>
