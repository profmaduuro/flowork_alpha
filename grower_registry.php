<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Growers";
$active_page = "growers";

$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$userid = current_userid();

$msg = $err = '';
$csv_preview  = [];
$csv_headers  = [];
$csv_filename = '';
$import_results = null;

// ── CSV TEMPLATE DOWNLOAD ──────────────────────────────────────────────────
// Download all registered growers as CSV
if (isset($_GET['download_all'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="growers_'.date('Y-m-d').'.csv"');
    echo "grower_num,name,surname,national_id,phone,contractor,farm_name,province,district,address1,address2,dry_land_ha,irr_land_ha,dryland\n";
    $all = $conn->query("SELECT * FROM growers WHERE selling_pointid=$spid ORDER BY grower_num");
    while ($gr = $all->fetch_assoc()) {
        echo implode(',', [
            '"'.$gr['grower_num'].'"', '"'.$gr['name'].'"', '"'.$gr['surname'].'"',
            '"'.$gr['national_id'].'"', '"'.$gr['phone'].'"', '"'.$gr['contractor'].'"',
            '"'.$gr['farm_name'].'"', '"'.$gr['province'].'"', '"'.$gr['district'].'"',
            '"'.$gr['address1'].'"', '"'.$gr['address2'].'"',
            $gr['dry_land_ha'], $gr['irr_land_ha'], '"'.$gr['dryland'].'"'
        ])."
";
    }
    exit;
}

if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="growers_template.csv"');
    echo "grower_num,name,surname,national_id,phone,address1,address2,contractor,farm_name,province,district,dry_land_ha,irr_land_ha,dryland\n";
    echo "G001234,John,Moyo,63-123456A-00,0771234567,Plot 5 Mazowe,,TIMB,Moyo Farm,Mashonaland Central,Mazowe,10,5,5\n";
    echo "G001235,Mary,Ndlovu,23-654321B-00,0712345678,Farm 12 Gweru,,Contract,Ndlovu Farm,Midlands,Gweru,8,0,8\n";
    exit;
}

// ── SINGLE GROWER ADD ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $fields = ['grower_num','name','surname','national_id','phone',
               'address1','address2','contractor','farm_name','province',
               'district','dry_land_ha','irr_land_ha','dryland'];
    $d = [];
    foreach ($fields as $f) $d[$f] = $conn->real_escape_string(trim($_POST[$f] ?? ''));

    $dup = $conn->query("SELECT id FROM growers WHERE grower_num='{$d['grower_num']}' AND selling_pointid=$spid LIMIT 1")->num_rows;
    if ($dup) {
        $err = "Grower number {$d['grower_num']} already exists at this selling point.";
    } else {
        $now = date('Y-m-d H:i:s');
        $sql = "INSERT INTO growers (userid,selling_pointid,grower_num,name,surname,national_id,phone,
                address1,address2,contractor,farm_name,province,district,
                dry_land_ha,irr_land_ha,dryland,created_at)
                VALUES ($userid,$spid,
                '{$d['grower_num']}','{$d['name']}','{$d['surname']}','{$d['national_id']}',
                '{$d['phone']}','{$d['address1']}','{$d['address2']}','{$d['contractor']}',
                '{$d['farm_name']}','{$d['province']}','{$d['district']}',
                '{$d['dry_land_ha']}','{$d['irr_land_ha']}','{$d['dryland']}','$now')";
        if ($conn->query($sql))
            $msg = "Grower {$d['grower_num']} — {$d['name']} {$d['surname']} registered.";
        else
            $err = "DB Error: " . $conn->error;
    }
}

