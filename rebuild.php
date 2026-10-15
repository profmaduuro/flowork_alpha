<?php
require_once __DIR__ . '/includes/admin_gate.php';
/**
 * rebuild.php — Creates ALL core Flowork tables from scratch.
 * Safe to run multiple times (uses CREATE TABLE IF NOT EXISTS).
 * Run this before setup.php after a crash.
 */
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "includes/conn.php";
$conn->query("SET FOREIGN_KEY_CHECKS=0");

echo "<!DOCTYPE html><html><head><title>Rebuild</title>
<style>body{font-family:monospace;background:#1a1208;color:#e8b84b;padding:20px;font-size:13px;line-height:1.8}
.ok{color:#4caf50}.fail{color:#f44336}.info{color:#87ceeb}
h2{color:#c8952a;border-bottom:1px solid #333;padding-bottom:4px;margin-top:1.5rem}
a{color:#c8952a}hr{border-color:#333}</style></head><body>
<h2>⚙ Flowork Table Rebuild</h2>";

$tables = [

// ── Core auth ──────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    active TINYINT DEFAULT 1,
    confirm TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

"CREATE TABLE IF NOT EXISTS rights (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    created_byid INT DEFAULT 0,
    admin TINYINT DEFAULT 0,
    transporter_receiving TINYINT DEFAULT 0,
    scale TINYINT DEFAULT 0,
    junus TINYINT DEFAULT 0,
    buyer TINYINT DEFAULT 0,
    balance TINYINT DEFAULT 0,
    salessheets TINYINT DEFAULT 0,
    accounts TINYINT DEFAULT 0,
    transporter_payments TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(userid)
)",

// ── Setup ──────────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS selling_point (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    floor_code VARCHAR(20) DEFAULT '',
    floor_id VARCHAR(20) DEFAULT '',
    active TINYINT DEFAULT 1
)",

"CREATE TABLE IF NOT EXISTS seasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(120) NOT NULL,
    active TINYINT DEFAULT 0
)",

"CREATE TABLE IF NOT EXISTS user_selling_points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    selling_pointid INT NOT NULL,
    role ENUM('operator','manager','admin') DEFAULT 'operator',
    UNIQUE KEY usp_u (userid, selling_pointid),
    INDEX(userid), INDEX(selling_pointid)
)",

// ── Lookup tables ──────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS splits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    value INT NOT NULL DEFAULT 0
)",

"CREATE TABLE IF NOT EXISTS currency (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(40) NOT NULL
)",

"CREATE TABLE IF NOT EXISTS charge_type (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(80) NOT NULL
)",

"CREATE TABLE IF NOT EXISTS statutory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(120) NOT NULL,
    currencyid INT DEFAULT 1,
    accessid INT DEFAULT 0
)",

"CREATE TABLE IF NOT EXISTS ticket_check_letter (
    id INT AUTO_INCREMENT PRIMARY KEY,
    characters VARCHAR(5) NOT NULL,
    character_value INT NOT NULL DEFAULT 0
)",

"CREATE TABLE IF NOT EXISTS exchange_rate (
    id INT AUTO_INCREMENT PRIMARY KEY,
    amount DECIMAL(14,4) NOT NULL DEFAULT 0,
    start_of_dayid INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

"CREATE TABLE IF NOT EXISTS start_of_day (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description DATE NOT NULL,
    selling_pointid INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

"CREATE TABLE IF NOT EXISTS parameters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    param_key VARCHAR(80) NOT NULL UNIQUE,
    param_value TEXT DEFAULT ''
)",

// ── Growers ────────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS growers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grower_num VARCHAR(30) NOT NULL,
    name VARCHAR(80) NOT NULL,
    surname VARCHAR(80) DEFAULT '',
    national_id VARCHAR(40) DEFAULT '',
    phone VARCHAR(30) DEFAULT '',
    province VARCHAR(60) DEFAULT '',
    district VARCHAR(60) DEFAULT '',
    farm_name VARCHAR(100) DEFAULT '',
    contractor VARCHAR(80) DEFAULT '',
    selling_pointid INT DEFAULT 0,
    active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(grower_num), INDEX(selling_pointid)
)",

"CREATE TABLE IF NOT EXISTS grower_banking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    growerid INT NOT NULL UNIQUE,
    bank_name VARCHAR(100) DEFAULT '',
    branch VARCHAR(100) DEFAULT '',
    account_num VARCHAR(60) DEFAULT '',
    account_name VARCHAR(120) DEFAULT '',
    swift_code VARCHAR(20) DEFAULT '',
    phone VARCHAR(30) DEFAULT '',
    verified TINYINT DEFAULT 0,
    verified_by INT NULL,
    verified_at TIMESTAMP NULL,
    previous_account_num VARCHAR(60) DEFAULT '',
    account_changed_at TIMESTAMP NULL,
    INDEX(growerid)
)",

