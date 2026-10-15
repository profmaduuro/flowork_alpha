<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title  = "Live Feed";
$active_page = "live_feed";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);

function lfAll($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_all(MYSQLI_ASSOC)?:[];}
function lfSafe($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_assoc()?:[];}

$sf = $spid ? "AND g.selling_pointid=$spid" : "";
$today = date('Y-m-d');

// Check for simulation post actions (Admin only)
$is_sim_admin = (int)($_SESSION['is_admin'] ?? 0) === 1 || ($_SESSION['role'] ?? '') === 'admin';
if ($is_sim_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sim_action'])) {
    header('Content-Type: application/json');
    $sim_action = $_POST['sim_action'];
    
    if ($sim_action === 'sell_bale') {
        // Find or create an unsold ticket
        $t_res = $conn->query("SELECT t.id, t.barcode FROM tickets t LEFT JOIN sold_bales sb ON sb.ticketsid = t.id WHERE sb.id IS NULL LIMIT 1");
        if ($t_res && $t_res->num_rows > 0) {
            $ticket = $t_res->fetch_assoc();
            $ticket_id = (int)$ticket['id'];
            $barcode = $ticket['barcode'];
        } else {
            // Create a new simulated ticket
            $ref_res = $conn->query("SELECT bale_junusid, start_of_dayid FROM tickets ORDER BY RAND() LIMIT 1");
            if ($ref_res && $ref_res->num_rows > 0) {
                $ref = $ref_res->fetch_assoc();
                $bale_junusid = (int)$ref['bale_junusid'];
                $start_of_dayid = (int)$ref['start_of_dayid'];
                $barcode = "TS" . rand(100000, 999999);
                $now_str = date('Y-m-d H:i:s');
                $conn->query("INSERT INTO tickets (userid, bale_junusid, barcode, start_of_dayid, created_at) VALUES (1, $bale_junusid, '$barcode', $start_of_dayid, '$now_str')");
                $ticket_id = (int)$conn->insert_id;
            } else {
                echo json_encode(['ok'=>false,'msg'=>'No reference tickets found in system to clone.']);
                exit;
            }
        }
        
        // Select random TIMB grade
        $tg_res = $conn->query("SELECT id FROM timb_grades WHERE active=1 ORDER BY RAND() LIMIT 1");
        $timb_grade_id = $tg_res ? (int)$tg_res->fetch_assoc()['id'] : 1;
        
        // Select random buyer grade
        $bg_res = $conn->query("SELECT id FROM buyer_grades ORDER BY RAND() LIMIT 1");
        $buyer_grade_id = $bg_res ? (int)$bg_res->fetch_assoc()['id'] : 1;
        
        // Generate random price in cents (e.g. $1.50 to $5.50)
        $price_cents = rand(150, 550);
        $now_str = date('Y-m-d H:i:s');
        
        // Insert sale
        $ok = $conn->query("INSERT INTO sold_bales (userid, ticketsid, timb_gradesid, buyer_gradesid, price, created_at, s2_confirmed) VALUES (1, $ticket_id, $timb_grade_id, $buyer_grade_id, '$price_cents', '$now_str', 1)");
        
        if ($ok) {
            echo json_encode(['ok'=>true,'barcode'=>$barcode,'price'=>$price_cents/100.0]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>$conn->error]);
        }
        exit;
    }
    
    if ($sim_action === 'reset_floor') {
        $conn->query("DELETE FROM sold_bales WHERE DATE(created_at) = CURDATE()");
        $conn->query("DELETE FROM tickets WHERE barcode LIKE 'TS%'");
        echo json_encode(['ok'=>true]);
        exit;
    }
}

// For AJAX polling
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $after_id = (int)($_GET['after'] ?? 0);
    $feed = lfAll($conn, "SELECT sb.id, tk.barcode, b.name buyer_name, bg.description buyer_grade,
            tg2.description timb_grade,
            CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END price,
            bm.mass, g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
            sb.created_at
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        LEFT JOIN timb_grades tg2 ON tg2.id=sb.timb_gradesid
        WHERE sb.id > $after_id AND DATE(sb.created_at)='$today' $sf
        ORDER BY sb.id DESC LIMIT 20");

    $stats = lfSafe($conn, "SELECT COUNT(sb.id) bales, COALESCE(SUM(bm.mass),0) mass,
            COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) usd
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        WHERE DATE(sb.created_at)='$today' $sf");

    echo json_encode(['feed'=>$feed,'stats'=>$stats]);
    exit;
}

