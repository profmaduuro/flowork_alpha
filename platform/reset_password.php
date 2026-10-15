<?php
// Set a new password for the platform `developer` account.
// Command line only:  php platform/reset_password.php
// You are asked for the password (typed twice) so it never lands in shell history.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }

$_SERVER['SERVER_NAME'] = 'localhost';
require __DIR__ . '/../includes/conn.php';

function ask(string $prompt): string {
    echo $prompt;
    return rtrim((string)fgets(STDIN), "\r\n");
}

$user = $argv[1] ?? 'developer';
$new  = ask("New password for '$user' (min 10 characters): ");
if (strlen($new) < 10) { fwrite(STDERR, "Too short. Nothing changed.\n"); exit(1); }
if (ask("Type it again: ") !== $new) { fwrite(STDERR, "Passwords did not match. Nothing changed.\n"); exit(1); }

$hash = password_hash($new, PASSWORD_BCRYPT);
$stmt = $conn->prepare("UPDATE flowork_system_admins SET password_hash=? WHERE username=?");
$stmt->bind_param('ss', $hash, $user);
$stmt->execute();
if ($stmt->affected_rows < 1) {
    $chk = $conn->prepare("SELECT 1 FROM flowork_system_admins WHERE username=?");
    $chk->bind_param('s', $user);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        fwrite(STDERR, "No platform account '$user'. Run setup_database.php first.\n");
        exit(1);
    }
}

// Clear platform-login lockouts so the new password works straight away.
$conn->query("DELETE FROM rate_limits WHERE action_key='manager_login'");

echo "Password updated for '$user'. Sign in at /platform/login.php\n";
