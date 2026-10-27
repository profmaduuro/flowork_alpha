<?php
require_once __DIR__ . '/../../includes/password_helper.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// Session tokens are managed in api_tokens. Table setup has been moved to setup_database.php.

// ── LOGIN ─────────────────────────────────────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    require_once __DIR__ . '/../../includes/rate_limiter.php';
    if (!check_rate_limit($db, 'api_login', 10, 300)) {
        err('Too many login attempts. Please try again in 5 minutes.', 429);
    }
    $b = body();
    need($b, ['username','password']);
    if (!check_rate_limit($db, 'api_login_user', 20, 900, 'user:' . strtolower(substr(trim($b['username']), 0, 40)))) {
        err('Too many login attempts. Please try again later.', 429);
    }
    $u = $db->real_escape_string(trim($b['username']));
    $r = $db->query("SELECT id,username,full_name,is_admin,is_master,role,selling_pointid,password
                     FROM users WHERE username='$u' AND active=1 LIMIT 1");
    if (!$r || !$r->num_rows) err('Invalid username or password', 401);
    $user = $r->fetch_assoc();
    if (!flowork_password_verify($db, $b['password'], $user)) err('Invalid username or password', 401);

    // Same 2FA rule as the web login. Without this a password alone returned a
    // 30-day token for accounts that have 2FA switched on.
    require_once __DIR__ . '/../../includes/totp.php';
    if (flowork_2fa_enabled($db, (int)$user['id'])) {
        $otp = trim((string)($b['otp'] ?? ''));
        if ($otp === '') err('Two-factor code required', 401);
        if (!check_rate_limit($db, '2fa_verify', 10, 900, 'user:' . (int)$user['id'])) {
            err('Too many incorrect codes. Please try again later.', 429);
        }
        if (!flowork_2fa_check($db, (int)$user['id'], $otp)) err('Invalid two-factor code', 401);
    }

    $token  = bin2hex(random_bytes(32));
    $uid    = (int)$user['id'];
    $device = $db->real_escape_string($b['device'] ?? 'android');
    $expire = date('Y-m-d H:i:s', strtotime('+30 days'));
    $db->query("DELETE FROM api_tokens WHERE user_id=$uid AND device='$device'");
    $db->query("INSERT INTO api_tokens (user_id,token,device,expires_at) VALUES ($uid,'$token','$device','$expire')");
    if ($db->error) err('Token creation failed: ' . $db->error);

    // Get floors. This list must match exactly what getSpid() will later allow
    // through X-Selling-Point — otherwise the app offers a floor the API then
    // refuses. Previously any is_admin user was handed every floor, which is
    // wider than the web rule (master, or not tied to a floor).
    $allowed = allowedSpids($user);
    $in      = implode(',', array_map('intval', $allowed)) ?: '0';
    $sp_q    = $db->query("SELECT id,name,floor_code FROM selling_point
                           WHERE id IN ($in) AND active=1 ORDER BY name");
    $floors = ($sp_q && $sp_q !== true) ? $sp_q->fetch_all(MYSQLI_ASSOC) : [];
    unset($user['password']);
    ok(['token'=>$token,'user'=>$user,'floors'=>$floors]);
}

// ── ME — returns current sell date + rate ─────────────────────────────────────
if ($action === 'me') {
    $user = requireAuth();
    $spid = getSpid($user);
    $seasonid = getSeasonid($user);

    // Active season
    $s = $db->query("SELECT id,description FROM seasons WHERE active=1 LIMIT 1");
    $season = ($s && $s !== true && $s->num_rows) ? $s->fetch_assoc() : ['id'=>1,'description'=>''];

    // Current sell date — this floor's active start_of_day (Day Control), then selling_days, then today
    require_once __DIR__ . '/sell_day.php';
    $sell_date = apiSellDate($db, $spid);

    // Current exchange rate
    $er_q = $db->query("SELECT amount FROM exchange_rate
        WHERE seasonid=$seasonid ORDER BY id DESC LIMIT 1");
    $rate = ($er_q && $er_q !== true && $er_q->num_rows > 0)
        ? (float)$er_q->fetch_assoc()['amount'] : 0;

    ok(['user'=>$user,'season'=>$season,'selling_date'=>$sell_date,'exchange_rate'=>$rate]);
}

// ── LOGOUT ────────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        $tok = $db->real_escape_string(trim($m[1]));
        $db->query("DELETE FROM api_tokens WHERE token='$tok'");
    }
    ok(null, 'Logged out');
}

err('Unknown action');
