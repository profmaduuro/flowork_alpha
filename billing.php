<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Billing & Subscriptions";
$active_page = "billing";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$msg = $err  = '';

// ── GET Action: Print Invoice View (Local Floor Access Only) ──
if (isset($_GET['print_invoice'])) {
    $inv_id = (int)$_GET['print_invoice'];
    $q = $conn->query("SELECT i.*, sp.name as floor_name, sp.company_name, sp.address, sp.email, sp.phone
                       FROM flowork_invoices i
                       JOIN selling_point sp ON sp.id = i.selling_pointid
                       WHERE i.id=$inv_id AND i.selling_pointid=$spid LIMIT 1");
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
                        <small>Use this key to renew or extend this floor's subscription license.</small>
                    </div>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// ── Action: Apply License Key ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['license_key'])) {
    $key = $conn->real_escape_string(trim($_POST['license_key'] ?? ''));

    if (empty($key)) {
        $err = "Please enter a license key.";
    } else {
        // Find key in invoices for this floor and verify it is Paid
        $q = $conn->query("SELECT * FROM flowork_invoices WHERE license_key='$key' AND selling_pointid=$spid AND status='Paid' LIMIT 1");
        if ($q && $q->num_rows > 0) {
            $inv = $q->fetch_assoc();
            $days = (int)$inv['duration_days'];

            // Fetch current subscription
            $sub_q = $conn->query("SELECT * FROM flowork_subscriptions WHERE selling_pointid=$spid LIMIT 1");
            $sub = ($sub_q && $sub_q->num_rows > 0) ? $sub_q->fetch_assoc() : null;

            $today = date('Y-m-d');
            if ($sub) {
                $current_end = $sub['end_date'];
                // If active and not expired, append days to current end date
                if ($sub['status'] === 'Active' && $current_end && $current_end >= $today) {
                    $new_end = date('Y-m-d', strtotime($current_end . " + $days days"));
                    $new_start = $sub['start_date'];
                } else {
                    $new_start = $today;
                    $new_end = date('Y-m-d', strtotime($today . " + $days days"));
                }
                $conn->query("UPDATE flowork_subscriptions SET status='Active', license_key='$key', start_date='$new_start', end_date='$new_end' WHERE selling_pointid=$spid");
            } else {
                $new_end = date('Y-m-d', strtotime($today . " + $days days"));
                $conn->query("INSERT INTO flowork_subscriptions (selling_pointid, status, license_key, start_date, end_date) VALUES ($spid, 'Active', '$key', '$today', '$new_end')");
            }
            $msg = "✓ License successfully applied! Access extended by $days days (Valid until $new_end).";
        } else {
            $err = "Invalid, unpaid, or mismatched license key. Please check your invoice status.";
        }
    }
}

// ── Fetch Billing Information ──

// Subscription Status
$sub_q = $conn->query("SELECT * FROM flowork_subscriptions WHERE selling_pointid=$spid LIMIT 1");
$sub = ($sub_q && $sub_q !== true) ? $sub_q->fetch_assoc() : null;

// Floor Details
$floor_q = $conn->query("SELECT * FROM selling_point WHERE id=$spid LIMIT 1");
$floor = ($floor_q && $floor_q !== true) ? $floor_q->fetch_assoc() : null;

// Invoices
$inv_q = $conn->query("SELECT * FROM flowork_invoices WHERE selling_pointid=$spid ORDER BY id DESC");
$invoices = ($inv_q && $inv_q !== true) ? $inv_q->fetch_all(MYSQLI_ASSOC) : [];

include 'includes/layout.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h2>Billing &amp; Subscriptions</h2>
        <p>Manage subscription validity, check invoices, and activate platform licenses</p>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success"><?= $msg ?></div>
<?php endif; ?>
<?php if ($err): ?>
    <div class="alert alert-danger"><?= $err ?></div>
<?php endif; ?>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: var(--gold)">
        <div class="kpi-label">Subscription Status</div>
        <div class="kpi-value">
            <?php
            $sub_status = $sub['status'] ?? 'Inactive';
            $end_date = $sub['end_date'] ?? null;
            if ($sub_status === 'Active' && $end_date && $end_date < date('Y-m-d')) {
                $sub_status = 'Expired';
            }
            echo htmlspecialchars(strtoupper($sub_status));
            ?>
        </div>
        <div class="kpi-sub">
            <?= $sub_status === 'Active' ? 'Access remains fully unlocked' : 'Access blocks at floor login' ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color: var(--leaf)">
        <div class="kpi-label">Access Expiration Date</div>
        <div class="kpi-value sm"><?= $end_date ? htmlspecialchars($end_date) : 'No license set' ?></div>
        <div class="kpi-sub">
            <?php if ($end_date && $end_date >= date('Y-m-d')): ?>
                Expires in <?= round((strtotime($end_date) - time()) / 86400) ?> days
            <?php else: ?>
                Renewal required
            <?php endif; ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color: var(--info)">
        <div class="kpi-label">Current License Key</div>
        <div class="kpi-value sm" style="font-family: monospace; font-size:1.15rem; letter-spacing:0.02em">
            <?= !empty($sub['license_key']) ? htmlspecialchars($sub['license_key']) : 'None' ?>
        </div>
        <div class="kpi-sub">Applied license code</div>
    </div>
</div>

<div class="grid-2">
    <!-- Invoice History -->
    <div class="card">
        <div class="card-head">
            <h3>Invoice &amp; Payment History</h3>
        </div>
        <div class="card-body table-wrap">
            <?php if (empty($invoices)): ?>
                <p style="color:var(--muted); font-size:0.85rem; text-align:center; padding: 2rem 0;">
                    No invoices have been issued to this floor.
                </p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Amount</th>
                            <th>Duration</th>
                            <th>Issue Date</th>
                            <th>Status</th>
                            <th>License Key</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $inv): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($inv['invoice_num']) ?></strong><br>
                                    <a href="billing.php?print_invoice=<?= $inv['id'] ?>" target="_blank" style="font-size:0.68rem; color:var(--gold); text-decoration:none">Print Layout ↗</a>
                                </td>
                                <td><strong>$<?= number_format($inv['amount'], 2) ?></strong></td>
                                <td><?= (int)$inv['duration_days'] ?> Days</td>
                                <td><?= htmlspecialchars($inv['issue_date']) ?></td>
                                <td>
                                    <span class="badge <?= $inv['status']==='Paid'?'badge-active':'badge-inactive' ?>">
                                        <?= htmlspecialchars($inv['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($inv['license_key'])): ?>
                                        <code><?= htmlspecialchars($inv['license_key']) ?></code>
                                    <?php else: ?>
                                        <span style="color:var(--muted); font-size:0.75rem">Awaiting Payment</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- License Activation -->
    <div class="card" style="height: fit-content">
        <div class="card-head">
            <h3>Activate / Renew License</h3>
        </div>
        <div class="card-body">
            <p style="font-size:0.82rem; color:var(--muted); line-height:1.5; margin-bottom:1.5rem">
                If you have paid for a subscription renewal, copy the license key provided on your receipt and input it below to extend your platform access duration.
            </p>
            
            <form method="POST">
                <div class="form-group">
                    <label for="license_key">Platform License Key</label>
                    <input type="text" name="license_key" id="license_key" class="form-control" 
                           placeholder="FL-XXXX-XXXX-XXXX" style="font-family: monospace; letter-spacing:0.05em" required>
                </div>
                <button type="submit" class="btn btn-gold" style="width: 100%; justify-content: center; margin-top: 0.5rem">Apply License Key</button>
            </form>
        </div>
    </div>
</div>

<?php
include 'includes/layout_end.php';
?>
