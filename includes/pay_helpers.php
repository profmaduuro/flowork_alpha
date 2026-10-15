<?php
// Payment role, risk and log helpers. Used by pay_schema.php (web) and
// api/modules/payments.php (mobile API).
require_once __DIR__ . "/net_amount.php";

// ── Role helpers ──────────────────────────────────────────────────────────
if (!function_exists('pay_role')) {
function pay_role($conn, $userid) {
    $userid = (int)$userid;
    // Admin or master on the user record (users.php), or admin in the legacy
    // rights table, = pay admin. Same rule as flowork_resolve_rights().
    $q = $conn->query("SELECT u.is_admin, u.is_master, u.role, r.admin AS legacy_admin, pr.pay_role
        FROM users u
        LEFT JOIN rights r ON r.userid = u.id
        LEFT JOIN payment_roles pr ON pr.userid = u.id
        WHERE u.id = $userid LIMIT 1");
    $r = ($q && $q !== true) ? $q->fetch_assoc() : null;
    if (!$r) return 'clerk';
    if (!empty($r['is_admin']) || !empty($r['is_master']) || !empty($r['legacy_admin'])
        || in_array($r['role'] ?? '', ['admin', 'master'], true)) return 'admin';
    return $r['pay_role'] ?? 'clerk';
}
function pay_can($role, $action) {
    $perms = [
        'clerk'       => ['create_payment','create_batch','view_register','view_grower_history'],
        'accountant'  => ['create_payment','create_batch','review_batch','review_payment','view_register','view_audit','view_grower_history','export'],
        'manager'     => ['create_payment','create_batch','review_batch','review_payment','approve_batch','approve_payment','view_register','view_audit','view_grower_history','export','close_day'],
        'admin'       => ['create_payment','create_batch','review_batch','review_payment','approve_batch','approve_payment','release_batch','release_payment','lock_batch','view_register','view_audit','view_grower_history','export','close_day','manage_roles','delete'],
    ];
    $allowed = $perms[$role] ?? [];
    return in_array($action, $allowed);
}

// ── Risk detection ────────────────────────────────────────────────────────
function pay_risk_check($conn, $tgid, $sell_date, $net_amount, $currency) {
    $flags = [];
    $tgid = (int)$tgid;
    $date = $conn->real_escape_string($sell_date);

    // Duplicate payment check
    $dup = $conn->query("SELECT id, paid_at FROM payment_status WHERE transporter_growersid=$tgid AND sell_date='$date' AND currency='$currency' AND status IN ('paid','released') LIMIT 1")->fetch_assoc();
    if ($dup) $flags[] = 'DUPLICATE_PAYMENT';

    // Bank account changed recently
    $gb = $conn->query("SELECT account_changed_at, previous_account_num FROM grower_banking WHERE growerid=(SELECT growerid FROM transporter_growers WHERE id=$tgid) LIMIT 1")->fetch_assoc();
    if ($gb && $gb['account_changed_at']) {
        $days = (time() - strtotime($gb['account_changed_at'])) / 86400;
        if ($days < 14) $flags[] = 'BANK_ACCT_CHANGED_RECENTLY';
    }

    // Amount exceeds expected (>10% variance from what was balanced)
    $expected = (float)$conn->query("SELECT COALESCE(SUM(dtn.usd_net_value),0) net FROM balanced_bales bb JOIN transporter_growers tg ON tg.id=bb.transporter_growersid ".flowork_net_join()." WHERE tg.id=$tgid AND DATE(bb.created_at)='$date' AND bb.stoporder_processed=1")->fetch_assoc()['net'];
    if ($expected > 0 && abs($net_amount - $expected) / $expected > 0.10)
        $flags[] = 'AMOUNT_VARIANCE_GT_10PCT';

    // No bank account recorded
    $has_bank = $conn->query("SELECT id FROM grower_banking WHERE growerid=(SELECT growerid FROM transporter_growers WHERE id=$tgid) AND account_num != '' LIMIT 1")->num_rows;
    if (!$has_bank) $flags[] = 'NO_BANK_ACCOUNT';

    return $flags;
}

function pay_log($conn, $entity_type, $entity_id, $userid, $action, $currency='usd', $amount=0, $method='', $ref='', $notes='', $risk='') {
    $et  = $conn->real_escape_string($entity_type);
    $m   = $conn->real_escape_string($method);
    $r   = $conn->real_escape_string($ref);
    $n   = $conn->real_escape_string($notes);
    $rsk = $conn->real_escape_string($risk);
    $conn->query("INSERT INTO payment_log (entity_type,entity_id,userid,action,currency,amount,payment_method,reference_number,notes,risk_flag) VALUES ('$et',$entity_id,$userid,'$action','$currency',$amount,'$m','$r','$n','$rsk')");
}
}

if (!function_exists('flowork_assign_receipt')) {
    /**
     * Give a just-settled payment its receipt number (RCT-YYYY-NNNNNN).
     *
     * Cash always gets a system receipt number: there is no outside reference,
     * and a hand-typed number cannot be relied on to be unique. Other methods
     * keep the bank / mobile-money transaction reference typed by the clerk and
     * get a receipt number only when that was left blank.
     *
     * Numbers come from an AUTO_INCREMENT table, so two clerks paying at the
     * same moment can never get the same number.
     *
     * @return string the reference now stored on the payment
     */
    function flowork_assign_receipt(mysqli $conn, int $psid, string $method = ''): string {
        static $table_ready = false;
        if (!$table_ready) {
            $conn->query("CREATE TABLE IF NOT EXISTS payment_receipts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                payment_statusid INT NOT NULL,
                receipt_no VARCHAR(30) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_receipt_no (receipt_no),
                INDEX idx_psid (payment_statusid)
            )");
            $table_ready = true;
        }
        $psid = (int)$psid;
        if ($psid <= 0) return '';

        $row = $conn->query("SELECT payment_method, reference_number FROM payment_status WHERE id=$psid LIMIT 1");
        $ps  = ($row && $row !== true) ? $row->fetch_assoc() : null;
        if (!$ps) return '';

        $method  = $method !== '' ? $method : (string)$ps['payment_method'];
        $current = trim((string)$ps['reference_number']);
        $is_cash = strcasecmp(trim($method), 'Cash') === 0;

        if (!$is_cash && $current !== '') return $current;      // keep the bank / EcoCash ref

        $conn->query("INSERT INTO payment_receipts (payment_statusid) VALUES ($psid)");
        $rid = (int)$conn->insert_id;
        if ($rid <= 0) return $current;
        $receipt = 'RCT-' . date('Y') . '-' . str_pad((string)$rid, 6, '0', STR_PAD_LEFT);
        $conn->query("UPDATE payment_receipts SET receipt_no='$receipt' WHERE id=$rid");
        $conn->query("UPDATE payment_status SET reference_number='$receipt' WHERE id=$psid");
        return $receipt;
    }
}
