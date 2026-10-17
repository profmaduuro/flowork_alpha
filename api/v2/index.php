<?php
/**
 * Flowork API v2 — read-only JSON API for integrations.
 * Routes: /api/v2/?endpoint=health|growers|sales|payments|buyers|stats
 *
 * Auth: X-API-Key header only (never in the URL, where it lands in logs).
 * Each key belongs to one floor and only ever sees that floor's data. It is
 * stored as a SHA-256 hash in settings under api_key_v2_sp<floor id>.
 * Issue or replace a floor's key from the command line:
 *   php maintenance/api_key_v2.php <floor id>
 * With no key configured the API refuses every request.
 *
 * No CORS headers: this is for server-to-server integrations, not for
 * browsers on other sites.
 */
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once "../../includes/conn.php";
require_once "../../includes/net_amount.php";

function json_out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Floor id the presented key belongs to, or 0 when it matches none. */
function api_auth_floor(mysqli $conn): int {
    $key = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
    if (strlen($key) < 32) return 0;
    $hash = hash('sha256', $key);
    $res = $conn->query("SELECT name, value FROM settings WHERE name LIKE 'api\\_key\\_v2\\_sp%'");
    if (!$res || $res === true) return 0;
    while ($row = $res->fetch_assoc()) {
        if (hash_equals((string)$row['value'], $hash)) {
            return (int)substr($row['name'], strlen('api_key_v2_sp'));
        }
    }
    return 0;
}

function safe_query($conn, $sql) {
    $r = @$conn->query($sql);
    if (!$r || $r === true) return [];
    return $r->fetch_all(MYSQLI_ASSOC) ?: [];
}

$spid = api_auth_floor($conn);
if ($spid <= 0) json_out(['success' => false, 'error' => 'Missing or invalid API key (send it in the X-API-Key header)'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_out(['success' => false, 'error' => 'Only GET is supported'], 405);

$endpoint  = $_GET['endpoint'] ?? '';
$limit     = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset    = max(0, (int)($_GET['offset'] ?? 0));

switch ($endpoint) {

    // ── Health Check
    case 'health':
        json_out([
            'success' => true,
            'status' => 'healthy',
            'version' => '2.1',
            'selling_pointid' => $spid,
            'timestamp' => date('c'),
            'database' => $conn->ping() ? 'connected' : 'disconnected'
        ]);
        break;

    // ── Growers
    case 'growers':
        $search = $conn->real_escape_string($_GET['search'] ?? '');
        $where = $search !== '' ? "AND (grower_num LIKE '%$search%' OR name LIKE '%$search%' OR surname LIKE '%$search%')" : "";
        $rows = safe_query($conn, "SELECT id, grower_num, name, surname, phone, farm_name, province, district, crop_type, active, created_at
            FROM growers WHERE selling_pointid=$spid $where ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $total = safe_query($conn, "SELECT COUNT(*) c FROM growers WHERE selling_pointid=$spid $where");
        json_out(['success'=>true, 'data'=>$rows, 'total'=>(int)($total[0]['c']??0), 'limit'=>$limit, 'offset'=>$offset]);
        break;

    // ── Sales (sold bales)
    case 'sales':
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_out(['success'=>false, 'error'=>'date must be YYYY-MM-DD'], 400);
        $rows = safe_query($conn, "SELECT sb.id, tk.barcode, sb.price,
                bm.mass, tg.description timb_grade, bg.description buyer_grade,
                b.name buyer_name, g.grower_num, CONCAT(g.name,' ',g.surname) grower_name,
                sb.created_at
            FROM sold_bales sb
            JOIN tickets tk ON tk.id=sb.ticketsid
            JOIN bale_junus bj ON bj.id=tk.bale_junusid
            JOIN bale_mass bm ON bm.id=bj.bale_massid
            JOIN growers g ON g.id=bj.growerid
            LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
            LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
            LEFT JOIN buyer b ON b.id=bg.buyerid
            WHERE DATE(sb.created_at)='$date' AND g.selling_pointid=$spid
            ORDER BY sb.id DESC LIMIT $limit OFFSET $offset");
        json_out(['success'=>true, 'date'=>$date, 'data'=>$rows, 'count'=>count($rows)]);
        break;

    // ── Payments (balanced deliveries with their stored net)
    case 'payments':
        $rows = safe_query($conn, "SELECT bb.id, bb.batch_reference, bb.sell_date,
                g.grower_num, CONCAT(g.name,' ',g.surname) grower_name,
                dt.sold_bales, dt.mass, dt.usd_value, dt.zim_value,
                dtn.usd_net_value, dtn.zim_net_value, bb.created_at
            FROM balanced_bales bb
            JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
            JOIN growers g ON g.id=tg.growerid
            LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
            " . flowork_net_join('dtn', 'bb.id') . "
            WHERE g.selling_pointid=$spid
            ORDER BY bb.id DESC LIMIT $limit OFFSET $offset");
        json_out(['success'=>true, 'data'=>$rows, 'count'=>count($rows)]);
        break;

    // ── Buyers
    case 'buyers':
        $rows = safe_query($conn, "SELECT id, name, buyer_code, contact_name, phone, email, country, active
            FROM buyer WHERE selling_pointid=$spid
            ORDER BY name LIMIT $limit OFFSET $offset");
        json_out(['success'=>true, 'data'=>$rows, 'count'=>count($rows)]);
        break;

    // ── Stats
    case 'stats':
        $today = date('Y-m-d');
        $today_sales = safe_query($conn, "SELECT COUNT(sb.id) bales,
                COALESCE(SUM(bm.mass),0) mass,
                COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) usd
            FROM sold_bales sb
            JOIN tickets tk ON tk.id=sb.ticketsid
            JOIN bale_junus bj ON bj.id=tk.bale_junusid
            JOIN bale_mass bm ON bm.id=bj.bale_massid
            JOIN growers g ON g.id=bj.growerid
            WHERE DATE(sb.created_at)='$today' AND g.selling_pointid=$spid");
        $season = safe_query($conn, "SELECT COUNT(DISTINCT bb.id) batches,
                COALESCE(SUM(dt.usd_value),0) gross_usd,
                COALESCE(SUM(dt.mass),0) mass,
                COALESCE(SUM(dt.sold_bales),0) bales
            FROM balanced_bales bb
            JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
            JOIN growers g ON g.id=tg.growerid
            LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
            WHERE g.selling_pointid=$spid");
        json_out([
            'success' => true,
            'today' => $today_sales[0] ?? [],
            'season' => $season[0] ?? [],
            'timestamp' => date('c')
        ]);
        break;

    default:
        json_out([
            'success' => false,
            'error' => 'Unknown endpoint',
            'available_endpoints' => ['health','growers','sales','payments','buyers','stats'],
            'docs' => 'GET ?endpoint=<name> with your key in the X-API-Key header'
        ], 404);
}
