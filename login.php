<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/password_helper.php";
require_once "includes/audit.php";

// Already logged in and floor selected
if (!empty($_SESSION['user_id']) && !empty($_SESSION['selling_pointid'])) {
    header('Location: dashboard.php');
    exit;
}

$err   = '';
if (!empty($_SESSION['flash_err']) && empty($_SESSION['user_id'])) {
    $err = $_SESSION['flash_err'];
    unset($_SESSION['flash_err']);
}
$step  = 'login'; // login | choose_floor

// ── License Key Activation Submission ─────────────────────────────────────
if (!empty($_SESSION['user_id']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['license_key']) && isset($_POST['activate_spid'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token verification failed.");
    }
    $key = trim($_POST['license_key'] ?? '');
    $act_spid = (int)$_POST['activate_spid'];
    
    // Check if the key exists in flowork_invoices and matches this floor
    $stmt = $conn->prepare("SELECT * FROM flowork_invoices WHERE license_key=? AND selling_pointid=? AND status='Paid' LIMIT 1");
    $stmt->bind_param("si", $key, $act_spid);
    $stmt->execute();
    $inv_q = $stmt->get_result();
    if ($inv_q && $inv_q->num_rows > 0) {
        $inv = $inv_q->fetch_assoc();
        $days = (int)$inv['duration_days'];
        
        $stmt_sub = $conn->prepare("SELECT * FROM flowork_subscriptions WHERE selling_pointid=? LIMIT 1");
        $stmt_sub->bind_param("i", $act_spid);
        $stmt_sub->execute();
        $sub_q = $stmt_sub->get_result();
        $sub = ($sub_q && $sub_q->num_rows > 0) ? $sub_q->fetch_assoc() : null;
        
        $today = date('Y-m-d');
        if ($sub) {
            $current_end = $sub['end_date'];
            if ($sub['status'] === 'Active' && $current_end && $current_end >= $today) {
                $new_end = date('Y-m-d', strtotime($current_end . " + $days days"));
                $new_start = $sub['start_date'];
            } else {
                $new_start = $today;
                $new_end = date('Y-m-d', strtotime($today . " + $days days"));
            }
            $stmt_upd = $conn->prepare("UPDATE flowork_subscriptions SET status='Active', license_key=?, start_date=?, end_date=? WHERE selling_pointid=?");
            $stmt_upd->bind_param("sssi", $key, $new_start, $new_end, $act_spid);
            $stmt_upd->execute();
            $stmt_upd->close();
        } else {
            $new_end = date('Y-m-d', strtotime($today . " + $days days"));
            $stmt_ins = $conn->prepare("INSERT INTO flowork_subscriptions (selling_pointid, status, license_key, start_date, end_date) VALUES (?, 'Active', ?, ?, ?)");
            $stmt_ins->bind_param("isss", $act_spid, $key, $today, $new_end);
            $stmt_ins->execute();
            $stmt_ins->close();
        }
        $stmt_sub->close();
        
        $_SESSION['selling_pointid'] = $act_spid;
        $stmt_fl = $conn->prepare("SELECT name FROM selling_point WHERE id=? LIMIT 1");
        $stmt_fl->bind_param("i", $act_spid);
        $stmt_fl->execute();
        $fl_q = $stmt_fl->get_result();
        $fl = $fl_q ? $fl_q->fetch_assoc() : null;
        $_SESSION['selling_point_name'] = $fl['name'] ?? 'Main Floor';
        $stmt_fl->close();
        
        $stmt->close();
        header('Location: dashboard.php');
        exit;
    } else {
        $stmt->close();
        $err = "Invalid, unpaid, or mismatched license key.";
        $step = 'activate_license';
        $target_floor_id = $act_spid;
        $stmt_fl = $conn->prepare("SELECT name FROM selling_point WHERE id=? LIMIT 1");
        $stmt_fl->bind_param("i", $act_spid);
        $stmt_fl->execute();
        $fl_q = $stmt_fl->get_result();
        $fl = $fl_q ? $fl_q->fetch_assoc() : null;
        $target_floor_name = $fl['name'] ?? 'Floor';
        $stmt_fl->close();
    }
}

// ── Master User Quick Create / Edit User Action (Before Floor Selection) ────
$success_msg = '';
if (!empty($_SESSION['user_id']) && is_master_user() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_create_user') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token verification failed.");
    }
    $edit_id   = (int)($_POST['edit_userid'] ?? 0);
    $user_data = [
        'id'              => $edit_id,
        'username'        => trim($_POST['username'] ?? ''),
        'full_name'       => trim($_POST['full_name'] ?? ''),
        'password'        => trim($_POST['password'] ?? ''),
        'role'            => $_POST['role'] ?? 'clerk',
        'selling_pointid' => (int)($_POST['selling_pointid'] ?? 0),
        'is_master'       => (isset($_POST['is_master']) || $_POST['role'] === 'master') ? 1 : 0,
        'is_admin'        => ($_POST['role'] === 'admin' || $_POST['role'] === 'master') ? 1 : 0
    ];

    $res = flowork_save_user($conn, $user_data);
    if ($res['ok']) {
        $success_msg = $res['msg'];
        $audit_data = $user_data;
        unset($audit_data['password']);
        audit_log($conn, 0, $_SESSION['user_id'], 'users', $edit_id ? 'update' : 'create', $res['userid'], null, $audit_data);
    } else {
        $err = $res['err'];
    }
}

