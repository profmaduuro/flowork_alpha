<?php
/**
 * timb_grades_ws.php — TIMB new grade web service.
 *
 * Consumes:
 *   /newgradeWebservices?newgrade=T3OKQ,T4LD,L5FO
 *
 * This service SUBMITS grades to TIMB and returns a single confirmation for
 * the whole request ("Successful" / "Unsuccessful"). It is not a grade
 * download — the local catalogue in timb_grades stays the source of truth for
 * grading on the floor.
 *
 * Uses the Stop Order credential set. Transport lives in includes/timb_ws.php.
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/timb_ws.php";
require_once "audit.php";
require_login();

$page_title  = "TIMB Grades";
$active_page = "timb_grades_ws";

$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$userid = current_userid();
$msg = $err = '';

$can_use = has_right('admin') || has_right('floor');

timb_ws_schema($conn);

$submission = null;   // results of the submission just made
$pulled     = null;   // results of a grade pull

/* ── Actions ───────────────────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $err = "Security token expired. Please retry.";
        $action = '';
    }
    if ($action && !$can_use) {
        $err = "You do not have permission to submit grades to TIMB.";
        $action = '';
    }

    /* Pull grades out of a stored price matrix -------------------------- */
    if ($action === 'pull_from_matrix') {
        $type = ($_POST['matrix_type'] ?? 'seasonal') === 'daily' ? 'daily' : 'seasonal';
        $d_sql = null;
        if ($type === 'daily') {
            $d = $_POST['matrix_date'] ?? '';
            if (!strtotime($d)) { $err = "Choose a valid matrix date."; $type = ''; }
            else $d_sql = date('Y-m-d', strtotime($d));
        }
        if ($type) {
            $st = timb_ws_import_grades_from_matrix($conn, $spid, $type, $d_sql);
            $pulled = $st + ['source' => $type === 'daily' ? "matrix for " . date('d M Y', strtotime($d_sql)) : "previous season's matrix"];
            if ($st['seen'] === 0) {
                $err = "No price matrix is stored for that selection yet. Fetch it first on the "
                     . "TIMB Price Matrix page, then pull grades from it.";
            } else {
                audit_log($conn, $spid, $userid, 'timb_grades', 'import', null, null,
                          ['from'=>$type, 'date'=>$d_sql, 'seen'=>$st['seen'], 'added'=>$st['added']],
                          'TIMB grade list imported from price matrix');
                $msg = "✓ Read {$st['seen']} grades from the {$pulled['source']} — added {$st['added']} new, "
                     . "{$st['existing']} already in the catalogue.";
            }
        }
    }

    /* Pull grades from a directly configured endpoint -------------------- */
    if ($action === 'pull_direct') {
        $res = timb_ws_fetch_grades($conn, [], ['spid'=>$spid, 'userid'=>$userid]);
        if (!$res['ok']) {
            $err = "✕ " . $res['error'];
        } elseif (!$res['grades']) {
            $err = "The endpoint answered but returned no recognisable grade codes. Check the service log for the raw response.";
        } else {
            $added = 0;
            foreach ($res['grades'] as $g) if (timb_ws_ensure_local_grade($conn, $g)) $added++;
            $pulled = ['seen'=>count($res['grades']), 'added'=>$added,
                       'existing'=>count($res['grades']) - $added, 'grades'=>[],
                       'source'=>'configured endpoint'];
            audit_log($conn, $spid, $userid, 'timb_grades', 'import', null, null,
                      ['from'=>'grades_fetch', 'seen'=>count($res['grades']), 'added'=>$added],
                      'TIMB grade list downloaded');
            $msg = "✓ Received " . count($res['grades']) . " grades — added $added new.";
        }
    }

    if ($action === 'submit_grades') {
        $raw = trim($_POST['grades'] ?? '');
        if ($raw === '') {
            $err = "Enter at least one grade.";
        } else {
            $individually = empty($_POST['batch_together']);
            $res = timb_ws_submit_grades($conn, $raw, $individually, ['spid'=>$spid, 'userid'=>$userid]);
            $invalid = $res['invalid'] ?? [];

            if (!empty($res['results'])) {
                $submission = $res;
                $added_local = 0;
                if (!empty($_POST['add_local'])) {
                    foreach ($res['results'] as $r) {
                        if ($r['accepted'] && timb_ws_ensure_local_grade($conn, $r['grade'])) $added_local++;
                    }
                }
                audit_log($conn, $spid, $userid, 'timb_grade_submissions', 'submit', null, null,
                          ['batch_ref'=>$res['batch_ref'], 'accepted'=>$res['accepted'], 'rejected'=>$res['rejected']],
                          'TIMB new grade submission');

                $skipped = $invalid ? " Not sent (grades must be alphanumeric): " . implode(', ', $invalid) . "." : "";

                if ($res['rejected'] === 0) {
                    $msg = "✓ TIMB accepted all {$res['accepted']} grade(s)."
                         . ($added_local > 0 ? " Added $added_local to the local catalogue." : "") . $skipped;
                } else {
                    $err = "{$res['accepted']} accepted, {$res['rejected']} rejected — see the outcome below."
                         . ($added_local > 0 ? " Added $added_local accepted grade(s) to the local catalogue." : "") . $skipped;
                }
            } else {
                $err = "✕ " . ($res['error'] ?: 'No grades were submitted.');
            }
        }
    }
}

