<?php
/**
 * ajax.php — Central AJAX endpoint for the whole Flowork system
 * All responses: JSON { success, msg, data }
 */
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

function ok($msg = '', $data = []) { echo json_encode(['success'=>true, 'msg'=>$msg,'data'=>$data]); exit; }
function err($msg, $data = [])     { echo json_encode(['success'=>false,'msg'=>$msg,'data'=>$data]); exit; }
function esc($v) { global $conn; return $conn->real_escape_string(trim($v)); }

switch ($action) {

// ── Stop order: cancel/restore ────────────────────────────────────────────
case 'so_cancel': {
    $id  = (int)($_POST['id'] ?? 0);
    $src = $_POST['src'] ?? 'timb';
    $tbl = 'stoporders_timb';
    if ($src === 'internal') $tbl = 'internal_stoporders';
    elseif ($src === 'ext')   $tbl = 'stoporders';
    $conn->query("UPDATE $tbl SET active=0 WHERE id=$id");
    ok("Stop order cancelled.");
}
case 'so_restore': {
    $id  = (int)($_POST['id'] ?? 0);
    $src = $_POST['src'] ?? 'timb';
    $tbl = 'stoporders_timb';
    if ($src === 'internal') $tbl = 'internal_stoporders';
    elseif ($src === 'ext')   $tbl = 'stoporders';
    $conn->query("UPDATE $tbl SET active=1 WHERE id=$id");
    ok("Stop order restored.");
}
case 'so_delete': {
    $id  = (int)($_POST['id'] ?? 0);
    $src = $_POST['src'] ?? 'timb';
    $tbl = 'stoporders_timb';
    if ($src === 'internal') $tbl = 'internal_stoporders';
    elseif ($src === 'ext')   $tbl = 'stoporders';
    $conn->query("DELETE FROM $tbl WHERE id=$id");
    ok("Stop order deleted.");
}

// ── Mark sales sheet printed ──────────────────────────────────────────────
case 'mark_printed': {
    $bbid = (int)($_POST['bbid'] ?? 0);
    if ($bbid) $conn->query("UPDATE balanced_bales SET printed=1 WHERE id=$bbid");
    ok("Marked as printed.");
}

// ── Grower search (live) ──────────────────────────────────────────────────
case 'grower_search': {
    $q = esc($_GET['q'] ?? '');
    if (strlen($q) < 2) ok('', ['growers'=>[]]);
    $rows = $conn->query("
        SELECT g.id, g.grower_num, CONCAT(g.name,' ',g.surname) grower_name, g.province
        FROM growers g
        WHERE g.selling_pointid=$spid
          AND (g.grower_num LIKE '%$q%' OR g.name LIKE '%$q%' OR g.surname LIKE '%$q%')
        LIMIT 12
    ")->fetch_all(MYSQLI_ASSOC);
    ok('', ['growers'=>$rows]);
}

// ── Payment: mark paid (individual) ──────────────────────────────────────
case 'mark_payment_paid': {
    require_once "pay_schema.php";
    $role = pay_role($conn, $userid);
    if (!pay_can($role,'create_payment')) err('Access denied.');
    $tgid   = (int)($_POST['tgid']       ?? 0);
    $date   = esc($_POST['sell_date']    ?? '');
    $cur    = ($_POST['currency'] ?? '') === 'zig' ? 'zig' : 'usd';
    $method = esc($_POST['pay_method']   ?? '');
    $ref    = esc($_POST['pay_ref']      ?? '');
    $notes  = esc($_POST['pay_notes']    ?? '');
    $amount = (float)($_POST['pay_amount'] ?? 0);
    if (!$tgid || !$date || !$method) err('Missing required fields.');
    $closed = $conn->query("SELECT id FROM payment_day_close WHERE sell_date='$date' AND selling_pointid=$spid LIMIT 1")->num_rows;
    if ($closed) err('Payment day is closed.');
    $now = date('Y-m-d H:i:s');
    $ex  = $conn->query("SELECT id FROM payment_status WHERE transporter_growersid=$tgid AND sell_date='$date' AND currency='$cur' LIMIT 1")->fetch_assoc();
    if ($ex) {
        $psid = (int)$ex['id'];
        $conn->query("UPDATE payment_status SET status='paid',payment_method='$method',reference_number='$ref',paid_at='$now',paid_by=$userid,notes='$notes',amount_paid=$amount WHERE id=$psid");
    } else {
        $conn->query("INSERT INTO payment_status (transporter_growersid,sell_date,currency,net_amount,amount_paid,status,payment_method,reference_number,paid_at,paid_by,notes) VALUES ($tgid,'$date','$cur',$amount,$amount,'paid','$method','$ref','$now',$userid,'$notes')");
        $psid = (int)$conn->insert_id;
    }
    $ref = esc(flowork_assign_receipt($conn, $psid, $method));
    pay_log($conn,'payment_status',$psid,$userid,'paid',$cur,$amount,$method,$ref,$notes);
    ok(strtoupper($cur)." payment confirmed via $method".($ref?" #$ref":"."), ['tgid'=>$tgid,'status'=>'paid','method'=>$method,'ref'=>$ref]);
}

// ── Selling point info ────────────────────────────────────────────────────
case 'selling_point_info': {
    $r = $conn->query("SELECT name,floor_code FROM selling_point WHERE id=$spid LIMIT 1")->fetch_assoc();
    ok('', $r ?? []);
}

// ── Exchange rate ─────────────────────────────────────────────────────────
case 'exchange_rate': {
    $r = $conn->query("SELECT amount FROM exchange_rate ORDER BY id DESC LIMIT 1")->fetch_assoc();
    ok('', ['rate' => (float)($r['amount'] ?? 0)]);
}

// ── KPI refresh for a date ────────────────────────────────────────────────
case 'day_kpis': {
    $date = esc($_GET['date'] ?? date('Y-m-d'));
    $sold = $conn->query("
        SELECT COUNT(DISTINCT sb.id) bales, COALESCE(SUM(bm.mass),0) mass,
               COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) usd
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        WHERE DATE(sb.created_at)='$date' AND g.selling_pointid=$spid
    ")->fetch_assoc();
    $paid = $conn->query("
        SELECT COUNT(*) cnt, COALESCE(SUM(net_amount),0) total
        FROM payment_status ps
        JOIN transporter_growers tg ON tg.id=ps.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        WHERE g.selling_pointid=$spid AND ps.sell_date='$date' AND ps.status IN ('paid','released') AND ps.currency='usd'
    ")->fetch_assoc();
    ok('', [
        'bales'     => (int)$sold['bales'],
        'mass'      => (float)$sold['mass'],
        'gross_usd' => (float)$sold['usd'],
        'paid_cnt'  => (int)$paid['cnt'],
        'paid_usd'  => (float)$paid['total'],
    ]);
}

// ── Batch action (forward to pay_ajax.php) ────────────────────────────────
case 'batch_action':
case 'create_batch':
case 'confirm_payment':
case 'get_batches':
case 'get_batch_detail':
case 'get_growers':
case 'get_dashboard': {
    // These are handled by pay_ajax.php — proxy
    require_once "pay_schema.php";
    ob_start();
    include "pay_ajax.php";
    $out = ob_get_clean();
    echo $out;
    exit;
}

default:
    err("Unknown action: " . htmlspecialchars($action));
}
