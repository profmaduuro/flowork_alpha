<?php
/**
 * payment_refs.php — payment identity shared by every flow that settles a payment.
 *
 * Included by payments.php (direct pay + batch create) and pay_batches.php
 * (review/approve/release workflow) so a payment gets its reference and its
 * sales-sheet links no matter which route marked it paid.
 *
 * Requires $conn to be in scope.
 */
if (!isset($conn) || !$conn) return;

// Three distinct identifiers, deliberately kept apart:
//   payment_sheets.payment_ref   PAY-YYYY-NNNNNN — the payment reference for ONE
//                                batch/split. Unique per batch, because a single
//                                batch can be paid on its own.
//   payment_status.payment_ref   RUN-YYYY-NNNNNN — groups the batches settled
//                                together in one payment run for a grower/day.
//   payment_status.reference_number — the external bank / EcoCash transaction ref.
foreach (['payment_method VARCHAR(60) DEFAULT \'\'', 'reference_number VARCHAR(100) DEFAULT \'\'',
          'payment_ref VARCHAR(40) DEFAULT NULL'] as $cd) {
    $cn = explode(' ', $cd)[0];
    $ck = $conn->query("SHOW COLUMNS FROM payment_status LIKE '$cn'");
    if ($ck && $ck->num_rows === 0) $conn->query("ALTER TABLE payment_status ADD COLUMN $cd");
}
// payment_ref must allow NULL: unpaid rows have no reference, and MySQL permits
// many NULLs under a UNIQUE key but only one empty string.
$conn->query("ALTER TABLE payment_status MODIFY payment_ref VARCHAR(40) DEFAULT NULL");
$conn->query("UPDATE payment_status SET payment_ref=NULL WHERE payment_ref=''");

// Which sales sheets (balanced_bales rows) each payment settles. A grower is paid
// once per sale day, and that one payment can cover several sheets (Batch #/Split).
$conn->query("CREATE TABLE IF NOT EXISTS payment_sheets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_statusid INT NOT NULL,
  balanced_balesid INT NOT NULL,
  sale_batchid INT DEFAULT 0,
  splitid INT DEFAULT 0,
  payment_ref VARCHAR(40) DEFAULT NULL,
  linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_pay_sheet (payment_statusid, balanced_balesid),
  INDEX idx_bb (balanced_balesid)
)");
// Older installs created payment_sheets before it carried its own reference
$_psr = $conn->query("SHOW COLUMNS FROM payment_sheets LIKE 'payment_ref'");
if ($_psr && $_psr->num_rows === 0)
    $conn->query("ALTER TABLE payment_sheets ADD COLUMN payment_ref VARCHAR(40) DEFAULT NULL");
$conn->query("UPDATE payment_sheets SET payment_ref=NULL WHERE payment_ref=''");

/**
 * Allocate the next unique reference for a table+column pair, e.g. PAY-2026-000042.
 * The UNIQUE index is the real guard; the retry loop just steps past a number
 * another session claimed first (errno 1062 = duplicate key).
 */
if (!function_exists('flowork_alloc_ref')) {
    function flowork_alloc_ref($conn, $table, $rowid, $prefix) {
        $year  = date('Y');
        $table = preg_replace('/[^a-z_]/', '', $table);
        $rowid = (int)$rowid;
        $q = $conn->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(payment_ref,'-',-1) AS UNSIGNED)),0) mx
                           FROM $table WHERE payment_ref LIKE '$prefix-$year-%'");
        $next = (int)(($q && $q !== true) ? ($q->fetch_assoc()['mx'] ?? 0) : 0);
        for ($try = 0; $try < 50; $try++) {
            $next++;
            $ref = sprintf('%s-%s-%06d', $prefix, $year, $next);
            $conn->query("UPDATE $table SET payment_ref='$ref' WHERE id=$rowid");
            if ($conn->affected_rows > 0 && !$conn->errno) return $ref;
            if ($conn->errno && $conn->errno !== 1062) break; // real error, not a duplicate
        }
        return '';
    }
}

/** RUN-YYYY-NNNNNN — groups the batches settled together in one payment run. */
if (!function_exists('flowork_next_payment_ref')) {
    function flowork_next_payment_ref($conn, $psid) {
        return flowork_alloc_ref($conn, 'payment_status', $psid, 'RUN');
    }
}

/** PAY-YYYY-NNNNNN — the reference for one batch/split. Allocated once per
 *  payment_sheets row so a singly-paid batch carries its own distinct reference. */
if (!function_exists('flowork_sheet_ref_for')) {
    function flowork_sheet_ref_for($conn, $sheet_row_id) {
        $sheet_row_id = (int)$sheet_row_id;
        $q = $conn->query("SELECT payment_ref FROM payment_sheets WHERE id=$sheet_row_id LIMIT 1");
        $cur = ($q && $q !== true && $q->num_rows) ? trim((string)($q->fetch_assoc()['payment_ref'] ?? '')) : '';
        return $cur !== '' ? $cur : flowork_alloc_ref($conn, 'payment_sheets', $sheet_row_id, 'PAY');
    }
}

