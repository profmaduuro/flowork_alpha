<?php
/**
 * includes/queue.php
 * Lightweight Job Queue Helper for Flowork Alpha.
 */
require_once __DIR__ . '/conn.php';

function init_job_queue_table($conn) {
    static $initialized = false;
    if ($initialized) return;
    $sql = "CREATE TABLE IF NOT EXISTS job_queue (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        job_type VARCHAR(64) NOT NULL,
        payload LONGTEXT NOT NULL,
        status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $conn->query($sql);
    $initialized = true;
}

function dispatch_job($job_type, array $payload) {
    global $conn;
    if (!$conn || !($conn instanceof mysqli)) {
        return false;
    }
    init_job_queue_table($conn);

    $type_safe = $conn->real_escape_string($job_type);
    $payload_json = $conn->real_escape_string(json_encode($payload));

    $sql = "INSERT INTO job_queue (job_type, payload, status) VALUES ('$type_safe', '$payload_json', 'pending')";
    return (bool)$conn->query($sql);
}
