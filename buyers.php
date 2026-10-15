<?php
mysqli_report(MYSQLI_REPORT_OFF);
ob_start();
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Buyers";
$active_page = "buyers";

$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);

// ── Helper: parse single-column CSV ───────────────────────────────────────────
function parse_csv_single_col($filepath) {
    $rows = [];
    if (!file_exists($filepath) || filesize($filepath) === 0) return [];
    $raw = file_get_contents($filepath);
    if ($raw === false) return [];
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $raw = preg_replace('/^\xFF\xFE|^\xFE\xFF/', '', $raw);
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = explode("\n", $raw);
    $header_keywords = ['grade','grades','description','timb','code','grading'];
    $first = true;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $cols = str_getcsv($line, ',');
        if (count($cols) <= 1) $cols = str_getcsv($line, ';');
        $val = '';
        foreach ($cols as $col) { $col = trim($col, " \t\"'"); if ($col !== '') { $val = $col; break; } }
        if ($val === '') continue;
        if ($first) {
            $first = false;
            if (in_array(strtolower($val), $header_keywords)) continue;
            if (strlen($val) > 20 && !preg_match('/^[A-Z0-9\-\/]+$/', $val)) continue;
        }
        $rows[] = $val;
    }
    return $rows;
}

// ── CSV template downloads ─────────────────────────────────────────────────────
if (isset($_GET['dl'])) {
    if ($_GET['dl'] === 'buyer_grades') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="buyer_grades_template.csv"');
        echo "grade\nA1\nA2\nB1\nB2\nC1\nC2\nD\n"; exit;
    }
    if ($_GET['dl'] === 'timb_grades') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="timb_grades_template.csv"');
        echo "grade\nF1L\nF2L\nF1H\nS1L\nS2L\nB1K\nB2K\n"; exit;
    }
}

// ── AJAX: load buyer detail + grades ──────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buyer') {
    ob_end_clean(); header('Content-Type: application/json');
    $bid = (int)($_GET['id'] ?? 0);
    if (!$bid) { echo json_encode(['error' => 'No ID']); exit; }
    $bq = $conn->query("SELECT * FROM buyer WHERE id=$bid LIMIT 1");
    $b  = ($bq && $bq !== true) ? $bq->fetch_assoc() : null;
    if (!$b) { echo json_encode(['error' => 'Buyer not found']); exit; }
    $gq     = $conn->query("SELECT * FROM buyer_grades WHERE buyerid=$bid ORDER BY description");
    $grades = ($gq && $gq !== true) ? $gq->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['ok' => true, 'buyer' => $b, 'grades' => $grades]);
    exit;
}

