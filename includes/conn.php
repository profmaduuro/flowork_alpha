<?php
mysqli_report(MYSQLI_REPORT_OFF);

// Load environment variables if available
require_once __DIR__ . '/env_loader.php';

// ── Being updated? ────────────────────────────────────────────────────────
// The self-updater (updater/update.php) sets this flag for the few seconds it
// swaps files. Visitors get a short "back in a minute" page; the command line
// (the updater itself) is never stopped. The flag counts as stale after 10 minutes.
if (PHP_SAPI !== 'cli') {
    $_fw_upd_flag = dirname(__DIR__) . '/updater/state/maintenance.flag';
    if (is_file($_fw_upd_flag) && (int)@file_get_contents($_fw_upd_flag) > time() - 600) {
        http_response_code(503);
        header('Retry-After: 60');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<meta http-equiv="refresh" content="30"><title>Updating — Flowork</title></head>'
           . '<body style="font-family:system-ui,sans-serif;background:#F6F3EC;color:#1F1A14;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
           . '<div style="text-align:center;padding:2rem"><h1 style="font-size:1.4rem;margin:0 0 .5rem">Flowork is being updated</h1>'
           . '<p style="color:#6B6253;margin:0">It will be back in a minute. This page reloads by itself.</p></div></body></html>';
        exit;
    }
    unset($_fw_upd_flag);
}

// ── Base path ─────────────────────────────────────────────────────────────
if (!defined('APP_BASE')) {
    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $doc_root   = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
    $sub        = str_replace($doc_root, '', $script_dir);
    $sub        = rtrim(preg_replace('#/includes$#', '', $sub), '/');
    define('APP_BASE', $sub);
}
if (!function_exists('base_url')) {
    function base_url($path = '') { return APP_BASE . '/' . ltrim($path, '/'); }
}

// ── Credentials — three environments, one file ────────────────────────────
//
//  DOCKER/.ENV : reads from environment variables
//  LOCAL       : XAMPP localhost / root / no password
//  LIVE        : cPanel credentials (edit the live section below or use .env)
//

// Enforce secure session cookie settings
$secure_cookie = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
              || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure_cookie,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
} elseif (session_status() === PHP_SESSION_ACTIVE) {
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => 0,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $secure_cookie,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// Local install? (APP_ENV, or the server's own address — never the Host header)
$_is_local = flowork_is_local();

ini_set('display_errors', flowork_show_errors() ? '1' : '0');
ini_set('log_errors', '1');

$_host = flowork_env('DB_HOST');

if ($_host) {
    // ── Environment Variables (.env / Docker) ─────────────────────────────
    $_db_host = $_host;
    $_db_name = flowork_env('DB_NAME', 'king');
    $_db_user = flowork_env('DB_USER', 'flowork');
    $_db_pass = flowork_env('DB_PASS', '');
} else {
    if ($_is_local) {
        // ── XAMPP localhost ───────────────────────────────────────────────
        $_db_host = 'localhost';
        $_db_name = 'king';
        $_db_user = 'root';
        $_db_pass = '';
    } else {
        // In production, require environment variables. Do not fallback to hardcoded secrets.
        $_db_host = '';
        $_db_name = '';
        $_db_user = '';
        $_db_pass = '';
    }
}

if (empty($_db_host) || empty($_db_user)) {
    $msg = 'Database configuration parameters missing or incomplete.';
    error_log($msg);
    die(flowork_show_errors() ? "<pre style='color:red;padding:20px'>$msg</pre>"
                   : "<p style='font-family:sans-serif;padding:40px;text-align:center'>System configuration error.</p>");
}

// ── Connect ───────────────────────────────────────────────────────────────
$conn = @new mysqli($_db_host, $_db_user, $_db_pass, '');

if ($conn->connect_error) {
    $msg = 'DB connection failed: ' . $conn->connect_error;
    error_log($msg);
    die(flowork_show_errors() ? "<pre style='color:red;padding:20px'>$msg</pre>"
                   : "<p style='font-family:sans-serif;padding:40px;text-align:center'>System unavailable.</p>");
}

// Prevent database name SQL injection
$_db_name_clean = preg_replace('/[^a-zA-Z0-9_]/', '', $_db_name);
// Create the database only on a fresh install (MySQL error 1049, unknown
// database), not as an extra query on every request.
if (!@$conn->select_db($_db_name) && $conn->errno === 1049) {
    $conn->query("CREATE DATABASE IF NOT EXISTS `$_db_name_clean` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->select_db($_db_name);
}
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+02:00'");

// Database configuration loaded. Table setup has been moved to setup_database.php.

if (!function_exists('find_grower_normalized')) {
    function find_grower_normalized($conn, $raw_grower_num, $spid = 0) {
        $raw = trim($raw_grower_num);
        if (!$raw) return null;

        // Convert to uppercase
        $cleaned = strtoupper($raw);

        // Strip ZW- or ZW prefix
        $cleaned = preg_replace('/^ZW-?/', '', $cleaned);

        // Strip all non-alphanumeric characters
        $cleaned = preg_replace('/[^A-Z0-9]/', '', $cleaned);

        if (!$cleaned) return null;

        // Prepend V if purely numeric
        if (preg_match('/^\d+$/', $cleaned)) {
            $cleaned_v = 'V' . $cleaned;
        } else {
            $cleaned_v = $cleaned;
        }

        $spid = (int)$spid;
        $sp_cond = ($spid > 0) ? "AND (selling_pointid = $spid OR selling_pointid = 0)" : "";

        // Tier 1: Try exact match on cleaned string (highly indexed, extremely fast)
        $esc_cleaned   = $conn->real_escape_string($cleaned);
        $esc_cleaned_v = $conn->real_escape_string($cleaned_v);
        $res = $conn->query("SELECT id, grower_num, name, surname, selling_pointid FROM growers WHERE grower_num = '$esc_cleaned' $sp_cond LIMIT 1");
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc();
        }

        // Tier 2: Try exact match on cleaned string with 'V' prepended (if numeric)
        if ($cleaned_v !== $cleaned) {
            $res = $conn->query("SELECT id, grower_num, name, surname, selling_pointid FROM growers WHERE grower_num = '$esc_cleaned_v' $sp_cond LIMIT 1");
            if ($res && $res->num_rows > 0) {
                return $res->fetch_assoc();
            }
        }

        // Tier 3: Database-level normalized lookup (removes hyphens, spaces, slashes, ZW from both input and DB record)
        $sql = "SELECT id, grower_num, name, surname, selling_pointid FROM growers 
                WHERE (
                    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(grower_num, '-', ''), ' ', ''), '/', ''), '_', ''), 'ZW', '') = '$esc_cleaned'
                    OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(grower_num, '-', ''), ' ', ''), '/', ''), '_', ''), 'ZW', '') = '$esc_cleaned_v'
                ) $sp_cond LIMIT 1";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc();
        }

        // Tier 4: Suffix/partial digit lookup (if input has at least 4 digits, look for grower numbers ending in those digits)
        if (preg_match('/^\d{4,8}$/', $cleaned)) {
            $sql = "SELECT id, grower_num, name, surname, selling_pointid FROM growers 
                    WHERE REPLACE(REPLACE(REPLACE(REPLACE(grower_num, '-', ''), ' ', ''), '/', ''), '_', '') LIKE '%$esc_cleaned' $sp_cond LIMIT 1";
            $res = $conn->query($sql);
            if ($res && $res->num_rows > 0) {
                return $res->fetch_assoc();
            }
        }

        return null;
    }
}

