<?php
/**
 * buyer_login.php — Standalone Buyer Portal Login
 * Separate session/auth from the main Flowork staff system.
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/conn.php";

// ── Auto-create table if missing ──────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS buyer_portal_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        buyerid INT NOT NULL,
        username VARCHAR(60) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        email VARCHAR(120) DEFAULT '',
        last_login TIMESTAMP NULL DEFAULT NULL,
        active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_buyer(buyerid)
    )
");

// ── Logout ────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    $_SESSION['buyer_portal_user'] = null;
    unset($_SESSION['buyer_portal_user']);
    session_destroy();
    header('Location: buyer_login.php');
    exit;
}

// ── Already logged in ─────────────────────────────────────────────────────
if (!empty($_SESSION['buyer_portal_user'])) {
    header('Location: buyer_portal.php');
    exit;
}

$err = '';

// ── Handle login POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = $conn->real_escape_string(trim($_POST['username'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    $q    = $conn->query("SELECT * FROM buyer_portal_users WHERE username='$username' AND active=1 LIMIT 1");
    $user = ($q && $q !== true) ? $q->fetch_assoc() : null;

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $err = "Invalid username or password.";
    } else {
        // Update last_login
        $uid = (int)$user['id'];
        $conn->query("UPDATE buyer_portal_users SET last_login=NOW() WHERE id=$uid");

        $_SESSION['buyer_portal_user'] = [
            'id'       => $uid,
            'buyerid'  => (int)$user['buyerid'],
            'username' => $user['username'],
            'email'    => $user['email'],
        ];

        header('Location: buyer_portal.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Buyer Portal — Flowork</title>
<meta name="description" content="Flowork Buyer Portal — view your tobacco purchase history and grade breakdowns.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --ink:    #1a1208;
    --gold:   #c8952a;
    --gold-l: #e8b84b;
    --gold-d: #92650e;
    --cream:  #f5f0e8;
    --muted:  rgba(245,240,232,0.45);
    --leaf:   #3d6b35;
    --glass:  rgba(255,255,255,0.04);
    --glass-b:rgba(200,149,42,0.12);
}

html { scroll-behavior: smooth; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--ink);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    position: relative;
    overflow: hidden;
}

/* ── Ambient glow background ──────────────────────────────────────────── */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse 700px 500px at 20% 20%, rgba(200,149,42,0.07) 0%, transparent 70%),
        radial-gradient(ellipse 500px 700px at 85% 75%, rgba(61,107,53,0.06) 0%, transparent 65%),
        radial-gradient(ellipse 300px 300px at 60% 10%, rgba(200,149,42,0.04) 0%, transparent 60%);
    pointer-events: none;
    z-index: 0;
}

/* ── Watermark ────────────────────────────────────────────────────────── */
.watermark {
    position: fixed;
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(100px, 20vw, 300px);
    color: rgba(200,149,42,0.025);
    white-space: nowrap;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    pointer-events: none;
    letter-spacing: 0.08em;
    user-select: none;
    z-index: 0;
}

/* ── Login card ───────────────────────────────────────────────────────── */
.card {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 420px;
    background: rgba(255,255,255,0.035);
    border: 1px solid rgba(200,149,42,0.2);
    border-radius: 16px;
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    box-shadow:
        0 32px 80px rgba(0,0,0,0.6),
        0 0 0 1px rgba(255,255,255,0.04) inset,
        0 1px 0 rgba(200,149,42,0.15) inset;
    overflow: hidden;
}

/* ── Card header / brand ──────────────────────────────────────────────── */
.card-head {
    padding: 2.25rem 2rem 1.75rem;
    text-align: center;
    border-bottom: 1px solid rgba(200,149,42,0.12);
    background: linear-gradient(180deg, rgba(200,149,42,0.06) 0%, transparent 100%);
}

.brand-icon {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, var(--gold), var(--gold-d));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.1rem;
    box-shadow: 0 8px 24px rgba(200,149,42,0.35);
}

.brand-icon svg { width: 26px; height: 26px; }

.brand-name {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 2.2rem;
    letter-spacing: 0.14em;
    color: var(--cream);
    line-height: 1;
    margin-bottom: 0.3rem;
}

.brand-sub {
    font-size: 0.72rem;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--gold);
    font-weight: 500;
}

/* ── Card body ────────────────────────────────────────────────────────── */
.card-body { padding: 1.75rem 2rem 2rem; }

