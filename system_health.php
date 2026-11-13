<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title="System Health";$active_page="system_health";
$spid=(int)($_SESSION['selling_pointid']??0);$today=date('Y-m-d');

// AJAX refresh
if(isset($_GET['ajax'])){
    header('Content-Type: application/json');$checks=[];

    // DB connection
    $checks['db']=['label'=>'Database','status'=>$conn?'ok':'error','detail'=>$conn?'Connected':'Cannot connect'];

    // Key tables exist + row counts
    $tables=['growers','bale_mass','bale_junus','tickets','sold_bales','buyers','transporter','bale_locations','exchange_rate','selling_days'];
    foreach($tables as $tbl){
        $r=$conn->query("SELECT COUNT(*) c FROM `$tbl`");
        if(!$r)$checks['tbl_'.$tbl]=['label'=>$tbl,'status'=>'warn','detail'=>'Table missing'];
        else{$c=(int)$r->fetch_assoc()['c'];$checks['tbl_'.$tbl]=['label'=>$tbl,'status'=>'ok','detail'=>number_format($c).' rows'];}
    }

    // Today's selling day
    $day_q=$conn->query("SELECT status FROM selling_days WHERE selling_pointid=$spid AND sell_date='$today' LIMIT 1");
    $day=$day_q&&$day_q!==true?$day_q->fetch_assoc():null;
    $checks['selling_day']=['label'=>'Today selling day','status'=>$day?'ok':'warn','detail'=>$day?ucfirst($day['status']):'Not opened'];

    // Exchange rate
    $er_q=$conn->query("SELECT amount,sell_date FROM exchange_rate WHERE selling_pointid=$spid AND active=1 ORDER BY id DESC LIMIT 1");
    $er=$er_q&&$er_q!==true?$er_q->fetch_assoc():null;
    $er_old=$er&&strtotime($er['sell_date'])<strtotime('-2 days');
    $checks['exchange_rate']=['label'=>'Exchange Rate','status'=>$er?($er_old?'warn':'ok'):'error','detail'=>$er?$er['amount'].' ZiG/USD ('.($er_old?'⚠ old':'today').')':'Not set'];

    // Recent backup
    $backups=glob(__DIR__.'/backups/backup_*.sql');
    rsort($backups);
    $last_bk=$backups?filemtime($backups[0]):null;
    $bk_age=$last_bk?round((time()-$last_bk)/3600):null;
    $checks['backup']=['label'=>'Last Backup','status'=>$bk_age===null?'error':($bk_age>48?'warn':'ok'),'detail'=>$bk_age===null?'No backups':($bk_age.'h ago')];

    // Today's errors (check PHP error log)
    $checks['php']=['label'=>'PHP Version','status'=>'ok','detail'=>PHP_VERSION];

    // DB size
    $db_q=$conn->query("SELECT ROUND(SUM(data_length+index_length)/1024/1024,1) mb FROM information_schema.tables WHERE table_schema=DATABASE()");
    $db_mb=$db_q&&$db_q!==true?(float)$db_q->fetch_assoc()['mb']:0;
    $checks['db_size']=['label'=>'Database Size','status'=>$db_mb>500?'warn':'ok','detail'=>$db_mb.' MB'];

    // Disk space
    $free=disk_free_space('/');$total=disk_total_space('/');
    $pct_used=$total>0?round(($total-$free)/$total*100):0;
    $checks['disk']=['label'=>'Disk Space','status'=>$pct_used>90?'error':($pct_used>75?'warn':'ok'),'detail'=>$pct_used.'% used ('.round($free/1073741824,1).'GB free)'];

    // Scale queue pending
    $sq=$conn->query("SELECT COUNT(*) c FROM scale_queue WHERE selling_pointid=$spid AND status='pending'");
    $sqc=$sq&&$sq!==true?(int)$sq->fetch_assoc()['c']:0;
    $checks['scale_queue']=['label'=>'Scale Queue','status'=>$sqc>10?'warn':'ok','detail'=>$sqc.' pending weighings'];

    // Unsent SMS
    $sms_q=$conn->query("SELECT COUNT(*) c FROM sms_log WHERE selling_pointid=$spid AND sell_date='$today' AND status='failed'");
    $sms_fail=$sms_q&&$sms_q!==true?(int)$sms_q->fetch_assoc()['c']:0;
    $checks['sms']=['label'=>'Failed SMS today','status'=>$sms_fail>0?'warn':'ok','detail'=>$sms_fail.' failures'];

    echo json_encode(['checks'=>array_values($checks),'time'=>date('H:i:s'),'uptime'=>round((microtime(true)-$_SERVER['REQUEST_TIME_FLOAT'])*1000).'ms']);
    exit;
}

