<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title  = "Grower Performance";
$active_page = "grower_performance";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);

function gpSafe($conn,$sql){if(!$conn)return[];$r=@$conn->query($sql);if(!$r||$r===true)return[];return $r->fetch_assoc()?:[];}
function gpAll($conn,$sql){if(!$conn)return[];$r=@$conn->query($sql);if(!$r||$r===true)return[];return $r->fetch_all(MYSQLI_ASSOC)?:[];}

$sp_filter = $spid ? "AND g.selling_pointid=$spid" : "";

// Search / filter
$search = trim($_GET['q'] ?? '');
$search_sql = $search ? "AND (g.grower_num LIKE '%".addslashes($search)."%' OR g.name LIKE '%".addslashes($search)."%' OR g.surname LIKE '%".addslashes($search)."%')" : "";

// KPIs
$total_growers = gpSafe($conn,"SELECT COUNT(*) c FROM growers g WHERE g.active=1 $sp_filter");
$active_growers = gpSafe($conn,"SELECT COUNT(DISTINCT bb.growerid) c FROM balanced_bales bb JOIN transporter_growers tg ON tg.id=bb.transporter_growersid JOIN growers g ON g.id=tg.growerid WHERE 1=1 $sp_filter");
$total_mass = gpSafe($conn,"SELECT COALESCE(SUM(dt.mass),0) m FROM balanced_bales bb JOIN transporter_growers tg ON tg.id=bb.transporter_growersid JOIN growers g ON g.id=tg.growerid LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id WHERE 1=1 $sp_filter");
$total_usd = gpSafe($conn,"SELECT COALESCE(SUM(dt.usd_value),0) u FROM balanced_bales bb JOIN transporter_growers tg ON tg.id=bb.transporter_growersid JOIN growers g ON g.id=tg.growerid LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id WHERE 1=1 $sp_filter");

