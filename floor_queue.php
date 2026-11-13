<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Floor Queue";
$active_page = "floor_queue";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = (int)($_SESSION['userid'] ?? 0);
$is_admin    = (int)($_SESSION['is_admin'] ?? 0);
$today       = date('Y-m-d');
$err         = '';
$msg         = '';

// ── ENSURE TABLE EXISTS ────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS floor_queue (
      id               INT AUTO_INCREMENT PRIMARY KEY,
      selling_pointid  INT NOT NULL,
      growerid         INT NOT NULL,
      queue_date       DATE NOT NULL,
      queue_position   INT NOT NULL,
      checked_in_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      status           ENUM('waiting','selling','sold','skipped','no_show') DEFAULT 'waiting',
      notes            VARCHAR(255) DEFAULT '',
      INDEX idx_sp_date (selling_pointid, queue_date)
    )
");

// ── AJAX ENDPOINT ─────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $d = $conn->real_escape_string($_GET['date'] ?? $today);

    $rows_q = $conn->query("
        SELECT fq.id, fq.queue_position pos, fq.status,
               fq.notes, fq.checked_in_at,
               g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name, g.id growerid
        FROM floor_queue fq
        JOIN growers g ON g.id=fq.growerid
        WHERE fq.selling_pointid=$spid AND fq.queue_date='$d'
        ORDER BY fq.queue_position ASC
    ");
    $rows = ($rows_q && $rows_q !== true) ? $rows_q->fetch_all(MYSQLI_ASSOC) : [];

    $counts = ['waiting'=>0,'selling'=>0,'sold'=>0,'skipped'=>0,'no_show'=>0];
    foreach ($rows as $r) {
        if (isset($counts[$r['status']])) $counts[$r['status']]++;
    }

    echo json_encode(['rows' => $rows, 'counts' => $counts, 'time' => date('H:i:s')]);
    exit;
}

