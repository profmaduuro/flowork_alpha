<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title  = "Quality Control";
$active_page = "quality_control";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);

function qcSafe($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_assoc()?:[];}
function qcAll($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_all(MYSQLI_ASSOC)?:[];}
$sf = $spid ? "AND g.selling_pointid=$spid" : "";

// Grade distribution overview
$grade_overview = qcAll($conn,"SELECT tg.description grade, COUNT(sb.id) cnt,
    COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) avg_price,
    COALESCE(SUM(bm.mass),0) total_mass,
    COALESCE(MIN(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) min_price,
    COALESCE(MAX(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END),0) max_price
    FROM sold_bales sb
    JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    WHERE tg.active=1 $sf
    GROUP BY tg.id ORDER BY cnt DESC");

// Grade dispute stats
$dispute_stats = qcSafe($conn,"SELECT COUNT(*) total,
    SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) open_cnt,
    SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) approved_cnt,
    SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) rejected_cnt,
    SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) resolved_cnt
    FROM grade_disputes WHERE 1=1 ".($spid?"AND selling_pointid=$spid":""));

// Recent disputes
$disputes = qcAll($conn,"SELECT gd.*, g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
    tk.barcode
    FROM grade_disputes gd
    JOIN growers g ON g.id=gd.growerid
    LEFT JOIN sold_bales sb ON sb.id=gd.sold_balesid
    LEFT JOIN tickets tk ON tk.id=sb.ticketsid
    WHERE 1=1 ".($spid?"AND gd.selling_pointid=$spid":"")."
    ORDER BY gd.raised_at DESC LIMIT 30");

// Rejection rate by grade
$rejection = qcAll($conn,"SELECT tg.description grade,
    COALESCE(SUM(dt.laid_bales),0) laid,
    COALESCE(SUM(dt.sold_bales),0) sold,
    COALESCE(SUM(dt.rejected_bales),0) rejected
    FROM daily_totals dt
    JOIN balanced_bales bb ON bb.id=dt.balanced_balesid
    JOIN transporter_growers tg2 ON tg2.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg2.growerid
    LEFT JOIN sold_bales sb ON sb.ticketsid IN (SELECT tk.id FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid WHERE bj.transporter_growersid=tg2.id LIMIT 1)
    LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    WHERE dt.rejected_bales>0 $sf
    GROUP BY tg.id
    ORDER BY rejected DESC LIMIT 15");

// Most disputed grades
$disputed_grades = qcAll($conn,"SELECT tg.description grade, COUNT(gd.id) dispute_count,
    COALESCE(AVG(gd.original_price),0) avg_orig_price,
    COALESCE(AVG(gd.disputed_price),0) avg_disp_price
    FROM grade_disputes gd
    LEFT JOIN sold_bales sb ON sb.id=gd.sold_balesid
    LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
    WHERE 1=1 ".($spid?"AND gd.selling_pointid=$spid":"")."
    GROUP BY tg.id ORDER BY dispute_count DESC LIMIT 10");

include "includes/layout.php";
?>


<style>
.qc-status-grid { display:grid;grid-template-columns:repeat(5,1fr);gap:.5rem;margin-bottom:1.5rem; }
.qc-status-box { text-align:center;padding:.85rem .5rem;border-radius:var(--radius);border:1px solid var(--border);background:var(--surface); }
body.theme-dark .qc-status-box { background:var(--ink-soft); }
.qc-status-num { font-family:'DM Sans',sans-serif;font-size:1.53rem;line-height:1;font-weight:600}
.qc-status-lbl { font-size:.78rem;color:var(--muted);font-weight:500;margin-top:.2rem; }
.qc-dispute-status { display:inline-flex;padding:2px 8px;border-radius:999px;font-size:.78rem;font-weight:600;}
.qc-s-open { background:var(--warn-tint);color:var(--warn-text); }
.qc-s-review { background:rgba(26,107,154,.12);color:#1a6b9a; }
.qc-s-approved { background:rgba(61,107,53,.12);color:#3d6b35; }
.qc-s-rejected { background:rgba(192,57,43,.1);color:#c0392b; }
.qc-s-resolved { background:var(--cream-d);color:var(--muted); }

/* Premium Segmented / Capsule Tab Strip */
.premium-tabs-nav {
    display: inline-flex;
    padding: 4px;
    background: var(--cream-d);
    border-radius: 8px;
    margin-bottom: 1.5rem;
    gap: 2px;
    border: 1px solid var(--border);
}
body.theme-dark .premium-tabs-nav {
    background: rgba(26,18,8,0.3);
}
.premium-tab-btn {
    padding: 0.6rem 1.2rem;
    background: transparent;
    border: none;
    border-radius: 6px;
    font-family: inherit;
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--muted);
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    user-select: none;
}
.premium-tab-btn:hover {
    color: var(--ink);
    background: rgba(26, 18, 8, 0.04);
}
body.theme-dark .premium-tab-btn:hover {
    color: var(--cream);
    background: rgba(255, 255, 255, 0.04);
}
.premium-tab-btn.active {
    background:var(--primary-bg);
    color:var(--primary-text);
    box-shadow: 0 4px 12px rgba(26, 18, 8, 0.15);
    font-weight: 600;
}
body.theme-light .premium-tab-btn.active {
    background:var(--primary-bg);
    color:var(--primary-text);
}
body.theme-dark .premium-tab-btn.active {
    background: var(--gold);
    color: var(--ink);
    box-shadow: 0 4px 12px rgba(200, 149, 42, 0.2);
}

.qc-tab-panel {
    display: none;
}
.qc-tab-panel.active {
    display: block;
    animation: qcFadeIn 0.25s ease-in-out;
}

@keyframes qcFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

@media(max-width:900px) { .qc-status-grid { grid-template-columns:repeat(3,1fr); } }
@media(max-width:600px) { .qc-status-grid { grid-template-columns:repeat(2,1fr); } }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Quality Control</h2>
    <p>Grade analysis, dispute tracking, rejection monitoring</p>
  </div>
  <a href="grade_disputes.php" class="btn btn-gold btn-sm">Manage Disputes →</a>
</div>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
  <button class="premium-tab-btn active" data-tab="analytics" onclick="switchQcTab('analytics')">
      📊 Analytics Dashboard
  </button>
  <button class="premium-tab-btn" data-tab="pricing" onclick="switchQcTab('pricing')">
      💰 Pricing Analysis
  </button>
  <button class="premium-tab-btn" data-tab="disputes" onclick="switchQcTab('disputes')">
      📋 Disputes Registry <span class="badge badge-gold" style="font-size:.72rem; margin-left:4px"><?=(int)($dispute_stats['open_cnt']??0)?> open</span>
  </button>
</div>

<!-- Tab Panel 1: Analytics Dashboard -->
<div id="panel-analytics" class="qc-tab-panel active">
  <!-- Dispute Status Strip -->
  <div class="qc-status-grid">
    <div class="qc-status-box"><div class="qc-status-num" style="color:var(--text)"><?=(int)($dispute_stats['total']??0)?></div><div class="qc-status-lbl">Total Disputes</div></div>
    <div class="qc-status-box"><div class="qc-status-num" style="color:var(--warn-text)"><?=(int)($dispute_stats['open_cnt']??0)?></div><div class="qc-status-lbl">Open</div></div>
    <div class="qc-status-box"><div class="qc-status-num" style="color:var(--leaf)"><?=(int)($dispute_stats['approved_cnt']??0)?></div><div class="qc-status-lbl">Approved</div></div>
    <div class="qc-status-box"><div class="qc-status-num" style="color:var(--danger)"><?=(int)($dispute_stats['rejected_cnt']??0)?></div><div class="qc-status-lbl">Rejected</div></div>
    <div class="qc-status-box"><div class="qc-status-num" style="color:var(--muted)"><?=(int)($dispute_stats['resolved_cnt']??0)?></div><div class="qc-status-lbl">Resolved</div></div>
  </div>

  <div class="card">
    <div class="card-head"><h3>📊 Grade Distribution</h3><span class="badge badge-gold"><?=count($grade_overview)?> grades</span></div>
    <div class="card-body" style="max-width: 800px; margin: 0 auto;"><canvas id="qc-grade-chart" height="180"></canvas></div>
  </div>
</div>

<!-- Tab Panel 2: Pricing Analysis -->
<div id="panel-pricing" class="qc-tab-panel">
  <div class="card">
    <div class="card-head"><h3>💰 Grade Pricing Analysis</h3></div>
    <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
      <table>
        <thead><tr><th>Grade</th><th class="text-right">Bales</th><th class="text-right">Mass (kg)</th><th class="text-right">Avg $/kg</th><th class="text-right">Min $</th><th class="text-right">Max $</th><th>Range</th></tr></thead>
        <tbody>
        <?php foreach($grade_overview as $go):
          $range = (float)$go['max_price'] - (float)$go['min_price'];
        ?>
        <tr>
          <td style="font-weight:500"><?=htmlspecialchars($go['grade'])?></td>
          <td class="td-mono text-right"><?=number_format((int)$go['cnt'])?></td>
          <td class="td-mono text-right"><?=number_format((float)$go['total_mass'],0)?></td>
          <td class="td-mono text-right" style="font-weight:500;color:var(--gold)">$<?=number_format((float)$go['avg_price'],2)?></td>
          <td class="td-mono text-right">$<?=number_format((float)$go['min_price'],2)?></td>
          <td class="td-mono text-right">$<?=number_format((float)$go['max_price'],2)?></td>
          <td>
            <div style="display:flex;align-items:center;gap:4px">
              <div style="flex:1;height:6px;background:var(--cream-d);border-radius:3px;position:relative;min-width:60px">
                <div style="position:absolute;left:0;top:0;height:100%;width:<?=min(100,max(5,$range*20))?>%;background:<?=$range>2?'var(--danger)':($range>1?'var(--gold)':'var(--leaf)')?>;border-radius:3px"></div>
              </div>
              <span style="font-size:.72rem;color:var(--muted)">$<?=number_format($range,2)?></span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Tab Panel 3: Disputes Registry -->
<div id="panel-disputes" class="qc-tab-panel">
  <div class="grid-2 mb-3" style="align-items: start;">
    <!-- Most Disputed Grades -->
    <div class="card">
      <div class="card-head"><h3>⚠️ Most Disputed Grades</h3></div>
      <div class="card-body">
        <?php if(!empty($disputed_grades)): ?>
        <div class="table-wrap"><table>
          <thead><tr><th>Grade</th><th class="text-right">Disputes</th><th class="text-right">Avg Orig $</th><th class="text-right">Avg Disp $</th><th>Impact</th></tr></thead>
          <tbody>
          <?php foreach($disputed_grades as $dg):
            $diff = (float)$dg['avg_disp_price'] - (float)$dg['avg_orig_price'];
          ?>
          <tr>
            <td style="font-weight:500"><?=htmlspecialchars($dg['grade']??'—')?></td>
            <td class="td-mono text-right"><?=(int)$dg['dispute_count']?></td>
            <td class="td-mono text-right">$<?=number_format((float)$dg['avg_orig_price'],2)?></td>
            <td class="td-mono text-right">$<?=number_format((float)$dg['avg_disp_price'],2)?></td>
            <td style="color:<?=$diff>0?'var(--leaf)':'var(--danger)'?>;font-weight:500;font-size:.75rem"><?=$diff>0?'+':''?><?=number_format($diff,2)?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php else: ?>
        <div style="padding:2rem;text-align:center;color:var(--muted)">No disputes recorded yet</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent Disputes -->
    <div class="card">
      <div class="card-head"><h3>📋 Recent Disputes</h3></div>
      <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
        <table>
          <thead><tr><th>Barcode</th><th>Grower</th><th>Original</th><th>Disputed</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach($disputes as $d):
            $sclass = ['open'=>'qc-s-open','under_review'=>'qc-s-review','approved'=>'qc-s-approved','rejected'=>'qc-s-rejected','resolved'=>'qc-s-resolved'][$d['status']??'open'] ?? 'qc-s-open';
          ?>
          <tr>
            <td class="td-mono" style="font-size:.72rem"><?=htmlspecialchars($d['barcode']??'—')?></td>
            <td><span style="font-weight:500;font-size:.78rem"><?=htmlspecialchars($d['grower_name'])?></span><br><span style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($d['grower_num'])?></span></td>
            <td class="td-mono"><?=htmlspecialchars($d['original_grade'])?> ($<?=number_format((float)$d['original_price'],2)?>)</td>
            <td class="td-mono"><?=htmlspecialchars($d['disputed_grade'])?> ($<?=number_format((float)$d['disputed_price'],2)?>)</td>
            <td><span class="qc-dispute-status <?=$sclass?>"><?=htmlspecialchars($d['status'])?></span></td>
            <td style="font-size:.72rem;color:var(--muted)"><?=date('d M H:i',strtotime($d['raised_at']))?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($disputes)): ?>
          <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">No disputes recorded</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
function switchQcTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.qc-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_qc_tab', tabId);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const defaultTab = sessionStorage.getItem('active_qc_tab') || 'analytics';
    switchQcTab(defaultTab);
});

const gd = <?=json_encode($grade_overview)?>;
if (gd.length && document.getElementById('qc-grade-chart')) {
    const colors = ['#c8952a','#3d6b35','#e8b84b','#1a6b9a','#c0392b','#8e44ad','#2c3e50','#e67e22','#16a085','#d35400','#7f8c8d','#2980b9'];
    new Chart(document.getElementById('qc-grade-chart'), {
        type:'bar',
        data:{labels:gd.map(g=>g.grade),datasets:[{label:'Bales',data:gd.map(g=>parseInt(g.cnt)),backgroundColor:colors.slice(0,gd.length).map(c=>c+'aa'),borderRadius:4}]},
        options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.04)'}},x:{grid:{display:false}}}}
    });
}
</script>

<?php include "includes/layout_end.php"; ?>