// ── Transporters & bale flow ───────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS transporter (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    truck_num VARCHAR(40) DEFAULT '',
    location_id INT NULL,
    seasonid INT DEFAULT 0,
    selling_pointid INT DEFAULT 0,
    active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

"CREATE TABLE IF NOT EXISTS bale_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    subcontractor VARCHAR(100) DEFAULT '',
    is_primary TINYINT DEFAULT 0,
    selling_pointid INT DEFAULT 0,
    active TINYINT DEFAULT 1
)",

"CREATE TABLE IF NOT EXISTS transporter_growers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    growerid INT NOT NULL,
    transporterid INT NOT NULL,
    seasonid INT DEFAULT 0,
    active TINYINT DEFAULT 1,
    INDEX(growerid), INDEX(transporterid)
)",

"CREATE TABLE IF NOT EXISTS bale_mass (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mass DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

"CREATE TABLE IF NOT EXISTS bale_junus (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transporter_growersid INT NOT NULL,
    bale_massid INT NOT NULL,
    splitid INT DEFAULT 0,
    lot INT DEFAULT 0,
    bale_group INT DEFAULT 0,
    growerid INT DEFAULT 0,
    barcode VARCHAR(40) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(transporter_growersid), INDEX(barcode)
)",

"CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bale_junusid INT NOT NULL,
    barcode VARCHAR(40) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(bale_junusid)
)",

"CREATE TABLE IF NOT EXISTS ticket_splits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transporter_growersid INT NOT NULL,
    status ENUM('open','confirmed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(transporter_growersid)
)",

// ── Buyers & grades ────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS buyer (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    buyer_code VARCHAR(20) DEFAULT '',
    selling_pointid INT DEFAULT 0,
    active TINYINT DEFAULT 1
)",

"CREATE TABLE IF NOT EXISTS buyer_grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buyerid INT NOT NULL,
    description VARCHAR(60) NOT NULL,
    active TINYINT DEFAULT 1,
    INDEX(buyerid)
)",

"CREATE TABLE IF NOT EXISTS timb_grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(40) NOT NULL,
    selling_pointid INT DEFAULT 0,
    active TINYINT DEFAULT 1
)",

// ── Sales ──────────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS sold_bales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticketsid INT NOT NULL,
    price DECIMAL(10,4) DEFAULT 0,
    sale_code VARCHAR(20) DEFAULT '',
    timb_gradesid INT DEFAULT 0,
    buyer_gradesid INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(ticketsid), INDEX(created_at)
)",

// ── Balancing ──────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS balanced_bales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    splitid INT DEFAULT 0,
    growerid INT DEFAULT 0,
    transporter_growersid INT NOT NULL,
    sale_batchid INT DEFAULT 0,
    stoporder_processed TINYINT DEFAULT 0,
    printed TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(transporter_growersid), INDEX(sale_batchid), INDEX(created_at)
)",

"CREATE TABLE IF NOT EXISTS daily_totals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    balanced_balesid INT NOT NULL,
    exchange_rateid INT DEFAULT 0,
    mass DECIMAL(10,2) DEFAULT 0,
    usd_value DECIMAL(14,4) DEFAULT 0,
    zim_value DECIMAL(14,4) DEFAULT 0,
    laid_bales INT DEFAULT 0,
    sold_bales INT DEFAULT 0,
    rejected_bales INT DEFAULT 0,
    average_price DECIMAL(10,4) DEFAULT 0,
    sale_code VARCHAR(20) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(balanced_balesid), INDEX(created_at)
)",

"CREATE TABLE IF NOT EXISTS daily_totals_net_amount (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    balanced_balesid INT NOT NULL,
    usd_net_value DECIMAL(14,4) DEFAULT 0,
    zim_net_value DECIMAL(14,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(balanced_balesid)
)",

"CREATE TABLE IF NOT EXISTS daily_totals_split_amounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    balanced_balesid INT NOT NULL,
    usd_split_value DECIMAL(14,4) DEFAULT 0,
    zim_split_value DECIMAL(14,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(balanced_balesid)
)",

"CREATE TABLE IF NOT EXISTS grower_charges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    statutoryid INT NOT NULL,
    charge_typeid INT NOT NULL,
    selling_pointid INT DEFAULT 0,
    value DECIMAL(10,4) DEFAULT 0,
    active TINYINT DEFAULT 1
)",

"CREATE TABLE IF NOT EXISTS balanced_bales_charges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    grower_chargeid INT NOT NULL,
    balanced_balesid INT NOT NULL,
    usd_value DECIMAL(14,4) DEFAULT 0,
    zim_value DECIMAL(14,4) DEFAULT 0,
    INDEX(balanced_balesid)
)",

