<?php
// Pure-PHP TOTP (Google Authenticator compatible) and 2FA code checks.
// Used by two_factor.php and api/modules/auth.php.

function base32_decode($base32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32 = strtoupper(rtrim($base32,'='));
    $binary = '';
    foreach (str_split($base32) as $c) {
        $pos = strpos($alphabet,$c);
        if ($pos === false) continue;
        $binary .= str_pad(decbin($pos),5,'0',STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($binary,8) as $chunk) {
        if (strlen($chunk) < 8) break;
        $output .= chr(bindec($chunk));
    }
    return $output;
}

function totp_secret() {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i=0;$i<16;$i++) $secret .= $alphabet[random_int(0,31)];
    return $secret;
}

function totp_code($secret, $time=null) {
    $time = floor(($time ?? time()) / 30);
    $time_bytes = pack('N*',0) . pack('N*',$time);
    $key  = base32_decode($secret);
    $hash = hash_hmac('sha1',$time_bytes,$key,true);
    $offset = ord($hash[19]) & 0x0f;
    $code = (
        ((ord($hash[$offset+0]) & 0x7f) << 24) |
        ((ord($hash[$offset+1]) & 0xff) << 16) |
        ((ord($hash[$offset+2]) & 0xff) <<  8) |
        ((ord($hash[$offset+3]) & 0xff))
    ) % 1000000;
    return str_pad($code,6,'0',STR_PAD_LEFT);
}

function totp_verify($secret, $code) {
    $code = preg_replace('/\D/','',$code);
    $t = time();
    // Allow 1 window before/after for clock drift
    foreach ([-1,0,1] as $offset) {
        if (hash_equals(totp_code($secret,$t + $offset*30), $code)) return true;
    }
    return false;
}

function make_backup_codes() {
    $codes = [];
    for ($i=0;$i<8;$i++) $codes[] = strtoupper(bin2hex(random_bytes(4)));
    return $codes;
}


/**
 * Check a 2FA code for a user: a current TOTP code, or an unused backup code
 * (which is consumed). Shared by two_factor.php and the API login.
 */
function flowork_2fa_check(mysqli $conn, int $uid, string $code): bool {
    $stmt = $conn->prepare("SELECT secret, backup_codes FROM user_2fa WHERE userid=? AND enabled=1 LIMIT 1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $fa = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$fa || trim($code) === '') return false;

    $ok = totp_verify($fa['secret'], $code);
    if (!$ok) {
        $backups = array_filter(explode(',', $fa['backup_codes'] ?? ''), 'strlen');
        foreach ($backups as $i => $bc) {
            if (hash_equals(strtoupper(trim($bc)), strtoupper(trim($code)))) {
                unset($backups[$i]);
                $new_bc = implode(',', $backups);
                $stmt_upd = $conn->prepare("UPDATE user_2fa SET backup_codes=? WHERE userid=?");
                $stmt_upd->bind_param("si", $new_bc, $uid);
                $stmt_upd->execute();
                $stmt_upd->close();
                $ok = true;
                break;
            }
        }
    }
    if ($ok) {
        $stmt_upd = $conn->prepare("UPDATE user_2fa SET last_used=NOW() WHERE userid=?");
        $stmt_upd->bind_param("i", $uid);
        $stmt_upd->execute();
        $stmt_upd->close();
    }
    return $ok;
}

/** True when this user has 2FA switched on. */
function flowork_2fa_enabled(mysqli $conn, int $uid): bool {
    $stmt = $conn->prepare("SELECT 1 FROM user_2fa WHERE userid=? AND enabled=1 LIMIT 1");
    if (!$stmt) return false; // table not created yet: nobody has 2FA
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $on = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $on;
}