// ── AJAX POST handlers (JSON response) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    ob_end_clean(); header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'add_buyer_mark') {
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $code = $conn->real_escape_string(trim($_POST['buyer_code'] ?? ''));
        if (!$name || !$code) { echo json_encode(['error' => 'Name and code required']); exit; }
        
        $dup = $conn->query("SELECT id FROM buyer WHERE buyer_code='$code' AND selling_pointid=$spid LIMIT 1");
        if ($dup && $dup->num_rows) { echo json_encode(['error' => "Buyer mark '$code' already exists on this floor."]); exit; }
        
        if ($conn->query("INSERT INTO buyer (selling_pointid,name,buyer_code) VALUES ($spid,'$name','$code')")) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => 'DB: ' . $conn->error]);
        }
        exit;
    }

    if ($action === 'edit_buyer') {
        $bid  = (int)($_POST['buyerid'] ?? 0);
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $code = $conn->real_escape_string(trim($_POST['buyer_code'] ?? ''));
        if (!$bid || !$name || !$code) { echo json_encode(['error' => 'Name and code required']); exit; }
        $dup = $conn->query("SELECT id FROM buyer WHERE buyer_code='$code' AND id!=$bid AND selling_pointid=$spid LIMIT 1");
        if ($dup && $dup->num_rows) { echo json_encode(['error' => "Code '$code' is already used by another buyer"]); exit; }
        $conn->query("UPDATE buyer SET name='$name', buyer_code='$code' WHERE id=$bid");
        echo json_encode(['ok' => true, 'name' => htmlspecialchars($name), 'code' => htmlspecialchars($code)]);
        exit;
    }

    if ($action === 'delete_buyer') {
        $bid = (int)($_POST['buyerid'] ?? 0);
        if (!$bid) { echo json_encode(['error' => 'No buyer']); exit; }
        $uq   = $conn->query("SELECT COUNT(*) c FROM sold_bales sb JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid WHERE bg.buyerid=$bid");
        $used = ($uq && $uq !== true) ? (int)$uq->fetch_assoc()['c'] : 0;
        if ($used > 0) { echo json_encode(['error' => "Cannot delete — buyer is referenced in $used sold bale record(s)"]); exit; }
        $conn->query("DELETE FROM buyer_grades WHERE buyerid=$bid");
        $conn->query("DELETE FROM buyer WHERE id=$bid");
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'add_grade') {
        $bid  = (int)($_POST['buyerid'] ?? 0);
        $desc = $conn->real_escape_string(strtoupper(trim($_POST['description'] ?? '')));
        if (!$bid || !$desc) { echo json_encode(['error' => 'Buyer and grade code required']); exit; }
        $dup = $conn->query("SELECT id FROM buyer_grades WHERE buyerid=$bid AND description='$desc' LIMIT 1");
        if ($dup && $dup->num_rows) { echo json_encode(['error' => "Grade '$desc' already exists"]); exit; }
        $conn->query("INSERT INTO buyer_grades (buyerid,description) VALUES ($bid,'$desc')");
        $newid = (int)$conn->insert_id;
        echo json_encode(['ok' => true, 'id' => $newid, 'description' => $desc, 'active' => 1]);
        exit;
    }

    if ($action === 'toggle_grade') {
        $gid = (int)($_POST['gid'] ?? 0);
        $a   = (int)($_POST['active'] ?? 1);
        $conn->query("UPDATE buyer_grades SET active=$a WHERE id=$gid");
        echo json_encode(['ok' => true, 'active' => $a]);
        exit;
    }

    if ($action === 'delete_grade') {
        $gid = (int)($_POST['gid'] ?? 0);
        // Check usage
        $uq   = $conn->query("SELECT COUNT(*) c FROM sold_bales WHERE buyer_gradesid=$gid");
        $used = ($uq && $uq !== true) ? (int)$uq->fetch_assoc()['c'] : 0;
        if ($used > 0) { echo json_encode(['error' => "Grade used in $used bale(s) — cannot delete"]); exit; }
        $conn->query("DELETE FROM buyer_grades WHERE id=$gid");
        echo json_encode(['ok' => true]);
        exit;
    }

    // TIMB grade actions (AJAX-compatible)
    if ($action === 'toggle_timb') {
        $tid = (int)($_POST['tid'] ?? 0); $a = (int)($_POST['active'] ?? 1);
        $conn->query("UPDATE timb_grades SET active=$a WHERE id=$tid");
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'delete_timb') {
        $tid  = (int)($_POST['tid'] ?? 0);
        $used = (int)$conn->query("SELECT COUNT(*) c FROM sold_bales WHERE timb_gradesid=$tid")->fetch_assoc()['c'];
        if ($used) { echo json_encode(['error' => "Grade used in $used bale(s)"]); exit; }
        $conn->query("DELETE FROM timb_grades WHERE id=$tid");
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'rename_timb') {
        $tid  = (int)($_POST['tid'] ?? 0);
        $desc = $conn->real_escape_string(strtoupper(trim($_POST['description'] ?? '')));
        if (!$tid || !$desc) { echo json_encode(['error' => 'Code required']); exit; }
        $dup = $conn->query("SELECT id FROM timb_grades WHERE description='$desc' AND id!=$tid LIMIT 1");
        if ($dup && $dup->num_rows) { echo json_encode(['error' => "Grade '$desc' already exists"]); exit; }
        $conn->query("UPDATE timb_grades SET description='$desc' WHERE id=$tid");
        echo json_encode(['ok' => true, 'description' => $desc]);
        exit;
    }
    if ($action === 'add_timb') {
        $desc = $conn->real_escape_string(strtoupper(trim($_POST['description'] ?? '')));
        if (!$desc) { echo json_encode(['error' => 'Code required']); exit; }
        $dup = $conn->query("SELECT id FROM timb_grades WHERE description='$desc' LIMIT 1");
        if ($dup && $dup->num_rows) { echo json_encode(['error' => "Grade '$desc' already exists"]); exit; }
        $conn->query("INSERT INTO timb_grades (description) VALUES ('$desc')");
        $newid = (int)$conn->insert_id;
        echo json_encode(['ok' => true, 'id' => $newid, 'description' => $desc]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']); exit;
}

// ── Standard POST handlers (CSV upload — needs page reload) ───────────────────
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $reopen  = (int)($_POST['reopen_buyerid'] ?? 0); // re-open modal after reload

    if ($action === 'add_buyer') {
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $code = $conn->real_escape_string(trim($_POST['buyer_code'] ?? ''));
        if (!$name || !$code) { $err = "Name and buyer code required."; }
        else {
            $dup = $conn->query("SELECT id FROM buyer WHERE buyer_code='$code' AND selling_pointid=$spid LIMIT 1");
            if ($dup && $dup->num_rows) { $err = "Buyer code '$code' already exists."; }
            elseif ($conn->query("INSERT INTO buyer (selling_pointid,name,buyer_code) VALUES ($spid,'$name','$code')"))
                $msg = "Buyer '$name' added.";
            else $err = "DB: " . $conn->error;
        }
    }

    if ($action === 'csv_buyer_grades_preview') {
        $buyerid = (int)($_POST['buyerid_for_csv'] ?? 0);
        $reopen  = $buyerid;
        if (!$buyerid) { $err = "Select a buyer before uploading grades."; }
        elseif (!empty($_FILES['csvfile']['tmp_name']) && $_FILES['csvfile']['error'] === UPLOAD_ERR_OK) {
            $tmp  = $_FILES['csvfile']['tmp_name'];
            $rows = parse_csv_single_col($tmp);
            if (empty($rows)) {
                $err = "No grades found in CSV. Check the file has one grade per row.";
            } else {
                $_SESSION['bg_csv']     = $rows;
                $_SESSION['bg_buyerid'] = $buyerid;
                $msg = count($rows) . " grades read — review and confirm import.";
            }
        } else { $err = "No file received."; }
    }

    if ($action === 'csv_buyer_grades_import') {
        $rows    = $_SESSION['bg_csv']     ?? [];
        $buyerid = $_SESSION['bg_buyerid'] ?? 0;
        $reopen  = $buyerid;
        $on_dup  = $_POST['on_duplicate']  ?? 'skip';
        if (!$buyerid || empty($rows)) { $err = "Nothing to import."; }
        else {
            $ins = $skip = $upd = 0;
            foreach ($rows as $desc) {
                $d = $conn->real_escape_string(strtoupper(trim($desc)));
                if (!$d) continue;
                $dup = $conn->query("SELECT id FROM buyer_grades WHERE buyerid=$buyerid AND description='$d' LIMIT 1")->fetch_assoc();
                if ($dup) {
                    if ($on_dup === 'update') { $conn->query("UPDATE buyer_grades SET active=1 WHERE id={$dup['id']}"); $upd++; }
                    else { $skip++; }
                } else { $conn->query("INSERT INTO buyer_grades (buyerid,description) VALUES ($buyerid,'$d')"); $ins++; }
            }
            unset($_SESSION['bg_csv'], $_SESSION['bg_buyerid']);
            $msg = "Import done: $ins added, $upd re-activated, $skip skipped.";
        }
    }

    if ($action === 'clear_bg_csv') { unset($_SESSION['bg_csv'], $_SESSION['bg_buyerid']); }

    // CSV timb
    if ($action === 'csv_timb_preview') {
        if (!empty($_FILES['timbfile']['tmp_name']) && $_FILES['timbfile']['error'] === UPLOAD_ERR_OK) {
            $rows = parse_csv_single_col($_FILES['timbfile']['tmp_name']);
            if (empty($rows)) { $err = "No grades found in CSV."; }
            else { $_SESSION['timb_csv'] = $rows; $msg = count($rows) . " TIMB grades read — confirm import."; }
        } else { $err = "No file received."; }
    }
    if ($action === 'csv_timb_import') {
        $rows   = $_SESSION['timb_csv']  ?? [];
        $on_dup = $_POST['on_duplicate'] ?? 'skip';
        if (empty($rows)) { $err = "Nothing to import."; }
        else {
            $ins = $skip = $upd = 0;
            foreach ($rows as $desc) {
                $d = $conn->real_escape_string(strtoupper(trim($desc)));
                if (!$d) continue;
                $dup = $conn->query("SELECT id FROM timb_grades WHERE description='$d' LIMIT 1")->fetch_assoc();
                if ($dup) {
                    if ($on_dup === 'update') { $conn->query("UPDATE timb_grades SET active=1 WHERE id={$dup['id']}"); $upd++; }
                    else { $skip++; }
                } else { $conn->query("INSERT INTO timb_grades (description) VALUES ('$d')"); $ins++; }
            }
            unset($_SESSION['timb_csv']);
            $msg = "TIMB import: $ins added, $upd re-activated, $skip skipped.";
        }
    }
    if ($action === 'clear_timb_csv') { unset($_SESSION['timb_csv']); }
}

// ── Load page data ─────────────────────────────────────────────────────────────
$buyers_q = $conn->query("
    SELECT b.id, b.name, b.buyer_code,
           COUNT(bg.id)          AS total_grades,
           COALESCE(SUM(bg.active),0) AS active_grades
    FROM buyer b
    LEFT JOIN buyer_grades bg ON bg.buyerid = b.id
    WHERE b.selling_pointid = $spid
    GROUP BY b.id, b.name, b.buyer_code
    ORDER BY b.name
");
$buyers = ($buyers_q && $buyers_q !== true) ? $buyers_q->fetch_all(MYSQLI_ASSOC) : [];

$timb_q = $conn->query("SELECT * FROM timb_grades ORDER BY description");
$timb_all = ($timb_q && $timb_q !== true) ? $timb_q->fetch_all(MYSQLI_ASSOC) : [];

// TIMB usage counts
$usage = [];
$uq = $conn->query("SELECT timb_gradesid, COUNT(*) n FROM sold_bales WHERE timb_gradesid IS NOT NULL GROUP BY timb_gradesid");
if ($uq && $uq !== true) while ($u = $uq->fetch_assoc()) $usage[(int)$u['timb_gradesid']] = (int)$u['n'];

// Session CSV previews
$bg_preview   = $_SESSION['bg_csv']   ?? [];
$bg_buyerid   = $_SESSION['bg_buyerid'] ?? 0;
$timb_preview = $_SESSION['timb_csv'] ?? [];

// Buyer name for CSV preview header
$bg_buyer_name = '';
if ($bg_buyerid) {
    $bn = $conn->query("SELECT name FROM buyer WHERE id=$bg_buyerid LIMIT 1");
    if ($bn && $bn !== true && $bn->num_rows) $bg_buyer_name = $bn->fetch_assoc()['name'];
}

// Auto-reopen modal if CSV action just ran
$reopen_buyerid = (int)($reopen ?? 0);

include "includes/layout.php";
?>

<style>
/* ── Buyers table ── */
#buyers-table tbody tr { cursor:pointer; transition:background .1s; }
#buyers-table tbody tr:hover td { background:rgba(200,149,42,.07); }
#buyers-table td { font-size:.74rem; padding:.45rem .7rem; white-space:nowrap; }
#buyers-table th { font-size:.64rem; padding:.42rem .7rem; white-space:nowrap; }
.up-dot { display:inline-flex;align-items:center;gap:.3rem;font-size:.68rem;font-weight:500; }
.up-yes { color:#3a9a3a; }
.up-no  { color:var(--muted); }

/* ── Buyer modal ── */
#bmo {
  display:none; position:fixed; inset:0; z-index:9000;
  background:rgba(10,7,3,.72); backdrop-filter:blur(3px);
  overflow-y:auto; padding:2rem 1rem;
  animation:bmo-in .18s ease;
}
@keyframes bmo-in{from{opacity:0}to{opacity:1}}
#bmb {
  background:#fff; border-radius:14px;
  max-width:780px; margin:0 auto;
  box-shadow:0 24px 64px rgba(0,0,0,.4), 0 0 0 1px rgba(200,149,42,.12);
  overflow:hidden; animation:bmb-in .2s cubic-bezier(.16,1,.3,1);
}
@keyframes bmb-in{from{opacity:0;transform:translateY(16px) scale(.98)}to{opacity:1;transform:none}}
.bm-head {
  background:linear-gradient(135deg,#1a1208 0%,#2d2010 100%);
  padding:.95rem 1.25rem; display:flex; align-items:center; gap:.85rem;
  border-bottom:2px solid rgba(200,149,42,.25);
}
#bm-name { font-family:'Bebas Neue',sans-serif; font-size:1.35rem; color:#c8952a; letter-spacing:.1em; line-height:1; }
#bm-code-badge { padding:.18rem .55rem; border-radius:6px; background:rgba(200,149,42,.18);
                  border:1px solid rgba(200,149,42,.3); color:#c8952a; font-size:.72rem;
                  font-family:monospace; font-weight:600; letter-spacing:.04em; }
