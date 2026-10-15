<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title  = "Penalties & Incentives";
$active_page = "penalties";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);

function pnSafe($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_assoc()?:[];}
function pnAll($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_all(MYSQLI_ASSOC)?:[];}
$sf = $spid ? "AND g.selling_pointid=$spid" : "";

// Late deliveries: booked but delivered late (sell_date < delivery date)
$late_deliveries = pnAll($conn,"SELECT g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
    tg.sell_date booked_date, DATE(bb.created_at) actual_date,
    DATEDIFF(bb.created_at, tg.sell_date) days_late,
    t.name transporter_name,
    COALESCE(dt.usd_value,0) usd_value
    FROM transporter_growers tg
    JOIN growers g ON g.id=tg.growerid
    JOIN transporter t ON t.id=tg.transporterid
    JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    WHERE tg.booked=1 AND tg.sell_date IS NOT NULL
    AND DATEDIFF(bb.created_at, tg.sell_date) > 0 $sf
    ORDER BY days_late DESC LIMIT 30");

// High-performing growers (consistency metric: avg price > overall avg)
$overall_avg = pnSafe($conn,"SELECT COALESCE(AVG(dt.average_price),0) v FROM daily_totals dt
    JOIN balanced_bales bb ON bb.id=dt.balanced_balesid
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid WHERE dt.average_price>0 $sf");
$avg_threshold = (float)($overall_avg['v'] ?? 0) * 1.15;

$top_performers = pnAll($conn,"SELECT g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
    COUNT(DISTINCT bb.id) batches,
    COALESCE(AVG(dt.average_price),0) avg_price,
    COALESCE(SUM(dt.sold_bales),0) total_bales,
    COALESCE(SUM(dt.rejected_bales),0) rejected,
    COALESCE(SUM(dt.usd_value),0) total_usd
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    WHERE dt.average_price>0 $sf
    GROUP BY g.id
    HAVING avg_price >= $avg_threshold AND total_bales >= 5
    ORDER BY avg_price DESC LIMIT 20");

// Transporter performance
$transporter_perf = pnAll($conn,"SELECT t.name transporter_name,
    COUNT(DISTINCT tg.id) total_bookings,
    SUM(CASE WHEN bb.id IS NOT NULL AND DATEDIFF(bb.created_at, tg.sell_date) <= 0 THEN 1 ELSE 0 END) on_time,
    SUM(CASE WHEN bb.id IS NOT NULL AND DATEDIFF(bb.created_at, tg.sell_date) > 0 THEN 1 ELSE 0 END) late_cnt,
    SUM(CASE WHEN bb.id IS NULL THEN 1 ELSE 0 END) pending
    FROM transporter_growers tg
    JOIN transporter t ON t.id=tg.transporterid
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
    WHERE tg.booked=1 AND tg.sell_date IS NOT NULL $sf
    GROUP BY t.id
    ORDER BY total_bookings DESC LIMIT 15");

// KPIs
$late_count = count($late_deliveries);
$perf_count = count($top_performers);
$total_late_value = array_sum(array_column($late_deliveries, 'usd_value'));

include "includes/layout.php";
?>

<style>
.pn-incentive-card { background:linear-gradient(135deg,rgba(61,107,53,.06),rgba(61,107,53,.02));border:1px solid rgba(61,107,53,.15);border-radius:var(--radius);padding:.85rem;display:flex;align-items:center;gap:.75rem;transition:all .2s; }
.pn-incentive-card:hover { border-color:var(--leaf);transform:translateX(3px); }
.pn-medal { width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0; }
.pn-late-bar { height:6px;background:var(--cream-d);border-radius:3px;flex:1; }
.pn-late-fill { height:100%;border-radius:3px;background:var(--danger); }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Penalties & Incentives</h2>
    <p>Late delivery tracking, quality rewards, transporter performance</p>
  </div>
</div>

<div class="kpi-grid">
  <div class="kpi-card" style="--kpi-color:var(--danger)"><div class="kpi-label">Late Deliveries</div><div class="kpi-value"><?=$late_count?></div><div class="kpi-sub">growers delivered late</div></div>
  <div class="kpi-card" style="--kpi-color:var(--gold)"><div class="kpi-label">Late Delivery Value</div><div class="kpi-value sm">$<?=number_format($total_late_value,0)?></div><div class="kpi-sub">affected USD value</div></div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)"><div class="kpi-label">Top Performers</div><div class="kpi-value"><?=$perf_count?></div><div class="kpi-sub">above <?=number_format($avg_threshold,2)?> avg $/kg</div></div>
  <div class="kpi-card" style="--kpi-color:#e8b84b"><div class="kpi-label">Avg Price Threshold</div><div class="kpi-value sm">$<?=number_format((float)($overall_avg['v']??0),2)?></div><div class="kpi-sub">season average $/kg</div></div>
</div>

<div class="premium-tabs-nav" data-tabs="penalties">
  <button type="button" class="premium-tab-btn active" data-tab="late">⚠️ Late Deliveries</button>
  <button type="button" class="premium-tab-btn" data-tab="incentives">🌟 Quality Incentives</button>
  <button type="button" class="premium-tab-btn" data-tab="transporters">🚛 Transporter On-Time</button>
</div>

<div id="panel-late" class="tab-panel active" data-tabs="penalties">
  <!-- Late Deliveries -->
  <div class="card">
    <div class="card-head"><h3>⚠️ Late Deliveries</h3><span class="badge badge-red"><?=$late_count?></span></div>
    <div class="table-wrap" style="max-height:420px;overflow-y:auto">
      <table>
        <thead><tr><th>Grower</th><th>Transporter</th><th>Booked</th><th>Delivered</th><th class="text-right">Days Late</th></tr></thead>
        <tbody>
        <?php foreach($late_deliveries as $ld): ?>
        <tr>
          <td><div style="font-weight:500;font-size:.78rem"><?=htmlspecialchars($ld['grower_name'])?></div><div style="font-size:.6rem;color:var(--muted)"><?=htmlspecialchars($ld['grower_num'])?></div></td>
          <td style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($ld['transporter_name'])?></td>
          <td class="td-mono" style="font-size:.72rem"><?=htmlspecialchars($ld['booked_date']??'')?></td>
          <td class="td-mono" style="font-size:.72rem"><?=htmlspecialchars($ld['actual_date']??'')?></td>
          <td class="td-mono text-right" style="color:var(--danger);font-weight:600">+<?=(int)$ld['days_late']?>d</td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($late_deliveries)): ?>
        <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">✅ No late deliveries — excellent!</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div><!-- /panel-late -->

<div id="panel-incentives" class="tab-panel" data-tabs="penalties">
  <!-- Quality Incentive Candidates -->
  <div class="card">
    <div class="card-head"><h3>🌟 Quality Incentive Candidates</h3><span class="badge badge-green"><?=$perf_count?></span></div>
    <div class="card-body" style="max-height:420px;overflow-y:auto;display:flex;flex-direction:column;gap:.5rem">
      <?php foreach($top_performers as $i=>$tp):
        $medal = $i < 3 ? ['🥇','🥈','🥉'][$i] : '⭐';
        $rej_pct = (int)$tp['total_bales'] > 0 ? ((int)$tp['rejected'] / (int)$tp['total_bales']) * 100 : 0;
      ?>
      <div class="pn-incentive-card">
        <div class="pn-medal" style="background:<?=$i<3?'rgba(200,149,42,.12)':'var(--cream-d)'?>"><?=$medal?></div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:500;font-size:.82rem"><?=htmlspecialchars($tp['grower_name'])?></div>
          <div style="font-size:.62rem;color:var(--muted)"><?=htmlspecialchars($tp['grower_num'])?> · <?=(int)$tp['total_bales']?> bales · <?=round($rej_pct,1)?>% rejected</div>
        </div>
        <div style="text-align:right">
          <div style="font-family:'Bebas Neue',sans-serif;font-size:1.15rem;color:var(--leaf)">$<?=number_format((float)$tp['avg_price'],2)?></div>
          <div style="font-size:.58rem;color:var(--muted)">avg $/kg</div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if(empty($top_performers)): ?>
      <div style="padding:2rem;text-align:center;color:var(--muted)">No qualifying performers yet (need ≥5 bales & above-avg price)</div>
      <?php endif; ?>
    </div>
  </div>
</div><!-- /panel-incentives -->

<!-- Transporter Performance -->
<div id="panel-transporters" class="tab-panel" data-tabs="penalties">
<div class="card">
  <div class="card-head"><h3>🚛 Transporter On-Time Rate</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Transporter</th><th class="text-right">Bookings</th><th class="text-right">On-Time</th><th class="text-right">Late</th><th class="text-right">Pending</th><th>On-Time Rate</th></tr></thead>
      <tbody>
      <?php foreach($transporter_perf as $tp2):
        $total_done = (int)$tp2['on_time'] + (int)$tp2['late_cnt'];
        $on_time_pct = $total_done > 0 ? ((int)$tp2['on_time'] / $total_done) * 100 : 0;
        $pcolor = $on_time_pct >= 80 ? 'var(--leaf)' : ($on_time_pct >= 50 ? 'var(--gold)' : 'var(--danger)');
      ?>
      <tr>
        <td style="font-weight:500"><?=htmlspecialchars($tp2['transporter_name'])?></td>
        <td class="td-mono text-right"><?=(int)$tp2['total_bookings']?></td>
        <td class="td-mono text-right" style="color:var(--leaf)"><?=(int)$tp2['on_time']?></td>
        <td class="td-mono text-right" style="color:var(--danger)"><?=(int)$tp2['late_cnt']?></td>
        <td class="td-mono text-right" style="color:var(--muted)"><?=(int)$tp2['pending']?></td>
        <td style="min-width:120px">
          <div style="display:flex;align-items:center;gap:6px">
            <div style="flex:1;height:8px;background:var(--cream-d);border-radius:4px;overflow:hidden"><div style="height:100%;width:<?=$on_time_pct?>%;background:<?=$pcolor?>;border-radius:4px"></div></div>
            <span style="font-size:.68rem;font-weight:500;color:<?=$pcolor?>"><?=round($on_time_pct)?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</div><!-- /panel-transporters -->

<?php include "includes/layout_end.php"; ?>
