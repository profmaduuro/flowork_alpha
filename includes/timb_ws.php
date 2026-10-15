<?php
/**
 * includes/timb_ws.php — T.I.M.B. web service client.
 *
 * Shared transport for every TIMB web service (see the TIMB Webservices
 * documentation set). Grower master is the first consumer; the rest of the
 * services are declared in timb_ws_services() so they plug into the same
 * auth, logging and retry plumbing as they are implemented.
 *
 * Contract (per TIMB docs):
 *   Method        POST
 *   Content-Type  application/x-www-form-urlencoded
 *   Payload       JSON encoded
 *   Auth          Authorization: Digest <username>:<md5(password)>
 *                 NB this is a LITERAL header, not RFC-2617 digest
 *                 challenge/response — CURLAUTH_DIGEST must NOT be used.
 *   Failure       HTTP 401 when authorization is denied
 *
 * Two credential sets exist (see "WebService TIMB authentication.docx"):
 *   booking    Booking & Registration system  -> grower master, bookings
 *   stoporder  Stop order system              -> bale/deduction/transporter,
 *                                                stop order, creditor, grades,
 *                                                price matrix
 */

if (!defined('TIMB_WS_LOADED')) {
define('TIMB_WS_LOADED', 1);

define('TIMB_WS_DEFAULT_BASE', 'https://www.timb.co.zw');
define('TIMB_WS_LOG_CAP',      65536);   // bytes of response body kept in the log

/* ── Service registry ──────────────────────────────────────────────────── */

function timb_ws_services() {
    return [
        'growermaster'     => ['path'=>'/growermasterWebservices',    'cred'=>'booking',   'label'=>'Grower master (by number)', 'ready'=>true],
        'growermaster_all' => ['path'=>'/growermasterAllWebservices', 'cred'=>'booking',   'label'=>'Grower master (full DB)',   'ready'=>true],
        'booking'          => ['path'=>'/bookingWebservices',         'cred'=>'booking',   'label'=>'Bookings (query)',          'ready'=>true],
        'booking_insert'   => ['path'=>'/insertbookingWebservices',   'cred'=>'booking',   'label'=>'Bookings (insert)',         'ready'=>true],
        'booking_update'   => ['path'=>'/updatebookingWebservices',   'cred'=>'booking',   'label'=>'Bookings (update)',         'ready'=>true],
        'booking_delete'   => ['path'=>'/deletebookingWebservices',   'cred'=>'booking',   'label'=>'Bookings (delete)',         'ready'=>true],
        'creditorlist'     => ['path'=>'/creditorlistWebservices',    'cred'=>'stoporder', 'label'=>'Creditor list',             'ready'=>false],
        'creditor_all'     => ['path'=>'/creditorAllWebservices',     'cred'=>'stoporder', 'label'=>'Creditor file (all)',       'ready'=>false],
        'balefile'         => ['path'=>'/EODBalefileWebservices',        'cred'=>'stoporder', 'label'=>'EOD bale file',          'ready'=>false],
        'deductionfile'    => ['path'=>'/EODDeductionfileWebservices',   'cred'=>'stoporder', 'label'=>'EOD deduction file',     'ready'=>false],
        'transporterfile'  => ['path'=>'/EODTransporterfileWebservices', 'cred'=>'stoporder', 'label'=>'EOD transporter file',   'ready'=>false],
        'stoporder'        => ['path'=>'/stoporderWebservices',       'cred'=>'stoporder', 'label'=>'Stop order file',           'ready'=>false],
        'newgrade'         => ['path'=>'/newgradeWebservices',        'cred'=>'stoporder', 'label'=>'New grade submission',      'ready'=>true],
        'pricematrix'      => ['path'=>'/pricematrixWebservices',     'cred'=>'stoporder', 'label'=>'Price matrix (by date)',    'ready'=>true],
        'seasonalpricematrix' => ['path'=>'/seasonalpricematrixWebservices', 'cred'=>'stoporder', 'label'=>'Price matrix (previous season)', 'ready'=>true],
        // No grade-download endpoint appears anywhere in the TIMB documentation
        // set — /newgradeWebservices only submits. This slot exists so that if
        // TIMB supply one, its path can be set in settings without a code
        // change. Empty path = not configured, and calls are refused.
        'grades_fetch'     => ['path'=>'', 'cred'=>'stoporder', 'label'=>'Grade list download (undocumented)', 'ready'=>false, 'path_setting'=>'timb_grades_path'],
    ];
}

/* ── Schema ────────────────────────────────────────────────────────────── */

/** Add a column only if it is missing (portable across MySQL/MariaDB). */
function timb_ws_add_column($conn, $table, $column, $definition) {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $r = $conn->query("SELECT 1 FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1");
    if ($r && $r !== true && $r->num_rows > 0) return false;
    return (bool)$conn->query("ALTER TABLE `$t` ADD COLUMN `$c` $definition");
}

function timb_ws_schema($conn) {
    static $done = false;
    if ($done) return;
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS timb_service_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        selling_pointid INT NOT NULL DEFAULT 0,
        service VARCHAR(40) NOT NULL,
        url VARCHAR(255) DEFAULT '',
        request_params MEDIUMTEXT DEFAULT NULL,
        http_code INT DEFAULT 0,
        duration_ms INT DEFAULT 0,
        attempts TINYINT DEFAULT 1,
        ok TINYINT DEFAULT 0,
        simulated TINYINT DEFAULT 0,
        rows_returned INT DEFAULT 0,
        error_message VARCHAR(255) DEFAULT '',
        response_body MEDIUMTEXT DEFAULT NULL,
        userid INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sp_svc (selling_pointid, service, created_at)
    ) ENGINE=InnoDB");

    $conn->query("CREATE TABLE IF NOT EXISTS timb_credentials (
        cred_set VARCHAR(20) NOT NULL PRIMARY KEY,
        username VARCHAR(120) DEFAULT '',
        password_enc TEXT DEFAULT NULL,
        updated_by INT DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $conn->query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(80) PRIMARY KEY, value TEXT DEFAULT '')");

    // timb_grower_registry predates the web service; widen it to the full
    // grower master response shape.
    $conn->query("CREATE TABLE IF NOT EXISTS timb_grower_registry (
        id INT AUTO_INCREMENT PRIMARY KEY, selling_pointid INT NOT NULL,
        grower_num VARCHAR(40) NOT NULL, name VARCHAR(80) DEFAULT '',
        surname VARCHAR(80) DEFAULT '', national_id VARCHAR(40) DEFAULT '',
        province VARCHAR(60) DEFAULT '', district_name VARCHAR(60) DEFAULT '',
        area VARCHAR(60) DEFAULT '', farm_name VARCHAR(80) DEFAULT '',
        crop_type VARCHAR(30) DEFAULT 'Virginia', registration_date DATE DEFAULT NULL,
        status ENUM('active','suspended','cancelled') DEFAULT 'active',
        timb_verified TINYINT DEFAULT 0, last_synced TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_tgr (selling_pointid, grower_num), INDEX idx_sp (selling_pointid)
    ) ENGINE=InnoDB");

    foreach ([
        'initials'              => "VARCHAR(20) DEFAULT ''",
        'postal_address1'       => "VARCHAR(120) DEFAULT ''",
        'postal_address2'       => "VARCHAR(120) DEFAULT ''",
        'grower_type'           => "VARCHAR(10) DEFAULT ''",     // TIMB "type"
        'district'              => "VARCHAR(20) DEFAULT ''",     // TIMB district CODE
        'grower_class'          => "VARCHAR(10) DEFAULT ''",     // TIMB "class"
        'current_reg'           => "VARCHAR(10) DEFAULT ''",
        'next_reg'              => "VARCHAR(10) DEFAULT ''",
        'email'                 => "VARCHAR(120) DEFAULT ''",
        'association'           => "VARCHAR(60) DEFAULT ''",
        'barn_type'             => "VARCHAR(20) DEFAULT ''",
        'amend_type'            => "VARCHAR(5) DEFAULT ''",
        'contractor'            => "VARCHAR(60) DEFAULT ''",
        'hectares'              => "DECIMAL(10,2) DEFAULT 0",
        'landholding'           => "VARCHAR(20) DEFAULT ''",
        'obligations_fulfilled' => "VARCHAR(5) DEFAULT ''",
        'source'                => "VARCHAR(20) DEFAULT 'csv'",  // csv | growermaster | growermaster_all
        'raw_payload'           => "TEXT DEFAULT NULL",
    ] as $col => $def) {
        timb_ws_add_column($conn, 'timb_grower_registry', $col, $def);
    }

    // Price matrix — grade/price pairs, either for one sale date or for the
    // previous season. Rows are replaced wholesale on each fetch, so no unique
    // key is needed (and seasonal rows carry a NULL matrix_date, which a unique
    // key would not collapse anyway).
    $conn->query("CREATE TABLE IF NOT EXISTS timb_price_matrix (
        id INT AUTO_INCREMENT PRIMARY KEY,
        selling_pointid INT NOT NULL DEFAULT 0,
        matrix_type ENUM('daily','seasonal') NOT NULL DEFAULT 'daily',
        matrix_date DATE DEFAULT NULL,
        grade VARCHAR(40) NOT NULL,
        price_raw VARCHAR(20) DEFAULT '',
        price_usd DECIMAL(10,4) DEFAULT 0,
        simulated TINYINT DEFAULT 0,
        fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lookup (selling_pointid, matrix_type, matrix_date),
        INDEX idx_grade (grade)
    ) ENGINE=InnoDB");
    // Added after the table shipped — simulated prices must never be mistaken
    // for prices TIMB actually published.
    timb_ws_add_column($conn, 'timb_price_matrix', 'simulated', "TINYINT DEFAULT 0");

    // Every booking push to TIMB, with its outcome. Bookings are state on a
    // regulator's system, so insert/update/delete are recorded permanently
    // even when they fail.
    $conn->query("CREATE TABLE IF NOT EXISTS timb_booking_ops (
        id INT AUTO_INCREMENT PRIMARY KEY,
        selling_pointid INT NOT NULL DEFAULT 0,
        operation ENUM('query','insert','update','delete') NOT NULL,
        grower_num VARCHAR(40) NOT NULL DEFAULT '',
        booked_bales_id INT DEFAULT 0,
        sale_date DATE DEFAULT NULL,
        booking_date DATE DEFAULT NULL,
        bales_booked INT DEFAULT 0,
        reoffer CHAR(1) DEFAULT 'N',
        floor_code VARCHAR(20) DEFAULT '',
        booking_id VARCHAR(60) DEFAULT '',
        result VARCHAR(20) DEFAULT '',
        message VARCHAR(255) DEFAULT '',
        ok TINYINT DEFAULT 0,
        simulated TINYINT DEFAULT 0,
        log_id INT DEFAULT 0,
        userid INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sp (selling_pointid, created_at),
        INDEX idx_grower (grower_num, sale_date)
    ) ENGINE=InnoDB");

    // One row per grade per submission attempt. TIMB confirms a whole batch
    // with a single message, so batch_ref ties rows sent together.
    $conn->query("CREATE TABLE IF NOT EXISTS timb_grade_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        selling_pointid INT NOT NULL DEFAULT 0,
        grade VARCHAR(40) NOT NULL,
        batch_ref VARCHAR(40) DEFAULT '',
        batch_size INT DEFAULT 1,
        confirmation VARCHAR(60) DEFAULT '',
        accepted TINYINT DEFAULT 0,
        simulated TINYINT DEFAULT 0,
        log_id INT DEFAULT 0,
        userid INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sp (selling_pointid, created_at),
        INDEX idx_grade (grade)
    ) ENGINE=InnoDB");
}

