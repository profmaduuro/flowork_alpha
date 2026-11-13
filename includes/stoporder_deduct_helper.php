<?php
require_once __DIR__ . "/net_amount.php";
/**
 * stoporder_deduct_helper.php
 * Helper to process and deduct TIMB stop orders (both internal and external) for growers.
 */

if (!function_exists('flowork_delivery_rules')) {
    /** Add the per-delivery rule columns once (MariaDB: IF NOT EXISTS). */
    function flowork_ensure_rule_columns(mysqli $conn): void {
        static $done = false;
        if ($done) return;
        $conn->query("ALTER TABLE balanced_bales
            ADD COLUMN IF NOT EXISTS rule_so_mode VARCHAR(10) NULL DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS rule_rbz_usd DECIMAL(6,4) NULL DEFAULT NULL");
        // Which balancing took each company deduction (internal_stoporders): one row per
        // deduction, so it is taken once, not again by every split or later sale day.
        $conn->query("CREATE TABLE IF NOT EXISTS internal_stoporder_deductions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            internal_stoporderid INT NOT NULL,
            balanced_balesid INT NOT NULL,
            amount_deducted DECIMAL(12,4) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_iso (internal_stoporderid),
            KEY idx_bb (balanced_balesid))");
        $done = true;
    }

    /** The floor's Settings: [so_mode 'before'|'after', rbz USD share 0..1]. */
    function flowork_rule_defaults(mysqli $conn, int $spid): array {
        $so_mode = 'before';
        $sm_q = $conn->query("SELECT param_value FROM parameters WHERE param_key='so_deduction_mode' AND selling_pointid=$spid LIMIT 1");
        if ($sm_q && $sm_q !== true && $sm_q->num_rows) $so_mode = $sm_q->fetch_assoc()['param_value'];
        $_rbz = $conn->query("SELECT usd_percent FROM rbz_retention_rate LIMIT 1");
        $rbz  = ($_rbz && $_rbz !== true) ? $_rbz->fetch_assoc() : null;
        return [$so_mode === 'after' ? 'after' : 'before', $rbz ? (float)$rbz['usd_percent'] : 0.70];
    }

    /**
     * A rule chosen by the user (stop-order mode, RBZ USD share in percent),
     * validated; anything missing falls back to the floor's Settings.
     * Returns [so_mode, share 0..1] or null when the input is invalid.
     */
    function flowork_rule_from_input(mysqli $conn, int $spid, $mode_in, $pct_in): ?array {
        [$mode, $share] = flowork_rule_defaults($conn, $spid);
        if ($mode_in !== null && $mode_in !== '') {
            if (!in_array($mode_in, ['before', 'after'], true)) return null;
            $mode = $mode_in;
        }
        if ($pct_in !== null && $pct_in !== '') {
            if (!is_numeric($pct_in) || (float)$pct_in < 0 || (float)$pct_in > 100) return null;
            $share = round((float)$pct_in / 100, 4);
        }
        return [$mode, $share];
    }

    /** Has the grower's payment for this delivery's day been made (paid or released)? */
    function flowork_delivery_settled(mysqli $conn, int $tgid, string $date): bool {
        $d = $conn->real_escape_string($date);
        $q = $conn->query("SELECT 1 FROM payment_status WHERE transporter_growersid=$tgid AND sell_date='$d'
            AND status IN ('paid','released') LIMIT 1");
        return $q && $q !== true && $q->num_rows > 0;
    }

    /** Record a rule on a delivery (before it is calculated or recalculated). */
    function flowork_set_delivery_rule(mysqli $conn, int $bbid, string $mode, float $share): void {
        flowork_ensure_rule_columns($conn);
        $mode = $mode === 'after' ? 'after' : 'before';
        $conn->query("UPDATE balanced_bales SET rule_so_mode='$mode', rule_rbz_usd=" . round($share, 4) . " WHERE id=$bbid");
    }

    /**
     * [so_mode, rbz_usd_share] for a delivery. Uses the rule recorded on the
     * delivery; if none is recorded yet (first calculation), takes today's
     * Settings and records them on the delivery.
     */
    function flowork_delivery_rules(mysqli $conn, array $bb, int $spid): array {
        flowork_ensure_rule_columns($conn);
        $bbid = (int)$bb['id'];
        $r = $conn->query("SELECT rule_so_mode, rule_rbz_usd FROM balanced_bales WHERE id=$bbid LIMIT 1");
        $r = ($r && $r !== true) ? $r->fetch_assoc() : null;
        if ($r && $r['rule_so_mode'] !== null && $r['rule_rbz_usd'] !== null) {
            return [$r['rule_so_mode'], (float)$r['rule_rbz_usd']];
        }
        [$so_mode, $rbz_pct] = flowork_rule_defaults($conn, $spid);
        flowork_set_delivery_rule($conn, $bbid, $so_mode, $rbz_pct);
        return [$so_mode, $rbz_pct];
    }
}

if (!function_exists('flowork_refresh_unpaid_amounts')) {
    /**
     * After a delivery is recalculated, bring the amounts that payments stored
     * earlier in line with its new net, as long as no money has moved:
     *  - payment_status rows still pending or on hold;
     *  - items in payment batches not yet released (draft, reviewed, approved).
     * A reviewed or approved batch whose amounts change goes back to draft, so it
     * is checked again before release. Paid, partial and released payments are
     * never touched. Returns the ids of batches sent back to draft.
     */
    function flowork_refresh_unpaid_amounts(mysqli $conn, int $bbid, int $userid): array {
        $q = $conn->query("SELECT transporter_growersid tg, DATE(created_at) d FROM balanced_bales WHERE id=$bbid LIMIT 1");
        $bb = ($q && $q !== true) ? $q->fetch_assoc() : null;
        if (!$bb) return [];
        $tg = (int)$bb['tg']; $d = $conn->real_escape_string($bb['d']);
        $to_draft = [];
        foreach (['usd' => 'usd_net_value', 'zig' => 'zim_net_value'] as $cur => $col) {
            $nq = $conn->query("SELECT COALESCE(SUM(dtn.$col),0) net FROM balanced_bales bb " . flowork_net_join() . "
                WHERE bb.transporter_growersid=$tg AND DATE(bb.created_at)='$d'");
            $net_tg = round(($nq && $nq !== true) ? (float)$nq->fetch_assoc()['net'] : 0, 4);
            $own    = round(flowork_net_for_bbid($conn, $bbid, $cur), 4);

            $conn->query("UPDATE payment_status SET net_amount=$net_tg
                WHERE transporter_growersid=$tg AND sell_date='$d' AND currency='$cur' AND status IN ('pending','on_hold')");

            // Unreleased batch items for this delivery (one row per delivery, or per grower and day)
            $iq = $conn->query("SELECT pbi.id, pbi.payment_batchid bid, pbi.balanced_balesid bbid, pbi.net_amount, pb.status
                FROM payment_batch_item pbi JOIN payment_batch pb ON pb.id=pbi.payment_batchid
                WHERE pbi.transporter_growersid=$tg AND pbi.currency='$cur' AND pb.sell_date='$d'
                  AND pb.status IN ('draft','reviewed','approved')
                  AND (pbi.balanced_balesid=$bbid OR pbi.balanced_balesid IS NULL OR pbi.balanced_balesid=0)
                  AND NOT EXISTS (SELECT 1 FROM payment_status ps WHERE ps.transporter_growersid=$tg AND ps.sell_date='$d'
                                  AND ps.currency='$cur' AND ps.status IN ('paid','released','partial'))");
            while ($iq && $iq !== true && $it = $iq->fetch_assoc()) {
                $new = (int)$it['bbid'] === $bbid ? $own : $net_tg;
                if (abs((float)$it['net_amount'] - $new) < 0.00005) continue;
                $conn->query("UPDATE payment_batch_item SET net_amount=$new WHERE id=" . (int)$it['id']);
                $bid = (int)$it['bid'];
                if ($it['status'] !== 'draft' && !in_array($bid, $to_draft, true)) {
                    $conn->query("UPDATE payment_batch SET status='draft', reviewed_by=NULL, reviewed_at=NULL,
                        approved_by=NULL, approved_at=NULL WHERE id=$bid AND status IN ('reviewed','approved')");
                    $to_draft[] = $bid;
                    $m = $conn->real_escape_string("Payment batch #$bid went back to draft: its amounts changed when balanced sales were recalculated. Review and approve it again.");
                    $conn->query("INSERT INTO event_notifications (event_type,message) VALUES ('rate_updated','$m')");
                }
                if (function_exists('audit_log')) {
                    audit_log($conn, 0, $userid, 'payment_batch_item', 'recalc', (int)$it['id'],
                        ['net_amount' => (float)$it['net_amount']], ['net_amount' => $new]);
                }
            }
        }
        return $to_draft;
    }
}

if (!function_exists('flowork_recalculate_balance_batch')) {
    /**
     * Recalculate one delivery (charges, stop-order deductions, split, net).
     * It deletes and re-inserts the delivery's rows in six tables, so it runs
     * in one transaction: a crash or a concurrent recalculation can no longer
     * leave the delivery with half its rows.
     */
    function flowork_recalculate_balance_batch($conn, $bbid, $spid, $userid) {
        if (!empty($GLOBALS['flowork_in_txn'])) {
            return flowork_recalculate_balance_batch_body($conn, $bbid, $spid, $userid);
        }
        flowork_ensure_rule_columns($conn);   // ALTER commits implicitly: never inside the transaction
        $GLOBALS['flowork_in_txn'] = true;
        $conn->begin_transaction();
        try {
            $result = flowork_recalculate_balance_batch_body($conn, $bbid, $spid, $userid);
            // Payments not yet made follow the new figures (pending payments and unreleased batches)
            if ($result) $result['batches_to_draft'] = flowork_refresh_unpaid_amounts($conn, (int)$bbid, (int)$userid);
            $conn->commit();
            return $result;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        } finally {
            $GLOBALS['flowork_in_txn'] = false;
        }
    }

    function flowork_recalculate_balance_batch_body($conn, $bbid, $spid, $userid) {
        $bbid = (int)$bbid;
        $spid = (int)$spid;
        $userid = (int)$userid;

        // 1. Get balanced_bales info
        $bb_q = $conn->query("SELECT * FROM balanced_bales WHERE id=$bbid LIMIT 1");
        $bb = ($bb_q && $bb_q !== true) ? $bb_q->fetch_assoc() : null;
        if (!$bb) return false;

        $tgid = (int)$bb['transporter_growersid'];
        $splitid = (int)$bb['splitid'];
        $growerid = (int)$bb['growerid'];
        $today = date('Y-m-d', strtotime($bb['created_at']));

        // Load bales for gross and mass
        $res = $conn->query("
            SELECT bj.id, bm.mass, tk.start_of_dayid,
                   sb.id sold_id, sb.price, COALESCE(sb.sale_code,'') sale_code,
                   er.id exchange_rateid, er.amount exchange_rate_amount
            FROM bale_junus bj
            JOIN bale_mass bm ON bm.id=bj.bale_massid
            JOIN tickets tk ON tk.bale_junusid=bj.id
            LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
            LEFT JOIN exchange_rate er ON er.id = (SELECT er1.id FROM exchange_rate er1 WHERE er1.start_of_dayid = tk.start_of_dayid ORDER BY er1.active DESC, er1.id DESC LIMIT 1)
            WHERE bj.transporter_growersid=$tgid AND bj.splitid=$splitid");
        $bales = ($res && $res !== true) ? $res->fetch_all(MYSQLI_ASSOC) : [];
        if (empty($bales)) return false;

        $gross=0; $ytd_mass=0; $sold_cnt=0; $ex_rateid=0; $ex_rate_amt=0;
        foreach ($bales as $b) {
            $is_rej = strtoupper(substr(trim($b['sale_code']),0,1))==='R' && strlen(trim($b['sale_code']))<=3;
            if (!$is_rej && $b['sold_id']) {
                $p = (float)$b['price']; if ($p > 10) $p /= 100;
                $ytd_mass += (float)$b['mass'];
                $gross    += (float)$b['mass'] * $p;
                if (!$ex_rateid) { $ex_rateid=(int)$b['exchange_rateid']; $ex_rate_amt=(float)$b['exchange_rate_amount']; }
                $sold_cnt++;
            }
        }

        if (!$ex_rateid || !$ex_rate_amt) {
            $_er = $conn->query("SELECT er.id,er.amount FROM exchange_rate er
                JOIN start_of_day sod ON sod.id=er.start_of_dayid WHERE sod.active=1 ORDER BY er.id DESC LIMIT 1");
            if (!$_er || !$_er->num_rows)
                $_er = $conn->query("SELECT id,amount FROM exchange_rate ORDER BY id DESC LIMIT 1");
            if ($_er && $_er !== true && $_er->num_rows) {
                $er = $_er->fetch_assoc();
                $ex_rateid = (int)$er['id']; $ex_rate_amt = (float)$er['amount'];
            }
        }
        if (!$ex_rate_amt) $ex_rate_amt = 1;
        $avg_price = ($sold_cnt > 0 && $ytd_mass > 0) ? $gross / $ytd_mass : 0;

        // Reset previous stop order states for this batch before calculating
        // Mark previously deducted TIMB internal stop orders as unprocessed
        $prev_iso_q = $conn->query("SELECT internal_stop_order_amountid FROM internal_stop_order_deducted_amounts WHERE balanced_balesid=$bbid");
        if ($prev_iso_q && $prev_iso_q->num_rows > 0) {
            while ($prev_iso = $prev_iso_q->fetch_assoc()) {
                $conn->query("UPDATE internal_stop_order_amount SET processed=0 WHERE id=".(int)$prev_iso['internal_stop_order_amountid']);
            }
        }
        $conn->query("DELETE FROM internal_stop_order_deducted_amounts WHERE balanced_balesid=$bbid");

        // Mark previously deducted TIMB external stop orders as unprocessed
        $prev_ext_q = $conn->query("SELECT stoporderid FROM stoporder_deductions WHERE balanced_balesid=$bbid");
        if ($prev_ext_q && $prev_ext_q->num_rows > 0) {
            while ($prev_ext = $prev_ext_q->fetch_assoc()) {
                $conn->query("UPDATE stoporders SET processed=0 WHERE id=".(int)$prev_ext['stoporderid']);
            }
        }
        $conn->query("DELETE FROM stoporder_deductions WHERE balanced_balesid=$bbid");

        // Clean previous split values, daily totals, and charges
        $conn->query("DELETE FROM internal_stoporder_deductions WHERE balanced_balesid=$bbid");
        $conn->query("DELETE FROM daily_totals_split_amounts WHERE balanced_balesid=$bbid");
        $conn->query("DELETE FROM daily_totals_net_amount WHERE balanced_balesid=$bbid");
        $conn->query("DELETE FROM balanced_bales_charges WHERE balanced_balesid=$bbid");
        $conn->query("DELETE FROM daily_totals WHERE balanced_balesid=$bbid");

        // Insert fresh daily_totals
        $laid_cnt = count($bales);
        $rej_cnt_bal = 0;
        foreach ($bales as $b) {
            $sc = strtoupper(trim($b['sale_code']));
            if (in_array($sc,['R','CO','RO'])||($sc&&$sc[0]==='R'&&strlen($sc)<=3)) $rej_cnt_bal++;
        }
        $er_val = $ex_rateid > 0 ? $ex_rateid : 'NULL';
        $conn->query("INSERT INTO daily_totals
            (userid,balanced_balesid,exchange_rateid,mass,usd_value,zim_value,
             average_price,laid_bales,sold_bales,rejected_bales,created_at)
            VALUES ($userid,$bbid,$er_val,$ytd_mass,$gross,($gross * $ex_rate_amt),
             $avg_price,$laid_cnt,$sold_cnt,$rej_cnt_bal,'$today')");

        // Stop-order mode and RBZ split: the ones recorded on this delivery
        // when it was first calculated, so a later recalculation reproduces
        // its figures instead of applying whatever Settings say today.
        [$so_mode, $rbz_pct] = flowork_delivery_rules($conn, $bb, $spid);

        // Load both types of TIMB stop orders (internal and external)
        $all_so = [];
        
        // Internal TIMB stop orders
        $timb_so_q = $conn->query("
            SELECT isoa.id, isoa.amount, isoa.priority,
                   COALESCE(NULLIF(iso.description,''), gc.name, gc.creditor_name, iso.creditor_number) AS description,
                   iso.creditor_number
            FROM internal_stop_order_amount isoa
            JOIN internal_stop_order iso ON iso.id = isoa.internal_stop_orderid
            LEFT JOIN grower_creditors gc ON gc.creditor_no = iso.creditor_number
            WHERE isoa.growerid = $growerid
              AND isoa.active   = 1
              AND isoa.processed = 0
              AND (iso.selling_pointid = $spid OR iso.selling_pointid = 0 OR $spid = 0)
        ");
        if ($timb_so_q && $timb_so_q !== true) {
            while ($tso = $timb_so_q->fetch_assoc()) {
                $all_so[] = [
                    'type' => 'internal',
                    'id' => (int)$tso['id'],
                    'amount' => (float)$tso['amount'],
                    'priority' => (int)$tso['priority'],
                    'description' => $tso['description'],
                    'creditor_no' => $tso['creditor_number'],
                    'creditor_ref' => ''
                ];
            }
        }

        // External TIMB stop orders
        $ext_so_q = $conn->query("
            SELECT so.id, so.amount_1 AS amount, so.creditor_no, so.creditor_ref, so.priority
            FROM stoporders so
            WHERE so.growerid = $growerid
              AND (so.selling_pointid = $spid OR $spid = 0)
              AND so.active = 1
              AND so.processed = 0
        ");
        if ($ext_so_q && $ext_so_q !== true) {
            while ($eso = $ext_so_q->fetch_assoc()) {
                $all_so[] = [
                    'type' => 'external',
                    'id' => (int)$eso['id'],
                    'amount' => (float)$eso['amount'],
                    'priority' => (int)$eso['priority'],
                    'description' => 'External Stop Order',
                    'creditor_no' => $eso['creditor_no'],
                    'creditor_ref' => $eso['creditor_ref']
                ];
            }
        }

        // Sort combined stop orders by priority, then by id
        usort($all_so, function($a, $b) {
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }
            return $a['id'] <=> $b['id'];
        });

        $timb_so_total = 0;
        $remaining_for_split = $gross;

        // ── STEP 1: TIMB Stop Orders (Before split deduction if configured) ──
        if ($so_mode === 'before') {
            foreach ($all_so as &$so) {
                $amt = $so['amount'];
                $deduct = min($amt, max(0, $remaining_for_split));
                if ($deduct > 0) {
                    $remaining_for_split -= $deduct;
                    $timb_so_total       += $deduct;
                    
                    if ($so['type'] === 'internal') {
                        $conn->query("INSERT INTO internal_stop_order_deducted_amounts
                            (balanced_balesid, internal_stop_order_amountid, amount_deducted)
                            VALUES ($bbid, {$so['id']}, $deduct)
                            ON DUPLICATE KEY UPDATE amount_deducted=$deduct");
                        $conn->query("UPDATE internal_stop_order_amount SET processed=1 WHERE id={$so['id']}");
                    } else {
                        $ext_cn = $conn->real_escape_string($so['creditor_no']);
                        $ext_cr = $conn->real_escape_string($so['creditor_ref'] ?? '');
                        $conn->query("INSERT INTO stoporder_deductions
                            (balanced_balesid, stoporderid, amount_deducted, creditor_no, creditor_ref, created_at)
                            VALUES ($bbid, {$so['id']}, $deduct, '$ext_cn', '$ext_cr', NOW())
                            ON DUPLICATE KEY UPDATE amount_deducted=$deduct");
                        $conn->query("UPDATE stoporders SET processed=1 WHERE id={$so['id']}");
                    }
                    $so['amount'] -= $deduct;
                }
            }
        }

        // ── STEP 2: RBZ split on remaining base ──
        $usd_portion = $remaining_for_split * $rbz_pct;
        $zig_portion = $remaining_for_split * (1 - $rbz_pct);
        $zig_portion_zig = $zig_portion * $ex_rate_amt;

        $conn->query("INSERT INTO daily_totals_split_amounts
            (userid,balanced_balesid,usd_split_value,zim_split_value,created_at)
            VALUES ($userid,$bbid,$usd_portion,$zig_portion_zig,'$today')");

        // ── STEP 3: Grower charges — USD charges from 70%, ZiG charges from 30% ──
        $cur_usd = $usd_portion;
        $cur_zig = $zig_portion_zig;

        $cq = $conn->query("SELECT gc.id,gc.amount,gc.statutoryid,
            COALESCE(ct.description,'Amount') ct_d,
            COALESCE(cu.description,'USD') cu_d
            FROM grower_charges gc
            LEFT JOIN charge_type ct ON ct.id=gc.charge_typeid
            LEFT JOIN statutory st ON st.id=gc.statutoryid
            LEFT JOIN currency cu ON cu.id=st.currencyid
            WHERE gc.selling_pointid=$spid AND gc.active=1");
        $bank_charge_row = null;
        if ($cq && $cq !== true) while ($ch=$cq->fetch_assoc()) {
            $ca_u=0; $ca_z=0; $a=(float)$ch['amount'];
            if ((int)$ch['statutoryid'] === 7) {
                $bank_charge_row = $ch;
                continue;
            }
            if ($ch['cu_d']==='USD') {
                if ($ch['ct_d']==='Value')             $ca_u=$gross*$a;
                elseif ($ch['ct_d']==='Mass')           $ca_u=$ytd_mass*$a;
                elseif ($ch['ct_d']==='Mass and Value') $ca_u=($ytd_mass+$gross)*$a;
                elseif ($ch['ct_d']==='Bales')          $ca_u=$sold_cnt*$a;
                elseif ($ch['ct_d']==='Amount')         $ca_u=$a;
            } else {
                // On the full ZiG gross (gross USD × the day's exchange rate), not the ZiG portion.
                if ($ch['ct_d']==='Value')             $ca_z=$gross*$ex_rate_amt*$a;
                // The parameter is a USD rate per kg: sold mass × rate × the day's exchange rate.
                elseif ($ch['ct_d']==='Mass')           $ca_z=$ytd_mass*$a*$ex_rate_amt;
                elseif ($ch['ct_d']==='Bales')          $ca_z=$sold_cnt*$a;
                elseif ($ch['ct_d']==='Amount')         $ca_z=$a;
            }
            $conn->query("INSERT INTO balanced_bales_charges
                (userid,grower_chargeid,balanced_balesid,usd_value,zim_value)
                VALUES ($userid,{$ch['id']},$bbid,$ca_u,$ca_z)");
            $cur_usd -= $ca_u;
            $cur_zig -= $ca_z;
        }

        // ── STEP 4: Internal company stop orders (transport, advances, loans) ──
        // Each is taken once, by one balancing, and recorded in internal_stoporder_deductions:
        // one tied to a delivery note by that note's own split (split 1 when none is set),
        // one tied only to the grower by the grower's next balancing. Before this, every
        // split and every later sale day took them again.
        $iq = $conn->query("SELECT i.id, i.amount FROM internal_stoporders i
            LEFT JOIN internal_stoporder_deductions d ON d.internal_stoporderid=i.id
            WHERE i.active=1 AND i.amount>0 AND d.id IS NULL
              AND ((i.transporter_growersid=$tgid AND COALESCE(NULLIF(i.splitid,0),1)=$splitid)
                OR ((i.transporter_growersid IS NULL OR i.transporter_growersid=0) AND i.growerid=$growerid))
            ORDER BY (i.transporter_growersid=$tgid) DESC, i.id");
        if ($iq && $iq !== true) while ($so=$iq->fetch_assoc()) {
            $deduct = min((float)$so['amount'], max(0, $cur_usd));
            if ($deduct <= 0) continue;
            $cur_usd -= $deduct;
            $conn->query("INSERT INTO internal_stoporder_deductions (internal_stoporderid, balanced_balesid, amount_deducted, created_at)
                VALUES (".(int)$so['id'].", $bbid, $deduct, NOW())");
        }

        // ── STEP 4b: TIMB Stop Orders (After split deduction if configured) ──
        if ($so_mode === 'after') {
            foreach ($all_so as &$so) {
                $amt = $so['amount'];
                $deduct = min($amt, max(0, $cur_usd));
                if ($deduct > 0) {
                    $cur_usd       -= $deduct;
                    $timb_so_total += $deduct;
                    
                    if ($so['type'] === 'internal') {
                        $conn->query("INSERT INTO internal_stop_order_deducted_amounts
                            (balanced_balesid, internal_stop_order_amountid, amount_deducted)
                            VALUES ($bbid, {$so['id']}, $deduct)
                            ON DUPLICATE KEY UPDATE amount_deducted=$deduct");
                        $conn->query("UPDATE internal_stop_order_amount SET processed=1 WHERE id={$so['id']}");
                    } else {
                        $ext_cn = $conn->real_escape_string($so['creditor_no']);
                        $ext_cr = $conn->real_escape_string($so['creditor_ref'] ?? '');
                        $conn->query("INSERT INTO stoporder_deductions
                            (balanced_balesid, stoporderid, amount_deducted, creditor_no, creditor_ref, created_at)
                            VALUES ($bbid, {$so['id']}, $deduct, '$ext_cn', '$ext_cr', NOW())
                            ON DUPLICATE KEY UPDATE amount_deducted=$deduct");
                        $conn->query("UPDATE stoporders SET processed=1 WHERE id={$so['id']}");
                    }
                    $so['amount'] -= $deduct;
                }
            }
        }

        // ── STEP 4c: Calculate and insert dynamic Bank Charge ──
        if (isset($bank_charge_row)) {
            $bc_rate = (float)$bank_charge_row['amount'];
            $bc_usd = max(0, $cur_usd * $bc_rate);
            $bc_zig = max(0, $cur_zig * $bc_rate);
            
            $conn->query("INSERT INTO balanced_bales_charges
                (userid,grower_chargeid,balanced_balesid,usd_value,zim_value)
                VALUES ($userid,{$bank_charge_row['id']},$bbid,$bc_usd,$bc_zig)");
            
            $cur_usd -= $bc_usd;
            $cur_zig -= $bc_zig;
        }

        // ── STEP 5: Net payout ──
        if ($cur_usd < 0) $cur_usd = 0;
        if ($cur_zig < 0) $cur_zig = 0;
        flowork_save_net($conn, $userid, $bbid, $cur_usd, $cur_zig, $today);

        return ['net_usd' => $cur_usd, 'net_zig' => $cur_zig, 'timb_so_total' => $timb_so_total];
    }
}

if (!function_exists('flowork_deduct_stoporders')) {
    function flowork_deduct_stoporders($conn, $spid = 0) {
        $spid = (int)$spid;
        $userid = isset($_SESSION['userid']) ? (int)$_SESSION['userid'] : 1;

        // Find all growers who have active, unprocessed stop orders
        $growers_q = $conn->query("
            SELECT DISTINCT growerid
            FROM stoporders
            WHERE (selling_pointid = $spid OR $spid = 0)
              AND active = 1
              AND processed = 0
            
            UNION
            
            SELECT DISTINCT isoa.growerid
            FROM internal_stop_order_amount isoa
            JOIN internal_stop_order iso ON iso.id = isoa.internal_stop_orderid
            WHERE (iso.selling_pointid = $spid OR iso.selling_pointid = 0 OR $spid = 0)
              AND isoa.active = 1
              AND isoa.processed = 0
        ");

        $deducted_count = 0;
        if ($growers_q && $growers_q->num_rows > 0) {
            while ($gr = $growers_q->fetch_assoc()) {
                $growerid = (int)$gr['growerid'];
                if (!$growerid) continue;

                // Find the latest balanced batch for this grower
                $bb_q = $conn->query("
                    SELECT bb.id AS bbid, g.selling_pointid, bb.transporter_growersid tgid, DATE(bb.created_at) d
                    FROM balanced_bales bb
                    JOIN growers g ON g.id = bb.growerid
                    WHERE bb.growerid = $growerid
                    ORDER BY bb.id DESC
                    LIMIT 1
                ");

                if ($bb_q && $bb_q->num_rows > 0) {
                    $bb_row = $bb_q->fetch_assoc();
                    $bbid = (int)$bb_row['bbid'];
                    $row_spid = (int)$bb_row['selling_pointid'];
                    // A paid delivery keeps the figures it was paid on.
                    if (flowork_delivery_settled($conn, (int)$bb_row['tgid'], (string)$bb_row['d'])) continue;

                    // Run the unified settings-compliant calculation pipeline on the batch
                    $calc = flowork_recalculate_balance_batch($conn, $bbid, $row_spid, $userid);
                    if ($calc) {
                        $conn->query("UPDATE balanced_bales SET stoporder_processed = 1 WHERE id = $bbid");
                        $deducted_count += $calc['timb_so_total'];
                    }
                }
            }
        }
        return $deducted_count;
    }
}
