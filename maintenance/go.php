<?php
require_once dirname(__DIR__) . '/includes/admin_gate.php';
/**
 * go.php — Emergency setup wrapper.
 * Disables mysqli exceptions then runs all setup logic directly.
 * Use this instead of setup.php until the DB is recovered.
 */
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once dirname(__DIR__) . "/includes/password_helper.php";

// Read credentials from Docker env vars or fall back to XAMPP defaults
$_db_host = getenv('DB_HOST') ?: 'localhost';
$_db_name = getenv('DB_NAME') ?: 'king';
$_db_user = getenv('DB_USER') ?: 'root';
$_db_pass = getenv('DB_PASS') ?: '';

$conn = new mysqli($_db_host, $_db_user, $_db_pass, '');
if ($conn->connect_error) die("Cannot connect to MySQL: " . $conn->connect_error);

// Create DB if missing
$conn->query("CREATE DATABASE IF NOT EXISTS `$_db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// Explicitly select the database
$conn->select_db($_db_name);
if ($conn->error) die("Cannot select database '$_db_name': " . $conn->error);

$conn->set_charset('utf8mb4');
$conn->query("SET FOREIGN_KEY_CHECKS=0");

// Safe count helper
function safe_count($conn, $table) {
    $r = $conn->query("SELECT COUNT(*) c FROM `$table`");
    if (!$r || $r === true) return 0;
    return (int)($r->fetch_assoc()['c'] ?? 0);
}
function safe_row($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r || $r === true) return null;
    return $r->fetch_assoc();
}


// Create all core tables
$core_tables = [
"CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, active TINYINT DEFAULT 1, confirm TINYINT DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
"CREATE TABLE IF NOT EXISTS rights (id INT AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL, created_byid INT DEFAULT 0, admin TINYINT DEFAULT 0, transporter_receiving TINYINT DEFAULT 0, scale TINYINT DEFAULT 0, junus TINYINT DEFAULT 0, buyer TINYINT DEFAULT 0, balance TINYINT DEFAULT 0, salessheets TINYINT DEFAULT 0, accounts TINYINT DEFAULT 0, transporter_payments TINYINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(userid))",
"CREATE TABLE IF NOT EXISTS selling_point (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, floor_code VARCHAR(20) DEFAULT '', floor_id VARCHAR(20) DEFAULT '', active TINYINT DEFAULT 1)",
"CREATE TABLE IF NOT EXISTS seasons (id INT AUTO_INCREMENT PRIMARY KEY, description VARCHAR(120) NOT NULL, active TINYINT DEFAULT 0)",
"CREATE TABLE IF NOT EXISTS splits (id INT AUTO_INCREMENT PRIMARY KEY, value INT NOT NULL DEFAULT 0)",
"CREATE TABLE IF NOT EXISTS currency (id INT AUTO_INCREMENT PRIMARY KEY, description VARCHAR(40) NOT NULL)",
"CREATE TABLE IF NOT EXISTS charge_type (id INT AUTO_INCREMENT PRIMARY KEY, description VARCHAR(80) NOT NULL)",
"CREATE TABLE IF NOT EXISTS statutory (id INT AUTO_INCREMENT PRIMARY KEY, description VARCHAR(120) NOT NULL, currencyid INT DEFAULT 1, accessid INT DEFAULT 0)",
"CREATE TABLE IF NOT EXISTS ticket_check_letter (id INT AUTO_INCREMENT PRIMARY KEY, characters VARCHAR(5) NOT NULL, character_value INT NOT NULL DEFAULT 0)",
"CREATE TABLE IF NOT EXISTS exchange_rate (id INT AUTO_INCREMENT PRIMARY KEY, userid INT DEFAULT 0, seasonid INT DEFAULT 0, amount DECIMAL(14,4) NOT NULL DEFAULT 0, start_of_dayid INT DEFAULT NULL, active TINYINT DEFAULT 1, selling_pointid INT DEFAULT 0, sell_date DATE DEFAULT NULL, source VARCHAR(50) DEFAULT 'manual', datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
"CREATE TABLE IF NOT EXISTS start_of_day (id INT AUTO_INCREMENT PRIMARY KEY, userid INT DEFAULT 0, seasonid INT DEFAULT 0, description DATE NOT NULL, selling_pointid INT DEFAULT 0, active TINYINT DEFAULT 0, datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
// Existing installs created by an older go.php may have the lean versions — patch them
"ALTER TABLE start_of_day ADD COLUMN IF NOT EXISTS userid INT DEFAULT 0, ADD COLUMN IF NOT EXISTS seasonid INT DEFAULT 0, ADD COLUMN IF NOT EXISTS active TINYINT DEFAULT 0, ADD COLUMN IF NOT EXISTS datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
"ALTER TABLE exchange_rate ADD COLUMN IF NOT EXISTS userid INT DEFAULT 0, ADD COLUMN IF NOT EXISTS seasonid INT DEFAULT 0, ADD COLUMN IF NOT EXISTS active TINYINT DEFAULT 1, ADD COLUMN IF NOT EXISTS datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
"CREATE TABLE IF NOT EXISTS parameters (id INT AUTO_INCREMENT PRIMARY KEY, param_key VARCHAR(80) NOT NULL UNIQUE, param_value TEXT DEFAULT '')",
"CREATE TABLE IF NOT EXISTS user_selling_points (id INT AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL, selling_pointid INT NOT NULL, role ENUM('operator','manager','admin') DEFAULT 'operator', UNIQUE KEY usp_u (userid,selling_pointid))",
];
// Execute table creation — show errors so we know what's failing
$create_errors = [];
foreach ($core_tables as $sql) {
    $result = $conn->query($sql);
    if ($result === false) {
        // InnoDB might be broken — retry with MyISAM engine
        $sql_myisam = preg_replace('/\)$/', ') ENGINE=MyISAM', trim($sql));
        $result2 = $conn->query($sql_myisam);
        if ($result2 === false) {
            preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $sql, $m);
            $create_errors[] = ($m[1] ?? '?') . ': ' . $conn->error;
        }
    }
}
$conn->query("SET FOREIGN_KEY_CHECKS=1");
if (!empty($create_errors)) {
    echo '<div style="font-family:monospace;background:#1a1208;color:#f44336;padding:15px;margin:10px 0;border-radius:4px">';
    echo '<b>Table creation errors (InnoDB issue):</b><br>';
    foreach ($create_errors as $e) echo htmlspecialchars($e) . '<br>';
    echo '<br><b>Try this fix:</b><br>';
    echo '1. Stop XAMPP completely<br>';
    echo '2. Go to C:\xampp\mysql\data\<br>';
    echo '3. Delete ib_logfile0 and ib_logfile1<br>';
    echo '4. Start XAMPP again<br>';
    echo '5. Refresh this page';
    echo '</div>';
}

