<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_once "includes/password_helper.php";
require_login();

$page_title  = "User Management";
$active_page = "users";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();
$msg = $err  = '';

// Ensure users table has all needed columns
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(30) DEFAULT 'clerk'");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS rights JSON DEFAULT NULL");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_admin TINYINT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_master TINYINT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS selling_pointid INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS full_name VARCHAR(120) DEFAULT ''");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(100) DEFAULT ''");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(30) DEFAULT ''");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS active TINYINT DEFAULT 1");

// Ensure granular permissions table
$conn->query("CREATE TABLE IF NOT EXISTS user_permissions (
  userid INT NOT NULL PRIMARY KEY,
  can_weigh TINYINT DEFAULT 1,
  can_sell TINYINT DEFAULT 1,
  can_balance TINYINT DEFAULT 1,
  can_view_reports TINYINT DEFAULT 1,
  can_edit_prices TINYINT DEFAULT 0,
  can_void TINYINT DEFAULT 0,
  can_manage_growers TINYINT DEFAULT 1,
  can_manage_payments TINYINT DEFAULT 0,
  can_export_data TINYINT DEFAULT 1,
  can_manage_users TINYINT DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$ROLES = [
    'master'   => ['label'=>'Master User (All Floors Access)','rights'=>'all','color'=>'badge-gold'],
    'admin'    => ['label'=>'System Administrator (All Modules on Dedicated Floor)','rights'=>'all','color'=>'badge-red'],
    'manager'  => ['label'=>'Floor Manager','rights'=>'floor,balance,accounts,reports,stoporders,growers,dispatch','color'=>'badge-gold'],
    'clerk'    => ['label'=>'Floor Clerk','rights'=>'floor,growers','color'=>'badge-gray'],
    'accounts' => ['label'=>'Accounts Officer','rights'=>'accounts,reports,payments,stoporders','color'=>'badge-blue'],
    'reports'  => ['label'=>'Reports Only','rights'=>'reports','color'=>'badge-gray'],
    'viewer'   => ['label'=>'Viewer','rights'=>'reports','color'=>'badge-gray'],
];

$ALL_RIGHTS = [
    'floor'      => 'Floor Operations (tickets, weighing, floor summary)',
    'balance'    => 'Balance Bales & Sales Sheets',
    'accounts'   => 'Accounts & Payments',
    'reports'    => 'Reports & Analytics',
    'payments'   => 'Payment Processing',
    'stoporders' => 'Stop Order Processing',
    'growers'    => 'Grower Management',
    'dispatch'   => 'Dispatch & Logistics',
    'admin_page' => 'Admin Settings',
];

// Granular permission columns (user-readable labels)
$PERM_COLS = [
    'can_weigh'           => 'Weigh',
    'can_sell'            => 'Sell',
    'can_balance'         => 'Balance',
    'can_view_reports'    => 'Reports',
    'can_edit_prices'     => 'Edit Prices',
    'can_void'            => 'Void',
    'can_manage_growers'  => 'Growers',
    'can_manage_payments' => 'Payments',
    'can_export_data'     => 'Export',
    'can_manage_users'    => 'Users',
];

// Role presets for the permission matrix
$PERM_PRESETS = [
    'clerk'   => ['can_weigh'=>1,'can_sell'=>1,'can_balance'=>0,'can_view_reports'=>0,'can_edit_prices'=>0,'can_void'=>0,'can_manage_growers'=>1,'can_manage_payments'=>0,'can_export_data'=>0,'can_manage_users'=>0],
    'manager' => ['can_weigh'=>1,'can_sell'=>1,'can_balance'=>1,'can_view_reports'=>1,'can_edit_prices'=>1,'can_void'=>1,'can_manage_growers'=>1,'can_manage_payments'=>1,'can_export_data'=>1,'can_manage_users'=>0],
    'analyst' => ['can_weigh'=>0,'can_sell'=>0,'can_balance'=>0,'can_view_reports'=>1,'can_edit_prices'=>0,'can_void'=>0,'can_manage_growers'=>0,'can_manage_payments'=>0,'can_export_data'=>1,'can_manage_users'=>0],
    'admin'   => ['can_weigh'=>1,'can_sell'=>1,'can_balance'=>1,'can_view_reports'=>1,'can_edit_prices'=>1,'can_void'=>1,'can_manage_growers'=>1,'can_manage_payments'=>1,'can_export_data'=>1,'can_manage_users'=>1],
];

// ── Tenant scoping ──────────────────────────────────────────────────────────
// Only a Master User manages users across floors. Anyone else reaching this
// page (a floor admin) may touch only non-master users on their own floor;
// previously every action here took any user id, so a floor admin could
// reset a master's password or promote themselves to master.
$caller_is_master = is_master_user();
$caller_floor     = (int)($_SESSION['selling_pointid'] ?? 0);

function users_can_manage(mysqli $conn, int $target_uid, bool $caller_is_master, int $caller_floor): bool {
    if ($target_uid <= 0) return false;
    if ($caller_is_master) return true;
    $stmt = $conn->prepare("SELECT selling_pointid, is_master, role FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $target_uid);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$t) return false;
    return $caller_floor > 0
        && (int)$t['selling_pointid'] === $caller_floor
        && empty($t['is_master'])
        && ($t['role'] ?? '') !== 'master';
}

// ── AJAX: save granular permission ──────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_perm') {
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'CSRF validation failed. Request blocked.']);
        exit;
    }
    $puid  = (int)($_POST['uid']  ?? 0);
    $col   = preg_replace('/[^a-z_]/', '', $_POST['col'] ?? '');
    $val   = (int)(!empty($_POST['val']));
    $allowed_cols = array_keys($PERM_COLS);
    if (!$puid || !in_array($col, $allowed_cols)) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid parameters']);
        exit;
    }
    if (!users_can_manage($conn, $puid, $caller_is_master, $caller_floor)) {
        echo json_encode(['ok'=>false,'msg'=>'You can only manage users on your own floor.']);
        exit;
    }
    // Upsert permission row
    $stmt_ins = $conn->prepare("INSERT INTO user_permissions (userid) VALUES (?) ON DUPLICATE KEY UPDATE userid=userid");
    $stmt_ins->bind_param("i", $puid);
    $stmt_ins->execute();
    $stmt_ins->close();
    
    $stmt_upd = $conn->prepare("UPDATE user_permissions SET `$col`=? WHERE userid=?");
    $stmt_upd->bind_param("ii", $val, $puid);
    $stmt_upd->execute();
    $stmt_upd->close();
    
    audit_log($conn,$spid,$userid,'user_permissions','update',$puid,null,[$col=>$val]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── AJAX: apply preset ───────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'apply_preset') {
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'CSRF validation failed. Request blocked.']);
        exit;
    }
    $puid   = (int)($_POST['uid']    ?? 0);
    $preset = preg_replace('/[^a-z]/', '', $_POST['preset'] ?? '');
    if (!$puid || !isset($PERM_PRESETS[$preset])) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid preset']);
        exit;
    }
    if (!users_can_manage($conn, $puid, $caller_is_master, $caller_floor)) {
        echo json_encode(['ok'=>false,'msg'=>'You can only manage users on your own floor.']);
        exit;
    }
    $p = $PERM_PRESETS[$preset];
    $allowed_cols = array_keys($PERM_COLS);
    $sets = [];
    foreach ($p as $k=>$v) {
        $clean_k = preg_replace('/[^a-z_]/', '', $k);
        if (in_array($clean_k, $allowed_cols)) {
            $sets[] = "`$clean_k`=" . (int)$v;
        }
    }
    
    $stmt_ins = $conn->prepare("INSERT INTO user_permissions (userid) VALUES (?) ON DUPLICATE KEY UPDATE userid=userid");
    $stmt_ins->bind_param("i", $puid);
    $stmt_ins->execute();
    $stmt_ins->close();
    
    if (!empty($sets)) {
        $stmt_upd = $conn->prepare("UPDATE user_permissions SET ".implode(',',$sets)." WHERE userid=?");
        $stmt_upd->bind_param("i", $puid);
        $stmt_upd->execute();
        $stmt_upd->close();
    }
    
    audit_log($conn,$spid,$userid,'user_permissions','update',$puid,null,['preset'=>$preset]);
    echo json_encode(['ok'=>true,'perms'=>$p]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token verification failed.");
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'save_user') {
        $user_data = [
            'id'              => (int)($_POST['edit_userid'] ?? 0),
            'username'        => trim($_POST['username'] ?? ''),
            'full_name'       => trim($_POST['full_name'] ?? ''),
            'email'           => trim($_POST['email'] ?? ''),
            'phone'           => trim($_POST['phone'] ?? ''),
            'role'            => $_POST['role'] ?? 'clerk',
            'is_admin'        => isset($_POST['is_admin']) ? 1 : 0,
            'is_master'       => isset($_POST['is_master']) ? 1 : 0,
            'selling_pointid' => (int)($_POST['selling_pointid'] ?? 0),
            'password'        => $_POST['password'] ?? '',
            'rights'          => $_POST['rights'] ?? null
        ];

        if (!$caller_is_master) {
            // A floor admin cannot create or edit masters, and every user they
            // save stays on their own floor (selling_pointid 0 means all floors).
            $user_data['is_master']       = 0;
            $user_data['selling_pointid'] = $caller_floor;
            if ($user_data['role'] === 'master') $user_data['role'] = 'admin';
        }

        if (!$caller_is_master && $caller_floor <= 0) {
            $res = ['ok' => false, 'err' => 'Select a floor before managing users.'];
        } elseif ($user_data['id'] > 0 && !users_can_manage($conn, $user_data['id'], $caller_is_master, $caller_floor)) {
            $res = ['ok' => false, 'err' => 'You can only edit users on your own floor.'];
        } else {
            $res = flowork_save_user($conn, $user_data);
        }
        if ($res['ok']) {
            $msg = $res['msg'];
            $audit_data = $user_data;
            unset($audit_data['password']);
            audit_log($conn, $spid, $userid, 'users', $user_data['id'] ? 'update' : 'create', $res['userid'], null, $audit_data);
            if (!empty($user_data['id'])) {
                $_GET['edit'] = $user_data['id']; // Preserve edit state so updated values display on screen
            }
        } else {
            $err = $res['err'];
        }
    }

    if ($action === 'toggle_active') {
        $uid_t = (int)($_POST['toggle_userid'] ?? 0);
        if (!users_can_manage($conn, $uid_t, $caller_is_master, $caller_floor)) {
            $err = "You can only manage users on your own floor.";
        } else {
            $stmt_t = $conn->prepare("UPDATE users SET active = 1-active WHERE id=? AND id != ?");
            $stmt_t->bind_param("ii", $uid_t, $userid);
            $stmt_t->execute();
            $stmt_t->close();
            $msg = "User status toggled.";
        }
    }

    if ($action === 'reset_password') {
        $uid_r = (int)($_POST['reset_userid'] ?? 0);
        $new_plain = (string)($_POST['new_password'] ?? '');
        if (!users_can_manage($conn, $uid_r, $caller_is_master, $caller_floor)) {
            $err = "You can only manage users on your own floor.";
        } elseif (strlen($new_plain) < 8) {
            $err = "Enter a new password of at least 8 characters.";
        } else {
            $new_pw = flowork_password_hash($new_plain);
            $stmt_r = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt_r->bind_param("si", $new_pw, $uid_r);
            $stmt_r->execute();
            $stmt_r->close();
            audit_log($conn,$spid,$userid,'users','reset_password',$uid_r);
            $msg = "✓ Password reset.";
        }
    }
}

