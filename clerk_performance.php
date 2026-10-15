<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title="Clerk Performance";$active_page="clerk_performance";
$spid=(int)($_SESSION['selling_pointid']??0);
$today=date('Y-m-d');$yr=(int)date('Y');
$f_date_from=$conn->real_escape_string($_GET['from']??date('Y-m-01'));
$f_date_to=$conn->real_escape_string($_GET['to']??$today);

// AJAX
if(isset($_GET['ajax'])){
    header('Content-Type: application/json');
    $uid=(int)($_GET['uid']??0);
    if(!$uid){echo json_encode([]);exit;}
    // Daily activity for user
    $daily=$conn->query("SELECT DATE(tk.created_at) dt,COUNT(DISTINCT tk.id) tickets,COUNT(DISTINCT sb.id) sold
        FROM tickets tk LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN growers g ON g.id=bj.growerid
        WHERE g.selling_pointid=$spid AND DATE(tk.created_at) BETWEEN '$f_date_from' AND '$f_date_to'
        GROUP BY DATE(tk.created_at) ORDER BY dt");
    $rows=($daily&&$daily!==true)?$daily->fetch_all(MYSQLI_ASSOC):[];
    echo json_encode($rows);exit;
}

// Performance per user
$perf_q=$conn->query("
    SELECT u.id,u.username,u.full_name,
        COUNT(DISTINCT tk.id) tickets_created,
        COUNT(DISTINCT sb.id) bales_sold,
        COUNT(DISTINCT DATE(tk.created_at)) active_days,
        COALESCE(SUM(bm.mass),0) total_mass,
        COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd_value,
        COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END),0) avg_price
    FROM users u
    LEFT JOIN tickets tk ON tk.created_by=u.id
    LEFT JOIN bale_junus bj ON bj.id=tk.bale_junusid
    LEFT JOIN bale_mass bm ON bm.id=bj.bale_massid
    LEFT JOIN growers g ON g.id=bj.growerid AND g.selling_pointid=$spid
    LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
    WHERE (tk.created_at IS NULL OR DATE(tk.created_at) BETWEEN '$f_date_from' AND '$f_date_to')
    AND (g.selling_pointid=$spid OR g.id IS NULL)
    GROUP BY u.id ORDER BY bales_sold DESC
");
$perf=($perf_q&&$perf_q!==true)?$perf_q->fetch_all(MYSQLI_ASSOC):[];

