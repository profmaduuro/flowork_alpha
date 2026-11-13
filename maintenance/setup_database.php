<?php
require_once dirname(__DIR__) . '/includes/admin_gate.php';
/**
 * setup_database.php — Run once to create all missing tables and seed data
 * Safe to run multiple times — uses IF NOT EXISTS and INSERT IGNORE
 *
 * Access is controlled by includes/admin_gate.php above: an administrator
 * session, localhost, or ?key=<SETUP_KEY from .env>. A second hardcoded key
 * check used to sit here as well; it has been removed because it duplicated
 * the gate against a literal committed to the source tree.
 */
mysqli_report(MYSQLI_REPORT_OFF);
require_once dirname(__DIR__) . "/includes/conn.php";

$results = [];
$errors  = [];

function run($conn, $sql, $label, &$results, &$errors) {
    $r = $conn->query($sql);
    if ($r === false) {
        $errors[] = "❌ $label: " . $conn->error;
    } else {
        $results[] = "✓ $label";
    }
}

// ── DISABLE FK CHECKS for safe creation order ─────────────────────────────
$conn->query("SET FOREIGN_KEY_CHECKS=0");
$conn->query("SET sql_mode=''");

// ── SCHEMA BASELINE ──────────────────────────────────────────────────────
// Every table and column the app uses (maintenance/schema.sql). Without it a
// new install only got part of the structure and the rest appeared as pages
// were first opened, so some screens failed on a fresh database.
$schema_file = __DIR__ . '/schema.sql';
if (is_file($schema_file)) {
    $sql_all = file_get_contents($schema_file);
    $n_ok = 0; $n_err = 0;
    if ($conn->multi_query($sql_all)) {
        do {
            if ($res = $conn->store_result()) $res->free();
            $n_ok++;
        } while ($conn->more_results() && $conn->next_result());
    }
    if ($conn->errno) { $n_err++; $errors[] = "❌ schema baseline: " . $conn->error; }
    if (!$n_err) $results[] = "✓ schema baseline applied ($n_ok statements)";
} else {
    $errors[] = "❌ schema baseline: maintenance/schema.sql is missing";
}

// ── 1. CORE LOOKUP TABLES ─────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS splits (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    value INTEGER NOT NULL
)","splits", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS currency (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    description VARCHAR(100) NOT NULL,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","currency", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS charge_type (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    description VARCHAR(100) NOT NULL
)","charge_type", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS timb_grades (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    description VARCHAR(100) NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","timb_grades", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS ticket_check_letter (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    characters VARCHAR(100),
    character_value INTEGER NOT NULL
)","ticket_check_letter", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS amount_split_points (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    description VARCHAR(100) NOT NULL
)","amount_split_points", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS auction_contract (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    auction INTEGER NOT NULL DEFAULT 0,
    contract INTEGER NOT NULL DEFAULT 1
)","auction_contract", $results, $errors);

// ── 2. SELLING POINT ──────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS selling_point (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    floor_code VARCHAR(100) NOT NULL DEFAULT '',
    floor_id VARCHAR(100) NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    company_name VARCHAR(120) DEFAULT '',
    location VARCHAR(120) DEFAULT '',
    address TEXT DEFAULT '',
    phone VARCHAR(30) DEFAULT '',
    email VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","selling_point", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS selling_point_image (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    image VARCHAR(100) NOT NULL,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","selling_point_image", $results, $errors);

// ── 3. KEYS ───────────────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS booking_keys (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    system_key VARCHAR(100) NOT NULL,
    vector VARCHAR(100) NOT NULL,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","booking_keys", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS stop_order_keys (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    system_key VARCHAR(100) NOT NULL,
    vector VARCHAR(100) NOT NULL,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","stop_order_keys", $results, $errors);

// ── 4. USERS & RIGHTS ─────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    password VARCHAR(100) NOT NULL,
    full_name VARCHAR(120) DEFAULT '',
    email VARCHAR(100) DEFAULT '',
    phone VARCHAR(30) DEFAULT '',
    role VARCHAR(30) DEFAULT 'clerk',
    rights JSON DEFAULT NULL,
    is_admin TINYINT DEFAULT 0,
    is_master TINYINT DEFAULT 0,
    selling_pointid INTEGER DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    confirm INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(100) DEFAULT ''
)","users", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS rights (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER NOT NULL,
    created_byid INTEGER NOT NULL,
    admin INTEGER NOT NULL DEFAULT 0,
    transporter_receiving INTEGER NOT NULL DEFAULT 0,
    scale INTEGER NOT NULL DEFAULT 0,
    junus INTEGER NOT NULL DEFAULT 0,
    buyer INTEGER NOT NULL DEFAULT 0,
    balance INTEGER NOT NULL DEFAULT 0,
    salessheets INTEGER NOT NULL DEFAULT 0,
    accounts INTEGER NOT NULL DEFAULT 0,
    transporter_payments INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","rights", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS flowork_system_admins (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","flowork_system_admins", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS flowork_subscriptions (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    status VARCHAR(30) DEFAULT 'Inactive',
    license_key VARCHAR(100) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    amount DECIMAL(14,2) DEFAULT 0,
    last_renewed TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY sp_sub (selling_pointid)
)","flowork_subscriptions", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS flowork_invoices (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    invoice_num VARCHAR(50) NOT NULL UNIQUE,
    selling_pointid INTEGER NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'Unpaid',
    issue_date DATE NOT NULL,
    paid_date DATE DEFAULT NULL,
    license_key VARCHAR(100) DEFAULT NULL,
    duration_days INTEGER DEFAULT 30,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","flowork_invoices", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS api_tokens (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    device VARCHAR(100) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(token), INDEX(user_id)
)","api_tokens", $results, $errors);

