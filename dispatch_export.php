<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Dispatch Export";
$active_page = "dispatch_export";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$today       = date('Y-m-d');

// ── Ensure dispatch tables exist ──────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS dispatch_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL,
    selling_pointid INT NOT NULL,
    dispatch_date DATE NOT NULL,
    destination VARCHAR(120) DEFAULT '',
    vehicle_num VARCHAR(40) DEFAULT '',
    driver VARCHAR(80) DEFAULT '',
    notes TEXT DEFAULT '',
    status ENUM('open','closed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(dispatch_date), INDEX(selling_pointid)
)");
$conn->query("CREATE TABLE IF NOT EXISTS dispatch_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatch_sessionid INT NOT NULL,
    sold_balesid INT NOT NULL,
    bale_junusid INT NOT NULL,
    barcode VARCHAR(40) DEFAULT '',
    mass DECIMAL(10,2) DEFAULT 0,
    grower_num VARCHAR(30) DEFAULT '',
    timb_grade VARCHAR(40) DEFAULT '',
    price DECIMAL(10,4) DEFAULT 0,
    scanned_by INT NOT NULL,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY di_unique (dispatch_sessionid, sold_balesid),
    INDEX(dispatch_sessionid), INDEX(sold_balesid)
)");

function safe($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r || $r === true) {
        error_log("dispatch_export query error: " . $conn->error . " SQL: " . substr($sql,0,200));
        return [];
    }
    return $r->fetch_all(MYSQLI_ASSOC);
}
function safeRow($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r || $r === true) {
        error_log("dispatch_export query error: " . $conn->error . " SQL: " . substr($sql,0,200));
        return [];
    }
    return $r->fetch_assoc() ?? [];
}

// ── Filters ────────────────────────────────────────────────────────────────
$date_f  = $conn->real_escape_string($_GET['date'] ?? $today);
$sid     = (int)($_GET['sid'] ?? 0);

// ── CSV Export ─────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $sid) {
    $session = safeRow($conn, "SELECT * FROM dispatch_sessions WHERE id=$sid AND selling_pointid=$spid LIMIT 1");
    $items   = safe($conn, "
        SELECT di.barcode, di.grower_num, di.timb_grade, di.mass, di.price,
               (di.price * di.mass) AS value,
               g.name, g.surname, g.province
        FROM dispatch_items di
        LEFT JOIN bale_junus bj ON bj.id = di.bale_junusid
        LEFT JOIN growers g ON g.id = bj.growerid
        WHERE di.dispatch_sessionid = $sid
        ORDER BY di.grower_num, di.barcode
    ");
    $fn = 'dispatch_' . ($session['destination'] ?? 'export') . '_' . $date_f . '.csv';
    $fn = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $fn);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    echo "Dispatch Session #$sid\r\n";
    echo "Date:," . $date_f . "\r\n";
    echo "Destination:," . ($session['destination'] ?? '') . "\r\n";
    echo "Vehicle:," . ($session['vehicle_num'] ?? '') . "\r\n";
    echo "Driver:," . ($session['driver'] ?? '') . "\r\n\r\n";
    echo "Barcode,Grower #,Name,Province,Grade,Mass (kg),Price $/kg,Value USD\r\n";
    $total_mass = $total_val = 0;
    foreach ($items as $r) {
        echo implode(',', [
            '"' . $r['barcode']   . '"',
            '"' . $r['grower_num'] . '"',
            '"' . trim($r['name'] . ' ' . $r['surname']) . '"',
            '"' . ($r['province'] ?? '') . '"',
            '"' . $r['timb_grade'] . '"',
            number_format((float)$r['mass'], 2),
            number_format((float)$r['price'], 4),
            number_format((float)$r['value'], 2),
        ]) . "\r\n";
        $total_mass += (float)$r['mass'];
        $total_val  += (float)$r['value'];
    }
    echo "\r\nTOTAL,,,,," . number_format($total_mass,2) . ",," . number_format($total_val,2) . "\r\n";
    exit;
}

