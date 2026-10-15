<?php
/**
 * env_loader.php — Simple, lightweight .env file loader for Flowork.
 * Loads environment variables from the project root .env file.
 */

(function() {
    $env_path = dirname(__DIR__) . '/.env';
    if (!file_exists($env_path)) {
        return;
    }

    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments and empty lines
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        // Parse key=value
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);

            // Strip surrounding quotes
            if (preg_match('/^"(.*)"$/', $val, $matches) || preg_match('/^\'(.*)\'$/', $val, $matches)) {
                $val = $matches[1];
            }

            // A real environment variable (e.g. set by docker-compose) wins over
            // .env, so the same mounted .env cannot flip a container's APP_ENV/DB_*.
            if (getenv($key) !== false) {
                continue;
            }

            // Set environment variable
            putenv("$key=$val");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
})();

if (!function_exists('flowork_app_env')) {
    /** APP_ENV from .env / the environment, lower-cased; '' when unset. */
    function flowork_app_env(): string {
        $v = getenv('APP_ENV');
        if ($v === false || $v === '') $v = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? '';
        return strtolower(trim((string)$v));
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
