<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();
$page_title  = "Document Vault";
$active_page = "document_vault";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);

function dvAll($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_all(MYSQLI_ASSOC)?:[];}
function dvSafe($c,$s){if(!$c)return[];$r=@$c->query($s);if(!$r||$r===true)return[];return $r->fetch_assoc()?:[];}

$search = trim($_GET['q'] ?? '');
$search_sql = $search ? "AND (g.grower_num LIKE '%".addslashes($search)."%' OR g.name LIKE '%".addslashes($search)."%' OR g.surname LIKE '%".addslashes($search)."%')" : "";
$sf = $spid ? "AND g.selling_pointid=$spid" : "";

// Growers with document status
$growers_docs = dvAll($conn,"SELECT g.id, g.grower_num, CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
    g.national_id, g.phone, g.farm_name, g.province,
    CASE WHEN g.national_id IS NOT NULL AND g.national_id != '' THEN 1 ELSE 0 END has_id,
    CASE WHEN g.phone IS NOT NULL AND g.phone != '' THEN 1 ELSE 0 END has_phone,
    CASE WHEN g.contract_number IS NOT NULL AND g.contract_number != '' THEN 1 ELSE 0 END has_contract
    FROM growers g
    WHERE g.active=1 $sf $search_sql
    ORDER BY g.grower_num LIMIT 50");

// Doc completeness stats
$doc_stats = dvSafe($conn,"SELECT COUNT(*) total,
    SUM(CASE WHEN national_id IS NOT NULL AND national_id != '' THEN 1 ELSE 0 END) has_id,
    SUM(CASE WHEN phone IS NOT NULL AND phone != '' AND LENGTH(phone)>=9 THEN 1 ELSE 0 END) has_phone,
    SUM(CASE WHEN contract_number IS NOT NULL AND contract_number != '' THEN 1 ELSE 0 END) has_contract,
    SUM(CASE WHEN farm_name IS NOT NULL AND farm_name != '' THEN 1 ELSE 0 END) has_farm
    FROM growers WHERE active=1 $sf");

$total = max(1, (int)($doc_stats['total']??1));
$id_pct = round(((int)($doc_stats['has_id']??0) / $total) * 100);
$phone_pct = round(((int)($doc_stats['has_phone']??0) / $total) * 100);
$contract_pct = round(((int)($doc_stats['has_contract']??0) / $total) * 100);
$farm_pct = round(((int)($doc_stats['has_farm']??0) / $total) * 100);

// TIMB verification status
$timb_stats = dvSafe($conn,"SELECT COUNT(*) total,
    SUM(CASE WHEN timb_verified=1 THEN 1 ELSE 0 END) verified
    FROM timb_grower_registry WHERE 1=1 ".($spid?"AND selling_pointid=$spid":""));
$timb_pct = (int)($timb_stats['total']??0) > 0 ? round(((int)($timb_stats['verified']??0)/(int)$timb_stats['total'])*100) : 0;

include "includes/layout.php";
?>

<style>
.dv-completeness { display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.65rem;margin-bottom:1.5rem; }
.dv-meter { text-align:center;padding:.85rem;background:#fff;border:1px solid var(--border);border-radius:var(--radius); }
body.theme-dark .dv-meter { background:var(--ink-soft); }
.dv-ring-wrap { width:70px;height:70px;margin:0 auto .5rem; }
.dv-meter-lbl { font-size:.58rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;font-weight:500; }
.dv-meter-val { font-family:'Bebas Neue',sans-serif;font-size:1.3rem;color:var(--gold); }
.dv-check { display:inline-flex;width:18px;height:18px;border-radius:50%;align-items:center;justify-content:center;font-size:.6rem; }
.dv-check-yes { background:rgba(61,107,53,.12);color:var(--leaf); }
.dv-check-no { background:rgba(192,57,43,.08);color:var(--danger); }
.dv-sig-pad { border:2px dashed var(--border);border-radius:var(--radius);padding:2rem;text-align:center;cursor:pointer;transition:all .2s; }
.dv-sig-pad:hover { border-color:var(--gold);background:rgba(200,149,42,.03); }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Document Vault</h2>
    <p>Grower documentation, compliance tracking, e-signatures</p>
  </div>
  <form method="GET" style="display:flex;gap:.5rem">
    <input type="text" name="q" class="form-control" placeholder="Search grower..." value="<?=htmlspecialchars($search)?>" style="width:200px">
    <button class="btn btn-gold btn-sm">Search</button>
  </form>
</div>

<!-- Completeness Meters -->
<div class="dv-completeness">
  <div class="dv-meter">
    <div class="dv-meter-val"><?=$id_pct?>%</div>
    <div style="height:6px;background:var(--cream-d);border-radius:3px;margin:.4rem 0"><div style="height:100%;width:<?=$id_pct?>%;background:<?=$id_pct>=80?'var(--leaf)':'var(--gold)'?>;border-radius:3px"></div></div>
    <div class="dv-meter-lbl">National ID</div>
  </div>
  <div class="dv-meter">
    <div class="dv-meter-val"><?=$phone_pct?>%</div>
    <div style="height:6px;background:var(--cream-d);border-radius:3px;margin:.4rem 0"><div style="height:100%;width:<?=$phone_pct?>%;background:<?=$phone_pct>=80?'var(--leaf)':'var(--gold)'?>;border-radius:3px"></div></div>
    <div class="dv-meter-lbl">Phone Number</div>
  </div>
  <div class="dv-meter">
    <div class="dv-meter-val"><?=$contract_pct?>%</div>
    <div style="height:6px;background:var(--cream-d);border-radius:3px;margin:.4rem 0"><div style="height:100%;width:<?=$contract_pct?>%;background:<?=$contract_pct>=80?'var(--leaf)':'var(--gold)'?>;border-radius:3px"></div></div>
    <div class="dv-meter-lbl">Contract #</div>
  </div>
  <div class="dv-meter">
    <div class="dv-meter-val"><?=$farm_pct?>%</div>
    <div style="height:6px;background:var(--cream-d);border-radius:3px;margin:.4rem 0"><div style="height:100%;width:<?=$farm_pct?>%;background:<?=$farm_pct>=80?'var(--leaf)':'var(--gold)'?>;border-radius:3px"></div></div>
    <div class="dv-meter-lbl">Farm Name</div>
  </div>
  <div class="dv-meter">
    <div class="dv-meter-val"><?=$timb_pct?>%</div>
    <div style="height:6px;background:var(--cream-d);border-radius:3px;margin:.4rem 0"><div style="height:100%;width:<?=$timb_pct?>%;background:<?=$timb_pct>=80?'var(--leaf)':'var(--gold)'?>;border-radius:3px"></div></div>
    <div class="dv-meter-lbl">TIMB Verified</div>
  </div>
</div>

<div class="premium-tabs-nav" data-tabs="docvault">
  <button type="button" class="premium-tab-btn active" data-tab="docs">📁 Grower Documents</button>
  <button type="button" class="premium-tab-btn" data-tab="sign">✍️ E-Signature</button>
  <button type="button" class="premium-tab-btn" data-tab="expiry">📅 Expiry Alerts</button>
</div>

<!-- Grower Documents -->
<div id="panel-docs" class="tab-panel active" data-tabs="docvault">
  <div class="card">
    <div class="card-head"><h3>📁 Grower Documents</h3><span class="badge badge-gold"><?=count($growers_docs)?></span></div>
    <div class="table-wrap" style="max-height:500px;overflow-y:auto">
      <table>
        <thead><tr><th>Grower</th><th>ID</th><th>Phone</th><th>Contract</th><th>Farm</th></tr></thead>
        <tbody>
        <?php foreach($growers_docs as $gd): ?>
        <tr>
          <td><div style="font-weight:500;font-size:.78rem"><?=htmlspecialchars($gd['grower_name'])?></div><div style="font-size:.6rem;color:var(--muted)"><?=htmlspecialchars($gd['grower_num'])?></div></td>
          <td><span class="dv-check <?=(int)$gd['has_id']?'dv-check-yes':'dv-check-no'?>"><?=(int)$gd['has_id']?'✓':'✗'?></span></td>
          <td><span class="dv-check <?=(int)$gd['has_phone']?'dv-check-yes':'dv-check-no'?>"><?=(int)$gd['has_phone']?'✓':'✗'?></span></td>
          <td><span class="dv-check <?=(int)$gd['has_contract']?'dv-check-yes':'dv-check-no'?>"><?=(int)$gd['has_contract']?'✓':'✗'?></span></td>
          <td style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($gd['farm_name']??'—')?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /panel-docs -->

  <!-- E-Signature Pad -->
  <div id="panel-sign" class="tab-panel" data-tabs="docvault">
    <div class="card">
      <div class="card-head"><h3>✍️ E-Signature Capture</h3></div>
      <div class="card-body">
        <canvas id="dv-sig-canvas" width="400" height="200" style="border:2px dashed var(--border);border-radius:var(--radius);width:100%;cursor:crosshair;touch-action:none"></canvas>
        <div style="display:flex;gap:.5rem;margin-top:.65rem">
          <button class="btn btn-ghost btn-sm" onclick="clearSig()">Clear</button>
          <button class="btn btn-gold btn-sm" onclick="saveSig()">Save Signature</button>
        </div>
        <div style="font-size:.68rem;color:var(--muted);margin-top:.5rem">Draw signature above. Use with settlement sheets for grower acknowledgment.</div>
      </div>
    </div>
    </div><!-- /panel-sign -->

    <div id="panel-expiry" class="tab-panel" data-tabs="docvault">
    <div class="card">
      <div class="card-head"><h3>📅 Document Expiry Alerts</h3></div>
      <div class="card-body" style="padding:1.5rem;text-align:center;color:var(--muted)">
        <div style="font-size:1.5rem;opacity:.3;margin-bottom:.5rem">🔔</div>
        <div style="font-size:.82rem">Document expiry tracking will appear here when documents with expiry dates are uploaded.</div>
        <a href="file_manager_uploads.php" class="btn btn-ghost btn-sm" style="margin-top:.75rem">Go to File Manager →</a>
      </div>
    </div>
    </div><!-- /panel-expiry -->

<script>
// Signature pad
const canvas = document.getElementById('dv-sig-canvas');
const ctx = canvas.getContext('2d');
let drawing = false;
ctx.strokeStyle = '#1a1208';
ctx.lineWidth = 2;
ctx.lineCap = 'round';

canvas.addEventListener('pointerdown', e => { drawing = true; ctx.beginPath(); ctx.moveTo(e.offsetX, e.offsetY); });
canvas.addEventListener('pointermove', e => { if (drawing) { ctx.lineTo(e.offsetX, e.offsetY); ctx.stroke(); } });
canvas.addEventListener('pointerup', () => drawing = false);
canvas.addEventListener('pointerleave', () => drawing = false);

function clearSig() { ctx.clearRect(0, 0, canvas.width, canvas.height); }
function saveSig() {
    const data = canvas.toDataURL('image/png');
    if (window.fw) fw.toast('Signature captured successfully', 'success');
    else alert('Signature captured!');
}
</script>

<?php include "includes/layout_end.php"; ?>