// ── Handle all form submissions ─────────────────────────────────────────
$msg = []; $err = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_sp') {
        $name  = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $code  = $conn->real_escape_string(trim($_POST['floor_code'] ?? ''));
        $fid   = $conn->real_escape_string(trim($_POST['floor_id'] ?? ''));
        if (!$name || !$code || !$fid) {
            $err[] = "All selling point fields are required.";
        } else {
            if ($conn->query("INSERT INTO selling_point (name,floor_code,floor_id,active) VALUES ('$name','$code','$fid',1)"))
                $msg[] = "✓ Selling point '$name' created.";
            else $err[] = "Error: " . $conn->error;
        }
    }

    if ($action === 'create_season') {
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        if (!$desc) { $err[] = "Season description required."; }
        else {
            $conn->query("UPDATE seasons SET active=0");
            if ($conn->query("INSERT INTO seasons (description,active) VALUES ('$desc',1)"))
                $msg[] = "✓ Season '$desc' created and activated.";
            else $err[] = "Error: " . $conn->error;
        }
    }

    if ($action === 'activate_season') {
        $sid = (int)($_POST['seasonid'] ?? 0);
        $conn->query("UPDATE seasons SET active=0");
        $conn->query("UPDATE seasons SET active=1 WHERE id=$sid");
        $msg[] = "✓ Season activated.";
    }

    if ($action === 'activate_sp') {
        $spid = (int)($_POST['spid'] ?? 0);
        $conn->query("UPDATE selling_point SET active=0");
        $conn->query("UPDATE selling_point SET active=1 WHERE id=$spid");
        $msg[] = "✓ Selling point activated.";
    }

    if ($action === 'create_user') {
        $uname = $conn->real_escape_string(trim($_POST['username'] ?? ''));
        $pword = $conn->real_escape_string(flowork_password_hash(trim($_POST['password'] ?? '')));
        $now   = date('Y-m-d H:i:s');
        if (!$uname || trim($_POST['password'] ?? '') === '') { $err[] = "Username and password required."; }
        else {
            $ex = safe_row($conn, "SELECT id FROM users WHERE username='$uname' LIMIT 1");
            if ($ex) {
                $uid = (int)$ex['id'];
                $conn->query("UPDATE users SET password='$pword', active=1 WHERE id=$uid");
                $rq = safe_row($conn, "SELECT id FROM rights WHERE userid=$uid LIMIT 1");
                if ($rq) $conn->query("UPDATE rights SET admin=1,transporter_receiving=1,scale=1,junus=1,buyer=1,balance=1,salessheets=1,accounts=1,transporter_payments=1 WHERE userid=$uid");
                else $conn->query("INSERT INTO rights (userid,created_byid,admin,transporter_receiving,scale,junus,buyer,balance,salessheets,accounts,transporter_payments,created_at) VALUES ($uid,$uid,1,1,1,1,1,1,1,1,1,'$now')");
                $msg[] = "✓ User '$uname' password updated with full admin rights.";
            } else {
                if ($conn->query("INSERT INTO users (username,password,active,confirm,created_at) VALUES ('$uname','$pword',1,1,'$now')")) {
                    $uid = (int)$conn->insert_id;
                    $conn->query("INSERT INTO rights (userid,created_byid,admin,transporter_receiving,scale,junus,buyer,balance,salessheets,accounts,transporter_payments,created_at) VALUES ($uid,$uid,1,1,1,1,1,1,1,1,1,'$now')");
                    $msg[] = "✓ User '$uname' created with full admin rights.";
                } else $err[] = "Error: " . $conn->error;
            }
        }
    }

    if ($action === 'seed_lookups') {
        $seeded = [];
        if (safe_count($conn, 'splits') == 0) {
            for ($i = 0; $i <= 15; $i++) $conn->query("INSERT INTO splits (value) VALUES ($i)");
            $seeded[] = "splits (0–15)";
        }
        if (safe_count($conn, 'currency') == 0) {
            $conn->query("INSERT INTO currency (description) VALUES ('USD')");
            $conn->query("INSERT INTO currency (description) VALUES ('ZiG')");
            $seeded[] = "currency";
        }
        if (safe_count($conn, 'charge_type') == 0) {
            foreach (["Value","Mass","Mass and Value","Bales","Amount"] as $ct)
                $conn->query("INSERT INTO charge_type (description) VALUES ('$ct')");
            $seeded[] = "charge_type";
        }
        if (safe_count($conn, 'statutory') == 0) {
            $usd = (safe_row($conn, "SELECT id FROM currency WHERE description='USD' LIMIT 1")['id'] ?? 1);
            $zig = (safe_row($conn, "SELECT id FROM currency WHERE description='ZiG' LIMIT 1")['id'] ?? 2);
            foreach ([["Afforestation",$zig],["Weighing & Auction",$usd],["Floor Commission",$usd],["Service Charge",$usd],["MOA",$usd],["MOA",$zig],["Bank Charge",$usd]] as $s)
                $conn->query("INSERT INTO statutory (description,currencyid) VALUES ('{$s[0]}',{$s[1]})");
            $seeded[] = "statutory";
        }
        if (safe_count($conn, 'ticket_check_letter') == 0) {
            $chars = array_merge(range('0','9'), range('A','Z'), ['-','.',' ','$','/','+',' %']);
            foreach ($chars as $i => $ch) {
                $ce = $conn->real_escape_string($ch);
                $conn->query("INSERT INTO ticket_check_letter (characters,character_value) VALUES ('$ce',$i)");
            }
            $seeded[] = "ticket_check_letter";
        }
        $msg[] = $seeded ? "✓ Seeded: " . implode(', ', $seeded) : "✓ All lookup tables already seeded.";
    }

    if ($action === 'reload_session') {
        $sp = safe_row($conn, "SELECT id,name FROM selling_point WHERE active=1 LIMIT 1");
        $ss = safe_row($conn, "SELECT id,description FROM seasons WHERE active=1 LIMIT 1");
        $_SESSION['selling_pointid']    = $sp['id']          ?? 0;
        $_SESSION['selling_point_name'] = $sp['name']        ?? '';
        $_SESSION['seasonid']           = $ss['id']          ?? 0;
        $_SESSION['season_name']        = $ss['description'] ?? '';
        $msg[] = "✓ Session reloaded.";
    }
}

