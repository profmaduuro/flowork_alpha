<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Season Comparison";
$active_page = "season_compare";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);

function safe($c,$s){$r=$c->query($s);return($r&&$r!==true)?$r->fetch_all(MYSQLI_ASSOC):[];}
function safeVal($c,$s,$k=''){$r=$c->query($s);$row=($r&&$r!==true)?($r->fetch_assoc()??[]):[];return $k?($row[$k]??0):$row;}
function pct($a,$b){return $b>0?round(($a-$b)/$b*100,1):($a>0?100:0);}
function arrow($v){return $v>0?'<span style="color:#4caf50">▲ '.$v.'%</span>':($v<0?'<span style="color:#e53935">▼ '.abs($v).'%</span>':'<span style="color:var(--muted)">— 0%</span>');}
function arrowBig($v){
    if($v>0) return '<span style="color:#4caf50;font-size:1.1rem;font-weight:700">▲ '.abs($v).'%</span>';
    if($v<0) return '<span style="color:#e53935;font-size:1.1rem;font-weight:700">▼ '.abs($v).'%</span>';
    return '<span style="color:var(--muted);font-size:.9rem">— 0%</span>';
}

// Get available seasons/years
$years_q = $conn->query("
    SELECT YEAR(DATE(tk.created_at)) yr, 
           MIN(DATE(tk.created_at)) start_date,
           MAX(DATE(tk.created_at)) end_date,
           COUNT(DISTINCT DATE(tk.created_at)) sell_days
    FROM tickets tk
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    WHERE g.selling_pointid=$spid
    GROUP BY YEAR(DATE(tk.created_at))
    ORDER BY yr DESC
");
$years = ($years_q&&$years_q!==true)?$years_q->fetch_all(MYSQLI_ASSOC):[];

$yr_a = (int)($_GET['yr_a'] ?? ($years[0]['yr'] ?? date('Y')));
$yr_b = (int)($_GET['yr_b'] ?? ($years[1]['yr'] ?? ($yr_a - 1)));

// CSV export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="season_compare_'.$yr_a.'_vs_'.$yr_b.'.csv"');
    echo "Season Comparison: $yr_a vs $yr_b\r\n\r\n";
}

// Core stats function
function season_stats($conn,$spid,$yr) {
    $yr = (int)$yr;
    $r = $conn->query("
        SELECT COUNT(DISTINCT sb.id) bales_sold,
               COUNT(DISTINCT tk.id) bales_ticketed,
               COUNT(DISTINCT bj.id) bales_laid,
               COALESCE(SUM(bm.mass),0) mass_sold,
               COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd_value,
               COALESCE(MAX(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) hi_price,
               COALESCE(MIN(CASE WHEN sb.price>10 AND sb.price/100>0.001 THEN sb.price/100 WHEN sb.price>0.001 THEN sb.price END),0) lo_price,
               COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END),0) avg_price,
               COUNT(DISTINCT g.id) growers,
               COUNT(DISTINCT DATE(tk.created_at)) sell_days,
               COUNT(DISTINCT b.id) buyers
        FROM tickets tk
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        WHERE YEAR(DATE(tk.created_at))=$yr AND g.selling_pointid=$spid
    ");
    return ($r&&$r!==true)?($r->fetch_assoc()??[]):[];
}

function season_by_grade($conn,$spid,$yr) {
    $yr=(int)$yr;
    return safe($conn,"
        SELECT tg.description grade,
               COUNT(sb.id) bales, COALESCE(SUM(bm.mass),0) mass,
               COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) avg_price,
               COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        JOIN timb_grades tg ON tg.id=sb.timb_gradesid
        WHERE YEAR(DATE(sb.created_at))=$yr AND g.selling_pointid=$spid
        GROUP BY tg.id ORDER BY usd DESC
    ");
}

function season_by_buyer($conn,$spid,$yr) {
    $yr=(int)$yr;
    return safe($conn,"
        SELECT COALESCE(NULLIF(b.buyer_code,''), b.name) buyer, b.buyer_code code,
               COUNT(sb.id) bales, COALESCE(SUM(bm.mass),0) mass,
               COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd,
               COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) avg_price
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        JOIN buyer b ON b.id=bg.buyerid
        WHERE YEAR(DATE(sb.created_at))=$yr AND g.selling_pointid=$spid
        GROUP BY b.id ORDER BY usd DESC
    ");
}