#bm-close { background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15);
             color:rgba(245,240,232,.65); width:30px; height:30px; border-radius:7px;
             font-size:1.1rem; cursor:pointer; flex-shrink:0; line-height:1; transition:.15s; }
#bm-close:hover { background:rgba(255,255,255,.2); color:#fff; }

/* Edit buyer inline */
#bm-edit-bar { background:#fdf6e8; border-bottom:1px solid #e8d8a0; padding:.7rem 1.25rem;
               display:none; }
.bm-section { padding:.9rem 1.25rem; border-bottom:1px solid #f0ebe0; }
.bm-section:last-of-type { border-bottom:none; }
.bm-section-title { font-size:.68rem; font-weight:700; text-transform:uppercase;
                     letter-spacing:.07em; color:#9a7830; margin-bottom:.65rem; }

/* Grades table inside modal */
#bm-grades-table { width:100%; border-collapse:collapse; font-size:.76rem; }
#bm-grades-table th { background:#f8f5ef; color:#5a4820; font-size:.62rem; font-weight:600;
                       text-transform:uppercase; letter-spacing:.05em; padding:.4rem .65rem;
                       border-bottom:2px solid #e8dfc8; }
#bm-grades-table td { padding:.38rem .65rem; border-bottom:1px solid #f4efe4; color:#2a1e08; }
#bm-grades-table tr:last-child td { border-bottom:none; }
#bm-grades-table tr:hover td { background:#fdf6e8; }
.grade-code { font-family:'Bebas Neue',monospace; font-size:1.05rem; letter-spacing:.06em; color:#c8952a; }
.grade-code.inactive { color:#aaa; }

/* CSV preview box */
#bm-csv-prev { background:#fdf6e8; border:1px solid #e8d8a0; border-radius:8px;
               padding:.75rem 1rem; margin-bottom:.75rem; }

/* Footer */
#bm-foot { padding:.8rem 1.25rem; background:#f8f5ef; border-top:2px solid #e8dfc8;
            display:flex; align-items:center; gap:.65rem; flex-wrap:wrap; }

/* Toast */
#bm-toast { position:fixed; bottom:1.5rem; left:50%; transform:translateX(-50%);
             background:#1a1208; color:#f0ebe0; padding:.6rem 1.2rem; border-radius:8px;
             font-size:.82rem; font-weight:500; box-shadow:0 6px 20px rgba(0,0,0,.35);
             z-index:9999; display:none; animation:toast-in .2s ease; white-space:nowrap; }
@keyframes toast-in{from{opacity:0;transform:translateX(-50%) translateY(8px)}to{opacity:1;transform:translateX(-50%)}}

/* TIMB table */
.tbl-timb td { font-size:.72rem; padding:.4rem .65rem; }
.tbl-timb th { font-size:.63rem; padding:.4rem .65rem; }

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

.buyers-tab-panel {
    display: none;
}
.buyers-tab-panel.active {
    display: block;
    animation: buyersFadeIn 0.25s ease-in-out;
}

@keyframes buyersFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.buyers-centered-container {
    max-width: 820px;
    margin: 0 auto;
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Buyers &amp; Grades</h2>
    <p><?= count($buyers) ?> buyer<?= count($buyers)!=1?'s':'' ?> · click a row to manage</p>
  </div>
  <button class="btn btn-primary" onclick="openAddBuyer()">+ Add Buyer</button>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err)  ?></div><?php endif; ?>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
  <button class="premium-tab-btn active" data-tab="list" onclick="switchBuyersTab('list')">
      🏢 Buyers List
  </button>
  <button class="premium-tab-btn" data-tab="timb" onclick="switchBuyersTab('timb')">
      📋 TIMB Grades <span class="badge badge-gray" style="font-size:.62rem; margin-left:4px"><?= count($timb_all) ?></span>
  </button>
</div>

<!-- Tab Panel 1: Buyers list -->
<div id="panel-list" class="buyers-tab-panel active">
  <div class="buyers-centered-container">
    <div class="card">
      <div class="card-head">
        <h3>Buyers</h3>
        <div style="display:flex;align-items:center;gap:.5rem">
          <input type="text" id="buyer-search" placeholder="Search…" oninput="filterBuyers(this.value)"
                 class="form-control" style="width:180px;font-size:.8rem;padding:.3rem .6rem">
          <span class="badge badge-gray"><?= count($buyers) ?></span>
        </div>
      </div>
      <div class="table-wrap">
      <table id="buyers-table">
        <thead><tr>
          <th>#</th>
          <th>Buyer Name</th>
          <th>Buyer Code</th>
          <th class="text-right">Total Grades</th>
          <th class="text-right">Active</th>
          <th>Grades Uploaded</th>
          <th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($buyers)): ?>
        <tr><td colspan="7" class="text-center text-muted" style="padding:2.5rem">
            No buyers yet. Click "+ Add Buyer" to get started.</td></tr>
        <?php else: $seq=0; foreach ($buyers as $r): $seq++;
            $has_grades = (int)$r['total_grades'] > 0;
        ?>
        <tr data-id="<?= $r['id'] ?>"
            data-search="<?= strtolower(htmlspecialchars($r['name'].' '.$r['buyer_code'], ENT_QUOTES)) ?>"
            onclick="openBuyer(<?= $r['id'] ?>)">
          <td class="td-muted" style="font-size:.68rem"><?= $seq ?></td>
          <td class="fw-500" style="color:var(--text-strong)"><?= htmlspecialchars($r['name']) ?></td>
          <td><span class="badge badge-blue" style="font-size:.65rem;letter-spacing:.04em"><?= htmlspecialchars($r['buyer_code']) ?></span></td>
          <td class="td-mono text-right"><?= $r['total_grades'] > 0 ? '<strong>'.(int)$r['total_grades'].'</strong>' : '<span class="td-muted">0</span>' ?></td>
          <td class="td-mono text-right" style="color:var(--leaf)"><?= $r['active_grades'] > 0 ? (int)$r['active_grades'] : '<span class="td-muted">—</span>' ?></td>
          <td>
            <?php if ($has_grades): ?>
            <span class="up-dot up-yes">&#10003; Uploaded</span>
            <?php else: ?>
            <span class="up-dot up-no">&#8212; None</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;padding-right:.9rem;color:var(--muted);font-size:.8rem">&#8250;</td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>

