<?php
/**
 * two_factor.php — 2FA setup and verification
 * Uses TOTP (Google Authenticator compatible) via a pure-PHP implementation
 * No external libraries needed — uses hash_hmac
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";

$page_title  = "Two-Factor Authentication";
$active_page = "two_factor";
$userid      = current_userid();
$msg=$err='';

// Create 2FA table
$conn->query("CREATE TABLE IF NOT EXISTS user_2fa (
    userid INT PRIMARY KEY,
    secret VARCHAR(64) NOT NULL,
    enabled TINYINT DEFAULT 0,
    backup_codes TEXT DEFAULT '',
    enabled_at TIMESTAMP NULL DEFAULT NULL,
    last_used TIMESTAMP NULL DEFAULT NULL
)");

// TOTP helpers (shared with the mobile API login)
require_once __DIR__ . '/includes/totp.php';

// ── 2FA verification gate (called from login flow) ────────────────────────
if (isset($_GET['verify'])) {
    // This page is shown mid-login when 2FA is pending
    if (!isset($_SESSION['2fa_pending_userid'])) {
        header('Location: login.php'); exit;
    }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            die("CSRF token verification failed.");
        }
        $pending_uid = (int)$_SESSION['2fa_pending_userid'];
        $code = trim($_POST['code']??'');

        // Cap guesses: 5 per pending login (then back to the password step) and
        // 10 per account per 15 minutes from any address, so the 6-digit code
        // cannot be brute-forced.
        require_once __DIR__ . '/includes/rate_limiter.php';
        $_SESSION['2fa_attempts'] = (int)($_SESSION['2fa_attempts'] ?? 0) + 1;
        if ($_SESSION['2fa_attempts'] > 5
            || !check_rate_limit($conn, '2fa_verify', 10, 900, 'user:' . $pending_uid)) {
            unset($_SESSION['2fa_pending_userid'], $_SESSION['2fa_redirect'], $_SESSION['2fa_attempts']);
            $_SESSION['flash_err'] = "Too many incorrect codes. Sign in again.";
            header('Location: login.php'); exit;
        }

        if (flowork_2fa_check($conn, $pending_uid, $code)) {
            unset($_SESSION['2fa_attempts']);

            // Fetch user record to construct session properly
            $stmt_usr = $conn->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
            $stmt_usr->bind_param("i", $pending_uid);
            $stmt_usr->execute();
            $u_q = $stmt_usr->get_result();
            $user = ($u_q && $u_q->num_rows > 0) ? $u_q->fetch_assoc() : null;
            $stmt_usr->close();

            if ($user) {
                flowork_build_user_session($conn, $user);
            }

            $_SESSION['2fa_verified'] = true;
            unset($_SESSION['2fa_pending_userid']);
            // Restore original destination
            $redirect = (string)($_SESSION['2fa_redirect'] ?? 'dashboard.php');
            unset($_SESSION['2fa_redirect']);
            // Same-site paths only: a request path like //other.site/... would leave the site.
            if (!preg_match('#^(/(?!/)|[A-Za-z0-9_-]+\.php)#', $redirect) || preg_match('#[\r\n\\\\]#', $redirect)) {
                $redirect = 'dashboard.php';
            }
            header("Location: $redirect"); exit;
        } else {
            $err = "Invalid code. Please try again.";
        }
    }
    // Show verification form
    ?><!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Verify 2FA — Flowork</title>
    <style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Segoe UI',sans-serif;background:#1a1208;min-height:100vh;display:flex;align-items:center;justify-content:center}
    .box{background:#fff;border-radius:12px;padding:2rem;width:340px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
    h1{font-size:1.1rem;margin-bottom:.25rem;color:#1a1208}
    p{font-size:.8rem;color:#888;margin-bottom:1.25rem}
    input{width:100%;padding:.65rem;border:1.5px solid #e0d8c8;border-radius:6px;font-size:1.6rem;text-align:center;letter-spacing:.3em;margin-bottom:.85rem;outline:none}
    input:focus{border-color:#c8952a;box-shadow:0 0 0 3px rgba(200,149,42,.12)}
    button{width:100%;padding:.7rem;background:#c8952a;color:#fff;border:none;border-radius:6px;font-size:.9rem;font-weight:600;cursor:pointer}
    .err{background:#fce4ec;color:#c62828;padding:.6rem .85rem;border-radius:6px;font-size:.8rem;margin-bottom:.75rem}
    .icon{font-size:2.5rem;text-align:center;margin-bottom:.75rem}
    </style></head><body><div class="box">
    <div class="icon">🔐</div>
    <h1>Two-Factor Authentication</h1>
    <p>Enter the 6-digit code from your authenticator app, or a backup code.</p>
    <?php if($err):?><div class="err"><?=htmlspecialchars($err)?></div><?php endif;?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="text" name="code" maxlength="8" placeholder="000000" autofocus autocomplete="one-time-code">
      <button type="submit">Verify →</button>
    </form>
    </div></body></html><?php exit;
}

// ── Settings page ─────────────────────────────────────────────────────────
require_login();
if (!empty($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }
$stmt_fa = $conn->prepare("SELECT * FROM user_2fa WHERE userid=? LIMIT 1");
$stmt_fa->bind_param("i", $userid);
$stmt_fa->execute();
$fa_q = $stmt_fa->get_result();
$fa   = ($fa_q && $fa_q->num_rows > 0) ? $fa_q->fetch_assoc() : null;
$stmt_fa->close();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token verification failed.");
    }
    $action = $_POST['action'] ?? '';

    if ($action==='generate_secret') {
        $secret = totp_secret();
        $codes  = make_backup_codes();
        $bc_str = implode(',',$codes);
        
        $stmt_ins = $conn->prepare("INSERT INTO user_2fa (userid,secret,enabled,backup_codes)
            VALUES (?,?,0,?)
            ON DUPLICATE KEY UPDATE secret=?,enabled=0,backup_codes=?");
        $stmt_ins->bind_param("issss", $userid, $secret, $bc_str, $secret, $bc_str);
        $stmt_ins->execute();
        $stmt_ins->close();
        
        $_SESSION['2fa_setup_secret'] = $secret;
        $_SESSION['2fa_setup_codes']  = $codes;
        $msg = "Key generated. Add it to your authenticator app, then enter the code it shows.";
        $fa = ['secret'=>$secret,'enabled'=>0,'backup_codes'=>implode(',',$codes)];
    }

    if ($action==='enable_2fa') {
        $code = trim($_POST['code']??'');
        $sec  = $fa['secret'] ?? '';
        if (totp_verify($sec,$code)) {
            $stmt_en = $conn->prepare("UPDATE user_2fa SET enabled=1,enabled_at=NOW() WHERE userid=?");
            $stmt_en->bind_param("i", $userid);
            $stmt_en->execute();
            $stmt_en->close();
            
            $msg = "✓ 2FA enabled successfully!";
            $fa['enabled'] = 1;
            $_SESSION['2fa_verified'] = true;
        } else {
            $err = "Invalid code. Make sure your authenticator app is set up correctly.";
        }
    }

    if ($action==='disable_2fa') {
        // Switching 2FA off needs a current code, so a borrowed or stolen
        // session cannot remove it; admins cannot switch it off at all while
        // REQUIRE_ADMIN_2FA is on.
        $is_adm = !empty($_SESSION['is_admin']) || is_master_user();
        if ($is_adm && flowork_env('REQUIRE_ADMIN_2FA') === '1') {
            $err = "Two-factor authentication is required for administrators and cannot be switched off.";
        } elseif (!flowork_2fa_check($conn, $userid, trim($_POST['code'] ?? ''))) {
            $err = "Enter a current code from your authenticator app (or a backup code) to switch 2FA off.";
        } else {
            $stmt_dis = $conn->prepare("UPDATE user_2fa SET enabled=0 WHERE userid=?");
            $stmt_dis->bind_param("i", $userid);
            $stmt_dis->execute();
            $stmt_dis->close();
            audit_log($conn, (int)($_SESSION['selling_pointid'] ?? 0), $userid, 'user_2fa', 'disable', $userid);
            $msg = "2FA has been disabled.";
            if($fa) $fa['enabled'] = 0;
        }
    }

    if ($action==='regen_backups') {
        $codes = make_backup_codes();
        $bc_str = implode(',',$codes);
        
        $stmt_rg = $conn->prepare("UPDATE user_2fa SET backup_codes=? WHERE userid=?");
        $stmt_rg->bind_param("si", $bc_str, $userid);
        $stmt_rg->execute();
        $stmt_rg->close();
        
        $_SESSION['2fa_setup_codes'] = $codes;
        $msg = "✓ New backup codes generated. Save them now — they won't be shown again.";
        if($fa) $fa['backup_codes'] = implode(',',$codes);
    }
}

// The secret is shown for manual entry in the authenticator app. It used to be
// sent to api.qrserver.com to draw a QR code, which gave the key to a third
// party (and failed on a LAN without internet).
$setup_secret = $fa['secret'] ?? null;
$stmt_un = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
$stmt_un->bind_param("i", $userid);
$stmt_un->execute();
$username_q = $stmt_un->get_result();
$username = ($username_q && $username_q->num_rows > 0) ? ($username_q->fetch_assoc()['username'] ?? 'user') : 'user';
$stmt_un->close();

$backup_codes = $fa ? array_filter(explode(',',$fa['backup_codes']??'')) : [];
$show_backups = isset($_SESSION['2fa_setup_codes']);

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Two-Factor Authentication</h2>
    <p>Add an extra layer of security to your account</p>
  </div>
  <?php if($fa && $fa['enabled']):?>
  <span class="badge badge-green" style="font-size:.78rem">🔒 2FA Enabled</span>
  <?php else:?>
  <span class="badge badge-gray" style="font-size:.78rem">⚠ 2FA Disabled</span>
  <?php endif;?>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="premium-tabs-nav" data-tabs="twofa">
  <button type="button" class="premium-tab-btn active" data-tab="status">🔐 <?=(!$fa || !$fa['enabled']) ? 'Set Up 2FA' : '2FA Status'?></button>
  <button type="button" class="premium-tab-btn" data-tab="codes">🔑 Backup Codes</button>
  <button type="button" class="premium-tab-btn" data-tab="help">❓ How it works</button>
</div>

<div id="panel-status" class="tab-panel active" data-tabs="twofa">

<?php if(!$fa || !$fa['enabled']): ?>
<!-- Setup flow -->
<div class="card">
<div class="card-head"><h3>Set Up 2FA</h3></div>
<div class="card-body">
  <?php if(!$setup_secret): ?>
  <p style="font-size:.82rem;color:var(--muted);margin-bottom:1rem">
    Use an authenticator app like <strong>Google Authenticator</strong>, <strong>Authy</strong>, or <strong>Microsoft Authenticator</strong>.
  </p>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" name="action" value="generate_secret">
    <button type="submit" class="btn btn-primary" style="width:100%">📱 Begin Setup</button>
  </form>
  <?php else: ?>
  <div style="background:var(--cream-d);border-radius:6px;padding:.75rem;font-size:.8rem;margin-bottom:1rem;text-align:center">
    <div style="font-size:.72rem;color:var(--muted);margin-bottom:.35rem">In your authenticator app choose <b>Enter a setup key</b>, account <b><?=htmlspecialchars($username)?>@flowork</b>, time-based, and type this key:</div>
    <code style="font-size:1rem;letter-spacing:.15em;font-weight:600"><?=htmlspecialchars(trim(chunk_split($setup_secret, 4, ' ')))?></code>
  </div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" name="action" value="enable_2fa">
    <div class="form-group">
      <label>Enter the 6-digit code from your app</label>
      <input type="text" name="code" class="form-control" maxlength="6" placeholder="000000"
             style="text-align:center;font-size:1.4rem;letter-spacing:.3em" autofocus required>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%">✓ Verify &amp; Enable 2FA</button>
  </form>
  <?php endif; ?>
</div>
</div>

<?php else: ?>
<!-- 2FA enabled — management -->
<div class="card">
<div class="card-head"><h3>2FA is Active</h3></div>
<div class="card-body">
  <p style="font-size:.82rem;color:var(--muted);margin-bottom:1rem">Your account is protected with two-factor authentication.</p>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="regen_backups">
      <button class="btn btn-ghost">↻ New Backup Codes</button>
    </form>
    <form method="POST" onsubmit="return confirm('Disable 2FA? Your account will be less secure.')">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="disable_2fa">
      <input type="text" name="code" class="form-control" maxlength="8" placeholder="Current code" autocomplete="one-time-code" required
             style="max-width:160px;display:inline-block;margin-right:.5rem;text-align:center;letter-spacing:.2em">
      <button class="btn btn-danger">✕ Disable 2FA</button>
    </form>
  </div>
</div>
</div>
<?php endif; ?>

</div><!-- /panel-status -->

<!-- Backup codes -->
<div id="panel-codes" class="tab-panel" data-tabs="twofa">
<div class="card">
<div class="card-head"><h3>Backup Codes</h3></div>
<div class="card-body">
<?php if($show_backups || !empty($backup_codes)): ?>
<p style="font-size:.78rem;color:var(--danger);margin-bottom:.75rem;font-weight:500">
  ⚠ Save these codes somewhere safe. Each can only be used once.
</p>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem;margin-bottom:.75rem">
<?php
$display_codes = isset($_SESSION['2fa_setup_codes']) ? $_SESSION['2fa_setup_codes'] : array_filter(explode(',',$fa['backup_codes']??''));
foreach($display_codes as $code): if(!trim($code)) continue; ?>
<div style="background:var(--ink);color:var(--gold);font-family:monospace;padding:.45rem .75rem;border-radius:6px;font-size:.85rem;text-align:center;letter-spacing:.1em">
  <?=htmlspecialchars(strtoupper(trim($code)))?>
</div>
<?php endforeach;?>
</div>
<button onclick="window.print()" class="btn btn-ghost btn-sm" style="width:100%">🖨 Print Backup Codes</button>
<?php unset($_SESSION['2fa_setup_codes']); ?>
<?php else: ?>
<p style="font-size:.82rem;color:var(--muted)">Set up 2FA to generate backup codes.</p>
<?php endif; ?>
</div>
</div>
</div><!-- /panel-codes -->

<div id="panel-help" class="tab-panel" data-tabs="twofa">
<div class="card">
<div class="card-head"><h3>How it works</h3></div>
<div class="card-body" style="font-size:.82rem;color:var(--muted);display:grid;grid-template-columns:repeat(3,1fr);gap:1rem">
  <div style="text-align:center">
    <div style="font-size:1.8rem;margin-bottom:.35rem">📱</div>
    <div style="font-weight:600;color:var(--text-strong);margin-bottom:.25rem">Install App</div>
    <div>Download Google Authenticator, Authy, or similar TOTP app</div>
  </div>
  <div style="text-align:center">
    <div style="font-size:1.8rem;margin-bottom:.35rem">📷</div>
    <div style="font-weight:600;color:var(--text-strong);margin-bottom:.25rem">Add the setup key</div>
    <div>In the app choose "Enter a setup key" and type the key shown on the setup page</div>
  </div>
  <div style="text-align:center">
    <div style="font-size:1.8rem;margin-bottom:.35rem">🔢</div>
    <div style="font-weight:600;color:var(--text-strong);margin-bottom:.25rem">Enter Code</div>
    <div>On each login enter the 6-digit rotating code from your app</div>
  </div>
</div>
</div>
</div><!-- /panel-help -->

<?php include "includes/layout_end.php"; ?>