function season_monthly($conn,$spid,$yr) {
    $yr=(int)$yr;
    return safe($conn,"
        SELECT MONTH(DATE(sb.created_at)) mo,
               MONTHNAME(DATE(sb.created_at)) month_name,
               COUNT(sb.id) bales,
               COALESCE(SUM(bm.mass),0) mass,
               COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        WHERE YEAR(DATE(sb.created_at))=$yr AND g.selling_pointid=$spid
        GROUP BY MONTH(DATE(sb.created_at)) ORDER BY mo
    ");
}

$sa = season_stats($conn,$spid,$yr_a);
$sb = season_stats($conn,$spid,$yr_b);
$grades_a = season_by_grade($conn,$spid,$yr_a);
$grades_b = season_by_grade($conn,$spid,$yr_b);
$buyers_a = season_by_buyer($conn,$spid,$yr_a);
$buyers_b = season_by_buyer($conn,$spid,$yr_b);
$monthly_a = season_monthly($conn,$spid,$yr_a);
$monthly_b = season_monthly($conn,$spid,$yr_b);

// Index by month for easy comparison
$mo_a = array_column($monthly_a,'usd','mo');
$mo_b = array_column($monthly_b,'usd','mo');
$mo_bales_a = array_column($monthly_a,'bales','mo');
$mo_bales_b = array_column($monthly_b,'bales','mo');
$all_months = array_unique(array_merge(array_keys($mo_a),array_keys($mo_b)));
sort($all_months);

// ── NEW: Cumulative USD by auction day number (x-axis = day index)
function season_cumulative_by_day($conn,$spid,$yr) {
    $yr=(int)$yr;
    $rows = safe($conn,"
        SELECT DATE(sb.created_at) sell_date,
               COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) day_usd
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        WHERE YEAR(DATE(sb.created_at))=$yr AND g.selling_pointid=$spid
        GROUP BY DATE(sb.created_at)
        ORDER BY sell_date ASC
    ");
    $cum = 0;
    $result = [];
    foreach ($rows as $i => $row) {
        $cum += (float)$row['day_usd'];
        $result[] = ['day_num' => $i+1, 'date' => $row['sell_date'], 'cumulative' => $cum];
    }
    return $result;
}
$cum_a = season_cumulative_by_day($conn,$spid,$yr_a);
$cum_b = season_cumulative_by_day($conn,$spid,$yr_b);

// ── NEW: Grower sets for new/returning grower delta
function season_grower_ids($conn,$spid,$yr) {
    $yr=(int)$yr;
    $rows = safe($conn,"
        SELECT DISTINCT g.id
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN growers g ON g.id=bj.growerid
        WHERE YEAR(DATE(sb.created_at))=$yr AND g.selling_pointid=$spid
    ");
    return array_column($rows, 'id');
}
$growers_a_ids = season_grower_ids($conn,$spid,$yr_a);
$growers_b_ids = season_grower_ids($conn,$spid,$yr_b);
$new_growers    = count(array_diff($growers_a_ids, $growers_b_ids));
$lost_growers   = count(array_diff($growers_b_ids, $growers_a_ids));
$retained_growers = count(array_intersect($growers_a_ids, $growers_b_ids));

// ── NEW: Grade mix percentage comparison
$grades_a_total_bales = max(1, array_sum(array_column($grades_a, 'bales')));
$grades_b_total_bales = max(1, array_sum(array_column($grades_b, 'bales')));
$gb_a_bales = array_column($grades_a,'bales','grade');
$gb_b_bales = array_column($grades_b,'bales','grade');
$gb_a_usd   = array_column($grades_a,'usd','grade');
$gb_b_usd   = array_column($grades_b,'usd','grade');
$gb_bales_a_orig = array_column($grades_a,'bales','grade');
$all_grade_names = array_unique(array_merge(array_column($grades_a,'grade'),array_column($grades_b,'grade')));

include "includes/layout.php";
?>

<style>
/* KPI Comparison Grid */
.sc-kpi-grid {
    display:grid;
    grid-template-columns: 200px 1fr 80px 1fr;
    gap:0;
    border:1px solid var(--border);
    border-radius:var(--radius);
    overflow:hidden;
    margin-bottom:1.5rem;
    background:#fff;
}
.sc-kpi-header {
    display:grid;
    grid-template-columns: 200px 1fr 80px 1fr;
    background:var(--cream-d);
    border-bottom:2px solid var(--gold);
}
.sc-kpi-header > div {
    padding:.6rem 1rem;
    font-size:.62rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);
    border-right:1px solid var(--border);
}
.sc-kpi-header > div:last-child { border-right:none; }
.sc-kpi-row {
    display:contents;
}
.sc-kpi-row > div {
    padding:.75rem 1rem;
    border-bottom:1px solid var(--border);
    border-right:1px solid var(--border);
    display:flex;align-items:center;
}
.sc-kpi-row > div:last-child { border-right:none; }
.sc-kpi-label {
    font-size:.72rem;font-weight:500;color:var(--muted);
}
.sc-kpi-val-a {
    font-family:'Bebas Neue',sans-serif;font-size:1.5rem;color:var(--gold);letter-spacing:.04em;line-height:1;
}
.sc-kpi-val-b {
    font-family:'Bebas Neue',sans-serif;font-size:1.25rem;color:var(--muted);letter-spacing:.04em;line-height:1;
}
.sc-kpi-delta { justify-content:center;flex-direction:column;gap:2px;text-align:center; }

/* Grade mix bar */
.grade-mix-bar { height:8px; border-radius:4px; overflow:hidden; display:flex; margin-top:3px; }
.grade-mix-seg { height:100%; }

/* Cumulative chart container */
.cum-chart-wrap { position:relative; height:260px; }

@media(max-width:900px){
    .sc-kpi-grid,.sc-kpi-header { grid-template-columns:1fr 1fr; }
    .sc-kpi-row > div:nth-child(3) { display:none; }
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Season Comparison</h2>
    <p>Year-on-year performance benchmarking &amp; trend analysis</p>
  </div>
  <div style="display:flex;gap:.5rem;align-items:flex-end">
    <form method="GET" style="display:flex;gap:.5rem;align-items:flex-end">
      <div>
        <label style="font-size:.62rem;display:block">Season A</label>
        <select name="yr_a" class="form-control" style="font-size:.8rem;padding:.35rem .55rem">
          <?php foreach($years as $y):?>
          <option value="<?=$y['yr']?>" <?=$yr_a==$y['yr']?'selected':''?>><?=$y['yr']?></option>
          <?php endforeach;?>
        </select>
      </div>
      <span style="padding-bottom:.4rem;color:var(--muted)">vs</span>
      <div>
        <label style="font-size:.62rem;display:block">Season B</label>
        <select name="yr_b" class="form-control" style="font-size:.8rem;padding:.35rem .55rem">
          <?php foreach($years as $y):?>
          <option value="<?=$y['yr']?>" <?=$yr_b==$y['yr']?'selected':''?>><?=$y['yr']?></option>
          <?php endforeach;?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Compare</button>
    </form>
    <a href="?yr_a=<?=$yr_a?>&yr_b=<?=$yr_b?>&export=csv" class="btn btn-ghost">⬇ CSV</a>
  </div>
</div>

<?php if(isset($_GET['export'])): // CSV mode
    $metrics = [
        ['Bales Sold',      $sa['bales_sold'],    $sb['bales_sold']],
        ['Bales Laid',      $sa['bales_laid'],    $sb['bales_laid']],
        ['Mass Sold (kg)',  $sa['mass_sold'],      $sb['mass_sold']],
        ['USD Value',       $sa['usd_value'],      $sb['usd_value']],
        ['Hi Price $/kg',   $sa['hi_price'],       $sb['hi_price']],
        ['Lo Price $/kg',   $sa['lo_price'],       $sb['lo_price']],
        ['Avg Price $/kg',  $sa['avg_price'],      $sb['avg_price']],
        ['Growers',         $sa['growers'],        $sb['growers']],
        ['Sell Days',       $sa['sell_days'],      $sb['sell_days']],
        ['Buyers',          $sa['buyers'],         $sb['buyers']],
    ];
    echo "Metric,$yr_a,$yr_b,Change\r\n";
    foreach($metrics as $m) {
        $p = pct($m[1],$m[2]);
        echo '"'.$m[0].'",'.number_format($m[1],2).','.number_format($m[2],2).','.($p>=0?'+':'').$p."%\r\n";
    }
    echo "\r\nBY GRADE\r\n";
    echo "Grade,$yr_a Bales,$yr_a USD,$yr_b Bales,$yr_b USD\r\n";
    $gb_a_csv = array_column($grades_a,'usd','grade'); $gb_b_csv = array_column($grades_b,'usd','grade');
    $all_grades_csv = array_unique(array_merge(array_column($grades_a,'grade'),array_column($grades_b,'grade')));
    foreach($all_grades_csv as $gr) {
        echo '"'.$gr.'",'.($gb_a_csv[$gr]??0).','.($gb_b_csv[$gr]??0)."\r\n";
    }
    exit;
endif;?>

<!-- ══ NEW: Side-by-side KPI Comparison Grid ══ -->
<div class="premium-tabs-nav" data-tabs="seasoncompare">
  <button type="button" class="premium-tab-btn active" data-tab="benchmark">📊 Benchmark</button>
  <button type="button" class="premium-tab-btn" data-tab="trends">📈 Trends</button>
  <button type="button" class="premium-tab-btn" data-tab="grades">🎨 Grades</button>
  <button type="button" class="premium-tab-btn" data-tab="buyers">🏢 Buyers &amp; Months</button>
</div>

<div id="panel-benchmark" class="tab-panel active" data-tabs="seasoncompare">
<div class="card mb-3">
  <div class="card-head">
    <h3>📊 KPI Benchmark — <?=$yr_a?> vs <?=$yr_b?></h3>
    <span class="badge badge-gold">Season over Season</span>
  </div>
  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.82rem">
      <thead>
        <tr style="background:var(--cream-d);border-bottom:2px solid var(--gold)">
          <th style="padding:.65rem 1rem;text-align:left;font-size:.62rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Metric</th>
          <th style="padding:.65rem 1rem;text-align:right;font-size:.62rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--gold)"><?=$yr_a?></th>
          <th style="padding:.65rem 1rem;text-align:center;font-size:.62rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Δ Change</th>
          <th style="padding:.65rem 1rem;text-align:right;font-size:.62rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)"><?=$yr_b?></th>
        </tr>
      </thead>
      <tbody>
      <?php
      $kpi_rows = [
          ['Bales Sold',      (float)($sa['bales_sold']??0),  (float)($sb['bales_sold']??0),  '',  ''],
          ['Total Mass',      (float)($sa['mass_sold']??0),   (float)($sb['mass_sold']??0),   '',  ' kg'],
          ['USD Value',       (float)($sa['usd_value']??0),   (float)($sb['usd_value']??0),   '$', ''],
          ['Avg Price $/kg',  (float)($sa['avg_price']??0),   (float)($sb['avg_price']??0),   '$', ''],
          ['Hi Price $/kg',   (float)($sa['hi_price']??0),    (float)($sb['hi_price']??0),    '$', ''],
          ['Growers',         (float)($sa['growers']??0),     (float)($sb['growers']??0),     '',  ''],
          ['Sell Days',       (float)($sa['sell_days']??0),   (float)($sb['sell_days']??0),   '',  ''],
          ['Buyers',          (float)($sa['buyers']??0),      (float)($sb['buyers']??0),      '',  ''],
      ];
      foreach($kpi_rows as $ki => $m):
          $p = pct($m[1],$m[2]);
          $dp = ($m[3]==='$') ? 2 : 0;
          $rowbg = $ki%2===0 ? '' : 'background:#fafaf8';
      ?>
      <tr style="border-bottom:1px solid var(--border);<?=$rowbg?>">
        <td style="padding:.7rem 1rem;font-weight:500;font-size:.78rem"><?=$m[0]?></td>
        <td style="padding:.7rem 1rem;text-align:right;font-family:'Bebas Neue',sans-serif;font-size:1.35rem;color:var(--gold);letter-spacing:.04em;line-height:1"><?=$m[3].number_format($m[1],$dp).$m[4]?></td>
        <td style="padding:.7rem 1rem;text-align:center"><?=arrowBig($p)?></td>
        <td style="padding:.7rem 1rem;text-align:right;font-family:'Bebas Neue',sans-serif;font-size:1.1rem;color:var(--muted);letter-spacing:.04em;line-height:1"><?=$m[3].number_format($m[2],$dp).$m[4]?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ══ NEW: Grower Count Delta ══ -->
<div class="card mb-3">
  <div class="card-head">
    <h3>👨‍🌾 Grower Count Analysis — <?=$yr_a?> vs <?=$yr_b?></h3>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;text-align:center">
      <div>
        <div style="font-family:'Bebas Neue',sans-serif;font-size:2.2rem;color:var(--gold);line-height:1"><?=(int)($sa['growers']??0)?></div>
        <div style="font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-top:.2rem"><?=$yr_a?> Total</div>
      </div>
      <div>
        <div style="font-family:'Bebas Neue',sans-serif;font-size:2.2rem;color:#4caf50;line-height:1">+<?=$new_growers?></div>
        <div style="font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-top:.2rem">New Growers</div>
        <div style="font-size:.62rem;color:#4caf50;margin-top:.2rem">In <?=$yr_a?>, not <?=$yr_b?></div>
      </div>
      <div>
        <div style="font-family:'Bebas Neue',sans-serif;font-size:2.2rem;color:var(--leaf);line-height:1"><?=$retained_growers?></div>
        <div style="font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-top:.2rem">Retained</div>
        <div style="font-size:.62rem;color:var(--muted);margin-top:.2rem">Both seasons</div>
      </div>
      <div>
        <div style="font-family:'Bebas Neue',sans-serif;font-size:2.2rem;color:#e53935;line-height:1"><?=$lost_growers?></div>
        <div style="font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-top:.2rem">Not Returned</div>
        <div style="font-size:.62rem;color:#e53935;margin-top:.2rem">In <?=$yr_b?>, not <?=$yr_a?></div>
      </div>
    </div>
  </div>
</div>

</div><!-- /panel-benchmark -->

<div id="panel-trends" class="tab-panel" data-tabs="seasoncompare">
<!-- ══ NEW: Cumulative USD Progress Chart ══ -->
<div class="card mb-3">
  <div class="card-head">
    <h3>📈 Cumulative USD Progress — <?=$yr_a?> vs <?=$yr_b?></h3>
    <span class="badge badge-gray">By auction day number</span>
  </div>
  <div class="card-body">
    <div class="cum-chart-wrap"><canvas id="cumChart"></canvas></div>
  </div>
</div>

<!-- Monthly bar chart (existing) -->
<div class="card mb-3">
  <div class="card-head"><h3>Monthly USD Value — <?=$yr_a?> vs <?=$yr_b?></h3></div>
  <div class="card-body" style="padding:.75rem 1rem">
    <div style="position:relative;height:220px"><canvas id="monthChart"></canvas></div>
  </div>
</div>

</div><!-- /panel-trends -->

<div id="panel-grades" class="tab-panel" data-tabs="seasoncompare">
<div class="grid-2" style="align-items:start;gap:1rem">

<!-- ══ NEW: Grade Mix % Comparison ══ -->
<div class="card">
  <div class="card-head">
    <h3>🎨 Grade Mix Comparison</h3>
    <span class="badge badge-gray">% of season bales</span>
  </div>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>Grade</th>
      <th class="text-right"><?=$yr_a?> Bales</th>
      <th class="text-right"><?=$yr_a?> %</th>
      <th class="text-right"><?=$yr_b?> %</th>
      <th class="text-right">Mix Δ</th>
    </tr></thead>
    <tbody>
    <?php
    $GRADE_MIX_COLORS = ['#c8952a','#3d6b35','#e8b84b','#1a6b9a','#c0392b','#8e44ad','#e67e22','#16a085'];
    $ci = 0;
    foreach($all_grade_names as $gr):
        $ba = (int)($gb_a_bales[$gr]??0);
        $bb_val = (int)($gb_b_bales[$gr]??0);
        $pct_a = round($ba / $grades_a_total_bales * 100, 1);
        $pct_b = round($bb_val / $grades_b_total_bales * 100, 1);
        $mix_d = round($pct_a - $pct_b, 1);
        $col = $GRADE_MIX_COLORS[$ci % count($GRADE_MIX_COLORS)];
        $ci++;
    ?>
    <tr>
      <td>
        <span class="badge badge-gold" style="font-size:.6rem"><?=htmlspecialchars($gr)?></span>
      </td>
      <td class="td-mono text-right"><?=$ba > 0 ? number_format($ba) : '—'?></td>
      <td class="td-mono text-right">
        <?php if($pct_a > 0): ?>
        <div style="display:flex;align-items:center;gap:4px;justify-content:flex-end">
          <div style="width:<?=min(60,$pct_a*1.5)?>px;height:6px;background:<?=$col?>;border-radius:3px;opacity:.8"></div>
          <span><?=$pct_a?>%</span>
        </div>
        <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
      </td>
      <td class="td-mono text-right">
        <?php if($pct_b > 0): ?>
        <div style="display:flex;align-items:center;gap:4px;justify-content:flex-end">
          <div style="width:<?=min(60,$pct_b*1.5)?>px;height:6px;background:<?=$col?>;border-radius:3px;opacity:.35"></div>
          <span style="color:var(--muted)"><?=$pct_b?>%</span>
        </div>
        <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
      </td>
      <td class="text-right">
        <?php if($mix_d > 0): ?>
        <span style="color:#4caf50;font-weight:600;font-size:.75rem">+<?=$mix_d?>pp</span>
        <?php elseif($mix_d < 0): ?>
        <span style="color:#e53935;font-weight:600;font-size:.75rem"><?=$mix_d?>pp</span>
        <?php else: ?>
        <span style="color:var(--muted);font-size:.72rem">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- By Grade USD comparison (existing) -->
<div class="card">
  <div class="card-head"><h3>By TIMB Grade — USD</h3></div>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>Grade</th>
      <th class="text-right"><?=$yr_a?> Bales</th>
      <th class="text-right"><?=$yr_a?> USD</th>
      <th class="text-right"><?=$yr_b?> USD</th>
      <th class="text-right">Change</th>
    </tr></thead>
    <tbody>
    <?php
    $gb_a_full = array_column($grades_a,'usd','grade');
    $gb_b_full = array_column($grades_b,'usd','grade');
    $gb_bales_a_full = array_column($grades_a,'bales','grade');
    $all_grades2 = array_unique(array_merge(array_column($grades_a,'grade'),array_column($grades_b,'grade')));
    foreach($all_grades2 as $gr):
        $ua=(float)($gb_a_full[$gr]??0); $ub=(float)($gb_b_full[$gr]??0); $p=pct($ua,$ub);
    ?>
    <tr>
      <td><span class="badge badge-gold" style="font-size:.6rem"><?=htmlspecialchars($gr)?></span></td>
      <td class="td-mono text-right"><?=number_format((int)($gb_bales_a_full[$gr]??0))?></td>
      <td class="td-mono text-right fw-500">$<?=number_format($ua,0)?></td>
      <td class="td-mono text-right" style="color:var(--muted)">$<?=number_format($ub,0)?></td>
      <td class="text-right"><?=arrow($p)?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
</div>

</div>

</div><!-- /panel-grades -->

<div id="panel-buyers" class="tab-panel" data-tabs="seasoncompare">
<!-- By Buyer comparison -->
<div class="card">
  <div class="card-head"><h3>By Buyer</h3></div>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>Buyer</th>
      <th class="text-right"><?=$yr_a?> USD</th>
      <th class="text-right"><?=$yr_b?> USD</th>
      <th class="text-right">Change</th>
    </tr></thead>
    <tbody>
    <?php
    $bb_a = array_column($buyers_a,'usd','buyer');
    $bb_b = array_column($buyers_b,'usd','buyer');
    $all_buyers = array_unique(array_merge(array_column($buyers_a,'buyer'),array_column($buyers_b,'buyer')));
    foreach($all_buyers as $bn):
        $ua=(float)($bb_a[$bn]??0); $ub=(float)($bb_b[$bn]??0); $p=pct($ua,$ub);
    ?>
    <tr>
      <td style="font-size:.78rem"><?=htmlspecialchars($bn)?></td>
      <td class="td-mono text-right fw-500">$<?=number_format($ua,0)?></td>
      <td class="td-mono text-right" style="color:var(--muted)">$<?=number_format($ub,0)?></td>
      <td class="text-right"><?=arrow($p)?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
</div>

<!-- Monthly detail table -->
<div class="card mt-3">
  <div class="card-head"><h3>Monthly Breakdown</h3></div>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>Month</th>
      <th class="text-right"><?=$yr_a?> Bales</th>
      <th class="text-right"><?=$yr_a?> USD</th>
      <th class="text-right"><?=$yr_b?> Bales</th>
      <th class="text-right"><?=$yr_b?> USD</th>
      <th class="text-right">USD Change</th>
    </tr></thead>
    <tbody>
    <?php
    $MONTHS=['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    foreach($all_months as $mo):
        $ua=(float)($mo_a[$mo]??0); $ub=(float)($mo_b[$mo]??0); $p=pct($ua,$ub);
        $ba=(int)($mo_bales_a[$mo]??0); $bb_v=(int)($mo_bales_b[$mo]??0);
    ?>
    <tr>
      <td style="font-weight:500"><?=$MONTHS[$mo]?></td>
      <td class="td-mono text-right"><?=$ba>0?number_format($ba):'—'?></td>
      <td class="td-mono text-right fw-500"><?=$ua>0?'$'.number_format($ua,0):'—'?></td>
      <td class="td-mono text-right" style="color:var(--muted)"><?=$bb_v>0?number_format($bb_v):'—'?></td>
      <td class="td-mono text-right" style="color:var(--muted)"><?=$ub>0?'$'.number_format($ub,0):'—'?></td>
      <td class="text-right"><?=($ua>0||$ub>0)?arrow($p):'—'?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
</div>
</div><!-- /panel-buyers -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
var moA = <?=json_encode($mo_a)?>;
var moB = <?=json_encode($mo_b)?>;
var labels = <?=json_encode(array_map(fn($m)=>$MONTHS[$m-1],$all_months))?>;
var dataA  = <?=json_encode(array_map(fn($m)=>(float)($mo_a[$m]??0),$all_months))?>;
var dataB  = <?=json_encode(array_map(fn($m)=>(float)($mo_b[$m]??0),$all_months))?>;

// Monthly bar chart (existing)
new Chart(document.getElementById('monthChart'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            { label: '<?=$yr_a?>', data: dataA, backgroundColor: 'rgba(200,149,42,.85)', borderRadius: 4 },
            { label: '<?=$yr_b?>', data: dataB, backgroundColor: 'rgba(61,107,53,.5)', borderRadius: 4 },
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { font: { size: 11 }, boxWidth: 12 } } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: { ticks: { callback: function(v){ return '$'+Math.round(v/1000)+'k'; }, font: { size: 10 } },
                 grid: { color: 'rgba(128,128,128,.1)' } }
        }
    }
});