<!-- Tab Panel 2: TIMB Grades -->
<div id="panel-timb" class="buyers-tab-panel">
  <div class="buyers-centered-container">
    <?php
    $timb_active   = array_filter($timb_all, fn($r) => $r['active']);
    $timb_inactive = array_filter($timb_all, fn($r) => !$r['active']);
    ?>
    <div class="card">
      <div class="card-head">
        <h3>TIMB Grades</h3>
        <div class="flex gap-2">
          <a href="?dl=timb_grades" class="btn btn-ghost btn-sm">&#8595; Template</a>
          <button class="btn btn-ghost btn-sm" onclick="toggleEl('timb-csv-form')">&#8593; Upload CSV</button>
          <button class="btn btn-gold btn-sm" onclick="toggleEl('timb-add-form')">+ Add Grade</button>
        </div>
      </div>

      <div id="timb-add-form" style="display:none;padding:.75rem 1.15rem;border-bottom:1px solid var(--border);background:var(--cream)">
        <div style="display:flex;gap:.5rem;align-items:flex-end;max-width:380px">
          <div class="form-group" style="flex:1;margin:0">
            <label style="font-size:.72rem">TIMB Grade Code</label>
            <input type="text" id="timb-add-input" class="form-control" placeholder="e.g. F1L, S2H"
                   style="text-transform:uppercase" onkeydown="if(event.key==='Enter'){addTimbGrade();event.preventDefault()}">
          </div>
          <button class="btn btn-primary" onclick="addTimbGrade()">Add</button>
          <button class="btn btn-ghost" onclick="toggleEl('timb-add-form')">Cancel</button>
        </div>
        <div id="timb-add-err" style="font-size:.72rem;color:#c03030;margin-top:.3rem"></div>
      </div>

      <!-- TIMB CSV upload -->
      <div id="timb-csv-form" style="display:<?= !empty($timb_preview)?'block':'none' ?>;border-bottom:1px solid var(--border);background:var(--cream)">
      <?php if (!empty($timb_preview)): ?>
      <div style="padding:.85rem 1.15rem">
        <div style="font-size:.72rem;font-weight:600;margin-bottom:.5rem">Preview — <?= count($timb_preview) ?> TIMB grades to import</div>
        <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-bottom:.75rem;max-height:120px;overflow-y:auto">
          <?php foreach ($timb_preview as $g): ?>
          <span class="badge badge-gold" style="font-size:.7rem"><?= htmlspecialchars(strtoupper($g)) ?></span>
          <?php endforeach; ?>
        </div>
        <form method="POST" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
          <input type="hidden" name="action" value="csv_timb_import">
          <label style="display:flex;align-items:center;gap:.3rem;font-size:.8rem;margin:0">
            <input type="radio" name="on_duplicate" value="skip" checked> Skip duplicates
          </label>
          <label style="display:flex;align-items:center;gap:.3rem;font-size:.8rem;margin:0">
            <input type="radio" name="on_duplicate" value="update"> Re-activate duplicates
          </label>
          <button type="submit" class="btn btn-primary"
                  onclick="return confirm('Import <?= count($timb_preview) ?> TIMB grades?')">
            &#10003; Import <?= count($timb_preview) ?> Grades
          </button>
          <button type="button" class="btn btn-ghost btn-sm" onclick="cancelTimbCsv()">&#10005; Cancel</button>
        </form>
      </div>
      <?php else: ?>
      <div style="padding:.85rem 1.15rem">
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
          <input type="hidden" name="action" value="csv_timb_preview">
          <div class="form-group" style="flex:1;min-width:200px;margin:0">
            <label style="font-size:.72rem">CSV File</label>
            <input type="file" name="timbfile" accept=".csv,.txt" class="form-control" required>
          </div>
          <button type="submit" class="btn btn-primary">Preview &#8594;</button>
          <button type="button" class="btn btn-ghost" onclick="toggleEl('timb-csv-form')">Cancel</button>
        </form>
      </div>
      <?php endif; ?>
      </div>

      <!-- Filter strip -->
      <div style="padding:.5rem 1.15rem;border-bottom:1px solid var(--border);
                  display:flex;align-items:center;gap:.75rem;background:var(--cream-d)">
        <input type="text" id="timb-search" placeholder="Filter grades…" oninput="filterTimb(this.value)"
               class="form-control" style="max-width:200px;padding:.3rem .6rem;font-size:.8rem">
        <span style="font-size:.72rem;color:var(--muted)">
          <span style="color:var(--leaf);font-weight:500"><?= count($timb_active) ?> active</span>
          &nbsp;&middot;&nbsp;<?= count($timb_inactive) ?> inactive
        </span>
        <label style="display:flex;align-items:center;gap:.35rem;font-size:.78rem;cursor:pointer;margin-left:auto">
          <input type="checkbox" id="show-inactive" onchange="filterTimb(document.getElementById('timb-search').value)">
          Show inactive
        </label>
      </div>

      <div class="table-wrap" style="max-height:520px;overflow-y:auto">
      <table id="timb-table" class="tbl-timb">
      <thead><tr>
        <th style="width:36px">#</th>
        <th>Grade Code</th>
        <th class="text-right">Bales Sold</th>
        <th>Status</th>
        <th style="width:150px">Actions</th>
      </tr></thead>
      <tbody id="timb-tbody">
      <?php if (empty($timb_all)): ?>
      <tr><td colspan="5" class="text-center text-muted" style="padding:2rem">No TIMB grades yet.</td></tr>
      <?php else: $seq=0; foreach ($timb_all as $r): $seq++;
          $used   = $usage[(int)$r['id']] ?? 0;
          $active = (int)$r['active'];
      ?>
      <tr data-active="<?= $active ?>" data-grade="<?= strtolower(htmlspecialchars($r['description'])) ?>"
          data-id="<?= $r['id'] ?>">
        <td class="td-muted" style="font-size:.68rem"><?= $seq ?></td>
        <td>
          <span style="font-family:'Bebas Neue',monospace;font-size:1.1rem;letter-spacing:.06em;
                       color:<?= $active?'var(--gold)':'var(--muted)' ?>">
            <?= htmlspecialchars($r['description']) ?>
          </span>
          <?php if (!$active): ?>
          <span class="badge badge-gray" style="font-size:.52rem;margin-left:.35rem">Inactive</span>
          <?php endif; ?>
        </td>
        <td class="td-mono text-right">
          <?= $used > 0 ? '<strong>'.number_format($used).'</strong>' : '<span class="td-muted">—</span>' ?>
        </td>
        <td>
          <?php if ($active): ?>
          <span style="display:flex;align-items:center;gap:.3rem;font-size:.72rem">
            <span style="width:6px;height:6px;border-radius:50%;background:var(--leaf);flex-shrink:0"></span>Active
          </span>
          <?php else: ?>
          <span style="display:flex;align-items:center;gap:.3rem;font-size:.72rem;color:var(--muted)">
            <span style="width:6px;height:6px;border-radius:50%;background:var(--border);flex-shrink:0"></span>Inactive
          </span>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex;gap:.25rem">
            <button class="btn btn-ghost btn-sm" onclick="openTimbRename(<?= $r['id'] ?>,'<?= htmlspecialchars($r['description'],ENT_QUOTES) ?>')" title="Rename">&#9998;</button>
            <button class="btn btn-ghost btn-sm" onclick="toggleTimb(<?= $r['id'] ?>,<?= $active?0:1 ?>,this)" title="<?= $active?'Deactivate':'Activate' ?>">
              <?= $active?'&#9646;&#9646;':'&#9654;' ?>
            </button>
            <?php if ($used === 0): ?>
            <button class="btn btn-danger btn-sm" onclick="deleteTimb(<?= $r['id'] ?>,this)" title="Delete">&#10005;</button>
            <?php else: ?>
            <button class="btn btn-ghost btn-sm" disabled style="opacity:.3;cursor:not-allowed" title="Used in <?= $used ?> bale(s)">&#10005;</button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
      </table>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- ADD BUYER MODAL                                                             -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div id="add-buyer-modal" style="display:none;position:fixed;inset:0;z-index:9100;
     background:rgba(10,7,3,.7);align-items:center;justify-content:center">
  <div style="background:var(--cream);border-radius:12px;padding:1.5rem;width:380px;
              box-shadow:0 16px 48px rgba(0,0,0,.3)">
    <div style="font-weight:600;font-size:1rem;margin-bottom:1rem;color:var(--text-strong)">Add New Buyer</div>
    <form method="POST">
      <input type="hidden" name="action" value="add_buyer">
      <div class="form-group">
        <label>Buyer Name *</label>
        <input type="text" name="name" class="form-control" required autofocus placeholder="e.g. British American Tobacco">
      </div>
      <div class="form-group">
        <label>Buyer Code *</label>
        <input type="text" name="buyer_code" class="form-control" required placeholder="e.g. BAT, TIAN, ZLT"
               style="text-transform:uppercase;font-family:monospace">
      </div>
      <div style="display:flex;gap:.6rem;margin-top:.5rem">
        <button type="submit" class="btn btn-primary">Add Buyer</button>
        <button type="button" class="btn btn-ghost" onclick="closeAddBuyer()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- BUYER DETAIL MODAL                                                          -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div id="bmo" onclick="if(event.target===this)closeBuyer()">
