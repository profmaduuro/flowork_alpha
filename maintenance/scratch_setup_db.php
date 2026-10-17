<?php
require_once dirname(__DIR__) . '/includes/admin_gate.php';
require_once dirname(__DIR__) . "/includes/conn.php";
$conn->query("CREATE TABLE IF NOT EXISTS internal_stop_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    selling_pointid INT NOT NULL,
    description VARCHAR(100) NOT NULL DEFAULT '',
    creditor_number INT NOT NULL DEFAULT 0,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
echo "Table 1 error: " . $conn->error . "\n";

$conn->query("CREATE TABLE IF NOT EXISTS internal_stop_order_amount (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    internal_stop_orderid INT NOT NULL,
    seasonid INT NOT NULL DEFAULT 0,
    charge_typeid INT NOT NULL DEFAULT 1,
    amount DOUBLE NOT NULL DEFAULT 0,
    priority INT NOT NULL DEFAULT 1,
    active INT NOT NULL DEFAULT 1,
    default_deduction INT NOT NULL DEFAULT 0,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
echo "Table 2 error: " . $conn->error . "\n";

$conn->query("CREATE TABLE IF NOT EXISTS split_internal_stop_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    internal_stop_order_amountid INT NOT NULL,
    transporter_growersid INT NOT NULL,
    splitsid INT NOT NULL DEFAULT 1,
    quantity INT NOT NULL DEFAULT 1,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
echo "Table 3 error: " . $conn->error . "\n";

$conn->query("CREATE TABLE IF NOT EXISTS internal_stop_order_deducted_amounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    split_internal_stop_orderid INT NOT NULL,
    balanced_balesid INT NOT NULL,
    amount DOUBLE NOT NULL DEFAULT 0,
    datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
echo "Table 4 error: " . $conn->error . "\n";
