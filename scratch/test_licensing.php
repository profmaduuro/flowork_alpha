<?php
require_once __DIR__ . '/../includes/admin_gate.php';
/**
 * scratch/test_licensing.php - Command Line Test script for subscription management
 */
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . "/../includes/conn.php";

echo "=============================================\n";
echo "  FLOWORK LICENSE SYSTEM INTEGRATION TEST  \n";
echo "=============================================\n\n";

// 1. Verify tables exist
$tables = ['flowork_system_admins', 'flowork_subscriptions', 'flowork_invoices', 'selling_point'];
foreach ($tables as $tbl) {
    $res = $conn->query("SHOW TABLES LIKE '$tbl'");
    if ($res && $res->num_rows > 0) {
        echo "✓ Table '$tbl' exists.\n";
    } else {
        echo "❌ Table '$tbl' is MISSING!\n";
    }
}
echo "\n";

// 2. Verify seeded developer account
$dev_q = $conn->query("SELECT * FROM flowork_system_admins WHERE username='developer' LIMIT 1");
if ($dev_q && $dev_q->num_rows > 0) {
    $dev = $dev_q->fetch_assoc();
    echo "✓ Developer account exists. Hash: " . substr($dev['password_hash'], 0, 15) . "...\n";
    if (password_verify('flowork_dev_2026', $dev['password_hash'])) {
        echo "  ✓ Password check 'flowork_dev_2026' succeeded.\n";
    } else {
        echo "  ❌ Password check 'flowork_dev_2026' FAILED!\n";
    }
} else {
    echo "❌ Seed developer account is MISSING!\n";
}
echo "\n";

// 3. Test customer creation, invoicing, and activation cycle
echo "Running test invoice & subscription simulation...\n";

// Get a test selling point
$sp_q = $conn->query("SELECT id, name FROM selling_point LIMIT 1");
if (!$sp_q || $sp_q->num_rows === 0) {
    // Insert a dummy floor for testing if none exists
    $conn->query("INSERT INTO selling_point (name, floor_code, floor_id, active) VALUES ('Test Floor', 'TF', 'TF001', 1)");
    $sp_q = $conn->query("SELECT id, name FROM selling_point WHERE floor_code='TF' LIMIT 1");
}
$sp = $sp_q->fetch_assoc();
$spid = (int)$sp['id'];
$floor_name = $sp['name'];
echo "Using floor: '$floor_name' (ID: $spid)\n";

// Generate unique invoice
$inv_num = 'TESTINV-' . rand(1000, 9999);
$conn->query("DELETE FROM flowork_invoices WHERE invoice_num LIKE 'TESTINV-%'"); // clean old tests
$conn->query("DELETE FROM flowork_subscriptions WHERE selling_pointid=$spid"); // reset subscription for testing

// Insert invoice
$ins = $conn->query("INSERT INTO flowork_invoices (invoice_num, selling_pointid, amount, status, issue_date, duration_days) 
                     VALUES ('$inv_num', $spid, 1500.00, 'Unpaid', '2026-06-08', 30)");
if ($ins) {
    echo "✓ Test invoice '$inv_num' created.\n";
} else {
    echo "❌ Failed to create test invoice: " . $conn->error . "\n";
}

// Generate license key
function generate_test_key() {
    $chars = '0123456789ABCDEF';
    $key = 'FL-TEST-';
    for ($j = 0; $j < 8; $j++) {
        $key .= $chars[rand(0, 15)];
    }
    return $key;
}
$license_key = generate_test_key();

// Mark invoice as Paid
$upd = $conn->query("UPDATE flowork_invoices SET status='Paid', paid_date='2026-06-08', license_key='$license_key' WHERE invoice_num='$inv_num'");
if ($upd) {
    echo "✓ Invoice marked Paid. Key generated: '$license_key'.\n";
} else {
    echo "❌ Failed to update invoice: " . $conn->error . "\n";
}

// Simulate license check / activation
// Check if key is valid
$chk_q = $conn->query("SELECT * FROM flowork_invoices WHERE license_key='$license_key' AND selling_pointid=$spid AND status='Paid' LIMIT 1");
if ($chk_q && $chk_q->num_rows > 0) {
    echo "✓ Key verification matches.\n";
    $inv = $chk_q->fetch_assoc();
    $days = (int)$inv['duration_days'];
    
    // Simulate activation on empty/expired subscription
    $today = date('Y-m-d');
    $new_end = date('Y-m-d', strtotime($today . " + $days days"));
    $conn->query("INSERT INTO flowork_subscriptions (selling_pointid, status, license_key, start_date, end_date) 
                  VALUES ($spid, 'Active', '$license_key', '$today', '$new_end')");
                  
    // Verify subscription database write
    $sub_q = $conn->query("SELECT * FROM flowork_subscriptions WHERE selling_pointid=$spid LIMIT 1");
    $sub = $sub_q->fetch_assoc();
    if ($sub && $sub['status'] === 'Active' && $sub['end_date'] === $new_end) {
        echo "✓ Subscription activated successfully. Valid until " . $sub['end_date'] . ".\n";
    } else {
        echo "❌ Subscription activation FAILED!\n";
    }
} else {
    echo "❌ Key verification FAILED!\n";
}

// Cleanup test records
$conn->query("DELETE FROM flowork_invoices WHERE invoice_num='$inv_num'");
$conn->query("DELETE FROM flowork_subscriptions WHERE selling_pointid=$spid");
echo "\n✓ Test completed. Database cleaned up.\n";
echo "=============================================\n";
