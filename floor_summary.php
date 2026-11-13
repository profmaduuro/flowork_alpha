<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "Floor Summary";
$active_page = "floor_summary";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();
$msg = $err  = '';
$today       = date('Y-m-d');
$date_f      = $conn->real_escape_string($_GET['date'] ?? $today);

// Ensure floor_summary table exists with date-safe indexes
$conn->query("CREATE TABLE IF NOT EXISTS floor_summary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    ticketsid INT NOT NULL,
    row INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ticketsid (ticketsid),
    KEY idx_date (created_at)
)");
// Drop ANY unique index on ticketsid — regardless of what it was named.
// The old uk_ticket unique key silently blocks re-scanning bales on new days.
$idx_q = $conn->query("SHOW INDEX FROM floor_summary WHERE Column_name='ticketsid' AND Non_unique=0");
if ($idx_q) {
    while ($idx_row = $idx_q->fetch_assoc()) {
        $kname = $conn->real_escape_string($idx_row['Key_name']);
        $conn->query("ALTER TABLE floor_summary DROP INDEX `$kname`");
    }
}

// AJAX barcode lookup + add
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';

    if ($action === 'lookup') {
        $bc = $conn->real_escape_string(trim($_GET['barcode'] ?? ''));
        $r  = $conn->query("
            SELECT tk.id ticketid, tk.barcode, bm.temp_bacode timb_bc, bm.mass,
                   g.grower_num, g.name, g.surname,
                   COALESCE(bl.code, t.location, '—') loc_code,
                   COALESCE(bl.name, t.location, '—') loc_name,
                   fs.id fs_id, fs.`row` fs_row,
                   sb.id sold_id
            FROM tickets tk
            JOIN bale_junus bj ON bj.id=tk.bale_junusid
            JOIN bale_mass bm ON bm.id=bj.bale_massid
            JOIN growers g ON g.id=bj.growerid
            LEFT JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
            LEFT JOIN transporter t ON t.id=tg.transporterid
            LEFT JOIN bale_locations bl ON bl.id=t.location_id OR bl.code=t.location
            LEFT JOIN floor_summary fs ON fs.ticketsid=tk.id AND DATE(fs.created_at)=CURDATE()
            LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
            WHERE tk.barcode='$bc' AND ($spid=0 OR g.selling_pointid=$spid) LIMIT 1
        ");
        echo json_encode($r&&$r!==true?$r->fetch_assoc():['error'=>'Barcode not found']);
        exit;
    }

    if ($action === 'add') {
        $bc  = $conn->real_escape_string(trim($_GET['barcode'] ?? ''));
        $row = $conn->real_escape_string(trim($_GET['row'] ?? ''));
        // Find ticket
        $tk_q = $conn->query("SELECT tk.id FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN growers g ON g.id=bj.growerid WHERE tk.barcode='$bc' AND ($spid=0 OR g.selling_pointid=$spid) LIMIT 1");
        $tk   = ($tk_q&&$tk_q!==true)?$tk_q->fetch_assoc():null;
        if (!$tk) { echo json_encode(['error'=>'Barcode not found']); exit; }
        $tkid = (int)$tk['id'];
        // Check already scanned TODAY only — allow re-scanning on new days
        $existing = $conn->query("SELECT id FROM floor_summary WHERE ticketsid=$tkid AND DATE(created_at)=CURDATE() LIMIT 1");
        if ($existing && $existing->num_rows > 0) { echo json_encode(['error'=>'Already scanned today']); exit; }
        $conn->query("INSERT INTO floor_summary (userid,ticketsid,`row`,created_at) VALUES ($userid,$tkid," . (int)$row . ",NOW())");
        $new_id = (int)$conn->insert_id;          // capture BEFORE audit_log resets it
        if (!$new_id) {
            echo json_encode(['error'=>'Save failed: ' . ($conn->error ?: 'unknown DB error')]);
            exit;
        }
        audit_log($conn,$spid,$userid,'floor_summary','add',$new_id,null,['barcode'=>$bc,'row'=>$row]);
        echo json_encode(['ok'=>true,'id'=>$new_id,'message'=>'Added to floor summary']);
        exit;
    }

    if ($action === 'remove') {
        $fsid = (int)($_GET['id'] ?? 0);
        $conn->query("DELETE FROM floor_summary WHERE id=$fsid AND userid=$userid");
        echo json_encode(['ok'=>true]);
        exit;
    }
    exit;
}

