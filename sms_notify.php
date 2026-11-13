<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "audit.php";
require_login();
$page_title="SMS Notifications";$active_page="sms_notify";
$spid=(int)($_SESSION['selling_pointid']??0);$userid=current_userid();
$msg=$err='';$today=date('Y-m-d');

$conn->query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(80) PRIMARY KEY, value TEXT DEFAULT '')");
$conn->query("CREATE TABLE IF NOT EXISTS sms_log (
    id INT AUTO_INCREMENT PRIMARY KEY,selling_pointid INT NOT NULL,
    growerid INT NOT NULL,grower_num VARCHAR(30) DEFAULT '',phone VARCHAR(30) DEFAULT '',
    sell_date DATE NOT NULL,message TEXT DEFAULT '',status ENUM('sent','failed','simulated') DEFAULT 'simulated',
    response TEXT DEFAULT '',sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_sp(selling_pointid,sell_date))");

$cfg_q=$conn->query("SELECT name,value FROM settings WHERE name LIKE 'telerivet_%' OR name LIKE 'sms_%' OR name LIKE 'whatsapp_%' LIMIT 20");
$cfg=[];while($cfg_q&&$r=$cfg_q->fetch_assoc())$cfg[$r['name']]=$r['value'];

function build_sms($conn,$spid,$gid,$date_f,$zig_rate=0){
    $de=$conn->real_escape_string($date_f);
    $g=$conn->query("SELECT name,surname,grower_num,phone FROM growers WHERE id=$gid LIMIT 1")->fetch_assoc();
    if(!$g||!$g['phone'])return null;
    $s=$conn->query("SELECT COUNT(sb.id) bales,COALESCE(SUM(bm.mass),0) mass,
        COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) gross
        FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid JOIN growers g ON g.id=bj.growerid
        WHERE g.id=$gid AND DATE(sb.created_at)='$de'")->fetch_assoc();
    $gross=(float)($s['gross']??0);
    $bb_q = $conn->query("
        SELECT bb.id AS bbid, dtn.usd_net_value
        FROM balanced_bales bb
        JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
        LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid = bb.id
        WHERE bb.growerid = $gid
          AND COALESCE(tg.sell_date, DATE(bb.created_at)) = '$de'
        LIMIT 1
    ");
    $bb_row = ($bb_q && $bb_q !== true) ? $bb_q->fetch_assoc() : null;
    if ($bb_row) {
        $bbid = (int)$bb_row['bbid'];
        $net = (float)$bb_row['usd_net_value'];
        
        $ext_so_res = $conn->query("SELECT COALESCE(SUM(amount_deducted), 0) FROM stoporder_deductions WHERE balanced_balesid = $bbid");
        $ext_so = ($ext_so_res && $ext_so_res !== true) ? (float)$ext_so_res->fetch_row()[0] : 0.0;
        
        $int_so_res = $conn->query("SELECT COALESCE(SUM(amount_deducted), 0) FROM internal_stop_order_deducted_amounts WHERE balanced_balesid = $bbid");
        $int_so = ($int_so_res && $int_so_res !== true) ? (float)$int_so_res->fetch_row()[0] : 0.0;
        
        $d = $ext_so + $int_so;
    } else {
        $d = 0.0;
        $net = $gross;
    }
    $msg="FLOWORK TOBACCO RESULTS\n";
    $msg.="Grower: ".$g['grower_num']." - ".$g['name']." ".$g['surname']."\n";
    $msg.="Date: ".date('d/m/Y',strtotime($date_f))."\n";
    $msg.="Bales Sold: ".(int)($s['bales']??0)." | Mass: ".number_format((float)($s['mass']??0),1)."kg\n";
    $msg.="Gross USD: $".number_format($gross,2)."\n";
    if($d>0)$msg.="Deductions: $".number_format($d,2)."\n";
    $msg.="NET PAYABLE: $".number_format($net,2);
    if($zig_rate>0)$msg.=" / ZiG ".number_format($net*$zig_rate,2);
    return['grower'=>$g,'message'=>$msg,'gross'=>$gross,'net'=>$net,'bales'=>(int)($s['bales']??0)];
}

function send_sms($phone,$msg,$cfg){
    $key=$cfg['telerivet_api_key']??'';$pid=$cfg['telerivet_project_id']??'';
    $ph=preg_replace('/\D/','',$phone);
    if(strlen($ph)===9)$ph='263'.$ph;
    if(strlen($ph)===10&&$ph[0]==='0')$ph='263'.substr($ph,1);
    if(!$key||!$pid)return['ok'=>false,'status'=>'simulated','phone'=>$ph,'error'=>'Not configured'];
    $pl=json_encode(['content'=>$msg,'to_number'=>'+'.$ph,'message_type'=>'SMS']);
    $ch=curl_init("https://api.telerivet.com/v1/projects/$pid/messages/send");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$pl,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_USERPWD=>"$key:",CURLOPT_TIMEOUT=>10]);
    $resp=curl_exec($ch);$ce=curl_error($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    return $ce?['ok'=>false,'status'=>'failed','error'=>$ce]:['ok'=>$code<300,'status'=>$code<300?'sent':'failed','phone'=>$ph,'response'=>$resp];
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    $date_r=$conn->real_escape_string($_POST['send_date']??$today);
    $er_q=$conn->query("SELECT amount FROM exchange_rate WHERE selling_pointid=$spid AND active=1 ORDER BY id DESC LIMIT 1");
    $zig_rate=($er_q&&$er_q!==true)?(float)($er_q->fetch_assoc()['amount']??0):0;

    if($action==='send_all'){
        $gids_q=$conn->query("SELECT DISTINCT g.id,g.phone FROM growers g JOIN bale_junus bj ON bj.growerid=g.id JOIN tickets tk ON tk.bale_junusid=bj.id JOIN sold_bales sb ON sb.ticketsid=tk.id WHERE g.selling_pointid=$spid AND DATE(sb.created_at)='$date_r' AND g.phone IS NOT NULL AND g.phone!=''");
        $sent=$failed=$sim=0;
        while($gids_q&&$r=$gids_q->fetch_assoc()){
            $gid=(int)$r['id'];
            $data=build_sms($conn,$spid,$gid,$date_r,$zig_rate);
            if(!$data)continue;
            $res=send_sms($data['grower']['phone'],$data['message'],$cfg);
            $status=$res['status']??'failed';
            $phone_esc=$conn->real_escape_string($res['phone']??$r['phone']);
            $msg_esc=$conn->real_escape_string($data['message']);
            $resp_esc=$conn->real_escape_string(json_encode($res));
            $gnum=$conn->real_escape_string($data['grower']['grower_num']);
            $conn->query("INSERT INTO sms_log (selling_pointid,growerid,grower_num,phone,sell_date,message,status,response) VALUES ($spid,$gid,'$gnum','$phone_esc','$date_r','$msg_esc','$status','$resp_esc')");
            if($status==='sent')$sent++; elseif($status==='simulated')$sim++; else $failed++;
        }
        $msg="SMS results: $sent sent, $sim simulated, $failed failed.";
        if($sim>0&&!($cfg['telerivet_api_key']??''))$msg.=" Configure Telerivet API key to send real SMS.";
    }
    if($action==='send_one'){
        $gid=(int)($_POST['growerid']??0);
        $data=build_sms($conn,$spid,$gid,$date_r,$zig_rate);
        if($data){
            $res=send_sms($data['grower']['phone'],$data['message'],$cfg);
            $status=$res['status']??'failed';
            $phone_esc=$conn->real_escape_string($res['phone']??'');$msg_esc=$conn->real_escape_string($data['message']);
            $resp_esc=$conn->real_escape_string(json_encode($res));$gnum=$conn->real_escape_string($data['grower']['grower_num']);
            $conn->query("INSERT INTO sms_log (selling_pointid,growerid,grower_num,phone,sell_date,message,status,response) VALUES ($spid,$gid,'$gnum','$phone_esc','$date_r','$msg_esc','$status','$resp_esc')");
            $msg=$status==='sent'?"✓ SMS sent to ".$res['phone']:($status==='simulated'?"✓ Simulated — msg: ".substr($data['message'],0,80)."...":"✕ Failed: ".($res['error']??''));
        }else{$err="Grower has no phone number or no sales on this date.";}
    }
    if($action==='save_cfg'){
        foreach(['telerivet_api_key','telerivet_project_id','sms_from_number','whatsapp_token','whatsapp_phone_id'] as $f){
            $v=$conn->real_escape_string(trim($_POST[$f]??''));
            $conn->query("INSERT INTO settings (name,value) VALUES ('$f','$v') ON DUPLICATE KEY UPDATE value='$v'");
            $cfg[$f]=$_POST[$f]??'';
        }
        $msg="✓ Settings saved.";
    }
}

