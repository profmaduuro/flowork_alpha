<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_once "email_reports.php";
require_once "includes/rbz_change.php";
require_login();

$page_title  = "Notifications & Reports";
$active_page = "notifications";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();
$msg = $err  = '';
$today       = date('Y-m-d');

$conn->query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(80) PRIMARY KEY, value TEXT DEFAULT '')");
$conn->query("CREATE TABLE IF NOT EXISTS notification_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    selling_pointid INT NOT NULL,
    type ENUM('sms','email','whatsapp') DEFAULT 'sms',
    recipient VARCHAR(255) DEFAULT '',
    subject VARCHAR(255) DEFAULT '',
    status ENUM('sent','failed','simulated') DEFAULT 'sent',
    response TEXT DEFAULT '',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sp(selling_pointid),
    INDEX idx_date(sent_at)
)");
$conn->query("ALTER TABLE notification_log ADD COLUMN IF NOT EXISTS recipient VARCHAR(255) DEFAULT ''");

// Load settings
$cfg_q=$conn->query("SELECT name,value FROM settings WHERE name LIKE 'email_%' OR name LIKE 'telerivet_%' OR name LIKE 'sms_%' LIMIT 30");
$cfg=[];while($cfg_q&&$r=$cfg_q->fetch_assoc())$cfg[$r['name']]=$r['value'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action==='save_settings') {
        $fields = ['email_reports_to','email_from','email_smtp_host','email_smtp_port','email_smtp_user','email_smtp_pass',
                   'telerivet_api_key','telerivet_project_id','sms_from_number','whatsapp_token','whatsapp_phone_id'];
        foreach ($fields as $f) {
            $v=$conn->real_escape_string(trim($_POST[$f]??''));
            $conn->query("INSERT INTO settings (name,value) VALUES ('$f','$v') ON DUPLICATE KEY UPDATE value='$v'");
            $cfg[$f]=$_POST[$f]??'';
        }
        $msg="✓ Settings saved.";
    }

    if ($action==='send_eod_email') {
        $date = $conn->real_escape_string($_POST['report_date']??$today);
        // Support multiple email recipients
        $recipients = array_filter(array_map('trim', explode(',', $cfg['email_reports_to']??'')));
        if (empty($recipients)) { $err="No email recipients configured."; }
        else {
            $sent_to=[]; $failed=[];
            foreach ($recipients as $to) {
                $result = send_eod_email($conn,$spid,$date);
                $status = $result['ok']?'sent':'failed';
                $to_esc = $conn->real_escape_string($to);
                $resp_esc = $conn->real_escape_string(json_encode($result));
                $conn->query("INSERT INTO notification_log (selling_pointid,type,recipient,subject,status,response) VALUES ($spid,'email','$to_esc','EOD Report $date','$status','$resp_esc')");
                $result['ok'] ? $sent_to[]=$to : $failed[]=$to;
            }
            $msg="✓ EOD report sent to ".implode(', ',$sent_to).".";
            if($failed) $err="Failed for: ".implode(', ',$failed);
        }
    }

    if ($action==='send_sms_batch') {
        $date = $conn->real_escape_string($_POST['sms_date']??$today);
        $gids_q=$conn->query("SELECT DISTINCT g.id FROM growers g JOIN bale_junus bj ON bj.growerid=g.id JOIN tickets tk ON tk.bale_junusid=bj.id JOIN sold_bales sb ON sb.ticketsid=tk.id WHERE g.selling_pointid=$spid AND DATE(sb.created_at)='$date' AND g.phone IS NOT NULL AND g.phone!=''");
        $sent=$failed=$sim=0;
        while($gids_q&&$r=$gids_q->fetch_assoc()){
            $gid=(int)$r['id'];
            $res=send_grower_sms($conn,$spid,$gid,$date);
            $status=$res['ok']?'sent':($cfg['telerivet_api_key']??''?'failed':'simulated');
            $phone_esc=$conn->real_escape_string($res['phone']??'');
            $resp_esc=$conn->real_escape_string(substr(json_encode($res),0,500));
            $conn->query("INSERT INTO notification_log (selling_pointid,type,recipient,subject,status,response) VALUES ($spid,'sms','$phone_esc','Sale results $date','$status','$resp_esc')");
            if($status==='sent')$sent++; elseif($status==='simulated')$sim++; else $failed++;
        }
        $msg="SMS batch for $date: $sent sent, $sim simulated, $failed failed.";
        audit_log($conn,$spid,$userid,'notification_log','sms_batch',null,null,['date'=>$date,'sent'=>$sent,'failed'=>$failed]);
    }
}

