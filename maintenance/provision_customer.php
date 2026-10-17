<?php
/**
 * Provision a new customer on its own database — command line only.
 *
 *   php maintenance/provision_customer.php --db=flowork_goldleaf --floor="Golden Leaf Tobacco" --code=GL [--months=12]
 *   php maintenance/provision_customer.php --drop --db=flowork_goldleaf --confirm=flowork_goldleaf
 *
 * One customer = one database + one MySQL user that can reach only that
 * database + one app folder with its own .env. Customers then cannot see
 * each other's data even in the modules that have no floor column
 * (processing, packhouse, dispatch, transporters).
 *
 * Creates:
 *   1. database <db> and MySQL user '<db>'@'localhost' with a random password
 *   2. all tables and seed data (runs maintenance/setup_database.php as that user,
 *      which prints the one-time sysadmin and platform passwords)
 *   3. the floor, an Active subscription for --months, sysadmin tied to the floor
 *   4. backups/provision_<db>.env — the customer's .env (denied over HTTP, not in git).
 *      Copy it to the customer's app folder as .env, then delete it here.
 *
 * Needs a MySQL account that may create databases and users: MYSQL_ADMIN_USER /
 * MYSQL_ADMIN_PASS from the environment, or root with no password on XAMPP.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_OFF);

$root = dirname(__DIR__);
$o = getopt('', ['db:', 'floor:', 'code:', 'months:', 'drop', 'confirm:', 'host:']);
$db = (string)($o['db'] ?? '');
if (!preg_match('/^[a-z][a-z0-9_]{2,40}$/', $db)) {
    fwrite(STDERR, "--db must be 3-41 characters: lower-case letters, digits, underscore, starting with a letter\n"); exit(2);
}
if (in_array($db, ['king', 'mysql', 'information_schema', 'performance_schema', 'phpmyadmin', 'test'], true)) {
    fwrite(STDERR, "Refusing to use '$db'.\n"); exit(2);
}
$host = (string)($o['host'] ?? 'localhost');
$au = getenv('MYSQL_ADMIN_USER') ?: 'root';
$ap = getenv('MYSQL_ADMIN_PASS') ?: '';
$admin = @new mysqli($host, $au, $ap);
if ($admin->connect_error) { fwrite(STDERR, "MySQL admin connection failed: {$admin->connect_error}\n"); exit(1); }
$q = fn(string $sql) => $admin->query($sql) ?: throw new RuntimeException($admin->error . " in: " . substr($sql, 0, 80));

// ── Drop (for test customers) ─────────────────────────────────────────────
if (isset($o['drop'])) {
    if (($o['confirm'] ?? '') !== $db) { fwrite(STDERR, "Add --confirm=$db to drop database and user '$db'.\n"); exit(2); }
    $q("DROP DATABASE IF EXISTS `$db`");
    $q("DROP USER IF EXISTS '$db'@'localhost'");
    @unlink("$root/backups/provision_$db.env");
    echo "Dropped database and user '$db'.\n";
    exit(0);
}

$floor  = trim((string)($o['floor'] ?? ''));
$code   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($o['code'] ?? '')));
$months = max(1, (int)($o['months'] ?? 12));
if ($floor === '' || $code === '') { fwrite(STDERR, "--floor and --code are required\n"); exit(2); }

$exists = $admin->query("SHOW DATABASES LIKE '$db'");
if ($exists && $exists->num_rows) { fwrite(STDERR, "Database '$db' already exists. Nothing changed.\n"); exit(1); }

try {
    // 1. Database and its own MySQL user
    $pass = bin2hex(random_bytes(20));
    $q("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $q("CREATE USER '$db'@'localhost' IDENTIFIED BY '$pass'");
    $q("GRANT ALL PRIVILEGES ON `$db`.* TO '$db'@'localhost'");
    echo "Database and MySQL user '$db' created.\n";

    // 2. Tables and seed data, run as the customer's own user
    $env = ['DB_HOST' => $host, 'DB_NAME' => $db, 'DB_USER' => $db, 'DB_PASS' => $pass, 'APP_ENV' => 'production'];
    foreach ($env as $k => $v) putenv("$k=$v");
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/maintenance/setup_database.php"), $rc);
    foreach (array_keys($env) as $k) putenv($k);
    if ($rc !== 0) throw new RuntimeException("setup_database.php reported errors (exit $rc)");

    // 3. Floor, subscription, sysadmin on the floor
    $c = new mysqli($host, $db, $pass, $db);
    if ($c->connect_error) throw new RuntimeException('customer user cannot connect: ' . $c->connect_error);
    $fl = $c->real_escape_string($floor);
    $c->query("INSERT INTO selling_point (name, floor_code, floor_id, active, company_name) VALUES ('$fl', '$code', '{$code}001', 1, '$fl')")
        or throw new RuntimeException($c->error);
    $spid = (int)$c->insert_id;
    $end = date('Y-m-d', strtotime("+$months months"));
    $c->query("INSERT INTO flowork_subscriptions (selling_pointid, status, start_date, end_date) VALUES ($spid, 'Active', CURDATE(), '$end')")
        or throw new RuntimeException($c->error);
    $c->query("UPDATE users SET selling_pointid=$spid WHERE username='sysadmin'");
    echo "Floor '$floor' ($code) created as id $spid, subscription active until $end.\n";

    // 4. The customer's .env
    $file = "$root/backups/provision_$db.env";
    $envtxt = "# Flowork .env for $floor — generated " . date('Y-m-d H:i') . " by maintenance/provision_customer.php\n"
        . "# Copy to this customer's app folder as .env, then delete this file.\n"
        . "APP_ENV=production\n"
        . "DB_HOST=$host\nDB_PORT=3306\nDB_NAME=$db\nDB_USER=$db\nDB_PASS=$pass\n"
        . "SETUP_KEY=\nBACKUP_TOKEN=\nSYSCHECK_KEY=\n"
        . "TIMB_ENC_KEY=" . bin2hex(random_bytes(32)) . "\n"
        . "TRUSTED_PROXIES=\n"
        . "REQUIRE_ADMIN_2FA=1\n"
        . "BACKUP_PASSPHRASE=\nBACKUP_COPY_DIR=\nBACKUP_KEEP_DAYS=30\n";
    file_put_contents($file, $envtxt);
    @chmod($file, 0600);
    echo "Customer .env written to backups/provision_$db.env\n";
    echo "Next: copy the app release to its own folder, put that file there as .env,\n"
       . "      give the folder its own web address, and add its own nightly backup task.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: {$e->getMessage()}\nRolling back: dropping database and user '$db'.\n");
    $admin->query("DROP DATABASE IF EXISTS `$db`");
    $admin->query("DROP USER IF EXISTS '$db'@'localhost'");
    exit(1);
}