// ── 5. SEASONS & DATES ────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS seasons (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    description VARCHAR(100) NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","seasons", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS start_of_day (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER NOT NULL,
    seasonid INTEGER NOT NULL,
    description VARCHAR(100) NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","start_of_day", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS exchange_rate (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    seasonid INTEGER DEFAULT 0,
    start_of_dayid INTEGER DEFAULT 0,
    selling_pointid INTEGER DEFAULT 0,
    amount DOUBLE(100,6) NOT NULL DEFAULT 0,
    rate DOUBLE(100,6) DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    source VARCHAR(50) DEFAULT 'manual',
    sell_date DATE DEFAULT NULL,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","exchange_rate", $results, $errors);

// ── 6. GROWERS ────────────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS growers (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    selling_pointid INTEGER NOT NULL DEFAULT 0,
    grower_num VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    surname VARCHAR(100) NOT NULL,
    national_id VARCHAR(100) DEFAULT '',
    phone VARCHAR(100) DEFAULT '',
    address1 VARCHAR(100) DEFAULT '',
    address2 VARCHAR(100) DEFAULT '',
    contractor VARCHAR(100) DEFAULT '',
    farm_name VARCHAR(100) DEFAULT '',
    province VARCHAR(100) DEFAULT '',
    district VARCHAR(100) DEFAULT '',
    area VARCHAR(100) DEFAULT '',
    crop_type VARCHAR(30) DEFAULT 'Virginia',
    contract_number VARCHAR(60) DEFAULT '',
    dry_land_ha VARCHAR(100) DEFAULT '',
    irr_land_ha VARCHAR(100) DEFAULT '',
    dryland VARCHAR(100) DEFAULT '',
    active TINYINT DEFAULT 1,
    notes TEXT DEFAULT '',
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","growers", $results, $errors);

// ── 7. BALE LOCATIONS ─────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS bale_locations (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(100) DEFAULT '',
    subcontractor VARCHAR(100) DEFAULT '',
    is_primary TINYINT DEFAULT 0,
    active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","bale_locations", $results, $errors);

// ── 8. TRANSPORTERS ───────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS transporter (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    seasonid INTEGER DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    id_num VARCHAR(100) DEFAULT '',
    truck_num VARCHAR(100) DEFAULT '',
    location VARCHAR(100) DEFAULT '',
    location_id INTEGER DEFAULT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    open_close INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","transporter", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS transporter_growers (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    transporterid INTEGER NOT NULL,
    growerid INTEGER NOT NULL,
    selling_pointid INTEGER NOT NULL DEFAULT 0,
    locationid INTEGER DEFAULT NULL,
    splitid INTEGER DEFAULT 1,
    bales INTEGER NOT NULL DEFAULT 0,
    junused_bales INTEGER NOT NULL DEFAULT 0,
    open_close INTEGER NOT NULL DEFAULT 0,
    booked TINYINT DEFAULT 0,
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","transporter_growers", $results, $errors);

// ── 9. BALES ──────────────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS bale_mass (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    seasonid INTEGER DEFAULT 0,
    selling_pointid INTEGER DEFAULT 0,
    temp_bacode VARCHAR(100) NOT NULL,
    mass DECIMAL(10,2) NOT NULL,
    used INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","bale_mass", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS bale_junus (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    transporter_growersid INTEGER NOT NULL,
    bale_massid INTEGER NOT NULL,
    growerid INTEGER NOT NULL,
    bale_group INTEGER DEFAULT 0,
    lot INTEGER DEFAULT 0,
    splitid INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","bale_junus", $results, $errors);

// ── 10. TICKETS & SALES ───────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS tickets (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    bale_junusid INTEGER NOT NULL,
    barcode VARCHAR(100) NOT NULL,
    start_of_dayid INTEGER DEFAULT 0,
    created_by INTEGER DEFAULT NULL,
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","tickets", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS buyer (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    buyer_code VARCHAR(100) DEFAULT '',
    contact_name VARCHAR(80) DEFAULT '',
    phone VARCHAR(30) DEFAULT '',
    email VARCHAR(100) DEFAULT '',
    country VARCHAR(60) DEFAULT 'Zimbabwe',
    notes TEXT DEFAULT '',
    active TINYINT DEFAULT 1,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","buyer", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS buyer_grades (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    buyerid INTEGER NOT NULL DEFAULT 1,
    description VARCHAR(100) NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","buyer_grades", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS sold_bales (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    ticketsid INTEGER NOT NULL,
    sales_rep VARCHAR(100) DEFAULT '',
    sale_code VARCHAR(100) DEFAULT '',
    timb_gradesid INTEGER NOT NULL DEFAULT 1,
    buyer_gradesid INTEGER NOT NULL DEFAULT 1,
    price VARCHAR(100) NOT NULL DEFAULT '0',
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","sold_bales", $results, $errors);

// ── 11. FLOOR SUMMARY ─────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS floor_summary (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER NOT NULL,
    ticketsid INTEGER NOT NULL,
    `row` VARCHAR(20) DEFAULT '',
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ticket (ticketsid)
)","floor_summary", $results, $errors);

