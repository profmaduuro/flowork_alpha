<?php
require_once __DIR__ . '/includes/admin_gate.php';
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/conn.php";
require_once "includes/password_helper.php";

// Allow access without login for initial setup
$msg = [];
$err = [];

// ── POST: Create selling point ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_sp') {
        $name       = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $floor_code = $conn->real_escape_string(trim($_POST['floor_code'] ?? ''));
        $floor_id   = $conn->real_escape_string(trim($_POST['floor_id'] ?? ''));
        if (!$name || !$floor_code || !$floor_id) {
            $err[] = "All selling point fields required.";
        } else {
            if ($conn->query("INSERT INTO selling_point (name,floor_code,floor_id,active) VALUES ('$name','$floor_code','$floor_id',1)")) {
                $sid = $conn->insert_id;
                // Create a default active subscription for the new floor to allow immediate operations
                $far_future = date('Y-m-d', strtotime('+3650 days'));
                $conn->query("INSERT INTO flowork_subscriptions (selling_pointid, status, license_key, start_date, end_date) VALUES ($sid, 'Active', 'TRIAL-ACTIVE-LICENSE', CURDATE(), '$far_future') ON DUPLICATE KEY UPDATE status='Active'");
                $msg[] = "✓ Selling point '$name' created and licensed.";
            } else {
                $err[] = "Selling point error: " . $conn->error;
            }
        }
    }

    if ($action === 'create_season') {
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        if (!$desc) { $err[] = "Season description required."; }
        else {
            // Deactivate existing seasons first
            $conn->query("UPDATE seasons SET active=0");
            if ($conn->query("INSERT INTO seasons (description,active) VALUES ('$desc',1)"))
                $msg[] = "✓ Season '$desc' created and set as active.";
            else $err[] = "Season error: " . $conn->error;
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
        $username = $conn->real_escape_string(trim($_POST['username'] ?? ''));
        $password = $conn->real_escape_string(flowork_password_hash(trim($_POST['password'] ?? '')));
        $now      = date('Y-m-d H:i:s');
        if (!$username || trim($_POST['password'] ?? '') === '') { $err[] = "Username and password required."; }
        else {
            $existing = $conn->query("SELECT id FROM users WHERE username='$username' LIMIT 1")->fetch_assoc();
            if ($existing) {
                // Update password and activate
                $uid = $existing['id'];
                $conn->query("UPDATE users SET password='$password', active=1 WHERE id=$uid");
                // Ensure admin rights
                $rq = $conn->query("SELECT id FROM rights WHERE userid=$uid LIMIT 1")->fetch_assoc();
                if ($rq) {
                    $conn->query("UPDATE rights SET admin=1,transporter_receiving=1,scale=1,junus=1,buyer=1,balance=1,salessheets=1,accounts=1,transporter_payments=1 WHERE userid=$uid");
                } else {
                    $conn->query("INSERT INTO rights (userid,created_byid,admin,transporter_receiving,scale,junus,buyer,balance,salessheets,accounts,transporter_payments,created_at) VALUES ($uid,$uid,1,1,1,1,1,1,1,1,1,'$now')");
                }
                $msg[] = "✓ User '$username' password updated and rights set to admin.";
            } else {
                if ($conn->query("INSERT INTO users (username,password,active,confirm,created_at) VALUES ('$username','$password',1,1,'$now')")) {
                    $uid = $conn->insert_id;
                    $conn->query("INSERT INTO rights (userid,created_byid,admin,transporter_receiving,scale,junus,buyer,balance,salessheets,accounts,transporter_payments,created_at) VALUES ($uid,$uid,1,1,1,1,1,1,1,1,1,'$now')");
                    $msg[] = "✓ User '$username' created with full admin rights.";
                } else $err[] = "User error: " . $conn->error;
            }
        }
    }


    if ($action === 'seed_lookups') {
        $seeded = [];

        // splits: values 0-15
        if ($conn->query("SELECT COUNT(*) c FROM splits")->fetch_assoc()['c'] == 0) {
            for ($i = 0; $i <= 15; $i++) $conn->query("INSERT INTO splits (value) VALUES ($i)");
            $seeded[] = "splits (0–15)";
        }

        // currency
        if ($conn->query("SELECT COUNT(*) c FROM currency")->fetch_assoc()['c'] == 0) {
            $conn->query("INSERT INTO currency(description) VALUES ('USD')");
            $conn->query("INSERT INTO currency(description) VALUES ('ZiG')");
            $seeded[] = "currency (USD, ZiG)";
        }

        // charge_type
        if ($conn->query("SELECT COUNT(*) c FROM charge_type")->fetch_assoc()['c'] == 0) {
            foreach (["Value","Mass","Mass and Value","Bales","Amount"] as $ct)
                $conn->query("INSERT INTO charge_type(description) VALUES ('$ct')");
            $seeded[] = "charge_type (5 types)";
        }

        // statutory (requires currency IDs)
        if ($conn->query("SELECT COUNT(*) c FROM statutory")->fetch_assoc()['c'] == 0) {
            $usd = $conn->query("SELECT id FROM currency WHERE description='USD' LIMIT 1")->fetch_assoc()['id'] ?? 1;
            $zig = $conn->query("SELECT id FROM currency WHERE description='ZiG' LIMIT 1")->fetch_assoc()['id'] ?? 2;
            $stats = [
                ["Afforestration",         $zig, 1],
                ["Weighing & Auction",     $usd, 2],
                ["Floor Commission",       $usd, 3],
                ["Service Charge",         $usd, 4],
                ["MOA",                    $usd, 5],
                ["MOA",                    $zig, 6],
                ["Bank Charge",            $usd, 7],
            ];
            foreach ($stats as $s)
                $conn->query("INSERT INTO statutory(description,currencyid,accessid) VALUES ('{$s[0]}',{$s[1]},{$s[2]})");
            $seeded[] = "statutory (7 items)";
        }

        // ticket_check_letter
        if ($conn->query("SELECT COUNT(*) c FROM ticket_check_letter")->fetch_assoc()['c'] == 0) {
            $chars = array_merge(range('0','9'), range('A','Z'), ['-','.',' ','$','/','+',' %']);
            foreach ($chars as $i => $c) {
                $ce = $conn->real_escape_string($c);
                $conn->query("INSERT INTO ticket_check_letter(characters,character_value) VALUES ('$ce',$i)");
            }
            $seeded[] = "ticket_check_letter (43 chars)";
        }

        if ($seeded) $msg[] = "✓ Seeded: " . implode(', ', $seeded);
        else         $msg[] = "✓ All lookup tables already have data — nothing to seed.";
    }

    if ($action === 'create_usp_table') {
        $conn->query("CREATE TABLE IF NOT EXISTS user_selling_points (
            id INT AUTO_INCREMENT PRIMARY KEY,
            userid INT NOT NULL, selling_pointid INT NOT NULL,
            role ENUM('operator','manager','admin') DEFAULT 'operator',
            UNIQUE KEY usp_unique (userid,selling_pointid),
            FOREIGN KEY (userid) REFERENCES users(id),
            FOREIGN KEY (selling_pointid) REFERENCES selling_point(id)
        )");
        // Auto-assign sysadmin to all floors
        $sysadmin = $conn->query("SELECT id FROM users WHERE username='sysadmin' LIMIT 1")->fetch_assoc();
        if ($sysadmin) {
            $sp_all = $conn->query("SELECT id FROM selling_point WHERE active=1");
            while ($sp = $sp_all->fetch_assoc()) {
                $conn->query("INSERT IGNORE INTO user_selling_points (userid,selling_pointid,role) VALUES ({$sysadmin['id']},{$sp['id']},'admin')");
            }
        }
        $msg[] = "✓ user_selling_points table created. Sysadmin assigned to all floors.";
    }

    if ($action === 'reload_session' && !empty($_SESSION['userid'])) {
        // Refresh session with current DB values
        $sp = $conn->query("SELECT id,name FROM selling_point WHERE active=1 LIMIT 1")->fetch_assoc();
        $_SESSION['selling_pointid']    = $sp['id']   ?? 0;
        $_SESSION['selling_point_name'] = $sp['name'] ?? '';
        $ss = $conn->query("SELECT id,description FROM seasons WHERE active=1 LIMIT 1")->fetch_assoc();
        $_SESSION['seasonid']    = $ss['id']          ?? 0;
        $_SESSION['season_name'] = $ss['description'] ?? '';
        $msg[] = "✓ Session reloaded — selling_pointid={$_SESSION['selling_pointid']}, seasonid={$_SESSION['seasonid']}";
    }
}

