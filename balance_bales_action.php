<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "audit.php";
require_once "includes/stoporder_deduct_helper.php";
header('Content-Type: application/json');
require_login();

$spid    = (int)($_SESSION['selling_pointid'] ?? 0);
$userid  = current_userid();
$data    = json_decode(file_get_contents("php://input"), true) ?? $_POST;

$response = [];

$transporter_growersid = (int)($data['transporter_growersid'] ?? 0);
$splitid               = (int)($data['splitid'] ?? 1);
$growerid              = (int)($data['growerid'] ?? 0);
$created_at            = date('Y-m-d');

if (!$transporter_growersid || !$growerid) {
    echo json_encode(['response'=>'Missing parameters']); exit;
}
if (!flowork_on_floor($conn, 'tg', $transporter_growersid, $spid) || !flowork_on_floor($conn, 'grower', $growerid, $spid)) {
    echo json_encode(['response'=>'Delivery not found on this floor']); exit;
}

// Check grower is booked
$booked_q = $conn->query("SELECT booked FROM transporter_growers WHERE id=$transporter_growersid LIMIT 1");
$booked   = ($booked_q && $booked_q !== true) ? (int)$booked_q->fetch_assoc()['booked'] : 0;
if (!$booked) {
    echo json_encode(['response'=>'Grower is not booked. Book the grower first.']); exit;
}

// Check not already balanced
$bal_q = $conn->query("SELECT id FROM balanced_bales WHERE transporter_growersid=$transporter_growersid AND splitid=$splitid LIMIT 1");
if ($bal_q && $bal_q->num_rows > 0) {
    echo json_encode(['response'=>'Already balanced']); exit;
}

// Load all sold bales for this grower/split
$bales_q = $conn->query("
    SELECT bj.id, bm.mass, sb.price, sb.sale_code,
           sd.id selling_dateid, sd.description sell_date,
           er.amount exchange_rate_amount, er.id exchange_rateid,
           tg.bales bales_booked
    FROM bale_junus bj
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN tickets tk ON tk.bale_junusid=bj.id
    JOIN sold_bales sb ON sb.ticketsid=tk.id
    JOIN start_of_day sd ON sd.id=tk.start_of_dayid
    JOIN exchange_rate er ON er.start_of_dayid=sd.id
    JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
    WHERE bj.transporter_growersid=$transporter_growersid AND bj.splitid=$splitid
");

if (!$bales_q || $bales_q->num_rows === 0) {
    echo json_encode(['response'=>'No sold bales found for this grower']); exit;
}

$bales = $bales_q->fetch_all(MYSQLI_ASSOC);
$number_of_bales  = count($bales);
$gross_amount     = 0;
$sold_bales       = 0;
$rejected_bales   = 0;
$total_mass       = 0;
$all_prices       = 0;
$exchange_rateid  = 0;
$exchange_rate_amount = 0;
$selling_dateid   = 0;
$bales_booked     = 0;

foreach ($bales as $b) {
    $price = (float)$b['price'];
    // Prices over 10 are in cents per kg, divide by 100
    $price_per_kg = $price > 10 ? $price / 100 : $price;
    if (strtoupper(trim($b['sale_code'])) === 'R' || empty($b['price'])) {
        $rejected_bales++;
    } else {
        $mass = (float)$b['mass'];
        $gross_amount += $mass * $price_per_kg;
        $all_prices   += $price_per_kg;
        $total_mass   += $mass;
        $sold_bales++;
    }
    $exchange_rateid      = (int)$b['exchange_rateid'];
    $exchange_rate_amount = (float)$b['exchange_rate_amount'];
    $selling_dateid       = (int)$b['selling_dateid'];
    $bales_booked         = (int)$b['bales_booked'];
    $sell_date_str        = $b['sell_date'];
}

$average_price = $sold_bales > 0 ? $all_prices / $sold_bales : 0;
$zim_value     = $gross_amount * $exchange_rate_amount;

if ($number_of_bales === 0) {
    echo json_encode(['response'=>'No bales processed']); exit;
}

// Insert balanced_bales
$conn->query("INSERT INTO balanced_bales (userid,splitid,growerid,transporter_growersid,selling_pointid,sell_date,created_at,datetime)
    VALUES ($userid,$splitid,$growerid,$transporter_growersid,$spid,'$created_at','$created_at',NOW())");
$balanced_balesid = (int)$conn->insert_id;

if (!$balanced_balesid) {
    echo json_encode(['response'=>'Failed to create balanced_bales: '.$conn->error]); exit;
}

// Totals, charges, stop-order deductions, RBZ split and net come from the one
// shared pipeline the Balance page uses (includes/stoporder_deduct_helper.php).
// This endpoint used to apply its own formula with no RBZ split and no TIMB
// stop orders, so the same delivery could get a different net depending on
// which screen balanced it.
$calc = flowork_recalculate_balance_batch($conn, $balanced_balesid, $spid, $userid);
if (!$calc) {
    $conn->query("DELETE FROM balanced_bales WHERE id=$balanced_balesid");
    echo json_encode(['response'=>'Recalculation failed; nothing was balanced.']); exit;
}
$net_usd = (float)$calc['net_usd'];
$net_zig = (float)$calc['net_zig'];
$_c = $conn->query("SELECT COALESCE(SUM(usd_value),0) t FROM balanced_bales_charges WHERE balanced_balesid=$balanced_balesid");
$total_charges_usd = ($_c && $_c !== true) ? (float)$_c->fetch_assoc()['t'] : 0;
$_i = $conn->query("SELECT COALESCE(SUM(amount),0) t FROM internal_stop_order_deducted_amounts WHERE balanced_balesid=$balanced_balesid");
$total_iso = ($_i && $_i !== true) ? (float)$_i->fetch_assoc()['t'] : 0;

// Mark stop orders as processed
$conn->query("UPDATE balanced_bales SET stoporder_processed=1 WHERE id=$balanced_balesid");

audit_log($conn,$spid,$userid,'balanced_bales','balance',$balanced_balesid,null,[
    'growerid'=>$growerid,'gross'=>$gross_amount,'net'=>$net_usd,'charges'=>$total_charges_usd
]);

echo json_encode([
    'response' => 'success',
    'balanced_balesid' => $balanced_balesid,
    'gross_usd'  => round($gross_amount,2),
    'charges_usd'=> round($total_charges_usd,2),
    'iso_usd'    => round($total_iso,2),
    'net_usd'    => round($net_usd,2),
    'net_zig'    => round($net_zig,2),
    'bales'      => $number_of_bales,
    'sold'       => $sold_bales,
    'rejected'   => $rejected_bales,
]);