// CSV export
if (isset($_GET['export'])) {
    $exp_date = $conn->real_escape_string($_GET['export']);
    $rows_q   = $conn->query("
        SELECT fs.`row`, tk.barcode, bm.temp_bacode timb_bc, bm.mass,
               g.grower_num, g.name, g.surname,
               COALESCE(bl.code, t.location, '—') loc_code,
               COALESCE(bl.name, t.location, '—') loc_name,
               CASE WHEN sb.id IS NOT NULL THEN 'SOLD' ELSE 'UNSOLD' END status,
               DATE(fs.created_at) scan_date
        FROM floor_summary fs
        JOIN tickets tk ON tk.id=fs.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        LEFT JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
        LEFT JOIN transporter t ON t.id=tg.transporterid
        LEFT JOIN bale_locations bl ON bl.id=t.location_id OR bl.code=t.location
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        WHERE DATE(fs.created_at)='$exp_date' AND ($spid=0 OR g.selling_pointid=$spid)
        ORDER BY fs.`row`, COALESCE(bl.code, t.location), tk.barcode
    ");
    header('Content-Type: text/csv');
    header("Content-Disposition: attachment; filename=\"floor_summary_{$exp_date}.csv\"");
    echo "Row,Ticket Barcode,TIMB Barcode,Mass kg,Grower #,Grower Name,Location Code,Location Name,Status\r\n";
    while ($rows_q && $r=$rows_q->fetch_assoc()) {
        echo implode(',', [
            '"'.($r['row']??'').'"','"'.$r['barcode'].'"','"'.$r['timb_bc'].'"',
            $r['mass'],'"'.$r['grower_num'].'"','"'.trim($r['name'].' '.$r['surname']).'"',
            '"'.$r['loc_code'].'"','"'.$r['loc_name'].'"','"'.$r['status'].'"',
        ])."\r\n";
    }
    exit;
}

// Load floor summary for selected date
$summary_q = $conn->query("
    SELECT fs.id fs_id, fs.`row`, tk.barcode, bm.temp_bacode timb_bc, bm.mass,
           g.grower_num, g.name, g.surname,
           COALESCE(bl.code, t.location, '—') loc_code,
           COALESCE(bl.name, t.location, '—') loc_name,
           CASE WHEN sb.id IS NOT NULL THEN 'SOLD' ELSE 'UNSOLD' END status,
           fs.created_at
    FROM floor_summary fs
    JOIN tickets tk ON tk.id=fs.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    LEFT JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
    LEFT JOIN transporter t ON t.id=tg.transporterid
    LEFT JOIN bale_locations bl ON bl.id=t.location_id OR bl.code=t.location
    LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
    WHERE DATE(fs.created_at)='$date_f' AND ($spid=0 OR g.selling_pointid=$spid)
    ORDER BY fs.`row`, COALESCE(bl.code, t.location), tk.barcode
");
$summary = ($summary_q&&$summary_q!==true)?$summary_q->fetch_all(MYSQLI_ASSOC):[];

$total_mass = array_sum(array_column($summary,'mass'));
$sold_count = count(array_filter($summary,fn($r)=>$r['status']==='SOLD'));

include "includes/layout.php";
?>
<style>
.scan-box{background:var(--surface-2);border:2px solid var(--border);border-radius:var(--radius);padding:1rem;margin-bottom:1rem}
.scan-box.success{border-color:var(--leaf);animation:scan-flash .4s ease}
.scan-box.error{border-color:var(--danger);animation:scan-flash .4s ease}
@keyframes scan-flash{0%,100%{opacity:1}50%{opacity:.6}}
.row-badge{display:inline-block;background:var(--ink);color:var(--gold);font-family:'Bebas Neue',sans-serif;font-size:1.1rem;padding:2px 10px;border-radius:4px;min-width:32px;text-align:center}
@media print {
  .no-print,.scan-box,#bale-preview,.page-header .btn,.kpi-grid,nav,aside,.sidebar { display:none!important }
  body,html { background:#fff!important }
  .card { box-shadow:none!important;border:1px solid #ccc!important }
  .row-badge { background:#222!important;color:#f0c040!important;-webkit-print-color-adjust:exact }
  #print-header { display:block!important }
}
#print-header { display:none;margin-bottom:1rem }
</style>

<!-- Print-only header -->
<div id="print-header">
  <h2 style="margin:0;font-size:1.3rem">Floor Summary Report — <?=date('d F Y',strtotime($date_f))?></h2>
  <p style="margin:0.2rem 0 0;font-size:.8rem;color:#555">
    Total: <?=count($summary)?> bales &nbsp;·&nbsp;
    Sold: <?=$sold_count?> &nbsp;·&nbsp;
    Unsold: <?=count($summary)-$sold_count?> &nbsp;·&nbsp;
    Mass: <?=number_format($total_mass,2)?> kg
  </p>
  <hr style="margin:.5rem 0">
</div>

<div class="page-header no-print">
  <div class="page-header-left">
    <h2>Floor Summary</h2>
    <p>Scan ticket barcodes to capture bale positions on the selling floor</p>
  </div>
  <div style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
    <div>
      <label style="font-size:.72rem;display:block">Date</label>
      <input type="date" id="date-filter" value="<?=htmlspecialchars((string)$date_f)?>" onchange="location='floor_summary.php?date='+this.value" class="form-control" style="font-size:.8rem;padding:.32rem .55rem">
    </div>
    <a href="?date=<?=htmlspecialchars((string)$date_f)?>&export=<?=htmlspecialchars((string)$date_f)?>" class="btn btn-ghost btn-sm">⬇ CSV</a>
    <button onclick="window.print()" class="btn btn-ghost btn-sm">🖨 Print Report</button>
  </div>
</div>

<!-- Scan input -->
<div class="scan-box" id="scan-box">
  <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
    <div style="flex:2;min-width:200px">
      <label style="font-size:.68rem;color:var(--muted);display:block;margin-bottom:3px">Ticket Barcode (scan or type)</label>
      <input type="text" id="scan-input" class="form-control" placeholder="Scan ticket barcode..." autofocus
             style="font-size:1.05rem;padding:.55rem .85rem;font-family:monospace">
    </div>
    <div style="flex:1;min-width:100px">
      <label style="font-size:.68rem;color:var(--muted);display:block;margin-bottom:3px">Row / Position</label>
      <input type="number" min="1" id="row-input" class="form-control" placeholder="e.g. 3" value=""
             style="font-size:1rem;padding:.55rem .85rem">
    </div>
    <button onclick="captureBarcode()" class="btn btn-primary" style="padding:.55rem 1.25rem">
      ➕ Add
    </button>
  </div>
  <div id="scan-result" style="margin-top:.5rem;font-size:.78rem;display:none"></div>
</div>

<!-- Preview of last scan -->
<div id="bale-preview" style="display:none;background:rgba(200,149,42,.06);border:1px solid var(--gold);border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.8rem">
  Loading...
</div>

<!-- Stats -->
<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1rem">
  <div class="kpi-card"><div class="kpi-label">Scanned</div><div class="kpi-value" id="kpi-scanned"><?=count($summary)?></div></div>
  <div class="kpi-card"><div class="kpi-label">Sold</div><div class="kpi-value text-leaf" id="kpi-sold"><?=$sold_count?></div></div>
  <div class="kpi-card"><div class="kpi-label">Unsold</div><div class="kpi-value text-gold" id="kpi-unsold"><?=count($summary)-$sold_count?></div></div>
  <div class="kpi-card"><div class="kpi-label">Total Mass</div><div class="kpi-value"><?=number_format($total_mass,1)?> kg</div></div>
</div>

<!-- Summary table -->
<div class="card">
<div class="card-head">
  <h3>Floor Summary — <?=date('d F Y',strtotime($date_f))?></h3>
  <div style="display:flex;gap:.35rem">
    <input type="text" data-search="#fs-table" placeholder="Filter..." class="form-control" style="font-size:.72rem;padding:.28rem .55rem;width:150px">
    <span class="badge badge-gray"><?=count($summary)?> bales</span>
  </div>
</div>
<div class="table-wrap" id="fs-table-wrap">
<table id="fs-table">
<thead><tr>
  <th>Row</th>
  <th>Ticket Barcode</th>
  <th>TIMB Barcode</th>
  <th>Grower #</th>
  <th>Grower Name</th>
  <th class="text-right">Mass kg</th>
  <th>Location</th>
  <th>Status</th>
  <th>Scanned</th>
  <th class="no-print"></th>
</tr></thead>
<tbody id="summary-tbody">
<?php if (empty($summary)): ?>
<tr><td colspan="10" class="text-center text-muted" style="padding:2.5rem">
  No bales scanned for <?=date('d F Y',strtotime($date_f))?>. Start scanning ticket barcodes above.
</td></tr>
<?php else: foreach($summary as $r): ?>
<tr id="row-<?=$r['fs_id']?>" data-mass="<?=htmlspecialchars($r['mass'])?>" data-sold="<?=($r['status']==='SOLD'?'1':'0')?>">
  <td><span class="row-badge"><?=htmlspecialchars($r['row']??'—')?></span></td>
  <td style="font-family:monospace;font-size:.75rem"><?=htmlspecialchars($r['barcode'])?></td>
  <td style="font-family:monospace;font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($r['timb_bc'])?></td>
  <td><span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($r['grower_num'])?></span></td>
  <td style="font-size:.78rem"><?=htmlspecialchars(trim($r['name'].' '.$r['surname']))?></td>
  <td class="td-mono text-right"><?=number_format((float)$r['mass'],2)?></td>
  <td style="font-size:.72rem"><?=htmlspecialchars($r['loc_code']??'—')?></td>
  <td>
    <span class="badge <?=$r['status']==='SOLD'?'badge-green':'badge-gold'?>" style="font-size:.72rem">
      <?=$r['status']?>
    </span>
  </td>
  <td style="font-size:.68rem;color:var(--muted)"><?=date('H:i',strtotime($r['created_at']))?></td>
  <td>
    <button onclick="removeRow(<?=$r['fs_id']?>)" class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--danger)" title="Remove">✕</button>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<?php if(!empty($summary)): ?>
<div style="padding:.65rem 1.15rem;background:var(--cream-d);border-top:1px solid var(--border);display:flex;gap:1.5rem;font-size:.8rem">
  <span>Total: <strong><?=count($summary)?> bales</strong></span>
  <span>Mass: <strong><?=number_format($total_mass,2)?> kg</strong></span>
  <span>Sold: <strong><?=$sold_count?></strong></span>
  <span>Unsold: <strong><?=count($summary)-$sold_count?></strong></span>
</div>
<?php endif;?>
</div>

<script>
var currentRow = '';

document.getElementById('row-input').addEventListener('input', function() {
    currentRow = this.value.toUpperCase();
});

document.getElementById('scan-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); captureBarcode(); }
});

async function captureBarcode() {
    var bc = document.getElementById('scan-input').value.trim();
    var row = document.getElementById('row-input').value.trim().toUpperCase();
    if (!bc) { showScanFeedback('Enter a barcode first','error'); return; }

    var box = document.getElementById('scan-box');
    var res = document.getElementById('scan-result');

    // Lookup first
    var lookup = await fw.get('floor_summary.php', {ajax:1, action:'lookup', barcode:bc});
    if (lookup.error) { showScanFeedback(lookup.error,'error'); return; }

    // Show preview
    var prev = document.getElementById('bale-preview');
    prev.style.display = 'block';
    prev.innerHTML = '🌿 <strong>'+lookup.grower_num+'</strong> — '+lookup.name+' '+lookup.surname+
        ' &nbsp;|&nbsp; <strong>'+lookup.mass+' kg</strong> &nbsp;|&nbsp; '+
        (lookup.loc_code||'No location')+' &nbsp;|&nbsp; TIMB: '+lookup.timb_bc+
        (lookup.fs_id ? ' &nbsp; <span class="badge badge-red" style="font-size:.72rem">Already scanned</span>' : '');

    if (lookup.fs_id) { showScanFeedback('Already scanned in row ' + lookup.fs_row, 'error'); return; }

    // Add to floor summary
    var add = await fw.get('floor_summary.php', {ajax:1, action:'add', barcode:bc, row:row});
    if (add.error) { showScanFeedback(add.error,'error'); return; }

    showScanFeedback('✓ Added: '+lookup.grower_num+' — '+lookup.mass+' kg'+(row?' [Row '+row+']':''), 'success');
    document.getElementById('scan-input').value = '';
    document.getElementById('scan-input').focus();

    // Append row to table
    addTableRow(lookup, row, add);
}

function addTableRow(b, row, add) {
    var tbody = document.getElementById('summary-tbody');
    // Remove empty message row if present
    var empty = tbody.querySelector('td[colspan]');
    if (empty) empty.parentElement.remove();

    var tr = document.createElement('tr');
    tr.id = 'row-' + add.id;
    tr.dataset.mass = b.mass;
    tr.dataset.sold = b.sold_id ? '1' : '0';
    tr.innerHTML =
        '<td><span class="row-badge">'+(row||'—')+'</span></td>'+
        '<td style="font-family:monospace;font-size:.75rem">'+b.barcode+'</td>'+
        '<td style="font-family:monospace;font-size:.72rem;color:var(--muted)">'+b.timb_bc+'</td>'+
        '<td><span class="badge badge-gold" style="font-size:.72rem">'+b.grower_num+'</span></td>'+
        '<td style="font-size:.78rem">'+b.name+' '+b.surname+'</td>'+
        '<td class="td-mono text-right">'+parseFloat(b.mass).toFixed(2)+'</td>'+
        '<td style="font-size:.72rem">'+(b.loc_code||'—')+'</td>'+
        '<td><span class="badge '+(b.sold_id?'badge-green':'badge-gold')+'" style="font-size:.72rem">'+(b.sold_id?'SOLD':'UNSOLD')+'</span></td>'+
        '<td style="font-size:.68rem;color:var(--muted)">now</td>'+
        '<td><button onclick="removeRow('+add.id+')" class="btn btn-ghost btn-sm" style="font-size:.65rem;color:var(--danger)">✕</button></td>';
    tbody.prepend(tr);
    recalcKPIs();
}

async function removeRow(id) {
    if (!confirm('Remove this bale from floor summary?')) return;
    await fw.get('floor_summary.php', {ajax:1, action:'remove', id:id});
    var row = document.getElementById('row-'+id);
    if (row) row.remove();
    fw.toast('Removed from floor summary','info');
    recalcKPIs();
}

function recalcKPIs() {
    var rows = document.querySelectorAll('#summary-tbody tr[id^="row-"]');
    var scanned = rows.length, sold = 0, mass = 0;
    rows.forEach(function(r){ if(r.dataset.sold==='1') sold++; mass+=parseFloat(r.dataset.mass)||0; });
    var sc=document.getElementById('kpi-scanned'), sv=document.getElementById('kpi-sold'), su=document.getElementById('kpi-unsold');
    if(sc) sc.textContent=scanned; if(sv) sv.textContent=sold; if(su) su.textContent=scanned-sold;
}

function showScanFeedback(msg, type) {
    var box = document.getElementById('scan-box');
    var res = document.getElementById('scan-result');
    res.style.display = 'block';
    res.style.color = type==='error'?'var(--danger)':'var(--leaf)';
    res.textContent = msg;
    box.classList.remove('success','error');
    box.classList.add(type);
    setTimeout(()=>{ box.classList.remove('success','error'); },1500);
}

// Table search
document.querySelector('[data-search]')?.addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#fs-table tbody tr').forEach(function(tr) {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php include "includes/layout_end.php"; ?>