// Delivery status report
$f_log_from = $conn->real_escape_string($_GET['log_from'] ?? date('Y-m-d',strtotime('-7 days')));
$f_log_to   = $conn->real_escape_string($_GET['log_to']   ?? $today);
$f_log_type = $conn->real_escape_string($_GET['log_type'] ?? '');

$log_where = "nl.selling_pointid=$spid AND DATE(nl.sent_at) BETWEEN '$f_log_from' AND '$f_log_to'";
if ($f_log_type) $log_where .= " AND nl.type='$f_log_type'";

$log_q = $conn->query("SELECT * FROM notification_log nl WHERE $log_where ORDER BY nl.sent_at DESC LIMIT 200");
$logs  = ($log_q&&$log_q!==true)?$log_q->fetch_all(MYSQLI_ASSOC):[];

// Delivery stats
$stats_q = $conn->query("SELECT type,status,COUNT(*) cnt FROM notification_log WHERE $log_where GROUP BY type,status");
$stats_raw = ($stats_q&&$stats_q!==true)?$stats_q->fetch_all(MYSQLI_ASSOC):[];
$stats = [];
foreach($stats_raw as $s) $stats[$s['type']][$s['status']] = (int)$s['cnt'];

// Today grower count
$sms_today_q=$conn->query("SELECT COUNT(DISTINCT g.id) c FROM growers g JOIN bale_junus bj ON bj.growerid=g.id JOIN tickets tk ON tk.bale_junusid=bj.id JOIN sold_bales sb ON sb.ticketsid=tk.id WHERE g.selling_pointid=$spid AND DATE(sb.created_at)='$today' AND g.phone IS NOT NULL AND g.phone!=''");
$sms_today_count=($sms_today_q&&$sms_today_q!==true)?(int)$sms_today_q->fetch_assoc()['c']:0;

// System changes that affect figures (RBZ retention rate)
$rbz_changes = flowork_rbz_changes($conn, 10);
$rbz_now     = flowork_rbz_current($conn);
$rbz_stale   = flowork_rbz_stale_count($conn, $spid, $rbz_now);

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Notifications &amp; Reports</h2><p>Send emails, SMS, and view delivery status reports</p></div>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<?php if(!($cfg['telerivet_api_key']??'')||!($cfg['email_reports_to']??'')):?>
<div class="alert" style="background:rgba(200,149,42,.08);border-left:3px solid var(--gold);padding:.75rem 1rem;font-size:.82rem">
  ⚠ <?=!($cfg['email_reports_to']??'')?'No email recipients set. ':'';?><?=!($cfg['telerivet_api_key']??'')?'Telerivet SMS not configured. ':'';?>Actions will run in simulation mode.
</div>
<?php endif;?>

<?php if($rbz_changes): $_pc = fn($s) => rtrim(rtrim(number_format($s*100,2),'0'),'.'); ?>
<div class="card mb-3">
<div class="card-head"><h3>System changes</h3>
  <?php if($rbz_stale):?><a href="balance.php" class="badge badge-gold"><?=$rbz_stale?> balanced sale(s) on this floor to recalculate</a>
  <?php else:?><span class="badge badge-green">All balanced sales use the current rate</span><?php endif;?></div>
<div class="table-wrap"><table>
<thead><tr><th>When</th><th>Change</th><th>By</th></tr></thead>
<tbody>
<?php foreach($rbz_changes as $c):?>
<tr><td class="nowrap"><?=htmlspecialchars(date('d M Y H:i', strtotime($c['changed_at'])))?></td>
<td>RBZ retention <?=$c['old_usd']!==null?'USD '.$_pc((float)$c['old_usd']).'% → ':''?>USD <?=$_pc((float)$c['new_usd'])?>% / ZiG <?=$_pc(1-(float)$c['new_usd'])?>%</td>
<td><?=htmlspecialchars($c['username'] ?: '—')?></td></tr>
<?php endforeach;?>
</tbody></table></div>
</div>
<?php endif;?>

<div class="premium-tabs-nav" data-tabs="notify">
  <button type="button" class="premium-tab-btn active" data-tab="send">📤 Send Reports</button>
  <button type="button" class="premium-tab-btn" data-tab="cron">⏰ Automation</button>
  <button type="button" class="premium-tab-btn" data-tab="settings">⚙ Settings</button>
  <button type="button" class="premium-tab-btn" data-tab="log">📊 Delivery Log</button>
</div>

<div id="panel-send" class="tab-panel active" data-tabs="notify">

<!-- Email -->
<div class="card mb-3">
<div class="card-head"><h3>📧 EOD Email Report</h3></div>
<div class="card-body">
<form method="POST"><input type="hidden" name="action" value="send_eod_email">
<div class="form-row cols-2" style="align-items:flex-end">
  <div class="form-group" style="margin:0"><label>Report Date</label><input type="date" name="report_date" class="form-control" value="<?=$today?>"></div>
  <button type="submit" class="btn btn-primary" data-progress-label="Sending...">📧 Send EOD Report</button>
