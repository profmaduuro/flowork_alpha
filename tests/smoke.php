<?php
/**
 * Flowork smoke test — run from the command line, never through the web server:
 *
 *   C:/xampp/php/php.exe tests/smoke.php [--base=http://localhost/flowork_alpha/] [--skip-lint] [--skip-http]
 *
 * 1. Lint:  php -l on every PHP file in the repository.
 * 2. Net:   the payments net reader in includes/net_amount.php gives exactly
 *           one figure per delivery (balanced_bales row).
 * 3. Pages: logs in as a temporary admin user (created here, deleted at the
 *           end, password random and never printed) and requests the main
 *           pages. A page passes on HTTP 200 or a redirect, with no
 *           "Fatal error", "Parse error" or "Warning:" in the HTML and,
 *           for a 200, at least 1 KB of HTML.
 *
 * Exit code 0 when everything passes, 1 otherwise.
 * It writes to the database only to add and remove the temporary user.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$opts = getopt('', ['base::', 'skip-lint', 'skip-http']);
$base = rtrim($opts['base'] ?? 'http://localhost/flowork_alpha/', '/') . '/';

// Pages requested in step 3. Left out on purpose: scripts that dump the
// database, call TIMB/SMS/WhatsApp, or change data on a plain GET
// (auto_backup, backup, season_rollover, *_sync, timb_api, sms/whatsapp
// notify, setup/repair/rebuild/fix_* and the scratch_* scripts).
$pages = [
    'dashboard.php', 'role_dashboard.php', 'management_dashboard.php',
    'floor_map.php', 'floor_queue.php', 'floor_summary.php', 'live_floor.php',
    'grower_booking.php', 'grower_registry.php', 'grower_loans.php', 'grower_performance.php',
    'bales.php', 'bale_adjustments.php', 'bale_check.php', 'balance.php',
    'tickets.php', 'selling.php', 'sales.php', 'sales_sheets.php', 're_offer.php',
    'buyers.php', 'buyer_management.php', 'buyer_analytics.php',
    'stop_orders.php', 'stoporder_processing.php', 'stoporder_verification.php',
    'internal_stoporders.php', 'deductions.php', 'penalties.php',
    'payments.php', 'pay_dashboard.php', 'pay_batches.php', 'pay_reconcile.php',
    'pay_owed_recon.php', 'pay_audit.php', 'pay_close.php', 'pay_grower.php',
    'banking.php', 'bank_file.php', 'exchange_rate.php',
    'dispatch.php', 'transporters.php', 'vehicles.php',
    'reports.php', 'eod_reports.php', 'purchase_summary.php', 'timb_reports.php',
    'analytics.php', 'business_intelligence.php', 'season_compare.php', 'clerk_performance.php',
    'contract_farming.php', 'quality_control.php', 'grade_disputes.php',
    'processing.php', 'packhouse.php', 'locations.php', 'day_control.php',
    'void_controls.php', 'notifications.php', 'audit_log.php',
    'users.php', 'settings.php', 'multi_floor.php', 'two_factor.php',
    'system_health.php', 'help_center.php',
];

// Database, with the same config as the app. Loaded before any output so
// conn.php can still set its session cookie parameters.
$_SERVER['SCRIPT_FILENAME'] = $root . '/tests/smoke.php';
$_SERVER['DOCUMENT_ROOT']   = dirname($root);
ob_start();
require $root . '/includes/conn.php';
require_once $root . '/includes/net_amount.php';
ob_end_clean();
/** @var mysqli $conn */

$failures = 0;
function out(string $s = ''): void { echo $s, PHP_EOL; }
function head(string $s): void { out(); out("== $s =="); }
function result(bool $ok, string $label, string $detail = ''): void {
    global $failures;
    if (!$ok) $failures++;
    out(sprintf('  [%s] %-34s %s', $ok ? 'PASS' : 'FAIL', $label, $detail));
}