// ── 12. BALANCED BALES & CALCULATIONS ────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS balanced_bales (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    splitid INTEGER DEFAULT 1,
    growerid INTEGER DEFAULT 0,
    transporter_growersid INTEGER NOT NULL,
    selling_pointid INTEGER DEFAULT 0,
    sell_date DATE DEFAULT NULL,
    batch_reference VARCHAR(60) DEFAULT '',
    created_at VARCHAR(100) DEFAULT '',
    stoporder_processed INTEGER NOT NULL DEFAULT 0,
    sale_batchid INTEGER NOT NULL DEFAULT 0,
    printed INTEGER NOT NULL DEFAULT 0,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","balanced_bales", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS daily_totals (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    balanced_balesid INTEGER NOT NULL,
    exchange_rateid INTEGER DEFAULT 0,
    mass DECIMAL(12,2) NOT NULL DEFAULT 0,
    usd_value DOUBLE(100,4) NOT NULL DEFAULT 0,
    zim_value DOUBLE(100,4) NOT NULL DEFAULT 0,
    laid_bales INTEGER NOT NULL DEFAULT 0,
    sold_bales INTEGER NOT NULL DEFAULT 0,
    rejected_bales INTEGER NOT NULL DEFAULT 0,
    average_price DOUBLE(100,4) NOT NULL DEFAULT 0,
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","daily_totals", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS daily_totals_split_amounts (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    balanced_balesid INTEGER NOT NULL,
    usd_split_value DOUBLE(100,4) NOT NULL DEFAULT 0,
    zim_split_value DOUBLE(100,4) NOT NULL DEFAULT 0,
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","daily_totals_split_amounts", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS daily_totals_net_amount (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    balanced_balesid INTEGER NOT NULL,
    usd_net_value DOUBLE(100,4) NOT NULL DEFAULT 0,
    zim_net_value DOUBLE(100,4) NOT NULL DEFAULT 0,
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","daily_totals_net_amount", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS year_to_date_totals (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    growerid INTEGER NOT NULL,
    seasonid INTEGER DEFAULT 0,
    mass DECIMAL(12,2) NOT NULL DEFAULT 0,
    usd_value DOUBLE(100,4) NOT NULL DEFAULT 0,
    zim_value DOUBLE(100,4) NOT NULL DEFAULT 0,
    laid_bales INTEGER NOT NULL DEFAULT 0,
    sold_bales INTEGER NOT NULL DEFAULT 0,
    rejected_bales INTEGER NOT NULL DEFAULT 0,
    average_price DOUBLE(100,4) NOT NULL DEFAULT 0,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","year_to_date_totals", $results, $errors);

// ── 13. STATUTORY & CHARGES ───────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS statutory (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    description VARCHAR(100) NOT NULL,
    currencyid INTEGER NOT NULL DEFAULT 1,
    both_currencies INTEGER NOT NULL DEFAULT 0,
    accessid INTEGER NOT NULL DEFAULT 0
)","statutory", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS grower_charges (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    statutoryid INTEGER NOT NULL,
    selling_pointid INTEGER NOT NULL DEFAULT 0,
    seasonid INTEGER DEFAULT 0,
    charge_typeid INTEGER NOT NULL DEFAULT 1,
    amount DOUBLE(100,7) NOT NULL DEFAULT 0,
    creditor_no INTEGER DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","grower_charges", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS balanced_bales_charges (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    grower_chargeid INTEGER NOT NULL,
    balanced_balesid INTEGER NOT NULL,
    usd_value DOUBLE(100,6) NOT NULL DEFAULT 0,
    zim_value DOUBLE(100,6) NOT NULL DEFAULT 0,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","balanced_bales_charges", $results, $errors);

// ── 14. STOP ORDERS ───────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS stoporders (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    selling_pointid INTEGER DEFAULT 0,
    seasonid INTEGER DEFAULT 0,
    growerid INTEGER NOT NULL,
    sale_id VARCHAR(100) DEFAULT '',
    record_type INTEGER DEFAULT 0,
    grower_type VARCHAR(100) DEFAULT '',
    creditor_no VARCHAR(100) DEFAULT '',
    priority INTEGER DEFAULT 0,
    creditor_ref VARCHAR(100) DEFAULT '',
    account_no VARCHAR(100) DEFAULT '',
    amount_1 DECIMAL(12,2) DEFAULT 0,
    amount_2 DECIMAL(12,2) DEFAULT 0,
    amount_3 DECIMAL(12,2) DEFAULT 0,
    percent DECIMAL(8,4) DEFAULT 0,
    sos_date VARCHAR(100) DEFAULT '',
    formatted_sos_date DATE DEFAULT NULL,
    type VARCHAR(100) DEFAULT '',
    serial_no VARCHAR(100) DEFAULT '',
    active TINYINT DEFAULT 1,
    processed TINYINT DEFAULT 0,
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","stoporders", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS internal_stop_order (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER NOT NULL,
    selling_pointid INTEGER NOT NULL,
    description VARCHAR(100) NOT NULL,
    creditor_number INTEGER NOT NULL DEFAULT 0,
    charge_typeid INTEGER DEFAULT 1,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","internal_stop_order", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS internal_stop_order_amount (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER NOT NULL,
    internal_stop_orderid INTEGER NOT NULL,
    growerid INTEGER DEFAULT NULL,
    seasonid INTEGER NOT NULL DEFAULT 0,
    charge_typeid INTEGER NOT NULL DEFAULT 1,
    amount DOUBLE(100,6) NOT NULL DEFAULT 0,
    priority INTEGER NOT NULL DEFAULT 1,
    active INTEGER NOT NULL DEFAULT 1,
    default_deduction INTEGER NOT NULL DEFAULT 0,
    sell_date DATE DEFAULT NULL,
    reference VARCHAR(60) DEFAULT '',
    processed TINYINT DEFAULT 0,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","internal_stop_order_amount", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS split_internal_stop_order (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER NOT NULL,
    internal_stop_order_amountid INTEGER NOT NULL,
    transporter_growersid INTEGER NOT NULL,
    splitsid INTEGER NOT NULL DEFAULT 1,
    quantity INTEGER NOT NULL DEFAULT 1,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","split_internal_stop_order", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS internal_stop_order_to_balanced_bales (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER NOT NULL,
    internal_stop_order_amountid INTEGER NOT NULL,
    balanced_balesid INTEGER NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","internal_stop_order_to_balanced_bales", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS internal_stop_order_deducted_amounts (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER NOT NULL,
    balanced_balesid INTEGER NOT NULL,
    split_internal_stop_orderid INTEGER NOT NULL,
    amount DOUBLE(100,6) NOT NULL DEFAULT 0,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","internal_stop_order_deducted_amounts", $results, $errors);

