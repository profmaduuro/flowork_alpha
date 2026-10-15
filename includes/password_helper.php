<?php
// Password hashing helpers shared by the web app (login.php) and the
// mobile API (api/modules/auth.php).
//
// Storage format: password_hash() / PASSWORD_DEFAULT in users.password.
// Legacy rows still hold plaintext; flowork_password_verify() accepts a
// correct plaintext match once and upgrades the row to a hash in place,
// so the migration completes silently as users log in.

if (!function_exists('flowork_password_hash')) {
    function flowork_password_hash(string $password): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

if (!function_exists('flowork_password_store')) {
    function flowork_password_store(mysqli $conn, int $userId, string $password): void {
        $hash = flowork_password_hash($password);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('si', $hash, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('flowork_password_verify')) {
    function flowork_password_verify(mysqli $conn, string $password, array $userRow): bool {
        $stored = (string)($userRow['password'] ?? '');
        if ($stored === '' || $password === '') return false;

        $info = password_get_info($stored);
        if (!empty($info['algo'])) {
            if (!password_verify($password, $stored)) return false;
            if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                flowork_password_store($conn, (int)$userRow['id'], $password);
            }
            return true;
        }

        // Legacy plaintext row — accept once, then upgrade to a hash.
        if (!hash_equals($stored, $password)) return false;
        flowork_password_store($conn, (int)$userRow['id'], $password);
        return true;
    }
}

if (!function_exists('flowork_password_is_hashed')) {
    function flowork_password_is_hashed(string $stored): bool {
        $info = password_get_info($stored);
        return !empty($info['algo']);
    }
}
