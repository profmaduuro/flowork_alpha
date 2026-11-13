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

// Selected period — like dashboard.php, default to this floor's open selling day
// (start_of_day active=1, description = the sell date), else today.
// ?date=Y-m-d picks a day; ?period=week|month|ytd widens it to the range ending on that day.
$open_sod  = safeVal($conn,"SELECT id, description FROM start_of_day WHERE active=1 AND selling_pointid=$spid ORDER BY id DESC LIMIT 1");
$open_day  = (string)($open_sod['description'] ?? '');
$is_date   = fn($d)=>preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$d,$m) && checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
if (!$is_date($open_day)) $open_day = '';
$sel_date  = trim((string)($_GET['date'] ?? ''));
if (!$is_date($sel_date)) $sel_date = $open_day ?: $today;
$periods   = ['day'=>'Selected day','week'=>'Week to date','month'=>'Month to date','ytd'=>'Year to date'];
$period    = isset($periods[$_GET['period'] ?? '']) ? $_GET['period'] : 'day';
$sel_ts    = strtotime($sel_date);
$date_to   = $sel_date;
$date_from = match($period){
    'week'  => date('Y-m-d', strtotime('monday this week', $sel_ts)),
    'month' => date('Y-m-01', $sel_ts),
    'ytd'   => date('Y-01-01', $sel_ts),
    default => $sel_date,
};
$yr         = (int)date('Y', $sel_ts);
$yr_prev    = $yr - 1;
// Same range one year back, for the comparison arrows
$prev_from  = date('Y-m-d', strtotime($date_from.' -1 year'));
$prev_to    = date('Y-m-d', strtotime($date_to.' -1 year'));
$per_label  = $period==='day' ? date('d M Y', $sel_ts) : $periods[$period].' '.date('d M', strtotime($date_from)).' – '.date('d M Y', $sel_ts);
$sold_in    = "DATE(sb.created_at) BETWEEN '$date_from' AND '$date_to'";

// Selling days in the period — tickets match by start_of_dayid, fallback to date
$sod_ids = array_map('intval', array_column(safe($conn,"SELECT id FROM start_of_day WHERE selling_pointid=$spid AND description BETWEEN '$date_from' AND '$date_to'"),'id'));
$tk_in   = "DATE(tk.created_at) BETWEEN '$date_from' AND '$date_to'";
if ($sod_ids) $tk_in = "(tk.start_of_dayid IN (".implode(',',$sod_ids).") OR $tk_in)";

// Period sales
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
    WHERE $sold_in AND g.selling_pointid=$spid
");

