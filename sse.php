<?php
/**
 * sse.php
 * Lightweight Server-Sent Events (SSE) Router
 * Automatically handles connections, initializes event stream logs,
 * tracks client event state, and pushes realtime floor notifications.
 */
session_start();
// Floor events are internal: only signed-in users (2FA included) may listen.
if (empty($_SESSION['userid']) || empty($_SESSION['2fa_verified'])) {
    http_response_code(401);
    exit;
}
session_write_close(); // Release session file lock to prevent page blocking and allow instant concurrent navigation

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Avoid proxies caching buffer stream

require_once "includes/conn.php";

// Each request answers once and ends, so tell the browser to wait before
// reconnecting. Without this it reconnects about every 3 seconds per open tab,
// and every reconnect is a full PHP + database round trip.
echo "retry: 20000\n\n";

// Create the notifications table only when it is missing (MySQL error 1146),
// not on every reconnect.
function sse_query(mysqli $conn, string $sql) {
    $q = $conn->query($sql);
    if ($q === false && $conn->errno === 1146) {
        $conn->query("CREATE TABLE IF NOT EXISTS event_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(50) NOT NULL,
            message VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $q = $conn->query($sql);
    }
    return $q;
}

// Detect standard SSE HTTP header or parameter-based event trackers
$last_id = isset($_SERVER['HTTP_LAST_EVENT_ID']) 
    ? (int)$_SERVER['HTTP_LAST_EVENT_ID'] 
    : (isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0);

// If no tracker exists, initialize to the latest notification ID to avoid alert fatigue on load
if ($last_id === 0) {
    $latest_q = sse_query($conn, "SELECT MAX(id) m FROM event_notifications");
    $latest = ($latest_q && $latest_q !== true) ? $latest_q->fetch_assoc() : null;
    $last_id = $latest ? (int)$latest['m'] : 0;
}

// One check per request, so no PHP worker is held open on XAMPP; the client
// reconnects after the `retry` interval above.
$q = sse_query($conn, "SELECT * FROM event_notifications WHERE id > $last_id ORDER BY id ASC LIMIT 10");
if ($q && $q !== true && $q->num_rows > 0) {
    while ($row = $q->fetch_assoc()) {
        $last_id = (int)$row['id'];

        // Format standard SSE response packet
        echo "id: {$last_id}\n";
        echo "event: message\n";
        echo "data: " . json_encode([
            'id' => $last_id,
            'type' => $row['event_type'],
            'message' => $row['message'],
            'time' => date('H:i:s', strtotime($row['created_at']))
        ]) . "\n\n";
    }
}
@ob_flush();
flush();