// Selling points for assignment & floor mapping
$sps_q = $conn->query("SELECT id,name FROM selling_point ORDER BY name");
$sps   = ($sps_q && $sps_q !== true) ? $sps_q->fetch_all(MYSQLI_ASSOC) : [];
$sp_map = [];
foreach ($sps as $sp) {
    $sp_map[(int)$sp['id']] = $sp['name'];
}

// Floor filter for Master Users
$floor_filter = isset($_GET['filter_sp']) ? (int)$_GET['filter_sp'] : -1; // -1 = All Floors

$all_users_q = $conn->query("SELECT * FROM users ORDER BY active DESC, role, username");
$all_users   = ($all_users_q && $all_users_q !== true) ? $all_users_q->fetch_all(MYSQLI_ASSOC) : [];

if (!is_master_user()) {
    // Dedicated System Administrator or Floor User: strictly view users assigned to their floor
    $user_spid = (int)($_SESSION['selling_pointid'] ?? $_SESSION['user_spid'] ?? 0);
    $all_users = array_values(array_filter($all_users, fn($u) => (int)($u['selling_pointid'] ?? 0) === $user_spid && empty($u['is_master']) && ($u['role'] ?? '') !== 'master'));
    $users     = $all_users;
} else {
    // Master User: view all floors or apply selected floor filter
    if ($floor_filter >= 0) {
        if ($floor_filter === 0) {
            $users = array_values(array_filter($all_users, fn($u) => !empty($u['is_master']) || ($u['role']??'')==='master' || (int)($u['selling_pointid']??0)===0));
        } else {
            $users = array_values(array_filter($all_users, fn($u) => (int)($u['selling_pointid']??0) === $floor_filter));
        }
    } else {
        $users = $all_users;
    }
}

