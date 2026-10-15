<?php
require_once __DIR__ . '/includes/admin_gate.php';
require_once "includes/conn.php";
$tables = [
    'internal_stop_order',
    'internal_stop_order_amount',
    'split_internal_stop_order',
    'internal_stop_order_deducted_amounts'
];

foreach ($tables as $t) {
    echo "Dropping $t...\n";
    $conn->query("DROP TABLE IF EXISTS $t");
    echo "Error: " . $conn->error . "\n";
}
