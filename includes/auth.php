<?php
require_once __DIR__ . '/password_helper.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/rights.php';
require_once __DIR__ . '/floor_scope.php';

function require_login() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['userid'])) {
        header("Location: login.php");
        exit;
    }

    // Every state-changing request from a signed-in user must carry the CSRF
    // token, as a csrf_token field or an X-CSRF-Token header. csrf_js() in the
    // page <head> adds it to forms and fetch() calls automatically, so pages
    // no longer have to remember to check it themselves.
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!verify_csrf_token($sent)) {
            http_response_code(403);
            $wants_json = isset($_SERVER['HTTP_X_CSRF_TOKEN'])
                || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
                || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
                || isset($_GET['ajax']);
            if ($wants_json) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'success' => false,
                    'msg' => 'Your session has changed. Reload the page and try again.',
                    'message' => 'Your session has changed. Reload the page and try again.']);
            } else {
                header('Content-Type: text/html; charset=utf-8');
                echo '<p style="font-family:sans-serif;padding:40px;text-align:center">'
                   . 'This form was opened in an older session. '
                   . '<a href="javascript:history.back()">Go back</a>, reload the page and submit again.</p>';
            }
            exit;
        }
    }

    // If dedicated floor user, strictly lock selling_pointid to their assigned user_spid
    if (is_dedicated_user()) {
        $dedicated_spid = (int)$_SESSION['user_spid'];
        $_SESSION['selling_pointid'] = $dedicated_spid;
        
        $floors = $_SESSION['accessible_floors'] ?? [];
        foreach ($floors as $f) {
            if ((int)$f['id'] === $dedicated_spid) {
                $_SESSION['selling_point_name'] = $f['name'];
                break;
            }
        }
    }

    // Enforce that an active floor is selected (except on login/logout or for Master Users on user/floor management)
    if (empty($_SESSION['selling_pointid'])) {
        $current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $master_prefloor_allowed = ['login.php', 'logout.php', 'users.php', 'multi_floor.php'];
        if (!is_master_user() || !in_array($current_script, $master_prefloor_allowed)) {
            if ($current_script !== 'login.php' && $current_script !== 'logout.php') {
                header("Location: login.php");
                exit;
            }
        }
    }
    
    // Enforce 2FA verification dynamically on all requests if enabled for this user
    if (empty($_SESSION['2fa_verified'])) {
        global $conn;
        if (!isset($conn)) {
            $conn_path = dirname(__DIR__) . '/includes/conn.php';
            if (file_exists($conn_path)) {
                require_once $conn_path;
            }
        }
        if (isset($conn) && $conn instanceof mysqli) {
            $uid = (int)$_SESSION['userid'];
            $fa_q = $conn->query("SELECT enabled FROM user_2fa WHERE userid=$uid LIMIT 1");
            if ($fa_q && $fa_q->num_rows > 0) {
                $fa = $fa_q->fetch_assoc();
                if ($fa['enabled'] == 1) {
                    $_SESSION['2fa_pending_userid'] = $uid;
                    // Protect against infinite loop on two_factor.php itself
                    $current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
                    if ($current_script !== 'two_factor.php') {
                        $_SESSION['2fa_redirect'] = $_SERVER['REQUEST_URI'];
                        header("Location: two_factor.php?verify");
                        exit;
                    }
                }
            }
        }
    }

    // With REQUIRE_ADMIN_2FA=1, an administrator or master user without 2FA
    // can only reach the 2FA setup page (and log out) until they enable it.
    if (flowork_env('REQUIRE_ADMIN_2FA') === '1' && (!empty($_SESSION['is_admin']) || is_master_user())) {
        $current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!in_array($current_script, ['two_factor.php', 'logout.php', 'login.php'], true)) {
            global $conn;
            if (isset($conn) && $conn instanceof mysqli) {
                $uid = (int)$_SESSION['userid'];
                $fa_on = $conn->query("SELECT 1 FROM user_2fa WHERE userid=$uid AND enabled=1 LIMIT 1");
                if (!$fa_on || $fa_on === true || $fa_on->num_rows === 0) {
                    $_SESSION['flash_err'] = "Administrators must set up two-factor authentication before continuing.";
                    header("Location: two_factor.php");
                    exit;
                }
            }
        }
    }

    // Enforce module-level rights restriction
    $current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($current_script !== 'dashboard.php') {
        $rights_map = [
            // floor
            'floor_map.php'         => 'floor',
            'floor_queue.php'       => 'floor',
            'floor_summary.php'     => 'floor',
            'floor_tv.php'          => 'floor',
            'live_floor.php'        => 'floor',
            'live_feed.php'         => 'floor',
            'scale.php'             => 'floor',
            'scale_sync.php'        => 'floor',
            'junus.php'             => 'floor',
            'move_bales.php'        => 'floor',
            'multi_floor.php'       => 'admin',
            'switch_floor.php'      => 'floor',
            'void_controls.php'     => 'floor',

            // balance
            'balance.php'           => 'balance',
            'balance_bales_action.php'=> 'balance',
            'bale_adjustments.php'  => 'balance',
            'bale_check.php'        => 'balance',
            'bales.php'             => 'balance',
            'day_control.php'       => 'balance',
            'pay_close.php'         => 'balance',

            // accounts
            'billing.php'           => 'accounts',
            'sales.php'             => 'accounts',
            'sales_sheets.php'      => 'accounts',
            'selling.php'           => 'accounts',
            'selling2.php'          => 'accounts',
            'purchase_summary.php'  => 'accounts',
            'exchange_rate.php'     => 'accounts',
            'price_forecast.php'    => 'accounts',
            'financial_forecast.php'=> 'accounts',

            // reports
            'reports.php'           => 'reports',
            'analytics.php'         => 'reports',
            'buyer_analytics.php'   => 'reports',
            'clerk_performance.php' => 'reports',
            'grower_performance.php'=> 'reports',
            'season_compare.php'    => 'reports',
            'timb_reports.php'      => 'reports',
            'audit_log.php'         => 'reports',
            'system_health.php'     => 'reports',
            'syscheck.php'          => 'reports',
            'business_intelligence.php' => 'reports',

            // stoporders
            'stop_orders.php'           => 'stoporders',
            'stoporder_processing.php'  => 'stoporders',
            'stoporder_verification.php'=> 'stoporders',
            'internal_stoporders.php'   => 'stoporders',

            // growers
            'growers.php'           => 'growers',
            'grower_booking.php'    => 'growers',
            'grower_import.php'     => 'growers',
            'grower_loans.php'      => 'growers',
            'grower_registry.php'   => 'growers',
            'timb_grower_registry.php'=> 'growers',
            'timb_registry_sync.php'  => 'growers',

            // dispatch
            'dispatch.php'          => 'dispatch',
            'dispatch_export.php'   => 'dispatch',
            'vehicles.php'          => 'dispatch',
            'locations.php'         => 'dispatch',
            'transporters.php'      => 'dispatch',

            // payments
            'payments.php'          => 'payments',
            'pay_dashboard.php'     => 'payments',
            'pay_audit.php'         => 'payments',
            'pay_batches.php'       => 'payments',
            'pay_grower.php'        => 'payments',
            'pay_reconcile.php'     => 'payments',
            'pay_owed_recon.php'    => 'payments',
            'banking.php'           => 'payments',
            'bank_file.php'         => 'payments',

            // admin / settings
            'settings.php'          => 'admin',
            'users.php'             => 'admin',
            'backup.php'            => 'admin',
            'auto_backup.php'       => 'admin',
            'timb_api.php'          => 'admin',
            'notifications.php'     => 'admin',
            'sms_notify.php'        => 'admin',
            'whatsapp_notify.php'   => 'admin',
            'document_vault.php'    => 'admin',
            'file_manager_uploads.php'=> 'admin'
        ];
        
        if (isset($rights_map[$current_script])) {
            $required_right = $rights_map[$current_script];
            if (!has_right($required_right)) {
                $_SESSION['flash_err'] = "Access Denied: You do not have permissions for the " . ucfirst($required_right) . " module.";
                header("Location: dashboard.php");
                exit;
            }
        }
    }
}

