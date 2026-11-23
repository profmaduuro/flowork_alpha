<?php
/**
 * updater.php — Flowork updates itself from signed GitHub releases.
 *
 * Each installation runs updater/update.php from a cron job (cPanel) or a
 * scheduled task (XAMPP). The site fetches the update itself, over HTTPS, so
 * no one has to connect in to it and no firewall needs opening.
 *
 *   check     newest release on the site's channel (stable, or test = also pre-releases)
 *   download  package + manifest + signature; the manifest must be signed with the
 *             Flowork release key (updater/public_key.pem) and the package must match
 *             the manifest's SHA-256, and every file in it the manifest's per-file hash
 *   install   in the update hours (or when an admin asks), with nobody working:
 *             back up every file it will replace or remove, show "updating" to
 *             visitors, copy the files, upgrade the database (adding only), then
 *             check the site still loads; anything wrong puts the old files back
 *
 * Never touched: .env, the TIMB key, uploads/, backups/, updater/state/, cPanel's own
 * files, and the cPanel-generated block in .htaccess. Database upgrades only add
 * (includes/schema_sync.php), so each company's settings and data stay its own.
 */

require_once __DIR__ . '/schema_sync.php';

const FLOWORK_UPD_REPO_DEFAULT = 'profmaduuro/flowork_alpha';

/* ── Paths that belong to the installation, not to the program ─────────── */

/** True when a release must never ship, overwrite or delete this relative path. */
function flowork_upd_protected(string $rel): bool {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $l = strtolower($rel);
    if ($l === '.env' || str_starts_with($l, '.env.') || $l === 'includes/timb_key.php') return true;
    foreach (['uploads/', 'backups/', 'updater/state/', '.well-known/', 'cgi-bin/', '.git/', '.github/', '.idea/', '.claude/'] as $p) {
        if (str_starts_with($l, $p)) return true;
    }
    if (in_array($l, ['.user.ini', 'php.ini', 'error_log', '.htpasswd', '.ftpquota', '.ftp-deploy-sync-state.json'], true)) return true;
    if (basename($l) === 'error_log') return true;
    return false;
}

/** True when a repository file is left out of release packages (developer-only files). */
function flowork_upd_excluded_from_release(string $rel): bool {
    $l = strtolower(ltrim(str_replace('\\', '/', $rel), '/'));
    if (flowork_upd_protected($l)) return true;
    foreach (['maintenance/', 'scratch/', 'tests/', 'docker/'] as $p) if (str_starts_with($l, $p)) return true;
    if (in_array($l, ['docker-compose.yml', '.gitignore', '.gitattributes', '.env.example'], true)) return true;
    return (bool)preg_match('/\.(md|zip|sql|gz|bak|log)$/', $l);
}

/* ── Settings and log (in this installation's own database) ─────────────── */

