<?php
require_once dirname(__DIR__) . '/includes/admin_gate.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Suppress mysqli exceptions — we handle errors manually
mysqli_report(MYSQLI_REPORT_OFF);

echo "<!DOCTYPE html><html><head><title>DB Repair</title>
<style>
body{font-family:monospace;padding:20px;background:#1a1208;color:#e8b84b;font-size:13px;line-height:1.8}
.ok{color:#4caf50}.fail{color:#f44336}.info{color:#87ceeb}
h2{color:#c8952a;border-bottom:1px solid #333;padding-bottom:4px;margin-top:1.5rem}
a{color:#c8952a;font-size:1.1rem}hr{border-color:#333;margin:1rem 0}
</style></head><body><h2>⚙ Flowork DB Repair</h2>";

require_once dirname(__DIR__) . "/includes/conn.php";
echo "<p class='ok'>✓ Connected to MySQL</p>";
echo "<p class='ok'>✓ Database '" . htmlspecialchars($_db_name) . "' selected</p>";

$tables = [];
$r = $conn->query("SHOW TABLES");
if ($r) {
    while ($t = $r->fetch_row()) {
        $tables[] = $t[0];
    }
}
echo "<p class='info'>Found " . count($tables) . " tables</p><hr>";

$broken = $fixed = $dropped = 0;

$conn->query("SET FOREIGN_KEY_CHECKS=0");

foreach ($tables as $tbl) {

    // Test table with error suppression disabled exception mode
    $test = $conn->query("SELECT 1 FROM `$tbl` LIMIT 1");

    if ($test !== false) {
        echo "<span class='ok'>✓</span> $tbl<br>";
        continue;
    }

    $err = $conn->error;
    echo "<br><span class='fail'>✕ $tbl</span> — " . htmlspecialchars($err) . "<br>";
    $broken++;

    // Try REPAIR TABLE first
    $conn->query("REPAIR TABLE `$tbl`");
    $test2 = $conn->query("SELECT 1 FROM `$tbl` LIMIT 1");
    if ($test2 !== false) {
        echo "&nbsp;&nbsp;<span class='ok'>→ REPAIR TABLE fixed it ✓</span><br>";
        $fixed++; continue;
    }

    // Try forcing InnoDB engine rebuild
    $conn->query("ALTER TABLE `$tbl` ENGINE=InnoDB");
    $test3 = $conn->query("SELECT 1 FROM `$tbl` LIMIT 1");
    if ($test3 !== false) {
        echo "&nbsp;&nbsp;<span class='ok'>→ ENGINE rebuild fixed it ✓</span><br>";
        $fixed++; continue;
    }

    // Cannot fix — drop so setup.php recreates it
    $conn->query("DROP TABLE IF EXISTS `$tbl`");
    echo "&nbsp;&nbsp;<span class='info'>→ Dropped (setup.php will recreate it)</span><br>";
    $dropped++;
}

$conn->query("SET FOREIGN_KEY_CHECKS=1");

echo "<hr><h2>Done</h2>";
echo "<p>Broken: <b>$broken</b> &nbsp;|&nbsp; Fixed: <b>$fixed</b> &nbsp;|&nbsp; Dropped: <b>$dropped</b></p>";
echo "<hr>";
echo "<p><a href='setup.php'>→ Run setup.php to recreate tables and restore login</a></p>";
echo "<p><a href='login.php'>→ Try login directly</a></p>";
echo "<p class='fail' style='margin-top:1rem'>⚠ Delete repair.php when done!</p>";
echo "</body></html>";