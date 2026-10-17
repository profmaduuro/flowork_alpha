<?php
/**
 * net_amount.php
 * The one source of a delivery's net payable: daily_totals_net_amount,
 * one row per balanced_balesid.
 *
 * - Write it only through flowork_save_net() (called by the balance /
 *   stop-order pipeline in stoporder_deduct_helper.php).
 * - Read it only through flowork_net_join() / flowork_net_for_bbid(), so the
 *   Payments page, batch payments, the dashboard and the risk check all see
 *   the same figure.
 *
 * Older code inserted a new row on every recalculation without removing the
 * previous one, so some deliveries carry several rows. The reader picks the
 * newest row per delivery and never adds them together. Run
 * scratch/net_amount_cleanup.php to remove the extra rows and add the unique key.
 */

if (!function_exists('flowork_net_join')) {
    /**
     * LEFT JOIN clause exposing <alias>.usd_net_value and <alias>.zim_net_value
     * for the delivery whose id is in $bb_col (one row per delivery).
     */
    function flowork_net_join(string $alias = 'dtn', string $bb_col = 'bb.id'): string {
        return "LEFT JOIN (
                SELECT n.balanced_balesid, n.usd_net_value, n.zim_net_value
                FROM daily_totals_net_amount n
                JOIN (SELECT balanced_balesid, MAX(id) AS last_id
                      FROM daily_totals_net_amount GROUP BY balanced_balesid) nl
                  ON nl.last_id = n.id
            ) $alias ON $alias.balanced_balesid = $bb_col";
    }

    /** Stored net for one delivery in the given currency ('usd' or 'zig'). */
    function flowork_net_for_bbid(mysqli $conn, int $bbid, string $currency = 'usd'): float {
        $col = $currency === 'zig' ? 'zim_net_value' : 'usd_net_value';
        $q = $conn->query("SELECT $col AS net FROM daily_totals_net_amount
            WHERE balanced_balesid=$bbid ORDER BY id DESC LIMIT 1");
        $r = ($q && $q !== true) ? $q->fetch_assoc() : null;
        return $r ? (float)$r['net'] : 0.0;
    }

    /** Replace the delivery's net row, leaving exactly one row for $bbid. */
    function flowork_save_net(mysqli $conn, int $userid, int $bbid, float $usd, float $zig, string $created_at): bool {
        $created_at = $conn->real_escape_string($created_at);
        // Delete + insert as one unit, unless the caller already holds a transaction.
        $own = empty($GLOBALS['flowork_in_txn']);
        if ($own) $conn->begin_transaction();
        $ok = $conn->query("DELETE FROM daily_totals_net_amount WHERE balanced_balesid=$bbid")
           && $conn->query("INSERT INTO daily_totals_net_amount
            (userid,balanced_balesid,usd_net_value,zim_net_value,created_at)
            VALUES ($userid,$bbid,$usd,$zig,'$created_at')");
        if ($own) { $ok ? $conn->commit() : $conn->rollback(); }
        return (bool)$ok;
    }
}