function flowork_upd_ensure(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS flowork_update_settings (
        name VARCHAR(50) NOT NULL PRIMARY KEY, value VARCHAR(500) NOT NULL DEFAULT '',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $conn->query("CREATE TABLE IF NOT EXISTS flowork_update_log (
        id INT AUTO_INCREMENT PRIMARY KEY, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        level VARCHAR(10) NOT NULL DEFAULT 'info', version VARCHAR(30) NOT NULL DEFAULT '',
        message TEXT NOT NULL, KEY (created_at)) ENGINE=InnoDB");
    $done = true;
}

function flowork_upd_defaults(): array {
    return [
        'channel'       => 'stable',   // stable | test (test also takes pre-releases)
        'mode'          => 'auto',     // auto | manual (download, then wait for an admin)
        'paused'        => '0',
        'window_start'  => '1',        // install between these hours (server time)
        'window_end'    => '5',
        'repo'          => FLOWORK_UPD_REPO_DEFAULT,
        'site_url'      => '',         // for the after-install check; saved when an admin opens System Updates
        'install_requested' => '0',
        'check_requested'   => '0',
        'last_check'    => '',
        'latest_version'=> '',
        'ready_version' => '',
        'status'        => '',
        'failed_version'=> '',         // a version that failed to install is not retried automatically
        'last_run'      => '',         // every updater run, even one that had nothing to do
        'last_cron_run' => '',         // the last run started by cron / Task Scheduler (automatic updates work)
        'run_started'   => '',         // set while a run is going; cleared when it ends
        'step'          => '',         // what a running updater is doing, for the progress panel
        'last_result'   => '',         // the outcome of the last run, in words
    ];
}

function flowork_upd_get(mysqli $conn): array {
    flowork_upd_ensure($conn);
    $s = flowork_upd_defaults();
    $q = $conn->query("SELECT name, value FROM flowork_update_settings");
    while ($q && $r = $q->fetch_assoc()) if (array_key_exists($r['name'], $s)) $s[$r['name']] = $r['value'];
    return $s;
}

function flowork_upd_set(mysqli $conn, array $values): void {
    flowork_upd_ensure($conn);
    $allowed = flowork_upd_defaults();
    foreach ($values as $k => $v) {
        if (!array_key_exists($k, $allowed)) continue;
        $k = $conn->real_escape_string($k);
        $v = $conn->real_escape_string(substr((string)$v, 0, 500));
        $conn->query("INSERT INTO flowork_update_settings (name, value) VALUES ('$k', '$v') ON DUPLICATE KEY UPDATE value=VALUES(value)");
    }
}

function flowork_upd_log(mysqli $conn, string $level, string $msg, string $version = ''): void {
    flowork_upd_ensure($conn);
    $conn->query("INSERT INTO flowork_update_log (level, version, message) VALUES ('" . $conn->real_escape_string($level) . "','"
        . $conn->real_escape_string(substr($version, 0, 30)) . "','" . $conn->real_escape_string($msg) . "')");
    if (PHP_SAPI === 'cli') echo '[' . date('H:i:s') . "] $level: $msg\n";
}

/* ── Versions ───────────────────────────────────────────────────────────── */

function flowork_version(?string $root = null): string {
    $f = ($root ?? dirname(__DIR__)) . '/VERSION';
    $v = is_readable($f) ? trim((string)file_get_contents($f)) : '';
    return preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $v) ? $v : '0.0.0';
}

/* ── HTTP ───────────────────────────────────────────────────────────────── */

/** GET over HTTPS. Returns [status, body] or [0, error]. Follows redirects (GitHub asset downloads). */
function flowork_upd_http_get(string $url, ?string $save_to = null, int $timeout = 60, bool $allow_http = false): array {
    if (!preg_match($allow_http ? '#^https?://#' : '#^https://#', $url)) return [0, 'only https downloads are allowed'];
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: Flowork-Updater\r\nAccept: application/vnd.github+json\r\n", 'timeout' => $timeout, 'follow_location' => 1]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $code = (int)$m[1];
        if ($body === false) return [0, 'download failed (no curl, and PHP streams could not fetch it)'];
        if ($save_to !== null) { file_put_contents($save_to, $body); $body = ''; }
        return [$code, $body];
    }
    $ch = curl_init($url);
    $fh = $save_to !== null ? fopen($save_to, 'wb') : null;
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT => 'Flowork-Updater', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
        CURLOPT_PROTOCOLS => $allow_http ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => $allow_http ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($fh) curl_setopt($ch, CURLOPT_FILE, $fh); else curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fh) fclose($fh);
    if ($body === false) return [0, $err ?: 'download failed'];
    return [$code, $fh ? '' : (string)$body];
}

/* ── Finding a release ──────────────────────────────────────────────────── */

/**
 * Newest release on the channel that is newer than $current.
 * Returns ['version','tag','prerelease','assets'=>[name=>url]] or null, or ['error'=>msg].
 */
function flowork_upd_find_release(string $repo, string $channel, string $current): ?array {
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) return ['error' => 'the update source is not a GitHub repository name'];
    [$code, $body] = flowork_upd_http_get("https://api.github.com/repos/$repo/releases?per_page=30", null, 30);
    if ($code !== 200) return ['error' => "GitHub answered $code" . ($code === 0 ? " ($body)" : '') . ($code === 403 ? ' (rate limit; it will try again later)' : '')];
    $list = json_decode($body, true);
    if (!is_array($list)) return ['error' => 'GitHub sent something that is not a release list'];
    $best = null;
    foreach ($list as $rel) {
        if (!empty($rel['draft'])) continue;
        if (!empty($rel['prerelease']) && $channel !== 'test') continue;
        $v = ltrim((string)($rel['tag_name'] ?? ''), 'vV');
        if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $v)) continue;
        if (version_compare($v, $current, '<=')) continue;
        if ($best && version_compare($v, $best['version'], '<=')) continue;
        $assets = [];
        foreach ($rel['assets'] ?? [] as $a) $assets[(string)$a['name']] = (string)$a['browser_download_url'];
        $best = ['version' => $v, 'tag' => $rel['tag_name'], 'prerelease' => !empty($rel['prerelease']), 'assets' => $assets];
    }
    return $best;
}

