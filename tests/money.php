<?php
/**
 * Money-flow tests — command line only:
 *
 *   C:/xampp/php/php.exe tests/money.php [--keep-db]
 *
 * 1. Recalculation (on a throwaway copy of the database, never the live one):
 *    clones the app database into zz_money_test_<random>, recalculates every
 *    balanced delivery twice with flowork_recalculate_balance_batch() and checks
 *      - the same figures both times (no drift, no duplicate rows),
 *      - exactly one daily_totals row and one net row per delivery,
 *      - 0 <= net USD <= gross USD and net ZiG >= 0,
 *      - every delivery has a recorded rule, and pinned figures for the
 *        8 July 2026 deliveries under rule A (net reconciliation, column A).
 *    The copy is dropped at the end (--keep-db keeps it for inspection).
 * 2. Live data checks (read-only on the live database):
 *      - one stored net per delivery (unique key present),
 *      - every paid or released payment equals the stored net it settles.
 *
 * Exit code 0 when everything passes, 1 otherwise.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_OFF);

$root = dirname(__DIR__);
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['DOCUMENT_ROOT']   = dirname($root);
ob_start();
require $root . '/includes/conn.php';                 // live connection + credentials
require_once $root . '/includes/stoporder_deduct_helper.php';
ob_end_clean();
/** @var mysqli $conn */

$failures = 0;
function out(string $s = ''): void { echo $s, PHP_EOL; }
function check(bool $ok, string $label, string $detail = ''): void {
    global $failures;
    if (!$ok) $failures++;
    out(sprintf('  [%s] %-46s %s', $ok ? 'PASS' : 'FAIL', $label, $detail));
}
$all = fn(mysqli $c, string $sql) => ($r = $c->query($sql)) && $r !== true ? $r->fetch_all(MYSQLI_ASSOC) : [];

// ── 1. Recalculation on a throwaway copy ──────────────────────────────────
out('== 1. Recalculation (throwaway copy) ==');
$live_db = preg_replace('/[^a-zA-Z0-9_]/', '', $_db_name);
$test_db = 'zz_money_test_' . bin2hex(random_bytes(3));
$bin     = is_dir('C:/xampp/mysql/bin') ? 'C:/xampp/mysql/bin/' : '';
$dump    = tempnam(sys_get_temp_dir(), 'fwm');
// The app's own MySQL user may only reach its database, so the throwaway copy
// is made with an admin account: MYSQL_ADMIN_USER / MYSQL_ADMIN_PASS, or
// XAMPP's root without a password.
$adm_user = getenv('MYSQL_ADMIN_USER') ?: 'root';
$adm_pass = getenv('MYSQL_ADMIN_PASS') ?: '';
$adm = @new mysqli($_db_host, $adm_user, $adm_pass);
if ($adm->connect_error) { check(false, 'admin connection for the copy', $adm->connect_error . ' (set MYSQL_ADMIN_USER / MYSQL_ADMIN_PASS)'); exit(1); }
putenv('MYSQL_PWD=' . $adm_pass);
$auth = ' -h ' . escapeshellarg($_db_host) . ' -u ' . escapeshellarg($adm_user) . ' ';
exec(escapeshellarg($bin . 'mysqldump') . $auth . '--single-transaction ' . escapeshellarg($live_db) . ' > ' . escapeshellarg($dump), $o, $rc1);
$adm->query("CREATE DATABASE `$test_db` CHARACTER SET utf8mb4");
exec(escapeshellarg($bin . 'mysql') . $auth . escapeshellarg($test_db) . ' < ' . escapeshellarg($dump), $o, $rc2);
putenv('MYSQL_PWD');
@unlink($dump);
$keep_db = in_array('--keep-db', $argv, true);
register_shutdown_function(function () use ($adm, $test_db, $keep_db) {
    if ($keep_db) { out("  (kept $test_db)"); return; }
    $adm->query("DROP DATABASE IF EXISTS `$test_db`");
});
if ($rc1 !== 0 || $rc2 !== 0) { check(false, 'clone database', "mysqldump rc=$rc1, mysql rc=$rc2"); exit(1); }

$t = new mysqli($_db_host, $adm_user, $adm_pass, $test_db);
if ($t->connect_error) { check(false, 'connect to copy', $t->connect_error); exit(1); }
$t->set_charset('utf8mb4');
$t->query("SET time_zone = '+02:00'");

