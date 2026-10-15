<?php
/**
 * net_amount_cleanup.php — one row per delivery in daily_totals_net_amount.
 *
 * Command line only. Report first; nothing changes without --apply.
 *
 *   C:/xampp/php/php.exe scratch/net_amount_cleanup.php
 *       Report: every delivery with more than one net row, the row the app
 *       reads today (newest), the row written when it was balanced (oldest),
 *       the net recorded in the audit log at balancing, and any payment made.
 *
 *   C:/xampp/php/php.exe scratch/net_amount_cleanup.php --apply --keep=latest --confirm=YES
 *       1. Backs up the net, totals, charges, stop-order and payment tables
 *          to backups/net_cleanup_<timestamp>.sql (stops if the backup fails).
 *       2. Deletes every net row except the kept one (--keep=latest|first).
 *       3. Adds UNIQUE KEY uq_dtn_bbid (balanced_balesid) so a second row can
 *          never be written again.
 *
 * --keep=latest leaves the figure every page shows today unchanged.
 * Deduplicating does not make a figure correct: see the reconciliation for
 * deliveries whose stored net is wrong.
 *
 * Restore: C:/xampp/mysql/bin/mysql.exe -uroot king < backups/net_cleanup_<timestamp>.sql
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_OFF);

$opts    = getopt('', ['apply', 'keep:', 'confirm:']);
$apply   = isset($opts['apply']);
$keep    = $opts['keep'] ?? 'latest';
if (!in_array($keep, ['latest', 'first'], true)) { fwrite(STDERR, "--keep must be latest or first\n"); exit(2); }

$conn = new mysqli('localhost', 'root', '', 'king');
if ($conn->connect_error) { fwrite(STDERR, "DB connect failed: {$conn->connect_error}\n"); exit(2); }
$all = fn($sql) => ($r = $conn->query($sql)) && $r !== true ? $r->fetch_all(MYSQLI_ASSOC) : [];

// ── Report ────────────────────────────────────────────────────────────────
$dups = $all("SELECT balanced_balesid bbid, COUNT(*) n, MIN(id) first_id, MAX(id) last_id
    FROM daily_totals_net_amount GROUP BY balanced_balesid HAVING n > 1 ORDER BY balanced_balesid");
$tot  = $all("SELECT COUNT(*) n, COUNT(DISTINCT balanced_balesid) d FROM daily_totals_net_amount")[0];
$orph = $all("SELECT COUNT(*) n FROM daily_totals_net_amount n LEFT JOIN balanced_bales b ON b.id=n.balanced_balesid WHERE b.id IS NULL")[0]['n'];
$has_uq = (bool)$all("SHOW INDEX FROM daily_totals_net_amount WHERE Key_name='uq_dtn_bbid'");

printf("daily_totals_net_amount: %d rows for %d deliveries; %d deliveries have duplicates; %d orphan rows; unique key %s\n\n",
    $tot['n'], $tot['d'], count($dups), $orph, $has_uq ? 'PRESENT' : 'missing');

$to_delete = 0;
foreach ($dups as $d) {
    $bbid = (int)$d['bbid'];
    $info = $all("SELECT bb.transporter_growersid tgid, g.grower_num, DATE(bb.created_at) bal_date
        FROM balanced_bales bb LEFT JOIN growers g ON g.id=bb.growerid WHERE bb.id=$bbid")[0] ?? [];
    $audit = $all("SELECT JSON_UNQUOTE(JSON_EXTRACT(new_values,'$.net')) net FROM system_audit
        WHERE table_name='balanced_bales' AND action='create' AND record_id=$bbid ORDER BY id LIMIT 1")[0]['net'] ?? null;
    $paid = $all("SELECT currency, net_amount, status, paid_at FROM payment_status
        WHERE transporter_growersid=" . (int)($info['tgid'] ?? 0) . " AND sell_date='" . ($info['bal_date'] ?? '') . "'");
    $keep_id = $keep === 'latest' ? (int)$d['last_id'] : (int)$d['first_id'];
    printf("bbid %d  tgid %s  grower %s  balanced %s  audit net at balancing: %s\n", $bbid,
        $info['tgid'] ?? '?', $info['grower_num'] ?? '?', $info['bal_date'] ?? '?', $audit === null ? 'n/a' : number_format((float)$audit, 4));
    foreach ($all("SELECT id, usd_net_value u, zim_net_value z, created_at, datetime FROM daily_totals_net_amount WHERE balanced_balesid=$bbid ORDER BY id") as $r) {
        $tag = (int)$r['id'] === $keep_id ? 'KEEP  ' : 'DELETE';
        if ($tag === 'DELETE') $to_delete++;
        printf("   %s id %-5d usd %12.4f  zig %14.4f  written %s%s\n", $tag, $r['id'], $r['u'], $r['z'], $r['datetime'],
            (int)$r['id'] === (int)$d['last_id'] ? '  <- what the app reads now' : '');
    }
    foreach ($paid as $p) printf("   payment: %s %s %.4f (%s %s)\n", strtoupper($p['currency']), $p['status'], $p['net_amount'], $p['paid_at'] ?? '', '');
    echo "\n";
}
printf("Plan (--keep=%s): delete %d rows, keep 1 per delivery, then add UNIQUE KEY uq_dtn_bbid.\n", $keep, $to_delete);

if (!$apply) { echo "\nREPORT ONLY - nothing changed. Re-run with --apply --keep=$keep --confirm=YES once approved.\n"; exit(0); }
if (($opts['confirm'] ?? '') !== 'YES') { fwrite(STDERR, "Refusing to apply without --confirm=YES\n"); exit(2); }
if ($orph > 0) { fwrite(STDERR, "Orphan rows present; review them first. Nothing changed.\n"); exit(1); }

// ── 1. Backup ─────────────────────────────────────────────────────────────
$dir = dirname(__DIR__) . '/backups';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$file = $dir . '/net_cleanup_' . date('Ymd_His') . '.sql';
$tables = 'daily_totals_net_amount daily_totals daily_totals_split_amounts balanced_bales_charges stoporder_deductions internal_stop_order_deducted_amounts payment_status';
$cmd = '"C:/xampp/mysql/bin/mysqldump.exe" -uroot --single-transaction king ' . $tables . ' > ' . escapeshellarg($file);
exec($cmd, $out, $rc);
if ($rc !== 0 || !is_file($file) || filesize($file) < 1000 || strpos(file_get_contents($file), 'daily_totals_net_amount') === false) {
    fwrite(STDERR, "Backup failed (rc=$rc). Nothing changed.\n"); exit(1);
}
echo "Backup written: $file (" . filesize($file) . " bytes)\n";

// ── 2. Delete the extra rows ──────────────────────────────────────────────
$agg = $keep === 'latest' ? 'MAX' : 'MIN';
$conn->begin_transaction();
$ok = $conn->query("DELETE n FROM daily_totals_net_amount n
    JOIN (SELECT balanced_balesid, $agg(id) keep_id FROM daily_totals_net_amount GROUP BY balanced_balesid) k
      ON k.balanced_balesid = n.balanced_balesid
    WHERE n.id <> k.keep_id");
$deleted = $conn->affected_rows;
if (!$ok || $deleted !== $to_delete) {
    $conn->rollback();
    fwrite(STDERR, "Delete affected $deleted rows, expected $to_delete (" . $conn->error . "). Rolled back.\n"); exit(1);
}
$conn->commit();
echo "Deleted $deleted rows.\n";

// ── 3. Unique key ─────────────────────────────────────────────────────────
if (!$has_uq && !$conn->query("ALTER TABLE daily_totals_net_amount ADD UNIQUE KEY uq_dtn_bbid (balanced_balesid)")) {
    fwrite(STDERR, "Adding unique key failed: {$conn->error}\n"); exit(1);
}
$after = $all("SELECT COUNT(*) n, COUNT(DISTINCT balanced_balesid) d FROM daily_totals_net_amount")[0];
printf("Done: %d rows for %d deliveries; unique key uq_dtn_bbid in place.\n", $after['n'], $after['d']);
