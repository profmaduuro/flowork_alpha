<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title="Analytics";$active_page="analytics";
$spid=(int)($_SESSION['selling_pointid']??0);$yr=(int)date('Y');
$today=date('Y-m-d');

function safe($c,$s){$r=$c->query($s);return($r&&$r!==true)?$r->fetch_all(MYSQLI_ASSOC):[];}

// Price trends — daily avg price for current season
$price_trend=safe($conn,"SELECT DATE(sb.created_at) dt,AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END) avg_p,MAX(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END) hi_p,MIN(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END) lo_p,COUNT(sb.id) bales FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN growers g ON g.id=bj.growerid WHERE YEAR(sb.created_at)=$yr AND g.selling_pointid=$spid GROUP BY DATE(sb.created_at) ORDER BY dt");

// Grade trend — monthly bales per top grade
$grade_trend=safe($conn,"SELECT MONTH(sb.created_at) mo,tg.description grade,COUNT(sb.id) bales,AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END) avg_p FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN growers g ON g.id=bj.growerid JOIN timb_grades tg ON tg.id=sb.timb_gradesid WHERE YEAR(sb.created_at)=$yr AND g.selling_pointid=$spid GROUP BY MONTH(sb.created_at),tg.id ORDER BY mo,bales DESC");

// Grade mix pie
$grade_mix=safe($conn,"SELECT tg.description grade,COUNT(sb.id) bales,COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN bale_mass bm ON bm.id=bj.bale_massid JOIN growers g ON g.id=bj.growerid JOIN timb_grades tg ON tg.id=sb.timb_gradesid WHERE YEAR(sb.created_at)=$yr AND g.selling_pointid=$spid GROUP BY tg.id ORDER BY usd DESC");

// Season forecast (simple linear regression on daily totals)
$totals=array_map(fn($r)=>(float)$r['bales'],$price_trend);$n=count($totals);
$season_days=365-date('z');// days left in year
$avg_per_day=$n>0?array_sum($totals)/$n:0;
$projected_extra=round($avg_per_day*min($season_days,60));
$total_bales_yr=array_sum($totals);
$projected_total=$total_bales_yr+$projected_extra;

// Location performance
$loc_perf=safe($conn,"SELECT bl.code,bl.name loc_name,COUNT(sb.id) bales,COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd,AVG(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END) avg_p FROM bale_locations bl LEFT JOIN transporter t ON t.location_id=bl.id LEFT JOIN transporter_growers tg ON tg.transporterid=t.id LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id LEFT JOIN tickets tk ON tk.bale_junusid=bj.id LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id LEFT JOIN bale_mass bm ON bm.id=bj.bale_massid LEFT JOIN growers g ON g.id=bj.growerid AND g.selling_pointid=$spid WHERE bl.selling_pointid=$spid AND YEAR(sb.created_at)=$yr GROUP BY bl.id ORDER BY usd DESC");

// Province breakdown
$provs=safe($conn,"SELECT COALESCE(NULLIF(TRIM(g.province),''),'Unknown') province,COUNT(sb.id) bales,COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN bale_mass bm ON bm.id=bj.bale_massid JOIN growers g ON g.id=bj.growerid WHERE YEAR(sb.created_at)=$yr AND g.selling_pointid=$spid GROUP BY province ORDER BY usd DESC LIMIT 10");

// Multi-year price comparison
$yoy_prices=safe($conn,"SELECT YEAR(sb.created_at) yr,AVG(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END) avg_p,COUNT(sb.id) bales FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN growers g ON g.id=bj.growerid WHERE g.selling_pointid=$spid AND sb.price>0 GROUP BY YEAR(sb.created_at) ORDER BY yr DESC LIMIT 5");

