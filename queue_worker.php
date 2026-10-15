<?php
/**
 * queue_worker.php
 * Background Queue Worker Script.
 * Runs pending jobs from job_queue table.
 * Usage: php queue_worker.php [--once]
 */
// Command line only: over HTTP anyone could run the send queue and tie up a
// PHP worker for minutes per request.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/queue.php';

init_job_queue_table($conn);

$once = in_array('--once', $argv ?? []);
$max_iterations = $once ? 1 : 60;
$iteration = 0;

echo "[Job Queue Worker Started]\n";

while ($iteration < $max_iterations) {
    $iteration++;

    // Fetch next pending job
    $res = $conn->query("SELECT id, job_type, payload, attempts FROM job_queue WHERE status='pending' ORDER BY id ASC LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        if ($once) break;
        sleep(2);
        continue;
    }

    $job = $res->fetch_assoc();
    $job_id = (int)$job['id'];
    $job_type = $job['job_type'];
    $payload = json_decode($job['payload'], true) ?? [];
    $attempts = (int)$job['attempts'] + 1;

    // Mark as processing
    $conn->query("UPDATE job_queue SET status='processing', attempts=$attempts, updated_at=NOW() WHERE id=$job_id");

    $success = false;
    $error_msg = null;

    try {
        switch ($job_type) {
            case 'sms':
                if (file_exists(__DIR__ . '/sms_notify.php')) {
                    require_once __DIR__ . '/sms_notify.php';
                    if (function_exists('send_sms_notification')) {
                        $success = send_sms_notification($payload['phone'] ?? '', $payload['message'] ?? '');
                    } else {
                        $success = true;
                    }
                } else {
                    $success = true;
                }
                break;

            case 'whatsapp':
                if (file_exists(__DIR__ . '/whatsapp_notify.php')) {
                    require_once __DIR__ . '/whatsapp_notify.php';
                    if (function_exists('send_whatsapp_notification')) {
                        $success = send_whatsapp_notification($payload['phone'] ?? '', $payload['message'] ?? '');
                    } else {
                        $success = true;
                    }
                } else {
                    $success = true;
                }
                break;

            case 'email':
                if (file_exists(__DIR__ . '/cron_email.php')) {
                    require_once __DIR__ . '/cron_email.php';
                }
                $success = true;
                break;

            default:
                $error_msg = "Unknown job type: $job_type";
                $success = false;
                break;
        }
    } catch (Throwable $e) {
        $success = false;
        $error_msg = $e->getMessage();
    }

    if ($success) {
        $conn->query("UPDATE job_queue SET status='completed', updated_at=NOW() WHERE id=$job_id");
        echo "[SUCCESS] Job #$job_id ($job_type) completed.\n";
    } else {
        $err_safe = $conn->real_escape_string($error_msg ?: 'Processing error');
        $status = ($attempts >= 3) ? 'failed' : 'pending';
        $conn->query("UPDATE job_queue SET status='$status', last_error='$err_safe', updated_at=NOW() WHERE id=$job_id");
        echo "[FAILED] Job #$job_id ($job_type): $err_safe\n";
    }
}

echo "[Job Queue Worker Finished]\n";
