<?php
// Columns internal_stop_order_amount needs for the Creditor portal's stop orders
// (grower, reference, sell date, processed flag). They used to be added only by
// a File Manager stop-order import, and the reference column's definition was
// invalid ("VARCHAR(100) DEFAULT" with no value), so it was never created and
// the Stop Orders list query failed. Checked once per version, not per request.
require_once __DIR__ . '/schema_version.php';

const STOPORDER_AMOUNT_SCHEMA_VERSION = 1;   // bump when a column is added below

if (!function_exists('flowork_ensure_stoporder_amount_cols')) {
    function flowork_ensure_stoporder_amount_cols(mysqli $conn): void {
        if (flowork_schema_is_current($conn, 'stoporder_amount_cols', STOPORDER_AMOUNT_SCHEMA_VERSION)) return;
        $ok = true;
        foreach ([
            'growerid'  => 'growerid INT NULL DEFAULT NULL',
            'sell_date' => 'sell_date DATE NULL DEFAULT NULL',
            'reference' => "reference VARCHAR(100) NOT NULL DEFAULT ''",
            'processed' => 'processed TINYINT NOT NULL DEFAULT 0',
        ] as $col => $ddl) {
            $chk = $conn->query("SHOW COLUMNS FROM internal_stop_order_amount LIKE '$col'");
            if (!$chk || $chk === true) { $ok = false; continue; }
            if ($chk->num_rows === 0 && !$conn->query("ALTER TABLE internal_stop_order_amount ADD COLUMN $ddl")) $ok = false;
        }
        // Mark only when every column is in place, so a failure is retried next load
        if ($ok) flowork_schema_mark($conn, 'stoporder_amount_cols', STOPORDER_AMOUNT_SCHEMA_VERSION);
    }
}