/* ── Error ────────────────────────────────────────────────────────────── */
.err {
    background: rgba(198,40,40,0.12);
    border: 1px solid rgba(198,40,40,0.3);
    color: #ff8a80;
    padding: 0.7rem 1rem;
    border-radius: 8px;
    font-size: 0.82rem;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* ── Form ─────────────────────────────────────────────────────────────── */
.form-group { margin-bottom: 1.1rem; }

label {
    display: block;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: rgba(245,240,232,0.5);
    margin-bottom: 0.4rem;
}

.input-wrap { position: relative; }

.input-icon {
    position: absolute;
    left: 0.9rem;
    top: 50%;
    transform: translateY(-50%);
    color: rgba(200,149,42,0.5);
    pointer-events: none;
    display: flex;
    align-items: center;
}

input[type=text],
input[type=password] {
    width: 100%;
    padding: 0.8rem 0.9rem 0.8rem 2.6rem;
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(200,149,42,0.2);
    border-radius: 9px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.92rem;
    color: var(--cream);
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
}

input[type=text]::placeholder,
input[type=password]::placeholder { color: rgba(245,240,232,0.2); }

input[type=text]:focus,
input[type=password]:focus {
    border-color: var(--gold);
    background: rgba(200,149,42,0.06);
    box-shadow: 0 0 0 3px rgba(200,149,42,0.15);
}

/* ── Login button ─────────────────────────────────────────────────────── */
.btn-login {
    width: 100%;
    padding: 0.9rem;
    background: linear-gradient(135deg, var(--gold), var(--gold-d));
    color: var(--ink);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.92rem;
    font-weight: 600;
    border: none;
    border-radius: 9px;
    cursor: pointer;
    letter-spacing: 0.04em;
    margin-top: 0.5rem;
    transition: filter 0.2s, transform 0.15s, box-shadow 0.2s;
    box-shadow: 0 4px 20px rgba(200,149,42,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.btn-login:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
    box-shadow: 0 8px 28px rgba(200,149,42,0.4);
}

.btn-login:active { transform: translateY(0); }

/* ── Footer note ──────────────────────────────────────────────────────── */
.card-foot {
    padding: 1rem 2rem 1.5rem;
    text-align: center;
    border-top: 1px solid rgba(200,149,42,0.08);
}

.access-note {
    font-size: 0.73rem;
    color: rgba(245,240,232,0.3);
    line-height: 1.5;
}

.access-note span {
    color: rgba(200,149,42,0.6);
}

/* ── Bottom brand ─────────────────────────────────────────────────────── */
.page-foot {
    position: fixed;
    bottom: 1.25rem;
    left: 0; right: 0;
    text-align: center;
    font-size: 0.65rem;
    color: rgba(245,240,232,0.15);
    letter-spacing: 0.1em;
    z-index: 1;
}

/* ── Animated entrance ────────────────────────────────────────────────── */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}

.card { animation: fadeUp 0.45s ease both; }

/* ── Responsive ───────────────────────────────────────────────────────── */
@media (max-width: 480px) {
    .card-head { padding: 1.75rem 1.5rem 1.5rem; }
    .card-body  { padding: 1.5rem 1.5rem 1.75rem; }
    .card-foot  { padding: 0.85rem 1.5rem 1.25rem; }
}
</style>
</head>
<body>

<div class="watermark">FLOWORK</div>

<div class="card" role="main">
  <!-- Brand header -->
  <div class="card-head">
    <div class="brand-icon" aria-hidden="true">
      <svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M14 3C10 3 7 6 7 10c0 2.8 1.6 5 4 6.3L14 24l3-7.7C19.4 15 21 12.8 21 10c0-4-3-7-7-7z" fill="#1a1208" opacity="0.9"/>
        <path d="M14 6c-2.2 0-4 1.8-4 4s1.8 4 4 4 4-1.8 4-4-1.8-4-4-4z" fill="#1a1208" opacity="0.5"/>
      </svg>
    </div>
    <div class="brand-name">FLOWORK</div>
    <div class="brand-sub">Buyer Portal</div>
  </div>

  <!-- Login form -->
  <div class="card-body">
    <?php if ($err): ?>
    <div class="err" role="alert">
      <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="8" cy="8" r="7"/><path d="M8 5v3M8 11h.01"/></svg>
      <?= htmlspecialchars($err) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="buyer-login-form" autocomplete="on">
      <div class="form-group">
        <label for="buyer-username">Username</label>
        <div class="input-wrap">
          <span class="input-icon">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5" r="3"/><path d="M2 14c0-3.3 2.7-6 6-6s6 2.7 6 6"/></svg>
          </span>
          <input type="text" id="buyer-username" name="username"
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                 autofocus autocomplete="username" required
                 placeholder="Enter your username">
        </div>
      </div>

      <div class="form-group">
        <label for="buyer-password">Password</label>
        <div class="input-wrap">
          <span class="input-icon">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="7" width="10" height="7" rx="1"/><path d="M5 7V5a3 3 0 0 1 6 0v2"/></svg>
          </span>
          <input type="password" id="buyer-password" name="password"
                 autocomplete="current-password" required
                 placeholder="••••••••">
        </div>
      </div>

      <button type="submit" class="btn-login" id="buyer-login-btn">
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 2h4v12h-4M6 11l4-3-4-3"/></svg>
        Sign In to Portal
      </button>
    </form>
  </div>

  <!-- Access note footer -->
  <div class="card-foot">
    <p class="access-note">
      Don't have access? <span>Contact your floor administrator</span><br>
      to request buyer portal credentials.
    </p>
  </div>
</div>

<div class="page-foot">
  FLOWORK &copy; <?= date('Y') ?> &middot; Core Africa Group &middot; Buyer Portal
</div>

<script>
// Subtle loading state on form submit
document.getElementById('buyer-login-form').addEventListener('submit', function() {
    var btn = document.getElementById('buyer-login-btn');
    btn.innerHTML = '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin .7s linear infinite"><path d="M8 2a6 6 0 1 0 6 6"/></svg> Signing in…';
    btn.disabled = true;
    btn.style.opacity = '0.75';
});

// Add spin keyframes dynamically
var style = document.createElement('style');
style.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
document.head.appendChild(style);
</script>
</body>
</html>
