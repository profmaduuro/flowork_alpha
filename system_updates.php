<?php
/**
 * system_updates.php — this installation's self-update settings and history.
 * Master users only. The installing itself is done by updater/update.php,
 * run by cron / Task Scheduler (see includes/updater.php).
 */
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/updater.php";
require_login();

$page_title  = "System Updates";
$active_page = "system_updates";
if (!is_master_user()) { http_response_code(403); include "includes/layout.php"; echo '<div class="alert alert-danger">Only a master user can manage system updates.</div>'; include "includes/layout_end.php"; exit; }

$root = __DIR__;
$userid = current_userid();
$spid = (int)($_SESSION['selling_pointid'] ?? 0);
$msg = ''; $err = '';

// Remember this site's address for the check after each install
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$here = $host !== '' ? "$scheme://$host" . rtrim(APP_BASE, '/') : '';
$upd = flowork_upd_get($conn);
if ($here !== '' && $upd['site_url'] !== $here) { flowork_upd_set($conn, ['site_url' => $here]); $upd['site_url'] = $here; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $err = 'Security token expired. Please retry.';
    } else {
        $a = $_POST['action'] ?? '';
        if ($a === 'save') {
            $ws = max(0, min(23, (int)($_POST['window_start'] ?? 1)));
            $we = max(0, min(24, (int)($_POST['window_end'] ?? 5)));
            $new = [
                'channel' => ($_POST['channel'] ?? '') === 'test' ? 'test' : 'stable',
                'mode'    => ($_POST['mode'] ?? '') === 'manual' ? 'manual' : 'auto',
                'paused'  => !empty($_POST['paused']) ? '1' : '0',
                'window_start' => (string)$ws, 'window_end' => (string)($we === $ws ? ($ws + 1) % 24 : $we),
            ];
            flowork_upd_set($conn, $new + ['check_requested' => '1']);
            flowork_upd_log($conn, 'info', 'Settings changed by ' . ($_SESSION['username'] ?? 'user') . ': ' . json_encode($new));
            audit_log($conn, $spid, $userid, 'flowork_update_settings', 'update', null, null, $new);
            $msg = 'Update settings saved.';
        } elseif ($a === 'check') {
            flowork_upd_set($conn, ['check_requested' => '1']);
            $msg = 'The site will check for a new version on the updater\'s next run (within 15 minutes).';
        } elseif ($a === 'install') {
            flowork_upd_set($conn, ['install_requested' => '1', 'check_requested' => '1']);
            flowork_upd_log($conn, 'info', 'Install now requested by ' . ($_SESSION['username'] ?? 'user') . '.');
            $msg = 'The newest version will be installed on the updater\'s next run (within 15 minutes), outside the update hours too.';
        } elseif ($a === 'cancel_install') {
            flowork_upd_set($conn, ['install_requested' => '0']);
            $msg = 'Install request cancelled.';
        }
        $upd = flowork_upd_get($conn);
    }
}

