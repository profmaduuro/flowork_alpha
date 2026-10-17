<?php
mysqli_report(MYSQLI_REPORT_OFF);
ob_start(); // buffer all output so errors don't break JSON responses
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_once "includes/stoporder_deduct_helper.php";
require_login();

// Ensure internal_stoporders has creditor_no column
$_cc = $conn->query("SHOW COLUMNS FROM internal_stoporders LIKE 'creditor_no'");
if (!$_cc || $_cc->num_rows === 0) {
    $conn->query("ALTER TABLE internal_stoporders ADD COLUMN creditor_no VARCHAR(100) DEFAULT ''");
}
// Ensure deducted amounts table has correct columns
$conn->query("CREATE TABLE IF NOT EXISTS internal_stop_order_deducted_amounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    balanced_balesid INT NOT NULL,
    internal_stoporderid INT DEFAULT NULL,
    internal_stop_order_amountid INT DEFAULT NULL,
    amount_deducted DOUBLE NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(balanced_balesid)
)");
// Add columns if missing (migration)
foreach (['internal_stop_order_amountid INT DEFAULT NULL',
          'amount_deducted DOUBLE NOT NULL DEFAULT 0'] as $_mc) {
    $_mcn = explode(' ', $_mc)[0];
    $_chk = $conn->query("SHOW COLUMNS FROM internal_stop_order_deducted_amounts LIKE '$_mcn'");
    if (!$_chk || $_chk->num_rows === 0) {
        $conn->query("ALTER TABLE internal_stop_order_deducted_amounts ADD COLUMN $_mc");
    }
}

// Ensure stoporder_deductions table exists (links TIMB external stop orders to balanced batches)
$conn->query("CREATE TABLE IF NOT EXISTS stoporder_deductions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    balanced_balesid INT NOT NULL,
    stoporderid INT NOT NULL,
    amount_deducted DOUBLE NOT NULL DEFAULT 0,
    creditor_no VARCHAR(100) DEFAULT '',
    creditor_ref VARCHAR(100) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(balanced_balesid),
    INDEX(stoporderid),
    UNIQUE KEY uk_bb_so (balanced_balesid, stoporderid)
)");

$page_title  = "Balance & Settlement";
$active_page = "balance";

$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$today  = date('Y-m-d');