/* ── Verifying a package ────────────────────────────────────────────────── */

/**
 * Check a downloaded release in $dir (flowork-<v>.zip, .manifest.json, .manifest.sig)
 * against the release public key. Returns ['ok'=>true,'manifest'=>[...],'zip'=>path] or ['error'=>msg].
 */
function flowork_upd_verify(string $dir, string $version, string $pubkey_file): array {
    $zip = "$dir/flowork-$version.zip"; $man = "$dir/flowork-$version.manifest.json"; $sig = "$dir/flowork-$version.manifest.sig";
    foreach ([$zip, $man, $sig] as $f) if (!is_file($f)) return ['error' => 'missing ' . basename($f)];
    if (!function_exists('openssl_verify')) return ['error' => 'PHP OpenSSL is not available, so updates cannot be verified'];
    $pub = @openssl_pkey_get_public((string)@file_get_contents($pubkey_file));
    if (!$pub) return ['error' => 'the release public key (updater/public_key.pem) is missing or unreadable'];
    $man_raw = (string)file_get_contents($man);
    $signature = base64_decode(trim((string)file_get_contents($sig)), true);
    if ($signature === false || openssl_verify($man_raw, $signature, $pub, OPENSSL_ALGO_SHA256) !== 1) {
        return ['error' => 'the signature does not match the Flowork release key: not installing it'];
    }
    $m = json_decode($man_raw, true);
    if (!is_array($m) || ($m['name'] ?? '') !== 'flowork' || ($m['version'] ?? '') !== $version) return ['error' => 'the signed manifest is for another package or version'];
    if (!hash_equals((string)($m['sha256'] ?? ''), hash_file('sha256', $zip))) return ['error' => 'the package does not match its signed checksum (damaged or changed download)'];
    if (empty($m['files']) || !is_array($m['files'])) return ['error' => 'the manifest lists no files'];
    return ['ok' => true, 'manifest' => $m, 'zip' => $zip];
}

/** Extract a verified package to $to, checking every path and every file hash. Returns null or an error. */
function flowork_upd_extract(string $zipfile, array $manifest, string $to): ?string {
    if (!class_exists('ZipArchive')) return 'PHP Zip is not available';
    $z = new ZipArchive();
    if ($z->open($zipfile) !== true) return 'the package is not a readable zip file';
    $want = $manifest['files'];
    if ($z->numFiles !== count($want)) { $z->close(); return 'the package holds ' . $z->numFiles . ' files but the manifest lists ' . count($want); }
    for ($i = 0; $i < $z->numFiles; $i++) {
        $name = $z->getNameIndex($i);
        if ($name === false || $name === '' || str_contains($name, '..') || str_contains($name, '\\') || $name[0] === '/' || preg_match('/^[A-Za-z]:/', $name) || str_contains($name, "\0")) {
            $z->close(); return "the package has an unsafe path: $name";
        }
        if (!isset($want[$name])) { $z->close(); return "the package has a file the manifest does not list: $name"; }
        if (flowork_upd_protected($name)) { $z->close(); return "the package tries to replace a protected file: $name"; }
        $data = $z->getFromIndex($i);
        if ($data === false || !hash_equals($want[$name], hash('sha256', $data))) { $z->close(); return "file $name does not match the signed manifest"; }
        $dest = "$to/$name";
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true)) { $z->close(); return "cannot create a folder for $name"; }
        if (file_put_contents($dest, $data) === false) { $z->close(); return "cannot write $name"; }
    }
    $z->close();
    return null;
}

/* ── Installing ─────────────────────────────────────────────────────────── */

