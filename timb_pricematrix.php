<?php
/**
 * timb_pricematrix.php — TIMB price matrix web service.
 *
 * Consumes:
 *   /pricematrixWebservices          grade/price matrix for one sale date
 *   /seasonalpricematrixWebservices  previous season's matrix (no parameters)
 *
 * Both use the Stop Order credential set. Transport lives in includes/timb_ws.php.
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/timb_ws.php";
require_once "audit.php";
require_login();

$page_title  = "TIMB Price Matrix";
$active_page = "timb_pricematrix";

$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$userid = current_userid();
$msg = $err = '';

$can_use = has_right('accounts') || has_right('reports') || has_right('admin');

timb_ws_schema($conn);

$today    = date('Y-m-d');
$view_date = $_GET['date'] ?? $today;
if (!strtotime($view_date)) $view_date = $today;
$view_date = date('Y-m-d', strtotime($view_date));

$last_fetch = null;

/* ── Actions ───────────────────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $err = "Security token expired. Please retry.";
        $action = '';
    }
    if ($action && !$can_use) {
        $err = "You do not have permission to use the TIMB price matrix service.";
        $action = '';
    }

    /* Daily matrix ----------------------------------------------------- */
    if ($action === 'fetch_daily') {
        $d = $_POST['matrix_date'] ?? $today;
        if (!strtotime($d)) {
            $err = "Enter a valid date.";
        } else {
            $d_sql = date('Y-m-d', strtotime($d));
            $res = timb_ws_price_matrix($conn, $d_sql, ['spid'=>$spid, 'userid'=>$userid]);
            $last_fetch = $res;
            if ($res['ok']) {
                if (!$res['matrix']) {
                    $err = "TIMB returned no grades for {$res['matrix_date']}. That date may have no matrix published.";
                } else {
                    $st = timb_ws_save_price_matrix($conn, $spid, $res['matrix'], 'daily', $d_sql, !empty($res['simulated']));
                    audit_log($conn, $spid, $userid, 'timb_price_matrix', 'sync', null, null,
                              ['type'=>'daily', 'date'=>$d_sql, 'grades'=>$st['stored']], 'TIMB price matrix fetch');
                    $msg = "✓ Stored {$st['stored']} grade prices for " . date('d M Y', strtotime($d_sql))
                         . " (sent as {$res['matrix_date']})"
                         . ($st['replaced'] > 0 ? ", replacing {$st['replaced']} previously held" : "")
                         . (!empty($res['simulated']) ? " — simulated" : "") . ".";
                    $view_date = $d_sql;
                }
            } else {
                $err = "✕ " . $res['error'];
            }
        }
    }

    /* Seasonal matrix -------------------------------------------------- */
    if ($action === 'fetch_seasonal') {
        $res = timb_ws_seasonal_price_matrix($conn, ['spid'=>$spid, 'userid'=>$userid]);
        $last_fetch = $res;
        if ($res['ok']) {
            if (!$res['matrix']) {
                $err = "TIMB returned no grades for the previous season's matrix.";
            } else {
                $st = timb_ws_save_price_matrix($conn, $spid, $res['matrix'], 'seasonal', null, !empty($res['simulated']));
                audit_log($conn, $spid, $userid, 'timb_price_matrix', 'sync', null, null,
                          ['type'=>'seasonal', 'grades'=>$st['stored']], 'TIMB seasonal price matrix fetch');
                $msg = "✓ Stored {$st['stored']} grade prices for the previous season"
                     . ($st['replaced'] > 0 ? ", replacing {$st['replaced']} previously held" : "")
                     . (!empty($res['simulated']) ? " — simulated" : "") . ".";
            }
        } else {
            $err = "✕ " . $res['error'];
        }
    }
}

/* ── Page data ─────────────────────────────────────────────────────────── */

$mock_active = timb_ws_is_mock($conn, 'stoporder');