// ── Stop orders ────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS stoporders_timb (
    id INT AUTO_INCREMENT PRIMARY KEY,
    growerid INT NOT NULL,
    amount DECIMAL(14,4) DEFAULT 0,
    currency VARCHAR(10) DEFAULT 'USD',
    reference VARCHAR(100) DEFAULT '',
    sell_date DATE NULL,
    active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(growerid), INDEX(sell_date)
)",

"CREATE TABLE IF NOT EXISTS internal_stoporders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    growerid INT NOT NULL,
    transporter_growersid INT NULL,
    charge_type VARCHAR(60) DEFAULT 'other',
    description VARCHAR(255) DEFAULT '',
    amount DECIMAL(12,4) DEFAULT 0,
    sell_date DATE NULL,
    splitid INT DEFAULT 0,
    active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(growerid), INDEX(sell_date)
)",

"CREATE TABLE IF NOT EXISTS internal_stop_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    selling_pointid INT NOT NULL,
    description VARCHAR(200) DEFAULT '',
    creditor_number VARCHAR(40) DEFAULT '',
    active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

"CREATE TABLE IF NOT EXISTS internal_stop_order_amount (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    internal_stop_orderid INT NOT NULL,
    seasonid INT DEFAULT 0,
    charge_typeid INT DEFAULT 0,
    amount DECIMAL(12,4) DEFAULT 0,
    priority INT DEFAULT 0,
    default_deduction TINYINT DEFAULT 0,
    INDEX(internal_stop_orderid)
)",

"CREATE TABLE IF NOT EXISTS split_internal_stop_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    internal_stop_order_amountid INT NOT NULL,
    transporter_growersid INT NOT NULL,
    splitsid INT DEFAULT 0,
    quantity INT DEFAULT 1,
    INDEX(transporter_growersid)
)",

"CREATE TABLE IF NOT EXISTS internal_stop_order_deducted_amounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    split_internal_stop_orderid INT NOT NULL,
    balanced_balesid INT NOT NULL,
    amount DECIMAL(12,4) DEFAULT 0,
    INDEX(balanced_balesid)
)",

// ── Payments ───────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS payment_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transporter_growersid INT NOT NULL,
    sell_date DATE NOT NULL,
    currency ENUM('usd','zig') DEFAULT 'usd',
    gross_amount DECIMAL(14,4) DEFAULT 0,
    deductions DECIMAL(14,4) DEFAULT 0,
    net_amount DECIMAL(14,4) DEFAULT 0,
    amount_paid DECIMAL(14,4) DEFAULT 0,
    status ENUM('pending','partial','reviewed','approved','released','paid','on_hold') DEFAULT 'pending',
    payment_method VARCHAR(60) DEFAULT '',
    reference_number VARCHAR(100) DEFAULT '',
    paid_at TIMESTAMP NULL,
    paid_by INT NULL,
    reviewed_by INT NULL, reviewed_at TIMESTAMP NULL,
    approved_by INT NULL, approved_at TIMESTAMP NULL,
    released_by INT NULL, released_at TIMESTAMP NULL,
    notes VARCHAR(500) DEFAULT '',
    risk_flags VARCHAR(255) DEFAULT '',
    UNIQUE KEY ps_u (transporter_growersid, sell_date, currency),
    INDEX(sell_date)
)",

"CREATE TABLE IF NOT EXISTS payment_batch (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sell_date DATE NOT NULL,
    currency ENUM('usd','zig') DEFAULT 'usd',
    payment_method VARCHAR(60) NOT NULL,
    batch_ref VARCHAR(100) DEFAULT '',
    status ENUM('draft','reviewed','approved','released','locked') DEFAULT 'draft',
    notes VARCHAR(500) DEFAULT '',
    created_by INT NOT NULL,
    reviewed_by INT NULL, reviewed_at TIMESTAMP NULL,
    approved_by INT NULL, approved_at TIMESTAMP NULL,
    released_by INT NULL, released_at TIMESTAMP NULL,
    locked_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(sell_date)
)",

"CREATE TABLE IF NOT EXISTS payment_batch_item (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_batchid INT NOT NULL,
    transporter_growersid INT NOT NULL,
    net_amount DECIMAL(14,4) DEFAULT 0,
    amount_paid DECIMAL(14,4) DEFAULT 0,
    currency ENUM('usd','zig') DEFAULT 'usd',
    INDEX(payment_batchid)
)",

"CREATE TABLE IF NOT EXISTS payment_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('payment_status','payment_batch') DEFAULT 'payment_status',
    entity_id INT NOT NULL DEFAULT 0,
    userid INT NOT NULL,
    action VARCHAR(40) NOT NULL,
    currency ENUM('usd','zig') DEFAULT 'usd',
    amount DECIMAL(14,4) DEFAULT 0,
    payment_method VARCHAR(60) DEFAULT '',
    reference_number VARCHAR(100) DEFAULT '',
    notes VARCHAR(500) DEFAULT '',
    risk_flag VARCHAR(100) DEFAULT '',
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(entity_id), INDEX(logged_at)
)",