include "includes/layout.php";
?>
<style>
.chart-card{background:var(--cream);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:1.25rem}
.chart-head{padding:.75rem 1.15rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.chart-head h3{font-size:.9rem;margin:0}
.chart-body{padding:.85rem 1.15rem}
</style>

<div class="page-header">
  <div class="page-header-left"><h2>Analytics</h2><p>Price trends, grade analysis and season forecasts — <?=$yr?> season</p></div>
  <a href="season_compare.php" class="btn btn-ghost btn-sm">→ Season Compare</a>
</div>

<!-- Forecast strip -->
<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.25rem">
  <div class="kpi-card"><div class="kpi-label">Bales Sold YTD</div><div class="kpi-value"><?=number_format($total_bales_yr)?></div><div class="kpi-sub"><?=$n?> selling days</div></div>
  <div class="kpi-card"><div class="kpi-label">Avg/Day</div><div class="kpi-value"><?=number_format($avg_per_day,1)?></div><div class="kpi-sub">bales per sell day</div></div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)"><div class="kpi-label">60-Day Forecast</div><div class="kpi-value">+<?=number_format($projected_extra)?></div><div class="kpi-sub">projected additional</div></div>
  <div class="kpi-card" style="--kpi-color:var(--gold)"><div class="kpi-label">Season Projection</div><div class="kpi-value"><?=number_format($projected_total)?></div><div class="kpi-sub">total bales est.</div></div>
</div>

<!-- Price trend chart -->
<div class="chart-card">
<div class="chart-head"><h3>📈 Daily Price Trend — <?=$yr?></h3>
  <?php if(!empty($price_trend)):$last=end($price_trend);?>
  <span class="badge badge-gold" style="font-size:.72rem">Latest avg: $<?=number_format((float)$last['avg_p'],4)?>/kg</span>
  <?php endif;?>
</div>
<div class="chart-body"><div style="position:relative;height:220px"><canvas id="priceChart"></canvas></div></div>
</div>

<div class="grid-2" style="gap:1.25rem">

<!-- Grade mix donut -->
<div class="chart-card">
<div class="chart-head"><h3>🌿 Grade Mix — <?=$yr?></h3></div>
<div class="chart-body" style="display:flex;gap:1rem;align-items:center">
  <div style="flex:1;height:200px;position:relative"><canvas id="gradeChart"></canvas></div>
  <div style="width:140px">
    <?php $gt=array_sum(array_column($grade_mix,'bales'))?:1;
    foreach(array_slice($grade_mix,0,8) as $g):$pct=round($g['bales']/$gt*100,1);?>
    <div style="display:flex;justify-content:space-between;font-size:.72rem;margin-bottom:4px">
      <span class="badge badge-gold" style="font-size:.58rem"><?=htmlspecialchars($g['grade'])?></span>
      <span><?=$pct?>%</span>
    </div>
    <?php endforeach;?>
  </div>
</div>
</div>

<!-- YoY avg price -->
<div class="chart-card">
<div class="chart-head"><h3>📊 Year-on-Year Avg Price</h3></div>
<div class="chart-body">
  <div style="position:relative;height:200px"><canvas id="yoyChart"></canvas></div>
</div>
</div>

<!-- Location performance -->
<div class="chart-card">
<div class="chart-head"><h3>📍 Location Performance — <?=$yr?></h3></div>
<div class="chart-body"><div style="position:relative;height:200px"><canvas id="locChart"></canvas></div></div>
</div>

<!-- Province breakdown -->
<div class="chart-card">
<div class="chart-head"><h3>🗺 By Province — <?=$yr?></h3></div>
<div class="chart-body">
<table style="font-size:.78rem;width:100%">
<thead><tr><th>Province</th><th class="text-right">Bales</th><th class="text-right">USD</th><th>Share</th></tr></thead>
<tbody>
<?php $pt=array_sum(array_column($provs,'usd'))?:1;
foreach($provs as $p):$pct=round($p['usd']/$pt*100,1);?>
<tr>
  <td><?=htmlspecialchars($p['province'])?></td>
  <td class="td-mono text-right"><?=number_format((int)$p['bales'])?></td>
  <td class="td-mono text-right fw-500">$<?=number_format((float)$p['usd'],0)?></td>
  <td><div style="height:6px;background:var(--cream-d);border-radius:3px;width:100px;display:inline-block">
    <div style="height:6px;width:<?=$pct?>%;background:var(--gold);border-radius:3px"></div>
  </div> <?=$pct?>%</td>