// Stats
$today_sold_q=$conn->query("SELECT COUNT(DISTINCT g.id) c FROM growers g JOIN bale_junus bj ON bj.growerid=g.id JOIN tickets tk ON tk.bale_junusid=bj.id JOIN sold_bales sb ON sb.ticketsid=tk.id WHERE g.selling_pointid=$spid AND DATE(sb.created_at)='$today' AND g.phone IS NOT NULL AND g.phone!=''");
$today_count=($today_sold_q&&$today_sold_q!==true)?(int)$today_sold_q->fetch_assoc()['c']:0;
$log_q=$conn->query("SELECT sl.*,u.username FROM sms_log sl LEFT JOIN users u ON u.id=$userid WHERE sl.selling_pointid=$spid ORDER BY sl.sent_at DESC LIMIT 60");
$logs=($log_q&&$log_q!==true)?$log_q->fetch_all(MYSQLI_ASSOC):[];
$growers_q=$conn->query("SELECT id,grower_num,name,surname,phone FROM growers WHERE selling_pointid=$spid AND phone IS NOT NULL AND phone!='' ORDER BY grower_num LIMIT 500");
$growers=($growers_q&&$growers_q!==true)?$growers_q->fetch_all(MYSQLI_ASSOC):[];
$is_configured=!empty($cfg['telerivet_api_key']&&$cfg['telerivet_project_id']);

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>SMS Notifications</h2><p>Send sale results to growers via SMS · <?=$today_count?> growers with phone numbers sold today</p></div>
  <?php if($is_configured):?><span class="badge badge-green" style="font-size:.72rem">✓ Telerivet Configured</span><?php else:?><span class="badge badge-gold" style="font-size:.72rem">⚠ Simulation Mode</span><?php endif;?>