"CREATE TABLE IF NOT EXISTS payment_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL UNIQUE,
    pay_role ENUM('clerk','accountant','manager','admin') DEFAULT 'clerk'
)",

"CREATE TABLE IF NOT EXISTS payment_day_close (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sell_date DATE NOT NULL UNIQUE,
    selling_pointid INT NOT NULL,
    closed_by INT NOT NULL,
    closed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_paid_usd DECIMAL(14,4) DEFAULT 0,
    total_paid_zig DECIMAL(14,4) DEFAULT 0,
    grower_count INT DEFAULT 0,
    notes VARCHAR(255) DEFAULT '',
    INDEX(sell_date)
)",

"CREATE TABLE IF NOT EXISTS payment_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transporter_growersid INT NOT NULL,
    sell_date DATE NOT NULL,
    scheduled_date DATE NOT NULL,
    currency ENUM('usd','zig') DEFAULT 'usd',
    amount DECIMAL(14,4) DEFAULT 0,
    reason VARCHAR(255) DEFAULT '',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('scheduled','completed','cancelled') DEFAULT 'scheduled',
    INDEX(scheduled_date)
)",

// ── TIMB & dispatch ────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS timb_aes_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_type VARCHAR(20) NOT NULL,
    aes_key VARCHAR(255) DEFAULT '',
    aes_iv VARCHAR(255) DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)",

"CREATE TABLE IF NOT EXISTS selling_point_dispatch (
    id INT AUTO_INCREMENT PRIMARY KEY,
    selling_pointid INT NOT NULL,
    destination VARCHAR(100) DEFAULT '',
    dispatched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    userid INT NOT NULL,
    INDEX(selling_pointid)
)",

"CREATE TABLE IF NOT EXISTS dispatch (
    id INT AUTO_INCREMENT PRIMARY KEY,
    selling_point_dispatchid INT NOT NULL,
    bale_junusid INT NOT NULL,
    INDEX(selling_point_dispatchid)
)",

"CREATE TABLE IF NOT EXISTS dispatch_destination (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    selling_pointid INT DEFAULT 0
)",

// ── Fuel ───────────────────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS fuel_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    selling_pointid INT DEFAULT 0,
    week_start DATE NOT NULL,
    status ENUM('draft','submitted','approved','rejected') DEFAULT 'draft',
    notes TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(userid), INDEX(week_start)
)",

"CREATE TABLE IF NOT EXISTS fuel_request_growers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fuel_requestid INT NOT NULL,
    growerid INT NOT NULL,
    planned_day DATE NOT NULL,
    distance_km DECIMAL(8,2) DEFAULT 0,
    litres DECIMAL(8,2) DEFAULT 0,
    notes VARCHAR(255) DEFAULT '',
    INDEX(fuel_requestid)
)",

// ── Lat/long tracking ─────────────────────────────────────────────────────
"CREATE TABLE IF NOT EXISTS lat_long (
    id INT AUTO_INCREMENT PRIMARY KEY,
    growerid INT NOT NULL,
    latitude DECIMAL(10,7) DEFAULT 0,
    longitude DECIMAL(10,7) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(growerid)
)",

"CREATE TABLE IF NOT EXISTS device_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    officer_id INT NOT NULL,
    latitude DECIMAL(10,7) DEFAULT 0,
    longitude DECIMAL(10,7) DEFAULT 0,
    accuracy DECIMAL(8,2) DEFAULT 0,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(officer_id), INDEX(recorded_at)
)",

];

$created = $skipped = $failed = 0;
foreach ($tables as $sql) {
    // Extract table name for display
    preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/i', $sql, $m);
    $name = $m[1] ?? '?';

    $result = $conn->query($sql);
    if ($result === false) {
        echo "<span class='fail'>✕ $name</span> — " . htmlspecialchars($conn->error) . "<br>";
        $failed++;
    } else {
        // Check if it already existed
        $cnt = $conn->query("SELECT COUNT(*) c FROM `$name`")->fetch_assoc()['c'] ?? 0;
        echo "<span class='ok'>✓</span> $name ($cnt rows)<br>";
        $created++;
    }
}

$conn->query("SET FOREIGN_KEY_CHECKS=1");

echo "<hr>
<p><b>✓ Created/verified: $created</b> &nbsp;|&nbsp; <b class='fail'>✕ Failed: $failed</b></p>
<hr>
<h2>Next Steps</h2>
<p>1. <a href='setup.php'>→ Run setup.php</a> (create selling point, season, admin user, seed lookups)</p>
<p>2. <a href='login.php'>→ Login</a></p>
<p class='fail'>⚠ Delete rebuild.php and repair.php when done!</p>
</body></html>";
