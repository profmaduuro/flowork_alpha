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
            $fa_enabled = ($fa_q && $fa_q->num_rows > 0) && flowork_2fa_system_on($conn);
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
<?php
// Theme: the user's saved choice (same cookie as the app), else the device setting.
$lg_theme = $_COOKIE['flowork_theme'] ?? '';
$lg_theme = in_array($lg_theme, ['light', 'dark'], true) ? $lg_theme : '';
$lg_title = $step === 'choose_floor' ? 'Choose floor' : ($step === 'activate_license' ? 'Activate license' : 'Sign in');
?>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $lg_title ?> — Flowork</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="includes/layout.css?v=9">
<link rel="preload" as="image" href="images/login-tobacco-bales.jpg">
<style>
/* Sign-in pages: brand panel + form card. Colours come from the shared tokens
   in includes/layout.css, so light and dark mode match the rest of the app. */
html,body{height:auto;min-height:100%}
body{display:flex;min-height:100vh;background:var(--page);color:var(--text)}
.lg-shell{flex:1;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);min-height:100vh}

/* Brand panel: bales of cured tobacco on the auction floor
   (images/login-tobacco-bales.jpg) under a dark shade so the white text
   stays readable; the bales show through above the text. */
.lg-brand{position:relative;background:#14110D url('images/login-tobacco-bales.jpg') 55% center/cover no-repeat;color:#F4EFE6;padding:2.5rem 3rem;display:flex;flex-direction:column;justify-content:space-between;overflow:hidden}
.lg-brand::before{content:'';position:absolute;inset:0;pointer-events:none;
  background:linear-gradient(180deg,rgba(20,17,13,.72) 0%,rgba(20,17,13,.38) 22%,rgba(20,17,13,.42) 42%,rgba(20,17,13,.82) 64%,rgba(20,17,13,.95) 100%)}
.lg-brand > *{position:relative}
.lg-mark{display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit}
.lg-mark .dot{width:40px;height:40px;border-radius:10px;background:#2A231A;display:flex;align-items:center;justify-content:center;border:1px solid rgba(226,176,74,.25)}
.lg-mark .dot svg{width:21px;height:21px}
.lg-mark span{font-family:'Bebas Neue',sans-serif;font-size:1.9rem;letter-spacing:.12em;line-height:1}
.lg-hero{margin-top:auto;margin-bottom:2rem}
.lg-hero h1{font-size:2.1rem;font-weight:600;letter-spacing:-.01em;line-height:1.2;max-width:440px;text-shadow:0 1px 12px rgba(0,0,0,.35)}
.lg-hero p{margin-top:.8rem;color:#D8CFC0;font-size:.98rem;line-height:1.6;max-width:440px}
.lg-points{list-style:none;margin-top:1.75rem;display:flex;flex-direction:column;gap:.7rem}
.lg-points li{display:flex;align-items:center;gap:.65rem;color:#CFC6B6;font-size:.9rem}
.lg-points svg{width:18px;height:18px;color:#E2B04A;flex-shrink:0}
.lg-brand-foot{display:flex;justify-content:space-between;gap:1rem;font-size:.78rem;color:#B5AA98}
.lg-brand-foot a{color:inherit}

/* Form side */
.lg-side{display:flex;align-items:center;justify-content:center;padding:2.5rem 1.5rem}
.lg-card{width:100%;max-width:400px}
.lg-card.wide{max-width:560px}
.lg-mobile-mark{display:none;margin-bottom:1.75rem}
.lg-mobile-mark .dot{background:#1F1A14}
body.theme-dark .lg-mobile-mark .dot{background:#2A231A}
.lg-mobile-mark span{color:var(--text)}
.lg-head h2{font-size:1.6rem;font-weight:600;letter-spacing:-.01em;line-height:1.25}
.lg-head p{margin-top:.35rem;color:var(--text-muted);font-size:.92rem}
.lg-body{margin-top:1.6rem}
.lg-field{margin-bottom:1rem}
.lg-field label{display:block;font-size:.84rem;font-weight:500;color:var(--text-soft);margin-bottom:.4rem}
.lg-input{position:relative}
.lg-input > svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:17px;height:17px;color:var(--text-muted);pointer-events:none}
.lg-input .form-control{min-height:44px;padding-left:2.4rem;font-size:.92rem}
.lg-input.has-toggle .form-control{padding-right:3rem}
.lg-eye{position:absolute;right:4px;top:50%;transform:translateY(-50%);width:38px;height:38px;border:none;background:transparent;color:var(--text-muted);border-radius:var(--radius);cursor:pointer;display:flex;align-items:center;justify-content:center}
.lg-eye:hover{color:var(--text);background:var(--surface-3)}
.lg-eye svg{width:18px;height:18px}
.lg-caps{display:none;margin-top:.4rem;font-size:.8rem;color:var(--warn-text)}
.lg-caps.on{display:flex;align-items:center;gap:.35rem}
.lg-submit{width:100%;min-height:46px;font-size:.95rem;font-weight:600;margin-top:.4rem}
.lg-submit .fw-spinner{border-color:rgba(255,255,255,.35);border-top-color:#fff}
body.theme-dark .lg-submit .fw-spinner{border-color:rgba(20,17,13,.3);border-top-color:#14110D}
.lg-alert{display:flex;gap:.55rem;align-items:flex-start}
.lg-alert svg{width:18px;height:18px;flex-shrink:0;margin-top:1px}
.lg-foot{margin-top:2rem;font-size:.8rem;color:var(--text-muted);text-align:center}
.lg-link{color:var(--accent-text);font-weight:500;text-decoration:none}
.lg-link:hover{text-decoration:underline}

/* Choose floor */
.lg-user{display:flex;align-items:center;gap:.7rem;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:.65rem .85rem;margin-bottom:1rem;font-size:.88rem;color:var(--text-muted)}
.lg-user strong{color:var(--text);font-weight:600}
.lg-avatar{width:34px;height:34px;border-radius:50%;background:var(--accent-tint);color:var(--accent-text);display:flex;align-items:center;justify-content:center;font-weight:600;flex-shrink:0;text-transform:uppercase}
.lg-user .badge{margin-left:.35rem}
.lg-user .btn{margin-left:auto}
.lg-master{display:flex;gap:.7rem;align-items:flex-start;background:var(--accent-tint);color:var(--text);border-radius:var(--radius-lg);padding:.8rem .95rem;margin-bottom:1rem;font-size:.86rem}
.lg-master svg{width:20px;height:20px;color:var(--accent-text);flex-shrink:0;margin-top:1px}
.lg-master strong{display:block;font-weight:600}
.lg-master span{color:var(--text-soft);font-size:.8rem}
.lg-master-actions{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:1.25rem}
.lg-master-actions .btn{min-height:42px}
.floor-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.7rem}
.floor-card{display:flex;flex-direction:column;align-items:flex-start;gap:.55rem;text-align:left;padding:1rem;min-height:110px;border-radius:var(--radius-lg);border:1px solid var(--line);background:var(--surface);color:var(--text);cursor:pointer;font:inherit;box-shadow:var(--shadow-sm);transition:border-color .15s,background .15s,box-shadow .15s}
.floor-card:hover{border-color:var(--accent);box-shadow:var(--shadow-md)}
.floor-card.selected{border-color:var(--ok-text);background:var(--ok-tint)}
.floor-icon{width:36px;height:36px;border-radius:10px;background:var(--accent-tint);color:var(--accent-text);display:flex;align-items:center;justify-content:center}
.floor-icon svg{width:19px;height:19px}
.floor-card.selected .floor-icon{background:var(--surface);color:var(--ok-text)}
.floor-name{font-size:.92rem;font-weight:600;line-height:1.3}
.floor-code{display:inline-block;margin-top:.2rem;font-size:.75rem;font-weight:600;color:var(--accent-text);background:var(--accent-tint);padding:1px 7px;border-radius:999px;font-variant-numeric:tabular-nums}
.floor-submit{display:none}
.lg-success{display:flex;gap:.5rem;align-items:center}

/* Master user modal */
#master-user-modal-overlay{position:fixed;inset:0;background:rgba(20,17,13,.55);backdrop-filter:blur(3px);z-index:99999;align-items:center;justify-content:center;padding:1rem}
.lg-modal{width:100%;max-width:520px;max-height:calc(100vh - 2rem);overflow-y:auto;background:var(--surface);color:var(--text);border:1px solid var(--line);border-radius:var(--radius-lg);box-shadow:var(--shadow-md)}
.lg-modal-head{display:flex;align-items:center;gap:.75rem;padding:1rem 1.25rem;border-bottom:1px solid var(--line)}
.lg-modal-head h3{font-size:1.05rem;font-weight:600}
.lg-modal-head p{font-size:.8rem;color:var(--text-muted);margin-top:2px}
.lg-modal-head .btn{margin-left:auto}
.lg-modal-body{padding:1.1rem 1.25rem 1.25rem}
.lg-seg{display:flex;padding:3px;border-radius:10px;background:var(--surface-3);border:1px solid var(--line);margin-bottom:1.1rem}
.lg-seg button{flex:1;height:36px;border:none;border-radius:7px;background:transparent;color:var(--text-soft);font:500 .86rem 'DM Sans',sans-serif;cursor:pointer}
.lg-seg button.on{background:var(--surface);color:var(--text);font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,.1)}
body.theme-dark .lg-seg button.on{background:var(--line-strong)}
.lg-grid2{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.lg-modal-foot{display:flex;gap:.6rem;justify-content:flex-end;margin-top:1.25rem}

/* Narrow screens: no brand panel, the card carries the logo */
@media(max-width:900px){
  .lg-shell{grid-template-columns:1fr}
  .lg-brand{display:none}
  .lg-mobile-mark{display:flex}
  /* the field photo becomes the page background; the form sits on a card */
  .lg-side{align-items:center;padding:2rem 1rem;
    background:linear-gradient(180deg,rgba(20,17,13,.25),rgba(20,17,13,.7)),#14110D url('images/login-tobacco-bales.jpg') center/cover no-repeat}
  .lg-card{background:var(--surface);padding:1.6rem 1.35rem;border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.35)}
}

@media(max-width:480px){
  .lg-master-actions,.lg-grid2{grid-template-columns:1fr}
  .floor-grid{grid-template-columns:1fr 1fr}
  .lg-head h2{font-size:1.4rem}
}
</style>
</head>
<body class="<?= $lg_theme ? 'theme-' . $lg_theme : '' ?>">
<?php if (!$lg_theme): ?>
<script>
// No saved choice: follow the device's light/dark setting
if (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) document.body.classList.add('theme-dark');
</script>
<?php endif; ?>
<?php
$lg_leaf = '<svg viewBox="0 0 24 24" fill="none" stroke="#E2B04A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 20A7 7 0 0 1 4 13c0-5 4-9 16-9 0 12-4 16-9 16z"/><path d="M4 20c4-4 7-6 11-8"/></svg>';
$lg_ic = function ($n) {
    $p = [
        'user'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>',
        'lock'  => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'eye'   => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'alert' => '<path d="M12 3 2 21h20z"/><path d="M12 10v5M12 18h.01"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'scale' => '<path d="M12 3v18M6 21h12M5 7h14"/><path d="m5 7-3 7a3 3 0 0 0 6 0zM19 7l-3 7a3 3 0 0 0 6 0z"/>',
        'floor' => '<path d="M3 21h18M5 21V8l7-5 7 5v13"/><path d="M9 21v-6h6v6"/>',
        'crown' => '<path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5z"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 13.7c2 .9 3.5 3 3.5 6.3"/>',
        'plus'  => '<path d="M12 5v14M5 12h14"/>',
        'key'   => '<circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 9.2-9.2M17 6l3 3M14 9l2 2"/>',
        'x'     => '<path d="M18 6 6 18M6 6l12 12"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'shield'=> '<path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
    ][$n] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
?>
<div class="lg-shell">
  <aside class="lg-brand" aria-hidden="true">
    <div class="lg-mark"><span class="dot"><?= $lg_leaf ?></span><span>Flowork</span></div>
    <div class="lg-hero">
      <h1>Tobacco floor management, from booking to payment.</h1>
      <p>Book growers, weigh and sell bales, balance deliveries and pay growers, all on one floor system.</p>
      <ul class="lg-points">
        <li><?= $lg_ic('scale') ?> Weighing, ticketing and selling on the floor</li>
        <li><?= $lg_ic('chart') ?> Balancing, stop orders and grower payments</li>
        <li><?= $lg_ic('shield') ?> Per-floor access with two-factor sign-in</li>
      </ul>
    </div>
    <div class="lg-brand-foot"><span>© <?= date('Y') ?> Core Africa Group</span></div>
  </aside>

  <main class="lg-side">
<?php if ($step === 'login'): ?>
    <div class="lg-card">
      <div class="lg-mark lg-mobile-mark"><span class="dot"><?= $lg_leaf ?></span><span>Flowork</span></div>
      <div class="lg-head">
        <h2>Sign in</h2>
        <p>Welcome back. Enter your username and password.</p>
      </div>
      <div class="lg-body">
        <?php if ($err): ?>
        <div class="alert alert-danger lg-alert" role="alert"><?= $lg_ic('alert') ?><span><?= htmlspecialchars($err) ?></span></div>
        <?php endif; ?>

        <form method="POST" id="login-form" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <div class="lg-field">
            <label for="lg-username">Username</label>
            <div class="lg-input">
              <?= $lg_ic('user') ?>
              <input type="text" id="lg-username" name="username" class="form-control" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                     <?= empty($_POST['username']) ? 'autofocus' : '' ?> autocomplete="username" autocapitalize="none" spellcheck="false" required placeholder="Your username">
            </div>
          </div>
          <div class="lg-field">
            <label for="lg-password">Password</label>
            <div class="lg-input has-toggle">
              <?= $lg_ic('lock') ?>
              <input type="password" id="lg-password" name="password" class="form-control" autocomplete="current-password" required
                     <?= !empty($_POST['username']) ? 'autofocus' : '' ?> placeholder="Your password">
              <button type="button" class="lg-eye" id="lg-eye" aria-label="Show password" aria-pressed="false"><?= $lg_ic('eye') ?></button>
            </div>
            <div class="lg-caps" id="lg-caps" role="status"><?= $lg_ic('alert') ?> Caps Lock is on</div>
          </div>
          <button type="submit" class="btn btn-primary lg-submit" id="lg-submit">Sign in</button>
        </form>

        <p class="lg-foot">Trouble signing in? Ask your floor administrator to reset your password.</p>
      </div>
    </div>

<?php elseif ($step === 'choose_floor'): ?>
<?php $floors = $_SESSION['accessible_floors'] ?? []; ?>
    <div class="lg-card wide">
      <div class="lg-mark lg-mobile-mark"><span class="dot"><?= $lg_leaf ?></span><span>Flowork</span></div>
      <div class="lg-head">
        <h2>Choose a floor</h2>
        <p>Pick the auction floor you are working on today.</p>
      </div>
      <div class="lg-body">
        <?php if ($err): ?>
        <div class="alert alert-danger lg-alert" role="alert"><?= $lg_ic('alert') ?><span><?= htmlspecialchars($err) ?></span></div>
        <?php endif; ?>

        <div class="lg-user">
          <div class="lg-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($_SESSION['username'] ?? '?', 0, 1))) ?></div>
          <div>
            Signed in as <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong>
            <?php if (is_master_user()): ?><span class="badge badge-gold">Master user</span>
            <?php elseif ($_SESSION['is_admin'] ?? 0): ?><span class="badge badge-blue">Admin</span><?php endif; ?>
          </div>
          <a href="logout.php" class="btn btn-ghost btn-sm">Sign out</a>
        </div>

        <?php if (is_master_user()): ?>
        <div class="lg-master">
          <?= $lg_ic('crown') ?>
          <div><strong>Master user</strong><span>You can manage users and see every floor.</span></div>
        </div>

        <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success lg-success" role="status"><?= $lg_ic('check') ?> <?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>

        <div class="lg-master-actions">
          <button type="button" class="btn btn-ghost" onclick="openMasterUserModal()"><?= $lg_ic('plus') ?> Create or edit a user</button>
          <a href="users.php" class="btn btn-ghost"><?= $lg_ic('users') ?> All users</a>
        </div>

        <!-- Master user: create / edit a user before choosing a floor -->
        <div id="master-user-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title-text"
             style="display:<?= (!empty($_POST['action']) && $_POST['action'] === 'quick_create_user' && $err) ? 'flex' : 'none' ?>">
          <div class="lg-modal">
            <div class="lg-modal-head">
              <div>
                <h3 id="modal-title-text">Create a user</h3>
                <p>Manage users before choosing a floor</p>
              </div>
              <button type="button" class="btn btn-ghost btn-sm btn-icon" onclick="closeMasterUserModal()" aria-label="Close"><?= $lg_ic('x') ?></button>
            </div>
            <div class="lg-modal-body">
              <div class="lg-seg" role="tablist" aria-label="Create or edit">
                <button type="button" id="modal-tab-btn-create" class="on" onclick="setModalMode('create')" role="tab" aria-selected="true">Create user</button>
                <button type="button" id="modal-tab-btn-edit" onclick="setModalMode('edit')" role="tab" aria-selected="false">Edit existing user</button>
              </div>

              <?php if (!empty($err) && !empty($_POST['action']) && $_POST['action'] === 'quick_create_user'): ?>
              <div class="alert alert-danger lg-alert" role="alert"><?= $lg_ic('alert') ?><span><?= htmlspecialchars($err) ?></span></div>
              <?php endif; ?>

              <form method="POST" id="modal-user-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="quick_create_user">
                <input type="hidden" name="edit_userid" id="modal-edit-userid" value="0">
                <?php
                $sp_map_login = [];
                foreach ($floors as $fl_item) { $sp_map_login[(int)$fl_item['id']] = $fl_item['name']; }
                ?>
                <div id="modal-user-picker-wrapper" class="form-group" style="display:none">
                  <label for="modal-user-picker">User to edit</label>
                  <select id="modal-user-picker" onchange="onSelectUserToEdit(this.value)" class="form-control">
                    <option value="">Choose a user…</option>
                    <?php foreach ($all_sys_users as $su):
                      $su_spid = (int)($su['selling_pointid'] ?? 0);
                      $fl_desc = (!empty($su['is_master']) || $su['role'] === 'master') ? 'Master user' : ($sp_map_login[$su_spid] ?? ($su_spid > 0 ? "Floor #$su_spid" : 'All floors'));
                    ?>
                    <option value="<?= $su['id'] ?>"><?= htmlspecialchars($su['username']) ?> (<?= htmlspecialchars($su['full_name'] ?: ucfirst($su['role'])) ?> · <?= htmlspecialchars($fl_desc) ?>)</option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label for="modal-input-username">Username</label>
                  <input type="text" name="username" id="modal-input-username" class="form-control" required placeholder="e.g. jmoyo" autocomplete="off">
                </div>
                <div class="form-group">
                  <label for="modal-input-fullname">Full name</label>
                  <input type="text" name="full_name" id="modal-input-fullname" class="form-control" placeholder="e.g. John Moyo" autocomplete="off">
                </div>
                <div class="lg-grid2">
                  <div class="form-group">
                    <label for="modal-role-select">Role</label>
                    <select name="role" id="modal-role-select" onchange="onModalRoleChange(this.value)" class="form-control">
                      <option value="clerk">Floor clerk</option>
                      <option value="manager">Floor manager</option>
                      <option value="accounts">Accounts officer</option>
                      <option value="master">Master user (all floors)</option>
                      <option value="admin">Administrator</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label for="modal-sp-select">Floor</label>
                    <select name="selling_pointid" id="modal-sp-select" class="form-control">
                      <option value="0">All floors</option>
                      <?php foreach ($floors as $fl_item): ?>
                      <option value="<?= $fl_item['id'] ?>"><?= htmlspecialchars($fl_item['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="form-group">
                  <label for="modal-input-password" id="modal-label-password">Initial password</label>
                  <input type="text" name="password" id="modal-input-password" class="form-control" value="" placeholder="Leave blank to generate one" autocomplete="new-password">
                </div>
                <div class="lg-modal-foot">
                  <button type="button" class="btn btn-ghost" onclick="closeMasterUserModal()">Cancel</button>
                  <button type="submit" id="modal-btn-submit" class="btn btn-primary">Create user</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <form method="POST" id="floor-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="selling_pointid" id="chosen-spid" value="">
          <div class="floor-grid" role="list">
            <?php foreach ($floors as $f): ?>
            <button type="button" class="floor-card" role="listitem" onclick="selectFloor(<?= (int)$f['id'] ?>, this)">
              <span class="floor-icon"><?= $lg_ic('floor') ?></span>
              <span>
                <span class="floor-name"><?= htmlspecialchars($f['name']) ?></span>
                <?php if (!empty($f['floor_code'])): ?><br><span class="floor-code"><?= htmlspecialchars($f['floor_code']) ?></span><?php endif; ?>
              </span>
            </button>
            <?php endforeach; ?>
          </div>
          <button type="submit" id="floor-btn" class="btn floor-submit">Enter floor</button>
        </form>

        <p class="lg-foot">Choose a floor to continue.</p>
      </div>
    </div>

<script>
var ALL_SYS_USERS = <?= json_encode($all_sys_users ?? []) ?>;

function openMasterUserModal(mode) {
    var modal = document.getElementById('master-user-modal-overlay');
    if (modal) { modal.style.display = 'flex'; }
    setModalMode(mode || 'create');
    var u = document.getElementById('modal-input-username'); if (u) u.focus();
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
    if (!btnCreate) return;
    var edit = mode === 'edit';
    btnCreate.classList.toggle('on', !edit); btnCreate.setAttribute('aria-selected', !edit);
    btnEdit.classList.toggle('on', edit);    btnEdit.setAttribute('aria-selected', edit);
    pickerWrap.style.display = edit ? 'block' : 'none';
    titleText.textContent = edit ? 'Edit a user' : 'Create a user';
    passLabel.textContent = edit ? 'New password (leave blank to keep the current one)' : 'Initial password';
    passInput.value = '';
    passInput.placeholder = edit ? 'Leave blank to keep the current one' : 'Leave blank to generate one';
    btnSubmit.textContent = edit ? 'Update user' : 'Create user';
    if (!edit) {
        editId.value = '0';
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
    document.getElementById('modal-edit-userid').value    = user.id;
    document.getElementById('modal-input-username').value = user.username || '';
    document.getElementById('modal-input-fullname').value = user.full_name || '';
    document.getElementById('modal-role-select').value    = user.role || 'clerk';
    document.getElementById('modal-sp-select').value      = user.selling_pointid || 0;
    document.getElementById('modal-input-password').value = '';
}
function onModalRoleChange(role) {
    var spSelect = document.getElementById('modal-sp-select');
    if (spSelect && (role === 'master' || role === 'admin')) spSelect.value = '0';
}
window.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeMasterUserModal(); });
document.addEventListener('click', function(e) {
    var modal = document.getElementById('master-user-modal-overlay');
    if (modal && e.target === modal) closeMasterUserModal();
});
function selectFloor(id, card) {
    document.querySelectorAll('.floor-card').forEach(function(c){ c.classList.remove('selected'); c.disabled = true; });
    card.classList.add('selected');
    document.getElementById('chosen-spid').value = id;
    // brief visual confirmation, then continue
    setTimeout(function(){ document.getElementById('floor-form').submit(); }, 250);
}
</script>

<?php elseif ($step === 'activate_license'): ?>
    <div class="lg-card">
      <div class="lg-mark lg-mobile-mark"><span class="dot"><?= $lg_leaf ?></span><span>Flowork</span></div>
      <div class="lg-head">
        <h2>License required</h2>
        <p>Activate the subscription for <strong><?= htmlspecialchars($target_floor_name ?? '') ?></strong>.</p>
      </div>
      <div class="lg-body">
        <?php if ($err): ?>
        <div class="alert alert-danger lg-alert" role="alert"><?= $lg_ic('alert') ?><span><?= htmlspecialchars($err) ?></span></div>
        <?php endif; ?>
        <div class="alert alert-gold lg-alert"><?= $lg_ic('key') ?><span>This floor's software license has expired or is inactive. Enter a valid license key to reactivate it.</span></div>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="activate_spid" value="<?= (int)($target_floor_id ?? 0) ?>">
          <div class="lg-field">
            <label for="lg-license">License key</label>
            <div class="lg-input">
              <?= $lg_ic('key') ?>
              <input type="text" id="lg-license" name="license_key" class="form-control" required placeholder="FL-XXXX-XXXX-XXXX"
                     style="letter-spacing:.08em;font-family:ui-monospace,monospace" autofocus autocomplete="off" autocapitalize="characters" spellcheck="false">
            </div>
          </div>
          <button type="submit" class="btn btn-primary lg-submit">Activate license</button>
        </form>
        <p class="lg-foot"><a href="login.php" class="lg-link">Back to sign in</a></p>
      </div>
    </div>
<?php endif; ?>
  </main>
</div>

<script>
// Sign-in helpers: show/hide password, Caps Lock warning, one submit only
(function(){
    var pw = document.getElementById('lg-password'), eye = document.getElementById('lg-eye');
    if (pw && eye) {
        eye.addEventListener('click', function(){
            var show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            eye.setAttribute('aria-pressed', show ? 'true' : 'false');
            pw.focus();
        });
        var caps = document.getElementById('lg-caps');
        var check = function(e){ if (e.getModifierState) caps.classList.toggle('on', e.getModifierState('CapsLock')); };
        pw.addEventListener('keydown', check); pw.addEventListener('keyup', check);
        pw.addEventListener('blur', function(){ caps.classList.remove('on'); });
    }
    var form = document.getElementById('login-form'), btn = document.getElementById('lg-submit');
    if (form && btn) {
        form.addEventListener('submit', function(e){
            var u = document.getElementById('lg-username'), p = document.getElementById('lg-password');
            if (!u.value.trim()) { e.preventDefault(); u.focus(); return; }
            if (!p.value)        { e.preventDefault(); p.focus(); return; }
            if (btn.disabled)    { e.preventDefault(); return; }
            btn.disabled = true;
            btn.innerHTML = '<span class="fw-spinner" aria-hidden="true"></span> Signing in…';
        });
    }
    // Coming back with the browser's Back button: re-enable the button
    window.addEventListener('pageshow', function(){ if (btn) { btn.disabled = false; btn.textContent = 'Sign in'; } });
})();
</script>
</body>
</html>
