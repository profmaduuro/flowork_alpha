<?php
/**
 * Flowork self-update — run by cron (cPanel) or Task Scheduler (XAMPP), every 15 minutes:
 *
 *   php /path/to/flowork/updater/update.php
 *
 * Options:
 *   --check            only report whether a newer version is available
 *   --now              install now, ignoring the update hours and the idle check
 *   --package=<dir>    install a package from a folder instead of GitHub (testing)
 *   --pubkey=<file>    verify with another public key (testing)
 *
 * Settings (channel, automatic or approve, update hours, pause) are on the
 * System Updates page. See includes/updater.php for what it does and never touches.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
@set_time_limit(1200);
mysqli_report(MYSQLI_REPORT_OFF);
$root = dirname(__DIR__);
chdir($root);
require_once $root . '/includes/conn.php';
require_once $root . '/includes/updater.php';

$o = getopt('', ['check', 'now', 'package:', 'pubkey:']);
$opt = ['check_only' => isset($o['check']), 'force' => isset($o['now'])];
if (!empty($o['package'])) $opt['package_dir'] = $o['package'];
if (!empty($o['pubkey']))  $opt['pubkey'] = $o['pubkey'];

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    fwrite(STDERR, "Flowork updater: no database connection (check .env).\n");
    exit(2);
}
$r = flowork_upd_run($conn, $root, $opt);
echo 'Flowork ' . flowork_version($root) . ': ' . $r['status'] . ' — ' . $r['message'] . "\n";
exit(in_array($r['status'], ['error', 'failed'], true) ? 1 : 0);