// ── 15. GROWER CREDITORS ─────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS grower_creditors (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    selling_pointid INTEGER DEFAULT 0,
    name VARCHAR(120) DEFAULT '',
    creditor_no VARCHAR(100) NOT NULL,
    creditor_name VARCHAR(100) DEFAULT '',
    creditor_type VARCHAR(100) DEFAULT '',
    business_type VARCHAR(100) DEFAULT '',
    cell_phone VARCHAR(100) DEFAULT '',
    bank_name VARCHAR(80) DEFAULT '',
    account_num VARCHAR(40) DEFAULT '',
    email VARCHAR(100) DEFAULT '',
    status ENUM('active','suspended','closed') DEFAULT 'active',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","grower_creditors", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS selling_point_creditor_number (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    selling_pointid INTEGER NOT NULL,
    creditor_no VARCHAR(100) NOT NULL,
    description VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","selling_point_creditor_number", $results, $errors);

// ── 16. DISPATCH ──────────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS selling_point_dispatch (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    buyerid INTEGER DEFAULT 0,
    selling_pointid INTEGER DEFAULT 0,
    destination VARCHAR(100) DEFAULT '',
    dispatch_date DATE DEFAULT NULL,
    status ENUM('open','closed') DEFAULT 'open',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","selling_point_dispatch", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS dispatch_destination (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    selling_point_dispatchid INTEGER DEFAULT 0,
    driver_name VARCHAR(100) DEFAULT '',
    driver_surname VARCHAR(100) DEFAULT '',
    driver_id_number VARCHAR(100) DEFAULT '',
    horse_num VARCHAR(100) DEFAULT '',
    trailer_num VARCHAR(100) DEFAULT '',
    open_close INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","dispatch_destination", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS dispatch (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    dispatch_destinationid INTEGER DEFAULT 0,
    sold_baleid INTEGER DEFAULT 0,
    created_at VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","dispatch", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS dispatch_sessions (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER DEFAULT 0,
    destination VARCHAR(120) DEFAULT '',
    dispatch_date DATE DEFAULT NULL,
    status ENUM('open','closed') DEFAULT 'open',
    created_by INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","dispatch_sessions", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS dispatch_items (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    dispatch_sessionid INTEGER NOT NULL,
    sold_balesid INTEGER DEFAULT NULL,
    bale_massid INTEGER DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","dispatch_items", $results, $errors);

// ── 17. SALES BATCHING ────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS sales_batching (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER NOT NULL,
    selling_pointid INTEGER DEFAULT 0,
    sell_date DATE DEFAULT NULL,
    batch_reference VARCHAR(60) DEFAULT '',
    status ENUM('open','printed','reprinted') DEFAULT 'open',
    printed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","sales_batching", $results, $errors);

// ── 18. BOOKED BALES ─────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS booked_bales (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    growerid INTEGER NOT NULL,
    book_date VARCHAR(100) DEFAULT '',
    comm_graded VARCHAR(100) DEFAULT '',
    bales_booked VARCHAR(100) DEFAULT '',
    bales_delivered VARCHAR(100) DEFAULT '',
    bales_handled VARCHAR(100) DEFAULT '',
    sell_date VARCHAR(100) DEFAULT '',
    book_user VARCHAR(100) DEFAULT '',
    ip_address VARCHAR(100) DEFAULT '',
    floor VARCHAR(100) DEFAULT '',
    sale VARCHAR(100) DEFAULT '',
    booked_id VARCHAR(100) DEFAULT '',
    reoffer VARCHAR(100) DEFAULT '',
    booked_by VARCHAR(100) DEFAULT '',
    prefered_rep VARCHAR(100) DEFAULT '',
    last_sell VARCHAR(100) DEFAULT '',
    prefered_time VARCHAR(100) DEFAULT '',
    venue VARCHAR(100) DEFAULT '',
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","booked_bales", $results, $errors);

// ── 19. BANKING ───────────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS grower_banking (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    growerid INTEGER NOT NULL,
    selling_pointid INTEGER DEFAULT 0,
    currency ENUM('usd','zig') DEFAULT 'usd',
    bank_name VARCHAR(80) DEFAULT '',
    branch VARCHAR(60) DEFAULT '',
    account_num VARCHAR(40) DEFAULT '',
    account_name VARCHAR(100) DEFAULT '',
    swift_code VARCHAR(20) DEFAULT '',
    mobile_number VARCHAR(30) DEFAULT '',
    is_primary TINYINT DEFAULT 0,
    verified TINYINT DEFAULT 0,
    notes VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_grower_curr (growerid, currency)
)","grower_banking", $results, $errors);

// ── 20. VEHICLES ──────────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS vehicles (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL DEFAULT 0,
    reg_number VARCHAR(30) NOT NULL,
    make VARCHAR(50) DEFAULT '',
    model VARCHAR(50) DEFAULT '',
    type ENUM('truck','flatbed','pickup','van','other') DEFAULT 'truck',
    capacity_bales INTEGER DEFAULT 0,
    capacity_kg DECIMAL(10,2) DEFAULT 0,
    driver_name VARCHAR(80) DEFAULT '',
    driver_phone VARCHAR(20) DEFAULT '',
    status ENUM('available','on_route','maintenance','retired') DEFAULT 'available',
    notes TEXT DEFAULT '',
    last_used DATE DEFAULT NULL,
    last_gps_lat DECIMAL(10,6) DEFAULT NULL,
    last_gps_lng DECIMAL(10,6) DEFAULT NULL,
    last_gps_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","vehicles", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS vehicle_trips (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    vehicleid INTEGER NOT NULL,
    dispatch_sessionid INTEGER DEFAULT NULL,
    origin VARCHAR(120) DEFAULT '',
    destination VARCHAR(120) DEFAULT '',
    departed_at TIMESTAMP NULL DEFAULT NULL,
    arrived_at TIMESTAMP NULL DEFAULT NULL,
    bales_count INTEGER DEFAULT 0,
    mass_kg DECIMAL(10,2) DEFAULT 0,
    driver_name VARCHAR(80) DEFAULT '',
    notes TEXT DEFAULT '',
    status ENUM('loading','departed','arrived','cancelled') DEFAULT 'loading',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","vehicle_trips", $results, $errors);

