<?php
/**
 * Scheduled database + secrets backup. Command line only; meant for Windows
 * Task Scheduler or cron, e.g. every night:
 *
 *   C:/xampp/php/php.exe C:/xampp/htdocs/flowork_alpha/maintenance/scheduled_backup.php
 *
 * What it writes to backups/ (denied over HTTP):
 *   king_<stamp>.sql.gz        mysqldump --single-transaction (consistent snapshot)
 *   secrets_<stamp>.json[.enc] .env and includes/timb_key.php — a database
 *                              restore is useless without them
 * With BACKUP_PASSPHRASE set (16+ characters) both are encrypted
 * (AES-256-GCM, key from PBKDF2) and get a .enc suffix.
 *
 * Off-machine copy: set BACKUP_COPY_DIR (a mapped drive, NAS share or synced
 * cloud folder). Files are copied there only when they are encrypted, so
 * grower data and secrets never leave the machine in the clear.
 *
 * Files older than BACKUP_KEEP_DAYS (default 30) are removed from both places.
 * Exit code 0 on success, 1 on any failure (Task Scheduler shows it).
 *
 * Restore:
 *   php maintenance/scheduled_backup.php --decrypt backups/king_<stamp>.sql.gz.enc out.sql.gz
 *   gzip -d out.sql.gz   (or 7-Zip)  then  mysql -uroot <db> < out.sql
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['DOCUMENT_ROOT']   = dirname($root);
ob_start();
require $root . '/includes/conn.php';   // also loads .env; gives $_db_host/$_db_user/$_db_pass/$_db_name
ob_end_clean();

function env_str(string $k): string {
    $v = getenv($k);
    if ($v === false || $v === '') $v = $_ENV[$k] ?? '';
    return trim((string)$v);
}
function say(string $s): void { echo date('Y-m-d H:i:s'), "  $s", PHP_EOL; }
function fail(string $s): never { fwrite(STDERR, date('Y-m-d H:i:s') . "  FAILED: $s" . PHP_EOL); exit(1); }

const MAGIC = "FLOWORKBK1";
function encrypt_file(string $in, string $out, string $pass): void {
    $salt = random_bytes(16); $iv = random_bytes(12);
    $key  = hash_pbkdf2('sha256', $pass, $salt, 200000, 32, true);
    $ct   = openssl_encrypt(file_get_contents($in), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) fail("encryption failed for $in");
    file_put_contents($out, MAGIC . $salt . $iv . $tag . $ct);
}
function decrypt_file(string $in, string $out, string $pass): void {
    $d = file_get_contents($in);
    if ($d === false || strncmp($d, MAGIC, strlen(MAGIC)) !== 0) fail("$in is not a Flowork encrypted backup");
    $o = strlen(MAGIC);
    $salt = substr($d, $o, 16); $iv = substr($d, $o + 16, 12); $tag = substr($d, $o + 28, 16);
    $key  = hash_pbkdf2('sha256', $pass, $salt, 200000, 32, true);
    $pt   = openssl_decrypt(substr($d, $o + 44), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($pt === false) fail('wrong passphrase or damaged file');
    file_put_contents($out, $pt);
}

$pass = env_str('BACKUP_PASSPHRASE');
if (strlen($pass) > 0 && strlen($pass) < 16) fail('BACKUP_PASSPHRASE must be at least 16 characters');

// ── --decrypt <in> <out> ──────────────────────────────────────────────────
if (($argv[1] ?? '') === '--decrypt') {
    if ($pass === '') fail('set BACKUP_PASSPHRASE (in .env or the environment) to decrypt');
    if (empty($argv[2]) || empty($argv[3])) fail('usage: --decrypt <in.enc> <out>');
    decrypt_file($argv[2], $argv[3], $pass);
    say("decrypted to {$argv[3]}");
    exit(0);
}

$dir   = $root . '/backups';
$stamp = date('Ymd_His');
$keep  = max(1, (int)(env_str('BACKUP_KEEP_DAYS') ?: 30));
$copy  = rtrim(env_str('BACKUP_COPY_DIR'), '/\\');
if (!is_dir($dir) && !mkdir($dir, 0750, true)) fail("cannot create $dir");

// ── 1. Database dump ──────────────────────────────────────────────────────
$dump = env_str('MYSQLDUMP') ?: (is_file('C:/xampp/mysql/bin/mysqldump.exe') ? 'C:/xampp/mysql/bin/mysqldump.exe' : 'mysqldump');
$db   = preg_replace('/[^a-zA-Z0-9_]/', '', $_db_name);
$sql  = "$dir/{$db}_$stamp.sql";
// Password via the environment, not the command line, so it never shows in a process list.
putenv('MYSQL_PWD=' . $_db_pass);
$cmd = escapeshellarg($dump) . ' --single-transaction --routines --triggers --events --default-character-set=utf8mb4'
     . ' -h ' . escapeshellarg($_db_host) . ' -u ' . escapeshellarg($_db_user) . ' ' . escapeshellarg($db)
     . ' > ' . escapeshellarg($sql) . ' 2>&1';
exec($cmd, $out, $rc);
putenv('MYSQL_PWD');
$head = is_file($sql) ? (string)file_get_contents($sql, false, null, 0, 4000) : '';
$tail = is_file($sql) ? (string)file_get_contents($sql, false, null, max(0, filesize($sql) - 200)) : '';
if ($rc !== 0 || strpos($head, 'CREATE TABLE') === false || strpos($tail, 'Dump completed') === false) {
    $msg = trim($head) !== '' ? substr(trim($head), 0, 300) : "rc=$rc";
    @unlink($sql);
    fail("mysqldump: $msg");
}
$gz = "$sql.gz";
$fi = fopen($sql, 'rb'); $fo = gzopen($gz, 'wb6');
while (!feof($fi)) gzwrite($fo, fread($fi, 1 << 20));
fclose($fi); gzclose($fo);
$tables = substr_count(file_get_contents($sql), 'CREATE TABLE');
unlink($sql);
say(sprintf('database %s: %d tables, %.1f MB compressed', $db, $tables, filesize($gz) / 1048576));

// ── 2. Secrets ────────────────────────────────────────────────────────────
$sec = "$dir/secrets_$stamp.json";
$bundle = [];
foreach (['.env', 'includes/timb_key.php'] as $rel) {
    if (is_file("$root/$rel")) $bundle[$rel] = base64_encode(file_get_contents("$root/$rel"));
}
file_put_contents($sec, json_encode(['created' => date('c'), 'files' => $bundle], JSON_PRETTY_PRINT));
@chmod($sec, 0600);
say('secrets: ' . (implode(', ', array_keys($bundle)) ?: 'none found'));

// ── 3. Encrypt ────────────────────────────────────────────────────────────
$made = [$gz, $sec];
if ($pass !== '') {
    foreach ($made as $i => $f) { encrypt_file($f, "$f.enc", $pass); unlink($f); $made[$i] = "$f.enc"; }
    say('encrypted with BACKUP_PASSPHRASE');
} else {
    say('WARNING: BACKUP_PASSPHRASE not set — files are not encrypted and will not be copied off this machine');
}

// ── 4. Off-machine copy ───────────────────────────────────────────────────
$copied = false;
if ($copy !== '') {
    if ($pass === '') {
        say('WARNING: BACKUP_COPY_DIR is set but nothing was copied (needs BACKUP_PASSPHRASE)');
    } elseif (!is_dir($copy) && !@mkdir($copy, 0750, true)) {
        fail("BACKUP_COPY_DIR $copy is not reachable; local backup is in $dir");
    } else {
        foreach ($made as $f) {
            if (!copy($f, $copy . '/' . basename($f)) || filesize($copy . '/' . basename($f)) !== filesize($f)) fail("copy to $copy failed");
        }
        $copied = true;
        say("copied to $copy");
    }
} else {
    say('WARNING: BACKUP_COPY_DIR not set — the only copy is on this machine');
}

// ── 5. Rotation ───────────────────────────────────────────────────────────
$cut = time() - $keep * 86400; $removed = 0;
foreach (array_filter([$dir, $copied ? $copy : '']) as $d) {
    foreach (glob("$d/{{$db}_,secrets_}*", GLOB_BRACE) ?: [] as $f) {
        if (preg_match('/_(\d{8}_\d{6})\./', basename($f)) && filemtime($f) < $cut) { unlink($f); $removed++; }
    }
}
if ($removed) say("removed $removed file(s) older than $keep days");

$conn->query("CREATE TABLE IF NOT EXISTS backup_log (
    id INT AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NOT NULL, file_size_kb INT DEFAULT 0,
    table_count INT DEFAULT 0, status ENUM('success','failed','partial') DEFAULT 'success',
    triggered_by ENUM('manual','cron','token') DEFAULT 'manual', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$fn = $conn->real_escape_string(basename($made[0]));
$conn->query("INSERT INTO backup_log (filename, file_size_kb, table_count, status, triggered_by)
    VALUES ('$fn', " . (int)(filesize($made[0]) / 1024) . ", $tables, '" . ($pass !== '' && $copied ? 'success' : 'partial') . "', 'cron')");

say('done');
exit(0);
