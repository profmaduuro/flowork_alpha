<?php
/**
 * pay_writeoffs.php — accepted differences between a payment and the stored net.
 *
 * When a payment cannot be corrected (the grower already has the money) and the
 * owner decides not to recover it, the difference is recorded here with the
 * reason and who approved it. The money test and the daily reconciliation then
 * treat that payment as settled instead of flagging it every day.
 */

if (!function_exists('flowork_ensure_writeoffs')) {
    function flowork_ensure_writeoffs(mysqli $conn): void {
        static $done = false;
        if ($done) return;
        $conn->query("CREATE TABLE IF NOT EXISTS payment_writeoffs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_statusid INT NOT NULL,
            amount DECIMAL(14,4) NOT NULL COMMENT 'paid minus stored net; positive = overpaid',
            currency ENUM('usd','zig') NOT NULL DEFAULT 'usd',
            reason VARCHAR(255) NOT NULL,
            approved_by VARCHAR(120) NOT NULL,
            created_by INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_writeoff_payment (payment_statusid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    /** Record (or replace) the accepted difference for one payment. */
    function flowork_record_writeoff(mysqli $conn, int $psid, float $amount, string $currency, string $reason, int $userid, string $approved_by): bool {
        flowork_ensure_writeoffs($conn);
        $cur = $currency === 'zig' ? 'zig' : 'usd';
        $stmt = $conn->prepare("INSERT INTO payment_writeoffs (payment_statusid, amount, currency, reason, approved_by, created_by)
            VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount), reason=VALUES(reason), approved_by=VALUES(approved_by), created_by=VALUES(created_by)");
        $stmt->bind_param('idsssi', $psid, $amount, $cur, $reason, $approved_by, $userid);
        return $stmt->execute();
    }

    /** payment_statusid => written-off amount, for every recorded write-off. */
    function flowork_writeoffs(mysqli $conn): array {
        flowork_ensure_writeoffs($conn);
        $out = [];
        $q = $conn->query("SELECT payment_statusid, amount FROM payment_writeoffs");
        while ($q && $q !== true && $r = $q->fetch_assoc()) $out[(int)$r['payment_statusid']] = (float)$r['amount'];
        return $out;
    }
}