// ── POST ACTIONS ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CHECK IN: add grower to queue
    if ($action === 'check_in') {
        header('Content-Type: application/json');
        $search = trim($conn->real_escape_string($_POST['search'] ?? ''));
        $notes  = $conn->real_escape_string(trim($_POST['notes'] ?? ''));

        if (!$search) {
            echo json_encode(['error' => 'Please enter a grower number or name.']); exit;
        }

        // Find grower
        $gq = $conn->query("
            SELECT id, grower_num, name, surname
            FROM growers
            WHERE selling_pointid=$spid
              AND (grower_num LIKE '%$search%' OR name LIKE '%$search%' OR surname LIKE '%$search%')
            ORDER BY grower_num LIMIT 1
        ");
        if (!$gq || $gq->num_rows === 0) {
            echo json_encode(['error' => "No grower found matching \"$search\"."]); exit;
        }
        $grower = $gq->fetch_assoc();
        $gid = (int)$grower['id'];

        // Already in queue today?
        $ex = $conn->query("SELECT id FROM floor_queue WHERE selling_pointid=$spid AND growerid=$gid AND queue_date='$today' LIMIT 1");
        if ($ex && $ex->num_rows > 0) {
            echo json_encode(['error' => $grower['grower_num'].' is already in today\'s queue.']); exit;
        }

        // Next position
        $pos_q = $conn->query("SELECT COALESCE(MAX(queue_position),0)+1 pos FROM floor_queue WHERE selling_pointid=$spid AND queue_date='$today'");
        $next_pos = $pos_q ? (int)$pos_q->fetch_assoc()['pos'] : 1;

        $conn->query("
            INSERT INTO floor_queue (selling_pointid, growerid, queue_date, queue_position, status, notes)
            VALUES ($spid, $gid, '$today', $next_pos, 'waiting', '$notes')
        ");
        if ($conn->error) {
            echo json_encode(['error' => 'DB error: '.$conn->error]); exit;
        }

        echo json_encode([
            'ok'    => true,
            'msg'   => $grower['grower_num'].' — '.$grower['name'].' added at position #'.$next_pos.'.',
            'grower' => ['grower_num' => $grower['grower_num'], 'name' => $grower['name'].' '.($grower['surname']??''), 'pos' => $next_pos]
        ]);
        exit;
    }

    // SEARCH GROWER (for check-in dropdown)
    if ($action === 'search_grower') {
        header('Content-Type: application/json');
        $q = $conn->real_escape_string(trim($_POST['q'] ?? ''));
        if (strlen($q) < 2) { echo json_encode([]); exit; }
        $sq = $conn->query("
            SELECT id, grower_num, CONCAT(name,' ',COALESCE(surname,'')) grower_name
            FROM growers
            WHERE selling_pointid=$spid
              AND (grower_num LIKE '%$q%' OR name LIKE '%$q%' OR surname LIKE '%$q%')
            ORDER BY grower_num LIMIT 10
        ");
        $res = ($sq && $sq !== true) ? $sq->fetch_all(MYSQLI_ASSOC) : [];
        echo json_encode($res);
        exit;
    }

    // UPDATE STATUS
    if ($action === 'update_status') {
        header('Content-Type: application/json');
        $qid    = (int)($_POST['qid'] ?? 0);
        $status = $conn->real_escape_string($_POST['status'] ?? '');
        $allowed = ['waiting','selling','sold','skipped','no_show'];
        if (!$qid || !in_array($status, $allowed)) {
            echo json_encode(['error' => 'Invalid parameters.']); exit;
        }
        $conn->query("UPDATE floor_queue SET status='$status' WHERE id=$qid AND selling_pointid=$spid");
        echo json_encode(['ok' => true, 'msg' => 'Status updated to '.ucfirst(str_replace('_',' ',$status)).'.']);
        exit;
    }

    // CLEAR QUEUE (admin only)
    if ($action === 'clear_queue') {
        header('Content-Type: application/json');
        if ($is_admin !== 1) {
            echo json_encode(['error' => 'Access denied. Admins only.']); exit;
        }
        $conn->query("DELETE FROM floor_queue WHERE selling_pointid=$spid AND queue_date='$today'");
        echo json_encode(['ok' => true, 'msg' => "Today's queue cleared (".$conn->affected_rows." entries removed)."]);
        exit;
    }
}

// ── LOAD INITIAL DATA ─────────────────────────────────────────────────────
$queue_q = $conn->query("
    SELECT fq.id, fq.queue_position pos, fq.status,
           fq.notes, fq.checked_in_at,
           g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name, g.id growerid
    FROM floor_queue fq
    JOIN growers g ON g.id=fq.growerid
    WHERE fq.selling_pointid=$spid AND fq.queue_date='$today'
    ORDER BY fq.queue_position ASC
");
$queue_rows = ($queue_q && $queue_q !== true) ? $queue_q->fetch_all(MYSQLI_ASSOC) : [];

$cnt_waiting  = count(array_filter($queue_rows, fn($r)=>$r['status']==='waiting'));
$cnt_selling  = count(array_filter($queue_rows, fn($r)=>$r['status']==='selling'));
$cnt_sold     = count(array_filter($queue_rows, fn($r)=>$r['status']==='sold'));
$cnt_skipped  = count(array_filter($queue_rows, fn($r)=>$r['status']==='skipped'));
$cnt_no_show  = count(array_filter($queue_rows, fn($r)=>$r['status']==='no_show'));

include "includes/layout.php";
?>
<style>
/* ─── Queue-specific styles ─── */
.queue-kpi-strip{
  display:grid;grid-template-columns:repeat(5,1fr);
  gap:.6rem;margin-bottom:1.35rem;
}
.qkpi{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:.9rem 1rem;text-align:center;position:relative;overflow:hidden;
  transition:box-shadow .13s;
}
.qkpi:hover{box-shadow:0 3px 12px rgba(0,0,0,.05)}
.qkpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--qkpi-color,var(--gold))}
.qkpi-val{
  font-family:'DM Sans',sans-serif;font-size:2.04rem;
  color:var(--text);line-height:1;font-weight:600}
.qkpi-lbl{font-size:.78rem;font-weight:500;color:var(--muted);margin-top:.35rem}

/* Status badges */
.sq-badge{
  display:inline-flex;align-items:center;gap:4px;
  padding:2px 8px;border-radius:2px;font-size:.78rem;
  font-weight:500;white-space:nowrap;
}
.sq-waiting {background:var(--surface-2);color:var(--warn-text);border:1px solid #e8c96a}
.sq-selling {background:var(--surface-2);color:var(--ok-text);border:1px solid #9bcb94;animation:sq-pulse 2s infinite}
.sq-sold    {background:var(--surface-2);color:#888;border:1px solid var(--line)}
.sq-skipped {background:var(--warn-tint);color:var(--warn-text);border:1px solid #e0cca0}
.sq-no_show {background:var(--surface-2);color:var(--bad-text);border:1px solid #f5b8b2}
@keyframes sq-pulse{0%,100%{opacity:1}50%{opacity:.6}}

/* Action buttons in table */
.sq-action{
  display:inline-flex;align-items:center;gap:3px;
  padding:2px 7px;border-radius:2px;font-size:.78rem;font-weight:500;
  cursor:pointer;border:1px solid transparent;
  font-family:'DM Sans',sans-serif;transition:all .13s;
  background:none;
}
.sq-action:hover{transform:translateY(-1px)}
.sq-act-sell   {border-color:#9bcb94;color:var(--ok-text);}
.sq-act-sell:hover{background:var(--surface-2);}
.sq-act-sold   {border-color:#aaa;color:#666;}
.sq-act-sold:hover{background:var(--surface-2);}
.sq-act-skip   {border-color:#e0cca0;color:#a08050;}
.sq-act-skip:hover{background:var(--surface-2);}
.sq-act-noshow {border-color:#f5b8b2;color:var(--bad-text);}
.sq-act-noshow:hover{background:var(--surface-2);}
.sq-act-wait   {border-color:#e8c96a;color:var(--warn-text);}
.sq-act-wait:hover{background:var(--surface-2);}

/* Queue layout */
.queue-layout{display:grid;grid-template-columns:1fr 320px;gap:1.25rem;align-items:start}

/* Search suggestions */
.search-suggest-wrap{position:relative}
.search-suggest{
  position:absolute;top:100%;left:0;right:0;z-index:50;
  background:var(--surface);border:1.5px solid var(--gold);border-top:none;
  border-radius:0 0 var(--radius) var(--radius);
  max-height:220px;overflow-y:auto;
  box-shadow:0 8px 24px rgba(0,0,0,.1);
}
.suggest-item{
  padding:.48rem .8rem;font-size:.8rem;cursor:pointer;
  border-bottom:1px solid var(--border);display:flex;gap:.6rem;
  transition:background .1s;
}
.suggest-item:hover{background:var(--gold-bg)}
.suggest-item:last-child{border-bottom:none}
.suggest-num{font-weight:600;color:var(--gold);min-width:60px}

/* Row position badge */
.pos-badge{
  font-family:'DM Sans',sans-serif;font-size:1.02rem;
  color:var(--gold);
  min-width:36px;text-align:center;font-weight:600}

/* Auto-refresh indicator */
.refresh-dot{
  width:6px;height:6px;border-radius:50%;background:#4caf50;
  display:inline-block;animation:ref-pulse 2s infinite;
}
@keyframes ref-pulse{0%,100%{opacity:1}50%{opacity:.3}}

/* Dark theme tweaks */
body.theme-dark .qkpi{background:var(--ink-soft);border-color:rgba(200,149,42,0.15)}
body.theme-dark .sq-waiting{background:#1e1800;color:#e8c96a;border-color:#6e4e10}
body.theme-dark .sq-selling{background:#122012;color:#9bcb94;border-color:#2d5226}
body.theme-dark .sq-sold{background:#1c1c1c;color:#aaa;border-color:#444}
body.theme-dark .sq-skipped{background:#1c160a;color:#c8a060;border-color:#6e5230}
body.theme-dark .sq-no_show{background:#220e0e;color:#e88080;border-color:#5e1818}
body.theme-dark .search-suggest{background:var(--ink-soft);border-color:rgba(200,149,42,.35)}
body.theme-dark .suggest-item:hover{background:rgba(200,149,42,.1)}
body.theme-dark .suggest-item{border-color:rgba(200,149,42,.1)}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Floor Queue</h2>
    <p>Manage grower check-in and selling order · <?= htmlspecialchars($_SESSION['selling_point_name'] ?? '') ?> · <?= date('l, d F Y') ?></p>
  </div>
  <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
    <span style="font-size:.68rem;color:var(--muted);display:flex;align-items:center;gap:4px">
      <span class="refresh-dot"></span> Live · <span id="last-refresh-time">--:--</span>
    </span>
    <button onclick="refreshQueue()" class="btn btn-ghost btn-sm">⟳ Refresh</button>
    <?php if($is_admin===1): ?>
    <button onclick="clearQueue()" class="btn btn-danger btn-sm">🗑 Clear Today's Queue</button>
    <?php endif; ?>
  </div>
</div>

<!-- Flash messages -->
<div id="queue-msg" style="display:none" class="alert alert-success"></div>
<div id="queue-err" style="display:none" class="alert alert-danger"></div>

<!-- KPI Strip -->
<div class="queue-kpi-strip">
  <div class="qkpi" style="--qkpi-color:#c8952a">
    <div class="qkpi-val" id="cnt-waiting"><?= $cnt_waiting ?></div>
    <div class="qkpi-lbl">Waiting</div>
  </div>
  <div class="qkpi" style="--qkpi-color:#3d6b35">
    <div class="qkpi-val" id="cnt-selling"><?= $cnt_selling ?></div>
    <div class="qkpi-lbl">Selling</div>
  </div>
  <div class="qkpi" style="--qkpi-color:#888">
    <div class="qkpi-val" id="cnt-sold"><?= $cnt_sold ?></div>
    <div class="qkpi-lbl">Sold</div>
  </div>
  <div class="qkpi" style="--qkpi-color:#a08050">
    <div class="qkpi-val" id="cnt-skipped"><?= $cnt_skipped ?></div>
    <div class="qkpi-lbl">Skipped</div>
  </div>
  <div class="qkpi" style="--qkpi-color:#c0392b">
    <div class="qkpi-val" id="cnt-noshow"><?= $cnt_no_show ?></div>
    <div class="qkpi-lbl">No Show</div>
  </div>
</div>

<!-- Two-column layout -->
<div class="queue-layout">

  <!-- LEFT: Queue Board -->
  <div class="card">
    <div class="card-head">
      <h3>Queue Board — <span id="board-date"><?= date('d M Y') ?></span></h3>
      <span style="font-size:.68rem;color:var(--muted)" id="queue-total-label">
        <?= count($queue_rows) ?> entries
      </span>
    </div>
    <div class="table-wrap">
      <table id="queue-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Grower #</th>
            <th>Name</th>
            <th>Status</th>
            <th>Checked In</th>
            <th>Notes</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="queue-tbody">
          <?php if(empty($queue_rows)): ?>
          <tr><td colspan="7" class="text-center text-muted" style="padding:2rem">
            No growers in queue today. Use the check-in form to add growers.
          </td></tr>
          <?php else: ?>
          <?php foreach($queue_rows as $qr): ?>
          <?php
            $status = $qr['status'];
            $tin = date('H:i', strtotime($qr['checked_in_at']));
          ?>
          <tr id="qrow-<?= $qr['id'] ?>">
            <td><span class="pos-badge"><?= $qr['pos'] ?></span></td>
            <td><strong><?= htmlspecialchars($qr['grower_num']) ?></strong></td>
            <td><?= htmlspecialchars($qr['grower_name']) ?></td>
            <td><span class="sq-badge sq-<?=htmlspecialchars((string)$status)?>"><?= str_replace('_',' ',ucfirst($status)) ?></span></td>
            <td class="td-muted" style="font-size:.72rem"><?= $tin ?></td>
            <td class="td-muted" style="font-size:.72rem"><?= htmlspecialchars($qr['notes']) ?></td>
            <td><?= renderActions((int)$qr['id'], $status) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- RIGHT: Check-in Form -->
  <div>
    <div class="card">
      <div class="card-head">
        <h3>✅ Check-in Grower</h3>
      </div>
      <div class="card-body">
        <div class="form-group search-suggest-wrap">
          <label for="checkin-search">Grower Number or Name</label>
          <input type="text" id="checkin-search" class="form-control"
                 placeholder="e.g. ZW0001 or John"
                 autocomplete="off"
                 oninput="searchGrower(this.value)">
          <div class="search-suggest" id="search-suggest" style="display:none"></div>
        </div>

        <div class="form-group">
          <label for="checkin-notes">Notes (optional)</label>
          <input type="text" id="checkin-notes" class="form-control"
                 placeholder="e.g. Priority, late arrival, etc.">
        </div>

        <div id="selected-grower-info"
             style="display:none;background:var(--gold-bg);border:1px solid #e8c96a;
                    border-radius:var(--radius);padding:.7rem .9rem;margin-bottom:.9rem">
          <div style="font-size:.78rem;color:var(--muted);margin-bottom:.25rem">Selected Grower</div>
          <div style="font-weight:600;font-size:.88rem" id="selected-grower-name"></div>
          <div style="font-size:.72rem;color:var(--muted)" id="selected-grower-num"></div>
        </div>

        <button class="btn btn-primary" style="width:100%" onclick="checkIn()" id="checkin-btn">
          + Add to Queue
        </button>
      </div>
    </div>

    <!-- Status legend card -->
    <div class="card" style="margin-top:.9rem">
      <div class="card-head"><h3>Status Legend</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:.5rem">
        <?php foreach([
          ['waiting',  'gold',   'Waiting',  'In queue, not yet selling'],
          ['selling',  'green',  'Selling',  'Currently on the auction floor'],
          ['sold',     'gray',   'Sold',     'Sale completed successfully'],
          ['skipped',  '',       'Skipped',  'Passed over, may sell later'],
          ['no_show',  'red',    'No Show',  'Did not arrive on the day'],
        ] as [$s,$c,$l,$desc]): ?>
        <div style="display:flex;align-items:center;gap:.6rem">
          <span class="sq-badge sq-<?= $s ?>"><?= $l ?></span>
          <span style="font-size:.72rem;color:var(--muted)"><?= $desc ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div><!-- end queue-layout -->

<script>
var QUEUE_SPID  = <?= $spid ?>;
var QUEUE_TODAY = '<?= $today ?>';
var selectedGrowerId = null;
var selectedGrowerNum = '';
var suggestOpen = false;
var refreshInterval = null;

// ─── FLASH MESSAGES ───────────────────────────────────────────────────────
function showMsg(msg, type) {
  var mid = type==='error' ? 'queue-err' : 'queue-msg';
  var hid = type==='error' ? 'queue-msg' : 'queue-err';
  var el = document.getElementById(mid);
  var hel = document.getElementById(hid);
  if(hel) hel.style.display='none';
  if(el){
    el.textContent = msg;
    el.style.display='block';
    setTimeout(function(){el.style.display='none'},5000);
  }
}

// ─── STATUS BADGE HTML ───────────────────────────────────────────────────
function statusBadge(status) {
  var labels = {waiting:'Waiting',selling:'Selling',sold:'Sold',skipped:'Skipped',no_show:'No Show'};
  return '<span class="sq-badge sq-'+status+'">'+(labels[status]||status)+'</span>';
}

// ─── ACTION BUTTONS HTML ─────────────────────────────────────────────────
function actionButtons(qid, status) {
  var btns = '';
  if(status !== 'selling') btns += '<button class="sq-action sq-act-sell" onclick="setStatus('+qid+',\'selling\')">Selling</button>';
  if(status !== 'sold')    btns += '<button class="sq-action sq-act-sold" onclick="setStatus('+qid+',\'sold\')">Sold</button>';
  if(status !== 'waiting') btns += '<button class="sq-action sq-act-wait" onclick="setStatus('+qid+',\'waiting\')">Waiting</button>';
  if(status !== 'skipped') btns += '<button class="sq-action sq-act-skip" onclick="setStatus('+qid+',\'skipped\')">Skip</button>';
  if(status !== 'no_show') btns += '<button class="sq-action sq-act-noshow" onclick="setStatus('+qid+',\'no_show\')">No Show</button>';
  return btns;
}

// ─── UPDATE STATUS ───────────────────────────────────────────────────────
function setStatus(qid, status) {
  var fd = new FormData();
  fd.append('action','update_status');
  fd.append('qid', qid);
  fd.append('status', status);
  fetch('floor_queue.php', {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok){ showMsg(d.msg,'ok'); refreshQueue(); }
      else showMsg(d.error||'Error','error');
    }).catch(function(e){showMsg('Network error','error');});
}

// ─── REFRESH QUEUE ───────────────────────────────────────────────────────
function refreshQueue() {
  fetch('floor_queue.php?ajax=1&date='+QUEUE_TODAY+'&_='+Date.now())
    .then(function(r){return r.json();})
    .then(function(d){
      // Update KPIs
      var c = d.counts || {};
      setEl('cnt-waiting', c.waiting||0);
      setEl('cnt-selling', c.selling||0);
      setEl('cnt-sold',    c.sold||0);
      setEl('cnt-skipped', c.skipped||0);
      setEl('cnt-noshow',  c.no_show||0);

      setEl('last-refresh-time', d.time||'--');
      setEl('queue-total-label', (d.rows||[]).length+' entries');

      // Rebuild table
      var tbody = document.getElementById('queue-tbody');
      if(!d.rows || !d.rows.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:2rem">No growers in queue today.</td></tr>';
        return;
      }
      tbody.innerHTML = d.rows.map(function(r) {
        var tin = r.checked_in_at ? r.checked_in_at.substr(11,5) : '--';
        return '<tr id="qrow-'+r.id+'">'+
          '<td><span class="pos-badge">'+r.pos+'</span></td>'+
          '<td><strong>'+r.grower_num+'</strong></td>'+
          '<td>'+r.grower_name+'</td>'+
          '<td>'+statusBadge(r.status)+'</td>'+
          '<td class="td-muted" style="font-size:.72rem">'+tin+'</td>'+
          '<td class="td-muted" style="font-size:.72rem">'+(r.notes||'')+'</td>'+
          '<td>'+actionButtons(r.id, r.status)+'</td>'+
          '</tr>';
      }).join('');
    })
    .catch(function(e){console.error('Queue refresh error:',e)});
}

function setEl(id, val) {
  var el = document.getElementById(id);
  if(el) el.textContent = val;
}

// ─── GROWER SEARCH ───────────────────────────────────────────────────────
var searchTimer = null;
function searchGrower(val) {
  selectedGrowerId = null;
  selectedGrowerNum = '';
  document.getElementById('selected-grower-info').style.display='none';

  clearTimeout(searchTimer);
  if(val.length < 2) {
    document.getElementById('search-suggest').style.display='none';
    return;
  }
  searchTimer = setTimeout(function() {
    var fd = new FormData();
    fd.append('action','search_grower');
    fd.append('q', val);
    fetch('floor_queue.php', {method:'POST', body:fd})
      .then(function(r){return r.json();})
      .then(function(results) {
        var sg = document.getElementById('search-suggest');
        if(!results.length){sg.style.display='none';return;}
        sg.innerHTML = results.map(function(g){
          return '<div class="suggest-item" onclick="selectGrower('+g.id+',\''+g.grower_num+'\',\''+g.grower_name.replace(/'/g,"\\'")+'\')">' +
            '<span class="suggest-num">'+g.grower_num+'</span>' +
            '<span>'+g.grower_name+'</span>'+
            '</div>';
        }).join('');
        sg.style.display='block';
        suggestOpen = true;
      });
  }, 280);
}

function selectGrower(id, num, name) {
  selectedGrowerId = id;
  selectedGrowerNum = num;
  document.getElementById('checkin-search').value = num + ' — ' + name;
  document.getElementById('search-suggest').style.display = 'none';
  suggestOpen = false;
  document.getElementById('selected-grower-info').style.display='block';
  document.getElementById('selected-grower-name').textContent = name;
  document.getElementById('selected-grower-num').textContent = num;
}

// Close suggestions on outside click
document.addEventListener('click', function(e) {
  if(suggestOpen && !e.target.closest('.search-suggest-wrap')) {
    document.getElementById('search-suggest').style.display='none';
    suggestOpen = false;
  }
});

// ─── CHECK IN ────────────────────────────────────────────────────────────
function checkIn() {
  var search = '';
  if(selectedGrowerId) {
    search = selectedGrowerNum;
  } else {
    search = document.getElementById('checkin-search').value.trim();
    // Strip the " — Name" suffix if present
    if(search.includes(' — ')) search = search.split(' — ')[0].trim();
  }
  var notes = document.getElementById('checkin-notes').value.trim();

  if(!search){ showMsg('Enter a grower number or name.','error'); return; }

  var btn = document.getElementById('checkin-btn');
  btn.disabled = true;
  btn.textContent = 'Adding...';

  var fd = new FormData();
  fd.append('action','check_in');
  fd.append('search', search);
  fd.append('notes', notes);

  fetch('floor_queue.php', {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      btn.disabled = false;
      btn.textContent = '+ Add to Queue';
      if(d.ok){
        showMsg(d.msg, 'ok');
        document.getElementById('checkin-search').value = '';
        document.getElementById('checkin-notes').value = '';
        document.getElementById('selected-grower-info').style.display='none';
        selectedGrowerId = null; selectedGrowerNum = '';
        refreshQueue();
      } else {
        showMsg(d.error||'Check-in failed.','error');
      }
    })
    .catch(function(e){
      btn.disabled=false; btn.textContent='+ Add to Queue';
      showMsg('Network error.','error');
    });
}

// ─── CLEAR QUEUE ─────────────────────────────────────────────────────────
function clearQueue() {
  if(!confirm("Clear ALL entries from today's queue? This cannot be undone.")) return;
  var fd = new FormData();
  fd.append('action','clear_queue');
  fetch('floor_queue.php', {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok){ showMsg(d.msg,'ok'); refreshQueue(); }
      else showMsg(d.error||'Error','error');
    });
}

// ─── Allow pressing Enter in search box ──────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  var si = document.getElementById('checkin-search');
  if(si) {
    si.addEventListener('keydown', function(e){
      if(e.key==='Enter'){ e.preventDefault(); checkIn(); }
    });
  }
  var ni = document.getElementById('checkin-notes');
  if(ni) {
    ni.addEventListener('keydown', function(e){
      if(e.key==='Enter'){ e.preventDefault(); checkIn(); }
    });
  }
});

// ─── Auto-refresh every 15 seconds ───────────────────────────────────────
refreshInterval = setInterval(refreshQueue, 15000);

// Initial refresh to sync time display
setTimeout(function(){
  fetch('floor_queue.php?ajax=1&date='+QUEUE_TODAY+'&_='+Date.now())
    .then(function(r){return r.json();})
    .then(function(d){ setEl('last-refresh-time', d.time||'--'); });
}, 500);
</script>

<?php
function renderActions(int $qid, string $status): string {
    $btns = '';
    if($status !== 'selling') $btns .= '<button class="sq-action sq-act-sell" onclick="setStatus('.$qid.',\'selling\')">Selling</button>';
    if($status !== 'sold')    $btns .= '<button class="sq-action sq-act-sold" onclick="setStatus('.$qid.',\'sold\')">Sold</button>';
    if($status !== 'waiting') $btns .= '<button class="sq-action sq-act-wait" onclick="setStatus('.$qid.',\'waiting\')">Waiting</button>';
    if($status !== 'skipped') $btns .= '<button class="sq-action sq-act-skip" onclick="setStatus('.$qid.',\'skipped\')">Skip</button>';
    if($status !== 'no_show') $btns .= '<button class="sq-action sq-act-noshow" onclick="setStatus('.$qid.',\'no_show\')">No Show</button>';
    return $btns;
}
?>

<?php include "includes/layout_end.php"; ?>
