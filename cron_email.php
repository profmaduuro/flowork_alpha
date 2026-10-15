<?php
// cron_email.php — Called by Task Scheduler / cron to send EOD email
// Example cron: 0 18 * * * php /path/to/flowork/cron_email.php
// Command line only: over HTTP anyone could trigger EOD emails to every floor.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
chdir(__DIR__);
require_once "includes/conn.php";
require_once "email_reports_functions.php";

// Get all active selling points
$spq = $conn->query("SELECT id FROM selling_point WHERE active=1");
if ($spq) {
    while($sp = $spq->fetch_assoc()) {
        $result = _send_eod_inline($conn, (int)$sp["id"], date("Y-m-d"));
        $status = $result["ok"] ? "success" : "failed";
        $err = $conn->real_escape_string($result["error"] ?? "");
        $to  = $conn->real_escape_string($result["recipients"] ?? "");
        $conn->query("INSERT INTO email_report_log
            (selling_pointid,report_date,sent_to,method,status,error_msg)
            VALUES({$sp["id"]},\"".date("Y-m-d")."\",\"$to\",\"mail\",\"$status\",\"$err\")");
        echo date("H:i:s")." - SP#{$sp["id"]}: $status\n";
    }
}
