<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "audit.php";
require_once "includes/net_amount.php";
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

// Insert daily_totals
$conn->query("INSERT INTO daily_totals (userid,balanced_balesid,exchange_rateid,mass,usd_value,zim_value,laid_bales,sold_bales,rejected_bales,average_price,created_at,datetime)
    VALUES ($userid,$balanced_balesid,$exchange_rateid,$total_mass,$gross_amount,$zim_value,$number_of_bales,$sold_bales,$rejected_bales,$average_price,'$created_at',NOW())");

// Apply statutory charges
$charges_q = $conn->query("
    SELECT gc.id, gc.amount, gc.charge_typeid, gc.creditor_no, gc.statutoryid,
           s.currencyid, s.both_currencies, s.description charge_name,
           ct.description charge_type_desc
    FROM grower_charges gc
    JOIN statutory s ON s.id=gc.statutoryid
    JOIN charge_type ct ON ct.id=gc.charge_typeid
    WHERE gc.selling_pointid=$spid AND gc.active=1
");

if ($charges_q && $charges_q !== true) {
    while ($ch = $charges_q->fetch_assoc()) {
        $ch_usd = 0; $ch_zig = 0;
        $ct = $ch['charge_type_desc'];
        $amt= (float)$ch['amount'];
        
        // Skip Bank Charge (statutoryid = 7) for now, compute it later on net payout
        if ((int)$ch['statutoryid'] === 7) {
            $bank_charge_row = $ch;
            continue;
        }
        
        if (stripos($ct,'Value') !== false) {
            $ch_usd = $gross_amount * ($amt / 100);
            $ch_zig = $zim_value   * ($amt / 100);
        } elseif (stripos($ct,'Mass') !== false) {
            $ch_usd = $total_mass * $amt;
            $ch_zig = $total_mass * $amt * $exchange_rate_amount;
        } elseif (stripos($ct,'Bale') !== false) {
            $ch_usd = $number_of_bales * $amt;
            $ch_zig = $number_of_bales * $amt * $exchange_rate_amount;
        } else {
            $ch_usd = $amt;
            $ch_zig = $amt * $exchange_rate_amount;
        }
        $gc_id = (int)$ch['id'];
        $conn->query("INSERT INTO balanced_bales_charges (userid,grower_chargeid,balanced_balesid,usd_value,zim_value,datetime)
            VALUES ($userid,$gc_id,$balanced_balesid,$ch_usd,$ch_zig,NOW())");
    }
}

// Calculate total charges in USD (excluding Bank Charge, which is not in DB yet)
$total_charges_usd_q = $conn->query("SELECT COALESCE(SUM(usd_value),0) t FROM balanced_bales_charges WHERE balanced_balesid=$balanced_balesid");
$total_charges_usd   = ($total_charges_usd_q && $total_charges_usd_q !== true) ? (float)$total_charges_usd_q->fetch_assoc()['t'] : 0;
$total_charges_zig   = $total_charges_usd * $exchange_rate_amount;

// Internal stop order deductions
$iso_q = $conn->query("
    SELECT isoa.id, isoa.amount, isoa.charge_typeid, iso.description
    FROM internal_stop_order iso
    JOIN internal_stop_order_amount isoa ON isoa.internal_stop_orderid=iso.id
    WHERE iso.selling_pointid=$spid AND isoa.active=1 AND isoa.processed=0
      AND (isoa.growerid=$growerid OR isoa.growerid IS NULL)
    ORDER BY isoa.priority
");
$total_iso = 0;
if ($iso_q && $iso_q !== true) {
    while ($iso = $iso_q->fetch_assoc()) {
        $iso_amt = (float)$iso['amount'];
        // Create split_internal_stop_order
        $conn->query("INSERT INTO split_internal_stop_order (userid,internal_stop_order_amountid,transporter_growersid,splitsid,quantity,datetime)
            VALUES ($userid,{$iso['id']},$transporter_growersid,$splitid,1,NOW())");
        $siso_id = (int)$conn->insert_id;
        // Deducted amounts
        $conn->query("INSERT INTO internal_stop_order_deducted_amounts (userid,balanced_balesid,split_internal_stop_orderid,amount,datetime)
            VALUES ($userid,$balanced_balesid,$siso_id,$iso_amt,NOW())");
        $total_iso += $iso_amt;
    }
}

// Calculate net payout before bank charge
$usd_before_bc = max(0, $gross_amount - $total_charges_usd - $total_iso);
$zig_before_bc = $usd_before_bc * $exchange_rate_amount;

$bc_usd = 0; $bc_zig = 0;
if (isset($bank_charge_row)) {
    $bc_rate = (float)$bank_charge_row['amount'];
    $bc_usd = max(0, $usd_before_bc * $bc_rate);
    $bc_zig = max(0, $zig_before_bc * $bc_rate);
    
    $conn->query("INSERT INTO balanced_bales_charges (userid,grower_chargeid,balanced_balesid,usd_value,zim_value,datetime)
        VALUES ($userid,{$bank_charge_row['id']},$balanced_balesid,$bc_usd,$bc_zig,NOW())");
}

// Net amounts
$net_usd = max(0, $usd_before_bc - $bc_usd);
$net_zig = max(0, $zig_before_bc - $bc_zig);

flowork_save_net($conn, $userid, $balanced_balesid, $net_usd, $net_zig, $created_at);

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
