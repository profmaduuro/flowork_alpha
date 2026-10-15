<?php
function ok($data = null, string $msg = 'OK'): void {
    echo json_encode(['success'=>true,'message'=>$msg,'data'=>$data]);
    exit;
}

function err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success'=>false,'message'=>$msg,'data'=>null]);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function need(array $body, array $fields): void {
    foreach ($fields as $f) {
        if (!isset($body[$f]) || trim((string)$body[$f]) === '') {
            err("Field required: $f");
        }
    }
}

function requireAuth(): array {
    // Apache may strip Authorization header — check all sources
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';
    if (empty($header) && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        $header = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        err('Unauthorized — missing token', 401);
    }
    $token = trim($m[1]);
    $db    = getDB();
    $tok_e = $db->real_escape_string($token);
    $r = $db->query("
        SELECT at.user_id AS id, u.username, u.is_admin, u.is_master, u.role, u.selling_pointid, u.rights,
               COALESCE(s.id, 1) AS seasonid
        FROM api_tokens at
        JOIN users u ON u.id = at.user_id
        LEFT JOIN seasons s ON s.active = 1
        WHERE at.token = '$tok_e'
          AND at.expires_at > NOW()
          AND u.active = 1
        ORDER BY s.id DESC
        LIMIT 1
    ");
    if (!$r || $r->num_rows === 0) {
        err('Unauthorized — invalid or expired token', 401);
    }
    return $r->fetch_assoc();
}

/**
 * Refuse the request unless the token's user holds this module right.
 *
 * Uses the same rules as the web session (includes/rights.php). The API used
 * to check only that the token was valid, so a clerk's phone could call any
 * module, payments included.
 */
function requireRight(array $user, string $right): void {
    require_once __DIR__ . '/../../includes/rights.php';
    $rights = flowork_resolve_rights($user);
    if (empty($rights[$right])) {
        err("Forbidden — your account does not have the '$right' right", 403);
    }
}

/**
 * Selling points this API user may act on.
 *
 * Mirrors the rule the web session uses in flowork_build_user_session()
 * (includes/auth.php): a master user, or one not tied to a floor, reaches every
 * active selling point; everyone else reaches only their own.
 *
 * @return int[] allowed selling point ids
 */
function allowedSpids(array $user): array {
    static $cache = [];
    $uid = (int)($user['id'] ?? 0);
    if (isset($cache[$uid])) return $cache[$uid];

    $own       = (int)($user['selling_pointid'] ?? 0);
    $is_master = !empty($user['is_master']) || (($user['role'] ?? '') === 'master');

    if (!$is_master && $own > 0) {
        return $cache[$uid] = [$own];
    }

    $ids = [];
    $r = getDB()->query("SELECT id FROM selling_point WHERE active=1");
    if ($r && $r !== true) {
        while ($row = $r->fetch_assoc()) $ids[] = (int)$row['id'];
    }
    if ($own > 0 && !in_array($own, $ids, true)) $ids[] = $own;
    return $cache[$uid] = $ids;
}

/**
 * Resolve the selling point a request operates on.
 *
 * X-Selling-Point lets a user who legitimately reaches several floors pick one,
 * so it is honoured — but only after checking it against that user's own
 * access. It was previously returned unchecked, which let any token read and
 * write another floor's data by changing one header. Floors are separate
 * tenants, so an unauthorised value is refused rather than quietly downgraded:
 * silently substituting a different floor would hand back the wrong tenant's
 * data under a 200.
 */
function getSpid(array $user): int {
    $own = (int)($user['selling_pointid'] ?? 0);
    $hdr = (int)($_SERVER['HTTP_X_SELLING_POINT'] ?? 0);

    if ($hdr <= 0) return $own;
    if ($hdr === $own) return $hdr;

    if (!in_array($hdr, allowedSpids($user), true)) {
        err('Forbidden — you do not have access to selling point ' . $hdr, 403);
    }
    return $hdr;
}

function getSeasonid(array $user): int {
    return (int)($user['seasonid'] ?? 1);
}