// ── PDF / Print Export ─────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'print' && $sid) {
    $session = safeRow($conn, "SELECT ds.*, u.username FROM dispatch_sessions ds JOIN users u ON u.id=ds.userid WHERE ds.id=$sid AND ds.selling_pointid=$spid LIMIT 1");
    $items   = safe($conn, "
        SELECT di.barcode, di.grower_num, di.timb_grade, di.mass, di.price,
               (di.price * di.mass) AS value,
               g.name, g.surname
        FROM dispatch_items di
        LEFT JOIN bale_junus bj ON bj.id = di.bale_junusid
        LEFT JOIN growers g ON g.id = bj.growerid
        WHERE di.dispatch_sessionid = $sid
        ORDER BY di.grower_num, di.barcode
    ");
    $total_mass = array_sum(array_column($items, 'mass'));
    $total_val  = array_sum(array_column($items, 'value'));
    $sp_name    = $_SESSION['selling_point_name'] ?? '';
    ?>
<!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title>Dispatch Note #<?=$sid?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;font-size:11px;color:#000;padding:15mm}
h1{font-size:16px;font-weight:700;margin-bottom:2px}
.header{display:flex;justify-content:space-between;margin-bottom:12px;border-bottom:2px solid #000;padding-bottom:8px}
.meta{font-size:10px;line-height:1.7}
.meta strong{display:inline-block;width:80px}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:10px}
th{background:#1a1208;color:#fff;padding:5px 7px;text-align:left;font-size:9px;letter-spacing:.05em;text-transform:uppercase}
td{padding:4px 7px;border-bottom:1px solid #ddd}
tr:nth-child(even) td{background:#f9f9f9}
.text-right{text-align:right}
.tfoot td{font-weight:700;border-top:2px solid #000;background:#f0f0f0;padding:6px 7px}
.sigs{margin-top:30px;display:flex;gap:40px}
.sig-box{flex:1;border-top:1px solid #000;padding-top:6px;font-size:10px}
@media print{body{padding:10mm}.no-print{display:none}}
</style>
</head><body>
<div class="header">
  <div>
    <h1>DISPATCH NOTE</h1>
    <div style="font-size:10px;color:#555"><?=htmlspecialchars($sp_name)?></div>
  </div>
  <div style="text-align:right;font-size:10px">
    <div style="font-size:14px;font-weight:700">#<?=$sid?></div>
    <div><?=date('d F Y', strtotime($date_f))?></div>
    <div style="margin-top:4px">
      <span style="background:<?=$session['status']==='closed'?'#666':'#3d6b35'?>;color:#fff;padding:2px 8px;border-radius:3px;font-size:9px">
        <?=strtoupper($session['status'])?>
      </span>
    </div>
  </div>
</div>

<div class="meta">
  <div><strong>Destination:</strong> <?=htmlspecialchars($session['destination'])?></div>
  <div><strong>Vehicle:</strong> <?=htmlspecialchars($session['vehicle_num'] ?? '—')?></div>
  <div><strong>Driver:</strong> <?=htmlspecialchars($session['driver'] ?? '—')?></div>
  <?php if($session['notes']):?><div><strong>Notes:</strong> <?=htmlspecialchars($session['notes'])?></div><?php endif;?>
  <div><strong>Prepared by:</strong> <?=htmlspecialchars($session['username'])?></div>
</div>

<table>
<thead><tr>
  <th>#</th><th>Barcode</th><th>Grower</th><th>Grade</th>
  <th class="text-right">Mass (kg)</th><th class="text-right">Price $/kg</th><th class="text-right">Value USD</th>
</tr></thead>
<tbody>
<?php $rank=0; foreach($items as $r): $rank++; ?>
<tr>
  <td><?=$rank?></td>
  <td style="font-family:monospace"><?=htmlspecialchars($r['barcode'])?></td>
  <td><span style="font-weight:600"><?=htmlspecialchars($r['grower_num'])?></span> <?=htmlspecialchars(trim($r['name'].' '.$r['surname']))?></td>
  <td><?=htmlspecialchars($r['timb_grade'])?></td>
  <td class="text-right"><?=number_format((float)$r['mass'],1)?></td>
  <td class="text-right">$<?=number_format((float)$r['price'],4)?></td>
  <td class="text-right">$<?=number_format((float)$r['value'],2)?></td>
</tr>
<?php endforeach;?>
</tbody>
<tfoot>
<tr>
  <td colspan="4" class="tfoot" style="text-align:right">TOTAL — <?=count($items)?> bales</td>
  <td class="tfoot text-right"><?=number_format($total_mass,1)?></td>
  <td class="tfoot"></td>
  <td class="tfoot text-right">$<?=number_format($total_val,2)?></td>
</tr>
</tfoot>
</table>

<div class="sigs">
  <div class="sig-box">Prepared by: ___________________<br>Name & Signature</div>
  <div class="sig-box">Driver received: ___________________<br>Name & Signature</div>
  <div class="sig-box">Destination received: ___________________<br>Name, Date & Signature</div>
</div>

<script>window.onload=()=>window.print()</script>
</body></html>
    <?php exit; ?>
<?php } ?>

<?php
// ── Load sessions for page ─────────────────────────────────────────────────
$date_sessions = safe($conn, "
    SELECT ds.*,
           COALESCE(u.username, CONCAT('User #',ds.userid)) AS username,
           COUNT(di.id) cnt,
           COALESCE(SUM(di.mass),0) total_mass,
           COALESCE(SUM(di.price * di.mass),0) total_val
    FROM dispatch_sessions ds
    LEFT JOIN users u ON u.id = ds.userid
    LEFT JOIN dispatch_items di ON di.dispatch_sessionid = ds.id
    WHERE ds.selling_pointid = $spid
    GROUP BY ds.id ORDER BY ds.dispatch_date DESC, ds.id DESC
");

// Session detail
$session_detail = $items_detail = [];
if ($sid) {
    $session_detail = safeRow($conn, "SELECT ds.*, COALESCE(u.username, CONCAT('User #',ds.userid)) AS username FROM dispatch_sessions ds LEFT JOIN users u ON u.id=ds.userid WHERE ds.id=$sid AND ds.selling_pointid=$spid LIMIT 1");
    $items_detail   = safe($conn, "
        SELECT di.*, g.name, g.surname
        FROM dispatch_items di
        LEFT JOIN bale_junus bj ON bj.id = di.bale_junusid
        LEFT JOIN growers g ON g.id = bj.growerid
        WHERE di.dispatch_sessionid = $sid
        ORDER BY di.grower_num, di.barcode
    ");
}

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Dispatch Export</h2>
    <p>View, print and export dispatch session reports</p>
  </div>
  <a href="dispatch.php" class="btn btn-ghost btn-sm">← Back to Dispatch</a>
</div>

<?php if(empty($date_sessions)): ?>
<div class="alert" style="background:var(--gold-bg);border-left:3px solid var(--gold);color:var(--ink);padding:.85rem 1.15rem;margin-bottom:1rem;font-size:.85rem">
  <strong>No dispatch sessions found</strong> for selling point ID <?=$spid?>.
  <?php if(!$spid): ?>
  <br>⚠ No selling point selected — please log out, choose a floor when you log back in, and reload this page.
  <?php else: ?>
  <br>Go to <a href="dispatch.php">Dispatch</a> to create a session first, then return here to export.
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid-2" style="align-items:start">

<!-- Session list -->
<div class="card">
<div class="card-head">
  <h3>All Dispatch Sessions</h3>
  <span class="badge badge-gray"><?=count($date_sessions)?></span>
</div>
<?php if(!empty($date_sessions)):?>
<div class="table-wrap" style="max-height:600px;overflow-y:auto"><table>
<thead><tr>
  <th>Date</th><th>Destination</th><th class="text-right">Bales</th>
  <th class="text-right">Value</th><th>Status</th><th></th>
</tr></thead>
<tbody>
<?php foreach($date_sessions as $s): $isActive = (int)$s['id'] === $sid; ?>
<tr style="<?=$isActive?'background:rgba(200,149,42,.06);border-left:3px solid var(--gold)':''?>;cursor:pointer"
    onclick="location='?sid=<?=$s['id']?>&date=<?=$s['dispatch_date']?>'">
  <td style="font-size:.75rem"><?=date('d M Y', strtotime($s['dispatch_date']))?></td>
  <td>
    <div style="font-weight:500;font-size:.82rem"><?=htmlspecialchars($s['destination'])?></div>
    <?php if($s['vehicle_num']):?>
    <div style="font-size:.68rem;color:var(--muted)"><?=htmlspecialchars($s['vehicle_num'])?></div>
    <?php endif;?>
  </td>
  <td class="td-mono text-right"><?=$s['cnt']?></td>
  <td class="td-mono text-right fw-500">$<?=number_format((float)$s['total_val'],0)?></td>
  <td>
    <span class="badge <?=$s['status']==='closed'?'badge-gray':'badge-green'?>" style="font-size:.6rem">
      <?=ucfirst($s['status'])?>
    </span>
  </td>
  <td onclick="event.stopPropagation()" style="white-space:nowrap">
    <a href="?sid=<?=$s['id']?>&date=<?=$s['dispatch_date']?>&export=print" target="_blank"
       class="btn btn-ghost btn-sm">🖨 Print</a>
    <a href="?sid=<?=$s['id']?>&date=<?=$s['dispatch_date']?>&export=csv"
       class="btn btn-ghost btn-sm">⬇ CSV</a>
  </td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
<?php else:?>
<div class="card-body text-center text-muted" style="padding:2rem">No dispatch sessions yet.</div>
<?php endif;?>
</div>

<!-- Session detail -->
<div>
<?php if(!empty($session_detail)): ?>
<div class="card" style="border-top:3px solid var(--gold)">
<div class="card-head">
  <div>
    <h3>Session #<?=$sid?> — <?=htmlspecialchars($session_detail['destination'])?></h3>
    <div style="font-size:.7rem;color:var(--muted);margin-top:2px">
      <?=date('d M Y', strtotime($session_detail['dispatch_date']))?>
      <?php if($session_detail['vehicle_num']):?> · <?=htmlspecialchars($session_detail['vehicle_num'])?><?php endif;?>
      <?php if($session_detail['driver']):?> · <?=htmlspecialchars($session_detail['driver'])?><?php endif;?>
    </div>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <span class="badge <?=$session_detail['status']==='closed'?'badge-gray':'badge-green'?>" style="font-size:.62rem">
      <?=ucfirst($session_detail['status'])?>
    </span>
  </div>
</div>

<!-- Export actions -->
<div style="padding:.75rem 1.15rem;border-bottom:1px solid var(--border);display:flex;gap:.5rem;flex-wrap:wrap">
  <a href="?sid=<?=$sid?>&date=<?=$session_detail['dispatch_date']?>&export=print"
     target="_blank" class="btn btn-primary">
    🖨 Print Dispatch Note
  </a>
  <a href="?sid=<?=$sid?>&date=<?=$session_detail['dispatch_date']?>&export=csv"
     class="btn btn-ghost">
    ⬇ Download CSV
  </a>
</div>

<!-- Summary strip -->
<?php
$t_mass = array_sum(array_column($items_detail,'mass'));
$t_val  = array_sum(array_map(fn($r)=>(float)$r['price']*(float)$r['mass'], $items_detail));
?>
<div style="display:flex;gap:2rem;padding:.75rem 1.15rem;background:var(--cream-d);border-bottom:1px solid var(--border);font-size:.8rem">
  <div><span style="color:var(--muted)">Bales</span> <strong><?=count($items_detail)?></strong></div>
  <div><span style="color:var(--muted)">Total Mass</span> <strong><?=number_format($t_mass,1)?> kg</strong></div>
  <div><span style="color:var(--muted)">Total Value</span> <strong>$<?=number_format($t_val,2)?></strong></div>
  <div><span style="color:var(--muted)">By</span> <strong><?=htmlspecialchars($session_detail['username'])?></strong></div>
</div>

<!-- Bale table -->
<div class="table-wrap" style="max-height:450px;overflow-y:auto"><table>
<thead><tr>
  <th>#</th><th>Barcode</th><th>Grower</th><th>Grade</th>
  <th class="text-right">Mass</th><th class="text-right">Price</th><th class="text-right">Value</th>
</tr></thead>
<tbody>
<?php $rank=0; foreach($items_detail as $r): $rank++;
  $val = (float)$r['price'] * (float)$r['mass'];
?>
<tr>
  <td class="td-muted" style="font-size:.7rem"><?=$rank?></td>
  <td style="font-family:monospace;font-size:.75rem"><?=htmlspecialchars($r['barcode'])?></td>
  <td>
    <span class="badge badge-gold" style="font-size:.6rem"><?=htmlspecialchars($r['grower_num'])?></span>
    <span style="font-size:.75rem;margin-left:3px"><?=htmlspecialchars(trim($r['name'].' '.($r['surname']??'')))?></span>
  </td>
  <td><span class="badge badge-gray" style="font-size:.6rem"><?=htmlspecialchars($r['timb_grade'])?></span></td>
  <td class="td-mono text-right"><?=number_format((float)$r['mass'],1)?> kg</td>
  <td class="td-mono text-right">$<?=number_format((float)$r['price'],4)?></td>
  <td class="td-mono text-right fw-500">$<?=number_format($val,2)?></td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
</div>
<?php else:?>
<div class="card">
<div class="card-body text-center text-muted" style="padding:3rem">
  <div style="font-size:2rem;opacity:.25;margin-bottom:.5rem">📋</div>
  Select a session from the list to view and export
</div>
</div>
<?php endif;?>
</div>

</div>

<?php include "includes/layout_end.php"; ?>
