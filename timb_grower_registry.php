<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "audit.php";
require_login();

$page_title = "TIMB Grower Registry"; $active_page = "timb_grower_registry";
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$userid = current_userid();
$msg = $err = '';

if (!$spid) {
    $r = $conn->query("SELECT id FROM selling_point WHERE active=1 ORDER BY id LIMIT 1");
    if ($r && $r !== true && $r->num_rows > 0) { $spid = (int)$r->fetch_assoc()['id']; $_SESSION['selling_pointid'] = $spid; }
}

$conn->query("CREATE TABLE IF NOT EXISTS timb_grower_registry (
    id INT AUTO_INCREMENT PRIMARY KEY, selling_pointid INT NOT NULL,
    grower_num VARCHAR(40) NOT NULL, name VARCHAR(80) DEFAULT '', surname VARCHAR(80) DEFAULT '',
    national_id VARCHAR(40) DEFAULT '', province VARCHAR(60) DEFAULT '',
    district_name VARCHAR(60) DEFAULT '', area VARCHAR(60) DEFAULT '',
    farm_name VARCHAR(80) DEFAULT '', crop_type VARCHAR(30) DEFAULT 'Virginia',
    contractor VARCHAR(60) DEFAULT '', hectares DECIMAL(8,2) DEFAULT 0,
    address1 VARCHAR(120) DEFAULT '', phone VARCHAR(30) DEFAULT '',
    gender VARCHAR(5) DEFAULT '', barn_type VARCHAR(20) DEFAULT '',
    registration_date DATE DEFAULT NULL, status ENUM('active','suspended','cancelled') DEFAULT 'active',
    timb_verified TINYINT DEFAULT 1, last_synced TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tgr (selling_pointid, grower_num))");

// Ensure growers table has unique key for upsert
$conn->query("ALTER TABLE growers ADD UNIQUE KEY uk_grower_floor (grower_num, selling_pointid)");