$current = flowork_version($root);
$log = $conn->query("SELECT * FROM flowork_update_log ORDER BY id DESC LIMIT 40");
$log = ($log && $log !== true) ? $log->fetch_all(MYSQLI_ASSOC) : [];
$last_run = $upd['last_check'] !== '' ? strtotime($upd['last_check']) : 0;
$cron_ok = $last_run > time() - 3600 * 2;
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$newer = $upd['latest_version'] !== '' && version_compare($upd['latest_version'], $current, '>');

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left">
    <h2>System Updates</h2>
    <p>This site installs new Flowork versions by itself, from signed releases. Your settings and data are never changed by an update.</p>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=$h($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=$h($err)?></div><?php endif;?>

<div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:1.25rem">
  <div class="kpi-card"><div class="kpi-label">This site runs</div><div class="kpi-value">v<?=$h($current)?></div>
    <div class="kpi-sub"><?=$upd['channel']==='test'?'Test channel':'Stable channel'?></div></div>
  <div class="kpi-card"><div class="kpi-label">Newest available</div><div class="kpi-value"><?= $upd['latest_version'] !== '' ? 'v' . $h($upd['latest_version']) : '—' ?></div>
    <div class="kpi-sub"><?= $newer ? 'newer than this site' : ($upd['latest_version'] !== '' ? 'this site is up to date' : 'not checked yet') ?></div></div>
  <div class="kpi-card"><div class="kpi-label">Status</div><div class="kpi-value" style="font-size:1rem"><?=$h($upd['paused']==='1' ? 'Paused' : ($upd['status'] ?: '—'))?></div>
    <div class="kpi-sub"><?= $upd['install_requested']==='1' ? 'install requested' : ($upd['mode']==='manual' ? 'installs when you approve' : 'installs automatically') ?></div></div>
  <div class="kpi-card"><div class="kpi-label">Last check</div><div class="kpi-value" style="font-size:1rem"><?= $last_run ? $h(date('d M H:i', $last_run)) : 'never' ?></div>
    <div class="kpi-sub" style="<?=$cron_ok?'':'color:var(--danger)'?>"><?= $cron_ok ? 'the updater is running' : 'the updater has not run lately' ?></div></div>
</div>

<?php if(!$cron_ok):?>
<div class="card mb-3">
  <div class="card-head"><h3>Turn on the updater</h3><span class="badge badge-red">not running</span></div>
  <div class="card-body" style="font-size:.85rem;line-height:1.7">
    The updater is a small command the server runs every 15 minutes. Set it up once:
    <p style="margin:.6rem 0 .3rem"><strong>cPanel:</strong> Cron Jobs → Common settings: <em>Once Per Fifteen Minutes</em> → Command:</p>
    <code style="display:block;padding:.5rem .7rem;background:var(--surface-3);border-radius:6px;word-break:break-all">/usr/local/bin/php <?=$h(str_replace('\\','/',$root))?>/updater/update.php &gt;/dev/null 2&gt;&amp;1</code>
    <p style="margin:.6rem 0 .3rem"><strong>Windows (XAMPP):</strong> Task Scheduler → Create Task → Trigger: daily, repeat every 15 minutes → Action: start a program:</p>
    <code style="display:block;padding:.5rem .7rem;background:var(--surface-3);border-radius:6px;word-break:break-all">C:\xampp\php\php-win.exe "<?=$h(str_replace('/','\\',$root))?>\updater\update.php"</code>
  </div>
</div>
<?php endif;?>

<div class="grid-2" style="align-items:start">
<div class="card">
  <div class="card-head"><h3>Settings</h3></div>
  <div class="card-body">
  <form method="POST">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="save">
    <div class="form-group">
      <label>Channel</label>
      <select name="channel" class="form-control">
        <option value="stable" <?=$upd['channel']==='stable'?'selected':''?>>Stable — releases everyone gets</option>
        <option value="test" <?=$upd['channel']==='test'?'selected':''?>>Test — also pre-releases (for a test site)</option>
      </select>
    </div>
    <div class="form-group">
      <label>Installing</label>
      <select name="mode" class="form-control">
        <option value="auto" <?=$upd['mode']==='auto'?'selected':''?>>Automatically, in the update hours</option>
        <option value="manual" <?=$upd['mode']==='manual'?'selected':''?>>Download, then wait for me to install</option>
      </select>
    </div>
    <div class="form-group">
      <label>Update hours (server time, now <?=date('H:i')?>)</label>
      <div style="display:flex;gap:.5rem;align-items:center">
        <select name="window_start" class="form-control" style="width:auto"><?php for($i=0;$i<24;$i++):?><option value="<?=$i?>" <?=(int)$upd['window_start']===$i?'selected':''?>><?=sprintf('%02d:00',$i)?></option><?php endfor;?></select>
        <span>to</span>
        <select name="window_end" class="form-control" style="width:auto"><?php for($i=1;$i<=24;$i++):?><option value="<?=$i?>" <?=(int)$upd['window_end']===$i?'selected':''?>><?=sprintf('%02d:00',$i%24)?></option><?php endfor;?></select>
      </div>
      <div style="font-size:.72rem;color:var(--muted);margin-top:.3rem">It also waits while anyone has done something in the last 15 minutes.</div>
    </div>
    <label style="display:flex;gap:.5rem;align-items:center;font-size:.85rem;margin-bottom:1rem">
      <input type="checkbox" name="paused" value="1" <?=$upd['paused']==='1'?'checked':''?>> Pause updates on this site
    </label>
    <button class="btn btn-primary">Save settings</button>
  </form>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Update now</h3></div>
  <div class="card-body" style="font-size:.85rem;line-height:1.6">
    <p style="margin-top:0">Before installing, the site checks the release signature and every file, backs up the files it replaces, and checks afterwards that it still loads. If anything is wrong it puts the previous version back.</p>
    <p>Never changed by an update: the <code>.env</code> settings, the TIMB key, uploaded files and backups, and anything in the database except new tables and columns.</p>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <form method="POST"><?=csrf_field()?><input type="hidden" name="action" value="check"><button class="btn btn-ghost">Check for updates</button></form>
      <?php if($upd['install_requested']==='1'):?>
        <form method="POST"><?=csrf_field()?><input type="hidden" name="action" value="cancel_install"><button class="btn btn-ghost">Cancel install request</button></form>
      <?php elseif($newer || $upd['ready_version'] !== ''):?>
        <form method="POST" onsubmit="return confirm('Install v<?=$h($upd['ready_version'] ?: $upd['latest_version'])?> on the next run, even outside the update hours? Users may see an \'updating\' page for a minute.')">
          <?=csrf_field()?><input type="hidden" name="action" value="install">
          <button class="btn btn-primary">Install v<?=$h($upd['ready_version'] ?: $upd['latest_version'])?> now</button>
        </form>
      <?php endif;?>
    </div>
    <?php if($upd['failed_version'] !== ''):?>
      <p style="color:var(--danger);margin-bottom:0">v<?=$h($upd['failed_version'])?> failed to install and was rolled back; see the history. It is retried only when you choose Install now.</p>
    <?php endif;?>
  </div>
</div>
</div>

<div class="card" style="margin-top:1rem">
  <div class="card-head"><h3>Update history</h3><span class="badge badge-gray">last <?=count($log)?></span></div>
  <?php if(!$log):?>
    <div class="card-body text-muted">Nothing yet.</div>
  <?php else:?>
  <div class="table-wrap" style="max-height:420px;overflow:auto"><table>
    <thead><tr><th>When</th><th>Version</th><th>What happened</th></tr></thead>
    <tbody>
    <?php foreach($log as $l): $c = $l['level']==='error' ? 'badge-red' : ($l['level']==='warn' ? 'badge-gold' : 'badge-gray'); ?>
      <tr>
        <td style="white-space:nowrap;font-size:.78rem"><?=$h($l['created_at'])?></td>
        <td><?= $l['version'] !== '' ? '<span class="badge '.$c.'">v'.$h($l['version']).'</span>' : '' ?></td>
        <td style="font-size:.82rem"><?=$h($l['message'])?></td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?php endif;?>
</div>
<?php include "includes/layout_end.php"; ?>