// ── 1. Lint ─────────────────────────────────────────────────────────────
if (!isset($opts['skip-lint'])) {
    head('1. PHP lint');
    $skip_dirs = ['.git', 'backups', 'uploads', '.idea', 'vendor', 'node_modules'];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn($f) => !($f->isDir() && in_array($f->getFilename(), $skip_dirs, true))
    ));
    $files = [];
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') $files[] = $f->getPathname();
    }
    sort($files);
    $bad = 0;
    foreach ($files as $file) {
        $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1';
        exec($cmd, $lines, $code);
        if ($code !== 0) {
            $bad++;
            result(false, substr($file, strlen($root) + 1), trim(implode(' ', $lines)));
        }
        $lines = [];
    }
    result($bad === 0, 'lint', sprintf('%d files, %d with syntax errors', count($files), $bad));
}

// ── 2. Net payable: one figure per delivery ─────────────────────────────
head('2. Payments net (includes/net_amount.php)');
$q = $conn->query("SELECT bb.id, COUNT(*) AS c FROM balanced_bales bb "
    . flowork_net_join('dtn', 'bb.id') . " GROUP BY bb.id");
if (!$q) {
    result(false, 'net join query', $conn->error);
} else {
    $deliveries = 0; $multi = [];
    while ($r = $q->fetch_assoc()) {
        $deliveries++;
        if ((int)$r['c'] !== 1) $multi[] = $r['id'] . ' (' . $r['c'] . ' rows)';
    }
    result(!$multi, 'flowork_net_join one row each',
        $multi ? 'deliveries with several figures: ' . implode(', ', $multi)
               : "$deliveries deliveries, one figure each");

    // The single-delivery reader must agree with the join.
    $q = $conn->query("SELECT bb.id, dtn.usd_net_value, dtn.zim_net_value FROM balanced_bales bb "
        . flowork_net_join('dtn', 'bb.id') . " WHERE dtn.balanced_balesid IS NOT NULL");
    $mismatch = []; $stored = 0;
    while ($q && $r = $q->fetch_assoc()) {
        $stored++;
        $id = (int)$r['id'];
        if (abs(flowork_net_for_bbid($conn, $id, 'usd') - (float)$r['usd_net_value']) > 0.005
            || abs(flowork_net_for_bbid($conn, $id, 'zig') - (float)$r['zim_net_value']) > 0.005) {
            $mismatch[] = $id;
        }
    }
    result(!$mismatch, 'flowork_net_for_bbid = join',
        $mismatch ? 'differ for delivery id ' . implode(', ', $mismatch) : "$stored deliveries with a stored net");

    $dq = $conn->query("SELECT COUNT(*) AS c FROM (SELECT balanced_balesid FROM daily_totals_net_amount
        GROUP BY balanced_balesid HAVING COUNT(*) > 1) d");
    $dup = $dq ? (int)$dq->fetch_assoc()['c'] : 0;
    out("  [INFO] $dup deliveries still have duplicate stored rows (reader takes the newest; see scratch/net_amount_cleanup.php)");
}

// ── 3. Pages over HTTP ───────────────────────────────────────────────────
if (isset($opts['skip-http'])) {
    out();
    out($failures ? "RESULT: FAIL ($failures)" : 'RESULT: PASS');
    exit($failures ? 1 : 0);
}

head("3. Pages at $base");
// PHP errors reach the page only when APP_ENV is local; they always reach the
// web server's error log, so new log lines from this app are checked as well.
$error_log = flowork_env('SMOKE_ERROR_LOG') ?? (is_file('C:/xampp/apache/logs/error.log') ? 'C:/xampp/apache/logs/error.log' : '');
$log_start = ($error_log && is_readable($error_log)) ? filesize($error_log) : null;
if (!flowork_show_errors() && $log_start === null) {
    out('  [WARN] APP_ENV is not local and no readable error log (set SMOKE_ERROR_LOG): PHP errors cannot be seen.');
}