/** Return this payment's reference, allocating one only if it has none yet. */
if (!function_exists('flowork_pay_ref_for')) {
    function flowork_pay_ref_for($conn, $psid) {
        $psid = (int)$psid;
        $q = $conn->query("SELECT payment_ref FROM payment_status WHERE id=$psid LIMIT 1");
        $cur = ($q && $q !== true && $q->num_rows) ? trim($q->fetch_assoc()['payment_ref'] ?? '') : '';
        return $cur !== '' ? $cur : flowork_next_payment_ref($conn, $psid);
    }
}

/**
 * One-time self-healing backfill: give every already-recorded payment a reference
 * and link it to its sales sheets, then add the UNIQUE index (which could not be
 * created while historic rows all shared a blank reference).
 */
if (!function_exists('flowork_backfill_payment_refs')) {
    function flowork_backfill_payment_refs($conn) {
        // Payment-run refs used to be issued as PAY-; that prefix now belongs to the
        // per-batch reference, so historic run refs are re-badged RUN- once.
        $conn->query("UPDATE payment_status SET payment_ref = CONCAT('RUN-', SUBSTRING(payment_ref, 5))
                      WHERE payment_ref LIKE 'PAY-%'");

        $q = $conn->query("SELECT id, transporter_growersid, sell_date
                           FROM payment_status
                           WHERE payment_ref IS NULL AND status IN ('paid','partial','released')");
        if ($q && $q !== true) {
            while ($row = $q->fetch_assoc()) {
                flowork_next_payment_ref($conn, (int)$row['id']);
                flowork_link_payment_sheets($conn, (int)$row['id'],
                                            (int)$row['transporter_growersid'], $row['sell_date']);
            }
        }
        // Any sheet linked before per-batch references existed
        $sq = $conn->query("SELECT id FROM payment_sheets WHERE payment_ref IS NULL");
        if ($sq && $sq !== true) {
            while ($s = $sq->fetch_assoc()) flowork_sheet_ref_for($conn, (int)$s['id']);
        }

        $ix = $conn->query("SHOW INDEX FROM payment_status WHERE Key_name='uk_payment_ref'");
        if ($ix && $ix->num_rows === 0)
            $conn->query("ALTER TABLE payment_status ADD UNIQUE KEY uk_payment_ref (payment_ref)");
        $ix2 = $conn->query("SHOW INDEX FROM payment_sheets WHERE Key_name='uk_sheet_ref'");
        if ($ix2 && $ix2->num_rows === 0)
            $conn->query("ALTER TABLE payment_sheets ADD UNIQUE KEY uk_sheet_ref (payment_ref)");
    }
}

/**
 * Record which sales sheets a payment settles: every balanced_bales row for that
 * grower delivery on that sale date.
 */
if (!function_exists('flowork_link_payment_sheets')) {
    function flowork_link_payment_sheets($conn, $psid, $tgid, $sell_date) {
        $psid = (int)$psid; $tgid = (int)$tgid;
        $sd   = $conn->real_escape_string($sell_date);
        // Some balance flows leave balanced_bales.sell_date NULL; the Payments page
        // keys a sheet by DATE(created_at) in that case, so match the same way.
        // Matching on sell_date alone left those payments with no sheet link and
        // no PAY- reference.
        $q = $conn->query("SELECT id, sale_batchid, splitid FROM balanced_bales
                           WHERE transporter_growersid=$tgid
                             AND COALESCE(sell_date, DATE(created_at))='$sd'");
        if (!$q || $q === true) return 0;
        $ids = [];
        while ($bb = $q->fetch_assoc()) $ids[] = (int)$bb['id'];
        return flowork_link_payment_sheet_ids($conn, $psid, $ids);
    }
}

/** Link an explicit set of sales sheets (used by the batch flow, which settles
 *  only the bales the clerk selected rather than every sheet for that day). */
if (!function_exists('flowork_link_payment_sheet_ids')) {
    function flowork_link_payment_sheet_ids($conn, $psid, array $bbids) {
        $psid = (int)$psid;
        $ids = array_values(array_unique(array_filter(array_map('intval', $bbids))));
        if (!$ids) return 0;
        $q = $conn->query("SELECT id, sale_batchid, splitid FROM balanced_bales
                           WHERE id IN (".implode(',', $ids).")");
        if (!$q || $q === true) return 0;
        $n = 0;
        while ($bb = $q->fetch_assoc()) {
            $bbid = (int)$bb['id'];
            $conn->query("INSERT IGNORE INTO payment_sheets
                (payment_statusid, balanced_balesid, sale_batchid, splitid)
                VALUES ($psid, $bbid, ".(int)$bb['sale_batchid'].", ".(int)$bb['splitid'].")");
            // Every batch gets its own reference, whether it was just created or already linked
            $sq = $conn->query("SELECT id FROM payment_sheets
                                WHERE payment_statusid=$psid AND balanced_balesid=$bbid LIMIT 1");
            if ($sq && $sq !== true && $sq->num_rows)
                flowork_sheet_ref_for($conn, (int)$sq->fetch_assoc()['id']);
            $n++;
        }
        return $n;
    }
}

// Runs once: after the first pass every payment has a ref, so the query matches nothing.
flowork_backfill_payment_refs($conn);
