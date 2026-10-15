<?php
/**
 * email_reports.php — EOD Email Digest Management Page
 * Provides UI for configuring recipients, scheduling, and sending
 * the automated end-of-day email report.
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Email Reports";
$active_page = "email_reports";

$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$today  = date('Y-m-d');

// ── Ensure email_report_log table exists ────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS email_report_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    selling_pointid INT NOT NULL,
    report_date DATE NOT NULL,
    sent_to TEXT DEFAULT '',
    method VARCHAR(20) DEFAULT 'mail',
    status ENUM('success','failed') DEFAULT 'success',
    error_msg TEXT DEFAULT '',
    sent_by INT DEFAULT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sp_date(selling_pointid, report_date)
)");

// ── Handle form actions ─────────────────────────────────────────────────────
$flash = '';
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Save email settings
    if ($action === 'save_settings') {
        $fields = [
            'email_reports_to'   => trim($_POST['email_to'] ?? ''),
            'email_from'         => trim($_POST['email_from'] ?? ''),
            'email_smtp_host'    => trim($_POST['smtp_host'] ?? ''),
            'email_smtp_port'    => (int)($_POST['smtp_port'] ?? 587),
            'email_smtp_user'    => trim($_POST['smtp_user'] ?? ''),
            'email_smtp_pass'    => trim($_POST['smtp_pass'] ?? ''),
            'email_reports_enabled' => ($_POST['enabled'] ?? '0'),
            'email_reports_time' => trim($_POST['send_time'] ?? '18:00'),
        ];
        foreach ($fields as $k => $v) {
            $ks = $conn->real_escape_string($k);
            $vs = $conn->real_escape_string($v);
            $conn->query("INSERT INTO settings (name,value) VALUES('$ks','$vs')
                ON DUPLICATE KEY UPDATE value='$vs'");
        }
        $flash = '✓ Email settings saved successfully.';
    }

    // Send test / manual report
    if ($action === 'send_now') {
        $date_send = $conn->real_escape_string(trim($_POST['report_date'] ?? $today));
        require_once 'email_reports_functions.php';
        if (!function_exists('send_eod_email')) {
            // inline the function if separate file not found
            require_once 'email_reports_functions.php';
        }
        if (function_exists('send_eod_email')) {
            $result = send_eod_email($conn, $spid, $date_send);
        } else {
            $result = _send_eod_inline($conn, $spid, $date_send);
        }
        $status_val = $result['ok'] ? 'success' : 'failed';
        $err_val = $conn->real_escape_string($result['error'] ?? $result['response'] ?? '');
        $to_val  = $conn->real_escape_string($result['recipients'] ?? '');
        $mth_val = $conn->real_escape_string($result['method'] ?? 'mail');
        $conn->query("INSERT INTO email_report_log
            (selling_pointid,report_date,sent_to,method,status,error_msg,sent_by)
            VALUES($spid,'$date_send','$to_val','$mth_val','$status_val','$err_val',$userid)");
        if ($result['ok']) {
            $flash = "✓ Report for $date_send sent successfully to {$result['recipients']}.";
        } else {
            $flash = '✗ Send failed: ' . htmlspecialchars($result['error'] ?? 'Unknown error');
            $flash_type = 'danger';
        }
    }
}

// ── Inline email send function (if email_reports_functions.php missing) ─────
function _send_eod_inline($conn, $spid, $date_f) {
    $settings_q = $conn->query("SELECT name,value FROM settings WHERE name LIKE 'email_%' LIMIT 20");
    $cfg = [];
    while($settings_q && $r=$settings_q->fetch_assoc()) $cfg[$r['name']] = $r['value'];
    $to   = $cfg['email_reports_to'] ?? '';
    $from = $cfg['email_from'] ?? 'flowork@localhost';
    if (!$to) return ['ok'=>false,'error'=>'No recipient configured','recipients'=>''];
    $de = $conn->real_escape_string($date_f);
    $sp_q = $conn->query("SELECT name FROM selling_point WHERE id=$spid LIMIT 1");
    $sp_name = ($sp_q&&$sp_q!==true)?($sp_q->fetch_assoc()['name']??'Flowork'):'Flowork';
    $stats = $conn->query("
        SELECT COUNT(DISTINCT sb.id) bales_sold,
               COALESCE(SUM(bm.mass),0) mass,
               COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd,
               COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END),0) avg_price,
               COUNT(DISTINCT g.id) growers, COUNT(DISTINCT b.id) buyers
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        WHERE DATE(sb.created_at)='$de' AND g.selling_pointid=$spid
    ")->fetch_assoc();
    $date_display = date('l d F Y', strtotime($date_f));
    $body = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f5f0e8'>
    <div style='background:#1a1208;padding:20px;text-align:center'>
      <h1 style='color:#e8b84b;margin:0;font-size:20px'>🌿 $sp_name</h1>
      <p style='color:rgba(232,184,75,.6);margin:4px 0 0;font-size:12px'>End of Day Report — $date_display</p>
    </div>
    <div style='background:#fff;padding:20px'>
    <table width='100%' cellpadding='8' cellspacing='4'>
      <tr>
        <td style='background:#faf8f4;border-radius:6px;text-align:center;padding:12px'><div style='font-size:24px;font-weight:700;color:#c8952a'>".number_format((int)($stats['bales_sold']??0))."</div><div style='font-size:10px;color:#888;text-transform:uppercase'>Bales Sold</div></td>
        <td style='background:#faf8f4;border-radius:6px;text-align:center;padding:12px'><div style='font-size:24px;font-weight:700;color:#c8952a'>$".number_format((float)($stats['usd']??0),0)."</div><div style='font-size:10px;color:#888;text-transform:uppercase'>USD Value</div></td>
        <td style='background:#faf8f4;border-radius:6px;text-align:center;padding:12px'><div style='font-size:24px;font-weight:700;color:#c8952a'>".number_format((float)($stats['mass']??0),0)."</div><div style='font-size:10px;color:#888;text-transform:uppercase'>Mass (kg)</div></td>
        <td style='background:#faf8f4;border-radius:6px;text-align:center;padding:12px'><div style='font-size:24px;font-weight:700;color:#c8952a'>$".number_format((float)($stats['avg_price']??0),4)."</div><div style='font-size:10px;color:#888;text-transform:uppercase'>Avg \$/kg</div></td>
      </tr>
    </table>
    <p style='font-size:12px;color:#888;margin-top:12px'>Growers: ".(int)($stats['growers']??0)." &nbsp;·&nbsp; Buyers: ".(int)($stats['buyers']??0)."</p>
    </div>
    <div style='background:#1a1208;padding:12px;text-align:center;font-size:11px;color:rgba(232,184,75,.4)'>Flowork Floor Management System &middot; Automated EOD Report</div>
    </body></html>";
    $subject = "[Flowork] EOD Report — $sp_name — $date_display";
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: $from\r\n";
    $sent = @mail($to, $subject, $body, $headers);
    return ['ok'=>$sent,'method'=>'mail','recipients'=>$to];
}

// ── Load settings ────────────────────────────────────────────────────────────
$sq = $conn->query("SELECT name,value FROM settings WHERE name LIKE 'email_%'");
$cfg = [];
while($sq && $r=$sq->fetch_assoc()) $cfg[$r['name']] = $r['value'];

// ── Load send history ────────────────────────────────────────────────────────
$hist_q = $conn->query("SELECT erl.*, u.username
    FROM email_report_log erl
    LEFT JOIN users u ON u.id=erl.sent_by
    WHERE erl.selling_pointid=$spid
    ORDER BY erl.sent_at DESC LIMIT 50");
$history = ($hist_q && $hist_q!==true) ? $hist_q->fetch_all(MYSQLI_ASSOC) : [];

// ── Today's quick stats ─────────────────────────────────────────────────────
$qs = $conn->query("
    SELECT COUNT(DISTINCT sb.id) bales,
           COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd,
           COUNT(DISTINCT g.id) growers
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    WHERE DATE(sb.created_at)='$today' AND g.selling_pointid=$spid
");
$qs_row = ($qs && $qs!==true) ? $qs->fetch_assoc() : [];

include "includes/layout.php";
?>
<style>
.er-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem}
@media(max-width:680px){.er-grid{grid-template-columns:1fr}}
.er-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem}
.er-card h3{margin:0 0 .85rem;font-size:.85rem;color:var(--gold);text-transform:uppercase;letter-spacing:.06em;font-weight:600}
.er-form-row{display:flex;flex-direction:column;gap:.22rem;margin-bottom:.75rem}
.er-form-row label{font-size:.67rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.er-stat{display:inline-flex;align-items:center;gap:.6rem;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:.55rem .9rem}
.er-stat-val{font-size:1.4rem;font-weight:700;color:var(--gold);font-family:'Bebas Neue',monospace;line-height:1}
.er-stat-lbl{font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.status-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:20px;font-size:.62rem;font-weight:600}
.status-badge.success{background:rgba(60,150,60,.14);color:#2a8a2a;border:1px solid rgba(60,150,60,.3)}
.status-badge.failed{background:rgba(190,30,30,.1);color:#991010;border:1px solid rgba(190,30,30,.25)}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Email Reports</h2>
    <p>Configure & send automated EOD email digests</p>
  </div>
  <div style="display:flex;gap:.4rem">
    <a href="eod_reports.php" class="btn btn-ghost btn-sm">EOD Reports</a>
  </div>
</div>

<?php if($flash):?>
<div class="alert alert-<?=$flash_type?>" style="margin-bottom:.85rem"><?=$flash?></div>
<?php endif;?>

<!-- Today's Stats Preview -->
<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
  <div class="er-stat"><div><div class="er-stat-val"><?=number_format((int)($qs_row['bales']??0))?></div><div class="er-stat-lbl">Today's Bales</div></div></div>
  <div class="er-stat"><div><div class="er-stat-val">$<?=number_format((float)($qs_row['usd']??0),0)?></div><div class="er-stat-lbl">Today's USD</div></div></div>
  <div class="er-stat"><div><div class="er-stat-val"><?=(int)($qs_row['growers']??0)?></div><div class="er-stat-lbl">Growers Sold</div></div></div>
  <div style="flex:1;min-width:200px;display:flex;align-items:center">
    <form method="POST" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="action" value="send_now">
      <input type="date" name="report_date" value="<?=$today?>" class="form-control" style="width:145px;font-size:.8rem">
      <button class="btn btn-primary btn-sm" type="submit">📧 Send Report Now</button>
    </form>
  </div>
</div>

<div class="er-grid">
  <!-- Left: Settings -->
  <div class="er-card">
    <h3>⚙ Email Configuration</h3>
    <form method="POST">
      <input type="hidden" name="action" value="save_settings">
      <div class="er-form-row">
        <label>Recipients (comma-separated)</label>
        <input type="text" name="email_to" class="form-control" value="<?=htmlspecialchars($cfg['email_reports_to']??'')?>" placeholder="manager@flowork.co.zw, director@company.com">
      </div>
      <div class="er-form-row">
        <label>From Address</label>
        <input type="email" name="email_from" class="form-control" value="<?=htmlspecialchars($cfg['email_from']??'flowork@localhost')?>" placeholder="flowork@yourserver.com">
      </div>
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem">
        <div class="er-form-row" style="flex:1">
          <label>SMTP Host (optional)</label>
          <input type="text" name="smtp_host" class="form-control" value="<?=htmlspecialchars($cfg['email_smtp_host']??'')?>" placeholder="smtp.gmail.com">
        </div>
        <div class="er-form-row" style="width:85px">
          <label>Port</label>
          <input type="number" name="smtp_port" class="form-control" value="<?=htmlspecialchars($cfg['email_smtp_port']??'587')?>">
        </div>
      </div>
      <div class="er-form-row">
        <label>SMTP Username</label>
        <input type="text" name="smtp_user" class="form-control" value="<?=htmlspecialchars($cfg['email_smtp_user']??'')?>" placeholder="Leave blank to use PHP mail()">
      </div>
      <div class="er-form-row">
        <label>SMTP Password</label>
        <input type="password" name="smtp_pass" class="form-control" value="<?=htmlspecialchars($cfg['email_smtp_pass']??'')?>" placeholder="SMTP password">
      </div>
      <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:.85rem">
        <div class="er-form-row" style="flex:1;margin-bottom:0">
          <label>Auto-send time (for cron)</label>
          <input type="time" name="send_time" class="form-control" value="<?=htmlspecialchars($cfg['email_reports_time']??'18:00')?>">
        </div>
        <div style="display:flex;align-items:center;gap:.5rem;margin-top:.4rem">
          <label style="font-size:.7rem;color:var(--muted)">Enabled</label>
          <label style="position:relative;display:inline-block;width:36px;height:20px">
            <input type="checkbox" name="enabled" value="1" style="opacity:0;width:0;height:0" <?=($cfg['email_reports_enabled']??'0')==='1'?'checked':''?>>
            <span style="position:absolute;cursor:pointer;inset:0;background:var(--muted);border-radius:20px;transition:.3s"></span>
          </label>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">💾 Save Settings</button>
    </form>

    <!-- Cron hint -->
    <div style="margin-top:1rem;background:rgba(200,149,42,.06);border:1px solid rgba(200,149,42,.15);border-radius:8px;padding:.75rem;font-size:.68rem;color:var(--muted)">
      <div style="font-weight:600;color:var(--gold);margin-bottom:.3rem">⏰ Cron / Task Scheduler Setup</div>
      <div>To auto-send at 18:00 daily, add this to Windows Task Scheduler or cron:</div>
      <code style="display:block;margin-top:.4rem;background:#1a1208;padding:.4rem .6rem;border-radius:4px;font-size:.65rem;color:#a8d8a8;word-break:break-all">php <?=realpath('.')?>/cron_email.php</code>
      <div style="margin-top:.4rem">Or via Linux cron: <code style="font-size:.64rem;background:#1a1208;padding:.15rem .4rem;border-radius:3px;color:#a8d8a8">0 18 * * * php /path/to/flowork/cron_email.php</code></div>
    </div>
  </div>

  <!-- Right: History -->
  <div class="er-card">
    <h3>📋 Send History (last 50)</h3>
    <?php if(empty($history)):?>
    <div class="text-center text-muted" style="padding:2rem;font-size:.8rem">No reports sent yet.</div>
    <?php else:?>
    <div style="overflow-x:auto;max-height:480px;overflow-y:auto">
      <table style="width:100%;border-collapse:collapse;font-size:.72rem">
        <thead>
          <tr style="position:sticky;top:0;background:var(--ink)">
            <th style="padding:.38rem .65rem;text-align:left;color:var(--muted);font-weight:500;font-size:.6rem;text-transform:uppercase">Date</th>
            <th style="padding:.38rem .65rem;text-align:left;color:var(--muted);font-weight:500;font-size:.6rem;text-transform:uppercase">Report For</th>
            <th style="padding:.38rem .65rem;text-align:left;color:var(--muted);font-weight:500;font-size:.6rem;text-transform:uppercase">Recipients</th>
            <th style="padding:.38rem .65rem;text-align:left;color:var(--muted);font-weight:500;font-size:.6rem;text-transform:uppercase">Status</th>
            <th style="padding:.38rem .65rem;text-align:left;color:var(--muted);font-weight:500;font-size:.6rem;text-transform:uppercase">By</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($history as $h):
          $sent_dt = date('d M Y H:i', strtotime($h['sent_at']));
        ?>
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:.38rem .65rem;color:var(--muted)"><?=$sent_dt?></td>
          <td style="padding:.38rem .65rem;font-family:monospace;color:var(--cream)"><?=htmlspecialchars($h['report_date'])?></td>
          <td style="padding:.38rem .65rem;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($h['sent_to'])?>">
            <?=htmlspecialchars(mb_strimwidth($h['sent_to'],0,30,'…'))?>
          </td>
          <td style="padding:.38rem .65rem">
            <span class="status-badge <?=$h['status']?>"><?=$h['status']==='success'?'✓ Sent':'✗ Failed'?></span>
          </td>
          <td style="padding:.38rem .65rem;color:var(--muted)"><?=htmlspecialchars($h['username']??'system')?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
    <?php endif;?>
  </div>
</div>

<!-- Email Preview Mockup -->
<div class="er-card" style="margin-top:0">
  <h3>👁 Email Preview (Today's Data)</h3>
  <div style="max-width:600px;margin:0 auto;border:1px solid var(--border);border-radius:8px;overflow:hidden;font-family:Arial,sans-serif">
    <div style="background:#1a1208;padding:20px;text-align:center">
      <h1 style="color:#e8b84b;margin:0;font-size:20px">🌿 <?=htmlspecialchars($_SESSION['selling_point_name']??'Floor')?></h1>
      <p style="color:rgba(232,184,75,.6);margin:4px 0 0;font-size:12px">End of Day Report — <?=date('l d F Y')?></p>
    </div>
    <div style="background:#fff;padding:20px">
      <table width="100%" cellpadding="8" cellspacing="4">
        <tr>
          <td style="background:#faf8f4;border-radius:6px;text-align:center;padding:12px">
            <div style="font-size:28px;font-weight:700;color:#c8952a"><?=number_format((int)($qs_row['bales']??0))?></div>
            <div style="font-size:10px;color:#888;text-transform:uppercase">Bales Sold</div>
          </td>
          <td style="background:#faf8f4;border-radius:6px;text-align:center;padding:12px">
            <div style="font-size:28px;font-weight:700;color:#c8952a">$<?=number_format((float)($qs_row['usd']??0),0)?></div>
            <div style="font-size:10px;color:#888;text-transform:uppercase">USD Value</div>
          </td>
          <td style="background:#faf8f4;border-radius:6px;text-align:center;padding:12px">
            <div style="font-size:28px;font-weight:700;color:#c8952a"><?=(int)($qs_row['growers']??0)?></div>
            <div style="font-size:10px;color:#888;text-transform:uppercase">Growers</div>
          </td>
        </tr>
      </table>
      <p style="font-size:12px;color:#888;text-align:center;margin-top:12px">Automated End of Day Report via Flowork</p>
    </div>
    <div style="background:#1a1208;padding:12px;text-align:center;font-size:11px;color:rgba(232,184,75,.4)">
      Flowork Floor Management System · Automated report · <?=date('d/m/Y H:i')?>
    </div>
  </div>
</div>

<?php
// ── Also create cron_email.php if it doesn't exist ──────────────────────────
if (!file_exists('cron_email.php')) {
    file_put_contents('cron_email.php', '<?php
// cron_email.php — Called by Task Scheduler / cron to send EOD email
// Example cron: 0 18 * * * php /path/to/flowork/cron_email.php
if (PHP_SAPI !== "cli") { http_response_code(403); exit("Run from the command line."); }
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
');
}

include "includes/layout_end.php";
?>
