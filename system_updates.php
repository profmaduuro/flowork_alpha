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
            $msg = 'Check requested. The countdown below shows when the updater picks it up.';
        } elseif ($a === 'install') {
            flowork_upd_set($conn, ['install_requested' => '1', 'check_requested' => '1']);
            flowork_upd_log($conn, 'info', 'Install now requested by ' . ($_SESSION['username'] ?? 'user') . '.');
            $msg = 'Install requested. The countdown below shows when the updater starts it, outside the update hours too.';
        } elseif ($a === 'cancel_install') {
            flowork_upd_set($conn, ['install_requested' => '0']);
            $msg = 'Install request cancelled.';
        }
        $upd = flowork_upd_get($conn);
    }
}

$current = flowork_version($root);

// When the updater runs next: cPanel's "Once Per Fifteen Minutes" is */15, so on the quarter hour
function fw_upd_live(mysqli $conn, array $u, string $current): array {
    $now = time();
    $lid = $conn->query("SELECT MAX(id) m FROM flowork_update_log");
    $started = $u['run_started'] !== '' ? strtotime($u['run_started']) : 0;
    return [
        'now'       => $now,
        'next_run'  => (intdiv($now, 900) + 1) * 900,
        'last_run'  => $u['last_run'] !== '' ? strtotime($u['last_run']) : 0,
        'running'   => $started > $now - 900,           // a run older than 15 minutes has died
        'step'      => $u['step'],
        'result'    => $u['last_result'],
        'status'    => $u['status'],
        'current'   => $current,
        'latest'    => $u['latest_version'],
        'check_req' => $u['check_requested'] === '1',
        'inst_req'  => $u['install_requested'] === '1',
        'log_id'    => ($lid && $lid !== true) ? (int)($lid->fetch_assoc()['m'] ?? 0) : 0,
    ];
}
$live = fw_upd_live($conn, $upd, $current);
if (($_GET['live'] ?? '') === '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($live);
    exit;
}

$log = $conn->query("SELECT * FROM flowork_update_log ORDER BY id DESC LIMIT 40");
$log = ($log && $log !== true) ? $log->fetch_all(MYSQLI_ASSOC) : [];
$last_run = $upd['last_check'] !== '' ? strtotime($upd['last_check']) : 0;
$cron_ok = max($last_run, $live['last_run']) > time() - 3600 * 2;
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


<div class="card mb-3" id="upd-live" style="margin-bottom:1.25rem">
  <div class="card-body" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
    <div id="ul-icon" class="ul-icon" aria-hidden="true"></div>
    <div style="flex:1;min-width:220px">
      <div id="ul-title" style="font-weight:600">Updater activity</div>
      <div id="ul-sub" style="font-size:.8rem;color:var(--muted);margin-top:.15rem"></div>
      <div class="ul-bar"><div id="ul-fill"></div></div>
    </div>
    <div style="text-align:right">
      <div id="ul-count" style="font-size:1.6rem;font-weight:700;font-variant-numeric:tabular-nums;line-height:1">--:--</div>
      <div id="ul-countlbl" style="font-size:.72rem;color:var(--muted)">until the next run</div>
    </div>
  </div>
</div>
<style>
  .ul-icon{width:34px;height:34px;border-radius:50%;flex:none;background:var(--surface-3);position:relative}
  .ul-icon.wait::after{content:"";position:absolute;inset:11px;border-radius:50%;background:var(--muted)}
  .ul-icon.queued::after{content:"";position:absolute;inset:11px;border-radius:50%;background:var(--gold);animation:ulpulse 1.4s ease-in-out infinite}
  .ul-icon.run{background:none;border:3px solid var(--surface-3);border-top-color:var(--accent);animation:fw-spin .9s linear infinite}
  .ul-icon.off::after{content:"";position:absolute;inset:11px;border-radius:50%;background:var(--danger)}
  .ul-icon.done::after{content:"";position:absolute;inset:11px;border-radius:50%;background:var(--leaf)}
  @keyframes ulpulse{0%,100%{transform:scale(.7);opacity:.5}50%{transform:scale(1.15);opacity:1}}
  .ul-bar{height:5px;border-radius:3px;background:var(--surface-3);margin-top:.55rem;overflow:hidden}
  #ul-fill{height:100%;width:0;background:var(--accent);transition:width 1s linear}
  #upd-live.running #ul-fill{width:40%!important;animation:ulslide 1.2s ease-in-out infinite alternate}
  @keyframes ulslide{from{transform:translateX(-10%)}to{transform:translateX(160%)}}
