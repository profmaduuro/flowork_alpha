<?php
/**
 * Daily reconciliation report for one sale day — command line only.
 * (The same report is on the page "Daily Reconciliation", pay_daily_recon.php.)
 *
 *   php maintenance/daily_recon.php --date=2026-07-08 [--floor=1] [--csv=C:\path\recon_2026-07-08.csv]
 *
 * One line per balanced delivery: bales, mass, gross, charges, stop orders,
 * net (USD and ZiG), the rule it was calculated with, and the payment made.
 * Then checks that should all say OK during the pilot:
 *   - gross equals the sold bales x price x mass (daily_totals vs bales)
 *   - exactly one stored net per delivery, and a recorded rule
 *   - every paid/released payment equals the stored net it settles
 *     (or the difference is recorded as written off)
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
require_once $root . '/includes/daily_recon_lib.php';
ob_end_clean();

$o = getopt('', ['date:', 'floor:', 'csv:']);
$date = (string)($o['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { fwrite(STDERR, "--date must be YYYY-MM-DD\n"); exit(2); }
$floor = (int)($o['floor'] ?? 0);
$r = flowork_daily_recon($conn, $date, $floor);
$t = $r['totals'];

printf("Daily reconciliation — sale day %s%s — %d deliveries\n\n", $date, $floor ? " — floor $floor" : '', count($r['rows']));
printf("%-5s %-9s %-22s %-11s %5s %8s %10s %9s %9s %9s %9s %11s\n",
    'bbid', 'grower', 'name', 'rule', 'bales', 'mass kg', 'gross USD', 'charges', 'TIMB SO', 'int. SO', 'net USD', 'net ZiG');
foreach ($r['rows'] as $x) {
    printf("%-5d %-9s %-22s %-11s %5d %8.0f %10.2f %9.2f %9.2f %9.2f %9.2f %11.2f\n",
        $x['bbid'], $x['grower_num'], mb_strimwidth($x['grower'], 0, 22, '…'), $x['rule'], $x['sold_bales'], $x['mass'],
        $x['gross_usd'], $x['charges_usd'], $x['timb_so'], $x['internal_so'], $x['net_usd'], $x['net_zig']);
}
printf("%-5s %-9s %-22s %-11s %5d %8.0f %10.2f %9.2f %9.2f %9.2f %9.2f %11.2f\n", '', 'TOTAL', '', '', $t['bales'], $t['mass'],
    $t['gross'], $t['charges'], $t['timb'], $t['internal'], $t['net'], $t['zig']);
printf("\nPaid (USD, paid or released): %.2f   Stored net USD: %.2f\n\n", $t['paid'], $t['net']);

echo "Checks:\n";
if (!$r['rows']) echo "  [INFO] no balanced deliveries on this day\n";
foreach ($r['checks'] as $c) echo "  [FAIL] $c\n";
foreach ($r['notes'] as $n) echo "  [WRITTEN OFF] $n\n";
if ($r['rows'] && !$r['checks']) echo "  [OK] gross matches the bales, one net and a rule per delivery, payments equal the stored net\n";

if (!empty($o['csv'])) {
    $fh = fopen($o['csv'], 'w');
    if (!$fh) { fwrite(STDERR, "cannot write {$o['csv']}\n"); exit(1); }
    fwrite($fh, "\xEF\xBB\xBF");   // Excel opens UTF-8 correctly with a BOM
    foreach ($r['csv'] as $line) fputcsv($fh, $line);
    fclose($fh);
    echo "\nCSV written: {$o['csv']}\n";
}
exit($r['checks'] ? 1 : 0);
