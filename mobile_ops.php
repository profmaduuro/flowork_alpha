<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title="Mobile Operations";$active_page="mobile_ops";
$spid=(int)($_SESSION['selling_pointid']??0);$today=date('Y-m-d');

function safe($c,$s){$r=$c->query($s);return($r&&$r!==true)?$r->fetch_all(MYSQLI_ASSOC):[];}

// AJAX action handler
if(isset($_GET['ajax'])){
    header('Content-Type: application/json');
    $action=$_GET['action']??'';
    if($action==='lookup'){
        $q=$conn->real_escape_string(trim($_GET['q']??''));
        $r=$conn->query("SELECT tk.id,tk.barcode,bm.mass,bm.temp_bacode timb_bc,g.grower_num,g.name,g.surname,bl.code loc,tg.description grade,sb.id sold_id,CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END price FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN bale_mass bm ON bm.id=bj.bale_massid JOIN growers g ON g.id=bj.growerid LEFT JOIN transporter_growers tg2 ON tg2.id=bj.transporter_growersid LEFT JOIN transporter t ON t.id=tg2.transporterid LEFT JOIN bale_locations bl ON bl.id=t.location_id LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid WHERE (tk.barcode='$q' OR bm.temp_bacode='$q') AND g.selling_pointid=$spid LIMIT 1");
        echo json_encode($r&&$r!==true?$r->fetch_assoc():['error'=>'Not found']);exit;
    }
    if($action==='today'){
        $s=$conn->query("SELECT COUNT(DISTINCT sb.id) sold,COUNT(DISTINCT tk.id) ticketed,COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN bale_mass bm ON bm.id=bj.bale_massid JOIN growers g ON g.id=bj.growerid LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id WHERE DATE(tk.created_at)='$today' AND g.selling_pointid=$spid");
        $stats=($s&&$s!==true)?$s->fetch_assoc():[];
        $recent=safe($conn,"SELECT tk.barcode,bm.mass,g.grower_num,tg.description grade,CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END price,TIME(sb.created_at) sold_at FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN bale_mass bm ON bm.id=bj.bale_massid JOIN growers g ON g.id=bj.growerid LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid WHERE DATE(sb.created_at)='$today' AND g.selling_pointid=$spid ORDER BY sb.id DESC LIMIT 10");
        echo json_encode(['stats'=>$stats,'recent'=>$recent,'time'=>date('H:i:s')]);exit;
    }
    echo json_encode(['error'=>'Unknown action']);exit;
}

