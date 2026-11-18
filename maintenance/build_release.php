<?php
/**
 * Build a signed Flowork release package — command line only.
 *
 *   php maintenance/build_release.php --version=1.0.1 --out=dist [--key=private.pem] [--channel=stable]
 *
 * The private key comes from --key, or from the FLOWORK_SIGNING_KEY environment
 * variable (the GitHub secret in .github/workflows/release.yml). Writes:
 *   dist/flowork-<v>.zip            the program files (git-tracked, minus developer-only
 *                                   and installation-owned paths), with VERSION = <v>
 *   dist/flowork-<v>.manifest.json  version, package SHA-256, every file's SHA-256
 *   dist/flowork-<v>.manifest.sig   RSA-SHA256 signature of the manifest, base64
 * Installations verify all three with updater/public_key.pem before installing.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/updater.php';

$o = getopt('', ['version:', 'out:', 'key:', 'channel:', 'src:']);
$v = (string)($o['version'] ?? '');
if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $v)) { fwrite(STDERR, "--version=X.Y.Z required\n"); exit(2); }
$out = rtrim((string)($o['out'] ?? "$root/dist"), '/\\');
$src = rtrim((string)($o['src'] ?? $root), '/\\');
$pem = isset($o['key']) ? (string)@file_get_contents($o['key']) : (string)getenv('FLOWORK_SIGNING_KEY');
$key = $pem !== '' ? @openssl_pkey_get_private($pem) : false;
if (!$key) { fwrite(STDERR, "No usable private key (--key=file or FLOWORK_SIGNING_KEY).\n"); exit(2); }

// The files: what git knows about, read from the working tree
$list = [];
// tracked files plus new ones not yet committed (on GitHub everything is committed)
exec('git -C ' . escapeshellarg($src) . ' ls-files -co --exclude-standard -z', $lines, $rc);
if ($rc !== 0) { fwrite(STDERR, "git ls-files failed in $src\n"); exit(2); }
foreach (explode("\0", implode("\n", $lines)) as $f) {
    $f = trim($f, "\n");
    if ($f === '' || $f === 'VERSION' || flowork_upd_excluded_from_release($f) || !is_file("$src/$f")) continue;
    $list[] = $f;
}
sort($list);

if (!is_dir($out)) mkdir($out, 0755, true);
$zipf = "$out/flowork-$v.zip";
@unlink($zipf);
$z = new ZipArchive();
if ($z->open($zipf, ZipArchive::CREATE) !== true) { fwrite(STDERR, "Cannot write $zipf\n"); exit(2); }
$hashes = [];
foreach ($list as $f) {
    $data = (string)file_get_contents("$src/$f");
    $z->addFromString($f, $data);
    $hashes[$f] = hash('sha256', $data);
}
$z->addFromString('VERSION', "$v\n");
$hashes['VERSION'] = hash('sha256', "$v\n");
$z->close();

$manifest = [
    'name'    => 'flowork',
    'version' => $v,
    'channel' => (string)($o['channel'] ?? (str_contains($v, '-') ? 'test' : 'stable')),
    'created' => gmdate('c'),
    'sha256'  => hash_file('sha256', $zipf),
    'size'    => filesize($zipf),
    'files'   => $hashes,
];
$mjson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents("$out/flowork-$v.manifest.json", $mjson);
if (!openssl_sign($mjson, $sig, $key, OPENSSL_ALGO_SHA256)) { fwrite(STDERR, "Signing failed\n"); exit(2); }
file_put_contents("$out/flowork-$v.manifest.sig", base64_encode($sig) . "\n");
echo "Built flowork-$v: " . count($hashes) . " files, " . round(filesize($zipf) / 1048576, 2) . " MB, signed.\n";