function flowork_upd_state_dir(string $root): string {
    $d = "$root/updater/state";
    if (!is_dir($d)) mkdir($d, 0750, true);
    if (!is_file("$d/.htaccess")) file_put_contents("$d/.htaccess", "Require all denied\n");
    return $d;
}

function flowork_upd_rrmdir(string $d): void {
    if (!is_dir($d)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($d);
}

/** .htaccess from the release, keeping the blocks cPanel wrote into the site's own copy (PHP version handler etc.). */
function flowork_upd_merge_htaccess(string $new, string $old): string {
    if (!preg_match_all('/^#[^\n]*BEGIN cPanel-generated[^\n]*\n.*?^#[^\n]*END cPanel-generated[^\n]*$/ms', $old, $m)) return $new;
    foreach ($m[0] as $block) if (!str_contains($new, $block)) $new = rtrim($new) . "\n\n" . $block . "\n";
    return $new;
}

/** Whether the PHP CLI can run `php -l` here. */
function flowork_upd_can_lint(): bool {
    if (PHP_SAPI !== 'cli' || !function_exists('exec')) return false;
    $off = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $off, true) && PHP_BINARY !== '';
}

/**
 * Install a verified package into $root. $opt: site_url (for the check after
 * install), lint (bool). Returns ['ok'=>true,'schema'=>[...]] or ['error'=>msg, 'rolled_back'=>bool].
 */
