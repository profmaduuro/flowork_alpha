<?php
/**
 * api/v2/payment_gateway.php
 * Direct Zimbabwe Mobile Money Gateway Simulator (EcoCash & InnBucks)
 * Handles payout routing, number validations, transaction latency simulation,
 * and direct database status logging with support for explicit fail tests.
 */
session_start();
header('Content-Type: application/json');

require_once "../../includes/auth.php";
require_once "../../includes/conn.php";
require_login();

$userid = current_userid();
$today  = date('Y-m-d');

// Read JSON payload
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action   = $input['action']   ?? '';
$tgid     = (int)($input['tgid'] ?? 0);
$phone    = trim($input['phone'] ?? '');
$gateway  = trim($input['gateway'] ?? '');
$amount   = (float)($input['amount'] ?? 0);
$currency = trim($input['currency'] ?? 'usd');
$sell_dt  = trim($input['sell_date'] ?? $today);
$notes    = trim($input['notes'] ?? '');

if (!$action || !$tgid || !$phone || !$gateway || $amount <= 0) {
    echo json_encode(['error' => 'Missing required fields: action, tgid, phone, gateway, and positive amount are required.']);
    exit;
}

if (!in_array(strtolower($currency), ['usd', 'zig'])) {
    $currency = 'usd';
}
$currency = strtolower($currency);

// ── 1. Simulate Latency (800ms) ──────────────────────────────────────────────
usleep(800000); 

// ── 2. Zimbabwe Phone Number Validation ──────────────────────────────────────
// Match format: +263771234567, 263771234567, 0771234567
$phone_cleaned = preg_replace('/[^0-9]/', '', $phone);
if (str_starts_with($phone_cleaned, '263')) {
    $phone_formatted = '0' . substr($phone_cleaned, 3);
} elseif (str_starts_with($phone_cleaned, '0')) {
    $phone_formatted = $phone_cleaned;
} else {
    $phone_formatted = '0' . $phone_cleaned;
}

// Check database setup for payment_failures table
$conn->query("CREATE TABLE IF NOT EXISTS payment_failures (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transporter_growersid INT NOT NULL,
  sell_date DATE NOT NULL,
  currency ENUM('usd','zig') DEFAULT 'usd',
  amount DECIMAL(14,4) DEFAULT 0,
  gateway VARCHAR(30) DEFAULT '',
  phone VARCHAR(30) DEFAULT '',
  error_message VARCHAR(255) DEFAULT '',
  failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY pf_unique (transporter_growersid, sell_date, currency)
)");

if (!preg_match('/^07[7813]\d{7}$/', $phone_formatted)) {
    echo json_encode([
        'error' => "Invalid phone format: '{$phone}'. Zimbabwe mobile networks require standard prefixes (077/078 for EcoCash, or 071/073/077/078/etc)."
    ]);
    exit;
}

