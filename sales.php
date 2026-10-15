<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$active_page = "sales";
$userid   = current_userid();
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$seasonid = current_season_id();
$today    = date('Y-m-d');

// ── Resolve active sell date (used by both page render AND AJAX) ──────────────
$active_sell_date = '';
// 1. Try selling_days (authoritative)
$_sd_q = $conn->query("SELECT sell_date FROM selling_days
    WHERE selling_pointid=$spid AND status='open'
    ORDER BY sell_date DESC LIMIT 1");
if ($_sd_q && $_sd_q !== true && $_sd_q->num_rows > 0)
    $active_sell_date = $_sd_q->fetch_assoc()['sell_date'];
// 2. Fallback: active start_of_day description
if (!$active_sell_date) {
    $_sod_q = $conn->query("SELECT description FROM start_of_day
        WHERE seasonid=$seasonid AND active=1 ORDER BY id DESC LIMIT 1");
    if ($_sod_q && $_sod_q !== true && $_sod_q->num_rows > 0)
        $active_sell_date = $_sod_q->fetch_assoc()['description'];
}
// 3. Last resort: today
if (!$active_sell_date) $active_sell_date = $today;

// ── Ensure splits lookup table is seeded ─────────────────────────────────────
$_sc = $conn->query("SELECT COUNT(*) c FROM splits");
if ($_sc && (int)$_sc->fetch_assoc()['c'] === 0)
    for ($i = 0; $i <= 15; $i++) $conn->query("INSERT INTO splits (value) VALUES ($i)");

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $ajax = $_GET['ajax'] ?? '';

    // ── LIST deliveries (growers table) ──────────────────────────────────────
    if ($ajax === 'growers') {
        $date   = $conn->real_escape_string(trim($_GET['date'] ?? ''));
        $search = $conn->real_escape_string(trim($_GET['q']    ?? ''));
        // Default to active sell date if none supplied
        if (!$date) $date = $active_sell_date;
        $where  = "(" . ($spid ? "g.selling_pointid=$spid" : "1=1") . ")";
        if ($date)   $where .= " AND tg.sell_date='$date'";
        if ($search) $where .= " AND (g.grower_num LIKE '%$search%'
                                   OR g.name    LIKE '%$search%'
                                   OR g.surname LIKE '%$search%')";

        $r = $conn->query("
            SELECT
                tg.id tgid,
                g.grower_num, g.name, g.surname,
                tg.sell_date, tg.bales expected_bales, tg.booked,
                COALESCE(t.location,'—') location,
                COUNT(DISTINCT bj.id) total_bales,
                COUNT(DISTINCT tk.id) ticketed,
                COUNT(DISTINCT sb.id) sold,
                COUNT(DISTINCT bb.id) balanced,
                COUNT(DISTINCT CASE WHEN bb.stoporder_processed = 1 THEN bb.id END) processed_sheets,
                COUNT(DISTINCT CASE WHEN bj.splitid > 1 THEN bj.splitid END) extra_splits
            FROM transporter_growers tg
            JOIN growers g   ON g.id  = tg.growerid
            JOIN transporter t ON t.id = tg.transporterid
            LEFT JOIN bale_junus bj ON bj.transporter_growersid = tg.id
            LEFT JOIN tickets tk    ON tk.bale_junusid = bj.id
            LEFT JOIN sold_bales sb ON sb.ticketsid = tk.id
            LEFT JOIN balanced_bales bb ON bb.transporter_growersid = tg.id
            WHERE $where
            GROUP BY tg.id
            ORDER BY tg.sell_date DESC, g.grower_num ASC
            LIMIT 300
        ");
        $rows = ($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : [];
        echo json_encode(['rows' => $rows, 'active_date' => $date ?: $active_sell_date]);
        exit;
    }

    // ── BALES for a delivery (modal) ──────────────────────────────────────────
    if ($ajax === 'bales') {
        $tgid = (int)($_GET['tgid'] ?? 0);
        if (!$tgid) { echo json_encode(['error'=>'tgid required']); exit; }

        // Grower / delivery header
        $hdr = $conn->query("
            SELECT g.grower_num, g.name, g.surname, g.id growerid,
                   tg.sell_date, tg.bales expected_bales, tg.booked,
                   COALESCE(t.location,'—') location,
                   t.name transporter_name
            FROM transporter_growers tg
            JOIN growers g ON g.id = tg.growerid
            JOIN transporter t ON t.id = tg.transporterid
            WHERE tg.id=$tgid LIMIT 1
        ");
        $info = ($hdr && $hdr !== true) ? $hdr->fetch_assoc() : null;
        if (!$info) { echo json_encode(['error'=>'Delivery not found']); exit; }

        // Processed sheets count for this delivery
        $proc_q = $conn->query("SELECT
            COUNT(*) total,
            SUM(CASE WHEN stoporder_processed=1 THEN 1 ELSE 0 END) processed
            FROM balanced_bales WHERE transporter_growersid=$tgid");
        $proc_r = ($proc_q && $proc_q !== true) ? $proc_q->fetch_assoc() : ['total'=>0,'processed'=>0];
        $info['balanced_total']    = (int)$proc_r['total'];
        $info['processed_sheets']  = (int)$proc_r['processed'];

        // Bales
        $r = $conn->query("
            SELECT
                bj.id bale_junus_id, bj.splitid, bj.lot, bj.bale_group,
                ROUND(bm.mass,2) mass, bm.temp_bacode,
                COALESCE(tk.barcode,'') barcode,
                COALESCE(sb.id,0) sold_id,
                COALESCE(sb.sale_code,'') sale_code,
                COALESCE(sb.price,'0') price_stored,
                COALESCE(tgd.description,'—') timb_grade,
                COALESCE(bgd.description,'—') buyer_grade,
                COALESCE(b.name,'—') buyer_name,
                COALESCE(bb.id,0) balanced_id,
                COALESCE(bb.stoporder_processed,0) processed
            FROM bale_junus bj
            JOIN bale_mass bm ON bm.id = bj.bale_massid
            LEFT JOIN tickets tk     ON tk.bale_junusid  = bj.id
            LEFT JOIN sold_bales sb  ON sb.ticketsid     = tk.id
            LEFT JOIN timb_grades tgd ON tgd.id          = sb.timb_gradesid
            LEFT JOIN buyer_grades bgd ON bgd.id         = sb.buyer_gradesid
            LEFT JOIN buyer b        ON b.id             = bgd.buyerid
            LEFT JOIN balanced_bales bb ON bb.transporter_growersid = $tgid
                                       AND bb.splitid = bj.splitid
            WHERE bj.transporter_growersid = $tgid
            ORDER BY bj.splitid ASC, bj.lot ASC, bj.bale_group ASC
        ");
        $bales = ($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : [];

        echo json_encode(['info'=>$info,'bales'=>$bales]);
        exit;
    }

    // ── SAVE split assignments ────────────────────────────────────────────────
    if ($ajax === 'save_splits' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b           = json_decode(file_get_contents('php://input'), true) ?? [];
        $tgid        = (int)($b['tgid'] ?? 0);
        $assignments = $b['assignments'] ?? [];
        if (!$tgid)           { echo json_encode(['error'=>'tgid required']); exit; }
        if (empty($assignments)) { echo json_encode(['error'=>'No assignments']); exit; }

        // Verify tgid belongs to this floor
        $chk = $conn->query("
            SELECT tg.id FROM transporter_growers tg
            JOIN growers g ON g.id=tg.growerid
            WHERE tg.id=$tgid AND (g.selling_pointid=$spid OR $spid=0) LIMIT 1
        ");
        if (!$chk || !$chk->num_rows) { echo json_encode(['error'=>'Delivery not found']); exit; }

        // Block if any balanced bale is already processed
        $proc_chk = $conn->query("SELECT COUNT(*) c FROM balanced_bales
            WHERE transporter_growersid=$tgid AND stoporder_processed=1");
        if ($proc_chk && (int)$proc_chk->fetch_assoc()['c'] > 0) {
            echo json_encode(['error'=>'This grower has processed sales sheets. Reverse the grower before changing splits.']);
            exit;
        }

        $updated = 0; $errors = [];
        foreach ($assignments as $a) {
            $bjid  = (int)($a['bale_junus_id'] ?? 0);
            $split = (int)($a['splitid']       ?? 1);
            if ($split < 1)  $split = 1;
            if ($split > 15) $split = 15;
            if (!$bjid) continue;
            $conn->query("UPDATE bale_junus SET splitid=$split WHERE id=$bjid");
            if ($conn->error) { $errors[] = "Bale $bjid: ".$conn->error; }
            else $updated++;
        }

        audit_log($conn, $spid, $userid, 'bale_junus', 'split_assign', $tgid, null,
            ['tgid'=>$tgid,'updated'=>$updated]);

        echo json_encode(['ok'=>true,'updated'=>$updated,'errors'=>$errors]);
        exit;
    }

    // ── REVERSE processing (unlock splits) ────────────────────────────────────
    if ($ajax === 'reverse_processing' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b    = json_decode(file_get_contents('php://input'), true) ?? [];
        $tgid = (int)($b['tgid'] ?? 0);
        if (!$tgid) { echo json_encode(['error'=>'tgid required']); exit; }

        // Verify ownership
        $chk = $conn->query("
            SELECT tg.id FROM transporter_growers tg
            JOIN growers g ON g.id=tg.growerid
            WHERE tg.id=$tgid AND (g.selling_pointid=$spid OR $spid=0) LIMIT 1
        ");
        if (!$chk || !$chk->num_rows) { echo json_encode(['error'=>'Delivery not found']); exit; }

        // Reverse: clear stoporder_processed and printed flags
        $conn->query("UPDATE balanced_bales
            SET stoporder_processed=0, printed=0
            WHERE transporter_growersid=$tgid AND stoporder_processed=1");
        $reversed = $conn->affected_rows;

        audit_log($conn, $spid, $userid, 'balanced_bales', 'reverse_processing', $tgid, null,
            ['tgid'=>$tgid,'reversed'=>$reversed]);

        echo json_encode(['ok'=>true,'reversed'=>$reversed]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

// ── Page render ───────────────────────────────────────────────────────────────
$page_title = "Sales";

// Summary counts for header
$cnt_q = $conn->query("
    SELECT COUNT(DISTINCT tg.id) deliveries,
           COUNT(DISTINCT bj.id) bales,
           COUNT(DISTINCT CASE WHEN sb.id IS NOT NULL THEN bj.id END) sold
    FROM transporter_growers tg
    JOIN growers g ON g.id=tg.growerid
    LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
    LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
    LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
    WHERE tg.sell_date='$active_sell_date'
      AND (g.selling_pointid=$spid OR $spid=0)
");
$cnt = ($cnt_q && $cnt_q !== true) ? $cnt_q->fetch_assoc() : ['deliveries'=>0,'bales'=>0,'sold'=>0];

include "includes/layout.php";
?>

<style>
/* ── Split colour palette ──────────────────────────────────────────────────── */
.split-chip { display:inline-block; padding:.15rem .5rem; border-radius:99px;
              font-size:.68rem; font-weight:700; white-space:nowrap; }
.split-1  { background:#E8F5E9; color:#1B5E20; }
.split-2  { background:#E3F2FD; color:#0D47A1; }
.split-3  { background:#FFF3E0; color:#E65100; }
.split-4  { background:#F3E5F5; color:#6A1B9A; }
.split-5  { background:#FCE4EC; color:#880E4F; }
.split-6  { background:#E0F7FA; color:#006064; }
.split-7  { background:#FFF9C4; color:#827717; }
.split-8  { background:#EFEBE9; color:#3E2723; }
.split-9  { background:#E8EAF6; color:#1A237E; }
.split-0  { background:#F5F5F5; color:#9E9E9E; }

/* ── Modal ─────────────────────────────────────────────────────────────────── */
#salesModal { display:none; position:fixed; inset:0; z-index:2000;
              background:rgba(0,0,0,.55); align-items:center; justify-content:center; }
#salesModal.open { display:flex; }
#modalBox { background:#fff; border-radius:14px; width:min(96vw,1050px);
            max-height:90vh; display:flex; flex-direction:column;
            box-shadow:0 8px 40px rgba(0,0,0,.25); overflow:hidden; }
#modalHeader { background:#1A4D2E; color:#fff; padding:1rem 1.25rem;
               display:flex; align-items:center; gap:.75rem; flex-shrink:0; }
#modalBody   { flex:1; overflow-y:auto; padding:1rem 1.25rem; }
#modalFooter { padding:.75rem 1.25rem; border-top:1px solid rgba(0,0,0,.08);
               display:flex; align-items:center; gap:.75rem; background:#FAFAFA; flex-shrink:0; }

/* ── Bales table ────────────────────────────────────────────────────────────── */
#balesTable { width:100%; border-collapse:collapse; font-size:.78rem; }
#balesTable th { background:#F5F5F5; padding:.45rem .6rem; text-align:left;
                  font-size:.65rem; font-weight:700; text-transform:uppercase;
                  color:#666; border-bottom:2px solid rgba(0,0,0,.08); white-space:nowrap; }
#balesTable td { padding:.4rem .6rem; border-bottom:1px solid rgba(0,0,0,.05); vertical-align:middle; }
#balesTable tr:hover td { background:#FAFAFA; }
#balesTable tr.split-row-1 td { border-left:3px solid #4CAF50; }
#balesTable tr.split-row-2 td { border-left:3px solid #2196F3; }
#balesTable tr.split-row-3 td { border-left:3px solid #FF9800; }
#balesTable tr.split-row-4 td { border-left:3px solid #9C27B0; }
#balesTable tr.split-row-5 td { border-left:3px solid #E91E63; }
#balesTable tr.split-row-6 td { border-left:3px solid #00BCD4; }
#balesTable tr.split-row-7 td { border-left:3px solid #CDDC39; }
#balesTable tr.split-row-8 td { border-left:3px solid #795548; }
#balesTable tr.split-row-9 td { border-left:3px solid #3F51B5; }

/* ── Growers table ──────────────────────────────────────────────────────────── */
#growersTable tbody tr { cursor:pointer; transition:background .12s; }
#growersTable tbody tr:hover td { background:rgba(26,77,46,.04); }
</style>

<div class="page-header">
    <div class="page-header-left">
        <h2>Sales</h2>
        <p><?= $cnt['deliveries'] ?> deliveries &nbsp;·&nbsp;
           <?= $cnt['bales'] ?> bales &nbsp;·&nbsp;
           <?= $cnt['sold'] ?> sold &nbsp;·&nbsp;
           <strong><?= date('d M Y', strtotime($active_sell_date)) ?></strong></p>
    </div>
</div>

<!-- Filter bar -->
<div style="display:flex; gap:.65rem; margin-bottom:1rem; flex-wrap:wrap; align-items:center">
    <input id="filterDate" type="date" class="form-control" value="<?= $active_sell_date ?>"
           style="width:160px" onchange="loadGrowers()">
    <input id="filterSearch" type="text" class="form-control" placeholder="Search grower…"
           style="width:220px; flex:1; max-width:280px"
           oninput="loadGrowers()">
    <button class="btn btn-ghost btn-sm" onclick="loadGrowers()">🔄 Refresh</button>
    <span id="growersCount" style="font-size:.78rem; color:var(--text-muted); margin-left:auto"></span>
</div>

<!-- Growers table -->
<div class="card">
    <div class="table-wrap" style="max-height:calc(100vh - 230px); overflow-y:auto">
        <table id="growersTable">
            <thead>
                <tr>
                    <th>Grower #</th>
                    <th>Name</th>
                    <th>Location</th>
                    <th class="text-right">Bales</th>
                    <th class="text-right">Sold</th>
                    <th>Sale Date</th>
                    <th>Status</th>
                    <th>Splits</th>
                    <th>Booked</th>
                    <th>Processing</th>
                </tr>
            </thead>
            <tbody id="growersBody">
                <tr><td colspan="9" class="text-center text-muted" style="padding:2rem">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ══════════════════════════════ MODAL ══════════════════════════════════════ -->
<div id="salesModal" onclick="if(event.target===this)closeModal()">
<div id="modalBox">

    <!-- Modal header -->
    <div id="modalHeader">
        <div style="flex:1">
            <div id="modalTitle" style="font-size:1rem; font-weight:700"></div>
            <div id="modalSubtitle" style="font-size:.75rem; opacity:.8; margin-top:.15rem"></div>
        </div>
        <button onclick="closeModal()" style="background:rgba(255,255,255,.15);border:none;
                color:#fff;border-radius:6px;padding:.4rem .75rem;cursor:pointer;font-size:.85rem">
            ✕ Close
        </button>
    </div>

    <!-- Split summary chips -->
    <div style="padding:.65rem 1.25rem; background:#F9F9F9; border-bottom:1px solid rgba(0,0,0,.07);
                display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; flex-shrink:0">
        <span style="font-size:.7rem; font-weight:600; color:#666; text-transform:uppercase;
                     letter-spacing:.06em; margin-right:.25rem">Sales / Splits:</span>
        <div id="splitSummary" style="display:flex; gap:.4rem; flex-wrap:wrap; flex:1"></div>
    </div>

    <!-- Locked banner (hidden by default) -->
    <div id="lockedBanner" style="display:none; padding:.65rem 1.25rem;
                background:#FFF3E0; border-bottom:2px solid #FFCC80;
                gap:.75rem; align-items:center; flex-shrink:0; flex-wrap:wrap">
        <span style="font-size:1.1rem">🔒</span>
        <div style="flex:1; min-width:0">
            <div style="font-size:.8rem; font-weight:600; color:#BF360C">Splits Locked — Processed</div>
            <div id="lockedMsg" style="font-size:.74rem; color:#E65100; margin-top:1px"></div>
        </div>
        <button id="btnReverse" class="btn btn-sm" onclick="reverseProcessing()"
                style="background:#BF360C; color:#fff; border:none; white-space:nowrap">
            ↩ Reverse Processing
        </button>
    </div>

    <!-- Assign controls -->
    <div style="padding:.65rem 1.25rem; background:#fff; border-bottom:1px solid rgba(0,0,0,.07);
                display:flex; gap:.65rem; align-items:center; flex-wrap:wrap; flex-shrink:0">
        <label style="font-size:.78rem; font-weight:600; margin:0">Assign selected to:</label>
        <select id="assignSplitSelect" class="form-control" style="width:160px; height:34px; font-size:.82rem">
            <option value="1">Sale 1 (Main)</option>
            <option value="2">Sale 2 (Split)</option>
            <option value="3">Sale 3 (Split)</option>
            <option value="4">Sale 4</option>
            <option value="5">Sale 5</option>
            <option value="6">Sale 6</option>
            <option value="7">Sale 7</option>
            <option value="8">Sale 8</option>
            <option value="9">Sale 9</option>
        </select>
        <button id="btnAssign" class="btn btn-primary btn-sm" onclick="assignSelected()" style="height:34px">
            ✓ Assign Selected
        </button>
        <button id="btnSelectAll" class="btn btn-ghost btn-sm" onclick="selectAll()" style="height:34px">
            ☐ Select All
        </button>
        <button id="btnDeselectAll" class="btn btn-ghost btn-sm" onclick="deselectAll()" style="height:34px">
            ✕ Deselect All
        </button>
        <span id="selCount" style="font-size:.75rem; color:var(--text-muted); margin-left:auto">
            0 selected
        </span>
    </div>

    <!-- Bales table -->
    <div id="modalBody">
        <div id="balesLoading" style="text-align:center;padding:2rem;color:var(--text-muted)">Loading bales…</div>
        <table id="balesTable" style="display:none">
            <thead>
                <tr>
                    <th style="width:32px">
                        <input type="checkbox" id="chkAll" onchange="toggleAll(this.checked)" title="Select all">
                    </th>
                    <th>Barcode</th>
                    <th>Lot / Grp</th>
                    <th class="text-right">Mass</th>
                    <th>TIMB Grade</th>
                    <th>Buyer Grade</th>
                    <th class="text-right">Price</th>
                    <th style="text-align:center">Sale / Split</th>
                </tr>
            </thead>
            <tbody id="balesBody"></tbody>
        </table>
    </div>

    <!-- Modal footer -->
    <div id="modalFooter">
        <div id="saveStatus" style="font-size:.78rem; flex:1"></div>
        <div id="splitStats" style="font-size:.75rem; color:var(--text-muted); margin-right:.75rem"></div>
        <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
        <button id="btnSave" class="btn btn-primary" onclick="saveSplits()" style="padding:.65rem 1.5rem">
            💾 Save Split Assignments
        </button>
    </div>

</div>
</div>

<script>
const SPLIT_LABELS  = ['','Sale 1','Sale 2','Sale 3','Sale 4','Sale 5','Sale 6','Sale 7','Sale 8','Sale 9','Sale 10'];
const SPLIT_CLASSES = ['split-0','split-1','split-2','split-3','split-4',
                       'split-5','split-6','split-7','split-8','split-9'];

let currentTgid    = 0;
let baleData       = [];   // live working copy (splitid may change before save)
let pendingChanges = {};   // bjid → newSplitId
let isLocked       = false; // true when any sheet is processed

// ── Growers table ─────────────────────────────────────────────────────────────
function loadGrowers() {
    const date = document.getElementById('filterDate').value;
    const q    = document.getElementById('filterSearch').value.trim();
    document.getElementById('growersBody').innerHTML =
        '<tr><td colspan="9" class="text-center text-muted" style="padding:1.5rem">Loading…</td></tr>';

    fetch('sales.php?ajax=growers&date=' + encodeURIComponent(date) + '&q=' + encodeURIComponent(q),
          {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        // Sync date picker to the active sell date returned by the server
        if (d.active_date) {
            const picker = document.getElementById('filterDate');
            if (!picker.value || picker.value !== d.active_date) {
                picker.value = d.active_date;
            }
        }
        renderGrowers(d.rows || d);
    }).catch(()=>{
        document.getElementById('growersBody').innerHTML =
            '<tr><td colspan="9" class="text-center" style="color:#c03030;padding:1.5rem">Network error</td></tr>';
    });
}

function renderGrowers(rows) {
    const body = document.getElementById('growersBody');
    document.getElementById('growersCount').textContent = rows.length + ' deliveries';
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="10" class="text-center text-muted" style="padding:2rem">No deliveries found for this date/filter.</td></tr>';
        return;
    }
    let html = '';
    rows.forEach(r => {
        const total     = parseInt(r.total_bales) || 0;
        const sold      = parseInt(r.sold) || 0;
        const bal       = parseInt(r.balanced) || 0;
        const booked    = parseInt(r.booked) === 1;
        const splits    = parseInt(r.extra_splits) || 0;
        const processed = parseInt(r.processed_sheets) || 0;
        const locked    = processed > 0;

        // Status
        let statusHtml;
        if (bal > 0)        statusHtml = '<span class="badge badge-green" style="font-size:.62rem">Balanced</span>';
        else if (booked)    statusHtml = '<span class="badge badge-green" style="font-size:.62rem">Booked</span>';
        else if (sold >= total && total > 0)
                            statusHtml = '<span class="badge badge-blue"  style="font-size:.62rem">Fully Sold</span>';
        else if (sold > 0)  statusHtml = '<span class="badge badge-gold"  style="font-size:.62rem">Partial</span>';
        else if (total > 0) statusHtml = '<span class="badge badge-gray"  style="font-size:.62rem">Pending</span>';
        else                statusHtml = '<span class="badge badge-gray"  style="font-size:.62rem">No Bales</span>';

        // Processing status
        let procHtml;
        if (locked) {
            var procDark = document.body.classList.contains('theme-dark');
            procHtml = '<span class="badge" style="background:' + (procDark?'rgba(230,126,34,.18)':'#FFF3E0')
                     + ';color:' + (procDark?'#f0a06a':'#E65100')
                     + ';border:1px solid ' + (procDark?'rgba(230,126,34,.45)':'#FFCC80')
                     + ';font-size:.62rem">🔒 Processed (' + processed + ')</span>';
        } else if (bal > 0) {
            procHtml = '<span class="badge" style="background:#E8F5E9;color:#2E7D32;font-size:.62rem">✓ Balanced</span>';
        } else {
            procHtml = '<span style="font-size:.72rem;color:var(--text-muted)">—</span>';
        }

        // Splits badge
        const splitsBadge = splits > 0
            ? '<span class="badge" style="background:#E3F2FD;color:#0D47A1;font-size:.62rem">' + (splits+1) + ' splits</span>'
            : '<span style="font-size:.72rem;color:var(--text-muted)">—</span>';

        // Booked
        const bookedHtml = booked
            ? '<span class="badge badge-green" style="font-size:.62rem">Yes</span>'
            : '<span style="font-size:.72rem;color:var(--text-muted)">No</span>';

        // Progress bar
        const pct = total > 0 ? Math.round(sold/total*100) : 0;
        const soldHtml = '<span style="font-size:.78rem">' + sold + ' / ' + total + '</span>'
            + '<div style="width:48px;height:4px;background:rgba(0,0,0,.08);border-radius:99px;margin-top:2px;overflow:hidden">'
            + '<div style="width:' + pct + '%;height:100%;background:#4CAF50;border-radius:99px"></div></div>';

        const rowStyle = locked ? 'background:rgba(230,81,0,.03)' : '';
        html += '<tr onclick="openModal(' + r.tgid + ')" style="' + rowStyle + '">'
            + '<td><span class="badge badge-gold" style="font-size:.68rem">' + r.grower_num + '</span></td>'
            + '<td><span style="font-size:.78rem">' + r.name + ' ' + r.surname + '</span></td>'
            + '<td style="font-size:.75rem;color:var(--text-muted)">' + (r.location||'—') + '</td>'
            + '<td class="text-right td-mono" style="font-size:.78rem">' + total + '</td>'
            + '<td class="text-right">' + soldHtml + '</td>'
            + '<td style="font-size:.75rem;white-space:nowrap">' + (r.sell_date||'—') + '</td>'
            + '<td>' + statusHtml + '</td>'
            + '<td>' + splitsBadge + '</td>'
            + '<td>' + bookedHtml + '</td>'
            + '<td>' + procHtml + '</td>'
            + '</tr>';
    });
    body.innerHTML = html;
}

// ── Modal ─────────────────────────────────────────────────────────────────────
function openModal(tgid) {
    currentTgid    = tgid;
    pendingChanges = {};
    baleData       = [];
    isLocked       = false;
    document.getElementById('balesLoading').style.display  = '';
    document.getElementById('balesTable').style.display    = 'none';
    document.getElementById('saveStatus').textContent      = '';
    document.getElementById('splitSummary').innerHTML      = '';
    document.getElementById('selCount').textContent        = '0 selected';
    document.getElementById('chkAll').checked              = false;
    document.getElementById('lockedBanner').style.display  = 'none';
    document.getElementById('salesModal').classList.add('open');

    fetch('sales.php?ajax=bales&tgid=' + tgid,
          {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if (d.error) { document.getElementById('balesLoading').textContent = '❌ ' + d.error; return; }
        const info = d.info;
        document.getElementById('modalTitle').textContent =
            info.grower_num + ' — ' + info.name + ' ' + info.surname;
        document.getElementById('modalSubtitle').textContent =
            'Sell date: ' + (info.sell_date||'—') + '  ·  Location: ' + (info.location||'—') +
            '  ·  Transporter: ' + (info.transporter_name||'—') +
            '  ·  Expected: ' + (info.expected_bales||'0') + ' bales';

        // Lock state
        isLocked = (parseInt(info.processed_sheets) || 0) > 0;
        applyLockState(info);

        baleData = d.bales.map(b => Object.assign({},b)); // working copy
        document.getElementById('balesLoading').style.display = 'none';
        document.getElementById('balesTable').style.display   = '';
        renderBales();
    }).catch(()=>{
        document.getElementById('balesLoading').textContent = '❌ Network error';
    });
}

function applyLockState(info) {
    const banner  = document.getElementById('lockedBanner');
    const btnSave = document.getElementById('btnSave');
    const btnAssign = document.getElementById('btnAssign');
    const splitSel  = document.getElementById('assignSplitSelect');
    const selAll    = document.getElementById('btnSelectAll');
    const deselAll  = document.getElementById('btnDeselectAll');

    if (isLocked) {
        const proc  = parseInt(info.processed_sheets) || 0;
        const total = parseInt(info.balanced_total) || 0;
        document.getElementById('lockedMsg').textContent =
            proc + ' of ' + total + ' sales sheet' + (total!==1?'s':'') + ' for this grower ha' +
            (proc===1?'s':'ve') + ' been processed. Split assignments are locked.';
        banner.style.display = 'flex';
        btnSave.disabled     = true;
        if (btnAssign) btnAssign.disabled = true;
        if (splitSel)  splitSel.disabled  = true;
        if (selAll)    selAll.disabled    = true;
        if (deselAll)  deselAll.disabled  = true;
    } else {
        banner.style.display = 'none';
        btnSave.disabled     = false;
        if (btnAssign) btnAssign.disabled = false;
        if (splitSel)  splitSel.disabled  = false;
        if (selAll)    selAll.disabled    = false;
        if (deselAll)  deselAll.disabled  = false;
    }
}

function reverseProcessing() {
    if (!currentTgid) return;
    if (!confirm('This will reverse all processed sales sheets for this grower, clearing the processed and printed flags.\n\nThe sales sheets will need to be re-balanced and reprinted.\n\nContinue?')) return;

    const btn = document.getElementById('btnReverse');
    btn.disabled = true; btn.textContent = 'Reversing…';

    fetch('sales.php?ajax=reverse_processing', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({tgid: currentTgid})
    })
    .then(r=>r.json()).then(d=>{
        btn.disabled = false; btn.textContent = '↩ Reverse Processing';
        if (d.error) { alert('Error: ' + d.error); return; }
        isLocked = false;
        document.getElementById('lockedBanner').style.display = 'none';
        document.getElementById('saveStatus').textContent = '✓ Reversed ' + d.reversed + ' sheet(s). Splits are now editable.';
        document.getElementById('saveStatus').style.color = '#2E7D32';
        // Re-enable controls
        applyLockState({processed_sheets: 0, balanced_total: 0});
        // Refresh growers list in background
        loadGrowers();
    }).catch(()=>{
        btn.disabled = false; btn.textContent = '↩ Reverse Processing';
        alert('Network error. Please try again.');
    });
}

function closeModal() {
    document.getElementById('salesModal').classList.remove('open');
    currentTgid = 0;
    pendingChanges = {};
}

// ── Render bales table ────────────────────────────────────────────────────────
function renderBales() {
    const body = document.getElementById('balesBody');
    let html = '';
    baleData.forEach((b, idx) => {
        const split = parseInt(b.splitid) || 1;
        const sc    = SPLIT_CLASSES[Math.min(split, 9)];
        const sl    = SPLIT_LABELS[split] || ('Sale ' + split);
        let pr = parseFloat(b.price_stored) || 0;
        if (pr > 10) pr = pr / 100;
        const sold  = parseInt(b.sold_id) > 0;
        const bc    = b.barcode || ('<span style="color:#bbb">' + b.temp_bacode + '</span>');
        const rej   = b.sale_code && ['CO','RO','R'].indexOf(b.sale_code.toUpperCase()) !== -1;
        const prHtml = rej ? '<span style="color:#c03030">' + b.sale_code + '</span>'
                           : (sold && pr > 0 ? '$' + pr.toFixed(4) : '<span style="color:#bbb">—</span>');

        const baleProcessed = parseInt(b.processed) === 1;
        const chkHtml = isLocked
            ? '<input type="checkbox" class="bale-chk" data-idx="' + idx + '" disabled title="Locked — reverse processing to edit">'
            : '<input type="checkbox" class="bale-chk" data-idx="' + idx + '" onchange="onChkChange()">';
        const splitCellHtml = baleProcessed
            ? '<span class="split-chip ' + sc + '">' + sl + '</span>'
              + ' <span title="This split is processed" style="font-size:.7rem;color:#E65100">🔒</span>'
            : '<span class="split-chip ' + sc + '">' + sl + '</span>';
        const rowOpacity = baleProcessed ? 'opacity:.75;' : '';

        html += '<tr class="split-row-' + Math.min(split,9) + '" data-idx="' + idx + '" style="' + rowOpacity + '">'
            + '<td>' + chkHtml + '</td>'
            + '<td><code style="font-size:.7rem">' + bc + '</code></td>'
            + '<td class="td-mono" style="font-size:.72rem">' + (b.lot||'?') + '-' + (b.bale_group||'?') + '</td>'
            + '<td class="text-right td-mono">' + parseFloat(b.mass||0).toFixed(1) + 'kg</td>'
            + '<td><span class="badge badge-gold" style="font-size:.6rem">' + b.timb_grade + '</span></td>'
            + '<td style="font-size:.75rem">' + b.buyer_grade
              + (b.buyer_name && b.buyer_name !== '—' ? '<div style="font-size:.68rem;color:var(--text-muted)">' + b.buyer_name + '</div>' : '')
              + '</td>'
            + '<td class="text-right td-mono fw-500">' + prHtml + '</td>'
            + '<td style="text-align:center">' + splitCellHtml + '</td>'
            + '</tr>';
    });
    body.innerHTML = html || '<tr><td colspan="8" class="text-center text-muted" style="padding:1.5rem">No bales found for this delivery.</td></tr>';
    updateSplitSummary();
    updateSplitStats();
}

// ── Split summary chips ───────────────────────────────────────────────────────
function updateSplitSummary() {
    const counts = {};
    baleData.forEach(b => {
        const s = parseInt(b.splitid) || 1;
        counts[s] = (counts[s] || 0) + 1;
    });
    let html = '';
    Object.keys(counts).sort((a,b)=>a-b).forEach(s => {
        const sc = SPLIT_CLASSES[Math.min(s,9)];
        const sl = SPLIT_LABELS[s] || 'Sale ' + s;
        html += '<span class="split-chip ' + sc + '">' + sl + ': <b>' + counts[s] + '</b> bales</span>';
    });
    if (!html) html = '<span style="font-size:.72rem;color:var(--text-muted)">No bales</span>';
    document.getElementById('splitSummary').innerHTML = html;
}

function updateSplitStats() {
    const splits = {};
    baleData.forEach(b => { const s = parseInt(b.splitid)||1; splits[s] = (splits[s]||0)+1; });
    const n = Object.keys(splits).length;
    document.getElementById('splitStats').textContent =
        n === 1 ? 'Single sale (no split)' : n + ' separate sales sheets will be created';
}

// ── Selection helpers ─────────────────────────────────────────────────────────
function onChkChange() {
    const all   = document.querySelectorAll('.bale-chk');
    const sel   = document.querySelectorAll('.bale-chk:checked');
    document.getElementById('selCount').textContent = sel.length + ' selected';
    document.getElementById('chkAll').checked = sel.length === all.length && all.length > 0;
    document.getElementById('chkAll').indeterminate = sel.length > 0 && sel.length < all.length;
}
function toggleAll(checked) {
    document.querySelectorAll('.bale-chk').forEach(c => { c.checked = checked; });
    onChkChange();
}
function selectAll()   { toggleAll(true); }
function deselectAll() { toggleAll(false); }

// ── Assign selected bales to split ───────────────────────────────────────────
function assignSelected() {
    if (isLocked) { alert('Splits are locked. Reverse the processing first.'); return; }
    const newSplit = parseInt(document.getElementById('assignSplitSelect').value);
    const sel = document.querySelectorAll('.bale-chk:checked');
    if (!sel.length) { alert('Select at least one bale first.'); return; }
    sel.forEach(chk => {
        const idx = parseInt(chk.dataset.idx);
        baleData[idx].splitid = newSplit;
        pendingChanges[baleData[idx].bale_junus_id] = newSplit;
    });
    renderBales();
    // Re-tick the previously checked (now re-rendered, so all unchecked)
    document.getElementById('selCount').textContent = '0 selected';
    document.getElementById('saveStatus').textContent = Object.keys(pendingChanges).length + ' unsaved change(s)';
    document.getElementById('saveStatus').style.color = '#E65100';
}

// ── Save ──────────────────────────────────────────────────────────────────────
function saveSplits() {
    if (!Object.keys(pendingChanges).length) {
        document.getElementById('saveStatus').textContent = 'No changes to save.';
        document.getElementById('saveStatus').style.color = '#9E9E9E';
        return;
    }
    const assignments = Object.entries(pendingChanges).map(([bjid, split]) => ({
        bale_junus_id: parseInt(bjid),
        splitid: split
    }));

    document.getElementById('btnSave').disabled = true;
    document.getElementById('btnSave').textContent = 'Saving…';

    fetch('sales.php?ajax=save_splits', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({tgid: currentTgid, assignments})
    })
    .then(r=>r.json()).then(d=>{
        document.getElementById('btnSave').disabled = false;
        document.getElementById('btnSave').textContent = '💾 Save Split Assignments';
        if (d.error) {
            document.getElementById('saveStatus').textContent = '❌ ' + d.error;
            document.getElementById('saveStatus').style.color = '#C62828';
            return;
        }
        document.getElementById('saveStatus').textContent = '✓ Saved ' + d.updated + ' bale(s)';
        document.getElementById('saveStatus').style.color = '#2E7D32';
        pendingChanges = {};
        // Refresh growers table in background
        loadGrowers();
    }).catch(e=>{
        document.getElementById('btnSave').disabled = false;
        document.getElementById('btnSave').textContent = '💾 Save Split Assignments';
        document.getElementById('saveStatus').textContent = '❌ Network error';
        document.getElementById('saveStatus').style.color = '#C62828';
    });
}

// Close modal on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// Initial load
loadGrowers();
</script>

<?php include "includes/layout_end.php"; ?>