</div>
<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="premium-tabs-nav" data-tabs="smsnotify">
  <button type="button" class="premium-tab-btn active" data-tab="send">📱 Send Sale Results</button>
  <button type="button" class="premium-tab-btn" data-tab="settings">⚙ API Settings</button>
  <button type="button" class="premium-tab-btn" data-tab="log">🕘 SMS Log</button>
</div>

<div id="panel-send" class="tab-panel active" data-tabs="smsnotify">
<div class="card">
<div class="card-head"><h3>📱 Send Sale Results</h3></div>
<div class="card-body">
  <form method="POST" style="margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid var(--border)">
    <input type="hidden" name="action" value="send_all">
    <div class="form-row cols-2" style="align-items:flex-end">
      <div class="form-group" style="margin:0"><label>Selling Date</label><input type="date" name="send_date" class="form-control" value="<?=$today?>"></div>
      <button type="submit" class="btn btn-primary" onclick="return confirm('Send SMS to all <?=$today_count?> growers?')" data-progress-label="Sending SMS...">
        📤 Send to All (<?=$today_count?> growers)
      </button>
    </div>
  </form>
  <form method="POST">
    <input type="hidden" name="action" value="send_one">
    <div class="form-row cols-2" style="align-items:flex-end">
      <div class="form-group" style="margin:0"><label>Single Grower</label>
        <select name="growerid" class="form-control">
          <option value="">Select grower...</option>
          <?php foreach($growers as $g):?>
          <option value="<?=$g['id']?>"><?=htmlspecialchars($g['grower_num'].' — '.$g['name'].' '.$g['surname'].' ('.$g['phone'].')')?></option>
          <?php endforeach;?>
        </select>
      </div>
      <div><input type="date" name="send_date" class="form-control" value="<?=$today?>" style="margin-bottom:.5rem"><button type="submit" class="btn btn-ghost" style="width:100%">Send Single</button></div>
    </div>
  </form>
  <div style="margin-top:.75rem;padding:.65rem;background:var(--cream-d);border-radius:6px;font-size:.72rem;color:var(--muted)">
    <strong>Message preview:</strong> FLOWORK TOBACCO RESULTS · Grower # · Date · Bales · Mass · Gross USD · Deductions · NET PAYABLE USD / ZiG
  </div>