</div>
<?php if($cfg['email_reports_to']??''):?>
<div style="font-size:.7rem;color:var(--muted);margin-top:.4rem">
  To: <?=htmlspecialchars($cfg['email_reports_to'])?>
  <em>(comma-separate multiple recipients)</em>
</div>
<?php endif;?>
</form>
</div>
</div>

<!-- SMS Batch -->
<div class="card mb-3">
<div class="card-head"><h3>📱 SMS Batch — Sell Date</h3></div>
<div class="card-body">
<form method="POST"><input type="hidden" name="action" value="send_sms_batch">
<div class="form-row cols-2" style="align-items:flex-end">
  <div class="form-group" style="margin:0"><label>Selling Date</label><input type="date" name="sms_date" class="form-control" value="<?=$today?>"></div>
  <button type="submit" class="btn btn-primary" onclick="return confirm('Send SMS to all growers who sold on this date?')" data-progress-label="Sending...">
    📤 Send to <?=$sms_today_count?> Growers
  </button>
</div>
<div style="font-size:.7rem;color:var(--muted);margin-top:.4rem">Sends: grower #, bales, mass, gross, deductions, net USD and ZiG</div>
</form>
</div>
</div>

</div><!-- /panel-send -->

<!-- Cron setup -->
<div id="panel-cron" class="tab-panel" data-tabs="notify">
<div class="card">
<div class="card-head"><h3>⏰ Automation (Cron)</h3></div>
<div class="card-body" style="font-size:.75rem">
  <p style="color:var(--muted);margin-bottom:.5rem">Add to crontab for daily automation:</p>
  <div style="background:var(--surface-2);color:var(--text);padding:.5rem .75rem;border-radius:5px;font-family:monospace;font-size:.68rem;overflow-x:auto;margin-bottom:.4rem">
    0 18 * * * curl -s "<?=htmlspecialchars('https://yourdomain.com/flowork/notifications.php?cron=eod&token=flowork_cron&spid='.$spid)?>"
  </div>
  <div style="background:var(--surface-2);color:var(--text);padding:.5rem .75rem;border-radius:5px;font-family:monospace;font-size:.68rem;overflow-x:auto">
    0 17 * * * curl -s "<?=htmlspecialchars('https://yourdomain.com/flowork/notifications.php?cron=sms&token=flowork_cron&spid='.$spid)?>"
  </div>
</div>
</div>
</div><!-- /panel-cron -->

<!-- Settings -->
<div id="panel-settings" class="tab-panel" data-tabs="notify">
<div class="card">
<div class="card-head"><h3>⚙ Settings</h3></div>
<div class="card-body">
<form method="POST"><input type="hidden" name="action" value="save_settings">
<div style="font-size:.78rem;font-weight:600;color:var(--muted);margin-bottom:.4rem">Email (SMTP)</div>
<div class="form-group"><label>Recipients (comma-separated) *</label>
  <input type="text" name="email_reports_to" class="form-control" value="<?=htmlspecialchars($cfg['email_reports_to']??'')?>" placeholder="gm@co.com,accounts@co.com,manager@co.com"></div>
<div class="form-row cols-2">
  <div class="form-group"><label>From Email</label><input type="email" name="email_from" class="form-control" value="<?=htmlspecialchars($cfg['email_from']??'')?>" placeholder="flowork@co.com"></div>
  <div class="form-group"><label>SMTP Host</label><input type="text" name="email_smtp_host" class="form-control" value="<?=htmlspecialchars($cfg['email_smtp_host']??'')?>" placeholder="mail.co.com"></div>
</div>
<div class="form-row cols-2">
  <div class="form-group"><label>Port</label><input type="number" name="email_smtp_port" class="form-control" value="<?=$cfg['email_smtp_port']??587?>"></div>
  <div class="form-group"><label>SMTP User</label><input type="text" name="email_smtp_user" class="form-control" value="<?=htmlspecialchars($cfg['email_smtp_user']??'')?>"></div>
</div>
<div class="form-group"><label>SMTP Password</label><input type="password" name="email_smtp_pass" class="form-control" value="<?=htmlspecialchars($cfg['email_smtp_pass']??'')?>"></div>
<hr style="margin:.6rem 0;border-color:var(--border)">
<div style="font-size:.78rem;font-weight:600;color:var(--muted);margin-bottom:.4rem">SMS (Telerivet)</div>
<div class="form-row cols-2">
  <div class="form-group"><label>API Key</label><input type="password" name="telerivet_api_key" class="form-control" value="<?=htmlspecialchars($cfg['telerivet_api_key']??'')?>" placeholder="Telerivet API key"></div>
  <div class="form-group"><label>Project ID</label><input type="text" name="telerivet_project_id" class="form-control" value="<?=htmlspecialchars($cfg['telerivet_project_id']??'')?>" placeholder="PJ..."></div>