// ── 21. LOANS ─────────────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS grower_loans (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    growerid INTEGER NOT NULL,
    loan_type ENUM('advance','input_loan','emergency','other') DEFAULT 'advance',
    amount DECIMAL(14,2) NOT NULL,
    currency ENUM('usd','zig') DEFAULT 'usd',
    interest_rate DECIMAL(6,4) DEFAULT 0,
    issued_date DATE NOT NULL,
    due_date DATE DEFAULT NULL,
    description VARCHAR(255) DEFAULT '',
    status ENUM('active','partially_paid','fully_paid','written_off') DEFAULT 'active',
    total_repaid DECIMAL(14,2) DEFAULT 0,
    issued_by INTEGER NOT NULL,
    notes TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","grower_loans", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS loan_repayments (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    loan_id INTEGER NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    repayment_date DATE NOT NULL,
    sell_date DATE DEFAULT NULL,
    method ENUM('sale_deduction','cash','bank_transfer','other') DEFAULT 'sale_deduction',
    reference VARCHAR(80) DEFAULT '',
    recorded_by INTEGER NOT NULL,
    notes VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","loan_repayments", $results, $errors);

// ── 22. SYSTEM TABLES ─────────────────────────────────────────────────────
run($conn,"CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(80) PRIMARY KEY,
    value TEXT DEFAULT ''
)","settings", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS system_audit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    selling_pointid INTEGER NOT NULL DEFAULT 0,
    userid INTEGER NOT NULL DEFAULT 0,
    action VARCHAR(30) NOT NULL,
    table_name VARCHAR(60) NOT NULL,
    record_id INTEGER DEFAULT NULL,
    old_values JSON DEFAULT NULL,
    new_values JSON DEFAULT NULL,
    note VARCHAR(255) DEFAULT '',
    ip_address VARCHAR(45) DEFAULT '',
    user_agent VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","system_audit", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS scale_queue (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    device_id VARCHAR(60) DEFAULT '',
    operator VARCHAR(60) DEFAULT '',
    temp_bacode VARCHAR(40) DEFAULT '',
    mass DECIMAL(10,2) NOT NULL,
    tare DECIMAL(10,2) DEFAULT 0,
    gross DECIMAL(10,2) DEFAULT 0,
    unit VARCHAR(10) DEFAULT 'kg',
    status ENUM('pending','processed','rejected') DEFAULT 'pending',
    raw_data TEXT DEFAULT '',
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL DEFAULT NULL
)","scale_queue", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS sms_log (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    growerid INTEGER DEFAULT 0,
    grower_num VARCHAR(30) DEFAULT '',
    phone VARCHAR(30) DEFAULT '',
    sell_date DATE NOT NULL,
    message TEXT DEFAULT '',
    status ENUM('sent','failed','simulated') DEFAULT 'simulated',
    response TEXT DEFAULT '',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","sms_log", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS notification_log (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    type ENUM('sms','email','whatsapp') DEFAULT 'sms',
    recipient VARCHAR(255) DEFAULT '',
    subject VARCHAR(255) DEFAULT '',
    status ENUM('sent','failed','simulated') DEFAULT 'sent',
    response TEXT DEFAULT '',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","notification_log", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS selling_days (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    sell_date DATE NOT NULL,
    status ENUM('open','closed','locked') DEFAULT 'open',
    opened_by INTEGER DEFAULT NULL,
    opened_at TIMESTAMP NULL DEFAULT NULL,
    closed_by INTEGER DEFAULT NULL,
    closed_at TIMESTAMP NULL DEFAULT NULL,
    locked_by INTEGER DEFAULT NULL,
    locked_at TIMESTAMP NULL DEFAULT NULL,
    bales_sold INTEGER DEFAULT 0,
    total_mass DECIMAL(12,2) DEFAULT 0,
    total_usd DECIMAL(14,2) DEFAULT 0,
    notes TEXT DEFAULT '',
    UNIQUE KEY uk_day (selling_pointid, sell_date)
)","selling_days", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS backup_log (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    filename VARCHAR(255) NOT NULL,
    file_size_kb INTEGER DEFAULT 0,
    table_count INTEGER DEFAULT 0,
    status ENUM('success','failed','partial') DEFAULT 'success',
    triggered_by ENUM('manual','cron','token') DEFAULT 'manual',
    notes TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","backup_log", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS grade_disputes (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    sold_balesid INTEGER NOT NULL,
    growerid INTEGER NOT NULL,
    original_grade VARCHAR(40) DEFAULT '',
    disputed_grade VARCHAR(40) DEFAULT '',
    original_price DECIMAL(10,4) DEFAULT 0,
    disputed_price DECIMAL(10,4) DEFAULT 0,
    reason TEXT NOT NULL,
    status ENUM('open','under_review','approved','rejected','resolved') DEFAULT 'open',
    raised_by INTEGER NOT NULL,
    reviewed_by INTEGER DEFAULT NULL,
    resolution_notes TEXT DEFAULT '',
    final_grade VARCHAR(40) DEFAULT '',
    final_price DECIMAL(10,4) DEFAULT 0,
    raised_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL
)","grade_disputes", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS void_log (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    void_type ENUM('ticket','sold_bale','balance','dispatch_item','payment') NOT NULL,
    record_id INTEGER NOT NULL,
    reason VARCHAR(255) NOT NULL,
    authorised_by INTEGER NOT NULL,
    reversed_by INTEGER NOT NULL,
    original_data JSON DEFAULT NULL,
    status ENUM('pending','approved','rejected','completed') DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actioned_at TIMESTAMP NULL DEFAULT NULL
)","void_log", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS re_offer_log (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    sold_balesid INTEGER NOT NULL,
    original_price DECIMAL(10,4) DEFAULT 0,
    re_offer_price DECIMAL(10,4) DEFAULT 0,
    re_offer_date DATE NOT NULL,
    status ENUM('offered','sold','withdrawn') DEFAULT 'offered',
    new_buyer_gradesid INTEGER DEFAULT NULL,
    processed_by INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","re_offer_log", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS rate_fetch_log (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    fetch_date DATE NOT NULL,
    rate_fetched DECIMAL(14,4) DEFAULT NULL,
    source VARCHAR(100) DEFAULT '',
    raw_response TEXT DEFAULT '',
    status ENUM('success','failed','no_data') DEFAULT 'success',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","rate_fetch_log", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS timb_grower_registry (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    grower_num VARCHAR(40) NOT NULL,
    name VARCHAR(80) DEFAULT '',
    surname VARCHAR(80) DEFAULT '',
    national_id VARCHAR(40) DEFAULT '',
    province VARCHAR(60) DEFAULT '',
    district_name VARCHAR(60) DEFAULT '',
    area VARCHAR(60) DEFAULT '',
    farm_name VARCHAR(80) DEFAULT '',
    crop_type VARCHAR(30) DEFAULT 'Virginia',
    registration_date DATE DEFAULT NULL,
    status ENUM('active','suspended','cancelled') DEFAULT 'active',
    timb_verified TINYINT DEFAULT 0,
    last_synced TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tgr (selling_pointid, grower_num)
)","timb_grower_registry", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS timb_sync_log (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    sync_type VARCHAR(40) DEFAULT '',
    records_fetched INTEGER DEFAULT 0,
    records_matched INTEGER DEFAULT 0,
    records_new INTEGER DEFAULT 0,
    records_updated INTEGER DEFAULT 0,
    status ENUM('success','failed','partial') DEFAULT 'success',
    notes TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","timb_sync_log", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS timb_submissions (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    sell_date DATE NOT NULL,
    submission_type ENUM('daily_sales','origination','grower_schedule','bank_details') DEFAULT 'daily_sales',
    bales_count INTEGER DEFAULT 0,
    payload_size_kb INTEGER DEFAULT 0,
    status ENUM('pending','submitted','accepted','rejected','error') DEFAULT 'pending',
    response_code VARCHAR(20) DEFAULT '',
    response_message TEXT DEFAULT '',
    submitted_by INTEGER NOT NULL,
    submitted_at TIMESTAMP NULL DEFAULT NULL,
    reference_number VARCHAR(80) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","timb_submissions", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS user_2fa (
    userid INTEGER PRIMARY KEY,
    secret VARCHAR(64) NOT NULL,
    enabled TINYINT DEFAULT 0,
    backup_codes TEXT DEFAULT '',
    enabled_at TIMESTAMP NULL DEFAULT NULL,
    last_used TIMESTAMP NULL DEFAULT NULL
)","user_2fa", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS season_growers (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    seasonid INTEGER NOT NULL,
    growerid INTEGER NOT NULL,
    selling_pointid INTEGER NOT NULL,
    contract_number VARCHAR(60) DEFAULT '',
    crop_type VARCHAR(30) DEFAULT 'Virginia',
    expected_kg DECIMAL(10,2) DEFAULT 0,
    status ENUM('active','withdrawn','completed') DEFAULT 'active',
    rolled_from_seasonid INTEGER DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sg (seasonid, growerid)
)","season_growers", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS buyer_contracts (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    selling_pointid INTEGER NOT NULL,
    buyerid INTEGER NOT NULL,
    season_year INTEGER NOT NULL,
    contract_number VARCHAR(60) DEFAULT '',
    credit_limit_usd DECIMAL(14,2) DEFAULT 0,
    credit_used_usd DECIMAL(14,2) DEFAULT 0,
    target_bales INTEGER DEFAULT 0,
    target_mass_kg DECIMAL(10,2) DEFAULT 0,
    status ENUM('active','suspended','expired') DEFAULT 'active',
    notes TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bc (selling_pointid, buyerid, season_year)
)","buyer_contracts", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS rbz_off_set (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    userid INTEGER DEFAULT 0,
    selling_pointid INTEGER NOT NULL,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","rbz_off_set", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS rbz_retention_rate (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    usd_percent DOUBLE(100,6) NOT NULL DEFAULT 0,
    other_currency_percent DOUBLE(100,6) NOT NULL DEFAULT 0,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)","rbz_retention_rate", $results, $errors);

