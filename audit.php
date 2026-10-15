<?php
/**
 * audit.php — Central audit trail helper
 * Include this in any page that writes data.
 * Usage: audit_log($conn, $spid, $userid, 'tickets', 'create', $id, null, $new_data);
 */
if (!function_exists('audit_log')) {

    function audit_log($conn, $spid, $userid, $table, $action, $record_id=null, $old=null, $new=null, $note='') {
        static $table_created = false;
        if (!$table_created) {
            $conn->query("CREATE TABLE IF NOT EXISTS system_audit (
                id            BIGINT AUTO_INCREMENT PRIMARY KEY,
                selling_pointid INT NOT NULL DEFAULT 0,
                userid        INT NOT NULL DEFAULT 0,
                action        VARCHAR(30) NOT NULL,
                table_name    VARCHAR(60) NOT NULL,
                record_id     INT DEFAULT NULL,
                old_values    JSON DEFAULT NULL,
                new_values    JSON DEFAULT NULL,
                note          VARCHAR(255) DEFAULT '',
                ip_address    VARCHAR(45) DEFAULT '',
                user_agent    VARCHAR(255) DEFAULT '',
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_table_record (table_name, record_id),
                INDEX idx_user (userid),
                INDEX idx_created (created_at),
                INDEX idx_sp (selling_pointid)
            ) ENGINE=InnoDB");
            $table_created = true;
        }
        $ip  = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
        $ua  = $conn->real_escape_string(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));
        $nt  = $conn->real_escape_string(substr($note, 0, 255));
        $act = $conn->real_escape_string($action);
        $tbl = $conn->real_escape_string($table);
        $rid = $record_id ? (int)$record_id : 'NULL';
        $old_j = $old  ? "'" . $conn->real_escape_string(json_encode($old))  . "'" : 'NULL';
        $new_j = $new  ? "'" . $conn->real_escape_string(json_encode($new))  . "'" : 'NULL';
        $conn->query("INSERT INTO system_audit
            (selling_pointid,userid,action,table_name,record_id,old_values,new_values,note,ip_address,user_agent)
            VALUES ($spid,$userid,'$act','$tbl',$rid,$old_j,$new_j,'$nt','$ip','$ua')");
    }

    function audit_simple($conn, $spid, $userid, $action, $note='') {
        audit_log($conn, $spid, $userid, 'system', $action, null, null, null, $note);
    }
}