// Initial feed
$feed = lfAll($conn, "SELECT sb.id, tk.barcode, b.name buyer_name, bg.description buyer_grade,
        tg2.description timb_grade,
        CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END price,
        bm.mass, g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
        sb.created_at
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    LEFT JOIN buyer b ON b.id=bg.buyerid
    LEFT JOIN timb_grades tg2 ON tg2.id=sb.timb_gradesid
    WHERE DATE(sb.created_at)='$today' $sf
    ORDER BY sb.id DESC LIMIT 50");

$stats = lfSafe($conn, "SELECT COUNT(sb.id) bales, COALESCE(SUM(bm.mass),0) mass,
        COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END * bm.mass),0) usd
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    WHERE DATE(sb.created_at)='$today' $sf");

include "includes/layout.php";
?>

<style>
@keyframes lf-pulse { 0%,100%{opacity:1} 50%{opacity:.5} }
@keyframes lf-slideIn { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
.lf-live-badge { display:inline-flex;align-items:center;gap:6px;background:rgba(61,107,53,.12);border:1px solid rgba(61,107,53,.3);color:var(--leaf);font-size:.65rem;font-weight:600;letter-spacing:.1em;padding:3px 10px;border-radius:999px; }
.lf-live-dot { width:8px;height:8px;border-radius:50%;background:var(--leaf);animation:lf-pulse 2s ease infinite; }
.lf-stat-strip { display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1.5rem; }
.lf-stat-box { background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:1rem;text-align:center;transition:all .3s; }
body.theme-dark .lf-stat-box { background:var(--ink-soft); }
.lf-stat-num { font-family:'Bebas Neue',sans-serif;font-size:2.5rem;line-height:1;transition:all .3s; }
.lf-stat-lbl { font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.12em;font-weight:500;margin-top:.3rem; }
.lf-feed-item { display:flex;align-items:center;gap:.85rem;padding:.65rem 1rem;border-bottom:1px solid var(--border);animation:lf-slideIn .3s ease both;transition:background .15s; }
.lf-feed-item:hover { background:rgba(200,149,42,.04); }
.lf-feed-item.new { background:rgba(61,107,53,.06); }
.lf-feed-icon { width:32px;height:32px;border-radius:8px;background:var(--gold-bg);display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0; }
.lf-feed-main { flex:1;min-width:0; }
.lf-feed-title { font-size:.8rem;font-weight:500; }
.lf-feed-sub { font-size:.68rem;color:var(--muted); }
.lf-feed-price { font-family:'Bebas Neue',sans-serif;font-size:1.1rem;color:var(--gold);text-align:right; }
.lf-feed-time { font-size:.6rem;color:var(--muted);text-align:right;min-width:50px; }
.lf-high-price .lf-feed-icon { background:rgba(61,107,53,.12); }
.lf-high-price .lf-feed-price { color:var(--leaf); }
.lf-feed-wrap { max-height:600px;overflow-y:auto; }

/* Simulator CSS Styles */
.lf-sim-card {
    background: linear-gradient(135deg, rgba(61, 107, 53, 0.03), rgba(200, 149, 42, 0.03));
    border: 1px dashed var(--gold);
    border-radius: var(--radius);
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}
body.theme-dark .lf-sim-card {
    background: linear-gradient(135deg, rgba(61, 107, 53, 0.08), rgba(200, 149, 42, 0.08));
}
.lf-sim-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    border-bottom: 1px solid rgba(0,0,0,0.06);
    padding-bottom: 0.5rem;
}
body.theme-dark .lf-sim-header {
    border-bottom-color: rgba(255,255,255,0.08);
}
.lf-sim-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gold);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.lf-sim-badge {
    background: var(--gold-bg);
    color: var(--gold);
    font-size: 0.65rem;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 600;
}
.lf-sim-body {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: center;
}
.lf-sim-controls {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    align-items: center;
}
.lf-sim-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0.5rem 1rem;
    border-radius: var(--radius);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.2s;
    background: #fff;
    color: var(--ink);
}
body.theme-dark .lf-sim-btn {
    background: var(--ink-soft);
    color: var(--text-dark);
    border-color: var(--border);
}
.lf-sim-btn-sell {
    background: var(--leaf);
    color: #fff;
}
.lf-sim-btn-sell:hover {
    background: #32572b;
    transform: translateY(-1px);
}
.lf-sim-btn-auto {
    background: var(--gold);
    color: #fff;
}
.lf-sim-btn-auto:hover {
    background: #a87e24;
    transform: translateY(-1px);
}
.lf-sim-btn-auto.active {
    background: #d9534f;
    animation: lf-pulse 2s infinite;
}
.lf-sim-btn-reset {
    border-color: #d9534f;
    color: #d9534f;
}
.lf-sim-btn-reset:hover {
    background: #d9534f;
    color: #fff;
    transform: translateY(-1px);
}
.lf-sim-status {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.72rem;
    color: var(--muted);
    font-weight: 500;
}
.lf-sim-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #ccc;
    display: inline-block;
    transition: all 0.3s;
}
.lf-sim-status-dot.active {
    background: var(--leaf);
    animation: lf-pulse 1.5s infinite;
}
.lf-sim-toast {
    position: absolute;
    bottom: 10px;
    right: 10px;
    background: var(--ink);
    color: #fff;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 0.7rem;
    opacity: 0;
    transform: translateY(10px);
    transition: all 0.3s ease;
    pointer-events: none;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    z-index: 10;
}
.lf-sim-toast.show {
    opacity: 1;
    transform: translateY(0);
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Live Floor Feed</h2>
    <p>Real-time auction activity — auto-refreshes every 10 seconds</p>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <span class="lf-live-badge"><span class="lf-live-dot"></span> LIVE</span>
    <span style="font-size:.72rem;color:var(--muted)" id="lf-last-update">Updated just now</span>
  </div>
</div>

<?php if ($is_sim_admin): ?>
<!-- Floor Bidding Simulator Card -->
<div class="lf-sim-card">
  <div class="lf-sim-header">
    <div class="lf-sim-title">
      <span>⚡ Floor Bidding & Sale Simulator</span>
      <span class="lf-sim-badge">ADMIN CONTROL</span>
    </div>
    <div class="lf-sim-status">
      <span class="lf-sim-status-dot" id="sim-status-dot"></span>
      <span id="sim-status-text">Simulator: Idle</span>
    </div>
  </div>
  <div class="lf-sim-body">
    <div class="lf-sim-controls">
      <button class="lf-sim-btn lf-sim-btn-sell" id="btn-sim-sell" title="Simulate a single bale transaction">
        ⚡ Manual Sale
      </button>
      <button class="lf-sim-btn lf-sim-btn-auto" id="btn-sim-auto" title="Automatically simulate transactions on a timer">
        🔄 Start Auto-Sim
      </button>
      <select id="sim-interval" class="form-control" style="font-size: 0.75rem; padding: 6px 12px; width: 120px; border-radius: var(--radius); border: 1px solid var(--border); background: var(--input-bg, #fff); color: var(--ink); cursor: pointer;">
        <option value="2000">Interval: 2s</option>
        <option value="5000" selected>Interval: 5s</option>
        <option value="10000">Interval: 10s</option>
        <option value="30000">Interval: 30s</option>
      </select>
      <button class="lf-sim-btn lf-sim-btn-reset" id="btn-sim-reset" title="Clean up simulated data (removes TS-prefixed tickets and sales from today)">
        🗑️ Reset Floor
      </button>
    </div>
    <div style="font-size: 0.68rem; color: var(--muted); flex: 1; min-width: 200px;">
      Simulates high-fidelity auction floor transactions by cloning existing ticket models and matching them with growers, TIMB grades, and random buyers.
    </div>
  </div>
  <div class="lf-sim-toast" id="sim-toast">Bale sold!</div>
</div>
<?php endif; ?>

<!-- Live Stats -->
<div class="lf-stat-strip">
  <div class="lf-stat-box">
    <div class="lf-stat-num" id="lf-bales" style="color:var(--gold)"><?=number_format((int)($stats['bales']??0))?></div>
    <div class="lf-stat-lbl">Bales Sold Today</div>
  </div>
  <div class="lf-stat-box">
    <div class="lf-stat-num" id="lf-mass" style="color:var(--ink)"><?=number_format((float)($stats['mass']??0),0)?></div>
    <div class="lf-stat-lbl">Mass (kg)</div>
  </div>
  <div class="lf-stat-box">
    <div class="lf-stat-num" id="lf-usd" style="color:var(--leaf)">$<?=number_format((float)($stats['usd']??0),0)?></div>
    <div class="lf-stat-lbl">Gross USD Today</div>
  </div>
</div>

<!-- Feed -->
<div class="card">
  <div class="card-head">
    <h3>📋 Activity Feed</h3>
    <span class="badge badge-gold" id="lf-count"><?=count($feed)?> sales</span>
  </div>
  <div class="lf-feed-wrap" id="lf-feed">
    <?php foreach($feed as $f):
      $usd_val = (float)$f['price'] * (float)$f['mass'];
      $pkg = (float)$f['price'];
      $high = $pkg >= 4;
    ?>
    <div class="lf-feed-item <?=$high?'lf-high-price':''?>" data-id="<?=$f['id']?>">
      <div class="lf-feed-icon"><?=$high?'🌟':'📦'?></div>
      <div class="lf-feed-main">
        <div class="lf-feed-title"><?=htmlspecialchars($f['barcode'])?> → <?=htmlspecialchars($f['buyer_name']??'Unknown')?></div>
        <div class="lf-feed-sub"><?=htmlspecialchars($f['grower_num'])?> · <?=htmlspecialchars($f['timb_grade']??'')?> · <?=number_format((float)$f['mass'],1)?>kg</div>
      </div>
      <div>
        <div class="lf-feed-price">$<?=number_format($pkg,2)?>/kg</div>
        <div class="lf-feed-time"><?=date('H:i',strtotime($f['created_at']))?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($feed)): ?>
    <div style="padding:3rem;text-align:center;color:var(--muted)">
      <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem">🔄</div>
      Waiting for sales activity... Feed will update automatically.
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
let lastId = <?=!empty($feed) ? (int)$feed[0]['id'] : 0?>;
let pollInterval;

