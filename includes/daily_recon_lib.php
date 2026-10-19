<?php
/**
 * daily_recon_lib.php — the daily reconciliation for one sale day, shared by
 * the command-line report (maintenance/daily_recon.php) and the page
 * (pay_daily_recon.php). Read-only.
 *
 * flowork_daily_recon($conn, 'YYYY-MM-DD', $floor) returns:
 *   rows    one per balanced delivery (bales, mass, gross, charges, stop
 *           orders, net USD/ZiG, rule, and on the first delivery of each
 *           grower the payment, difference and written-off amount)
 *   totals  bales, mass, gross, charges, timb, internal, net, zig, paid
 *   checks  failures (gross vs bales, net rows, missing rule, payment vs net)
 *   notes   differences that are covered by a recorded write-off
 *   csv     header + lines, ready for fputcsv
 */
require_once __DIR__ . '/pay_writeoffs.php';

if (!function_exists('flowork_daily_recon')) {
    function flowork_daily_recon(mysqli $conn, string $date, int $floor = 0): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('date must be YYYY-MM-DD');
        $fsql = $floor ? "AND (g.selling_pointid=$floor OR bb.selling_pointid=$floor)" : '';
        $q = $conn->query("
            SELECT bb.id bbid, bb.transporter_growersid tgid, g.grower_num, CONCAT(g.name,' ',g.surname) grower,
                   bb.splitid, bb.rule_so_mode, bb.rule_rbz_usd,
                   dt.sold_bales, dt.rejected_bales, dt.mass, dt.usd_value gross_usd,
                   (SELECT COALESCE(SUM(usd_value),0) FROM balanced_bales_charges c WHERE c.balanced_balesid=bb.id) charges_usd,
                   (SELECT COALESCE(SUM(amount_deducted),0) FROM stoporder_deductions s WHERE s.balanced_balesid=bb.id) timb_so,
                   (SELECT COALESCE(SUM(COALESCE(amount_deducted, amount)),0) FROM internal_stop_order_deducted_amounts i WHERE i.balanced_balesid=bb.id) internal_so,
                   (SELECT COUNT(*) FROM daily_totals_net_amount n WHERE n.balanced_balesid=bb.id) net_rows,
                   n.usd_net_value net_usd, n.zim_net_value net_zig
            FROM balanced_bales bb
            JOIN growers g ON g.id = bb.growerid
            LEFT JOIN daily_totals dt ON dt.balanced_balesid = bb.id
            LEFT JOIN daily_totals_net_amount n ON n.balanced_balesid = bb.id
            WHERE DATE(bb.created_at) = '$date' $fsql
            ORDER BY g.grower_num, bb.id");
        $rows = ($q && $q !== true) ? $q->fetch_all(MYSQLI_ASSOC) : [];

        $payments = [];
        $pq = $conn->query("SELECT id, transporter_growersid tgid, currency, status, net_amount, amount_paid FROM payment_status WHERE sell_date='$date'");
        while ($pq && $pq !== true && $p = $pq->fetch_assoc()) $payments[$p['tgid']][$p['currency']] = $p;
        $writeoffs = flowork_writeoffs($conn);

        $checks = []; $notes = [];
        $tot = array_fill_keys(['bales','mass','gross','charges','timb','internal','net','zig','paid'], 0.0);
        $by_tg = [];
        foreach ($rows as $i => $r) {
            $rows[$i]['rule'] = $r['rule_so_mode']
                ? $r['rule_so_mode'] . ' ' . round((float)$r['rule_rbz_usd'] * 100) . '/' . round((1 - (float)$r['rule_rbz_usd']) * 100) : 'none';
            foreach ([['bales','sold_bales'],['mass','mass'],['gross','gross_usd'],['charges','charges_usd'],['timb','timb_so'],['internal','internal_so'],['net','net_usd'],['zig','net_zig']] as [$k, $c]) $tot[$k] += (float)$r[$c];
            $by_tg[$r['tgid']][] = $i;
            $g = flowork_recon_bales_gross($conn, (int)$r['tgid'], (int)$r['splitid']);
            if (abs($g - (float)$r['gross_usd']) > 0.01) $checks[] = "delivery {$r['bbid']}: gross on record " . number_format((float)$r['gross_usd'], 2) . " but the bales add up to " . number_format($g, 2);
            if ((int)$r['net_rows'] !== 1) $checks[] = "delivery {$r['bbid']}: {$r['net_rows']} stored net rows (expected 1)";
            if (!$r['rule_so_mode']) $checks[] = "delivery {$r['bbid']}: no recorded rule";
        }

        foreach ($by_tg as $tg => $idx) {
            $owed = array_sum(array_map(fn($i) => (float)$rows[$i]['net_usd'], $idx));
            $p = $payments[$tg]['usd'] ?? null;
            $paid = $p && in_array($p['status'], ['paid', 'released'], true) ? (float)($p['amount_paid'] ?: $p['net_amount']) : null;
            $wo = $p ? ($writeoffs[(int)$p['id']] ?? 0.0) : 0.0;
            $tot['paid'] += (float)$paid;
            foreach ($idx as $n => $i) {
                $rows[$i]['pay_status']  = $n === 0 ? ($p['status'] ?? 'none') : '';
                $rows[$i]['paid']        = $n === 0 ? $paid : null;
                $rows[$i]['difference']  = $n === 0 && $paid !== null ? $paid - $owed : null;
                $rows[$i]['written_off'] = $n === 0 && $wo ? $wo : null;
            }
            $grower = $rows[$idx[0]]['grower_num'];
            if ($paid !== null && abs($paid - $owed - $wo) >= 0.01) {
                $checks[] = sprintf("grower %s: paid %.2f USD, stored net %.2f%s (%s%.2f unexplained)", $grower, $paid, $owed,
                    $wo ? sprintf(', written off %.2f', $wo) : '', $paid - $owed - $wo > 0 ? 'over +' : 'under ', $paid - $owed - $wo);
            } elseif ($wo) {
                $notes[] = sprintf("grower %s: paid %.2f USD, stored net %.2f, difference %.2f written off", $grower, $paid, $owed, $wo);
            }
        }

        $csv = [['bbid','grower_num','grower','rule','sold_bales','rejected','mass_kg','gross_usd','charges_usd','timb_so_usd','internal_so_usd','net_usd','net_zig','paid_usd_status','paid_usd','difference_usd','written_off_usd']];
        foreach ($rows as $r) {
            $csv[] = [$r['bbid'], $r['grower_num'], $r['grower'], $r['rule'], $r['sold_bales'], $r['rejected_bales'],
                round((float)$r['mass'], 1), round((float)$r['gross_usd'], 2), round((float)$r['charges_usd'], 2), round((float)$r['timb_so'], 2),
                round((float)$r['internal_so'], 2), round((float)$r['net_usd'], 2), round((float)$r['net_zig'], 2),
                $r['pay_status'], $r['paid'] !== null ? round($r['paid'], 2) : '',
                $r['difference'] !== null ? round($r['difference'], 2) : '', $r['written_off'] !== null ? round($r['written_off'], 2) : ''];
        }
        return ['date' => $date, 'floor' => $floor, 'rows' => $rows, 'totals' => $tot, 'checks' => $checks, 'notes' => $notes, 'csv' => $csv];
    }

    /** Gross recomputed from the sold bales themselves (rejected bales excluded). */
    function flowork_recon_bales_gross(mysqli $conn, int $tgid, int $split): float {
        $q = $conn->query("SELECT bm.mass, sb.price, COALESCE(sb.sale_code,'') sc
            FROM bale_junus bj JOIN bale_mass bm ON bm.id=bj.bale_massid
            JOIN tickets tk ON tk.bale_junusid=bj.id JOIN sold_bales sb ON sb.ticketsid=tk.id
            WHERE bj.transporter_growersid=$tgid AND bj.splitid=$split");
        $g = 0.0;
        while ($q && $q !== true && $b = $q->fetch_assoc()) {
            $sc = strtoupper(trim($b['sc']));
            if ($sc !== '' && $sc[0] === 'R' && strlen($sc) <= 3) continue;
            $p = (float)$b['price']; if ($p > 10) $p /= 100;
            $g += $p * (float)$b['mass'];
        }
        return $g;
    }
}