if (!function_exists('sync_booked_delivery_bales')) {
    function sync_booked_delivery_bales($conn, $growerid, $sell_date) {
        $gid = (int)$growerid;
        $sd = $conn->real_escape_string($sell_date);
        
        // Calculate total bales delivered from transporter_growers for this grower and date
        $q = $conn->query("SELECT SUM(bales) as tot FROM transporter_growers WHERE growerid=$gid AND sell_date='$sd'");
        $tot = 0;
        if ($q && $q->num_rows > 0) {
            $tot = (int)$q->fetch_assoc()['tot'];
        }
        
        // Get the booked quantity to cap the delivered quantity
        $bk_q = $conn->query("SELECT bales_booked FROM booked_bales WHERE growerid=$gid AND sell_date='$sd' LIMIT 1");
        $booked = 0;
        $is_booked = 0;
        if ($bk_q && $bk_q->num_rows > 0) {
            $booked = (int)$bk_q->fetch_assoc()['bales_booked'];
            $is_booked = 1;
        }
        
        $delivered = min($tot, $booked);
        
        // Update booked_bales
        $conn->query("UPDATE booked_bales SET bales_delivered = $delivered WHERE growerid=$gid AND sell_date='$sd'");
        
        // Also make sure transporter_growers.booked is set to 1 if there's a booking
        $conn->query("UPDATE transporter_growers SET booked = $is_booked WHERE growerid=$gid AND sell_date='$sd'");
    }
}

if (!function_exists('flowork_delivery_booking')) {
    /**
     * The booking that lets a delivery be balanced: the grower's booking for the
     * delivery's own sell date, with bales and a booking ID. A booking on another
     * day does not count, so a delivery rolled over to a new selling day must be
     * booked again for that day. Returns the booked_bales row, or null.
     */
    function flowork_delivery_booking(mysqli $conn, int $tgid): ?array {
        $q = $conn->query("SELECT growerid, sell_date FROM transporter_growers WHERE id=$tgid LIMIT 1");
        $tg = ($q && $q !== true) ? $q->fetch_assoc() : null;
        if (!$tg || !$tg['sell_date']) return null;
        $gid = (int)$tg['growerid'];
        $sd  = $conn->real_escape_string($tg['sell_date']);
        $alt = $conn->real_escape_string(date('d-M-y', strtotime($tg['sell_date'])));
        $b = $conn->query("SELECT id, bales_booked, booked_id, sell_date FROM booked_bales
            WHERE growerid=$gid AND (sell_date='$sd' OR sell_date='$alt')
              AND bales_booked > 0 AND TRIM(COALESCE(booked_id,'')) <> ''
            ORDER BY id DESC LIMIT 1");
        return ($b && $b !== true && $b->num_rows) ? $b->fetch_assoc() : null;
    }
}