// Bales ticketed on the period's selling days
$ticketed = safeVal($conn,"
    SELECT COUNT(DISTINCT tk.id) ticketed
    FROM tickets tk
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    WHERE $tk_in AND g.selling_pointid=$spid
");

// Same period last year, for comparison
$prev_season = safeVal($conn,"
    SELECT COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd,
           COUNT(DISTINCT sb.id) bales_sold,
           COALESCE(SUM(bm.mass),0) mass
    FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    WHERE DATE(sb.created_at) BETWEEN '$prev_from' AND '$prev_to' AND g.selling_pointid=$spid
");

// Exchange rate
$er_q = $conn->query("SELECT amount FROM exchange_rate WHERE selling_pointid=$spid AND active=1 ORDER BY id DESC LIMIT 1");
$zig_rate = ($er_q&&$er_q!==true)?(float)($er_q->fetch_assoc()['amount']??0):0;

// Day status — the selected date's selling day
$day_q = $conn->query("SELECT status FROM selling_days WHERE selling_pointid=$spid AND sell_date='$sel_date' LIMIT 1");
$day_status = ($day_q&&$day_q!==true)?($day_q->fetch_assoc()['status']??null):null;
if ($open_day===$sel_date) $day_status = 'open';

// Top buyers in the period
$top_buyers = safe($conn,"
    SELECT b.name buyer, COUNT(sb.id) bales,
           COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd
    FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid JOIN buyer b ON b.id=bg.buyerid
    WHERE $sold_in AND g.selling_pointid=$spid
    GROUP BY b.id ORDER BY usd DESC LIMIT 8
");

// Grade breakdown in the period
$grades = safe($conn,"
    SELECT tg.description grade, COUNT(sb.id) bales,
           COALESCE(SUM(bm.mass),0) mass,
           COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) avg_price
    FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    WHERE $sold_in AND g.selling_pointid=$spid
    GROUP BY tg.id ORDER BY bales DESC
");

// Revenue trend — a single day shows the 14 days up to it, a range shows every day in it
$trend_from = $period==='day' ? date('Y-m-d', strtotime($sel_date.' -13 days')) : $date_from;
$trend_title = $period==='day' ? '14-Day Revenue Trend' : 'Daily Revenue — '.$periods[$period];
$trend = safe($conn,"
    SELECT DATE(sb.created_at) dt,
           COUNT(sb.id) bales,
           COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd
    FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    WHERE DATE(sb.created_at) BETWEEN '$trend_from' AND '$date_to' AND g.selling_pointid=$spid
    GROUP BY DATE(sb.created_at) ORDER BY dt
");

// Pending payments
$pend_pay = safeVal($conn,"SELECT COUNT(*) c, COALESCE(SUM(net_amount),0) amt FROM payment_status WHERE selling_pointid=$spid AND status='pending'");

function pct($a,$b){return $b>0?round(($a-$b)/$b*100,1):($a>0?100:0);}
function arrow($v){$c=$v>0?'var(--ok-text)':'var(--bad-text)';$d=$v>0?'▲':'▼';return "<span style='color:$c;font-size:.72rem'>$d ".abs($v)."%</span>";}

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Management Dashboard</h2>
    <p><?=$sp_name?> · <?=htmlspecialchars($per_label)?><?=$open_day===''?' · no selling day open':''?></p>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
    <form method="GET" style="display:flex;gap:.4rem;align-items:center;margin:0">
      <input type="date" name="date" value="<?=$sel_date?>" class="form-control" style="padding:.3rem .5rem;font-size:.78rem;width:auto" onchange="this.form.submit()">
      <select name="period" class="form-control" style="padding:.3rem .5rem;font-size:.78rem;width:auto" onchange="this.form.submit()">
        <?php foreach($periods as $pk=>$pl):?>
        <option value="<?=$pk?>" <?=$period===$pk?'selected':''?>><?=$pl?></option>
        <?php endforeach;?>
      </select>
      <?php if($sel_date!==($open_day ?: $today) || $period!=='day'):?>
      <a href="management_dashboard.php" class="btn btn-ghost btn-sm"><?=$open_day?'Open day':'Today'?></a>
      <?php endif;?>
    </form>
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

<style>
.md-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:.6rem}
.md-kpis .kpi-sub{min-height:1em}
.md-strip{display:flex;flex-wrap:wrap;gap:0;margin-bottom:1.1rem;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);overflow:hidden}
.md-strip .it{flex:1 1 120px;padding:.55rem .9rem;border-right:1px solid var(--line);min-width:0}
.md-strip .it:last-child{border-right:0}
.md-strip .lb{font-size:.7rem;color:var(--muted);font-weight:600;white-space:nowrap}
.md-strip .vl{font-size:1rem;font-weight:700;font-variant-numeric:tabular-nums;margin-top:1px;white-space:nowrap}
.md-strip .vl small{font-size:.7rem;font-weight:500;color:var(--muted);margin-left:.25rem}
.md-strip a.it{color:inherit;text-decoration:none}
.md-strip a.it:hover{background:var(--cream-d)}
.md-row{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:1rem;margin-bottom:1rem;align-items:stretch}
.md-row>.card{display:flex;flex-direction:column;min-width:0}
.md-buyers{padding:.4rem 1rem .8rem;overflow:auto;max-height:260px}
.md-buyer{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.15rem .75rem;padding:.5rem 0;border-bottom:1px solid var(--line)}
.md-buyer:last-child{border-bottom:0}
.md-buyer .nm{font-size:.82rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.md-buyer .usd{font-size:.82rem;font-weight:600;font-variant-numeric:tabular-nums;text-align:right}
.md-buyer .bar{grid-column:1/-1;height:5px;background:var(--cream-d);border-radius:3px;overflow:hidden}
.md-buyer .bar span{display:block;height:100%;background:var(--gold);border-radius:3px}
.md-buyer .meta{grid-column:1/-1;font-size:.7rem;color:var(--muted);font-variant-numeric:tabular-nums}
.md-empty{padding:2rem 1rem;text-align:center;color:var(--muted);font-size:.82rem}
.md-grades{max-height:380px;overflow:auto}
.md-grades table{width:100%}
.md-grades thead th{position:sticky;top:0;background:var(--surface);z-index:1}
.md-grades td,.md-grades th{padding-top:.3rem;padding-bottom:.3rem}
.md-grades .share{display:flex;align-items:center;gap:.5rem;justify-content:flex-end}
.md-grades .share .bar{width:90px;height:5px;background:var(--cream-d);border-radius:3px;overflow:hidden}
.md-grades .share .bar span{display:block;height:100%;background:var(--leaf)}
.md-grades .share em{font-style:normal;font-size:.72rem;color:var(--muted);width:3.2em;text-align:right;font-variant-numeric:tabular-nums}
.md-foot{padding:.45rem 1rem .6rem;font-size:.72rem;color:var(--muted);border-top:1px solid var(--line)}
@media (max-width:1000px){.md-kpis{grid-template-columns:repeat(2,1fr)}.md-row{grid-template-columns:1fr}}
@media (max-width:560px){.md-grades .share .bar{display:none}}
</style>

<?php
$usd_curr   = (float)($season['usd']??0);
$usd_prev   = (float)($prev_season['usd']??0);
$bales_curr = (int)($season['bales_sold']??0);
$bales_prev = (int)($prev_season['bales_sold']??0);
$mass_curr  = (float)($season['mass']??0);
$mass_prev  = (float)($prev_season['mass']??0);
$tick_n     = (int)($ticketed['ticketed']??0);
// "vs last year" only when last year had sales in the same range
$vs = fn($a,$b)=>$b>0 ? arrow(pct($a,$b)).' vs '.$yr_prev : '<span style="color:var(--muted)">No sales in '.$yr_prev.' to compare</span>';
$kpis = [
    ['Bales sold',     number_format($bales_curr),            $vs($bales_curr,$bales_prev)],
    ['USD value',      '$'.number_format($usd_curr,0),        $vs($usd_curr,$usd_prev)],
    ['Mass',           number_format($mass_curr,0).' kg',     $vs($mass_curr,$mass_prev)],
    ['Avg price $/kg', '$'.number_format((float)($season['avg_price']??0),2), 'Highest $'.number_format((float)($season['hi_price']??0),2)],
];
?>
<!-- Main figures for the period -->
<div class="md-kpis">
<?php foreach($kpis as $k):?>
<div class="kpi-card" style="--kpi-color:var(--gold)">
  <div class="kpi-label"><?=$k[0]?></div>
  <div class="kpi-value"><?=$k[1]?></div>
  <div class="kpi-sub"><?=$k[2]?></div>
</div>
<?php endforeach;?>
</div>

<!-- Smaller counts -->
<div class="md-strip">
  <div class="it"><div class="lb">Ticketed</div><div class="vl"><?=number_format($tick_n)?><small>bales</small></div></div>
  <div class="it"><div class="lb">Sold of ticketed</div><div class="vl"><?=$tick_n>0?round(min(100,$bales_curr/$tick_n*100)).'%':'—'?></div></div>
  <div class="it"><div class="lb">Growers sold</div><div class="vl"><?=number_format((int)($season['growers']??0))?></div></div>
  <div class="it"><div class="lb">Buyers</div><div class="vl"><?=number_format((int)($season['buyers']??0))?></div></div>
  <div class="it"><div class="lb">Selling days</div><div class="vl"><?=number_format((int)($season['sell_days']??0))?></div></div>
  <a class="it" href="payments.php" title="Open Payments"><div class="lb">Pending payments</div><div class="vl"><?=number_format((int)($pend_pay['c']??0))?><small>$<?=number_format((float)($pend_pay['amt']??0),0)?></small></div></a>
  <?php if($zig_rate>0):?>
  <div class="it"><div class="lb">Value in ZiG</div><div class="vl"><?=number_format($usd_curr*$zig_rate,0)?><small>@ <?=number_format($zig_rate,4)?></small></div></div>
  <?php endif;?>
</div>

<!-- Revenue trend + buyers -->
<div class="md-row">
  <div class="card">
    <div class="card-head"><h3><?=htmlspecialchars($trend_title)?></h3></div>
    <div class="card-body" style="padding:.75rem;flex:1">
      <?php if($trend):?>
      <div style="position:relative;height:240px"><canvas id="trendChart"></canvas></div>
      <?php else:?>
      <div class="md-empty">No sales in this range.</div>
      <?php endif;?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Buyers</h3></div>
    <?php if($top_buyers):
      $total_buyer_usd = array_sum(array_column($top_buyers,'usd')) ?: 1;
    ?>
    <div class="md-buyers">
      <?php foreach($top_buyers as $b):
        $sh = round((float)$b['usd']/$total_buyer_usd*100,1);
      ?>
      <div class="md-buyer">
        <span class="nm" title="<?=htmlspecialchars($b['buyer'])?>"><?=htmlspecialchars($b['buyer'])?></span>
        <span class="usd">$<?=number_format((float)$b['usd'],0)?></span>
        <div class="bar"><span style="width:<?=$sh?>%"></span></div>
        <span class="meta"><?=$sh?>% of value · <?=number_format((int)$b['bales'])?> bales</span>
      </div>
      <?php endforeach;?>
    </div>
    <?php else:?>
    <div class="md-empty">No buyer purchases in this range.</div>
    <?php endif;?>
  </div>
</div>

<!-- Grade breakdown -->
<div class="card" style="margin-bottom:1rem">
  <div class="card-head"><h3>Grade breakdown</h3></div>
  <?php if($grades):
    $g_mass = array_sum(array_map(fn($g)=>(float)$g['mass'],$grades)) ?: 1;
    $g_top5 = array_sum(array_map(fn($g)=>(float)$g['mass'],array_slice($grades,0,5)));
    $g_max  = max(array_map(fn($g)=>(float)$g['mass'],$grades)) ?: 1;   // longest bar = biggest grade
  ?>
  <div class="md-grades table-wrap">
    <table>
      <thead><tr>
        <th>Grade</th><th class="text-right">Bales</th><th class="text-right">Mass (kg)</th>
        <th class="text-right">Share of mass</th><th class="text-right">Avg $/kg</th>
      </tr></thead>
      <tbody>
      <?php foreach($grades as $gr): $gs = round((float)$gr['mass']/$g_mass*100,1); ?>
      <tr>
        <td><span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($gr['grade'])?></span></td>
        <td class="td-mono text-right"><?=number_format((int)$gr['bales'])?></td>
        <td class="td-mono text-right"><?=number_format((float)$gr['mass'],0)?></td>
        <td><div class="share"><span class="bar"><span style="width:<?=round((float)$gr['mass']/$g_max*100,1)?>%"></span></span><em><?=$gs?>%</em></div></td>
        <td class="td-mono text-right">$<?=number_format((float)$gr['avg_price'],2)?></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
  <div class="md-foot"><?=count($grades)?> grades · the top 5 make up <?=round($g_top5/$g_mass*100)?>% of the mass</div>
  <?php else:?>
  <div class="md-empty">No graded sales in this range.</div>
  <?php endif;?>
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
<?php
// Every calendar day in the range (up to two months) gets a bar, so a single
// selling day sits among its neighbours instead of one bar filling the chart.
$trend_by = array_column($trend, null, 'dt');
$trend_days = [];
$span = (strtotime($date_to) - strtotime($trend_from)) / 86400;
if ($span <= 62) {
    for ($t = strtotime($trend_from); $t <= strtotime($date_to); $t += 86400) $trend_days[] = date('Y-m-d', $t);
} else {
    $trend_days = array_column($trend, 'dt');
}
?>
// Trend chart
var trendCanvas = document.getElementById('trendChart');
if (trendCanvas) {
  var trendLabels = <?=json_encode(array_map(fn($d)=>date('d M',strtotime($d)),$trend_days))?>;
  var trendData   = <?=json_encode(array_map(fn($d)=>(float)($trend_by[$d]['usd'] ?? 0),$trend_days))?>;
  var trendBales  = <?=json_encode(array_map(fn($d)=>(int)($trend_by[$d]['bales'] ?? 0),$trend_days))?>;
  new Chart(trendCanvas,{
    type:'bar',
    data:{ labels:trendLabels, datasets:[{ label:'USD value', data:trendData, backgroundColor:'rgba(200,149,42,.8)', borderRadius:4, maxBarThickness:36 }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false},
        tooltip:{ callbacks:{ label:function(c){ return '$'+Math.round(c.parsed.y).toLocaleString()+' · '+trendBales[c.dataIndex]+' bales'; } } } },
        scales:{ x:{grid:{display:false},ticks:{font:{size:10},maxRotation:0,autoSkip:true}},
                 y:{beginAtZero:true,ticks:{callback:function(v){return v>=1000?'$'+Math.round(v/1000)+'k':'$'+v;},font:{size:10}},grid:{color:'rgba(128,128,128,.1)'}} } }
  });
}
</script>

<?php include "includes/layout_end.php"; ?>
