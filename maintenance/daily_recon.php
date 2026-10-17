<?php
/**
 * Daily reconciliation report for one sale day — command line only.
 *
 *   php maintenance/daily_recon.php --date=2026-07-08 [--floor=1] [--csv=C:\path\recon_2026-07-08.csv]
 *
 * One line per balanced delivery: bales, mass, gross, charges, stop orders,
 * net (USD and ZiG), the rule it was calculated with, and the payment made.
 * Then checks that should all say OK during the pilot:
 *   - gross equals the sold bales x price x mass (daily_totals vs bales)
 *   - exactly one stored net per delivery, and a recorded rule
 *   - every paid/released payment equals the stored net it settles
 * Finance compares the CSV with the manual tickets, sales sheets and payment
 * register for the day. Exit code 0 when every check is OK, 1 otherwise.
 * Read-only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['DOCUMENT_ROOT']   = dirname($root);
ob_start();
require $root . '/includes/conn.php';
ob_end_clean();

$o = getopt('', ['date:', 'floor:', 'csv:']);
$date = (string)($o['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { fwrite(STDERR, "--date must be YYYY-MM-DD\n"); exit(2); }
$floor = (int)($o['floor'] ?? 0);
$fsql  = $floor ? "AND (g.selling_pointid=$floor OR bb.selling_pointid=$floor)" : '';

$rows = $conn->query("
    SELECT bb.id bbid, bb.transporter_growersid tgid, g.grower_num, CONCAT(g.name,' ',g.surname) grower,
           COALESCE(NULLIF(g.selling_pointid,0), bb.selling_pointid) spid, bb.splitid,
           bb.rule_so_mode, bb.rule_rbz_usd,
           dt.sold_bales, dt.rejected_bales, dt.mass, dt.usd_value gross_usd, dt.zim_value gross_zig,
           sa.usd_split_value split_usd, sa.zim_split_value split_zig,
           (SELECT COALESCE(SUM(usd_value),0) FROM balanced_bales_charges c WHERE c.balanced_balesid=bb.id) charges_usd,
           (SELECT COALESCE(SUM(zim_value),0) FROM balanced_bales_charges c WHERE c.balanced_balesid=bb.id) charges_zig,
           (SELECT COALESCE(SUM(amount_deducted),0) FROM stoporder_deductions s WHERE s.balanced_balesid=bb.id) timb_so,
           (SELECT COALESCE(SUM(COALESCE(amount_deducted, amount)),0) FROM internal_stop_order_deducted_amounts i WHERE i.balanced_balesid=bb.id) internal_so,
           (SELECT COUNT(*) FROM daily_totals_net_amount n WHERE n.balanced_balesid=bb.id) net_rows,
           n.usd_net_value net_usd, n.zim_net_value net_zig
    FROM balanced_bales bb
    JOIN growers g ON g.id = bb.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid = bb.id
    LEFT JOIN daily_totals_split_amounts sa ON sa.balanced_balesid = bb.id
    LEFT JOIN daily_totals_net_amount n ON n.balanced_balesid = bb.id
    WHERE DATE(bb.created_at) = '$date' $fsql
    ORDER BY g.grower_num, bb.id");
$rows = ($rows && $rows !== true) ? $rows->fetch_all(MYSQLI_ASSOC) : [];

// Gross recomputed from the bales themselves
$bales_gross = function (int $tgid, int $split) use ($conn): float {
    $q = $conn->query("SELECT bm.mass, sb.price, COALESCE(sb.sale_code,'') sc
        FROM bale_junus bj JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN tickets tk ON tk.bale_junusid=bj.id JOIN sold_bales sb ON sb.ticketsid=tk.id
        WHERE bj.transporter_growersid=$tgid AND bj.splitid=$split");
    $g = 0.0;
    while ($q && $q !== true && $b = $q->fetch_assoc()) {
        $sc = strtoupper(trim($b['sc']));
        if ($sc !== '' && $sc[0] === 'R' && strlen($sc) <= 3) continue;   // rejected
        $p = (float)$b['price']; if ($p > 10) $p /= 100;
        $g += $p * (float)$b['mass'];
    }
    return $g;
};

$payments = [];
$pq = $conn->query("SELECT transporter_growersid tgid, currency, status, net_amount, amount_paid, payment_method, reference_number
    FROM payment_status WHERE sell_date='$date'");
while ($pq && $pq !== true && $p = $pq->fetch_assoc()) $payments[$p['tgid']][$p['currency']] = $p;

$fails = 0; $checks = []; $csv = [];
$csv[] = ['bbid','grower_num','grower','rule','sold_bales','rejected','mass_kg','gross_usd','charges_usd','timb_so_usd','internal_so_usd','net_usd','net_zig','paid_usd_status','paid_usd','difference_usd'];
$tot = array_fill_keys(['bales','mass','gross','charges','timb','internal','net','zig','paid'], 0.0);
$by_tg = [];

printf("Daily reconciliation — sale day %s%s — %d deliveries\n\n", $date, $floor ? " — floor $floor" : '', count($rows));
printf("%-5s %-9s %-22s %-11s %5s %8s %10s %9s %9s %9s %9s %11s\n",
    'bbid', 'grower', 'name', 'rule', 'bales', 'mass kg', 'gross USD', 'charges', 'TIMB SO', 'int. SO', 'net USD', 'net ZiG');
foreach ($rows as $r) {
    $rule = $r['rule_so_mode'] ? $r['rule_so_mode'] . ' ' . round((float)$r['rule_rbz_usd'] * 100) . '/' . round((1 - (float)$r['rule_rbz_usd']) * 100) : 'none';
    printf("%-5d %-9s %-22s %-11s %5d %8.0f %10.2f %9.2f %9.2f %9.2f %9.2f %11.2f\n",
        $r['bbid'], $r['grower_num'], mb_strimwidth($r['grower'], 0, 22, '…'), $rule, $r['sold_bales'], $r['mass'],
        $r['gross_usd'], $r['charges_usd'], $r['timb_so'], $r['internal_so'], $r['net_usd'], $r['net_zig']);
    foreach ([['bales','sold_bales'],['mass','mass'],['gross','gross_usd'],['charges','charges_usd'],['timb','timb_so'],['internal','internal_so'],['net','net_usd'],['zig','net_zig']] as [$k, $c]) $tot[$k] += (float)$r[$c];
    $by_tg[$r['tgid']][] = $r;

    $g = $bales_gross((int)$r['tgid'], (int)$r['splitid']);
    if (abs($g - (float)$r['gross_usd']) > 0.01) $checks[] = "delivery {$r['bbid']}: gross on record " . number_format($r['gross_usd'], 2) . " but bales add up to " . number_format($g, 2);
    if ((int)$r['net_rows'] !== 1) $checks[] = "delivery {$r['bbid']}: {$r['net_rows']} stored net rows (expected 1)";
    if (!$r['rule_so_mode']) $checks[] = "delivery {$r['bbid']}: no recorded rule";
}

// Payment per grower and day vs stored net
foreach ($by_tg as $tg => $list) {
    $owed = array_sum(array_map(fn($x) => (float)$x['net_usd'], $list));
    $p = $payments[$tg]['usd'] ?? null;
    $paid = $p && in_array($p['status'], ['paid', 'released'], true) ? (float)($p['amount_paid'] ?: $p['net_amount']) : null;
    $tot['paid'] += (float)$paid;
    foreach ($list as $i => $x) {
        $csv[] = [$x['bbid'], $x['grower_num'], $x['grower'], $x['rule_so_mode'] . ' ' . $x['rule_rbz_usd'], $x['sold_bales'], $x['rejected_bales'],
            round((float)$x['mass'], 1), round((float)$x['gross_usd'], 2), round((float)$x['charges_usd'], 2), round((float)$x['timb_so'], 2),
            round((float)$x['internal_so'], 2), round((float)$x['net_usd'], 2), round((float)$x['net_zig'], 2),
            $i === 0 ? ($p['status'] ?? 'none') : '', $i === 0 && $paid !== null ? round($paid, 2) : '',
            $i === 0 && $paid !== null ? round($paid - $owed, 2) : ''];
    }
    if ($paid !== null && abs($paid - $owed) >= 0.01) {
        $checks[] = sprintf("grower %s: paid %.2f USD, stored net %.2f (%s%.2f)", $list[0]['grower_num'], $paid, $owed, $paid > $owed ? 'over +' : 'under ', $paid - $owed);
    }
}

printf("%-5s %-9s %-22s %-11s %5d %8.0f %10.2f %9.2f %9.2f %9.2f %9.2f %11.2f\n", '', 'TOTAL', '', '', $tot['bales'], $tot['mass'],
    $tot['gross'], $tot['charges'], $tot['timb'], $tot['internal'], $tot['net'], $tot['zig']);
printf("\nPaid (USD, paid or released): %.2f   Stored net USD: %.2f\n\n", $tot['paid'], $tot['net']);

echo "Checks:\n";
if (!$rows) echo "  [INFO] no balanced deliveries on this day\n";
foreach ($checks as $c) { echo "  [FAIL] $c\n"; $fails++; }
if ($rows && !$checks) echo "  [OK] gross matches the bales, one net and a rule per delivery, payments equal the stored net\n";

if (!empty($o['csv'])) {
    $fh = fopen($o['csv'], 'w');
    if (!$fh) { fwrite(STDERR, "cannot write {$o['csv']}\n"); exit(1); }
    fwrite($fh, "\xEF\xBB\xBF");   // Excel opens UTF-8 correctly with a BOM
    foreach ($csv as $line) fputcsv($fh, $line);
    fclose($fh);
    echo "\nCSV written: {$o['csv']}\n";
}
exit($fails ? 1 : 0);
