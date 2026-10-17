<?php
/**
 * pay_ajax.php — Central AJAX endpoint for all payment module actions
 * All responses return JSON: { success, msg, data }
 */
session_start();
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/conn.php";
require_once dirname(__DIR__) . "/pay_schema.php";
require_once __DIR__ . "/net_amount.php";

header('Content-Type: application/json; charset=utf-8');

// JSON refusals instead of require_login()'s redirects: this endpoint lives in
// includes/, so its relative "login.php" / "dashboard.php" Locations would 404.
// The rights check is here rather than in the auth.php rights map because
// it covers both the page scripts and any direct call.
if (empty($_SESSION['userid'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'msg' => 'Not logged in.', 'data' => []]);
    exit;
}
require_login();
if (!has_right('payments')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Access Denied: you do not have permissions for the Payments module.', 'data' => []]);
    exit;
}

$userid  = current_userid();
$spid    = (int)($_SESSION['selling_pointid'] ?? 0);
$action  = $_POST['action'] ?? $_GET['action'] ?? '';

pay_schema($conn, $spid);
$my_role = pay_role($conn, $userid);

function json_ok($msg, $data = []) {
    echo json_encode(['success' => true,  'msg' => $msg, 'data' => $data]);
    exit;
}
function json_err($msg, $data = []) {
    echo json_encode(['success' => false, 'msg' => $msg, 'data' => $data]);
    exit;
}
function esc($conn, $v) { return $conn->real_escape_string(trim($v)); }

// ── Is day closed? ────────────────────────────────────────────────────────
function is_day_closed($conn, $date, $spid) {
    return $conn->query("SELECT id FROM payment_day_close WHERE sell_date='$date' AND selling_pointid=$spid LIMIT 1")->num_rows > 0;
}