</div>
</div>
</div><!-- /panel-send -->

<div id="panel-settings" class="tab-panel" data-tabs="smsnotify">
<div class="card">
<div class="card-head"><h3>⚙ API Settings</h3></div>
<div class="card-body">
<form method="POST"><input type="hidden" name="action" value="save_cfg">
<div style="font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.5rem">Telerivet (SMS)</div>
<div class="form-group"><label>API Key</label><input type="password" name="telerivet_api_key" class="form-control" value="<?=htmlspecialchars($cfg['telerivet_api_key']??'')?>" placeholder="Your Telerivet API key"></div>
<div class="form-row cols-2">
  <div class="form-group"><label>Project ID</label><input type="text" name="telerivet_project_id" class="form-control" value="<?=htmlspecialchars($cfg['telerivet_project_id']??'')?>" placeholder="PJ..."></div>
  <div class="form-group"><label>From Number</label><input type="text" name="sms_from_number" class="form-control" value="<?=htmlspecialchars($cfg['sms_from_number']??'')?>" placeholder="+263..."></div>
</div>
<hr style="margin:.6rem 0;border-color:var(--border)">
<div style="font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.5rem">WhatsApp Business API (optional)</div>
<div class="form-row cols-2">
  <div class="form-group"><label>Access Token</label><input type="password" name="whatsapp_token" class="form-control" value="<?=htmlspecialchars($cfg['whatsapp_token']??'')?>" placeholder="Bearer token"></div>
  <div class="form-group"><label>Phone Number ID</label><input type="text" name="whatsapp_phone_id" class="form-control" value="<?=htmlspecialchars($cfg['whatsapp_phone_id']??'')?>" placeholder="From Meta dashboard"></div>
</div>
<button type="submit" class="btn btn-primary" style="width:100%">Save Settings</button>
</form>
</div>
</div>
</div><!-- /panel-settings -->

<div id="panel-log" class="tab-panel" data-tabs="smsnotify">
<div class="card">
<div class="card-head"><h3>SMS Log</h3><span class="badge badge-gray"><?=count($logs)?></span></div>
<div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
<thead><tr><th>Time</th><th>Grower</th><th>Phone</th><th>Date</th><th>Status</th></tr></thead>
<tbody>
<?php if(empty($logs)):?><tr><td colspan="5" class="text-center text-muted" style="padding:2rem">No SMS sent yet.</td></tr>
<?php else:foreach($logs as $l):$sc=['sent'=>'badge-green','failed'=>'badge-red','simulated'=>'badge-gold'];?>
<tr>
  <td style="font-size:.7rem"><?=date('d M H:i',strtotime($l['sent_at']))?></td>
  <td><span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($l['grower_num'])?></span></td>
  <td style="font-size:.72rem;font-family:monospace"><?=htmlspecialchars($l['phone'])?></td>
  <td style="font-size:.7rem"><?=date('d M',strtotime($l['sell_date']))?></td>
  <td><span class="badge <?=$sc[$l['status']]?>" style="font-size:.72rem"><?=strtoupper($l['status'])?></span></td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table></div>
</div>
</div><!-- /panel-log -->
<?php include "includes/layout_end.php";?>
