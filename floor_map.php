<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Floor Map";
$active_page = "floor_map";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$today       = date('Y-m-d');
$date_f      = $conn->real_escape_string($_GET['date'] ?? $today);

// AJAX data
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $locs_q = $conn->query("SELECT id,code,name,subcontractor FROM bale_locations WHERE selling_pointid=$spid AND active=1 ORDER BY code");
    $locs = ($locs_q&&$locs_q!==true)?$locs_q->fetch_all(MYSQLI_ASSOC):[];

    $data = [];
    foreach ($locs as $loc) {
        $lid = (int)$loc['id'];
        $stats_q = $conn->query("
            SELECT
                COUNT(DISTINCT bm.id)    total_bales,
                COUNT(DISTINCT bj.id)    junus_bales,
                COUNT(DISTINCT tk.id)    ticketed,
                COUNT(DISTINCT sb.id)    sold,
                COALESCE(SUM(CASE WHEN sb.id IS NOT NULL THEN bm.mass ELSE 0 END),0) sold_mass,
                COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass WHEN sb.price>0 THEN sb.price*bm.mass ELSE 0 END),0) usd,
                COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END),0) avg_price
            FROM bale_mass bm
            LEFT JOIN bale_junus bj ON bj.bale_massid=bm.id
            LEFT JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
            LEFT JOIN transporter t ON t.id=tg.transporterid
            LEFT JOIN bale_locations bl ON bl.id=t.location_id
            LEFT JOIN growers g ON g.id=bj.growerid
            LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
            LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
            WHERE DATE(bm.created_at)='$date_f' AND bl.id=$lid AND g.selling_pointid=$spid
        ");
        $stats = ($stats_q&&$stats_q!==true)?($stats_q->fetch_assoc()??[]):[];

        // Top growers in this location
        $growers_q = $conn->query("
            SELECT g.grower_num, COUNT(DISTINCT bm.id) bales,
                   COALESCE(SUM(CASE WHEN sb.id IS NOT NULL THEN bm.mass ELSE 0 END),0) mass
            FROM bale_mass bm
            JOIN bale_junus bj ON bj.bale_massid=bm.id
            JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
            JOIN transporter t ON t.id=tg.transporterid
            JOIN bale_locations bl ON bl.id=t.location_id
            JOIN growers g ON g.id=bj.growerid
            LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
            LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
            WHERE DATE(bm.created_at)='$date_f' AND bl.id=$lid AND g.selling_pointid=$spid
            GROUP BY g.id ORDER BY bales DESC LIMIT 5
        ");
        $top_growers = ($growers_q&&$growers_q!==true)?$growers_q->fetch_all(MYSQLI_ASSOC):[];

        $data[] = [
            'id'          => $lid,
            'code'        => $loc['code'],
            'name'        => $loc['name'],
            'subcontractor'=> $loc['subcontractor'],
            'total'       => (int)($stats['total_bales']??0),
            'sold'        => (int)($stats['sold']??0),
            'unsold'      => max(0,(int)($stats['ticketed']??0)-(int)($stats['sold']??0)),
            'mass'        => round((float)($stats['sold_mass']??0),1),
            'usd'         => round((float)($stats['usd']??0),2),
            'avg_price'   => round((float)($stats['avg_price']??0),4),
            'growers'     => $top_growers,
        ];
    }
    echo json_encode(['locations'=>$data,'time'=>date('H:i:s'),'date'=>$date_f]);
    exit;
}

include "includes/layout.php";
?>

