<?php
// No admin_gate here: this is a library for the payment module, not a
// maintenance script. Every caller runs require_login() and the 'payments'
// right check itself (see the rights map in includes/auth.php).
// Payment module schema — include this at the top of every pay_*.php file
// Creates all tables and returns helper functions

function pay_schema($conn, $spid) {

// ── Payment roles (extends existing rights table) ─────────────────────────
// pay_role: clerk | accountant | manager | admin
$conn->query("CREATE TABLE IF NOT EXISTS payment_roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  userid INT NOT NULL UNIQUE,
  pay_role ENUM('clerk','accountant','manager','admin') DEFAULT 'clerk',
  INDEX(userid)
)");

// ── Grower bank accounts (enhanced) ───────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS grower_banking (
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
)");

// ── Payment status (per grower per date per currency) ─────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS payment_status (
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
  UNIQUE KEY ps_unique (transporter_growersid, sell_date, currency),
  INDEX(sell_date), INDEX(status)
)");
// Add new columns if upgrading
$upgrades = [
  'amount_paid DECIMAL(14,4) DEFAULT 0',
  'reviewed_by INT NULL', 'reviewed_at TIMESTAMP NULL',
  'approved_by INT NULL', 'approved_at TIMESTAMP NULL',
  'released_by INT NULL', 'released_at TIMESTAMP NULL',
  'risk_flags VARCHAR(255) DEFAULT \'\'',
];
foreach ($upgrades as $col_def) {
    $cn = explode(' ', $col_def)[0];
    $ck = $conn->query("SHOW COLUMNS FROM payment_status LIKE '$cn'");
    if ($ck && $ck->num_rows === 0)
        @$conn->query("ALTER TABLE payment_status ADD COLUMN $col_def");
}

// ── Payment batches ────────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS payment_batch (
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
  INDEX(sell_date), INDEX(status)
)");
$batch_upgrades = [
  'status ENUM(\'draft\',\'reviewed\',\'approved\',\'released\',\'locked\') DEFAULT \'draft\'',
  'reviewed_by INT NULL', 'reviewed_at TIMESTAMP NULL',
  'approved_by INT NULL', 'approved_at TIMESTAMP NULL',
  'released_by INT NULL', 'released_at TIMESTAMP NULL',
  'locked_at TIMESTAMP NULL',
];
foreach ($batch_upgrades as $col_def) {
    $cn = explode(' ', $col_def)[0];
    $ck = $conn->query("SHOW COLUMNS FROM payment_batch LIKE '$cn'");
    if ($ck && $ck->num_rows === 0)
        @$conn->query("ALTER TABLE payment_batch ADD COLUMN $col_def");
}

// ── Payment batch items ────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS payment_batch_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_batchid INT NOT NULL,
  transporter_growersid INT NOT NULL,
  net_amount DECIMAL(14,4) DEFAULT 0,
  amount_paid DECIMAL(14,4) DEFAULT 0,
  currency ENUM('usd','zig') DEFAULT 'usd',
  INDEX(payment_batchid)
)");
$item_upgrades = ['amount_paid DECIMAL(14,4) DEFAULT 0'];
foreach ($item_upgrades as $col_def) {
    $cn = explode(' ', $col_def)[0];
    $ck = $conn->query("SHOW COLUMNS FROM payment_batch_item LIKE '$cn'");
    if ($ck && $ck->num_rows === 0)
        @$conn->query("ALTER TABLE payment_batch_item ADD COLUMN $col_def");
}

// ── Payment audit log ─────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS payment_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('payment_status','payment_batch') DEFAULT 'payment_status',
  entity_id INT NOT NULL,
  userid INT NOT NULL,
  action VARCHAR(40) NOT NULL,
  currency ENUM('usd','zig') DEFAULT 'usd',
  amount DECIMAL(14,4) DEFAULT 0,
  payment_method VARCHAR(60) DEFAULT '',
  reference_number VARCHAR(100) DEFAULT '',
  notes VARCHAR(500) DEFAULT '',
  risk_flag VARCHAR(100) DEFAULT '',
  logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(entity_id), INDEX(logged_at), INDEX(action)
)");
// Upgrade payment_log: add entity_type, entity_id (alias of payment_statusid), risk_flag
$ck_et = $conn->query("SHOW COLUMNS FROM payment_log LIKE 'entity_type'");
if ($ck_et && $ck_et->num_rows === 0)
    @$conn->query("ALTER TABLE payment_log ADD COLUMN entity_type ENUM('payment_status','payment_batch') DEFAULT 'payment_status'");
$ck_ei = $conn->query("SHOW COLUMNS FROM payment_log LIKE 'entity_id'");
if ($ck_ei && $ck_ei->num_rows === 0) {
    // Try to add entity_id; populate from payment_statusid if that column exists
    @$conn->query("ALTER TABLE payment_log ADD COLUMN entity_id INT NOT NULL DEFAULT 0");
    $ck_ps = $conn->query("SHOW COLUMNS FROM payment_log LIKE 'payment_statusid'");
    if ($ck_ps && $ck_ps->num_rows > 0)
        @$conn->query("UPDATE payment_log SET entity_id = payment_statusid WHERE entity_id = 0");
}
$ck_rf = $conn->query("SHOW COLUMNS FROM payment_log LIKE 'risk_flag'");
if ($ck_rf && $ck_rf->num_rows === 0)
    @$conn->query("ALTER TABLE payment_log ADD COLUMN risk_flag VARCHAR(100) DEFAULT ''");

// ── Payment day close ─────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS payment_day_close (
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
)");

// ── Scheduled payments ────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS payment_schedule (
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
  INDEX(scheduled_date), INDEX(transporter_growersid)
)");
}

// Role, risk and log helpers live in includes/pay_helpers.php so the mobile
// API can use them without passing through this file's admin gate.
require_once __DIR__ . '/includes/pay_helpers.php';
