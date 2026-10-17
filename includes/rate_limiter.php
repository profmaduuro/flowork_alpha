<?php
/**
 * rate_limiter.php — Database-backed rate limiter.
 * Protects login and API endpoints from brute force and abuse.
 */

/**
 * Client address used for rate limiting.
 *
 * Only REMOTE_ADDR is trusted by default. Client-IP / X-Forwarded-For /
 * X-Real-IP are set by whoever sends the request, so trusting them let an
 * attacker get a fresh limit on every request by changing the header. A
 * forwarded address is used only when REMOTE_ADDR is a proxy listed in
 * TRUSTED_PROXIES (.env, comma-separated, e.g. the nginx container's IP).
 */
function rate_limit_client_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $trusted = flowork_env('TRUSTED_PROXIES', '');
    $trusted = array_filter(array_map('trim', explode(',', (string)$trusted)));

    if ($trusted && in_array($remote, $trusted, true)) {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if ($fwd !== '') {
            // The proxy appends the address it saw, so take the right-most entry.
            $parts = array_map('trim', explode(',', $fwd));
            $cand  = end($parts);
            if (filter_var($cand, FILTER_VALIDATE_IP)) return $cand;
        }
    }

    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '127.0.0.1';
}

/**
 * @param string|null $subject What to count against. Defaults to the client
 *                             address; pass e.g. 'user:42' to limit per account
 *                             regardless of where the requests come from.
 */
function check_rate_limit(mysqli $conn, string $action_key, int $limit = 60, int $window_seconds = 60, ?string $subject = null): bool {
    $ip = $subject !== null ? substr($subject, 0, 45) : rate_limit_client_ip();

    $time = time();
    $esc_ip = $conn->real_escape_string($ip);
    $esc_action = $conn->real_escape_string($action_key);

    // Clean up expired windows for this action only. Purging every action with
    // this caller's window let a 60s API check wipe 5-15 minute login counters.
    $conn->query("DELETE FROM rate_limits WHERE action_key = '$esc_action' AND window_start < " . ($time - $window_seconds));

    // Try to retrieve current rate limit row
    $q = $conn->query("SELECT request_count, window_start FROM rate_limits
                       WHERE ip_address = '$esc_ip' AND action_key = '$esc_action' LIMIT 1");

    if ($q && $q->num_rows > 0) {
        $row = $q->fetch_assoc();
        $count = (int)$row['request_count'];
        $start = (int)$row['window_start'];

        if ($start < ($time - $window_seconds)) {
            // Window expired, reset window and count
            $conn->query("UPDATE rate_limits SET request_count = 1, window_start = $time
                          WHERE ip_address = '$esc_ip' AND action_key = '$esc_action'");
            $count = 1;
        } else {
            // Within window, increment count
            $conn->query("UPDATE rate_limits SET request_count = request_count + 1
                          WHERE ip_address = '$esc_ip' AND action_key = '$esc_action'");
            $count++;
        }
    } else {
        // No record, create one
        $conn->query("INSERT INTO rate_limits (ip_address, action_key, request_count, window_start)
                      VALUES ('$esc_ip', '$esc_action', 1, $time)
                      ON DUPLICATE KEY UPDATE request_count = request_count + 1");
        $count = 1;
    }

    return $count <= $limit;
}
