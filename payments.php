<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title = "Payments"; $active_page = "payments";
$userid = current_userid(); $spid = (int)($_SESSION['selling_pointid'] ?? 0);
$msg = $err = '';

// Net payable is read from the stored net (includes/net_amount.php); this page does not recalculate it.

// ── Payment method groups ──────────────────────────────────────────────────
$PAYMENT_TYPES = [
    'Cash'                => ['icon'=>'💵','color'=>'#3d6b35'],
    'Nedbank Transfer'    => ['icon'=>'🏦','color'=>'#1a6b9a'],
    'FBC Bank Transfer'   => ['icon'=>'🏦','color'=>'#1a6b9a'],
    'BancABC Transfer'    => ['icon'=>'🏦','color'=>'#1a6b9a'],
    'CBZ Transfer'        => ['icon'=>'🏦','color'=>'#1a6b9a'],
    'Stanbic Transfer'    => ['icon'=>'🏦','color'=>'#1a6b9a'],
    'Other Bank Transfer' => ['icon'=>'🏦','color'=>'#1a6b9a'],
    'EcoCash'             => ['icon'=>'📱','color'=>'#c8952a'],
    'Mukuru'              => ['icon'=>'📱','color'=>'#c8952a'],
    'InnBucks'            => ['icon'=>'📱','color'=>'#c8952a'],
    'RTGS'                => ['icon'=>'⚡','color'=>'#7a6e5f'],
    'Cheque'              => ['icon'=>'📝','color'=>'#7a6e5f'],
];

// ── Tables ────────────────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS payment_status (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transporter_growersid INT NOT NULL,
  sell_date DATE NOT NULL,
  currency ENUM('usd','zig') DEFAULT 'usd',
  gross_amount DECIMAL(14,4) DEFAULT 0,
  deductions DECIMAL(14,4) DEFAULT 0,
  net_amount DECIMAL(14,4) DEFAULT 0,
  status ENUM('pending','paid','partial','on_hold') DEFAULT 'pending',
  payment_method VARCHAR(60) DEFAULT '',
  reference_number VARCHAR(100) DEFAULT '',
  paid_at TIMESTAMP NULL DEFAULT NULL,
  paid_by INT NULL DEFAULT NULL,
  notes VARCHAR(255) DEFAULT '',
  UNIQUE KEY ps_unique (transporter_growersid, sell_date, currency),
  INDEX(sell_date)
)");
$conn->query("CREATE TABLE IF NOT EXISTS payment_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_statusid INT NOT NULL,
  userid INT NOT NULL,
  action VARCHAR(30) NOT NULL,
  currency ENUM('usd','zig') DEFAULT 'usd',
  amount DECIMAL(14,4) DEFAULT 0,
  payment_method VARCHAR(60) DEFAULT '',
  reference_number VARCHAR(100) DEFAULT '',
  notes VARCHAR(255) DEFAULT '',
  logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(payment_statusid), INDEX(logged_at)
)");


// Payment identity (PAY-/RUN- references + sales-sheet links) lives in a shared
// include so the approval workflow in pay_batches.php uses the exact same rules.
require_once "includes/payment_refs.php";
require_once "includes/pay_helpers.php"; // flowork_assign_receipt()
require_once "includes/net_amount.php";   // flowork_net_join(), flowork_net_for_bbid()

// ── Batch payment table ────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS payment_batch (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sell_date DATE NOT NULL,
  currency ENUM('usd','zig') DEFAULT 'usd',
  payment_method VARCHAR(60) NOT NULL,
  batch_ref VARCHAR(100) DEFAULT '',
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reversed_at TIMESTAMP NULL DEFAULT NULL,
  reversed_by INT NULL DEFAULT NULL,
  reversal_reason VARCHAR(255) DEFAULT '',
  INDEX(sell_date)
)");
$conn->query("CREATE TABLE IF NOT EXISTS payment_batch_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_batchid INT NOT NULL,
  transporter_growersid INT NOT NULL,
  net_amount DECIMAL(14,4) DEFAULT 0,
  currency ENUM('usd','zig') DEFAULT 'usd',
  INDEX(payment_batchid)
)");
// Upgrade: add balanced_balesid to payment_batch_item (bale-level tracking)
$_ck = $conn->query("SHOW COLUMNS FROM payment_batch_item LIKE 'balanced_balesid'");
if ($_ck && $_ck->num_rows === 0)
    $conn->query("ALTER TABLE payment_batch_item ADD COLUMN balanced_balesid INT NULL DEFAULT NULL AFTER transporter_growersid");

