<?php
require_once dirname(__DIR__) . '/includes/admin_gate.php';
mysqli_report(MYSQLI_REPORT_OFF);
require_once dirname(__DIR__) . "/includes/conn.php";

function show_columns($conn, $table) {
    echo "\n=== Columns in $table ===\n";
    $res = $conn->query("DESCRIBE `$table`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            echo "{$row['Field']} - {$row['Type']} (Null: {$row['Null']}, Key: {$row['Key']}, Default: {$row['Default']})\n";
        }
    } else {
        echo "Error describing $table: " . $conn->error . "\n";
    }
}

show_columns($conn, 'booked_bales');
show_columns($conn, 'transporter_growers');
show_columns($conn, 'growers');
show_columns($conn, 'balanced_bales');