<div id="bmb">

  <!-- Header -->
  <div class="bm-head">
    <div style="flex:1;min-width:0">
      <div id="bm-name">--</div>
      <div style="margin-top:.3rem;display:flex;align-items:center;gap:.5rem">
        <span id="bm-code-badge">--</span>
        <span id="bm-grade-count" style="font-size:.68rem;color:rgba(245,240,232,.45)"></span>
      </div>
    </div>
    <button class="btn btn-ghost btn-sm" onclick="toggleAddMark()" id="bm-add-mark-btn"
            style="font-size:.72rem;background:rgba(255,255,255,.07);border-color:rgba(255,255,255,.15);color:rgba(245,240,232,.7);margin-right:5px">
      + Add Mark
    </button>
    <button class="btn btn-ghost btn-sm" onclick="toggleEditBuyer()" id="bm-edit-btn"
            style="font-size:.72rem;background:rgba(255,255,255,.07);border-color:rgba(255,255,255,.15);color:rgba(245,240,232,.7)">
      &#9998; Edit
    </button>
    <button id="bm-close" onclick="closeBuyer()">&times;</button>
  </div>

  <!-- Edit buyer inline bar -->
  <div id="bm-edit-bar">
    <div style="font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#9a7830;margin-bottom:.5rem">Edit Buyer Details</div>
    <div style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
      <div class="form-group" style="flex:2;min-width:180px;margin:0">
        <label style="font-size:.7rem">Buyer Name</label>
        <input type="text" id="edit-name" class="form-control">
      </div>
      <div class="form-group" style="flex:1;min-width:120px;margin:0">
        <label style="font-size:.7rem">Buyer Code</label>
        <input type="text" id="edit-code" class="form-control" style="text-transform:uppercase;font-family:monospace">
      </div>
      <button class="btn btn-primary btn-sm" onclick="saveEditBuyer()">Save</button>
      <button class="btn btn-ghost btn-sm" onclick="toggleEditBuyer()">Cancel</button>
    </div>
    <div id="edit-err" style="font-size:.71rem;color:#c03030;margin-top:.3rem"></div>
  </div>

  <!-- Add buyer mark inline bar -->
  <div id="bm-add-mark-bar" style="background:#fdf9f0; border-bottom:1px solid #e8d8a0; padding:.7rem 1.25rem; display:none;">
    <div style="font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#9a7830;margin-bottom:.5rem">Add Buyer Mark</div>
    <div style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
      <div class="form-group" style="flex:2;min-width:180px;margin:0">
        <label style="font-size:.7rem">Buyer Name</label>
        <input type="text" id="add-mark-name" class="form-control" readonly disabled>
      </div>
      <div class="form-group" style="flex:1;min-width:120px;margin:0">
        <label style="font-size:.7rem">New Mark / Code</label>
        <input type="text" id="add-mark-code" class="form-control" style="text-transform:uppercase;font-family:monospace" placeholder="e.g. PA3"
               onkeydown="if(event.key==='Enter'){saveAddBuyerMark();event.preventDefault()}">
      </div>
      <button class="btn btn-gold btn-sm" onclick="saveAddBuyerMark()">+ Add Mark</button>
      <button class="btn btn-ghost btn-sm" onclick="toggleAddMark()">Cancel</button>
    </div>
    <div id="add-mark-err" style="font-size:.71rem;color:#c03030;margin-top:.3rem"></div>
  </div>

  <!-- Grades section -->
  <div class="bm-section">
    <div class="bm-section-title">Buyer Grades</div>

    <!-- CSV preview (shown after upload) -->
    <?php if (!empty($bg_preview) && $bg_buyerid): ?>
    <div id="bm-csv-prev">
      <div style="font-size:.73rem;font-weight:600;margin-bottom:.45rem">
        Preview — <?= count($bg_preview) ?> grades to import for <?= htmlspecialchars($bg_buyer_name) ?>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-bottom:.65rem;max-height:100px;overflow-y:auto">
        <?php foreach ($bg_preview as $g): ?>
        <span class="badge badge-gold" style="font-size:.68rem"><?= htmlspecialchars($g) ?></span>
        <?php endforeach; ?>
      </div>
      <form method="POST" style="display:flex;gap:.55rem;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="action" value="csv_buyer_grades_import">
        <input type="hidden" name="reopen_buyerid" value="<?= $bg_buyerid ?>">
        <label style="display:flex;align-items:center;gap:.3rem;font-size:.78rem;margin:0">
          <input type="radio" name="on_duplicate" value="skip" checked> Skip duplicates
        </label>
        <label style="display:flex;align-items:center;gap:.3rem;font-size:.78rem;margin:0">
          <input type="radio" name="on_duplicate" value="update"> Re-activate
        </label>
        <button type="submit" class="btn btn-primary btn-sm"
                onclick="return confirm('Import <?= count($bg_preview) ?> grades?')">
          &#10003; Import <?= count($bg_preview) ?> Grades
        </button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="cancelBgCsv()">&#10005; Cancel</button>
      </form>
    </div>
    <?php endif; ?>

    <!-- Add grade row -->
    <div style="display:flex;gap:.45rem;align-items:flex-end;margin-bottom:.75rem">
      <div class="form-group" style="flex:1;max-width:220px;margin:0">
        <label style="font-size:.68rem">Add Grade Code</label>
        <input type="text" id="new-grade-input" class="form-control"
               placeholder="e.g. A1, B2H, FLR" style="text-transform:uppercase;font-size:.85rem"
               onkeydown="if(event.key==='Enter'){addGrade();event.preventDefault()}">
      </div>
      <button class="btn btn-gold btn-sm" onclick="addGrade()">+ Add</button>
      <button class="btn btn-ghost btn-sm" onclick="toggleEl('bm-csv-section')" style="margin-left:auto">
        &#8593; Upload CSV
      </button>
      <a id="bm-csv-dl" href="#" class="btn btn-ghost btn-sm">&#8595; Template</a>
    </div>
    <div id="add-grade-err" style="font-size:.71rem;color:#c03030;margin-bottom:.3rem;display:none"></div>

    <!-- CSV upload section (hidden by default) -->
    <div id="bm-csv-section" style="display:none;margin-bottom:.75rem;border:1px solid #e8d8a0;
         border-radius:8px;padding:.75rem 1rem;background:#fdf9f0">
      <div style="font-size:.7rem;color:#9a7830;margin-bottom:.5rem">
        One grade per row. First row (header) is skipped automatically.
      </div>
      <form method="POST" enctype="multipart/form-data" style="display:flex;gap:.45rem;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="action" value="csv_buyer_grades_preview">
        <input type="hidden" name="reopen_buyerid" id="csv-reopen-id" value="">
        <input type="hidden" name="buyerid_for_csv" id="csv-buyer-id" value="">
        <div class="form-group" style="flex:1;min-width:180px;margin:0">
          <label style="font-size:.7rem">CSV File</label>
          <input type="file" name="csvfile" accept=".csv,.txt" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Preview &#8594;</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleEl('bm-csv-section')">Cancel</button>
      </form>
    </div>

    <!-- Grades table -->
    <div style="border:1px solid #e8dfc8;border-radius:8px;overflow:hidden">
    <table id="bm-grades-table">
      <thead><tr>
        <th>Grade</th>
        <th>Status</th>
        <th style="width:110px;text-align:right">Actions</th>
      </tr></thead>
      <tbody id="bm-grades-tbody">
        <tr id="bm-grades-empty"><td colspan="3" style="text-align:center;color:#aaa;padding:1.5rem;font-size:.78rem">
          No grades yet — add one above or upload a CSV.
        </td></tr>
      </tbody>
    </table>
    </div>
  </div>

  <!-- Footer -->
  <div id="bm-foot">
    <button class="btn btn-danger btn-sm" onclick="deleteBuyer()" id="bm-del-btn">
      &#128465; Delete Buyer
    </button>
    <button class="btn btn-ghost btn-sm" style="margin-left:auto" onclick="closeBuyer()">Close</button>
  </div>