// Upgrade: add reversal columns to payment_batch if missing
foreach (['reversed_at TIMESTAMP NULL DEFAULT NULL',
          'reversed_by INT NULL DEFAULT NULL',
          "reversal_reason VARCHAR(255) DEFAULT ''"] as $_cd) {
    $_cn = explode(' ', $_cd)[0];
    $_ck = $conn->query("SHOW COLUMNS FROM payment_batch LIKE '$_cn'");
    if ($_ck && $_ck->num_rows === 0)
        $conn->query("ALTER TABLE payment_batch ADD COLUMN $_cd");
}

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Confirm individual payment
    if ($action === 'confirm_payment') {
        $tgid     = (int)($_POST['tgid'] ?? 0);
        $date     = $conn->real_escape_string($_POST['sell_date'] ?? '');
        $currency = $_POST['currency'] === 'zig' ? 'zig' : 'usd';
        $method   = $conn->real_escape_string(trim($_POST['pay_method']  ?? ''));
        $ref      = $conn->real_escape_string(trim($_POST['pay_ref']     ?? ''));
        $notes    = $conn->real_escape_string(trim($_POST['pay_notes']   ?? ''));
        $now      = date('Y-m-d H:i:s');
        if (!$tgid || !$date || !$method) { $err = "Grower, date and payment method required."; }
        else {
            // Amount = stored net of this grower's deliveries balanced on the date (single source).
            $net_col = $currency === 'zig' ? 'zim_net_value' : 'usd_net_value';
            $amt_q   = $conn->query("SELECT COALESCE(SUM(dtn.$net_col),0) AS net FROM balanced_bales bb ".flowork_net_join()."
                WHERE bb.transporter_growersid=$tgid AND DATE(bb.created_at)='$date'");
            $amount  = ($amt_q && $amt_q !== true) ? (float)$amt_q->fetch_assoc()['net'] : 0;
            $ex = $conn->query("SELECT id FROM payment_status WHERE transporter_growersid=$tgid AND sell_date='$date' AND currency='$currency' LIMIT 1")->fetch_assoc();
            if ($ex) {
                $psid = (int)$ex['id'];
                $conn->query("UPDATE payment_status SET status='paid',net_amount=$amount,payment_method='$method',reference_number='$ref',paid_at='$now',paid_by=$userid,notes='$notes' WHERE id=$psid");
            } else {
                $conn->query("INSERT INTO payment_status (transporter_growersid,sell_date,currency,net_amount,status,payment_method,reference_number,paid_at,paid_by,notes) VALUES ($tgid,'$date','$currency',$amount,'paid','$method','$ref','$now',$userid,'$notes')");
                $psid = (int)$conn->insert_id;
            }
            // Receipt number: always for cash, otherwise only when no bank ref was typed
            $ref = $conn->real_escape_string(flowork_assign_receipt($conn, $psid, $method));
            // Give the payment its own unique ID and tie it to the sales sheets it settles
            $pay_ref = flowork_pay_ref_for($conn, $psid);
            $sheets_n = flowork_link_payment_sheets($conn, $psid, $tgid, $date);
            pay_log($conn, 'payment_status', $psid, $userid, 'paid', $currency, $amount, $_POST['pay_method'] ?? '', stripslashes($ref), $_POST['pay_notes'] ?? '');
            $msg = "✓ ".strtoupper($currency)." payment $pay_ref confirmed via $method".($ref?" · receipt/ref $ref":"")
                 . ($sheets_n ? " · covers $sheets_n sales sheet".($sheets_n>1?'s':'') : "").".";
        }
    }

    // Create payment batch
    if ($action === 'create_batch') {
        $date     = $conn->real_escape_string($_POST['sell_date'] ?? '');
        $currency = $_POST['currency'] === 'zig' ? 'zig' : 'usd';
        $method   = $conn->real_escape_string(trim($_POST['batch_method'] ?? ''));
        $ref      = $conn->real_escape_string(trim($_POST['batch_ref']    ?? ''));
        $notes    = $conn->real_escape_string(trim($_POST['batch_notes']  ?? ''));
        // bbids: one per bale (unique key); tgids_map & nets_map keyed by bbid
        $bbids     = array_values(array_filter(array_map('intval', $_POST['batch_bbids'] ?? [])));
        $tgids_map = $_POST['batch_tgids'] ?? [];   // [bbid => tgid]
        // Amounts come from the stored net (flowork_net_for_bbid), never from the posted form.
        $nets_map  = [];
        foreach ($bbids as $b0) $nets_map[$b0] = flowork_net_for_bbid($conn, $b0, $currency);

        if (!$date || !$method || empty($bbids)) {
            $err = "Date, payment method and at least one grower required.";
        } else {
            $conn->query("INSERT INTO payment_batch (sell_date,currency,payment_method,batch_ref,notes,created_by) VALUES ('$date','$currency','$method','$ref','$notes',$userid)");
            $bid = (int)$conn->insert_id;
            $now = date('Y-m-d H:i:s');
            $paid_tgids = []; // track payment_status already handled per tgid

            foreach ($bbids as $bbid) {
                $tgid = (int)($tgids_map[$bbid] ?? 0);
                if (!$tgid) continue;

                $amt = $nets_map[$bbid];

                // Insert one batch item per bale (balanced_balesid stored for the report)
                $conn->query("INSERT INTO payment_batch_item
                    (payment_batchid,transporter_growersid,balanced_balesid,net_amount,currency)
                    VALUES ($bid,$tgid,$bbid,$amt,'$currency')");

                // Mark payment_status paid at tgid level (once per tgid, not per bale)
                if (!isset($paid_tgids[$tgid])) {
                    $paid_tgids[$tgid] = true;
                    // Sum ALL selected bales for this tgid to store as the ps net_amount
                    $tgid_total = 0;
                    foreach ($bbids as $b2) {
                        if ((int)($tgids_map[$b2] ?? 0) === $tgid)
                            $tgid_total += isset($nets_map[$b2]) ? (float)$nets_map[$b2] : 0;
                    }
                    $ex = $conn->query("SELECT id FROM payment_status WHERE transporter_growersid=$tgid AND sell_date='$date' AND currency='$currency' LIMIT 1")->fetch_assoc();
                    if ($ex) {
                        $psid=(int)$ex['id'];
                        $conn->query("UPDATE payment_status SET status='paid',net_amount=$tgid_total,payment_method='$method',reference_number='$ref',paid_at='$now',paid_by=$userid WHERE id=$psid");
                    } else {
                        $conn->query("INSERT INTO payment_status (transporter_growersid,sell_date,currency,net_amount,status,payment_method,reference_number,paid_at,paid_by) VALUES ($tgid,'$date','$currency',$tgid_total,'paid','$method','$ref','$now',$userid)");
                        $psid=(int)$conn->insert_id;
                    }
                    $g_ref = $conn->real_escape_string(flowork_assign_receipt($conn, $psid, $method));
                    // Unique payment ID + the sales sheets this batch payment settles
                    flowork_pay_ref_for($conn, $psid);
                    $tg_sheets = [];
                    foreach ($bbids as $b2) {
                        if ((int)($tgids_map[$b2] ?? 0) === $tgid) $tg_sheets[] = $b2;
                    }
                    flowork_link_payment_sheet_ids($conn, $psid, $tg_sheets);
                    pay_log($conn, 'payment_status', $psid, $userid, 'batch_paid', $currency, $tgid_total, $_POST['batch_method'] ?? '', stripslashes($g_ref));
                }
            }
            $msg = "✓ Batch created: ".count($bbids)." bale(s) for ".count($paid_tgids)." grower(s) marked paid via $method.";
            header("Location: payments.php?date=$date&batch_id=$bid&msg=".urlencode($msg)); exit;
        }
    }

    if ($action === 'mark_hold') {
        $tgid  = (int)($_POST['tgid'] ?? 0);
        $date  = $conn->real_escape_string($_POST['sell_date'] ?? '');
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
        $conn->query("UPDATE payment_status SET status='on_hold',notes='$notes' WHERE transporter_growersid=$tgid AND sell_date='$date'");
        $msg = "Placed on hold.";
    }

    // ── Reverse individual grower payment ─────────────────────────────────
    if ($action === 'reverse_payment') {
        $tgid     = (int)($_POST['tgid']      ?? 0);
        $date     = $conn->real_escape_string($_POST['sell_date']  ?? '');
        $currency = ($_POST['currency'] ?? '') === 'zig' ? 'zig' : 'usd';
        $reason   = $conn->real_escape_string(trim($_POST['reason'] ?? ''));
        if (!$tgid || !$date) {
            $err = "Grower and date are required for reversal.";
        } elseif (!$reason) {
            $err = "A reason is required to reverse a payment.";
        } else {
            $ps = $conn->query("SELECT id, status, net_amount
                FROM payment_status
                WHERE transporter_growersid=$tgid AND sell_date='$date' AND currency='$currency'
                LIMIT 1");
            $ps = ($ps && $ps !== true) ? $ps->fetch_assoc() : null;
            if (!$ps) {
                $err = "No payment record found to reverse.";
            } elseif ($ps['status'] !== 'paid') {
                $err = "Payment status is '{$ps['status']}' — only 'paid' records can be reversed.";
            } else {
                $psid = (int)$ps['id'];
                $amt  = (float)$ps['net_amount'];
                $conn->query("UPDATE payment_status SET
                    status='pending', payment_method='', reference_number='',
                    paid_at=NULL, paid_by=NULL,
                    notes='Reversed by user $userid: $reason'
                    WHERE id=$psid");
                if ($conn->error) {
                    $err = "Reversal failed: " . $conn->error;
                } else {
                    $conn->query("INSERT INTO payment_log
                        (payment_statusid, userid, action, currency, amount, notes)
                        VALUES ($psid, $userid, 'reversed', '$currency', $amt, '$reason')");
                    $msg = "↩ Payment reversed — grower reset to Pending.";
                }
            }
        }
    }

    // ── Reverse entire payment batch ──────────────────────────────────────
    if ($action === 'reverse_batch') {
        $bid    = (int)($_POST['batch_id'] ?? 0);
        $reason = $conn->real_escape_string(trim($_POST['reason'] ?? ''));
        if (!$bid) {
            $err = "Batch ID is required.";
        } elseif (!$reason) {
            $err = "A reason is required to reverse a batch.";
        } else {
            $batch = $conn->query("SELECT id, sell_date, currency, payment_method, reversed_at
                FROM payment_batch WHERE id=$bid LIMIT 1");
            $batch = ($batch && $batch !== true) ? $batch->fetch_assoc() : null;
            if (!$batch) {
                $err = "Batch #$bid not found.";
            } elseif (!empty($batch['reversed_at'])) {
                $err = "Batch #$bid has already been reversed on " .
                       date('d M Y H:i', strtotime($batch['reversed_at'])) . ".";
            } else {
                $bdate    = $conn->real_escape_string($batch['sell_date']);
                $bcur     = $batch['currency'];
                $now      = date('Y-m-d H:i:s');
                $items_q  = $conn->query("SELECT transporter_growersid
                    FROM payment_batch_item WHERE payment_batchid=$bid");
                $reversed = 0; $skipped = 0;
                while ($item = $items_q->fetch_assoc()) {
                    $tgid = (int)$item['transporter_growersid'];
                    $ps   = $conn->query("SELECT id, net_amount FROM payment_status
                        WHERE transporter_growersid=$tgid AND sell_date='$bdate' AND currency='$bcur'
                        LIMIT 1");
                    $ps   = ($ps && $ps !== true) ? $ps->fetch_assoc() : null;
                    if ($ps) {
                        $psid = (int)$ps['id'];
                        $amt  = (float)$ps['net_amount'];
                        $conn->query("UPDATE payment_status SET
                            status='pending', payment_method='', reference_number='',
                            paid_at=NULL, paid_by=NULL,
                            notes='Batch #$bid reversed by user $userid: $reason'
                            WHERE id=$psid");
                        if (!$conn->error) {
                            $conn->query("INSERT INTO payment_log
                                (payment_statusid, userid, action, currency, amount, notes)
                                VALUES ($psid, $userid, 'batch_reversed', '$bcur', $amt,
                                        'Batch #$bid: $reason')");
                            $reversed++;
                        } else { $skipped++; }
                    } else { $skipped++; }
                }
                // Stamp the batch as reversed
                $conn->query("UPDATE payment_batch SET
                    reversed_at='$now', reversed_by=$userid, reversal_reason='$reason'
                    WHERE id=$bid");
                $msg = "↩ Batch #$bid reversed — $reversed grower(s) reset to Pending." .
                       ($skipped ? " ($skipped skipped — already pending or not found)" : "");
            }
        }
    }
}

// ── Filters ────────────────────────────────────────────────────────────────
$date_from    = $conn->real_escape_string(trim($_GET['date_from'] ?? ''));
$date_to      = $conn->real_escape_string(trim($_GET['date_to']   ?? ''));
$date_f       = $conn->real_escape_string(trim($_GET['date']      ?? '')); // legacy single date
$filter_status= $conn->real_escape_string(trim($_GET['status']    ?? ''));
$batch_id     = (int)($_GET['batch_id'] ?? 0);
if (!empty($_GET['msg'])) $msg = htmlspecialchars(urldecode($_GET['msg']));

// Build date condition
if ($date_from && $date_to) {
    $date_cond = "DATE(bb.created_at) BETWEEN '$date_from' AND '$date_to'";
    $ps_date_cond = "ps_usd.sell_date BETWEEN '$date_from' AND '$date_to'";
    $ps_zig_cond  = "ps_zig.sell_date BETWEEN '$date_from' AND '$date_to'";
} elseif ($date_from) {
    $date_cond = "DATE(bb.created_at) >= '$date_from'";
    $ps_date_cond = "ps_usd.sell_date >= '$date_from'";
    $ps_zig_cond  = "ps_zig.sell_date >= '$date_from'";
} elseif ($date_f) {
    $date_cond = "DATE(bb.created_at) = '$date_f'";
    $ps_date_cond = "ps_usd.sell_date = '$date_f'";
    $ps_zig_cond  = "ps_zig.sell_date = '$date_f'";
} else {
    $date_cond    = "1=1"; // all dates
    $ps_date_cond = "1=1";
    $ps_zig_cond  = "1=1";
}

// ── Payments Made report (CSV / PDF) ───────────────────────────────────────
// Reports actual payments from payment_status (the authoritative payment record)
// for the period currently filtered on screen, scoped to the active floor.
if (($_GET['report'] ?? '') === 'payments') {
    $pm_format = ($_GET['format'] ?? 'csv') === 'pdf' ? 'pdf' : 'csv';

    if     ($date_from && $date_to) { $pm_cond = "ps.sell_date BETWEEN '$date_from' AND '$date_to'";
                                      $pm_period = date('d M Y', strtotime($date_from)).' — '.date('d M Y', strtotime($date_to)); }
    elseif ($date_from)             { $pm_cond = "ps.sell_date >= '$date_from'";
                                      $pm_period = 'From '.date('d M Y', strtotime($date_from)); }
    elseif ($date_f)                { $pm_cond = "ps.sell_date = '$date_f'";
                                      $pm_period = date('d M Y', strtotime($date_f)); }
    else                            { $pm_cond = "1=1"; $pm_period = 'All dates'; }

    // One line per sales sheet (batch/split) — payments are never collapsed together.
    // The per-sheet figure comes from payment_batch_item, which reconciles exactly to
    // the payment total; payments recorded outside a batch have no per-sheet split,
    // so that column is left blank rather than invented.
    $pm_q = $conn->query("
        SELECT ps.id AS psid, ps.sell_date, ps.currency, ps.net_amount AS payment_total,
               ps.status, ps.payment_method, ps.reference_number, ps.paid_at,
               COALESCE(ps.payment_ref,'')  AS run_ref,
               COALESCE(psh.payment_ref,'') AS payment_ref,
               psh.balanced_balesid, psh.sale_batchid, psh.splitid,
               pbi.net_amount AS sheet_amount,
               COALESCE(pb.id,0)        AS pay_batch_id,
               COALESCE(pb.batch_ref,'') AS pay_batch_ref,
               g.grower_num, TRIM(CONCAT(g.name,' ',g.surname)) AS grower_name, g.phone,
               COALESCE(u.username,'') AS paid_by_name,
               COALESCE(gb.bank_name,'') AS bank_name,
               COALESCE(gb.account_num,'') AS account_num
        FROM payment_status ps
        JOIN payment_sheets psh      ON psh.payment_statusid = ps.id
        JOIN transporter_growers tg  ON tg.id = ps.transporter_growersid
        JOIN growers g               ON g.id  = tg.growerid
        LEFT JOIN payment_batch_item pbi
               ON pbi.balanced_balesid = psh.balanced_balesid
              AND pbi.transporter_growersid = ps.transporter_growersid
              AND pbi.currency = ps.currency
        LEFT JOIN payment_batch pb   ON pb.id = pbi.payment_batchid
        LEFT JOIN users u            ON u.id  = ps.paid_by
        LEFT JOIN grower_banking gb  ON gb.id = (SELECT MIN(id) FROM grower_banking WHERE growerid = g.id)
        WHERE ($pm_cond)
          AND (g.selling_pointid=$spid OR tg.selling_pointid=$spid OR $spid=0)
          -- 'released' is how the pay_batches.php approval workflow marks a paid batch
          AND ps.status IN ('paid','partial','released')
        ORDER BY ps.paid_at DESC, ps.payment_ref ASC, psh.payment_ref ASC, psh.sale_batchid ASC
    ");
    $pm_rows = ($pm_q && $pm_q !== true) ? $pm_q->fetch_all(MYSQLI_ASSOC) : [];

    // Totals per currency + per payment method.
    // Each payment appears on several lines (one per sheet), so payment totals are
    // counted once per payment while batch amounts are summed per line.
    $pm_cur = []; $pm_meth = []; $pm_seen = []; $pm_seen_meth = [];
    foreach ($pm_rows as $pr) {
        $c     = strtoupper($pr['currency']);
        $sheet = ($pr['sheet_amount'] !== null) ? (float)$pr['sheet_amount'] : null;
        $m     = $pr['payment_method'] !== '' ? $pr['payment_method'] : 'Unspecified';
        $k     = $m.'|'.$c;

        if (!isset($pm_cur[$c]))
            $pm_cur[$c] = ['lines'=>0,'payments'=>0,'sheet_sum'=>0.0,'payment_sum'=>0.0,'unsplit'=>0];
        if (!isset($pm_meth[$k]))
            $pm_meth[$k] = ['method'=>$m,'cur'=>$c,'lines'=>0,'payments'=>0,'sheet_sum'=>0.0,'payment_sum'=>0.0];

        $pm_cur[$c]['lines']++;  $pm_meth[$k]['lines']++;
        if ($sheet !== null) { $pm_cur[$c]['sheet_sum'] += $sheet; $pm_meth[$k]['sheet_sum'] += $sheet; }
        else                 { $pm_cur[$c]['unsplit']++; }

        if (!isset($pm_seen[$pr['psid']])) {
            $pm_seen[$pr['psid']] = true;
            $pm_cur[$c]['payments']++;
            $pm_cur[$c]['payment_sum'] += (float)$pr['payment_total'];
        }
        if (!isset($pm_seen_meth[$k][$pr['psid']])) {
            $pm_seen_meth[$k][$pr['psid']] = true;
            $pm_meth[$k]['payments']++;
            $pm_meth[$k]['payment_sum'] += (float)$pr['payment_total'];
        }
    }
    ksort($pm_meth);

    $pm_floor = '';
    $fq = $conn->query("SELECT name FROM selling_point WHERE id=$spid LIMIT 1");
    if ($fq && $fq !== true && $fq->num_rows) $pm_floor = $fq->fetch_assoc()['name'];

    // ── CSV ────────────────────────────────────────────────────────────────
    if ($pm_format === 'csv') {
        // Quote every field and double embedded quotes so notes/names cannot break the file
        $q = function ($v) { return '"' . str_replace('"', '""', (string)$v) . '"'; };
        header('Content-Type: text/csv; charset=utf-8');
        $fn = 'payments_made_' . preg_replace('/[^A-Za-z0-9]/','_', $pm_period) . '.csv';
        header("Content-Disposition: attachment; filename=\"$fn\"");

        echo "Payments Made Report\r\n";
        echo $q('Floor').','.$q($pm_floor ?: '—')."\r\n";
        echo $q('Period (sale dates)').','.$q($pm_period)."\r\n";
        echo $q('Generated').','.$q(date('d M Y H:i'))."\r\n";
        echo $q('Detail').','.$q('One line per sales sheet (batch/split) — payments are not combined')."\r\n";
        echo $q('Sheet lines').','.count($pm_rows)."\r\n";
        echo $q('Payments').','.count($pm_seen)."\r\n\r\n";

        foreach ($pm_cur as $c => $t) {
            echo $q("Total paid $c").','.number_format($t['payment_sum'],2,'.','')
               . ','.$q($t['payments'].' payment(s) across '.$t['lines'].' sheet line(s)')."\r\n";
            echo $q("Sum of batch amounts $c").','.number_format($t['sheet_sum'],2,'.','')
               . ($t['unsplit'] ? ','.$q($t['unsplit'].' line(s) have no per-batch split recorded') : '')."\r\n";
        }
        echo "\r\n";

        // gross_amount / deductions / amount_paid are never populated by the payment
        // flow (only net_amount is), so they are deliberately not reported here.
        echo implode(',', array_map($q, [
            'Payment Ref','Payment Run','Sale Batch #','Split','Sales Sheet','Paid At','Sale Date',
            'Grower #','Grower Name','Phone','Currency','Batch Amount','Payment Run Total',
            'Status','Method','Bank/Txn Reference','Pay Batch #','Pay Batch Ref',
            'Bank','Account #','Paid By'
        ]))."\r\n";

        foreach ($pm_rows as $pr) {
            echo implode(',', [
                $q($pr['payment_ref']),
                $q($pr['run_ref']),
                $q($pr['sale_batchid']),
                $q($pr['splitid']),
                $q('Batch #'.$pr['sale_batchid'].'/Split '.$pr['splitid']),
                $q($pr['paid_at'] ? date('Y-m-d H:i', strtotime($pr['paid_at'])) : ''),
                $q($pr['sell_date']),
                $q($pr['grower_num']),
                $q($pr['grower_name']),
                $q($pr['phone']),
                $q(strtoupper($pr['currency'])),
                $pr['sheet_amount'] !== null ? number_format((float)$pr['sheet_amount'],2,'.','') : '',
                number_format((float)$pr['payment_total'],2,'.',''),
                $q($pr['status']),
                $q($pr['payment_method']),
                $q($pr['reference_number']),
                $q($pr['pay_batch_id'] ?: ''),
                $q($pr['pay_batch_ref']),
                $q($pr['bank_name']),
                $q($pr['account_num']),
                $q($pr['paid_by_name']),
            ])."\r\n";
        }

        echo "\r\n".$q('Summary by method')."\r\n";
        echo implode(',', array_map($q, ['Method','Currency','Payments','Sheet lines','Sum of batch amounts','Payment total']))."\r\n";
        foreach ($pm_meth as $mm) {
            echo implode(',', [
                $q($mm['method']), $q($mm['cur']), $mm['payments'], $mm['lines'],
                number_format($mm['sheet_sum'],2,'.',''),
                number_format($mm['payment_sum'],2,'.',''),
            ])."\r\n";
        }
        exit;
    }

    // ── PDF (print-optimised HTML, same approach as the batch report) ──────
    $co_q = $conn->query("SELECT param_value FROM parameters WHERE param_key='company_name' LIMIT 1");
    $company = ($co_q && $co_q !== true && $co_q->num_rows) ? ($co_q->fetch_assoc()['param_value'] ?? 'Flowork') : 'Flowork';
    header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Payments Made — <?=htmlspecialchars($pm_period)?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;font-size:9px;color:#111;padding:12px}
.hdr{display:flex;justify-content:space-between;align-items:flex-start;
     margin-bottom:9px;padding-bottom:7px;border-bottom:2px solid #1a1208}
.hdr h1{font-size:15px;font-weight:700;color:#1a1208}
.hdr h2{font-size:8.5px;color:#666;margin-top:1px}
.tag{background:#c8952a;color:#fff;font-size:7px;font-weight:700;
     padding:2px 5px;border-radius:2px;text-transform:uppercase;letter-spacing:.1em}
.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:5px;
      background:#f5f0e8;padding:6px 9px;border-radius:3px;margin-bottom:9px}
.mi label{display:block;font-size:6.5px;font-weight:700;letter-spacing:.1em;
           text-transform:uppercase;color:#7a6e5f;margin-bottom:1px}
.mi span{font-size:10px;font-weight:700}
table{width:100%;border-collapse:collapse;margin-bottom:10px}
thead th{background:#1a1208;color:#e8b84b;font-size:7px;font-weight:700;
         letter-spacing:.08em;text-transform:uppercase;padding:3.5px 4px;text-align:left}
thead th.right{text-align:right}
tbody td{padding:3px 4px;border-bottom:1px solid #ede6d6;font-size:8px}
tbody tr:nth-child(even){background:#faf8f5}
tfoot td{background:#1a1208;color:#e8b84b;font-weight:700;padding:3.5px 4px;
         font-size:8px;border-top:1.5px solid #c8952a}
.right{text-align:right}
.badge{background:#fdf6e8;border:1px solid #e8c96a;padding:1px 3px;
       border-radius:2px;font-weight:700;font-size:7px}
h3{font-size:8.5px;text-transform:uppercase;letter-spacing:.1em;color:#7a6e5f;margin:10px 0 4px}
.sig{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;margin-top:14px}
.sig-box{border-top:1px solid #1a1208;padding-top:4px}
.sig-box label{font-size:7px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7a6e5f}
.empty{padding:22px;text-align:center;color:#7a6e5f;font-size:9px;
       border:1px dashed #d8d0c0;border-radius:3px}
@media print{body{padding:0}@page{margin:8mm 6mm;size:A4 landscape}}
</style></head><body>
<div class="hdr">
  <div><h1><?=htmlspecialchars($company)?></h1><h2>Payments Made Report</h2></div>
  <div style="text-align:right">
    <span class="tag">Payments</span>
    <div style="font-size:7.5px;color:#666;margin-top:3px">Printed: <?=date('d M Y H:i')?></div>
  </div>
</div>
<div class="meta">
  <div class="mi"><label>Floor</label><span><?=htmlspecialchars($pm_floor ?: '—')?></span></div>
  <div class="mi"><label>Period (sale dates)</label><span><?=htmlspecialchars($pm_period)?></span></div>
  <div class="mi"><label>Payments</label><span><?=count($pm_seen)?> <span style="font-size:7px;font-weight:400;color:#7a6e5f">· <?=count($pm_rows)?> batch lines</span></span></div>
  <div class="mi"><label>Total Paid</label><span><?php
      $bits=[]; foreach($pm_cur as $c=>$t) $bits[]=$c.' '.number_format($t['payment_sum'],2);
      echo $bits ? htmlspecialchars(implode('  ·  ',$bits)) : '—';
  ?></span></div>
</div>

<?php if (empty($pm_rows)): ?>
<div class="empty">No payments were made in this period.</div>
<?php else: ?>
<table>
<thead><tr>
  <th>#</th><th>Payment Ref</th><th>Sale Batch #</th><th>Split</th><th>Paid At</th><th>Sale Date</th>
  <th>Grower #</th><th>Grower Name</th><th>Method</th><th>Bank / Txn Ref</th><th>Cur</th>
  <th class="right">Batch Amount</th><th>Payment Run</th><th class="right">Run Total</th>
</tr></thead>
<tbody>
<?php
$seq = 0; $prev_ps = null;
foreach ($pm_rows as $pr):
    $newpay = ($prev_ps !== $pr['psid']);   // first line of a payment
    $prev_ps = $pr['psid'];
    if ($newpay) $seq++;
?>
<tr<?=$newpay?' style="border-top:1.5px solid #c8952a"':''?>>
  <td style="color:#888"><?=$newpay?$seq:''?></td>
  <td style="font-family:monospace;font-weight:700"><?=htmlspecialchars($pr['payment_ref'] ?: '—')?></td>
  <td><span class="badge">Batch #<?=(int)$pr['sale_batchid']?></span></td>
  <td><?=(int)$pr['splitid']?></td>
  <td style="white-space:nowrap"><?=$pr['paid_at'] ? date('d M Y H:i', strtotime($pr['paid_at'])) : '—'?></td>
  <td style="white-space:nowrap"><?=date('d M Y', strtotime($pr['sell_date']))?></td>
  <td><span class="badge"><?=htmlspecialchars($pr['grower_num'])?></span></td>
  <td><?=htmlspecialchars($pr['grower_name'])?>
      <?php if($pr['status']==='partial'):?><span class="badge" style="border-color:#e0a0a0">PARTIAL</span><?php endif;?></td>
  <td><?=htmlspecialchars($pr['payment_method'] ?: '—')?></td>
  <td style="font-family:monospace"><?=htmlspecialchars($pr['reference_number'] ?: '—')?></td>
  <td><?=strtoupper($pr['currency'])?></td>
  <td class="right"><strong><?=$pr['sheet_amount'] !== null ? number_format((float)$pr['sheet_amount'],2) : '—'?></strong></td>
  <td style="font-family:monospace;font-size:7px;color:#666"><?=htmlspecialchars($pr['run_ref'] ?: '—')?></td>
  <?php /* run total only on the run's first line, so the column cannot be double-counted */ ?>
  <td class="right"><?=$newpay?number_format((float)$pr['payment_total'],2):'<span style="color:#bbb">↳</span>'?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<?php foreach($pm_cur as $c=>$t): ?>
<tr>
  <td colspan="11">TOTAL <?=$c?> — <?=$t['lines']?> batch payment(s) in <?=$t['payments']?> run(s)<?=
      $t['unsplit'] ? ' · '.$t['unsplit'].' without a per-batch split' : '' ?></td>
  <td class="right"><?=number_format($t['sheet_sum'],2)?></td>
  <td></td>
  <td class="right"><?=$c?> <?=number_format($t['payment_sum'],2)?></td>
</tr>
<?php endforeach; ?>
</tfoot>
</table>

<h3>Summary by payment method</h3>
<table>
<thead><tr>
  <th>Method</th><th>Currency</th><th class="right">Payments</th><th class="right">Batch lines</th>
  <th class="right">Sum of batch amounts</th><th class="right">Payment total</th>
</tr></thead>
<tbody>
<?php foreach($pm_meth as $mm): ?>
<tr>
  <td><?=htmlspecialchars($mm['method'])?></td>
  <td><?=$mm['cur']?></td>
  <td class="right"><?=$mm['payments']?></td>
  <td class="right"><?=$mm['lines']?></td>
  <td class="right"><?=number_format($mm['sheet_sum'],2)?></td>
  <td class="right"><strong><?=number_format($mm['payment_sum'],2)?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<div class="sig">
  <div class="sig-box"><label>Prepared by (Accounts)</label></div>
  <div class="sig-box"><label>Checked by</label></div>
  <div class="sig-box"><label>Approved by</label></div>
</div>
<script>window.print();</script>
</body></html>
<?php
    exit;
}

// ── Exchange rate ──────────────────────────────────────────────────────────
$er = $conn->query("SELECT amount FROM exchange_rate ORDER BY id DESC LIMIT 1")->fetch_assoc();
$zig_rate = (float)($er['amount'] ?? 0);

// Status condition
$status_cond = '';
if ($filter_status === 'paid')     $status_cond = " AND status_usd='paid'";
elseif ($filter_status === 'pending') $status_cond = " AND status_usd='pending'";
elseif ($filter_status === 'on_hold') $status_cond = " AND status_usd='on_hold'";

// ── Load all growers with settled balances ─────────────────────────────────
// One row per balanced_bale (bb.id) so each delivery lot shows separately.
// payment_status JOINed by sell_date so paid/pending is correct for this date.
// grower_banking uses MIN(id) to avoid fan-out when a grower has multiple accounts.
$p_sql = "
    SELECT
        tg.id AS tgid, bb.id AS bbid,
        DATE(bb.created_at) AS bal_date,
        g.id AS growerid, g.grower_num, g.name, g.surname, g.phone, g.selling_pointid,
        COALESCE(bl.code,'') AS loc_code,
        COALESCE(dt.sold_bales,0)       AS bales_sold,
        COALESCE(dt.usd_value,0)        AS gross_usd,
        er.amount                       AS exchange_rate,
        dts.usd_split_value, dts.zim_split_value,
        COALESCE(dtn.usd_net_value,0)   AS stored_net_usd,
        COALESCE(dtn.zim_net_value,0)   AS stored_net_zig,
        
        -- Sum of USD charges excluding Bank Charge (statutoryid = 7)
        COALESCE((
            SELECT SUM(bbc.usd_value)
            FROM balanced_bales_charges bbc
            JOIN grower_charges gc ON gc.id = bbc.grower_chargeid
            WHERE bbc.balanced_balesid = bb.id AND gc.statutoryid != 7
        ), 0) AS charges_usd_no_bc,
        
        -- Sum of ZiG charges excluding Bank Charge
        COALESCE((
            SELECT SUM(bbc.zim_value)
            FROM balanced_bales_charges bbc
            JOIN grower_charges gc ON gc.id = bbc.grower_chargeid
            WHERE bbc.balanced_balesid = bb.id AND gc.statutoryid != 7
        ), 0) AS charges_zig_no_bc,
        
        -- Sum of TIMB internal stop orders
        COALESCE((
            SELECT SUM(isod.amount_deducted)
            FROM internal_stop_order_deducted_amounts isod
            WHERE isod.balanced_balesid = bb.id
        ), 0) AS internal_so,
        
        -- Sum of TIMB external stop orders
        COALESCE((
            SELECT SUM(sd.amount_deducted)
            FROM stoporder_deductions sd
            WHERE sd.balanced_balesid = bb.id
        ), 0) AS external_so,
        
        -- Sum of company internal stop orders
        COALESCE((
            SELECT SUM(amount)
            FROM internal_stoporders
            WHERE (transporter_growersid = tg.id OR (growerid = g.id AND (transporter_growersid IS NULL OR transporter_growersid = 0)))
              AND active IN (0,1)
        ), 0) AS company_so,
        
        COALESCE(ps_usd.status,'pending')      AS status_usd,
        COALESCE(ps_zig.status,'pending')      AS status_zig,
        COALESCE(ps_usd.payment_method,'')     AS method_usd,
        COALESCE(ps_usd.reference_number,'')   AS ref_usd,
        COALESCE(ps_usd.payment_ref,'')        AS runref_usd,
        COALESCE((SELECT psh.payment_ref FROM payment_sheets psh
                   WHERE psh.payment_statusid = ps_usd.id
                     AND psh.balanced_balesid = bb.id LIMIT 1),'') AS payref_usd,
        ps_usd.paid_at                         AS paid_at_usd,
        ps_zig.paid_at                         AS paid_at_zig,
        COALESCE(gb.bank_name,'')    AS bank_name,
        COALESCE(gb.account_num,'')  AS account_num,
        COALESCE(gb.account_name,'') AS account_name,
        COALESCE(gb.branch,'')       AS branch
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g              ON g.id  = tg.growerid
    JOIN transporter t          ON t.id  = tg.transporterid
    LEFT JOIN bale_locations bl ON bl.id = t.location_id
    LEFT JOIN daily_totals dt             ON dt.balanced_balesid  = bb.id
    LEFT JOIN exchange_rate er            ON er.id = dt.exchange_rateid
    LEFT JOIN daily_totals_split_amounts dts ON dts.balanced_balesid = bb.id
    ".flowork_net_join('dtn', 'bb.id')."
    LEFT JOIN payment_status ps_usd
           ON ps_usd.transporter_growersid = tg.id
          AND ps_usd.currency  = 'usd'
          AND ps_usd.sell_date = DATE(bb.created_at)
    LEFT JOIN payment_status ps_zig
           ON ps_zig.transporter_growersid = tg.id
          AND ps_zig.currency  = 'zig'
          AND ps_zig.sell_date = DATE(bb.created_at)
    LEFT JOIN grower_banking gb
           ON gb.id = (SELECT MIN(id) FROM grower_banking WHERE growerid = g.id)
    WHERE ($date_cond)
      AND (g.selling_pointid=$spid OR tg.selling_pointid=$spid OR $spid=0)
    GROUP BY bb.id, tg.id, g.id, t.id, bl.id, dt.id, dts.id, dtn.balanced_balesid, ps_usd.id, ps_zig.id, gb.id, er.id
    ORDER BY bb.created_at DESC, g.grower_num ASC
";

// Apply status filter in PHP (avoids HAVING issues with aliases)
$p_q   = $conn->query($p_sql);
$p_all = [];
if ($p_q && $p_q !== true) {
    while ($r = $p_q->fetch_assoc()) {
        if ($filter_status && $r['status_usd'] !== $filter_status) continue;
        $p_all[] = $r;
    }
} else {
    error_log("Payments SQL error: " . $conn->error);
}

$rows = []; $totals = ['gross'=>0,'ded'=>0,'net'=>0,'zig'=>0,'paid'=>0,'pending'=>0];
foreach ($p_all as $r) {
    // Net payable = stored net for this delivery (single source, see includes/net_amount.php)
    $r['net_usd']        = (float)$r['stored_net_usd'];
    $r['net_zig']        = (float)$r['stored_net_zig'];
    $r['deductions_usd'] = (float)$r['gross_usd'] - $r['net_usd'];

    $r['net']     = $r['net_usd'];
    $r['gross']   = (float)$r['gross_usd'];
    $totals['gross']  += $r['gross'];
    $totals['ded']    += (float)$r['deductions_usd'];
    $totals['net']    += $r['net'];
    $totals['zig']    += $r['net_zig'];
    if ($r['status_usd'] === 'paid') $totals['paid']    += $r['net'];
    else                             $totals['pending']  += $r['net'];
    $rows[] = $r;
}

// ── Batch export (Excel/CSV/PDF) via GET ───────────────────────────────────
$export_type = $_GET['export'] ?? '';
$export_bid  = (int)($_GET['ebid'] ?? $batch_id);

if ($export_type && $export_bid) {
    // Load batch info
    $bi = $conn->query("SELECT pb.*, u.username FROM payment_batch pb JOIN users u ON u.id=pb.created_by WHERE pb.id=$export_bid LIMIT 1")->fetch_assoc();
    if (!$bi) { echo "Batch not found."; exit; }

    // Load batch items
    $items_q = $conn->query("
        SELECT pbi.*,
               g.grower_num, CONCAT(g.name,' ',g.surname) grower_name,
               g.phone, g.national_id,
               gb.bank_name, gb.account_num, gb.account_name, gb.branch, gb.swift_code
        FROM payment_batch_item pbi
        JOIN transporter_growers tg ON tg.id = pbi.transporter_growersid
        JOIN growers g ON g.id = tg.growerid
        LEFT JOIN grower_banking gb
               ON gb.id = (SELECT MIN(id) FROM grower_banking WHERE growerid = g.id)
        WHERE pbi.payment_batchid = $export_bid
        ORDER BY g.grower_num ASC
    ");
    $items = $items_q->fetch_all(MYSQLI_ASSOC);
    $batch_total = array_sum(array_column($items, 'net_amount'));
    $date_label  = date('d F Y', strtotime($bi['sell_date']));

    // ── CSV ──────────────────────────────────────────────────────────────
    if ($export_type === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        $fn = "payment_batch_{$export_bid}_{$bi['payment_method']}.csv";
        $fn = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $fn);
        header("Content-Disposition: attachment; filename=\"$fn\"");
        $cur = strtoupper($bi['currency']);
        echo "Payment Batch Report\r\n";
        echo "Batch #,$export_bid\r\n";
        echo "Date,\"$date_label\"\r\n";
        echo "Payment Method,\"{$bi['payment_method']}\"\r\n";
        echo "Batch Reference,\"{$bi['batch_ref']}\"\r\n";
        echo "Currency,$cur\r\n";
        echo "Growers,".count($items)."\r\n";
        echo "Total Amount $cur,".number_format($batch_total,4)."\r\n\r\n";
        echo "Grower #,Grower Name,Phone,Bank / Method,Account #,Account Name,Branch,Amount $cur\r\n";
        foreach ($items as $it) {
            echo implode(',', [
                '"'.$it['grower_num'].'"',
                '"'.$it['grower_name'].'"',
                '"'.($it['phone']??'').'"',
                '"'.($it['bank_name']??$bi['payment_method']).'"',
                '"'.($it['account_num']??'').'"',
                '"'.($it['account_name']??'').'"',
                '"'.($it['branch']??'').'"',
                number_format((float)$it['net_amount'],4),
            ])."\r\n";
        }
        echo "\r\nTOTAL,,,,,,,$".number_format($batch_total,4)."\r\n";
        exit;
    }

    // ── PDF ──────────────────────────────────────────────────────────────
    if ($export_type === 'pdf') {
        $co_q = $conn->query("SELECT param_value FROM parameters WHERE param_key='company_name' LIMIT 1");
        $company = $co_q ? ($co_q->fetch_assoc()['param_value'] ?? 'Flowork') : 'Flowork';
        $cur = strtoupper($bi['currency']);
        header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Payment Batch #<?=$export_bid?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;font-size:9px;color:#111;padding:12px}
.hdr{display:flex;justify-content:space-between;align-items:flex-start;
     margin-bottom:9px;padding-bottom:7px;border-bottom:2px solid #1a1208}
.hdr h1{font-size:15px;font-weight:700;color:#1a1208}
.hdr h2{font-size:8.5px;color:#666;margin-top:1px}
.tag{background:#c8952a;color:#fff;font-size:7px;font-weight:700;
     padding:2px 5px;border-radius:2px;text-transform:uppercase;letter-spacing:.1em}
.meta{display:grid;grid-template-columns:repeat(5,1fr);gap:5px;
      background:#f5f0e8;padding:6px 9px;border-radius:3px;margin-bottom:9px}
.mi label{display:block;font-size:6.5px;font-weight:700;letter-spacing:.1em;
           text-transform:uppercase;color:#7a6e5f;margin-bottom:1px}
.mi span{font-size:10px;font-weight:700}
table{width:100%;border-collapse:collapse}
thead th{background:#1a1208;color:#e8b84b;font-size:7px;font-weight:700;
         letter-spacing:.08em;text-transform:uppercase;padding:3.5px 4px;text-align:left}
thead th.right{text-align:right}
tbody td{padding:3px 4px;border-bottom:1px solid #ede6d6;font-size:8px}
tbody tr:nth-child(even){background:#faf8f5}
tfoot td{background:#1a1208;color:#e8b84b;font-weight:700;padding:3.5px 4px;
         font-size:8px;border-top:1.5px solid #c8952a}
.right{text-align:right}
.badge{background:#fdf6e8;border:1px solid #e8c96a;padding:1px 3px;
       border-radius:2px;font-weight:700;font-size:7px}
.sig{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;margin-top:14px}
.sig-box{border-top:1px solid #1a1208;padding-top:4px}
.sig-box label{font-size:7px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7a6e5f}
@media print{body{padding:0}@page{margin:8mm 6mm;size:A4 landscape}}
</style></head><body>
<div class="hdr">
  <div><h1><?=htmlspecialchars($company)?></h1><h2>Payment Batch Report</h2></div>
  <div style="text-align:right">
    <span class="tag">Batch #<?=$export_bid?></span>
    <div style="font-size:7.5px;color:#666;margin-top:3px">
      Printed: <?=date('d M Y H:i')?>
    </div>
  </div>
</div>
<div class="meta">
  <div class="mi"><label>Batch #</label><span><?=$export_bid?></span></div>
  <div class="mi"><label>Sale Date</label><span><?=$date_label?></span></div>
  <div class="mi"><label>Payment Method</label><span><?=htmlspecialchars($bi['payment_method'])?></span></div>
  <div class="mi"><label>Batch Ref</label><span><?=htmlspecialchars($bi['batch_ref']?:'—')?></span></div>
  <div class="mi"><label>Total <?=$cur?></label><span><?=$cur?> <?=number_format($batch_total,2)?></span></div>
</div>
<table>
<thead><tr>
  <th>#</th><th>Grower #</th><th>Grower Name</th><th>Phone</th>
  <th>Bank / Institution</th><th>Account #</th><th>Account Name</th>
  <th class="right">Amount (<?=$cur?>)</th>
</tr></thead>
<tbody>
<?php $seq=0; foreach($items as $it): $seq++; ?>
<tr>
  <td style="color:#888"><?=$seq?></td>
  <td><span class="badge"><?=htmlspecialchars($it['grower_num'])?></span></td>
  <td><?=htmlspecialchars($it['grower_name'])?></td>
  <td style="color:#666"><?=htmlspecialchars($it['phone']??'—')?></td>
  <td><?=htmlspecialchars($it['bank_name']??$bi['payment_method'])?></td>
  <td style="font-family:monospace"><?=htmlspecialchars($it['account_num']??'—')?></td>
  <td><?=htmlspecialchars($it['account_name']??'—')?></td>
  <td class="right"><strong><?=number_format((float)$it['net_amount'],4)?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr>
  <td colspan="7">TOTAL — <?=count($items)?> growers</td>
  <td class="right"><?=$cur?> <?=number_format($batch_total,4)?></td>
</tr></tfoot>
</table>
<div class="sig">
  <div class="sig-box"><label>Prepared by (Accounts)</label></div>
  <div class="sig-box"><label>Approved by</label></div>
  <div class="sig-box"><label>Bank / Platform confirmed</label></div>
</div>
<script>window.print();</script>
</body></html>
<?php     exit;
    }

    // ── Excel (.xlsx) ─────────────────────────────────────────────────────
    if ($export_type === 'xlsx') {
        $cur = strtoupper($bi['currency']);
        $py_data = json_encode([
            'batch_id' => $export_bid,
            'date'     => $bi['sell_date'],
            'method'   => $bi['payment_method'],
            'ref'      => $bi['batch_ref'],
            'currency' => $cur,
            'items'    => $items,
            'total'    => $batch_total,
        ]);
        $tmpjson = tempnam(sys_get_temp_dir(), 'pb_') . '.json';
        file_put_contents($tmpjson, $py_data);
        $outfile = sys_get_temp_dir() . "/payment_batch_{$export_bid}.xlsx";
        $py = <<<PY
import json, sys
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side, numbers
from openpyxl.utils import get_column_letter

with open('$tmpjson') as f: d = json.load(f)

wb = Workbook()
ws = wb.active
ws.title = "Payment Batch"

ink  = "1a1208"; gold = "c8952a"; gold_l = "fdf6e8"; cream = "f5f0e8"
hdr_font   = Font(name='Arial', bold=True, color="e8b84b", size=9)
hdr_fill   = PatternFill("solid", fgColor=ink)
title_font = Font(name='Arial', bold=True, size=14, color=ink)
sub_font   = Font(name='Arial', size=9, color="7a6e5f")
meta_fill  = PatternFill("solid", fgColor=cream)
total_fill = PatternFill("solid", fgColor=ink)
total_font = Font(name='Arial', bold=True, color="e8b84b", size=9)
bd = Border(bottom=Side(style='thin', color='c8952a'))
thin = Side(style='thin', color='dddddd')
row_border = Border(bottom=Side(style='thin', color='ede6d6'))

# Title
ws['A1'] = d['method'] + " Payment Batch Report"
ws['A1'].font = Font(name='Arial', bold=True, size=14, color=ink)
ws['A2'] = "Batch #" + str(d['batch_id']) + "  |  " + d['date'] + "  |  " + d['method'] + ("  |  Ref: " + d['ref'] if d['ref'] else "") + "  |  " + d['currency'] + " " + "{:,.2f}".format(d['total'])
ws['A2'].font = Font(name='Arial', size=9, color="7a6e5f")
ws['A3'] = ""

# Headers
cols = ["#","Grower #","Grower Name","Phone","Bank / Institution","Account #","Account Name","Branch","Amount (" + d['currency'] + ")"]
widths = [5, 11, 22, 14, 20, 16, 20, 14, 14]
for i, (h, w) in enumerate(zip(cols, widths), 1):
    c = ws.cell(row=4, column=i, value=h)
    c.font = hdr_font; c.fill = hdr_fill
    c.alignment = Alignment(horizontal='right' if i==len(cols) else 'left', vertical='center')
    ws.column_dimensions[get_column_letter(i)].width = w

ws.row_dimensions[4].height = 18

# Data rows
amt_fmt = '#,##0.0000'
for seq, it in enumerate(d['items'], 1):
    row = 4 + seq
    vals = [seq, it['grower_num'], it['grower_name'], it.get('phone',''),
            it.get('bank_name', d['method']), it.get('account_num',''),
            it.get('account_name',''), it.get('branch',''), float(it['net_amount'])]
    for col, v in enumerate(vals, 1):
        c = ws.cell(row=row, column=col, value=v)
        c.font = Font(name='Arial', size=9)
        c.border = row_border
        if col == len(vals):
            c.alignment = Alignment(horizontal='right')
            c.number_format = amt_fmt
        elif col == 1:
            c.font = Font(name='Arial', size=9, color="888888")
            c.alignment = Alignment(horizontal='center')
    # Alternate row fill
    if seq % 2 == 0:
        for col in range(1, len(vals)+1):
            ws.cell(row=row, column=col).fill = PatternFill("solid", fgColor="faf8f5")

# Total row
tr = 4 + len(d['items']) + 1
ws.cell(row=tr, column=1, value="TOTAL").font = total_font
ws.cell(row=tr, column=1).fill = total_fill
for col in range(2, 9):
    c = ws.cell(row=tr, column=col, value="")
    c.fill = total_fill
tc = ws.cell(row=tr, column=9, value=float(d['total']))
tc.font = total_font; tc.fill = total_fill
tc.number_format = amt_fmt
tc.alignment = Alignment(horizontal='right')

# Freeze header
ws.freeze_panes = 'A5'

wb.save('$outfile')
PY;
        file_put_contents('/tmp/gen_batch.py', $py);
        exec('python3 /tmp/gen_batch.py 2>&1', $out, $ret);
        unlink($tmpjson);
        if ($ret !== 0 || !file_exists($outfile)) {
            echo "Excel generation failed: " . implode("\n", $out); exit;
        }
        $fn = "payment_batch_{$export_bid}_" . preg_replace('/[^A-Za-z0-9]/', '_', $bi['payment_method']) . ".xlsx";
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"$fn\"");
        header('Content-Length: ' . filesize($outfile));
        readfile($outfile); unlink($outfile); exit;
    }
}

// ── Load existing batches for this date ────────────────────────────────────
$batches_q = $conn->query("
    SELECT pb.*, u.username,
           COUNT(pbi.id) AS grower_count,
           SUM(pbi.net_amount) AS batch_total
    FROM payment_batch pb
    JOIN users u ON u.id = pb.created_by
    LEFT JOIN payment_batch_item pbi ON pbi.payment_batchid = pb.id
    WHERE pb.sell_date = '$date_f' AND pb.created_by IN (
        SELECT id FROM users WHERE selling_pointid IS NULL OR 1=1
    )
    GROUP BY pb.id ORDER BY pb.id DESC
");
$batches = $batches_q ? $batches_q->fetch_all(MYSQLI_ASSOC) : [];

// Pre-load all batch items for expandable rows (single query for all batches)
$batch_items = [];
if (!empty($batches)) {
    $bid_list = implode(',', array_column($batches, 'id'));
    $bi_q = $conn->query("
        SELECT pbi.payment_batchid, pbi.id AS item_id,
               pbi.net_amount, pbi.currency,
               g.grower_num, g.name, g.surname,
               gb.bank_name, gb.account_num
        FROM payment_batch_item pbi
        JOIN transporter_growers tg ON tg.id = pbi.transporter_growersid
        JOIN growers g ON g.id = tg.growerid
        LEFT JOIN grower_banking gb
               ON gb.id = (SELECT MIN(id) FROM grower_banking WHERE growerid = g.id)
        WHERE pbi.payment_batchid IN ($bid_list)
        ORDER BY pbi.payment_batchid ASC, g.grower_num ASC, pbi.id ASC
    ");
    while ($bi_q && $bi_q !== true && $item = $bi_q->fetch_assoc()) {
        $batch_items[(int)$item['payment_batchid']][] = $item;
    }
}


include "includes/layout.php";
$status_labels = ['pending'=>'Pending','paid'=>'Paid','partial'=>'Partial','on_hold'=>'On Hold'];
$status_colors = ['pending'=>'badge-gray','paid'=>'badge-green','partial'=>'badge-gold','on_hold'=>'badge-red'];
?>
<style>
.payments-centered-container {
    max-width: 1000px;
    margin: 0 auto;
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Payments</h2>
    <p>
      <?php if($date_from && $date_to): ?>
        <?=date('d M Y',strtotime($date_from))?> — <?=date('d M Y',strtotime($date_to))?>
      <?php elseif($date_from): ?>
        From <?=date('d M Y',strtotime($date_from))?>
      <?php elseif($date_f): ?>
        <?=date('d M Y',strtotime($date_f))?>
      <?php else: ?>
        All Dates
      <?php endif; ?>
      &nbsp;·&nbsp; <?=count($rows)?> record(s)
    </p>
  </div>
  <div style="display:flex;gap:.45rem;flex-wrap:wrap">
    <?php
      // Carry the current date filter through to the report
      $pm_qs = http_build_query(array_filter([
          'report'    => 'payments',
          'date_from' => $date_from,
          'date_to'   => $date_to,
          'date'      => $date_f,
      ], function($v){ return $v !== '' && $v !== null; }));
    ?>
    <a href="?<?=$pm_qs?>&amp;format=csv" class="btn btn-ghost btn-sm"
       title="Download the payments made in this period as CSV">⬇ Payments CSV</a>
    <a href="?<?=$pm_qs?>&amp;format=pdf" target="_blank" rel="noopener" class="btn btn-ghost btn-sm"
       title="Open a printable payments report (use your browser's Save as PDF)">🖨 Payments PDF</a>
    <button class="btn btn-primary btn-sm" onclick="switchTab('batches','payments'); showBatchPanel()">+ Create Payment Batch</button>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav" data-tabs="payments">
  <button type="button" class="premium-tab-btn active" data-tab="register">
      📋 Payments Register
  </button>
  <button type="button" class="premium-tab-btn" data-tab="batches">
      📦 Payment Batches
      <?php if(!empty($batches)): ?>
      <span class="badge badge-gray" style="font-size:.62rem; margin-left:4px"><?=count($batches)?></span>
      <?php endif; ?>
  </button>
</div>

<!-- Tab Panel 1: Payments Register -->
<div id="panel-register" class="tab-panel active" data-tabs="payments">
  <!-- Filter bar -->
  <div class="card mb-3">
    <div class="card-body" style="padding:.65rem 1.15rem">
      <form method="GET" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
        <div class="form-group" style="margin:0">
          <label style="font-size:.62rem;display:block;margin-bottom:.2rem">From Date</label>
          <input type="date" name="date_from" class="form-control" value="<?=htmlspecialchars($date_from)?>"
                 style="padding:.35rem .6rem;font-size:.82rem">
        </div>
        <div class="form-group" style="margin:0">
          <label style="font-size:.62rem;display:block;margin-bottom:.2rem">To Date</label>
          <input type="date" name="date_to" class="form-control" value="<?=htmlspecialchars($date_to)?>"
                 style="padding:.35rem .6rem;font-size:.82rem">
        </div>
        <div class="form-group" style="margin:0">
          <label style="font-size:.62rem;display:block;margin-bottom:.2rem">Status</label>
          <select name="status" class="form-control" style="padding:.35rem .6rem;font-size:.82rem">
            <option value="">All Statuses</option>
            <option value="pending"  <?=$filter_status==='pending' ?'selected':''?>>Pending</option>
            <option value="paid"     <?=$filter_status==='paid'    ?'selected':''?>>Paid</option>
            <option value="on_hold"  <?=$filter_status==='on_hold' ?'selected':''?>>On Hold</option>
          </select>
        </div>
        <div style="display:flex;gap:.35rem;align-items:flex-end">
          <button type="submit" class="btn btn-primary btn-sm">Apply</button>
          <a href="payments.php" class="btn btn-ghost btn-sm">All</a>
          <a href="payments.php?date_from=<?=date('Y-m-d')?>&date_to=<?=date('Y-m-d')?>"
             class="btn btn-ghost btn-sm">Today</a>
          <a href="payments.php?status=pending" class="btn btn-ghost btn-sm"
             style="color:var(--gold)">Pending Only</a>
        </div>
      </form>
    </div>
  </div>

  <!-- KPI strip -->
  <div class="kpi-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.25rem">
    <div class="kpi-card" style="--kpi-color:var(--gold)"><div class="kpi-label">Gross USD</div><div class="kpi-value sm">$<?=number_format($totals['gross'],2)?></div></div>
    <div class="kpi-card" style="--kpi-color:var(--danger)"><div class="kpi-label">Deductions</div><div class="kpi-value sm">$<?=number_format($totals['ded'],2)?></div><div class="kpi-sub"><?=$totals['gross']>0?round($totals['ded']/$totals['gross']*100,1).'% of gross':'—'?></div></div>
    <div class="kpi-card" style="--kpi-color:var(--leaf)"><div class="kpi-label">Net Payable USD</div><div class="kpi-value sm">$<?=number_format($totals['net'],2)?></div><div class="kpi-sub"><?=count($rows)?> growers</div></div>
    <div class="kpi-card" style="--kpi-color:var(--leaf)"><div class="kpi-label">Paid USD</div><div class="kpi-value sm">$<?=number_format($totals['paid'],2)?></div></div>
    <div class="kpi-card" style="--kpi-color:var(--gold)"><div class="kpi-label">Pending USD</div><div class="kpi-value sm">$<?=number_format($totals['pending'],2)?></div></div>
  </div>

  <!-- ── PAYMENT REGISTER TABLE ─────────────────────────────────────────── -->
  <div class="card">
    <div class="card-head">
      <h3>Payment Register</h3>
      <span class="badge badge-gray"><?=count($rows)?> growers</span>
    </div>
    <div class="table-wrap"><table>
      <thead><tr>
        <th>Loc</th><th>Grower #</th><th>Name</th>
        <th class="text-right">Gross USD</th>
        <th class="text-right">Deductions</th>
        <th class="text-right">Net USD</th>
        <?php if($zig_rate>0):?><th class="text-right">Net ZiG</th><?php endif;?>
        <th>USD Status</th>
        <th>Payment Ref</th>
        <th>Method / Bank Ref</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php if(!empty($rows)): foreach($rows as $r):?>
        <tr data-tgid="<?=$r['tgid']?>">
          <td><?php if($r['loc_code']):?><span style="font-family:'Bebas Neue',sans-serif;font-size:1.05rem;color:var(--gold)"><?=htmlspecialchars($r['loc_code'])?></span><?php else:?><span class="td-muted">—</span><?php endif;?></td>
          <td><span class="badge badge-gold" style="font-size:.62rem"><?=htmlspecialchars($r['grower_num'])?></span></td>
          <td style="font-size:.82rem;font-weight:500"><?=htmlspecialchars($r['name'].' '.$r['surname'])?></td>
          <td class="td-mono text-right">$<?=number_format((float)$r['gross_usd'],2)?></td>
          <td class="td-mono text-right" style="color:var(--danger)">$<?=number_format($r['deductions_usd'],2)?></td>
          <td class="td-mono text-right fw-500">$<?=number_format((float)$r['net_usd'],2)?></td>
          <?php if($zig_rate>0):?><td class="td-mono text-right">ZiG <?=number_format((float)$r['net_zig'],2)?></td><?php endif;?>
          <td data-cell="status">
            <span class="badge <?=$status_colors[$r['status_usd']]?>" style="font-size:.62rem"><?=$status_labels[$r['status_usd']]?></span>
            <?php if($r['paid_at_usd']):?>
              <div style="font-size:.6rem;color:var(--muted)"><?=date('d/m H:i',strtotime($r['paid_at_usd']))?></div>
            <?php endif;?>
          </td>
          <td data-cell="payref" style="white-space:nowrap">
            <?php if(!empty($r['payref_usd'])):?>
              <div class="td-mono" style="font-size:.72rem;color:var(--gold);font-weight:700"><?=htmlspecialchars($r['payref_usd'])?></div>
              <?php if(!empty($r['runref_usd'])):?>
                <div class="td-mono" style="font-size:.6rem;color:var(--muted)">run <?=htmlspecialchars($r['runref_usd'])?></div>
              <?php endif;?>
            <?php else:?><span class="td-muted">—</span><?php endif;?>
          </td>
          <td data-cell="method" style="font-size:.72rem;color:var(--muted)">
            <?php if($r['method_usd']):?>
              <div><?=htmlspecialchars($r['method_usd'])?></div>
              <?php if($r['ref_usd']):?><div class="td-mono"><?=htmlspecialchars($r['ref_usd'])?></div><?php endif;?>
            <?php else:?>—<?php endif;?>
          </td>
          <td style="white-space:nowrap">
            <?php if($r['status_usd']!=='paid' && $r['net']>0):?>
              <button class="btn btn-primary btn-sm"
                      onclick="openPayModal(<?=$r['tgid']?>, '<?=addslashes($r['grower_num'])?>', '<?=addslashes($r['name'].' '.$r['surname'])?>', 'usd', <?=$r['net']?>, '<?=htmlspecialchars((string)$r['bal_date'])?>', '<?=addslashes($r['phone'] ?? '')?>')"
                      style="font-size:.72rem">✓ Pay</button>
            <?php else:?>
              <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-start">
                <span style="font-size:.68rem;color:var(--leaf);font-weight:500">✓ Paid</span>
                <button type="button" class="btn btn-ghost btn-sm"
                        onclick="openReverseModal('payment',<?=$r['tgid']?>,'<?=addslashes($r['grower_num'])?> — <?=addslashes($r['name'].' '.$r['surname'])?>','<?=htmlspecialchars($r['bal_date'])?>','usd')"
                        style="font-size:.6rem;color:var(--danger);padding:1px 5px;line-height:1.4">↩ Reverse</button>
              </div>
            <?php endif;?>
          </td>
        </tr>
      <?php endforeach; else:?>
        <tr><td colspan="<?=$zig_rate>0?11:10?>" class="text-center text-muted" style="padding:2rem">No settled balances on this date.</td></tr>
      <?php endif;?>
      </tbody>
    </table></div>
  </div>
</div>

<!-- Tab Panel 2: Payment Batches -->
<div id="panel-batches" class="tab-panel" data-tabs="payments">
  <!-- ── BATCH CREATION PANEL ────────────────────────────────────────────── -->
  <div id="batch-panel" style="display:none;margin-bottom:1.5rem">
    <div class="card" style="border-top:3px solid var(--gold)">
      <div class="card-head">
        <h3>Create Payment Batch</h3>
        <button class="btn btn-ghost btn-sm" onclick="hideBatchPanel()">✕</button>
      </div>
      <div class="card-body">
        <div class="grid-2" style="align-items:start;gap:1.25rem">
          <!-- Left: batch settings -->
          <div>
            <div class="form-group" style="margin-bottom:.75rem">
              <label>Sell Date <span style="color:var(--danger)">*</span>
                <span style="font-size:.68rem;color:var(--muted);font-weight:400"> — the sale date the growers delivered on</span>
              </label>
              <input type="date" id="batch-date" class="form-control"
                     value="<?=htmlspecialchars($date_f)?>"
                     style="max-width:220px"
                     onchange="refreshBatchGrowerList(this.value)">
            </div>
            <div class="form-row cols-2">
              <div class="form-group">
                <label>Payment Method <span style="color:var(--danger)">*</span></label>
                <select id="batch-method" class="form-control" onchange="updateBatchSummary()">
                  <option value="">— Select method —</option>
                  <?php foreach($PAYMENT_TYPES as $mt=>$mi):?>
                    <option value="<?=htmlspecialchars($mt)?>"><?=$mi['icon']?> <?=htmlspecialchars($mt)?></option>
                  <?php endforeach;?>
                </select>
              </div>
              <div class="form-group">
                <label>Currency</label>
                <select id="batch-currency" class="form-control" onchange="updateBatchSummary()">
                  <option value="usd">USD</option>
                  <?php if($zig_rate>0):?><option value="zig">ZiG</option><?php endif;?>
                </select>
              </div>
            </div>
            <div class="form-row cols-2">
              <div class="form-group">
                <label>Batch Reference <span style="font-size:.68rem;color:var(--muted)">(optional)</span></label>
                <input type="text" id="batch-ref" class="form-control" placeholder="e.g. TT-20240402-001" style="font-family:monospace">
              </div>
              <div class="form-group">
                <label>Notes</label>
                <input type="text" id="batch-notes" class="form-control" placeholder="Optional notes">
              </div>
            </div>
            <div style="background:var(--cream-d);border:1px solid var(--border);border-radius:var(--radius);padding:.75rem 1rem;font-size:.78rem;margin-bottom:.85rem">
              <div class="fw-500" style="margin-bottom:.4rem">Selected for this batch:</div>
              <div id="batch-summary" style="color:var(--muted)">Select growers from the table →</div>
            </div>
            <div id="batch-submit-area" style="display:none">
              <button type="button" id="batch-submit-btn" onclick="submitBatch()"
                      class="btn btn-primary" style="width:100%;padding:.7rem">
                ✓ Create Batch &amp; Mark as Paid
              </button>
            </div>
          </div>
          <!-- Right: grower selection -->
          <div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
              <div style="font-size:.62rem;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">
                Select growers for this batch
              </div>
              <div class="flex gap-2">
                <button type="button" class="btn btn-ghost btn-sm" onclick="selectAllPending()">Select all pending</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="clearSelection()">Clear</button>
              </div>
            </div>
            <div style="max-height:340px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius)">
              <table style="width:100%;border-collapse:collapse;font-size:.78rem">
                <thead><tr style="background:var(--ink);color:var(--cream);position:sticky;top:0;z-index:1">
                  <th style="padding:5px 8px;width:32px"><input type="checkbox" id="chk-all" onchange="toggleAll(this)"></th>
                  <th style="padding:5px 8px">Grower</th>
                  <th style="padding:5px 8px;text-align:right">Net USD</th>
                  <th style="padding:5px 8px">Bank / Account</th>
                  <th style="padding:5px 8px;text-align:center">Status</th>
                </tr></thead>
                <tbody id="batch-grower-list">
                <?php foreach($rows as $r):
                  $is_paid = $r['status_usd']==='paid';
                ?>
                  <tr class="batch-row" data-status="<?=$r['status_usd']?>"
                      style="border-bottom:1px solid var(--border);<?=$is_paid?'opacity:.45':''?>"
                      onclick="<?=$is_paid?'':'toggleRow(this)'?>">
                    <td style="padding:4px 8px">
                      <?php if(!$is_paid):?>
                        <input type="checkbox" class="batch-chk"
                               data-tgid="<?=$r['tgid']?>"
                               data-bbid="<?=$r['bbid']?>"
                               data-net="<?=number_format((float)$r['net'],4,'.','')?>"
                               data-net-zig="<?=number_format((float)$r['net_zig'],4,'.','')?>"
                               data-name="<?=htmlspecialchars($r['grower_num'].' '.$r['name'].' '.$r['surname'],ENT_QUOTES)?>"
                               onchange="updateBatchSummary()" onclick="event.stopPropagation()">
                      <?php else:?>
                        <span style="font-size:.7rem;color:var(--leaf)">✓</span>
                      <?php endif;?>
                    </td>
                    <td style="padding:4px 8px">
                      <span class="badge badge-gold" style="font-size:.6rem"><?=htmlspecialchars($r['grower_num'])?></span>
                      <span style="margin-left:4px;font-size:.78rem"><?=htmlspecialchars($r['name'].' '.$r['surname'])?></span>
                    </td>
                    <td class="td-mono text-right" style="padding:4px 8px">
                      $<?=number_format((float)$r['net'],2)?>
                    </td>
                    <td style="padding:4px 8px;font-size:.72rem;color:var(--muted)">
                      <?=htmlspecialchars($r['bank_name']?:'—')?>
                    </td>
                    <td style="padding:4px 8px;text-align:center">
                      <span class="badge <?=$status_colors[$r['status_usd']]?>" style="font-size:.58rem"><?=$status_labels[$r['status_usd']]?></span>
                    </td>
                  </tr>
                <?php endforeach;?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── EXISTING BATCHES ────────────────────────────────────────────────── -->
  <?php if(!empty($batches)): ?>
    <div class="card mb-3">
      <div class="card-head">
        <h3>Payment Batches — <?=date('d M Y',strtotime($date_f))?></h3>
        <div style="display:flex;align-items:center;gap:.5rem">
          <span class="badge badge-gray"><?=count($batches)?></span>
          <span style="font-size:.65rem;color:var(--muted)">Click a row to view payments</span>
        </div>
      </div>
      <div class="table-wrap"><table>
      <thead><tr>
        <th style="width:24px"></th>
        <th>Batch #</th><th>Method</th><th>Reference</th><th>Currency</th>
        <th class="text-right">Items</th><th class="text-right">Total</th>
        <th>Created by</th><th>When</th><th onclick="event.stopPropagation()"></th>
      </tr></thead>
      <tbody>
      <?php foreach($batches as $b):
        $b_items  = $batch_items[(int)$b['id']] ?? [];
        $b_total  = array_sum(array_column($b_items, 'net_amount'));
        $is_rev   = !empty($b['reversed_at']);
        $minfo    = $PAYMENT_TYPES[$b['payment_method']] ?? ['icon'=>'💳'];
      ?>
        <!-- ── Summary row (clickable) ── -->
        <tr class="batch-head-row"
            onclick="toggleBatchItems(<?=$b['id']?>)"
            style="cursor:pointer;<?=$batch_id==$b['id']?'background:var(--gold-bg)':''?><?=$is_rev?';opacity:.55':''?>"
            title="Click to <?=empty($b_items)?'(no items)':'expand / collapse payments'?>">
          <td style="padding:4px 8px;text-align:center">
            <span id="batch-ind-<?=$b['id']?>" style="font-size:.65rem;color:var(--muted);
                  transition:transform .15s;display:inline-block">▶</span>
          </td>
          <td><span class="badge badge-blue" style="font-size:.62rem">#<?=$b['id']?></span></td>
          <td>
            <span style="font-size:.85rem"><?=$minfo['icon']?></span>
            <span style="font-size:.82rem;margin-left:4px;font-weight:500"><?=htmlspecialchars($b['payment_method'])?></span>
          </td>
          <td class="td-mono" style="font-size:.75rem"><?=htmlspecialchars($b['batch_ref']?:'—')?></td>
          <td><span class="badge badge-gray" style="font-size:.62rem"><?=strtoupper($b['currency'])?></span></td>
          <td class="td-mono text-right"><?=count($b_items)?></td>
          <td class="td-mono text-right fw-500"><?=strtoupper($b['currency'])?> <?=number_format($b_total,2)?></td>
          <td class="td-muted" style="font-size:.72rem"><?=htmlspecialchars($b['username'])?></td>
          <td class="td-muted" style="font-size:.72rem"><?=date('d M H:i',strtotime($b['created_at']))?></td>
          <td style="white-space:nowrap" onclick="event.stopPropagation()">
            <div class="flex gap-1" style="flex-wrap:wrap">
              <a href="?date=<?=htmlspecialchars((string)$date_f)?>&ebid=<?=$b['id']?>&export=pdf" target="_blank"
                 class="btn btn-ghost btn-sm" style="font-size:.68rem">🖨 PDF</a>
              <a href="?date=<?=htmlspecialchars((string)$date_f)?>&ebid=<?=$b['id']?>&export=csv"
                 class="btn btn-ghost btn-sm" style="font-size:.68rem">⬇ CSV</a>
              <a href="?date=<?=htmlspecialchars((string)$date_f)?>&ebid=<?=$b['id']?>&export=xlsx"
                 class="btn btn-ghost btn-sm" style="font-size:.68rem">⬇ Excel</a>
              <?php if($is_rev): ?>
                <span style="font-size:.62rem;color:var(--muted);padding:2px 4px"
                      title="Reversed: <?=htmlspecialchars($b['reversal_reason'])?>">
                  ↩ Reversed <?=date('d/m/y',strtotime($b['reversed_at']))?>
                </span>
              <?php else: ?>
                <button type="button"
                        onclick="openReverseModal('batch',<?=$b['id']?>,'Batch #<?=$b['id']?> — <?=addslashes(htmlspecialchars($b['payment_method']))?> (<?=count($b_items)?> items)','<?=htmlspecialchars($b['sell_date'])?>','<?=$b['currency']?>')"
                        class="btn btn-ghost btn-sm" style="font-size:.68rem;color:var(--danger)">↩ Reverse</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>

        <!-- ── Expandable items row ── -->
        <tr id="batch-expand-<?=$b['id']?>" style="display:none">
          <td colspan="10" style="padding:0;background:var(--cream-d);border-bottom:2px solid var(--gold)">
            <div style="padding:.6rem 1.1rem .8rem 2.5rem">
              <!-- items sub-header -->
              <div style="display:flex;justify-content:space-between;align-items:center;
                          margin-bottom:.45rem">
                <div style="font-size:.62rem;font-weight:600;letter-spacing:.1em;
                            text-transform:uppercase;color:var(--muted)">
                  Batch #<?=$b['id']?> — <?=htmlspecialchars($b['payment_method'])?>
                  <?php if($b['batch_ref']):?>
                    &nbsp;·&nbsp; Ref: <span style="font-family:monospace"><?=htmlspecialchars($b['batch_ref'])?></span>
                  <?php endif;?>
                </div>
                <div style="font-size:.72rem;font-weight:600;color:var(--ink)">
                  <?=count($b_items)?> item(s) &nbsp;·&nbsp;
                  <?=strtoupper($b['currency'])?> <strong><?=number_format($b_total,4)?></strong>
                </div>
              </div>

              <?php if(empty($b_items)): ?>
                <div style="font-size:.75rem;color:var(--muted);padding:.5rem 0">No items found for this batch.</div>
              <?php else: ?>
                <div style="border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
                <table style="width:100%;border-collapse:collapse;font-size:.76rem">
                <thead>
                  <tr style="background:var(--ink);color:var(--cream)">
                    <th style="padding:4px 8px">#</th>
                    <th style="padding:4px 8px">Grower #</th>
                    <th style="padding:4px 8px">Name</th>
                    <th style="padding:4px 8px">Bank</th>
                    <th style="padding:4px 8px;font-family:monospace">Account #</th>
                    <th style="padding:4px 8px;text-align:right">Amount (<?=strtoupper($b['currency'])?>)</th>
                  </tr>
                </thead>
                <tbody>
                <?php $seq=0; foreach($b_items as $it): $seq++; ?>
                <tr style="border-bottom:1px solid var(--border);<?=$seq%2===0?'background:#faf8f5':''?>">
                  <td style="padding:3px 8px;color:var(--muted);font-size:.7rem"><?=$seq?></td>
                  <td style="padding:3px 8px">
                    <span class="badge badge-gold" style="font-size:.58rem"><?=htmlspecialchars($it['grower_num'])?></span>
                  </td>
                  <td style="padding:3px 8px;font-weight:500"><?=htmlspecialchars(trim($it['name'].' '.$it['surname']))?></td>
                  <td style="padding:3px 8px;font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($it['bank_name']??'—')?></td>
                  <td style="padding:3px 8px;font-family:monospace;font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($it['account_num']??'—')?></td>
                  <td style="padding:3px 8px;text-align:right;font-family:monospace;font-weight:600">
                    <?=number_format((float)$it['net_amount'],4)?>
                  </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr style="background:var(--ink)">
                    <td colspan="5" style="padding:4px 8px;color:var(--gold);font-size:.72rem;font-weight:600">
                      TOTAL — <?=$seq?> item(s)
                    </td>
                    <td style="padding:4px 8px;text-align:right;font-family:monospace;
                               color:var(--gold);font-weight:700;font-size:.78rem">
                      <?=strtoupper($b['currency'])?> <?=number_format($b_total,4)?>
                    </td>
                  </tr>
                </tfoot>
                </table>
                </div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      </table></div>
    </div>
  <?php else: ?>
    <div class="card mb-3">
      <div class="card-head"><h3>Payment Batches — <?=date('d M Y',strtotime($date_f))?></h3></div>
      <div class="card-body text-center text-muted" style="padding:2rem">No payment batches on this date.</div>
    </div>
  <?php endif;?>
</div><!-- /panel-batches -->

<!-- ── Individual payment modal ─────────────────────────────────────── -->
<div id="pay-modal" style="display:none;position:fixed;inset:0;z-index:400;
     background:rgba(0,0,0,0.5);align-items:center;justify-content:center">
<div style="background:var(--cream);border-radius:var(--radius);width:460px;max-width:95vw;
            box-shadow:0 12px 40px rgba(0,0,0,0.25);overflow:hidden">
  <div style="background:var(--ink);color:var(--cream);padding:1rem 1.25rem;
              display:flex;align-items:center;justify-content:space-between">
    <div>
      <div style="font-family:'Bebas Neue',sans-serif;font-size:1.1rem;letter-spacing:.08em">Confirm Payment</div>
      <div id="modal-grower-label" style="font-size:.72rem;color:rgba(245,240,232,.5);margin-top:2px"></div>
    </div>
    <button onclick="closePayModal()" style="background:none;border:none;color:rgba(245,240,232,.55);cursor:pointer;font-size:1.1rem;padding:4px">✕</button>
  </div>
  <div style="padding:.65rem 1.25rem;background:var(--gold-bg);border-bottom:1px solid rgba(200,149,42,.2);
              display:flex;justify-content:space-between;align-items:center">
    <div style="font-size:.72rem;color:var(--muted)">Net amount to pay</div>
    <div id="modal-amount-display" style="font-family:'Bebas Neue',sans-serif;font-size:1.6rem;color:var(--ink)"></div>
  </div>
  <form method="POST" id="pay-form" style="padding:1.15rem 1.25rem">
    <input type="hidden" name="action"     value="confirm_payment">
    <input type="hidden" name="tgid"       id="modal-tgid">
    <input type="hidden" name="sell_date"  id="modal-date">
    <input type="hidden" name="currency"   id="modal-currency">
    <input type="hidden" name="pay_amount" id="modal-pay-amount">
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Payment Method *</label>
        <select name="pay_method" id="modal-method" class="form-control" required>
          <option value="">— Select —</option>
          <?php foreach($PAYMENT_TYPES as $mt=>$mi):?>
          <option value="<?=htmlspecialchars($mt)?>"><?=$mi['icon']?> <?=htmlspecialchars($mt)?></option>
          <?php endforeach;?>
        </select>
      </div>
      <div class="form-group">
        <label>Reference / Receipt #</label>
        <input type="text" name="pay_ref" id="modal-ref" class="form-control" placeholder="Bank ref, or blank for auto receipt" style="font-family:monospace">
        <div id="modal-ref-hint" style="font-size:.66rem;color:var(--muted);margin-top:3px">Leave blank and a receipt number (RCT-…) is assigned automatically.</div>
      </div>
    </div>

    <div class="form-group">
      <label>Notes <span style="font-size:.68rem;color:var(--muted)">(optional)</span></label>
      <input type="text" name="pay_notes" class="form-control" placeholder="e.g. Grower collected in person">
    </div>
    <div style="background:#f5faf5;border:1px solid rgba(61,107,53,.2);border-radius:var(--radius);padding:.7rem;margin-bottom:.8rem;font-size:.78rem">
      <div class="fw-500" style="color:var(--leaf);margin-bottom:.4rem">✓ Confirm before submitting</div>
      <label style="display:flex;gap:.4rem;cursor:pointer;margin-bottom:.3rem">
        <input type="checkbox" id="chk1" required> Amount verified against sales sheet
      </label>
      <label style="display:flex;gap:.4rem;cursor:pointer">
        <input type="checkbox" id="chk2" required> Grower signed / receipt issued
      </label>
    </div>
    <div class="flex gap-2">
      <button type="submit" class="btn btn-primary" style="flex:1;padding:.7rem">✓ Confirm Payment</button>
      <button type="button" class="btn btn-ghost" onclick="closePayModal()">Cancel</button>
    </div>
  </form>
</div>
</div>

<!-- ── Reversal confirmation modal ────────────────────────────────────── -->
<div id="reverse-modal" style="display:none;position:fixed;inset:0;z-index:500;
     background:rgba(0,0,0,0.55);align-items:center;justify-content:center">
<div style="background:var(--cream);border-radius:var(--radius);width:460px;max-width:95vw;
            box-shadow:0 16px 48px rgba(0,0,0,0.3);overflow:hidden">
  <!-- Header -->
  <div style="background:#7a1e1e;color:#fff;padding:.9rem 1.25rem;
              display:flex;align-items:center;justify-content:space-between">
    <div>
      <div style="font-family:'Bebas Neue',sans-serif;font-size:1.15rem;letter-spacing:.08em">↩ Reverse Payment</div>
      <div id="rev-label" style="font-size:.72rem;color:rgba(255,255,255,.55);margin-top:2px"></div>
    </div>
    <button onclick="closeReverseModal()" style="background:none;border:none;color:rgba(255,255,255,.55);cursor:pointer;font-size:1.2rem;padding:4px 6px">✕</button>
  </div>
  <!-- Warning banner -->
  <div style="background:#fff5f5;border-bottom:1px solid rgba(122,30,30,.15);padding:.75rem 1.25rem;
              font-size:.8rem;color:#7a1e1e;display:flex;gap:.6rem;align-items:flex-start">
    <span style="font-size:1rem;flex-shrink:0">⚠</span>
    <span>This resets the payment status back to <strong>Pending</strong>.
    The grower will appear as unpaid and can be re-confirmed once figures are corrected.
    A full audit trail is preserved.</span>
  </div>
  <!-- Form -->
  <form method="POST" id="reverse-form" style="padding:1.1rem 1.25rem">
    <input type="hidden" name="action"    id="rev-action">
    <input type="hidden" name="tgid"      id="rev-tgid">
    <input type="hidden" name="sell_date" id="rev-date">
    <input type="hidden" name="currency"  id="rev-currency">
    <input type="hidden" name="batch_id"  id="rev-batch-id">
    <div class="form-group" style="margin-bottom:.9rem">
      <label style="font-size:.75rem;font-weight:600">
        Reason for reversal <span style="color:var(--danger)">*</span>
      </label>
      <input type="text" name="reason" id="rev-reason" class="form-control"
             placeholder="e.g. Incorrect amount, wrong account, data entry error"
             required style="margin-top:.3rem">
    </div>
    <div style="display:flex;gap:.5rem">
      <button type="submit" class="btn btn-sm"
              style="flex:1;padding:.7rem;background:#7a1e1e;color:#fff;border:none;
                     border-radius:var(--radius);cursor:pointer;font-weight:600;font-size:.82rem">
        ↩ Confirm Reversal
      </button>
      <button type="button" class="btn btn-ghost" onclick="closeReverseModal()"
              style="padding:.7rem 1.1rem">Cancel</button>
    </div>
  </form>
</div>
</div>

<!-- ── Hidden batch form (populated by submitBatch() before submit) ───── -->
<form method="POST" id="batch-form">
  <input type="hidden" name="action"       value="create_batch">
  <input type="hidden" name="sell_date"    id="bf-date">
  <input type="hidden" name="batch_method" id="bf-method">
  <input type="hidden" name="batch_ref"    id="bf-ref">
  <input type="hidden" name="batch_notes"  id="bf-notes">
  <input type="hidden" name="currency"     id="bf-currency">
  <div id="bf-tgids"></div><!-- batch_tgids[] injected here by submitBatch() -->
</form>

<script>
// ═══════════════════════════════════════════════════════════════════════
// BATCH EXPAND / COLLAPSE
// ═══════════════════════════════════════════════════════════════════════
function toggleBatchItems(bid) {
  var expand    = document.getElementById('batch-expand-' + bid);
  var indicator = document.getElementById('batch-ind-'    + bid);
  if (!expand) return;
  var opening = expand.style.display === 'none' || expand.style.display === '';
  expand.style.display = opening ? 'table-row' : 'none';
  if (indicator) indicator.textContent = opening ? '▼' : '▶';
}

// ═══════════════════════════════════════════════════════════════════════
// INDIVIDUAL PAYMENT MODAL & DIRECT PAYOUTS
// ═══════════════════════════════════════════════════════════════════════
var payModal = document.getElementById('pay-modal');

// Bind method change listener once
document.addEventListener('DOMContentLoaded', function() {
  var methodSelect = document.getElementById('modal-method');
  if (methodSelect) {
    methodSelect.addEventListener('change', function() {
      syncRefField(this.value);
    });
  }
});

// Cash always gets a system receipt number, so the field is locked for cash.
function syncRefField(method) {
  var ref = document.getElementById('modal-ref'), hint = document.getElementById('modal-ref-hint');
  if (!ref) return;
  if (method === 'Cash') {
    ref.value = ''; ref.readOnly = true; ref.placeholder = 'Assigned automatically';
    hint.textContent = 'Cash: a receipt number (RCT-…) is assigned when you confirm.';
  } else {
    ref.readOnly = false; ref.placeholder = 'Bank ref, or blank for auto receipt';
    hint.textContent = 'Leave blank and a receipt number (RCT-…) is assigned automatically.';
  }
}

function openPayModal(tgid, growerNum, growerName, currency, amount, date, phone) {
  var cur = currency.toUpperCase();
  var fmt = (cur === 'USD' ? '$' : cur + ' ') +
            parseFloat(amount).toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2});
  document.getElementById('modal-grower-label').textContent  = growerNum + ' — ' + growerName;
  document.getElementById('modal-amount-display').textContent = fmt;
  
  // Reset the form then restore hidden values (form.reset() clears hidden inputs too)
  document.getElementById('pay-form').reset();
  document.getElementById('modal-tgid').value       = tgid;
  document.getElementById('modal-date').value       = date;
  document.getElementById('modal-currency').value   = currency;
  document.getElementById('modal-pay-amount').value = amount;
  
  syncRefField('');

  payModal.style.display = 'flex';
  setTimeout(function(){ document.getElementById('modal-method').focus(); }, 60);
}

function closePayModal() { payModal.style.display = 'none'; }

payModal.addEventListener('click', function(e) { if (e.target === payModal) closePayModal(); });
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') { closePayModal(); closeReverseModal(); }
});

// ═══════════════════════════════════════════════════════════════════════
// REVERSAL MODAL
// ═══════════════════════════════════════════════════════════════════════
var revModal = document.getElementById('reverse-modal');

/**
 * type     : 'payment' | 'batch'
 * id       : tgid (payment) or batch_id (batch)
 * label    : human-readable description shown in the header
 * date     : sell_date (YYYY-MM-DD)
 * currency : 'usd' | 'zig'
 */
function openReverseModal(type, id, label, date, currency) {
  document.getElementById('rev-label').textContent    = label;
  document.getElementById('rev-reason').value         = '';
  document.getElementById('rev-action').value         = type === 'batch' ? 'reverse_batch' : 'reverse_payment';
  document.getElementById('rev-batch-id').value       = type === 'batch' ? id : '';
  document.getElementById('rev-tgid').value           = type === 'payment' ? id : '';
  document.getElementById('rev-date').value           = date     || '';
  document.getElementById('rev-currency').value       = currency || 'usd';
  revModal.style.display = 'flex';
  setTimeout(function(){ document.getElementById('rev-reason').focus(); }, 60);
}

function closeReverseModal() { revModal.style.display = 'none'; }
revModal.addEventListener('click', function(e) { if (e.target === revModal) closeReverseModal(); });

// ═══════════════════════════════════════════════════════════════════════
// BATCH PANEL
// ═══════════════════════════════════════════════════════════════════════
function showBatchPanel() {
  document.getElementById('batch-panel').style.display = 'block';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
function hideBatchPanel() {
  document.getElementById('batch-panel').style.display = 'none';
}

// Reload the page filtered to the chosen sell date so the grower list
// updates to show only growers who delivered on that date.
function refreshBatchGrowerList(dateVal) {
  if (!dateVal) return;
  var url = new URL(window.location.href);
  url.searchParams.set('date_from', dateVal);
  url.searchParams.set('date_to',   dateVal);
  // Preserve report/status params if present
  window.location.href = url.toString() + '#batch-panel';
}

// Row click toggles the checkbox in that row
function toggleRow(tr) {
  var chk = tr.querySelector('.batch-chk');
  if (chk) { chk.checked = !chk.checked; updateBatchSummary(); }
}

// Select-all header checkbox
function toggleAll(el) {
  document.querySelectorAll('.batch-chk').forEach(function(c) { c.checked = el.checked; });
  updateBatchSummary();
}

// Convenience buttons
function selectAllPending() {
  document.querySelectorAll('.batch-row[data-status="pending"] .batch-chk')
          .forEach(function(c) { c.checked = true; });
  document.getElementById('chk-all').checked = false; // indeterminate state
  updateBatchSummary();
}
function clearSelection() {
  document.querySelectorAll('.batch-chk').forEach(function(c) { c.checked = false; });
  document.getElementById('chk-all').checked = false;
  updateBatchSummary();
}

// Recalculate the summary box whenever selections change
function updateBatchSummary() {
  var chks    = document.querySelectorAll('.batch-chk:checked');
  var total   = 0;
  var names   = [];
  var cur        = document.getElementById('batch-currency').value;
  chks.forEach(function(c) {
    total += parseFloat(cur === 'zig' ? (c.dataset.netZig || 0) : (c.dataset.net || 0));
    names.push(c.dataset.name);
  });
  cur = cur.toUpperCase();
  var summary    = document.getElementById('batch-summary');
  var submitArea = document.getElementById('batch-submit-area');

  if (chks.length === 0) {
    summary.innerHTML    = '<span style="color:var(--muted)">Select growers from the table →</span>';
    submitArea.style.display = 'none';
  } else {
    var nameList = names.slice(0, 3).join(', ') + (names.length > 3 ? ' + ' + (names.length - 3) + ' more' : '');
    summary.innerHTML =
      '<strong>' + chks.length + ' grower(s)</strong>' +
      ' &nbsp;·&nbsp; ' +
      '<strong>' + cur + ' ' + total.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</strong>' +
      '<br><span style="font-size:.7rem;color:var(--muted)">' + nameList + '</span>';
    submitArea.style.display = 'block';
  }
}

// ─── Submit batch ────────────────────────────────────────────────────────────
function submitBatch() {
  var date     = document.getElementById('batch-date').value.trim();
  var method   = document.getElementById('batch-method').value.trim();
  var currency = document.getElementById('batch-currency').value;
  var ref      = document.getElementById('batch-ref').value.trim();
  var notes    = document.getElementById('batch-notes').value.trim();
  var chks     = document.querySelectorAll('.batch-chk:checked');

  // Validate
  if (!date)         { alert('Please select a sell date for this batch.'); document.getElementById('batch-date').focus(); return; }
  if (!method)       { alert('Please select a payment method.'); document.getElementById('batch-method').focus(); return; }
  if (chks.length === 0) { alert('Please select at least one grower to include in the batch.'); return; }

  // Confirm
  var total = 0;
  chks.forEach(function(c) { total += parseFloat(c.dataset.net || 0); });
  var cur = currency.toUpperCase();
  var confirm_msg =
    'Create payment batch?\n\n' +
    '  Date:    ' + date + '\n' +
    '  Method:  ' + method + '\n' +
    '  Ref:     ' + (ref || '—') + '\n' +
    '  Growers: ' + chks.length + '\n' +
    '  Total:   ' + cur + ' ' + total.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2}) + '\n\n' +
    'All selected growers will be marked as PAID.';
  if (!window.confirm(confirm_msg)) return;

  // Populate hidden form fields
  document.getElementById('bf-date').value     = date;
  document.getElementById('bf-method').value   = method;
  document.getElementById('bf-currency').value = currency;
  document.getElementById('bf-ref').value      = ref;
  document.getElementById('bf-notes').value    = notes;

  // Inject one hidden input set per selected bale, keyed by bbid (balanced_bales id).
  // Using bbid ensures each delivery lot gets its own correct amount even when
  // multiple bales share the same tgid (transporter_growers id).
  var tgidContainer = document.getElementById('bf-tgids');
  tgidContainer.innerHTML = '';
  chks.forEach(function(c) {
    var bbid = c.dataset.bbid;
    var tgid = c.dataset.tgid;

    // bale id
    var bbInp   = document.createElement('input');
    bbInp.type  = 'hidden';
    bbInp.name  = 'batch_bbids[]';
    bbInp.value = bbid;
    tgidContainer.appendChild(bbInp);

    // tgid keyed by bbid so PHP can look up the transporter_growers record
    var tgInp   = document.createElement('input');
    tgInp.type  = 'hidden';
    tgInp.name  = 'batch_tgids[' + bbid + ']';
    tgInp.value = tgid;
    tgidContainer.appendChild(tgInp);

    // per-bale net amount keyed by bbid — the exact figure shown in the register
    var netInp   = document.createElement('input');
    netInp.type  = 'hidden';
    netInp.name  = 'batch_nets[' + bbid + ']';
    netInp.value = currency === 'zig' ? (c.dataset.netZig || 0) : (c.dataset.net || 0);
    tgidContainer.appendChild(netInp);
  });

  // Show loading state on button
  var btn = document.getElementById('batch-submit-btn');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Saving…'; }

  document.getElementById('batch-form').submit();
}
</script>

<?php include "includes/layout_end.php"; ?>