// ── CSV UPLOAD: PREVIEW STEP ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'csv_preview') {
    if (!empty($_FILES['csvfile']['tmp_name'])) {
        $tmp = $_FILES['csvfile']['tmp_name'];
        $handle = fopen($tmp, 'r');
        if ($handle) {
            // Detect delimiter: comma or semicolon
            $first_line = fgets($handle);
            rewind($handle);
            $delim = substr_count($first_line, ';') > substr_count($first_line, ',') ? ';' : ',';

            $row_num = 0;
            while (($row = fgetcsv($handle, 2000, $delim)) !== false) {
                $row = array_map('trim', $row);
                if ($row_num === 0) {
                    // Normalise headers: lowercase, strip BOM
                    $csv_headers = array_map(function($h) {
                        return strtolower(preg_replace('/[^a-z0-9_]/i', '_', ltrim($h, "\xEF\xBB\xBF")));
                    }, $row);
                } else {
                    if (implode('', $row) === '') continue; // skip blank rows
                    $csv_preview[] = array_combine(
                        array_slice($csv_headers, 0, count($row)),
                        array_slice($row, 0, count($csv_headers))
                    );
                }
                $row_num++;
                if ($row_num > 201) break; // preview cap: 200 rows + header
            }
            fclose($handle);

            // Store in session for import step
            $_SESSION['csv_preview']  = $csv_preview;
            $_SESSION['csv_headers']  = $csv_headers;
            $_SESSION['csv_filename'] = htmlspecialchars($_FILES['csvfile']['name']);
        } else {
            $err = "Could not read the uploaded file.";
        }
    } else {
        $err = "No file uploaded. Please choose a CSV file.";
    }
}

// Resume preview from session if returning to this step
if (empty($csv_preview) && !empty($_SESSION['csv_preview'])) {
    $csv_preview  = $_SESSION['csv_preview'];
    $csv_headers  = $_SESSION['csv_headers'];
    $csv_filename = $_SESSION['csv_filename'] ?? '';
}

// ── CSV IMPORT: CONFIRM STEP ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'csv_import') {
    $rows        = $_SESSION['csv_preview']  ?? [];
    $headers     = $_SESSION['csv_headers']  ?? [];
    $col_map     = $_POST['col_map']         ?? [];   // user's column mapping
    $on_dup      = $_POST['on_duplicate']    ?? 'skip'; // skip | update

    $inserted = 0; $updated = 0; $skipped = 0; $errors = [];
    $now = date('Y-m-d H:i:s');

    // Required DB columns and their defaults
    $db_cols = ['grower_num'=>'','name'=>'','surname'=>'','national_id'=>'',
                'phone'=>'','address1'=>'','address2'=>'','contractor'=>'',
                'farm_name'=>'','province'=>'','district'=>'',
                'dry_land_ha'=>'0','irr_land_ha'=>'0','dryland'=>''];

    foreach ($rows as $i => $row) {
        // Map CSV columns → DB columns using user's mapping
        $d = $db_cols; // start with defaults
        foreach ($col_map as $db_col => $csv_col) {
            if ($csv_col !== '' && isset($row[$csv_col])) {
                $d[$db_col] = trim($row[$csv_col]);
            }
        }

        // Validate required
        if (empty($d['grower_num'])) { $errors[] = "Row ".($i+2).": grower_num is empty — skipped."; $skipped++; continue; }
        if (empty($d['name']))       { $errors[] = "Row ".($i+2).": name is empty — skipped.";       $skipped++; continue; }

        // Escape all
        foreach ($d as $k => $v) $d[$k] = $conn->real_escape_string($v);

        $dup = $conn->query("SELECT id FROM growers WHERE grower_num='{$d['grower_num']}' AND selling_pointid=$spid LIMIT 1")->fetch_assoc();

        if ($dup) {
            if ($on_dup === 'update') {
                $gid = (int)$dup['id'];
                $sql = "UPDATE growers SET
                    name='{$d['name']}',surname='{$d['surname']}',national_id='{$d['national_id']}',
                    phone='{$d['phone']}',address1='{$d['address1']}',address2='{$d['address2']}',
                    contractor='{$d['contractor']}',farm_name='{$d['farm_name']}',
                    province='{$d['province']}',district='{$d['district']}',
                    dry_land_ha='{$d['dry_land_ha']}',irr_land_ha='{$d['irr_land_ha']}',dryland='{$d['dryland']}'
                    WHERE id=$gid";
                if ($conn->query($sql)) $updated++;
                else $errors[] = "Row ".($i+2)." update error: ".$conn->error;
            } else {
                $skipped++;
            }
        } else {
            $sql = "INSERT INTO growers (userid,selling_pointid,grower_num,name,surname,national_id,phone,
                    address1,address2,contractor,farm_name,province,district,
                    dry_land_ha,irr_land_ha,dryland,created_at)
                    VALUES ($userid,$spid,
                    '{$d['grower_num']}','{$d['name']}','{$d['surname']}','{$d['national_id']}',
                    '{$d['phone']}','{$d['address1']}','{$d['address2']}','{$d['contractor']}',
                    '{$d['farm_name']}','{$d['province']}','{$d['district']}',
                    '{$d['dry_land_ha']}','{$d['irr_land_ha']}','{$d['dryland']}','$now')";
            if ($conn->query($sql)) $inserted++;
            else $errors[] = "Row ".($i+2)." insert error: ".$conn->error;
        }
    }

    // Clear session preview
    unset($_SESSION['csv_preview'], $_SESSION['csv_headers'], $_SESSION['csv_filename']);
    $csv_preview = $csv_headers = [];

    $import_results = compact('inserted','updated','skipped','errors');
    $msg = "Import complete — {$inserted} inserted, {$updated} updated, {$skipped} skipped.";
}

