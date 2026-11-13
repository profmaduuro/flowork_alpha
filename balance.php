<?php
mysqli_report(MYSQLI_REPORT_OFF);
ob_start(); // buffer all output so errors don't break JSON responses
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_once "includes/stoporder_deduct_helper.php";
require_once "includes/balance_delivery.php";
require_once "includes/rbz_change.php";
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
    // Booked only with a booking (and booking ID) for this delivery's own sell date.
    $bbr = flowork_delivery_booking($conn, $tgid);
    $is_booked    = $bbr !== null;
    $bales_booked = $bbr ? (int)$bbr['bales_booked'] : 0;

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
    $so_processed = 0; $rule = null; $settled = false;
    if ($already_balanced) {
        flowork_ensure_rule_columns($conn);
        $sp_q = $conn->query("SELECT stoporder_processed, rule_so_mode, rule_rbz_usd, transporter_growersid, DATE(created_at) d
            FROM balanced_bales WHERE id=$already_balanced LIMIT 1");
        $sp_r = ($sp_q && $sp_q !== true) ? $sp_q->fetch_assoc() : null;
        $so_processed = (int)($sp_r['stoporder_processed'] ?? 0);
        if ($sp_r && $sp_r['rule_so_mode'] !== null) $rule = ['mode' => $sp_r['rule_so_mode'], 'pct' => round((float)$sp_r['rule_rbz_usd'] * 100, 2)];
        $settled = $sp_r && flowork_delivery_settled($conn, (int)$sp_r['transporter_growersid'], (string)$sp_r['d']);
    }

    echo json_encode([
        'ok'              => true,
        'grower'          => $pg,
        'splits'          => $splits_available,
        'current_split'   => $splitid,
        'already_balanced'=> $already_balanced,
        'so_processed'    => $so_processed,
        'rule'            => $rule,
        'settled'         => $settled,
        'can_change_rule' => $already_balanced && !$settled && (!empty($_SESSION['is_admin']) || is_master_user()),
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
    // Rule for this delivery, chosen on the confirm panel (defaults: the floor's Settings).
    $rule = flowork_rule_from_input($conn, $spid, $_POST['rule_so_mode'] ?? null, $_POST['rule_rbz_usd_pct'] ?? null);
    if (!$rule) { echo json_encode(['error'=>'Invalid rule: stop orders must be before or after the split, and the USD share 0 to 100%.']); exit; }

    $r = flowork_balance_delivery($conn, $tgid, $splitid, $growerid, $spid, $userid, $rule);
    if (empty($r['ok'])) { echo json_encode(['error'=>$r['error']]); exit; }

    echo json_encode([
        'ok'     => true,
        'bbid'   => $r['bbid'],
        'batch'  => $r['batch'],
        'gross'  => round($r['gross'],2),
        'net'    => round($r['net_usd'],2),
        'net_zig'=> round($r['net_zig'],2),
        'bales'  => $r['bales'],
        'msg'    => "Balanced — Gross: \$".number_format($r['gross'],2)." · Net: \$".number_format($r['net_usd'],2)." USD / {$r['sold']} bales · Batch #{$r['batch']}",
    ]);
    exit;
}

// ── REVERSE ───────────────────────────────────────────────────────────────
// do_reverse() lives in includes/balance_delivery.php.

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



// ── CHANGE THE RULE OF A BALANCED, UNPAID DELIVERY (admin, AJAX) ─────────
// Records the new stop-order mode / RBZ split on the delivery and recalculates
// it. Refused once the grower's payment for that day is paid or released.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_rule') {
    ob_end_clean(); header('Content-Type: application/json');
    if (empty($_SESSION['is_admin']) && !is_master_user()) { echo json_encode(['error'=>'Only an administrator can change the rule of a balanced delivery.']); exit; }
    $bbid = (int)($_POST['bbid'] ?? 0);
    if (!flowork_on_floor($conn, 'bb', $bbid, $spid)) { echo json_encode(['error'=>'Delivery not found on this floor']); exit; }
    $rule = flowork_rule_from_input($conn, $spid, $_POST['rule_so_mode'] ?? null, $_POST['rule_rbz_usd_pct'] ?? null);
    if (!$rule) { echo json_encode(['error'=>'Invalid rule: stop orders must be before or after the split, and the USD share 0 to 100%.']); exit; }
    flowork_ensure_rule_columns($conn);
    $cur = $conn->query("SELECT bb.transporter_growersid tgid, DATE(bb.created_at) d, bb.rule_so_mode, bb.rule_rbz_usd,
            n.usd_net_value, n.zim_net_value
        FROM balanced_bales bb LEFT JOIN daily_totals_net_amount n ON n.balanced_balesid=bb.id WHERE bb.id=$bbid")->fetch_assoc();
    if (flowork_delivery_settled($conn, (int)$cur['tgid'], (string)$cur['d'])) {
        echo json_encode(['error'=>'This grower has already been paid for that day. Reverse the payment first, then change the rule.']); exit;
    }
    flowork_set_delivery_rule($conn, $bbid, $rule[0], $rule[1]);
    $calc = flowork_recalculate_balance_batch($conn, $bbid, $spid, $userid);
    if (!$calc) {
        flowork_set_delivery_rule($conn, $bbid, (string)$cur['rule_so_mode'], (float)$cur['rule_rbz_usd']);
        echo json_encode(['error'=>'Recalculation failed; the rule was put back.']); exit;
    }
    audit_log($conn, $spid, $userid, 'balanced_bales', 'change_rule', $bbid,
        ['rule_so_mode'=>$cur['rule_so_mode'], 'rule_rbz_usd'=>(float)$cur['rule_rbz_usd'], 'net_usd'=>(float)$cur['usd_net_value'], 'net_zig'=>(float)$cur['zim_net_value']],
        ['rule_so_mode'=>$rule[0], 'rule_rbz_usd'=>$rule[1], 'net_usd'=>round($calc['net_usd'], 4), 'net_zig'=>round($calc['net_zig'], 4)]);
    echo json_encode(['ok'=>true, 'bbid'=>$bbid, 'net'=>round($calc['net_usd'], 2), 'net_zig'=>round($calc['net_zig'], 2),
        'msg'=>sprintf('Rule changed: stop orders %s the split, USD %s%%. Net now $%s / ZiG %s.', $rule[0], round($rule[1]*100, 2),
            number_format($calc['net_usd'], 2), number_format($calc['net_zig'], 2))]);
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

    // Stop orders loaded after a grower was balanced: apply them to that grower's balanced sale too
    $late_usd = (float)flowork_deduct_stoporders($conn, $spid);
    // RBZ retention changed in Settings: recalculate unpaid balanced sales with the new rate
    [$rbz_done, $rbz_failed, $rbz_drafts] = flowork_rbz_apply($conn, $spid, $userid);

    $parts = [];
    if ($processed) $parts[] = "Stop orders and deductions applied to $processed balanced sale(s).";
    if ($rbz_done)  $parts[] = "$rbz_done balanced sale(s) recalculated with the new RBZ retention rate; sales sheets and EOD reports now use it.";
    $msg = implode(' ', $parts);
    if ($rbz_failed) $msg .= " $rbz_failed could not be recalculated and still use the old rate.";
    if ($rbz_done) $msg .= " Unpaid payments were updated to the new amounts.";
    if ($rbz_drafts) $msg .= " Payment batch(es) #" . implode(', #', $rbz_drafts) . " went back to draft: review and approve them again.";
    $processed += $rbz_done;
    if ($late_usd > 0) $msg .= " Newer stop orders deducted from balanced growers: \$" . number_format($late_usd, 2) . ".";
    if (!$processed && $late_usd <= 0) $msg = "Nothing to apply: every balanced sale already has its stop orders and deductions.";
    header("Location: balance.php?msg=" . urlencode($msg));
    exit;
}

// ── PAGE DATA ──────────────────────────────────────────────────────────────
$msg = $msg ?? '';
$msg = $msg ?: htmlspecialchars($_GET['msg'] ?? '');

// ── Filters: sale date (default: the floor's open selling day, else today;
//    ?date=all for every date) and transporter ───────────────────────────
$_od = $conn->query("SELECT description FROM start_of_day WHERE active=1 AND selling_pointid=$spid ORDER BY id DESC LIMIT 1");
$open_day = ($_od && $_od !== true && $_od->num_rows) ? (string)$_od->fetch_assoc()['description'] : '';
$is_date  = fn($d) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
if (!$is_date($open_day)) $open_day = '';
$f_date = trim((string)($_GET['date'] ?? ''));
if ($f_date !== 'all' && !$is_date($f_date)) $f_date = $open_day ?: $today;
$f_tr   = (int)($_GET['transporter'] ?? 0);
$f_day  = $f_date === 'all' ? '1=1' : "COALESCE(tg.sell_date, DATE(tg.created_at))='$f_date'";
// A delivery from an earlier sale date that still has bales to balance stays on the
// list, so a hanging delivery is not hidden by the date filter.
$f_carry = "(COALESCE(tg.sell_date, DATE(tg.created_at)) < '$f_date' AND EXISTS (
    SELECT 1 FROM bale_junus bj2 WHERE bj2.transporter_growersid = tg.id
      AND NOT EXISTS (SELECT 1 FROM balanced_bales bb2
                      WHERE bb2.transporter_growersid = tg.id AND bb2.splitid = COALESCE(bj2.splitid, 1))))";
