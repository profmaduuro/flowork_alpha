<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$active_page = "";
$page_title = "SSE Notification Tester";
include "includes/layout.php";

$message = "";
if (isset($_POST['trigger'])) {
    $type = $_POST['type'] ?? 'bale_weighed';
    $custom_msg = $_POST['message'] ?? 'Test notification triggered!';
    
    $type = $conn->real_escape_string($type);
    $custom_msg = $conn->real_escape_string($custom_msg);
    
    $sql = "INSERT INTO event_notifications (event_type, message) VALUES ('$type', '$custom_msg')";
    if ($conn->query($sql)) {
        $message = "Notification triggered successfully! Check your other open tabs for the toast alert and audio chime.";
    } else {
        $message = "Error: " . $conn->error;
    }
}
?>

<div class="card" style="max-width: 500px; margin: 4rem auto 2rem; padding: 2rem;">
    <h2 style="font-family: 'Bebas Neue', sans-serif; letter-spacing: 0.05em; color: var(--gold); margin-bottom: 1rem;">
        🔔 Real-Time SSE Notification Tester
    </h2>
    <p style="font-size: 0.85rem; color: var(--muted); margin-bottom: 1.5rem; line-height: 1.5;">
        Use this tool to inject a notification into the event system. Open another page (e.g. the Dashboard) in a separate tab to see the toast alert and hear the alert chime instantly!
    </p>

    <?php if ($message): ?>
        <div style="background: rgba(61,107,53,0.12); border: 1px solid rgba(61,107,53,0.25); color: var(--leaf); padding: 10px; border-radius: 6px; font-size: 0.8rem; margin-bottom: 1.5rem;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div style="margin-bottom: 1rem;">
            <label style="display: block; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 6px; color: var(--muted);">Event Type</label>
            <select name="type" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--cream); color: var(--ink); font-family: inherit; font-size: 0.8rem;">
                <option value="bale_weighed">Bale Weighed (Success Toast)</option>
                <option value="bale_sold">Bale Sold (Success Toast)</option>
                <option value="rate_updated">Exchange Rate Updated (Warning Toast)</option>
            </select>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <label style="display: block; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 6px; color: var(--muted);">Notification Message</label>
            <input type="text" name="message" value="Bale B1092 weighed at Selling Point A" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--cream); color: var(--ink); font-family: inherit; font-size: 0.8rem;">
        </div>

        <button type="submit" name="trigger" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 0.85rem; letter-spacing: 0.05em; border-radius: 6px; border: 0; cursor: pointer;">
            🚀 Send Test Notification
        </button>
    </form>
</div>

<?php include "includes/layout_end.php"; ?>