</div><!-- /#bmb -->
</div><!-- /#bmo -->

<!-- TIMB Rename modal -->
<div id="timb-rename-modal" style="display:none;position:fixed;inset:0;z-index:9200;
     background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:var(--cream);border-radius:var(--radius);padding:1.4rem;width:320px;
              box-shadow:0 8px 32px rgba(0,0,0,.2)">
    <div style="font-weight:600;font-size:.95rem;margin-bottom:.85rem">Rename TIMB Grade</div>
    <div class="form-group">
      <label>Grade Code</label>
      <input type="text" id="timb-rename-val" class="form-control" required
             style="font-family:monospace;font-size:1rem;letter-spacing:.06em;text-transform:uppercase">
    </div>
    <div id="timb-rename-err" style="font-size:.71rem;color:#c03030;margin-bottom:.4rem"></div>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-primary btn-sm" onclick="saveTimbRename()">Save</button>
      <button class="btn btn-ghost btn-sm" onclick="closeTimbRename()">Cancel</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="bm-toast"></div>

<script>
// ─────────────────────────────────────────────────────────
// Utilities
// ─────────────────────────────────────────────────────────
function toggleEl(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
}
function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function toast(msg, ok) {
    var t = document.getElementById('bm-toast');
    t.textContent = msg;
    t.style.background = ok === false ? '#8a2020' : '#1a1208';
    t.style.display = 'block';
    clearTimeout(t._timer);
    t._timer = setTimeout(function(){ t.style.display = 'none'; }, 2800);
}
async function ajaxPost(data) {
    var fd = new FormData();
    for (var k in data) fd.append(k, data[k]);
    var r = await fetch(location.pathname, {
        method:'POST',
        headers:{'X-Requested-With':'XMLHttpRequest'},
        body: fd
    });
    return r.json();
}

// ─────────────────────────────────────────────────────────
// Buyer table search
// ─────────────────────────────────────────────────────────
function filterBuyers(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#buyers-table tbody tr[data-id]').forEach(function(tr) {
        var s = tr.dataset.search || '';
        tr.style.display = (!q || s.includes(q)) ? '' : 'none';
    });
}

// ─────────────────────────────────────────────────────────
// Add Buyer modal
// ─────────────────────────────────────────────────────────
function openAddBuyer() {
    document.getElementById('add-buyer-modal').style.display = 'flex';
}
function closeAddBuyer() {
    document.getElementById('add-buyer-modal').style.display = 'none';
}
document.getElementById('add-buyer-modal').addEventListener('click', function(e){
    if (e.target === this) closeAddBuyer();
});

// ─────────────────────────────────────────────────────────
// Buyer detail modal
// ─────────────────────────────────────────────────────────
var _curBuyerId = 0;
var _curBuyerName = '';

async function openBuyer(id) {
    _curBuyerId = id;
    document.getElementById('bmo').style.display = 'block';
    document.getElementById('bm-grades-tbody').innerHTML =
        '<tr><td colspan="3" style="text-align:center;padding:1.5rem;color:#aaa;font-size:.78rem">Loading…</td></tr>';
    document.getElementById('bm-name').textContent = '…';
    document.getElementById('bm-code-badge').textContent = '…';

    try {
        var r = await fetch(location.pathname + '?ajax=buyer&id=' + id, {
            headers: {'X-Requested-With':'XMLHttpRequest'}
        });
        var d = await r.json();
        if (d.error) { toast(d.error, false); closeBuyer(); return; }
        renderBuyerModal(d.buyer, d.grades);
    } catch(e) {
        toast('Failed to load buyer', false);
    }
}

function renderBuyerModal(b, grades) {
    _curBuyerName = b.name;
    document.getElementById('bm-name').textContent = b.name;
    document.getElementById('bm-code-badge').textContent = b.buyer_code;
    document.getElementById('bm-grade-count').textContent =
        grades.length + ' grade' + (grades.length!==1?'s':'');

    // Pre-fill edit fields
    document.getElementById('edit-name').value = b.name;
    document.getElementById('edit-code').value = b.buyer_code;
    document.getElementById('bm-edit-bar').style.display = 'none';
    document.getElementById('edit-err').textContent = '';

    // Reset add mark fields
    document.getElementById('bm-add-mark-bar').style.display = 'none';
    document.getElementById('add-mark-err').textContent = '';

    // CSV ids
    document.getElementById('csv-buyer-id').value  = b.id;
    document.getElementById('csv-reopen-id').value = b.id;
    document.getElementById('bm-csv-dl').href = '?dl=buyer_grades&b=' + b.id;

    renderGrades(grades);
}

function renderGrades(grades) {
    var tbody = document.getElementById('bm-grades-tbody');
    if (!grades || grades.length === 0) {
        tbody.innerHTML = '<tr id="bm-grades-empty"><td colspan="3" style="text-align:center;color:#aaa;padding:1.5rem;font-size:.78rem">No grades yet — add one above or upload a CSV.</td></tr>';
        return;
    }
    var html = '';
    grades.forEach(function(g) {
        var active = parseInt(g.active);
        html += '<tr data-gid="' + g.id + '">'
              + '<td><span class="grade-code' + (active?'':' inactive') + '">' + escH(g.description) + '</span></td>'
              + '<td>' + (active
                  ? '<span style="display:inline-flex;align-items:center;gap:.28rem;font-size:.7rem"><span style="width:6px;height:6px;border-radius:50%;background:var(--leaf)"></span>Active</span>'
                  : '<span style="display:inline-flex;align-items:center;gap:.28rem;font-size:.7rem;color:var(--muted)"><span style="width:6px;height:6px;border-radius:50%;background:var(--border)"></span>Inactive</span>'
                ) + '</td>'
              + '<td style="text-align:right">'
              + '<button class="btn btn-ghost btn-sm" style="font-size:.68rem;padding:2px 6px" onclick="toggleGrade(' + g.id + ',' + (active?0:1) + ',this)">' + (active?'Disable':'Enable') + '</button> '
              + '<button class="btn btn-danger btn-sm" style="font-size:.68rem;padding:2px 6px" onclick="deleteGrade(' + g.id + ',this)">&#10005;</button>'
              + '</td></tr>';
    });
    tbody.innerHTML = html;
}

