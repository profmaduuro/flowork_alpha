<?php
/**
 * audit.php — Central audit trail helper
 * Include this in any page that writes data.
 * Usage: audit_log($conn, $spid, $userid, 'tickets', 'create', $id, null, $new_data);
 */
if (!function_exists('audit_log')) {

    function audit_log($conn, $spid, $userid, $table, $action, $record_id=null, $old=null, $new=null, $note='') {
        // Secure proxy-aware IP detection
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_X_REAL_IP'
        ];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $parts = explode(',', $_SERVER[$header]);
                $candidate = trim($parts[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $ip = $candidate;
                    break;
                }
            }
        }

        $spid = (int)$spid;
        $userid = (int)$userid;
        $rid = $record_id ? (int)$record_id : null;
        $old_j = $old ? json_encode($old) : null;
        $new_j = $new ? json_encode($new) : null;
        $nt = substr($note, 0, 255);
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        
        $stmt = $conn->prepare("INSERT INTO system_audit 
            (selling_pointid, userid, action, table_name, record_id, old_values, new_values, note, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("iississsss", $spid, $userid, $action, $table, $rid, $old_j, $new_j, $nt, $ip, $ua);
            $stmt->execute();
            $stmt->close();
        } else {
            error_log("Failed to prepare audit log statement: " . $conn->error);
        }
    }

    function audit_simple($conn, $spid, $userid, $action, $note='') {
        audit_log($conn, $spid, $userid, 'system', $action, null, null, null, $note);
    }
}
