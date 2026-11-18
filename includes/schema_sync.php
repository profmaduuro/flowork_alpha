<?php
/**
 * schema_sync.php — the database structure as data, and an additive upgrade.
 *
 * flowork_schema_export() reads this database's tables, columns and indexes
 * (plus the rows of a few global lookup tables) into an array, written to
 * updater/schema.json with each release (maintenance/schema_export.php).
 *
 * flowork_schema_apply() brings another database up to that structure: it
 * creates missing tables and adds missing columns and indexes. It never
 * drops, renames or changes a table, column, index or row, and lookup rows
 * are only added to a table that is empty, so each company's own settings
 * and data stay exactly as they are. It runs on MariaDB and MySQL alike.
 */

/** Lookup tables whose rows ship with a release (added only when the table is empty). */
const FLOWORK_SCHEMA_SEED_TABLES = ['charge_type', 'currency', 'statutory', 'gl_accounts', 'gl_posting_rules'];

/**
 * A column definition from SHOW CREATE TABLE, made portable: MySQL 8 refuses
 * a literal default on TEXT/BLOB columns (a bracketed one works in both) and
 * MariaDB writes current_timestamp() where MySQL 5.7 wants CURRENT_TIMESTAMP.
 */
function flowork_schema_fix_def(string $def): string {
    $def = preg_replace('/\bcurrent_timestamp\(\)/i', 'CURRENT_TIMESTAMP', $def);
    if (preg_match('/^`[^`]+`\s+(tiny|medium|long)?(text|blob)\b/i', $def)) {
        $def = preg_replace("/ DEFAULT ('(?:[^'\\\\]|\\\\.|'')*')/", ' DEFAULT ($1)', $def);
        $def = preg_replace('/ DEFAULT NULL\b/', '', $def);
    }
    return $def;
}

/** This database's structure: ['tables' => [name => [create, columns, indexes]], 'seeds' => [...]]. */
function flowork_schema_export(mysqli $conn): array {
    $out = ['format' => 1, 'tables' => [], 'seeds' => []];
    $r = $conn->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
    while ($r && $row = $r->fetch_row()) {
        $t = $row[0];
        $create = $conn->query("SHOW CREATE TABLE `$t`")->fetch_row()[1];
        $create = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $create);
        $create = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
        $lines = explode("\n", $create);
        $cols = []; $idx = [];
        foreach ($lines as $i => $ln) {
            if (preg_match('/^\s+(`([^`]+)`.*?),?$/', $ln, $m)) {
                $def = flowork_schema_fix_def(rtrim($m[1], ','));
                $lines[$i] = '  ' . $def . (str_ends_with(rtrim($ln), ',') ? ',' : '');
                $cols[$m[2]] = $def;
            } elseif (preg_match('/^\s+((?:UNIQUE |FULLTEXT )?KEY `([^`]+)` \(.*\))[^)]*?,?$/', $ln, $m)) {
                $idx[$m[2]] = rtrim(trim($ln), ',');
            }
        }
        $out['tables'][$t] = ['create' => implode("\n", $lines), 'columns' => $cols, 'indexes' => $idx];
    }
    foreach (FLOWORK_SCHEMA_SEED_TABLES as $t) {
        if (!isset($out['tables'][$t])) continue;
        $res = $conn->query("SELECT * FROM `$t` ORDER BY 1");
        if (!$res || !$res->num_rows) continue;
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $out['seeds'][$t] = ['columns' => array_keys($rows[0]), 'rows' => array_map('array_values', $rows)];
    }
    return $out;
}

/**
 * Bring this database up to $schema, adding only. Returns
 * ['tables' => n created, 'columns' => n added, 'indexes' => n added,
 *  'seeded' => [table...], 'errors' => [msg...]].
 */
function flowork_schema_apply(mysqli $conn, array $schema): array {
    $res = ['tables' => 0, 'columns' => 0, 'indexes' => 0, 'seeded' => [], 'errors' => []];
    $have = [];
    $q = $conn->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()");
    while ($q && $r = $q->fetch_row()) $have[$r[0]][strtolower($r[1])] = true;
    $have_idx = [];
    $q = $conn->query("SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()");
    while ($q && $r = $q->fetch_row()) $have_idx[$r[0]][strtolower($r[1])] = true;

    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    foreach ($schema['tables'] ?? [] as $t => $def) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) { $res['errors'][] = "skipped table with an odd name: $t"; continue; }
        if (!isset($have[$t])) {
            if ($conn->query($def['create'])) { $res['tables']++; $have[$t] = array_fill_keys(array_map('strtolower', array_keys($def['columns'])), true); $have_idx[$t] = array_fill_keys(array_map('strtolower', array_keys($def['indexes'])), true); }
            else $res['errors'][] = "create $t: " . $conn->error;
            continue;
        }
        foreach ($def['columns'] as $col => $cdef) {
            if (isset($have[$t][strtolower($col)])) continue;
            if (stripos($cdef, 'AUTO_INCREMENT') !== false) continue;   // the key column comes with the table
            if ($conn->query("ALTER TABLE `$t` ADD COLUMN $cdef")) { $res['columns']++; $have[$t][strtolower($col)] = true; }
            else $res['errors'][] = "add $t.$col: " . $conn->error;
        }
        foreach ($def['indexes'] as $name => $idef) {
            if (isset($have_idx[$t][strtolower($name)])) continue;
            if ($conn->query("ALTER TABLE `$t` ADD $idef")) $res['indexes']++;
            else $res['errors'][] = "add index $t.$name: " . $conn->error;   // e.g. a unique key the data does not allow
        }
    }
    foreach ($schema['seeds'] ?? [] as $t => $seed) {
        if (!isset($have[$t]) || !preg_match('/^[A-Za-z0-9_]+$/', $t)) continue;
        $c = $conn->query("SELECT 1 FROM `$t` LIMIT 1");
        if (!$c || $c->num_rows) continue;                 // has rows: the company's own, left alone
        $cols = array_values(array_filter($seed['columns'], fn($c) => isset($have[$t][strtolower($c)])));
        if (!$cols) continue;
        $pos = array_flip($seed['columns']);
        $ok = true;
        foreach ($seed['rows'] as $row) {
            $vals = array_map(fn($c) => $row[$pos[$c]] === null ? 'NULL' : "'" . $conn->real_escape_string((string)$row[$pos[$c]]) . "'", $cols);
            if (!$conn->query("INSERT INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ")")) { $ok = false; $res['errors'][] = "seed $t: " . $conn->error; break; }
        }
        if ($ok) $res['seeded'][] = $t;
    }
    // RBZ retention: the standard 70% USD / 30% ZiG only when none is set (each company sets its own in Settings)
    if (isset($have['rbz_retention_rate'])) {
        $c = $conn->query("SELECT 1 FROM rbz_retention_rate LIMIT 1");
        if ($c && !$c->num_rows && $conn->query("INSERT INTO rbz_retention_rate (usd_percent, other_currency_percent) VALUES (0.70, 0.30)")) $res['seeded'][] = 'rbz_retention_rate';
    }
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
    return $res;
}
