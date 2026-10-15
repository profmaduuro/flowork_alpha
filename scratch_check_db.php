<?php
require_once __DIR__ . '/includes/admin_gate.php';
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli("127.0.0.1", "flowork", "flowork_pass", "king", 3307);

function simulate_random_sale($conn) {
    // 1. Find or create an unsold ticket
    $t_res = $conn->query("SELECT t.id, t.barcode FROM tickets t LEFT JOIN sold_bales sb ON sb.ticketsid = t.id WHERE sb.id IS NULL LIMIT 1");
    if ($t_res && $t_res->num_rows > 0) {
        $ticket = $t_res->fetch_assoc();
        $ticket_id = (int)$ticket['id'];
        $barcode = $ticket['barcode'];
        echo "Using existing unsold ticket: ID $ticket_id, Barcode $barcode\n";
    } else {
        // Create a new simulated ticket
        $ref_res = $conn->query("SELECT bale_junusid, start_of_dayid FROM tickets ORDER BY RAND() LIMIT 1");
        if (!$ref_res || $ref_res->num_rows === 0) {
            return ["ok" => false, "err" => "No reference tickets found to clone."];
        }
        $ref = $ref_res->fetch_assoc();
        $bale_junusid = (int)$ref['bale_junusid'];
        $start_of_dayid = (int)$ref['start_of_dayid'];
        $barcode = "TS" . rand(100000, 999999);
        $now_str = date('Y-m-d H:i:s');
        
        $ok = $conn->query("INSERT INTO tickets (userid, bale_junusid, barcode, start_of_dayid, created_at) VALUES (1, $bale_junusid, '$barcode', $start_of_dayid, '$now_str')");
        if (!$ok) {
            return ["ok" => false, "err" => "Failed to create simulated ticket: " . $conn->error];
        }
        $ticket_id = (int)$conn->insert_id;
        echo "Created new simulated ticket: ID $ticket_id, Barcode $barcode\n";
    }
    
    // 2. Select random TIMB grade
    $tg_res = $conn->query("SELECT id FROM timb_grades WHERE active=1 ORDER BY RAND() LIMIT 1");
    $timb_grade_id = $tg_res ? (int)$tg_res->fetch_assoc()['id'] : 1;
    
    // 3. Select random buyer grade
    $bg_res = $conn->query("SELECT id FROM buyer_grades ORDER BY RAND() LIMIT 1");
    $buyer_grade_id = $bg_res ? (int)$bg_res->fetch_assoc()['id'] : 1;
    
    // 4. Generate random price (represented in cents, e.g. $1.50 to $5.50)
    $price_cents = rand(150, 550);
    $now_str = date('Y-m-d H:i:s');
    
    // 5. Insert sale
    $ok = $conn->query("INSERT INTO sold_bales (userid, ticketsid, timb_gradesid, buyer_gradesid, price, created_at, s2_confirmed) VALUES (1, $ticket_id, $timb_grade_id, $buyer_grade_id, '$price_cents', '$now_str', 1)");
    if (!$ok) {
        return ["ok" => false, "err" => "Failed to insert simulated sale: " . $conn->error];
    }
    
    $sale_id = (int)$conn->insert_id;
    return [
        "ok" => true,
        "sale_id" => $sale_id,
        "barcode" => $barcode,
        "price" => $price_cents / 100.0
    ];
}

for ($i = 0; $i < 5; $i++) {
    echo "--- SALE " . ($i + 1) . " ---\n";
    $res = simulate_random_sale($conn);
    print_r($res);
    echo "\n";
}