include "includes/layout.php";
?>
<style>
/* Mobile-first design */
.mob-wrap{max-width:480px;margin:0 auto}
.mob-kpi{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-bottom:1rem}
.mob-kpi-item{background:var(--cream-d);border:1px solid var(--border);border-radius:8px;padding:.65rem .5rem;text-align:center}
.mob-kpi-item .v{font-size:1.4rem;font-weight:700;color:var(--gold);font-family:'Bebas Neue',sans-serif;line-height:1}
.mob-kpi-item .l{font-size:.6rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-top:2px}
.mob-search{display:flex;gap:.5rem;margin-bottom:1rem}
.mob-search input{flex:1;padding:.65rem .85rem;border:2px solid var(--border);border-radius:8px;font-size:.95rem;outline:none;background:var(--cream)}
.mob-search input:focus{border-color:var(--gold)}
.mob-search button{padding:.65rem 1rem;background:var(--gold);color:#fff;border:none;border-radius:8px;font-size:.9rem;cursor:pointer;font-weight:600}
.mob-result{background:var(--cream);border:1px solid var(--border);border-radius:8px;padding:1rem;margin-bottom:1rem;display:none}
.mob-result.show{display:block}
.mob-result .big{font-size:1.8rem;font-weight:700;font-family:'Bebas Neue',sans-serif;color:var(--gold)}
.mob-feed-item{display:flex;align-items:center;gap:.65rem;padding:.55rem 0;border-bottom:1px solid rgba(200,149,42,.08);font-size:.8rem}
.mob-feed-item:last-child{border-bottom:none}
.mob-badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:.65rem;font-weight:600;background:#fef3d8;color:#8b6200}
@media(min-width:600px){.mob-wrap{max-width:100%}.mob-kpi{grid-template-columns:repeat(4,1fr)}}
.pulse-ring{display:inline-block;width:8px;height:8px;border-radius:50%;background:#4caf50;animation:pulse-dot 1.8s infinite}
</style>

<div class="page-header" style="max-width:600px">
  <div class="page-header-left"><h2>📱 Mobile Operations</h2><p>Optimised for phone and tablet use on the floor</p></div>
  <span id="mob-time" style="font-size:.72rem;color:var(--muted);display:flex;align-items:center;gap:4px"><span class="pulse-ring"></span> Live</span>
</div>

<div class="mob-wrap">

<!-- Today KPIs -->
<div class="mob-kpi" id="mob-kpis">
  <div class="mob-kpi-item"><div class="v" id="m-sold">—</div><div class="l">Sold</div></div>
  <div class="mob-kpi-item"><div class="v" id="m-tick">—</div><div class="l">Ticketed</div></div>
  <div class="mob-kpi-item"><div class="v" id="m-usd">—</div><div class="l">USD</div></div>
</div>

<!-- Barcode lookup -->
<div class="mob-search">
  <input type="text" id="mob-bc" placeholder="Scan or type barcode..." autofocus>
  <button onclick="lookupBale()">🔍</button>
</div>

<div id="mob-result" class="mob-result">
  <div id="mob-result-body"></div>
</div>

<!-- Live sales feed -->
<div style="background:var(--cream);border:1px solid var(--border);border-radius:8px;overflow:hidden">
  <div style="padding:.65rem 1rem;background:var(--ink);color:var(--cream);display:flex;justify-content:space-between;align-items:center">
    <span style="font-size:.82rem;font-weight:600">Recent Sales</span>
    <span id="feed-updated" style="font-size:.65rem;opacity:.4"></span>
  </div>
  <div id="mob-feed" style="padding:.5rem .85rem">
    <div style="text-align:center;color:var(--muted);padding:1.5rem;font-size:.8rem">Loading...</div>
  </div>
</div>

<!-- Quick links -->
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:.5rem;margin-top:1rem">
  <?php foreach([
    ['scale.php','⚖ Weigh Bale'],['junus.php','📋 Junus'],
    ['tickets.php','🎫 Tickets'],['floor.php','🏷 Sell'],
    ['bale_check.php','🔍 Check Bale'],['live_floor.php','📡 Live Floor'],
  ] as [$href,$lbl]):?>
  <a href="<?=$href?>" style="display:block;padding:.75rem;background:var(--cream-d);border:1px solid var(--border);border-radius:8px;text-align:center;font-size:.82rem;font-weight:500;color:var(--ink);text-decoration:none"><?=$lbl?></a>
  <?php endforeach;?>
</div>

</div>

<script>
async function loadToday(){
    var d=await fw.get('mobile_ops.php',{ajax:1,action:'today'});
    document.getElementById('m-sold').textContent=d.stats.sold||'0';
    document.getElementById('m-tick').textContent=d.stats.ticketed||'0';
    document.getElementById('m-usd').textContent='$'+(Math.round((d.stats.usd||0)/1000))+'k';
    document.getElementById('mob-time').innerHTML='<span class="pulse-ring"></span> '+d.time;
    document.getElementById('feed-updated').textContent=d.time;
    var feed=document.getElementById('mob-feed');
    if(d.recent&&d.recent.length){
        feed.innerHTML=d.recent.map(function(r){
            var p=parseFloat(r.price||0);
            return '<div class="mob-feed-item">'+
                '<span class="mob-badge">'+r.grower_num+'</span>'+
                '<span style="flex:1">'+r.grade+'</span>'+
                '<span>'+parseFloat(r.mass).toFixed(1)+'kg</span>'+
                '<span style="color:var(--leaf);font-weight:600">$'+p.toFixed(4)+'</span>'+
                '<span style="color:var(--muted);font-size:.65rem">'+r.sold_at+'</span>'+
                '</div>';
        }).join('');
    }else{feed.innerHTML='<div style="text-align:center;color:var(--muted);padding:1rem;font-size:.8rem">No sales yet today</div>';}
}

async function lookupBale(){
    var q=document.getElementById('mob-bc').value.trim();
    if(!q)return;
    var res=document.getElementById('mob-result');
    var body=document.getElementById('mob-result-body');
    res.classList.add('show');body.innerHTML='Searching...';
    var d=await fw.get('mobile_ops.php',{ajax:1,action:'lookup',q:q});
    if(d.error){body.innerHTML='<span style="color:var(--danger)">'+d.error+'</span>';return;}
    var p=parseFloat(d.price||0);var is_sold=!!d.sold_id;
    body.innerHTML=
        '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.5rem">'+
        '<div><div class="mob-badge">'+d.grower_num+'</div>'+
        '<div style="font-size:.85rem;font-weight:600;margin-top:3px">'+d.name+' '+d.surname+'</div></div>'+
        '<span style="font-size:.75rem;padding:3px 9px;border-radius:4px;font-weight:600;background:'+(is_sold?'#e8f5e9':'#fff8e1')+';color:'+(is_sold?'#2e7d32':'#f57f17')+'">'+(is_sold?'SOLD':'UNSOLD')+'</span></div>'+
        '<div class="big">'+parseFloat(d.mass).toFixed(2)+' kg</div>'+
        '<div style="font-size:.72rem;color:var(--muted);margin-top:.25rem">'+
        'Barcode: '+d.barcode+' &nbsp; Grade: '+(d.grade||'—')+' &nbsp; Loc: '+(d.loc||'—')+
        (is_sold?' &nbsp; Price: $'+p.toFixed(4)+'/kg':'')+
        '</div>';
}

document.getElementById('mob-bc').addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();lookupBale();}});
loadToday();setInterval(loadToday,30000);
</script>

<?php include "includes/layout_end.php";?>