</style>
<script>
(function(){
  var L = <?= json_encode($live) ?>, cronOk = <?= $cron_ok ? 'true' : 'false' ?>;
  var skew = L.now * 1000 - Date.now();            // server clock minus this browser's clock
  var start = {last_run: L.last_run, log_id: L.log_id}, wasRunning = L.running, down = false;
  var $ = function(id){ return document.getElementById(id); };
  function ago(t){ var s = Math.round((Date.now() + skew) / 1000 - t); if (s < 60) return 'just now'; if (s < 3600) return Math.floor(s/60) + ' min ago'; return Math.floor(s/3600) + ' h ago'; }
  function render(){
    var now = (Date.now() + skew) / 1000;
    var next = L.next_run; while (next <= now) next += 900;
    var left = Math.max(0, Math.round(next - now));
    var mm = Math.floor(left / 60), ss = left % 60;
    var box = $('upd-live'), icon = $('ul-icon');
    box.classList.toggle('running', !!L.running && !down);
    $('ul-count').textContent = (mm < 10 ? '0' : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;
    $('ul-countlbl').textContent = 'until the next run (' + new Date(next * 1000 - skew).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) + ')';
    $('ul-fill').style.width = L.running ? '' : (100 * (900 - left) / 900) + '%';
    var lastTxt = L.last_run ? 'Last run ' + ago(L.last_run) + (L.result ? ': ' + L.result : '') + '.' : 'The updater has not run on this site yet.';
    if (down) {
      icon.className = 'ul-icon run';
      $('ul-title').textContent = 'Installing the update…';
      $('ul-sub').textContent = 'The site is briefly unavailable while files are swapped. This page reloads by itself when it is back.';
    } else if (L.running) {
      icon.className = 'ul-icon run';
      $('ul-title').textContent = 'The updater is running: ' + (L.step || 'working') + '…';
      $('ul-sub').textContent = 'Started ' + ago(L.last_run) + '. This page follows along by itself.';
    } else if (L.inst_req || L.check_req) {
      icon.className = 'ul-icon queued';
      $('ul-title').textContent = (L.inst_req ? 'Install requested' : 'Check requested') + ': waiting for the next run';
      $('ul-sub').textContent = 'It starts when the countdown reaches zero. ' + lastTxt;
    } else if (!L.last_run && !cronOk) {
      icon.className = 'ul-icon off';
      $('ul-title').textContent = 'The updater has not run yet';
      $('ul-sub').textContent = 'If it is set up, its first run shows here when the countdown reaches zero. If not, see "Turn on the updater" below.';
    } else {
      icon.className = 'ul-icon ' + (L.last_run && L.last_run > now - 1800 ? 'done' : 'wait');
      $('ul-title').textContent = 'Waiting for the next run';
      $('ul-sub').textContent = lastTxt;
    }
  }
  function poll(){
    fetch('system_updates.php?live=1', {cache: 'no-store', credentials: 'same-origin'})
      .then(function(r){ if (r.status === 503) { down = true; return null; } return r.ok ? r.json() : null; })
      .then(function(d){
        if (!d) { render(); return; }
        var wasDown = down, wasQueued = L.inst_req || L.check_req; down = false;
        skew = d.now * 1000 - Date.now(); L = d;
        // A run finished, a request was handled or the history changed: reload to show the result
        if (wasDown || d.log_id !== start.log_id || (wasRunning && !d.running) || (wasQueued && !d.running && !d.inst_req && !d.check_req)) { location.reload(); return; }
        wasRunning = d.running; render();
      })
      .catch(function(){ render(); })
      .then(function(){ setTimeout(poll, (L.running || down) ? 3000 : (L.inst_req || L.check_req) ? 5000 : 15000); });
  }
  render(); setInterval(render, 1000); setTimeout(poll, 3000);
})();
</script>
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