/* ── Settings ──────────────────────────────────────────────────────────── */

function timb_ws_setting($conn, $key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $r = $conn->query("SELECT name, value FROM settings WHERE name LIKE 'timb_%'");
        if ($r && $r !== true) while ($row = $r->fetch_assoc()) $cache[$row['name']] = $row['value'];
    }
    $env = getenv(strtoupper($key));
    if ($env !== false && $env !== '') return $env;
    return (isset($cache[$key]) && $cache[$key] !== '') ? $cache[$key] : $default;
}

function timb_ws_set_setting($conn, $key, $value) {
    $k = $conn->real_escape_string($key);
    $v = $conn->real_escape_string($value);
    $conn->query("INSERT INTO settings (name,value) VALUES ('$k','$v')
                  ON DUPLICATE KEY UPDATE value='$v'");
}

function timb_ws_base_url($conn) {
    return rtrim(timb_ws_setting($conn, 'timb_base_url', TIMB_WS_DEFAULT_BASE), '/');
}

/**
 * Base URL for one service. TIMB run the booking/grower-master webservices and
 * the stop-order (debt recovery) webservices as SEPARATE systems — the latter
 * has its own login at /sos19/ and does not accept the booking credentials at
 * the main host. When a stop-order base URL is configured it is used for every
 * 'stoporder' service; otherwise those fall back to the main base (current
 * behaviour), so this is a no-op until TIMB supply the stop-order endpoint.
 */
function timb_ws_base_for($conn, $def) {
    if (($def['cred'] ?? '') === 'stoporder') {
        $so = rtrim(timb_ws_setting($conn, 'timb_stoporder_base_url', ''), '/');
        if ($so !== '') return $so;
    }
    return timb_ws_base_url($conn);
}

/**
 * CA bundle used to verify the TIMB endpoint.
 *
 * TIMB serves only their leaf certificate and omit the "RapidSSL TLS RSA CA G1"
 * intermediate, so OpenSSL cannot build a path to a trusted root and fails with
 * "unable to get local issuer certificate". Browsers hide this by fetching the
 * intermediate over AIA; PHP's curl does not. certs/timb-ca.pem carries that
 * intermediate plus its root so the chain completes with verification left ON.
 *
 * Returns null when the file is absent, in which case curl uses the system CA
 * bundle (php.ini curl.cainfo) exactly as before.
 */
function timb_ws_cainfo() {
    $path = __DIR__ . '/certs/timb-ca.pem';
    return is_readable($path) ? $path : null;
}

/** Mock mode: explicitly enabled, or credentials not configured yet. */
function timb_ws_is_mock($conn, $cred_set = 'booking') {
    if (timb_ws_setting($conn, 'timb_mock_mode', '0') === '1') return true;
    $c = timb_ws_credentials($conn, $cred_set);
    return ($c['username'] === '' || $c['password'] === '');
}

/* ── Credential storage (encrypted at rest) ────────────────────────────── */

/**
 * Encryption key. Preferred source is the TIMB_ENC_KEY environment variable
 * (.env). Failing that a key is generated once into includes/timb_key.php —
 * a .php file so Apache executes rather than serves it.
 */
function timb_ws_enc_key() {
    static $key = null;
    if ($key !== null) return $key;

    $env = getenv('TIMB_ENC_KEY');
    if ($env !== false && strlen($env) >= 32) return $key = substr(hash('sha256', $env, true), 0, 32);

    $file = __DIR__ . '/timb_key.php';
    if (is_readable($file)) {
        $stored = @include $file;
        if (is_string($stored) && strlen($stored) >= 32) {
            return $key = substr(hash('sha256', $stored, true), 0, 32);
        }
    }
    $secret = bin2hex(random_bytes(32));
    @file_put_contents($file, "<?php\n// Auto-generated TIMB credential encryption key. Do not commit.\nreturn '" . $secret . "';\n", LOCK_EX);
    @chmod($file, 0600);
    return $key = substr(hash('sha256', $secret, true), 0, 32);
}

function timb_ws_encrypt($plain) {
    if ($plain === '') return '';
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', timb_ws_enc_key(), OPENSSL_RAW_DATA, $iv);
    if ($ct === false) return '';
    return base64_encode($iv) . ':' . base64_encode($ct);
}

function timb_ws_decrypt($blob) {
    if (!$blob || strpos($blob, ':') === false) return '';
    list($iv_b64, $ct_b64) = explode(':', $blob, 2);
    $iv = base64_decode($iv_b64, true);
    $ct = base64_decode($ct_b64, true);
    if ($iv === false || $ct === false || strlen($iv) !== 16) return '';
    $plain = openssl_decrypt($ct, 'aes-256-cbc', timb_ws_enc_key(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

/**
 * Resolve a credential set. Environment variables win over the database so a
 * production floor can keep secrets entirely out of the DB:
 *   TIMB_BOOKING_USER   / TIMB_BOOKING_PASS
 *   TIMB_STOPORDER_USER / TIMB_STOPORDER_PASS
 */
function timb_ws_credentials($conn, $cred_set = 'booking') {
    $set = in_array($cred_set, array('booking','stoporder'), true) ? $cred_set : 'booking';

    $eu = getenv('TIMB_' . strtoupper($set) . '_USER');
    $ep = getenv('TIMB_' . strtoupper($set) . '_PASS');
    if ($eu !== false && $eu !== '' && $ep !== false && $ep !== '') {
        return ['username'=>$eu, 'password'=>$ep, 'source'=>'env'];
    }

    $s = $conn->real_escape_string($set);
    $r = $conn->query("SELECT username, password_enc FROM timb_credentials WHERE cred_set='$s' LIMIT 1");
    if ($r && $r !== true && $r->num_rows > 0) {
        $row = $r->fetch_assoc();
        return [
            'username' => (string)$row['username'],
            'password' => timb_ws_decrypt($row['password_enc']),
            'source'   => 'db',
        ];
    }
    return ['username'=>'', 'password'=>'', 'source'=>'none'];
}

function timb_ws_save_credentials($conn, $cred_set, $username, $password, $userid = 0) {
    $set = in_array($cred_set, array('booking','stoporder'), true) ? $cred_set : 'booking';
    $s   = $conn->real_escape_string($set);
    $u   = $conn->real_escape_string(trim($username));
    $uid = (int)$userid;

    if ($password === '') {
        // Blank password field = keep the stored one.
        $conn->query("INSERT INTO timb_credentials (cred_set,username,password_enc,updated_by)
                      VALUES ('$s','$u',NULL,$uid)
                      ON DUPLICATE KEY UPDATE username='$u', updated_by=$uid");
        return true;
    }
    $enc = $conn->real_escape_string(timb_ws_encrypt($password));
    return (bool)$conn->query("INSERT INTO timb_credentials (cred_set,username,password_enc,updated_by)
                               VALUES ('$s','$u','$enc',$uid)
                               ON DUPLICATE KEY UPDATE username='$u', password_enc='$enc', updated_by=$uid");
}

/* ── Transport ─────────────────────────────────────────────────────────── */

/**
 * Call a TIMB web service.
 *
 * @return array ok, http, data (decoded JSON), raw, error, log_id, simulated,
 *               duration_ms, attempts
 */
function timb_ws_call($conn, $service, $params = array(), $opts = array()) {
    $services = timb_ws_services();
    if (!isset($services[$service])) {
        return ['ok'=>false,'http'=>0,'data'=>null,'raw'=>'','error'=>"Unknown TIMB service '$service'",
                'log_id'=>0,'simulated'=>false,'duration_ms'=>0,'attempts'=>0];
    }
    $def  = $services[$service];
    $spid = (int)(isset($opts['spid']) ? $opts['spid'] : ($_SESSION['selling_pointid'] ?? 0));
    $uid  = (int)(isset($opts['userid']) ? $opts['userid'] : (function_exists('current_userid') ? current_userid() : 0));

    timb_ws_schema($conn);

    if (!empty($opts['force_mock']) || timb_ws_is_mock($conn, $def['cred'])) {
        $res = timb_ws_mock($service, $params, $conn);
        $res['log_id'] = timb_ws_log($conn, $spid, $uid, $service, '(simulated)', $params, $res);
        return $res;
    }

    $cred = timb_ws_credentials($conn, $def['cred']);

    // A service may take its path from settings (see 'grades_fetch').
    $path = $def['path'];
    if (!empty($def['path_setting'])) $path = timb_ws_setting($conn, $def['path_setting'], '');
    if ($path === '') {
        $res = ['ok'=>false,'http'=>0,'data'=>null,'raw'=>'','simulated'=>false,'duration_ms'=>0,'attempts'=>0,
                'error'=>"No endpoint configured for '{$def['label']}'. TIMB does not document this service; "
                       . "if they have given you a path, set it in TIMB settings."];
        $res['log_id'] = timb_ws_log($conn, $spid, $uid, $service, '(not configured)', $params, $res);
        return $res;
    }
    $url = timb_ws_base_for($conn, $def) . $path;
    $body = http_build_query($params);
    // The TIMB docs illustrate the request as a POST carrying its parameters in
    // the query string; the body carries the same pairs urlencoded.
    $full = $url . ($body !== '' ? '?' . $body : '');

    $timeout = (int)(isset($opts['timeout']) ? $opts['timeout'] : timb_ws_setting($conn, 'timb_timeout', '60'));
    $tries   = max(1, (int)(isset($opts['retries']) ? $opts['retries'] : 3));
    $verify  = timb_ws_setting($conn, 'timb_ssl_verify', '1') === '1';

    $raw = ''; $http = 0; $err = ''; $errno = 0; $attempt = 0; $t0 = microtime(true);

    while ($attempt < $tries) {
        $attempt++;
        $ch = curl_init($full);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                // Literal digest header per TIMB spec — NOT CURLAUTH_DIGEST.
                'Authorization: Digest ' . $cred['username'] . ':' . md5($cred['password']),
            ],
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Flowork/TIMB-WS',
        ]);
        // Supply the intermediate TIMB omits from their handshake.
        if ($verify && ($cainfo = timb_ws_cainfo())) {
            curl_setopt($ch, CURLOPT_CAINFO, $cainfo);
        }
        $raw    = (string)curl_exec($ch);
        $http   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        $errno  = curl_errno($ch);
        curl_close($ch);

        // Retry transient failures only; 4xx is a permanent answer.
        $transient = ($err !== '' || $http === 0 || $http >= 500);
        if (!$transient) break;
        if ($attempt < $tries) usleep(400000 * $attempt);
    }

    $duration = (int)round((microtime(true) - $t0) * 1000);
    $data = null; $ok = false;

    if ($err !== '') {
        $err = timb_ws_friendly_error($errno, $err, $url, $timeout, $attempt);
    } elseif ($http === 401) {
        // TIMB's own 401 body distinguishes between the username/password, the
        // floor code registered against that username, and the hash format --
        // which the previous generic wording threw away.
        $label  = $def['cred'] === 'booking' ? 'Booking & Registration' : 'Stop Order';
        $reason = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
        $err = "TIMB rejected the $label credentials for this service (HTTP 401)"
             . ($reason !== '' ? ': ' . substr($reason, 0, 180) : '.')
             . " Note that TIMB issue a separate login per service group: a username that "
             . "works for bookings and grower master is still refused here unless TIMB have "
             . "registered it for $label services against this floor code.";
    } elseif ($http >= 500) {
        // A 5xx is TIMB's server failing, not our request. Their body is a
        // generic Apache error page, so don't echo it back at the user.
        $label = $def['label'] ?? $service;
        $err = "TIMB's server returned HTTP $http for the \"$label\" service. "
             . "This is a fault on TIMB's side — not your request, credentials or floor code "
             . "(the same login is accepted by their other services). It is usually transient: "
             . "retry shortly, and if it persists report it to TIMB (it@timb.co.zw) with the time it occurred.";
        if ($service === 'growermaster_all') {
            $err .= " Meanwhile the by-number Grower Lookup is unaffected, so you can still fetch "
                  . "specific growers there — including by uploading a file of grower numbers.";
        }
    } elseif ($http < 200 || $http >= 300) {
        $reason = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
        $err = "TIMB returned HTTP $http" . ($reason !== '' ? ': ' . substr($reason, 0, 180) : '');
    } else {
        $data = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // One match comes back as a bare object, several as a list.
            $data = timb_ws_normalize_rows($data);
            $ok   = true;
        } elseif (($multi = timb_ws_decode_concatenated($raw)) !== null) {
            // Several JSON objects run together instead of a JSON array.
            $data = $multi;
            $ok   = true;
        } else {
            $err = 'TIMB response was not valid JSON: ' . substr(strip_tags($raw), 0, 180);
            $data = null;
        }
    }

    $res = ['ok'=>$ok, 'http'=>$http, 'data'=>$data, 'raw'=>$raw, 'error'=>$err,
            'simulated'=>false, 'duration_ms'=>$duration, 'attempts'=>$attempt];
    $res['log_id'] = timb_ws_log($conn, $spid, $uid, $service, $full, $params, $res);
    return $res;
}

/**
 * Split a payload into its top-level JSON values.
 *
 * Quote- and escape-aware on purpose: TIMB field values carry free text
 * (farm names, addresses), so splitting on a literal "}{" would corrupt any
 * record whose text happens to contain those characters.
 *
 * Returns [] if the payload is not cleanly balanced, so the caller can fall
 * back to reporting the original parse error.
 */
function timb_ws_split_json_values($raw) {
    $out = []; $depth = 0; $start = null; $in_str = false; $esc = false;
    $len = strlen($raw);

    for ($i = 0; $i < $len; $i++) {
        $c = $raw[$i];

        if ($in_str) {
            if ($esc)             { $esc = false; }
            elseif ($c === '\\')  { $esc = true; }
            elseif ($c === '"')   { $in_str = false; }
            continue;
        }

        if ($c === '"') { $in_str = true; continue; }

        if ($c === '{' || $c === '[') {
            if ($depth === 0) $start = $i;
            $depth++;
        } elseif ($c === '}' || $c === ']') {
            $depth--;
            if ($depth < 0) return [];
            if ($depth === 0 && $start !== null) {
                $out[] = substr($raw, $start, $i - $start + 1);
                $start = null;
            }
        }
    }

    return ($depth === 0 && !$in_str) ? $out : [];
}

/**
 * TIMB's growermaster endpoint answers a multi-grower query with several JSON
 * objects butted straight together -- {...}{...}{...} -- rather than a JSON
 * array, which json_decode() rejects outright. Decode those into the list of
 * rows the rest of this file expects.
 *
 * Returns null when the payload is not that shape, leaving the caller's
 * original "not valid JSON" error intact.
 */
function timb_ws_decode_concatenated($raw) {
    $chunks = timb_ws_split_json_values($raw);
    if (count($chunks) < 2) return null;

    $rows = [];
    foreach ($chunks as $chunk) {
        $row = json_decode($chunk, true);
        if (json_last_error() !== JSON_ERROR_NONE) return null;
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Normalise a decoded payload to a LIST of records.
 *
 * TIMB answer a query matching one record with a bare JSON object, and a query
 * matching several with a stream of them. Everything downstream either iterates
 * rows or reads $data[0], so the single-record shape silently produced nothing:
 * the field values are scalars, every `is_array($row)` guard skipped them, and
 * the service log recorded the record's FIELD COUNT as "rows returned" -- a
 * lookup that looked successful in the log but rendered an empty page.
 *
 * A list is passed through untouched; an associative array is wrapped.
 */
function timb_ws_normalize_rows($data) {
    if (!is_array($data) || $data === []) return $data;

    $i = 0;
    foreach ($data as $k => $_) {
        if ($k !== $i++) return [$data];   // associative keys => a single record
    }
    return $data;                          // already a list of records
}

/**
 * Turn a cURL failure into something a floor administrator can act on.
 * "Failed to connect ... Timeout was reached" tells nobody what to do next.
 */
function timb_ws_friendly_error($errno, $err, $url, $timeout, $attempts) {
    $host = parse_url($url, PHP_URL_HOST) ?: 'the TIMB server';
    $port = parse_url($url, PHP_URL_PORT);
    $where = $host . ($port ? ':' . $port : '');

    switch ($errno) {
        case 6:  // CURLE_COULDNT_RESOLVE_HOST
            return "Cannot resolve $host. Check the base URL and this server's DNS settings.";

        case 7:  // CURLE_COULDNT_CONNECT
        case 28: // CURLE_OPERATION_TIMEDOUT
            return "Could not reach TIMB at $where — no response after $attempts attempt(s). "
                 . "The address resolves, so the request is not reaching the gateway. "
                 . "TIMB only accepts connections from floor servers whose public IP they have registered; "
                 . "confirm this server's IP is whitelisted with TIMB, and that outbound traffic to $where is not blocked. "
                 . "Run Diagnostics below to see exactly which step fails.";

        case 35: // CURLE_SSL_CONNECT_ERROR
        case 60: // CURLE_PEER_FAILED_VERIFICATION
            return "TLS handshake with $where failed: $err. "
                 . "TIMB send an incomplete certificate chain (they omit their intermediate), which "
                 . "includes/certs/timb-ca.pem is there to complete — check that file is present and readable. "
                 . "If TIMB have since changed certificate authority it will need rebuilding from their new chain. "
                 . "Do not switch SSL verification off to work around this: it leaves grower and price data "
                 . "open to interception on the wire.";
    }
    return "Connection error talking to $where: $err";
}

/** Persist the full request/response of one call. Credentials are never logged. */
function timb_ws_log($conn, $spid, $uid, $service, $url, $params, $res) {
    $svc  = $conn->real_escape_string($service);
    $u    = $conn->real_escape_string(substr($url, 0, 255));
    $pj   = $conn->real_escape_string(substr(json_encode($params, JSON_UNESCAPED_SLASHES), 0, TIMB_WS_LOG_CAP));
    $body = (string)(isset($res['raw']) ? $res['raw'] : '');
    if (strlen($body) > TIMB_WS_LOG_CAP) {
        $body = substr($body, 0, TIMB_WS_LOG_CAP) . "\n… [truncated, " . strlen($res['raw']) . " bytes total]";
    }
    $b    = $conn->real_escape_string($body);
    $e    = $conn->real_escape_string(substr((string)(isset($res['error']) ? $res['error'] : ''), 0, 255));
    $rows = (isset($res['data']) && is_array($res['data'])) ? count($res['data']) : 0;

    $conn->query("INSERT INTO timb_service_log
        (selling_pointid,service,url,request_params,http_code,duration_ms,attempts,ok,simulated,rows_returned,error_message,response_body,userid)
        VALUES (" . (int)$spid . ",'$svc','$u','$pj'," . (int)(isset($res['http'])?$res['http']:0) . "," . (int)(isset($res['duration_ms'])?$res['duration_ms']:0) . ","
        . (int)(isset($res['attempts'])?$res['attempts']:1) . "," . (!empty($res['ok']) ? 1 : 0) . "," . (!empty($res['simulated']) ? 1 : 0) . ","
        . (int)$rows . ",'$e','$b'," . (int)$uid . ")");
    return (int)$conn->insert_id;
}

/* ── Diagnostics ───────────────────────────────────────────────────────── */

/**
 * Walk the connection step by step so a failure can be attributed: DNS, then
 * raw TCP to each candidate port, then a real HTTPS request. Each step returns
 * ok/label/detail for display.
 *
 * The TIMB documents give the server as "https://www.timb.co.zw:80", so port
 * 80 is probed alongside 443 rather than assumed to be a typo.
 */
function timb_ws_diagnose($conn) {
    $base = timb_ws_base_url($conn);
    $host = parse_url($base, PHP_URL_HOST) ?: $base;
    $port = parse_url($base, PHP_URL_PORT);
    $steps = [];

    // 1. Outbound internet at all — separates "our link is down" from "TIMB is unreachable".
    $t0 = microtime(true);
    $probe = @fsockopen('1.1.1.1', 443, $e1, $e2, 5);
    $steps[] = [
        'label'  => 'Outbound internet',
        'ok'     => (bool)$probe,
        'detail' => $probe ? 'Reached 1.1.1.1:443 in ' . round((microtime(true)-$t0)*1000) . 'ms'
                           : 'Could not open any outbound connection — this server has no working internet route.',
    ];
    if ($probe) fclose($probe);

    // 2. DNS.
    $ips = @gethostbynamel($host);
    $steps[] = [
        'label'  => "DNS for $host",
        'ok'     => !empty($ips),
        'detail' => $ips ? 'Resolves to ' . implode(', ', array_slice($ips, 0, 4))
                         : "Name does not resolve. Check the base URL and this server's DNS.",
    ];

    // 3. Raw TCP to each candidate port.
    $ports = $port ? [$port] : [443, 80];
    $any_tcp = false;
    foreach ($ports as $p) {
        $t0 = microtime(true);
        $sock = @fsockopen(($p == 443 ? 'ssl://' : '') . $host, $p, $eno, $estr, 8);
        $ms = round((microtime(true) - $t0) * 1000);
        if ($sock) { $any_tcp = true; fclose($sock); }
        $steps[] = [
            'label'  => "TCP connect $host:$p",
            'ok'     => (bool)$sock,
            'detail' => $sock ? "Connected in {$ms}ms"
                              : "No response after {$ms}ms. Either TIMB has not whitelisted this server's "
                                . 'public IP, or a firewall blocks the port.'
                                . ($estr ? ' [' . substr(preg_replace('/\s+/', ' ', $estr), 0, 90) . ']' : ''),
        ];
    }

    // 4. A real request, only worth trying if something answered.
    if ($any_tcp) {
        $cred = timb_ws_credentials($conn, 'booking');
        $steps[] = [
            'label'  => 'Credentials',
            'ok'     => ($cred['username'] !== '' && $cred['password'] !== ''),
            'detail' => $cred['username'] !== ''
                        ? "Booking & Registration user '{$cred['username']}' (from {$cred['source']})"
                        : 'No Booking & Registration credentials set — calls run in simulation mode.',
        ];
    } else {
        $steps[] = [
            'label'  => 'HTTPS request',
            'ok'     => false,
            'detail' => 'Skipped — nothing answered on the TCP ports above, so no request can succeed yet.',
        ];
    }

    return $steps;
}

/* ── Mock mode ─────────────────────────────────────────────────────────── */

/** Sample rows lifted from the TIMB grower master documentation. */
function timb_ws_mock_growers() {
    return json_decode('[{"grower_no":"V123456","surname":"JASI","name":"BEN","initials":null,"postal_address1":null,"postal_address2":null,"type":"G","farm_name":"ZANA VILL 3 PLOT 251","district":"4","area":"HWEDZA","class":"R","current_reg":null,"next_reg":null,"nationalid_id":"80-022879P80","email":null,"association":"SELF","barn_type":"CON","amend_type":"C","contractor":null,"hectares":"6","province":"MASHONALAND EAST","landholding":"A1R","obligations_fulfilled":"NO"},{"grower_no":"V302201","surname":"BEATON","name":"LOVEMORE","initials":null,"postal_address1":null,"postal_address2":null,"type":"G","farm_name":"CHIGUDU PLOT 44","district":"1","area":"MVURWI","class":"R","current_reg":"2018","next_reg":null,"nationalid_id":"45-124706S45","email":null,"association":"SELF","barn_type":"CON","amend_type":"N","contractor":"CT","hectares":"6","province":"MASHONALAND CENTRAL","landholding":"A1R","obligations_fulfilled":"YES"},{"grower_no":"V221559","surname":"ZVOGODIYI","name":"EUKERIA","initials":null,"postal_address1":null,"postal_address2":null,"type":"G","farm_name":"HAKAS","district":"8","area":"SEKE","class":"R","current_reg":null,"next_reg":null,"nationalid_id":"24-162141W24","email":null,"association":"SELF","barn_type":"CON","amend_type":"C","contractor":null,"hectares":"102","province":"MASHONALAND EAST","landholding":"A1R","obligations_fulfilled":"YES"}]', true);
}

function timb_ws_mock($service, $params, $conn = null) {
    usleep(300000); // stand in for network latency so the UI behaves realistically
    $base = ['ok'=>true,'http'=>200,'error'=>'','simulated'=>true,'duration_ms'=>300,'attempts'=>1];
    $fixtures = timb_ws_mock_growers();

    if ($service === 'growermaster') {
        $wanted = array_filter(array_map('trim', explode(',', (string)reset($params))));
        $out = [];
        $i = 0;
        foreach ($wanted as $gn) {
            $gn  = strtoupper($gn);
            $hit = null;
            foreach ($fixtures as $f) if (strcasecmp($f['grower_no'], $gn) === 0) { $hit = $f; break; }
            if (!$hit) {
                // Echo an unknown number back as a synthetic record so the
                // import path can be exercised without live credentials.
                $hit = $fixtures[$i % count($fixtures)];
                $hit['grower_no'] = $gn;
            }
            $out[] = $hit;
            $i++;
        }
        return $base + ['data'=>$out, 'raw'=>json_encode($out, JSON_UNESCAPED_SLASHES)];
    }

    if ($service === 'growermaster_all') {
        return $base + ['data'=>$fixtures, 'raw'=>json_encode($fixtures, JSON_UNESCAPED_SLASHES)];
    }

    if ($service === 'booking') {
        $grower = strtoupper(trim((string)(isset($params['grower']) ? $params['grower'] : '')));
        // Exercise the documented error convention: the message arrives in
        // bookingID with every other field blank.
        if (substr($grower, -1) === '9') {
            $out = [['bookingID'=>'Grower is not booked to sell on your floor.','bookingDate'=>'','grower'=>'',
                     'balesBooked'=>'','sellDate'=>'','bookedBy'=>'','floor'=>'','reoffer'=>'']];
        } else {
            $out = [['bookingID'    => (string)(1552599548 + (crc32($grower) % 90000)),
                     'bookingDate'  => strtoupper(date('d-M-y', strtotime('-2 days'))),
                     'grower'       => $grower,
                     'balesBooked'  => (string)(5 + (crc32($grower) % 120)),
                     'sellDate'     => (string)(isset($params['saleDate']) ? $params['saleDate'] : ''),
                     'bookedBy'     => 'TESTUSER',
                     'floor'        => (string)(isset($params['floor']) ? $params['floor'] : ''),
                     'reoffer'      => 'N']];
        }
        return $base + ['data'=>$out, 'raw'=>json_encode($out, JSON_UNESCAPED_SLASHES)];
    }

    if ($service === 'booking_insert' || $service === 'booking_update' || $service === 'booking_delete') {
        $verb = ['booking_insert'=>'insert','booking_update'=>'update','booking_delete'=>'delete'][$service];
        $bales = (int)(isset($params['balesBooked']) ? $params['balesBooked'] : 0);
        // A zero-bale booking is the reachable failure path.
        $ok = ($verb === 'delete') || $bales > 0;
        $out = [[$verb.'BookingResult'  => $ok ? 'success' : 'failed',
                 $verb.'BookingMessage' => $ok ? "booking successfully {$verb}" . ($verb === 'delete' ? 'd' : ($verb === 'update' ? 'd' : 'ed'))
                                              : 'no booking date is open for the specified date']];
        return $base + ['data'=>$out, 'raw'=>json_encode($out, JSON_UNESCAPED_SLASHES)];
    }

    if ($service === 'newgrade') {
        // TIMB answers a whole batch with one confirmation. Reject anything
        // that is not a plausible grade code so the failure path is reachable.
        $grades = array_filter(array_map('trim', explode(',', (string)reset($params))));
        $bad    = false;
        foreach ($grades as $g) if (!preg_match('/^[A-Z0-9]{2,10}$/i', $g)) $bad = true;
        $out = [['confirmation' => $bad ? ' Unsuccessful' : ' Successful']];
        return $base + ['data'=>$out, 'raw'=>json_encode($out, JSON_UNESCAPED_SLASHES)];
    }

    if ($service === 'pricematrix' || $service === 'seasonalpricematrix') {
        // The documentation shows four grades, but a real matrix covers the
        // whole grade catalogue. Build one from the local TIMB grades so a
        // simulated fetch is not mistaken for a real but nearly-empty result.
        $out = [];
        if ($conn) {
            $r = $conn->query("SELECT description FROM timb_grades WHERE active=1 ORDER BY description LIMIT 400");
            if ($r && $r !== true) {
                while ($g = $r->fetch_assoc()) {
                    // Deterministic per grade, so re-fetching a date is stable.
                    $seed  = crc32($g['description'] . ($service === 'seasonalpricematrix' ? 'S' : 'D'));
                    $cents = 80 + ($seed % 420);
                    $out[] = ['grade' => $g['description'], 'price' => (string)$cents];
                }
            }
        }
        if (!$out) {
            $out = json_decode('[{"grade":"T3OKQ","price":"235"},{"grade":"L20","price":"453"},{"grade":"X50","price":"133"},{"grade":"A5L0","price":"103"}]', true);
        }
        return $base + ['data'=>$out, 'raw'=>json_encode($out, JSON_UNESCAPED_SLASHES)];
    }

    return ['ok'=>false,'http'=>0,'data'=>null,'raw'=>'','error'=>"No mock defined for '$service'.",
            'simulated'=>true,'duration_ms'=>0,'attempts'=>1];
}

/* ── Grower master ─────────────────────────────────────────────────────── */

/** Normalise a grower number the way TIMB expects it (V-prefixed, no spaces). */
function timb_ws_clean_grower_num($raw) {
    $c = strtoupper(trim((string)$raw));
    $c = preg_replace('/^ZW-?/', '', $c);
    $c = preg_replace('/[^A-Z0-9]/', '', $c);
    if ($c === '') return '';
    if (preg_match('/^\d+$/', $c)) $c = 'V' . $c;
    return $c;
}

/** Map one TIMB grower master record onto Flowork field names. */
function timb_ws_map_grower($r) {
    $v = function ($k) use ($r) {
        $x = isset($r[$k]) ? $r[$k] : null;
        return ($x === null) ? '' : trim((string)$x);
    };
    return [
        'grower_num'            => timb_ws_clean_grower_num($v('grower_no')),
        'surname'               => $v('surname'),
        'name'                  => $v('name'),
        'initials'              => $v('initials'),
        'postal_address1'       => $v('postal_address1'),
        'postal_address2'       => $v('postal_address2'),
        'grower_type'           => $v('type'),
        'farm_name'             => $v('farm_name'),
        'district'              => $v('district'),
        'area'                  => $v('area'),
        'grower_class'          => $v('class'),
        'current_reg'           => $v('current_reg'),
        'next_reg'              => $v('next_reg'),
        'national_id'           => $v('nationalid_id'),
        'email'                 => $v('email'),
        'association'           => $v('association'),
        'barn_type'             => $v('barn_type'),
        'amend_type'            => $v('amend_type'),
        'contractor'            => $v('contractor'),
        'hectares'              => (float)($v('hectares') !== '' ? $v('hectares') : 0),
        'province'              => $v('province'),
        'landholding'           => $v('landholding'),
        'obligations_fulfilled' => strtoupper($v('obligations_fulfilled')),
        'raw'                   => $r,
    ];
}

/** Query grower master for one or more grower numbers. */
function timb_ws_growermaster($conn, $grower_numbers, $opts = array()) {
    $nums = is_array($grower_numbers) ? $grower_numbers : preg_split('/[\s,;]+/', (string)$grower_numbers);
    $nums = array_values(array_unique(array_filter(array_map('timb_ws_clean_grower_num', $nums))));
    if (!$nums) {
        return ['ok'=>false,'error'=>'No valid grower numbers supplied.','growers'=>[],'log_id'=>0,
                'simulated'=>false,'http'=>0,'raw'=>'','data'=>null,'requested'=>[]];
    }

    $param = timb_ws_setting($conn, 'timb_growermaster_param', 'growerNumbers');
    $res   = timb_ws_call($conn, 'growermaster', [$param => implode(',', $nums)], $opts);

    // TIMB answer a multi-grower query by re-emitting the whole accumulated
    // result set once per grower -- ask for 3 numbers and you get 6 records
    // back as {A}{A},{B}{A},{B},{C}. Collapse on grower number, last write
    // winning, so callers get one record per grower. This is a no-op if TIMB
    // ever return a clean one-record-per-grower response.
    $by_num = [];
    if ($res['ok'] && is_array($res['data'])) {
        foreach ($res['data'] as $row) {
            if (!is_array($row)) continue;
            $g   = timb_ws_map_grower($row);
            $key = $g['grower_num'] !== '' ? $g['grower_num'] : count($by_num);
            $by_num[$key] = $g;
        }
    }
    $res['growers']   = array_values($by_num);
    $res['requested'] = $nums;
    return $res;
}

/** Pull the whole grower database for a floor code. */
function timb_ws_growermaster_all($conn, $floorcode, $opts = array()) {
    $fc = strtoupper(trim((string)$floorcode));
    if ($fc === '') {
        return ['ok'=>false,'error'=>'No floor code configured for this selling point.','growers'=>[],
                'log_id'=>0,'simulated'=>false,'http'=>0,'raw'=>'','data'=>null];
    }

    $res = timb_ws_call($conn, 'growermaster_all', ['floorcode' => $fc], $opts + ['timeout'=>180]);

    // Collapse on grower number for the same reason as timb_ws_growermaster():
    // a registry pull cannot legitimately carry the same grower twice, and it
    // guards against the duplicate records TIMB emit on the by-number service.
    $by_num = [];
    if ($res['ok'] && is_array($res['data'])) {
        foreach ($res['data'] as $row) {
            if (!is_array($row)) continue;
            $g   = timb_ws_map_grower($row);
            $key = $g['grower_num'] !== '' ? $g['grower_num'] : count($by_num);
            $by_num[$key] = $g;
        }
    }
    $res['growers'] = array_values($by_num);
    return $res;
}

/**
 * Upsert mapped grower master records into timb_grower_registry.
 * Scoped strictly to one selling point.
 *
 * @return array new, updated, unchanged, skipped
 */
function timb_ws_save_registry($conn, $spid, $growers, $source = 'growermaster') {
    $spid  = (int)$spid;
    $src   = $conn->real_escape_string($source);
    $stats = ['new'=>0, 'updated'=>0, 'unchanged'=>0, 'skipped'=>0];

    foreach ($growers as $g) {
        if (empty($g['grower_num'])) { $stats['skipped']++; continue; }
        $gn = $conn->real_escape_string($g['grower_num']);

        $existing = null;
        $r = $conn->query("SELECT * FROM timb_grower_registry WHERE selling_pointid=$spid AND grower_num='$gn' LIMIT 1");
        if ($r && $r !== true && $r->num_rows > 0) $existing = $r->fetch_assoc();

        $cols = [
            'name'=>$g['name'], 'surname'=>$g['surname'], 'national_id'=>$g['national_id'],
            'province'=>$g['province'], 'district_name'=>$g['area'], 'area'=>$g['area'],
            'farm_name'=>$g['farm_name'], 'initials'=>$g['initials'],
            'postal_address1'=>$g['postal_address1'], 'postal_address2'=>$g['postal_address2'],
            'grower_type'=>$g['grower_type'], 'district'=>$g['district'],
            'grower_class'=>$g['grower_class'], 'current_reg'=>$g['current_reg'],
            'next_reg'=>$g['next_reg'], 'email'=>$g['email'], 'association'=>$g['association'],
            'barn_type'=>$g['barn_type'], 'amend_type'=>$g['amend_type'],
            'contractor'=>$g['contractor'], 'landholding'=>$g['landholding'],
            'obligations_fulfilled'=>$g['obligations_fulfilled'],
        ];

        $changed = false;
        if ($existing) {
            foreach ($cols as $k => $val) {
                if (array_key_exists($k, $existing) && (string)$existing[$k] !== (string)$val) { $changed = true; break; }
            }
            if (!$changed && (float)(isset($existing['hectares']) ? $existing['hectares'] : 0) !== (float)$g['hectares']) $changed = true;
        }

        $set = [];
        foreach ($cols as $k => $val) $set[] = "`$k`='" . $conn->real_escape_string((string)$val) . "'";
        $set[] = "hectares=" . (float)$g['hectares'];
        $set[] = "timb_verified=1";
        $set[] = "source='$src'";
        $set[] = "raw_payload='" . $conn->real_escape_string(json_encode(isset($g['raw']) ? $g['raw'] : [], JSON_UNESCAPED_SLASHES)) . "'";
        $set[] = "last_synced=NOW()";
        $set_sql = implode(',', $set);

        if ($existing) {
            $conn->query("UPDATE timb_grower_registry SET $set_sql WHERE id=" . (int)$existing['id']);
            if ($changed) $stats['updated']++; else $stats['unchanged']++;
        } else {
            $conn->query("INSERT INTO timb_grower_registry SET selling_pointid=$spid, grower_num='$gn', $set_sql");
            $stats['new']++;
        }
    }
    return $stats;
}

/**
 * Compare one TIMB record against the local growers row for this floor.
 * @return array local (row|null), diffs [field => [local, timb]]
 */
function timb_ws_diff_local($conn, $spid, $g) {
    $spid = (int)$spid;
    $gn   = $conn->real_escape_string($g['grower_num']);
    $r = $conn->query("SELECT * FROM growers WHERE grower_num='$gn' AND selling_pointid=$spid LIMIT 1");
    $local = ($r && $r !== true && $r->num_rows > 0) ? $r->fetch_assoc() : null;
    if (!$local) return ['local'=>null, 'diffs'=>[]];

    $compare = [
        'name'        => $g['name'],
        'surname'     => $g['surname'],
        'national_id' => $g['national_id'],
        'farm_name'   => $g['farm_name'],
        'province'    => $g['province'],
        'area'        => $g['area'],
        'contractor'  => $g['contractor'],
    ];
    $diffs = [];
    foreach ($compare as $field => $timb_val) {
        $local_val = trim((string)(isset($local[$field]) ? $local[$field] : ''));
        if ($timb_val === '') continue;                       // TIMB blank never overwrites
        if (strcasecmp($local_val, (string)$timb_val) !== 0) {
            $diffs[$field] = [$local_val, (string)$timb_val];
        }
    }
    return ['local'=>$local, 'diffs'=>$diffs];
}

/**
 * Apply TIMB grower master data to the growers table for this floor.
 * Only ever fills/corrects fields TIMB actually supplied.
 *
 * @param bool $create_missing create growers that do not exist locally
 * @return array updated, created, skipped
 */
function timb_ws_apply_to_growers($conn, $spid, $growers, $create_missing = false) {
    $spid  = (int)$spid;
    $stats = ['updated'=>0, 'created'=>0, 'skipped'=>0];

    foreach ($growers as $g) {
        if (empty($g['grower_num'])) { $stats['skipped']++; continue; }
        $gn = $conn->real_escape_string($g['grower_num']);

        $r   = $conn->query("SELECT id FROM growers WHERE grower_num='$gn' AND selling_pointid=$spid LIMIT 1");
        $row = ($r && $r !== true && $r->num_rows > 0) ? $r->fetch_assoc() : null;

        $fields = [
            'name'        => $g['name'],
            'surname'     => $g['surname'],
            'national_id' => $g['national_id'],
            'farm_name'   => $g['farm_name'],
            'province'    => $g['province'],
            'district'    => $g['district'],
            'area'        => $g['area'],
            'contractor'  => $g['contractor'],
            'address1'    => $g['postal_address1'],
            'address2'    => $g['postal_address2'],
        ];

        if ($row) {
            $set = [];
            foreach ($fields as $k => $val) {
                if ($val === '') continue;                    // never blank out local data
                $set[] = "`$k`='" . $conn->real_escape_string((string)$val) . "'";
            }
            if (!$set) { $stats['skipped']++; continue; }
            $conn->query("UPDATE growers SET " . implode(',', $set) . " WHERE id=" . (int)$row['id']);
            $stats['updated']++;
        } elseif ($create_missing) {
            $set = ["grower_num='$gn'", "selling_pointid=$spid", "active=1"];
            foreach ($fields as $k => $val) {
                $set[] = "`$k`='" . $conn->real_escape_string((string)$val) . "'";
            }
            $conn->query("INSERT INTO growers SET " . implode(',', $set));
            if ($conn->affected_rows > 0) $stats['created']++; else $stats['skipped']++;
        } else {
            $stats['skipped']++;
        }
    }
    return $stats;
}

/* ── Price matrix ──────────────────────────────────────────────────────── */

/**
 * TIMB expects an Oracle-style date, e.g. 12-DEC-17. The documentation shows
 * exactly one example, so the format stays configurable.
 */
function timb_ws_timb_date($conn, $date) {
    $ts = is_numeric($date) ? (int)$date : strtotime((string)$date);
    if (!$ts) return '';
    $fmt = timb_ws_setting($conn, 'timb_matrix_date_format', 'd-M-y');
    return strtoupper(date($fmt, $ts));
}

/** Kept for callers written before bookings shared the same format. */
function timb_ws_matrix_date($conn, $date) { return timb_ws_timb_date($conn, $date); }

/**
 * Flowork stores auction prices with a cents/dollars ambiguity resolved by
 * "greater than 10 means cents" (see price_forecast.php). TIMB matrix prices
 * arrive the same way — "235" is US$2.35/kg — so apply the same rule.
 *
 * One refinement: the spec's examples are all whole numbers, so a value that
 * carries a decimal point is already expressed in dollars. Without this,
 * "10.5" would be read as 10.5 cents rather than US$10.50.
 */
function timb_ws_price_to_usd($raw) {
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$raw);
    if ($clean === '' || $clean === '-') return 0.0;
    $v = (float)$clean;
    if (strpos($clean, '.') !== false) return $v;   // decimal given = dollars
    return $v > 10 ? $v / 100 : $v;
}

/** Map the grade/price pairs TIMB returns. */
function timb_ws_map_matrix($rows) {
    $out = [];
    foreach ((array)$rows as $r) {
        if (!is_array($r)) continue;
        $grade = strtoupper(trim((string)(isset($r['grade']) ? $r['grade'] : '')));
        if ($grade === '') continue;
        $raw = trim((string)(isset($r['price']) ? $r['price'] : ''));
        $out[] = ['grade'=>$grade, 'price_raw'=>$raw, 'price_usd'=>timb_ws_price_to_usd($raw)];
    }
    return $out;
}

/** Price matrix for one sale date. */
function timb_ws_price_matrix($conn, $date, $opts = array()) {
    $md = timb_ws_matrix_date($conn, $date);
    if ($md === '') {
        return ['ok'=>false,'error'=>'Invalid matrix date.','matrix'=>[],'log_id'=>0,
                'simulated'=>false,'http'=>0,'raw'=>'','data'=>null,'matrix_date'=>''];
    }
    $res = timb_ws_call($conn, 'pricematrix', ['matrixdate' => $md], $opts);
    $res['matrix']      = $res['ok'] ? timb_ws_map_matrix($res['data']) : [];
    $res['matrix_date'] = $md;
    return $res;
}

/** Previous season's price matrix. Takes no parameters. */
function timb_ws_seasonal_price_matrix($conn, $opts = array()) {
    $res = timb_ws_call($conn, 'seasonalpricematrix', [], $opts + ['timeout'=>120]);
    $res['matrix'] = $res['ok'] ? timb_ws_map_matrix($res['data']) : [];
    return $res;
}

/**
 * Replace the stored matrix for this floor and date. A re-fetch is a full
 * refresh, so the previous rows for exactly that (floor, type, date) go first.
 *
 * @return array stored, replaced
 */
function timb_ws_save_price_matrix($conn, $spid, $matrix, $type = 'daily', $date_sql = null, $simulated = false) {
    $spid = (int)$spid;
    $type = ($type === 'seasonal') ? 'seasonal' : 'daily';
    $cond = "selling_pointid=$spid AND matrix_type='$type' AND "
          . ($date_sql ? "matrix_date='" . $conn->real_escape_string($date_sql) . "'" : "matrix_date IS NULL");

    $prev = 0;
    $r = $conn->query("SELECT COUNT(*) n FROM timb_price_matrix WHERE $cond");
    if ($r && $r !== true) $prev = (int)$r->fetch_assoc()['n'];

    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM timb_price_matrix WHERE $cond");
        $stored = 0;
        foreach ($matrix as $m) {
            $g  = $conn->real_escape_string($m['grade']);
            $pr = $conn->real_escape_string($m['price_raw']);
            $d  = $date_sql ? "'" . $conn->real_escape_string($date_sql) . "'" : 'NULL';
            if ($conn->query("INSERT INTO timb_price_matrix (selling_pointid,matrix_type,matrix_date,grade,price_raw,price_usd,simulated)
                              VALUES ($spid,'$type',$d,'$g','$pr'," . (float)$m['price_usd'] . "," . ($simulated ? 1 : 0) . ")")) $stored++;
        }
        $conn->commit();
        return ['stored'=>$stored, 'replaced'=>$prev];
    } catch (Exception $e) {
        $conn->rollback();
        return ['stored'=>0, 'replaced'=>0, 'error'=>$e->getMessage()];
    }
}

/* ── Bookings ──────────────────────────────────────────────────────────── */

/** Floor code for a selling point, as TIMB knows it. */
function timb_ws_floor_code($conn, $spid) {
    $spid = (int)$spid;
    $r = $conn->query("SELECT floor_code FROM selling_point WHERE id=$spid LIMIT 1");
    if ($r && $r !== true && $r->num_rows > 0) return strtoupper(trim((string)$r->fetch_assoc()['floor_code']));
    return '';
}

/** Local reoffer values ('0'/'1', ''/'Y') normalised to TIMB's Y/N. */
function timb_ws_reoffer($v) {
    $v = strtoupper(trim((string)$v));
    return ($v === 'Y' || $v === '1' || $v === 'YES' || $v === 'TRUE') ? 'Y' : 'N';
}

/**
 * TIMB report booking errors INSIDE a 200 response: the message is placed in
 * bookingID and every other field is blank. Without this check an error like
 * "Grower is not booked to sell on your floor." would be stored as a booking
 * reference.
 *
 * @return string the error message, or '' when the row is a real booking
 */
function timb_ws_booking_error($row) {
    if (!is_array($row)) return 'Unrecognised booking response.';
    $id     = trim((string)(isset($row['bookingID']) ? $row['bookingID'] : ''));
    $grower = trim((string)(isset($row['grower']) ? $row['grower'] : ''));

    if ($id === '' && $grower === '') return 'Empty booking response.';
    // A real bookingID is a short token; a message contains spaces.
    if ($id !== '' && (strpos($id, ' ') !== false || $grower === '')) return $id;
    return '';
}

/** Map one TIMB booking row to Flowork's vocabulary. */
function timb_ws_map_booking($row) {
    $g = function ($k) use ($row) { return trim((string)(isset($row[$k]) ? $row[$k] : '')); };
    $sell = $g('sellDate'); $book = $g('bookingDate');
    return [
        'booking_id'   => $g('bookingID'),
        'grower_num'   => timb_ws_clean_grower_num($g('grower')),
        'bales_booked' => (int)$g('balesBooked'),
        'sell_date'    => $sell ? date('Y-m-d', strtotime($sell)) : '',
        'booking_date' => $book ? date('Y-m-d', strtotime($book)) : '',
        'booked_by'    => $g('bookedBy'),
        'floor'        => strtoupper($g('floor')),
        'reoffer'      => timb_ws_reoffer($g('reoffer')),
        'sell_date_raw'=> $sell,
    ];
}

/**
 * Query one grower's booking for one sale date. TIMB serve bookings grower by
 * grower, so there is no bulk form.
 */
function timb_ws_booking_query($conn, $grower, $sale_date, $floor_code, $opts = array()) {
    $gn = timb_ws_clean_grower_num($grower);
    $fc = strtoupper(trim((string)$floor_code));
    $sd = timb_ws_timb_date($conn, $sale_date);

    if ($gn === '' || $fc === '' || $sd === '') {
        return ['ok'=>false, 'booking'=>null, 'log_id'=>0, 'simulated'=>false, 'http'=>0, 'raw'=>'', 'data'=>null,
                'error'=>$gn === '' ? 'Enter a grower number.'
                       : ($fc === '' ? 'No floor code is set for this selling point.' : 'Enter a valid sale date.')];
    }

    $res = timb_ws_call($conn, 'booking', ['grower'=>$gn, 'saleDate'=>$sd, 'floor'=>$fc], $opts);
    $res['booking'] = null;

    if ($res['ok']) {
        $row = (is_array($res['data']) && isset($res['data'][0])) ? $res['data'][0] : null;
        $msg = timb_ws_booking_error($row);
        if ($msg !== '') {
            // A 200 carrying an error is still a failure.
            $res['ok']    = false;
            $res['error'] = $msg;
        } else {
            $res['booking'] = timb_ws_map_booking($row);
        }
    }
    return $res;
}

/**
 * Insert, update or delete a booking at TIMB. All three take the same
 * parameters and answer with <verb>BookingResult / <verb>BookingMessage.
 *
 * These change state on TIMB's system — callers must confirm with the user
 * before invoking, particularly 'delete'.
 */
function timb_ws_booking_write($conn, $op, $booking, $opts = array()) {
    $map = ['insert'=>'booking_insert', 'update'=>'booking_update', 'delete'=>'booking_delete'];
    if (!isset($map[$op])) {
        return ['ok'=>false, 'error'=>"Unknown booking operation '$op'.", 'result'=>'', 'message'=>'', 'log_id'=>0, 'simulated'=>false];
    }

    $gn = timb_ws_clean_grower_num(isset($booking['grower_num']) ? $booking['grower_num'] : '');
    $fc = strtoupper(trim((string)(isset($booking['floor']) ? $booking['floor'] : '')));
    $sd = timb_ws_timb_date($conn, isset($booking['sell_date']) ? $booking['sell_date'] : '');
    $bd = timb_ws_timb_date($conn, isset($booking['booking_date']) ? $booking['booking_date'] : date('Y-m-d'));

    if ($gn === '' || $fc === '' || $sd === '') {
        return ['ok'=>false, 'result'=>'', 'message'=>'', 'log_id'=>0, 'simulated'=>false,
                'error'=>'Grower number, floor code and sale date are all required.'];
    }

    $params = [
        'grower'      => $gn,
        'saleDate'    => $sd,
        'floor'       => $fc,
        'bookingDate' => $bd,
        'balesBooked' => (string)(int)(isset($booking['bales_booked']) ? $booking['bales_booked'] : 0),
        'bookedBy'    => substr(trim((string)(isset($booking['booked_by']) ? $booking['booked_by'] : '')), 0, 40),
        'reoffer'     => timb_ws_reoffer(isset($booking['reoffer']) ? $booking['reoffer'] : 'N'),
    ];

    $res = timb_ws_call($conn, $map[$op], $params, $opts);
    $res['result'] = $res['message'] = '';

    if ($res['ok']) {
        $row = (is_array($res['data']) && isset($res['data'][0])) ? $res['data'][0] : [];
        $res['result']  = trim((string)(isset($row[$op.'BookingResult'])  ? $row[$op.'BookingResult']  : ''));
        $res['message'] = trim((string)(isset($row[$op.'BookingMessage']) ? $row[$op.'BookingMessage'] : ''));
        if (strcasecmp($res['result'], 'success') !== 0) {
            $res['ok']    = false;
            $res['error'] = $res['message'] !== '' ? $res['message'] : "TIMB reported '{$res['result']}'.";
        }
    }
    return $res;
}

/** Record a booking operation and its outcome. */
function timb_ws_log_booking_op($conn, $spid, $uid, $op, $booking, $res, $bb_id = 0) {
    $e = function ($v) use ($conn) { return $conn->real_escape_string((string)$v); };
    $sd = !empty($booking['sell_date'])    ? "'" . $e($booking['sell_date']) . "'"    : 'NULL';
    $bd = !empty($booking['booking_date']) ? "'" . $e($booking['booking_date']) . "'" : 'NULL';

    $conn->query("INSERT INTO timb_booking_ops
        (selling_pointid,operation,grower_num,booked_bales_id,sale_date,booking_date,bales_booked,reoffer,
         floor_code,booking_id,result,message,ok,simulated,log_id,userid)
        VALUES (" . (int)$spid . ",'" . $e($op) . "','" . $e(isset($booking['grower_num']) ? $booking['grower_num'] : '') . "',"
        . (int)$bb_id . ",$sd,$bd," . (int)(isset($booking['bales_booked']) ? $booking['bales_booked'] : 0) . ",'"
        . $e(timb_ws_reoffer(isset($booking['reoffer']) ? $booking['reoffer'] : 'N')) . "','"
        . $e(isset($booking['floor']) ? $booking['floor'] : '') . "','"
        . $e(isset($res['booking']['booking_id']) ? $res['booking']['booking_id'] : (isset($booking['booking_id']) ? $booking['booking_id'] : '')) . "','"
        . $e(isset($res['result']) ? $res['result'] : '') . "','"
        . $e(substr(isset($res['error']) && $res['error'] !== '' ? $res['error'] : (isset($res['message']) ? $res['message'] : ''), 0, 255)) . "',"
        . (!empty($res['ok']) ? 1 : 0) . "," . (!empty($res['simulated']) ? 1 : 0) . ","
        . (int)(isset($res['log_id']) ? $res['log_id'] : 0) . "," . (int)$uid . ")");
    return $conn->insert_id;
}

/**
 * Write a booking TIMB returned into booked_bales, matching on grower and
 * sale date within this selling point. Existing rows keep their identity and
 * gain TIMB's real booking reference.
 *
 * @return array action ('created'|'updated'|'unchanged'|'no_grower'), id
 */
function timb_ws_apply_booking($conn, $spid, $b, $userid = 0) {
    $spid = (int)$spid;
    $gnum = $conn->real_escape_string($b['grower_num']);

    $gr = $conn->query("SELECT id FROM growers WHERE grower_num='$gnum' AND selling_pointid=$spid LIMIT 1");
    if (!$gr || $gr === true || $gr->num_rows === 0) return ['action'=>'no_grower', 'id'=>0];
    $gid = (int)$gr->fetch_assoc()['id'];

    $sell = $conn->real_escape_string($b['sell_date']);
    $book = $conn->real_escape_string($b['booking_date'] !== '' ? $b['booking_date'] : $b['sell_date']);
    $bid  = $conn->real_escape_string($b['booking_id']);
    $bales= (int)$b['bales_booked'];
    $ro   = $conn->real_escape_string($b['reoffer']);
    $by   = $conn->real_escape_string($b['booked_by']);
    $fl   = $conn->real_escape_string($b['floor']);

    $ex = $conn->query("SELECT id, bales_booked, booked_id FROM booked_bales
                        WHERE growerid=$gid AND sell_date='$sell' LIMIT 1");
    if ($ex && $ex !== true && $ex->num_rows > 0) {
        $row = $ex->fetch_assoc();
        if ((int)$row['bales_booked'] === $bales && trim((string)$row['booked_id']) === $b['booking_id']) {
            return ['action'=>'unchanged', 'id'=>(int)$row['id']];
        }
        $conn->query("UPDATE booked_bales SET bales_booked='$bales', booked_id='$bid', reoffer='$ro',
                      booked_by='$by', floor='$fl', book_date='$book', datetime=NOW()
                      WHERE id=" . (int)$row['id']);
        return ['action'=>'updated', 'id'=>(int)$row['id']];
    }

    $conn->query("INSERT INTO booked_bales
        (userid,growerid,book_date,bales_booked,sell_date,book_user,floor,booked_id,reoffer,booked_by,datetime)
        VALUES (" . (int)$userid . ",$gid,'$book','$bales','$sell','" . (int)$userid . "','$fl','$bid','$ro','$by',NOW())");
    return ['action'=>'created', 'id'=>(int)$conn->insert_id];
}

/* ── New grade submission ──────────────────────────────────────────────── */

/** TIMB pads its confirmation with a leading space — compare on the trimmed value. */
function timb_ws_grade_confirmation($data) {
    $first = (is_array($data) && isset($data[0]) && is_array($data[0])) ? $data[0] : [];
    $text  = trim((string)(isset($first['confirmation']) ? $first['confirmation'] : ''));
    return ['text'=>$text, 'accepted'=>(strcasecmp($text, 'Successful') === 0)];
}

/**
 * Submit new grades to TIMB.
 *
 * TIMB confirms a batch with ONE message, so a multi-grade request that comes
 * back "Unsuccessful" does not say which grade was rejected. Submitting one at
 * a time ($individually) costs one request per grade but attributes the answer
 * precisely; that is the default.
 *
 * @return array results (per grade), accepted, rejected, batch_ref
 */
function timb_ws_submit_grades($conn, $grades, $individually = true, $opts = array()) {
    $list = is_array($grades) ? $grades : preg_split('/[\s,;]+/', (string)$grades);
    $list = array_values(array_unique(array_filter(array_map(function ($g) {
        return strtoupper(trim((string)$g));
    }, $list))));

    // Grade codes are alphanumeric. Reject anything else here rather than
    // spending a request on it — TIMB would refuse it anyway, and a batched
    // refusal would taint the valid grades sent alongside it.
    $invalid = [];
    $list = array_values(array_filter($list, function ($g) use (&$invalid) {
        if (preg_match('/^[A-Z0-9]{1,20}$/', $g)) return true;
        $invalid[] = $g;
        return false;
    }));

    if (!$list) {
        return ['ok'=>false,
                'error'=>$invalid ? 'No valid grade codes supplied (grades are alphanumeric): ' . implode(', ', $invalid)
                                  : 'No grades supplied.',
                'results'=>[], 'accepted'=>0, 'rejected'=>0, 'batch_ref'=>'', 'invalid'=>$invalid];
    }

    $spid  = (int)(isset($opts['spid']) ? $opts['spid'] : ($_SESSION['selling_pointid'] ?? 0));
    $uid   = (int)(isset($opts['userid']) ? $opts['userid'] : (function_exists('current_userid') ? current_userid() : 0));
    $ref   = 'GRD-' . date('YmdHis') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 4));
    $batches = $individually ? array_chunk($list, 1) : [$list];

    $results = []; $accepted = 0; $rejected = 0; $any_error = '';

    foreach ($batches as $batch) {
        $res  = timb_ws_call($conn, 'newgrade', ['newgrade' => implode(',', $batch)], $opts);
        $conf = $res['ok'] ? timb_ws_grade_confirmation($res['data'])
                           : ['text'=>'', 'accepted'=>false];
        if (!$res['ok'] && $any_error === '') $any_error = $res['error'];

        foreach ($batch as $g) {
            $ok_g = ($res['ok'] && $conf['accepted']);
            $results[] = [
                'grade'        => $g,
                'accepted'     => $ok_g,
                'confirmation' => $res['ok'] ? ($conf['text'] !== '' ? $conf['text'] : '(empty confirmation)') : $res['error'],
                'simulated'    => !empty($res['simulated']),
                'log_id'       => (int)$res['log_id'],
                'batch_size'   => count($batch),
            ];
            if ($ok_g) $accepted++; else $rejected++;

            $conn->query("INSERT INTO timb_grade_submissions
                (selling_pointid,grade,batch_ref,batch_size,confirmation,accepted,simulated,log_id,userid)
                VALUES ($spid,'" . $conn->real_escape_string($g) . "','" . $conn->real_escape_string($ref) . "'," . count($batch) . ",'"
                . $conn->real_escape_string(substr($results[count($results)-1]['confirmation'], 0, 60)) . "'," . ($ok_g ? 1 : 0) . ","
                . (!empty($res['simulated']) ? 1 : 0) . "," . (int)$res['log_id'] . "," . (int)$uid . ")");
        }
    }

    return ['ok'=>($rejected === 0 && !$invalid), 'error'=>$any_error, 'results'=>$results,
            'accepted'=>$accepted, 'rejected'=>$rejected, 'batch_ref'=>$ref, 'invalid'=>$invalid];
}

/**
 * Pull the TIMB grade list into the local catalogue.
 *
 * TIMB publish no grade-download service. What they do publish is the price
 * matrix, whose every row is a grade plus its price — so the matrix IS the
 * authoritative grade list, and the seasonal one covers a whole season. This
 * reads a matrix already stored by timb_pricematrix.php and adds any grade the
 * local catalogue is missing.
 *
 * @param string $type      'seasonal' (whole season) or 'daily'
 * @param string $date_sql  required for 'daily'
 * @return array seen, added, existing, grades (the newly added codes)
 */
function timb_ws_import_grades_from_matrix($conn, $spid, $type = 'seasonal', $date_sql = null) {
    $spid = (int)$spid;
    $type = ($type === 'daily') ? 'daily' : 'seasonal';
    $cond = "selling_pointid=$spid AND matrix_type='$type' AND "
          . ($type === 'daily' && $date_sql ? "matrix_date='" . $conn->real_escape_string($date_sql) . "'" : "matrix_date IS NULL");

    $r = $conn->query("SELECT DISTINCT grade FROM timb_price_matrix WHERE $cond ORDER BY grade");
    $stats = ['seen'=>0, 'added'=>0, 'existing'=>0, 'grades'=>[]];
    if (!$r || $r === true) return $stats;

    while ($row = $r->fetch_assoc()) {
        $stats['seen']++;
        if (timb_ws_ensure_local_grade($conn, $row['grade'])) {
            $stats['added']++;
            $stats['grades'][] = $row['grade'];
        } else {
            $stats['existing']++;
        }
    }
    return $stats;
}

/**
 * Fetch a grade list from a directly configured endpoint, if TIMB have given
 * one. Accepts either [{"grade":"X"}] or a bare ["X","Y"] response.
 */
function timb_ws_fetch_grades($conn, $params = array(), $opts = array()) {
    $res = timb_ws_call($conn, 'grades_fetch', $params, $opts);
    $res['grades'] = [];
    if ($res['ok'] && is_array($res['data'])) {
        foreach ($res['data'] as $row) {
            $g = '';
            if (is_string($row)) $g = $row;
            elseif (is_array($row)) $g = (string)($row['grade'] ?? $row['description'] ?? $row['code'] ?? '');
            $g = strtoupper(trim($g));
            if ($g !== '' && preg_match('/^[A-Z0-9]{1,20}$/', $g)) $res['grades'][] = $g;
        }
        $res['grades'] = array_values(array_unique($res['grades']));
    }
    return $res;
}

/** Add an accepted grade to the local TIMB grade catalogue if it is not there. */
function timb_ws_ensure_local_grade($conn, $grade) {
    $g = $conn->real_escape_string(strtoupper(trim($grade)));
    if ($g === '') return false;
    $r = $conn->query("SELECT id FROM timb_grades WHERE description='$g' LIMIT 1");
    if ($r && $r !== true && $r->num_rows > 0) return false;
    return (bool)$conn->query("INSERT INTO timb_grades (description, active) VALUES ('$g', 1)");
}

} // TIMB_WS_LOADED
