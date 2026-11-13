<?php
// Balancing one delivery split, and reversing balanced deliveries. Shared by
// the Balance page and the bale file import, so a delivery balanced from an
// imported file gets the same totals, charges, stop orders and net as one
// balanced by hand.
require_once __DIR__ . '/stoporder_deduct_helper.php';
require_once __DIR__ . '/audit.php';

if (!function_exists('flowork_delete_balance_rows')) {
    /**
     * Delete one balanced_bales row and everything balancing wrote for it, and
     * put back the TIMB stop orders it processed. Runs inside the caller's
     * transaction, if any. Returns whether the balanced_bales row was deleted.
     */
    function flowork_delete_balance_rows(mysqli $conn, int $bid): bool {
        $conn->query("DELETE FROM internal_stop_order_deducted_amounts WHERE balanced_balesid=$bid");
        $conn->query("DELETE FROM internal_stoporder_deductions WHERE balanced_balesid=$bid");
        $conn->query("DELETE FROM daily_totals_net_amount WHERE balanced_balesid=$bid");
        $conn->query("DELETE FROM balanced_bales_charges WHERE balanced_balesid=$bid");
        $conn->query("DELETE FROM daily_totals_split_amounts WHERE balanced_balesid=$bid");
        $conn->query("DELETE FROM daily_totals WHERE balanced_balesid=$bid");
        // Reset TIMB external stop orders that were processed against this batch
        $so_ids_q = $conn->query("SELECT stoporderid FROM stoporder_deductions WHERE balanced_balesid=$bid");
        if ($so_ids_q && $so_ids_q !== true) {
            while ($so_row = $so_ids_q->fetch_assoc()) {
                $conn->query("UPDATE stoporders SET processed=0 WHERE id=".(int)$so_row['stoporderid']);
            }
        }
        $conn->query("DELETE FROM stoporder_deductions WHERE balanced_balesid=$bid");
        return (bool)$conn->query("DELETE FROM balanced_bales WHERE id=$bid");
    }
}