// Load all permissions into a keyed array
$perms_q = $conn->query("SELECT * FROM user_permissions");
$all_perms = [];
if ($perms_q && $perms_q !== true) {
    while ($pr = $perms_q->fetch_assoc()) {
        $all_perms[(int)$pr['userid']] = $pr;
    }
}

// Selected user for edit
$sel_uid  = (int)($_GET['edit'] ?? 0);
$sel_user = null;
foreach ($all_users as $u) { if ((int)$u['id'] === $sel_uid) { $sel_user = $u; break; } }

include "includes/layout.php";
?>
<style>
/* ── Permission Matrix ── */
.perm-matrix { overflow-x:auto; }
.perm-table { border-collapse:collapse; font-size:.72rem; min-width:700px; }
.perm-table th { font-size:.78rem; font-weight:600; color:var(--muted); padding:.45rem .5rem; border-bottom:2px solid var(--border);
                 text-align:center; white-space:nowrap; }
.perm-table th.perm-user-col { text-align:left; min-width:140px; }
.perm-table td { padding:.38rem .4rem; border-bottom:1px solid var(--border); text-align:center; vertical-align:middle; }
.perm-table td.perm-user-name { text-align:left; font-weight:500; padding-left:.7rem; }
.perm-table tr:last-child td { border-bottom:none; }
.perm-table tr:hover td { background:rgba(200,149,42,.04); }

