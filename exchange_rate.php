<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "Exchange Rate";
$active_page = "exchange_rate";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();
$seasonid    = current_season_id();
$msg=$err='';

$conn->query("CREATE TABLE IF NOT EXISTS exchange_rate (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT DEFAULT 0,
    seasonid INT DEFAULT 0,
    selling_pointid INT DEFAULT 0,
    sell_date DATE NOT NULL,
    start_of_dayid INT DEFAULT 0,
    amount DECIMAL(14,4) NOT NULL,
    active TINYINT DEFAULT 1,
    source VARCHAR(50) DEFAULT 'manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (sell_date)
)");

// ── Migrations: add missing columns to existing tables ───────────────────────
$_cols = $conn->query("SHOW COLUMNS FROM exchange_rate");
$_existing = [];
if ($_cols && $_cols !== true) while ($c = $_cols->fetch_assoc()) $_existing[] = $c['Field'];
if (!in_array('userid',     $_existing)) $conn->query("ALTER TABLE exchange_rate ADD COLUMN userid INT DEFAULT 0");
if (!in_array('seasonid',   $_existing)) $conn->query("ALTER TABLE exchange_rate ADD COLUMN seasonid INT DEFAULT 0");
if (!in_array('created_at', $_existing)) $conn->query("ALTER TABLE exchange_rate ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
if (in_array('rate', $_existing))        $conn->query("ALTER TABLE exchange_rate DROP COLUMN rate");
// Ensure start_of_dayid is INT (was VARCHAR in some installs)
$conn->query("ALTER TABLE exchange_rate MODIFY COLUMN start_of_dayid INT DEFAULT 0");

$conn->query("CREATE TABLE IF NOT EXISTS rate_fetch_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fetch_date DATE NOT NULL,
    rate_fetched DECIMAL(14,4) DEFAULT NULL,
    source VARCHAR(100) DEFAULT '',
    raw_response TEXT DEFAULT '',
    status ENUM('success','failed','no_data') DEFAULT 'success',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$today = date('Y-m-d');

// Auto-fetch RBZ rate (called via AJAX or manual)
function fetch_rbz_rate($conn) {
    // Try RBZ official interbank rate API (JSON feed)
    $sources = [
        ['url'=>'https://wapi.rbz.co.zw/rates', 'parser'=>'rbz_api'],
        ['url'=>'https://www.rbz.co.zw/documents/Exchange_Rates/interbank.json','parser'=>'rbz_json'],
    ];

    $rate = null;
    $source_used = '';
    $raw = '';

    foreach ($sources as $src) {
        $ctx = stream_context_create(['http'=>[
            'timeout'=>8,
            'user_agent'=>'Flowork/1.0',
            'ignore_errors'=>true,
        ]]);
        $resp = @file_get_contents($src['url'], false, $ctx);
        if (!$resp) continue;
        $raw = substr($resp, 0, 2000);

        // Try to find ZiG/USD or USD/ZiG rate in JSON
        $data = @json_decode($resp, true);
        if ($data) {
            // Search for ZiG rate in various structures
            array_walk_recursive($data, function($v, $k) use (&$rate) {
                if ($rate) return;
                $kl = strtolower($k);
                if ((strpos($kl,'zig')!==false || strpos($kl,'zwg')!==false || strpos($kl,'usd')!==false)
                    && is_numeric($v) && $v > 1 && $v < 10000) {
                    $rate = (float)$v;
                }
            });
        }

        if (!$rate) {
            // Try regex for common patterns: "ZiG":"26.5" or "USD/ZWG":26.5
            if (preg_match('/(?:zig|zwg)["\s:]+([0-9]+\.?[0-9]*)/i', $resp, $m)) {
                $rate = (float)$m[1];
            } elseif (preg_match('/usd["\s:]+([0-9]+\.?[0-9]*)/i', $resp, $m) && $m[1] > 1) {
                $rate = (float)$m[1];
            }
        }

        if ($rate && $rate > 1) {
            $source_used = $src['url'];
            break;
        }
    }

    $status = $rate ? 'success' : 'failed';
    $raw_esc = $conn->real_escape_string(substr($raw,0,1000));
    $src_esc = $conn->real_escape_string($source_used);
    $conn->query("INSERT INTO rate_fetch_log (fetch_date,rate_fetched,source,raw_response,status)
        VALUES ('$today',".($rate?$rate:'NULL').",'$src_esc','$raw_esc','$status')");

    return ['rate'=>$rate,'source'=>$source_used,'status'=>$status];
}

// AJAX fetch endpoint
if (isset($_GET['fetch_rate'])) {
    header('Content-Type: application/json');
    $result = fetch_rbz_rate($conn);
    echo json_encode($result);
    exit;
}

// Handle manual rate set
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    if ($action==='set_rate') {
        $rate     = (float)($_POST['rate'] ?? 0);
        $rate_date= $conn->real_escape_string(trim($_POST['rate_date'] ?? $today));
        $source   = $conn->real_escape_string($_POST['source'] ?? 'manual');
        if ($rate <= 0) { $err = "Invalid rate."; }
        else {
            // Get active start_of_day id for this season
            $sod_q = $conn->query("SELECT id FROM start_of_day WHERE seasonid=$seasonid AND active=1 ORDER BY id DESC LIMIT 1");
            $sod_id = ($sod_q && $sod_q !== true && $sod_q->num_rows > 0) ? (int)$sod_q->fetch_assoc()['id'] : 0;
            $conn->query("UPDATE exchange_rate SET active=0 WHERE selling_pointid=$spid");
            if ($sod_id) $conn->query("UPDATE exchange_rate SET active=0 WHERE start_of_dayid=$sod_id");
            $conn->query("INSERT INTO exchange_rate (userid,seasonid,selling_pointid,sell_date,start_of_dayid,amount,active,source)
                VALUES ($userid,$seasonid,$spid,'$rate_date',$sod_id,$rate,1,'$source')");
            $conn->query("INSERT INTO event_notifications (event_type, message) VALUES ('rate_updated', 'Active Exchange Rate updated to " . $rate . " ZiG/USD')");
            audit_log($conn,$spid,$userid,'exchange_rate','update',null,null,['rate'=>$rate,'date'=>$rate_date,'source'=>$source]);
            $msg = "✓ Rate set to $rate ZiG/USD for $rate_date.";
        }
    }
}

// Current rate
// Load via proper start_of_day join (authoritative)
$sod_q = $conn->query("SELECT sd.id, sd.description sell_date, sd.active,
    er.amount zig_rate, er.id er_id, er.created_at rate_set_at
    FROM start_of_day sd
    LEFT JOIN exchange_rate er ON er.start_of_dayid=sd.id AND er.active=1
    WHERE sd.seasonid=$seasonid
    ORDER BY sd.id DESC LIMIT 1");
$active_sod = ($sod_q && $sod_q !== true && $sod_q->num_rows > 0) ? $sod_q->fetch_assoc() : null;

// Fallback: also check old selling_pointid-based rate
$er_q = $conn->query("SELECT * FROM exchange_rate WHERE selling_pointid=$spid AND active=1 ORDER BY id DESC LIMIT 1");
$current_rate = ($er_q&&$er_q!==true)?$er_q->fetch_assoc():null;
// Prefer the start_of_day linked rate
if ($active_sod && $active_sod['zig_rate'])
    $current_rate = ['amount'=>$active_sod['zig_rate'],'sell_date'=>$active_sod['sell_date'],
                     'source'=>$current_rate['source']??'manual','created_at'=>$current_rate['created_at']??$active_sod['rate_set_at']??null];

// Rate history
$history_q = $conn->query("SELECT * FROM exchange_rate WHERE selling_pointid=$spid ORDER BY id DESC LIMIT 30");
$history = ($history_q&&$history_q!==true)?$history_q->fetch_all(MYSQLI_ASSOC):[];

// Fetch log
$fetch_log_q = $conn->query("SELECT * FROM rate_fetch_log ORDER BY id DESC LIMIT 20");
$fetch_log = ($fetch_log_q&&$fetch_log_q!==true)?$fetch_log_q->fetch_all(MYSQLI_ASSOC):[];

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Exchange Rate</h2>
    <p>ZiG / USD rate management — manual or auto-fetch from RBZ</p>
  </div>
  <div style="display:flex;gap:.5rem">
    <button id="fetch-btn" class="btn btn-ghost" onclick="autoFetch()">
      🔄 Fetch RBZ Rate
    </button>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<?php
// ── Status banner: show selling day + rate state clearly ──────────────────
$day_open   = $active_sod && $active_sod['active'] == 1;
$rate_set   = $active_sod && !empty($active_sod['zig_rate']);
$both_set   = $day_open && $rate_set;
?>
<?php if($both_set):?>
<div class="alert" style="background:rgba(61,107,53,.1);border-left:4px solid var(--leaf);padding:.85rem 1rem;display:flex;align-items:center;gap:1rem;margin-bottom:1rem">
  <span style="font-size:1.4rem">✅</span>
  <div>
    <strong>Selling day is open and exchange rate is set</strong><br>
    <span style="font-size:.78rem;color:var(--muted)">
      Date: <strong><?=htmlspecialchars($active_sod['sell_date'])?></strong> &nbsp;·&nbsp;
      Rate: <strong><?=number_format((float)$active_sod['zig_rate'],4)?> ZiG/USD</strong> &nbsp;·&nbsp;
      Set at: <?=date('d M Y H:i',strtotime($active_sod['rate_set_at'] ?? 'now'))?>
      &nbsp;·&nbsp; Start of Day ID: <code><?=$active_sod['id']?></code>
    </span>
  </div>
  <a href="day_control.php" class="btn btn-ghost btn-sm" style="margin-left:auto;white-space:nowrap">Manage Day →</a>
</div>
<?php elseif($day_open && !$rate_set):?>
<div class="alert" style="background:rgba(200,149,42,.1);border-left:4px solid var(--gold);padding:.85rem 1rem;margin-bottom:1rem">
  ⚠ <strong>Selling day is open</strong> (<?=htmlspecialchars($active_sod['sell_date'])?>) but <strong>no exchange rate has been set yet</strong>.
  Set the ZiG rate below before processing any sales today.
</div>
<?php else:?>
<div class="alert" style="background:rgba(200,42,42,.08);border-left:4px solid var(--danger);padding:.85rem 1rem;margin-bottom:1rem">
  ❌ <strong>No selling day is open and no exchange rate is active.</strong>
  Go to <a href="day_control.php" style="color:var(--gold)"><strong>Day Control</strong></a> to open today's selling day first,
  then set the exchange rate here.
</div>
<?php endif;?>

<div class="premium-tabs-nav" data-tabs="fxrate">
  <button type="button" class="premium-tab-btn active" data-tab="current">💱 Current &amp; Set</button>
  <button type="button" class="premium-tab-btn" data-tab="history">📋 Rate History</button>
  <button type="button" class="premium-tab-btn" data-tab="log">🔄 Auto-Fetch Log</button>
</div>

<!-- Current rate -->
<div id="panel-current" class="tab-panel active" data-tabs="fxrate">
<div class="card" style="border-top:3px solid var(--gold)">
<div class="card-head">
  <h3>Current Active Rate</h3>
  <?php if($current_rate):?>
  <span class="badge badge-green" style="font-size:.75rem">
    <?=number_format((float)$current_rate['amount'],4)?> ZiG/USD
  </span>
  <?php else:?>
  <span class="badge badge-red" style="font-size:.72rem">No rate set</span>
  <?php endif;?>
</div>
<?php if($current_rate):?>
<div style="display:flex;gap:2rem;padding:.75rem 1.15rem;font-size:.82rem;background:var(--cream-d);border-bottom:1px solid var(--border);flex-wrap:wrap">
  <div><span style="color:var(--muted)">Rate:</span> <strong><?=number_format((float)$current_rate['amount'],4)?> ZiG per USD</strong></div>
  <div><span style="color:var(--muted)">For date:</span> <strong><?=date('d M Y',strtotime($current_rate['sell_date']))?></strong></div>
  <div><span style="color:var(--muted)">Source:</span> <?=htmlspecialchars($current_rate['source']??'manual')?></div>
  <div><span style="color:var(--muted)">Set at:</span> <?=date('d M H:i',strtotime($current_rate['created_at']))?></div>
</div>
<?php endif;?>
<div class="card-body">
  <div id="fetch-result" style="margin-bottom:.75rem;display:none" class="alert alert-success"></div>
  <form method="POST" id="rate-form">
  <input type="hidden" name="action" value="set_rate">
  <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="margin:0;flex:1;min-width:160px">
      <label>ZiG/USD Rate</label>
      <input type="number" name="rate" id="rate-input" class="form-control" step="0.0001" min="0.0001"
             value="<?=number_format((float)($current_rate['amount']??0),4)?>" placeholder="e.g. 26.5000" required>
    </div>
    <div class="form-group" style="margin:0">
      <label>For Date</label>
      <input type="date" name="rate_date" class="form-control" value="<?=$today?>">
    </div>
    <input type="hidden" name="source" id="rate-source" value="manual">
    <button type="submit" class="btn btn-primary">Set Rate</button>
  </div>
  </form>
</div>
</div>
</div><!-- /panel-current -->

<!-- Rate history -->
<div id="panel-history" class="tab-panel" data-tabs="fxrate">
<div class="card">
<div class="card-head"><h3>Rate History</h3></div>
<div class="table-wrap" style="max-height:400px;overflow-y:auto"><table>
<thead><tr><th>Date</th><th class="text-right">Rate ZiG/USD</th><th>Source</th><th>Status</th></tr></thead>
<tbody>
<?php if(empty($history)):?>
<tr><td colspan="4" class="text-center text-muted" style="padding:1.5rem">No rate history yet.</td></tr>
<?php else: foreach($history as $r):?>
<tr>
  <td style="font-size:.78rem"><?=date('d M Y',strtotime($r['sell_date']))?></td>
  <td class="td-mono text-right fw-500"><?=number_format((float)$r['amount'],4)?></td>
  <td style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($r['source']??'manual')?></td>
  <td>
    <?php if($r['active']):?>
    <span class="badge badge-green" style="font-size:.58rem">Active</span>
    <?php else:?>
    <span class="badge badge-gray" style="font-size:.58rem">Old</span>
    <?php endif;?>
  </td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table></div>
</div>
</div><!-- /panel-history -->

<!-- Fetch log -->
<div id="panel-log" class="tab-panel" data-tabs="fxrate">
<div class="card">
<div class="card-head"><h3>Auto-Fetch Log</h3></div>
<div class="table-wrap" style="max-height:400px;overflow-y:auto"><table>
<thead><tr><th>Date/Time</th><th class="text-right">Rate</th><th>Status</th></tr></thead>
<tbody>
<?php if(empty($fetch_log)):?>
<tr><td colspan="3" class="text-center text-muted" style="padding:1.5rem">
  No fetch attempts yet. Click <strong>Fetch RBZ Rate</strong> above to try.
</td></tr>
<?php else: foreach($fetch_log as $fl):?>
<tr>
  <td style="font-size:.72rem"><?=date('d M H:i',strtotime($fl['created_at']))?></td>
  <td class="td-mono text-right"><?=$fl['rate_fetched']?number_format((float)$fl['rate_fetched'],4):'—'?></td>
  <td><span class="badge <?=$fl['status']==='success'?'badge-green':'badge-red'?>" style="font-size:.58rem"><?=strtoupper($fl['status'])?></span></td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table></div>
<div class="card-body" style="padding:.6rem 1rem;font-size:.72rem;color:var(--muted);border-top:1px solid var(--border)">
  Auto-fetch attempts the RBZ interbank rate feed. If unavailable, enter the rate manually from
  <a href="https://www.rbz.co.zw/index.php/financial-markets/exchange-rates" target="_blank">rbz.co.zw</a>.
  <br>For daily automation add to cron: <code style="font-size:.68rem">0 8 * * * curl "<?=htmlspecialchars('https://yourdomain.com/flowork/exchange_rate.php?fetch_rate=1&token='.($current_rate?'':'set_token_here'))?>"`</code>
</div>
</div>
</div><!-- /panel-log -->

<script>
async function autoFetch() {
    var btn = document.getElementById('fetch-btn');
    var res = document.getElementById('fetch-result');
    btn.disabled = true;
    btn.textContent = '⟳ Fetching...';
    fw.barStart('Fetching RBZ rate...');
    try {
        var data = await fw.get('exchange_rate.php', { fetch_rate: 1 });
        fw.barDone();
        if (data.rate && data.rate > 0) {
            document.getElementById('rate-input').value = parseFloat(data.rate).toFixed(4);
            document.getElementById('rate-source').value = 'rbz_auto';
            res.className = 'alert alert-success';
            res.textContent = '✓ RBZ rate fetched: ' + data.rate.toFixed(4) + ' ZiG/USD. Click Set Rate to apply.';
            res.style.display = 'block';
            fw.toast('Rate fetched: ' + data.rate.toFixed(4) + ' ZiG/USD', 'success');
        } else {
            res.className = 'alert alert-danger';
            res.textContent = '⚠ Could not fetch rate from RBZ. Please enter manually.';
            res.style.display = 'block';
            fw.toast('Could not fetch RBZ rate automatically', 'warning');
        }
    } catch(e) {
        fw.barError();
        fw.toast('Network error fetching rate', 'error');
    }
    btn.disabled = false;
    btn.textContent = '🔄 Fetch RBZ Rate';
}
</script>

<?php include "includes/layout_end.php"; ?>