$deliveries = $all($t, "SELECT bb.id, COALESCE(NULLIF(g.selling_pointid,0), bb.selling_pointid) spid, dt.usd_value gross
    FROM balanced_bales bb JOIN growers g ON g.id=bb.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id ORDER BY bb.id");
check(count($deliveries) > 0, 'deliveries to recalculate', count($deliveries) . ' found');

$net = fn(int $id) => $all($t, "SELECT COUNT(*) n, MAX(usd_net_value) usd, MAX(zim_net_value) zig
    FROM daily_totals_net_amount WHERE balanced_balesid=$id")[0];
$dt  = fn(int $id) => $all($t, "SELECT COUNT(*) n, MAX(usd_value) usd FROM daily_totals WHERE balanced_balesid=$id")[0];

$first = [];
foreach ([1, 2] as $pass) {
    foreach ($deliveries as $d) {
        $id = (int)$d['id'];
        $r = flowork_recalculate_balance_batch($t, $id, (int)$d['spid'], 1);
        if (!$r) { check(false, "recalculate delivery $id (pass $pass)", 'returned false'); continue; }
        $n = $net($id); $tt = $dt($id);
        $fig = sprintf('%.4f/%.4f', $n['usd'], $n['zig']);
        if ($pass === 1) {
            $first[$id] = $fig;
            $gross = (float)$tt['usd'];
            $ok = (int)$n['n'] === 1 && (int)$tt['n'] === 1
               && (float)$n['usd'] >= 0 && (float)$n['zig'] >= 0 && (float)$n['usd'] <= $gross + 0.005;
            check($ok, "delivery $id rows and bounds",
                sprintf('net rows %d, totals rows %d, gross %.2f, net USD %.2f, ZiG %.2f', $n['n'], $tt['n'], $gross, $n['usd'], $n['zig']));
        } else {
            check($first[$id] === $fig && (int)$n['n'] === 1, "delivery $id same result twice", $first[$id] === $fig ? $fig : "{$first[$id]} then $fig");
        }
    }
}

// Every delivery carries the rule it was calculated with, so recalculating
// never depends on today's Settings.
$norule = $all($t, "SELECT COUNT(*) c FROM balanced_bales WHERE rule_so_mode IS NULL OR rule_rbz_usd IS NULL")[0]['c'] ?? '?';
check((string)$norule === '0', 'every delivery has a recorded rule', "$norule without");

// Pinned: the 8 July 2026 deliveries under rule A (stop orders after the
// split, 70/30), as decided by the owner on 2026-10-17 — reconciliation column A.
$pinned = [73 => [0.00, 20675.06], 74 => [190.69, 8358.90], 75 => [0.00, 7582.89], 76 => [0.00, 6819.23]];
foreach ($pinned as $id => [$usd, $zig]) {
    $n = $net($id);
    if ((int)$n['n'] === 0) { out("  [SKIP] delivery $id not in this database"); continue; }
    $ok = abs((float)$n['usd'] - $usd) < 0.01 && abs((float)$n['zig'] - $zig) < 0.01;
    check($ok, "delivery $id matches reconciliation (A)", sprintf('got USD %.2f ZiG %.2f, expected USD %.2f ZiG %.2f', $n['usd'], $n['zig'], $usd, $zig));
}
$t->close();

// ── 2. Live data checks (read-only) ───────────────────────────────────────
out();
out('== 2. Live data checks (read-only) ==');
$uq = $all($conn, "SHOW INDEX FROM daily_totals_net_amount WHERE Key_name='uq_dtn_bbid'");
check((bool)$uq, 'one stored net per delivery (unique key)', $uq ? 'uq_dtn_bbid present' : 'missing — run scratch/net_amount_cleanup.php');

$rows = $all($conn, "SELECT ps.id, ps.transporter_growersid tgid, ps.sell_date, ps.currency, ps.status,
        COALESCE(NULLIF(ps.amount_paid,0), ps.net_amount) paid,
        (SELECT COALESCE(SUM(IF(ps.currency='zig', n.zim_net_value, n.usd_net_value)),0)
           FROM balanced_bales bb JOIN daily_totals_net_amount n ON n.balanced_balesid=bb.id
          WHERE bb.transporter_growersid=ps.transporter_growersid AND DATE(bb.created_at)=ps.sell_date) owed
    FROM payment_status ps WHERE ps.status IN ('paid','released') ORDER BY ps.id");
// A difference the owner has written off (includes/pay_writeoffs.php) counts as settled.
require_once $root . '/includes/pay_writeoffs.php';
$writeoffs = flowork_writeoffs($conn);
foreach ($rows as $r) {
    $diff = (float)$r['paid'] - (float)$r['owed'];
    $wo   = $writeoffs[(int)$r['id']] ?? 0.0;
    $left = $diff - $wo;
    check(abs($left) < 0.01, "payment {$r['id']} equals stored net",
        sprintf('tgid %d %s %s: paid %.2f, stored net %.2f%s%s', $r['tgid'], $r['sell_date'], strtoupper($r['currency']),
            $r['paid'], $r['owed'],
            $wo ? sprintf(', written off %.2f', $wo) : '',
            abs($left) >= 0.01 ? sprintf(' (%s%.2f unexplained)', $left > 0 ? 'over +' : 'under ', $left) : ''));
}
if (!$rows) out('  [INFO] no paid or released payments');

// A sale day can hold several exchange_rate rows (each rate change adds one).
// A join that matched them all used to count every bale once per row, which
// doubled delivery 77. Stored totals must match the delivery's real bales.
$dup = $all($conn, "SELECT dt.balanced_balesid bbid, dt.laid_bales stored, COUNT(bj.id) actual
    FROM daily_totals dt JOIN balanced_bales bb ON bb.id=dt.balanced_balesid
    JOIN bale_junus bj ON bj.transporter_growersid=bb.transporter_growersid AND bj.splitid=bb.splitid
    JOIN tickets tk ON tk.bale_junusid=bj.id JOIN sold_bales sb ON sb.ticketsid=tk.id
    GROUP BY dt.id HAVING stored <> actual");
check(!$dup, 'stored bale counts match the real bales', $dup
    ? implode(', ', array_map(fn($d) => "delivery {$d['bbid']}: stored {$d['stored']}, actual {$d['actual']}", $dup))
    : 'every delivery');

out();
out($failures ? "RESULT: FAIL ($failures)" : 'RESULT: PASS');
exit($failures ? 1 : 0);