</div>
<div class="form-group"><label>From Number</label><input type="text" name="sms_from_number" class="form-control" value="<?=htmlspecialchars($cfg['sms_from_number']??'')?>" placeholder="+263..."></div>
<button type="submit" class="btn btn-primary" style="width:100%">Save Settings</button>
</form>
</div>
</div>

</div><!-- /panel-settings -->

<!-- Delivery status report -->
<div id="panel-log" class="tab-panel" data-tabs="notify">
<div class="card">
<div class="card-head">
  <h3>📊 Delivery Status Report</h3>
  <form method="GET" style="display:flex;gap:.3rem">
    <input type="date" name="log_from" value="<?=htmlspecialchars((string)$f_log_from)?>" class="form-control" style="font-size:.7rem;padding:.25rem .45rem">
    <input type="date" name="log_to" value="<?=htmlspecialchars((string)$f_log_to)?>" class="form-control" style="font-size:.7rem;padding:.25rem .45rem">
    <select name="log_type" class="form-control" style="font-size:.7rem;padding:.25rem .4rem">
      <option value="">All</option>
      <option value="email" <?=$f_log_type==='email'?'selected':''?>>Email</option>
      <option value="sms" <?=$f_log_type==='sms'?'selected':''?>>SMS</option>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm" style="font-size:.68rem">Go</button>
  </form>
</div>

<!-- Stats summary -->
<?php if(!empty($stats)):?>
<div style="display:flex;gap:.75rem;padding:.65rem 1rem;background:var(--cream-d);border-bottom:1px solid var(--border);flex-wrap:wrap;font-size:.75rem">
  <?php foreach($stats as $type=>$statuses):
    $sent=($statuses['sent']??0)+($statuses['simulated']??0);$failed=$statuses['failed']??0;
  ?>
  <span><strong><?=strtoupper($type)?></strong>: <span class="text-leaf"><?=$sent?> sent</span><?=$failed?" · <span style='color:var(--danger)'>$failed failed</span>":'';?></span>
  <?php endforeach;?>
</div>
<?php endif;?>

<div class="table-wrap" style="max-height:320px;overflow-y:auto"><table>
<thead><tr><th>Time</th><th>Type</th><th>Recipient</th><th>Status</th><th>Subject</th></tr></thead>
<tbody>
<?php if(empty($logs)):?>
<tr><td colspan="5" class="text-center text-muted" style="padding:1.5rem">No notifications in this period.</td></tr>
<?php else:foreach($logs as $l):$sc=['sent'=>'badge-green','failed'=>'badge-red','simulated'=>'badge-gold'];?>
<tr>
  <td style="font-size:.68rem;white-space:nowrap"><?=date('d M H:i',strtotime($l['sent_at']))?></td>
  <td><span class="badge badge-gray" style="font-size:.72rem"><?=strtoupper($l['type'])?></span></td>
  <td style="font-size:.7rem;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($l['recipient'])?></td>
  <td><span class="badge <?=$sc[$l['status']]??'badge-gray'?>" style="font-size:.72rem"><?=strtoupper($l['status'])?></span></td>
  <td style="font-size:.68rem;color:var(--muted)"><?=htmlspecialchars($l['subject'])?></td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table></div>
</div>
</div><!-- /panel-log -->

<?php
// Cron endpoint
if(isset($_GET['cron'])&&$_GET['token']==='flowork_cron'){
    $sp=(int)($_GET['spid']??$spid);
    if($_GET['cron']==='eod'){$r=send_eod_email($conn,$sp);echo json_encode($r);}
    elseif($_GET['cron']==='sms'){
        $gids_q=$conn->query("SELECT DISTINCT g.id FROM growers g JOIN bale_junus bj ON bj.growerid=g.id JOIN tickets tk ON tk.bale_junusid=bj.id JOIN sold_bales sb ON sb.ticketsid=tk.id WHERE g.selling_pointid=$sp AND DATE(sb.created_at)='$today' AND g.phone IS NOT NULL AND g.phone!=''");
        $sent=0;while($gids_q&&$r=$gids_q->fetch_assoc()){send_grower_sms($conn,$sp,(int)$r['id'],$today);$sent++;}
        echo json_encode(['sent'=>$sent]);
    }
    exit;
}
?>

<?php include "includes/layout_end.php"; ?>
