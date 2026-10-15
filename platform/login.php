<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . "/session.php"; // own session, separate from the floor app
require_once __DIR__ . "/../includes/conn.php";
require_once __DIR__ . "/../includes/auth.php"; // csrf_field() / verify_csrf_token()
require_once __DIR__ . "/../includes/rate_limiter.php";

// Handle Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['flowork_manager_id']);
    unset($_SESSION['flowork_manager_user']);
    header("Location: login.php");
    exit;
}

// Redirect if already logged in
if (!empty($_SESSION['flowork_manager_id'])) {
    header("Location: index.php");
    exit;
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token verification failed.");
    }
    $username = $conn->real_escape_string(trim($_POST['username'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    if (!check_rate_limit($conn, 'manager_login', 5, 900)) {
        $err = "Too many login attempts. Please try again in 15 minutes.";
    } elseif (empty($username) || empty($password)) {
        $err = "Please enter both username and password.";
    } else {
        $q = $conn->query("SELECT * FROM flowork_system_admins WHERE username='$username' LIMIT 1");
        if ($q && $q->num_rows > 0) {
            $row = $q->fetch_assoc();
            if (password_verify($password, $row['password_hash']) && $password === 'flowork_dev_2026') {
                // The old seeded password is public (it was in the source). Refuse it
                // until setup_database.php has rotated it to a per-install value.
                $err = "This account still uses the old default password. Run setup_database.php to generate a new one.";
            } elseif (password_verify($password, $row['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['flowork_manager_id'] = (int)$row['id'];
                $_SESSION['flowork_manager_user'] = $row['username'];
                header("Location: index.php");
                exit;
            }
        }
        if (!$err) $err = "Invalid platform credentials.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Flowork Platform Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'DM Sans', sans-serif;
    background: #0f0a05;
    background-image: radial-gradient(circle at 50% 50%, #20150b 0%, #0a0603 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #f5f0e8;
    padding: 1.5rem;
}
.login-card {
    background: rgba(28, 19, 10, 0.45);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(200, 149, 42, 0.25);
    border-radius: 16px;
    width: 100%;
    max-width: 420px;
    padding: 3rem 2.5rem;
    box-shadow: 0 24px 80px rgba(0,0,0,0.8);
    position: relative;
    overflow: hidden;
}
.login-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #c8952a, #3d6b35);
}
.header {
    text-align: center;
    margin-bottom: 2.5rem;
}
.logo {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 3rem;
    color: #f5f0e8;
    letter-spacing: 0.12em;
    line-height: 1;
    margin-bottom: 0.2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.logo span {
    width: 10px;
    height: 10px;
    background: #c8952a;
    border-radius: 50%;
    display: inline-block;
}
.subtitle {
    font-size: 0.75rem;
    color: rgba(245, 240, 232, 0.45);
    text-transform: uppercase;
    letter-spacing: 0.2em;
    font-weight: 500;
}
.form-group {
    margin-bottom: 1.5rem;
}
label {
    display: block;
    font-size: 0.68rem;
    font-weight: 700;
    color: #c8952a;
    margin-bottom: 0.5rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.input-control {
    width: 100%;
    padding: 0.85rem 1rem;
    background: rgba(10, 6, 3, 0.7);
    border: 1px solid rgba(200, 149, 42, 0.2);
    border-radius: 8px;
    font-size: 0.95rem;
    color: #f5f0e8;
    outline: none;
    transition: all 0.2s ease;
    font-family: inherit;
}
.input-control:focus {
    border-color: #c8952a;
    box-shadow: 0 0 0 3px rgba(200,149,42,0.15);
    background: rgba(10, 6, 3, 0.9);
}
.btn {
    width: 100%;
    padding: 1rem;
    background: #c8952a;
    color: #0f0a05;
    border: none;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    transition: all 0.2s ease;
    margin-top: 0.5rem;
}
.btn:hover {
    background: #e8b84b;
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(200, 149, 42, 0.25);
}
.btn:active {
    transform: translateY(0);
}
.error-box {
    background: rgba(192, 57, 43, 0.15);
    border: 1px solid rgba(192, 57, 43, 0.4);
    border-left: 4px solid #c0392b;
    color: #f2a1a1;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    font-size: 0.82rem;
    margin-bottom: 1.5rem;
    line-height: 1.4;
}
.footer {
    text-align: center;
    font-size: 0.68rem;
    color: rgba(245, 240, 232, 0.3);
    margin-top: 2rem;
    letter-spacing: 0.05em;
}
</style>
</head>
<body>

<div class="login-card">
    <div class="header">
        <div class="logo"><span></span>FLOWORK</div>
        <div class="subtitle">Platform Manager</div>
    </div>

    <?php if (!empty($err)): ?>
        <div class="error-box"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off"><?= csrf_field() ?>
        <div class="form-group">
            <label for="username">System Username</label>
            <input type="text" name="username" id="username" class="input-control" required placeholder="developer" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="password">System Password</label>
            <input type="password" name="password" id="password" class="input-control" required placeholder="••••••••">
        </div>
        <button type="submit" class="btn">Authenticate Portal</button>
    </form>

    <div class="footer">
        &copy; <?= date('Y') ?> Flowork Inc. &middot; System Developer Console
    </div>
</div>

</body>
</html>