if (!function_exists('flowork_balance_delivery')) {
    /**
     * Balance one split of a delivery: checks the booking and that every bale
     * is sold, records the rule, runs the shared recalculation, puts it in a
     * new sales batch and closes the delivery note. The caller checks that the
     * delivery and grower are on the floor.
     *
     * @param array $rule [so_mode, rbz_usd_share] from flowork_rule_from_input()
     * @return array ['error' => message] or ['ok' => true, 'bbid', 'batch',
     *               'gross', 'net_usd', 'net_zig', 'bales', 'sold']
     */
    function flowork_balance_delivery(mysqli $conn, int $tgid, int $splitid, int $growerid, int $spid, int $userid, array $rule): array {
        // One balance per delivery split at a time: without this, two requests
        // (a double click, or two clerks) both pass the "already balanced" check
        // and insert two balanced_bales rows for the same bales.
        $lock = "flowork_balance_{$tgid}_{$splitid}";
        $lq = $conn->query("SELECT GET_LOCK('$lock', 10) l");
        if (!$lq || $lq === true || (int)$lq->fetch_assoc()['l'] !== 1) {
            return ['error' => 'This delivery is being balanced by another request. Try again in a moment.'];
        }
        try {
            return flowork_balance_delivery_locked($conn, $tgid, $splitid, $growerid, $spid, $userid, $rule);
        } finally {
            $conn->query("SELECT RELEASE_LOCK('$lock')");
        }
    }

    /** flowork_balance_delivery() once its lock is held. */
    function flowork_balance_delivery_locked(mysqli $conn, int $tgid, int $splitid, int $growerid, int $spid, int $userid, array $rule): array {
        $today = date('Y-m-d');

        $_ab = $conn->query("SELECT id FROM balanced_bales WHERE transporter_growersid=$tgid AND splitid=$splitid LIMIT 1");
        if ($_ab && $_ab !== true && $_ab->num_rows) return ['error' => 'Already balanced.'];

        // A booking for another day (for example before a roll-over) does not count.
        if (!flowork_delivery_booking($conn, $tgid)) {
            $_tgi = $conn->query("SELECT COALESCE(sell_date,'$today') sell_dt FROM transporter_growers WHERE id=$tgid LIMIT 1");
            $sell_dt = ($_tgi && $_tgi !== true && $_tgi->num_rows) ? $_tgi->fetch_assoc()['sell_dt'] : $today;
            return ['error' => "Grower is not booked for $sell_dt. Book them for that selling day (with a booking ID) in Grower Booking first."];
        }

        $res = $conn->query("
            SELECT bj.id, bm.mass, sb.id sold_id, sb.price, COALESCE(sb.sale_code,'') sale_code
            FROM bale_junus bj
            JOIN bale_mass bm ON bm.id=bj.bale_massid
            JOIN tickets tk ON tk.bale_junusid=bj.id
            LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
            WHERE bj.transporter_growersid=$tgid AND bj.splitid=$splitid");
        $bales = ($res && $res !== true) ? $res->fetch_all(MYSQLI_ASSOC) : [];
        if (empty($bales)) return ['error' => 'No bales found for this split.'];

        $unsold = 0;
        foreach ($bales as $b) {
            if (!$b['sold_id']) $unsold++;
        }
        if ($unsold > 0) return ['error' => "$unsold bale(s) not yet sold. Complete selling first."];

        $gross = 0; $sold_cnt = 0;
        foreach ($bales as $b) {
            $is_rej = strtoupper(substr(trim($b['sale_code']),0,1))==='R' && strlen(trim($b['sale_code']))<=3;
            if (!$is_rej && $b['sold_id']) {
                $p = (float)$b['price']; if ($p > 10) $p /= 100;
                $gross += (float)$b['mass'] * $p;
                $sold_cnt++;
            }
        }

        $conn->query("INSERT INTO balanced_bales
            (userid,splitid,growerid,transporter_growersid,created_at,datetime)
            VALUES ($userid,$splitid,$growerid,$tgid,'$today',NOW())");
        $bbid = (int)$conn->insert_id;
        if (!$bbid) return ['error' => 'Balance insert failed: ' . $conn->error];
        flowork_set_delivery_rule($conn, $bbid, $rule[0], $rule[1]);

        $calc = flowork_recalculate_balance_batch($conn, $bbid, $spid, $userid);
        if (!$calc) {
            // Take out whatever the pipeline wrote before it failed, not just the row
            flowork_delete_balance_rows($conn, $bbid);
            return ['error' => 'Recalculation pipeline failed.'];
        }

        // Batch & close delivery note
        $conn->query("CREATE TABLE IF NOT EXISTS sales_batching (
            id INT AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $conn->query("INSERT INTO sales_batching (userid) VALUES ($userid)");
        $batch_id = (int)$conn->insert_id ?: 1;
        $conn->query("UPDATE balanced_bales SET stoporder_processed=0, sale_batchid=$batch_id WHERE id=$bbid");
        $conn->query("UPDATE transporter_growers SET open_close=1 WHERE id=$tgid");

        audit_log($conn,$spid,$userid,'balanced_bales','create',$bbid,null,
            ['tgid'=>$tgid,'gross'=>$gross,'net'=>$calc['net_usd'],'bales'=>count($bales),'rule_so_mode'=>$rule[0],'rule_rbz_usd'=>$rule[1]]);

        return [
            'ok'      => true,
            'bbid'    => $bbid,
            'batch'   => $batch_id,
            'gross'   => $gross,
            'net_usd' => (float)$calc['net_usd'],
            'net_zig' => (float)$calc['net_zig'],
            'bales'   => count($bales),
            'sold'    => $sold_cnt,
        ];
    }
}

if (!function_exists('do_reverse')) {
    /**
     * Reverse (delete) balanced deliveries matching $where_sql on this floor only.
     * A delivery whose payment is already paid or released is skipped: reverse
     * the payment first, otherwise the payment would point at nothing.
     * Returns the number reversed; $skipped receives the ids left alone.
     */
    function do_reverse($conn, $where_sql, $spid = 0, $userid = 0, &$skipped = []) {
        $spid = (int)$spid;
        // Payments key a sheet by COALESCE(bb.sell_date, DATE(bb.created_at)); a
        // delivery balanced after its sale day (an imported sale, a roll-over) may
        // be paid under its sell date instead, so both dates are checked.
        $bl = $conn->query("SELECT bb.id, bb.transporter_growersid, bb.growerid,
                COALESCE(bb.sell_date, DATE(bb.created_at)) bal_date, tg.sell_date tg_date
            FROM balanced_bales bb JOIN growers g ON g.id = bb.growerid
            LEFT JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
            WHERE ($where_sql) AND (g.selling_pointid=$spid OR bb.selling_pointid=$spid)");
        $rev = 0;
        while ($bl && $b = $bl->fetch_assoc()) {
            $bid  = (int)$b['id'];
            $tgid = (int)$b['transporter_growersid'];
            $dates = array_unique(array_filter([(string)$b['bal_date'], (string)($b['tg_date'] ?? '')]));
            $in = implode(',', array_map(fn($d) => "'" . $conn->real_escape_string($d) . "'", $dates)) ?: "''";
            $paid = $conn->query("SELECT 1 FROM payment_status WHERE transporter_growersid=$tgid
                AND sell_date IN ($in) AND status IN ('paid','released') LIMIT 1");
            if ($paid && $paid !== true && $paid->num_rows > 0) { $skipped[] = $bid; continue; }
            $conn->begin_transaction();
            if (flowork_delete_balance_rows($conn, $bid)) {
                $rev++;
                // Re-open the delivery note so grower can be re-balanced
                if ($tgid) $conn->query("UPDATE transporter_growers SET open_close=0 WHERE id=$tgid");
                $conn->commit();
                audit_log($conn, $spid, (int)$userid, 'balanced_bales', 'reverse', $bid, ['tgid' => $tgid, 'growerid' => (int)$b['growerid']], null);
            } else {
                $conn->rollback();
            }
        }
        return $rev;
    }
}