// ── Clear CSV preview ──────────────────────────────────────────────────────
if (isset($_GET['clear_csv'])) {
    unset($_SESSION['csv_preview'], $_SESSION['csv_headers'], $_SESSION['csv_filename']);
    header("Location: growers.php"); exit;
}

// ── SEARCH / LIST ──────────────────────────────────────────────────────────
$search = $conn->real_escape_string(trim($_GET['q'] ?? ''));
$where  = "selling_pointid=$spid";
if ($search) $where .= " AND (grower_num LIKE '%$search%' OR name LIKE '%$search%' OR surname LIKE '%$search%' OR national_id LIKE '%$search%' OR farm_name LIKE '%$search%')";

$page_num  = max(1, (int)($_GET['p'] ?? 1));
$per_page  = 100;
$offset    = ($page_num - 1) * $per_page;
$total     = (int)$conn->query("SELECT COUNT(*) c FROM growers WHERE $where")->fetch_assoc()['c'];
$growers   = $conn->query("SELECT * FROM growers WHERE $where ORDER BY grower_num ASC LIMIT $per_page OFFSET $offset");
$pages     = ceil($total / $per_page);

// DB columns for mapping UI
$db_fields = [
    'grower_num'  => 'Grower Number *',
    'name'        => 'First Name *',
    'surname'     => 'Surname',
    'national_id' => 'National ID',
    'phone'       => 'Phone',
    'contractor'  => 'Contractor',
    'farm_name'   => 'Farm Name',
    'province'    => 'Province',
    'district'    => 'District',
    'address1'    => 'Address 1',
    'address2'    => 'Address 2',
    'dry_land_ha' => 'Dry Land Ha',
    'irr_land_ha' => 'Irrigated Ha',
    'dryland'     => 'Dryland',
];

// Auto-map: try to match CSV headers to DB fields by similarity
function auto_map($csv_headers, $db_fields) {
    $map = [];
    $aliases = [
        'grower_num'  => ['grower_num','grower_number','growerno','grower no','grower#','grower_no','number'],
        'name'        => ['name','first_name','firstname','first name','given_name'],
        'surname'     => ['surname','last_name','lastname','last name','family_name'],
        'national_id' => ['national_id','national_id','id_number','id number','id_no','nationalid','nat_id'],
        'phone'       => ['phone','mobile','cell','telephone','contact','phone_number'],
        'contractor'  => ['contractor','contract','company'],
        'farm_name'   => ['farm_name','farm','farm name','farmname'],
        'province'    => ['province','prov'],
        'district'    => ['district','dist'],
        'address1'    => ['address1','address_1','address','addr1','addr'],
        'address2'    => ['address2','address_2','addr2'],
        'dry_land_ha' => ['dry_land_ha','dry_land','dryland_ha','dry ha','dry land'],
        'irr_land_ha' => ['irr_land_ha','irr_land','irrigated_ha','irr ha','irrigated','irr land'],
        'dryland'     => ['dryland','dry_land_type'],
    ];
    foreach ($db_fields as $db_col => $label) {
        $map[$db_col] = '';
        $candidates = $aliases[$db_col] ?? [$db_col];
        foreach ($csv_headers as $h) {
            $hn = strtolower(trim($h));
            foreach ($candidates as $c) {
                if ($hn === strtolower($c) || $hn === strtolower(str_replace(' ','_',$c))) {
                    $map[$db_col] = $h;
                    break 2;
                }
            }
        }
    }
    return $map;
}

$auto_map = !empty($csv_headers) ? auto_map($csv_headers, $db_fields) : [];

include "includes/layout.php";
?>