// Today's activity by user
$today_q=$conn->query("
    SELECT u.id,u.username,COUNT(DISTINCT tk.id) tickets,COUNT(DISTINCT sb.id) sold,
        COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd
    FROM users u
    LEFT JOIN tickets tk ON tk.created_by=u.id AND DATE(tk.created_at)='$today'
    LEFT JOIN bale_junus bj ON bj.id=tk.bale_junusid
    LEFT JOIN bale_mass bm ON bm.id=bj.bale_massid
    LEFT JOIN growers g ON g.id=bj.growerid AND g.selling_pointid=$spid
    LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
    GROUP BY u.id HAVING tickets>0 ORDER BY sold DESC
");
$today_perf=($today_q&&$today_q!==true)?$today_q->fetch_all(MYSQLI_ASSOC):[];

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Clerk Performance</h2><p>Activity tracking per floor operator and clerk</p></div>
  <form method="GET" style="display:flex;gap:.5rem;align-items:flex-end">
    <div><label style="font-size:.62rem;display:block">From</label><input type="date" name="from" class="form-control" value="<?=htmlspecialchars((string)$f_date_from)?>" style="font-size:.8rem;padding:.32rem .55rem"></div>
    <div><label style="font-size:.62rem;display:block">To</label><input type="date" name="to" class="form-control" value="<?=htmlspecialchars((string)$f_date_to)?>" style="font-size:.8rem;padding:.32rem .55rem"></div>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
  </form>
</div>

<!-- Today strip -->
<?php if(!empty($today_perf)):?>
<div class="card mb-3" style="border-top:3px solid var(--gold)">
<div class="card-head"><h3>Today's Activity</h3><span class="badge badge-green pulse">● Live</span></div>
<div style="display:flex;gap:0;flex-wrap:wrap">
<?php foreach($today_perf as $t):?>
<div style="flex:1;min-width:140px;padding:.85rem 1rem;border-right:1px solid var(--border);text-align:center">
  <div style="font-size:.72rem;font-weight:600;color:var(--gold);margin-bottom:.25rem"><?=htmlspecialchars($t['username'])?></div>
  <div style="font-size:1.4rem;font-weight:700;font-family:'Bebas Neue',sans-serif"><?=$t['sold']?></div>
  <div style="font-size:.65rem;color:var(--muted)">bales sold</div>
  <div style="font-size:.72rem;color:var(--leaf);margin-top:2px">$<?=number_format((float)$t['usd'],0)?></div>
</div>
<?php endforeach;?>
</div>
</div>
<?php endif;?>

<!-- Period leaderboard -->
<div class="grid-2" style="align-items:start;gap:1rem">
<div class="card">
<div class="card-head"><h3>Leaderboard — <?=htmlspecialchars((string)$f_date_from)?> to <?=htmlspecialchars((string)$f_date_to)?></h3></div>
<div class="table-wrap"><table>
<thead><tr><th>#</th><th>Clerk</th><th class="text-right">Tickets</th><th class="text-right">Bales Sold</th><th class="text-right">Mass kg</th><th class="text-right">USD Value</th><th class="text-right">Avg Days</th></tr></thead>
<tbody>
<?php $rank=0;foreach($perf as $p):if(!(int)$p['tickets_created'])continue;$rank++;?>
<tr>
  <td style="font-size:.75rem;color:var(--muted)"><?=$rank?></td>
  <td>
    <span style="font-weight:600;color:<?=$rank===1?'var(--gold)':($rank===2?'#888':($rank===3?'#c87533':'var(--ink)'))?>">
      <?=$rank<=3?['🥇','🥈','🥉'][$rank-1].' ':''?><?=htmlspecialchars($p['username'])?>
    </span>
    <?php if($p['full_name']):?><div style="font-size:.65rem;color:var(--muted)"><?=htmlspecialchars($p['full_name'])?></div><?php endif;?>
  </td>
  <td class="td-mono text-right"><?=number_format((int)$p['tickets_created'])?></td>
  <td class="td-mono text-right fw-500"><?=number_format((int)$p['bales_sold'])?></td>
  <td class="td-mono text-right"><?=number_format((float)$p['total_mass'],0)?></td>
  <td class="td-mono text-right">$<?=number_format((float)$p['usd_value'],0)?></td>
  <td class="td-mono text-right"><?=(int)$p['active_days']?></td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
</div>

<!-- Activity chart by user -->
<div class="card">
<div class="card-head"><h3>Daily Activity Chart</h3></div>
<div class="card-body">
  <div class="form-group">
    <label>Select Clerk</label>
    <select id="uid-select" class="form-control" onchange="loadChart(this.value)">
      <option value="">Select a clerk...</option>
      <?php foreach($perf as $p):if(!(int)$p['tickets_created'])continue;?>
      <option value="<?=$p['id']?>"><?=htmlspecialchars($p['username'])?></option>
      <?php endforeach;?>
    </select>
  </div>
  <div style="position:relative;height:200px"><canvas id="perfChart"></canvas></div>
</div>
</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
var perfChart=null;
async function loadChart(uid){
    if(!uid)return;
    var d=await fw.get('clerk_performance.php',{ajax:1,uid:uid,from:'<?=htmlspecialchars((string)$f_date_from)?>',to:'<?=htmlspecialchars((string)$f_date_to)?>'});
    var labels=d.map(r=>r.dt);var sold=d.map(r=>parseInt(r.sold));var tickets=d.map(r=>parseInt(r.tickets));
    if(perfChart)perfChart.destroy();
    perfChart=new Chart(document.getElementById('perfChart'),{type:'bar',
        data:{labels:labels,datasets:[
            {label:'Bales Sold',data:sold,backgroundColor:'rgba(61,107,53,.8)',borderRadius:3},
            {label:'Tickets',data:tickets,backgroundColor:'rgba(200,149,42,.4)',borderRadius:3}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{font:{size:10},boxWidth:10}}},
            scales:{x:{ticks:{font:{size:9}},grid:{display:false}},y:{ticks:{font:{size:9}},grid:{color:'rgba(128,128,128,.08)'}}}}});
}
</script>
<?php include "includes/layout_end.php";?>