const SMOKE_PREFIX = '__smoke_test_';
$conn->query("DELETE FROM users WHERE username LIKE '\\_\\_smoke\\_test\\_%'"); // leftovers from an aborted run

$spq = $conn->query("SELECT sp.id FROM selling_point sp JOIN flowork_subscriptions s ON s.selling_pointid = sp.id
    WHERE sp.active = 1 AND s.status = 'Active' AND (s.end_date IS NULL OR s.end_date >= CURDATE())
    ORDER BY sp.id LIMIT 1");
$spid = ($spq && ($r = $spq->fetch_assoc())) ? (int)$r['id'] : 0;
if (!$spid) {
    result(false, 'test user', 'no active floor with an active subscription to log in to');
    out(); out("RESULT: FAIL ($failures)");
    exit(1);
}

$username = SMOKE_PREFIX . bin2hex(random_bytes(4));
$password = bin2hex(random_bytes(24));
$hash     = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (username, password, active, is_admin, role, full_name, selling_pointid, is_master)
    VALUES (?, ?, 1, 1, 'admin', 'Smoke test (temporary)', ?, 0)");
$stmt->bind_param('ssi', $username, $hash, $spid);
if (!$stmt->execute()) {
    result(false, 'create test user', $stmt->error);
    out(); out("RESULT: FAIL ($failures)");
    exit(1);
}
$test_uid = (int)$conn->insert_id;
$stmt->close();

// Enrol the test admin in 2FA so the login goes through the real code step
// (and REQUIRE_ADMIN_2FA does not send every page to the setup screen).
require_once $root . '/includes/totp.php';
$totp_secret = totp_secret();
$conn->query("INSERT INTO user_2fa (userid, secret, enabled, backup_codes, enabled_at)
    VALUES ($test_uid, '" . $conn->real_escape_string($totp_secret) . "', 1, '', NOW())");

$cleanup = function () use ($conn, $test_uid, $username) {
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("DELETE FROM users WHERE id = $test_uid");
    $conn->query("DELETE FROM user_2fa WHERE userid = $test_uid");
    $key = $conn->real_escape_string('user:' . strtolower(substr($username, 0, 40)));
    $conn->query("DELETE FROM rate_limits WHERE ip_address IN ('$key', 'user:$test_uid')");
    $left = $conn->query("SELECT COUNT(*) AS c FROM users WHERE id = $test_uid")->fetch_assoc()['c'] ?? 1;
    out(sprintf('  [%s] %-34s %s', $left ? 'FAIL' : 'PASS', 'test user removed', $left ? "user id $test_uid still exists" : ''));
};
register_shutdown_function($cleanup);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER         => true,
    CURLOPT_COOKIEFILE     => '',          // in-memory cookie jar
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_USERAGENT      => 'flowork-smoke/1.0',
]);
$fetch = function (string $path, ?array $post = null) use ($ch, $base) {
    curl_setopt($ch, CURLOPT_URL, $base . $path);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    $raw = curl_exec($ch);
    if ($raw === false) return [0, '', '', curl_error($ch)];
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $hdrs  = substr($raw, 0, $hsize);
    $body  = substr($raw, $hsize);
    $loc   = preg_match('/^Location:\s*(\S+)/mi', $hdrs, $m) ? $m[1] : '';
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body, $loc, ''];
};