// ── AJAX: load grower bale panel ──────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'panel') {
    ob_end_clean(); header('Content-Type: application/json');
    $tgid    = (int)($_GET['tgid'] ?? 0);
    $splitid = (int)($_GET['split'] ?? 0);
    if (!$tgid) { echo json_encode(['error'=>'No tgid']); exit; }
    if (!flowork_on_floor($conn, 'tg', $tgid, $spid)) { echo json_encode(['error'=>'Delivery not found on this floor']); exit; }

    $pg = $conn->query("SELECT g.id growerid, g.grower_num, g.name, g.surname,
        tg.id tgid, tg.bales tg_bales, tg.junused_bales, tg.booked,
        COALESCE(tg.sell_date,'$today') sell_dt,
        tr.name transporter_name, tr.truck_num,
        COALESCE(bl.code,'') loc_code
        FROM transporter_growers tg
        JOIN growers g ON g.id=tg.growerid
        JOIN transporter tr ON tr.id=tg.transporterid
        LEFT JOIN bale_locations bl ON bl.id=tr.location_id
        WHERE tg.id=$tgid LIMIT 1");
    $pg = ($pg && $pg !== true) ? $pg->fetch_assoc() : null;
    if (!$pg) { echo json_encode(['error'=>'Grower not found']); exit; }
    $growerid = (int)$pg['growerid'];

    $splits_q = $conn->query("SELECT bj.splitid, COUNT(*) cnt,
        EXISTS(SELECT 1 FROM balanced_bales bb
               WHERE bb.transporter_growersid=$tgid AND bb.splitid=bj.splitid) balanced
        FROM bale_junus bj WHERE bj.transporter_growersid=$tgid AND bj.splitid>0
        GROUP BY bj.splitid ORDER BY bj.splitid");
    $splits_available = ($splits_q && $splits_q !== true) ? $splits_q->fetch_all(MYSQLI_ASSOC) : [];
    if (!$splitid && !empty($splits_available)) $splitid = (int)$splits_available[0]['splitid'];
    if (!$splitid) $splitid = 1;

    $_ab = $conn->query("SELECT id FROM balanced_bales
        WHERE transporter_growersid=$tgid AND splitid=$splitid LIMIT 1");
    $already_balanced = ($_ab && $_ab !== true && $_ab->num_rows) ? (int)$_ab->fetch_assoc()['id'] : 0;

    $sell_dt     = $pg['sell_dt'];
    $sell_dt_alt = date('d-M-y', strtotime($sell_dt));
    $is_booked   = (int)($pg['booked'] ?? 0) === 1;
    $bales_booked = 0;
    $_bb = $conn->query("SELECT id,bales_booked FROM booked_bales
        WHERE growerid=$growerid AND (sell_date='$sell_dt' OR sell_date='$sell_dt_alt')
        ORDER BY id DESC LIMIT 1");
    if (!$_bb || !$_bb->num_rows)
        $_bb = $conn->query("SELECT id,bales_booked FROM booked_bales WHERE growerid=$growerid ORDER BY id DESC LIMIT 1");
    if ($_bb && $_bb !== true && $_bb->num_rows) {
        $bbr = $_bb->fetch_assoc();
        $bales_booked = (int)$bbr['bales_booked'];
        if ($bales_booked > 0) $is_booked = true;
    }

    $split_where = $splitid > 0 ? "AND bj.splitid=$splitid" : "";
    $bale_q = $conn->query("
        SELECT bj.id bjid, bj.lot, bj.bale_group, bj.splitid, bm.mass,
               bm.temp_bacode timb_bc, tk.barcode, tk.id ticketid,
               sb.id sold_id, sb.price, sb.sale_code,
               sb.timb_gradesid, sb.buyer_gradesid,
               tg2.description timb_grade,
               bg.description buyer_grade, bg.id bgid,
               b.name buyer_name, b.id buyerid
        FROM bale_junus bj
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        LEFT JOIN timb_grades tg2 ON tg2.id=sb.timb_gradesid
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        WHERE bj.transporter_growersid=$tgid $split_where
        ORDER BY bj.lot ASC, bj.bale_group ASC");
    $bales = ($bale_q && $bale_q !== true) ? $bale_q->fetch_all(MYSQLI_ASSOC) : [];

    $total_bales = count($bales);
    $rej_codes   = ['R','CO','RO'];
    $sold_cnt    = 0; $rej_cnt = 0; $unsold = 0;
    foreach ($bales as $b) {
        if (!$b['sold_id']) { $unsold++; continue; }
        $sc = strtoupper(trim($b['sale_code'] ?? ''));
        if (in_array($sc, $rej_codes) || ($sc && $sc[0]==='R' && strlen($sc)<=3)) {
            $rej_cnt++;
        } else {
            $sold_cnt++;
        }
    }
    // Rejected bales are "dealt with" — don't block balancing
    $total_mass  = array_sum(array_column($bales, 'mass'));
    $gross = array_reduce($bales, function($c, $b) {
        if (!$b['sold_id']) return $c;
        $sc = strtoupper(trim($b['sale_code'] ?? ''));
        $rej = in_array($sc, ['R','CO','RO']) || ($sc && $sc[0]==='R' && strlen($sc)<=3);
        if ($rej) return $c; // rejected = no revenue
        $p = (float)$b['price']; if ($p > 10) $p /= 100;
        return $c + (float)$b['mass'] * $p;
    }, 0);

    $booking_warn = '';
    if (!$is_booked) $booking_warn = 'NOT_BOOKED';
    elseif ($bales_booked > 0 && $total_bales > $bales_booked)
        $booking_warn = "MISMATCH:$bales_booked:$total_bales";

    $tg_all = $conn->query("SELECT id, description FROM timb_grades WHERE active=1 ORDER BY description");
    $tg_all = ($tg_all && $tg_all !== true) ? $tg_all->fetch_all(MYSQLI_ASSOC) : [];
    $bg_all = $conn->query("SELECT bg.id, bg.description, b.name buyer_name
        FROM buyer_grades bg JOIN buyer b ON b.id=bg.buyerid
        WHERE bg.active=1 ORDER BY b.name, bg.description");
    $bg_all = ($bg_all && $bg_all !== true) ? $bg_all->fetch_all(MYSQLI_ASSOC) : [];

    // Check if stop orders already processed for this batch
    $so_processed = 0;
    if ($already_balanced) {
        $sp_q = $conn->query("SELECT stoporder_processed FROM balanced_bales WHERE id=$already_balanced LIMIT 1");
        $so_processed = ($sp_q && $sp_q !== true) ? (int)$sp_q->fetch_assoc()['stoporder_processed'] : 0;
    }

    echo json_encode([
        'ok'              => true,
        'grower'          => $pg,
        'splits'          => $splits_available,
        'current_split'   => $splitid,
        'already_balanced'=> $already_balanced,
        'so_processed'    => $so_processed,
        'booking_warn'    => $booking_warn,
        'bales_booked'    => $bales_booked,
        'bales'           => $bales,
        'stats'           => compact('total_bales','sold_cnt','rej_cnt','unsold','total_mass','gross'),
        'timb_grades'     => $tg_all,
        'buyer_grades'    => $bg_all,
    ]);
    exit;
}

// ── AJAX: update single bale ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_bale') {
    ob_end_clean(); header('Content-Type: application/json');
    $sbid = (int)($_POST['sold_id'] ?? 0);
    if (!$sbid) { echo json_encode(['error'=>'No ID']); exit; }
    if (!flowork_on_floor($conn, 'sold_bale', $sbid, $spid)) { echo json_encode(['error'=>'Bale not found on this floor']); exit; }
    $before = $conn->query("SELECT price, sale_code, timb_gradesid, buyer_gradesid FROM sold_bales WHERE id=$sbid")->fetch_assoc();
    $sets = [];
    if (!empty($_POST['timb_gradesid']))  $sets[] = "timb_gradesid=".(int)$_POST['timb_gradesid'];
    if (!empty($_POST['buyer_gradesid'])) $sets[] = "buyer_gradesid=".(int)$_POST['buyer_gradesid'];
    $price = (float)($_POST['price'] ?? 0);
    if (isset($_POST['sale_code']))        $sets[] = "sale_code='".$conn->real_escape_string(trim($_POST['sale_code'])). "'";
    if ($price > 0) $sets[] = "price=$price";
    if ($sets) {
        $conn->query("UPDATE sold_bales SET ".implode(',',$sets)." WHERE id=$sbid");
        // Price and grade decide what the grower is paid, so keep a record of every change.
        audit_log($conn, $spid, $userid, 'sold_bales', 'update', $sbid, $before,
            $conn->query("SELECT price, sale_code, timb_gradesid, buyer_gradesid FROM sold_bales WHERE id=$sbid")->fetch_assoc());
    }
    $row = $conn->query("SELECT sb.id, sb.price, sb.sale_code, sb.timb_gradesid, sb.buyer_gradesid,
        tg.description timb_grade, bg.description buyer_grade, b.name buyer_name
        FROM sold_bales sb
        LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        WHERE sb.id=$sbid LIMIT 1");
    $row = ($row && $row !== true) ? $row->fetch_assoc() : [];
    echo json_encode(['ok'=>true,'row'=>$row]);
    exit;
}

// ── AJAX: search growers ──────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    ob_end_clean(); header('Content-Type: application/json');
    $q   = $conn->real_escape_string(trim($_GET['q'] ?? ''));
    $where = $q && $q !== '*'
        ? "AND (g.grower_num LIKE '%$q%' OR g.name LIKE '%$q%' OR g.surname LIKE '%$q%' OR tr.name LIKE '%$q%')"
        : "";
    $rows = $conn->query("
        SELECT tg.id tgid, tg.bales, tg.junused_bales, tg.booked,
               g.grower_num, g.name, g.surname,
               tr.name transporter_name, tr.truck_num,
               COALESCE(bj.splitid, 1) splitid,
               COUNT(DISTINCT bj.id) junus_total,
               COUNT(DISTINCT sb.id) sold_count,
               COALESCE(SUM(bm.mass),0) total_mass
        FROM transporter_growers tg
        JOIN growers g ON g.id=tg.growerid
        JOIN transporter tr ON tr.id=tg.transporterid
        LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
        LEFT JOIN bale_mass bm ON bm.id=bj.bale_massid
        LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        WHERE (g.selling_pointid=$spid OR $spid=0)
          AND NOT EXISTS (
              SELECT 1 FROM balanced_bales _bb
              WHERE _bb.transporter_growersid = tg.id
                AND _bb.splitid = COALESCE(bj.splitid, 1)
          )
          AND tg.junused_bales > 0 $where
        GROUP BY tg.id, g.id, g.grower_num, g.name, g.surname,
                 tr.name, tr.truck_num, tg.bales, tg.junused_bales, tg.booked,
                 COALESCE(bj.splitid, 1)
        ORDER BY tg.booked DESC,
                 (COUNT(DISTINCT sb.id)=COUNT(DISTINCT bj.id) AND COUNT(DISTINCT bj.id)>0) DESC,
                 g.grower_num ASC
        LIMIT 100");
    $data = ($rows && $rows !== true) ? $rows->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['rows' => $data]);
    exit;
}



// ── BALANCE ACTION (AJAX POST) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'balance') {
    ob_end_clean(); header('Content-Type: application/json');
    $tgid     = (int)($_POST['tgid'] ?? 0);
    $splitid  = max(1, (int)($_POST['splitid'] ?? 1));
    $growerid = (int)($_POST['growerid'] ?? 0);
    if (!$tgid || !$growerid) { echo json_encode(['error'=>'Missing fields']); exit; }
    if (!flowork_on_floor($conn, 'tg', $tgid, $spid) || !flowork_on_floor($conn, 'grower', $growerid, $spid)) {
        echo json_encode(['error'=>'Delivery not found on this floor']); exit;
    }

    // Already balanced?
    $_ab = $conn->query("SELECT id FROM balanced_bales WHERE transporter_growersid=$tgid AND splitid=$splitid LIMIT 1");
    if ($_ab && $_ab !== true && $_ab->num_rows) { echo json_encode(['error'=>'Already balanced.']); exit; }

    // Booking check
    $_tgi = $conn->query("SELECT booked, COALESCE(sell_date,'$today') sell_dt FROM transporter_growers WHERE id=$tgid LIMIT 1");
    $tgi  = ($_tgi && $_tgi !== true) ? $_tgi->fetch_assoc() : null;
    $is_booked   = $tgi && (int)$tgi['booked'] === 1;
    $sell_dt     = $tgi['sell_dt'] ?? $today;
    $sell_dt_alt = date('d-M-y', strtotime($sell_dt));
    $_bb = $conn->query("SELECT bales_booked FROM booked_bales
        WHERE growerid=$growerid AND (sell_date='$sell_dt' OR sell_date='$sell_dt_alt')
        ORDER BY id DESC LIMIT 1");
    if (!$_bb || !$_bb->num_rows)
        $_bb = $conn->query("SELECT bales_booked FROM booked_bales WHERE growerid=$growerid ORDER BY id DESC LIMIT 1");
    if ($_bb && $_bb !== true && $_bb->num_rows) {
        if ((int)$_bb->fetch_assoc()['bales_booked'] > 0) $is_booked = true;
    }
    if (!$is_booked) { echo json_encode(['error'=>'Grower is not booked. Go to Grower Booking first.']); exit; }

    // Load bales to count unsold
    $res = $conn->query("
        SELECT bj.id, bm.mass, tk.start_of_dayid,
               sb.id sold_id, sb.price, COALESCE(sb.sale_code,'') sale_code,
               er.id exchange_rateid, er.amount exchange_rate_amount
        FROM bale_junus bj
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN tickets tk ON tk.bale_junusid=bj.id
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        LEFT JOIN exchange_rate er ON tk.start_of_dayid=er.start_of_dayid
        WHERE bj.transporter_growersid=$tgid AND bj.splitid=$splitid");
    $bales = ($res && $res !== true) ? $res->fetch_all(MYSQLI_ASSOC) : [];
    if (empty($bales)) { echo json_encode(['error'=>'No bales found for this split.']); exit; }

    $unsold = 0;
    foreach ($bales as $b) {
        if (!$b['sold_id']) $unsold++;
    }
    if ($unsold > 0) { echo json_encode(['error'=>"$unsold bale(s) not yet sold. Complete selling first."]); exit; }

    // Get gross and counts for response
    $gross=0; $sold_cnt=0;
    foreach ($bales as $b) {
        $is_rej = strtoupper(substr(trim($b['sale_code']),0,1))==='R' && strlen(trim($b['sale_code']))<=3;
        if (!$is_rej && $b['sold_id']) {
            $p = (float)$b['price']; if ($p > 10) $p /= 100;
            $gross    += (float)$b['mass'] * $p;
            $sold_cnt++;
        }
    }

    // Insert balanced_bales
    $conn->query("INSERT INTO balanced_bales
        (userid,splitid,growerid,transporter_growersid,created_at,datetime)
        VALUES ($userid,$splitid,$growerid,$tgid,'$today',NOW())");
    $bbid = (int)$conn->insert_id;
    if (!$bbid) { echo json_encode(['error'=>'Balance insert failed: '.$conn->error]); exit; }

    // Recalculate using unified pipeline
    $calc = flowork_recalculate_balance_batch($conn, $bbid, $spid, $userid);
    if (!$calc) {
        echo json_encode(['error'=>'Recalculation pipeline failed.']); exit;
    }

    // Batch & close delivery note
    $conn->query("CREATE TABLE IF NOT EXISTS sales_batching (
        id INT AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->query("INSERT INTO sales_batching (userid) VALUES ($userid)");
    $batch_id = (int)$conn->insert_id ?: 1;
    $conn->query("UPDATE balanced_bales SET stoporder_processed=0, sale_batchid=$batch_id WHERE id=$bbid");
    $conn->query("UPDATE transporter_growers SET open_close=1 WHERE id=$tgid");

    audit_log($conn,$spid,$userid,'balanced_bales','create',$bbid,null,
        ['tgid'=>$tgid,'gross'=>$gross,'net'=>$calc['net_usd'],'bales'=>count($bales)]);

    echo json_encode([
        'ok'     => true,
        'bbid'   => $bbid,
        'batch'  => $batch_id,
        'gross'  => round($gross,2),
        'net'    => round($calc['net_usd'],2),
        'net_zig'=> round($calc['net_zig'],2),
        'bales'  => count($bales),
        'msg'    => "Balanced — Gross: \$".number_format($gross,2)." · Net: \$".number_format($calc['net_usd'],2)." USD / $sold_cnt bales · Batch #$batch_id",
    ]);
    exit;
}

// ── REVERSE ───────────────────────────────────────────────────────────────
/**
 * Reverse (delete) balanced deliveries matching $where_sql on this floor only.
 * A delivery whose payment is already paid or released is skipped: reverse
 * the payment first, otherwise the payment would point at nothing.
 * Returns the number reversed; $skipped receives the ids left alone.
 */
function do_reverse($conn, $where_sql, $spid = 0, $userid = 0, &$skipped = []) {
    $spid = (int)$spid;
    $bl = $conn->query("SELECT bb.id, bb.transporter_growersid, bb.growerid, DATE(bb.created_at) bal_date
        FROM balanced_bales bb JOIN growers g ON g.id = bb.growerid
        WHERE ($where_sql) AND (g.selling_pointid=$spid OR bb.selling_pointid=$spid)");
    $rev = 0;
    while ($bl && $b = $bl->fetch_assoc()) {
        $bid  = (int)$b['id'];
        $tgid = (int)$b['transporter_growersid'];
        $paid = $conn->query("SELECT 1 FROM payment_status WHERE transporter_growersid=$tgid
            AND sell_date='" . $conn->real_escape_string($b['bal_date']) . "' AND status IN ('paid','released') LIMIT 1");
        if ($paid && $paid !== true && $paid->num_rows > 0) { $skipped[] = $bid; continue; }
        $conn->begin_transaction();
        $conn->query("DELETE FROM internal_stop_order_deducted_amounts WHERE balanced_balesid=$bid");
        $conn->query("DELETE FROM daily_totals_net_amount WHERE balanced_balesid=$bid");
        $conn->query("DELETE FROM balanced_bales_charges WHERE balanced_balesid=$bid");
        $conn->query("DELETE FROM daily_totals_split_amounts WHERE balanced_balesid=$bid");
        $conn->query("DELETE FROM daily_totals WHERE balanced_balesid=$bid");
        // Reset TIMB external stop orders that were processed against this batch
        $so_ids_q = $conn->query("SELECT stoporderid FROM stoporder_deductions WHERE balanced_balesid=$bid");
        if ($so_ids_q && $so_ids_q !== true) {
            while ($so_row = $so_ids_q->fetch_assoc()) {
                $conn->query("UPDATE stoporders SET processed=0 WHERE id=".(int)$so_row['stoporderid']);
            }
        }
        $conn->query("DELETE FROM stoporder_deductions WHERE balanced_balesid=$bid");
        if ($conn->query("DELETE FROM balanced_bales WHERE id=$bid")) {
            $rev++;
            // Re-open the delivery note so grower can be re-balanced
            if ($tgid) $conn->query("UPDATE transporter_growers SET open_close=0 WHERE id=$tgid");
            $conn->commit();
            audit_log($conn, $spid, (int)$userid, 'balanced_bales', 'reverse', $bid, ['tgid' => $tgid, 'growerid' => (int)$b['growerid']], null);
        } else {
            $conn->rollback();
        }
    }
    return $rev;
}

// ── AJAX: lookup batch before reversing ───────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'reverse_lookup') {
    ob_end_clean(); header('Content-Type: application/json');
    $by  = $_GET['by']  ?? 'batch';
    $val = $conn->real_escape_string(trim($_GET['val'] ?? ''));
    if (!$val) { echo json_encode(['error'=>'No value']); exit; }

    if ($by === 'batch') {
        $q = $conn->query("
            SELECT bb.id, bb.sale_batchid, bb.splitid,
                   g.grower_num, g.name, g.surname,
                   COALESCE(dt.usd_value,0) gross,
                   COALESCE(dt.sold_bales,0) bales,
                   COALESCE(bb.created_at, '') created_at
            FROM balanced_bales bb
            JOIN growers g ON g.id=bb.growerid
            LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
            WHERE bb.sale_batchid=".(int)$val."
            ORDER BY bb.id ASC");
    } elseif ($by === 'grower') {
        $q = $conn->query("
            SELECT bb.id, bb.sale_batchid, bb.splitid,
                   g.grower_num, g.name, g.surname,
                   COALESCE(dt.usd_value,0) gross,
                   COALESCE(dt.sold_bales,0) bales,
                   COALESCE(bb.created_at,'') created_at
            FROM balanced_bales bb
            JOIN growers g ON g.id=bb.growerid
            LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
            WHERE g.grower_num='$val'
            ORDER BY bb.id DESC LIMIT 10");
    } elseif ($by === 'bbid') {
        $q = $conn->query("
            SELECT bb.id, bb.sale_batchid, bb.splitid,
                   g.grower_num, g.name, g.surname,
                   COALESCE(dt.usd_value,0) gross,
                   COALESCE(dt.sold_bales,0) bales,
                   COALESCE(bb.created_at,'') created_at
            FROM balanced_bales bb
            JOIN growers g ON g.id=bb.growerid
            LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
            WHERE bb.id=".(int)$val." LIMIT 1");
    } else {
        echo json_encode(['error'=>'Unknown lookup type']); exit;
    }
    $rows = ($q && $q !== true) ? $q->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['ok'=>true,'rows'=>$rows,'count'=>count($rows)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reverse') {
    ob_end_clean(); header('Content-Type: application/json');
    $by    = $_POST['by']    ?? 'batch';
    $val   = $conn->real_escape_string(trim($_POST['val'] ?? ''));
    $rev   = 0;
    $label = '';
    if (!$val) { echo json_encode(['error'=>'No value provided']); exit; }

    $skipped = [];
    if ($by === 'batch') {
        $batch = (int)$val;
        $rev   = do_reverse($conn, "bb.sale_batchid=$batch", $spid, $userid, $skipped);
        $label = "Batch #$batch";
    } elseif ($by === 'bbid') {
        $bbid = (int)$val;
        $rev  = do_reverse($conn, "bb.id=$bbid", $spid, $userid, $skipped);
        $label = "Record #$bbid";
    } elseif ($by === 'grower') {
        // val = grower_num, looked up on this floor only
        $gq = $conn->query("SELECT id FROM growers WHERE grower_num='$val' AND selling_pointid=$spid LIMIT 1");
        $gr = ($gq && $gq !== true) ? $gq->fetch_assoc() : null;
        if (!$gr) { echo json_encode(['error'=>"Grower '$val' not found on this floor"]); exit; }
        $gid = (int)$gr['id'];
        $rev = do_reverse($conn, "bb.growerid=$gid", $spid, $userid, $skipped);
        $label = "Grower $val";
    } elseif ($by === 'tgid') {
        $tgid = (int)$val;
        $rev  = do_reverse($conn, "bb.transporter_growersid=$tgid", $spid, $userid, $skipped);
        $label = "Delivery note #$tgid";
    }
    $msg = "Reversed $label — $rev record(s) removed.";
    if ($skipped) $msg .= " " . count($skipped) . " already paid and left as is (record " . implode(', ', $skipped) . "): reverse the payment first.";
    echo json_encode(['ok'=>true,'rev'=>$rev,'skipped'=>$skipped,'msg'=>$msg]);
    exit;
}



// ── RUN STOP ORDERS (single batch, AJAX) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_so_single') {
    ob_end_clean(); header('Content-Type: application/json');
    $bbid = (int)($_POST['bbid'] ?? 0);
    if (!$bbid) { echo json_encode(['error'=>'No batch ID']); exit; }
    if (!flowork_on_floor($conn, 'bb', $bbid, $spid)) { echo json_encode(['error'=>'Batch not found on this floor']); exit; }

    // Check if already processed
    $bb_q = $conn->query("SELECT stoporder_processed FROM balanced_bales WHERE id=$bbid LIMIT 1");
    $bb_r = ($bb_q && $bb_q !== true) ? $bb_q->fetch_assoc() : null;
    if (!$bb_r) { echo json_encode(['error'=>'Batch not found']); exit; }
    if ($bb_r['stoporder_processed']) { echo json_encode(['ok'=>true,'msg'=>'Already processed.']); exit; }

    // Run the unified recalculation
    $calc = flowork_recalculate_balance_batch($conn, $bbid, $spid, $userid);
    if (!$calc) {
        echo json_encode(['error'=>'Recalculation failed.']); exit;
    }

    // Mark batch processed
    $conn->query("UPDATE balanced_bales SET stoporder_processed=1 WHERE id=$bbid");
    audit_log($conn,$spid,$userid,'balanced_bales','run_so',$bbid,null,['deducted'=>$calc['timb_so_total']]);
    echo json_encode(['ok'=>true,'msg'=>"Stop orders processed. Deducted TIMB SO: \$".number_format($calc['timb_so_total'],2)." · Net: \$".number_format($calc['net_usd'],2)]);
    exit;
}

// ── RUN STOP ORDERS ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_so') {
    // Process TIMB stop orders for all balanced batches where stoporder_processed=0
    $pending = $conn->query("
        SELECT bb.id AS bbid
        FROM balanced_bales bb
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        WHERE bb.stoporder_processed=0
          AND (g.selling_pointid=$spid OR $spid=0)
    ");

    $processed = 0;
    if ($pending && $pending !== true) {
        while ($row = $pending->fetch_assoc()) {
            $bbid = (int)$row['bbid'];
            $calc = flowork_recalculate_balance_batch($conn, $bbid, $spid, $userid);
            if ($calc) {
                $conn->query("UPDATE balanced_bales SET stoporder_processed=1 WHERE id=$bbid");
                $processed++;
            }
        }
    }

    $msg = "Stop orders processed for $processed batch(es).";
    if (!$processed) $msg = "No pending batches found.";
    header("Location: balance.php?msg=" . urlencode($msg));
    exit;
}

// ── PAGE DATA ──────────────────────────────────────────────────────────────
$msg = $msg ?? '';
$msg = $msg ?: htmlspecialchars($_GET['msg'] ?? '');

// ── Simple robust query — no optional table JOINs that may fail ─────────
$rows_q = $conn->query("
    SELECT
        tg.id                               AS tgid,
        tg.booked,
        COALESCE(tg.sell_date,'$today')      AS sell_dt,
        g.id                                AS growerid,
        g.grower_num,
        g.name                              AS first_name,
        g.surname,
        COALESCE(g.province,'')             AS province,
        COALESCE(bl.code,'')                AS loc_code,
        COALESCE(bl.name,'')                AS loc_name,
        tr.name                             AS transporter_name,
        tr.truck_num,
        COALESCE(sp.floor_code,'VL')        AS floor_code,
        COALESCE(bj.splitid, 1)             AS splitid,
        COUNT(DISTINCT bj.id)               AS junus_total,
        COUNT(DISTINCT sb.id)               AS sold_count,
        COUNT(DISTINCT CASE WHEN UPPER(SUBSTR(TRIM(COALESCE(sb.sale_code,'')),1,1))='R'
            AND LENGTH(TRIM(COALESCE(sb.sale_code,'')))<=3 THEN sb.id END) AS rej_count,
        COALESCE(SUM(bm.mass),0)            AS total_mass,
        bb.id                               AS bbid,
        bb.sale_batchid                     AS batch_num,
        COALESCE(bb.printed,0)              AS printed,
        COALESCE(bb.stoporder_processed,0)  AS so_processed,
        COALESCE(dt.usd_value,0)            AS gross_usd,
        COALESCE(dt.zim_value,0)            AS gross_zig,
        COALESCE(dtn.usd_net_value,0)       AS net_usd,
        COALESCE(dtn.zim_net_value,0)       AS net_zig,
        COALESCE(dt.average_price,0)        AS avg_price,
        COALESCE(dt.sold_bales,0)           AS dt_sold,
        COALESCE(dt.rejected_bales,0)       AS dt_rej,
        COALESCE(er.amount,0)               AS exch_rate,
        '' AS usd_pay_status, '' AS usd_pay_ref,
        '' AS zig_pay_status, '' AS zig_pay_ref,
        '' AS fca_bank,       '' AS fca_account,
        '' AS zw_bank,        '' AS zw_account,
        0  AS usd_deductions, 0  AS zim_deductions
    FROM transporter_growers tg
    JOIN growers g              ON g.id  = tg.growerid
    JOIN transporter tr         ON tr.id = tg.transporterid
    LEFT JOIN bale_locations bl ON bl.id = tr.location_id
    LEFT JOIN selling_point sp  ON sp.id = g.selling_pointid
    LEFT JOIN bale_junus bj     ON bj.transporter_growersid = tg.id
    LEFT JOIN bale_mass bm      ON bm.id = bj.bale_massid
    LEFT JOIN tickets tk        ON tk.bale_junusid = bj.id
    LEFT JOIN sold_bales sb     ON sb.ticketsid = tk.id
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid = tg.id
                                AND bb.splitid = COALESCE(bj.splitid, 1)
    LEFT JOIN daily_totals dt           ON dt.balanced_balesid = bb.id
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid = bb.id
    LEFT JOIN exchange_rate er          ON er.id = dt.exchange_rateid
    WHERE (g.selling_pointid = $spid OR $spid = 0)
    GROUP BY
        tg.id, g.id, g.grower_num, g.name, g.surname, g.province,
        bl.code, bl.name, tr.name, tr.truck_num, sp.floor_code,
        COALESCE(bj.splitid, 1),
        bb.id, bb.sale_batchid, bb.printed, bb.stoporder_processed,
        dt.usd_value, dt.zim_value, dtn.usd_net_value, dtn.zim_net_value,
        dt.average_price, dt.sold_bales, dt.rejected_bales, er.amount,
        tg.booked, tg.sell_date
    HAVING COUNT(DISTINCT bj.id) > 0 OR bb.id IS NOT NULL
    ORDER BY
        bb.id IS NULL DESC,
        (COUNT(DISTINCT sb.id) = COUNT(DISTINCT bj.id) AND COUNT(DISTINCT bj.id) > 0) DESC,
        g.grower_num ASC
    LIMIT 1000
");
$all_rows = ($rows_q && $rows_q !== true) ? $rows_q->fetch_all(MYSQLI_ASSOC) : [];
if (!$rows_q) error_log("balance.php query error: " . $conn->error);

// Summary KPIs
$total_growers  = count($all_rows);
$balanced_count = count(array_filter($all_rows, fn($r) => $r['bbid']));
$pending_count  = $total_growers - $balanced_count;
$total_gross    = array_sum(array_column($all_rows,'gross_usd'));
$total_net      = array_sum(array_column($all_rows,'net_usd'));
$total_mass_all = array_sum(array_column($all_rows,'total_mass'));
$total_bales    = array_sum(array_column($all_rows,'junus_total'));
$sold_bales_all = array_sum(array_column($all_rows,'sold_count'));
$avg_kg = ($total_mass_all > 0 && $total_gross > 0) ? $total_gross/$total_mass_all : 0;

$_pso = $conn->query("SELECT
        SUM(bb.stoporder_processed=0) pending_so,
        COUNT(*) total_bal
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    WHERE (g.selling_pointid=$spid OR $spid=0)");
$_pso_row   = ($_pso && $_pso !== true) ? $_pso->fetch_assoc() : ['pending_so'=>0,'total_bal'=>0];
$pending_so = (int)($_pso_row['pending_so'] ?? 0);
$total_bal  = (int)($_pso_row['total_bal']  ?? 0);

$_er_q = $conn->query("SELECT amount FROM exchange_rate ORDER BY id DESC LIMIT 1");
$_er_row = ($_er_q && $_er_q !== true) ? $_er_q->fetch_assoc() : null;   // none yet on a new install
$er_now = $_er_row ? (float)$_er_row['amount'] : 0;

// Short aliases used in HTML
$total   = $total_growers;
$bal_cnt = $balanced_count;
$pend    = $pending_count;
$t_gross = $total_gross;
$t_net   = $total_net;
$t_mass  = $total_mass_all;

include "includes/layout.php";
?>
<style>
.ksi{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.85rem}
.ksc{flex:1;min-width:105px;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:.55rem .8rem}
.ksl{font-size:.57rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.ksv{font-size:1.05rem;font-weight:700;line-height:1.2;margin-top:.1rem;color:var(--gold)}
.ksv.g{color:var(--leaf)}.ksv.w{color:var(--text-strong)}
.kss{font-size:.58rem;color:var(--muted);margin-top:.03rem}
.fbar{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;margin-bottom:.55rem}
/* Premium Segmented / Capsule Tab Strip */
.premium-tabs-nav {
    display: inline-flex;
    padding: 4px;
    background: var(--cream-d);
    border-radius: 8px;
    gap: 2px;
    border: 1px solid var(--border);
}
body.theme-dark .premium-tabs-nav {
    background: rgba(26,18,8,0.3);
}
.premium-tab-btn {
    padding: 0.4rem 1rem;
    background: transparent;
    border: none;
    border-radius: 6px;
    font-family: inherit;
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--muted);
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    user-select: none;
}
.premium-tab-btn:hover {
    color: var(--ink);
    background: rgba(26, 18, 8, 0.04);
}
body.theme-dark .premium-tab-btn:hover {
    color: var(--cream);
    background: rgba(255, 255, 255, 0.04);
}
.premium-tab-btn.active {
    background: var(--ink);
    color: var(--gold-l);
    box-shadow: 0 4px 12px rgba(26, 18, 8, 0.15);
    font-weight: 600;
}
body.theme-light .premium-tab-btn.active {
    background: var(--ink);
    color: var(--gold-l);
}
body.theme-dark .premium-tab-btn.active {
    background: var(--gold);
    color: var(--ink);
    box-shadow: 0 4px 12px rgba(200, 149, 42, 0.2);
}
.bwrap{overflow-x:auto;max-height:640px;overflow-y:auto}
#bmaster thead th{position:sticky;top:0;background:var(--ink);color:var(--cream);font-size:.63rem;white-space:nowrap;padding:.42rem .6rem;z-index:2;font-weight:500}
#bmaster tbody tr{cursor:pointer;transition:background .1s}
#bmaster tbody tr:hover td{background:rgba(200,149,42,.08)}
#bmaster tbody tr.rb td{background:rgba(60,140,60,.04)}
#bmaster tbody td{font-size:.7rem;padding:.4rem .6rem;white-space:nowrap}
#bmaster tfoot td{font-size:.7rem;padding:.45rem .6rem;white-space:nowrap;background:var(--cream-d);font-weight:700;border-top:2px solid var(--border);position:sticky;bottom:0}
.sp{display:inline-flex;align-items:center;gap:.2rem;padding:.16rem .5rem;border-radius:20px;font-size:.6rem;font-weight:600;white-space:nowrap}
.sp-b{background:rgba(60,150,60,.14);color:#2a8a2a;border:1px solid rgba(60,150,60,.3)}
.sp-r{background:rgba(200,149,42,.14);color:#9a6a0a;border:1px solid rgba(200,149,42,.3)}
.sp-p{background:rgba(210,100,30,.12);color:#a05020;border:1px solid rgba(210,100,30,.3)}
.sp-n{background:rgba(190,30,30,.1);color:#991010;border:1px solid rgba(190,30,30,.25)}
/* status pills sit on translucent tints — lighten the text on dark surfaces */
body.theme-dark .sp-b{color:#7ed67e}
body.theme-dark .sp-r{color:#e8b84b}
body.theme-dark .sp-p{color:#f0a06a}
body.theme-dark .sp-n{color:#f08a7d}
/* ── Balance Modal ── */
#bmo {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 9000;
  background: rgba(10,7,3,.72);
  backdrop-filter: blur(3px);
  overflow-y: auto;
  padding: 2.5rem 1rem;
  animation: bmo-in .18s ease;
}
@keyframes bmo-in{from{opacity:0}to{opacity:1}}
#bmb {
  background: #fff;
  border-radius: 14px;
  max-width: 1060px;
  margin: 0 auto;
  box-shadow: 0 32px 80px rgba(0,0,0,.45), 0 0 0 1px rgba(200,149,42,.15);
  overflow: hidden;
  animation: bmb-in .22s cubic-bezier(.16,1,.3,1);
}
@keyframes bmb-in{from{opacity:0;transform:translateY(18px) scale(.98)}to{opacity:1;transform:none}}

/* Modal header - dark ink band */
#bmb .bm-head {
  background: linear-gradient(135deg, #1a1208 0%, #2d2010 100%);
  padding: 1.1rem 1.4rem;
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  border-bottom: 2px solid rgba(200,149,42,.3);
}
#mgn {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.4rem;
  color: #c8952a;
  letter-spacing: .1em;
  line-height: 1;
}
#mgs {
  font-size: .72rem;
  color: rgba(245,240,232,.5);
  margin-top: .15rem;
}

/* Stat chips in header */
.ms { display:flex; flex-direction:column; align-items:center; background:rgba(255,255,255,.06); border:1px solid rgba(200,149,42,.2); border-radius:8px; padding:.35rem .7rem; min-width:60px; }
.msl { font-size:.52rem; color:rgba(245,240,232,.4); text-transform:uppercase; letter-spacing:.07em; }
.msv { font-size:.95rem; font-weight:700; color:#c8952a; line-height:1.2; }
.msv.g { color:#5dbf6a; }
.msv.r { color:#f07070; }
.msv.w { color:#f0ebe0; }

/* Edit hint bar */
#meh {
  background: linear-gradient(90deg, rgba(200,149,42,.08), rgba(200,149,42,.04));
  border-bottom: 1px solid rgba(200,149,42,.15);
  padding: .45rem 1.4rem;
  font-size: .7rem;
  color: #9a6a1a;
  display: flex;
  align-items: center;
  gap: .5rem;
}

/* Bale table */
#mbd { background: #fff; }
#mbd table { width:100%; border-collapse:collapse; }
#mbd thead th {
  background: #f8f5ef;
  color: #5a4820;
  font-size: .63rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .05em;
  padding: .5rem .75rem;
  border-bottom: 2px solid #e8dfc8;
  white-space: nowrap;
  position: sticky;
  top: 0;
  z-index: 2;
}
#mbd tbody tr { border-bottom: 1px solid #f0ebe0; transition: background .1s; }
#mbd tbody tr:last-child { border-bottom: none; }
#mbd tbody tr:hover td { background: #fdf6e8 !important; }
#mbd tbody td { font-size: .76rem; padding: .48rem .75rem; color: #2a1e08; white-space: nowrap; }
#mbd tfoot tr { background: #f8f5ef; border-top: 2px solid #e8dfc8; }
#mbd tfoot td { font-size: .76rem; padding: .5rem .75rem; font-weight: 700; color: #2a1e08; }

/* Editable cell */
.ec { cursor: pointer; position: relative; }
.ec:hover { background: rgba(200,149,42,.1) !important; }
.ep { display:inline-block; width:12px; height:12px; background:rgba(200,149,42,.2); border-radius:3px; margin-left:4px; font-size:.55rem; text-align:center; line-height:12px; color:#9a6a1a; vertical-align:middle; }

/* Modal footer */
#bm-foot {
  padding: .9rem 1.4rem;
  background: #f8f5ef;
  border-top: 2px solid #e8dfc8;
  display: flex;
  align-items: center;
  gap: .75rem;
  flex-wrap: wrap;
}
.btn-balance{background:linear-gradient(135deg,#2d6b25,#3d8a32);color:#fff;border:none;padding:.65rem 1.6rem;border-radius:8px;font-size:.9rem;font-weight:600;cursor:pointer;box-shadow:0 4px 12px rgba(45,107,37,.3);transition:transform .15s,box-shadow .15s;letter-spacing:.02em}
.btn-balance:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(45,107,37,.4)}
.btn-balance:disabled{opacity:.6;cursor:not-allowed;transform:none}
#bm-close-btn{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:rgba(245,240,232,.65);width:32px;height:32px;border-radius:8px;font-size:1.2rem;cursor:pointer;flex-shrink:0;transition:.15s;line-height:1}
#bm-close-btn:hover{background:rgba(255,255,255,.18);color:#fff}
@keyframes spin{to{transform:rotate(360deg)}}
</style>

<div class="page-header">
<div class="page-header-left">
<h2>Balance &amp; Settlement</h2>
<p><?= $total ?> grower slots &nbsp;&middot;&nbsp; <?= $bal_cnt ?> balanced &nbsp;&middot;&nbsp; <?= $pend ?> pending</p>
</div>
<div style="display:flex;gap:.4rem;flex-wrap:wrap">
<?php if(isset($total_bal) && $total_bal>0):?>
<form method="POST" onsubmit="return confirm('Apply TIMB stop order deductions to all pending balanced batches?')">
<input type="hidden" name="action" value="run_so">
<button class="btn <?=$pending_so>0?'btn-primary':'btn-ghost'?> btn-sm">
    &#9654; Run Stop Orders<?=$pending_so>0?" ($pending_so pending)":' (all processed)'?>
</button>
</form>
<?php endif;?>
<a href="grower_booking.php" class="btn btn-ghost btn-sm">Booking</a>
<a href="sales_sheets.php" class="btn btn-ghost btn-sm">Sales Sheets</a>
<button onclick="doExport()" class="btn btn-ghost btn-sm">Export CSV</button>
</div>
</div>

<?php if($msg):?><div class="alert alert-success" id="top-msg"><?=$msg?></div><?php endif;?>

<!-- Reversal Panel -->
<div class="card" style="margin-bottom:.85rem">
  <div class="card-head" style="cursor:pointer" id="rev-head">
    <h3 style="margin:0">&#8617; Reverse a Balanced Sale</h3>
    <span style="font-size:.72rem;color:var(--muted)" id="rev-toggle-lbl">&#9660; Click to expand</span>
  </div>
  <div id="revbody" style="display:none;padding:1.1rem 1.25rem">

    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:.85rem">
      <div>
        <label class="form-label" style="font-size:.68rem">Reverse by</label>
        <select id="rev-by" class="form-control" style="min-width:160px">
          <option value="batch">Batch Number</option>
          <option value="grower">Grower Number</option>
          <option value="bbid">Record ID (bbid)</option>
        </select>
      </div>
      <div style="flex:1;min-width:180px">
        <label class="form-label" style="font-size:.68rem" id="rev-lbl">Batch Number</label>
        <input type="text" id="rev-val" class="form-control" placeholder="e.g. 16" style="font-family:monospace;font-size:.9rem">
      </div>
      <button class="btn btn-secondary" onclick="revLookup()" style="white-space:nowrap">&#128269; Lookup</button>
    </div>

    <div id="rev-preview" style="display:none;border:1px solid #f0c8c8;border-radius:8px;overflow:hidden;margin-bottom:.75rem">
      <div style="background:#fff3f3;padding:.55rem 1rem;font-size:.72rem;font-weight:600;color:#8a2020;border-bottom:1px solid #f0c8c8" id="rev-preview-title"></div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse">
          <thead>
            <tr style="background:#f8f0f0">
              <th style="padding:.38rem .75rem;font-size:.62rem;text-align:left;color:#7a2020;font-weight:600">Batch #</th>
              <th style="padding:.38rem .75rem;font-size:.62rem;text-align:left;color:#7a2020;font-weight:600">Grower</th>
              <th style="padding:.38rem .75rem;font-size:.62rem;text-align:left;color:#7a2020;font-weight:600">Name</th>
              <th style="padding:.38rem .75rem;font-size:.62rem;text-align:right;color:#7a2020;font-weight:600">Bales</th>
              <th style="padding:.38rem .75rem;font-size:.62rem;text-align:right;color:#7a2020;font-weight:600">Gross USD</th>
              <th style="padding:.38rem .75rem;font-size:.62rem;text-align:left;color:#7a2020;font-weight:600">Date</th>
            </tr>
          </thead>
          <tbody id="rev-preview-body"></tbody>
        </table>
      </div>
      <div style="padding:.6rem 1rem;background:#fff8f8;border-top:1px solid #f0c8c8;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
        <span style="flex:1;font-size:.75rem;color:#8a2020" id="rev-confirm-msg"></span>
        <button class="btn btn-danger" onclick="doReverse()" id="rev-confirm-btn">&#8617; Confirm Reversal</button>
        <button class="btn btn-ghost btn-sm" onclick="revClear()">Cancel</button>
      </div>
    </div>

    <div id="rev-result" style="font-size:.78rem;min-height:1.2rem"></div>
    <div style="font-size:.65rem;color:var(--muted);margin-top:.4rem">&#9888; Reversal permanently removes balance records and reopens the delivery note for re-balancing.</div>
  </div>
</div>

<!-- KPIs -->
<div class="ksi">
<div class="ksc"><div class="ksl">Growers</div><div class="ksv w"><?=$total?></div><div class="kss"><?=$bal_cnt?> balanced &middot; <?=$pend?> pending</div></div>
<div class="ksc"><div class="ksl">Bales / Mass</div><div class="ksv w"><?=number_format(array_sum(array_column($all_rows,'junus_total')))?></div><div class="kss"><?=number_format($t_mass,0)?> kg</div></div>
<div class="ksc"><div class="ksl">Gross USD</div><div class="ksv">$<?=number_format($t_gross,2)?></div><div class="kss">balanced batches</div></div>
<div class="ksc"><div class="ksl">Net USD</div><div class="ksv g">$<?=number_format($t_net,2)?></div><div class="kss">after deductions</div></div>
<div class="ksc"><div class="ksl">Avg $/kg</div><div class="ksv"><?=$t_mass>0?'$'.number_format($t_gross/$t_mass,4):'--'?></div><div class="kss">floor average</div></div>
<div class="ksc"><div class="ksl">Rate ZiG/USD</div><div class="ksv w"><?=$er_now>0?number_format($er_now,2):'--'?></div><div class="kss">current rate</div></div>
</div>

<!-- ZiG/USD Split Preview Widget -->
<div id="zig-preview-widget" style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:.75rem 1.1rem;margin-bottom:.85rem;display:flex;gap:1.2rem;align-items:center;flex-wrap:wrap">
  <div style="font-size:.7rem;font-weight:600;color:var(--gold);letter-spacing:.05em;white-space:nowrap">⚡ ZiG/USD SPLIT PREVIEW</div>
  <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;flex:1">
    <div style="font-size:.68rem;color:var(--muted)">Gross USD:</div>
    <input id="zpw-gross" type="number" step="0.01" min="0" placeholder="Enter amount..." value="<?=number_format($t_gross,2,'.','')?>
" style="width:120px;padding:.28rem .55rem;border:1px solid var(--border);border-radius:6px;background:var(--cream-d);color:var(--text-strong);font-size:.78rem;font-family:monospace" oninput="calcSplit()">
    <div style="font-size:.68rem;color:var(--muted)">Rate:</div>
    <input id="zpw-rate" type="number" step="0.01" min="0" placeholder="ZiG rate" value="<?=number_format($er_now,2,'.','')?>
" style="width:80px;padding:.28rem .55rem;border:1px solid var(--border);border-radius:6px;background:var(--cream-d);color:var(--text-strong);font-size:.78rem;font-family:monospace" oninput="calcSplit()">
  </div>
  <div style="display:flex;gap:1rem;flex-wrap:wrap" id="zpw-result">
    <div style="text-align:center;min-width:90px">
      <div style="font-size:.55rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">70% USD</div>
      <div id="zpw-usd" style="font-size:1.05rem;font-weight:700;color:var(--leaf);font-family:monospace">$<?=number_format($t_gross*0.70,2)?></div>
    </div>
    <div style="text-align:center;min-width:90px">
      <div style="font-size:.55rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">30% ZiG</div>
      <div id="zpw-zig" style="font-size:1.05rem;font-weight:700;color:var(--gold);font-family:monospace"><?=number_format($t_gross*0.30*$er_now,2)?> ZiG</div>
    </div>
    <div style="text-align:center;min-width:90px">
      <div style="font-size:.55rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Net USD (est.)</div>
      <div id="zpw-net" style="font-size:1.05rem;font-weight:700;color:var(--text-strong);font-family:monospace">$<?=number_format($t_net,2)?></div>
    </div>
  </div>
  <div style="font-size:.6rem;color:var(--muted);white-space:nowrap">RBZ 70/30 split · Estimated only</div>
</div>
<script>
function calcSplit(){
  var g=parseFloat(document.getElementById('zpw-gross').value)||0;
  var r=parseFloat(document.getElementById('zpw-rate').value)||1;
  var usd=g*0.70, zig=g*0.30*r;
  document.getElementById('zpw-usd').textContent='$'+usd.toFixed(2);
  document.getElementById('zpw-zig').textContent=zig.toFixed(2)+' ZiG';
  document.getElementById('zpw-net').textContent='~$'+usd.toFixed(2);
}
calcSplit();
</script>

<!-- Filter bar -->
<div class="fbar">
  <input type="text" id="srch" class="form-control" placeholder="Search grower, transporter..." style="flex:1;min-width:150px;max-width:260px;font-size:.8rem" autocomplete="off">
  <div class="premium-tabs-nav">
    <button class="premium-tab-btn active" id="tab-all" onclick="setTab('all')">
      All <span class="badge badge-gray" id="c-all" style="font-size:.62rem; margin-left:4px"><?=$total?></span>
    </button>
    <button class="premium-tab-btn" id="tab-pending" onclick="setTab('pending')">
      Pending <span class="badge badge-gray" id="c-pending" style="font-size:.62rem; margin-left:4px"><?=$pend?></span>
    </button>
    <button class="premium-tab-btn" id="tab-ready" onclick="setTab('ready')">
      Ready <span class="badge badge-gold" id="c-ready" style="font-size:.62rem; margin-left:4px">0</span>
    </button>
    <button class="premium-tab-btn" id="tab-balanced" onclick="setTab('balanced')">
      Balanced <span class="badge badge-gray" id="c-balanced" style="font-size:.62rem; margin-left:4px"><?=$bal_cnt?></span>
    </button>
  </div>
  <span id="rinfo" style="font-size:.67rem;color:var(--muted)"></span>
</div>

<!-- Master table -->
<div class="card"><div class="bwrap"><table id="bmaster">
<thead><tr>
<th></th><th>Batch No.</th><th>Grower No.</th><th>First Name</th><th>Surname</th>
<th>Area</th><th>Province</th><th>Floor Code</th><th>Sale Date</th><th>Split</th>
<th>S/Status</th><th>GDN Status</th>
<th class="text-right">Paid Bales</th><th class="text-right">Paid Mass</th>
<th class="text-right">Rejected</th><th class="text-right">US Value</th>
<th class="text-right">USD Deductions</th><th class="text-right">USD Svc Chg</th>
<th class="text-right">USD Paid</th><th>US Pay</th><th>USD Ref</th>
<th class="text-right">RTGS Value</th><th class="text-right">ZW Deductions</th>
<th class="text-right">RTGS Svc Chg</th><th class="text-right">ZW Paid</th>
<th>ZW Pay</th><th>ZWG Ref</th>
<th>FCA Bank</th><th>FCA Account</th><th>ZW Bank</th><th>ZW Account</th>
<th class="text-right">Recon</th><th>Printed</th><th>Released</th><th>Cloud Pay</th><th></th>
</tr></thead>
<tbody>
<?php
$ready_cnt=0;
foreach($all_rows as $r):
    $isbal=!empty($r['bbid']);
    $asold=(int)$r['sold_count']===(int)$r['junus_total']&&(int)$r['junus_total']>0;
    $bkd=(int)($r['booked']??0)===1;
    $pct=$r['junus_total']>0?round($r['sold_count']/$r['junus_total']*100):0;
    $gdn=$isbal?'processed':($r['sold_count']>0?'selling':'laid');
    $sstat=$isbal?'Processed':'Draft';
    if($isbal){
        $tab='balanced';
        $so_proc = (int)($r['so_processed'] ?? 0);
        $dot  = $so_proc ? 'G' : 'A';
        $pill = $so_proc
            ? '<span class="sp sp-p">&#10003; Processed</span>'
            : '<span class="sp sp-b">Balanced</span>';
    }
    elseif($asold&&$bkd){$tab='ready';$ready_cnt++;$dot='Y';$pill='<span class="sp sp-r">Ready</span>';}
    elseif(!$bkd){$tab='pending';$dot='R';$pill='<span class="sp sp-n">Not Booked</span>';}
    else{$tab='pending';$dot='R';$pill='<span class="sp sp-p">'.$pct.'% Sold</span>';}
    $usp=$r['usd_pay_status']==='paid'?'Paid':($r['usd_pay_status']?ucfirst($r['usd_pay_status']):'0');
    $zwp=$r['zig_pay_status']==='paid'?'Paid':($r['zig_pay_status']?ucfirst($r['zig_pay_status']):'0');
    $rel=$r['printed']?$r['sell_dt']:'';
    $usd_svc=$isbal?number_format(max(0,(float)$r['gross_usd']-(float)$r['net_usd']-(float)$r['usd_deductions']),2):'0.0';
    $zig_svc=$isbal?number_format(max(0,(float)$r['gross_zig']-(float)$r['net_zig']-(float)$r['zim_deductions']),2):'0.0';
    $srch=strtolower(htmlspecialchars($r['grower_num'].' '.$r['first_name'].' '.$r['surname'].' '.$r['transporter_name'].' '.$r['loc_name'].' '.$r['province'],ENT_QUOTES));
?>
<tr class="<?=$isbal?'rb':''?>" data-tgid="<?=$r['tgid']?>" data-split="<?=$r['splitid']?>" data-tab="<?=$tab?>" data-s="<?=$srch?>" data-so="<?=$isbal?($r['so_processed']??0):0?>" onclick="openModal(<?=(int)$r['tgid']?>,<?=(int)$r['splitid']?>)">
<td style="text-align:center;font-size:.75rem;color:<?=$isbal?'#3a9a3a':($tab==='ready'?'#c8952a':'#c03030')?>"><?=$isbal?'&#10003;':($tab==='ready'?'~':'!')?></td>
<td><?=$r['batch_num']?'<span class="badge badge-blue" style="font-size:.6rem">'.(int)$r['batch_num'].'</span>':'<span style="color:var(--muted)">--</span>'?></td>
<td><span class="fw-500" style="color:var(--gold)"><?=htmlspecialchars($r['grower_num'])?></span></td>
<td><?=htmlspecialchars($r['first_name'])?></td>
<td><?=htmlspecialchars($r['surname'])?></td>
<td style="font-size:.67rem"><?=htmlspecialchars($r['loc_name']?:$r['loc_code'])?></td>
<td style="font-size:.67rem"><?=htmlspecialchars($r['province'])?></td>
<td><span class="badge badge-gray" style="font-size:.58rem"><?=htmlspecialchars($r['floor_code'])?></span></td>
<td><?=$r['sell_dt']?></td>
<td><span style="display:inline-block;min-width:24px;text-align:center;background:<?=$isbal?'#2d7a4f':'#c8952a'?>;color:#fff;border-radius:10px;font-size:.68rem;font-weight:700;padding:2px 8px"><?=(int)$r['splitid']?></span></td>
<td class="cell-status"><?=$pill?></td>
<td style="font-size:.67rem;color:var(--muted)"><?=$gdn?></td>
<td class="td-mono text-right fw-500"><?=$isbal?(int)$r['dt_sold']:(int)$r['sold_count']?></td>
<td class="td-mono text-right"><?=number_format((float)$r['total_mass'],0)?></td>
<td class="td-mono text-right"><?=$isbal?(int)$r['dt_rej']:(int)$r['rej_count']?></td>
<td class="td-mono text-right fw-500"><?=$isbal?'$'.number_format((float)$r['gross_usd'],2):'--'?></td>
<td class="td-mono text-right"><?=$isbal?number_format((float)$r['usd_deductions'],2):'0.0'?></td>
<td class="td-mono text-right"><?=$isbal?$usd_svc:'0.0'?></td>
<td class="td-mono text-right" style="color:var(--leaf);font-weight:600"><?=$isbal?number_format((float)$r['net_usd'],2):'0.0'?></td>
<td style="font-size:.67rem"><?=$usp?></td>
<td style="font-size:.63rem;color:var(--muted)"><?=htmlspecialchars($r['usd_pay_ref'])?></td>
<td class="td-mono text-right"><?=$isbal?number_format((float)$r['gross_zig'],2):'0.0'?></td>
<td class="td-mono text-right"><?=$isbal?number_format((float)$r['zim_deductions'],2):'0.0'?></td>
<td class="td-mono text-right"><?=$isbal?$zig_svc:'0.0'?></td>
<td class="td-mono text-right" style="color:var(--leaf);font-weight:600"><?=$isbal?number_format((float)$r['net_zig'],2):'0.0'?></td>
<td style="font-size:.67rem"><?=$zwp?></td>
<td style="font-size:.63rem;color:var(--muted)"><?=htmlspecialchars($r['zig_pay_ref'])?></td>
<td style="font-size:.65rem"><?=htmlspecialchars($r['fca_bank'])?></td>
<td style="font-size:.63rem;font-family:monospace"><?=htmlspecialchars($r['fca_account'])?></td>
<td style="font-size:.65rem"><?=htmlspecialchars($r['zw_bank'])?></td>
<td style="font-size:.63rem;font-family:monospace"><?=htmlspecialchars($r['zw_account'])?></td>
<td class="td-mono text-right"><?=$isbal?number_format((float)$r['net_usd']-(float)$r['gross_usd'],2):'0.0'?></td>
<td class="td-mono text-right"><?=$r['printed']?1:0?></td>
<td style="font-size:.67rem"><?=$rel?></td>
<td class="td-mono text-right">0</td>
<td onclick="event.stopPropagation()" style="text-align:center">
<?php if($isbal):?><a href="print_sheet.php?bbid=<?=$r['bbid']?>" target="_blank" class="btn btn-ghost btn-sm" style="font-size:.58rem;padding:2px 5px">Print</a><?php endif;?>
</td>
</tr>
<?php endforeach;?>
<?php if(empty($all_rows)):?><tr><td colspan="36" class="text-center text-muted" style="padding:2.5rem">No grower slots found.</td></tr><?php endif;?>
</tbody>
<tfoot><tr>
<td colspan="12"><strong id="tfl">TOTALS</strong></td>
<td class="td-mono text-right" id="tf1"><?=array_sum(array_column(array_filter($all_rows,fn($r)=>$r['bbid']),'dt_sold'))?></td>
<td class="td-mono text-right" id="tf2"><?=number_format($t_mass,0)?></td>
<td class="td-mono text-right" id="tf3">0</td>
<td class="td-mono text-right" id="tf4">$<?=number_format($t_gross,2)?></td>
<td class="td-mono text-right" id="tf5">0.0</td>
<td class="td-mono text-right" id="tf6">0.0</td>
<td class="td-mono text-right" id="tf7" style="color:var(--leaf)">$<?=number_format($t_net,2)?></td>
<td colspan="2"></td>
<td class="td-mono text-right" id="tf8"><?=number_format(array_sum(array_column($all_rows,'gross_zig')),2)?></td>
<td colspan="13"></td>
</tr></tfoot>
</table></div></div>

<!-- MODAL -->
<div id="bmo" onclick="if(event.target===this)closeModal()">
<div id="bmb">

  <!-- ── Header ── -->
  <div class="bm-head">
    <div style="flex:1;min-width:0">
      <div id="mgn">--</div>
      <div id="mgs">--</div>
    </div>
    <div id="mst" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center"></div>
    <div id="msp" style="display:flex;gap:.4rem;margin-left:.25rem"></div>
    <button onclick="closeModal()" id="bm-close-btn">&times;</button>
  </div>

  <!-- ── Edit hint ── -->
  <div id="meh" style="display:none">
    <span style="font-size:.8rem">&#9998;</span>
    Click <strong>TIMB Grade</strong>, <strong>Buyer / Grade</strong>, <strong>Sale Code</strong> or <strong>Price</strong> to edit inline &mdash; changes save automatically
  </div>

  <!-- ── Loading ── -->
  <div id="mld" style="text-align:center;padding:4rem 2rem;background:#fff">
    <div style="width:40px;height:40px;border:3px solid #e8dfc8;border-top-color:#c8952a;border-radius:50%;animation:spin .7s linear infinite;margin:0 auto"></div>
    <div style="color:#9a8060;margin-top:1rem;font-size:.85rem">Loading bales&hellip;</div>
  </div>

  <!-- ── Bale table ── -->
  <div id="mbd" style="display:none;max-height:48vh;overflow-y:auto;overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th style="text-align:center;width:36px">#</th>
          <th>Barcode</th>
          <th>Scale BC</th>
          <th>TIMB Grade</th>
          <th>Buyer / Grade</th>
          <th>Sale Code</th>
          <th style="text-align:right">Mass kg</th>
          <th style="text-align:right">$/kg</th>
          <th style="text-align:right">Value USD</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody id="mbb"></tbody>
      <tfoot>
        <tr>
          <td colspan="6" id="mfl"></td>
          <td id="mfm" style="text-align:right;font-family:monospace"></td>
          <td></td>
          <td id="mfv" style="text-align:right;font-family:monospace;color:#2a6a2a;font-size:.82rem"></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- ── Footer / action bar ── -->
  <div id="bm-foot">
    <div id="mac" style="flex:1;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap"></div>
  </div>

</div>
</div>


<script>
/* global state */
var TG=[],BG=[],AT=0,AS=0,AG=0;


function xe(s){
    return String(s||'')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}

/* ── Filter ── */
var aTab='all';
document.getElementById('srch').addEventListener('input',applyF);
function setTab(t){
    aTab=t;
    ['all','pending','ready','balanced'].forEach(function(x){
        document.getElementById('tab-'+x).classList.toggle('active',x===t);
    });
    applyF();
}
function applyF(){
    var q=document.getElementById('srch').value.trim().toLowerCase();
    var rows=document.querySelectorAll('#bmaster tbody tr'),vis=0;
    rows.forEach(function(r){
        var ok=(aTab==='all'||r.dataset.tab===aTab)&&(!q||(r.dataset.s||'').includes(q));
        r.style.display=ok?'':'none';
        if(ok)vis++;
    });
    document.getElementById('rinfo').textContent=vis+' row'+(vis===1?'':'s');
}
applyF();
(function(){
    document.getElementById('c-ready').textContent=
        document.querySelectorAll('#bmaster tbody tr[data-tab="ready"]').length;
})();

/* ── CSV Export ── */
function doExport(){
    var h=[],lines=[];
    document.querySelectorAll('#bmaster thead th').forEach(function(th){
        h.push('"'+th.textContent.trim().replace(/"/g,'""')+'"');
    });
    lines.push(h.join(','));
    document.querySelectorAll('#bmaster tbody tr').forEach(function(row){
        if(row.style.display==='none')return;
        var cc=[];
        row.querySelectorAll('td').forEach(function(td){
            cc.push('"'+td.textContent.trim().replace(/"/g,'""')+'"');
        });
        lines.push(cc.join(','));
    });
    var a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([lines.join('\n')],{type:'text/csv'}));
    a.download='Sales_Master.csv';
    a.click();
}

/* ── Open Modal ── */
var currentTgid=0;
var SPLITS=[];   // splits of the open delivery, each {splitid, cnt, balanced}
function openModal(tgid,split){
    currentTgid=tgid;
    // Force-clear ALL overlays that could block the modal
    var ov=document.getElementById('fw-overlay');
    if(ov){ov.classList.remove('fw-on');ov.style.pointerEvents='none';ov.style.opacity='0';ov.style.zIndex='-1';}
    // Also clear sidebar overlay if open
    var so=document.querySelector('.sidebar-overlay');
    if(so)so.style.display='none';
    // Ensure body scroll is not locked
    document.body.style.overflow='';

    AT=tgid; AS=split||0;
    document.getElementById('bmo').style.display='block';
    document.getElementById('mld').style.display='block';
    document.getElementById('mbd').style.display='none';
    document.getElementById('meh').style.display='none';
    document.getElementById('mst').innerHTML='';
    document.getElementById('msp').innerHTML='';
    document.getElementById('mac').innerHTML='';
    document.getElementById('mgn').textContent='Loading...';
    document.getElementById('mgs').textContent='';

    fetch('balance.php?ajax=panel&tgid='+tgid+'&split='+(split||0),
          {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){
        return r.text().then(function(txt){
            try { return JSON.parse(txt); }
            catch(e) { throw new Error('Server error: '+txt.substring(0,200)); }
        });
    })
    .then(function(d){
        document.getElementById('mld').style.display='none';
        if(d.error){
            document.getElementById('mac').innerHTML='<div class="alert alert-danger">'+xe(d.error)+'</div>';
            return;
        }
        TG=d.timb_grades||[];
        BG=d.buyer_grades||[];
        AG=parseInt(d.grower.growerid);
        renderModal(d);
    })
    .catch(function(err){
        document.getElementById('mld').style.display='none';
        document.getElementById('mac').innerHTML='<div class="alert alert-danger">Load failed: '+xe(err.message)+'</div>';
    });
}

function closeModal(){
    document.getElementById('bmo').style.display='none';
}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal();});

/* ── Render Modal ── */
function renderModal(d){
    var g=d.grower,s=d.stats,bales=d.bales,warn=d.booking_warn||'',bbId=d.already_balanced;

    document.getElementById('mgn').textContent=g.grower_num;
    document.getElementById('mgs').textContent=
        g.name+' '+g.surname+' - '+g.transporter_name+(g.loc_code?' - Loc '+g.loc_code:'');

    var bBadge=warn==='NOT_BOOKED'
        ?'<span class="sp sp-n">Not Booked</span>'
        :warn.startsWith('MISMATCH')
            ?'<span class="sp sp-n">Mismatch '+warn.split(':')[1]+'/'+warn.split(':')[2]+'</span>'
            :'<span class="sp sp-b">Booked ('+d.bales_booked+')</span>';

    var bale_lbl = s.sold_cnt+' sold';
    if(s.rej_cnt>0) bale_lbl += ', '+s.rej_cnt+' rej';
    if(s.unsold>0)  bale_lbl += ', '+s.unsold+' pending';
    var bale_color = s.unsold>0?'r':(s.rej_cnt>0?'w':'g');
    document.getElementById('mst').innerHTML=
        '<div class="ms"><span class="msl">Bales</span>'
        +'<span class="msv '+bale_color+'">'+bale_lbl+'</span></div>'
        +'<div class="ms"><span class="msl">Mass</span>'
        +'<span class="msv w">'+parseFloat(s.total_mass).toFixed(1)+' kg</span></div>'
        +'<div class="ms"><span class="msl">Gross USD</span>'
        +'<span class="msv">$'+parseFloat(s.gross).toFixed(2)+'</span></div>'
        +bBadge;

    SPLITS=d.splits||[];
    if(d.splits.length>1){
        document.getElementById('msp').innerHTML=d.splits.map(function(sp){
            var act=sp.splitid==d.current_split;
            var bal=parseInt(sp.balanced||0);
            var st='font-size:.72rem;font-weight:700;padding:5px 12px;border-radius:8px;'
                  +'cursor:pointer;margin:0 4px 4px 0;border:2px solid ';
            if(bal)      st+='#2d7a4f;background:#2d7a4f;color:#fff';
            else if(act) st+='#c8952a;background:#c8952a;color:#1a1208';
            else         st+='#c0392b;background:#fdf2f2;color:#c0392b';
            if(act)      st+=';box-shadow:0 0 0 3px rgba(200,149,42,.35)';
            return '<button onclick="openModal('+AT+','+sp.splitid+')" style="'+st+'">'
                +(bal?'&#10003; ':'')+'Split '+sp.splitid+' ('+sp.cnt+')'
                +(bal?'':' &middot; pending')+'</button>';
        }).join('');
    }

    if(!bbId) document.getElementById('meh').style.display='block';

    var rm=0,rv=0,seq=0;
    var rows=bales.map(function(b){
        seq++;
        var p=parseFloat(b.price||0); if(p>10)p/=100;
        var mass=parseFloat(b.mass||0),val=b.sold_id?mass*p:0;
        rm+=mass; rv+=val;
        var sbid=parseInt(b.sold_id||0);
        var sc=(b.sale_code||'').trim();
        var isRej=sc&&sc.toUpperCase()[0]==='R'&&sc.length<=3;
        var canEdit=!!(sbid&&!bbId);

        var tH=b.timb_grade
            ?'<span class="badge badge-gold" style="font-size:.57rem">'+xe(b.timb_grade)+'</span>'
            :'<span class="td-muted">-</span>';
        var bH=b.buyer_grade
            ?'<span class="badge badge-gray" style="font-size:.57rem">'+xe(b.buyer_grade)+'</span>'
            :'<span class="td-muted">-</span>';
        var bN=b.buyer_name?'<div style="font-size:.55rem;color:var(--muted)">'+xe(b.buyer_name)+'</div>':'';
        var scH=sc?'<code style="font-size:.65rem">'+xe(sc)+'</code>':'<span class="td-muted" style="font-size:.65rem">-</span>';
        var stat=isRej
            ?'<span class="badge badge-red" style="font-size:.52rem">Rejected</span>'
            :b.sold_id?'<span class="badge badge-green" style="font-size:.52rem">Sold</span>'
            :'<span class="badge badge-gray" style="font-size:.52rem">Unsold</span>';

        var ep=canEdit?'<span class="ep">*</span>':'';

        // Use data attributes - NO inline string params to avoid quote issues
        var tA=canEdit?'id="t'+sbid+'" class="ec" data-sbid="'+sbid+'" data-cur="'+parseInt(b.timb_gradesid||0)+'" data-type="timb" onclick="editG(this)"':'';
        var bA=canEdit?'id="bg'+sbid+'" class="ec" data-sbid="'+sbid+'" data-cur="'+parseInt(b.buyer_gradesid||0)+'" data-type="buyer" onclick="editG(this)"':'id="bg'+sbid+'"';
        var pA=canEdit?'id="pr'+sbid+'" class="ec td-mono text-right" data-sbid="'+sbid+'" data-price="'+p.toFixed(4)+'" onclick="editP(this)"':'class="td-mono text-right"';
        var sA=canEdit?'id="sc'+sbid+'" class="ec" data-sbid="'+sbid+'" data-sc="'+xe(sc)+'" onclick="editS(this)"':'';

        var rs=isRej?'opacity:.6;background:#fff0f0':!b.sold_id?'background:#fff8ee':'';
        return '<tr id="br'+sbid+'" style="'+rs+'">'
            +'<td style="font-size:.65rem;color:var(--muted);padding:.36rem .6rem">'+seq+'</td>'
            +'<td style="padding:.36rem .6rem"><code style="font-size:.65rem">'+xe(b.barcode||'-')+'</code></td>'
            +'<td style="padding:.36rem .6rem"><code style="font-size:.58rem;color:var(--muted)">'+xe(b.timb_bc||'-')+'</code></td>'
            +'<td style="padding:.36rem .6rem" '+tA+'>'+tH+ep+'</td>'
            +'<td style="padding:.36rem .6rem" '+bA+'>'+bH+bN+ep+'</td>'
            +'<td style="padding:.36rem .6rem" '+sA+'>'+scH+ep+'</td>'
            +'<td class="td-mono text-right" style="padding:.36rem .6rem">'+mass.toFixed(1)+'</td>'
            +'<td '+pA+' style="padding:.36rem .6rem">'+(sbid?'$'+p.toFixed(4):'-')+ep+'</td>'
            +'<td class="td-mono text-right" style="padding:.36rem .6rem" id="v'+sbid+'">'+(val>0?'<span class="fw-500">$'+val.toFixed(2)+'</span>':'-')+'</td>'
            +'<td style="padding:.36rem .6rem" id="ss'+sbid+'">'+stat+'</td>'
            +'</tr>';
    });

    document.getElementById('mbb').innerHTML=rows.join('')||'<tr><td colspan="10" class="text-center text-muted" style="padding:2rem">No bales.</td></tr>';
    document.getElementById('mfl').textContent=seq+' bales, '+s.sold_cnt+' sold'+(s.unsold>0?', '+s.unsold+' unsold':'');
    document.getElementById('mfm').textContent=rm.toFixed(1)+' kg';
    document.getElementById('mfv').textContent='$'+rv.toFixed(2);
    document.getElementById('mbd').style.display='block';

    var ac=document.getElementById('mac');
    if(bbId){
        var soBtn = d.so_processed ? ''
            : '<button onclick="runSO('+bbId+')" style="background:#8a5a0a;color:#fff;border:none;padding:.6rem 1.1rem;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer" id="so-btn">&#9654; Run Stop Orders</button>';
        ac.innerHTML='<div style="flex:1"><div style="font-weight:700;color:#2a6a2a">&#10003; Already Balanced</div>'
            +'<div style="font-size:.72rem;color:#7a6040;margin-top:.2rem">Gross <strong>$'+parseFloat(s.gross).toFixed(2)+'</strong>'
            +(d.so_processed?' &middot; <span style="color:#2a6a2a">Stop orders processed</span>':' &middot; <span style="color:#8a5a0a">Stop orders pending</span>')
            +'</div></div>'
            +soBtn
            +'<a href="print_sheet.php?bbid='+bbId+'" target="_blank" style="background:#1a4a8a;color:#fff;text-decoration:none;padding:.6rem 1.2rem;border-radius:8px;font-size:.82rem;font-weight:600">&#128438; Print Sheet</a>'
            +'<button onclick="closeModal()" style="background:none;border:1px solid #d0c8b0;color:#7a6040;padding:.6rem 1rem;border-radius:8px;font-size:.82rem;cursor:pointer">Close</button>';
    } else if(warn==='NOT_BOOKED'){
        ac.innerHTML='<div style="flex:1"><div style="font-size:.82rem;font-weight:600;color:var(--danger)">Not Booked</div>'
            +'<div style="font-size:.7rem"><a href="grower_booking.php" style="color:var(--gold)">Go to Grower Booking</a></div></div>'
            +'<button onclick="closeModal()" class="btn btn-ghost btn-sm">Close</button>';
    } else if(warn.startsWith('MISMATCH')){
        var pts=warn.split(':');
        ac.innerHTML='<div style="flex:1"><div style="font-size:.82rem;font-weight:600;color:var(--danger)">Mismatch: booked '+pts[1]+', received '+pts[2]+'</div></div>'
            +'<button onclick="closeModal()" class="btn btn-ghost btn-sm">Close</button>';
    } else if(s.unsold>0){
        ac.innerHTML='<div style="flex:1"><div style="font-size:.82rem;font-weight:500;color:var(--danger)">'+s.unsold+' bale(s) not yet sold</div>'
            +'<div style="font-size:.7rem"><a href="selling.php" style="color:var(--gold)">Go to Selling &#8594;</a></div></div>'
            +'<button onclick="closeModal()" class="btn btn-ghost btn-sm">Close</button>';
    } else {
        // All bales dealt with (sold + rejected) — ready to balance
        ac.innerHTML='<div style="flex:1">'
            +'<div style="font-size:.85rem;font-weight:700;color:#2a6a2a">&#10003; All '+s.total_bales+' bales sold &mdash; ready to balance</div>'
            +'<div style="font-size:.72rem;color:#7a6040;margin-top:.2rem">Gross <strong style=\"color:#1a1208\">$'+parseFloat(s.gross).toFixed(2)+'</strong>'
            +' &nbsp;&middot;&nbsp; '+parseFloat(s.total_mass).toFixed(1)+' kg'
            +' &nbsp;&middot;&nbsp; Booked: '+d.bales_booked+'</div>'
            +'</div>'
            +'<button id="balbtn" class="btn-balance" onclick="doBalance('+AG+')">&#10003; Confirm &amp; Balance</button>'
            +'<button onclick="closeModal()" style="background:none;border:1px solid #d0c8b0;color:#7a6040;padding:.6rem 1rem;border-radius:8px;font-size:.82rem;cursor:pointer">Cancel</button>';
    }
}

/* ── Balance ── */
function doBalance(gid){
    if(!confirm('Balance this grower?\n\nCalculates net payout, applies deductions, creates sales sheet.\nCannot be undone without reversal.'))return;
    var btn=document.getElementById('balbtn');
    if(btn){btn.disabled=true;btn.textContent='Processing...';}
    var fd=new FormData();
    fd.append('action','balance');
    fd.append('tgid',AT);
    fd.append('splitid',AS||1);
    fd.append('growerid',gid);
    fetch('balance.php?ajax=balance',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){
        return r.text().then(function(txt){
            try { return JSON.parse(txt); }
            catch(e) { throw new Error('Server error: '+txt.substring(0,300)); }
        });
    })
    .then(function(d){
        if(d.ok){
            if(typeof fw!=='undefined')fw.toast('Balanced successfully','success');
            document.getElementById('mac').innerHTML=
                '<div style="flex:1">'
                +'<div class="fw-500" style="color:var(--leaf)">Balanced - Batch #'+d.batch+'</div>'
                +'<div style="font-size:.72rem;color:var(--muted)">Gross $'+parseFloat(d.gross).toFixed(2)
                +' - Net $'+parseFloat(d.net).toFixed(2)
                +' - ZiG '+parseFloat(d.net_zig).toFixed(2)+'</div>'
                +'</div>'
                +'<a href="print_sheet.php?bbid='+d.bbid+'" target="_blank" class="btn btn-primary btn-sm">Print Sheet</a>'
                +'<button onclick="closeModal()" class="btn btn-ghost btn-sm">Close</button>';
            updateRow(AT,AS||1,d);
            // Auto-advance to the next unbalanced split of this delivery
            var cur=(AS||1), nxt=null, i;
            for(i=0;i<SPLITS.length;i++) if(SPLITS[i].splitid==cur) SPLITS[i].balanced=1;
            for(i=0;i<SPLITS.length;i++) if(!parseInt(SPLITS[i].balanced||0)){nxt=SPLITS[i];break;}
            if(nxt){
                document.getElementById('mac').innerHTML+=
                    '<div style="font-size:.78rem;font-weight:700;color:#8a5a0a;display:flex;align-items:center;gap:.4rem">'
                    +'&#8618; Loading Split '+nxt.splitid+'&hellip;</div>';
                setTimeout(function(){ openModal(AT,nxt.splitid); },1400);
            }
        } else {
            if(typeof fw!=='undefined')fw.toast(d.error||'Failed','error');
            if(btn){btn.disabled=false;btn.textContent='Confirm and Balance';}
        }
    })
    .catch(function(e){
        if(typeof fw!=='undefined')fw.toast('Error: '+e.message,'error');
        if(btn){btn.disabled=false;btn.textContent='Confirm and Balance';}
    });
}

function updateRow(tgid,split,d){
    // Each split has its own register row — match both, or the wrong row gets flagged
    var row=document.querySelector('#bmaster tbody tr[data-tgid="'+tgid+'"][data-split="'+split+'"]')
         || document.querySelector('#bmaster tbody tr[data-tgid="'+tgid+'"]');
    if(!row)return;
    row.classList.add('rb');
    row.dataset.tab='balanced';
    var c=row.querySelectorAll('td');
    if(c[0]){c[0].style.color='#3a9a3a';c[0].innerHTML='&#10003;';}
    if(c[1])c[1].innerHTML='<span class="badge badge-blue" style="font-size:.6rem">'+d.batch+'</span>';
    // Mark row as Balanced in table
    var statusCell = row ? row.querySelector('.cell-status') : null;
    if(statusCell) statusCell.innerHTML='<span class="sp sp-b">Balanced</span>';
    if(row) row.setAttribute('data-so','0');
    if(c[15])c[15].innerHTML='<span class="fw-500">$'+parseFloat(d.gross).toFixed(2)+'</span>';
    if(c[18])c[18].innerHTML='<span class="fw-500" style="color:var(--leaf)">'+parseFloat(d.net).toFixed(2)+'</span>';
    var pc=row.querySelector('td:last-child');
    if(pc)pc.innerHTML='<a href="print_sheet.php?bbid='+d.bbid+'" target="_blank" class="btn btn-ghost btn-sm" style="font-size:.58rem;padding:2px 5px">Print</a>';
    applyF();
}

/* ── Edit Grade ── */
function editG(cell){
    var sbid=parseInt(cell.dataset.sbid);
    var cid=parseInt(cell.dataset.cur||0);
    var type=cell.dataset.type;
    if(!sbid)return;
    if(cell.querySelector('select'))return;
    var grades=type==='timb'?TG:BG;
    var sel=document.createElement('select');
    sel.className='form-control';
    sel.style.cssText='font-size:.72rem;padding:2px 4px;min-width:130px';
    sel.innerHTML='<option value="">--</option>';
    grades.forEach(function(g){
        var o=document.createElement('option');
        o.value=g.id;
        o.textContent=g.description+(g.buyer_name?' ('+g.buyer_name+')':'');
        if(parseInt(g.id)===cid)o.selected=true;
        sel.appendChild(o);
    });
    sel.onchange=function(){
        if(!sel.value)return;
        var fd=new FormData();
        fd.append('sold_id',sbid);
        fd.append(type==='timb'?'timb_gradesid':'buyer_gradesid',sel.value);
        saveE(sbid,fd,cell);
    };
    sel.onblur=function(){setTimeout(function(){if(!sel.dataset.s)openModal(AT,AS);},200);};
    cell.innerHTML='';cell.appendChild(sel);sel.focus();
}

/* ── Edit Price ── */
function editP(cell){
    var sbid=parseInt(cell.dataset.sbid);
    var cur=parseFloat(cell.dataset.price||0);
    if(!sbid)return;
    if(cell.querySelector('input'))return;
    var inp=document.createElement('input');
    inp.type='number';inp.step='0.0001';inp.min='0';
    inp.value=cur.toFixed(4);
    inp.className='form-control';
    inp.style.cssText='font-size:.72rem;padding:2px 4px;width:90px;text-align:right';
    var save=function(){
        if(parseFloat(inp.value)<=0){openModal(AT,AS);return;}
        var fd=new FormData();
        fd.append('sold_id',sbid);
        fd.append('price',inp.value);
        saveE(sbid,fd,cell);
    };
    inp.onblur=save;
    inp.onkeydown=function(e){if(e.key==='Enter')save();if(e.key==='Escape')openModal(AT,AS);};
    cell.innerHTML='';cell.appendChild(inp);inp.focus();inp.select();
}

/* ── Edit Sale Code ── */
function editS(cell){
    var sbid=parseInt(cell.dataset.sbid);
    var cur=cell.dataset.sc||'';
    if(!sbid)return;
    if(cell.querySelector('select'))return;
    var sel=document.createElement('select');
    sel.className='form-control';
    sel.style.cssText='font-size:.72rem;padding:2px 4px;min-width:100px';
    var opts=[['','-- none --'],['S','S - Sold'],['R','R - Rejected'],['T','T - Trial'],['P','P - Private']];
    opts.forEach(function(o){
        var opt=document.createElement('option');
        opt.value=o[0];opt.textContent=o[1];
        if(o[0]===cur)opt.selected=true;
        sel.appendChild(opt);
    });
    sel.onchange=function(){
        sel.dataset.s='1';
        var fd=new FormData();
        fd.append('sold_id',sbid);
        fd.append('sale_code',sel.value);
        saveE(sbid,fd,cell);
    };
    sel.onblur=function(){setTimeout(function(){if(!sel.dataset.s)openModal(AT,AS);},200);};
    cell.innerHTML='';cell.appendChild(sel);sel.focus();
}

/* ── Save Edit ── */
function saveE(sbid,fd,origCell){
    fetch('balance.php?ajax=update_bale',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.ok){
            var row=d.row;
            var p=parseFloat(row.price||0);if(p>10)p/=100;
            var sc=row.sale_code||'';
            var isRej=sc&&sc.toUpperCase()[0]==='R'&&sc.length<=3;

            var tc=document.getElementById('t'+sbid);
            if(tc){tc.innerHTML='<span class="badge badge-gold" style="font-size:.57rem">'+(row.timb_grade||'-')+'</span><span class="ep">*</span>';tc.dataset.cur=row.timb_gradesid;}
            var bc=document.getElementById('bg'+sbid);
            if(bc){bc.innerHTML='<span class="badge badge-gray" style="font-size:.57rem">'+(row.buyer_grade||'-')+'</span>'+(row.buyer_name?'<div style="font-size:.55rem;color:var(--muted)">'+xe(row.buyer_name)+'</div>':'')+'<span class="ep">*</span>';bc.dataset.cur=row.buyer_gradesid;}
            var pc=document.getElementById('pr'+sbid);
            if(pc){pc.innerHTML='$'+p.toFixed(4)+'<span class="ep">*</span>';pc.dataset.price=p.toFixed(4);}
            var scc=document.getElementById('sc'+sbid);
            if(scc){
                var scH=sc?'<code style="font-size:.65rem">'+xe(sc)+'</code>':'<span class="td-muted" style="font-size:.65rem">-</span>';
                scc.innerHTML=scH+'<span class="ep">*</span>';
                scc.dataset.sc=sc;
            }
            var stc=document.getElementById('ss'+sbid);
            if(stc){stc.innerHTML=isRej?'<span class="badge badge-red" style="font-size:.52rem">Rejected</span>':'<span class="badge badge-green" style="font-size:.52rem">Sold</span>';}
            var br=document.getElementById('br'+sbid);
            if(br){br.style.opacity=isRej?'.6':'1';br.style.background=isRej?'#fff0f0':'';}
            var mass=br?parseFloat((br.querySelectorAll('td')[6]||{}).textContent||0):0;
            var vc=document.getElementById('v'+sbid);
            if(vc&&mass>0)vc.innerHTML=isRej?'-':'<span class="fw-500">$'+(mass*p).toFixed(2)+'</span>';
            if(typeof fw!=='undefined')fw.toast('Saved','success');
        } else {
            if(typeof fw!=='undefined')fw.toast(d.error||'Failed','error');
        }
    })
    .catch(function(e){if(typeof fw!=='undefined')fw.toast('Error: '+e.message,'error');});
}

/* ── Reversal panel ── */
document.getElementById('rev-head').addEventListener('click', function(){
    var b = document.getElementById('revbody');
    var lbl = document.getElementById('rev-toggle-lbl');
    if(b.style.display === 'none'){
        b.style.display = 'block';
        lbl.textContent = '\u25b2 Click to collapse';
    } else {
        b.style.display = 'none';
        lbl.textContent = '\u25bc Click to expand';
    }
});

document.getElementById('rev-by').addEventListener('change', function(){
    var labels = {batch:'Batch Number', grower:'Grower Number', bbid:'Record ID (bbid)'};
    var placeholders = {batch:'e.g. 16', grower:'e.g. V123456', bbid:'e.g. 42'};
    var v = this.value;
    document.getElementById('rev-lbl').textContent = labels[v] || 'Value';
    document.getElementById('rev-val').placeholder = placeholders[v] || '';
    document.getElementById('rev-val').value = '';
    revClear();
});

document.getElementById('rev-val').addEventListener('keydown', function(e){
    if(e.key === 'Enter') revLookup();
});

function revClear(){
    document.getElementById('rev-preview').style.display = 'none';
    document.getElementById('rev-result').innerHTML = '';
}

function revLookup(){
    var by  = document.getElementById('rev-by').value;
    var val = document.getElementById('rev-val').value.trim();
    var res = document.getElementById('rev-result');
    revClear();
    if(!val){ res.innerHTML = '<span style="color:var(--danger)">Please enter a value.</span>'; return; }
    res.innerHTML = '<span style="color:var(--muted)">Looking up...</span>';

    fetch('balance.php?ajax=reverse_lookup&by=' + encodeURIComponent(by) + '&val=' + encodeURIComponent(val), {
        headers: {'X-Requested-With':'XMLHttpRequest'}
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        res.innerHTML = '';
        if(!d.ok || d.error){
            res.innerHTML = '<span style="color:var(--danger)">' + xe(d.error||'Lookup failed') + '</span>';
            return;
        }
        if(!d.rows || d.rows.length === 0){
            res.innerHTML = '<span style="color:var(--danger)">No balanced records found.</span>';
            return;
        }
        var tbody = document.getElementById('rev-preview-body');
        tbody.innerHTML = d.rows.map(function(r){
            var dt = r.created_at ? r.created_at.substring(0,10) : '--';
            return '<tr style="border-bottom:1px solid #f5e8e8">'
                + '<td style="padding:.38rem .75rem;font-size:.73rem;font-weight:700;color:#1a4a8a">#' + xe(r.sale_batchid||'--') + '</td>'
                + '<td style="padding:.38rem .75rem;font-size:.73rem;font-family:monospace;color:#c8952a">' + xe(r.grower_num) + '</td>'
                + '<td style="padding:.38rem .75rem;font-size:.73rem">' + xe(r.name + ' ' + r.surname) + '</td>'
                + '<td style="padding:.38rem .75rem;font-size:.73rem;text-align:right">' + xe(r.bales) + '</td>'
                + '<td style="padding:.38rem .75rem;font-size:.73rem;text-align:right;font-weight:600">$' + parseFloat(r.gross).toFixed(2) + '</td>'
                + '<td style="padding:.38rem .75rem;font-size:.73rem;color:#7a6040">' + dt + '</td>'
                + '</tr>';
        }).join('');
        document.getElementById('rev-preview-title').textContent = d.count + ' record(s) found — review before reversing:';
        document.getElementById('rev-confirm-msg').textContent = 'This will permanently delete ' + d.count + ' balance record(s) and reopen the delivery note(s) for re-balancing.';
        document.getElementById('rev-preview').style.display = 'block';
    })
    .catch(function(e){
        res.innerHTML = '<span style="color:var(--danger)">Error: ' + xe(e.message) + '</span>';
    });
}

function doReverse(){
    var by  = document.getElementById('rev-by').value;
    var val = document.getElementById('rev-val').value.trim();
    var res = document.getElementById('rev-result');
    var btn = document.getElementById('rev-confirm-btn');
    if(!val){ res.innerHTML = '<span style="color:var(--danger)">Enter a value.</span>'; return; }
    if(btn){ btn.disabled = true; btn.textContent = 'Reversing...'; }
    var fd = new FormData();
    fd.append('action','reverse');
    fd.append('by', by);
    fd.append('val', val);
    fetch('balance.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(btn){ btn.disabled = false; btn.innerHTML = '&#8617; Confirm Reversal'; }
        if(d.ok){
            document.getElementById('rev-preview').style.display = 'none';
            document.getElementById('rev-val').value = '';
            res.innerHTML = '<span style="color:var(--leaf);font-weight:600">&#10003; ' + xe(d.msg) + '</span>';
            if(typeof fw !== 'undefined') fw.toast(d.msg, 'success');
            setTimeout(function(){ location.reload(); }, 1800);
        } else {
            res.innerHTML = '<span style="color:var(--danger)">&#10005; ' + xe(d.error||'Failed') + '</span>';
        }
    })
    .catch(function(e){
        if(btn){ btn.disabled = false; btn.innerHTML = '&#8617; Confirm Reversal'; }
        res.innerHTML = '<span style="color:var(--danger)">Error: ' + xe(e.message) + '</span>';
    });
}
</script>
<?php include "includes/layout_end.php"; ?>
