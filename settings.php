<?php
ob_start();
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "Settings";
$active_page = "settings";
$userid   = current_userid();
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$seasonid = (int)($_SESSION['seasonid'] ?? 0);
$today    = date('Y-m-d');
$is_admin = (int)($_SESSION['is_admin'] ?? 0);
$msg = $err = '';

// ── POST HANDLERS ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        ob_clean();
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'CSRF verification failed. Request blocked.']);
        exit;
    }
    $action = $_POST['action'] ?? '';

    // Selling date (AJAX)
    if ($action === 'set_selling_date') {
        ob_end_clean(); header('Content-Type: application/json');
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? $today));
        $rate = (float)($_POST['rate'] ?? 0);
        $_cx  = $conn->query("SELECT id FROM start_of_day WHERE description='$desc' AND active=1 LIMIT 1");
        if ($_cx && $_cx !== true && $_cx->num_rows) {
            echo json_encode(['error'=>"Selling date '$desc' already exists."]); exit;
        }
        $conn->query("INSERT INTO start_of_day (userid,seasonid,description) VALUES ($userid,$seasonid,'$desc')");
        $sodid = (int)$conn->insert_id;
        if ($rate > 0 && $sodid) {
            $conn->query("INSERT INTO exchange_rate (userid,seasonid,start_of_dayid,amount) VALUES ($userid,$seasonid,$sodid,$rate)");
        }
        echo json_encode(['ok'=>true,'sodid'=>$sodid,'desc'=>$desc,'rate'=>$rate,'msg'=>"Selling date set: $desc".($rate>0?" · Rate: 1 USD = $rate ZiG":'')]);
        exit;
    }

    // Update exchange rate (AJAX)
    if ($action === 'update_rate') {
        ob_end_clean(); header('Content-Type: application/json');
        $sodid = (int)($_POST['sodid'] ?? 0);
        $rate  = (float)($_POST['rate'] ?? 0);
        if (!$sodid || $rate <= 0) { echo json_encode(['error'=>'Invalid']); exit; }
        if (!flowork_on_floor($conn, 'start_of_day', $sodid, $spid)) { echo json_encode(['error'=>'Sale day not found on this floor']); exit; }
        $_eq = $conn->query("SELECT id, amount FROM exchange_rate WHERE start_of_dayid=$sodid LIMIT 1");
        $_old_rate = ($_eq && $_eq !== true && $_eq->num_rows) ? $_eq->fetch_assoc()['amount'] : null;
        $_eq = $conn->query("SELECT id FROM exchange_rate WHERE start_of_dayid=$sodid LIMIT 1");
        // The rate converts every ZiG figure for that day, so record who changed it.
        audit_log($conn, $spid, $userid, 'exchange_rate', $_old_rate === null ? 'create' : 'update', $sodid,
            $_old_rate === null ? null : ['amount' => $_old_rate], ['amount' => $rate]);
        if ($_eq && $_eq !== true && $_eq->num_rows) {
            $conn->query("UPDATE exchange_rate SET amount=$rate WHERE start_of_dayid=$sodid");
        } else {
            $conn->query("INSERT INTO exchange_rate (userid,seasonid,start_of_dayid,amount) VALUES ($userid,$seasonid,$sodid,$rate)");
        }
        echo json_encode(['ok'=>true,'msg'=>"Rate updated: 1 USD = $rate ZiG"]);
        exit;
    }

    // RBZ rates (AJAX)
    if ($action === 'set_rbz') {
        ob_end_clean(); header('Content-Type: application/json');
        $u = (float)($_POST['usd_percent'] ?? 0) / 100;
        $o = (float)($_POST['other_percent'] ?? 0) / 100;
        if ($u < 0 || $u > 1 || $o < 0 || $o > 1) { echo json_encode(['error'=>'Percentages must be 0 to 100.']); exit; }
        if (abs($u + $o - 1) > 0.00005) {
            echo json_encode(['error'=>'USD and ZiG must add up to 100% (now '.round(($u+$o)*100, 2).'%).']); exit;
        }
        require_once __DIR__ . '/includes/rbz_change.php';
        $_rq = $conn->query("SELECT id, usd_percent FROM rbz_retention_rate LIMIT 1");
        $ex  = ($_rq && $_rq !== true) ? $_rq->fetch_assoc() : null;
        $old = $ex ? (float)$ex['usd_percent'] : null;
        if ($ex) $conn->query("UPDATE rbz_retention_rate SET usd_percent=$u,other_currency_percent=$o WHERE id={$ex['id']}");
        else     $conn->query("INSERT INTO rbz_retention_rate (usd_percent,other_currency_percent) VALUES ($u,$o)");
        $msg = 'RBZ retention updated.';
        if ($old === null || abs($old - $u) > 0.00005) {
            flowork_rbz_record_change($conn, $old, $u, (int)$userid, (string)($_SESSION['username'] ?? ''));
            audit_log($conn, $spid, $userid, 'rbz_retention_rate', 'update', $ex['id'] ?? null, ['usd_percent'=>$old], ['usd_percent'=>$u]);
            $msg .= ' Balanced sales keep the old rate until you run Apply stop orders on Balance & Settlement.';
        }
        echo json_encode(['ok'=>true,'msg'=>$msg]);
        exit;
    }

    // Add/update charge (AJAX)
    if ($action === 'add_charge') {
        ob_end_clean(); header('Content-Type: application/json');
        $sid  = (int)($_POST['statutoryid'] ?? 0);
        $ctid = (int)($_POST['charge_typeid'] ?? 0);
        $amt  = (float)($_POST['amount'] ?? 0);
        $cno  = (int)($_POST['creditor_no'] ?? 0);
        if (!$sid || !$ctid || $amt <= 0) {
            echo json_encode(['error'=>'All charge fields required.']); exit;
        }
        $conn->query("UPDATE grower_charges SET active=0 WHERE selling_pointid=$spid AND statutoryid=$sid");
        $conn->query("INSERT INTO grower_charges (userid,statutoryid,selling_pointid,seasonid,charge_typeid,amount,creditor_no) VALUES ($userid,$sid,$spid,$seasonid,$ctid,$amt,$cno)");
        $newid = (int)$conn->insert_id;
        // Get the full row back for display
        $row = $conn->query("SELECT gc.*,COALESCE(s.description,'') sd,COALESCE(ct.description,'') ctd,COALESCE(cur.description,'USD') cur
            FROM grower_charges gc
            LEFT JOIN statutory s ON s.id=gc.statutoryid
            LEFT JOIN charge_type ct ON ct.id=gc.charge_typeid
            LEFT JOIN currency cur ON cur.id=s.currencyid
            WHERE gc.id=$newid LIMIT 1")->fetch_assoc();
        echo json_encode(['ok'=>true,'msg'=>'Charge saved.','row'=>$row]);
        exit;
    }

    // Remove charge (AJAX)
    if ($action === 'remove_charge') {
        ob_end_clean(); header('Content-Type: application/json');
        $cid = (int)($_POST['charge_id'] ?? 0);
        if ($cid) $conn->query("UPDATE grower_charges SET active=0 WHERE id=$cid AND selling_pointid=$spid");
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Update charge inline (AJAX)
    if ($action === 'update_charge') {
        ob_end_clean(); header('Content-Type: application/json');
        $cid = (int)($_POST['charge_id'] ?? 0);
        $amt = (float)($_POST['amount'] ?? 0);
        if ($cid && $amt > 0) {
            $set = "amount=$amt";
            if (array_key_exists('creditor_no', $_POST)) {
                $cno = (int)$_POST['creditor_no'];
                $set .= ",creditor_no=$cno";
            }
            $conn->query("UPDATE grower_charges SET $set WHERE id=$cid AND selling_pointid=$spid");
            echo json_encode(['ok'=>true]);
        } else { echo json_encode(['error'=>'Invalid']); }
        exit;
    }

    // Company profile (multi-floor support)
    if ($action === 'save_company') {
        $conn->query("CREATE TABLE IF NOT EXISTS parameters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            param_key VARCHAR(150) NOT NULL,
            param_value TEXT NOT NULL DEFAULT '',
            selling_pointid INT DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_param (param_key, selling_pointid)
        )");
        // Add selling_pointid column if missing
        $chk = $conn->query("SHOW COLUMNS FROM parameters LIKE 'selling_pointid'");
        if (!$chk || $chk->num_rows === 0) {
            $conn->query("ALTER TABLE parameters ADD COLUMN selling_pointid INT DEFAULT 0");
            $conn->query("ALTER TABLE parameters DROP INDEX IF EXISTS param_key");
            $conn->query("ALTER TABLE parameters ADD UNIQUE KEY uk_param (param_key, selling_pointid)");
        }
        $prof_spid = (int)($_POST['profile_spid'] ?? $spid);
        $offtaker_pct = (float)($_POST['offtaker_comm_pct'] ?? 1.50);
        
        $stmt_sp = $conn->prepare("UPDATE selling_point SET offtaker_comm_pct = ? WHERE id = ?");
        $stmt_sp->bind_param("di", $offtaker_pct, $prof_spid);
        $stmt_sp->execute();
        $stmt_sp->close();

        $fields = ['name','address','phone','email','website','reg_number','vat_number','tagline','floor_name'];
        $stmt_param = $conn->prepare("INSERT INTO parameters (param_key,param_value,selling_pointid) VALUES (?,?,?)
                                      ON DUPLICATE KEY UPDATE param_value=?");
        foreach ($fields as $f) {
            $key_str = 'company_' . $f;
            $val = trim($_POST['company_'.$f] ?? '');
            $stmt_param->bind_param("ssis", $key_str, $val, $prof_spid, $val);
            $stmt_param->execute();
        }
        $stmt_param->close();

        if (!empty($_FILES['company_logo']['tmp_name']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','svg','webp'])) {
                if ($ext === 'svg') {
                    $svg_content = file_get_contents($_FILES['company_logo']['tmp_name']);
                    require_once __DIR__ . '/includes/svg_guard.php';
                    if (!flowork_svg_is_safe($svg_content)) {
                        ob_end_clean(); header('Content-Type: application/json');
                        echo json_encode(['error' => 'Security check failed: Uploaded SVG contains invalid scripting tags.']);
                        exit;
                    }
                }
                $filename = 'company_logo_'.$prof_spid.'.'.$ext;
                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $upload_dir.$filename)) {
                    $logo = 'uploads/'.$filename;
                    $stmt_logo = $conn->prepare("INSERT INTO parameters (param_key,param_value,selling_pointid) VALUES ('company_logo_path',?,?)
                                                 ON DUPLICATE KEY UPDATE param_value=?");
                    $stmt_logo->bind_param("sis", $logo, $prof_spid, $logo);
                    $stmt_logo->execute();
                    $stmt_logo->close();
                }
            }
        }
        ob_end_clean(); header('Content-Type: application/json');
        echo json_encode(['ok'=>true,'msg'=>'Company profile saved.']);
        exit;
    }

    // Add a brand-new company (AJAX): creates its floor, a trial licence like the
    // Multi-Floor page does, and its company profile. Saving a profile on an
    // existing floor's tab only ever changes that floor; this is how a new
    // company is added. Master users only, as on the Multi-Floor page.
    if ($action === 'add_company') {
        ob_end_clean(); header('Content-Type: application/json');
        if (!is_master_user()) {
            echo json_encode(['error' => 'Only a master user can add a new company.']); exit;
        }
        $co_name  = trim($_POST['company_name'] ?? '');
        $fl_name  = trim($_POST['floor_name'] ?? '') ?: $co_name;
        $fl_code  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['floor_code'] ?? ''));
        $comm     = round((float)($_POST['offtaker_comm_pct'] ?? 0.13), 4);
        if ($co_name === '')                   { echo json_encode(['error' => 'Enter the company name.']); exit; }
        if ($fl_code === '' || strlen($fl_code) > 8) { echo json_encode(['error' => 'Enter a short floor code (letters and numbers, up to 8), e.g. SMX.']); exit; }
        $dup = $conn->prepare("SELECT id, name FROM selling_point WHERE UPPER(floor_code)=? OR LOWER(name)=LOWER(?) LIMIT 1");
        $dup->bind_param('ss', $fl_code, $fl_name);
        $dup->execute();
        $d = $dup->get_result()->fetch_assoc();
        $dup->close();
        if ($d) { echo json_encode(['error' => "A floor with that name or code already exists ({$d['name']}). Open its tab to edit its profile."]); exit; }

        $addr  = trim($_POST['company_address'] ?? '');
        $phone = trim($_POST['company_phone'] ?? '');
        $email = trim($_POST['company_email'] ?? '');
        $ins = $conn->prepare("INSERT INTO selling_point (name, floor_code, active, is_active, company_name, address, phone, email, offtaker_comm_pct)
                               VALUES (?, ?, 1, 1, ?, ?, ?, ?, ?)");
        if (!$ins) { echo json_encode(['error' => 'Could not prepare the new floor: ' . $conn->error]); exit; }
        $ins->bind_param('ssssssd', $fl_name, $fl_code, $co_name, $addr, $phone, $email, $comm);
        if (!$ins->execute()) { echo json_encode(['error' => 'Could not create the floor: ' . $ins->error]); exit; }
        $new_spid = (int)$conn->insert_id;
        $ins->close();

        // Licence: same trial licence the Multi-Floor page gives a new floor
        $far_future = date('Y-m-d', strtotime('+3650 days'));
        $conn->query("INSERT INTO flowork_subscriptions (selling_pointid, status, license_key, start_date, end_date)
                      VALUES ($new_spid, 'Active', 'TRIAL-ACTIVE-LICENSE', CURDATE(), '$far_future')
                      ON DUPLICATE KEY UPDATE status='Active'");

        // Company profile, stored like save_company does
        $stmt_param = $conn->prepare("INSERT INTO parameters (param_key,param_value,selling_pointid) VALUES (?,?,?)
                                      ON DUPLICATE KEY UPDATE param_value=?");
        $_POST['company_floor_name'] = $_POST['company_floor_name'] ?? $fl_name;
        foreach (['name','address','phone','email','website','reg_number','vat_number','tagline','floor_name'] as $fld) {
            $key_str = 'company_' . $fld;
            $val = trim($_POST['company_' . $fld] ?? '');
            $stmt_param->bind_param("ssis", $key_str, $val, $new_spid, $val);
            $stmt_param->execute();
        }
        $stmt_param->close();

        // A master user can switch to it straight away (the list is otherwise built at sign-in)
        $_SESSION['accessible_floors'][] = ['id' => $new_spid, 'name' => $fl_name, 'floor_code' => $fl_code];

        audit_log($conn, 0, $userid, 'selling_point', 'create', $new_spid, null, ['name' => $fl_name, 'company' => $co_name, 'via' => 'settings_company']);
        echo json_encode(['ok' => true, 'id' => $new_spid, 'msg' => "Company '$co_name' added with its own floor ($fl_code)."]);
        exit;
    }

    // SO priorities (AJAX)
    if ($action === 'save_so_mode') {
        ob_end_clean(); header('Content-Type: application/json');
        $mode    = in_array($_POST['so_mode']??'', ['before','after']) ? $_POST['so_mode'] : 'before';
        $sp_save = max(1, $spid); // don't save with spid=0
        // Ensure table and columns exist
        $conn->query("CREATE TABLE IF NOT EXISTS parameters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            param_key VARCHAR(150) NOT NULL,
            param_value TEXT NOT NULL DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        // Add selling_pointid column if missing
        $chk_col = $conn->query("SHOW COLUMNS FROM parameters LIKE 'selling_pointid'");
        if (!$chk_col || $chk_col->num_rows === 0) {
            $conn->query("ALTER TABLE parameters ADD COLUMN selling_pointid INT NOT NULL DEFAULT 0");
        }
        // Check if unique key exists
        $uk = $conn->query("SHOW INDEX FROM parameters WHERE Key_name='uk_param'");
        if (!$uk || $uk->num_rows === 0) {
            $conn->query("ALTER TABLE parameters ADD UNIQUE KEY uk_param (param_key(100), selling_pointid)");
        }
        // Check existing
        $ex = $conn->query("SELECT id FROM parameters WHERE param_key='so_deduction_mode' AND selling_pointid=$sp_save LIMIT 1");
        if ($ex && $ex !== true && $ex->num_rows > 0) {
            $conn->query("UPDATE parameters SET param_value='$mode' WHERE param_key='so_deduction_mode' AND selling_pointid=$sp_save");
        } else {
            $conn->query("INSERT INTO parameters (param_key,param_value,selling_pointid) VALUES ('so_deduction_mode','$mode',$sp_save)");
        }
        if ($conn->error) {
            echo json_encode(['error'=>'DB error: '.$conn->error]); exit;
        }
        echo json_encode(['ok'=>true,'msg'=>'SO deduction mode set to: '.($mode==='before'?'Before RBZ Split':'After RBZ Split')]);
        exit;
    }

    if ($action === 'save_so_priorities') {
        ob_end_clean(); header('Content-Type: application/json');
        foreach (($_POST['priorities'] ?? []) as $id => $pri) {
            $id=(int)$id; $pri=(int)$pri;
            if ($id>0) $conn->query("UPDATE internal_stop_order_amount SET priority=$pri WHERE id=$id");
        }
        echo json_encode(['ok'=>true,'msg'=>'Stop order priorities saved.']);
        exit;
    }

    // ── TIMB File Keys (per selling point) ──────────────────────────────
    if ($action === 'save_timb_keys') {
        ob_end_clean(); header('Content-Type: application/json');
        $tk_spid  = (int)($_POST['tk_spid']  ?? $spid);
        // Booking keys
        $bk_key  = $conn->real_escape_string(trim($_POST['bk_system_key'] ?? ''));
        $bk_iv   = $conn->real_escape_string(trim($_POST['bk_vector']     ?? ''));
        // Stop order keys
        $sk_key  = $conn->real_escape_string(trim($_POST['sk_system_key'] ?? ''));
        $sk_iv   = $conn->real_escape_string(trim($_POST['sk_vector']     ?? ''));
        $saved   = [];
        // Ensure tables exist
        $conn->query("CREATE TABLE IF NOT EXISTS booking_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            selling_pointid INT NOT NULL DEFAULT 0,
            system_key VARCHAR(512) NOT NULL DEFAULT '',
            vector VARCHAR(256) NOT NULL DEFAULT '',
            datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_bk_sp (selling_pointid)
        )");
        $conn->query("CREATE TABLE IF NOT EXISTS stop_order_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            selling_pointid INT NOT NULL DEFAULT 0,
            system_key VARCHAR(512) NOT NULL DEFAULT '',
            vector VARCHAR(256) NOT NULL DEFAULT '',
            datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_sk_sp (selling_pointid)
        )");
        if (strlen($bk_key) >= 8) {
            $conn->query("INSERT INTO booking_keys (selling_pointid,system_key,vector) VALUES ($tk_spid,'$bk_key','$bk_iv')
                ON DUPLICATE KEY UPDATE system_key='$bk_key',vector='$bk_iv',datetime=NOW()");
            $saved[] = 'Booking key saved';
        }
        if (strlen($sk_key) >= 8) {
            $conn->query("INSERT INTO stop_order_keys (selling_pointid,system_key,vector) VALUES ($tk_spid,'$sk_key','$sk_iv')
                ON DUPLICATE KEY UPDATE system_key='$sk_key',vector='$sk_iv',datetime=NOW()");
            $saved[] = 'Stop order key saved';
        }
        if ($conn->error) { echo json_encode(['error'=>'DB: '.$conn->error]); exit; }
        if ($saved) echo json_encode(['ok'=>true,'msg'=>implode(' · ', $saved).'.' ]);
        else echo json_encode(['error'=>'Enter at least one key (min 8 chars).']);
        exit;
    }
}

// ── DATA QUERIES ──────────────────────────────────────────────────────────
// Selling days log
$sod_log = $conn->query("
    SELECT sod.id, sod.description,
           COALESCE(er.amount,0) rate,
           er.id er_id
    FROM start_of_day sod
    LEFT JOIN exchange_rate er ON er.id = (SELECT er1.id FROM exchange_rate er1 WHERE er1.start_of_dayid = sod.id ORDER BY er1.active DESC, er1.id DESC LIMIT 1)
    WHERE sod.seasonid=$seasonid AND sod.active=1
    ORDER BY sod.id DESC LIMIT 60
");
$sod_rows = ($sod_log && $sod_log !== true) ? $sod_log->fetch_all(MYSQLI_ASSOC) : [];

$_sod_today = $conn->query("SELECT id FROM start_of_day WHERE description='$today' AND active=1 LIMIT 1");
$sod_today  = ($_sod_today && $_sod_today !== true) ? $_sod_today->fetch_assoc() : null;
$_rate_q    = $sod_today ? $conn->query("SELECT amount FROM exchange_rate WHERE start_of_dayid={$sod_today['id']} LIMIT 1") : null;
$rate_today = ($_rate_q && $_rate_q !== true) ? $_rate_q->fetch_assoc() : null;

$_rbz_q = $conn->query("SELECT * FROM rbz_retention_rate LIMIT 1");
$rbz    = ($_rbz_q && $_rbz_q !== true) ? $_rbz_q->fetch_assoc() : null;

$statutory = $conn->query("SELECT s.*,COALESCE(c.description,'USD') currency FROM statutory s LEFT JOIN currency c ON c.id=s.currencyid ORDER BY s.id");
$ctypes    = $conn->query("SELECT * FROM charge_type ORDER BY id");
$charges_q = $conn->query("SELECT gc.*,COALESCE(s.description,'') sd,COALESCE(ct.description,'') ctd,COALESCE(cur.description,'USD') cur
    FROM grower_charges gc
    LEFT JOIN statutory s ON s.id=gc.statutoryid
    LEFT JOIN charge_type ct ON ct.id=gc.charge_typeid
    LEFT JOIN currency cur ON cur.id=s.currencyid
    WHERE gc.selling_pointid=$spid AND gc.active=1");
$charges_rows = ($charges_q && $charges_q !== true) ? $charges_q->fetch_all(MYSQLI_ASSOC) : [];

// Selling points for company profiles
$sp_all = $conn->query("SELECT id,name,floor_code,offtaker_comm_pct FROM selling_point WHERE active=1 ORDER BY name");
$sp_rows = ($sp_all && $sp_all !== true) ? $sp_all->fetch_all(MYSQLI_ASSOC) : [];

// Company profiles per selling point
$companies = [];
$conn->query("CREATE TABLE IF NOT EXISTS parameters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    param_key VARCHAR(150) NOT NULL,
    param_value TEXT NOT NULL DEFAULT '',
    selling_pointid INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_param (param_key, selling_pointid)
)");
$pq = $conn->query("SELECT param_key,param_value,selling_pointid FROM parameters WHERE param_key LIKE 'company_%'");
if ($pq) while ($pr = $pq->fetch_assoc()) {
    $k = str_replace('company_','',$pr['param_key']);
    $companies[$pr['selling_pointid']][$k] = $pr['param_value'];
}

// SO deduction mode setting
$so_mode_setting = 'before'; // default
$conn->query("CREATE TABLE IF NOT EXISTS parameters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    param_key VARCHAR(150) NOT NULL,
    param_value TEXT NOT NULL DEFAULT '',
    selling_pointid INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_param (param_key, selling_pointid)
)");
$sm_q = $conn->query("SELECT param_value FROM parameters WHERE param_key='so_deduction_mode' AND selling_pointid=$spid LIMIT 1");
if ($sm_q && $sm_q!==true && $sm_q->num_rows) $so_mode_setting = $sm_q->fetch_assoc()['param_value'];

// SO priorities
$so_list = [];
$so_q = $conn->query("
    SELECT isoa.id, isoa.amount, isoa.priority, isoa.processed,
           iso.description, iso.creditor_number,
           COALESCE(NULLIF(gc.name,''),gc.creditor_name,iso.creditor_number) cred_name,
           COALESCE(g.grower_num,'—') grower_num,
           COALESCE(CONCAT(g.name,' ',g.surname),'') grower_name
    FROM internal_stop_order_amount isoa
    JOIN internal_stop_order iso ON iso.id=isoa.internal_stop_orderid
    LEFT JOIN grower_creditors gc ON gc.creditor_no=iso.creditor_number
    LEFT JOIN growers g ON g.id=isoa.growerid
    WHERE (iso.selling_pointid=$spid OR iso.selling_pointid=0 OR $spid=0)
      AND isoa.active=1
    ORDER BY isoa.priority ASC, isoa.id ASC
");
if ($so_q && $so_q !== true) $so_list = $so_q->fetch_all(MYSQLI_ASSOC);

// ── TIMB keys per selling point ────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS booking_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    selling_pointid INT NOT NULL DEFAULT 0,
    system_key VARCHAR(512) NOT NULL DEFAULT '',
    vector VARCHAR(256) NOT NULL DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bk_sp (selling_pointid)
)");
$conn->query("CREATE TABLE IF NOT EXISTS stop_order_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    selling_pointid INT NOT NULL DEFAULT 0,
    system_key VARCHAR(512) NOT NULL DEFAULT '',
    vector VARCHAR(256) NOT NULL DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sk_sp (selling_pointid)
)");
$timb_keys = [];
$tk_bk = $conn->query("SELECT selling_pointid, system_key, vector, datetime FROM booking_keys");
if ($tk_bk && $tk_bk !== true) while ($r = $tk_bk->fetch_assoc()) $timb_keys[$r['selling_pointid']]['booking'] = $r;
$tk_sk = $conn->query("SELECT selling_pointid, system_key, vector, datetime FROM stop_order_keys");
if ($tk_sk && $tk_sk !== true) while ($r = $tk_sk->fetch_assoc()) $timb_keys[$r['selling_pointid']]['stoporder'] = $r;

include "includes/layout.php";
?>
<style>
/* Premium Settings Layout */
.settings-workspace {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 1.5rem;
    align-items: start;
    margin-top: 1rem;
}
@media (max-width: 900px) {
    .settings-workspace {
        grid-template-columns: 1fr;
    }
}

/* Premium Segmented / Capsule Tab Strip (Vertical) */
.premium-vertical-nav {
    display: flex;
    flex-direction: column;
    padding: 6px;
    background: var(--cream-d);
    border-radius: 12px;
    gap: 4px;
    border: 1px solid var(--border);
}
body.theme-dark .premium-vertical-nav {
    background: rgba(26,18,8,0.3);
}

.premium-tab-btn {
    padding: 0.8rem 1.2rem;
    background: transparent;
    border: none;
    border-radius: 8px;
    font-family: inherit;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--muted);
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 0.65rem;
    user-select: none;
    text-align: left;
    width: 100%;
}
.premium-tab-btn:hover {
    color: var(--ink);
    background: rgba(26, 18, 8, 0.04);
}
body.theme-dark .premium-tab-btn:hover {
    color: var(--cream);
    background: rgba(255, 255, 255, 0.04);
}
.premium-tab-btn.active {
    background:var(--primary-bg);
    color:var(--primary-text);
    box-shadow: 0 4px 12px rgba(26, 18, 8, 0.15);
    font-weight: 600;
}
body.theme-light .premium-tab-btn.active {
    background:var(--primary-bg);
    color:var(--primary-text);
}
body.theme-dark .premium-tab-btn.active {
    background: var(--gold);
    color: var(--ink);
    box-shadow: 0 4px 12px rgba(200, 149, 42, 0.2);
}

/* ── Modal redefined as Inline Tab Panel ── */
.sm-overlay {
    display: none;
    position: relative;
    inset: auto;
    z-index: auto;
    background: transparent;
    padding: 0;
    overflow: visible;
}
.sm-overlay.open {
    display: block;
    animation: settingsFadeIn 0.25s ease-in-out;
}
.sm-box {
    background:var(--surface);
    border-radius: 12px;
    max-width: 100% !important;
    margin: 0;
    box-shadow: 0 12px 40px rgba(26,18,8,0.03);
    overflow: hidden;
    border: 1px solid var(--border);
}
body.theme-dark .sm-box {
    background: var(--ink-soft);
}
.sm-box.wide {
    max-width: 100% !important;
}
.sm-head {
    background:var(--surface-2);
    color:var(--text);
    padding: 0.85rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.sm-title {
    font-family:'DM Sans',sans-serif;
    font-size:0.94rem;
    color: var(--gold);
    flex: 1;font-weight:600}
.sm-close {
    display: none; /* Hide close buttons since it's tabbed */
}
.sm-body {
    padding: 1.25rem;
}
.sm-foot {
    padding: 0.75rem 1.25rem;
    border-top: 2px solid var(--border);
    background: rgba(26, 18, 8, 0.015);
    display: flex;
    gap: 0.65rem;
    align-items: center;
    justify-content: flex-end;
}

@keyframes settingsFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ── Scrollable table inside modal ── */
.modal-tbl-wrap{max-height:340px;overflow-y:auto;border:1px solid var(--border);border-radius:8px}
.modal-tbl-wrap table thead th{position:sticky;top:0;background:var(--surface-2);color:var(--text-muted);
    font-size:.65rem;padding:.4rem .7rem;white-space:nowrap;z-index:2}
.modal-tbl-wrap table tbody td{font-size:.78rem;padding:.42rem .7rem}
.modal-tbl-wrap table tbody tr:hover td{background:rgba(200,149,42,.06)}

.rate-inp{width:90px;padding:.3rem .45rem;font-family:monospace;font-size:.82rem;
          border:1.5px solid var(--border);border-radius:5px;background:var(--surface);
          color:var(--text);text-align:right}
.rate-inp:focus{border-color:var(--gold);outline:none}

.sp-sect{font-size:.78rem;font-weight:600;color:var(--muted);margin:.9rem 0 .4rem}
.sp-sect:first-child{margin-top:0}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Settings</h2>
    <p>Floor setup, rates, charges and company profiles</p>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="settings-workspace">
    <!-- Left vertical tabs -->
    <div class="premium-vertical-nav">
        <button class="premium-tab-btn active" data-tab="date" onclick="openModal('modal-date')">
            <span>📅</span> Selling Date & Rate
        </button>
        <button class="premium-tab-btn" data-tab="rbz" onclick="openModal('modal-rbz')">
            <span>🏦</span> RBZ Retention
        </button>
        <button class="premium-tab-btn" data-tab="charges" onclick="openModal('modal-charges')">
            <span>💰</span> Grower Charges
        </button>
        <button class="premium-tab-btn" data-tab="company" onclick="openModal('modal-company')">
            <span>🏢</span> Company Profiles
        </button>
        <button class="premium-tab-btn" data-tab="so-mode" onclick="openModal('modal-so-mode')">
            <span>⚖️</span> SO Deduction Mode
        </button>
        <button class="premium-tab-btn" data-tab="so" onclick="openModal('modal-so')">
            <span>🛑</span> Stop Order Priorities
        </button>
        <button class="premium-tab-btn" data-tab="timb-keys" onclick="openModal('modal-timb-keys')">
            <span>🔑</span> TIMB File Keys
        </button>
    </div>
    
    <!-- Right side tab panels -->
    <div class="settings-panels" style="min-width: 0;">

<!-- ══════════════════════════════════════════════════════════════════
     MODAL 1: SELLING DATE & EXCHANGE RATE
═══════════════════════════════════════════════════════════════════ -->
<div class="sm-overlay" id="modal-date" onclick="if(event.target===this)closeModal('modal-date')">
<div class="sm-box">
  <div class="sm-head">
    <div class="sm-title">Selling Date &amp; Exchange Rate</div>
    <button class="sm-close" onclick="closeModal('modal-date')">&times;</button>
  </div>
  <div class="sm-body">
    <div class="sp-sect">Create New Selling Day</div>
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Selling Date</label>
        <input type="date" id="new-sod-date" class="form-control" value="<?=$today?>" required>
      </div>
      <div class="form-group">
        <label>Exchange Rate (ZiG per 1 USD)</label>
        <input type="number" id="new-sod-rate" class="form-control" step="0.0001" min="0"
               value="<?=$rate_today?number_format((float)$rate_today['amount'],4):'0.0000'?>"
               placeholder="e.g. 25.5123">
      </div>
    </div>
    <div style="margin-top:.5rem">
      <button class="btn btn-primary btn-sm" onclick="createSod()">+ Create Selling Day</button>
      <span id="sod-msg" style="font-size:.78rem;margin-left:.75rem;color:var(--leaf)"></span>
    </div>

    <div class="sp-sect" style="margin-top:1.25rem">All Selling Days This Season</div>
    <div class="modal-tbl-wrap">
    <table>
    <thead><tr>
      <th>#</th><th>Date</th>
      <th class="text-right">Rate (ZiG/USD)</th>
      <th class="text-right">Action</th>
    </tr></thead>
    <tbody>
    <?php foreach($sod_rows as $i=>$sod):?>
    <tr id="sod-row-<?=$sod['id']?>">
      <td style="color:var(--muted)"><?=count($sod_rows)-$i?></td>
      <td style="font-weight:<?=$sod['description']===$today?'700':'400'?>;
          color:<?=$sod['description']===$today?'var(--gold)':'inherit'?>">
        <?=htmlspecialchars($sod['description'])?>
        <?php if($sod['description']===$today):?><span class="badge badge-green" style="font-size:.72rem">Today</span><?php endif;?>
      </td>
      <td class="text-right">
        <input class="rate-inp" type="number" step="0.0001" min="0"
               id="rate-<?=$sod['id']?>"
               value="<?=number_format((float)$sod['rate'],4)?>"
               onkeydown="if(event.key==='Enter'){saveRate(<?=$sod['id']?>);event.preventDefault();}">
      </td>
      <td class="text-right">
        <button class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 8px"
                onclick="saveRate(<?=$sod['id']?>)">Save</button>
      </td>
    </tr>
    <?php endforeach;?>
    <?php if(empty($sod_rows)):?>
    <tr><td colspan="4" class="text-center text-muted" style="padding:1.5rem">No selling days created yet</td></tr>
    <?php endif;?>
    </tbody>
    </table>
    </div>
  </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     MODAL 2: RBZ RETENTION
═══════════════════════════════════════════════════════════════════ -->
<div class="sm-overlay" id="modal-rbz" onclick="if(event.target===this)closeModal('modal-rbz')">
<div class="sm-box" style="max-width:440px">
  <div class="sm-head">
    <div class="sm-title">RBZ Retention Rates</div>
    <button class="sm-close" onclick="closeModal('modal-rbz')">&times;</button>
  </div>
  <div class="sm-body">
    <p style="font-size:.8rem;color:var(--muted);margin-bottom:1rem">
      Set the percentage split of gross proceeds. USD + ZiG percentages should total 100%.
    </p>
    <div class="form-row cols-2">
      <div class="form-group">
        <label>USD Portion (%)</label>
        <input type="number" id="rbz-usd" class="form-control" step="0.1" min="0" max="100"
               value="<?=$rbz?round((float)$rbz['usd_percent']*100,1):70?>" placeholder="70">
      </div>
      <div class="form-group">
        <label>ZiG Portion (%)</label>
        <input type="number" id="rbz-zig" class="form-control" step="0.1" min="0" max="100"
               value="<?=$rbz?round((float)$rbz['other_currency_percent']*100,1):30?>" placeholder="30">
      </div>
    </div>
  </div>
  <div class="sm-foot">
    <button class="btn btn-ghost btn-sm" onclick="closeModal('modal-rbz')">Cancel</button>
    <button class="btn btn-primary" onclick="saveRbz()">&#10003; Save RBZ Rates</button>
  </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     MODAL 3: GROWER CHARGES
═══════════════════════════════════════════════════════════════════ -->
<div class="sm-overlay" id="modal-charges" onclick="if(event.target===this)closeModal('modal-charges')">
<div class="sm-box">
  <div class="sm-head">
    <div class="sm-title">Grower Charges</div>
    <button class="sm-close" onclick="closeModal('modal-charges')">&times;</button>
  </div>
  <div class="sm-body">
    <div class="sp-sect">Add New Charge</div>
    <div class="form-row cols-2" style="align-items:flex-end">
      <div class="form-group">
        <label>Statutory / Charge Name</label>
        <select id="ch-sid" class="form-control">
          <option value="">— Select —</option>
          <?php if($statutory&&$statutory!==true):$statutory->data_seek(0);while($s=$statutory->fetch_assoc()):?>
          <option value="<?=$s['id']?>"><?=htmlspecialchars($s['description'])?> (<?=$s['currency']?>)</option>
          <?php endwhile;endif;?>
        </select>
      </div>
      <div class="form-group">
        <label>Charge Type</label>
        <select id="ch-ctid" class="form-control">
          <option value="">— Select —</option>
          <?php if($ctypes&&$ctypes!==true):$ctypes->data_seek(0);while($ct=$ctypes->fetch_assoc()):?>
          <option value="<?=$ct['id']?>"><?=htmlspecialchars($ct['description'])?></option>
          <?php endwhile;endif;?>
        </select>
      </div>
      <div class="form-group">
        <label>Amount / Rate</label>
        <input type="number" id="ch-amt-new" class="form-control" step="0.0001" min="0" placeholder="e.g. 0.025">
      </div>
      <div class="form-group">
        <label>Creditor No. <span style="font-size:.65rem;color:var(--muted)">(for creditor reports)</span></label>
        <input type="number" id="ch-cno" class="form-control" min="0" placeholder="e.g. 5">
      </div>
      <div class="form-group">
        <button class="btn btn-primary" style="margin-top:1.55rem;width:100%" onclick="addCharge()">+ Add Charge</button>
      </div>
    </div>
    <div id="charge-err" style="font-size:.78rem;color:var(--danger);margin-top:.25rem;display:none"></div>

    <div class="sp-sect" style="margin-top:1rem">Active Charges</div>
    <div class="modal-tbl-wrap">
    <table id="charges-tbl">
    <thead><tr>
      <th>Charge</th><th>Type</th><th>Currency</th>
      <th class="text-right">Rate / Amount</th><th class="text-right">Creditor No.</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach($charges_rows as $ch):?>
    <tr id="ch-<?=$ch['id']?>">
      <td style="font-weight:500"><?=htmlspecialchars($ch['sd'])?></td>
      <td style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($ch['ctd'])?></td>
      <td><span class="badge <?=$ch['cur']==='USD'?'badge-green':'badge-blue'?>" style="font-size:.72rem"><?=$ch['cur']?></span></td>
      <td class="text-right">
        <input class="rate-inp" type="number" step="0.0001" min="0"
               id="ch-amt-<?=$ch['id']?>"
               value="<?=number_format((float)$ch['amount'],4)?>"
               onkeydown="if(event.key==='Enter'){saveCharge(<?=$ch['id']?>);event.preventDefault();}">
      </td>
      <td class="text-right">
        <input class="rate-inp" type="number" step="1" min="0" style="width:70px"
               id="ch-cno-<?=$ch['id']?>"
               value="<?=(int)($ch['creditor_no'] ?? 0)?>"
               onkeydown="if(event.key==='Enter'){saveCharge(<?=$ch['id']?>);event.preventDefault();}">
      </td>
      <td style="white-space:nowrap">
        <button class="btn btn-ghost btn-sm" style="font-size:.72rem;padding:2px 7px"
                onclick="saveCharge(<?=$ch['id']?>)">Save</button>
        <button class="btn btn-ghost btn-sm" style="font-size:.72rem;padding:2px 7px;color:var(--danger)"
                onclick="removeCharge(<?=$ch['id']?>)">Remove</button>
      </td>
    </tr>
    <?php endforeach;?>
    <?php if(empty($charges_rows)):?>
    <tr><td colspan="6" class="text-center text-muted" style="padding:1.5rem">No charges configured</td></tr>
    <?php endif;?>
    </tbody>
    </table>
    </div>
  </div>
  <div class="sm-foot">
    <button class="btn btn-ghost btn-sm" onclick="closeModal('modal-charges')">Close</button>
  </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     MODAL 4: COMPANY PROFILES
═══════════════════════════════════════════════════════════════════ -->
<div class="sm-overlay" id="modal-company" onclick="if(event.target===this)closeModal('modal-company')">
<div class="sm-box wide">
  <div class="sm-head">
    <div class="sm-title">Company Profiles</div>
    <button class="sm-close" onclick="closeModal('modal-company')">&times;</button>
  </div>
  <div class="sm-body" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">

    <?php if(empty($sp_rows)):?>
    <p class="text-muted text-center">No selling points configured. Create selling points first.</p>
    <?php else:?>

    <!-- Tab pills -->
    <div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:1.25rem" id="prof-tabs">
      <?php foreach($sp_rows as $i=>$sp):?>
      <button class="btn <?=$i===0?'btn-primary':'btn-ghost'?> btn-sm prof-tab"
              onclick="showProf(<?=$sp['id']?>,this)"
              style="font-size:.75rem">
        <?=htmlspecialchars($sp['name'])?>
        <?php $hasProf=!empty($companies[$sp['id']]['name']);?>
        <?php if($hasProf):?><span style="color:var(--gold)"> ✓</span><?php endif;?>
      </button>
      <?php endforeach;?>
      <button type="button" class="btn btn-ghost btn-sm prof-tab" onclick="showProf('new',this)" style="font-size:.75rem">+ Add company</button>
    </div>

    <!-- New company: creates its own floor and profile -->
    <div class="prof-panel" id="prof-new" style="display:none">
    <?php if(!is_master_user()):?>
      <div class="alert alert-info">Only a master user can add a new company. Ask a master user, or use the Multi-Floor page if you are one.</div>
    <?php else:?>
    <form class="company-form" id="add-company-form" onsubmit="return false">
    <p style="font-size:.82rem;color:var(--muted);margin-bottom:.85rem">
      Adds a new company with its <strong>own floor</strong>, so its sales sheets, bookings and reports stay separate.
      It gets a trial licence and appears on the floor list straight away.
    </p>
    <div class="form-row cols-2">
      <div class="form-group">
        <label for="nc-name">Company name *</label>
        <input type="text" id="nc-name" name="company_name" class="form-control" required placeholder="e.g. Servemox Tobacco (Pvt) Ltd">
      </div>
      <div class="form-group">
        <label for="nc-code">Floor code *</label>
        <input type="text" id="nc-code" name="floor_code" class="form-control" required maxlength="8" placeholder="e.g. SMX"
               style="text-transform:uppercase" autocomplete="off">
      </div>
    </div>
    <div class="form-row cols-2">
      <div class="form-group">
        <label for="nc-floor">Floor name</label>
        <input type="text" id="nc-floor" name="floor_name" class="form-control" placeholder="Same as company name if left blank">
      </div>
      <div class="form-group">
        <label for="nc-brand">Floor / brand name on sales sheets</label>
        <input type="text" id="nc-brand" name="company_floor_name" class="form-control" placeholder="e.g. Servemox Tobacco Company">
      </div>
    </div>
    <div class="form-group">
      <label for="nc-addr">Address</label>
      <input type="text" id="nc-addr" name="company_address" class="form-control">
    </div>
    <div class="form-row cols-2">
      <div class="form-group"><label for="nc-phone">Phone</label><input type="text" id="nc-phone" name="company_phone" class="form-control"></div>
      <div class="form-group"><label for="nc-email">Email</label><input type="email" id="nc-email" name="company_email" class="form-control"></div>
    </div>
    <div class="form-row cols-2">
      <div class="form-group"><label for="nc-web">Website</label><input type="text" id="nc-web" name="company_website" class="form-control"></div>
      <div class="form-group"><label for="nc-tag">Tagline</label><input type="text" id="nc-tag" name="company_tagline" class="form-control"></div>
    </div>
    <div class="form-row cols-2">
      <div class="form-group"><label for="nc-reg">Registration number</label><input type="text" id="nc-reg" name="company_reg_number" class="form-control"></div>
      <div class="form-group"><label for="nc-vat">VAT number</label><input type="text" id="nc-vat" name="company_vat_number" class="form-control"></div>
    </div>
    <div class="form-row cols-2">
      <div class="form-group">
        <label for="nc-comm">Offtaker commission rate (cents per kg)</label>
        <input type="number" step="0.0001" id="nc-comm" name="offtaker_comm_pct" class="form-control" value="0.1300">
      </div>
      <div class="form-group"></div>
    </div>
    <p style="font-size:.8rem;color:var(--muted)">You can add the logo afterwards on the new company's tab.</p>
    <div style="text-align:right;margin-top:.5rem">
      <button type="button" class="btn btn-primary" onclick="addCompany(this)">+ Add company</button>
    </div>
    </form>
    <?php endif;?>
    </div>

    <?php foreach($sp_rows as $i=>$sp):
      $c = $companies[$sp['id']] ?? [];
      $logo_path = $c['logo_path'] ?? '';
    ?>
    <div class="prof-panel" id="prof-<?=$sp['id']?>" style="display:<?=$i===0?'block':'none'?>">
    <form class="company-form" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" name="profile_spid" value="<?=$sp['id']?>">
    <p style="font-size:.72rem;color:var(--muted);margin-bottom:.85rem">
      Profile for <strong><?=htmlspecialchars($sp['name'])?></strong>
      (<?=htmlspecialchars($sp['floor_code'])?>)
      — appears on all sales sheets for this floor.
      To add a different company, use <strong>+ Add company</strong> instead: saving here replaces this floor's profile.
    </p>
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Company Name *</label>
        <input type="text" name="company_name" class="form-control"
               value="<?=htmlspecialchars($c['name']??'')?>" placeholder="e.g. Vision Leaf Tobacco Pvt Ltd">
      </div>
      <div class="form-group">
        <label>Floor / Brand Name</label>
        <input type="text" name="company_floor_name" class="form-control"
               value="<?=htmlspecialchars($c['floor_name']??'')?>" placeholder="e.g. Vision Leaf Tobacco">
      </div>
    </div>
    <div class="form-group">
      <label>Address</label>
      <input type="text" name="company_address" class="form-control"
             value="<?=htmlspecialchars($c['address']??'')?>"
             placeholder="e.g. Corner Highfield Road and Hood Road, Southerton, Harare">
    </div>
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Phone</label>
        <input type="text" name="company_phone" class="form-control"
               value="<?=htmlspecialchars($c['phone']??'')?>" placeholder="+263 77 123 4567">
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="company_email" class="form-control"
               value="<?=htmlspecialchars($c['email']??'')?>" placeholder="info@company.co.zw">
      </div>
    </div>
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Website</label>
        <input type="text" name="company_website" class="form-control"
               value="<?=htmlspecialchars($c['website']??'')?>" placeholder="www.company.co.zw">
      </div>
      <div class="form-group">
        <label>Tagline</label>
        <input type="text" name="company_tagline" class="form-control"
               value="<?=htmlspecialchars($c['tagline']??'')?>" placeholder="e.g. Zimbabwe Tobacco Floor Auction">
      </div>
    </div>
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Registration Number</label>
        <input type="text" name="company_reg_number" class="form-control"
               value="<?=htmlspecialchars($c['reg_number']??'')?>" placeholder="e.g. 124/200">
      </div>
      <div class="form-group">
        <label>VAT Number</label>
        <input type="text" name="company_vat_number" class="form-control"
               value="<?=htmlspecialchars($c['vat_number']??'')?>" placeholder="e.g. 123456">
      </div>
    </div>
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Offtaker Commission Rate (Cents per Kg)</label>
        <input type="number" step="0.0001" name="offtaker_comm_pct" class="form-control"
               value="<?=number_format((float)($sp['offtaker_comm_pct']??0.13), 4)?>" placeholder="e.g. 0.13">
      </div>
      <div class="form-group">
        <!-- Optional empty spacer -->
      </div>
    </div>
    <!-- Logo -->
    <div class="form-group">
      <label>Company Logo</label>
      <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
        <?php if($logo_path && file_exists(__DIR__.'/'.$logo_path)):?>
        <img src="<?=htmlspecialchars($logo_path)?>" style="height:50px;border:1px solid var(--border);border-radius:6px;padding:4px;background:var(--surface)">
        <?php endif;?>
        <input type="file" name="company_logo" accept="image/*" class="form-control" style="flex:1">
      </div>
    </div>
    <div style="text-align:right;margin-top:.5rem">
      <button type="button" class="btn btn-primary" onclick="saveCompany(this)">&#10003; Save Profile</button>
    </div>
    </form>
    </div>
    <?php endforeach;?>
    <?php endif;?>
  </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     MODAL 5b: STOP ORDER DEDUCTION MODE
═══════════════════════════════════════════════════════════════════ -->
<div class="sm-overlay" id="modal-so-mode" onclick="if(event.target===this)closeModal('modal-so-mode')">
<div class="sm-box" style="max-width:480px">
  <div class="sm-head">
    <div class="sm-title">Stop Order Deduction Mode</div>
    <button class="sm-close" onclick="closeModal('modal-so-mode')">&times;</button>
  </div>
  <div class="sm-body">
    <p style="font-size:.82rem;color:var(--muted);margin-bottom:1.25rem;line-height:1.6">
      Choose when TIMB global stop orders are deducted from the grower's proceeds.
      This setting applies to all sales sheets on this floor.
    </p>

    <?php
    $b_bord = $so_mode_setting==='before'?'var(--gold)':'var(--border)';
    $b_bg   = $so_mode_setting==='before'?'#fdf8ee':'var(--cream)';
    $b_rad  = $so_mode_setting==='before'?'var(--gold)':'transparent';
    $a_bord = $so_mode_setting==='after'?'var(--gold)':'var(--border)';
    $a_bg   = $so_mode_setting==='after'?'#fdf8ee':'var(--cream)';
    $a_rad  = $so_mode_setting==='after'?'var(--gold)':'transparent';
    ?>
    <div style="display:flex;flex-direction:column;gap:.75rem">
      <label id="so-mode-before" onclick="selectSOMode('before')"
             style="display:flex;align-items:flex-start;gap:.85rem;padding:.9rem 1rem;border-radius:9px;cursor:pointer;transition:.15s;border:2px solid <?=$b_bord?>;background:<?=$b_bg?>">
        <div id="so-radio-before" style="width:18px;height:18px;border-radius:50%;border:2px solid var(--gold);background:<?=$b_rad?>;flex-shrink:0;margin-top:2px"></div>
        <div>
          <div style="font-weight:600;font-size:.88rem;margin-bottom:.2rem">Before RBZ Split</div>
          <div style="font-size:.75rem;color:var(--muted);line-height:1.5">TIMB SO deducted from <strong>gross proceeds first</strong>, then RBZ 70/30 split applied to remainder.</div>
          <div style="font-size:.72rem;color:var(--muted);margin-top:.4rem;font-family:monospace">Gross &rarr; &minus;TIMB SO &rarr; &times;70% USD | &times;30% ZiG</div>
        </div>
      </label>
      <label id="so-mode-after" onclick="selectSOMode('after')"
             style="display:flex;align-items:flex-start;gap:.85rem;padding:.9rem 1rem;border-radius:9px;cursor:pointer;transition:.15s;border:2px solid <?=$a_bord?>;background:<?=$a_bg?>">
        <div id="so-radio-after" style="width:18px;height:18px;border-radius:50%;border:2px solid var(--gold);background:<?=$a_rad?>;flex-shrink:0;margin-top:2px"></div>
        <div>
          <div style="font-weight:600;font-size:.88rem;margin-bottom:.2rem">After RBZ Split (from USD portion)</div>
          <div style="font-size:.75rem;color:var(--muted);line-height:1.5">RBZ 70/30 split applied to <strong>full gross first</strong>, then TIMB SO deducted from 70% USD only.</div>
          <div style="font-size:.72rem;color:var(--muted);margin-top:.4rem;font-family:monospace">Gross &rarr; &times;70% USD &rarr; &minus;TIMB SO | &times;30% ZiG (unchanged)</div>
        </div>
      </label>
    </div>
    <input type="hidden" id="so-mode-val" value="<?=$so_mode_setting?>">
  </div>
  <div class="sm-foot">
    <button class="btn btn-ghost btn-sm" onclick="closeModal('modal-so-mode')">Cancel</button>
    <button class="btn btn-primary" onclick="saveSOMode()">✓ Save Setting</button>
  </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     MODAL 5: STOP ORDER PRIORITIES
═══════════════════════════════════════════════════════════════════ -->
<div class="sm-overlay" id="modal-so" onclick="if(event.target===this)closeModal('modal-so')">
<div class="sm-box">
  <div class="sm-head">
    <div class="sm-title">Stop Order Priorities</div>
    <button class="sm-close" onclick="closeModal('modal-so')">&times;</button>
  </div>
  <?php if(empty($so_list)):?>
  <div class="sm-body text-center text-muted" style="padding:2rem">
    No TIMB stop orders loaded.
    <a href="file_manager_uploads.php" style="color:var(--gold)">Upload a Stop Order CSV</a> first.
  </div>
  <?php else:?>
  <form id="so-prio-form">
  <div class="sm-body" style="padding-bottom:.5rem">
    <p style="font-size:.78rem;color:var(--muted);margin-bottom:.85rem">
      Lower number = deducted first. Processed stop orders cannot be reordered.
    </p>
    <div class="modal-tbl-wrap">
    <table>
    <thead><tr>
      <th style="width:70px">Priority</th>
      <th>Creditor</th><th>Creditor #</th>
      <th>Grower</th>
      <th class="text-right">Amount</th>
      <th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach($so_list as $so):?>
    <tr style="<?=$so['processed']?'opacity:.45':''?>">
      <td>
        <input type="number" name="priorities[<?=$so['id']?>]"
               value="<?=(int)$so['priority']?>" min="1" max="999"
               class="rate-inp" style="width:55px;text-align:center"
               <?=$so['processed']?'disabled':''?>>
      </td>
      <td style="font-weight:500"><?=htmlspecialchars($so['cred_name'])?></td>
      <td class="td-mono" style="color:var(--gold);font-size:.72rem"><?=htmlspecialchars($so['creditor_number'])?></td>
      <td>
        <span style="color:var(--gold);font-family:monospace;font-size:.7rem"><?=htmlspecialchars($so['grower_num'])?></span>
        <span style="color:var(--muted);font-size:.7rem"> <?=htmlspecialchars($so['grower_name'])?></span>
      </td>
      <td class="text-right" style="font-weight:600">$<?=number_format((float)$so['amount'],2)?></td>
      <td>
        <span class="badge <?=$so['processed']?'badge-green':'badge-gold'?>" style="font-size:.72rem">
          <?=$so['processed']?'Processed':'Pending'?>
        </span>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
    </table>
    </div>
    <div style="margin-top:.65rem;font-size:.7rem;color:var(--muted)">
      <?=count(array_filter($so_list,fn($s)=>!$s['processed']))?> pending &nbsp;&middot;&nbsp;
      <?=count(array_filter($so_list,fn($s)=>$s['processed']))?> processed &nbsp;&middot;&nbsp;
      Total pending: $<?=number_format(array_sum(array_column(array_filter($so_list,fn($s)=>!$s['processed']),'amount')),2)?>
    </div>
  </div>
  <div class="sm-foot">
    <button type="button" class="btn btn-ghost btn-sm" onclick="autoNumber()">Auto-number</button>
    <span style="flex:1"></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('modal-so')">Cancel</button>
    <button class="btn btn-primary" onclick="saveSoPriorities()">&#10003; Save Priorities</button>
  </div>
  </form>
  <?php endif;?>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     MODAL 6: TIMB FILE KEYS (per selling point)
═══════════════════════════════════════════════════════════════════ -->
<div class="sm-overlay" id="modal-timb-keys" onclick="if(event.target===this)closeModal('modal-timb-keys')">
<div class="sm-box wide">
  <div class="sm-head">
    <div class="sm-title">🔑 TIMB File Decryption Keys</div>
    <button class="sm-close" onclick="closeModal('modal-timb-keys')">&times;</button>
  </div>
  <div class="sm-body" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
    <p style="font-size:.78rem;color:var(--muted);margin-bottom:1rem;line-height:1.6">
      Enter the AES-256 encryption keys provided by TIMB for each selling point / floor.
      These are used to decrypt the encrypted booking and stop order files (<code>.encry_en</code>).
      Each floor may have different keys.
    </p>

    <?php if(empty($sp_rows)):?>
    <p class="text-muted text-center">No selling points configured.</p>
    <?php else:?>

    <!-- Floor tab pills -->
    <div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:1.25rem" id="tk-tabs">
      <?php foreach($sp_rows as $i=>$sp):
        $has_bk = !empty($timb_keys[$sp['id']]['booking']['system_key']);
        $has_sk = !empty($timb_keys[$sp['id']]['stoporder']['system_key']);
        $both   = $has_bk && $has_sk;
        $any    = $has_bk || $has_sk;
      ?>
      <button class="btn <?=$i===0?'btn-primary':'btn-ghost'?> btn-sm tk-tab"
              onclick="showTkPanel(<?=$sp['id']?>,this)"
              style="font-size:.75rem" id="tk-tab-<?=$sp['id']?>">
        <?=htmlspecialchars($sp['name'])?>
        <?php if($both):?><span style="color:#27ae60"> ✓✓</span>
        <?php elseif($any):?><span style="color:var(--gold)"> ✓</span>
        <?php endif;?>
      </button>
      <?php endforeach;?>
    </div>

    <?php foreach($sp_rows as $i=>$sp):
      $bk = $timb_keys[$sp['id']]['booking']  ?? [];
      $sk = $timb_keys[$sp['id']]['stoporder'] ?? [];
    ?>
    <div class="tk-panel" id="tk-panel-<?=$sp['id']?>" style="display:<?=$i===0?'block':'none'?>">
      <p style="font-size:.72rem;color:var(--muted);margin-bottom:1rem">
        Keys for <strong><?=htmlspecialchars($sp['name'])?></strong>
        (<?=htmlspecialchars($sp['floor_code'])?>)
      </p>

      <!-- Booking Keys -->
      <div style="background:var(--cream-d,#faf8f4);border:1px solid var(--border);border-radius:9px;padding:1rem;margin-bottom:1rem">
        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem">
          <span style="font-size:.85rem">📋</span>
          <strong style="font-size:.82rem">Booking File Keys</strong>
          <?php if(!empty($bk['system_key'])):?>
          <span class="badge badge-green" style="font-size:.72rem">✓ Configured</span>
          <?php if(!empty($bk['datetime'])):?>
          <span style="font-size:.65rem;color:var(--muted)">Updated <?=date('d M Y H:i',strtotime($bk['datetime']))?></span>
          <?php endif;?>
          <?php else:?>
          <span class="badge badge-gray" style="font-size:.72rem">Not set</span>
          <?php endif;?>
        </div>
        <div class="form-row cols-2">
          <div class="form-group">
            <label>System Key (AES key from TIMB)</label>
            <input type="text" id="bk-key-<?=$sp['id']?>" class="form-control"
                   value="<?=htmlspecialchars($bk['system_key']??'')?>"
                   placeholder="256-bit key" style="font-family:monospace;font-size:.75rem">
          </div>
          <div class="form-group">
            <label>Initialization Vector (IV)</label>
            <input type="text" id="bk-iv-<?=$sp['id']?>" class="form-control"
                   value="<?=htmlspecialchars($bk['vector']??'')?>"
                   placeholder="IV from TIMB" style="font-family:monospace;font-size:.75rem">
          </div>
        </div>
      </div>

      <!-- Stop Order Keys -->
      <div style="background:var(--cream-d,#faf8f4);border:1px solid var(--border);border-radius:9px;padding:1rem;margin-bottom:1rem">
        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem">
          <span style="font-size:.85rem">🛑</span>
          <strong style="font-size:.82rem">Stop Order File Keys</strong>
          <?php if(!empty($sk['system_key'])):?>
          <span class="badge badge-green" style="font-size:.72rem">✓ Configured</span>
          <?php if(!empty($sk['datetime'])):?>
          <span style="font-size:.65rem;color:var(--muted)">Updated <?=date('d M Y H:i',strtotime($sk['datetime']))?></span>
          <?php endif;?>
          <?php else:?>
          <span class="badge badge-gray" style="font-size:.72rem">Not set</span>
          <?php endif;?>
        </div>
        <div class="form-row cols-2">
          <div class="form-group">
            <label>System Key</label>
            <input type="text" id="sk-key-<?=$sp['id']?>" class="form-control"
                   value="<?=htmlspecialchars($sk['system_key']??'')?>"
                   placeholder="256-bit key" style="font-family:monospace;font-size:.75rem">
          </div>
          <div class="form-group">
            <label>Initialization Vector (IV)</label>
            <input type="text" id="sk-iv-<?=$sp['id']?>" class="form-control"
                   value="<?=htmlspecialchars($sk['vector']??'')?>"
                   placeholder="IV from TIMB" style="font-family:monospace;font-size:.75rem">
          </div>
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:.75rem">
        <button class="btn btn-primary" onclick="saveTimbKeys(<?=$sp['id']?>)">&#10003; Save Keys for <?=htmlspecialchars($sp['name'])?></button>
        <span id="tk-msg-<?=$sp['id']?>" style="font-size:.78rem;color:var(--leaf)"></span>
      </div>
    </div>
    <?php endforeach;?>
    <?php endif;?>
  </div>
  <div class="sm-foot">
    <button class="btn btn-ghost btn-sm" onclick="closeModal('modal-timb-keys')">Close</button>
  </div>
</div></div></div></div>

<script>
// ── Tabbed Settings Controller ─────────────────────────────────────────────
function openModal(id) {
    // Hide all tab panels (sm-overlay)
    document.querySelectorAll('.sm-overlay').forEach(el => el.classList.remove('open'));
    
    // Show selected tab panel
    const targetPanel = document.getElementById(id);
    if (targetPanel) {
        targetPanel.classList.add('open');
    }
    
    // Update active state in vertical tab strip
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    
    const tabName = id.replace('modal-', '');
    const targetBtn = document.querySelector(`[data-tab="${tabName}"]`);
    if (targetBtn) {
        targetBtn.classList.add('active');
    }
    
    // Store in sessionStorage to persist
    sessionStorage.setItem('active_settings_tab', tabName);
}

function closeModal(id) {
    // Keep the panel active, no-op or close it.
}

document.addEventListener('DOMContentLoaded', () => {
    // Restore active settings tab
    const activeTab = sessionStorage.getItem('active_settings_tab') || 'date';
    openModal('modal-' + activeTab);
});

document.addEventListener('keydown',function(e){
    if(e.key==='Escape') document.querySelectorAll('.sm-overlay.open').forEach(function(m){m.classList.remove('open');});
});

function notify(msg, type){
    // Try fw.toast first, fallback to our own simple notification
    if(window.fw && fw.toast){ fw.toast(msg, type||'success'); return; }
    var n = document.getElementById('set-notify');
    if(!n){
        n = document.createElement('div');
        n.id = 'set-notify';
        n.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:99999;min-width:260px;max-width:380px;padding:.75rem 1rem;border-radius:8px;font-size:.85rem;font-weight:500;box-shadow:0 4px 20px rgba(0,0,0,.25);transition:.3s';
        document.body.appendChild(n);
    }
    n.style.background = (type==='error')?'#c0392b':'#2d6b25';
    n.style.color = '#fff';
    n.textContent = msg;
    n.style.opacity='1';
    clearTimeout(n._t);
    n._t = setTimeout(function(){ n.style.opacity='0'; }, 3500);
}

function post(data){
    var fd = new FormData();
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');
    return fetch('settings.php', {method:'POST', body:fd})
        .then(function(r){ return r.text(); })
        .then(function(t){
            t = t.trim();
            // Strip any PHP warning text before the JSON — cut at the FIRST '{'
            // (lastIndexOf broke nested responses like add_charge's {..,"row":{..}})
            var start = t.indexOf('{');
            if(start > 0) t = t.substring(start);
            try{ return JSON.parse(t); }
            catch(e){ throw new Error(t.substring(0,400)); }
        });
}

// Alias
function toast(msg,type){ notify(msg,type); }

// ── Selling Date ───────────────────────────────────────────────────────────
function createSod(){
    var d = document.getElementById('new-sod-date').value;
    var r = document.getElementById('new-sod-rate').value;
    console.log('createSod fired: date='+d+' rate='+r);
    if(!d){ notify('Select a date','error'); return; }
    notify('Saving...','info');
    post({action:'set_selling_date',description:d,rate:r})
    .then(function(res){
        console.log('createSod response:',res);
        if(res.error){ notify(res.error,'error'); return; }
        notify(res.msg,'success');
        // Add row to table
        var tbody = document.querySelector('#modal-date .modal-tbl-wrap tbody');
        var count = tbody.querySelectorAll('tr').length + 1;
        var isToday = d === '<?=$today?>';
        var row = '<tr id="sod-row-'+res.sodid+'">'
            +'<td style="color:var(--muted)">'+count+'</td>'
            +'<td style="font-weight:'+(isToday?'700':'400')+';color:'+(isToday?'var(--gold)':'inherit')+'">'
            +d+(isToday?' <span class="badge badge-green" style="font-size:.72rem">Today</span>':'')+'</td>'
            +'<td class="text-right"><input class="rate-inp" type="number" step="0.0001" id="rate-'+res.sodid+'" value="'+parseFloat(r||0).toFixed(4)+'" data-sodid="'+res.sodid+'" class="rate-inp sod-rate-inp"></td>'
            +'<td class="text-right"><button class="btn btn-ghost btn-sm" style="font-size:.65rem;padding:3px 8px" onclick="saveRate('+res.sodid+')">Save</button></td>'
            +'</tr>';
        // Remove empty row if present
        var empty = tbody.querySelector('td[colspan]');
        if(empty) empty.parentElement.remove();
        tbody.insertAdjacentHTML('afterbegin', row);
        document.getElementById('sod-msg').textContent = 'Created: '+d;
    })
    .catch(function(e){ toast(e.message,'error'); });
}

// ── Exchange Rate ──────────────────────────────────────────────────────────
function saveRate(sodid){
    var val = document.getElementById('rate-'+sodid).value;
    post({action:'update_rate',sodid:sodid,rate:val})
    .then(function(d){ if(d.ok) toast(d.msg); else toast(d.error,'error'); });
}

// ── RBZ ───────────────────────────────────────────────────────────────────
function saveRbz(){
    var u = document.getElementById('rbz-usd').value;
    var z = document.getElementById('rbz-zig').value;
    console.log('saveRbz fired usd='+u+' zig='+z);
    notify('Saving...','info');
    post({action:'set_rbz',usd_percent:u,other_percent:z})
    .then(function(d){
        console.log('saveRbz response:',d);
        if(d.ok) notify(d.msg,'success');
        else notify(d.error,'error');
    }).catch(function(e){ notify(e.message,'error'); });
}

// ── Charges ───────────────────────────────────────────────────────────────
function addCharge(){
    var sid  = document.getElementById('ch-sid').value;
    var ctid = document.getElementById('ch-ctid').value;
    var amt  = document.getElementById('ch-amt-new').value;
    var cno  = document.getElementById('ch-cno').value || 0;
    var errEl = document.getElementById('charge-err');
    errEl.style.display='none';
    console.log('addCharge fired sid='+sid+' ctid='+ctid+' amt='+amt);
    if(!sid||!ctid||!amt||parseFloat(amt)<=0){
        errEl.textContent='All fields are required.';
        errEl.style.display='block';
        notify('Fill all charge fields','error');
        return;
    }
    notify('Saving charge...','info');
    post({action:'add_charge',statutoryid:sid,charge_typeid:ctid,amount:amt,creditor_no:cno})
    .then(function(d){
        console.log('addCharge response:',d);
        if(d.error){ errEl.textContent=d.error; errEl.style.display='block'; notify(d.error,'error'); return; }
        notify(d.msg,'success');
        // Reset form
        document.getElementById('ch-sid').value='';
        document.getElementById('ch-ctid').value='';
        document.getElementById('ch-amt-new').value='';
        document.getElementById('ch-cno').value='';
        // Append row to table
        var r = d.row;
        var tbody = document.querySelector('#charges-tbl tbody');
        var empty = tbody.querySelector('td[colspan]');
        if(empty) empty.parentElement.remove();
        var cur = r.cur||'USD';
        var badgeClass = cur==='USD'?'badge-green':'badge-blue';
        var html = '<tr id="ch-'+r.id+'">'
            +'<td style="font-weight:500">'+xe(r.sd)+'</td>'
            +'<td style="font-size:.72rem;color:var(--muted)">'+xe(r.ctd)+'</td>'
            +'<td><span class="badge '+badgeClass+'" style="font-size:.72rem">'+cur+'</span></td>'
            +'<td class="text-right"><input class="rate-inp" type="number" step="0.0001" min="0" id="ch-amt-'+r.id+'" value="'+parseFloat(r.amount).toFixed(4)+'" data-chid="'+r.id+'"></td>'
            +'<td class="text-right"><input class="rate-inp" type="number" step="1" min="0" style="width:70px" id="ch-cno-'+r.id+'" value="'+(parseInt(r.creditor_no)||0)+'"></td>'
            +'<td style="white-space:nowrap">'
            +'<button class="btn btn-ghost btn-sm" style="font-size:.72rem;padding:2px 7px" onclick="saveCharge('+r.id+')">Save</button> '
            +'<button class="btn btn-ghost btn-sm" style="font-size:.72rem;padding:2px 7px;color:var(--danger)" onclick="removeCharge('+r.id+')">Remove</button>'
            +'</td></tr>';
        tbody.insertAdjacentHTML('beforeend',html);
    })
    .catch(function(e){ errEl.textContent=e.message; errEl.style.display='block'; });
}

function saveCharge(id){
    var val = document.getElementById('ch-amt-'+id).value;
    var body = {action:'update_charge',charge_id:id,amount:val};
    var cnoEl = document.getElementById('ch-cno-'+id);
    if(cnoEl) body.creditor_no = cnoEl.value || 0;
    post(body)
    .then(function(d){ if(d.ok) toast('Charge updated'); else toast(d.error,'error'); });
}

function removeCharge(id){
    if(!confirm('Remove this charge?')) return;
    post({action:'remove_charge',charge_id:id})
    .then(function(d){
        if(d.ok){
            var row=document.getElementById('ch-'+id);
            if(row) row.remove();
            toast('Charge removed');
        }
    });
}

// ── Company profiles ───────────────────────────────────────────────────────
function showProf(spid,btn){
    document.querySelectorAll('.prof-panel').forEach(function(p){p.style.display='none';});
    document.querySelectorAll('.prof-tab').forEach(function(b){b.classList.remove('btn-primary');b.classList.add('btn-ghost');});
    document.getElementById('prof-'+spid).style.display='block';
    btn.classList.add('btn-primary');
    btn.classList.remove('btn-ghost');
}

function saveCompany(btn){
    var form = btn.closest('form');
    var spid = form.querySelector('[name="profile_spid"]').value;
    var fd   = new FormData(form);
    fd.append('action','save_company');
    fd.append('profile_spid',spid);
    fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');
    btn.disabled=true; btn.textContent='Saving...';
    fetch('settings.php',{method:'POST',body:fd})
    .then(function(r){ return r.text().then(function(t){ try{return JSON.parse(t);}catch(e){throw new Error(t.substring(0,200));} }); })
    .then(function(d){
        btn.disabled=false; btn.innerHTML='&#10003; Save Profile';
        if(d.ok){ toast(d.msg); }
        else toast(d.error||'Error','error');
    })
    .catch(function(e){ btn.disabled=false; btn.innerHTML='&#10003; Save Profile'; toast(e.message,'error'); });
}

function addCompany(btn){
    var form = document.getElementById('add-company-form');
    var name = form.querySelector('[name="company_name"]'), code = form.querySelector('[name="floor_code"]');
    if (!name.value.trim()) { name.focus(); toast('Enter the company name.','error'); return; }
    if (!code.value.trim()) { code.focus(); toast('Enter a short floor code, e.g. SMX.','error'); return; }
    var fd = new FormData(form);
    fd.append('action','add_company');
    fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');
    btn.disabled = true; btn.textContent = 'Adding…';
    fetch('settings.php',{method:'POST',body:fd})
    .then(function(r){ return r.text().then(function(t){ try{return JSON.parse(t);}catch(e){throw new Error(t.substring(0,200));} }); })
    .then(function(d){
        if (d.ok) {
            toast(d.msg);
            // reopen Company Profiles on the new company's tab
            setTimeout(function(){ location.href = 'settings.php?company=' + d.id; }, 700);
        } else {
            btn.disabled = false; btn.textContent = '+ Add company';
            toast(d.error || 'Error','error');
        }
    })
    .catch(function(e){ btn.disabled = false; btn.textContent = '+ Add company'; toast(e.message,'error'); });
}
// After adding a company: open Company Profiles on its tab
(function(){
    var id = new URLSearchParams(location.search).get('company');
    if (!id) return;
    document.addEventListener('DOMContentLoaded', function(){
        openModal('modal-company');
        var panel = document.getElementById('prof-' + id);
        var tab = [...document.querySelectorAll('.prof-tab')].find(function(b){ return (b.getAttribute('onclick')||'').indexOf('showProf(' + id + ',') === 0; });
        if (panel && tab) showProf(id, tab);
    });
})();

// ── SO Priorities ──────────────────────────────────────────────────────────
// Delegated keydown for dynamically added rate/charge inputs
document.addEventListener('keydown', function(e){
    if(e.key !== 'Enter') return;
    var t = e.target;
    if(t.dataset.sodid){ e.preventDefault(); saveRate(t.dataset.sodid); }
    if(t.dataset.chid){  e.preventDefault(); saveCharge(t.dataset.chid); }
});

function autoNumber(){
    var inputs=document.querySelectorAll('input[name^="priorities"]');
    var n=1;
    inputs.forEach(function(inp){ if(!inp.disabled) inp.value=n++; });
}

function saveSoPriorities(){
    var inputs=document.querySelectorAll('input[name^="priorities"]');
    var fd=new FormData();
    fd.append('action','save_so_priorities');
    fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');
    inputs.forEach(function(inp){
        var m=inp.name.match(/priorities\[(\d+)\]/);
        if(m) fd.append('priorities['+m[1]+']',inp.value);
    });
    fetch('settings.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){ if(d.ok) toast(d.msg); else toast(d.error,'error'); });
}

function xe(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── TIMB File Keys ─────────────────────────────────────────────────────────
function showTkPanel(spid, btn){
    document.querySelectorAll('.tk-panel').forEach(function(p){p.style.display='none';});
    document.querySelectorAll('.tk-tab').forEach(function(b){b.classList.remove('btn-primary');b.classList.add('btn-ghost');});
    document.getElementById('tk-panel-'+spid).style.display='block';
    btn.classList.add('btn-primary');
    btn.classList.remove('btn-ghost');
}

function saveTimbKeys(spid){
    var bkKey = document.getElementById('bk-key-'+spid)?.value.trim() || '';
    var bkIv  = document.getElementById('bk-iv-'+spid)?.value.trim()  || '';
    var skKey = document.getElementById('sk-key-'+spid)?.value.trim() || '';
    var skIv  = document.getElementById('sk-iv-'+spid)?.value.trim()  || '';
    var msgEl = document.getElementById('tk-msg-'+spid);
    if(!bkKey && !skKey){ notify('Enter at least one key.','error'); return; }
    msgEl.textContent = 'Saving...';
    post({action:'save_timb_keys', tk_spid:spid,
          bk_system_key:bkKey, bk_vector:bkIv,
          sk_system_key:skKey, sk_vector:skIv})
    .then(function(d){
        if(d.ok){
            msgEl.style.color='var(--leaf)';
            msgEl.textContent = '✓ '+d.msg;
            notify(d.msg,'success');
            // Update tab badge
            var tab = document.getElementById('tk-tab-'+spid);
            if(tab){
                var badge = tab.querySelector('span');
                if(!badge){ badge=document.createElement('span'); tab.appendChild(badge); }
                badge.style.color = (bkKey&&skKey)?'#27ae60':'var(--gold)';
                badge.textContent = (bkKey&&skKey)?' ✓✓':' ✓';
            }
        } else {
            msgEl.style.color='var(--danger)';
            msgEl.textContent = '✕ '+(d.error||'Error');
            notify(d.error||'Error','error');
        }
    })
    .catch(function(e){ msgEl.textContent=''; notify(e.message,'error'); });
}

// SO deduction mode
function selectSOMode(mode){
    document.getElementById('so-mode-val').value = mode;
    ['before','after'].forEach(function(m){
        var lbl = document.getElementById('so-mode-'+m);
        var radio = document.getElementById('so-radio-'+m);
        var active = m===mode;
        lbl.style.borderColor  = active ? 'var(--gold)' : 'var(--border)';
        lbl.style.background   = active ? '#fdf8ee' : 'var(--cream)';
        radio.style.background = active ? 'var(--gold)' : 'transparent';
    });
}
function saveSOMode(){
    var mode = document.getElementById('so-mode-val').value;
    post({action:'save_so_mode', so_mode:mode})
    .then(function(d){
        if(d.ok){ notify(d.msg,'success'); closeModal('modal-so-mode'); }
        else notify(d.error,'error');
    }).catch(function(e){ notify(e.message,'error'); });
}
</script>

<?php include "includes/layout_end.php"; ?>
