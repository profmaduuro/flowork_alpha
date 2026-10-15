<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Management Dashboard";
$active_page = "management_dashboard";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$sp_name     = htmlspecialchars($_SESSION['selling_point_name'] ?? '');

function safe($c,$s){$r=$c->query($s);return($r&&$r!==true)?$r->fetch_all(MYSQLI_ASSOC):[];}
function safeVal($c,$s){$r=$c->query($s);return($r&&$r!==true)?($r->fetch_assoc()??[]):[];}

$today      = date('Y-m-d');
$yr         = (int)date('Y');
$yr_prev    = $yr - 1;
$month_start= date('Y-m-01');
$week_start = date('Y-m-d', strtotime('monday this week'));

// Current season stats
$season = safeVal($conn,"
    SELECT COUNT(DISTINCT sb.id) bales_sold,
           COALESCE(SUM(bm.mass),0) mass,
           COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd,
           COUNT(DISTINCT g.id) growers,
           COUNT(DISTINCT b.id) buyers,
           COUNT(DISTINCT DATE(sb.created_at)) sell_days,
           MAX(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END) hi_price,
           AVG(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END) avg_price
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
    JOIN buyer b ON b.id=bg.buyerid
    WHERE YEAR(DATE(sb.created_at))=$yr AND g.selling_pointid=$spid
");

// Today stats
$today_s = safeVal($conn,"
    SELECT COUNT(DISTINCT sb.id) bales_sold,
           COUNT(DISTINCT tk.id) ticketed,
           COALESCE(SUM(bm.mass),0) mass,
           COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd
    FROM tickets tk
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
    WHERE DATE(tk.created_at)='$today' AND g.selling_pointid=$spid
");

// Week stats
$week_s = safeVal($conn,"
    SELECT COUNT(DISTINCT sb.id) bales_sold,
           COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    WHERE DATE(sb.created_at) >= '$week_start' AND g.selling_pointid=$spid
");

// Previous season for comparison
$prev_season = safeVal($conn,"
    SELECT COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd,
           COUNT(DISTINCT sb.id) bales_sold,
           COALESCE(SUM(bm.mass),0) mass
    FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    WHERE YEAR(DATE(sb.created_at))=$yr_prev AND g.selling_pointid=$spid
");

// Exchange rate
$er_q = $conn->query("SELECT amount FROM exchange_rate WHERE selling_pointid=$spid AND active=1 ORDER BY id DESC LIMIT 1");
$zig_rate = ($er_q&&$er_q!==true)?(float)($er_q->fetch_assoc()['amount']??0):0;

// Day status
$day_q = $conn->query("SELECT status FROM selling_days WHERE selling_pointid=$spid AND sell_date='$today' LIMIT 1");
$day_status = ($day_q&&$day_q!==true)?($day_q->fetch_assoc()['status']??null):null;

// Top buyers this season
$top_buyers = safe($conn,"
    SELECT b.name buyer, COUNT(sb.id) bales,
           COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd
    FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid JOIN buyer b ON b.id=bg.buyerid
    WHERE YEAR(DATE(sb.created_at))=$yr AND g.selling_pointid=$spid
    GROUP BY b.id ORDER BY usd DESC LIMIT 8
");

// Grade breakdown this season
$grades = safe($conn,"
    SELECT tg.description grade, COUNT(sb.id) bales,
           COALESCE(SUM(bm.mass),0) mass,
           COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) avg_price
    FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    WHERE YEAR(DATE(sb.created_at))=$yr AND g.selling_pointid=$spid
    GROUP BY tg.id ORDER BY bales DESC
");

// Last 14 days trend
$trend = safe($conn,"
    SELECT DATE(sb.created_at) dt,
           COUNT(sb.id) bales,
           COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd
    FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    WHERE DATE(sb.created_at) >= DATE_SUB('$today',INTERVAL 13 DAY) AND g.selling_pointid=$spid
    GROUP BY DATE(sb.created_at) ORDER BY dt
");

// Pending payments
$pend_pay = safeVal($conn,"SELECT COUNT(*) c, COALESCE(SUM(net_amount),0) amt FROM payment_status WHERE selling_pointid=$spid AND status='pending'");

function pct($a,$b){return $b>0?round(($a-$b)/$b*100,1):($a>0?100:0);}
function arrow($v){$c=$v>0?'#4caf50':'#e53935';$d=$v>0?'▲':'▼';return "<span style='color:$c;font-size:.72rem'>$d ".abs($v)."%</span>";}

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Management Dashboard</h2>
    <p><?=$sp_name?> · <?=date('l d F Y')?></p>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <?php
    $ds_color = $day_status==='open'?'var(--leaf)':($day_status==='locked'?'var(--danger)':'var(--muted)');
    $ds_icon  = $day_status==='open'?'🟢':($day_status==='locked'?'🔒':($day_status==='closed'?'🔴':'⚪'));
    ?>
    <span style="font-size:.78rem;color:var(--muted)">
      Day: <span style="color:<?=$ds_color?>;font-weight:600"><?=$ds_icon?> <?=ucfirst($day_status??'Not opened')?></span>
    </span>
    <?php if($zig_rate>0):?>
    <span style="font-size:.72rem;color:var(--muted)">| ZiG <?=number_format($zig_rate,4)?>/USD</span>
    <?php endif;?>
    <a href="day_control.php" class="btn btn-ghost btn-sm">Day Control</a>
    <a href="live_floor.php" class="btn btn-primary btn-sm">📡 Live Floor</a>
  </div>
</div>

<!-- TODAY strip -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.6rem;margin-bottom:.75rem">
<?php
$today_kpis = [
    ['Today Ticketed', number_format((int)($today_s['ticketed']??0)), 'bales on floor'],
    ['Today Sold',     number_format((int)($today_s['bales_sold']??0)), 'bales sold'],
    ['Today Mass',     number_format((float)($today_s['mass']??0),0).' kg', ''],
    ['Today USD',      '$'.number_format((float)($today_s['usd']??0),0), $zig_rate>0?'ZiG '.number_format((float)($today_s['usd']??0)*$zig_rate,0):''],
];
foreach($today_kpis as $k):?>
<div style="background:var(--cream-d);border:1px solid var(--border);border-radius:var(--radius);padding:.65rem .85rem">
  <div style="font-size:.6rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)"><?=$k[0]?></div>
  <div style="font-size:1.3rem;font-weight:700;font-family:'Bebas Neue',sans-serif;color:var(--gold);margin-top:2px"><?=$k[1]?></div>
  <?php if($k[2]):?><div style="font-size:.65rem;color:var(--muted)"><?=$k[2]?></div><?php endif;?>
</div>
<?php endforeach;?>
</div>

<!-- SEASON KPIs -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1.25rem">
<?php
$usd_curr = (float)($season['usd']??0);
$usd_prev = (float)($prev_season['usd']??0);
$bales_curr = (int)($season['bales_sold']??0);
$bales_prev = (int)($prev_season['bales_sold']??0);
$season_kpis = [
    ['Season '.$yr.' Bales',    number_format($bales_curr),  arrow(pct($bales_curr,$bales_prev)).' vs '.$yr_prev],
    ['Season '.$yr.' Mass',     number_format((float)($season['mass']??0),0).' kg', ''],
    ['Season '.$yr.' USD',      '$'.number_format($usd_curr,0), arrow(pct($usd_curr,$usd_prev)).' vs '.$yr_prev],
    ['Avg Price $/kg',           '$'.number_format((float)($season['avg_price']??0),4), 'Hi $'.number_format((float)($season['hi_price']??0),4)],
];
foreach($season_kpis as $k):?>
<div class="kpi-card" style="--kpi-color:var(--gold)">
  <div class="kpi-label"><?=$k[0]?></div>
  <div class="kpi-value"><?=$k[1]?></div>
  <div class="kpi-sub"><?=$k[2]?></div>
</div>
<?php endforeach;?>
</div>

<!-- Charts row -->
<div class="grid-2" style="align-items:start;gap:1rem;margin-bottom:1rem">

<!-- 14-day trend -->
<div class="card">
<div class="card-head"><h3>14-Day Revenue Trend</h3></div>
<div class="card-body" style="padding:.75rem"><div style="position:relative;height:180px"><canvas id="trendChart"></canvas></div></div>
</div>

<!-- Buyer share -->
<div class="card">
<div class="card-head"><h3>Buyer Share — <?=$yr?></h3></div>
<div class="card-body" style="padding:.75rem"><div style="position:relative;height:180px"><canvas id="buyerChart"></canvas></div></div>
</div>

</div>

<!-- Bottom tables -->
<div class="grid-2" style="align-items:start;gap:1rem">

<!-- Top buyers -->
<div class="card">
<div class="card-head"><h3>Top Buyers — <?=$yr?></h3></div>
<div class="table-wrap"><table>
<thead><tr><th>Buyer</th><th class="text-right">Bales</th><th class="text-right">USD Value</th></tr></thead>
<tbody>
<?php $total_buyer_usd = array_sum(array_column($top_buyers,'usd'))?:1;
foreach($top_buyers as $b):
    $pct_share = round($b['usd']/$total_buyer_usd*100,1);
?>
<tr>
  <td>
    <div style="font-size:.78rem;font-weight:500"><?=htmlspecialchars($b['buyer'])?></div>
    <div style="height:4px;background:var(--cream-d);border-radius:2px;margin-top:3px;width:100%">
      <div style="height:4px;width:<?=$pct_share?>%;background:var(--gold);border-radius:2px"></div>
    </div>
  </td>
  <td class="td-mono text-right"><?=number_format((int)$b['bales'])?></td>
  <td class="td-mono text-right fw-500">$<?=number_format((float)$b['usd'],0)?><br><span style="font-size:.65rem;color:var(--muted)"><?=$pct_share?>%</span></td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
</div>

<!-- Grade breakdown -->
<div class="card">
<div class="card-head"><h3>Grade Breakdown — <?=$yr?></h3></div>
<div class="table-wrap"><table>
<thead><tr>
  <th>Grade</th><th class="text-right">Bales</th>
  <th class="text-right">Mass (kg)</th><th class="text-right">Avg $/kg</th>
</tr></thead>
<tbody>
<?php foreach($grades as $gr):?>
<tr>
  <td><span class="badge badge-gold" style="font-size:.6rem"><?=htmlspecialchars($gr['grade'])?></span></td>
  <td class="td-mono text-right"><?=number_format((int)$gr['bales'])?></td>
  <td class="td-mono text-right"><?=number_format((float)$gr['mass'],0)?></td>
  <td class="td-mono text-right">$<?=number_format((float)$gr['avg_price'],4)?></td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
</div>

</div>

<!-- Quick links -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem;padding:.75rem;background:var(--cream-d);border-radius:var(--radius);border:1px solid var(--border)">
  <span style="font-size:.7rem;color:var(--muted);align-self:center;margin-right:.25rem">Quick links:</span>
  <?php foreach([
    ['live_floor.php','📡 Live Floor'],
    ['season_compare.php','📊 Season Compare'],
    ['bank_file.php','🏦 Bank File'],
    ['eod_reports.php','📋 EOD Reports'],
    ['dispatch.php','🚛 Dispatch'],
    ['audit_log.php','📋 Audit Log'],
    ['day_control.php','📅 Day Control'],
  ] as $lk):?>
  <a href="<?=$lk[0]?>" class="btn btn-ghost btn-sm" style="font-size:.72rem"><?=$lk[1]?></a>
  <?php endforeach;?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
// Trend chart
var trendLabels = <?=json_encode(array_map(fn($r)=>date('d M',strtotime($r['dt'])),$trend))?>;
var trendData   = <?=json_encode(array_map(fn($r)=>(float)$r['usd'],$trend))?>;
new Chart(document.getElementById('trendChart'),{
    type:'bar',
    data:{ labels:trendLabels, datasets:[{ label:'USD Value', data:trendData, backgroundColor:'rgba(200,149,42,.75)', borderRadius:4 }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}},
        scales:{ x:{grid:{display:false},ticks:{font:{size:9}}},
                 y:{ticks:{callback:function(v){return '$'+Math.round(v/1000)+'k';},font:{size:9}},grid:{color:'rgba(128,128,128,.08)'}} } }
});
// Buyer donut
var buyerLabels = <?=json_encode(array_column($top_buyers,'buyer'))?>;
var buyerData   = <?=json_encode(array_map(fn($b)=>(float)$b['usd'],$top_buyers))?>;
var buyerColors = ['#c8952a','#3d6b35','#e8b84b','#6a9b5f','#a07020','#2d5a25','#d4a84b','#4a7d3a'];
new Chart(document.getElementById('buyerChart'),{
    type:'doughnut',
    data:{ labels:buyerLabels, datasets:[{ data:buyerData, backgroundColor:buyerColors, borderWidth:2 }] },
    options:{ responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ position:'right', labels:{ font:{size:9}, boxWidth:10, padding:8 } } } }
});
</script>

<?php include "includes/layout_end.php"; ?>