// ── Map TIMB CSV columns to our fields ────────────────────────────────────
// Handles both TIMB format (GROWER NO., SURNAME, NAME...) and generic format (grower_num, name...)
function map_timb_row($row) {
    // Normalise keys: lowercase, strip spaces/dots/underscores/BOM
    $d = [];
    foreach ($row as $k => $v) {
        $nk = preg_replace('/[^a-z0-9]/', '', strtolower(trim($k)));
        $d[$nk] = trim($v);
    }

    // Grower number — TIMB: "GROWER NO." → "growerno"
    $gnum = $d['growerno'] ?? $d['growernum'] ?? $d['grower_num'] ?? $d['grower'] ?? '';
    $gnum = strtoupper(trim($gnum));

    // Name fields — TIMB has SURNAME first, then NAME
    $name    = $d['name']    ?? $d['firstname'] ?? $d['first']    ?? '';
    $surname = $d['surname'] ?? $d['lastname']  ?? $d['last']     ?? '';

    // National ID
    $nid = $d['nationalid'] ?? $d['national_id'] ?? $d['idnumber'] ?? $d['id'] ?? '';

    // Province
    $prov = $d['province'] ?? '';

    // District — TIMB has both DISTRICT (code) and DISTRICT_NAME (text)
    $dist = $d['districtname'] ?? $d['district_name'] ?? $d['district'] ?? '';

    // Area
    $area = $d['area'] ?? '';

    // Farm name — TIMB: "FARM NAME" → "farmname"
    $farm = $d['farmname'] ?? $d['farm_name'] ?? $d['farm'] ?? '';

    // Contractor
    $contr = $d['contractor'] ?? '';

    // Hectares
    $ha = (float)($d['hectares'] ?? $d['ha'] ?? 0);

    // Address
    $addr1 = $d['postaladdress1'] ?? $d['address1'] ?? $d['address'] ?? '';
    $addr2 = $d['postaladdress2'] ?? $d['address2'] ?? '';
    $phone = $d['telno'] ?? $d['phone'] ?? $d['contact'] ?? '';
    $gender= $d['gender'] ?? '';
    $barn  = $d['barntype'] ?? $d['barn_type'] ?? '';

    return compact('gnum','name','surname','nid','prov','dist','area','farm','contr','ha','addr1','addr2','phone','gender','barn');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'import_csv') {
        $file = $_FILES['csv_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) { $err = "Upload error."; }
        else {
            $handle   = fopen($file['tmp_name'], 'r');
            $raw_hdr  = fgetcsv($handle); // read header row
            if (!$raw_hdr) { $err = "Empty or invalid CSV."; fclose($handle); }
            else {
                $imported = $updated = $skipped = $gr_new = $gr_upd = 0;
                $now = date('Y-m-d H:i:s');

                while (($cols = fgetcsv($handle)) !== false) {
                    // Combine header with row values
                    $raw = array_combine(
                        array_slice($raw_hdr, 0, count($cols)),
                        array_slice($cols, 0, count($raw_hdr))
                    );
                    $d = map_timb_row($raw);

                    if (!$d['gnum']) { $skipped++; continue; }

                    // Escape all fields
                    $gn   = $conn->real_escape_string($d['gnum']);
                    $nm   = $conn->real_escape_string($d['name']);
                    $sur  = $conn->real_escape_string($d['surname']);
                    $nid  = $conn->real_escape_string($d['nid']);
                    $prov = $conn->real_escape_string($d['prov']);
                    $dist = $conn->real_escape_string($d['dist']);
                    $area = $conn->real_escape_string($d['area']);
                    $farm = $conn->real_escape_string($d['farm']);
                    $contr= $conn->real_escape_string($d['contr']);
                    $ha   = (float)$d['ha'];
                    $addr1= $conn->real_escape_string($d['addr1']);
                    $addr2= $conn->real_escape_string($d['addr2']);
                    $phone= $conn->real_escape_string($d['phone']);
                    $gend = $conn->real_escape_string($d['gender']);
                    $barn = $conn->real_escape_string($d['barn']);

                    // ── 1. Upsert into timb_grower_registry ──────────────
                    $conn->query("INSERT INTO timb_grower_registry
                        (selling_pointid,grower_num,name,surname,national_id,province,district_name,
                         area,farm_name,contractor,hectares,address1,phone,gender,barn_type,
                         timb_verified,last_synced)
                        VALUES ($spid,'$gn','$nm','$sur','$nid','$prov','$dist',
                         '$area','$farm','$contr',$ha,'$addr1','$phone','$gend','$barn',
                         1,NOW())
                        ON DUPLICATE KEY UPDATE
                            name=IF('$nm'!='','$nm',name),
                            surname=IF('$sur'!='','$sur',surname),
                            national_id=IF('$nid'!='','$nid',national_id),
                            province=IF('$prov'!='','$prov',province),
                            district_name=IF('$dist'!='','$dist',district_name),
                            area=IF('$area'!='','$area',area),
                            farm_name=IF('$farm'!='','$farm',farm_name),
                            contractor=IF('$contr'!='','$contr',contractor),
                            hectares=IF($ha>0,$ha,hectares),
                            address1=IF('$addr1'!='','$addr1',address1),
                            phone=IF('$phone'!='','$phone',phone),
                            last_synced=NOW()");

                    if ($conn->affected_rows === 1)     $imported++;
                    elseif ($conn->affected_rows === 2) $updated++;

                    // ── 2. Sync into growers table (the main registry) ───
                    $conn->query("INSERT INTO growers
                        (userid,selling_pointid,grower_num,name,surname,national_id,province,
                         district,area,farm_name,contractor,address1,address2,phone,
                         dry_land_ha,irr_land_ha,dryland,created_at,datetime)
                        VALUES ($userid,$spid,'$gn','$nm','$sur','$nid','$prov',
                         '$dist','$area','$farm','$contr','$addr1','$addr2','$phone',
                         $ha,0,0,'$now','$now')
                        ON DUPLICATE KEY UPDATE
                            name=IF('$nm'!='','$nm',name),
                            surname=IF('$sur'!='','$sur',surname),
                            national_id=IF('$nid'!='','$nid',national_id),
                            province=IF('$prov'!='','$prov',province),
                            district=IF('$dist'!='','$dist',district),
                            area=IF('$area'!='','$area',area),
                            farm_name=IF('$farm'!='','$farm',farm_name),
                            contractor=IF('$contr'!='','$contr',contractor),
                            phone=IF('$phone'!='','$phone',phone)");

                    if ($conn->affected_rows === 1)     $gr_new++;
                    elseif ($conn->affected_rows === 2) $gr_upd++;
                }
                fclose($handle);

                audit_log($conn,$spid,$userid,'timb_grower_registry','import',null,null,
                    ['timb_new'=>$imported,'timb_upd'=>$updated,'gr_new'=>$gr_new,'gr_upd'=>$gr_upd,'skipped'=>$skipped]);

                // Redirect to grower registry so user can verify immediately
                $_SESSION['gr_flash'] = "✓ TIMB Register imported: $imported new + $updated updated in TIMB registry · $gr_new new + $gr_upd updated in Grower Registry. $skipped rows skipped.";
                header("Location: grower_registry.php?fm_sync=1");
                exit;
            }
        }
    }

    if ($action === 'match') {
        // Match unmatched TIMB entries to growers — also inserts missing ones
        $tgr_q = $conn->query("SELECT * FROM timb_grower_registry WHERE selling_pointid=$spid");
        $matched = $created = 0;
        $now = date('Y-m-d H:i:s');
        while ($tgr_q && $r = $tgr_q->fetch_assoc()) {
            $gn   = $conn->real_escape_string($r['grower_num']);
            $nm   = $conn->real_escape_string($r['name']);
            $sur  = $conn->real_escape_string($r['surname']);
            $nid  = $conn->real_escape_string($r['national_id']);
            $prov = $conn->real_escape_string($r['province']);
            $dist = $conn->real_escape_string($r['district_name']);
            $farm = $conn->real_escape_string($r['farm_name']);
            $g_q  = $conn->query("SELECT id FROM growers WHERE grower_num='$gn' AND selling_pointid=$spid LIMIT 1");
            if ($g_q && $g_q->num_rows > 0) { $matched++; }
            else {
                $conn->query("INSERT INTO growers (userid,selling_pointid,grower_num,name,surname,national_id,province,district,farm_name,address1,address2,phone,contractor,dry_land_ha,irr_land_ha,dryland,created_at,datetime) VALUES ($userid,$spid,'$gn','$nm','$sur','$nid','$prov','$dist','$farm','','','','',0,0,0,'$now','$now')");
                $created++;
            }
        }
        $msg = "✓ Matched $matched existing growers. Created $created new grower records from TIMB registry.";
    }
}

// Load stats
$f_search = $conn->real_escape_string(trim($_GET['q'] ?? ''));
$where    = "tgr.selling_pointid=$spid";
if ($f_search) $where .= " AND (tgr.grower_num LIKE '%$f_search%' OR tgr.name LIKE '%$f_search%' OR tgr.national_id LIKE '%$f_search%')";

$tgr_q = $conn->query("
    SELECT tgr.*, CASE WHEN g.id IS NOT NULL THEN 1 ELSE 0 END in_registry
    FROM timb_grower_registry tgr
    LEFT JOIN growers g ON g.grower_num=tgr.grower_num AND g.selling_pointid=$spid
    WHERE $where ORDER BY tgr.grower_num LIMIT 300");
$registry = ($tgr_q && $tgr_q !== true) ? $tgr_q->fetch_all(MYSQLI_ASSOC) : [];

$_r1 = $conn->query("SELECT COUNT(*) c FROM timb_grower_registry WHERE selling_pointid=$spid");
$total = $_r1 ? (int)$_r1->fetch_assoc()['c'] : 0;
$_r2 = $conn->query("SELECT COUNT(*) c FROM timb_grower_registry tgr JOIN growers g ON g.grower_num=tgr.grower_num AND g.selling_pointid=$spid WHERE tgr.selling_pointid=$spid");
$in_registry = $_r2 ? (int)$_r2->fetch_assoc()['c'] : 0;
$_r3 = $conn->query("SELECT COUNT(*) c FROM growers WHERE selling_pointid=$spid");
$gr_total = $_r3 ? (int)$_r3->fetch_assoc()['c'] : 0;

include "includes/layout.php";
?>
<div class="page-header">
  <div class="page-header-left">
    <h2>TIMB Grower Registry</h2>
    <p>Import TIMB <code>groreg*.csv</code> files — growers sync automatically to Grower Registry</p>
  </div>
  <a href="grower_registry.php" class="btn btn-ghost btn-sm">→ View Grower Registry</a>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1rem">
  <div class="kpi-card"><div class="kpi-label">TIMB Registry</div><div class="kpi-value"><?=number_format($total)?></div></div>
  <div class="kpi-card"><div class="kpi-label">In Grower Registry</div><div class="kpi-value text-leaf"><?=number_format($in_registry)?></div></div>
  <div class="kpi-card"><div class="kpi-label">Not in Registry</div><div class="kpi-value text-gold"><?=number_format($total-$in_registry)?></div></div>
  <div class="kpi-card"><div class="kpi-label">Total Growers</div><div class="kpi-value"><?=number_format($gr_total)?></div></div>
</div>

<div class="premium-tabs-nav" data-tabs="timbreg">
  <button type="button" class="premium-tab-btn active" data-tab="registry">📋 TIMB Registry</button>
  <button type="button" class="premium-tab-btn" data-tab="import">📂 Import Register</button>
  <button type="button" class="premium-tab-btn" data-tab="sync">🔄 Sync Missing</button>
</div>

<!-- Import CSV -->
<div id="panel-import" class="tab-panel" data-tabs="timbreg">
<div class="card">
<div class="card-head"><h3>📂 Import TIMB Grower Register</h3></div>
<div class="card-body">
<div style="background:var(--cream-d);border-radius:6px;padding:.65rem .85rem;margin-bottom:.75rem;font-size:.78rem">
  <strong>Accepted format:</strong> TIMB <code>groreg*.csv</code> export file<br>
  Columns detected automatically: <code>GROWER NO.</code> · <code>SURNAME</code> · <code>NAME</code> · <code>NATIONAL ID</code> · <code>FARM NAME</code> · <code>DISTRICT_NAME</code> · <code>AREA</code> · <code>PROVINCE</code> · <code>CONTRACTOR</code> · <code>HECTARES</code><br>
  <span style="color:var(--leaf)">✓ Growers are inserted into <strong>both</strong> the TIMB Registry and the main Grower Registry automatically.</span><br>
  <span style="color:var(--muted)">Existing growers are updated — no duplicates created.</span>
</div>
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" value="import_csv">
<div class="form-group">
  <label>TIMB Grower Register CSV <span style="font-size:.7rem;color:var(--muted)">(groreg*.csv or any TIMB export)</span></label>
  <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
</div>
<button type="submit" class="btn btn-primary" style="width:100%" data-progress-label="Importing and syncing to Grower Registry...">
  ⬆ Import &amp; Sync to Grower Registry
</button>
</form>
</div>
</div>

</div><!-- /panel-import -->

<!-- Sync missing -->
<div id="panel-sync" class="tab-panel" data-tabs="timbreg">
<div class="card">
<div class="card-head"><h3>🔄 Sync Missing Growers</h3></div>
<div class="card-body">
<p style="font-size:.78rem;color:var(--muted);margin-bottom:.75rem">
  If any TIMB registry entries are not yet in the Grower Registry, click to create them.
  Currently <strong><?=number_format($total - $in_registry)?></strong> growers in TIMB registry are not in Grower Registry.
</p>
<form method="POST">
  <input type="hidden" name="action" value="match">
  <button type="submit" class="btn <?=($total-$in_registry)>0?'btn-primary':'btn-ghost'?>" style="width:100%"
    data-progress-label="Syncing...">
    🔄 Sync <?=number_format($total-$in_registry)?> Missing Growers Now
  </button>
</form>
</div>
</div>
</div><!-- /panel-sync -->

<!-- Registry table -->
<div id="panel-registry" class="tab-panel active" data-tabs="timbreg">
<div class="card">
<div class="card-head">
  <h3>TIMB Registry — <?=number_format($total)?></h3>
  <form method="GET" style="display:flex;gap:.35rem">
    <input type="text" name="q" value="<?=htmlspecialchars($f_search)?>" class="form-control"
           placeholder="Search grower #, name..." style="font-size:.75rem;padding:.3rem .55rem;width:160px">
    <button type="submit" class="btn btn-ghost btn-sm">Go</button>
  </form>
</div>
<div class="table-wrap" style="max-height:560px;overflow-y:auto"><table>
<thead><tr>
  <th>Grower #</th><th>Surname</th><th>Name</th>
  <th>Province</th><th>District</th><th>Farm</th>
  <th>National ID</th><th>In Registry</th>
</tr></thead>
<tbody>
<?php if(empty($registry)):?>
<tr><td colspan="8" class="text-center text-muted" style="padding:2rem">
  No TIMB registry data yet. Import a <code>groreg*.csv</code> file to populate.
</td></tr>
<?php else: foreach($registry as $r):?>
<tr>
  <td><span class="badge badge-gold" style="font-size:.6rem"><?=htmlspecialchars($r['grower_num'])?></span></td>
  <td style="font-size:.78rem;font-weight:500"><?=htmlspecialchars($r['surname'])?></td>
  <td style="font-size:.78rem"><?=htmlspecialchars($r['name'])?></td>
  <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($r['province'])?></td>
  <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($r['district_name'])?></td>
  <td style="font-size:.7rem"><?=htmlspecialchars($r['farm_name'])?></td>
  <td style="font-size:.68rem;font-family:monospace"><?=htmlspecialchars($r['national_id'])?></td>
  <td>
    <?=$r['in_registry']
      ? '<span class="badge badge-green" style="font-size:.58rem">✓ Yes</span>'
      : '<span class="badge badge-red" style="font-size:.58rem">✗ No</span>'?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table></div>
</div>
</div><!-- /panel-registry -->

<?php include "includes/layout_end.php"; ?>