$d_esc = $conn->real_escape_string($view_date);
$daily_q = $conn->query("SELECT * FROM timb_price_matrix
                         WHERE selling_pointid=$spid AND matrix_type='daily' AND matrix_date='$d_esc'
                         ORDER BY price_usd DESC, grade");
$daily = ($daily_q && $daily_q !== true) ? $daily_q->fetch_all(MYSQLI_ASSOC) : [];

$seasonal_q = $conn->query("SELECT * FROM timb_price_matrix
                            WHERE selling_pointid=$spid AND matrix_type='seasonal' AND matrix_date IS NULL
                            ORDER BY price_usd DESC, grade");
$seasonal = ($seasonal_q && $seasonal_q !== true) ? $seasonal_q->fetch_all(MYSQLI_ASSOC) : [];

$season_map = [];
foreach ($seasonal as $s) $season_map[$s['grade']] = (float)$s['price_usd'];

// What the floor actually achieved on this date, per TIMB grade, for comparison.
$actual = [];
$act_q = $conn->query("
    SELECT tg.description AS grade, COUNT(*) bales, SUM(bm.mass) kg,
           AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END) avg_usd
    FROM sold_bales sb
    JOIN tickets tk     ON tk.id = sb.ticketsid
    JOIN bale_junus bj  ON bj.id = tk.bale_junusid
    JOIN bale_mass bm   ON bm.id = bj.bale_massid
    JOIN growers g      ON g.id  = bj.growerid
    JOIN timb_grades tg ON tg.id = sb.timb_gradesid
    WHERE DATE(sb.created_at)='$d_esc' AND g.selling_pointid=$spid
    GROUP BY tg.description");
if ($act_q && $act_q !== true) {
    while ($a = $act_q->fetch_assoc()) $actual[strtoupper($a['grade'])] = $a;
}

$dates_q = $conn->query("SELECT matrix_date, COUNT(*) n, MAX(fetched_at) f, MAX(simulated) sim
                         FROM timb_price_matrix
                         WHERE selling_pointid=$spid AND matrix_type='daily'
                         GROUP BY matrix_date ORDER BY matrix_date DESC LIMIT 60");
$dates = ($dates_q && $dates_q !== true) ? $dates_q->fetch_all(MYSQLI_ASSOC) : [];

$log_q = $conn->query("SELECT l.*, u.username FROM timb_service_log l
                       LEFT JOIN users u ON u.id=l.userid
                       WHERE l.selling_pointid=$spid AND l.service IN ('pricematrix','seasonalpricematrix')
                       ORDER BY l.id DESC LIMIT 40");
$logs = ($log_q && $log_q !== true) ? $log_q->fetch_all(MYSQLI_ASSOC) : [];

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>TIMB Price Matrix</h2>
    <p>Reference grade prices published by T.I.M.B.</p>
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
  <strong>Simulation mode.</strong> No Stop Order credentials are configured, so fetches return generated prices
  across your local grade catalogue rather than anything from TIMB. Stored rows are marked
  <span class="badge badge-gold" style="font-size:.72rem">simulated</span> and can be replaced by re-fetching.
  Set credentials under <a href="timb_growermaster.php?tab=settings">TIMB settings</a> to go live.
</div>
<?php endif;?>

<div class="premium-tabs-nav" data-tabs="timbpm">
  <button type="button" class="premium-tab-btn" data-tab="daily">📅 Daily Matrix <span class="badge badge-gray"><?=count($daily)?></span></button>
  <button type="button" class="premium-tab-btn" data-tab="seasonal">🗓 Previous Season <span class="badge badge-gray"><?=count($seasonal)?></span></button>
  <button type="button" class="premium-tab-btn" data-tab="history">🕘 Held Dates</button>
  <button type="button" class="premium-tab-btn" data-tab="log">🧾 Service Log</button>
</div>

<!-- ══ Daily ═══════════════════════════════════════════════════════════ -->
<div id="panel-daily" class="tab-panel active" data-tabs="timbpm">

<div class="card">
  <div class="card-head"><h3>Fetch matrix for a date</h3><span class="badge badge-gray">POST /pricematrixWebservices</span></div>
  <div class="card-body">
    <form method="POST" class="form-row" style="align-items:flex-end;gap:.5rem">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="fetch_daily">
      <div class="form-group" style="margin-bottom:0">
        <label>Sale date</label>
        <input type="date" name="matrix_date" class="form-control" value="<?=htmlspecialchars($view_date)?>" required>
        <small style="color:var(--muted);font-size:.72rem">
          Sent to TIMB as <code><?=htmlspecialchars(timb_ws_matrix_date($conn, $view_date))?></code>
        </small>
      </div>
      <button class="btn btn-primary" <?=$can_use?'':'disabled'?>>Fetch from TIMB</button>
    </form>
    <p style="font-size:.72rem;color:var(--muted);margin-top:.75rem">
      TIMB serves the matrix one date at a time. Re-fetching a date replaces the prices held for it.
    </p>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h3>Matrix for <?=htmlspecialchars(date('d M Y', strtotime($view_date)))?>
      <?php if($daily && !empty($daily[0]['simulated'])):?>
        <span class="badge badge-gold">SIMULATED DATA</span>
      <?php endif;?>
    </h3>
    <div>
      <form method="GET" style="display:inline-flex;gap:.4rem;align-items:center">
        <input type="hidden" name="tab" value="daily">
        <input type="date" name="date" class="form-control" style="width:auto;padding:.3rem .5rem;font-size:.75rem" value="<?=htmlspecialchars($view_date)?>">
        <button class="btn btn-ghost btn-sm">View</button>
      </form>
    </div>
  </div>
  <?php if(!$daily):?>
    <div class="card-body text-center text-muted">
      No matrix held for this date. Fetch it above.
    </div>
  <?php else:?>
  <?php if(!empty($daily[0]['simulated'])):?>
    <div class="card-body" style="border-bottom:1px solid var(--border)">
      <div class="alert alert-gold" style="margin:0;font-size:.78rem">
        <strong>These prices did not come from TIMB.</strong> They were generated in simulation mode
        because credentials were not configured at the time of the fetch. Re-fetch this date once the
        connection to TIMB works — the stored rows will be replaced.
      </div>
    </div>
  <?php endif;?>
  <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
    <thead><tr>
      <th>Grade</th><th class="text-right">TIMB price</th><th class="text-right">US$/kg</th>
      <th class="text-right">Prev. season</th><th class="text-right">Δ vs season</th>
      <th class="text-right">Sold here</th><th class="text-right">Achieved US$/kg</th><th class="text-right">Δ vs matrix</th>
    </tr></thead>
    <tbody>
    <?php foreach($daily as $row):
      $grade = strtoupper($row['grade']);
      $matrix_usd = (float)$row['price_usd'];
      $season_usd = isset($season_map[$grade]) ? $season_map[$grade] : null;
      $act = isset($actual[$grade]) ? $actual[$grade] : null;
      $act_usd = $act ? (float)$act['avg_usd'] : null;
    ?>
      <tr>
        <td class="td-mono"><strong><?=htmlspecialchars($grade)?></strong></td>
        <td class="td-mono text-right" style="color:var(--muted);font-size:.72rem"><?=htmlspecialchars($row['price_raw'])?></td>
        <td class="td-mono text-right"><strong><?=number_format($matrix_usd, 2)?></strong></td>
        <td class="td-mono text-right" style="font-size:.75rem">
          <?= $season_usd !== null ? number_format($season_usd, 2) : '<span style="color:var(--muted)">—</span>' ?>
        </td>
        <td class="td-mono text-right" style="font-size:.75rem">
          <?php if($season_usd !== null && $season_usd > 0):
            $d = $matrix_usd - $season_usd; ?>
            <span style="color:<?= $d >= 0 ? 'var(--leaf)' : 'var(--danger)' ?>">
              <?= ($d >= 0 ? '+' : '') . number_format($d, 2) ?>
            </span>
          <?php else:?><span style="color:var(--muted)">—</span><?php endif;?>
        </td>
        <td class="td-mono text-right" style="font-size:.75rem">
          <?= $act ? number_format((int)$act['bales']) . ' bales' : '<span style="color:var(--muted)">—</span>' ?>
        </td>
        <td class="td-mono text-right" style="font-size:.75rem">
          <?= $act_usd !== null ? number_format($act_usd, 2) : '<span style="color:var(--muted)">—</span>' ?>
        </td>
        <td class="td-mono text-right" style="font-size:.75rem">
          <?php if($act_usd !== null && $matrix_usd > 0):
            $d = $act_usd - $matrix_usd; ?>
            <span style="color:<?= $d >= 0 ? 'var(--leaf)' : 'var(--danger)' ?>">
              <?= ($d >= 0 ? '+' : '') . number_format($d, 2) ?>
            </span>
          <?php else:?><span style="color:var(--muted)">—</span><?php endif;?>
        </td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <div class="card-body" style="border-top:1px solid var(--border);font-size:.72rem;color:var(--muted)">
    TIMB sends prices as whole numbers — <code>235</code> means US$2.35/kg. Flowork applies the same
    "greater than 10 means cents" rule it uses for sale prices. <strong>Achieved</strong> columns compare against
    what this floor actually sold on this date, by TIMB grade.
  </div>
  <?php endif;?>
</div>
</div><!-- /panel-daily -->

<!-- ══ Seasonal ════════════════════════════════════════════════════════ -->
<div id="panel-seasonal" class="tab-panel" data-tabs="timbpm">
<div class="card">
  <div class="card-head"><h3>Previous season's matrix</h3><span class="badge badge-gray">POST /seasonalpricematrixWebservices</span></div>
  <div class="card-body">
    <p style="font-size:.8rem;margin-bottom:1rem">
      The seasonal matrix takes no parameters — TIMB returns the whole previous season in one call.
      Fetching replaces the full set held for this floor.
    </p>
    <form method="POST" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Fetching…'">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="fetch_seasonal">
      <button class="btn btn-primary" <?=$can_use?'':'disabled'?>>Fetch previous season</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h3>Previous season prices</h3>
    <span class="badge badge-gray"><?=count($seasonal)?> grades<?= $seasonal ? ' · fetched ' . htmlspecialchars(date('d M Y H:i', strtotime($seasonal[0]['fetched_at']))) : '' ?></span>
  </div>
  <?php if(!$seasonal):?>
    <div class="card-body text-center text-muted">Not fetched yet.</div>
  <?php else:?>
  <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
    <thead><tr><th>Grade</th><th class="text-right">TIMB price</th><th class="text-right">US$/kg</th></tr></thead>
    <tbody>
    <?php foreach($seasonal as $row):?>
      <tr>
        <td class="td-mono"><strong><?=htmlspecialchars($row['grade'])?></strong></td>
        <td class="td-mono text-right" style="color:var(--muted);font-size:.72rem"><?=htmlspecialchars($row['price_raw'])?></td>
        <td class="td-mono text-right"><strong><?=number_format((float)$row['price_usd'], 2)?></strong></td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?php endif;?>
</div>
</div><!-- /panel-seasonal -->

<!-- ══ History ═════════════════════════════════════════════════════════ -->
<div id="panel-history" class="tab-panel" data-tabs="timbpm">
<div class="card">
  <div class="card-head"><h3>Dates held for this floor</h3><span class="badge badge-gray"><?=count($dates)?> dates</span></div>
  <?php if(!$dates):?>
    <div class="card-body text-center text-muted">No daily matrices stored yet.</div>
  <?php else:?>
  <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
    <thead><tr><th>Sale date</th><th class="text-right">Grades</th><th>Fetched</th><th></th></tr></thead>
    <tbody>
    <?php foreach($dates as $d):?>
      <tr>
        <td class="td-mono"><?=htmlspecialchars(date('d M Y', strtotime($d['matrix_date'])))?>
          <?= !empty($d['sim']) ? ' <span class="badge badge-gold" style="font-size:.58rem">simulated</span>' : '' ?></td>
        <td class="td-mono text-right"><?=(int)$d['n']?></td>
        <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars(date('d M Y H:i', strtotime($d['f'])))?></td>
        <td><a href="?tab=daily&date=<?=urlencode($d['matrix_date'])?>" class="btn btn-ghost btn-sm" style="font-size:.65rem">View</a></td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?php endif;?>
</div>
</div><!-- /panel-history -->

<!-- ══ Service log ═════════════════════════════════════════════════════ -->
<div id="panel-log" class="tab-panel" data-tabs="timbpm">
<div class="card">
  <div class="card-head"><h3>Price matrix service log</h3><span class="badge badge-gray">last <?=count($logs)?> calls</span></div>
  <?php if(!$logs):?>
    <div class="card-body text-center text-muted">No calls yet.</div>
  <?php else:?>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Service</th><th>Result</th><th class="text-right">HTTP</th><th class="text-right">Rows</th><th class="text-right">Time</th><th>User</th><th></th></tr></thead>
    <tbody>
    <?php foreach($logs as $l):?>
      <tr>
        <td style="font-size:.7rem;color:var(--muted);white-space:nowrap"><?=htmlspecialchars(date('d M H:i:s', strtotime($l['created_at'])))?></td>
        <td style="font-size:.72rem"><?=htmlspecialchars($l['service'])?><?= $l['simulated'] ? ' <span class="badge badge-gold" style="font-size:.58rem">sim</span>' : '' ?></td>
        <td style="font-size:.72rem">
          <?php if($l['ok']):?><span class="badge badge-green">OK</span>
          <?php else:?><span class="badge badge-red">FAIL</span>
            <div style="font-size:.66rem;color:var(--danger);margin-top:.25rem"><?=htmlspecialchars((string)$l['error_message'])?></div>
          <?php endif;?>
        </td>
        <td class="td-mono text-right" style="font-size:.72rem"><?=(int)$l['http_code']?></td>
        <td class="td-mono text-right" style="font-size:.72rem"><?=(int)$l['rows_returned']?></td>
        <td class="td-mono text-right" style="font-size:.72rem"><?=(int)$l['duration_ms']?>ms<?= (int)$l['attempts']>1 ? ' ·'.(int)$l['attempts'].'×' : '' ?></td>
        <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars((string)($l['username'] ?? ''))?></td>
        <td><button type="button" class="btn btn-ghost btn-sm" style="font-size:.65rem"
                    onclick="var d=document.getElementById('pmlog<?=(int)$l['id']?>');d.hidden=!d.hidden">raw</button></td>
      </tr>
      <tr id="pmlog<?=(int)$l['id']?>" hidden>
        <td colspan="8" style="background:var(--cream-d);padding:.75rem">
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