/* Checkbox toggle */
.perm-cb { display:none; }
.perm-toggle {
    display:inline-flex; align-items:center; justify-content:center;
    width:26px; height:26px; border-radius:6px; cursor:pointer;
    border:1.5px solid var(--border); background:var(--cream-d);
    font-size:.85rem; transition:all .13s; user-select:none;
}
.perm-cb:checked + .perm-toggle {
    background:var(--surface-2); border-color:#9bcb94; color:var(--ok-text);
}
.perm-cb:not(:checked) + .perm-toggle {
    background:var(--cream-d); border-color:var(--border); color:#bbb;
}
.perm-toggle:hover { border-color:var(--gold); transform:scale(1.1); }
.perm-saving { opacity:.5; pointer-events:none; }

/* Quick-Set preset buttons */
.preset-btn {
    padding:.22rem .65rem; border-radius:4px; font-size:.78rem; font-weight:600;
    cursor:pointer; border:1.5px solid; transition:all .13s; }
.preset-clerk   { background:var(--surface-2); border-color:#bbb; color:#666; }
.preset-manager { background:var(--gold-bg); border-color:var(--gold); color:var(--warn-text); }
.preset-analyst { background:var(--info-bg); border-color:#aacde6; color:var(--info); }
.preset-admin   { background:var(--danger-bg); border-color:#f5b8b2; color:var(--danger); }
.preset-btn:hover { filter:brightness(.92); transform:scale(1.04); }

.perm-updated-flash { animation:perm-flash .55s ease; }
@keyframes perm-flash { 0%{background:rgba(61,107,53,.3)} 100%{background:transparent} }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>User Management</h2>
    <p>Manage system users, roles, access rights and granular permissions</p>
  </div>
  <div style="display:flex;align-items:center;gap:.75rem">
    <?php if (empty($_SESSION['selling_pointid'])): ?>
    <a href="login.php" class="btn btn-gold btn-sm">🏭 Enter / Choose Floor &rarr;</a>
    <?php endif; ?>
    <a href="users.php" class="btn btn-ghost btn-sm">+ New User</a>
  </div>
</div>

<?php if (empty($_SESSION['selling_pointid']) && is_master_user()): ?>
<div class="alert alert-gold" style="margin-bottom:1rem">
  👑 <strong>Master User Pre-Floor Mode</strong> — You are managing users before selecting or entering an active auction floor.
</div>
<?php endif; ?>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="premium-tabs-nav" data-tabs="users"<?= ($sel_user || $err) ? ' data-force="edit"' : '' ?>>
  <button type="button" class="premium-tab-btn active" data-tab="list">👥 System Users</button>
  <button type="button" class="premium-tab-btn" data-tab="edit"><?=$sel_user ? '✏ Edit User' : '➕ Create User'?></button>
  <button type="button" class="premium-tab-btn" data-tab="perms">🔐 Permissions</button>
  <button type="button" class="premium-tab-btn" data-tab="roles">📖 Role Reference</button>
</div>

<!-- User list -->
<div id="panel-list" class="tab-panel active" data-tabs="users">
<div class="card">
<div class="card-head" style="display:flex;align-items:center;justify-content:space-between">
  <div style="display:flex;align-items:center;gap:.75rem">
    <h3>System Users</h3>
    <span class="badge badge-gold" title="Total users matching active filter"><?=count($users)?> / <?=count($all_users)?> Users</span>
  </div>
  <?php if (is_master_user()): ?>
  <div style="display:flex;align-items:center;gap:.5rem">
    <span style="font-size:.7rem;color:var(--muted);font-weight:600">Filter Floor:</span>
    <select onchange="location.href='users.php?filter_sp='+this.value" class="form-control" style="font-size:.72rem;padding:.25rem .5rem;width:auto;background:var(--cream-d);color:var(--cream);border:1px solid var(--border)">
      <option value="-1" <?= $floor_filter===-1?'selected':'' ?>>🌐 All Floors Users (<?= count($all_users) ?>)</option>
      <option value="0" <?= $floor_filter===0?'selected':'' ?>>👑 Master Users (All Floors)</option>
      <?php foreach ($sps as $sp_f): ?>
      <option value="<?= $sp_f['id'] ?>" <?= $floor_filter===(int)$sp_f['id']?'selected':'' ?>>🔒 Floor: <?= htmlspecialchars($sp_f['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
</div>
<div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
<thead><tr>
  <th>User</th><th>Role</th><th>Selling Point / Floor</th><th>Status</th><th></th>
</tr></thead>
<tbody>
<?php foreach ($users as $u):
  $role_info = $ROLES[$u['role'] ?? 'clerk'] ?? ['label'=>ucfirst($u['role']??''),'color'=>'badge-gray'];
  $is_me = (int)$u['id'] === $userid;
  $u_spid = (int)($u['selling_pointid'] ?? 0);
  $fl_name = $sp_map[$u_spid] ?? ('SP #'.$u_spid);
?>
<tr style="<?=!$u['active']?'opacity:.5':''?><?=(int)$u['id']===$sel_uid?';background:rgba(200,149,42,.06);border-left:3px solid var(--gold)':''?>">
  <td>
    <div style="font-weight:600;font-size:.82rem"><?=htmlspecialchars($u['username'])?> <?=$is_me?'<span class="badge badge-gray" style="font-size:.55rem">You</span>':''?></div>
    <div style="font-size:.68rem;color:var(--muted)"><?=htmlspecialchars($u['full_name']??'')?> <?=$u['email']?'· '.$u['email']:''?></div>
  </td>
  <td><span class="badge <?=$role_info['color']?>" style="font-size:.72rem"><?=$role_info['label']?></span></td>
  <td style="font-size:.72rem">
    <?php if(!empty($u['is_master']) || ($u['role']??'')==='master'): ?>
    <span class="badge badge-gold" style="font-size:.72rem">👑 All Floors (Master)</span>
    <?php elseif(!empty($u['is_admin']) || ($u['role']??'')==='admin'): ?>
      <?php if($u_spid > 0): ?>
      <span class="badge badge-red" style="font-size:.72rem" title="System Administrator on <?= htmlspecialchars($fl_name) ?>">🔒 <?= htmlspecialchars($fl_name) ?> (Admin)</span>
      <?php else: ?>
      <span class="badge badge-gold" style="font-size:.72rem">👑 All Floors (Admin)</span>
      <?php endif; ?>
    <?php elseif($u_spid > 0): ?>
    <span class="badge badge-blue" style="font-size:.72rem" title="Dedicated to <?= htmlspecialchars($fl_name) ?>">🔒 <?= htmlspecialchars($fl_name) ?></span>
    <?php else: ?>
    <span class="badge badge-gray" style="font-size:.72rem">🌐 All Floors</span>
    <?php endif; ?>
  </td>
  <td><span class="badge <?=$u['active']?'badge-green':'badge-red'?>" style="font-size:.72rem"><?=$u['active']?'Active':'Inactive'?></span></td>
  <td style="white-space:nowrap">
    <a href="?edit=<?=$u['id']?>" class="btn btn-ghost btn-sm" style="font-size:.65rem">Edit</a>
    <?php if(!$is_me):?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="toggle_active">
      <input type="hidden" name="toggle_userid" value="<?=$u['id']?>">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem"><?=$u['active']?'Disable':'Enable'?></button>
    </form>
    <?php endif;?>
  </td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
</div>

</div><!-- /panel-list -->

<!-- Create / Edit user -->
<div id="panel-edit" class="tab-panel" data-tabs="users">
<div class="card mb-3">
<div class="card-head">
  <h3><?=$sel_user ? 'Edit: '.htmlspecialchars($sel_user['username']) : 'Create New User'?></h3>
  <?php if($sel_user):?><a href="users.php" class="btn btn-ghost btn-sm">+ New</a><?php endif;?>
</div>
<div class="card-body">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
<input type="hidden" name="action" value="save_user">
<input type="hidden" name="edit_userid" value="<?=$sel_user?$sel_user['id']:0?>">

<div class="form-row cols-2">
  <div class="form-group"><label>Username *</label>
    <input type="text" name="username" class="form-control" value="<?=htmlspecialchars($sel_user['username']??'')?>" required placeholder="e.g. jmoyo"></div>
  <div class="form-group"><label><?=$sel_user?'New Password (blank=unchanged)':'Password'?></label>
    <input type="text" name="password" class="form-control" placeholder="<?=$sel_user?'Leave blank to keep':'Leave blank to generate one'?>" value=""></div>
</div>
<div class="form-row cols-2">
  <div class="form-group"><label>Full Name</label>
    <input type="text" name="full_name" class="form-control" value="<?=htmlspecialchars($sel_user['full_name']??'')?>"></div>
  <div class="form-group"><label>Email</label>
    <input type="email" name="email" class="form-control" value="<?=htmlspecialchars($sel_user['email']??'')?>"></div>
</div>
<div class="form-row cols-2">
  <div class="form-group"><label>Phone</label>
    <input type="text" name="phone" class="form-control" value="<?=htmlspecialchars($sel_user['phone']??'')?>"></div>
  <div class="form-group"><label>Floor Dedication / Selling Point</label>
    <select name="selling_pointid" class="form-control">
      <?php if (is_master_user()): ?>
      <option value="0">All Floors (Master / Unrestricted Access)</option>
      <?php foreach($sps as $sp):?>
      <option value="<?=$sp['id']?>" <?=($sel_user['selling_pointid']??0)==$sp['id']?'selected':''?>><?=htmlspecialchars($sp['name'])?> (Dedicated Floor)</option>
      <?php endforeach;?>
      <?php else: ?>
      <?php $user_spid = (int)($_SESSION['selling_pointid'] ?? $_SESSION['user_spid'] ?? 0); ?>
      <?php foreach($sps as $sp): if ((int)$sp['id'] === $user_spid): ?>
      <option value="<?=$sp['id']?>" selected>🔒 <?=htmlspecialchars($sp['name'])?> (Your Dedicated Floor)</option>
      <?php endif; endforeach;?>
      <?php endif; ?>
    </select>
  </div>
</div>

<div class="form-group">
  <label>Role (sets default rights)</label>
  <select name="role" class="form-control" id="role-select" onchange="setRoleRights(this.value)">
    <?php foreach($ROLES as $rk=>$rv):?>
    <option value="<?=$rk?>" <?=($sel_user['role']??'clerk')===$rk?'selected':''?>><?=$rv['label']?></option>
    <?php endforeach;?>
  </select>
</div>

<?php if (is_master_user()): ?>
<div class="form-group mb-2">
  <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--gold);font-weight:600">
    <input type="checkbox" name="is_master" value="1" <?=($sel_user['is_master']??0)||($sel_user['role']??'')==='master'?'checked':''?>>
    👑 Master User (Full access to view and monitor all floors simultaneously)
  </label>
</div>
<?php endif; ?>

<div class="form-group">
  <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
    <input type="checkbox" name="is_admin" value="1" <?=($sel_user['is_admin']??0)?'checked':''?>>
    System Administrator (overrides all module rights)
  </label>
</div>

<div style="background:var(--cream-d);border-radius:6px;padding:.75rem;margin-bottom:.75rem">
  <div style="font-size:.78rem;font-weight:600;color:var(--muted);margin-bottom:.5rem">Individual Rights</div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:.25rem">
  <?php
  $cur_rights = [];
  if ($sel_user && !empty($sel_user['rights'])) {
      $cur_rights = @json_decode($sel_user['rights'], true) ?? [];
  }
  foreach ($ALL_RIGHTS as $rkey => $rlabel):?>
  <label style="font-size:.75rem;display:flex;align-items:center;gap:.4rem;cursor:pointer">
    <input type="checkbox" name="rights[]" value="<?=$rkey?>" class="right-cb right-<?=$rkey?>"
           <?=in_array($rkey,$cur_rights)?'checked':''?>>
    <?=htmlspecialchars($rlabel)?>
  </label>
  <?php endforeach;?>
  </div>
</div>

<button type="submit" class="btn btn-primary" style="width:100%">
  <?=$sel_user?'Update User':'Create User'?>
</button>
</form>
</div>
</div>

<!-- Reset password -->
<?php if($sel_user):?>
<div class="card">
<div class="card-head"><h3>Reset Password</h3></div>
<div class="card-body">
<form method="POST" style="display:flex;gap:.5rem">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
  <input type="hidden" name="action" value="reset_password">
  <input type="hidden" name="reset_userid" value="<?=$sel_user['id']?>">
  <input type="text" name="new_password" class="form-control" value="" minlength="8" required>
  <button type="submit" class="btn btn-danger">Reset</button>
</form>
</div>
</div>
<?php endif;?>
</div><!-- /panel-edit -->

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- GRANULAR PERMISSION MATRIX                                                 -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="panel-perms" class="tab-panel" data-tabs="users">
<div class="card">
<div class="card-head">
  <h3>Granular Permission Matrix</h3>
  <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
    <span style="font-size:.72rem;color:var(--muted)">Quick-set all users to preset:</span>
    <button class="preset-btn preset-clerk"   onclick="applyPresetAll('clerk')">Clerk</button>
    <button class="preset-btn preset-manager" onclick="applyPresetAll('manager')">Manager</button>
    <button class="preset-btn preset-analyst" onclick="applyPresetAll('analyst')">Analyst</button>
    <button class="preset-btn preset-admin"   onclick="applyPresetAll('admin')">Admin</button>
  </div>
</div>
<div class="perm-matrix">
<table class="perm-table" id="perm-matrix-table">
<thead>
<tr>
  <th class="perm-user-col">User</th>
  <?php foreach($PERM_COLS as $col=>$label):?>
  <th title="<?=htmlspecialchars($col)?>"><?=htmlspecialchars($label)?></th>
  <?php endforeach;?>
  <th style="min-width:130px">Quick Set</th>
</tr>
</thead>
<tbody>
<?php foreach($users as $u):
  $role_info = $ROLES[$u['role']??'clerk'] ?? ['label'=>ucfirst($u['role']??''),'color'=>'badge-gray'];
  $up = $all_perms[(int)$u['id']] ?? [];
?>
<tr id="pmrow-<?=$u['id']?>" style="<?=!$u['active']?'opacity:.5':''?>">
  <td class="perm-user-name">
    <div style="font-weight:600;font-size:.78rem"><?=htmlspecialchars($u['username'])?></div>
    <div><span class="badge <?=$role_info['color']?>" style="font-size:.72rem"><?=$role_info['label']?></span></div>
  </td>
  <?php foreach($PERM_COLS as $col=>$label):
    $val = isset($up[$col]) ? (int)$up[$col] : 1; // default allowed
  ?>
  <td>
    <input type="checkbox" class="perm-cb" id="pc-<?=$u['id']?>-<?=$col?>"
           <?=$val?'checked':''?> data-uid="<?=$u['id']?>" data-col="<?=$col?>"
           onchange="savePerm(this)">
    <label for="pc-<?=$u['id']?>-<?=$col?>" class="perm-toggle"
           title="<?=htmlspecialchars($label)?>: <?=$val?'Allowed':'Denied'?>">
      <?=$val?'✓':'✗'?>
    </label>
  </td>
  <?php endforeach;?>
  <td>
    <div style="display:flex;gap:.25rem;flex-wrap:wrap">
      <button class="preset-btn preset-clerk"   onclick="applyPreset(<?=$u['id']?>,'clerk')"   title="Clerk: weigh+sell+growers">C</button>
      <button class="preset-btn preset-manager" onclick="applyPreset(<?=$u['id']?>,'manager')" title="Manager: most permissions">M</button>
      <button class="preset-btn preset-analyst" onclick="applyPreset(<?=$u['id']?>,'analyst')" title="Analyst: reports+export only">A</button>
      <button class="preset-btn preset-admin"   onclick="applyPreset(<?=$u['id']?>,'admin')"   title="Admin: all permissions">★</button>
    </div>
  </td>
</tr>
<?php endforeach;?>
</tbody>
</table>
</div>
<div style="padding:.5rem 1rem;font-size:.72rem;color:var(--muted)">
  <span style="background:var(--surface-2);border:1px solid #9bcb94;border-radius:3px;padding:1px 6px;color:var(--ok-text);margin-right:.35rem">✓ Allowed</span>
  <span style="background:var(--cream-d);border:1px solid var(--border);border-radius:3px;padding:1px 6px;color:#bbb;margin-right:.5rem">✗ Denied</span>
  Changes are saved automatically via AJAX.
</div>
</div>
</div><!-- /panel-perms -->

<!-- Rights legend -->
<div id="panel-roles" class="tab-panel" data-tabs="users">
<div class="card">
<div class="card-head"><h3>Role Rights Reference</h3></div>
<div class="card-body" style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;font-size:.75rem">
<?php foreach($ROLES as $rk=>$rv):?>
<div style="background:var(--cream-d);border-radius:6px;padding:.6rem .8rem">
  <span class="badge <?=$rv['color']?>" style="font-size:.72rem;margin-bottom:.35rem;display:inline-block"><?=$rv['label']?></span>
  <div style="color:var(--muted);font-size:.68rem"><?=htmlspecialchars($rv['rights'])?></div>
</div>
<?php endforeach;?>
</div>
</div>
</div><!-- /panel-roles -->

<script>
var ROLE_RIGHTS =<?=json_encode(array_map(fn($r)=>explode(',',$r['rights']),$ROLES))?>;
var PERM_PRESETS = <?=json_encode($PERM_PRESETS)?>;
var PERM_COLS = <?=json_encode(array_keys($PERM_COLS))?>;

function setRoleRights(role) {
    var rights = ROLE_RIGHTS[role] || [];
    document.querySelectorAll('.right-cb').forEach(function(cb) {
        cb.checked = rights.includes('all') || rights.includes(cb.value);
    });

    var isMasterCb = document.querySelector('input[name="is_master"]');
    var spSelect   = document.querySelector('select[name="selling_pointid"]');
    if (role === 'master' || role === 'admin') {
        if (isMasterCb) isMasterCb.checked = true;
        if (spSelect)   spSelect.value = '0';
    }
}

// Coordinate floor select when Master checkbox is toggled
document.addEventListener('change', function(e) {
    if (e.target && e.target.name === 'is_master') {
        var spSelect = document.querySelector('select[name="selling_pointid"]');
        if (e.target.checked && spSelect) {
            spSelect.value = '0';
        }
    }
});

// ── Save individual permission ─────────────────────────────────────────────
function savePerm(cb) {
    var uid = cb.dataset.uid;
    var col = cb.dataset.col;
    var val = cb.checked ? 1 : 0;
    var label = cb.nextElementSibling;
    label.classList.add('perm-saving');
    label.textContent = '…';

    var fd = new FormData();
    fd.append('uid', uid);
    fd.append('col', col);
    fd.append('val', val);
    fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

    fetch('?ajax=save_perm', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d) {
            label.classList.remove('perm-saving');
            label.textContent = val ? '✓' : '✗';
            label.title = col + ': ' + (val ? 'Allowed' : 'Denied');
            var td = label.closest('td');
            td.classList.add('perm-updated-flash');
            setTimeout(function(){ td.classList.remove('perm-updated-flash'); }, 600);
            if (window.fw) fw.toast(col.replace('can_','').replace(/_/g,' ') + ' ' + (val?'allowed':'denied') + ' for user #'+uid, 'success');
        })
        .catch(function() {
            label.classList.remove('perm-saving');
            label.textContent = val ? '✓' : '✗';
            if (window.fw) fw.toast('Save failed', 'error');
        });
}

// ── Apply preset to single user ────────────────────────────────────────────
function applyPreset(uid, preset) {
    var row = document.getElementById('pmrow-' + uid);
    if (!row) return;
    row.querySelectorAll('.perm-toggle').forEach(function(l){ l.classList.add('perm-saving'); });

    var fd = new FormData();
    fd.append('uid', uid);
    fd.append('preset', preset);
    fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

    fetch('?ajax=apply_preset', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (!d.ok) return;
            PERM_COLS.forEach(function(col) {
                var cb = document.getElementById('pc-'+uid+'-'+col);
                if (!cb) return;
                var v = d.perms[col] || 0;
                cb.checked = v === 1;
                var label = cb.nextElementSibling;
                label.classList.remove('perm-saving');
                label.textContent = v ? '✓' : '✗';
            });
            if (window.fw) fw.toast('Applied ' + preset + ' preset to user #' + uid, 'success');
        })
        .catch(function() {
            row.querySelectorAll('.perm-toggle').forEach(function(l){ l.classList.remove('perm-saving'); });
        });
}

// ── Apply preset to ALL active users ──────────────────────────────────────
function applyPresetAll(preset) {
    if (!confirm('Apply "' + preset + '" preset to ALL active users? This will overwrite individual permissions.')) return;
    document.querySelectorAll('#perm-matrix-table tbody tr').forEach(function(row) {
        var id = row.id.replace('pmrow-','');
        if (id) applyPreset(parseInt(id), preset);
    });
}
</script>

<?php include "includes/layout_end.php"; ?>
