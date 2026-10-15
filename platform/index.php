<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . "/session.php"; // own session, separate from the floor app
require_once __DIR__ . "/../includes/conn.php";
require_once __DIR__ . "/../includes/auth.php"; // csrf_field() / verify_csrf_token()

// Auth Check
if (empty($_SESSION['flowork_manager_id'])) {
    header("Location: login.php");
    exit;
}

$manager_user = $_SESSION['flowork_manager_user'];
$msg = $err = '';
$active_tab = $_GET['tab'] ?? 'customers'; // customers | subscriptions | invoices | settings

// ── helper: Generate License Key ──
function generate_license_key() {
    $chars = '0123456789ABCDEF';
    $key = 'FL-';
    for ($i = 0; $i < 3; $i++) {
        for ($j = 0; $j < 4; $j++) {
            $key .= $chars[random_int(0, 15)];
        }
        if ($i < 2) $key .= '-';
    }
    return $key;
}

// ── helper: Generate Invoice Number ──
function generate_invoice_num() {
    return 'INV-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
}

// ── Action: Handle POST Forms ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("CSRF token verification failed.");
    }
    $action = $_POST['action'] ?? '';

    // Create or Edit Customer Floor
    if ($action === 'save_customer') {
        $sp_id = (int)($_POST['customer_id'] ?? 0);
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $floor_code = $conn->real_escape_string(trim($_POST['floor_code'] ?? ''));
        $floor_id = $conn->real_escape_string(trim($_POST['floor_id'] ?? ''));
        $company = $conn->real_escape_string(trim($_POST['company_name'] ?? ''));
        $location = $conn->real_escape_string(trim($_POST['location'] ?? ''));
        $address = $conn->real_escape_string(trim($_POST['address'] ?? ''));
        $phone = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
        $email = $conn->real_escape_string(trim($_POST['email'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;

        if (empty($name) || empty($floor_code)) {
            $err = "Customer Name and Floor Code are required.";
        } else {
            if ($sp_id > 0) {
                // Update
                $sql = "UPDATE selling_point SET name='$name', floor_code='$floor_code', floor_id='$floor_id',
                        active=$active, company_name='$company', location='$location', address='$address',
                        phone='$phone', email='$email' WHERE id=$sp_id";
                if ($conn->query($sql)) {
                    $msg = "✓ Customer '$name' details updated successfully.";
                } else {
                    $err = "Database error: " . $conn->error;
                }
            } else {
                // Insert
                $sql = "INSERT INTO selling_point (name, floor_code, floor_id, active, company_name, location, address, phone, email)
                        VALUES ('$name', '$floor_code', '$floor_id', $active, '$company', '$location', '$address', '$phone', '$email')";
                if ($conn->query($sql)) {
                    $new_id = $conn->insert_id;
                    // Auto-create an inactive subscription row
                    $conn->query("INSERT IGNORE INTO flowork_subscriptions (selling_pointid, status) VALUES ($new_id, 'Inactive')");
                    $msg = "✓ Customer '$name' registered successfully.";
                } else {
                    $err = "Database error: " . $conn->error;
                }
            }
        }
    }

    // Manual Subscription Override
    if ($action === 'save_subscription') {
        $sp_id = (int)($_POST['selling_pointid'] ?? 0);
        $status = $conn->real_escape_string($_POST['status'] ?? 'Inactive');
        $license_key = $conn->real_escape_string(trim($_POST['license_key'] ?? ''));
        $start_date = !empty($_POST['start_date']) ? "'" . $conn->real_escape_string($_POST['start_date']) . "'" : "NULL";
        $end_date = !empty($_POST['end_date']) ? "'" . $conn->real_escape_string($_POST['end_date']) . "'" : "NULL";
        $amount = (float)($_POST['amount'] ?? 0);

        if ($sp_id <= 0) {
            $err = "Invalid Floor selection.";
        } else {
            $sql = "INSERT INTO flowork_subscriptions (selling_pointid, status, license_key, start_date, end_date, amount)
                    VALUES ($sp_id, '$status', " . ($license_key ? "'$license_key'" : "NULL") . ", $start_date, $end_date, $amount)
                    ON DUPLICATE KEY UPDATE status='$status', license_key=" . ($license_key ? "'$license_key'" : "NULL") . ",
                                            start_date=$start_date, end_date=$end_date, amount=$amount";
            if ($conn->query($sql)) {
                $msg = "✓ Subscription settings updated successfully.";
            } else {
                $err = "Database error: " . $conn->error;
            }
        }
    }

    // Generate Invoice
    if ($action === 'create_invoice') {
        $sp_id = (int)($_POST['selling_pointid'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $duration = (int)($_POST['duration_days'] ?? 30);
        $issue_date = date('Y-m-d');
        $inv_num = generate_invoice_num();

        if ($sp_id <= 0 || $amount <= 0 || $duration <= 0) {
            $err = "All fields (Floor, Amount, Duration) must be valid and greater than zero.";
        } else {
            $sql = "INSERT INTO flowork_invoices (invoice_num, selling_pointid, amount, status, issue_date, duration_days)
                    VALUES ('$inv_num', $sp_id, $amount, 'Unpaid', '$issue_date', $duration)";
            if ($conn->query($sql)) {
                $msg = "✓ Invoice '$inv_num' successfully issued.";
                $active_tab = 'invoices';
            } else {
                $err = "Database error: " . $conn->error;
            }
        }
    }

    // Mark Invoice as Paid & Generate License Key
    if ($action === 'pay_invoice') {
        $inv_id = (int)($_POST['invoice_id'] ?? 0);
        if ($inv_id <= 0) {
            $err = "Invalid Invoice selection.";
        } else {
            // Fetch invoice details
            $q = $conn->query("SELECT * FROM flowork_invoices WHERE id=$inv_id LIMIT 1");
            if ($q && $q->num_rows > 0) {
                $inv = $q->fetch_assoc();
                if ($inv['status'] === 'Paid') {
                    $err = "Invoice is already marked Paid.";
                } else {
                    $license_key = generate_license_key();
                    $today_date = date('Y-m-d');

                    $conn->query("UPDATE flowork_invoices SET status='Paid', paid_date='$today_date', license_key='$license_key' WHERE id=$inv_id");
                    $msg = "✓ Invoice marked as PAID. Generated License Key: <strong>$license_key</strong>";
                    $active_tab = 'invoices';
                }
            } else {
                $err = "Invoice record not found.";
            }
        }
    }

    // Change System Password
    if ($action === 'update_password') {
        $curr_pw = trim($_POST['current_password'] ?? '');
        $new_pw = trim($_POST['new_password'] ?? '');
        $new_pw_conf = trim($_POST['new_password_confirm'] ?? '');

        if (empty($curr_pw) || empty($new_pw)) {
            $err = "Please enter both current and new passwords.";
        } elseif ($new_pw !== $new_pw_conf) {
            $err = "New password fields do not match.";
        } else {
            $mid = (int)$_SESSION['flowork_manager_id'];
            $q = $conn->query("SELECT password_hash FROM flowork_system_admins WHERE id=$mid LIMIT 1");
            if ($q && $q->num_rows > 0) {
                $admin = $q->fetch_assoc();
                if (password_verify($curr_pw, $admin['password_hash'])) {
                    $new_hash = password_hash($new_pw, PASSWORD_BCRYPT);
                    $conn->query("UPDATE flowork_system_admins SET password_hash='$new_hash' WHERE id=$mid");
                    $msg = "✓ System Password updated successfully.";
                } else {
                    $err = "Incorrect current password.";
                }
            } else {
                $err = "Account error.";
            }
        }
        $active_tab = 'settings';
    }
}

// ── GET Action: Print Invoice View ──
if (isset($_GET['print_invoice'])) {
    $inv_id = (int)$_GET['print_invoice'];
    $q = $conn->query("SELECT i.*, sp.name as floor_name, sp.company_name, sp.address, sp.email, sp.phone
                       FROM flowork_invoices i
                       JOIN selling_point sp ON sp.id = i.selling_pointid
                       WHERE i.id=$inv_id LIMIT 1");
    if ($q && $q->num_rows > 0) {
        $inv = $q->fetch_assoc();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Invoice - <?= htmlspecialchars($inv['invoice_num']) ?></title>
            <style>
                body { font-family: sans-serif; color: #333; padding: 40px; line-height: 1.6; }
                .inv-box { max-width: 800px; margin: auto; border: 1px solid #ddd; padding: 30px; box-shadow: 0 4px 8px rgba(0,0,0,0.05); }
                .inv-header { display: flex; justify-content: space-between; border-bottom: 2px solid #c8952a; padding-bottom: 20px; margin-bottom: 20px; }
                .inv-logo { font-size: 28px; font-weight: bold; color: #1a1208; }
                .inv-logo span { color: #c8952a; }
                .inv-meta { text-align: right; }
                .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 40px; }
                .bill-to h3, .payment-details h3 { margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 4px; color: #1a1208; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
                th { background: #f5f0e8; text-align: left; padding: 10px; font-size: 13px; text-transform: uppercase; }
                td { padding: 10px; border-bottom: 1px solid #eee; }
                .total { text-align: right; font-size: 18px; font-weight: bold; }
                .license-alert { background: #edf4ec; border: 1px solid #3d6b35; padding: 15px; border-radius: 4px; margin-top: 20px; color: #2d5226; font-size: 14px; }
                .btn-print { background: #1a1208; color: #fff; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; float: right; margin-top: 20px; font-weight: bold; }
                @media print { .btn-print { display: none; } }
            </style>
        </head>
        <body>
            <div class="inv-box">
                <button class="btn-print" onclick="window.print()">Print Invoice</button>
                <div class="inv-header">
                    <div class="inv-logo">FLOWORK<span>.</span></div>
                    <div class="inv-meta">
                        <h2>INVOICE</h2>
                        <strong>Invoice #:</strong> <?= htmlspecialchars($inv['invoice_num']) ?><br>
                        <strong>Issue Date:</strong> <?= htmlspecialchars($inv['issue_date']) ?><br>
                        <strong>Status:</strong> <span style="color:<?= $inv['status']==='Paid'?'#2d5226':'#c0392b' ?>"><?= strtoupper($inv['status']) ?></span>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="bill-to">
                        <h3>BILL TO:</h3>
                        <strong><?= htmlspecialchars($inv['floor_name']) ?></strong><br>
                        <?= htmlspecialchars($inv['company_name']) ?><br>
                        <?= nl2br(htmlspecialchars($inv['address'])) ?><br>
                        <?= htmlspecialchars($inv['phone']) ?> | <?= htmlspecialchars($inv['email']) ?>
                    </div>
                    <div class="payment-details">
                        <h3>FROM:</h3>
                        <strong>Flowork Platform Solutions Ltd.</strong><br>
                        Developer Operations<br>
                        support@flowork.com<br>
                        <strong>Access Period:</strong> <?= (int)$inv['duration_days'] ?> Days
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Quantity</th>
                            <th style="text-align:right">Price</th>
                            <th style="text-align:right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Flowork Tobacco Auction Software Platform Subscription License (<?= (int)$inv['duration_days'] ?> days)</td>
                            <td>1</td>
                            <td style="text-align:right">$<?= number_format($inv['amount'], 2) ?></td>
                            <td style="text-align:right">$<?= number_format($inv['amount'], 2) ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="total">
                    TOTAL DUE: $<?= number_format($inv['amount'], 2) ?>
                </div>

                <?php if ($inv['status'] === 'Paid' && !empty($inv['license_key'])): ?>
                    <div class="license-alert">
                        <strong>✓ License Key Generated:</strong> <?= htmlspecialchars($inv['license_key']) ?><br>
                        <small>Enter this key inside the Flowork Login system to activate your auction floor's access.</small>
                    </div>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// ── Read Data for Dashboard ──

// Customers
$cust_q = $conn->query("SELECT sp.*, sub.status as sub_status, sub.start_date, sub.end_date, sub.license_key
                        FROM selling_point sp
                        LEFT JOIN flowork_subscriptions sub ON sub.selling_pointid = sp.id
                        ORDER BY sp.name ASC");
$customers = ($cust_q && $cust_q !== true) ? $cust_q->fetch_all(MYSQLI_ASSOC) : [];

// Invoices
$inv_q = $conn->query("SELECT i.*, sp.name as floor_name
                       FROM flowork_invoices i
                       JOIN selling_point sp ON sp.id = i.selling_pointid
                       ORDER BY i.id DESC");
$invoices = ($inv_q && $inv_q !== true) ? $inv_q->fetch_all(MYSQLI_ASSOC) : [];

// Selected customer details for edit
$edit_customer = null;
if (isset($_GET['edit_cust_id'])) {
    $ec_id = (int)$_GET['edit_cust_id'];
    $ec_q = $conn->query("SELECT * FROM selling_point WHERE id=$ec_id LIMIT 1");
    if ($ec_q && $ec_q !== true) {
        $edit_customer = $ec_q->fetch_assoc();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Flowork Platform Manager</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&display=swap" rel="stylesheet">
<style>
:root {
  --ink: #0f0a05;
  --ink-soft: #1d140b;
  --gold: #c8952a;
  --gold-l: #e8b84b;
  --gold-bg: rgba(200, 149, 42, 0.12);
  --cream: #faf8f4;
  --cream-d: #ede6d6;
  --leaf: #3d6b35;
  --leaf-l: rgba(61, 107, 53, 0.15);
  --muted: #8c7e6c;
  --border: rgba(200, 149, 42, 0.15);
  --danger: #c0392b;
  --danger-bg: rgba(192, 57, 43, 0.15);
  --radius: 8px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'DM Sans', sans-serif;
    background: var(--ink);
    background-image: radial-gradient(circle at 50% 50%, #1a1107 0%, #060402 100%);
    min-height: 100vh;
    color: var(--cream);
    display: flex;
    flex-direction: column;
}

/* Header Navbar */
.nav-header {
    background: #171007;
    border-bottom: 2px solid var(--gold);
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 2rem;
    height: 60px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.5);
}
.logo {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.8rem;
    color: var(--cream);
    letter-spacing: 0.12em;
    display: flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}
.logo span {
    width: 8px;
    height: 8px;
    background: var(--gold);
    border-radius: 50%;
}
.logo-tag {
    background: var(--gold-bg);
    border: 1px solid rgba(200, 149, 42, 0.3);
    color: var(--gold-l);
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.15em;
    padding: 2px 8px;
    border-radius: 4px;
    margin-left: 10px;
}
.user-menu {
    display: flex;
    align-items: center;
    gap: 15px;
    font-size: 0.85rem;
}
.user-badge {
    background: var(--gold);
    color: var(--ink);
    padding: 3px 10px;
    font-weight: 700;
    border-radius: 4px;
    font-size: 0.75rem;
    text-transform: uppercase;
}
.btn-logout {
    color: rgba(250, 248, 244, 0.5);
    text-decoration: none;
    padding: 4px 10px;
    border: 1px solid rgba(250, 248, 244, 0.1);
    border-radius: 4px;
    transition: all 0.2s;
}
.btn-logout:hover {
    color: var(--cream);
    border-color: rgba(250, 248, 244, 0.3);
    background: rgba(250, 248, 244, 0.05);
}

/* Tabs Bar */
.tabs-bar {
    background: #1c130a;
    display: flex;
    gap: 5px;
    padding: 0 2rem;
    border-bottom: 1px solid var(--border);
}
.tab-btn {
    padding: 1rem 1.5rem;
    background: transparent;
    border: none;
    color: var(--muted);
    font-weight: 500;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s;
    border-bottom: 3px solid transparent;
    text-decoration: none;
    display: inline-block;
}
.tab-btn:hover {
    color: var(--cream);
    background: rgba(200, 149, 42, 0.04);
}
.tab-btn.active {
    color: var(--gold-l);
    border-bottom-color: var(--gold);
    font-weight: 700;
    background: var(--gold-bg);
}

/* Container */
.container {
    flex: 1;
    max-width: 1300px;
    width: 100%;
    margin: 2rem auto;
    padding: 0 1.5rem;
}

/* Page Layout Grids */
.grid-main {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}
@media (min-width: 992px) {
    .grid-main.has-sidebar {
        grid-template-columns: 2fr 1fr;
    }
}

/* Card */
.card {
    background: rgba(28, 19, 10, 0.4);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.card-header {
    background: rgba(28, 19, 10, 0.6);
    border-bottom: 1px solid var(--border);
    padding: 1rem 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.card-header h3 {
    font-size: 0.9rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--gold-l);
}
.card-body {
    padding: 1.25rem;
}

/* Forms */
.form-group {
    margin-bottom: 1rem;
}
.form-group label {
    display: block;
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 0.4rem;
}
.form-control {
    width: 100%;
    padding: 0.65rem 0.85rem;
    background: rgba(10, 6, 3, 0.7);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    color: var(--cream);
    font-family: inherit;
    font-size: 0.85rem;
    outline: none;
    transition: all 0.2s;
}
.form-control:focus {
    border-color: var(--gold);
    background: rgba(10, 6, 3, 0.9);
}
textarea.form-control {
    resize: vertical;
    height: 80px;
}
.btn-submit {
    background: var(--gold);
    color: var(--ink);
    padding: 0.65rem 1.25rem;
    border: none;
    border-radius: var(--radius);
    cursor: pointer;
    font-weight: 700;
    font-size: 0.85rem;
    transition: all 0.2s;
}
.btn-submit:hover {
    background: var(--gold-l);
    transform: translateY(-1px);
}
.btn-secondary {
    background: transparent;
    color: var(--cream);
    border: 1px solid var(--border);
    padding: 0.65rem 1.25rem;
    border-radius: var(--radius);
    cursor: pointer;
    font-weight: 500;
    font-size: 0.85rem;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-block;
}
.btn-secondary:hover {
    background: rgba(250, 248, 244, 0.05);
    border-color: rgba(250, 248, 244, 0.3);
}

/* Tables */
.table-wrap {
    overflow-x: auto;
}
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    text-align: left;
}
thead th {
    font-size: 0.65rem;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    padding: 0.85rem 1rem;
    border-bottom: 2px solid var(--border);
    background: rgba(15, 10, 5, 0.8);
}
tbody tr {
    border-bottom: 1px solid rgba(200, 149, 42, 0.08);
    transition: background 0.15s;
}
tbody tr:hover {
    background: rgba(200, 149, 42, 0.03);
}
tbody td {
    padding: 0.85rem 1rem;
    vertical-align: middle;
}

/* Badges */
.badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.badge-active { background: var(--leaf-l); color: #81c784; border: 1px solid rgba(129, 199, 132, 0.3); }
.badge-inactive { background: rgba(250, 248, 244, 0.05); color: var(--muted); border: 1px solid var(--border); }
.badge-expired { background: var(--danger-bg); color: #e57373; border: 1px solid rgba(229, 115, 115, 0.3); }
.badge-suspended { background: var(--gold-bg); color: var(--gold-l); border: 1px solid rgba(232, 184, 75, 0.3); }

/* Alert Messages */
.alert {
    padding: 0.85rem 1.25rem;
    border-radius: var(--radius);
    margin-bottom: 1.5rem;
    font-size: 0.85rem;
    border-left: 4px solid transparent;
}
.alert-success {
    background: var(--leaf-l);
    border-color: var(--leaf);
    color: #a5d6a7;
}
.alert-danger {
    background: var(--danger-bg);
    border-color: var(--danger);
    color: #ef9a9a;
}

.action-btn {
    background: rgba(200, 149, 42, 0.15);
    color: var(--gold-l);
    border: 1px solid rgba(200, 149, 42, 0.3);
    padding: 3px 8px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.72rem;
    transition: all 0.2s;
    text-decoration: none;
}
.action-btn:hover {
    background: var(--gold);
    color: var(--ink);
    border-color: var(--gold);
}
.action-btn-green {
    background: rgba(61, 107, 53, 0.2);
    color: #81c784;
    border: 1px solid rgba(61, 107, 53, 0.4);
}
.action-btn-green:hover {
    background: var(--leaf);
    color: var(--cream);
    border-color: var(--leaf);
}
</style>
</head>
<body>

<div class="nav-header">
    <a href="index.php" class="logo"><span></span>FLOWORK <div class="logo-tag">Developer Engine</div></a>
    <div class="user-menu">
        <span class="user-badge"><?= htmlspecialchars($manager_user) ?></span>
        <a href="login.php?logout=1" class="btn-logout">Sign out</a>
    </div>
</div>

<div class="tabs-bar">
    <a href="index.php?tab=customers" class="tab-btn <?= $active_tab==='customers'?'active':'' ?>">Customer floors</a>
    <a href="index.php?tab=subscriptions" class="tab-btn <?= $active_tab==='subscriptions'?'active':'' ?>">Access licenses</a>
    <a href="index.php?tab=invoices" class="tab-btn <?= $active_tab==='invoices'?'active':'' ?>">Billing &amp; Invoices</a>
    <a href="index.php?tab=settings" class="tab-btn <?= $active_tab==='settings'?'active':'' ?>">System settings</a>
</div>

<div class="container">
    <?php if (!empty($msg)): ?>
        <div class="alert alert-success"><?= $msg ?></div>
    <?php endif; ?>
    <?php if (!empty($err)): ?>
        <div class="alert alert-danger"><?= $err ?></div>
    <?php endif; ?>

    <?php if ($active_tab === 'customers'): ?>
        <!-- ════════════════════ CUSTOMER DIRECTORY ════════════════════ -->
        <div class="grid-main has-sidebar">
            <div class="card">
                <div class="card-header">
                    <h3>Flowork Customers (Floors)</h3>
                </div>
                <div class="card-body table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Floor Name</th>
                                <th>Code / ID</th>
                                <th>Contact details</th>
                                <th>Active</th>
                                <th>License Status</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $c): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($c['name']) ?></strong><br>
                                        <small style="color:var(--muted)"><?= htmlspecialchars($c['company_name']) ?></small>
                                    </td>
                                    <td>
                                        <code><?= htmlspecialchars($c['floor_code']) ?></code> / <code><?= htmlspecialchars($c['floor_id']) ?></code>
                                    </td>
                                    <td>
                                        <small><?= htmlspecialchars($c['email']) ?></small><br>
                                        <small><?= htmlspecialchars($c['phone']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?= $c['active']?'badge-active':'badge-inactive' ?>">
                                            <?= $c['active']?'Yes':'No' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $sub_status = $c['sub_status'] ?? 'Inactive';
                                        $end_date = $c['end_date'] ?? null;
                                        $badge_class = 'badge-inactive';

                                        if ($sub_status === 'Active') {
                                            if ($end_date && $end_date < date('Y-m-d')) {
                                                $sub_status = 'Expired';
                                                $badge_class = 'badge-expired';
                                            } else {
                                                $badge_class = 'badge-active';
                                            }
                                        } elseif ($sub_status === 'Suspended') {
                                            $badge_class = 'badge-suspended';
                                        }
                                        ?>
                                        <span class="badge <?= $badge_class ?>"><?= $sub_status ?></span><br>
                                        <?php if ($end_date): ?>
                                            <small style="color:var(--muted)">Expires: <?= htmlspecialchars($end_date) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right; gap:5px;">
                                        <a href="index.php?tab=customers&edit_cust_id=<?= (int)$c['id'] ?>" class="action-btn">Edit Details</a>
                                        <a href="index.php?tab=subscriptions&selling_pointid=<?= (int)$c['id'] ?>" class="action-btn action-btn-green">Set Subscription</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><?= $edit_customer ? 'Edit customer details' : 'Register new customer' ?></h3>
                </div>
                <div class="card-body">
                    <form method="POST" autocomplete="off"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_customer">
                        <?php if ($edit_customer): ?>
                            <input type="hidden" name="customer_id" value="<?= (int)$edit_customer['id'] ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Floor Name *</label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. Vision Leaf Floor" value="<?= htmlspecialchars($edit_customer['name'] ?? '') ?>">
                        </div>

                        <div class="form-row cols-2">
                            <div class="form-group">
                                <label>Floor Code *</label>
                                <input type="text" name="floor_code" class="form-control" required placeholder="e.g. VL" value="<?= htmlspecialchars($edit_customer['floor_code'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Floor ID (TIMB ID)</label>
                                <input type="text" name="floor_id" class="form-control" placeholder="e.g. VLF001" value="<?= htmlspecialchars($edit_customer['floor_id'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Company Legal Name</label>
                            <input type="text" name="company_name" class="form-control" placeholder="e.g. Vision Leaf Tobacco Pvt Ltd" value="<?= htmlspecialchars($edit_customer['company_name'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label>Registered Location</label>
                            <input type="text" name="location" class="form-control" placeholder="e.g. Harare" value="<?= htmlspecialchars($edit_customer['location'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label>Billing Address</label>
                            <textarea name="address" class="form-control" placeholder="Street Address, City, Country"><?= htmlspecialchars($edit_customer['address'] ?? '') ?></textarea>
                        </div>

                        <div class="form-row cols-2">
                            <div class="form-group">
                                <label>Contact Phone</label>
                                <input type="text" name="phone" class="form-control" placeholder="+263..." value="<?= htmlspecialchars($edit_customer['phone'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Contact Email</label>
                                <input type="email" name="email" class="form-control" placeholder="billing@customer.com" value="<?= htmlspecialchars($edit_customer['email'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-group" style="display:flex; align-items:center; gap:8px; margin: 1.5rem 0;">
                            <input type="checkbox" name="active" id="active" value="1" <?= (!isset($edit_customer) || !empty($edit_customer['active'])) ? 'checked' : '' ?>>
                            <label for="active" style="display:inline; margin:0; cursor:pointer">Enable Floor Login (Active Customer)</label>
                        </div>

                        <div style="display:flex; gap:10px;">
                            <button type="submit" class="btn-submit"><?= $edit_customer ? 'Save changes' : 'Register floor' ?></button>
                            <?php if ($edit_customer): ?>
                                <a href="index.php?tab=customers" class="btn-secondary">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php elseif ($active_tab === 'subscriptions'): ?>
        <!-- ════════════════════ SUBSCRIPTIONS OVERRIDE ════════════════════ -->
        <?php
        $target_spid = (int)($_GET['selling_pointid'] ?? 0);
        $sub_details = null;
        if ($target_spid > 0) {
            $sd_q = $conn->query("SELECT * FROM flowork_subscriptions WHERE selling_pointid=$target_spid LIMIT 1");
            if ($sd_q && $sd_q !== true) {
                $sub_details = $sd_q->fetch_assoc();
            }
        }
        ?>
        <div class="grid-main has-sidebar">
            <div class="card">
                <div class="card-header">
                    <h3>Active Floor Subscription Overrides</h3>
                </div>
                <div class="card-body table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Floor Name</th>
                                <th>Status</th>
                                <th>License Key</th>
                                <th>Period dates</th>
                                <th>Amount Paid</th>
                                <th>Last renewed</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $c): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                                    <td>
                                        <?php
                                        $sub_status = $c['sub_status'] ?? 'Inactive';
                                        $end_date = $c['end_date'] ?? null;
                                        $badge_class = 'badge-inactive';

                                        if ($sub_status === 'Active') {
                                            if ($end_date && $end_date < date('Y-m-d')) {
                                                $sub_status = 'Expired';
                                                $badge_class = 'badge-expired';
                                            } else {
                                                $badge_class = 'badge-active';
                                            }
                                        } elseif ($sub_status === 'Suspended') {
                                            $badge_class = 'badge-suspended';
                                        }
                                        ?>
                                        <span class="badge <?= $badge_class ?>"><?= $sub_status ?></span>
                                    </td>
                                    <td>
                                        <code><?= htmlspecialchars($c['license_key'] ?? 'None') ?></code>
                                    </td>
                                    <td>
                                        <?php if (!empty($c['end_date'])): ?>
                                            <small><?= htmlspecialchars($c['start_date'] ?? '—') ?> &rarr; <?= htmlspecialchars($c['end_date'] ?? '') ?></small>
                                        <?php else: ?>
                                            <span style="color:var(--muted)">No dates set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong>$<?= number_format((float)($c['amount'] ?? 0), 2) ?></strong>
                                    </td>
                                    <td>
                                        <small><?= htmlspecialchars($c['last_renewed'] ?? 'N/A') ?></small>
                                    </td>
                                    <td style="text-align:right">
                                        <a href="index.php?tab=subscriptions&selling_pointid=<?= (int)$c['id'] ?>" class="action-btn">Modify License</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Manual settings override</h3>
                </div>
                <div class="card-body">
                    <form method="POST"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_subscription">

                        <div class="form-group">
                            <label>Target Floor / Customer *</label>
                            <select name="selling_pointid" class="form-control" required onchange="window.location='index.php?tab=subscriptions&selling_pointid='+this.value">
                                <option value="">-- Select Floor --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= $target_spid===$c['id']?'selected':'' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($target_spid > 0): ?>
                            <div class="form-group">
                                <label>Access Status</label>
                                <select name="status" class="form-control">
                                    <option value="Active" <?= ($sub_details['status']??'')==='Active'?'selected':'' ?>>Active</option>
                                    <option value="Inactive" <?= ($sub_details['status']??'')==='Inactive'?'selected':'' ?>>Inactive</option>
                                    <option value="Suspended" <?= ($sub_details['status']??'')==='Suspended'?'selected':'' ?>>Suspended</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Active License Key</label>
                                <input type="text" name="license_key" class="form-control" placeholder="FL-XXXX-XXXX-XXXX" value="<?= htmlspecialchars($sub_details['license_key'] ?? '') ?>">
                            </div>

                            <div class="form-row cols-2">
                                <div class="form-group">
                                    <label>Start Date</label>
                                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($sub_details['start_date'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Expiration Date</label>
                                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($sub_details['end_date'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Override Paid Amount (USD)</label>
                                <input type="number" step="0.01" name="amount" class="form-control" value="<?= (float)($sub_details['amount'] ?? 0) ?>">
                            </div>

                            <button type="submit" class="btn-submit">Override Settings</button>
                        <?php else: ?>
                            <p style="color:var(--muted); font-size:0.82rem; text-align:center; padding: 2rem 0;">
                                Select a floor from the dropdown above to override its live license configurations.
                            </p>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

    <?php elseif ($active_tab === 'invoices'): ?>
        <!-- ════════════════════ INVOICING & LICENSE KEYS ════════════════════ -->
        <div class="grid-main has-sidebar">
            <div class="card">
                <div class="card-header">
                    <h3>Invoices and Generated Keys</h3>
                </div>
                <div class="card-body table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Customer Floor</th>
                                <th>Amount</th>
                                <th>Duration</th>
                                <th>Issued Date</th>
                                <th>Status</th>
                                <th>Generated License Key</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $inv): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($inv['invoice_num']) ?></strong><br>
                                        <a href="index.php?print_invoice=<?= $inv['id'] ?>" target="_blank" style="font-size:0.75rem; color:var(--gold-l); text-decoration:none">Print Layout ↗</a>
                                    </td>
                                    <td><?= htmlspecialchars($inv['floor_name']) ?></td>
                                    <td><strong>$<?= number_format($inv['amount'], 2) ?></strong></td>
                                    <td><?= (int)$inv['duration_days'] ?> Days</td>
                                    <td><?= htmlspecialchars($inv['issue_date']) ?></td>
                                    <td>
                                        <span class="badge <?= $inv['status']==='Paid'?'badge-active':'badge-inactive' ?>">
                                            <?= $inv['status'] ?>
                                        </span>
                                        <?php if ($inv['status']==='Paid' && $inv['paid_date']): ?>
                                            <br><small style="color:var(--muted)"><?= htmlspecialchars($inv['paid_date']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($inv['license_key'])): ?>
                                            <code><?= htmlspecialchars($inv['license_key']) ?></code>
                                        <?php else: ?>
                                            <span style="color:var(--muted)">Pending Payment</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right">
                                        <?php if ($inv['status'] !== 'Paid'): ?>
                                            <form method="POST" style="display:inline"><?= csrf_field() ?>
                                                <input type="hidden" name="action" value="pay_invoice">
                                                <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                                                <button type="submit" class="action-btn action-btn-green" onclick="return confirm('Confirm payment receipt for <?= htmlspecialchars($inv['invoice_num']) ?>?')">Mark Paid</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:var(--leaf); font-size:0.75rem; font-weight:700">✓ Settled</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Issue Platform Invoice</h3>
                </div>
                <div class="card-body">
                    <form method="POST"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_invoice">

                        <div class="form-group">
                            <label>Customer Floor *</label>
                            <select name="selling_pointid" class="form-control" required>
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>">
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Invoice Amount (USD) *</label>
                            <input type="number" step="0.01" name="amount" class="form-control" required placeholder="e.g. 1500.00" value="1500.00">
                        </div>

                        <div class="form-group">
                            <label>Access Duration (Days) *</label>
                            <select name="duration_days" class="form-control">
                                <option value="30">30 Days (1 Month)</option>
                                <option value="90">90 Days (3 Months)</option>
                                <option value="180">180 Days (6 Months)</option>
                                <option value="365" selected>365 Days (1 Year)</option>
                            </select>
                        </div>

                        <button type="submit" class="btn-submit">Issue Invoice</button>
                    </form>
                </div>
            </div>
        </div>

    <?php elseif ($active_tab === 'settings'): ?>
        <!-- ════════════════════ DEVELOPER PROFILE SETTINGS ════════════════════ -->
        <div class="grid-main" style="max-width: 600px; margin: auto;">
            <div class="card">
                <div class="card-header">
                    <h3>Update Developer/Owner Password</h3>
                </div>
                <div class="card-body">
                    <form method="POST"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_password">

                        <div class="form-group">
                            <label>Current Password</label>
                            <input type="password" name="current_password" class="form-control" required placeholder="Enter current password">
                        </div>

                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" class="form-control" required placeholder="At least 8 characters">
                        </div>

                        <div class="form-group">
                            <label>Confirm New Password</label>
                            <input type="password" name="new_password_confirm" class="form-control" required placeholder="Verify new password">
                        </div>

                        <button type="submit" class="btn-submit">Change password</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