function flowork_upd_install(mysqli $conn, string $root, array $manifest, string $zipfile, array $opt = []): array {
    $version = $manifest['version'];
    $state = flowork_upd_state_dir($root);
    $stage = "$state/staging-$version";
    flowork_upd_rrmdir($stage);
    mkdir($stage, 0750, true);
    if ($e = flowork_upd_extract($zipfile, $manifest, $stage)) { flowork_upd_rrmdir($stage); return ['error' => $e, 'rolled_back' => false]; }

    $new_files = array_keys($manifest['files']);
    $installed_f = "$state/installed_files.json";
    $old_files = is_file($installed_f) ? (json_decode((string)file_get_contents($installed_f), true) ?: []) : [];
    // Files the previous release installed that this one no longer has: removed (never protected ones)
    $remove = array_values(array_filter(array_diff($old_files, $new_files), fn($f) => !flowork_upd_protected($f) && is_file("$root/$f")));

    // Lint the new PHP files before anything changes on the live site
    if (!empty($opt['lint']) && flowork_upd_can_lint()) {
        foreach ($new_files as $f) {
            if (!str_ends_with($f, '.php')) continue;
            $out = []; $rc = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("$stage/$f") . ' 2>&1', $out, $rc);
            if ($rc !== 0) { flowork_upd_rrmdir($stage); return ['error' => "$f has a PHP error on this server: " . trim(implode(' ', $out)), 'rolled_back' => false]; }
        }
    }

    // Back up everything that will be replaced or removed, and note what is new
    $bdir = "$root/backups/updates";
    if (!is_dir($bdir)) mkdir($bdir, 0750, true);
    if (!is_file("$root/backups/.htaccess")) @file_put_contents("$root/backups/.htaccess", "Require all denied\n");
    $from = flowork_version($root);
    $bzip = "$bdir/code-$from-before-$version-" . date('Ymd-His') . '.zip';
    $z = new ZipArchive();
    if ($z->open($bzip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { flowork_upd_rrmdir($stage); return ['error' => 'cannot write the backup in backups/updates', 'rolled_back' => false]; }
    $created = [];
    foreach (array_merge($new_files, $remove) as $f) {
        if (is_file("$root/$f")) $z->addFile("$root/$f", $f); else $created[] = $f;
    }
    $z->addFromString('.flowork-created.json', json_encode($created));
    if (!$z->close()) { flowork_upd_rrmdir($stage); return ['error' => 'the backup could not be completed', 'rolled_back' => false]; }

    $flag = "$state/maintenance.flag";
    file_put_contents($flag, (string)time());
    $rollback = function (string $why) use ($root, $bzip, $flag, $stage, $remove): array {
        $ok = flowork_upd_restore($root, $bzip);
        if (function_exists('opcache_reset')) @opcache_reset();
        @unlink($flag);
        flowork_upd_rrmdir($stage);
        return ['error' => $why . ($ok ? ' The previous version was put back.' : ' Putting the previous version back also failed: restore ' . basename($bzip) . ' by hand.'), 'rolled_back' => $ok];
    };

    // Copy the new files in, then remove the dropped ones
    foreach ($new_files as $f) {
        $src = "$stage/$f"; $dst = "$root/$f";
        if (!is_dir(dirname($dst)) && !mkdir(dirname($dst), 0755, true)) return $rollback("Could not create the folder for $f.");
        $data = (string)file_get_contents($src);
        if ($f === '.htaccess' && is_file($dst)) $data = flowork_upd_merge_htaccess($data, (string)file_get_contents($dst));
        if (is_file($dst) && hash_file('sha256', $dst) === hash('sha256', $data)) continue;   // unchanged
        // Write beside it and swap; where the swap is refused (Windows keeps the
        // running updater's own file open, antivirus may hold a file briefly),
        // write over it in place; retry a busy file for a few seconds
        $tmp = "$dst.fwupd-tmp";
        $ok = false;
        for ($try = 0; $try < 15 && !$ok; $try++) {
            if ($try) usleep(300000);
            $ok = @file_put_contents($tmp, $data) !== false && @rename($tmp, $dst);
            if (!$ok) { @unlink($tmp); $ok = @file_put_contents($dst, $data) !== false; }
        }
        if (!$ok) return $rollback("Could not write $f.");
    }
    foreach ($remove as $f) @unlink("$root/$f");
    // A web server's PHP cache could keep running the old code: drop it
    if (function_exists('opcache_reset')) @opcache_reset();

    // Database: add what the new version needs (never changes existing data)
    $schema = ['tables' => 0, 'columns' => 0, 'indexes' => 0, 'seeded' => [], 'errors' => []];
    $sj = "$root/updater/schema.json";
    if (is_file($sj)) {
        $s = json_decode((string)file_get_contents($sj), true);
        if (is_array($s)) $schema = flowork_schema_apply($conn, $s);
    }
    @unlink($flag);

    // Does the site still load?
    if (!empty($opt['site_url'])) {
        // the site's own address: http is fine here (a floor PC on the LAN); downloads stay https-only
        [$code, $body] = flowork_upd_http_get(rtrim($opt['site_url'], '/') . '/login.php', null, 30, true);
        if ($code !== 200 || stripos($body, '<html') === false || stripos($body, 'Fatal error') !== false) {
            file_put_contents($flag, (string)time());
            $why = $code !== 200 ? 'answered ' . ($code ?: 'nothing') : (stripos($body, 'Fatal error') !== false ? 'showed a PHP error' : 'did not send a page');
            return $rollback("After installing, the sign-in page $why instead of loading.");
        }
    }

    file_put_contents($installed_f, json_encode($new_files));
    flowork_upd_rrmdir($stage);
    // keep the last 5 code backups
    $olds = glob("$bdir/code-*.zip") ?: [];
    usort($olds, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($olds, 5) as $o) @unlink($o);
    return ['ok' => true, 'schema' => $schema, 'backup' => basename($bzip)];
}

/** Put the files from a code backup back, and remove the files the failed install added. */
function flowork_upd_restore(string $root, string $bzip): bool {
    $z = new ZipArchive();
    if ($z->open($bzip) !== true) return false;
    $ok = true; $created = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $name = $z->getNameIndex($i);
        $data = $z->getFromIndex($i);
        if ($name === '.flowork-created.json') { $created = json_decode((string)$data, true) ?: []; continue; }
        if ($name === false || str_contains($name, '..') || flowork_upd_protected($name)) continue;
        if (!is_dir(dirname("$root/$name"))) @mkdir(dirname("$root/$name"), 0755, true);
        if (@file_put_contents("$root/$name", $data) === false) $ok = false;
    }
    $z->close();
    foreach ($created as $f) if (!flowork_upd_protected($f) && !str_contains($f, '..')) @unlink("$root/$f");
    return $ok;
}

/* ── One run (what the cron job does) ───────────────────────────────────── */

/**
 * $opt: force (ignore update hours and the idle check), package_dir (install from
 * a local folder instead of GitHub; for testing), pubkey (another public key; testing),
 * check_only.
 */