function closeBuyer() {
    document.getElementById('bmo').style.display = 'none';
    document.getElementById('bm-add-mark-bar').style.display = 'none';
    _curBuyerId = 0;
}

// ── Add buyer mark ──
function toggleAddMark() {
    var bar = document.getElementById('bm-add-mark-bar');
    if (bar.style.display === 'block') {
        bar.style.display = 'none';
    } else {
        document.getElementById('bm-edit-bar').style.display = 'none';
        bar.style.display = 'block';
        document.getElementById('add-mark-code').value = '';
        document.getElementById('add-mark-code').focus();
        document.getElementById('add-mark-name').value = document.getElementById('bm-name').textContent;
        document.getElementById('add-mark-err').textContent = '';
    }
}

async function saveAddBuyerMark() {
    var name = document.getElementById('add-mark-name').value.trim();
    var code = document.getElementById('add-mark-code').value.trim().toUpperCase();
    var errDiv = document.getElementById('add-mark-err');
    errDiv.textContent = '';
    if (!name || !code) { errDiv.textContent = 'Mark/Code is required.'; return; }
    
    var d = await ajaxPost({action: 'add_buyer_mark', name: name, buyer_code: code});
    if (d.error) { errDiv.textContent = d.error; return; }
    
    closeBuyer();
    toast('Buyer mark added');
    // Refresh page to show the newly added buyer mark in the main table list
    setTimeout(() => { window.location.reload(); }, 500);
}

// ── Edit buyer ──
function toggleEditBuyer() {
    var bar = document.getElementById('bm-edit-bar');
    document.getElementById('bm-add-mark-bar').style.display = 'none';
    bar.style.display = bar.style.display === 'none' || bar.style.display === '' ? 'block' : 'none';
    document.getElementById('edit-err').textContent = '';
    if (bar.style.display === 'block') document.getElementById('edit-name').focus();
}

async function saveEditBuyer() {
    var name = document.getElementById('edit-name').value.trim();
    var code = document.getElementById('edit-code').value.trim().toUpperCase();
    document.getElementById('edit-err').textContent = '';
    if (!name || !code) { document.getElementById('edit-err').textContent = 'Name and code required.'; return; }
    var d = await ajaxPost({action:'edit_buyer', buyerid:_curBuyerId, name:name, buyer_code:code});
    if (d.error) { document.getElementById('edit-err').textContent = d.error; return; }
    document.getElementById('bm-name').textContent = d.name;
    document.getElementById('bm-code-badge').textContent = d.code;
    document.getElementById('bm-edit-bar').style.display = 'none';
    // Update table row
    var tr = document.querySelector('#buyers-table tr[data-id="' + _curBuyerId + '"]');
    if (tr) {
        tr.querySelector('td:nth-child(2)').textContent = d.name;
        tr.querySelector('td:nth-child(3) span').textContent = d.code;
        tr.dataset.search = (d.name + ' ' + d.code).toLowerCase();
    }
    toast('Buyer updated');
}

// ── Delete buyer ──
async function deleteBuyer() {
    if (!confirm('Delete buyer "' + _curBuyerName + '" and all their grades? This cannot be undone.')) return;
    var d = await ajaxPost({action:'delete_buyer', buyerid:_curBuyerId});
    if (d.error) { toast(d.error, false); return; }
    // Remove row from table
    var tr = document.querySelector('#buyers-table tr[data-id="' + _curBuyerId + '"]');
    if (tr) tr.remove();
    closeBuyer();
    toast('Buyer deleted');
    // Update count in header
    var tbody = document.querySelector('#buyers-table tbody');
    var cnt   = tbody.querySelectorAll('tr[data-id]').length;
    document.querySelector('.page-header-left p').textContent =
        cnt + ' buyer' + (cnt!==1?'s':'') + ' · click a row to manage';
}

// ── Add grade ──
async function addGrade() {
    var inp  = document.getElementById('new-grade-input');
    var desc = inp.value.trim().toUpperCase();
    var errEl = document.getElementById('add-grade-err');
    errEl.style.display = 'none';
    if (!desc) { errEl.textContent = 'Enter a grade code.'; errEl.style.display=''; return; }
    var d = await ajaxPost({action:'add_grade', buyerid:_curBuyerId, description:desc});
    if (d.error) { errEl.textContent = d.error; errEl.style.display=''; return; }
    inp.value = '';
    inp.focus();
    // Append to table
    var tbody = document.getElementById('bm-grades-tbody');
    var empty = document.getElementById('bm-grades-empty');
    if (empty) empty.remove();
    var tr = document.createElement('tr');
    tr.dataset.gid = d.id;
    tr.innerHTML = '<td><span class="grade-code">' + escH(d.description) + '</span></td>'
        + '<td><span style="display:inline-flex;align-items:center;gap:.28rem;font-size:.7rem"><span style="width:6px;height:6px;border-radius:50%;background:var(--leaf)"></span>Active</span></td>'
        + '<td style="text-align:right">'
        + '<button class="btn btn-ghost btn-sm" style="font-size:.68rem;padding:2px 6px" onclick="toggleGrade(' + d.id + ',0,this)">Disable</button> '
        + '<button class="btn btn-danger btn-sm" style="font-size:.68rem;padding:2px 6px" onclick="deleteGrade(' + d.id + ',this)">&#10005;</button>'
        + '</td>';
    tbody.appendChild(tr);
    // Update grade count badge
    var rows = tbody.querySelectorAll('tr[data-gid]').length;
    document.getElementById('bm-grade-count').textContent = rows + ' grade' + (rows!==1?'s':'');
    // Update uploaded dot in main table
    updateUploadedDot(_curBuyerId, rows);
    toast('Grade ' + d.description + ' added');
}

// ── Toggle grade ──
async function toggleGrade(gid, newActive, btn) {
    var d = await ajaxPost({action:'toggle_grade', gid:gid, active:newActive});
    if (d.error) { toast(d.error, false); return; }
    var tr   = btn.closest('tr');
    var span = tr.querySelector('.grade-code');
    if (newActive) {
        span.classList.remove('inactive');
        tr.querySelector('td:nth-child(2)').innerHTML =
            '<span style="display:inline-flex;align-items:center;gap:.28rem;font-size:.7rem"><span style="width:6px;height:6px;border-radius:50%;background:var(--leaf)"></span>Active</span>';
        btn.textContent = 'Disable';
        btn.setAttribute('onclick', 'toggleGrade(' + gid + ',0,this)');
    } else {
        span.classList.add('inactive');
        tr.querySelector('td:nth-child(2)').innerHTML =
            '<span style="display:inline-flex;align-items:center;gap:.28rem;font-size:.7rem;color:var(--muted)"><span style="width:6px;height:6px;border-radius:50%;background:var(--border)"></span>Inactive</span>';
        btn.textContent = 'Enable';
        btn.setAttribute('onclick', 'toggleGrade(' + gid + ',1,this)');
    }
    toast(newActive ? 'Grade enabled' : 'Grade disabled');
}

// ── Delete grade ──
async function deleteGrade(gid, btn) {
    var tr   = btn.closest('tr');
    var code = tr.querySelector('.grade-code').textContent.trim();
    if (!confirm('Delete grade "' + code + '"?')) return;
    var d = await ajaxPost({action:'delete_grade', gid:gid});
    if (d.error) { toast(d.error, false); return; }
    tr.remove();
    var tbody = document.getElementById('bm-grades-tbody');
    var rows  = tbody.querySelectorAll('tr[data-gid]').length;
    if (rows === 0) {
        tbody.innerHTML = '<tr id="bm-grades-empty"><td colspan="3" style="text-align:center;color:#aaa;padding:1.5rem;font-size:.78rem">No grades yet — add one above or upload a CSV.</td></tr>';
    }
    document.getElementById('bm-grade-count').textContent = rows + ' grade' + (rows!==1?'s':'');
    updateUploadedDot(_curBuyerId, rows);
    toast('Grade deleted');
}

