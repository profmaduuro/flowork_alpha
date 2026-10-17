<?php
/**
 * auto_backup.php
 * Can be called via:
 * 1. Browser (manual) - include "includes/auth.php" guard
 * 2. Cron: php /var/www/html/auto_backup.php --cron
 * 3. URL with secret token: ?token=YOUR_SECRET_TOKEN
 */
mysqli_report(MYSQLI_REPORT_OFF);
$is_cron = in_array('--cron', $argv ?? []);

// Token access dumps the entire database without a login, so there is no
// fallback value: it defaulted to a token committed to the source tree and
// published in .env.example, which made an unauthenticated full backup
// reachable by anyone who had seen the code. With BACKUP_TOKEN unset, token
// access is off and the normal require_login() path below applies.
require_once __DIR__ . '/includes/env_loader.php';
$backup_token = flowork_env('BACKUP_TOKEN', '');
$token_ok = is_string($backup_token)
         && strlen($backup_token) >= 16
         && isset($_GET['token'])
         && hash_equals($backup_token, (string)$_GET['token']);

if (!$is_cron && !$token_ok) {
    session_start();
    require_once "includes/auth.php";
    require_once "includes/conn.php";
    require_login();
} else {
    require_once "includes/conn.php";
}

$page_title  = "Auto Backup";
$active_page = "auto_backup";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = $_SESSION['user_id'] ?? 0;

// Backup settings
$backup_dir  = __DIR__ . '/backups/';
$keep_days   = 30;
$max_backups = 60;

if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);

// Ensure .htaccess protection
if (!file_exists($backup_dir . '.htaccess')) {
    file_put_contents($backup_dir . '.htaccess', "Deny from all\n");
}