<style>
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
    background: var(--ink);
    color: var(--gold-l);
    box-shadow: 0 4px 12px rgba(26, 18, 8, 0.15);
    font-weight: 600;
}
body.theme-light .premium-tab-btn.active {
    background: var(--ink);
    color: var(--gold-l);
}
body.theme-dark .premium-tab-btn.active {
    background: var(--gold);
    color: var(--ink);
    box-shadow: 0 4px 12px rgba(200, 149, 42, 0.2);
}

.registry-tab-panel {
    display: none;
}
.registry-tab-panel.active {
    display: block;
    animation: registryFadeIn 0.25s ease-in-out;
}

@keyframes registryFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.registry-form-container {
    max-width: 800px;
    margin: 0 auto;
}
</style>

<div class="page-header">
    <div class="page-header-left">
        <h2>Grower Registry</h2>
        <p>
        <?= number_format($total) ?> growers at
        <strong><?= htmlspecialchars($_SESSION['selling_point_name'] ?? 'Unknown') ?></strong>
        <?php if (!$spid): ?>
        <span style="color:var(--danger);font-weight:500"> — WARNING: No selling point in session</span>
        <?php endif; ?>
    </p>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($import_results): ?>
<div class="card mb-3" style="border-top:3px solid var(--leaf)">
    <div class="card-head"><h3>Import Results</h3></div>
    <div class="card-body">
        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:<?= !empty($import_results['errors'])?'1rem':'0' ?>">
            <div><span style="font-family:'Bebas Neue',sans-serif;font-size:2rem;color:var(--leaf)"><?= $import_results['inserted'] ?></span> <span class="text-muted">inserted</span></div>
            <div><span style="font-family:'Bebas Neue',sans-serif;font-size:2rem;color:var(--gold)"><?= $import_results['updated'] ?></span> <span class="text-muted">updated</span></div>
            <div><span style="font-family:'Bebas Neue',sans-serif;font-size:2rem;color:var(--muted)"><?= $import_results['skipped'] ?></span> <span class="text-muted">skipped</span></div>
        </div>
        <?php if (!empty($import_results['errors'])): ?>
        <div style="background:var(--danger-bg);border:1px solid #f5b8b2;border-radius:var(--radius);padding:0.75rem;max-height:160px;overflow-y:auto;font-size:0.78rem;color:var(--danger)">
            <?php foreach ($import_results['errors'] as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="list" onclick="switchRegistryTab('list')">
        👥 Grower List
    </button>
    <button class="premium-tab-btn" data-tab="add" onclick="switchRegistryTab('add')">
        ➕ Register Grower
    </button>
    <button class="premium-tab-btn" data-tab="csv" onclick="switchRegistryTab('csv')">
        📥 Bulk CSV Import
    </button>
</div>

<!-- Tab Panel 1: Grower List -->
<div id="panel-list" class="registry-tab-panel active">
    <!-- Search + Export all -->
    <div class="card mb-3">
        <div class="card-body" style="padding:0.65rem 1.15rem; display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap">
            <form method="GET" style="display:flex;gap:0.6rem;align-items:center;flex:1">
                <input type="text" name="q" class="form-control" style="max-width:380px"
                       placeholder="Search grower #, name, ID, farm..."
                       value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-primary">Search</button>
                <?php if ($search): ?><a href="grower_registry.php" class="btn btn-ghost">Clear</a><?php endif; ?>
                <span class="text-muted" style="font-size:0.78rem;margin-left:0.5rem"><?= number_format($total) ?> total</span>
            </form>
            <a href="?download_all=1" class="btn btn-ghost btn-sm">⬇ Export All Growers</a>
        </div>
    </div>

    <!-- Grower List Table -->
    <div class="card">
        <div class="card-head">
            <h3>Grower List</h3>
            <div style="display:flex;gap:0.5rem;align-items:center">
                <?php if ($pages > 1): ?>
                <span class="badge badge-gray">Page <?= $page_num ?> of <?= $pages ?></span>
                <?php endif; ?>
                <span class="badge badge-gold"><?= number_format($total) ?></span>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Grower #</th>
                        <th>Name</th>
                        <th>National ID</th>
                        <th>Phone</th>
                        <th>Farm</th>
                        <th>Province</th>
                        <th>Contractor</th>
                        <th class="text-right">Dry Ha</th>
                        <th class="text-right">Irr Ha</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($growers && $growers->num_rows > 0): $row_n = $offset + 1; while ($r = $growers->fetch_assoc()): ?>
                <tr>
                    <td class="td-muted" style="font-size:0.72rem"><?= $row_n++ ?></td>
                    <td><span class="badge badge-gold"><?= htmlspecialchars($r['grower_num']) ?></span></td>
                    <td>
                        <div class="fw-500"><?= htmlspecialchars($r['name'].' '.$r['surname']) ?></div>
                        <?php if ($r['farm_name']): ?>
                        <div class="td-muted" style="font-size:0.7rem"><?= htmlspecialchars($r['farm_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="td-muted"><?= htmlspecialchars($r['national_id']) ?></td>
                    <td class="td-muted"><?= htmlspecialchars($r['phone']) ?></td>
                    <td><?= htmlspecialchars($r['farm_name']) ?></td>
                    <td class="td-muted"><?= htmlspecialchars($r['province']) ?></td>
                    <td class="td-muted"><?= htmlspecialchars($r['contractor']) ?></td>
                    <td class="td-mono text-right"><?= htmlspecialchars($r['dry_land_ha']) ?></td>
                    <td class="td-mono text-right"><?= htmlspecialchars($r['irr_land_ha']) ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="10" class="text-center text-muted" style="padding:2rem">
                    <?= $search ? 'No growers matching "'.htmlspecialchars($search).'".' : 'No growers registered yet.' ?>
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <div style="padding:0.85rem 1.15rem;border-top:1px solid var(--border);display:flex;gap:0.4rem;flex-wrap:wrap;align-items:center">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
            <a href="?q=<?= urlencode($search) ?>&p=<?= $p ?>"
               class="btn btn-sm <?= $p == $page_num ? 'btn-primary' : 'btn-ghost' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <span class="text-muted" style="margin-left:0.5rem;font-size:0.75rem">
                Showing <?= number_format($offset+1) ?>–<?= number_format(min($offset+$per_page,$total)) ?> of <?= number_format($total) ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Tab Panel 2: Register Grower -->
<div id="panel-add" class="registry-tab-panel">
    <div class="registry-form-container">
        <div class="card">
            <div class="card-head">
                <div>
                    <h3>Register Single Grower</h3>
                    <div style="font-size:0.72rem;color:var(--muted);margin-top:2px">
                        Selling point: <strong style="color:var(--text-strong)"><?= htmlspecialchars($_SESSION['selling_point_name'] ?? '—') ?></strong>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="form-row cols-3">
                        <div class="form-group"><label>Grower Number *</label><input type="text" name="grower_num" class="form-control" required placeholder="G001234"></div>
                        <div class="form-group"><label>First Name *</label><input type="text" name="name" class="form-control" required></div>
                        <div class="form-group"><label>Surname</label><input type="text" name="surname" class="form-control"></div>
                    </div>
                    <div class="form-row cols-3">
                        <div class="form-group"><label>National ID</label><input type="text" name="national_id" class="form-control"></div>
                        <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control"></div>
                        <div class="form-group"><label>Contractor</label><input type="text" name="contractor" class="form-control"></div>
                    </div>
                    <div class="form-row cols-2">
                        <div class="form-group"><label>Address 1</label><input type="text" name="address1" class="form-control"></div>
                        <div class="form-group"><label>Address 2</label><input type="text" name="address2" class="form-control"></div>
                    </div>
                    <div class="form-row cols-3">
                        <div class="form-group"><label>Farm Name</label><input type="text" name="farm_name" class="form-control"></div>
                        <div class="form-group"><label>Province</label><input type="text" name="province" class="form-control"></div>
                        <div class="form-group"><label>District</label><input type="text" name="district" class="form-control"></div>
                    </div>
                    <div class="form-row cols-3">
                        <div class="form-group"><label>Dry Land (ha)</label><input type="text" name="dry_land_ha" class="form-control" value="0"></div>
                        <div class="form-group"><label>Irrigated (ha)</label><input type="text" name="irr_land_ha" class="form-control" value="0"></div>
                        <div class="form-group"><label>Dryland</label><input type="text" name="dryland" class="form-control"></div>
                    </div>
                    <div class="flex gap-2 mt-2">
                        <button type="submit" class="btn btn-primary">Register Grower</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 3: Bulk CSV Import -->
<div id="panel-csv" class="registry-tab-panel">
    <div class="registry-form-container">
        <?php if (empty($csv_preview)): ?>
        <!-- STEP 1: Upload file -->
        <div class="card">
            <div class="card-head">
                <h3>Upload Growers from CSV</h3>
            </div>
            <div class="card-body">
                <div style="display:flex;align-items:center;gap:0.75rem;background:var(--ink);color:var(--cream);padding:0.75rem 1rem;border-radius:var(--radius);margin-bottom:1rem">
                    <div>
                        <div style="font-size:0.62rem;letter-spacing:0.14em;text-transform:uppercase;color:rgba(232,184,75,0.6)">Importing into selling point</div>
                        <div style="font-family:'Bebas Neue',sans-serif;font-size:1.1rem;color:var(--gold-l);letter-spacing:0.08em">
                            <?= htmlspecialchars($_SESSION['selling_point_name'] ?? 'NOT SET') ?>
                        </div>
                    </div>
                    <?php if (count($_SESSION['accessible_floors'] ?? []) > 1): ?>
                    <div style="margin-left:auto;font-size:0.72rem;color:rgba(245,240,232,0.45)">
                        Use the floor switcher in the topbar to change selling point
                    </div>
                    <?php endif; ?>
                </div>

                <div class="alert alert-gold" style="margin-bottom:1.25rem">
                    <strong>CSV format:</strong> First row must be column headers. Supported delimiters: comma or semicolon.
                    Columns can be in any order — you will map them in the next step.
                    <br><br>
                    <strong>Required columns:</strong> grower_num, name &nbsp;|&nbsp;
                    <a href="?download_template=1" style="color:inherit;font-weight:500;text-decoration:underline">⬇ Download template</a>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="csv_preview">
                    <div style="border:2px dashed var(--border);border-radius:var(--radius);padding:2.5rem;text-align:center;background:var(--cream);cursor:pointer;transition:border-color 0.15s"
                         id="drop-zone"
                         ondragover="event.preventDefault();this.style.borderColor='var(--gold)'"
                         ondragleave="this.style.borderColor='var(--border)'"
                         ondrop="handleDrop(event)">
                        <div style="font-size:2.5rem;margin-bottom:0.5rem">📄</div>
                        <div style="font-weight:500;margin-bottom:0.35rem">Drop CSV file here</div>
                        <div class="text-muted" style="font-size:0.8rem;margin-bottom:1rem">or click to browse</div>
                        <input type="file" name="csvfile" id="csvfile" accept=".csv,.txt"
                               style="display:none" onchange="showFilename(this)">
                        <button type="button" class="btn btn-gold" onclick="document.getElementById('csvfile').click()">
                            Choose File
                        </button>
                        <div id="chosen-file" style="margin-top:0.75rem;font-size:0.82rem;color:var(--leaf);font-weight:500"></div>
                    </div>
                    <div style="margin-top:1rem;display:flex;gap:0.75rem">
                        <button type="submit" class="btn btn-primary" style="flex:1;padding:0.75rem">
                            Preview & Map Columns →
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- STEP 2: Map columns + preview -->
        <div class="card" style="border-top:3px solid var(--gold)">
            <div class="card-head">
                <div>
                    <h3>Step 2 — Map Columns &amp; Confirm Import</h3>
                    <div style="font-size:0.72rem;color:var(--muted);margin-top:2px">
                        Growers will be imported into:
                        <strong style="color:var(--text-strong)"><?= htmlspecialchars($_SESSION['selling_point_name'] ?? '—') ?></strong>
                    </div>
                </div>
                <div style="display:flex;gap:0.5rem;align-items:center">
                    <span class="badge badge-gold"><?= count($csv_preview) ?> rows</span>
                    <a href="?clear_csv=1" class="btn btn-ghost btn-sm">✕ Cancel</a>
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="csv_import">

                    <!-- Column mapping grid -->
                    <div style="margin-bottom:1.5rem">
                        <div class="kpi-label" style="margin-bottom:0.75rem">Map your CSV columns to database fields</div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:0.6rem">
                            <?php foreach ($db_fields as $db_col => $label):
                                $auto = $auto_map[$db_col] ?? '';
                                $required = strpos($label, '*') !== false;
                            ?>
                            <div style="background:var(--cream);border:1px solid var(--border);border-radius:var(--radius);padding:0.6rem 0.75rem">
                                <div style="font-size:0.65rem;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:<?= $required?'var(--gold)':'var(--muted)' ?>;margin-bottom:0.35rem">
                                    <?= htmlspecialchars($label) ?>
                                </div>
                                <select name="col_map[<?= $db_col ?>]" class="form-control" style="padding:0.3rem 0.5rem;font-size:0.78rem">
                                    <option value="">— skip —</option>
                                    <?php foreach ($csv_headers as $h): ?>
                                    <option value="<?= htmlspecialchars($h) ?>" <?= $auto===$h?'selected':'' ?>>
                                        <?= htmlspecialchars($h) ?>
                                        <?php
                                        // Show a sample value
                                        $sample = $csv_preview[0][$h] ?? '';
                                        if ($sample) echo ' — '.htmlspecialchars(substr($sample,0,20));
                                        ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Selling point confirmation -->
                    <div style="background:var(--ink);color:var(--cream);border-radius:var(--radius);padding:0.6rem 1rem;margin-bottom:1rem;display:flex;align-items:center;gap:0.6rem">
                        <div style="width:8px;height:8px;border-radius:50%;background:var(--gold);flex-shrink:0"></div>
                        <span style="font-size:0.8rem">
                            All <?= count($csv_preview) ?> growers will be assigned to
                            <strong style="color:var(--gold-l)"><?= htmlspecialchars($_SESSION['selling_point_name'] ?? '—') ?></strong>
                        </span>
                    </div>

                    <!-- Duplicate handling -->
                    <div style="background:var(--cream);border:1px solid var(--border);border-radius:var(--radius);padding:0.85rem 1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap">
                        <div class="kpi-label" style="margin-bottom:0">If grower number already exists:</div>
                        <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;font-size:0.85rem">
                            <input type="radio" name="on_duplicate" value="skip" checked> Skip (keep existing)
                        </label>
                        <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;font-size:0.85rem">
                            <input type="radio" name="on_duplicate" value="update"> Update (overwrite existing)
                        </label>
                    </div>

                    <!-- Preview table -->
                    <div class="kpi-label" style="margin-bottom:0.5rem">
                        Preview — first <?= min(5, count($csv_preview)) ?> of <?= count($csv_preview) ?> rows
                    </div>
                    <div class="table-wrap" style="margin-bottom:1.25rem;max-height:220px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius)">
                        <table>
                            <thead>
                                <tr>
                                    <?php foreach ($csv_headers as $h): ?>
                                    <th><?= htmlspecialchars($h) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($csv_preview, 0, 5) as $row): ?>
                                <tr>
                                    <?php foreach ($csv_headers as $h): ?>
                                    <td style="font-size:0.75rem"><?= htmlspecialchars($row[$h] ?? '') ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="display:flex;gap:0.75rem;align-items:center">
                        <button type="submit" class="btn btn-primary" style="padding:0.75rem 2rem"
                                onclick="return confirm('Import <?= count($csv_preview) ?> growers into the database?')">
                            ✓ Import <?= count($csv_preview) ?> Growers
                        </button>
                        <a href="?clear_csv=1" class="btn btn-ghost">← Start Over</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function switchRegistryTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.registry-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_registry_tab', tabId);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    <?php if ($err || !empty($_POST['action']) && $_POST['action'] === 'add'): ?>
    const defaultTab = 'add';
    <?php elseif (!empty($csv_preview) || isset($_GET['fm_sync'])): ?>
    const defaultTab = 'csv';
    <?php else: ?>
    const defaultTab = sessionStorage.getItem('active_registry_tab') || 'list';
    <?php endif; ?>
    
    switchRegistryTab(defaultTab);
});

function showFilename(input) {
    const el = document.getElementById('chosen-file');
    el.textContent = input.files[0] ? '✓ ' + input.files[0].name + ' (' + (input.files[0].size/1024).toFixed(1) + ' KB)' : '';
}

function handleDrop(event) {
    event.preventDefault();
    document.getElementById('drop-zone').style.borderColor = 'var(--border)';
    const file = event.dataTransfer.files[0];
    if (!file) return;
    const input = document.getElementById('csvfile');
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    showFilename(input);
}
</script>

<?php include "includes/layout_end.php"; ?>