run($conn,"CREATE TABLE IF NOT EXISTS rate_limits (
    ip_address VARCHAR(45) NOT NULL,
    action_key VARCHAR(100) NOT NULL,
    request_count INTEGER DEFAULT 1,
    window_start INTEGER NOT NULL,
    PRIMARY KEY (ip_address, action_key)
)","rate_limits", $results, $errors);

// ── RE-ENABLE FK CHECKS ───────────────────────────────────────────────────
$conn->query("SET FOREIGN_KEY_CHECKS=1");
function add_col($conn, $table, $col, $def, &$results, &$errors) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    if ($check && $check->num_rows === 0) {
        $r = $conn->query("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        if ($r === false) $errors[] = "❌ ADD $table.$col: " . $conn->error;
        else $results[] = "✓ Added column $table.$col";
    } else {
        $results[] = "✓ Column $table.$col already exists";
    }
}

// Add missing columns to users table
add_col($conn,'users','is_admin','TINYINT DEFAULT 0',$results,$errors);
add_col($conn,'users','role',"VARCHAR(30) DEFAULT 'clerk'",$results,$errors);
add_col($conn,'users','full_name',"VARCHAR(120) DEFAULT ''",$results,$errors);
add_col($conn,'users','email',"VARCHAR(100) DEFAULT ''",$results,$errors);
add_col($conn,'users','phone',"VARCHAR(30) DEFAULT ''",$results,$errors);
add_col($conn,'users','selling_pointid','INTEGER DEFAULT 0',$results,$errors);
add_col($conn,'users','rights','JSON DEFAULT NULL',$results,$errors);