<style>
.floor-grid{display:grid;gap:.75rem;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));margin-bottom:1.25rem}
.loc-card{background:var(--cream);border:2px solid var(--border);border-radius:10px;padding:1rem;cursor:pointer;transition:all .18s;position:relative;overflow:hidden}
.loc-card:hover{border-color:var(--gold);transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.12)}
.loc-card.has-data{border-color:rgba(200,149,42,.4)}
.loc-card .loc-code{font-family:'Bebas Neue',sans-serif;font-size:2.5rem;color:var(--gold);line-height:1;margin-bottom:.25rem}
.loc-card .loc-name{font-size:.7rem;color:var(--muted);margin-bottom:.75rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.loc-card .bale-bar{height:6px;background:var(--cream-d);border-radius:3px;overflow:hidden;margin-bottom:.6rem}
.loc-card .bale-bar-fill{height:6px;border-radius:3px;transition:width .6s ease}
.loc-stat{display:flex;justify-content:space-between;font-size:.72rem;margin-bottom:2px}
.loc-stat .lbl{color:var(--muted)}
.loc-stat .val{font-weight:600}
.loc-card .pulse-dot{width:8px;height:8px;border-radius:50%;position:absolute;top:.7rem;right:.7rem}
.pulse-dot.active{background:#4caf50;animation:pulse-dot 1.8s infinite}
.pulse-dot.inactive{background:var(--muted)}
@keyframes pulse-dot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}

.detail-panel{position:fixed;right:0;top:0;bottom:0;width:320px;background:var(--cream);border-left:1px solid var(--border);z-index:500;transform:translateX(320px);transition:transform .28s cubic-bezier(.4,0,.2,1);box-shadow:-4px 0 20px rgba(0,0,0,.1);overflow-y:auto}
.detail-panel.open{transform:translateX(0)}
.detail-head{background:var(--ink);color:var(--cream);padding:1rem 1.15rem;display:flex;justify-content:space-between;align-items:flex-start}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Floor Map</h2>
    <p>Visual layout of all bale locations · Auto-refreshes every 60s</p>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <span id="refresh-badge" style="font-size:.7rem;color:var(--muted)">Loading...</span>
    <form method="GET"><input type="date" name="date" class="form-control" value="<?=htmlspecialchars((string)$date_f)?>" style="font-size:.8rem;padding:.32rem .55rem"></form>
    <button onclick="loadMap()" class="btn btn-ghost btn-sm">⟳</button>
  </div>
</div>

<!-- Summary bar -->
<div id="summary-bar" style="display:flex;gap:1.5rem;font-size:.78rem;padding:.65rem 1rem;background:var(--cream-d);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:1rem;flex-wrap:wrap">
  <span style="color:var(--muted)">Loading floor data...</span>
</div>

<!-- Floor grid -->
<div class="floor-grid" id="floor-grid">
  <div style="grid-column:1/-1;text-align:center;padding:3rem;color:var(--muted)">Loading...</div>
</div>

<!-- Detail panel -->
<div class="detail-panel" id="detail-panel">
  <div class="detail-head">
    <div>
      <div id="dp-code" style="font-family:'Bebas Neue',sans-serif;font-size:2rem;line-height:1"></div>
      <div id="dp-name" style="font-size:.72rem;opacity:.5;margin-top:2px"></div>
    </div>
    <button onclick="closeDetail()" style="background:none;border:none;color:var(--cream);cursor:pointer;font-size:1.2rem;opacity:.6">✕</button>
  </div>
  <div id="dp-body" style="padding:1rem"></div>
</div>
<div id="detail-overlay" onclick="closeDetail()" style="display:none;position:fixed;inset:0;z-index:499;background:rgba(0,0,0,.2)"></div>

<script>
var mapDate = '<?=htmlspecialchars((string)$date_f)?>';
var refreshTimer;

function loadMap() {
    fetch('floor_map.php?ajax=1&date=' + mapDate + '&_=' + Date.now())
        .then(r=>r.json())
        .then(d => {
            document.getElementById('refresh-badge').textContent = 'Updated ' + d.time;
            renderMap(d.locations);
        })
        .catch(() => { document.getElementById('refresh-badge').textContent = 'Error loading'; });
}

function renderMap(locs) {
    var grid = document.getElementById('floor-grid');
    var bar  = document.getElementById('summary-bar');

    if (!locs || !locs.length) {
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:3rem;color:var(--muted)">No locations configured for this floor.<br><a href="locations.php" style="color:var(--gold)">→ Set up locations</a></div>';
        return;
    }

    var totBales=0, totSold=0, totMass=0, totUsd=0;
    locs.forEach(l=>{ totBales+=l.total; totSold+=l.sold; totMass+=l.mass; totUsd+=l.usd; });

    bar.innerHTML =
        '<span><strong>' + totBales.toLocaleString() + '</strong> <span style="color:var(--muted)">total bales</span></span>' +
        '<span><strong style="color:var(--leaf)">' + totSold.toLocaleString() + '</strong> <span style="color:var(--muted)">sold</span></span>' +
        '<span><strong style="color:var(--gold)">' + (totBales-totSold).toLocaleString() + '</strong> <span style="color:var(--muted)">unsold</span></span>' +
        '<span><strong>' + totMass.toLocaleString('en',{maximumFractionDigits:0}) + ' kg</strong> <span style="color:var(--muted)">mass sold</span></span>' +
        '<span><strong>$' + totUsd.toLocaleString('en',{minimumFractionDigits:0,maximumFractionDigits:0}) + '</strong> <span style="color:var(--muted)">USD value</span></span>' +
        '<span style="margin-left:auto;font-size:.65rem;color:var(--muted)">' + locs.length + ' locations</span>';

    grid.innerHTML = locs.map(function(l) {
        var pct = l.total > 0 ? Math.round(l.sold / l.total * 100) : 0;
        var barColor = pct >= 80 ? '#3d6b35' : pct >= 50 ? '#c8952a' : '#e53935';
        var hasData  = l.total > 0;
        return '<div class="loc-card' + (hasData?' has-data':'') + '" onclick="showDetail(' + JSON.stringify(l).replace(/"/g,'&quot;') + ')">' +
            '<div class="pulse-dot ' + (hasData?'active':'inactive') + '"></div>' +
            '<div class="loc-code">' + l.code + '</div>' +
            '<div class="loc-name">' + (l.name||'') + '</div>' +
            '<div class="bale-bar"><div class="bale-bar-fill" style="width:' + pct + '%;background:' + barColor + '"></div></div>' +
            '<div class="loc-stat"><span class="lbl">Bales</span><span class="val">' + l.total + '</span></div>' +
            '<div class="loc-stat"><span class="lbl">Sold</span><span class="val" style="color:var(--leaf)">' + l.sold + '</span></div>' +
            '<div class="loc-stat"><span class="lbl">Unsold</span><span class="val" style="color:' + (l.unsold>0?'var(--danger)':'var(--muted)') + '">' + l.unsold + '</span></div>' +
            '<div class="loc-stat"><span class="lbl">Value</span><span class="val">$' + l.usd.toLocaleString('en',{maximumFractionDigits:0}) + '</span></div>' +
            '</div>';
    }).join('');
}

function showDetail(loc) {
    document.getElementById('dp-code').textContent = loc.code;
    document.getElementById('dp-name').textContent = loc.name + (loc.subcontractor ? ' · ' + loc.subcontractor : '');
    var pct = loc.total > 0 ? Math.round(loc.sold/loc.total*100) : 0;
    var body = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:1rem">' +
        kpiBox('Total Bales', loc.total, '') +
        kpiBox('Sold', loc.sold, 'var(--leaf)') +
        kpiBox('Unsold', loc.unsold, loc.unsold>0?'var(--danger)':'var(--muted)') +
        kpiBox('Sold %', pct+'%', '') +
        kpiBox('Mass kg', loc.mass.toLocaleString('en',{maximumFractionDigits:1}), '') +
        kpiBox('USD Value', '$'+loc.usd.toLocaleString('en',{maximumFractionDigits:0}), 'var(--gold)') +
        '</div>';
    if (loc.avg_price > 0) {
        body += '<div style="font-size:.72rem;color:var(--muted);margin-bottom:.75rem">Avg price: <strong>$' + loc.avg_price.toFixed(4) + '/kg</strong></div>';
    }
    if (loc.growers && loc.growers.length) {
        body += '<div style="font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.35rem">Top Growers</div>';
        loc.growers.forEach(function(g) {
            body += '<div style="display:flex;justify-content:space-between;font-size:.75rem;padding:.3rem 0;border-bottom:1px solid var(--border)">' +
                '<span class="badge badge-gold" style="font-size:.6rem">' + g.grower_num + '</span>' +
                '<span>' + g.bales + ' bales · ' + parseFloat(g.mass).toFixed(1) + ' kg</span></div>';
        });
    }
    body += '<div style="margin-top:1rem"><a href="floor_summary.php?loc=' + loc.id + '" class="btn btn-ghost" style="width:100%;font-size:.75rem">→ Full Location Detail</a></div>';
    document.getElementById('dp-body').innerHTML = body;
    document.getElementById('detail-panel').classList.add('open');
    document.getElementById('detail-overlay').style.display = 'block';
}

function kpiBox(lbl, val, color) {
    return '<div style="background:var(--cream-d);border-radius:6px;padding:.6rem .75rem;text-align:center">' +
        '<div style="font-size:1.2rem;font-weight:700;color:' + (color||'var(--gold)') + ';line-height:1.1">' + val + '</div>' +
        '<div style="font-size:.6rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-top:2px">' + lbl + '</div></div>';
}

function closeDetail() {
    document.getElementById('detail-panel').classList.remove('open');
    document.getElementById('detail-overlay').style.display = 'none';
}

// Init
loadMap();
refreshTimer = setInterval(loadMap, 60000);
</script>

<?php include "includes/layout_end.php"; ?>