include "includes/layout.php";
?>
<style>
.check-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.75rem;margin-bottom:1.25rem}
.check-item{background:var(--cream-d);border:1px solid var(--border);border-radius:var(--radius);padding:.85rem 1rem;position:relative;overflow:hidden}
.check-item::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px}
.check-item.ok::before{background:#4caf50}
.check-item.warn::before{background:#e8b84b}
.check-item.error::before{background:#e53935}
.check-item .ci-label{font-size:.78rem;font-weight:600;color:var(--muted);margin-bottom:.3rem}
.check-item .ci-status{font-size:1.1rem;font-weight:700;margin-bottom:.25rem}
.check-item.ok .ci-status{color:var(--ok-text)}
.check-item.warn .ci-status{color:var(--warn-text)}
.check-item.error .ci-status{color:var(--bad-text)}
.check-item .ci-detail{font-size:.72rem;color:var(--muted)}
</style>

<div class="page-header">
  <div class="page-header-left"><h2>System Health</h2><p>Live status of all system components</p></div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <span id="health-time" style="font-size:.72rem;color:var(--muted)">Loading...</span>
    <button onclick="runChecks()" class="btn btn-ghost btn-sm">⟳ Refresh</button>
  </div>
</div>

<div class="check-grid" id="check-grid">
  <div style="grid-column:1/-1;text-align:center;padding:2rem;color:var(--muted)">Running health checks...</div>
</div>

<!-- Quick actions -->
<div class="card">
<div class="card-head"><h3>Quick Actions</h3></div>
<div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap">
  <?php foreach([
    ['auto_backup.php','💾 Run Backup','btn-primary'],
    ['day_control.php','📅 Day Control','btn-ghost'],
    ['exchange_rate.php','💱 Set Rate','btn-ghost'],
    ['scale_sync.php','⚖ Scale Queue','btn-ghost'],
    ['audit_log.php','📋 Audit Log','btn-ghost'],
    ['syscheck.php','🔍 Full Syscheck','btn-ghost'],
  ] as [$href,$lbl,$cls]):?>
  <a href="<?=$href?>" class="btn <?=$cls?> btn-sm"><?=$lbl?></a>
  <?php endforeach;?>
</div>
</div>

<!-- Error log viewer -->
<div class="card mt-3">
<div class="card-head"><h3>Recent PHP Errors</h3></div>
<div id="error-log" class="card-body" style="font-family:monospace;font-size:.72rem;max-height:240px;overflow-y:auto;background:var(--surface-2);color:#a0e8a0;border-radius:0 0 var(--radius) var(--radius)">
<?php
$log_file=ini_get('error_log');
if($log_file&&file_exists($log_file)){
    $lines=array_slice(file($log_file),(-50));
    foreach(array_reverse($lines) as $line){
        $col=strpos($line,'Fatal')!==false||strpos($line,'Error')!==false?'#ff8080':(strpos($line,'Warning')!==false?'#ffcc80':'#a0e8a0');
        echo '<div style="color:'.htmlspecialchars($col).'">'.htmlspecialchars(rtrim($line)).'</div>';
    }
}else{echo '<span style="color:#888">Error log not accessible or not configured.</span>';}
?>
</div>
</div>

<script>
async function runChecks(){
    var d=await fw.get('system_health.php',{ajax:1});
    document.getElementById('health-time').textContent='Last check: '+d.time+' ('+d.uptime+')';
    var grid=document.getElementById('check-grid');
    var icons={ok:'✓',warn:'⚠',error:'✕'};
    grid.innerHTML=d.checks.map(function(c){
        return '<div class="check-item '+c.status+'">' +
            '<div class="ci-label">'+c.label+'</div>' +
            '<div class="ci-status">'+icons[c.status]+'</div>' +
            '<div class="ci-detail">'+c.detail+'</div></div>';
    }).join('');
}
runChecks();
setInterval(runChecks,60000);
</script>

<?php include "includes/layout_end.php";?>
