<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title="Print Labels & Floor Sheets";$active_page="print_labels";
$spid=(int)($_SESSION['selling_pointid']??0);$today=date('Y-m-d');
$date_f=$conn->real_escape_string($_GET['date']??$today);
$type=$_GET['type']??'labels'; // labels|floor_sheet|grower_sheet

function safe($c,$s){$r=$c->query($s);return($r&&$r!==true)?$r->fetch_all(MYSQLI_ASSOC):[];}

// Print mode — clean output
if(isset($_GET['print'])){
    $type_p=$_GET['type']??'labels';
    $loc_p=(int)($_GET['loc']??0);$grower_p=$conn->real_escape_string($_GET['grower']??'');
    $where="DATE(bm.created_at)='$date_f' AND g.selling_pointid=$spid";
    if($loc_p)$where.=" AND bl.id=$loc_p";
    if($grower_p)$where.=" AND g.grower_num='$grower_p'";

    $bales=safe($conn,"
        SELECT bm.id,bm.temp_bacode,bm.mass,bm.created_at,
               g.grower_num,g.name,g.surname,g.province,g.farm_name,g.national_id,
               bl.code loc_code,bl.name loc_name,t.name transporter,
               tk.barcode ticket_bc,sb.price,
               CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END price_pkg,
               tg.description grade
        FROM bale_mass bm
        LEFT JOIN bale_junus bj ON bj.bale_massid=bm.id
        LEFT JOIN growers g ON g.id=bj.growerid
        LEFT JOIN transporter_growers tg2 ON tg2.id=bj.transporter_growersid
        LEFT JOIN transporter t ON t.id=tg2.transporterid
        LEFT JOIN bale_locations bl ON bl.id=t.location_id
        LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
        WHERE $where ORDER BY bl.code,g.grower_num,bm.id");

    $sp_name=htmlspecialchars($_SESSION['selling_point_name']??'');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Print</title><style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;font-size:10px}
    @media print{@page{margin:8mm}.no-print{display:none}}
    .btn{display:inline-block;padding:6px 14px;background:#c8952a;color:#fff;border:none;border-radius:4px;cursor:pointer;margin-bottom:12px;font-size:12px}
    ';

    if($type_p==='labels'){
        echo '.labels{display:flex;flex-wrap:wrap;gap:4mm;padding:4mm}
        .label{width:90mm;height:50mm;border:1px solid #000;padding:3mm;display:flex;flex-direction:column;gap:2mm;page-break-inside:avoid}
        .l-top{display:flex;justify-content:space-between;align-items:flex-start}
        .l-loc{font-size:22px;font-weight:700;letter-spacing:.02em}
        .l-bc{font-family:monospace;font-size:9px;background:#f0f0f0;padding:2px 4px;border-radius:2px}
        .l-grower{font-size:12px;font-weight:700}.l-sub{font-size:8px;color:#555;margin-top:1px}
        .l-mass{font-size:20px;font-weight:700}.l-date{font-size:8px;color:#888}
        .l-status{font-size:8px;padding:1px 5px;border-radius:3px;font-weight:600}
        .sold{background:#e8f5e9;color:#2e7d32}.unsold{background:#fff8e1;color:#f57f17}
        </style></head><body>
        <div class="no-print" style="padding:8px"><button class="btn" onclick="window.print()">🖨 Print Labels</button> &nbsp; '.count($bales).' labels</div>
        <div class="labels">';
        foreach($bales as $b){
            $is_sold=!empty($b['ticket_bc']);
            echo '<div class="label">';
            echo '<div class="l-top"><span class="l-loc">'.htmlspecialchars($b['loc_code']??'?').'</span>';
            echo '<span class="l-status '.($is_sold?'sold':'unsold').'">'.($is_sold?'SOLD':'UNSOLD').'</span></div>';
            echo '<div class="l-grower">'.htmlspecialchars($b['grower_num'].' — '.$b['name'].' '.$b['surname']).'</div>';
            echo '<div class="l-sub">'.htmlspecialchars($b['farm_name']??'').' &nbsp; '.htmlspecialchars($b['province']??'').'</div>';
            echo '<div style="display:flex;justify-content:space-between;align-items:flex-end">';
            echo '<div><div class="l-mass">'.number_format((float)$b['mass'],2).' kg</div>';
            echo '<div class="l-bc">TIMB: '.htmlspecialchars($b['temp_bacode']??'').($b['ticket_bc']?' &nbsp; FLWK: '.htmlspecialchars($b['ticket_bc']):'').'</div></div>';
            if($b['grade']){echo '<div style="text-align:right"><div style="font-size:14px;font-weight:700;color:#c8952a">'.htmlspecialchars($b['grade']).'</div>';
            echo '<div style="font-size:9px">$'.number_format((float)($b['price_pkg']??0),4).'/kg</div></div>';}
            echo '</div>';
            echo '<div class="l-date">'.date('d M Y',strtotime($b['created_at'])).' &nbsp; '.$sp_name.'</div>';
            echo '</div>';
        }
        echo '</div>';
    } elseif($type_p==='floor_sheet'){
        echo 'table{width:100%;border-collapse:collapse;font-size:9px}
        th{background:#1a1208;color:#fff;padding:4px 6px;font-size:8px;text-align:left}
        td{padding:3px 6px;border-bottom:1px solid #e0e0e0}tr:nth-child(even) td{background:#f9f9f9}
        .tr{text-align:right}.hdr{margin-bottom:10px}h1{font-size:14px}h2{font-size:10px;color:#555}
        </style></head><body>
        <div class="no-print" style="padding:8px"><button class="btn" onclick="window.print()">🖨 Print Floor Sheet</button></div>
        <div class="hdr"><h1>Floor Sheet — '.date('d F Y',strtotime($date_f)).'</h1><h2>'.$sp_name.'</h2></div>
        <table><thead><tr><th>#</th><th>TIMB BC</th><th>Grower #</th><th>Name</th><th>Location</th><th class="tr">Mass kg</th><th>Grade</th><th>Status</th><th>Price $/kg</th><th>Value</th><th>Sign</th></tr></thead><tbody>';
        $rank=0;
        foreach($bales as $b){$rank++;
            echo '<tr><td>'.$rank.'</td><td style="font-family:monospace;font-size:8px">'.htmlspecialchars($b['temp_bacode']??'').'</td>';
            echo '<td>'.htmlspecialchars($b['grower_num']).'</td><td>'.htmlspecialchars($b['name'].' '.$b['surname']).'</td>';
            echo '<td>'.htmlspecialchars($b['loc_code']??'').'</td><td class="tr">'.number_format((float)$b['mass'],1).'</td>';
            echo '<td>'.htmlspecialchars($b['grade']??'').'</td>';
            echo '<td>'.(!empty($b['ticket_bc'])?'SOLD':'—').'</td>';
            echo '<td class="tr">'.($b['price_pkg']>0?'$'.number_format((float)$b['price_pkg'],4):'—').'</td>';
            $val=$b['price_pkg']>0?(float)$b['price_pkg']*(float)$b['mass']:0;
            echo '<td class="tr">'.($val>0?'$'.number_format($val,2):'—').'</td><td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</td></tr>';
        }
        $tot_mass=array_sum(array_column($bales,'mass'));
        echo '<tr style="background:#1a1208;color:#fff"><td colspan="5" style="font-weight:700">TOTAL — '.$rank.' bales</td><td class="tr" style="font-weight:700">'.number_format($tot_mass,1).'</td><td colspan="5"></td></tr>';
        echo '</tbody></table>';
    }
    echo '<script>window.onload=()=>window.print()</script></body></html>';
    exit;
}

// Summary counts for date
$counts_q=$conn->query("SELECT COUNT(DISTINCT bm.id) total,COUNT(DISTINCT tk.id) ticketed,COUNT(DISTINCT sb.id) sold FROM bale_mass bm LEFT JOIN bale_junus bj ON bj.bale_massid=bm.id LEFT JOIN growers g ON g.id=bj.growerid LEFT JOIN tickets tk ON tk.bale_junusid=bj.id LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id WHERE DATE(bm.created_at)='$date_f' AND g.selling_pointid=$spid");
$counts=($counts_q&&$counts_q!==true)?($counts_q->fetch_assoc()??[]):[];

$locs=safe($conn,"SELECT id,code,name FROM bale_locations WHERE selling_pointid=$spid AND active=1 ORDER BY code");
$growers_sold=safe($conn,"SELECT DISTINCT g.grower_num FROM growers g JOIN bale_junus bj ON bj.growerid=g.id JOIN tickets tk ON tk.bale_junusid=bj.id JOIN sold_bales sb ON sb.ticketsid=tk.id WHERE DATE(sb.created_at)='$date_f' AND g.selling_pointid=$spid ORDER BY g.grower_num LIMIT 200");

$base_url="print_labels.php?date=$date_f";
include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left"><h2>Print — Labels &amp; Floor Sheets</h2><p>Print bale labels and floor reconciliation sheets</p></div>
  <form method="GET" style="display:flex;gap:.5rem;align-items:flex-end">
    <input type="date" name="date" class="form-control" value="<?=htmlspecialchars((string)$date_f)?>" style="font-size:.8rem;padding:.32rem .55rem">
    <input type="hidden" name="type" value="<?=htmlspecialchars((string)$type)?>">
  </form>
</div>

<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.25rem">
  <div class="kpi-card"><div class="kpi-label">Total Bales</div><div class="kpi-value"><?=number_format((int)($counts['total']??0))?></div></div>
  <div class="kpi-card"><div class="kpi-label">Ticketed</div><div class="kpi-value"><?=number_format((int)($counts['ticketed']??0))?></div></div>
  <div class="kpi-card"><div class="kpi-label">Sold</div><div class="kpi-value"><?=number_format((int)($counts['sold']??0))?></div></div>
</div>

<div class="premium-tabs-nav" data-tabs="printlabels">
  <button type="button" class="premium-tab-btn active" data-tab="labels">🏷 Bale Labels</button>
  <button type="button" class="premium-tab-btn" data-tab="floor">📋 Floor Sheet</button>
  <button type="button" class="premium-tab-btn" data-tab="grower">📄 Grower Sales Sheet</button>
  <button type="button" class="premium-tab-btn" data-tab="dispatch">🚛 Dispatch Notes</button>
</div>

<!-- Bale Labels -->
<div id="panel-labels" class="tab-panel active" data-tabs="printlabels">
<div class="card">
<div class="card-head"><h3>🏷 Bale Labels (90×50mm)</h3></div>
<div class="card-body">
  <p style="font-size:.8rem;color:var(--muted);margin-bottom:.75rem">Print one label per bale with TIMB barcode, grower, mass, grade, location and status.</p>
  <div class="form-group"><label>Filter by Location (optional)</label>
    <select id="lbl-loc" class="form-control">
      <option value="0">All locations</option>
      <?php foreach($locs as $l):?><option value="<?=$l['id']?>"><?=htmlspecialchars($l['code'].' — '.$l['name'])?></option><?php endforeach;?>
    </select>
  </div>
  <div class="form-group"><label>Filter by Grower (optional)</label>
    <select id="lbl-grower" class="form-control">
      <option value="">All growers</option>
      <?php foreach($growers_sold as $g):?><option value="<?=$g['grower_num']?>"><?=$g['grower_num']?></option><?php endforeach;?>
    </select>
  </div>
  <button onclick="printLabels()" class="btn btn-primary" style="width:100%">🖨 Print Bale Labels</button>
</div>
</div>

</div><!-- /panel-labels -->

<!-- Floor Sheet -->
<div id="panel-floor" class="tab-panel" data-tabs="printlabels">
<div class="card">
<div class="card-head"><h3>📋 Floor Reconciliation Sheet</h3></div>
<div class="card-body">
  <p style="font-size:.8rem;color:var(--muted);margin-bottom:.75rem">Full bale list with mass, grade, price and value. Signature column for reconciliation.</p>
  <div class="form-group"><label>Filter by Location (optional)</label>
    <select id="fs-loc" class="form-control">
      <option value="0">All locations</option>
      <?php foreach($locs as $l):?><option value="<?=$l['id']?>"><?=htmlspecialchars($l['code'].' — '.$l['name'])?></option><?php endforeach;?>
    </select>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
    <button onclick="printFloorSheet('all')" class="btn btn-primary">🖨 Print All Bales</button>
    <button onclick="printFloorSheet('sold')" class="btn btn-ghost">🖨 Sold Only</button>
  </div>
</div>
</div>

</div><!-- /panel-floor -->

<!-- Grower schedule -->
<div id="panel-grower" class="tab-panel" data-tabs="printlabels">
<div class="card">
<div class="card-head"><h3>📄 Grower Sales Sheet</h3></div>
<div class="card-body">
  <p style="font-size:.8rem;color:var(--muted);margin-bottom:.75rem">Per-grower summary with bales, mass, deductions and net payable. For grower sign-off.</p>
  <div class="form-group"><label>Select Grower</label>
    <select id="gs-grower" class="form-control">
      <option value="">All growers sold today</option>
      <?php foreach($growers_sold as $g):?><option value="<?=$g['grower_num']?>"><?=$g['grower_num']?></option><?php endforeach;?>
    </select>
  </div>
  <button onclick="printGrowerSheet()" class="btn btn-primary" style="width:100%">🖨 Print Grower Sheet</button>
</div>
</div>

</div><!-- /panel-grower -->

<!-- Dispatch note shortcut -->
<div id="panel-dispatch" class="tab-panel" data-tabs="printlabels">
<div class="card">
<div class="card-head"><h3>🚛 Dispatch Notes</h3></div>
<div class="card-body">
  <p style="font-size:.8rem;color:var(--muted);margin-bottom:.75rem">Print dispatch notes for truck loadings from the Dispatch Export page.</p>
  <a href="dispatch_export.php" class="btn btn-ghost" style="width:100%">→ Go to Dispatch Export</a>
</div>
</div>
</div><!-- /panel-dispatch -->

<script>
function printLabels(){
    var loc=document.getElementById('lbl-loc').value;
    var grower=document.getElementById('lbl-grower').value;
    var url='print_labels.php?print=1&type=labels&date=<?=htmlspecialchars((string)$date_f)?>';
    if(loc!=='0')url+='&loc='+loc;
    if(grower)url+='&grower='+encodeURIComponent(grower);
    window.open(url,'_blank');
}
function printFloorSheet(mode){
    var loc=document.getElementById('fs-loc').value;
    var url='print_labels.php?print=1&type=floor_sheet&date=<?=htmlspecialchars((string)$date_f)?>&mode='+mode;
    if(loc!=='0')url+='&loc='+loc;
    window.open(url,'_blank');
}
function printGrowerSheet(){
    var g=document.getElementById('gs-grower').value;
    var url='eod_reports.php?date=<?=htmlspecialchars((string)$date_f)?>&report=grower_sched_usd'+(g?'&grower='+encodeURIComponent(g):'');
    window.open(url,'_blank');
}
</script>
<?php include "includes/layout_end.php";?>