// Create backup log table
$conn->query("CREATE TABLE IF NOT EXISTS backup_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    file_size_kb INT DEFAULT 0,
    table_count INT DEFAULT 0,
    status ENUM('success','failed','partial') DEFAULT 'success',
    triggered_by ENUM('manual','cron','token') DEFAULT 'manual',
    notes TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

function do_backup($conn, $backup_dir, $triggered_by='manual') {
    $dbname  = $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'];
    $ts      = date('Y-m-d_H-i-s');
    $fname   = "backup_{$dbname}_{$ts}.sql";
    $fpath   = $backup_dir . $fname;

    $tables_q = $conn->query("SHOW TABLES");
    if (!$tables_q) return ['ok'=>false,'error'=>'Cannot list tables: '.$conn->error];

    $sql  = "-- Flowork Auto Backup\n-- Database: $dbname\n-- Date: ".date('Y-m-d H:i:s')."\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
    $tbl_count = 0;

    while ($row = $tables_q->fetch_row()) {
        $table = $row[0];
        $create = $conn->query("SHOW CREATE TABLE `$table`")->fetch_assoc();
        $sql .= "-- Table: $table\n";
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql .= $create['Create Table'] . ";\n\n";

        $data = $conn->query("SELECT * FROM `$table`");
        if ($data && $data->num_rows > 0) {
            while ($drow = $data->fetch_assoc()) {
                $vals = array_map(function($v) use ($conn) {
                    return $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
                }, array_values($drow));
                $cols = '`' . implode('`,`', array_keys($drow)) . '`';
                $sql .= "INSERT INTO `$table` ($cols) VALUES (".implode(',',$vals).");\n";
            }
            $sql .= "\n";
        }
        $tbl_count++;
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $written = file_put_contents($fpath, $sql);
    if ($written === false) return ['ok'=>false,'error'=>"Cannot write to $fpath"];

    $size_kb = round(filesize($fpath) / 1024);
    $fname_esc = $conn->real_escape_string($fname);
    $trig_esc  = $conn->real_escape_string($triggered_by);
    $conn->query("INSERT INTO backup_log (filename,file_size_kb,table_count,status,triggered_by)
        VALUES ('$fname_esc',$size_kb,$tbl_count,'success','$trig_esc')");

    return ['ok'=>true,'file'=>$fname,'size_kb'=>$size_kb,'tables'=>$tbl_count];
}

function purge_old_backups($backup_dir, $keep_days, $conn) {
    $purged = 0;
    $cutoff = time() - ($keep_days * 86400);
    foreach (glob($backup_dir . 'backup_*.sql') as $f) {
        if (filemtime($f) < $cutoff) {
            $fn = basename($f);
            @unlink($f);
            $fn_esc = $conn->real_escape_string($fn);
            $conn->query("UPDATE backup_log SET notes='Purged after $keep_days days' WHERE filename='$fn_esc'");
            $purged++;
        }
    }
    return $purged;
}

// Run backup if triggered
$result = null;
if ($is_cron || $token_ok || (isset($_POST['action']) && $_POST['action']==='run_backup')) {
    $triggered = $is_cron ? 'cron' : ($token_ok ? 'token' : 'manual');
    $result = do_backup($conn, $backup_dir, $triggered);
    $purged = purge_old_backups($backup_dir, $keep_days, $conn);
    $result['purged'] = $purged;

    if ($is_cron || $token_ok) {
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}

// Download backup
if (isset($_GET['dl'])) {
    $fn = basename($_GET['dl']);
    $fp = $backup_dir . $fn;
    if (file_exists($fp) && preg_match('/^backup_.*\.sql$/',$fn)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        header('Content-Length: '.filesize($fp));
        readfile($fp);
        exit;
    }
}

// Delete backup
if (isset($_POST['action']) && $_POST['action']==='delete_backup') {
    $fn = basename($_POST['filename'] ?? '');
    $fp = $backup_dir . $fn;
    if (file_exists($fp) && preg_match('/^backup_.*\.sql$/',$fn)) {
        @unlink($fp);
    }
}

// Load backup list
$backups = [];
foreach (array_reverse(glob($backup_dir . 'backup_*.sql')) as $f) {
    $backups[] = [
        'filename' => basename($f),
        'size_kb'  => round(filesize($f)/1024),
        'mtime'    => filemtime($f),
    ];
}

// Load backup log
$log_rows = $conn->query("SELECT * FROM backup_log ORDER BY id DESC LIMIT 30");
$logs = ($log_rows&&$log_rows!==true)?$log_rows->fetch_all(MYSQLI_ASSOC):[];

// Cron setup instructions
$php_path = PHP_BINARY ?: '/usr/local/bin/php';
$script_path = __FILE__;
$cron_line = "0 2 * * * $php_path $script_path --cron >> /tmp/flowork_backup.log 2>&1";

if (!$is_cron && !$token_ok) include "includes/layout.php";
?>
<?php if(!$is_cron&&!$token_ok): ?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Automated Backup</h2>
    <p>Schedule, run and manage database backups</p>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="run_backup">
    <button type="submit" class="btn btn-primary" data-progress-label="Creating backup...">
      ▶ Run Backup Now
    </button>
  </form>
</div>

<?php if($result):?>
<div class="alert alert-<?=$result['ok']?'success':'danger'?>">
  <?php if($result['ok']):?>
    ✓ Backup created: <strong><?=$result['file']?></strong>
    (<?=$result['size_kb']?> KB · <?=$result['tables']?> tables)
    <?php if($result['purged']>0):?> · <?=$result['purged']?> old backups purged.<?php endif;?>
  <?php else:?>
    ✕ Backup failed: <?=htmlspecialchars($result['error'])?>
  <?php endif;?>
</div>
<?php endif;?>

<div class="premium-tabs-nav" data-tabs="autobackup">
  <button type="button" class="premium-tab-btn active" data-tab="files">🗄 Backup Files</button>
  <button type="button" class="premium-tab-btn" data-tab="log">🕘 Backup Log</button>
  <button type="button" class="premium-tab-btn" data-tab="cron">⏰ Automated Schedule</button>
</div>

<!-- Cron setup -->
<div id="panel-cron" class="tab-panel" data-tabs="autobackup">
<div class="card">
<div class="card-head"><h3>⏰ Automated Schedule (Cron)</h3></div>
<div class="card-body">
  <p style="font-size:.82rem;margin-bottom:.75rem">Add this line to your crontab to run a backup every night at 2 AM:</p>
  <div style="background:var(--ink);color:var(--gold);padding:.75rem 1rem;border-radius:6px;font-family:monospace;font-size:.78rem;overflow-x:auto">
    <?=htmlspecialchars($cron_line)?>
  </div>
  <p style="font-size:.75rem;color:var(--muted);margin-top:.5rem">
    On cPanel: <strong>Cron Jobs → Add New</strong> → paste the command (without the schedule part) and set timing to Daily at 2:00 AM.
    <br>For Docker: add a cron service to your docker-compose or use a cURL token URL instead:
    <code style="font-size:.72rem"><?=htmlspecialchars('https://yourdomain.com/flowork/auto_backup.php?token=YOUR_BACKUP_TOKEN')?></code>
  </p>
</div>
</div>

</div><!-- /panel-cron -->

<!-- Backup files -->
<div id="panel-files" class="tab-panel active" data-tabs="autobackup">
<div class="card">
<div class="card-head">
  <h3>Backup Files</h3>
  <span class="badge badge-gray"><?=count($backups)?> files · Keep <?=$keep_days?> days</span>
</div>
<?php if(empty($backups)):?>
<div class="card-body text-center text-muted">No backups yet. Run your first backup above.</div>
<?php else:?>
<div class="table-wrap" style="max-height:450px;overflow-y:auto"><table>
<thead><tr><th>File</th><th class="text-right">Size</th><th>Created</th><th></th></tr></thead>
<tbody>
<?php foreach($backups as $bk):?>
<tr>
  <td style="font-size:.72rem;font-family:monospace"><?=htmlspecialchars($bk['filename'])?></td>
  <td class="td-mono text-right" style="font-size:.72rem"><?=number_format($bk['size_kb'])?> KB</td>
  <td style="font-size:.7rem;color:var(--muted)"><?=date('d M Y H:i',$bk['mtime'])?></td>
  <td style="white-space:nowrap">
    <a href="?dl=<?=urlencode($bk['filename'])?>" class="btn btn-ghost btn-sm" style="font-size:.65rem">⬇</a>
    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this backup file?')">
      <input type="hidden" name="action" value="delete_backup">
      <input type="hidden" name="filename" value="<?=htmlspecialchars($bk['filename'])?>">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--danger)">✕</button>
    </form>
  </td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
<?php endif;?>
</div>

</div><!-- /panel-files -->

<!-- Backup log -->
<div id="panel-log" class="tab-panel" data-tabs="autobackup">
<div class="card">
<div class="card-head"><h3>Backup Log</h3></div>
<?php if(empty($logs)):?>
<div class="card-body text-center text-muted">No backup history yet.</div>
<?php else:?>
<div class="table-wrap" style="max-height:450px;overflow-y:auto"><table>
<thead><tr><th>Date/Time</th><th class="text-right">Size</th><th>Tables</th><th>Trigger</th><th>Status</th></tr></thead>
<tbody>
<?php foreach($logs as $l):?>
<tr>
  <td style="font-size:.72rem"><?=date('d M H:i',strtotime($l['created_at']))?></td>
  <td class="td-mono text-right" style="font-size:.72rem"><?=number_format($l['file_size_kb'])?> KB</td>
  <td class="td-mono text-right" style="font-size:.72rem"><?=$l['table_count']?></td>
  <td><span class="badge badge-gray" style="font-size:.58rem"><?=strtoupper($l['triggered_by'])?></span></td>
  <td><span class="badge <?=$l['status']==='success'?'badge-green':'badge-red'?>" style="font-size:.58rem"><?=strtoupper($l['status'])?></span></td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
<?php endif;?>
</div>
</div><!-- /panel-log -->

<?php include "includes/layout_end.php"; ?>
<?php endif; ?>