// Top growers by total USD
$rankings = gpAll($conn,"SELECT g.id, g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
        g.province, g.farm_name,
        COUNT(DISTINCT bb.id) batches,
        COALESCE(SUM(dt.sold_bales),0) total_bales,
        COALESCE(SUM(dt.mass),0) total_mass,
        COALESCE(SUM(dt.usd_value),0) total_usd,
        COALESCE(AVG(dt.average_price),0) avg_price,
        COALESCE(SUM(dt.rejected_bales),0) rejected
    FROM growers g
    LEFT JOIN transporter_growers tg ON tg.growerid=g.id
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    WHERE g.active=1 $sp_filter $search_sql
    GROUP BY g.id
    ORDER BY total_usd DESC
    LIMIT 50");

// Grade distribution for selected grower (or top grower)
$sel_gid = (int)($_GET['gid'] ?? ($rankings[0]['id'] ?? 0));
$sel_grower = gpSafe($conn,"SELECT g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) n, g.province, g.farm_name FROM growers g WHERE g.id=$sel_gid");
$grade_dist = gpAll($conn,"SELECT tg.description grade, COUNT(sb.id) cnt, COALESCE(SUM(bm.mass),0) mass
    FROM sold_bales sb
    JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    WHERE bj.growerid=$sel_gid
    GROUP BY tg.id ORDER BY cnt DESC LIMIT 10");

// Monthly performance for selected grower
$monthly = gpAll($conn,"SELECT DATE_FORMAT(bb.created_at,'%Y-%m') month_key,
        COALESCE(SUM(dt.sold_bales),0) bales,
        COALESCE(SUM(dt.usd_value),0) usd,
        COALESCE(SUM(dt.mass),0) mass
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    WHERE tg.growerid=$sel_gid
    GROUP BY month_key ORDER BY month_key ASC LIMIT 12");

// Earnings projection for booked growers
$proj_booked = gpSafe($conn,"SELECT SUM(tg.bales) bales FROM transporter_growers tg
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
    WHERE tg.booked=1 AND bb.id IS NULL $sp_filter");
$avg_bale_val = gpSafe($conn,"SELECT COALESCE(AVG(dt.usd_value/GREATEST(dt.sold_bales,1)),0) v FROM daily_totals dt
    JOIN balanced_bales bb ON bb.id=dt.balanced_balesid
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid WHERE dt.sold_bales>0 $sp_filter");
$proj_val = (int)($proj_booked['bales']??0) * (float)($avg_bale_val['v']??0);

// ── NEW: Profitability Score & enriched rankings ──
// Get prior year for season-over-season
$cur_year = date('Y');
$prev_year = $cur_year - 1;

// Prior season data (same growers, previous year)
$prior_season = gpAll($conn,"SELECT tg2.growerid,
        COALESCE(SUM(dt2.usd_value),0) prev_usd,
        COALESCE(SUM(dt2.mass),0) prev_mass
    FROM balanced_bales bb2
    JOIN transporter_growers tg2 ON tg2.id=bb2.transporter_growersid
    JOIN growers g2 ON g2.id=tg2.growerid
    LEFT JOIN daily_totals dt2 ON dt2.balanced_balesid=bb2.id
    WHERE YEAR(bb2.created_at)=$prev_year AND g2.active=1 $sp_filter
    GROUP BY tg2.growerid");
$prior_map = [];
foreach ($prior_season as $ps) {
    $prior_map[(int)$ps['growerid']] = $ps;
}

// Per-grower grade distribution for mini inline bar
$grade_mini = gpAll($conn,"SELECT bj.growerid, tg.description grade, COUNT(sb.id) cnt
    FROM sold_bales sb
    JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    WHERE g.active=1 $sp_filter
    GROUP BY bj.growerid, tg.id");
$grade_mini_map = [];
foreach ($grade_mini as $gm) {
    $grade_mini_map[(int)$gm['growerid']][] = $gm;
}

// Global price stats for scoring normalization
$global_stats = gpSafe($conn,"SELECT
    MAX(CASE WHEN dt.mass>0 THEN dt.usd_value/dt.mass ELSE 0 END) max_upkg,
    MIN(CASE WHEN dt.mass>0 AND dt.usd_value>0 THEN dt.usd_value/dt.mass ELSE 9999 END) min_upkg
    FROM daily_totals dt
    JOIN balanced_bales bb ON bb.id=dt.balanced_balesid
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    WHERE dt.mass>0 $sp_filter");
$max_upkg = max(1, (float)($global_stats['max_upkg'] ?? 1));
$min_upkg = (float)($global_stats['min_upkg'] ?? 0);

// Compute profitability score for each ranking row
$GRADE_SCORES = ['FLR'=>100,'SLR'=>90,'TLR'=>80,'HF'=>70,'FVR'=>85,'CX'=>60,'KK'=>50,'RJ'=>10];
foreach ($rankings as &$r) {
    $total_b = (int)($r['total_bales'] ?? 0);
    $rejected = (int)($r['rejected'] ?? 0);
    $mass = (float)($r['total_mass'] ?? 0);
    $usd = (float)($r['total_usd'] ?? 0);
    $gid = (int)$r['id'];

    // Net USD/kg (no separate deduction table assumed; use gross)
    $net_upkg = $mass > 0 ? $usd / $mass : 0;

    // Rejection rate
    $rej_rate = ($total_b + $rejected) > 0 ? $rejected / ($total_b + $rejected) : 0;
    $rej_inv = 1 - $rej_rate; // higher = better

    // Net $/kg score (0-100)
    $range = $max_upkg - $min_upkg;
    $upkg_score = $range > 0 ? min(100, max(0, ($net_upkg - $min_upkg) / $range * 100)) : 50;

    // Grade quality score: weighted average of grade scores from mini map
    $g_items = $grade_mini_map[$gid] ?? [];
    $g_total = array_sum(array_column($g_items, 'cnt')) ?: 1;
    $g_wscore = 0;
    foreach ($g_items as $gi) {
        $grade_key = strtoupper(substr($gi['grade'], 0, 3));
        $gs = $GRADE_SCORES[$grade_key] ?? 50;
        $g_wscore += $gs * ((int)$gi['cnt'] / $g_total);
    }
    if (empty($g_items)) $g_wscore = 50;

    // Composite
    $prof_score = round($upkg_score * 0.40 + $rej_inv * 100 * 0.30 + $g_wscore * 0.30);
    $r['prof_score'] = $prof_score;
    $r['net_upkg']   = $net_upkg;
    $r['rej_rate']   = $rej_rate;
    $r['grade_items'] = $g_items;
    $r['g_total']     = $g_total;

    // Prior season delta
    $prev = $prior_map[$gid] ?? null;
    if ($prev && (float)$prev['prev_mass'] > 0 && $mass > 0) {
        $prev_upkg = (float)$prev['prev_usd'] / (float)$prev['prev_mass'];
        $r['prev_upkg'] = $prev_upkg;
        $r['delta_upkg'] = $net_upkg - $prev_upkg;
    } else {
        $r['prev_upkg'] = null;
        $r['delta_upkg'] = null;
    }
}
unset($r);

// Sort for top/bottom 10
usort($rankings, fn($a,$b) => $b['prof_score'] - $a['prof_score']);
$top10    = array_slice($rankings, 0, 10);
$bottom10 = array_slice(array_reverse($rankings), 0, 10);
// Re-sort for display (USD descending)
usort($rankings, fn($a,$b) => $b['total_usd'] <=> $a['total_usd']);

// CSV export
if (isset($_GET['export_csv'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="grower_performance_'.date('Ymd').'.csv"');
    echo "Grower #,Grower Name,Farm,Province,Batches,Total Bales,Rejected,Rejection%,Total Mass,Total USD,Net $/kg,Profitability Score,Prior $/kg,Delta $/kg\r\n";
    foreach ($rankings as $r) {
        $rej_pct = round(($r['rej_rate'] ?? 0) * 100, 1);
        $prev    = $r['prev_upkg'] !== null ? number_format((float)$r['prev_upkg'], 4) : '';
        $delta   = $r['delta_upkg'] !== null ? number_format((float)$r['delta_upkg'], 4) : '';
        echo '"'.addcslashes($r['grower_num']??'', '"').'",';
        echo '"'.addcslashes($r['grower_name']??'', '"').'",';
        echo '"'.addcslashes($r['farm_name']??'', '"').'",';
        echo '"'.addcslashes($r['province']??'', '"').'",';
        echo (int)$r['batches'].',';
        echo (int)$r['total_bales'].',';
        echo (int)$r['rejected'].',';
        echo $rej_pct.'%,';
        echo number_format((float)$r['total_mass'], 2).',';
        echo number_format((float)$r['total_usd'], 2).',';
        echo number_format((float)$r['net_upkg'], 4).',';
        echo (int)$r['prof_score'].',';
        echo $prev.',';
        echo $delta."\r\n";
    }
    exit;
}

include "includes/layout.php";
?>

<style>
.gp-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.gp-rank-medal { display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;font-size:.72rem;font-weight:700;flex-shrink:0; }
.gp-rank-1 { background:linear-gradient(135deg,#f0c27f,#c8952a);color:#1a1208; }
.gp-rank-2 { background:linear-gradient(135deg,#c0c0c0,#8a8a8a);color:#fff; }
.gp-rank-3 { background:linear-gradient(135deg,#d4a574,#8B6914);color:#fff; }
.gp-rank-n { background:var(--cream-d);color:var(--muted); }
@keyframes gp-grow { from { transform: scaleX(0); } to { transform: scaleX(1); } }
.gp-bar-fill { animation: gp-grow .6s ease both; transform-origin: left; }
@media(max-width:900px) { .gp-detail-grid { grid-template-columns:1fr; } }

/* Profitability Score badge */
.prof-score {
    display:inline-flex;align-items:center;justify-content:center;
    width:34px;height:34px;border-radius:50%;
    font-family:'DM Sans',sans-serif;font-size:0.77rem;font-weight:700;
    flex-shrink:0;
}
.prof-score.high  { background:linear-gradient(135deg,#4caf50,#2e7d32);color:#fff; }
.prof-score.mid   { background:linear-gradient(135deg,#e8b84b,#c8952a);color:#1a1208; }
.prof-score.low   { background:linear-gradient(135deg,#ef5350,#c62828);color:#fff; }

/* Grade mini inline bar */
.grade-mini-bar { display:flex;height:6px;border-radius:3px;overflow:hidden;width:80px;flex-shrink:0; }
.grade-mini-seg { height:100%; }

/* Delta pill */
.delta-pill { display:inline-flex;align-items:center;gap:2px;padding:1px 5px;border-radius:10px;font-size:.72rem;font-weight:600; }
.delta-up   { background:var(--surface-2);color:var(--ok-text); }
.delta-dn   { background:var(--surface-2);color:var(--bad-text); }
.delta-neu  { background:var(--cream-d);color:var(--muted); }

/* Top/Bottom tables */
.tb-grid { display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem; }
@media(max-width:900px){ .tb-grid{grid-template-columns:1fr;} }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Grower Performance</h2>
    <p>Rankings, profitability scores, grade analysis &amp; earnings projection</p>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
    <form method="GET" style="display:flex;gap:.5rem;align-items:center">
      <input type="text" name="q" class="form-control" placeholder="Search grower..." value="<?=htmlspecialchars($search)?>" style="width:200px">
      <button type="submit" class="btn btn-gold btn-sm">Search</button>
      <?php if($search): ?><a href="grower_performance.php" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
    </form>
    <a href="?q=<?=urlencode($search)?>&export_csv=1" class="btn btn-ghost btn-sm">⬇ Export CSV</a>
  </div>
</div>

<!-- KPI Strip -->
<div class="kpi-grid">
  <div class="kpi-card" style="--kpi-color:var(--gold)">
    <div class="kpi-label">Registered Growers</div>
    <div class="kpi-value"><?=number_format((int)($total_growers['c']??0))?></div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)">
    <div class="kpi-label">Active This Season</div>
    <div class="kpi-value"><?=number_format((int)($active_growers['c']??0))?></div>
  </div>
  <div class="kpi-card" style="--kpi-color:#e8b84b">
    <div class="kpi-label">Total Mass Sold</div>
    <div class="kpi-value sm"><?=number_format((float)($total_mass['m']??0),0)?></div>
    <div class="kpi-sub">kg season</div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--gold)">
    <div class="kpi-label">Season USD</div>
    <div class="kpi-value sm">$<?=number_format((float)($total_usd['u']??0),0)?></div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)">
    <div class="kpi-label">Projected (Booked)</div>
    <div class="kpi-value sm">$<?=number_format($proj_val,0)?></div>
    <div class="kpi-sub"><?=(int)($proj_booked['bales']??0)?> bales pending</div>
  </div>
</div>

<div class="premium-tabs-nav" data-tabs="growerperf">
  <button type="button" class="premium-tab-btn active" data-tab="rankings">🏆 Rankings &amp; Detail</button>
  <button type="button" class="premium-tab-btn" data-tab="leaders">🏅 Top / Bottom 10</button>
</div>

<!-- Top 10 / Bottom 10 Profitability Tables -->
<div id="panel-leaders" class="tab-panel" data-tabs="growerperf">
<div class="tb-grid">
  <!-- Top 10 -->
  <div class="card">
    <div class="card-head">
      <h3>🏅 Top 10 — Profitability Score</h3>
      <span class="badge badge-green">Best performers</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Grower</th><th class="text-right">Score</th><th class="text-right">Net $/kg</th><th class="text-right">Rej%</th></tr></thead>
        <tbody>
        <?php foreach($top10 as $i=>$r):
          $sc = (int)$r['prof_score'];
          $cls = $sc>=70?'high':($sc>=40?'mid':'low');
        ?>
        <tr style="cursor:pointer" onclick="location.href='?gid=<?=$r['id']?>&q=<?=urlencode($search)?>'">
          <td style="font-weight:600;color:var(--muted);font-size:.72rem"><?=$i+1?></td>
          <td>
            <div style="font-weight:500;font-size:.78rem"><?=htmlspecialchars($r['grower_name'])?></div>
            <div style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($r['grower_num'])?></div>
          </td>
          <td class="text-right"><span class="prof-score <?=$cls?>"><?=$sc?></span></td>
          <td class="td-mono text-right" style="font-size:.78rem">$<?=number_format($r['net_upkg'],2)?></td>
          <td class="td-mono text-right" style="font-size:.75rem;color:<?=$r['rej_rate']>0.05?'var(--danger)':'var(--leaf)'?>">
            <?=number_format($r['rej_rate']*100,1)?>%
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Bottom 10 -->
  <div class="card">
    <div class="card-head">
      <h3>⚠ Bottom 10 — Profitability Score</h3>
      <span class="badge badge-red">Needs attention</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Grower</th><th class="text-right">Score</th><th class="text-right">Net $/kg</th><th class="text-right">Rej%</th></tr></thead>
        <tbody>
        <?php foreach($bottom10 as $i=>$r):
          $sc = (int)$r['prof_score'];
          $cls = $sc>=70?'high':($sc>=40?'mid':'low');
        ?>
        <tr style="cursor:pointer" onclick="location.href='?gid=<?=$r['id']?>&q=<?=urlencode($search)?>'">
          <td style="font-weight:600;color:var(--muted);font-size:.72rem"><?=$i+1?></td>
          <td>
            <div style="font-weight:500;font-size:.78rem"><?=htmlspecialchars($r['grower_name'])?></div>
            <div style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($r['grower_num'])?></div>
          </td>
          <td class="text-right"><span class="prof-score <?=$cls?>"><?=$sc?></span></td>
          <td class="td-mono text-right" style="font-size:.78rem">$<?=number_format($r['net_upkg'],2)?></td>
          <td class="td-mono text-right" style="font-size:.75rem;color:<?=$r['rej_rate']>0.05?'var(--danger)':'var(--leaf)'?>">
            <?=number_format($r['rej_rate']*100,1)?>%
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div><!-- /panel-leaders -->

<!-- Rankings Table + Detail Panel -->
<div id="panel-rankings" class="tab-panel active" data-tabs="growerperf">
<div class="gp-detail-grid mb-3">
  <!-- Rankings -->
  <div class="card">
    <div class="card-head">
      <h3>🏆 Grower Rankings</h3>
      <span class="badge badge-gold"><?=count($rankings)?> growers</span>
    </div>
    <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
      <table>
        <thead><tr>
          <th>#</th>
          <th>Grower</th>
          <th class="text-right">Score</th>
          <th class="text-right">Bales</th>
          <th class="text-right">USD</th>
          <th class="text-right">Net $/kg</th>
          <th class="text-right">Rej%</th>
          <th>Grades</th>
          <th class="text-right">YoY</th>
        </tr></thead>
        <tbody>
        <?php
        $GRADE_COLORS = ['#c8952a','#3d6b35','#e8b84b','#1a6b9a','#c0392b','#8e44ad','#2c3e50','#e67e22','#16a085','#d35400'];
        foreach($rankings as $i=>$r):
          $medal = $i===0?'gp-rank-1':($i===1?'gp-rank-2':($i===2?'gp-rank-3':'gp-rank-n'));
          $selected = (int)$r['id'] === $sel_gid;
          $sc = (int)$r['prof_score'];
          $cls = $sc>=70?'high':($sc>=40?'mid':'low');
          $g_items = $r['grade_items'] ?? [];
          $g_total = max(1, $r['g_total'] ?? 1);
          // Delta
          $delta = $r['delta_upkg'];
          if ($delta !== null) {
              if ($delta > 0.001) $delta_html = '<span class="delta-pill delta-up">▲ $'.number_format($delta,2).'</span>';
              elseif ($delta < -0.001) $delta_html = '<span class="delta-pill delta-dn">▼ $'.number_format(abs($delta),2).'</span>';
              else $delta_html = '<span class="delta-pill delta-neu">— same</span>';
          } else {
              $delta_html = '<span style="color:var(--muted);font-size:.6rem">—</span>';
          }
        ?>
        <tr style="<?=$selected?'background:var(--gold-bg)':''?>;cursor:pointer" onclick="location.href='?gid=<?=$r['id']?>&q=<?=urlencode($search)?>'">
          <td><span class="gp-rank-medal <?=$medal?>"><?=$i+1?></span></td>
          <td>
            <div style="font-weight:500;font-size:.78rem"><?=htmlspecialchars($r['grower_name'])?></div>
            <div style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($r['grower_num'])?></div>
          </td>
          <td class="text-right"><span class="prof-score <?=$cls?>" style="width:28px;height:28px;font-size:.75rem"><?=$sc?></span></td>
          <td class="td-mono text-right"><?=number_format((int)$r['total_bales'])?></td>
          <td class="td-mono text-right" style="font-weight:500;color:var(--gold)">$<?=number_format((float)$r['total_usd'],0)?></td>
          <td class="td-mono text-right">$<?=number_format($r['net_upkg'],2)?></td>
          <td class="td-mono text-right" style="color:<?=$r['rej_rate']>0.05?'var(--danger)':'inherit'?>"><?=number_format($r['rej_rate']*100,1)?>%</td>
          <td>
            <?php if(!empty($g_items)): ?>
            <div class="grade-mini-bar" title="Grade distribution">
              <?php foreach($g_items as $ci=>$gi):
                $w = round((int)$gi['cnt'] / $g_total * 100);
                $col = $GRADE_COLORS[$ci % count($GRADE_COLORS)];
              ?>
              <div class="grade-mini-seg" style="width:<?=$w?>%;background:<?=$col?>" title="<?=htmlspecialchars($gi['grade'])?>: <?=$gi['cnt']?> bales"></div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <span style="font-size:.72rem;color:var(--muted)">—</span>
            <?php endif; ?>
          </td>
          <td class="text-right"><?=$delta_html?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($rankings)): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:2rem">No growers found</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Detail Panel -->
  <div>
    <?php if($sel_gid && !empty($sel_grower['n'])): ?>
    <!-- Grower Profile -->
    <?php
    // Find selected grower's enriched data
    $sel_enriched = null;
    foreach($rankings as $r) { if((int)$r['id']===$sel_gid){ $sel_enriched=$r; break; } }
    ?>
    <div class="card mb-2">
      <div class="card-head">
        <h3>👤 <?=htmlspecialchars($sel_grower['n'])?></h3>
        <span class="badge badge-gold"><?=htmlspecialchars($sel_grower['grower_num']??'')?></span>
      </div>
      <div class="card-body">
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.75rem">
          <?=htmlspecialchars($sel_grower['farm_name']??'')?> <?=htmlspecialchars($sel_grower['province']??'')?>
        </div>
        <?php if($sel_enriched): ?>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;text-align:center">
          <div>
            <div style="font-family:'DM Sans',sans-serif;font-size:1.36rem;line-height:1;color:var(--gold);font-weight:600"><?=$sel_enriched['prof_score']?></div>
            <div style="font-size:.78rem;color:var(--muted);">Profit Score</div>
          </div>
          <div>
            <div style="font-family:'DM Sans',sans-serif;font-size:1.36rem;line-height:1;font-weight:600">$<?=number_format($sel_enriched['net_upkg'],2)?></div>
            <div style="font-size:.78rem;color:var(--muted);">Net $/kg</div>
          </div>
          <div>
            <div style="font-family:'DM Sans',sans-serif;font-size:1.36rem;line-height:1;color:<?=$sel_enriched['rej_rate']>0.05?'var(--danger)':'var(--leaf)'?>;font-weight:600"><?=number_format($sel_enriched['rej_rate']*100,1)?>%</div>
            <div style="font-size:.78rem;color:var(--muted);">Rejection</div>
          </div>
          <div>
            <?php if($sel_enriched['delta_upkg']!==null): $d=$sel_enriched['delta_upkg']; ?>
            <div style="font-family:'DM Sans',sans-serif;font-size:1.36rem;line-height:1;color:<?=$d>=0?'var(--leaf)':'var(--danger)'?>;font-weight:600"><?=$d>=0?'+':''?><?=number_format($d,2)?></div>
            <div style="font-size:.78rem;color:var(--muted);">YoY $/kg</div>
            <?php else: ?>
            <div style="font-family:'DM Sans',sans-serif;font-size:1.36rem;line-height:1;color:var(--muted);font-weight:600">—</div>
            <div style="font-size:.78rem;color:var(--muted);">YoY $/kg</div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Grade Distribution -->
    <div class="card mb-2">
      <div class="card-head"><h3>📊 Grade Distribution</h3></div>
      <div class="card-body">
        <canvas id="gp-grade-chart" height="180"></canvas>
      </div>
    </div>

    <!-- Monthly Performance -->
    <div class="card">
      <div class="card-head"><h3>📈 Monthly Trend</h3></div>
      <div class="card-body">
        <canvas id="gp-monthly-chart" height="160"></canvas>
      </div>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-body" style="padding:3rem;text-align:center;color:var(--muted)">
        <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem">👤</div>
        Select a grower from the rankings to view their detailed performance.
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
</div><!-- /panel-rankings -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
// Grade Distribution Doughnut
const gradeData = <?=json_encode($grade_dist)?>;
if (gradeData.length && document.getElementById('gp-grade-chart')) {
    const colors = ['#c8952a','#3d6b35','#e8b84b','#1a6b9a','#c0392b','#8e44ad','#2c3e50','#e67e22','#16a085','#d35400'];
    new Chart(document.getElementById('gp-grade-chart'), {
        type: 'doughnut',
        data: {
            labels: gradeData.map(g => g.grade),
            datasets: [{
                data: gradeData.map(g => parseInt(g.cnt)),
                backgroundColor: colors.slice(0, gradeData.length),
                borderWidth: 1, borderColor: '#fff'
            }]
        },
        options: { responsive:true, plugins:{ legend:{position:'right',labels:{boxWidth:10,font:{size:10}}} } }
    });
}

// Monthly Performance Bar
const monthData = <?=json_encode($monthly)?>;
if (monthData.length && document.getElementById('gp-monthly-chart')) {
    new Chart(document.getElementById('gp-monthly-chart'), {
        type: 'bar',
        data: {
            labels: monthData.map(m => m.month_key),
            datasets: [{
                label: 'USD Value',
                data: monthData.map(m => parseFloat(m.usd)),
                backgroundColor: 'rgba(200,149,42,0.6)',
                borderRadius: 4
            }]
        },
        options: {
            responsive:true,
            plugins:{legend:{display:false}},
            scales:{ y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.04)'},ticks:{callback:v=>'$'+Math.round(v/1000)+'k'}}, x:{grid:{display:false}} }
        }
    });
}
</script>

<?php include "includes/layout_end.php"; ?>