// Update the uploaded dot + counts in the main table
function updateUploadedDot(bid, total) {
    var tr = document.querySelector('#buyers-table tr[data-id="' + bid + '"]');
    if (!tr) return;
    tr.querySelector('td:nth-child(4)').innerHTML = total > 0
        ? '<strong>' + total + '</strong>' : '<span class="td-muted">0</span>';
    tr.querySelector('td:nth-child(6)').innerHTML = total > 0
        ? '<span class="up-dot up-yes">&#10003; Uploaded</span>'
        : '<span class="up-dot up-no">&#8212; None</span>';
}

// CSV cancel
function cancelBgCsv() {
    var f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = '<input type="hidden" name="action" value="clear_bg_csv">'
                + '<input type="hidden" name="reopen_buyerid" value="0">';
    document.body.appendChild(f);
    f.submit();
}

// ─────────────────────────────────────────────────────────
// TIMB grades
// ─────────────────────────────────────────────────────────
function filterTimb(q) {
    q = q.toLowerCase().trim();
    var showInactive = document.getElementById('show-inactive').checked;
    document.querySelectorAll('#timb-table tbody tr[data-grade]').forEach(function(row) {
        var grade  = row.dataset.grade || '';
        var active = row.dataset.active === '1';
        var matches = !q || grade.includes(q);
        row.style.display = (matches && (active || showInactive)) ? '' : 'none';
    });
}

async function addTimbGrade() {
    var inp = document.getElementById('timb-add-input');
    var err = document.getElementById('timb-add-err');
    var desc = inp.value.trim().toUpperCase();
    err.textContent = '';
    if (!desc) { err.textContent = 'Enter a grade code.'; return; }
    var d = await ajaxPost({action:'add_timb', description:desc});
    if (d.error) { err.textContent = d.error; return; }
    inp.value = ''; inp.focus();
    // Append row to timb table
    var tbody = document.getElementById('timb-tbody');
    var emptyRow = tbody.querySelector('td[colspan]');
    if (emptyRow) emptyRow.closest('tr').remove();
    var tr = document.createElement('tr');
    tr.dataset.active = '1'; tr.dataset.grade = desc.toLowerCase(); tr.dataset.id = d.id;
    var seq = tbody.querySelectorAll('tr').length + 1;
    tr.innerHTML = '<td class="td-muted" style="font-size:.68rem">' + seq + '</td>'
        + '<td><span style="font-family:\'Bebas Neue\',monospace;font-size:1.1rem;letter-spacing:.06em;color:var(--gold)">' + escH(desc) + '</span></td>'
        + '<td class="td-mono text-right"><span class="td-muted">—</span></td>'
        + '<td><span style="display:flex;align-items:center;gap:.3rem;font-size:.72rem"><span style="width:6px;height:6px;border-radius:50%;background:var(--leaf)"></span>Active</span></td>'
        + '<td><div style="display:flex;gap:.25rem">'
        + '<button class="btn btn-ghost btn-sm" onclick="openTimbRename(' + d.id + ',\'' + escH(desc) + '\')" title="Rename">&#9998;</button>'
        + '<button class="btn btn-ghost btn-sm" onclick="toggleTimb(' + d.id + ',0,this)" title="Deactivate">&#9646;&#9646;</button>'
        + '<button class="btn btn-danger btn-sm" onclick="deleteTimb(' + d.id + ',this)" title="Delete">&#10005;</button>'
        + '</div></td>';
    tbody.appendChild(tr);
    toggleEl('timb-add-form');
    toast('TIMB grade ' + desc + ' added');
}

async function toggleTimb(tid, newActive, btn) {
    var d = await ajaxPost({action:'toggle_timb', tid:tid, active:newActive});
    if (d.error) { toast(d.error, false); return; }
    var tr = btn.closest('tr');
    tr.dataset.active = newActive;
    var codeSpan = tr.querySelector('td:nth-child(2) span');
    if (codeSpan) codeSpan.style.color = newActive ? 'var(--gold)' : 'var(--muted)';
    // Update status cell
    tr.querySelector('td:nth-child(4)').innerHTML = newActive
        ? '<span style="display:flex;align-items:center;gap:.3rem;font-size:.72rem"><span style="width:6px;height:6px;border-radius:50%;background:var(--leaf)"></span>Active</span>'
        : '<span style="display:flex;align-items:center;gap:.3rem;font-size:.72rem;color:var(--muted)"><span style="width:6px;height:6px;border-radius:50%;background:var(--border)"></span>Inactive</span>';
    // Update toggle button
    btn.innerHTML = newActive ? '&#9646;&#9646;' : '&#9654;';
    btn.title = newActive ? 'Deactivate' : 'Activate';
    btn.setAttribute('onclick', 'toggleTimb(' + tid + ',' + (newActive?0:1) + ',this)');
    // Re-apply filter
    filterTimb(document.getElementById('timb-search').value);
    toast(newActive ? 'Grade activated' : 'Grade deactivated');
}

async function deleteTimb(tid, btn) {
    var tr   = btn.closest('tr');
    var code = tr.querySelector('td:nth-child(2) span').textContent.trim();
    if (!confirm('Delete TIMB grade "' + code + '"? Cannot be undone.')) return;
    var d = await ajaxPost({action:'delete_timb', tid:tid});
    if (d.error) { toast(d.error, false); return; }
    tr.remove();
    toast('Grade ' + code + ' deleted');
}

// TIMB rename modal
var _renameTimbId = 0;
function openTimbRename(tid, current) {
    _renameTimbId = tid;
    document.getElementById('timb-rename-val').value = current;
    document.getElementById('timb-rename-err').textContent = '';
    var m = document.getElementById('timb-rename-modal');
    m.style.display = 'flex';
    setTimeout(function(){ document.getElementById('timb-rename-val').select(); }, 60);
}
function closeTimbRename() {
    document.getElementById('timb-rename-modal').style.display = 'none';
}
async function saveTimbRename() {
    var val = document.getElementById('timb-rename-val').value.trim().toUpperCase();
    var err = document.getElementById('timb-rename-err');
    err.textContent = '';
    if (!val) { err.textContent = 'Grade code required.'; return; }
    var d = await ajaxPost({action:'rename_timb', tid:_renameTimbId, description:val});
    if (d.error) { err.textContent = d.error; return; }
    // Update row
    var tr = document.querySelector('#timb-table tr[data-id="' + _renameTimbId + '"]');
    if (tr) {
        tr.dataset.grade = val.toLowerCase();
        tr.querySelector('td:nth-child(2) span').textContent = val;
    }
    closeTimbRename();
    toast('Grade renamed to ' + val);
}
document.getElementById('timb-rename-modal').addEventListener('click', function(e){
    if (e.target === this) closeTimbRename();
});

// ─────────────────────────────────────────────────────────
// Init
// ─────────────────────────────────────────────────────────
function switchBuyersTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.buyers-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_buyers_tab', tabId);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Hide inactive TIMB grades by default
    filterTimb('');

    // Re-open buyer modal if CSV just ran
    var reopenId = <?= (int)($reopen_buyerid ?? 0) ?>;
    if (reopenId) openBuyer(reopenId);

    // Initialize tab state
    const defaultTab = sessionStorage.getItem('active_buyers_tab') || 'list';
    switchBuyersTab(defaultTab);
});

function cancelTimbCsv() {
    var f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = '<input type="hidden" name="action" value="clear_timb_csv">';
    document.body.appendChild(f);
    f.submit();
}
</script>

<?php include "includes/layout_end.php"; ?>