// ── 3. Mock Failures (subscriber funding/connectivity tests) ──────────────────
// Phones ending in '99' trigger insufficient funds
if (str_ends_with($phone_formatted, '99')) {
    $err_msg = "Direct Mobile Money Gateway Transfer failed: Insufficient subscriber funds in {$gateway} wallet.";
    $gateway_esc = $conn->real_escape_string($gateway);
    $phone_esc   = $conn->real_escape_string($phone_formatted);
    $err_esc     = $conn->real_escape_string($err_msg);
    $date_esc    = $conn->real_escape_string($sell_dt);
    
    $conn->query("INSERT INTO payment_failures 
        (transporter_growersid, sell_date, currency, amount, gateway, phone, error_message)
        VALUES 
        ($tgid, '$date_esc', '$currency', $amount, '$gateway_esc', '$phone_esc', '$err_esc')
        ON DUPLICATE KEY UPDATE error_message='$err_esc', failed_at=NOW()");

    echo json_encode([
        'success' => false,
        'error_code' => 'GATEWAY_INSUFFICIENT_FUNDS',
        'error' => $err_msg
    ]);
    exit;
}
// Phones ending in '00' trigger offline status
if (str_ends_with($phone_formatted, '00')) {
    $err_msg = "Direct Mobile Money Gateway Transfer failed: Subscriber unreachable. Phone is either switched off or out of coverage range.";
    $gateway_esc = $conn->real_escape_string($gateway);
    $phone_esc   = $conn->real_escape_string($phone_formatted);
    $err_esc     = $conn->real_escape_string($err_msg);
    $date_esc    = $conn->real_escape_string($sell_dt);

    $conn->query("INSERT INTO payment_failures 
        (transporter_growersid, sell_date, currency, amount, gateway, phone, error_message)
        VALUES 
        ($tgid, '$date_esc', '$currency', $amount, '$gateway_esc', '$phone_esc', '$err_esc')
        ON DUPLICATE KEY UPDATE error_message='$err_esc', failed_at=NOW()");

    echo json_encode([
        'success' => false,
        'error_code' => 'GATEWAY_SUBSCRIBER_OFFLINE',
        'error' => $err_msg
    ]);
    exit;
}

// ── 4. Success Payout Processing ──────────────────────────────────────────────
if ($action === 'payout' || $action === 'retry') {
    // Generate authentic transaction reference code
    $prefix = strtoupper(substr($gateway, 0, 2)); // EC for EcoCash, IN for InnBucks
    $ref = $prefix . date('ymd') . sprintf('%04d', rand(0, 9999)) . strtoupper(substr(uniqid(), -3));

    // Start Transaction
    $conn->begin_transaction();
    try {
        $now = date('Y-m-d H:i:s');
        $method_esc = $conn->real_escape_string($gateway);
        $ref_esc    = $conn->real_escape_string($ref);
        $date_esc   = $conn->real_escape_string($sell_dt);
        $cur_esc    = $conn->real_escape_string($currency);
        $notes_esc  = $conn->real_escape_string("Direct simulated MM payout: " . ($notes ?: "N/A"));

        // Check if payment_status entry exists
        $ex_q = $conn->query("SELECT id FROM payment_status WHERE transporter_growersid=$tgid AND sell_date='$date_esc' AND currency='$cur_esc' LIMIT 1");
        $ex = $ex_q ? $ex_q->fetch_assoc() : null;

        if ($ex) {
            $psid = (int)$ex['id'];
            $conn->query("UPDATE payment_status SET 
                status='paid',
                payment_method='$method_esc',
                reference_number='$ref_esc',
                paid_at='$now',
                paid_by=$userid,
                notes='$notes_esc' 
                WHERE id=$psid");
        } else {
            $conn->query("INSERT INTO payment_status 
                (transporter_growersid, sell_date, currency, net_amount, status, payment_method, reference_number, paid_at, paid_by, notes) 
                VALUES 
                ($tgid, '$date_esc', '$cur_esc', $amount, 'paid', '$method_esc', '$ref_esc', '$now', $userid, '$notes_esc')");
            $psid = (int)$conn->insert_id;
        }

        // Write to payment audit log
        $conn->query("INSERT INTO payment_log 
            (payment_statusid, userid, action, currency, amount, payment_method, reference_number, notes) 
            VALUES 
            ($psid, $userid, 'direct_payout', '$cur_esc', $amount, '$method_esc', '$ref_esc', '$notes_esc')");

        // Clear failures list upon successful payout retry
        $conn->query("DELETE FROM payment_failures WHERE transporter_growersid=$tgid AND sell_date='$date_esc' AND currency='$cur_esc'");

        $conn->commit();

        echo json_encode([
            'success' => true,
            'reference' => $ref,
            'message' => "✓ Simulated payout of $" . number_format($amount, 2) . " " . strtoupper($currency) . " completed successfully to {$phone_formatted} via {$gateway}.",
            'details' => [
                'ref' => $ref,
                'gateway' => $gateway,
                'amount' => $amount,
                'currency' => $currency,
                'phone' => $phone_formatted,
                'paid_at' => $now
            ]
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'error' => 'Database transaction failed during payout: ' . $e->getMessage()
        ]);
    }
    exit;
}

echo json_encode(['error' => 'Invalid action string parameter.']);
exit;