function has_right($right) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['is_admin'])) return true;
    return !empty($_SESSION['rights'][$right]);
}

function is_master_user() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return !empty($_SESSION['is_master']) || (($_SESSION['role'] ?? '') === 'master');
}

function is_dedicated_user() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return !is_master_user() && !empty($_SESSION['user_spid']) && (int)$_SESSION['user_spid'] > 0;
}

function current_userid()       { return $_SESSION['userid']           ?? 0; }
function current_user()         { return $_SESSION['username']          ?? 'Unknown'; }
function current_selling_point(){ return $_SESSION['selling_pointid']   ?? 0; }
function current_season_id()    { return $_SESSION['seasonid']          ?? 0; }

function flowork_build_user_session($conn, $user) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    // New identity, new session id: stops a session id planted before login
    // (session fixation) from carrying over into the authenticated session.
    session_regenerate_id(true);

    $uid       = (int)$user['id'];
    $user_spid = (int)($user['selling_pointid'] ?? 0);
    $is_master = (!empty($user['is_master']) || ($user['role'] ?? '') === 'master') ? 1 : 0;
    $is_admin  = (!empty($user['is_admin'])  || ($user['role'] ?? '') === 'admin')  ? 1 : 0;

    // Get accessible floors
    if ($is_master || $user_spid === 0) {
        $sp_q = $conn->query("SELECT id, name, floor_code FROM selling_point WHERE active=1 ORDER BY name");
    } else {
        $sp_q = $conn->query("SELECT id, name, floor_code FROM selling_point WHERE id=$user_spid AND active=1 LIMIT 1");
    }
    $floors = ($sp_q && $sp_q !== true) ? $sp_q->fetch_all(MYSQLI_ASSOC) : [];

    // Ensure at least one floor exists
    if (empty($floors)) {
        if ($user_spid > 0) {
            $sp_q = $conn->query("SELECT id, name, floor_code FROM selling_point WHERE id=$user_spid LIMIT 1");
            $floors = ($sp_q && $sp_q !== true) ? $sp_q->fetch_all(MYSQLI_ASSOC) : [];
        }
        if (empty($floors)) {
            $conn->query("INSERT INTO selling_point (name, floor_code, floor_id, active, datetime)
                VALUES ('Main Floor', 'MF', 'MF001', 1, NOW())
                ON DUPLICATE KEY UPDATE name=name");
            $new_sp = $conn->insert_id ?: 1;
            $far_future = date('Y-m-d', strtotime('+3650 days'));
            $conn->query("INSERT INTO flowork_subscriptions (selling_pointid, status, plan_type, start_date, end_date)
                VALUES ($new_sp, 'Active', 'Lifetime', NOW(), '$far_future')
                ON DUPLICATE KEY UPDATE status='Active'");
            $floors = [['id' => $new_sp, 'name' => 'Main Floor', 'floor_code' => 'MF']];
        }
    }

    // Set base session variables
    $_SESSION['user_id']           = $uid;
    $_SESSION['userid']            = $uid;
    $_SESSION['username']          = $user['username'];
    $_SESSION['role']              = $user['role'] ?? 'clerk';
    $_SESSION['is_admin']          = $is_admin;
    $_SESSION['is_master']         = $is_master;
    $_SESSION['user_spid']         = $user_spid;
    $_SESSION['accessible_floors'] = $floors;

    if (!$is_master && $user_spid > 0) {
        $_SESSION['selling_pointid']    = $user_spid;
        $_SESSION['selling_point_name'] = $floors[0]['name'] ?? 'Floor';
    } else {
        $_SESSION['selling_pointid']    = 0; // chosen at login or auto-selected
    }

    // Resolve rights and populate $_SESSION['rights']
    $rights = flowork_resolve_rights($user);
    $_SESSION['rights'] = $rights;

    // Active season
    $s_q = $conn->query("SELECT id, description FROM seasons WHERE active=1 LIMIT 1");
    if ($s_q && $s_q !== true && $s_q->num_rows > 0) {
        $s = $s_q->fetch_assoc();
        $_SESSION['seasonid']    = (int)$s['id'];
        $_SESSION['season_name'] = $s['description'];
    } else {
        $_SESSION['seasonid']    = 1;
        $_SESSION['season_name'] = 'Season 2026';
    }
}

// CSRF Token Helpers
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('js_arg')) {
    /**
     * A value as a quoted JavaScript argument inside an HTML attribute, e.g.
     * onclick="f(<?= js_arg($name) ?>)". addslashes() is not enough there: a
     * double quote in the value still ends the attribute.
     */
    function js_arg($v): string {
        return htmlspecialchars(json_encode((string)$v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_js')) {
    /**
     * Script that attaches the CSRF token to every POST the page makes:
     * a hidden field on each submitted form (including form.submit() calls,
     * which skip the submit event) and an X-CSRF-Token header on same-origin
     * fetch() calls. Emit it in <head> before any other script, so
     * require_login() can check every POST without each page doing it.
     */
    function csrf_js() {
        $tok = json_encode(csrf_token());
        return <<<HTML
<script>
(function () {
  var TOKEN = $tok;
  function addField(form) {
    if (!form || String(form.method).toLowerCase() !== 'post') return;
    if (form.querySelector('input[name="csrf_token"]')) return;
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = 'csrf_token'; i.value = TOKEN;
    form.appendChild(i);
  }
  document.addEventListener('submit', function (e) { addField(e.target); }, true);
  var nativeSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () { addField(this); return nativeSubmit.call(this); };
  var nativeFetch = window.fetch;
  if (nativeFetch) {
    window.fetch = function (input, init) {
      init = init || {};
      var method = String(init.method || (input && input.method) || 'GET').toUpperCase();
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      var sameOrigin = new URL(url, location.href).origin === location.origin;
      if (method !== 'GET' && method !== 'HEAD' && sameOrigin) {
        var h = new Headers(init.headers || (input && input.headers) || {});
        if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', TOKEN);
        init.headers = h;
      }
      return nativeFetch.call(this, input, init);
    };
  }
})();
</script>
HTML;
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        // The session token is created unconditionally when this file loads, so
        // it is always present for a real request.
        //
        // Two earlier escape hatches were removed because both let a forged
        // cross-site POST through for any logged-in user:
        //   * a `toggle_theme` field in the POST returned true for the whole
        //     request — layout.php sets that cookie itself, so nothing needed it;
        //   * an "authenticated session" fallback returned true regardless of the
        //     token and adopted the supplied value as the session token.
        // Every page that calls this emits csrf_field() in all of its forms.
        return !empty($_SESSION['csrf_token'])
            && is_string($token)
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}

/**
 * Coordinated User Creation & Editing Handler
 */
if (!function_exists('flowork_save_user')) {
    function flowork_save_user($conn, $data) {
        $uid        = (int)($data['id'] ?? 0);
        $uname      = trim($data['username'] ?? '');
        $fname      = trim($data['full_name'] ?? '');
        $email      = trim($data['email'] ?? '');
        $phone      = trim($data['phone'] ?? '');
        $role       = $data['role'] ?? 'clerk';
        $is_adm     = (!empty($data['is_admin']) || $role === 'admin') ? 1 : 0;
        $is_mast    = (!empty($data['is_master']) || $role === 'master') ? 1 : 0;
        $sp_assign  = (int)($data['selling_pointid'] ?? 0);

        if ($is_mast) {
            $sp_assign = 0; // Master Users have access to all floors
        }

        if (!$uname) {
            return ['ok' => false, 'err' => 'Username is required.'];
        }

        // Check username uniqueness
        if ($uid > 0) {
            $stmt_ex = $conn->prepare("SELECT id FROM users WHERE username=? AND id != ? LIMIT 1");
            $stmt_ex->bind_param("si", $uname, $uid);
        } else {
            $stmt_ex = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
            $stmt_ex->bind_param("s", $uname);
        }
        $stmt_ex->execute();
        $ex = $stmt_ex->get_result();
        if ($ex && $ex->num_rows > 0) {
            $stmt_ex->close();
            return ['ok' => false, 'err' => "Username '$uname' already exists."];
        }
        $stmt_ex->close();

        // Standardized rights JSON
        if (isset($data['rights']) && is_array($data['rights'])) {
            $rights_json = json_encode(array_values($data['rights']));
        } else {
            if ($is_mast || $role === 'admin') {
                $rights_json = json_encode(['floor','balance','accounts','reports','stoporders','growers','dispatch','payments','admin']);
            } elseif ($role === 'manager') {
                $rights_json = json_encode(['floor','balance','accounts','reports','stoporders','growers']);
            } elseif ($role === 'accounts') {
                $rights_json = json_encode(['accounts','reports','balance']);
            } else {
                $rights_json = json_encode(['floor','growers']);
            }
        }

        if ($uid > 0) {
            // EDIT USER
            if (!empty($data['password'])) {
                $pw = flowork_password_hash($data['password']);
                $stmt_upd = $conn->prepare("UPDATE users SET username=?, full_name=?, email=?, phone=?, role=?, is_admin=?, is_master=?, selling_pointid=?, rights=?, password=? WHERE id=?");
                $stmt_upd->bind_param("sssssiiissi", $uname, $fname, $email, $phone, $role, $is_adm, $is_mast, $sp_assign, $rights_json, $pw, $uid);
            } else {
                $stmt_upd = $conn->prepare("UPDATE users SET username=?, full_name=?, email=?, phone=?, role=?, is_admin=?, is_master=?, selling_pointid=?, rights=? WHERE id=?");
                $stmt_upd->bind_param("sssssiiisi", $uname, $fname, $email, $phone, $role, $is_adm, $is_mast, $sp_assign, $rights_json, $uid);
            }
            $stmt_upd->execute();
            $stmt_upd->close();

            // If editing currently logged in user, refresh active session
            if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $uid) {
                $u_q = $conn->query("SELECT * FROM users WHERE id=$uid LIMIT 1");
                if ($u_q && $u_q !== true && $u_q->num_rows > 0) {
                    flowork_build_user_session($conn, $u_q->fetch_assoc());
                }
            }

            return ['ok' => true, 'userid' => $uid, 'msg' => "✓ User '$uname' updated successfully."];
        } else {
            // CREATE USER
            // No shared default: when the admin leaves the password blank, generate
            // one for this user and show it once so it can be handed over.
            $generated = empty($data['password']);
            $pw_plain  = $generated ? bin2hex(random_bytes(5)) : $data['password'];
            $pw = flowork_password_hash($pw_plain);

            $stmt_ins = $conn->prepare("INSERT INTO users (username, full_name, email, phone, role, is_admin, is_master, selling_pointid, rights, password, active) VALUES (?,?,?,?,?,?,?,?,?,?,1)");
            $stmt_ins->bind_param("sssssiiiss", $uname, $fname, $email, $phone, $role, $is_adm, $is_mast, $sp_assign, $rights_json, $pw);
            $stmt_ins->execute();
            $new_uid = $stmt_ins->insert_id;
            $stmt_ins->close();

            if ($new_uid) {
                $stmt_perm = $conn->prepare("INSERT IGNORE INTO user_permissions (userid) VALUES (?)");
                $stmt_perm->bind_param("i", $new_uid);
                $stmt_perm->execute();
                $stmt_perm->close();
            }

            $msg = $generated
                ? "✓ User '$uname' created. Temporary password: $pw_plain (shown once; give it to the user)."
                : "✓ User '$uname' created successfully.";
            return ['ok' => true, 'userid' => $new_uid, 'msg' => $msg];
        }
    }
}

