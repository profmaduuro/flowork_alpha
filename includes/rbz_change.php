<?php
/**
 * RBZ retention rate changes.
 *
 * Each balanced delivery records the RBZ USD share it was calculated with
 * (balanced_bales.rule_rbz_usd), and sales sheets and EOD reports read that.
 * When the rate in Settings changes, the change is logged here and announced
 * (notification bell, Notifications page, Balance page). The deliveries that
 * still carry another rate are recalculated with the new one by "Apply stop
 * orders" on the Balance page. Paid or released deliveries keep their figures.
 */

if (!function_exists('flowork_rbz_current')) {

    function flowork_rbz_ensure(mysqli $conn): void {
        static $done = false;
        if ($done) return;
        $conn->query("CREATE TABLE IF NOT EXISTS rbz_rate_changes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            old_usd DECIMAL(7,4) NULL,
            new_usd DECIMAL(7,4) NOT NULL,
            userid INT NOT NULL DEFAULT 0,
            username VARCHAR(100) NOT NULL DEFAULT '',
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $done = true;
    }

    /** The USD share (0..1) in Settings → RBZ retention. */
    function flowork_rbz_current(mysqli $conn): float {
        $q = $conn->query("SELECT usd_percent FROM rbz_retention_rate LIMIT 1");
        $u = ($q && $q !== true && $q->num_rows) ? (float)$q->fetch_assoc()['usd_percent'] : 0.70;
        return $u > 1 ? $u / 100 : $u;
    }

    /** Log a change and announce it on the notification bell. */
    function flowork_rbz_record_change(mysqli $conn, ?float $old, float $new, int $userid, string $username): void {
        flowork_rbz_ensure($conn);
        $ov = $old === null ? 'NULL' : round($old, 4);
        $un = $conn->real_escape_string(substr($username, 0, 100));
        $conn->query("INSERT INTO rbz_rate_changes (old_usd,new_usd,userid,username) VALUES ($ov," . round($new, 4) . ",$userid,'$un')");
        $fmt = fn($s) => rtrim(rtrim(number_format($s * 100, 2), '0'), '.');
        $msg = 'RBZ retention changed' . ($old !== null ? ' from USD ' . $fmt($old) . '%' : '')
             . ' to USD ' . $fmt($new) . '% / ZiG ' . $fmt(1 - $new) . '%'
             . ($username !== '' ? " by $username" : '')
             . '. Run Apply stop orders on Balance & Settlement to recalculate balanced sales.';
        $m = $conn->real_escape_string(substr($msg, 0, 255));
        if (!$conn->query("INSERT INTO event_notifications (event_type,message) VALUES ('rate_updated','$m')") && $conn->errno === 1146) {
            $conn->query("CREATE TABLE IF NOT EXISTS event_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY, event_type VARCHAR(50) NOT NULL,
                message VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
            $conn->query("INSERT INTO event_notifications (event_type,message) VALUES ('rate_updated','$m')");
        }
    }

    /** The latest changes, newest first. */
    function flowork_rbz_changes(mysqli $conn, int $limit = 10): array {
        flowork_rbz_ensure($conn);
        $q = $conn->query("SELECT * FROM rbz_rate_changes ORDER BY id DESC LIMIT " . max(1, $limit));
        return ($q && $q !== true) ? $q->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * SQL condition (on balanced_bales bb) for deliveries on a floor that carry
     * another RBZ share than $share and are not paid or released.
     */
    function flowork_rbz_stale_where(int $spid, float $share): string {
        $s = round($share, 4);
        return "bb.rule_rbz_usd IS NOT NULL AND ABS(bb.rule_rbz_usd - $s) > 0.00005
            AND EXISTS (SELECT 1 FROM growers g WHERE g.id=bb.growerid AND (g.selling_pointid=$spid OR $spid=0))
            AND NOT EXISTS (SELECT 1 FROM payment_status ps WHERE ps.transporter_growersid=bb.transporter_growersid
                AND ps.sell_date=DATE(bb.created_at) AND ps.status IN ('paid','released','partial'))";
    }

    /** How many balanced deliveries on a floor still use another rate. */
    function flowork_rbz_stale_count(mysqli $conn, int $spid, float $share): int {
        $q = $conn->query("SELECT COUNT(*) n FROM balanced_bales bb WHERE " . flowork_rbz_stale_where($spid, $share));
        if (!$q) {   // no payment_status table yet: nothing is paid
            $q = $conn->query("SELECT COUNT(*) n FROM balanced_bales bb WHERE bb.rule_rbz_usd IS NOT NULL
                AND ABS(bb.rule_rbz_usd - " . round($share, 4) . ") > 0.00005
                AND EXISTS (SELECT 1 FROM growers g WHERE g.id=bb.growerid AND (g.selling_pointid=$spid OR $spid=0))");
        }
        return ($q && $q !== true) ? (int)$q->fetch_assoc()['n'] : 0;
    }

    /**
     * Recalculate every unpaid balanced delivery on the floor that carries another
     * rate, with the rate in Settings (its stop-order mode is kept).
     * Returns [recalculated, failed, payment batches sent back to draft].
     */
    function flowork_rbz_apply(mysqli $conn, int $spid, int $userid): array {
        $share = flowork_rbz_current($conn);
        $q = $conn->query("SELECT bb.id, bb.rule_so_mode, bb.rule_rbz_usd FROM balanced_bales bb WHERE " . flowork_rbz_stale_where($spid, $share));
        $done = 0; $failed = 0; $to_draft = [];
        while ($q && $q !== true && $r = $q->fetch_assoc()) {
            $bbid = (int)$r['id'];
            flowork_set_delivery_rule($conn, $bbid, (string)($r['rule_so_mode'] ?? 'before'), $share);
            try {
                $calc = flowork_recalculate_balance_batch($conn, $bbid, $spid, $userid);
            } catch (Throwable $e) { $calc = null; }
            if ($calc) {
                $to_draft = array_values(array_unique(array_merge($to_draft, $calc['batches_to_draft'] ?? [])));
                $conn->query("UPDATE balanced_bales SET stoporder_processed=1 WHERE id=$bbid");
                audit_log($conn, $spid, $userid, 'balanced_bales', 'rbz_reapply', $bbid,
                    ['rbz_usd' => (float)$r['rule_rbz_usd']], ['rbz_usd' => $share]);
                $done++;
            } else {
                // put the old rate back so the delivery still shows as not updated
                flowork_set_delivery_rule($conn, $bbid, (string)($r['rule_so_mode'] ?? 'before'), (float)$r['rule_rbz_usd']);
                $failed++;
            }
        }
        return [$done, $failed, $to_draft];
    }
}