/* ── Page data ─────────────────────────────────────────────────────────── */

$mock_active = timb_ws_is_mock($conn, 'stoporder');

$search = trim($_GET['q'] ?? '');
$gwhere = "WHERE active=1";
if ($search !== '') $gwhere .= " AND description LIKE '%" . $conn->real_escape_string($search) . "%'";
$grades_q = $conn->query("SELECT id, description, datetime FROM timb_grades $gwhere ORDER BY description LIMIT 400");
$grades = ($grades_q && $grades_q !== true) ? $grades_q->fetch_all(MYSQLI_ASSOC) : [];

$gc = $conn->query("SELECT COUNT(*) n FROM timb_grades WHERE active=1");
$grade_count = ($gc && $gc !== true) ? (int)$gc->fetch_assoc()['n'] : 0;

// Matrices already held, as sources for a grade pull.
$mx_q = $conn->query("SELECT matrix_type, matrix_date, COUNT(*) n, MAX(fetched_at) f
                      FROM timb_price_matrix WHERE selling_pointid=$spid
                      GROUP BY matrix_type, matrix_date
                      ORDER BY matrix_type, matrix_date DESC LIMIT 40");
$matrices = ($mx_q && $mx_q !== true) ? $mx_q->fetch_all(MYSQLI_ASSOC) : [];
$has_seasonal = false;
$daily_dates  = [];
foreach ($matrices as $m) {
    if ($m['matrix_type'] === 'seasonal') $has_seasonal = true;
    elseif ($m['matrix_date']) $daily_dates[] = $m;
}
$grades_path = timb_ws_setting($conn, 'timb_grades_path', '');

$sub_q = $conn->query("SELECT s.*, u.username FROM timb_grade_submissions s
                       LEFT JOIN users u ON u.id=s.userid
                       WHERE s.selling_pointid=$spid
                       ORDER BY s.id DESC LIMIT 100");
$subs = ($sub_q && $sub_q !== true) ? $sub_q->fetch_all(MYSQLI_ASSOC) : [];

$log_q = $conn->query("SELECT l.*, u.username FROM timb_service_log l
                       LEFT JOIN users u ON u.id=l.userid
                       WHERE l.selling_pointid=$spid AND l.service='newgrade'
                       ORDER BY l.id DESC LIMIT 40");
$logs = ($log_q && $log_q !== true) ? $log_q->fetch_all(MYSQLI_ASSOC) : [];

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>TIMB Grades</h2>
    <p>Submit new grades to T.I.M.B. for approval</p>
  </div>
  <div>
    <span class="badge <?= $mock_active ? 'badge-gold' : 'badge-green' ?>">
      <?= $mock_active ? '◌ Simulation mode' : '● Live — stop order credentials' ?>
    </span>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<?php if($mock_active):?>
<div class="alert alert-gold">
  <strong>Simulation mode.</strong> No Stop Order credentials are configured, so nothing reaches TIMB.
  Simulated submissions accept anything matching a plausible grade code and reject the rest.
  Set credentials under <a href="timb_growermaster.php?tab=settings">TIMB settings</a> to go live.
</div>
<?php endif;?>

<div class="premium-tabs-nav" data-tabs="timbgr">
  <button type="button" class="premium-tab-btn" data-tab="pull">⬇ Pull Grades from TIMB</button>
  <button type="button" class="premium-tab-btn" data-tab="submit">⬆ Submit New Grades</button>
  <button type="button" class="premium-tab-btn" data-tab="catalogue">📚 Local Catalogue <span class="badge badge-gray"><?=$grade_count?></span></button>
  <button type="button" class="premium-tab-btn" data-tab="history">🕘 Submission History</button>
  <button type="button" class="premium-tab-btn" data-tab="log">🧾 Service Log</button>
</div>

<!-- ══ Pull grades ═════════════════════════════════════════════════════ -->
<div id="panel-pull" class="tab-panel active" data-tabs="timbgr">

<div class="card">
  <div class="card-head"><h3>Where TIMB grades come from</h3></div>
  <div class="card-body" style="font-size:.8rem">
    <p style="margin-bottom:.75rem">
      TIMB publish no grade-download service — across the whole documentation set the only grades
      endpoint is <code>/newgradeWebservices</code>, which <strong>submits</strong> a grade and returns
      "Successful" or "Unsuccessful".
    </p>
    <p style="margin-bottom:0">
      What they do publish is the <strong>price matrix</strong>, and every row of it is a grade plus its
      price. So the matrix is the authoritative grade list: fetch it on the
      <a href="timb_pricematrix.php">TIMB Price Matrix</a> page, then pull the grades out of it here.
      The previous season's matrix takes no parameters and covers a whole season, which makes it the
      best source for a full catalogue refresh.
    </p>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h3>Pull from a stored price matrix</h3>
    <span class="badge badge-gray"><?=count($matrices)?> matrices held</span>
  </div>
  <div class="card-body">
    <?php if(!$matrices):?>
      <div class="alert alert-gold" style="font-size:.78rem;margin-bottom:0">
        No price matrix has been fetched yet. Go to <a href="timb_pricematrix.php">TIMB Price Matrix</a>
        and fetch the previous season's matrix first — then come back here.
      </div>
    <?php else:?>
      <form method="POST">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="pull_from_matrix">
        <div class="form-group">
          <label>Source</label>
          <select name="matrix_type" class="form-control" onchange="document.getElementById('pullDate').style.display=(this.value==='daily'?'block':'none')">
            <option value="seasonal" <?=$has_seasonal?'':'disabled'?>>
              Previous season's matrix <?= $has_seasonal ? '(recommended — full season)' : '— not fetched yet' ?>
            </option>
            <option value="daily" <?=$daily_dates?'':'disabled'?>>
              A specific sale date <?= $daily_dates ? '' : '— none fetched yet' ?>
            </option>
          </select>
        </div>
        <div class="form-group" id="pullDate" style="display:none">
          <label>Sale date</label>
          <select name="matrix_date" class="form-control">
            <?php foreach($daily_dates as $d):?>
              <option value="<?=htmlspecialchars($d['matrix_date'])?>">
                <?=htmlspecialchars(date('d M Y', strtotime($d['matrix_date'])))?> — <?=(int)$d['n']?> grades
              </option>
            <?php endforeach;?>
          </select>
        </div>
        <button class="btn btn-primary" <?=$can_use?'':'disabled'?>>Pull grades into catalogue</button>
      </form>
    <?php endif;?>
  </div>
  <?php if($matrices):?>
  <div class="table-wrap"><table>
    <thead><tr><th>Matrix</th><th class="text-right">Grades</th><th>Fetched</th></tr></thead>
    <tbody>
    <?php foreach($matrices as $m):?>
      <tr>
        <td style="font-size:.78rem">
          <?= $m['matrix_type']==='seasonal'
              ? '<strong>Previous season</strong>'
              : htmlspecialchars(date('d M Y', strtotime($m['matrix_date']))) ?>
        </td>
        <td class="td-mono text-right"><?=(int)$m['n']?></td>
        <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars(date('d M Y H:i', strtotime($m['f'])))?></td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?php endif;?>
</div>

<?php if($pulled):?>
<div class="card">
  <div class="card-head"><h3>Pull result</h3><span class="badge badge-gray"><?=htmlspecialchars($pulled['source'])?></span></div>
  <div class="card-body grid-3" style="text-align:center">
    <div><div style="font-size:1.6rem;font-weight:600"><?=(int)$pulled['seen']?></div><div style="font-size:.72rem;color:var(--muted)">GRADES READ</div></div>
    <div><div style="font-size:1.6rem;font-weight:600;color:var(--gold)"><?=(int)$pulled['added']?></div><div style="font-size:.72rem;color:var(--muted)">ADDED</div></div>
    <div><div style="font-size:1.6rem;font-weight:600;color:var(--muted)"><?=(int)$pulled['existing']?></div><div style="font-size:.72rem;color:var(--muted)">ALREADY HELD</div></div>
  </div>
  <?php if(!empty($pulled['grades'])):?>
  <div class="card-body" style="border-top:1px solid var(--border)">
    <div style="font-size:.72rem;color:var(--muted);margin-bottom:.4rem">Newly added</div>
    <div style="font-size:.75rem;font-family:monospace;line-height:1.8">
      <?php foreach($pulled['grades'] as $g):?><span class="badge badge-green" style="margin-right:.25rem"><?=htmlspecialchars($g)?></span><?php endforeach;?>
    </div>
  </div>
  <?php endif;?>
</div>
<?php endif;?>

<div class="card">
  <div class="card-head">
    <h3>Direct grade endpoint</h3>
    <span class="badge <?= $grades_path ? 'badge-blue' : 'badge-gray' ?>"><?= $grades_path ? htmlspecialchars($grades_path) : 'not configured' ?></span>
  </div>
  <div class="card-body">
    <p style="font-size:.78rem;margin-bottom:.75rem">
      If TIMB give you a grade-download endpoint that is not in the documentation, set its path as
      <code>timb_grades_path</code> in settings and it will run through the same authentication and
      logging as every other service. Until then this stays switched off rather than guessing a URL.
    </p>
    <form method="POST">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="pull_direct">
      <button class="btn btn-ghost" <?= ($can_use && $grades_path) ? '' : 'disabled' ?>>
        <?= $grades_path ? 'Download grade list' : 'No endpoint configured' ?>
      </button>
    </form>
  </div>
</div>

</div><!-- /panel-pull -->

<!-- ══ Submit ══════════════════════════════════════════════════════════ -->
<div id="panel-submit" class="tab-panel" data-tabs="timbgr">

<div class="card">
  <div class="card-head"><h3>Submit new grades</h3><span class="badge badge-gray">POST /newgradeWebservices</span></div>
  <div class="card-body">
    <form method="POST">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="submit_grades">
      <div class="form-group">
        <label>Grade code(s)</label>
        <textarea name="grades" class="form-control" rows="3" placeholder="T3OKQ, T4LD, L5FO"
                  style="text-transform:uppercase" required><?=htmlspecialchars($_POST['grades'] ?? '')?></textarea>
        <small style="color:var(--muted);font-size:.72rem">Separate with commas, spaces or new lines.</small>
      </div>

      <label style="display:block;font-size:.78rem;margin-bottom:.4rem">
        <input type="checkbox" name="add_local" value="1" checked>
        Add accepted grades to the local catalogue (<code>timb_grades</code>) if missing
      </label>
      <label style="display:block;font-size:.78rem;margin-bottom:.75rem">
        <input type="checkbox" name="batch_together" value="1">
        Send all grades in one request
      </label>

      <div class="alert alert-info" style="font-size:.75rem;margin-bottom:1rem">
        <strong>Why one request per grade by default.</strong> TIMB answers a request with a single
        confirmation — <code>[{"confirmation":" Successful"}]</code> — for everything in it. Send three grades
        together and an "Unsuccessful" reply will not say which one was refused. Sending them one at a time
        costs one request each and tells you exactly which grade TIMB rejected.
      </div>

      <button class="btn btn-primary" <?=$can_use?'':'disabled'?>>Submit to TIMB</button>
    </form>
  </div>
</div>

<?php if($submission):?>
<div class="card">
  <div class="card-head">
    <h3>Outcome</h3>
    <span class="badge badge-gray"><?=htmlspecialchars($submission['batch_ref'])?></span>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Grade</th><th>Result</th><th>TIMB confirmation</th><th class="text-right">Sent with</th></tr></thead>
    <tbody>
    <?php foreach($submission['results'] as $r):?>
      <tr>
        <td class="td-mono"><strong><?=htmlspecialchars($r['grade'])?></strong></td>
        <td><?= $r['accepted'] ? '<span class="badge badge-green">Accepted</span>' : '<span class="badge badge-red">Rejected</span>' ?></td>
        <td style="font-size:.75rem"><?=htmlspecialchars($r['confirmation'])?>
          <?= $r['simulated'] ? ' <span class="badge badge-gold" style="font-size:.58rem">sim</span>' : '' ?></td>
        <td class="td-mono text-right" style="font-size:.72rem">
          <?= (int)$r['batch_size'] > 1 ? (int)$r['batch_size'] . ' grades' : 'on its own' ?>
        </td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?php if($submission['rejected'] > 0 && count($submission['results']) > 1 && (int)$submission['results'][0]['batch_size'] > 1):?>
  <div class="card-body" style="border-top:1px solid var(--border);font-size:.75rem;color:var(--danger)">
    ⚠ These grades were sent together, so TIMB's single "Unsuccessful" applies to the whole request —
    the rows above cannot tell you which grade caused it. Re-submit them one at a time to find out.
  </div>
  <?php endif;?>
</div>
<?php endif;?>

</div><!-- /panel-submit -->

<!-- ══ Catalogue ═══════════════════════════════════════════════════════ -->
<div id="panel-catalogue" class="tab-panel" data-tabs="timbgr">
<div class="card">
  <div class="card-head">
    <h3>Local TIMB grade catalogue</h3>
    <span class="badge badge-gray"><?=$grade_count?> active grades</span>
  </div>
  <div class="card-body" style="padding-bottom:.5rem">
    <form method="GET" class="form-row" style="align-items:flex-end;gap:.5rem">
      <input type="hidden" name="tab" value="catalogue">
      <div class="form-group" style="margin-bottom:0;flex:1">
        <input type="text" name="q" class="form-control" value="<?=htmlspecialchars($search)?>" placeholder="Search grade code">
      </div>
      <button class="btn btn-ghost btn-sm">Search</button>
      <?php if($search!==''):?><a href="?tab=catalogue" class="btn btn-ghost btn-sm">Clear</a><?php endif;?>
    </form>
    <p style="font-size:.72rem;color:var(--muted);margin-top:.6rem">
      This catalogue is shared across all selling points and drives grading on the floor.
      <?php if(count($grades) >= 400):?>Showing the first 400 matches.<?php endif;?>
    </p>
  </div>
  <?php if(!$grades):?>
    <div class="card-body text-center text-muted">No grades match.</div>
  <?php else:?>
  <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
    <thead><tr><th>Grade</th><th>Added</th></tr></thead>
    <tbody>
    <?php foreach($grades as $g):?>
      <tr>
        <td class="td-mono"><strong><?=htmlspecialchars($g['description'])?></strong></td>
        <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars(date('d M Y', strtotime($g['datetime'])))?></td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?php endif;?>
</div>
</div><!-- /panel-catalogue -->

<!-- ══ History ═════════════════════════════════════════════════════════ -->
<div id="panel-history" class="tab-panel" data-tabs="timbgr">
<div class="card">
  <div class="card-head"><h3>Submission history</h3><span class="badge badge-gray"><?=count($subs)?> records</span></div>
  <?php if(!$subs):?>
    <div class="card-body text-center text-muted">Nothing submitted yet.</div>
  <?php else:?>
  <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
    <thead><tr><th>When</th><th>Grade</th><th>Result</th><th>Confirmation</th><th>Batch</th><th>User</th></tr></thead>
    <tbody>
    <?php foreach($subs as $s):?>
      <tr>
        <td style="font-size:.7rem;color:var(--muted);white-space:nowrap"><?=htmlspecialchars(date('d M Y H:i', strtotime($s['created_at'])))?></td>
        <td class="td-mono"><strong><?=htmlspecialchars($s['grade'])?></strong></td>
        <td><?= $s['accepted'] ? '<span class="badge badge-green">Accepted</span>' : '<span class="badge badge-red">Rejected</span>' ?>
            <?= $s['simulated'] ? ' <span class="badge badge-gold" style="font-size:.58rem">sim</span>' : '' ?></td>
        <td style="font-size:.72rem"><?=htmlspecialchars((string)$s['confirmation'])?></td>
        <td style="font-size:.68rem;color:var(--muted)" class="td-mono">
          <?=htmlspecialchars((string)$s['batch_ref'])?>
          <?= (int)$s['batch_size'] > 1 ? ' <span class="badge badge-gold" style="font-size:.55rem">×'.(int)$s['batch_size'].'</span>' : '' ?>
        </td>
        <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars((string)($s['username'] ?? ''))?></td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?php endif;?>
</div>
</div><!-- /panel-history -->

<!-- ══ Service log ═════════════════════════════════════════════════════ -->
<div id="panel-log" class="tab-panel" data-tabs="timbgr">
<div class="card">
  <div class="card-head"><h3>New grade service log</h3><span class="badge badge-gray">last <?=count($logs)?> calls</span></div>
  <?php if(!$logs):?>
    <div class="card-body text-center text-muted">No calls yet.</div>
  <?php else:?>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Result</th><th class="text-right">HTTP</th><th class="text-right">Time</th><th>User</th><th></th></tr></thead>
    <tbody>
    <?php foreach($logs as $l):?>
      <tr>
        <td style="font-size:.7rem;color:var(--muted);white-space:nowrap"><?=htmlspecialchars(date('d M H:i:s', strtotime($l['created_at'])))?></td>
        <td style="font-size:.72rem">
          <?php if($l['ok']):?><span class="badge badge-green">OK</span>
          <?php else:?><span class="badge badge-red">FAIL</span>
            <div style="font-size:.66rem;color:var(--danger);margin-top:.25rem"><?=htmlspecialchars((string)$l['error_message'])?></div>
          <?php endif;?>
          <?= $l['simulated'] ? ' <span class="badge badge-gold" style="font-size:.58rem">sim</span>' : '' ?>
        </td>
        <td class="td-mono text-right" style="font-size:.72rem"><?=(int)$l['http_code']?></td>
        <td class="td-mono text-right" style="font-size:.72rem"><?=(int)$l['duration_ms']?>ms<?= (int)$l['attempts']>1 ? ' ·'.(int)$l['attempts'].'×' : '' ?></td>
        <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars((string)($l['username'] ?? ''))?></td>
        <td><button type="button" class="btn btn-ghost btn-sm" style="font-size:.65rem"
                    onclick="var d=document.getElementById('grlog<?=(int)$l['id']?>');d.hidden=!d.hidden">raw</button></td>
      </tr>
      <tr id="grlog<?=(int)$l['id']?>" hidden>
        <td colspan="6" style="background:var(--cream-d);padding:.75rem">
          <div style="font-size:.68rem;color:var(--muted);margin-bottom:.25rem">REQUEST — <?=htmlspecialchars((string)$l['url'])?></div>
          <pre style="font-size:.68rem;white-space:pre-wrap;word-break:break-all;margin:0 0 .6rem"><?=htmlspecialchars((string)$l['request_params'])?></pre>
          <div style="font-size:.68rem;color:var(--muted);margin-bottom:.25rem">RESPONSE</div>
          <pre style="font-size:.68rem;white-space:pre-wrap;word-break:break-all;max-height:280px;overflow:auto;margin:0"><?=htmlspecialchars((string)$l['response_body'])?></pre>
        </td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?php endif;?>
</div>
</div><!-- /panel-log -->

<?php include "includes/layout_end.php"; ?>
