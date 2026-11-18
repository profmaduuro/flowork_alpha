<?php
/**
 * Write updater/schema.json from this database's structure — command line only.
 *
 *   php maintenance/schema_export.php
 *
 * Run it on the development database whenever a change adds tables or
 * columns, and commit updater/schema.json with that change. Each release
 * carries the file, and every installation's updater brings its own database
 * up to it (adding only; see includes/schema_sync.php).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_OFF);
require_once dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/includes/schema_sync.php';

$schema = flowork_schema_export($conn);
$out = dirname(__DIR__) . '/updater/schema.json';
if (!is_dir(dirname($out))) mkdir(dirname($out), 0755, true);
file_put_contents($out, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
$cols = array_sum(array_map(fn($t) => count($t['columns']), $schema['tables']));
echo "Wrote updater/schema.json: " . count($schema['tables']) . " tables, $cols columns, " . count($schema['seeds']) . " lookup tables.\n";