// ── STEP 2: Floor selected ────────────────────────────────────────────────
if (!empty($_SESSION['user_id']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selling_pointid']) && !isset($_POST['license_key']) && empty($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token verification failed.");
    }
    $spid = (int)($_POST['selling_pointid'] ?? 0);
    $floors = $_SESSION['accessible_floors'] ?? [];
    $matched = array_filter($floors, fn($f) => (int)$f['id'] === $spid);
    if ($spid && !empty($matched)) {
        $floor = array_values($matched)[0];
        
        // Check floor subscription
        $stmt_sub = $conn->prepare("SELECT * FROM flowork_subscriptions WHERE selling_pointid = ? LIMIT 1");
        $stmt_sub->bind_param("i", $spid);
        $stmt_sub->execute();
        $sub_q = $stmt_sub->get_result();
        $sub = ($sub_q && $sub_q->num_rows > 0) ? $sub_q->fetch_assoc() : null;
        $stmt_sub->close();
        $is_expired = (!$sub || $sub['status'] !== 'Active' || (!empty($sub['end_date']) && $sub['end_date'] < date('Y-m-d')));
        
        if ($is_expired) {
            $step = 'activate_license';
            $target_floor_id = $spid;
            $target_floor_name = $floor['name'];
        } else {
            $_SESSION['selling_pointid']    = $spid;
            $_SESSION['selling_point_name'] = $floor['name'];
            header('Location: dashboard.php');
            exit;
        }
    } else {
        $err = "Invalid selection. Please choose a floor.";
    }
}

// Fetch all system users for modal edit picker
$all_sys_users = [];
if (!empty($_SESSION['user_id']) && is_master_user()) {
    $u_res = $conn->query("SELECT id, username, full_name, role, selling_pointid, is_master, is_admin FROM users ORDER BY username");
    if ($u_res && $u_res !== true) {
        $all_sys_users = $u_res->fetch_all(MYSQLI_ASSOC);
    }
}

// ── Show floor chooser if logged in but no floor ──────────────────────────
if (!empty($_SESSION['user_id']) && empty($_SESSION['selling_pointid']) && $step !== 'activate_license') {
    $step = 'choose_floor';
}