function flowork_upd_run(mysqli $conn, string $root, array $opt = []): array {
    $state = flowork_upd_state_dir($root);
    $lock = fopen("$state/update.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['status' => 'busy', 'message' => 'another update run is still going'];
    // Recorded so System Updates can show that the updater runs and what it is doing
    $now = date('Y-m-d H:i:s');
    flowork_upd_set($conn, ['last_run' => $now, 'run_started' => $now, 'step' => 'starting']
        + (PHP_SAPI === 'cli' ? ['last_cron_run' => $now] : []));
    try {
        $r = flowork_upd_run_steps($conn, $root, $opt);
    } catch (Throwable $e) {
        $r = ['status' => 'error', 'message' => $e->getMessage()];
    }
    flowork_upd_set($conn, ['run_started' => '', 'step' => '', 'last_result' => $r['message'] ?? $r['status']]);
    flock($lock, LOCK_UN);
    return $r;
}

function flowork_upd_step(mysqli $conn, string $what): void {
    flowork_upd_set($conn, ['step' => $what]);
}

function flowork_upd_run_steps(mysqli $conn, string $root, array $opt): array {
    $state = flowork_upd_state_dir($root);

    $s = flowork_upd_get($conn);
    $current = flowork_version($root);
    $pubkey = $opt['pubkey'] ?? "$root/updater/public_key.pem";
    $requested = $s['install_requested'] === '1';

    if ($s['paused'] === '1' && empty($opt['force'])) {
        flowork_upd_set($conn, ['status' => 'paused']);
        return ['status' => 'paused', 'message' => 'updates are paused on this site'];
    }

    // 1. Which release?
    if (!empty($opt['package_dir'])) {
        $found = glob(rtrim($opt['package_dir'], '/\\') . '/flowork-*.manifest.json') ?: [];
        if (!$found) return ['status' => 'error', 'message' => 'no flowork-*.manifest.json in ' . $opt['package_dir']];
        $m = json_decode((string)file_get_contents($found[0]), true);
        $rel = ['version' => (string)($m['version'] ?? ''), 'local' => rtrim($opt['package_dir'], '/\\')];
        if (!version_compare($rel['version'], $current, '>')) return ['status' => 'current', 'message' => "the package ($rel[version]) is not newer than $current"];
    } else {
        $due = !empty($opt['force']) || !empty($opt['check_only']) || $s['check_requested'] === '1' || $requested
            || $s['last_check'] === '' || strtotime($s['last_check']) < time() - 1800;
        if (!$due && $s['ready_version'] === '') return ['status' => 'idle', 'message' => 'nothing to do (checked GitHub less than 30 minutes ago)'];
        flowork_upd_step($conn, 'checking GitHub for a new version');
        $rel = flowork_upd_find_release($s['repo'], $s['channel'], $current);
        flowork_upd_set($conn, ['last_check' => date('Y-m-d H:i:s'), 'check_requested' => '0']);
        if (isset($rel['error'])) { flowork_upd_log($conn, 'warn', 'Update check: ' . $rel['error']); flowork_upd_set($conn, ['status' => 'check failed']); return ['status' => 'error', 'message' => $rel['error']]; }
        if (!$rel) { flowork_upd_set($conn, ['latest_version' => $current, 'status' => 'up to date', 'ready_version' => '']); return ['status' => 'current', 'message' => "$current is the newest on the {$s['channel']} channel"]; }
        flowork_upd_set($conn, ['latest_version' => $rel['version'], 'status' => "{$rel['version']} available"]);
    }
    $v = $rel['version'];
    if (!empty($opt['check_only'])) return ['status' => 'available', 'message' => "$v is available (this site has $current)"];
    if ($s['failed_version'] === $v && !$requested && empty($opt['force'])) {
        return ['status' => 'held', 'message' => "$v failed to install before; it waits until an admin chooses Install now"];
    }

    // 2. Download and verify
    $dl = "$state/download-$v";
    if (isset($rel['local'])) {
        $dl = $rel['local'];
    } else {
        if (!is_dir($dl)) mkdir($dl, 0750, true);
        foreach (["flowork-$v.zip", "flowork-$v.manifest.json", "flowork-$v.manifest.sig"] as $a) {
            if (is_file("$dl/$a")) continue;
            flowork_upd_step($conn, "downloading $v ($a)");
            if (empty($rel['assets'][$a])) { flowork_upd_log($conn, 'error', "Release $v has no $a; it was not built by the release workflow.", $v); return ['status' => 'error', 'message' => "release $v has no $a"]; }
            [$code, $err] = flowork_upd_http_get($rel['assets'][$a], "$dl/$a.part", 300);
            if ($code !== 200) { @unlink("$dl/$a.part"); flowork_upd_log($conn, 'warn', "Download of $a failed ($code $err); it will try again.", $v); return ['status' => 'error', 'message' => "download failed: $a"]; }
            rename("$dl/$a.part", "$dl/$a");
        }
    }
    flowork_upd_step($conn, "checking the signature of $v");
    $ver = flowork_upd_verify($dl, $v, $pubkey);
    if (isset($ver['error'])) {
        flowork_upd_log($conn, 'error', "Version $v refused: " . $ver['error'], $v);
        if (!isset($rel['local'])) flowork_upd_rrmdir($dl);
        flowork_upd_set($conn, ['status' => "refused $v"]);
        return ['status' => 'error', 'message' => $ver['error']];
    }
    if ($s['ready_version'] !== $v) { flowork_upd_set($conn, ['ready_version' => $v]); flowork_upd_log($conn, 'info', "Version $v downloaded and verified.", $v); }

    // 3. When to install
    if (empty($opt['force']) && !$requested) {
        if ($s['mode'] === 'manual') { flowork_upd_set($conn, ['status' => "$v ready: waiting for an admin to install"]); return ['status' => 'ready', 'message' => "$v is ready; an admin installs it from System Updates"]; }
        $h = (int)date('G'); $a = (int)$s['window_start']; $b = (int)$s['window_end'];
        $in = $a <= $b ? ($h >= $a && $h < $b) : ($h >= $a || $h < $b);
        if (!$in) { flowork_upd_set($conn, ['status' => "$v ready: installs between {$a}:00 and {$b}:00"]); return ['status' => 'ready', 'message' => "$v waits for the update hours ({$a}:00–{$b}:00)"]; }
        $q = $conn->query("SELECT MAX(created_at) t FROM system_audit");
        $last = ($q && $q !== true) ? (string)($q->fetch_assoc()['t'] ?? '') : '';
        if ($last !== '' && strtotime($last) > time() - 900) { flowork_upd_set($conn, ['status' => "$v ready: waiting until nobody is working"]); return ['status' => 'ready', 'message' => 'someone worked in the last 15 minutes; waiting']; }
    }

    // 4. Install
    flowork_upd_log($conn, 'info', "Installing $v (was $current).", $v);
    flowork_upd_step($conn, "installing $v");
    $r = flowork_upd_install($conn, $root, $ver['manifest'], $ver['zip'], ['site_url' => $s['site_url'], 'lint' => true]);
    flowork_upd_set($conn, ['install_requested' => '0']);
    if (empty($r['ok'])) {
        flowork_upd_log($conn, 'error', "Installing $v failed: " . $r['error'], $v);
        flowork_upd_set($conn, ['failed_version' => $v, 'status' => "install of $v failed"]);
        return ['status' => 'failed', 'message' => $r['error']];
    }
    $sc = $r['schema'];
    $db = "database: {$sc['tables']} table(s), {$sc['columns']} column(s), {$sc['indexes']} index(es) added"
        . ($sc['seeded'] ? '; filled ' . implode(', ', $sc['seeded']) : '')
        . ($sc['errors'] ? '; ' . count($sc['errors']) . ' could not be added: ' . implode(' | ', array_slice($sc['errors'], 0, 5)) : '');
    flowork_upd_log($conn, $sc['errors'] ? 'warn' : 'info', "Updated from $current to $v. $db. Backup: {$r['backup']}.", $v);
    flowork_upd_set($conn, ['ready_version' => '', 'failed_version' => '', 'status' => "updated to $v", 'latest_version' => $v]);
    if (!isset($rel['local'])) flowork_upd_rrmdir($dl);
    return ['status' => 'updated', 'message' => "updated from $current to $v"];
}
