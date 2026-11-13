<?php
/**
 * Make a user a master user (all floors; can create other masters) —
 * command line only.
 *
 *   php maintenance/grant_master.php                    list the users and who is master
 *   php maintenance/grant_master.php --user=sysadmin    make sysadmin a master user
 *   php maintenance/grant_master.php --user=bob --revoke
 *
 * In Docker: docker compose exec app php maintenance/grant_master.php --user=sysadmin
 *
 * Inside the app only a master can make another user master, so an install
 * that has none (e.g. one set up before setup_database.php created sysadmin
 * as master) needs this once. The user must log out and in again.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_OFF);
require_once dirname(__DIR__) . '/includes/conn.php';

$o      = getopt('', ['user:', 'revoke']);
$user   = trim((string)($o['user'] ?? ''));
$revoke = isset($o['revoke']);

if ($user === '') {
    $q = $conn->query("SELECT username, role, is_admin, is_master, selling_pointid, active FROM users ORDER BY is_master DESC, is_admin DESC, username");
    if (!$q) { fwrite(STDERR, "Cannot read users: {$conn->error}\n"); exit(1); }
    printf("%-24s %-10s %-6s %-6s %-6s %s\n", 'USER', 'ROLE', 'ADMIN', 'MASTER', 'FLOOR', 'ACTIVE');
    while ($r = $q->fetch_assoc()) {
        $m = (!empty($r['is_master']) || $r['role'] === 'master') ? 'yes' : '';
        printf("%-24s %-10s %-6s %-6s %-6s %s\n", $r['username'], $r['role'], $r['is_admin'] ? 'yes' : '', $m, (int)$r['selling_pointid'] ?: 'all', $r['active'] ? 'yes' : 'no');
    }
    echo "\nUse --user=<name> to make one a master user.\n";
    exit(0);
}

$st = $conn->prepare("SELECT id, role, is_master, active FROM users WHERE username=? LIMIT 1");
$st->bind_param('s', $user);
$st->execute();
$row = $st->get_result()->fetch_assoc();
if (!$row) { fwrite(STDERR, "No user named '$user'. Run without --user to list them.\n"); exit(1); }
$id = (int)$row['id'];

if ($revoke) {
    // a 'master' role would keep the user master, so step it down to admin
    $ok = $conn->query("UPDATE users SET is_master=0, role=IF(role='master','admin',role) WHERE id=$id");
} else {
    // masters see every floor: selling_pointid 0
    $ok = $conn->query("UPDATE users SET is_master=1, is_admin=1, selling_pointid=0, active=1 WHERE id=$id");
}
if (!$ok) { fwrite(STDERR, "Update failed: {$conn->error}\n"); exit(1); }

if (is_file(dirname(__DIR__) . '/includes/audit.php')) {
    require_once dirname(__DIR__) . '/includes/audit.php';
    if (function_exists('audit_log')) {
        @audit_log($conn, 0, 0, 'users', $revoke ? 'master_revoked_cli' : 'master_granted_cli', $id,
            ['is_master' => (int)$row['is_master']], ['is_master' => $revoke ? 0 : 1], 'maintenance/grant_master.php');
    }
}
echo $revoke ? "$user is no longer a master user.\n"
             : "$user is now a master user (all floors). Log out and in again to see it.\n";