// ── Read current state ─────────────────────────────────────────────────────
$db_ok = !$conn->connect_error;

$sp_list      = $conn->query("SELECT * FROM selling_point ORDER BY id");
$season_list  = $conn->query("SELECT * FROM seasons ORDER BY id DESC");
$user_list    = $conn->query("SELECT u.id,u.username,u.password,u.active,r.admin FROM users u LEFT JOIN rights r ON r.userid=u.id ORDER BY u.id"); // password only used to show hashed/legacy status

$active_sp     = $conn->query("SELECT * FROM selling_point WHERE active=1 LIMIT 1")->fetch_assoc();
$active_season = $conn->query("SELECT * FROM seasons WHERE active=1 LIMIT 1")->fetch_assoc();

$checks = [
    'DB connected'         => $db_ok,
    'Selling point exists' => $sp_list->num_rows > 0,
    'Active selling point' => !empty($active_sp),
    'Season exists'        => $season_list->num_rows > 0,
    'Active season'        => !empty($active_season),
    'Users exist'          => $conn->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'] > 0,
    'Splits seeded'        => $conn->query("SELECT COUNT(*) c FROM splits")->fetch_assoc()['c'] > 0,
    'Currency seeded'      => $conn->query("SELECT COUNT(*) c FROM currency")->fetch_assoc()['c'] > 0,
    'Statutory seeded'     => $conn->query("SELECT COUNT(*) c FROM statutory")->fetch_assoc()['c'] > 0,
    'Charge types seeded'  => $conn->query("SELECT COUNT(*) c FROM charge_type")->fetch_assoc()['c'] > 0,
    'Ticket check letters' => $conn->query("SELECT COUNT(*) c FROM ticket_check_letter")->fetch_assoc()['c'] > 0,
];