try {
    // Log in through the real login form.
    [$code, $body, , $err] = $fetch('login.php');
    $token = preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m) ? html_entity_decode($m[1]) : '';
    if ($code !== 200 || $token === '') {
        result(false, 'login page', $err ?: "HTTP $code, CSRF token " . ($token ? 'found' : 'not found'));
        throw new RuntimeException('login');
    }
    [$code, $body, $loc] = $fetch('login.php', ['csrf_token' => $token, 'username' => $username, 'password' => $password]);
    if (str_contains($loc, 'two_factor.php')) {
        // Second step: the code from the authenticator, computed from the test user's secret.
        [$code, $body, $loc] = $fetch('two_factor.php?verify', ['csrf_token' => $token, 'code' => totp_code($totp_secret)]);
        if (str_contains($loc, 'login.php')) [$code, $body, $loc] = $fetch('login.php');
    }
    $logged_in = $code >= 300 && $code < 400 && str_contains($loc, 'dashboard.php');
    $why = '';
    if (!$logged_in && preg_match('/Invalid username or password|Too many login attempts|CSRF[^<]*/i', $body, $m)) $why = $m[0];
    result($logged_in, 'login as temporary admin', $logged_in ? '' : trim("HTTP $code $loc $why"));
    if (!$logged_in) throw new RuntimeException('login');

    // Under Apache PHP prints errors as HTML ("<b>Warning</b>:  ..."), on the
    // CLI as plain text, so allow an optional </b> before the colon.
    $error_marker = '~(Fatal error|Parse error|Warning)(</b>)?\s*:~i';
    foreach ($pages as $page) {
        $t0 = microtime(true);
        [$code, $body, $loc, $err] = $fetch($page);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $found = [];
        if (preg_match_all($error_marker, $body, $mm, PREG_OFFSET_CAPTURE)) {
            foreach (array_slice($mm[0], 0, 3) as [, $pos]) {
                $snip = trim(preg_replace('/\s+/', ' ', strip_tags(substr($body, $pos, 260))));
                $found[] = mb_strimwidth($snip, 0, 160, '…');
            }
        }
        // A 200 with almost no HTML is a page that bailed out early, e.g.
        // "Access denied" shown to this admin user.
        if ($code === 200 && strlen($body) < 1024) {
            $found[] = 'near-empty page: ' . mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags($body))), 0, 120, '…');
        }
        $status_ok = $code === 200 || ($code >= 300 && $code < 400);
        $ok = $status_ok && !$found;
        $detail = $err ?: "HTTP $code" . ($loc ? " -> $loc" : '') . sprintf(' %dms %.0fKB', $ms, strlen($body) / 1024);
        if (str_contains($loc, 'login.php') || str_contains($loc, 'two_factor.php')) {
            $ok = false;
            $detail .= ' (sent to ' . basename(parse_url($loc, PHP_URL_PATH) ?: $loc) . ': page not checked)';
        }
        if ($found) $detail .= ' | ' . implode(' | ', $found);
        result($ok, $page, $detail);
    }

    $fetch('logout.php');

    // New PHP warnings / fatal errors this app wrote to the error log during the run.
    if ($log_start !== null) {
        clearstatcache();
        $fh = fopen($error_log, 'rb');
        fseek($fh, $log_start);
        $new = stream_get_contents($fh);
        fclose($fh);
        $app_path = str_replace('/', '\\\\', str_replace('\\', '/', $root)) . '\\\\';   // as Apache writes it: C:\\xampp\\...
        $hits = [];
        foreach (preg_split('/\R/', (string)$new) as $line) {
            if (stripos($line, $app_path) === false && stripos($line, str_replace('\\\\', '\\', $app_path)) === false) continue;
            if (!preg_match('/PHP (Warning|Fatal error|Parse error)/', $line)) continue;
            $hits[] = mb_strimwidth(preg_replace('/^\[[^\]]*\]\s*(\[[^\]]*\]\s*)*/', '', $line), 0, 170, '…');
        }
        result(!$hits, 'error log: no new warnings/fatals', $hits ? count($hits) . ' new | ' . implode(' | ', array_slice(array_unique($hits), 0, 3)) : basename($error_log) . ' checked');
    }
} catch (RuntimeException $e) {
    // reported above
} finally {
    curl_close($ch);
    $cleanup();
}

out();
out($failures ? "RESULT: FAIL ($failures)" : 'RESULT: PASS');
exit($failures ? 1 : 0);
