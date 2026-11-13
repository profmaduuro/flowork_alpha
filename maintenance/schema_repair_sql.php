<?php
/**
 * Write an SQL file that brings another Flowork database (e.g. the live one on
 * cPanel) up to this database's structure — command line only.
 *
 *   php maintenance/schema_repair_sql.php            writes backups/schema_repair_<date>.sql
 *   php maintenance/schema_repair_sql.php --out=x.sql
 *
 * Import the file in the other server's phpMyAdmin (Import tab). It only adds:
 *   - every missing table (CREATE TABLE IF NOT EXISTS, structure only)
 *   - every missing column and index on tables that already exist
 *   - a few global lookup tables' rows (charge types, currencies, RBZ split,
 *     statutory, GL accounts and posting rules), and only when that table is empty
 * It never drops, renames, changes or deletes anything, and can be imported
 * again safely. It runs on MySQL 5.7/8 and MariaDB alike: each column/index is
 * checked in information_schema and added through PREPARE, instead of the
 * MariaDB-only ADD COLUMN IF NOT EXISTS.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_OFF);
require_once dirname(__DIR__) . '/includes/conn.php';

$o   = getopt('', ['out:']);
$out = (string)($o['out'] ?? (dirname(__DIR__) . '/backups/schema_repair_' . date('Y-m-d') . '.sql'));
if (!is_dir(dirname($out))) mkdir(dirname($out), 0700, true);

$q = fn(string $s) => "'" . $conn->real_escape_string($s) . "'";

// Column definitions as SHOW CREATE TABLE prints them, made MySQL-8 safe:
// TEXT/BLOB/JSON may not have a literal default in MySQL, an expression
// default in brackets works in both.
function fw_fix_def(string $def): string {
    $def = preg_replace('/\bcurrent_timestamp\(\)/i', 'CURRENT_TIMESTAMP', $def);
    if (preg_match('/^`[^`]+`\s+(tiny|medium|long)?(text|blob)\b/i', $def)) {
        $def = preg_replace("/ DEFAULT ('(?:[^'\\\\]|\\\\.|'')*')/", ' DEFAULT ($1)', $def);
        $def = preg_replace('/ DEFAULT NULL\b/', '', $def);
    }
    return $def;
}

$tables = [];
$r = $conn->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
while ($row = $r->fetch_row()) $tables[] = $row[0];

$L = [];
$L[] = '-- Flowork schema repair, generated ' . date('Y-m-d H:i') . ' from ' . count($tables) . ' tables.';
$L[] = '-- Adds missing tables, columns and indexes. Changes or deletes nothing. Safe to import again.';
$L[] = 'SET NAMES utf8mb4;';
$L[] = 'SET FOREIGN_KEY_CHECKS=0;';
$L[] = "SET @old_mode=@@SESSION.sql_mode, SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO';";
$L[] = '';

$cols_added = 0; $idx_added = 0;
foreach ($tables as $t) {
    $create = $conn->query("SHOW CREATE TABLE `$t`")->fetch_row()[1];
    $create = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $create);
    $create = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
    $lines  = explode("\n", $create);
    foreach ($lines as $i => $ln) {
        if (preg_match('/^\s+`/', $ln)) $lines[$i] = '  ' . fw_fix_def(rtrim(trim($ln), ',')) . (str_ends_with(rtrim($ln), ',') ? ',' : '');
    }
    $L[] = "-- ── $t";
    $L[] = implode("\n", $lines) . ';';

    // columns, for a table that already existed
    foreach ($lines as $ln) {
        if (!preg_match('/^\s+(`([^`]+)`.*?),?$/', $ln, $m)) continue;
        $def = rtrim($m[1], ',');
        if (stripos($def, 'AUTO_INCREMENT') !== false) continue;   // the key column is there if the table is
        $col = $m[2];
        $alter = "ALTER TABLE `$t` ADD COLUMN $def";
        $L[] = "SET @s=(SELECT IF(COUNT(*)=0, " . $q($alter) . ", 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $q($t) . " AND COLUMN_NAME=" . $q($col) . ");";
        $L[] = 'PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;';
        $cols_added++;
    }
    // indexes (not the primary key, not foreign keys)
    foreach ($lines as $ln) {
        if (!preg_match('/^\s+((UNIQUE |FULLTEXT )?KEY `([^`]+)` \(.*\))[^)]*?,?$/', $ln, $m)) continue;
        $idx = $m[3];
        $alter = "ALTER TABLE `$t` ADD " . rtrim(trim($ln), ',');
        $L[] = "SET @s=(SELECT IF(COUNT(*)=0, " . $q($alter) . ", 'DO 0') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $q($t) . " AND INDEX_NAME=" . $q($idx) . ");";
        $L[] = 'PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;';
        $idx_added++;
    }
    $L[] = '';
}

// Global lookup rows, only into an empty table
$seed = ['charge_type', 'currency', 'statutory', 'gl_accounts', 'gl_posting_rules'];
foreach ($seed as $t) {
    if (!in_array($t, $tables, true)) continue;
    $res = $conn->query("SELECT * FROM `$t` ORDER BY 1");
    if (!$res || !$res->num_rows) continue;
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $cols = array_keys($rows[0]);
    $sel = [];
    foreach ($rows as $row) {
        $sel[] = 'SELECT ' . implode(',', array_map(fn($c) => ($row[$c] === null ? 'NULL' : $q((string)$row[$c])) . " AS `$c`", $cols));
    }
    $L[] = "-- rows for $t (only if it is empty)";
    $L[] = "INSERT INTO `$t` (`" . implode('`,`', $cols) . "`) SELECT * FROM (" . implode(' UNION ALL ', $sel) . ") x WHERE NOT EXISTS (SELECT 1 FROM `$t`);";
}
// RBZ split: the standard 70 / 30 when there is none
$L[] = '-- RBZ retention 70% USD / 30% ZiG (only if none is set; change it in Settings)';
$L[] = "INSERT INTO `rbz_retention_rate` (`usd_percent`,`other_currency_percent`) SELECT 0.70, 0.30 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `rbz_retention_rate`);";
$L[] = '';
$L[] = 'SET SESSION sql_mode=@old_mode;';
$L[] = 'SET FOREIGN_KEY_CHECKS=1;';
$L[] = "SELECT 'Flowork schema repair finished' AS result;";

file_put_contents($out, implode("\n", $L) . "\n");
echo "Wrote $out\n" . count($tables) . " tables, $cols_added column checks, $idx_added index checks.\n";