$session_info = [
    'userid'          => $_SESSION['userid']           ?? '(not set)',
    'username'        => $_SESSION['username']          ?? '(not set)',
    'selling_pointid' => $_SESSION['selling_pointid']   ?? '(not set)',
    'seasonid'        => $_SESSION['seasonid']          ?? '(not set)',
    'season_name'     => $_SESSION['season_name']       ?? '(not set)',
];

$all_ok = !in_array(false, $checks, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Flowork — System Setup</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--ink:#1a1208;--gold:#c8952a;--gold-l:#e8b84b;--gold-bg:#fdf6e8;--cream:#f5f0e8;--cream-d:#ede6d6;--leaf:#3d6b35;--leaf-l:#edf4ec;--muted:#7a6e5f;--border:rgba(26,18,8,0.1);--danger:#c0392b;--danger-bg:#fdf2f2}
body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--ink);padding:2rem;font-size:14px}
h1{font-family:'Bebas Neue',sans-serif;font-size:2rem;letter-spacing:0.1em;color:var(--ink);margin-bottom:0.25rem}
h2{font-size:0.72rem;font-weight:500;letter-spacing:0.12em;text-transform:uppercase;color:var(--muted);margin-bottom:0.75rem;margin-top:1.5rem}
.wrap{max-width:860px;margin:0 auto}
.card{background:#fff;border:1px solid var(--border);border-radius:4px;overflow:hidden;margin-bottom:1.25rem}
.card-head{padding:0.85rem 1.15rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.card-head h3{font-size:0.72rem;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted)}
.card-body{padding:1.15rem}
.check-row{display:flex;align-items:center;gap:0.6rem;padding:0.45rem 0;border-bottom:1px solid var(--border);font-size:0.82rem}
.check-row:last-child{border-bottom:none}
.dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.dot-ok{background:var(--leaf)}
.dot-fail{background:var(--danger)}
.badge{display:inline-flex;padding:2px 8px;border-radius:2px;font-size:0.65rem;font-weight:500;letter-spacing:0.06em;text-transform:uppercase}
.badge-ok{background:var(--leaf-l);color:#2a5223;border:1px solid #9bcb94}
.badge-fail{background:var(--danger-bg);color:var(--danger);border:1px solid #f5b8b2}
.badge-gold{background:var(--gold-bg);color:#92650e;border:1px solid #e8c96a}
.badge-gray{background:var(--cream);color:var(--muted);border:1px solid var(--border)}
.alert{padding:0.7rem 1rem;border-radius:4px;font-size:0.82rem;margin-bottom:0.75rem;border-left:3px solid transparent}
.alert-success{background:var(--leaf-l);border-color:var(--leaf);color:#2a5223}
.alert-danger{background:var(--danger-bg);border-color:var(--danger);color:var(--danger)}
.alert-gold{background:var(--gold-bg);border-color:var(--gold);color:#7a5010}
label{display:block;font-size:0.65rem;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted);margin-bottom:0.35rem;margin-top:0.75rem}
input,select{width:100%;padding:0.5rem 0.75rem;border:1.5px solid var(--border);border-radius:4px;background:var(--cream);color:var(--ink);font-family:'DM Sans',sans-serif;font-size:0.85rem;outline:none;-webkit-appearance:none}
input:focus,select:focus{border-color:var(--gold);background:#fff}
.btn{display:inline-flex;align-items:center;gap:5px;padding:0.5rem 1rem;border-radius:4px;font-family:'DM Sans',sans-serif;font-size:0.8rem;font-weight:500;cursor:pointer;transition:all 0.13s;border:none;white-space:nowrap}
.btn-primary{background:var(--ink);color:var(--gold-l)}
.btn-primary:hover{background:var(--gold);color:var(--ink)}
.btn-gold{background:var(--gold);color:var(--ink)}
.btn-ghost{background:transparent;color:var(--ink);border:1px solid var(--border)}
.btn-sm{padding:0.28rem 0.65rem;font-size:0.72rem}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.row{display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap}
.row .fg{flex:1;min-width:140px}
table{width:100%;border-collapse:collapse;font-size:0.8rem}
thead th{font-size:0.62rem;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted);padding:0.55rem 0.85rem;border-bottom:1px solid var(--border);background:var(--cream);text-align:left}
tbody td{padding:0.55rem 0.85rem;border-bottom:1px solid var(--border)}
tbody tr:last-child td{border-bottom:none}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.75rem;padding-bottom:1rem;border-bottom:2px solid var(--gold)}
.topbar-logo{font-family:'Bebas Neue',sans-serif;font-size:1.5rem;color:var(--ink);letter-spacing:0.1em}
.banner{background:var(--ink);color:var(--gold-l);padding:1rem 1.25rem;border-radius:4px;margin-bottom:1.25rem;display:flex;align-items:center;gap:1rem;font-size:0.85rem}
.banner-icon{font-size:1.5rem;flex-shrink:0}

/* Premium Segmented / Capsule Tab Strip */
.premium-tabs-nav {
    display: inline-flex;
    padding: 4px;
    background: var(--cream-d);
    border-radius: 8px;
    margin-bottom: 1.5rem;
    gap: 2px;
    border: 1px solid var(--border);
}
.premium-tab-btn {
    padding: 0.6rem 1.2rem;
    background: transparent;
    border: none;
    border-radius: 6px;
    font-family: inherit;
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--muted);
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    user-select: none;
}
.premium-tab-btn:hover {
    color: var(--ink);
    background: rgba(26, 18, 8, 0.04);
}
.premium-tab-btn.active {
    background: var(--ink);
    color: var(--gold-l);
    box-shadow: 0 4px 12px rgba(26, 18, 8, 0.15);
    font-weight: 600;
}

.setup-tab-panel {
    display: none;
}
.setup-tab-panel.active {
    display: block;
    animation: setupFadeIn 0.25s ease-in-out;
}

@keyframes setupFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>
<div class="wrap">

<div class="topbar">
    <div>
        <div class="topbar-logo">⚙ Flowork System Setup</div>
        <div style="font-size:0.75rem;color:var(--muted);margin-top:2px">Run this page to initialise and diagnose the system</div>
    </div>
    <div style="display:flex;gap:0.5rem">
        <a href="login.php" class="btn btn-ghost btn-sm">← Login</a>
        <a href="dashboard.php" class="btn btn-primary btn-sm">Dashboard →</a>
    </div>
</div>

<?php foreach ($msg as $m): ?><div class="alert alert-success"><?= htmlspecialchars($m) ?></div><?php endforeach; ?>
<?php foreach ($err as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<?php if (!$all_ok): ?>
<div class="banner"><div class="banner-icon">⚠</div><div>Some checks are failing. Complete the steps below to get the system working.</div></div>
<?php else: ?>
<div class="alert alert-success">✓ All checks passing — system is ready. <a href="dashboard.php" style="color:inherit;font-weight:500">Go to dashboard →</a></div>
<?php endif; ?>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="system" onclick="switchSetupTab('system')">
        ⚙️ Diagnostics & Session
    </button>
    <button class="premium-tab-btn" data-tab="floors" onclick="switchSetupTab('floors')">
        📅 Floors & Seasons
    </button>
    <button class="premium-tab-btn" data-tab="users" onclick="switchSetupTab('users')">
        👥 Manage Users
    </button>
    <button class="premium-tab-btn" data-tab="tools" onclick="switchSetupTab('tools')">
        🛠 Database & Access
    </button>
</div>

<!-- Tab Panel 1: Diagnostics & Session -->
<div id="panel-system" class="setup-tab-panel active">
    <!-- SYSTEM CHECKS -->
    <div class="card">
        <div class="card-head"><h3>System Checks</h3></div>
        <div class="card-body" style="padding:0.5rem 1.15rem">
            <?php foreach ($checks as $label => $ok): ?>
            <div class="check-row">
                <div class="dot <?= $ok?'dot-ok':'dot-fail' ?>"></div>
                <span style="flex:1"><?= $label ?></span>
                <span class="badge <?= $ok?'badge-ok':'badge-fail' ?>"><?= $ok?'OK':'MISSING' ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- SESSION INFO -->
    <div class="card">
        <div class="card-head">
            <h3>Current Session</h3>
            <?php if (!empty($_SESSION['userid'])): ?>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="reload_session">
                <button class="btn btn-gold btn-sm">↺ Reload Session</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding:0.5rem 1.15rem">
            <?php foreach ($session_info as $k => $v): ?>
            <div class="check-row">
                <code style="font-size:0.75rem;color:var(--muted);flex:1"><?= $k ?></code>
                <span class="badge <?= ($v && $v !== '(not set)' && $v !== '0') ? 'badge-ok' : 'badge-fail' ?>">
                    <?= htmlspecialchars((string)$v) ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- INSTRUCTIONS -->
    <div class="card">
        <div class="card-head"><h3>What to do after setup</h3></div>
        <div class="card-body">
            <ol style="padding-left:1.25rem;font-size:0.85rem;line-height:2">
                <li>Create a <strong>Selling Point</strong> and activate it</li>
                <li>Create a <strong>Season</strong> and activate it</li>
                <li>Click <strong>↺ Reload Session</strong> (while logged in) so the dashboard picks up the new IDs</li>
                <li>Go to <strong>Settings</strong> → set today's selling date + exchange rate</li>
                <li>Add <strong>Buyers</strong> and their grades</li>
                <li>Add <strong>Transporters</strong> and assign growers to them</li>
                <li>Start the daily workflow: <strong>Scale → Junus → Tickets → Floor → Balance → Sales Sheets</strong></li>
            </ol>
            <div style="margin-top:1rem;display:flex;gap:0.5rem;flex-wrap:wrap">
                <a href="dashboard.php" class="btn btn-primary">Dashboard</a>
                <a href="settings.php" class="btn btn-ghost">Settings</a>
                <a href="buyers.php" class="btn btn-ghost">Buyers</a>
                <a href="transporters.php" class="btn btn-ghost">Transporters</a>
                <a href="growers.php" class="btn btn-ghost">Growers</a>
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 2: Floors & Seasons -->
<div id="panel-floors" class="setup-tab-panel">
    <div class="grid-2">
        <!-- SELLING POINT -->
        <div class="card" style="margin-bottom:0">
            <div class="card-head">
                <h3>Selling Point</h3>
                <?= $active_sp ? '<span class="badge badge-ok">Active: '.htmlspecialchars($active_sp['name']).'</span>' : '<span class="badge badge-fail">None active</span>' ?>
            </div>
            <div class="card-body">
                <?php if ($sp_list->num_rows > 0): ?>
                <table style="margin-bottom:1rem">
                    <thead><tr><th>ID</th><th>Name</th><th>Code</th><th>Active</th><th></th></tr></thead>
                    <tbody>
                    <?php $sp_list->data_seek(0); while ($r = $sp_list->fetch_assoc()): ?>
                    <tr>
                        <td class="badge badge-gray"><?= $r['id'] ?></td>
                        <td><?= htmlspecialchars($r['name']) ?></td>
                        <td><?= htmlspecialchars($r['floor_code']) ?></td>
                        <td><?= $r['active'] ? '<span class="badge badge-ok">Yes</span>' : '<span class="badge badge-gray">No</span>' ?></td>
                        <td>
                            <?php if (!$r['active']): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="activate_sp">
                                <input type="hidden" name="spid" value="<?= $r['id'] ?>">
                                <button class="btn btn-gold btn-sm">Activate</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="create_sp">
                    <label>Floor Name</label>
                    <input type="text" name="name" placeholder="e.g. Harare Tobacco Floor" style="margin-bottom:0.5rem">
                    <label>Floor Code</label>
                    <input type="text" name="floor_code" placeholder="e.g. HTF" style="margin-bottom:0.5rem">
                    <label>Floor ID</label>
                    <input type="text" name="floor_id" placeholder="e.g. 001" style="margin-bottom:0.75rem">
                    <button type="submit" class="btn btn-primary" style="width:100%">Create Selling Point</button>
                </form>
            </div>
        </div>

        <!-- SEASONS -->
        <div class="card" style="margin-bottom:0">
            <div class="card-head">
                <h3>Season</h3>
                <?= $active_season ? '<span class="badge badge-ok">Active: '.htmlspecialchars($active_season['description']).'</span>' : '<span class="badge badge-fail">None active</span>' ?>
            </div>
            <div class="card-body">
                <?php if ($season_list->num_rows > 0): ?>
                <table style="margin-bottom:1rem">
                    <thead><tr><th>ID</th><th>Description</th><th>Active</th><th></th></tr></thead>
                    <tbody>
                    <?php $season_list->data_seek(0); while ($r = $season_list->fetch_assoc()): ?>
                    <tr>
                        <td><?= $r['id'] ?></td>
                        <td><?= htmlspecialchars($r['description']) ?></td>
                        <td><?= $r['active'] ? '<span class="badge badge-ok">Yes</span>' : '<span class="badge badge-gray">No</span>' ?></td>
                        <td>
                            <?php if (!$r['active']): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="activate_season">
                                <input type="hidden" name="seasonid" value="<?= $r['id'] ?>">
                                <button class="btn btn-gold btn-sm">Activate</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="create_season">
                    <label>Season Description</label>
                    <input type="text" name="description" placeholder="e.g. 2024/2025 Season" style="margin-bottom:0.75rem">
                    <button type="submit" class="btn btn-primary" style="width:100%">Create Season</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 3: Users -->
<div id="panel-users" class="setup-tab-panel">
    <div class="card">
        <div class="card-head"><h3>Users</h3></div>
        <div class="card-body">
            <?php if ($user_list->num_rows > 0): ?>
            <table style="margin-bottom:1rem">
                <thead><tr><th>ID</th><th>Username</th><th>Password</th><th>Active</th><th>Admin</th></tr></thead>
                <tbody>
                <?php $user_list->data_seek(0); while ($r = $user_list->fetch_assoc()): ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <td class="fw-500"><?= htmlspecialchars($r['username']) ?></td>
                    <td><?= flowork_password_is_hashed($r['password'] ?? '') ? '<span class="badge badge-ok">Hashed</span>' : '<span class="badge badge-fail">Legacy</span>' ?></td>
                    <td><?= $r['active'] ? '<span class="badge badge-ok">Yes</span>' : '<span class="badge badge-fail">No</span>' ?></td>
                    <td><?= $r['admin'] ? '<span class="badge badge-gold">Admin</span>' : '<span class="badge badge-gray">No</span>' ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="action" value="create_user">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                    <div>
                        <label>Username</label>
                        <input type="text" name="username" placeholder="sysadmin">
                    </div>
                    <div>
                        <label>Password</label>
                        <input type="text" name="password" placeholder="plain text password">
                    </div>
                </div>
                <div style="margin-top:0.75rem">
                    <button type="submit" class="btn btn-primary">Create / Reset User (full admin rights)</button>
                </div>
            </form>
            <div class="alert alert-gold" style="margin-top:0.75rem;margin-bottom:0">
                If sysadmin exists, submitting will reset its password and ensure admin rights are set.
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 4: Database & Tools -->
<div id="panel-tools" class="setup-tab-panel">
    <!-- SEED LOOKUPS -->
    <div class="card">
        <div class="card-head"><h3>Seed Lookup Tables</h3>
            <?php
            $all_seeded =
                $conn->query("SELECT COUNT(*) c FROM splits")->fetch_assoc()['c'] > 0 &&
                $conn->query("SELECT COUNT(*) c FROM currency")->fetch_assoc()['c'] > 0 &&
                $conn->query("SELECT COUNT(*) c FROM statutory")->fetch_assoc()['c'] > 0 &&
                $conn->query("SELECT COUNT(*) c FROM charge_type")->fetch_assoc()['c'] > 0 &&
                $conn->query("SELECT COUNT(*) c FROM ticket_check_letter")->fetch_assoc()['c'] > 0;
            echo $all_seeded ? '<span class="badge badge-ok">All seeded</span>' : '<span class="badge badge-fail">Missing data</span>';
            ?>
        </div>
        <div class="card-body">
            <p style="font-size:0.82rem;color:var(--muted);margin-bottom:1rem">
                Seeds required lookup tables: <strong>splits</strong> (0–15),
                <strong>currency</strong> (USD, ZiG), <strong>charge_type</strong>,
                <strong>statutory</strong>, <strong>ticket_check_letter</strong>.
                Safe to run multiple times — skips tables that already have data.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="seed_lookups">
                <button type="submit" class="btn btn-primary">Seed All Lookup Tables</button>
            </form>
        </div>
    </div>

    <!-- MULTI-TENANT SETUP -->
    <div class="card">
        <div class="card-head"><h3>Multi-Floor Access Control</h3>
        <?php
        $usp = $conn->query("SHOW TABLES LIKE 'user_selling_points'")->num_rows;
        echo $usp ? '<span class="badge badge-ok">Table exists</span>' : '<span class="badge badge-fail">Not created</span>';
        ?>
        </div>
        <div class="card-body">
        <p style="font-size:0.82rem;color:var(--muted);margin-bottom:1rem">
            Creates the <code>user_selling_points</code> table that controls which users can log into which selling points.
            Required for running parallel sales across multiple floors. Safe to run multiple times.
            Automatically assigns sysadmin to all active floors.
        </p>
        <form method="POST">
</div>
</body>
</html>