// ── Floor ownership ───────────────────────────────────────────────────────
/** Is this delivery's grower on the user's current floor? */
function pay_tg_on_floor($conn, int $tgid, int $spid): bool {
    $q = $conn->query("SELECT 1 FROM transporter_growers tg JOIN growers g ON g.id=tg.growerid
        WHERE tg.id=$tgid AND (g.selling_pointid=$spid OR tg.selling_pointid=$spid) LIMIT 1");
    return $q && $q !== true && $q->num_rows > 0;
}
/** Does every grower in this batch belong to the user's current floor? */
function pay_batch_on_floor($conn, int $bid, int $spid): bool {
    $q = $conn->query("SELECT COUNT(*) n, SUM(g.selling_pointid=$spid OR tg.selling_pointid=$spid) mine
        FROM payment_batch_item pbi
        JOIN transporter_growers tg ON tg.id=pbi.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        WHERE pbi.payment_batchid=$bid");
    $r = ($q && $q !== true) ? $q->fetch_assoc() : null;
    return $r && (int)$r['n'] > 0 && (int)$r['n'] === (int)$r['mine'];
}
/** Stored net owed to this delivery's grower for deliveries balanced on $date. */
function pay_stored_net($conn, int $tgid, string $date, string $currency, bool $processed_only = false): float {
    $col = $currency === 'zig' ? 'zim_net_value' : 'usd_net_value';
    $extra = $processed_only ? ' AND bb.stoporder_processed=1' : '';
    $q = $conn->query("SELECT COALESCE(SUM(dtn.$col),0) net FROM balanced_bales bb " . flowork_net_join() . "
        WHERE bb.transporter_growersid=$tgid AND DATE(bb.created_at)='$date'$extra");
    return ($q && $q !== true) ? (float)$q->fetch_assoc()['net'] : 0.0;
}

switch ($action) {

    // ── Confirm individual payment ────────────────────────────────────────
    case 'confirm_payment': {
        if (!pay_can($my_role,'create_payment')) json_err('Access denied.');
        $tgid     = (int)($_POST['tgid'] ?? 0);
        $date     = esc($conn, $_POST['sell_date'] ?? '');
        $currency = ($_POST['currency'] ?? '') === 'zig' ? 'zig' : 'usd';
        $method   = esc($conn, $_POST['pay_method']  ?? '');
        $ref      = esc($conn, $_POST['pay_ref']     ?? '');
        $notes    = esc($conn, $_POST['pay_notes']   ?? '');
        if (!$tgid || !$date || !$method) json_err('Grower, date and payment method required.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_err('Sell date must be YYYY-MM-DD.');
        if (!pay_tg_on_floor($conn, $tgid, $spid)) json_err('This grower is not on your current floor.');
        if (is_day_closed($conn, $date, $spid)) json_err('Payment day is closed.');
        // The amount is the stored net (single source), never a figure posted by the browser.
        $amount = pay_stored_net($conn, $tgid, $date, $currency);
        $flags = pay_risk_check($conn, $tgid, $date, $amount, $currency);
        $flags_str = esc($conn, implode(',', $flags));
        $now = date('Y-m-d H:i:s');
        $ex = $conn->query("SELECT id, status FROM payment_status WHERE transporter_growersid=$tgid AND sell_date='$date' AND currency='$currency' LIMIT 1")->fetch_assoc();
        if ($ex && in_array($ex['status'], ['paid', 'released'], true)) {
            json_err('This payment is already recorded as ' . $ex['status'] . '. Reverse it first if it needs correcting.');
        }
        if ($ex) {
            $psid = (int)$ex['id'];
            $conn->query("UPDATE payment_status SET status='paid',net_amount=$amount,payment_method='$method',reference_number='$ref',paid_at='$now',paid_by=$userid,notes='$notes',amount_paid=$amount,risk_flags='$flags_str' WHERE id=$psid");
        } else {
            $conn->query("INSERT INTO payment_status (transporter_growersid,sell_date,currency,net_amount,amount_paid,status,payment_method,reference_number,paid_at,paid_by,notes,risk_flags) VALUES ($tgid,'$date','$currency',$amount,$amount,'paid','$method','$ref','$now',$userid,'$notes','$flags_str')");
            $psid = (int)$conn->insert_id;
        }
        $ref = $conn->real_escape_string(flowork_assign_receipt($conn, $psid, $method));
        pay_log($conn,'payment_status',$psid,$userid,'paid',$currency,$amount,$method,$ref,$notes,implode(',',$flags));
        $cur = strtoupper($currency);
        json_ok("$cur payment confirmed via $method".($ref?" #$ref":"").".", [
            'tgid'   => $tgid,
            'status' => 'paid',
            'method' => $method,
            'ref'    => $ref,
            'flags'  => $flags,
        ]);
    }

    // ── Create batch ──────────────────────────────────────────────────────
    case 'create_batch': {
        if (!pay_can($my_role,'create_batch')) json_err('Access denied.');
        $date     = esc($conn, $_POST['sell_date'] ?? '');
        $currency = ($_POST['currency'] ?? '') === 'zig' ? 'zig' : 'usd';
        $method   = esc($conn, $_POST['batch_method'] ?? '');
        $ref      = esc($conn, $_POST['batch_ref']    ?? '');
        $notes    = esc($conn, $_POST['batch_notes']  ?? '');
        $tgids    = array_filter(array_map('intval', $_POST['batch_tgids'] ?? []));
        if (!$date || !$method || empty($tgids)) json_err('Date, method and growers required.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_err('Sell date must be YYYY-MM-DD.');
        if (is_day_closed($conn, $date, $spid)) json_err('Payment day is closed.');
        $tgids = array_values(array_filter($tgids, fn($t) => pay_tg_on_floor($conn, $t, $spid)));
        if (empty($tgids)) json_err('None of the selected growers are on your current floor.');
        $conn->query("INSERT INTO payment_batch (sell_date,currency,payment_method,batch_ref,notes,created_by,status) VALUES ('$date','$currency','$method','$ref','$notes',$userid,'draft')");
        $bid = (int)$conn->insert_id;
        $now = date('Y-m-d H:i:s');
        $total = 0; $risk_count = 0;
        foreach ($tgids as $tgid) {
            // Net in the batch's own currency (this used the USD net for ZiG batches too).
            $na = pay_stored_net($conn, $tgid, $date, $currency, true);
            $flags = pay_risk_check($conn, $tgid, $date, $na, $currency);
            if (!empty($flags)) $risk_count++;
            $fs = esc($conn, implode(',', $flags));
            $conn->query("INSERT INTO payment_batch_item (payment_batchid,transporter_growersid,net_amount,currency) VALUES ($bid,$tgid,$na,'$currency')");
            $ex = $conn->query("SELECT id, status FROM payment_status WHERE transporter_growersid=$tgid AND sell_date='$date' AND currency='$currency' LIMIT 1")->fetch_assoc();
            // Never put a paid or released payment back to pending.
            if ($ex && !in_array($ex['status'], ['paid', 'released'], true)) $conn->query("UPDATE payment_status SET status='pending',risk_flags='$fs' WHERE id={$ex['id']}");
            else $conn->query("INSERT INTO payment_status (transporter_growersid,sell_date,currency,net_amount,status,risk_flags) VALUES ($tgid,'$date','$currency',$na,'pending','$fs')");
            $total += $na;
        }
        pay_log($conn,'payment_batch',$bid,$userid,'created',$currency,$total,$method,$ref,"Batch of ".count($tgids)." growers");
        json_ok("Batch #$bid created — ".count($tgids)." growers, ".strtoupper($currency)." ".number_format($total,2).($risk_count?" ($risk_count with risk flags)":"").".", [
            'batch_id'    => $bid,
            'grower_count'=> count($tgids),
            'total'       => $total,
            'risk_count'  => $risk_count,
        ]);
    }

    // ── Batch workflow transition ──────────────────────────────────────────
    case 'batch_action': {
        $bid        = (int)($_POST['bid'] ?? 0);
        $transition = $_POST['transition'] ?? '';
        if (!$bid || !$transition) json_err('Missing parameters.');
        $transitions = [
            'review'  => ['reviewed', 'review_batch',  'reviewed_by', 'reviewed_at', 'draft'],
            'approve' => ['approved', 'approve_batch',  'approved_by', 'approved_at', 'reviewed'],
            'release' => ['released', 'release_batch',  'released_by', 'released_at', 'approved'],
            'lock'    => ['locked',   'lock_batch',     'released_by', 'locked_at',   'released'],
        ];
        if (!isset($transitions[$transition])) json_err('Invalid transition.');
        [$new_st, $perm, $by_col, $at_col, $req_st] = $transitions[$transition];
        if (!pay_can($my_role, $perm)) json_err('Your role cannot perform this action.');
        $batch = $conn->query("SELECT id, status, sell_date FROM payment_batch WHERE id=$bid LIMIT 1")->fetch_assoc();
        if (!$batch || !pay_batch_on_floor($conn, $bid, $spid)) json_err('Batch not found.');
        // The batch's own date decides which payments it settles, not a posted one.
        $date = esc($conn, $batch['sell_date']);
        if (is_day_closed($conn, $date, $spid)) json_err('Day is closed.');
        if ($batch['status'] !== $req_st) json_err("Batch must be in '$req_st' status first (currently '{$batch['status']}').");
        $now = date('Y-m-d H:i:s');
        $conn->query("UPDATE payment_batch SET status='$new_st',$by_col=$userid,$at_col='$now' WHERE id=$bid");
        // On release: mark all items paid
        if ($transition === 'release') {
            $items = $conn->query("SELECT pbi.transporter_growersid,pbi.net_amount,pbi.currency,pb.payment_method,pb.batch_ref FROM payment_batch_item pbi JOIN payment_batch pb ON pb.id=pbi.payment_batchid WHERE pbi.payment_batchid=$bid")->fetch_all(MYSQLI_ASSOC);
            foreach ($items as $it) {
                $tg=(int)$it['transporter_growersid']; $na=(float)$it['net_amount'];
                $m=esc($conn,$it['payment_method']); $r=esc($conn,$it['batch_ref']); $cu=$it['currency'];
                $ex=$conn->query("SELECT id, status FROM payment_status WHERE transporter_growersid=$tg AND sell_date='$date' AND currency='$cu' LIMIT 1")->fetch_assoc();
                if ($ex && in_array($ex['status'], ['paid', 'released'], true)) continue; // already settled, e.g. paid individually
                if ($ex) { $conn->query("UPDATE payment_status SET status='released',payment_method='$m',reference_number='$r',released_by=$userid,released_at='$now',amount_paid=$na WHERE id={$ex['id']}"); $rel_psid = (int)$ex['id']; }
                else { $conn->query("INSERT INTO payment_status (transporter_growersid,sell_date,currency,net_amount,amount_paid,status,payment_method,reference_number,released_by,released_at) VALUES ($tg,'$date','$cu',$na,$na,'released','$m','$r',$userid,'$now')"); $rel_psid = (int)$conn->insert_id; }
                flowork_assign_receipt($conn, $rel_psid, $it['payment_method']);
            }
        }
        pay_log($conn,'payment_batch',$bid,$userid,$transition.'_batch');
        json_ok("Batch #$bid ".ucfirst($new_st).".", ['batch_id'=>$bid,'status'=>$new_st]);
    }

    // ── Load growers for batch creation panel ─────────────────────────────
    case 'get_growers': {
        $date = esc($conn, $_GET['date'] ?? date('Y-m-d'));
        $rows = $conn->query("
            SELECT tg.id AS tgid, g.grower_num, CONCAT(g.name,' ',g.surname) grower_name,
                   COALESCE(SUM(dtn.usd_net_value),0) net_usd,
                   COALESCE(ps.status,'pending') status_usd,
                   gb.bank_name, gb.account_num, gb.verified,
                   COALESCE(ps.risk_flags,'') risk_flags
            FROM balanced_bales bb
            JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
            JOIN growers g ON g.id=tg.growerid
            JOIN transporter t ON t.id=tg.transporterid
            ".flowork_net_join()."
            LEFT JOIN payment_status ps ON ps.transporter_growersid=tg.id AND ps.sell_date='$date' AND ps.currency='usd'
            LEFT JOIN grower_banking gb ON gb.growerid=g.id
            WHERE g.selling_pointid=$spid AND DATE(bb.created_at)='$date' AND bb.stoporder_processed=1
            GROUP BY tg.id ORDER BY g.grower_num
        ")->fetch_all(MYSQLI_ASSOC);
        json_ok('', ['growers'=>$rows]);
    }

    // ── Load batch list for date ──────────────────────────────────────────
    case 'get_batches': {
        $date = esc($conn, $_GET['date'] ?? date('Y-m-d'));
        $rows = $conn->query("
            SELECT pb.id,pb.payment_method,pb.status,pb.batch_ref,pb.currency,pb.created_at,
                   u.username, COUNT(pbi.id) cnt, COALESCE(SUM(pbi.net_amount),0) total
            FROM payment_batch pb
            JOIN users u ON u.id=pb.created_by
            LEFT JOIN payment_batch_item pbi ON pbi.payment_batchid=pb.id
            WHERE pb.sell_date='$date'
            GROUP BY pb.id ORDER BY pb.id DESC
        ")->fetch_all(MYSQLI_ASSOC);
        json_ok('', ['batches'=>$rows]);
    }

    // ── Load batch detail ─────────────────────────────────────────────────
    case 'get_batch_detail': {
        $bid = (int)($_GET['bid'] ?? 0);
        if (!$bid) json_err('No batch ID.');
        $batch = $conn->query("SELECT pb.*,u.username FROM payment_batch pb JOIN users u ON u.id=pb.created_by WHERE pb.id=$bid LIMIT 1")->fetch_assoc();
        if (!$batch) json_err('Batch not found.');
        $items = $conn->query("SELECT pbi.*,g.grower_num,CONCAT(g.name,' ',g.surname) grower_name,g.phone,gb.bank_name,gb.account_num,gb.account_name,gb.branch,gb.verified,COALESCE(ps.risk_flags,'') risk_flags FROM payment_batch_item pbi JOIN transporter_growers tg ON tg.id=pbi.transporter_growersid JOIN growers g ON g.id=tg.growerid LEFT JOIN grower_banking gb ON gb.growerid=g.id LEFT JOIN payment_status ps ON ps.transporter_growersid=tg.id AND ps.currency=pbi.currency WHERE pbi.payment_batchid=$bid ORDER BY g.grower_num")->fetch_all(MYSQLI_ASSOC);
        json_ok('', ['batch'=>$batch,'items'=>$items]);
    }

    // ── Dashboard KPIs ────────────────────────────────────────────────────
    case 'get_dashboard': {
        $date = esc($conn, $_GET['date'] ?? date('Y-m-d'));
        $payable = $conn->query("SELECT COUNT(DISTINCT tg.id) growers,COALESCE(SUM(dtn.usd_net_value),0) net_usd FROM balanced_bales bb JOIN transporter_growers tg ON tg.id=bb.transporter_growersid JOIN growers g ON g.id=tg.growerid ".flowork_net_join()." WHERE g.selling_pointid=$spid AND DATE(bb.created_at)='$date' AND bb.stoporder_processed=1")->fetch_assoc();
        $paid = $conn->query("SELECT COUNT(*) cnt,COALESCE(SUM(net_amount),0) total FROM payment_status ps JOIN transporter_growers tg ON tg.id=ps.transporter_growersid JOIN growers g ON g.id=tg.growerid WHERE g.selling_pointid=$spid AND ps.sell_date='$date' AND ps.currency='usd' AND ps.status IN ('paid','released')")->fetch_assoc();
        json_ok('', [
            'payable_growers' => (int)$payable['growers'],
            'payable_usd'     => (float)$payable['net_usd'],
            'paid_growers'    => (int)$paid['cnt'],
            'paid_usd'        => (float)$paid['total'],
            'pending_usd'     => max(0, (float)$payable['net_usd'] - (float)$paid['total']),
        ]);
    }

    default:
        json_err("Unknown action: $action");
}