</tr>
<?php endforeach;?>
</tbody>
</table>
</div>
</div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
if (typeof Chart !== 'undefined') {
var ptDates=<?=json_encode(array_column($price_trend,'dt'))?>;
var ptAvg  =<?=json_encode(array_map(fn($r)=>round((float)$r['avg_p'],4),$price_trend))?>;
var ptHi   =<?=json_encode(array_map(fn($r)=>round((float)$r['hi_p'],4),$price_trend))?>;
var ptLo   =<?=json_encode(array_map(fn($r)=>round((float)$r['lo_p'],4),$price_trend))?>;

// Price trend
new Chart(document.getElementById('priceChart'),{type:'line',data:{labels:ptDates,datasets:[
    {label:'Avg $/kg',data:ptAvg,borderColor:'#c8952a',backgroundColor:'rgba(200,149,42,.08)',tension:.35,pointRadius:2,borderWidth:2,fill:true},
    {label:'Hi $/kg',data:ptHi,borderColor:'#3d6b35',borderDash:[4,4],tension:.35,pointRadius:0,borderWidth:1},
    {label:'Lo $/kg',data:ptLo,borderColor:'#e53935',borderDash:[4,4],tension:.35,pointRadius:0,borderWidth:1},
]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{font:{size:10},boxWidth:12}}},
    scales:{x:{ticks:{maxRotation:45,font:{size:9}},grid:{display:false}},y:{ticks:{callback:v=>'$'+v.toFixed(4),font:{size:9}},grid:{color:'rgba(128,128,128,.08)'}}}}});

// Grade donut
var gradeLabels=<?=json_encode(array_column($grade_mix,'grade'))?>;
var gradeBales=<?=json_encode(array_map(fn($g)=>(int)$g['bales'],$grade_mix))?>;
var gradeColors=['#c8952a','#3d6b35','#e8b84b','#6a9b5f','#a07020','#2d5a25','#d4a84b','#4a7d3a','#888','#555'];
new Chart(document.getElementById('gradeChart'),{type:'doughnut',data:{labels:gradeLabels,datasets:[{data:gradeBales,backgroundColor:gradeColors,borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}}}});

// YoY price bar
var yoyLabels=<?=json_encode(array_column($yoy_prices,'yr'))?>.reverse();
var yoyPrices=<?=json_encode(array_map(fn($r)=>round((float)$r['avg_p'],4),$yoy_prices))?>.reverse();
new Chart(document.getElementById('yoyChart'),{type:'bar',data:{labels:yoyLabels,datasets:[{label:'Avg $/kg',data:yoyPrices,backgroundColor:'rgba(200,149,42,.75)',borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{font:{size:10}},grid:{display:false}},y:{ticks:{callback:v=>'$'+v.toFixed(4),font:{size:9}},grid:{color:'rgba(128,128,128,.08)'}}}}});

var locLabels=<?=json_encode(array_column($loc_perf,'loc_name'))?>;
var locUsd=<?=json_encode(array_column($loc_perf,'usd'))?>;
new Chart(document.getElementById('locChart'),{type:'bar',data:{labels:locLabels,datasets:[{label:'USD Value',data:locUsd,backgroundColor:'rgba(61,107,53,.75)',borderRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{font:{size:10}},grid:{display:false}},y:{ticks:{callback:v=>'$'+Math.round(v/1000)+'k',font:{size:9}},grid:{color:'rgba(128,128,128,.08)'}}}}});
}
</script>

<?php include "includes/layout_end.php";?>