function formatNum(n) { return n.toLocaleString('en-US'); }

function poll() {
    fetch('live_feed.php?ajax=1&after=' + lastId)
        .then(r => r.json())
        .then(data => {
            // Update stats with animation
            if (data.stats) {
                animateStat('lf-bales', parseInt(data.stats.bales||0));
                animateStat('lf-mass', parseInt(data.stats.mass||0));
                document.getElementById('lf-usd').textContent = '$' + formatNum(parseInt(data.stats.usd||0));
            }
            // Add new feed items
            if (data.feed && data.feed.length > 0) {
                const container = document.getElementById('lf-feed');
                const emptyMsg = container.querySelector('[style*="text-align:center"]');
                if (emptyMsg) emptyMsg.remove();
                
                data.feed.reverse().forEach(f => {
                    const pkg = parseFloat(f.price);
                    const high = pkg >= 4;
                    const el = document.createElement('div');
                    el.className = 'lf-feed-item new ' + (high ? 'lf-high-price' : '');
                    el.dataset.id = f.id;
                    el.innerHTML = `
                        <div class="lf-feed-icon">${high?'🌟':'📦'}</div>
                        <div class="lf-feed-main">
                            <div class="lf-feed-title">${f.barcode} → ${f.buyer_name||'Unknown'}</div>
                            <div class="lf-feed-sub">${f.grower_num} · ${f.timb_grade||''} · ${parseFloat(f.mass).toFixed(1)}kg</div>
                        </div>
                        <div>
                            <div class="lf-feed-price">$${pkg.toFixed(2)}/kg</div>
                            <div class="lf-feed-time">${new Date(f.created_at).toLocaleTimeString('en',{hour:'2-digit',minute:'2-digit'})}</div>
                        </div>`;
                    container.insertBefore(el, container.firstChild);
                    setTimeout(() => el.classList.remove('new'), 3000);
                });
                lastId = Math.max(lastId, ...data.feed.map(f => parseInt(f.id)));
                document.getElementById('lf-count').textContent = container.querySelectorAll('.lf-feed-item').length + ' sales';
            }
            document.getElementById('lf-last-update').textContent = 'Updated ' + new Date().toLocaleTimeString('en',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
        })
        .catch(() => {});
}

function animateStat(id, target) {
    const el = document.getElementById(id);
    const prefix = el.textContent.startsWith('$') ? '$' : '';
    el.textContent = prefix + formatNum(target);
    el.style.transform = 'scale(1.08)';
    el.style.color = 'var(--gold)';
    setTimeout(() => { el.style.transform = 'scale(1)'; }, 300);
}

// Poll every 10 seconds
pollInterval = setInterval(poll, 10000);

// Simulator JS Controls
<?php if ($is_sim_admin): ?>
let simIntervalId = null;

function showSimToast(msg, isError = false) {
    const toast = document.getElementById('sim-toast');
    toast.textContent = msg;
    toast.style.background = isError ? '#d9534f' : 'var(--ink)';
    toast.classList.add('show');
    setTimeout(() => {
        toast.classList.remove('show');
    }, 2500);
}

function triggerSimulatedSale() {
    const formData = new FormData();
    formData.append('sim_action', 'sell_bale');
    
    // Animate manual button if clicked
    const btn = document.getElementById('btn-sim-sell');
    btn.style.transform = 'scale(0.95)';
    setTimeout(() => btn.style.transform = 'scale(1)', 100);

    fetch('live_feed.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            showSimToast(`Bale ${data.barcode} sold at $${data.price.toFixed(2)}/kg!`);
            poll(); // Update feed immediately
        } else {
            showSimToast(data.msg || 'Error simulating sale', true);
        }
    })
    .catch(err => {
        showSimToast('Network or server error', true);
    });
}

