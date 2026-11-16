<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Live Floor";
$active_page = "live_floor";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$sp_name     = htmlspecialchars($_SESSION['selling_point_name'] ?? '');
$today       = date('Y-m-d');

// AJAX refresh endpoint
// AJAX refresh endpoint
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    function safe_val($conn,$sql){$r=$conn->query($sql);return($r&&$r!==true)?($r->fetch_assoc()??[]):[];}
    function safe_all($conn,$sql){$r=$conn->query($sql);return($r&&$r!==true)?$r->fetch_all(MYSQLI_ASSOC):[];}

    // One floor's figures for sale day $d, counted the way the reports count them:
    // sold = bales sold on $d; ticketed = bales on $d's selling day (rolled-over
    // deliveries included, as their tickets move to that day); weighed = this
    // floor's bales weighed on $d.
    function live_summary($conn, int $sp, string $d): array {
        $sold = safe_val($conn,"
            SELECT
              COUNT(sb.id) sold,
              COUNT(CASE WHEN LEFT(UPPER(TRIM(sb.sale_code)),1)='R' THEN sb.id END) rejected,
              COALESCE(SUM(bm.mass),0) sold_mass,
              COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass WHEN sb.price>0 THEN sb.price*bm.mass ELSE 0 END),0) usd_value,
              MAX(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END) hi_price,
              MIN(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END) lo_price,
              AVG(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END) avg_price
            FROM sold_bales sb
            JOIN tickets tk ON tk.id=sb.ticketsid
            JOIN bale_junus bj ON bj.id=tk.bale_junusid
            JOIN bale_mass bm ON bm.id=bj.bale_massid
            JOIN growers g ON g.id=bj.growerid
            WHERE DATE(sb.created_at)='$d' AND g.selling_pointid=$sp");
        // Tickets on this floor's selling day for $d; tickets made on $d when no day was opened
        $day = safe_all($conn,"SELECT id FROM start_of_day WHERE description='$d' AND selling_pointid=$sp");
        $sods = implode(',', array_map('intval', array_column($day, 'id')));
        $tk_on_day = $sods ? "tk.start_of_dayid IN ($sods)" : "DATE(tk.created_at)='$d'";
        $tix = safe_val($conn,"
            SELECT COUNT(DISTINCT tk.id) ticketed,
                   COUNT(DISTINCT CASE WHEN sb.id IS NULL OR DATE(sb.created_at)='$d' THEN g.id END) growers
            FROM tickets tk
            JOIN bale_junus bj ON bj.id=tk.bale_junusid
            JOIN growers g ON g.id=bj.growerid
            LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
            WHERE $tk_on_day AND g.selling_pointid=$sp
              AND (sb.id IS NULL OR DATE(sb.created_at)='$d')");
        // Weighed on $d on this floor, not yet ticketed to a day
        $wq = safe_val($conn,"
            SELECT COUNT(DISTINCT bm.id) weighed,
                   COUNT(DISTINCT CASE WHEN tk.id IS NULL THEN bm.id END) waiting,
                   COUNT(DISTINCT bj.id) junus_bales
            FROM bale_mass bm
            LEFT JOIN bale_junus bj ON bj.bale_massid=bm.id
            LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
            WHERE DATE(bm.created_at)='$d' AND bm.selling_pointid=$sp");
        $ticketed = max((int)($tix['ticketed'] ?? 0), (int)($sold['sold'] ?? 0));
        return $sold + [
            'ticketed'    => $ticketed,
            'total_bales' => $ticketed + (int)($wq['waiting'] ?? 0),
            'weighed'     => (int)($wq['weighed'] ?? 0),
            'junus_bales' => (int)($wq['junus_bales'] ?? 0),
            'growers'     => (int)($tix['growers'] ?? 0),
        ];
    }

    $d = $conn->real_escape_string($_GET['date'] ?? $today);
    $view_multi = (!empty($_GET['all_floors']) || !empty($_GET['multi'])) && is_master_user();

    if ($view_multi) {
        $sp_list = safe_all($conn, "SELECT id, name, floor_code FROM selling_point WHERE active=1 ORDER BY name");
        $floors_data = [];
        $grand_total_bales = 0;
        $grand_sold_bales = 0;
        $grand_usd = 0;
        $grand_mass = 0;

        foreach ($sp_list as $fl) {
            $f_spid = (int)$fl['id'];
            $summary = live_summary($conn, $f_spid, $d);

            $grand_total_bales += (int)($summary['total_bales'] ?? 0);
            $grand_sold_bales  += (int)($summary['sold'] ?? 0);
            $grand_usd         += (float)($summary['usd_value'] ?? 0);
            $grand_mass        += (float)($summary['sold_mass'] ?? 0);

            $floors_data[] = [
                'id'         => $f_spid,
                'name'       => $fl['name'],
                'floor_code' => $fl['floor_code'],
                'summary'    => $summary
            ];
        }

        echo json_encode([
            'multi_mode'   => true,
            'floors_data'  => $floors_data,
            'totals'       => [
                'total_bales' => $grand_total_bales,
                'sold_bales'  => $grand_sold_bales,
                'usd_value'   => $grand_usd,
                'sold_mass'   => $grand_mass
            ],
            'time' => date('H:i:s')
        ]);
        exit;
    }

    $summary = live_summary($conn, $spid, $d);

    $by_buyer = safe_all($conn,"
        SELECT COALESCE(NULLIF(b.buyer_code,''), b.name) buyer, b.buyer_code code,
               COUNT(sb.id) bales, COALESCE(SUM(bm.mass),0) mass,
               COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        JOIN buyer b ON b.id=bg.buyerid
        WHERE DATE(sb.created_at)='$d' AND g.selling_pointid=$spid
        GROUP BY b.id ORDER BY usd DESC LIMIT 10
    ");

    $by_loc = safe_all($conn,"
        SELECT bl.code loc, COUNT(sb.id) bales,
               COALESCE(SUM(bm.mass),0) mass,
               COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd
        FROM bale_locations bl
        JOIN (transporter_growers tg JOIN transporter t ON t.id=tg.transporterid)
             ON COALESCE(tg.locationid, t.location_id)=bl.id
        JOIN bale_junus bj ON bj.transporter_growersid=tg.id
        JOIN tickets tk ON tk.bale_junusid=bj.id
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        WHERE DATE(tk.created_at)='$d' AND bl.selling_pointid=$spid
        GROUP BY bl.id ORDER BY usd DESC
    ");

    $recent = safe_all($conn,"
        SELECT g.grower_num, g.name, g.surname, bm.mass, sb.price,
               tg2.description grade, DATE_FORMAT(sb.created_at,'%H:%i:%s') sold_at
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        LEFT JOIN timb_grades tg2 ON tg2.id=sb.timb_gradesid
        WHERE DATE(sb.created_at)='$d' AND g.selling_pointid=$spid
        ORDER BY sb.id DESC LIMIT 15
    ");

    $er_q = $conn->query("SELECT amount FROM exchange_rate WHERE selling_pointid=$spid AND active=1 ORDER BY id DESC LIMIT 1");
    $er = ($er_q&&$er_q!==true)?(float)($er_q->fetch_assoc()['amount']??0):0;

    echo json_encode([
        'multi_mode' => false,
        'summary'    => $summary,
        'by_buyer'   => $by_buyer,
        'by_loc'     => $by_loc,
        'recent'     => $recent,
        'er'         => $er,
        'time'       => date('H:i:s'),
    ]);
    exit;
}

include "includes/layout.php";
?>

<style>
.live-kpi{background:var(--cream-d);border:1px solid var(--border);border-radius:var(--radius);
  padding:.85rem 1rem;text-align:center;transition:transform .15s}
.live-kpi:hover{transform:translateY(-2px)}
.live-kpi .lk-val{font-size:1.53rem;font-weight:700;font-family:'DM Sans',sans-serif;
  color:var(--gold);line-height:1.1}
.live-kpi .lk-lbl{font-size:.78rem;font-weight:600;color:var(--muted);margin-top:3px}
.live-kpi .lk-sub{font-size:.7rem;color:var(--muted);margin-top:1px}
.pulse{animation:pulse-dot 1.8s infinite}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.35}}
.feed-row{display:flex;align-items:center;gap:.75rem;padding:.45rem .85rem;
  border-bottom:1px solid rgba(200,149,42,.06);font-size:.78rem;transition:background .2s}
.feed-row:hover{background:rgba(200,149,42,.04)}
.feed-row:first-child{animation:feed-in .4s ease both}
@keyframes feed-in{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
.multi-floor-card{background:var(--cream-d);border:1px solid var(--gold);border-radius:8px;padding:1rem;margin-bottom:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.15)}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Live Floor Dashboard</h2>
    <p><?=$sp_name?> · <span id="live-date"><?=date('l d F Y')?></span></p>
  </div>
  <div style="display:flex;align-items:center;gap:.75rem">
    <?php if (is_master_user()): ?>
    <div style="display:inline-flex;padding:3px;background:var(--cream-d);border-radius:6px;border:1px solid var(--border)">
      <button type="button" id="btn-mode-single" class="btn btn-sm btn-primary" onclick="setDashboardMode('single')">🎯 Single Floor</button>
      <button type="button" id="btn-mode-multi" class="btn btn-sm btn-ghost" onclick="setDashboardMode('multi')">📺 Multi-Floor Matrix</button>
    </div>
    <?php endif; ?>
    <span id="refresh-indicator" style="font-size:.72rem;color:var(--muted);display:flex;align-items:center;gap:4px">
      <span class="pulse" style="width:7px;height:7px;border-radius:50%;background:#4caf50;display:inline-block"></span>
      Live · <span id="last-update">--:--:--</span>
    </span>
    <select id="date-picker" class="form-control" style="font-size:.75rem;padding:.35rem .55rem;width:auto">
      <option value="<?=$today?>">Today</option>
    </select>
    <button onclick="forceRefresh()" class="btn btn-ghost btn-sm">⟳ Refresh</button>
  </div>
</div>

<?php if (is_master_user()): ?>
<div class="alert alert-gold" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <div>
    <strong>👑 MASTER USER LOGGED IN</strong> — Real-time multi-floor monitoring mode available.
  </div>
  <span style="font-size:.72rem;font-weight:600">UNRESTRICTED ACCESS</span>
</div>
<?php endif; ?>

<!-- MULTI-FLOOR MATRIX CONTAINER (MASTER USER) -->
<div id="multi-floor-container" style="display:none;margin-bottom:1.5rem">
  <div class="card mb-3">
    <div class="card-head">
      <h3>👑 Multi-Floor Operations Matrix</h3>
      <span class="badge badge-gold">All Active Floors</span>
    </div>
    <div class="card-body">
      <div id="multi-floor-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem">
        <div class="text-center text-muted" style="padding:2rem;grid-column:1/-1">Loading all floors data...</div>
      </div>
    </div>
  </div>
</div>

<!-- SINGLE FLOOR CONTAINER -->
<div id="single-floor-container">
  <!-- KPI Grid -->
  <div id="kpi-grid" style="display:grid;grid-template-columns:repeat(5,1fr);gap:.75rem;margin-bottom:1.25rem">
    <?php foreach([
      ['id'=>'kpi-weighed', 'lbl'=>'Bales on Floor','sub'=>'weighed today'],
      ['id'=>'kpi-sold',    'lbl'=>'Bales Sold',    'sub'=>'sold today'],
      ['id'=>'kpi-unsold',  'lbl'=>'Unsold',         'sub'=>'remaining'],
      ['id'=>'kpi-mass',    'lbl'=>'Mass Sold',      'sub'=>'kg'],
      ['id'=>'kpi-usd',     'lbl'=>'USD Value',      'sub'=>'gross proceeds'],
    ] as $k):?>
    <div class="live-kpi">
      <div class="lk-val" id="<?=$k['id']?>">—</div>
      <div class="lk-lbl"><?=$k['lbl']?></div>
      <div class="lk-sub" id="<?=$k['id']?>-sub"><?=$k['sub']?></div>
    </div>
    <?php endforeach;?>
  </div>

  <!-- Secondary KPIs -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1.25rem">
    <?php foreach([
      ['id'=>'kpi-growers','lbl'=>'Growers'],
      ['id'=>'kpi-hi',     'lbl'=>'Hi Price $/kg'],
      ['id'=>'kpi-lo',     'lbl'=>'Lo Price $/kg'],
      ['id'=>'kpi-avg',    'lbl'=>'Avg Price $/kg'],
    ] as $k):?>
    <div class="live-kpi">
      <div class="lk-val" id="<?=$k['id']?>">—</div>
      <div class="lk-lbl"><?=$k['lbl']?></div>
    </div>
    <?php endforeach;?>
  </div>

  <div class="grid-2" style="align-items:start;gap:1rem">

  <!-- Sales feed -->
  <div>
  <div class="card">
  <div class="card-head">
    <h3>Recent Sales</h3>
    <span class="badge badge-green pulse" style="font-size:.72rem">● LIVE</span>
  </div>
  <div id="sales-feed" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
    <div class="text-center text-muted" style="padding:2rem">Loading...</div>
  </div>
  </div>
  </div>

  <!-- By buyer + by location -->
  <div>
  <div class="card mb-3">
  <div class="card-head"><h3>By Buyer</h3></div>
  <div id="buyer-table" class="table-wrap" style="max-height:200px;overflow-y:auto">
    <div class="text-center text-muted" style="padding:1rem">Loading...</div>
  </div>
  </div>

  <div class="card">
  <div class="card-head"><h3>By Location</h3></div>
  <div id="loc-table" class="table-wrap" style="max-height:200px;overflow-y:auto">
    <div class="text-center text-muted" style="padding:1rem">Loading...</div>
  </div>
  </div>
  </div>

  </div>

  <!-- Progress bar for today's floor -->
  <div class="card" style="margin-top:1rem">
  <div class="card-head"><h3>Floor Progress</h3><span id="progress-label" style="font-size:.72rem;color:var(--muted)"></span></div>
  <div style="padding:.75rem 1.15rem">
    <div style="height:20px;background:var(--cream-d);border-radius:10px;overflow:hidden;border:1px solid var(--border)">
      <div id="progress-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#c8952a,#3d6b35);
           border-radius:10px;transition:width .8s ease;display:flex;align-items:center;justify-content:flex-end;
           padding-right:8px">
        <span id="progress-pct" style="font-size:.65rem;font-weight:700;color:#fff"></span>
      </div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:.65rem;color:var(--muted);margin-top:4px">
      <span>0 bales</span>
      <span id="progress-max"></span>
    </div>
  </div>
  </div>
</div>

<script>
var currentDate = '<?=$today?>';
var currentMode = 'single';
var refreshTimer;
var isRefreshing = false;

function fmt(n, dp, prefix) {
    if(n===null||n===undefined||n==='') return '—';
    var v = parseFloat(n);
    if(isNaN(v)) return '—';
    var s = dp!==undefined ? v.toFixed(dp) : Math.round(v).toLocaleString('en');
    return (prefix||'') + s;
}

function setDashboardMode(mode) {
    currentMode = mode;
    var btnSingle = document.getElementById('btn-mode-single');
    var btnMulti  = document.getElementById('btn-mode-multi');
    var singleContainer = document.getElementById('single-floor-container');
    var multiContainer  = document.getElementById('multi-floor-container');

    if (mode === 'multi') {
        if (btnSingle) { btnSingle.className = 'btn btn-sm btn-ghost'; }
        if (btnMulti)  { btnMulti.className  = 'btn btn-sm btn-primary'; }
        if (singleContainer) singleContainer.style.display = 'none';
        if (multiContainer)  multiContainer.style.display  = 'block';
    } else {
        if (btnSingle) { btnSingle.className = 'btn btn-sm btn-primary'; }
        if (btnMulti)  { btnMulti.className  = 'btn btn-sm btn-ghost'; }
        if (singleContainer) singleContainer.style.display = 'block';
        if (multiContainer)  multiContainer.style.display  = 'none';
    }
    forceRefresh();
}

function refresh() {
    if(isRefreshing) return;
    isRefreshing = true;

    var url = 'live_floor.php?ajax=1&date=' + currentDate + '&_=' + Date.now();
    if (currentMode === 'multi') {
        url += '&all_floors=1';
    }

    fetch(url)
        .then(function(r){ return r.json(); })
        .then(function(d) {
            isRefreshing = false;
            document.getElementById('last-update').textContent = d.time;

            if (d.multi_mode && d.floors_data) {
                renderMultiFloorMatrix(d.floors_data, d.totals);
                return;
            }

            var s = d.summary || {};
            var sold   = parseInt(s.sold||0);
            var total  = parseInt(s.total_bales||0);
            var unsold = Math.max(0, parseInt(s.ticketed||0) - sold);
            var usd    = parseFloat(s.usd_value||0);
            var er     = parseFloat(d.er||0);

            document.getElementById('kpi-weighed').textContent = (total||0).toLocaleString('en');
            document.getElementById('kpi-sold').textContent    = sold.toLocaleString('en');
            document.getElementById('kpi-unsold').textContent  = unsold.toLocaleString('en');
            document.getElementById('kpi-mass').textContent    = fmt(s.sold_mass, 0) + ' kg';
            document.getElementById('kpi-usd').textContent     = '$' + fmt(usd, 2);
            if(er>0) document.getElementById('kpi-usd-sub').textContent = 'ZiG ' + fmt(usd*er,0);
            document.getElementById('kpi-growers').textContent = fmt(s.growers);
            document.getElementById('kpi-hi').textContent  = '$' + fmt(s.hi_price,4);
            document.getElementById('kpi-lo').textContent  = '$' + fmt(s.lo_price,4);
            document.getElementById('kpi-avg').textContent = '$' + fmt(s.avg_price,4);

            // Progress bar
            var pct = total > 0 ? Math.round(sold/total*100) : 0;
            document.getElementById('progress-bar').style.width = pct + '%';
            document.getElementById('progress-pct').textContent = pct>5 ? pct+'%' : '';
            document.getElementById('progress-label').textContent = sold + ' of ' + total + ' bales sold';
            document.getElementById('progress-max').textContent = total + ' bales';

            // Sales feed
            var feed = document.getElementById('sales-feed');
            if(d.recent && d.recent.length) {
                feed.innerHTML = d.recent.map(function(r) {
                    var p = parseFloat(r.price); if(p>10) p/=100;
                    return '<div class="feed-row">' +
                        '<span style="font-size:.65rem;color:var(--muted);width:52px;flex-shrink:0">' + r.sold_at + '</span>' +
                        '<span class="badge badge-gold" style="font-size:.72rem;flex-shrink:0">' + r.grower_num + '</span>' +
                        '<span style="flex:1;font-size:.75rem">' + r.name + ' ' + r.surname + '</span>' +
                        '<span style="font-size:.72rem;color:var(--muted)">' + (r.grade||'') + '</span>' +
                        '<span style="font-size:.72rem">' + parseFloat(r.mass).toFixed(1) + ' kg</span>' +
                        '<span class="td-mono fw-500" style="font-size:.72rem;color:var(--leaf)">$' + p.toFixed(4) + '</span>' +
                    '</div>';
                }).join('');
            } else {
                feed.innerHTML = '<div class="text-center text-muted" style="padding:2rem">No sales yet today.</div>';
            }

            // Buyer table
            var bt = document.getElementById('buyer-table');
            if(d.by_buyer && d.by_buyer.length) {
                bt.innerHTML = '<table><thead><tr><th>Buyer</th><th class="text-right">Bales</th><th class="text-right">USD</th></tr></thead><tbody>' +
                    d.by_buyer.map(function(b){
                        return '<tr><td style="font-size:.75rem">' + b.buyer + '</td>' +
                               '<td class="td-mono text-right">' + b.bales + '</td>' +
                               '<td class="td-mono text-right fw-500">$' + fmt(b.usd,2) + '</td></tr>';
                    }).join('') + '</tbody></table>';
            } else { bt.innerHTML = '<div class="text-center text-muted" style="padding:1rem">No sales.</div>'; }

            // Location table
            var lt = document.getElementById('loc-table');
            if(d.by_loc && d.by_loc.length) {
                lt.innerHTML = '<table><thead><tr><th>Location</th><th class="text-right">Bales</th><th class="text-right">USD</th></tr></thead><tbody>' +
                    d.by_loc.map(function(l){
                        return '<tr><td><span style="font-family:\'Bebas Neue\',sans-serif;font-size:1rem;color:var(--gold)">' + l.loc + '</span></td>' +
                               '<td class="td-mono text-right">' + l.bales + '</td>' +
                               '<td class="td-mono text-right fw-500">$' + fmt(l.usd,2) + '</td></tr>';
                    }).join('') + '</tbody></table>';
            } else { lt.innerHTML = '<div class="text-center text-muted" style="padding:1rem">No location data.</div>'; }
        })
        .catch(function(e){ isRefreshing = false; console.error(e); });
}

function renderMultiFloorMatrix(floors, totals) {
    var grid = document.getElementById('multi-floor-grid');
    if (!floors || !floors.length) {
        grid.innerHTML = '<div class="text-center text-muted" style="padding:2rem">No registered floors.</div>';
        return;
    }

    grid.innerHTML = floors.map(function(fl) {
        var s = fl.summary || {};
        var total  = parseInt(s.total_bales||0);
        var sold   = parseInt(s.sold||0);
        var unsold = Math.max(0, parseInt(s.ticketed||0) - sold);
        var usd    = parseFloat(s.usd_value||0);
        var avgP   = parseFloat(s.avg_price||0);

        return '<div class="multi-floor-card">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;border-bottom:1px solid var(--border);padding-bottom:.5rem">' +
                '<div>' +
                    '<strong style="font-size:.95rem;color:var(--gold-l)">' + fl.name + '</strong>' +
                    '<div style="font-size:.65rem;color:var(--muted)">' + (fl.floor_code || 'FL#'+fl.id) + '</div>' +
                '</div>' +
                '<span class="badge badge-gold" style="font-size:.72rem">● ACTIVE</span>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.75rem;font-size:.78rem">' +
                '<div><span style="font-size:.65rem;color:var(--muted);display:block">BALES WEIGHED</span><strong style="font-size:1.1rem">' + total.toLocaleString('en') + '</strong></div>' +
                '<div><span style="font-size:.65rem;color:var(--muted);display:block">BALES SOLD</span><strong style="font-size:1.1rem;color:var(--leaf)">' + sold.toLocaleString('en') + '</strong></div>' +
                '<div><span style="font-size:.65rem;color:var(--muted);display:block">REVENUE (USD)</span><strong style="font-size:1.1rem;color:var(--gold)">$' + fmt(usd, 2) + '</strong></div>' +
                '<div><span style="font-size:.65rem;color:var(--muted);display:block">AVG PRICE</span><strong style="font-size:1.1rem">$' + fmt(avgP, 4) + '</strong></div>' +
            '</div>' +
            '<div style="display:flex;gap:.4rem">' +
                '<form method="POST" action="switch_floor.php" style="flex:1">' +
                    '<?php echo csrf_field(); ?>' +
                    '<input type="hidden" name="selling_pointid" value="' + fl.id + '">' +
                    '<button type="submit" class="btn btn-ghost btn-sm" style="width:100%;font-size:.68rem">Focus Floor &rarr;</button>' +
                '</form>' +
            '</div>' +
        '</div>';
    }).join('');
}

function forceRefresh(){ clearInterval(refreshTimer); refresh(); refreshTimer = setInterval(refresh,30000); }

// Start
forceRefresh();

// Date picker
document.getElementById('date-picker').addEventListener('change', function(){
    currentDate = this.value;
    forceRefresh();
});
</script>

<?php include "includes/layout_end.php"; ?>
