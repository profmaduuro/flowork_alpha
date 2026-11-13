<?php
// System-wide switch for two-factor sign-in.
//
// Stored in `parameters` as security_2fa_required (selling_pointid 0):
//   '1' (or no row) = codes are asked for, as before;
//   '0'             = nobody is asked for a code at sign-in (web or mobile),
//                     and REQUIRE_ADMIN_2FA no longer forces admins to set it up.
// Turning it off keeps everyone's authenticator setup (user_2fa), so turning it
// back on works without re-pairing phones. Changed on the Two-Factor page by an admin.

if (!function_exists('flowork_2fa_system_on')) {
    function flowork_2fa_system_on(mysqli $conn): bool {
        static $cache = null;
        if ($cache !== null) return $cache;
        $q = $conn->query("SELECT param_value FROM parameters
                           WHERE param_key='security_2fa_required' AND selling_pointid=0 LIMIT 1");
        if (!$q || $q === true || !$q->num_rows) return $cache = true;   // no setting yet: unchanged behaviour
        return $cache = trim((string)$q->fetch_assoc()['param_value']) !== '0';
    }

    function flowork_2fa_system_set(mysqli $conn, bool $on): bool {
        $v = $on ? '1' : '0';
        $st = $conn->prepare("INSERT INTO parameters (param_key, param_value, selling_pointid)
                              VALUES ('security_2fa_required', ?, 0) ON DUPLICATE KEY UPDATE param_value=?");
        if (!$st) return false;
        $st->bind_param('ss', $v, $v);
        $ok = $st->execute();
        $st->close();
        return $ok;
    }
}