// Add missing columns to other existing tables
add_col($conn,'selling_point','company_name',"VARCHAR(120) DEFAULT ''",$results,$errors);
add_col($conn,'selling_point','offtaker_comm_pct',"DECIMAL(5,2) DEFAULT 1.50",$results,$errors);
add_col($conn,'selling_point','location',"VARCHAR(120) DEFAULT ''",$results,$errors);
add_col($conn,'selling_point','address',"TEXT DEFAULT ''",$results,$errors);
add_col($conn,'selling_point','phone',"VARCHAR(30) DEFAULT ''",$results,$errors);
add_col($conn,'selling_point','email',"VARCHAR(100) DEFAULT ''",$results,$errors);
add_col($conn,'transporter_growers','locationid','INTEGER DEFAULT NULL',$results,$errors);
add_col($conn,'transporter_growers','splitid','INTEGER DEFAULT 1',$results,$errors);
add_col($conn,'transporter_growers','booked','TINYINT DEFAULT 0',$results,$errors);
add_col($conn,'balanced_bales','selling_pointid','INTEGER DEFAULT 0',$results,$errors);
add_col($conn,'balanced_bales','sell_date','DATE DEFAULT NULL',$results,$errors);
add_col($conn,'balanced_bales','batch_reference',"VARCHAR(60) DEFAULT ''",$results,$errors);
add_col($conn,'grower_creditors','selling_pointid','INTEGER DEFAULT 0',$results,$errors);
add_col($conn,'grower_creditors','bank_name',"VARCHAR(80) DEFAULT ''",$results,$errors);
add_col($conn,'grower_creditors','account_num',"VARCHAR(40) DEFAULT ''",$results,$errors);
add_col($conn,'grower_creditors','email',"VARCHAR(100) DEFAULT ''",$results,$errors);
add_col($conn,'grower_creditors','status',"VARCHAR(20) DEFAULT 'active'",$results,$errors);
add_col($conn,'tickets','created_by','INTEGER DEFAULT NULL',$results,$errors);
add_col($conn,'transporter','location_id','INTEGER DEFAULT NULL',$results,$errors);
add_col($conn,'bale_mass','selling_pointid','INTEGER DEFAULT 0',$results,$errors);
add_col($conn,'exchange_rate','selling_pointid','INTEGER DEFAULT 0',$results,$errors);
add_col($conn,'exchange_rate','sell_date','DATE DEFAULT NULL',$results,$errors);
add_col($conn,'exchange_rate','rate','DOUBLE DEFAULT 0',$results,$errors);
add_col($conn,'exchange_rate','source',"VARCHAR(50) DEFAULT 'manual'",$results,$errors);
add_col($conn,'growers','area',"VARCHAR(100) DEFAULT ''",$results,$errors);
add_col($conn,'growers','crop_type',"VARCHAR(30) DEFAULT 'Virginia'",$results,$errors);
add_col($conn,'growers','contract_number',"VARCHAR(60) DEFAULT ''",$results,$errors);
add_col($conn,'growers','active','TINYINT DEFAULT 1',$results,$errors);
add_col($conn,'growers','notes',"TEXT DEFAULT ''",$results,$errors);
add_col($conn,'sales_batching','selling_pointid','INTEGER DEFAULT 0',$results,$errors);
add_col($conn,'sales_batching','sell_date','DATE DEFAULT NULL',$results,$errors);
add_col($conn,'sales_batching','batch_reference',"VARCHAR(60) DEFAULT ''",$results,$errors);
add_col($conn,'sales_batching','status',"VARCHAR(20) DEFAULT 'open'",$results,$errors);
add_col($conn,'sales_batching','printed_at','TIMESTAMP NULL DEFAULT NULL',$results,$errors);
add_col($conn,'internal_stop_order','charge_typeid','INTEGER DEFAULT 1',$results,$errors);

// Set sysadmin as admin (now that column exists)
$r = $conn->query("UPDATE users SET is_admin=1, role='admin' WHERE username='sysadmin'");
if ($r) $results[] = "✓ sysadmin set as admin";
else $errors[] = "❌ sysadmin update: ".$conn->error;

