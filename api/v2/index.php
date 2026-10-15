<?php
/**
 * Flowork API v2 — RESTful JSON API
 * Routes: /api/v2/?endpoint=growers|sales|payments|buyers|health
 * Auth: API key via X-API-Key header or ?api_key= param
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once "../../includes/conn.php";

// API Key authentication
function api_auth($conn) {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
    if (!$key) return ['error' => 'Missing API key', 'code' => 401];
    
    // Check settings for valid API key
    $stmt = $conn->prepare("SELECT value FROM settings WHERE name='api_key_v2'");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if ($row && $row['value'] === $key) return ['ok' => true, 'key' => $key];
    }
    
    // Fallback: accept hardcoded dev key
    if ($key === 'flowork-dev-key-2024') return ['ok' => true, 'key' => $key];
    
    return ['error' => 'Invalid API key', 'code' => 403];
}

function json_out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function safe_query($conn, $sql) {
    $r = @$conn->query($sql);
    if (!$r || $r === true) return [];
    return $r->fetch_all(MYSQLI_ASSOC) ?: [];
}

// Auth check
$auth = api_auth($conn);
if (isset($auth['error'])) json_out(['success'=>false,'error'=>$auth['error']], $auth['code']);

$endpoint = $_GET['endpoint'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$spid = (int)($_GET['selling_pointid'] ?? 0);
$sp_filter = $spid ? "AND selling_pointid=$spid" : "";

switch ($endpoint) {

    // ── Health Check
    case 'health':
        json_out([
            'success' => true,
            'status' => 'healthy',
            'version' => '2.0',
            'timestamp' => date('c'),
            'database' => $conn->ping() ? 'connected' : 'disconnected'
        ]);
        break;

    // ── Growers
    case 'growers':
        if ($method === 'GET') {
            $search = addslashes($_GET['search'] ?? '');
            $where = $search ? "AND (grower_num LIKE '%$search%' OR name LIKE '%$search%' OR surname LIKE '%$search%')" : "";
            $rows = safe_query($conn, "SELECT id, grower_num, name, surname, phone, farm_name, province, district, crop_type, active, created_at
                FROM growers WHERE 1=1 $sp_filter $where ORDER BY id DESC LIMIT $limit OFFSET $offset");
            $total = safe_query($conn, "SELECT COUNT(*) c FROM growers WHERE 1=1 $sp_filter $where");
            json_out(['success'=>true, 'data'=>$rows, 'total'=>(int)($total[0]['c']??0), 'limit'=>$limit, 'offset'=>$offset]);
        }
        break;

    // ── Sales (sold bales)
    case 'sales':
        if ($method === 'GET') {
            $date = addslashes($_GET['date'] ?? date('Y-m-d'));
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
                WHERE DATE(sb.created_at)='$date'
                ORDER BY sb.id DESC LIMIT $limit OFFSET $offset");
            json_out(['success'=>true, 'date'=>$date, 'data'=>$rows, 'count'=>count($rows)]);
        }
        break;

    // ── Payments (balanced batches)
    case 'payments':
        if ($method === 'GET') {
            $rows = safe_query($conn, "SELECT bb.id, bb.batch_reference, bb.sell_date,
                    g.grower_num, CONCAT(g.name,' ',g.surname) grower_name,
                    dt.sold_bales, dt.mass, dt.usd_value, dt.zim_value,
                    dtn.usd_net_value, dtn.zim_net_value, bb.created_at
                FROM balanced_bales bb
                JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
                JOIN growers g ON g.id=tg.growerid
                LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
                LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
                WHERE 1=1 ".($spid?"AND bb.selling_pointid=$spid":"")."
                ORDER BY bb.id DESC LIMIT $limit OFFSET $offset");
            json_out(['success'=>true, 'data'=>$rows, 'count'=>count($rows)]);
        }
        break;

    // ── Buyers
    case 'buyers':
        if ($method === 'GET') {
            $rows = safe_query($conn, "SELECT id, name, buyer_code, contact_name, phone, email, country, active
                FROM buyer WHERE 1=1 ".($spid?"AND selling_pointid=$spid":"")."
                ORDER BY name LIMIT $limit OFFSET $offset");
            json_out(['success'=>true, 'data'=>$rows, 'count'=>count($rows)]);
        }
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
            WHERE DATE(sb.created_at)='$today'");
        $season = safe_query($conn, "SELECT COUNT(DISTINCT bb.id) batches,
                COALESCE(SUM(dt.usd_value),0) gross_usd,
                COALESCE(SUM(dt.mass),0) mass,
                COALESCE(SUM(dt.sold_bales),0) bales
            FROM balanced_bales bb LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id");
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
            'docs' => 'Use ?endpoint=<name>&api_key=<key> to query the API'
        ], 404);
}
?>
