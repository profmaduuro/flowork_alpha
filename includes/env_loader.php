<?php
/**
 * env_loader.php — loads this app's .env and answers flowork_env($key).
 *
 * Values are kept per request in $GLOBALS['FLOWORK_DOTENV'] (and $_ENV /
 * $_SERVER), never written with putenv(). Under Apache on Windows every app
 * shares one process, and putenv() changed that process for every later
 * request: one app's APP_ENV or DB_NAME then leaked into another app's
 * requests, and with "an existing variable wins" the second app silently ran
 * on the first app's settings — fatal with one database per customer.
 *
 * Precedence in flowork_env():
 *   - web requests: this app's .env; the process environment only when
 *     FLOWORK_TRUST_PROCESS_ENV=1 is set for the container (Docker, where
 *     compose provides APP_ENV / DB_*), and only then does it win;
 *   - command line: the process environment wins (an explicit
 *     `DB_NAME=x php ...` or a parent script's putenv), then .env.
 */

(function() {
    $GLOBALS['FLOWORK_DOTENV'] = [];
    $env_path = dirname(__DIR__) . '/.env';
    if (!is_readable($env_path)) return;

    foreach (file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $key = trim($parts[0]);
        $val = trim($parts[1]);
        if (preg_match('/^"(.*)"$/', $val, $m) || preg_match("/^'(.*)'$/", $val, $m)) $val = $m[1];
        $GLOBALS['FLOWORK_DOTENV'][$key] = $val;
        $_ENV[$key] = $val;
        $_SERVER[$key] = $val;
    }
})();

if (!function_exists('flowork_env')) {
    /** A setting from this app's .env or the environment (see precedence above); $default when unset or empty. */
    function flowork_env(string $key, ?string $default = null): ?string {
        $proc = getenv($key);
        $proc = ($proc === false || $proc === '') ? null : (string)$proc;
        $dot  = $GLOBALS['FLOWORK_DOTENV'][$key] ?? null;
        $dot  = ($dot === null || $dot === '') ? null : (string)$dot;
        $process_first = PHP_SAPI === 'cli' || getenv('FLOWORK_TRUST_PROCESS_ENV') === '1';
        $v = $process_first ? ($proc ?? $dot) : $dot;
        return $v ?? $default;
    }
}

if (!function_exists('flowork_app_env')) {
    /** APP_ENV, lower-cased; '' when unset. */
    function flowork_app_env(): string {
        return strtolower(trim((string)flowork_env('APP_ENV', '')));
    }

    /**
     * Is this a local development install?
     *
     * Decided by APP_ENV when it is set. Otherwise by the server's own address
     * (SERVER_ADDR: loopback or private LAN, as with XAMPP reached by phones on
     * the LAN), never by the Host header, which the client chooses: sending a
     * dot-less Host used to switch a production server into "local" mode.
     */
    function flowork_is_local(): bool {
        $env = flowork_app_env();
        if ($env !== '') return in_array($env, ['local', 'development', 'dev'], true);
        if (PHP_SAPI === 'cli') return true;
        $addr = $_SERVER['SERVER_ADDR'] ?? '';
        if ($addr === '127.0.0.1' || $addr === '::1') return true;
        return filter_var($addr, FILTER_VALIDATE_IP) !== false
            && filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** Show PHP errors in the page only when APP_ENV explicitly says local. */
    function flowork_show_errors(): bool {
        return in_array(flowork_app_env(), ['local', 'development', 'dev'], true);
    }
}