if ($step === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token verification failed.");
    }
    require_once "includes/rate_limiter.php";
    $username = trim($_POST['username'] ?? '');
    if (!check_rate_limit($conn, 'web_login', 10, 300)
        || !check_rate_limit($conn, 'web_login_user', 20, 900, 'user:' . strtolower(substr($username, 0, 40)))) {
        $err = "Too many login attempts. Please try again in a few minutes.";
    } else {
        $password = trim($_POST['password'] ?? '');

        $stmt = $conn->prepare("SELECT * FROM users WHERE username=? AND active=1 LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $u_q = $stmt->get_result();
        $user = ($u_q && $u_q->num_rows > 0) ? $u_q->fetch_assoc() : null;
        $stmt->close();

        if (!$user || !flowork_password_verify($conn, $password, $user)) {
            $err = "Invalid username or password.";
        } else {
            $uid = (int)$user['id'];
            
            // Check if 2FA is enabled for this user
            $stmt_fa = $conn->prepare("SELECT enabled FROM user_2fa WHERE userid=? AND enabled=1 LIMIT 1");
            $stmt_fa->bind_param("i", $uid);
            $stmt_fa->execute();
            $fa_q = $stmt_fa->get_result();
            $fa_enabled = ($fa_q && $fa_q->num_rows > 0);
            $stmt_fa->close();
            
            if ($fa_enabled) {
                // Set pending credentials and redirect to 2FA verification
                $_SESSION['2fa_pending_userid'] = $uid;
                $_SESSION['2fa_redirect'] = 'login.php';
                header("Location: two_factor.php?verify");
                exit;
            }
            
            // No 2FA: Build session immediately
            flowork_build_user_session($conn, $user);
            $_SESSION['2fa_verified'] = true; // No 2FA required, so mark verified
            
            $floors = $_SESSION['accessible_floors'] ?? [];
            if (is_dedicated_user() || (count($floors) === 1 && !is_master_user())) {
                $spid = (int)($floors[0]['id'] ?? $_SESSION['user_spid']);
                
                // Check floor subscription
                $stmt_sub = $conn->prepare("SELECT * FROM flowork_subscriptions WHERE selling_pointid = ? LIMIT 1");
                $stmt_sub->bind_param("i", $spid);
                $stmt_sub->execute();
                $sub_q = $stmt_sub->get_result();
                $sub = ($sub_q && $sub_q->num_rows > 0) ? $sub_q->fetch_assoc() : null;
                $stmt_sub->close();
                $is_expired = (!$sub || $sub['status'] !== 'Active' || (!empty($sub['end_date']) && $sub['end_date'] < date('Y-m-d')));
                
                if ($is_expired) {
                    $_SESSION['selling_pointid'] = 0;
                    $step = 'activate_license';
                    $target_floor_id = $spid;
                    $target_floor_name = $floors[0]['name'] ?? 'Floor';
                } else {
                    $_SESSION['selling_pointid']    = $spid;
                    $_SESSION['selling_point_name'] = $floors[0]['name'] ?? 'Floor';
                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $step = 'choose_floor';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $step === 'choose_floor' ? 'Choose Floor' : 'Login' ?> — Flowork</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:'Plus Jakarta Sans','Segoe UI',system-ui,sans-serif;
  background:#050811;
  background-image: 
    radial-gradient(circle at 15% 15%, rgba(245, 158, 11, 0.18) 0%, transparent 40%),
    radial-gradient(circle at 85% 85%, rgba(16, 185, 129, 0.15) 0%, transparent 40%),
    radial-gradient(circle at 50% 50%, rgba(2, 132, 199, 0.1) 0%, transparent 60%),
    radial-gradient(rgba(245, 158, 11, 0.08) 1px, transparent 1px);
  background-size: 100% 100%, 100% 100%, 100% 100%, 24px 24px;
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:1.5rem;
  color:#f8fafc;
}

.box{
  background:rgba(13, 19, 33, 0.78);
  backdrop-filter:blur(24px);
  -webkit-backdrop-filter:blur(24px);
  border:1px solid rgba(245, 158, 11, 0.28);
  border-radius:20px;
  width:100%;
  box-shadow:0 30px 80px rgba(0,0,0,0.85), 0 0 40px rgba(245, 158, 11, 0.15);
  overflow:hidden;
}
.box-login{max-width:380px}
.box-floor{max-width:440px}

/* Header band */
.bh{
  background:linear-gradient(135deg, rgba(15, 23, 42, 0.95), rgba(30, 41, 59, 0.85));
  padding:1.35rem 1.5rem 1.1rem;
  text-align:center;
  border-bottom:1px solid rgba(245, 158, 11, 0.2);
  position:relative;
}
.bh::after{
  content:'';
  position:absolute;
  bottom:-1px;
  left:15%;
  right:15%;
  height:1px;
  background:linear-gradient(90deg, transparent, #f59e0b, transparent);
}
.bh-icon{
  width:46px;
  height:46px;
  background:linear-gradient(135deg, #10b981, #059669);
  border-radius:12px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:1.4rem;
  margin:0 auto .5rem;
  box-shadow:0 0 18px rgba(16, 185, 129, 0.4);
  border:1px solid rgba(255,255,255,0.25);
}
.bh h1{
  font-size:1.25rem;
  color:#ffffff;
  font-weight:800;
  letter-spacing:.08em;
  text-shadow:0 2px 8px rgba(0,0,0,0.5);
}
.bh p{
  font-size:.72rem;
  color:#94a3b8;
  margin-top:.2rem;
  font-weight:500;
  letter-spacing:.02em;
}

/* Body */
.bb{padding:1.25rem 1.5rem 1.5rem}

/* Form */
.form-group{margin-bottom:1rem}
label{
  display:block;
  font-size:.7rem;
  font-weight:700;
  color:#cbd5e1;
  margin-bottom:.35rem;
  letter-spacing:.06em;
  text-transform:uppercase;
}
input[type=text],input[type=password]{
  width:100%;
  padding:.65rem .85rem;
  border:1.5px solid rgba(245, 158, 11, 0.35);
  border-radius:8px;
  font-size:.88rem;
  outline:none;
  background:rgba(15, 23, 42, 0.85);
  color:#ffffff;
  font-weight:600;
  transition:all .2s ease;
}
input:focus{
  border-color:#f59e0b;
  box-shadow:0 0 0 3px rgba(245, 158, 11, 0.25), 0 0 12px rgba(245, 158, 11, 0.2);
}
.btn{
  width:100%;
  padding:.7rem;
  background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  color:#000000;
  border:none;
  border-radius:8px;
  font-size:.88rem;
  font-weight:800;
  cursor:pointer;
  letter-spacing:.04em;
  margin-top:.2rem;
  box-shadow:0 4px 16px rgba(245, 158, 11, 0.35);
  transition:all .2s ease;
}
.btn:hover{
  transform:translateY(-2px);
  box-shadow:0 6px 22px rgba(245, 158, 11, 0.5);
}
.err{
  background:rgba(239, 68, 68, 0.15);
  color:#fca5a5;
  padding:.6rem .85rem;
  border-radius:8px;
  font-size:.8rem;
  margin-bottom:1rem;
  border:1px solid rgba(239, 68, 68, 0.4);
  font-weight:600;
}
.footer{
  text-align:center;
  font-size:.68rem;
  color:#64748b;
  margin-top:1rem;
  font-weight:500;
}

/* Floor grid */
.floor-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(130px,1fr));
  gap:.75rem;
  margin-bottom:1rem;
}
.floor-card{
  border:1.5px solid rgba(255,255,255,0.12);
  border-radius:12px;
  padding:.9rem .75rem;
  cursor:pointer;
  text-align:center;
  transition:all .25s cubic-bezier(0.4, 0, 0.2, 1);
  background:linear-gradient(145deg, rgba(30, 41, 59, 0.6) 0%, rgba(15, 23, 42, 0.8) 100%);
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:.45rem;
  position:relative;
  overflow:hidden;
}
.floor-card:hover{
  border-color:#f59e0b;
  background:linear-gradient(145deg, rgba(245, 158, 11, 0.18) 0%, rgba(30, 41, 59, 0.9) 100%);
  transform:translateY(-3px) scale(1.02);
  box-shadow:0 10px 24px rgba(0,0,0,0.6), 0 0 16px rgba(245, 158, 11, 0.25);
}
.floor-card.selected{
  border-color:#10b981;
  background:linear-gradient(145deg, rgba(16, 185, 129, 0.2) 0%, rgba(15, 23, 42, 0.9) 100%);
  box-shadow:0 0 20px rgba(16, 185, 129, 0.35);
}
.floor-icon{
  width:40px;
  height:40px;
  border-radius:50%;
  background:linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(16, 185, 129, 0.2));
  border:1px solid rgba(245, 158, 11, 0.35);
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:1.2rem;
  box-shadow:0 0 10px rgba(245, 158, 11, 0.2);
}
.floor-name{
  font-size:.82rem;
  font-weight:700;
  color:#f8fafc;
  line-height:1.25;
}
.floor-code{
  font-size:.65rem;
  color:#f59e0b;
  font-weight:800;
  font-family:monospace;
  margin-top:.1rem;
  letter-spacing:.05em;
  background:rgba(245, 158, 11, 0.12);
  padding:1px 5px;
  border-radius:4px;
  border:1px solid rgba(245, 158, 11, 0.25);
}
.floor-submit{display:none}

.user-info{
  display:flex;
  align-items:center;
  gap:.65rem;
  background:rgba(15, 23, 42, 0.7);
  border:1px solid rgba(255,255,255,0.12);
  border-radius:10px;
  padding:.55rem .85rem;
  margin-bottom:1rem;
  font-size:.78rem;
  color:#94a3b8;
}
.user-info strong{color:#ffffff;font-weight:700}
.user-avatar{
  width:30px;
  height:30px;
  background:linear-gradient(135deg, #f59e0b, #d97706);
  border-radius:50%;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:.8rem;
  font-weight:800;
  color:#000000;
  flex-shrink:0;
  box-shadow:0 0 10px rgba(245, 158, 11, 0.3);
}
.logout-link{
  margin-left:auto;
  font-size:.72rem;
  color:#f59e0b;
  text-decoration:none;
  font-weight:700;
  padding:3px 8px;
  border-radius:5px;
  background:rgba(245, 158, 11, 0.12);
  border:1px solid rgba(245, 158, 11, 0.25);
  transition:all .15s ease;
}
.logout-link:hover{
  background:rgba(245, 158, 11, 0.25);
  color:#ffffff;
}

.master-banner{
  background:linear-gradient(135deg, rgba(245, 158, 11, 0.18) 0%, rgba(16, 185, 129, 0.18) 100%);
  border:1px solid rgba(245, 158, 11, 0.45);
  color:#fcd34d;
  padding:.65rem .85rem;
  border-radius:10px;
  margin-bottom:1rem;
  font-size:.78rem;
  text-align:center;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  box-shadow:0 0 16px rgba(245, 158, 11, 0.15);
}
</style>
</head>
<body>

<?php if ($step === 'login'): ?>
<!-- ═══════════════════════════════════════════════════════ LOGIN ═══════ -->
<div class="box box-login">
  <div class="bh">
    <div class="bh-icon">🌿</div>
    <h1>FLOWORK</h1>
    <p>Tobacco Floor Management System</p>
  </div>
  <div class="bb">
    <?php if ($err): ?>
    <div class="err"><?=htmlspecialchars($err)?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" value="<?=htmlspecialchars($_POST['username']??'')?>"
               autofocus autocomplete="username" required placeholder="Enter your username">
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" autocomplete="current-password" required placeholder="••••••••">
      </div>
      <button type="submit" class="btn">Sign In &rarr;</button>
    </form>

    <div class="footer">Flowork &copy; <?=date('Y')?> &middot; Core Africa Group</div>
  </div>
</div>

<?php elseif ($step === 'choose_floor'): ?>
<!-- ════════════════════════════════════════════════════ CHOOSE FLOOR ═══ -->
<?php $floors = $_SESSION['accessible_floors'] ?? []; ?>
<div class="box box-floor">
  <div class="bh">
    <div class="bh-icon">🏭</div>
    <h1>SELECT FLOOR</h1>
    <p>Choose your active auction floor to continue</p>
  </div>
  <div class="bb">

    <?php if ($err): ?>
    <div class="err"><?=htmlspecialchars($err)?></div>
    <?php endif; ?>

    <?php if (is_master_user()): ?>
    <div class="master-banner">
      <span style="font-size:1.2rem">👑</span>
      <div>
        <strong style="color:#fcd34d">Master User Logged In</strong>
        <div style="font-size:.72rem;color:#cbd5e1;margin-top:2px">Unrestricted access to manage users and monitor all floors</div>
      </div>
    </div>

    <?php if (!empty($success_msg)): ?>
    <div style="background:rgba(16,185,129,0.2);color:#6ee7b7;border:1px solid #10b981;padding:.75rem 1rem;border-radius:10px;font-size:.85rem;font-weight:600;margin-bottom:1.25rem;display:flex;align-items:center;gap:8px;box-shadow:0 0 15px rgba(16,185,129,0.2)">
      <span style="font-size:1.1rem">✓</span> <?= htmlspecialchars($success_msg) ?>
    </div>
    <?php endif; ?>

    <!-- Master Pre-Floor User Management Actions -->
    <div style="display:flex;gap:12px;margin-bottom:1.25rem">
      <button type="button" onclick="openMasterUserModal()" style="background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%);color:#000000;font-size:.9rem;padding:.8rem 1.1rem;flex:1;border-radius:10px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;border:1px solid #fbbf24;box-shadow:0 4px 20px rgba(245, 158, 11, 0.4);letter-spacing:.02em;transition:all 0.2s">
        <span style="font-size:1.1rem">➕</span> Create / Edit User Modal
      </button>
      <a href="users.php" style="background:linear-gradient(135deg, rgba(30, 58, 138, 0.7) 0%, rgba(14, 165, 233, 0.35) 100%);border:1px solid #0284c7;color:#38bdf8;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;font-size:.9rem;padding:.8rem 1.1rem;flex:1;border-radius:10px;font-weight:800;box-shadow:0 4px 20px rgba(2, 132, 199, 0.35);transition:all 0.2s">
        <span style="font-size:1.1rem">👥</span> Full User Directory &rarr;
      </a>
    </div>

    <!-- Interactive Pop-up Modal for Master User Pre-Floor User Creation & Editing -->
    <div id="master-user-modal-overlay" style="display:<?= (!empty($_POST['action']) && $_POST['action']==='quick_create_user' && $err) ? 'flex' : 'none' ?>;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(5,8,17,0.92);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);z-index:99999;align-items:center;justify-content:center;padding:1rem">
      <div style="background:linear-gradient(145deg, #0d1322 0%, #151d30 50%, #080c16 100%);border:1.5px solid #f59e0b;border-radius:18px;width:100%;max-width:510px;padding:1.75rem;box-shadow:0 30px 90px rgba(0,0,0,0.95), 0 0 40px rgba(245,158,11,0.35);box-sizing:border-box">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;background:rgba(15,23,42,0.8);padding:.85rem 1.1rem;margin:-1.75rem -1.75rem 1.25rem -1.75rem;border-bottom:1.5px solid #f59e0b;border-radius:16px 16px 0 0">
          <div style="display:flex;align-items:center;gap:10px">
            <span style="font-size:1.4rem">👑</span>
            <div>
              <h3 id="modal-title-text" style="font-size:1.05rem;color:#fcd34d;margin:0;font-weight:800;letter-spacing:.02em">Create / Edit User</h3>
              <div style="font-size:.72rem;color:#cbd5e1;margin-top:2px">Pre-Floor System User Management</div>
            </div>
          </div>
          <button type="button" onclick="closeMasterUserModal()" style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);color:#ffffff;width:34px;height:34px;border-radius:50%;font-size:1.3rem;cursor:pointer;line-height:1;display:flex;align-items:center;justify-content:center;font-weight:700">&times;</button>
        </div>

        <div style="display:flex;gap:8px;margin-bottom:1.25rem;padding:5px;background:#050811;border-radius:10px;border:1px solid rgba(245,158,11,0.3)">
          <button type="button" id="modal-tab-btn-create" onclick="setModalMode('create')" style="flex:1;padding:.65rem .75rem;font-size:.85rem;font-weight:800;border-radius:8px;border:1.5px solid #fbbf24;cursor:pointer;background:linear-gradient(135deg, #f59e0b, #d97706);color:#000000 !important;box-shadow:0 4px 14px rgba(245, 158, 11, 0.45)">➕ Create User</button>
          <button type="button" id="modal-tab-btn-edit" onclick="setModalMode('edit')" style="flex:1;padding:.65rem .75rem;font-size:.85rem;font-weight:800;border-radius:8px;border:1.5px solid rgba(255,255,255,0.15);cursor:pointer;background:rgba(30,41,59,0.7);color:#ffffff !important">✏ Edit Existing User</button>
        </div>

        <?php if (!empty($err) && !empty($_POST['action']) && $_POST['action']==='quick_create_user'): ?>
        <div style="background:rgba(239,68,68,0.2);border:1.5px solid #ef4444;color:#fca5a5;padding:.7rem .9rem;border-radius:8px;font-size:.85rem;font-weight:700;margin-bottom:1.1rem">
          <?= htmlspecialchars($err) ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="modal-user-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="action" value="quick_create_user">
          <input type="hidden" name="edit_userid" id="modal-edit-userid" value="0">

          <?php
          $sp_map_login = [];
          foreach ($floors as $fl_item) { $sp_map_login[(int)$fl_item['id']] = $fl_item['name']; }
          ?>
          <div id="modal-user-picker-wrapper" style="display:none;margin-bottom:1rem">
            <label style="font-size:.75rem;color:#fcd34d;font-weight:800;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em">Select Existing User to Edit *</label>
            <select id="modal-user-picker" onchange="onSelectUserToEdit(this.value)" class="form-control" style="font-size:.88rem;padding:.65rem .85rem;width:100%;border-radius:8px;border:1.5px solid #f59e0b;background:#0f172a;color:#ffffff;font-weight:700">
              <option value="" style="background:#0f172a;color:#ffffff">-- Choose User --</option>
              <?php foreach ($all_sys_users as $su):
                $su_spid = (int)($su['selling_pointid'] ?? 0);
                $fl_desc = (!empty($su['is_master']) || $su['role']==='master') ? '👑 Master User' : ($sp_map_login[$su_spid] ?? ($su_spid > 0 ? "Floor #$su_spid" : '🌐 All Floors'));
              ?>
              <option value="<?= $su['id'] ?>" style="background:#0f172a;color:#ffffff"><?= htmlspecialchars($su['username']) ?> (<?= htmlspecialchars($su['full_name'] ?: ucfirst($su['role'])) ?> &middot; <?= htmlspecialchars($fl_desc) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" style="margin-bottom:1rem">
            <label style="font-size:.75rem;color:#cbd5e1;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em">Username *</label>
            <input type="text" name="username" id="modal-input-username" class="form-control" required placeholder="e.g. jmoyo" style="font-size:.9rem;padding:.65rem .85rem;width:100%;border-radius:8px;border:1.5px solid rgba(245,158,11,0.4);background:#0f172a;color:#ffffff;font-weight:600">
          </div>

          <div class="form-group" style="margin-bottom:1rem">
            <label style="font-size:.75rem;color:#cbd5e1;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em">Full Name</label>
            <input type="text" name="full_name" id="modal-input-fullname" class="form-control" placeholder="John Moyo" style="font-size:.9rem;padding:.65rem .85rem;width:100%;border-radius:8px;border:1.5px solid rgba(245,158,11,0.4);background:#0f172a;color:#ffffff;font-weight:600">
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
            <div>
              <label style="font-size:.75rem;color:#cbd5e1;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em">System Role</label>
              <select name="role" id="modal-role-select" onchange="onModalRoleChange(this.value)" class="form-control" style="font-size:.88rem;padding:.65rem .85rem;width:100%;border-radius:8px;border:1.5px solid rgba(245,158,11,0.4);background:#0f172a;color:#ffffff;font-weight:700">
                <option value="clerk" style="background:#0f172a;color:#ffffff">Floor Clerk</option>
                <option value="manager" style="background:#0f172a;color:#ffffff">Floor Manager</option>
                <option value="accounts" style="background:#0f172a;color:#ffffff">Accounts Officer</option>
                <option value="master" style="background:#0f172a;color:#ffffff">Master User (All Floors Access)</option>
                <option value="admin" style="background:#0f172a;color:#ffffff">Administrator</option>
              </select>
            </div>
            <div>
              <label style="font-size:.75rem;color:#cbd5e1;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em">Floor Dedication</label>
              <select name="selling_pointid" id="modal-sp-select" class="form-control" style="font-size:.88rem;padding:.65rem .85rem;width:100%;border-radius:8px;border:1.5px solid rgba(245,158,11,0.4);background:#0f172a;color:#ffffff;font-weight:700">
                <option value="0" style="background:#0f172a;color:#ffffff">🌐 All Floors (Master)</option>
                <?php foreach ($floors as $fl_item): ?>
                <option value="<?= $fl_item['id'] ?>" style="background:#0f172a;color:#ffffff">🔒 Floor: <?= htmlspecialchars($fl_item['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:1.5rem">
            <label style="font-size:.75rem;color:#cbd5e1;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em" id="modal-label-password">Initial Password</label>
            <input type="text" name="password" id="modal-input-password" class="form-control" value="" placeholder="Leave blank to generate one" style="font-size:.9rem;padding:.65rem .85rem;width:100%;border-radius:8px;border:1.5px solid rgba(245,158,11,0.4);background:#0f172a;color:#ffffff;font-weight:600">
          </div>

          <div style="display:flex;gap:12px;justify-content:flex-end">
            <button type="button" onclick="closeMasterUserModal()" style="padding:.7rem 1.4rem;background:rgba(255,255,255,0.12);color:#ffffff !important;border:1px solid rgba(255,255,255,0.2);border-radius:8px;cursor:pointer;font-weight:700;font-size:.88rem">Cancel</button>
            <button type="submit" id="modal-btn-submit" style="padding:.7rem 1.6rem;background:linear-gradient(135deg, #10b981 0%, #059669 100%);color:#ffffff !important;border:none;border-radius:8px;font-weight:800;font-size:.92rem;box-shadow:0 4px 18px rgba(16,185,129,0.45);cursor:pointer;text-shadow:0 1px 2px rgba(0,0,0,0.5)">Create User &rarr;</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- User info bar -->
    <div class="user-info">
      <div class="user-avatar"><?=strtoupper(substr($_SESSION['username']??'?',0,1))?></div>
      <div>
        Signed in as <strong><?=htmlspecialchars($_SESSION['username']??'')?></strong>
        <?php if(is_master_user()):?>
        <span style="font-size:.68rem;background:linear-gradient(135deg,#f59e0b,#d97706);color:#000000;font-weight:800;padding:3px 10px;border-radius:12px;margin-left:6px;letter-spacing:.04em">👑 MASTER USER</span>
        <?php elseif($_SESSION['is_admin']??0):?>
        <span style="font-size:.68rem;background:#0284c7;color:#fff;font-weight:700;padding:2px 8px;border-radius:10px;margin-left:6px">ADMIN</span>
        <?php endif;?>
      </div>
      <a href="logout.php" class="logout-link">&larr; Sign out</a>
    </div>

    <form method="POST" id="floor-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="selling_pointid" id="chosen-spid" value="">

      <div class="floor-grid">
        <?php foreach ($floors as $f): ?>
        <div class="floor-card" onclick="selectFloor(<?=(int)$f['id']?>, this)">
          <div class="floor-icon">🏛</div>
          <div>
            <div class="floor-name"><?=htmlspecialchars($f['name'])?></div>
            <?php if(!empty($f['floor_code'])):?>
            <div class="floor-code"><?=htmlspecialchars($f['floor_code'])?></div>
            <?php endif;?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <button type="submit" id="floor-btn" class="btn floor-submit" style="display:none">
        Enter Floor &rarr;
      </button>
    </form>

    <div class="footer">Click a floor above to continue &middot; Flowork &copy; <?=date('Y')?></div>
  </div>
</div>

<script>
var ALL_SYS_USERS = <?= json_encode($all_sys_users) ?>;

function openMasterUserModal(mode) {
    var modal = document.getElementById('master-user-modal-overlay');
    if (modal) { modal.style.display = 'flex'; }
    setModalMode(mode || 'create');
}
function closeMasterUserModal() {
    var modal = document.getElementById('master-user-modal-overlay');
    if (modal) { modal.style.display = 'none'; }
}

function setModalMode(mode) {
    var btnCreate = document.getElementById('modal-tab-btn-create');
    var btnEdit   = document.getElementById('modal-tab-btn-edit');
    var pickerWrap= document.getElementById('modal-user-picker-wrapper');
    var editId    = document.getElementById('modal-edit-userid');
    var titleText = document.getElementById('modal-title-text');
    var passLabel = document.getElementById('modal-label-password');
    var passInput = document.getElementById('modal-input-password');
    var btnSubmit = document.getElementById('modal-btn-submit');

    if (mode === 'edit') {
        if(btnCreate) { btnCreate.style.background='rgba(30,41,59,0.7)'; btnCreate.style.color='#ffffff'; btnCreate.style.border='1.5px solid rgba(255,255,255,0.15)'; btnCreate.style.boxShadow='none'; }
        if(btnEdit)   { btnEdit.style.background='linear-gradient(135deg, #f59e0b, #d97706)'; btnEdit.style.color='#000000'; btnEdit.style.border='1.5px solid #fbbf24'; btnEdit.style.boxShadow='0 4px 14px rgba(245,158,11,0.45)'; }
        if(pickerWrap) pickerWrap.style.display = 'block';
        if(titleText)  titleText.textContent = '✏ Edit Existing User';
        if(passLabel)  passLabel.textContent = 'New Password (leave blank = unchanged)';
        if(passInput) { passInput.value = ''; passInput.placeholder = 'Leave blank to keep current'; }
        if(btnSubmit)  btnSubmit.textContent = 'Update User →';
    } else {
        if(btnCreate) { btnCreate.style.background='linear-gradient(135deg, #f59e0b, #d97706)'; btnCreate.style.color='#000000'; btnCreate.style.border='1.5px solid #fbbf24'; btnCreate.style.boxShadow='0 4px 14px rgba(245,158,11,0.45)'; }
        if(btnEdit)   { btnEdit.style.background='rgba(30,41,59,0.7)'; btnEdit.style.color='#ffffff'; btnEdit.style.border='1.5px solid rgba(255,255,255,0.15)'; btnEdit.style.boxShadow='none'; }
        if(pickerWrap) pickerWrap.style.display = 'none';
        if(editId)     editId.value = '0';
        if(titleText)  titleText.textContent = '➕ Create New User';
        if(passLabel)  passLabel.textContent = 'Initial Password';
        if(passInput) { passInput.value = ''; passInput.placeholder = 'Leave blank to generate one'; }
        if(btnSubmit)  btnSubmit.textContent = 'Create User →';

        document.getElementById('modal-input-username').value = '';
        document.getElementById('modal-input-fullname').value = '';
        document.getElementById('modal-role-select').value = 'clerk';
        document.getElementById('modal-sp-select').value = '0';
    }
}

function onSelectUserToEdit(uidStr) {
    var uid = parseInt(uidStr || 0);
    if (!uid) return;
    var user = ALL_SYS_USERS.find(function(u){ return parseInt(u.id) === uid; });
    if (!user) return;

    document.getElementById('modal-edit-userid').value   = user.id;
    document.getElementById('modal-input-username').value = user.username || '';
    document.getElementById('modal-input-fullname').value = user.full_name || '';
    document.getElementById('modal-role-select').value   = user.role || 'clerk';
    document.getElementById('modal-sp-select').value     = user.selling_pointid || 0;
    document.getElementById('modal-input-password').value = '';
}

function onModalRoleChange(role) {
    var spSelect = document.getElementById('modal-sp-select');
    if (spSelect && (role === 'master' || role === 'admin')) {
        spSelect.value = '0';
    }
}

// Close modal on Escape key or clicking backdrop
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeMasterUserModal();
});
document.addEventListener('click', function(e) {
    var modal = document.getElementById('master-user-modal-overlay');
    if (modal && e.target === modal) closeMasterUserModal();
});

function selectFloor(id, card) {
    document.querySelectorAll('.floor-card').forEach(function(c){c.classList.remove('selected');});
    card.classList.add('selected');
    document.getElementById('chosen-spid').value = id;
    // Auto-submit after brief visual confirmation
    setTimeout(function(){
        document.getElementById('floor-form').submit();
    }, 300);
}
</script>

<?php elseif ($step === 'activate_license'): ?>
<!-- ════════════════════════════════════════════════════ ACTIVATE LICENSE ═══ -->
<div class="box box-login">
  <div class="bh" style="background: linear-gradient(135deg, #1a1208, #2a0b0b);">
    <div class="bh-icon" style="background: #c0392b;">🔑</div>
    <h1>LICENSE REQUIRED</h1>
    <p>Activate subscription for <?= htmlspecialchars($target_floor_name) ?></p>
  </div>
  <div class="bb">
    <?php if ($err): ?>
    <div class="err"><?=htmlspecialchars($err)?></div>
    <?php endif; ?>

    <p style="font-size:0.8rem; color:#666; margin-bottom:1.2rem; text-align:center; line-height:1.4">
      The software license for this auction floor has expired or is currently inactive. Please input a valid platform license key to reactivate.
    </p>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="activate_spid" value="<?= (int)$target_floor_id ?>">
      <div class="form-group">
        <label>Platform License Key</label>
        <input type="text" name="license_key" required placeholder="FL-XXXX-XXXX-XXXX" 
               style="text-align:center; letter-spacing:0.1em; font-family:monospace" autofocus autocomplete="off">
      </div>
      <button type="submit" class="btn" style="background:#3d6b35">Activate License &rarr;</button>
    </form>
    
    <div style="text-align:center; margin-top: 1rem;">
      <a href="login.php" style="font-size:0.75rem; color:#c8952a; text-decoration:none;">&larr; Back to login</a>
    </div>

    <div class="footer">Flowork &copy; <?=date('Y')?> &middot; Core Africa Group</div>
  </div>
</div>
<?php endif; ?>

</body>
</html>