// Seed developer credentials (moved from conn.php)
//
// The password used to be a fixed string in this file, which made the
// platform-owner console reachable on every install by anyone who had seen
// the source. It is now generated per install and shown once here. An
// install still on the old fixed password is rotated the same way.
$chk_dev = $conn->query("SELECT id, password_hash FROM flowork_system_admins WHERE username='developer' LIMIT 1");
$dev_row = ($chk_dev && $chk_dev->num_rows > 0) ? $chk_dev->fetch_assoc() : null;
if (!$dev_row || password_verify('flowork_dev_2026', $dev_row['password_hash'])) {
    $dev_pass = bin2hex(random_bytes(9));
    $dev_hash = $conn->real_escape_string(password_hash($dev_pass, PASSWORD_BCRYPT));
    if ($dev_row) {
        $r_dev = $conn->query("UPDATE flowork_system_admins SET password_hash='$dev_hash' WHERE id=" . (int)$dev_row['id']);
    } else {
        $r_dev = $conn->query("INSERT INTO flowork_system_admins (id, username, password_hash, email)
                      VALUES (1, 'developer', '$dev_hash', 'developer@flowork.com')");
    }
    if ($r_dev) $results[] = "developer platform password set to: $dev_pass — copy it now, it is not shown again";
    else $errors[] = "❌ developer user seeding: ".$conn->error;
} else {
    $results[] = "✓ developer user already exists";
}

// User create/edit used to write the plaintext password into the audit trail.
// Strip it from any rows already recorded (safe to run repeatedly).
$tbl_audit = $conn->query("SHOW TABLES LIKE 'system_audit'");
if ($tbl_audit && $tbl_audit->num_rows > 0) {
    $r_purge = $conn->query("UPDATE system_audit
        SET new_values = JSON_REMOVE(new_values, '$.password')
        WHERE table_name = 'users' AND new_values IS NOT NULL
          AND JSON_CONTAINS_PATH(new_values, 'one', '$.password')");
    if ($r_purge) $results[] = "✓ removed stored passwords from " . $conn->affected_rows . " audit row(s)";
    else $errors[] = "❌ audit password purge: ".$conn->error;
}

// ── SEED DATA (INSERT IGNORE — safe to run multiple times) ────────────────
$seeds = [
    // splits 0-15
    "INSERT IGNORE INTO splits (id,value) VALUES (1,0),(2,1),(3,2),(4,3),(5,4),(6,5),(7,6),(8,7),(9,8),(10,9),(11,10),(12,11),(13,12),(14,13),(15,14),(16,15)",
    // charge types (real system values)
    "INSERT IGNORE INTO charge_type (id,description) VALUES (1,'Value'),(2,'Mass'),(3,'Mass and Value'),(4,'Bales'),(5,'Amount')",
    // currencies
    "INSERT IGNORE INTO currency (id,description) VALUES (1,'USD'),(2,'ZiG')",
    // statutory charges
    "INSERT IGNORE INTO statutory (id,description,currencyid,accessid) VALUES (1,'Afforestration',2,1),(2,'Weighing & Auction',1,2),(3,'Floor Commission',1,3),(4,'Service Charge',1,4),(5,'MOA',1,5),(6,'MOA',2,6),(7,'Bank Charge',1,7)",
    // ticket check letters
    "INSERT IGNORE INTO ticket_check_letter (characters,character_value) VALUES ('0',0),('1',1),('2',2),('3',3),('4',4),('5',5),('6',6),('7',7),('8',8),('9',9),('A',10),('B',11),('C',12),('D',13),('E',14),('F',15),('G',16),('H',17),('I',18),('J',19),('K',20),('L',21),('M',22),('N',23),('O',24),('P',25),('Q',26),('R',27),('S',28),('T',29),('U',30),('V',31),('W',32),('X',33),('Y',34),('Z',35),('-',36),('.',37),(' ',38),('\$',39),('/',40),('+',41),('%',42)",
];

foreach ($seeds as $sql) {
    $label = substr($sql, 0, 50).'...';
    run($conn, $sql, "SEED: $label", $results, $errors);
}

// First administrator. The password used to be the fixed string 'core',
// published in this file; it is now generated once per install and shown
// only here. An existing sysadmin is never touched.
$chk_admin = $conn->query("SELECT id FROM users WHERE id=1 OR username='sysadmin' LIMIT 1");
if ($chk_admin && $chk_admin->num_rows === 0) {
    require_once dirname(__DIR__) . '/includes/password_helper.php';
    $admin_pass = bin2hex(random_bytes(9));
    $admin_hash = $conn->real_escape_string(flowork_password_hash($admin_pass));
    // sysadmin is the install's master user (all floors, can create other
    // masters). Without one nobody could ever be made master: a floor admin
    // may not grant it. provision_customer.php ties a customer's sysadmin to
    // its floor instead.
    if ($conn->query("INSERT INTO users (id,username,password,active,is_admin,is_master,role,selling_pointid,created_at)
                      VALUES (1,'sysadmin','$admin_hash',1,1,1,'admin',0,NOW())")) {
        $results[] = "sysadmin login created with password: $admin_pass — copy it now, it is not shown again, and change it after the first login";
    } else {
        $errors[] = "❌ sysadmin seeding: ".$conn->error;
    }
} else {
    $results[] = "✓ sysadmin user already exists";
}
$conn->query("UPDATE users SET is_admin=1, role='admin' WHERE username='sysadmin'");

if (PHP_SAPI === 'cli') {
    foreach ($results as $r) echo "  ok  $r\n";
    foreach ($errors  as $e) echo "  ERR $e\n";
    echo count($results), " succeeded, ", count($errors), " errors\n";
    exit($errors ? 1 : 0);
}

// ── REPORT ────────────────────────────────────────────────────────────────
$total = count($results) + count($errors);
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>DB Setup</title>
<style>body{font-family:Arial,sans-serif;max-width:900px;margin:2rem auto;padding:1rem;background:#f5f0e8}
h1{color:#1a1208;border-bottom:3px solid #c8952a;padding-bottom:.5rem}
.ok{color:#2e7d32;font-size:.85rem;margin:.1rem 0}
.err{color:#c62828;font-size:.85rem;margin:.1rem 0;font-weight:600}
.summary{background:#1a1208;color:#e8b84b;padding:1rem;border-radius:8px;margin-top:1rem;font-size:.9rem}
</style></head><body>
<h1>🌿 Flowork — Database Setup</h1>
<p style="color:#555;margin-bottom:1rem">Running <?=$total?> operations...</p>
<?php foreach ($results as $r): ?><p class="ok">✓ <?=htmlspecialchars($r)?></p><?php endforeach; ?>
<?php foreach ($errors  as $e): ?><p class="err">❌ <?=htmlspecialchars($e)?></p><?php endforeach; ?>
<div class="summary">
  ✅ <?=count($results)?> succeeded &nbsp;·&nbsp;
  ❌ <?=count($errors)?> errors
  <?php if(empty($errors)): ?>
  <br><br>All tables created successfully. <a href="login.php" style="color:#e8b84b">→ Go to Login</a>
  <br><small>Use the sysadmin password shown above for the first login, then change it.</small>
  <?php else: ?>
  <br><br>Some errors occurred — these are usually FK constraint issues on tables that already exist. Check above.
  <?php endif; ?>
</div>
</body></html>