// ── Read current state ──────────────────────────────────────────────────
$sp_list     = $conn->query("SELECT * FROM selling_point ORDER BY id") ?: null;
$season_list = $conn->query("SELECT * FROM seasons ORDER BY id DESC") ?: null;
$user_list   = $conn->query("SELECT u.id,u.username,u.password,u.active,r.admin FROM users u LEFT JOIN rights r ON r.userid=u.id ORDER BY u.id") ?: null;
$_sp_q = $conn->query("SELECT * FROM selling_point WHERE active=1 LIMIT 1");
$active_sp = ($_sp_q && $_sp_q !== true) ? ($_sp_q->fetch_assoc() ?: []) : [];
$_ss_q = $conn->query("SELECT * FROM seasons WHERE active=1 LIMIT 1");
$active_ss = ($_ss_q && $_ss_q !== true) ? ($_ss_q->fetch_assoc() ?: []) : [];

$checks = [
    'DB connected'         => true,
    'Selling point exists' => ($sp_list && $sp_list->num_rows > 0),
    'Active selling point' => !empty($active_sp),
    'Season exists'        => ($season_list && $season_list->num_rows > 0),
    'Active season'        => !empty($active_ss),
    'Users exist'          => safe_count($conn, 'users') > 0,
    'Splits seeded'        => safe_count($conn, 'splits') > 0,
    'Currency seeded'      => safe_count($conn, 'currency') > 0,
    'Statutory seeded'     => safe_count($conn, 'statutory') > 0,
];
$all_ok = !in_array(false, $checks, true);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Flowork Setup</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#f5f0e8;color:#1a1208;padding:1.5rem;font-size:14px}
.wrap{max-width:860px;margin:0 auto}
h1{font-size:1.5rem;font-weight:700;margin-bottom:.25rem;color:#1a1208}
h2{font-size:.7rem;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:#7a6e5f;margin:1.5rem 0 .5rem}
.card{background:#fff;border:1px solid rgba(26,18,8,.1);border-radius:4px;overflow:hidden;margin-bottom:1rem}
.card-head{padding:.75rem 1rem;border-bottom:1px solid rgba(26,18,8,.08);font-size:.7rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:#7a6e5f;display:flex;align-items:center;justify-content:space-between}
.card-body{padding:1rem}
.check{display:flex;align-items:center;gap:.6rem;padding:.4rem 0;border-bottom:1px solid rgba(26,18,8,.06);font-size:.82rem}
.check:last-child{border:none}
.dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
.ok-dot{background:#3d6b35}.fail-dot{background:#c03a2b}
.badge{display:inline-flex;padding:2px 8px;border-radius:2px;font-size:.62rem;font-weight:600;letter-spacing:.06em}
.badge-ok{background:#edf4ec;color:#2a5223;border:1px solid #9bcb94}
.badge-fail{background:#fdf2f2;color:#c03a2b;border:1px solid #f5b8b2}
.badge-gold{background:#fdf6e8;color:#92650e;border:1px solid #e8c96a}
.alert{padding:.65rem 1rem;border-radius:4px;font-size:.82rem;margin-bottom:.65rem;border-left:3px solid transparent}
.alert-ok{background:#edf4ec;border-color:#3d6b35;color:#2a5223}
.alert-err{background:#fdf2f2;border-color:#c03a2b;color:#c03a2b}
label{display:block;font-size:.62rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:#7a6e5f;margin:.6rem 0 .25rem}
input,select{width:100%;padding:.45rem .7rem;border:1.5px solid rgba(26,18,8,.12);border-radius:4px;font-size:.85rem;font-family:inherit;background:#f9f7f4;outline:none}
input:focus,select:focus{border-color:#c8952a;background:#fff}
.btn{display:inline-flex;align-items:center;padding:.45rem 1rem;border-radius:4px;font-size:.8rem;font-weight:500;cursor:pointer;border:none;font-family:inherit}
.btn-primary{background:#1a1208;color:#e8b84b}
.btn-gold{background:#c8952a;color:#fff}
.btn-sm{padding:.25rem .65rem;font-size:.72rem}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.row{display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap}
.row .fg{flex:1;min-width:130px}
table{width:100%;border-collapse:collapse;font-size:.8rem}
th{font-size:.62rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#7a6e5f;padding:.5rem .75rem;border-bottom:1px solid rgba(26,18,8,.1);background:#f5f0e8;text-align:left}
td{padding:.5rem .75rem;border-bottom:1px solid rgba(26,18,8,.07)}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid #c8952a}
.logo{font-size:1.3rem;font-weight:700;letter-spacing:.05em}
</style>
</head>
<body>
<div class="wrap">
<div class="topbar">
  <div>
    <div class="logo">⚙ Flowork Setup</div>
    <div style="font-size:.72rem;color:#7a6e5f;margin-top:2px">Emergency recovery — all forms post to go.php</div>
  </div>
  <div style="display:flex;gap:.5rem">
    <a href="go.php" class="btn btn-sm" style="border:1px solid rgba(26,18,8,.15)">↺ Refresh</a>
    <a href="login.php" class="btn btn-primary btn-sm">Login →</a>
  </div>
</div>

<?php foreach($msg as $m): ?><div class="alert alert-ok"><?=htmlspecialchars($m)?></div><?php endforeach; ?>
<?php foreach($err as $e): ?><div class="alert alert-err"><?=htmlspecialchars($e)?></div><?php endforeach; ?>

<?php if($all_ok): ?>
<div class="alert alert-ok" style="font-size:.9rem;font-weight:500">
  ✓ All checks passing — <a href="login.php" style="color:#2a5223;font-weight:700">Go to login →</a>
</div>
<?php else: ?>
<div class="alert" style="background:#fdf6e8;border-color:#c8952a;color:#7a5010">
  ⚠ Complete the steps below to restore the system.
</div>
<?php endif; ?>

<!-- Checks -->
<div class="card">
<div class="card-head">System Checks</div>
<div class="card-body" style="padding:.5rem 1rem">
<?php foreach($checks as $label => $ok): ?>
<div class="check">
  <div class="dot <?=$ok?'ok-dot':'fail-dot'?>"></div>
  <span style="flex:1"><?=$label?></span>
  <span class="badge <?=$ok?'badge-ok':'badge-fail'?>"><?=$ok?'OK':'MISSING'?></span>
</div>
<?php endforeach; ?>
</div>
</div>

<div class="grid2">

<!-- Selling Point -->
<div class="card">
<div class="card-head">
  Selling Point
  <?= !empty($active_sp) ? '<span class="badge badge-ok">'.htmlspecialchars($active_sp['name']).'</span>' : '<span class="badge badge-fail">None active</span>' ?>
</div>
<div class="card-body">
  <?php if($sp_list && $sp_list->num_rows > 0): ?>
  <table style="margin-bottom:.75rem">
  <tr><th>Name</th><th>Code</th><th>Active</th><th></th></tr>
  <?php $sp_list->data_seek(0); while($r=$sp_list->fetch_assoc()): ?>
  <tr>
    <td><?=htmlspecialchars($r['name'])?></td>
    <td><?=htmlspecialchars($r['floor_code'])?></td>
    <td><?=$r['active']?'<span class="badge badge-ok">Yes</span>':'<span class="badge" style="background:#eee">No</span>'?></td>
    <td>
      <?php if(!$r['active']): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="activate_sp">
        <input type="hidden" name="spid" value="<?=$r['id']?>">
        <button class="btn btn-gold btn-sm">Activate</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endwhile; ?>
  </table>
  <?php endif; ?>
  <form method="POST">
  <input type="hidden" name="action" value="create_sp">
  <div class="row">
    <div class="fg"><label>Floor Name</label><input name="name" placeholder="e.g. Harare Floor" required></div>
    <div class="fg"><label>Code</label><input name="floor_code" placeholder="e.g. HTF" required></div>
  </div>
  <div><label>Floor ID</label><input name="floor_id" placeholder="e.g. 001" required style="margin-bottom:.5rem"></div>
  <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.25rem">Create Selling Point</button>
  </form>
</div>
</div>

<!-- Season -->
<div class="card">
<div class="card-head">
  Season
  <?= !empty($active_ss) ? '<span class="badge badge-ok">'.htmlspecialchars($active_ss['description']).'</span>' : '<span class="badge badge-fail">None active</span>' ?>
</div>
<div class="card-body">
  <?php if($season_list && $season_list->num_rows > 0): ?>
  <table style="margin-bottom:.75rem">
  <tr><th>Description</th><th>Active</th><th></th></tr>
  <?php $season_list->data_seek(0); while($r=$season_list->fetch_assoc()): ?>
  <tr>
    <td><?=htmlspecialchars($r['description'])?></td>
    <td><?=$r['active']?'<span class="badge badge-ok">Yes</span>':'<span class="badge" style="background:#eee">No</span>'?></td>
    <td>
      <?php if(!$r['active']): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="activate_season">
        <input type="hidden" name="seasonid" value="<?=$r['id']?>">
        <button class="btn btn-gold btn-sm">Activate</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endwhile; ?>
  </table>
  <?php endif; ?>
  <form method="POST">
  <input type="hidden" name="action" value="create_season">
  <div><label>Season Description</label><input name="description" placeholder="e.g. 2024/2025 Season" required style="margin-bottom:.5rem"></div>
  <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.25rem">Create Season</button>
  </form>
</div>
</div>
</div><!-- /grid2 -->

<!-- Users -->
<div class="card">
<div class="card-head">Users</div>
<div class="card-body">
  <?php if($user_list && $user_list->num_rows > 0): ?>
  <table style="margin-bottom:.75rem">
  <tr><th>ID</th><th>Username</th><th>Password</th><th>Active</th><th>Admin</th></tr>
  <?php $user_list->data_seek(0); while($r=$user_list->fetch_assoc()): ?>
  <tr>
    <td><?=$r['id']?></td>
    <td><b><?=htmlspecialchars($r['username'])?></b></td>
    <td><code style="font-size:.75rem"><?=htmlspecialchars($r['password'])?></code></td>
    <td><?=$r['active']?'<span class="badge badge-ok">Yes</span>':'<span class="badge badge-fail">No</span>'?></td>
    <td><?=$r['admin']?'<span class="badge badge-gold">Admin</span>':''?></td>
  </tr>
  <?php endwhile; ?>
  </table>
  <?php endif; ?>
  <form method="POST">
  <input type="hidden" name="action" value="create_user">
  <div class="row">
    <div class="fg"><label>Username</label><input name="username" placeholder="sysadmin" required></div>
    <div class="fg"><label>Password</label><input name="password" placeholder="plain text password" required></div>
  </div>
  <button type="submit" class="btn btn-primary" style="margin-top:.5rem">Create / Reset User (full admin)</button>
  </form>
  <div style="font-size:.72rem;color:#7a6e5f;margin-top:.5rem">
    If user already exists, this resets the password and ensures admin rights.
  </div>
</div>
</div>

<!-- Seed + Reload -->
<div class="grid2">
<div class="card">
<div class="card-head">Seed Lookup Tables</div>
<div class="card-body">
  <p style="font-size:.8rem;color:#7a6e5f;margin-bottom:.75rem">Seeds splits, currency, charge_type, statutory, ticket_check_letter. Safe to run multiple times.</p>
  <form method="POST">
  <input type="hidden" name="action" value="seed_lookups">
  <button type="submit" class="btn btn-primary" style="width:100%">Seed All Lookups</button>
  </form>
</div>
</div>
<div class="card">
<div class="card-head">Reload Session</div>
<div class="card-body">
  <p style="font-size:.8rem;color:#7a6e5f;margin-bottom:.75rem">After setup, reload your session so the dashboard picks up the selling point and season.</p>
  <form method="POST">
  <input type="hidden" name="action" value="reload_session">
  <button type="submit" class="btn btn-primary" style="width:100%">↺ Reload Session</button>
  </form>
  <div style="font-size:.72rem;color:#7a6e5f;margin-top:.5rem">
    selling_pointid: <b><?=$_SESSION['selling_pointid']??'not set'?></b> &nbsp;
    seasonid: <b><?=$_SESSION['seasonid']??'not set'?></b>
  </div>
</div>
</div>
</div>

<div style="text-align:center;color:#7a6e5f;font-size:.72rem;margin-top:1rem">
  Delete go.php after recovery is complete.
  &nbsp;·&nbsp; <a href="login.php" style="color:#c8952a">→ Go to Login</a>
</div>

</div>
</body></html>