// NEW: Cumulative USD progress line chart
var cumA = <?=json_encode($cum_a)?>;
var cumB = <?=json_encode($cum_b)?>;
var maxDays = Math.max(cumA.length, cumB.length);
var dayLabels = Array.from({length: maxDays}, (_, i) => 'Day ' + (i + 1));

new Chart(document.getElementById('cumChart'), {
    type: 'line',
    data: {
        labels: dayLabels,
        datasets: [
            {
                label: '<?=$yr_a?>',
                data: cumA.map(d => d.cumulative),
                borderColor: '#c8952a',
                backgroundColor: 'rgba(200,149,42,0.08)',
                borderWidth: 2.5,
                tension: 0.3,
                fill: true,
                pointRadius: cumA.length < 30 ? 4 : 2,
                pointHoverRadius: 6
            },
            {
                label: '<?=$yr_b?>',
                data: cumB.map(d => d.cumulative),
                borderColor: '#3d6b35',
                backgroundColor: 'rgba(61,107,53,0.08)',
                borderWidth: 2.5,
                tension: 0.3,
                fill: true,
                borderDash: [5,3],
                pointRadius: cumB.length < 30 ? 4 : 2,
                pointHoverRadius: 6
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', labels: { font: { size: 11 }, boxWidth: 14 } },
            tooltip: {
                callbacks: {
                    label: ctx => ' ' + ctx.dataset.label + ': $' + Math.round(ctx.raw || 0).toLocaleString()
                }
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { font: { size: 10 }, maxTicksLimit: 20 }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    callback: v => '$' + (v >= 1000000 ? (v/1000000).toFixed(1)+'M' : Math.round(v/1000)+'k'),
                    font: { size: 10 }
                },
                grid: { color: 'rgba(128,128,128,.1)' }
            }
        }
    }
});
</script>

<?php include "includes/layout_end.php"; ?>
