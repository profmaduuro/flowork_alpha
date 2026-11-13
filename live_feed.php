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
.lf-stat-box { background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;text-align:center;transition:all .3s; }
body.theme-dark .lf-stat-box { background:var(--ink-soft); }
.lf-stat-num { font-family:'DM Sans',sans-serif;font-size:2.12rem;line-height:1;transition:all .3s;font-weight:600}
.lf-stat-lbl { font-size:.78rem;color:var(--muted);font-weight:500;margin-top:.3rem; }
.lf-feed-item { display:flex;align-items:center;gap:.85rem;padding:.65rem 1rem;border-bottom:1px solid var(--border);animation:lf-slideIn .3s ease both;transition:background .15s; }
.lf-feed-item:hover { background:rgba(200,149,42,.04); }
.lf-feed-item.new { background:rgba(61,107,53,.06); }
.lf-feed-icon { width:32px;height:32px;border-radius:8px;background:var(--gold-bg);display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0; }
.lf-feed-main { flex:1;min-width:0; }
.lf-feed-title { font-size:.8rem;font-weight:500; }
.lf-feed-sub { font-size:.68rem;color:var(--muted); }
.lf-feed-price { font-family:'DM Sans',sans-serif;font-size:0.94rem;color:var(--gold);text-align:right;font-weight:600}
.lf-feed-time { font-size:.72rem;color:var(--muted);text-align:right;min-width:50px; }
.lf-high-price .lf-feed-icon { background:rgba(61,107,53,.12); }
.lf-high-price .lf-feed-price { color:var(--leaf); }
.lf-feed-wrap { max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto; }

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


<!-- Live Stats -->
<div class="lf-stat-strip">
  <div class="lf-stat-box">
    <div class="lf-stat-num" id="lf-bales" style="color:var(--gold)"><?=number_format((int)($stats['bales']??0))?></div>
    <div class="lf-stat-lbl">Bales Sold Today</div>
  </div>
  <div class="lf-stat-box">
    <div class="lf-stat-num" id="lf-mass" style="color:var(--text)"><?=number_format((float)($stats['mass']??0),0)?></div>
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

</script>

<?php include "includes/layout_end.php"; ?>
