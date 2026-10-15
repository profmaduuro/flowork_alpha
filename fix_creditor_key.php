<?php
require_once __DIR__ . '/includes/admin_gate.php';
// docker cp fix_creditor_key2.php flowork_app:/var/www/html/fix_creditor_key2.php
// visit http://localhost:8080/fix_creditor_key2.php
session_start();
require_once "includes/conn.php";

echo "<pre style='font:13px monospace;background:#111;color:#9c9;padding:20px'>";

// Show ALL constraints on the table
echo "=== Current indexes ===\n";
$idx = $conn->query("SHOW INDEX FROM grower_creditors");
$all_keys = [];
while ($r = $idx->fetch_assoc()) {
    echo "  {$r['Key_name']} col={$r['Column_name']} unique=".($r['Non_unique']==0?'YES':'NO')."\n";
    $all_keys[$r['Key_name']] = true;
}

echo "\n=== Foreign key constraints ===\n";
$fk = $conn->query("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'grower_creditors'
      AND REFERENCED_TABLE_NAME IS NOT NULL");
$fk_names = [];
if ($fk && $fk !== true) {
    while ($r = $fk->fetch_assoc()) {
        echo "  FK: {$r['CONSTRAINT_NAME']} ({$r['COLUMN_NAME']}) -> {$r['REFERENCED_TABLE_NAME']}.{$r['REFERENCED_COLUMN_NAME']}\n";
        $fk_names[] = $r['CONSTRAINT_NAME'];
    }
}
if (empty($fk_names)) echo "  (none)\n";

// Drop foreign keys first
foreach ($fk_names as $fkn) {
    $conn->query("ALTER TABLE grower_creditors DROP FOREIGN KEY `$fkn`");
    echo $conn->error ? "❌ Drop FK $fkn: {$conn->error}\n" : "✓ Dropped FK: $fkn\n";
}

// Now drop all non-PRIMARY indexes EXCEPT ones we want to keep
$skip = ['PRIMARY'];
foreach (array_keys($all_keys) as $kn) {
    if (in_array($kn, $skip)) continue;
    $conn->query("ALTER TABLE grower_creditors DROP INDEX `$kn`");
    echo $conn->error ? "❌ Drop index $kn: {$conn->error}\n" : "✓ Dropped index: $kn\n";
}

// Add the simple unique key on creditor_no
$conn->query("ALTER TABLE grower_creditors ADD UNIQUE KEY uk_cred_no (creditor_no(50))");
echo $conn->error ? "❌ Add key: {$conn->error}\n" : "✓ Added UNIQUE KEY on creditor_no(50)\n";

echo "\n=== Final indexes ===\n";
$idx = $conn->query("SHOW INDEX FROM grower_creditors");
while ($r = $idx->fetch_assoc()) {
    echo "  {$r['Key_name']} ({$r['Column_name']}) unique=".($r['Non_unique']==0?'YES':'NO')."\n";
}

echo "\nRows: ".$conn->query("SELECT COUNT(*) c FROM grower_creditors")->fetch_assoc()['c']."\n";
echo "\n✓ Done — now re-upload the creditor CSV in File Manager.\n";
echo "</pre>";