$f_rows_day = $f_date === 'all' ? '1=1' : "($f_day OR $f_carry)";
$f_where = " AND $f_rows_day" . ($f_tr ? " AND tg.transporterid=$f_tr" : '');
// Transporters with deliveries on the chosen date
$_tq = $conn->query("SELECT tr.id, tr.name, tr.truck_num, COUNT(DISTINCT tg.id) n
    FROM transporter_growers tg JOIN growers g ON g.id=tg.growerid JOIN transporter tr ON tr.id=tg.transporterid
    WHERE (g.selling_pointid=$spid OR $spid=0) AND $f_rows_day
    GROUP BY tr.id ORDER BY tr.name, tr.id");
$f_transporters = ($_tq && $_tq !== true) ? $_tq->fetch_all(MYSQLI_ASSOC) : [];

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
    WHERE (g.selling_pointid = $spid OR $spid = 0) $f_where
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
// Balanced growers with stop orders still to deduct (loaded after they were balanced)
$_late = $conn->query("SELECT COUNT(DISTINCT bb.growerid) n
    FROM balanced_bales bb JOIN growers g ON g.id=bb.growerid
    WHERE (g.selling_pointid=$spid OR $spid=0)
      AND (EXISTS (SELECT 1 FROM stoporders s WHERE s.growerid=bb.growerid AND s.active=1 AND s.processed=0)
        OR EXISTS (SELECT 1 FROM internal_stop_order_amount a WHERE a.growerid=bb.growerid AND a.active=1 AND a.processed=0))");
$late_so = ($_late && $_late !== true) ? (int)$_late->fetch_assoc()['n'] : 0;
// Balanced sales still on an old RBZ retention rate
$rbz_now   = flowork_rbz_current($conn);
$rbz_stale = flowork_rbz_stale_count($conn, $spid, $rbz_now);
$rbz_last  = $rbz_stale ? (flowork_rbz_changes($conn, 1)[0] ?? null) : null;

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
/* Balance & Settlement — built on the shared tokens in includes/layout.css
   (--surface, --line, --text, --text-muted, --ok-*, --warn-*, --bad-*), so light
   and dark mode need no overrides here. Tabs use the shared .premium-tab-* style. */

/* ── Summary strip ─────────────────────────────────── */
.ksi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem;margin-bottom:1rem}
.ksc{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:.85rem 1rem;box-shadow:var(--shadow-sm);min-width:0}
.ksl{font-size:.74rem;font-weight:500;color:var(--text-muted)}
.ksv{font-size:1.15rem;font-weight:600;line-height:1.25;margin-top:.2rem;color:var(--text);font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ksv.g{color:var(--ok-text)}.ksv.w{color:var(--text)}
.kss{font-size:.72rem;color:var(--text-muted);margin-top:.15rem}

/* ── Header actions / panels ───────────────────────── */
.bal-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.bal-actions form{margin:0}
.bal-panel-toggle{cursor:pointer;user-select:none}
.bal-panel-toggle h3{display:flex;align-items:center;gap:.5rem}
.bal-panel-toggle h3 svg{width:17px;height:17px;color:var(--text-muted)}
.bal-panel-toggle .hint{font-size:.82rem;color:var(--text-muted);display:flex;align-items:center;gap:.35rem}
.bal-panel-toggle .hint svg{width:14px;height:14px;transition:transform .2s}
.bal-panel-toggle.open .hint svg{transform:rotate(180deg)}
.bal-note{font-size:.8rem;color:var(--text-muted);margin-top:.5rem;display:flex;gap:.4rem;align-items:flex-start}
.bal-note svg{width:15px;height:15px;flex-shrink:0;margin-top:2px}

/* Reversal preview */
.rev-box{border:1px solid var(--bad-text);border-color:color-mix(in srgb,var(--bad-text) 35%,transparent);border-radius:var(--radius);overflow:hidden;margin-bottom:.75rem}
.rev-box-title{background:var(--bad-tint);padding:.6rem 1rem;font-size:.86rem;font-weight:600;color:var(--bad-text)}
.rev-box table{width:100%;border-collapse:collapse;font-size:.85rem}
.rev-box th{padding:.5rem .8rem;font-size:.78rem;font-weight:500;text-align:left;color:var(--text-muted);background:var(--surface-2);border-bottom:1px solid var(--line)}
.rev-box th.r,.rev-box td.r{text-align:right}
.rev-box td{padding:.5rem .8rem;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums}
.rev-box-foot{padding:.65rem 1rem;background:var(--surface-2);border-top:1px solid var(--line);display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.rev-box-foot span{flex:1;font-size:.85rem;color:var(--bad-text)}

/* ZiG / USD split preview */
.zpw{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:.85rem 1.1rem;margin-bottom:1rem;display:flex;gap:1rem 1.5rem;align-items:center;flex-wrap:wrap;box-shadow:var(--shadow-sm)}
.zpw-title{font-size:.9rem;font-weight:600;color:var(--text);display:flex;align-items:center;gap:.45rem;white-space:nowrap}
.zpw-title svg{width:16px;height:16px;color:var(--accent-text)}
.zpw-inputs{display:flex;align-items:center;gap:.5rem .75rem;flex-wrap:wrap;flex:1}
.zpw-inputs label{display:flex;align-items:center;gap:.4rem;font-size:.82rem;color:var(--text-muted)}
.zpw-inputs .form-control{width:auto;min-height:34px;font-variant-numeric:tabular-nums}
.zpw-out{display:flex;gap:1.25rem;flex-wrap:wrap}
.zpw-out > div{min-width:90px}
.zpw-out .l{font-size:.78rem;color:var(--text-muted)}
.zpw-out .v{font-size:1.1rem;font-weight:600;font-variant-numeric:tabular-nums;color:var(--text)}
.zpw-out .v.usd{color:var(--ok-text)}.zpw-out .v.zig{color:var(--accent-text)}
.zpw-foot{font-size:.78rem;color:var(--text-muted);white-space:nowrap}

/* ── Filter bar ────────────────────────────────────── */
.fbar{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.75rem}
.fbar form{display:flex;gap:.5rem;align-items:center;margin:0;flex-wrap:wrap}
.fbar .form-control{width:auto}
.fbar #srch{flex:1;min-width:170px;max-width:280px}
.fbar .premium-tabs-nav{margin-bottom:0}
.fbar .premium-tab-btn .badge{margin-left:2px}
#rinfo{font-size:.8rem;color:var(--text-muted)}

/* ── Master table ──────────────────────────────────── */
.bwrap{overflow:auto;max-height:calc(100vh - var(--top-h) - 2rem)}
#bmaster{font-size:.78rem}
#bmaster thead th{position:sticky;top:0;z-index:2;background:var(--surface-2);color:var(--text-muted);font-size:.7rem;font-weight:500;white-space:nowrap;padding:.5rem .7rem;border-bottom:1px solid var(--line)}
#bmaster tbody tr{cursor:pointer;transition:background .1s}
#bmaster tbody tr:hover td{background:var(--surface-2)}
#bmaster tbody td{padding:.4rem .7rem;white-space:nowrap;line-height:1.35}
#bmaster tfoot td{padding:.6rem .7rem;white-space:nowrap;background:var(--surface-2);font-weight:600;border-top:1px solid var(--line-strong);position:sticky;bottom:0}
#bmaster .gnum{font-weight:500;color:var(--accent-text);font-variant-numeric:tabular-nums}
#bmaster .muted{color:var(--text-muted)}
#bmaster .mono{font-family:ui-monospace,monospace;font-size:.8rem;color:var(--text-muted)}
#bmaster .net{color:var(--ok-text);font-weight:600}
/* row marker: a small coloured dot instead of ✓ ~ ! */
.rowmark{display:inline-block;width:8px;height:8px;border-radius:50%}
.rowmark.done{background:var(--ok-text)}.rowmark.ready{background:var(--accent)}.rowmark.todo{background:var(--bad-text)}
.splitno{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:22px;padding:0 7px;border-radius:999px;font-size:.76rem;font-weight:600;background:var(--warn-tint);color:var(--warn-text)}
.splitno.done{background:var(--ok-tint);color:var(--ok-text)}

/* status pills */
.sp{display:inline-flex;align-items:center;gap:.25rem;padding:2px 9px;border-radius:999px;font-size:.76rem;font-weight:500;white-space:nowrap}
.sp-b{background:var(--ok-tint);color:var(--ok-text)}
.sp-r{background:var(--warn-tint);color:var(--warn-text)}
.sp-p{background:var(--info-tint);color:var(--info-text)}
.sp-n{background:var(--bad-tint);color:var(--bad-text)}

/* ── Balance modal ─────────────────────────────────── */
#bmo{display:none;position:fixed;inset:0;z-index:9000;background:rgba(20,17,13,.5);backdrop-filter:blur(3px);overflow-y:auto;padding:2.5rem 1rem;animation:bmo-in .18s ease}
@keyframes bmo-in{from{opacity:0}to{opacity:1}}
#bmb{background:var(--surface);color:var(--text);border:1px solid var(--line);border-radius:var(--radius-lg);max-width:1060px;margin:0 auto;box-shadow:var(--shadow-md);overflow:hidden;animation:bmb-in .2s ease-out}
@keyframes bmb-in{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
#bmb .bm-head{padding:1.1rem 1.4rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;border-bottom:1px solid var(--line)}
#mgn{font-size:1.25rem;font-weight:600;color:var(--text);line-height:1.25}
#mgs{font-size:.85rem;color:var(--text-muted);margin-top:.15rem}
.ms{display:flex;flex-direction:column;background:var(--surface-2);border:1px solid var(--line);border-radius:var(--radius);padding:.4rem .75rem;min-width:70px}
.msl{font-size:.75rem;color:var(--text-muted)}
.msv{font-size:.95rem;font-weight:600;color:var(--text);line-height:1.3;font-variant-numeric:tabular-nums;white-space:nowrap}
.msv.g{color:var(--ok-text)}.msv.r{color:var(--bad-text)}.msv.w{color:var(--text)}
#bm-close-btn{width:36px;height:36px;border-radius:var(--radius);border:1px solid var(--line);background:var(--surface);color:var(--text-soft);cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center}
#bm-close-btn:hover{background:var(--surface-3)}
#bm-close-btn svg{width:18px;height:18px}
/* split switcher */
.bm-split{font:600 .8rem 'DM Sans',sans-serif;padding:5px 12px;border-radius:999px;cursor:pointer;margin:0 4px 4px 0;border:1px solid var(--line-strong);background:var(--surface);color:var(--text-soft)}
.bm-split.done{background:var(--ok-tint);color:var(--ok-text);border-color:transparent}
.bm-split.pend{background:var(--bad-tint);color:var(--bad-text);border-color:transparent}
.bm-split.act{background:var(--primary-bg);color:var(--primary-text);border-color:transparent;box-shadow:0 0 0 3px var(--focus)}
/* edit hint */
#meh{background:var(--warn-tint);color:var(--warn-text);padding:.55rem 1.4rem;font-size:.84rem;align-items:center;gap:.5rem;border-bottom:1px solid var(--line)}
#meh svg{width:15px;height:15px;vertical-align:-2px;margin-right:4px}
/* loading */
#mld{text-align:center;padding:4rem 2rem}
#mld .spin{width:36px;height:36px;border:3px solid var(--line);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;margin:0 auto}
#mld .lbl{color:var(--text-muted);margin-top:1rem;font-size:.9rem}
/* bale table */
#mbd table{width:100%;border-collapse:collapse;font-size:.78rem}
#mbd thead th{background:var(--surface-2);color:var(--text-muted);font-size:.7rem;font-weight:500;padding:.55rem .75rem;border-bottom:1px solid var(--line);white-space:nowrap;position:sticky;top:0;z-index:2;text-align:left}
#mbd tbody tr{border-bottom:1px solid var(--line)}
#mbd tbody tr:last-child{border-bottom:none}
#mbd tbody tr:hover td{background:var(--surface-2)}
#mbd tbody tr.is-rej td{background:var(--bad-tint);color:var(--text-soft)}
#mbd tbody tr.is-unsold td{background:var(--warn-tint)}
#mbd tbody td{padding:.45rem .75rem;white-space:nowrap}
#mbd tbody td code{font-size:.8rem;color:var(--text)}
#mbd tbody td code.dim{color:var(--text-muted)}
#mbd tbody .bname{font-size:.75rem;color:var(--text-muted)}
#mbd tfoot td{background:var(--surface-2);border-top:1px solid var(--line-strong);padding:.55rem .75rem;font-weight:600;font-variant-numeric:tabular-nums}
#mbd tfoot td.val{color:var(--ok-text)}
/* inline-editable cells */
.ec{cursor:pointer;position:relative}
.ec:hover{background:var(--accent-tint)!important}
.ep{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;background:var(--accent-tint);color:var(--accent-text);border-radius:4px;margin-left:5px;font-size:.65rem;vertical-align:middle}
/* footer / action bar */
#bm-foot{padding:.95rem 1.4rem;background:var(--surface-2);border-top:1px solid var(--line);display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.bm-status{font-size:.95rem;font-weight:600;color:var(--ok-text);display:flex;align-items:center;gap:.4rem}
.bm-status.bad{color:var(--bad-text)}
.bm-status svg{width:17px;height:17px}
.bm-meta{font-size:.84rem;color:var(--text-muted);margin-top:.2rem}
.bm-meta strong{color:var(--text)}
.bm-meta .ok{color:var(--ok-text)}.bm-meta .warn{color:var(--warn-text)}
.bm-rule{display:flex;flex-wrap:wrap;gap:.4rem .8rem;align-items:center;font-size:.84rem;color:var(--text-muted);margin-top:.45rem}
.bm-rule label{display:flex;align-items:center;gap:.35rem}
.bm-rule select,.bm-rule input{min-height:30px;padding:2px 8px;border:1px solid var(--line-strong);border-radius:6px;background:var(--surface);color:var(--text);font:400 .84rem 'DM Sans',sans-serif}
.bm-rule input{width:5rem}
#rule-note{color:var(--warn-text)}
.bm-link{font-size:.84rem;color:var(--accent-text);font-weight:500}
.btn-balance{display:inline-flex;align-items:center;gap:6px;min-height:42px;background:#2F6B28;color:#fff;border:none;padding:.6rem 1.4rem;border-radius:var(--radius);font:600 .95rem 'DM Sans',sans-serif;cursor:pointer;transition:background .15s}
.btn-balance:hover{background:#255820}
.btn-balance:disabled{opacity:.6;cursor:not-allowed}
.btn-balance svg{width:17px;height:17px}
@keyframes spin{to{transform:rotate(360deg)}}

@media(max-width:600px){
  .ksi{grid-template-columns:1fr 1fr;gap:.5rem}
  .ksv{font-size:1.1rem}
  .fbar #srch{max-width:none}
  .zpw-foot{white-space:normal}
  #bmo{padding:0}
  #bmb{border-radius:0;min-height:100%}
}
</style>

<div class="page-header">
<div class="page-header-left">
<h2>Balance &amp; Settlement</h2>
<p><?= $f_date==='all' ? 'All sale dates' : 'Sale date '.date('d M Y', strtotime($f_date)).($f_date===$open_day?' (open)':'').', plus earlier deliveries not yet balanced' ?> · <?= $total ?> grower slots · <?= $bal_cnt ?> balanced · <?= $pend ?> pending</p>
</div>
<div class="bal-actions">
<?php $_so_todo = $pending_so + $late_so + $rbz_stale; ?>
<form method="POST" onsubmit="return confirm('Apply stop orders and deductions to the balanced sales on this floor?')">
<input type="hidden" name="action" value="run_so">
<button class="btn <?=$_so_todo>0?'btn-gold':'btn-ghost'?>" <?=$total_bal>0?'':'disabled title="Balance a grower first"'?>>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg> Apply stop orders<?=
      $total_bal<=0 ? ' (no balanced sales yet)' : ($_so_todo>0 ? " ($_so_todo to apply)" : ' (all applied)')?>
</button>
</form>
<a href="grower_booking.php" class="btn btn-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg> Booking</a>
<a href="sales_sheets.php" class="btn btn-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h6M9 17h6"/></svg> Sales sheets</a>
<button type="button" onclick="doExport()" class="btn btn-ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg> Export CSV</button>
</div>
</div>

<?php if($msg):?><div class="alert alert-success" id="top-msg"><?=$msg?></div><?php endif;?>
<?php if($rbz_stale): $_pc = fn($s) => rtrim(rtrim(number_format($s*100,2),'0'),'.'); ?>
<div class="alert alert-warning" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
  <div style="flex:1;min-width:260px">
    <strong>RBZ retention rate changed</strong>
    <?php if($rbz_last):?> on <?=htmlspecialchars(date('d M Y H:i', strtotime($rbz_last['changed_at'])))?><?=$rbz_last['username']!==''?' by '.htmlspecialchars($rbz_last['username']):''?><?php endif;?>:
    now USD <?=$_pc($rbz_now)?>% / ZiG <?=$_pc(1-$rbz_now)?>%<?php if($rbz_last && $rbz_last['old_usd']!==null):?> (was USD <?=$_pc((float)$rbz_last['old_usd'])?>%)<?php endif;?>.
    <?=$rbz_stale?> balanced sale(s) on this floor still use the old rate. Run <em>Apply stop orders</em> to recalculate them; sales sheets and EOD reports follow. Paid sales keep their figures.
  </div>
  <form method="POST" style="margin:0" onsubmit="return confirm('Recalculate <?=$rbz_stale?> balanced sale(s) with the new RBZ retention rate and apply stop orders?')">
    <input type="hidden" name="action" value="run_so">
    <button class="btn btn-gold">Apply stop orders now</button>
  </form>
</div>
<?php endif;?>

<!-- Reversal Panel -->
<div class="card" style="margin-bottom:.85rem">
  <div class="card-head bal-panel-toggle" id="rev-head" role="button" tabindex="0" aria-expanded="false" aria-controls="revbody">
    <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/></svg> Reverse a balanced sale</h3>
    <span class="hint"><span id="rev-toggle-lbl">Show</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
  </div>
  <div id="revbody" style="display:none;padding:1.1rem 1.25rem">

    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:.85rem">
      <div>
        <label class="form-label" for="rev-by">Reverse by</label>
        <select id="rev-by" class="form-control" style="min-width:160px">
          <option value="batch">Batch Number</option>
          <option value="grower">Grower Number</option>
          <option value="bbid">Record ID (bbid)</option>
        </select>
      </div>
      <div style="flex:1;min-width:180px">
        <label class="form-label" for="rev-val" id="rev-lbl">Batch Number</label>
        <input type="text" id="rev-val" class="form-control" placeholder="e.g. 16">
      </div>
      <button type="button" class="btn btn-ghost" onclick="revLookup()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg> Look up</button>
    </div>

    <div id="rev-preview" class="rev-box" style="display:none">
      <div class="rev-box-title" id="rev-preview-title"></div>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr><th>Batch</th><th>Grower</th><th>Name</th><th class="r">Bales</th><th class="r">Gross USD</th><th>Date</th></tr>
          </thead>
          <tbody id="rev-preview-body"></tbody>
        </table>
      </div>
      <div class="rev-box-foot">
        <span id="rev-confirm-msg"></span>
        <button type="button" class="btn btn-danger" onclick="doReverse()" id="rev-confirm-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/></svg> Confirm reversal</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="revClear()">Cancel</button>
      </div>
    </div>

    <div id="rev-result" style="font-size:.88rem;min-height:1.2rem"></div>
    <div class="bal-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2 21h20z"/><path d="M12 10v5M12 18h.01"/></svg> Reversing permanently removes the balance records and reopens the delivery note so it can be balanced again.</div>
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
<div id="zig-preview-widget" class="zpw">
  <div class="zpw-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 3h5v5M8 3H3v5M21 3l-7 7M3 3l7 7M12 22v-8"/></svg> ZiG / USD split preview</div>
  <div class="zpw-inputs">
    <label>Gross USD <input id="zpw-gross" class="form-control" type="number" step="0.01" min="0" placeholder="Amount" value="<?=number_format($t_gross,2,'.','')?>" style="width:130px" oninput="calcSplit()"></label>
    <label>Rate <input id="zpw-rate" class="form-control" type="number" step="0.01" min="0" placeholder="ZiG rate" value="<?=number_format($er_now,2,'.','')?>" style="width:96px" oninput="calcSplit()"></label>
  </div>
  <div class="zpw-out" id="zpw-result">
    <div><div class="l">70% USD</div><div class="v usd" id="zpw-usd">$<?=number_format($t_gross*0.70,2)?></div></div>
    <div><div class="l">30% ZiG</div><div class="v zig" id="zpw-zig"><?=number_format($t_gross*0.30*$er_now,2)?> ZiG</div></div>
    <div><div class="l">Net USD (est.)</div><div class="v" id="zpw-net">$<?=number_format($t_net,2)?></div></div>
  </div>
  <div class="zpw-foot">RBZ 70/30 split · estimate only</div>
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
  <form method="GET">
    <input type="date" name="date" value="<?=$f_date==='all'?'':$f_date?>" class="form-control" onchange="this.form.submit()" title="Sale date" aria-label="Sale date">
    <select name="transporter" class="form-control" style="max-width:240px" onchange="this.form.submit()" aria-label="Transporter">
      <option value="0">All transporters</option>
      <?php foreach($f_transporters as $t):?>
      <option value="<?=(int)$t['id']?>" <?=$f_tr===(int)$t['id']?'selected':''?>><?=htmlspecialchars($t['name'].($t['truck_num']?' · '.$t['truck_num']:''))?> (<?=(int)$t['n']?>)</option>
      <?php endforeach;?>
    </select>
    <?php if($f_date!=='all'):?>
    <a href="balance.php?date=all" class="btn btn-ghost btn-sm">All dates</a>
    <?php else:?>
    <span class="badge badge-gray">All dates</span>
    <?php endif;?>
    <?php if($f_date!==($open_day ?: $today) || $f_tr):?>
    <a href="balance.php" class="btn btn-ghost btn-sm"><?=$open_day?'Open day':'Today'?></a>
    <?php endif;?>
  </form>
  <input type="search" id="srch" class="form-control" placeholder="Search grower or transporter" aria-label="Search grower or transporter" autocomplete="off">
  <div class="premium-tabs-nav">
    <button class="premium-tab-btn active" id="tab-all" onclick="setTab('all')">
      All <span class="badge badge-gray" id="c-all"><?=$total?></span>
    </button>
    <button class="premium-tab-btn" id="tab-pending" onclick="setTab('pending')">
      Pending <span class="badge badge-gray" id="c-pending"><?=$pend?></span>
    </button>
    <button class="premium-tab-btn" id="tab-ready" onclick="setTab('ready')">
      Ready <span class="badge badge-gold" id="c-ready">0</span>
    </button>
    <button class="premium-tab-btn" id="tab-balanced" onclick="setTab('balanced')">
      Balanced <span class="badge badge-gray" id="c-balanced"><?=$bal_cnt?></span>
    </button>
  </div>
  <span id="rinfo"></span>
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
<td style="text-align:center"><span class="rowmark <?=$isbal?'done':($tab==='ready'?'ready':'todo')?>" title="<?=$isbal?'Balanced':($tab==='ready'?'Ready to balance':'Not ready')?>"></span></td>
<td><?=$r['batch_num']?'<span class="badge badge-blue">'.(int)$r['batch_num'].'</span>':'<span class="muted">—</span>'?></td>
<td><span class="gnum"><?=htmlspecialchars($r['grower_num'])?></span></td>
<td><?=htmlspecialchars($r['first_name'])?></td>
<td><?=htmlspecialchars($r['surname'])?></td>
<td class="muted"><?=htmlspecialchars($r['loc_name']?:$r['loc_code'])?></td>
<td class="muted"><?=htmlspecialchars($r['province'])?></td>
<td><span class="badge badge-gray"><?=htmlspecialchars($r['floor_code'])?></span></td>
<td><?=$r['sell_dt']?></td>
<td><span class="splitno<?=$isbal?' done':''?>"><?=(int)$r['splitid']?></span></td>
<td class="cell-status"><?=$pill?></td>
<td class="muted"><?=$gdn?></td>
<td class="td-mono text-right fw-500"><?=$isbal?(int)$r['dt_sold']:(int)$r['sold_count']?></td>
<td class="td-mono text-right"><?=number_format((float)$r['total_mass'],0)?></td>
<td class="td-mono text-right"><?=$isbal?(int)$r['dt_rej']:(int)$r['rej_count']?></td>
<td class="td-mono text-right fw-500"><?=$isbal?'$'.number_format((float)$r['gross_usd'],2):'--'?></td>
<td class="td-mono text-right"><?=$isbal?number_format((float)$r['usd_deductions'],2):'0.0'?></td>
<td class="td-mono text-right"><?=$isbal?$usd_svc:'0.0'?></td>
<td class="td-mono text-right net"><?=$isbal?number_format((float)$r['net_usd'],2):'0.0'?></td>
<td><?=$usp?></td>
<td class="mono"><?=htmlspecialchars($r['usd_pay_ref'])?></td>
<td class="td-mono text-right"><?=$isbal?number_format((float)$r['gross_zig'],2):'0.0'?></td>
<td class="td-mono text-right"><?=$isbal?number_format((float)$r['zim_deductions'],2):'0.0'?></td>
<td class="td-mono text-right"><?=$isbal?$zig_svc:'0.0'?></td>
<td class="td-mono text-right net"><?=$isbal?number_format((float)$r['net_zig'],2):'0.0'?></td>
<td><?=$zwp?></td>
<td class="mono"><?=htmlspecialchars($r['zig_pay_ref'])?></td>
<td><?=htmlspecialchars($r['fca_bank'])?></td>
<td class="mono"><?=htmlspecialchars($r['fca_account'])?></td>
<td><?=htmlspecialchars($r['zw_bank'])?></td>
<td class="mono"><?=htmlspecialchars($r['zw_account'])?></td>
<td class="td-mono text-right"><?=$isbal?number_format((float)$r['net_usd']-(float)$r['gross_usd'],2):'0.0'?></td>
<td class="td-mono text-right"><?=$r['printed']?1:0?></td>
<td class="muted"><?=$rel?></td>
<td class="td-mono text-right">0</td>
<td onclick="event.stopPropagation()" style="text-align:center">
<?php if($isbal):?><a href="print_sheet.php?bbid=<?=$r['bbid']?>" target="_blank" class="btn btn-ghost btn-sm">Print</a><?php endif;?>
</td>
</tr>
<?php endforeach;?>
<?php if(empty($all_rows)):?><tr><td colspan="36" class="text-center text-muted" style="padding:2.5rem">No deliveries for this sale date and transporter.</td></tr><?php endif;?>
</tbody>
<tfoot><tr>
<td colspan="12"><strong id="tfl">Totals</strong></td>
<td class="td-mono text-right" id="tf1"><?=array_sum(array_column(array_filter($all_rows,fn($r)=>$r['bbid']),'dt_sold'))?></td>
<td class="td-mono text-right" id="tf2"><?=number_format($t_mass,0)?></td>
<td class="td-mono text-right" id="tf3">0</td>
<td class="td-mono text-right" id="tf4">$<?=number_format($t_gross,2)?></td>
<td class="td-mono text-right" id="tf5">0.0</td>
<td class="td-mono text-right" id="tf6">0.0</td>
<td class="td-mono text-right" id="tf7" style="color:var(--ok-text)">$<?=number_format($t_net,2)?></td>
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
    <button type="button" onclick="closeModal()" id="bm-close-btn" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
  </div>

  <!-- ── Edit hint ── -->
  <div id="meh" style="display:none">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>Click a <strong>TIMB grade</strong>, <strong>buyer grade</strong>, <strong>sale code</strong> or <strong>price</strong> to change it. Changes save straight away.
  </div>

  <!-- ── Loading ── -->
  <div id="mld">
    <div class="spin"></div>
    <div class="lbl">Loading bales…</div>
  </div>

  <!-- ── Bale table ── -->
  <div id="mbd" style="display:none;max-height:48vh;overflow-y:auto;overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th style="text-align:center;width:40px">#</th>
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
          <td id="mfm" style="text-align:right"></td>
          <td></td>
          <td id="mfv" class="val" style="text-align:right"></td>
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


<?php [$__rule_mode, $__rule_share] = flowork_rule_defaults($conn, $spid); ?>
<script>
/* global state */
var TG=[],BG=[],AT=0,AS=0,AG=0;
/* The floor's Settings: the rule offered by default when balancing */
var RULE_DEFAULT={mode:<?= json_encode($__rule_mode) ?>,pct:<?= json_encode(round($__rule_share * 100, 2)) ?>};


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
            var cls='bm-split '+(act?'act':(bal?'done':'pend'));
            return '<button type="button" onclick="openModal('+AT+','+sp.splitid+')" class="'+cls+'"'+(act?' aria-current="true"':'')+'>'
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
            ?'<span class="badge badge-gold">'+xe(b.timb_grade)+'</span>'
            :'<span class="td-muted">-</span>';
        var bH=b.buyer_grade
            ?'<span class="badge badge-gray">'+xe(b.buyer_grade)+'</span>'
            :'<span class="td-muted">-</span>';
        var bN=b.buyer_name?'<div class="bname">'+xe(b.buyer_name)+'</div>':'';
        var scH=sc?'<code>'+xe(sc)+'</code>':'<span class="td-muted">-</span>';
        var stat=isRej
            ?'<span class="badge badge-red">Rejected</span>'
            :b.sold_id?'<span class="badge badge-green">Sold</span>'
            :'<span class="badge badge-gray">Unsold</span>';

        var ep=canEdit?'<span class="ep">*</span>':'';

        // Use data attributes - NO inline string params to avoid quote issues
        var tA=canEdit?'id="t'+sbid+'" class="ec" data-sbid="'+sbid+'" data-cur="'+parseInt(b.timb_gradesid||0)+'" data-type="timb" onclick="editG(this)"':'';
        var bA=canEdit?'id="bg'+sbid+'" class="ec" data-sbid="'+sbid+'" data-cur="'+parseInt(b.buyer_gradesid||0)+'" data-type="buyer" onclick="editG(this)"':'id="bg'+sbid+'"';
        var pA=canEdit?'id="pr'+sbid+'" class="ec td-mono text-right" data-sbid="'+sbid+'" data-price="'+p.toFixed(4)+'" onclick="editP(this)"':'class="td-mono text-right"';
        var sA=canEdit?'id="sc'+sbid+'" class="ec" data-sbid="'+sbid+'" data-sc="'+xe(sc)+'" onclick="editS(this)"':'';

        var rc=isRej?'is-rej':(!b.sold_id?'is-unsold':'');
        return '<tr id="br'+sbid+'" class="'+rc+'">'
            +'<td style="text-align:center;color:var(--text-muted)">'+seq+'</td>'
            +'<td><code>'+xe(b.barcode||'-')+'</code></td>'
            +'<td><code class="dim">'+xe(b.timb_bc||'-')+'</code></td>'
            +'<td '+tA+'>'+tH+ep+'</td>'
            +'<td '+bA+'>'+bH+bN+ep+'</td>'
            +'<td '+sA+'>'+scH+ep+'</td>'
            +'<td class="td-mono text-right">'+mass.toFixed(1)+'</td>'
            +'<td '+pA+'>'+(sbid?'$'+p.toFixed(4):'-')+ep+'</td>'
            +'<td class="td-mono text-right" id="v'+sbid+'">'+(val>0?'<span class="fw-500">$'+val.toFixed(2)+'</span>':'-')+'</td>'
            +'<td id="ss'+sbid+'">'+stat+'</td>'
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
            : '<button type="button" onclick="runSO('+bbId+')" class="btn btn-gold" id="so-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg> Run stop orders</button>';
        ac.innerHTML='<div style="flex:1;min-width:240px"><div class="bm-status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg> Already balanced</div>'
            +'<div class="bm-meta">Gross <strong>$'+parseFloat(s.gross).toFixed(2)+'</strong>'
            +(d.so_processed?' · <span class="ok">Stop orders processed</span>':' · <span class="warn">Stop orders pending</span>')
            +'</div>'
            +(d.rule?'<div class="bm-meta">Rule: TIMB stop orders '+d.rule.mode+' the RBZ split, USD '+d.rule.pct+'%'
                +(d.settled?' · paid, rule locked':'')+'</div>':'')
            +(d.can_change_rule?'<div class="bm-rule">'
                +'<select id="cr-mode" aria-label="When stop orders apply"><option value="before">before split</option><option value="after">after split</option></select>'
                +'<label><input id="cr-pct" type="number" min="0" max="100" step="0.01" aria-label="USD share"> %</label>'
                +'<button type="button" onclick="changeRule('+bbId+')" class="btn btn-ghost btn-sm">Change rule and recalculate</button>'
                +'</div>':'')
            +'</div>'
            +soBtn
            +'<a href="print_sheet.php?bbid='+bbId+'" target="_blank" class="btn btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2"/><path d="M6 14h12v7H6z"/></svg> Print sheet</a>'
            +'<button type="button" onclick="closeModal()" class="btn btn-ghost">Close</button>';
        if(d.can_change_rule){
            var r0=d.rule||RULE_DEFAULT;
            document.getElementById('cr-mode').value=r0.mode; document.getElementById('cr-pct').value=r0.pct;
        }
    } else if(warn==='NOT_BOOKED'){
        ac.innerHTML='<div style="flex:1"><div class="bm-status bad">Not booked</div>'
            +'<a class="bm-link" href="grower_booking.php">Go to Grower Booking</a></div>'
            +'<button type="button" onclick="closeModal()" class="btn btn-ghost">Close</button>';
    } else if(warn.startsWith('MISMATCH')){
        var pts=warn.split(':');
        ac.innerHTML='<div style="flex:1"><div class="bm-status bad">Mismatch: booked '+pts[1]+', received '+pts[2]+'</div></div>'
            +'<button type="button" onclick="closeModal()" class="btn btn-ghost">Close</button>';
    } else if(s.unsold>0){
        ac.innerHTML='<div style="flex:1"><div class="bm-status bad">'+s.unsold+' bale(s) not yet sold</div>'
            +'<a class="bm-link" href="selling.php">Go to Selling</a></div>'
            +'<button type="button" onclick="closeModal()" class="btn btn-ghost">Close</button>';
    } else {
        // All bales dealt with (sold + rejected) — ready to balance
        ac.innerHTML='<div style="flex:1;min-width:240px">'
            +'<div class="bm-status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg> All '+s.total_bales+' bales sold, ready to balance</div>'
            +'<div class="bm-meta">Gross <strong>$'+parseFloat(s.gross).toFixed(2)+'</strong>'
            +' · '+parseFloat(s.total_mass).toFixed(1)+' kg'
            +' · Booked: '+d.bales_booked+'</div>'
            +'<div class="bm-rule">'
            +'<label>TIMB stop orders <select id="rule-mode">'
            +'<option value="before">before the RBZ split</option><option value="after">after the RBZ split</option></select></label>'
            +'<label>USD share <input id="rule-pct" type="number" min="0" max="100" step="0.01"> %</label>'
            +'<span id="rule-note"></span>'
            +'</div>'
            +'</div>'
            +'<button type="button" id="balbtn" class="btn-balance" onclick="doBalance('+AG+')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg> Confirm and balance</button>'
            +'<button type="button" onclick="closeModal()" class="btn btn-ghost">Cancel</button>';
        // Rule for this delivery: starts at the floor's Settings, can be changed here.
        var rm=document.getElementById('rule-mode'), rp=document.getElementById('rule-pct'), rn=document.getElementById('rule-note');
        rm.value=RULE_DEFAULT.mode; rp.value=RULE_DEFAULT.pct;
        var flag=function(){ rn.textContent=(rm.value!==RULE_DEFAULT.mode||parseFloat(rp.value)!==RULE_DEFAULT.pct)?'differs from Settings':''; };
        rm.onchange=flag; rp.oninput=flag;
    }
}

/* ── Balance ── */
/* ── Change the rule of a balanced, unpaid delivery (admin) ── */
function changeRule(bbid){
    var m=document.getElementById('cr-mode').value, p=document.getElementById('cr-pct').value;
    if(!confirm('Change the rule for this delivery and recalculate it?\n\nTIMB stop orders '+m+' the RBZ split, USD '+p+'% / ZiG '+(100-parseFloat(p||0))+'%.\nThe old and new figures are kept in the audit log.'))return;
    var fd=new FormData();
    fd.append('action','change_rule'); fd.append('bbid',bbid);
    fd.append('rule_so_mode',m); fd.append('rule_rbz_usd_pct',p);
    fetch('balance.php?ajax=change_rule',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(typeof fw!=='undefined') fw.toast(d.ok?d.msg:(d.error||'Failed'), d.ok?'success':'error');
        else alert(d.ok?d.msg:(d.error||'Failed'));
    })
    .catch(function(e){ if(typeof fw!=='undefined') fw.toast('Error: '+e.message,'error'); });
}

function doBalance(gid){
    var _rm=document.getElementById('rule-mode'), _rp=document.getElementById('rule-pct');
    var _rule=_rm?('\n\nRule: TIMB stop orders '+_rm.value+' the RBZ split, USD '+_rp.value+'% / ZiG '+(100-parseFloat(_rp.value||0))+'%'):'';
    if(!confirm('Balance this grower?\n\nCalculates net payout, applies deductions, creates sales sheet.\nCannot be undone without reversal.'+_rule))return;
    var btn=document.getElementById('balbtn');
    if(btn){btn.disabled=true;btn.textContent='Processing...';}
    var fd=new FormData();
    fd.append('action','balance');
    fd.append('tgid',AT);
    fd.append('splitid',AS||1);
    fd.append('growerid',gid);
    var rm=document.getElementById('rule-mode'), rp=document.getElementById('rule-pct');
    if(rm) fd.append('rule_so_mode',rm.value);
    if(rp) fd.append('rule_rbz_usd_pct',rp.value);
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
                    '<div class="bm-meta" style="font-weight:600">Loading split '+nxt.splitid+'…</div>';
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
    if(c[0]){c[0].innerHTML='<span class="rowmark done" title="Balanced"></span>';}
    if(c[1])c[1].innerHTML='<span class="badge badge-blue">'+d.batch+'</span>';
    // Mark row as Balanced in table
    var statusCell = row ? row.querySelector('.cell-status') : null;
    if(statusCell) statusCell.innerHTML='<span class="sp sp-b">Balanced</span>';
    if(row) row.setAttribute('data-so','0');
    if(c[15])c[15].innerHTML='<span class="fw-500">$'+parseFloat(d.gross).toFixed(2)+'</span>';
    if(c[18])c[18].innerHTML='<span class="net">'+parseFloat(d.net).toFixed(2)+'</span>';
    var pc=row.querySelector('td:last-child');
    if(pc)pc.innerHTML='<a href="print_sheet.php?bbid='+d.bbid+'" target="_blank" class="btn btn-ghost btn-sm">Print</a>';
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
                var scH=sc?'<code>'+xe(sc)+'</code>':'<span class="td-muted">-</span>';
                scc.innerHTML=scH+'<span class="ep">*</span>';
                scc.dataset.sc=sc;
            }
            var stc=document.getElementById('ss'+sbid);
            if(stc){stc.innerHTML=isRej?'<span class="badge badge-red">Rejected</span>':'<span class="badge badge-green">Sold</span>';}
            var br=document.getElementById('br'+sbid);
            if(br){br.classList.toggle('is-rej',isRej);}
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
document.getElementById('rev-head').addEventListener('keydown', function(e){
    if(e.key==='Enter'||e.key===' '){ e.preventDefault(); this.click(); }
});
document.getElementById('rev-head').addEventListener('click', function(){
    var b = document.getElementById('revbody');
    var lbl = document.getElementById('rev-toggle-lbl');
    var head = document.getElementById('rev-head');
    var open = b.style.display === 'none';
    b.style.display = open ? 'block' : 'none';
    lbl.textContent = open ? 'Hide' : 'Show';
    head.classList.toggle('open', open);
    head.setAttribute('aria-expanded', open ? 'true' : 'false');
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
            return '<tr>'
                + '<td><span class="badge badge-blue">' + xe(r.sale_batchid||'--') + '</span></td>'
                + '<td style="color:var(--accent-text);font-weight:500">' + xe(r.grower_num) + '</td>'
                + '<td>' + xe(r.name + ' ' + r.surname) + '</td>'
                + '<td class="r">' + xe(r.bales) + '</td>'
                + '<td class="r" style="font-weight:600">$' + parseFloat(r.gross).toFixed(2) + '</td>'
                + '<td style="color:var(--text-muted)">' + dt + '</td>'
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
            setTimeout(fwReload, 1800);
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