function toggleAutoSim() {
    const btn = document.getElementById('btn-sim-auto');
    const dot = document.getElementById('sim-status-dot');
    const statusText = document.getElementById('sim-status-text');
    
    if (simIntervalId) {
        // Stop
        clearInterval(simIntervalId);
        simIntervalId = null;
        btn.textContent = '🔄 Start Auto-Sim';
        btn.classList.remove('active');
        dot.classList.remove('active');
        statusText.textContent = 'Simulator: Idle';
        showSimToast('Auto-simulation stopped');
    } else {
        // Start
        startAutoSimTimer();
        btn.textContent = '⏹️ Stop Auto-Sim';
        btn.classList.add('active');
        dot.classList.add('active');
        showSimToast('Auto-simulation started');
    }
}

function startAutoSimTimer() {
    if (simIntervalId) clearInterval(simIntervalId);
    
    const intervalVal = parseInt(document.getElementById('sim-interval').value) || 5000;
    const statusText = document.getElementById('sim-status-text');
    statusText.textContent = `Simulator: Active (${(intervalVal/1000).toFixed(0)}s)`;
    
    // Initial sale immediately, then interval
    triggerSimulatedSale();
    simIntervalId = setInterval(triggerSimulatedSale, intervalVal);
}

// Add event listeners when DOM content loads
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('btn-sim-sell').addEventListener('click', triggerSimulatedSale);
    document.getElementById('btn-sim-auto').addEventListener('click', toggleAutoSim);
    
    document.getElementById('sim-interval').addEventListener('change', () => {
        if (simIntervalId) {
            startAutoSimTimer();
            showSimToast('Simulation interval updated');
        }
    });
    
    document.getElementById('btn-sim-reset').addEventListener('click', () => {
        if (confirm('Are you sure you want to reset simulated sales and TS-prefixed tickets from today?')) {
            const formData = new FormData();
            formData.append('sim_action', 'reset_floor');
            
            fetch('live_feed.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    showSimToast('Floor cleared of simulated transactions');
                    // Stop auto-simulation if running
                    if (simIntervalId) toggleAutoSim();
                    
                    // Clear the feed UI list
                    const container = document.getElementById('lf-feed');
                    container.innerHTML = `
                        <div style="padding:3rem;text-align:center;color:var(--muted)">
                            <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem">🔄</div>
                            Waiting for sales activity... Feed will update automatically.
                        </div>`;
                    
                    // Reset polling stats
                    lastId = 0;
                    poll();
                } else {
                    showSimToast('Error resetting floor', true);
                }
            })
            .catch(() => {
                showSimToast('Network or server error during reset', true);
            });
        }
    });
});
<?php endif; ?>
</script>

<?php include "includes/layout_end.php"; ?>
