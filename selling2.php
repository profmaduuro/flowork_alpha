<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$active_page = "selling2";
$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$today  = date('Y-m-d');

// ── One-time migration: ensure s2_confirmed column exists ────────────────────
$_chk = $conn->query("SHOW COLUMNS FROM sold_bales LIKE 's2_confirmed'");
if ($_chk && $_chk->num_rows === 0)
    $conn->query("ALTER TABLE sold_bales ADD COLUMN s2_confirmed TINYINT NOT NULL DEFAULT 0");

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $ajax = $_GET['ajax'] ?? '';

    // ── LOOKUP ────────────────────────────────────────────────────────────────
    if ($ajax === 'lookup') {
        $bc = $conn->real_escape_string(trim($_GET['barcode'] ?? ''));
        if (!$bc) { echo json_encode(['error'=>'Barcode required']); exit; }
        $r = $conn->query("
            SELECT tk.id ticket_id, tk.barcode,
                   ROUND(bm.mass,2) mass, bm.temp_bacode timb_bc,
                   bj.lot, bj.bale_group,
                   g.grower_num, g.name grower_name, g.surname,
                   tgr.id tgid, tgr.sell_date,
                   t.name transporter_name,
                   COALESCE(sb.id,0) sold_id,
                   COALESCE(sb.sale_code,'') sale_code,
                   COALESCE(sb.price,'') price_stored,
                   COALESCE(sb.s2_confirmed,0) s2_confirmed,
                   tgd.id timb_grade_id, tgd.description timb_grade,
                   bgd.id buyer_grade_id, bgd.description buyer_grade,
                   bgd.buyerid buyer_id,
                   COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name
            FROM tickets tk
            JOIN bale_junus bj   ON bj.id  = tk.bale_junusid
            JOIN bale_mass bm    ON bm.id  = bj.bale_massid
            JOIN transporter_growers tgr ON tgr.id = bj.transporter_growersid
            JOIN growers g       ON g.id   = tgr.growerid
            LEFT JOIN transporter t  ON t.id   = tgr.transporterid
            LEFT JOIN sold_bales sb  ON sb.ticketsid = tk.id
            LEFT JOIN timb_grades tgd ON tgd.id = sb.timb_gradesid
            LEFT JOIN buyer_grades bgd ON bgd.id = sb.buyer_gradesid
            LEFT JOIN buyer b    ON b.id   = bgd.buyerid
            WHERE tk.barcode = '$bc'
              AND (g.selling_pointid = $spid OR $spid = 0)
            LIMIT 1
        ");
        if (!$r || !$r->num_rows) { echo json_encode(['error'=>'Bale not found for barcode: '.htmlspecialchars($bc)]); exit; }
        $row = $r->fetch_assoc();
        // Selling 2 requires S1 capture first
        if ((int)$row['sold_id'] === 0) {
            echo json_encode(['error'=>'Bale has not been captured in Selling 1 yet.']);
            exit;
        }
        echo json_encode($row);
        exit;
    }

    // ── BATCH (s2_confirmed bales only for confirmed list) ────────────────────
    if ($ajax === 'batch') {
        $tgid = (int)($_GET['tgid'] ?? 0);
        if (!$tgid) { echo json_encode(['error'=>'tgid required']); exit; }
        $r = $conn->query("
            SELECT tk.id ticket_id, tk.barcode,
                   bj.lot, bj.bale_group,
                   ROUND(bm.mass,1) mass,
                   COALESCE(sb.id,0) sold_id,
                   COALESCE(sb.sale_code,'') sale_code,
                   COALESCE(sb.price,'') price_stored,
                   COALESCE(sb.s2_confirmed,0) s2_confirmed,
                   bgd.description buyer_grade,
                   COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name
            FROM tickets tk
            JOIN bale_junus bj   ON bj.id  = tk.bale_junusid
            JOIN bale_mass bm    ON bm.id  = bj.bale_massid
            JOIN transporter_growers tgr ON tgr.id = bj.transporter_growersid
            JOIN growers g       ON g.id   = tgr.growerid
            LEFT JOIN sold_bales sb  ON sb.ticketsid = tk.id
            LEFT JOIN buyer_grades bgd ON bgd.id = sb.buyer_gradesid
            LEFT JOIN buyer b    ON b.id   = bgd.buyerid
            WHERE bj.transporter_growersid = $tgid
              AND (g.selling_pointid = $spid OR $spid = 0)
            ORDER BY bj.lot ASC, bj.bale_group ASC
        ");
        $tickets = [];
        if ($r && $r !== true) while ($row = $r->fetch_assoc()) $tickets[] = $row;
        $total    = count($tickets);
        $captured = count(array_filter($tickets, fn($t)=>(int)$t['sold_id']>0));
        $s2done   = count(array_filter($tickets, fn($t)=>(int)$t['s2_confirmed']===1));
        echo json_encode(['tgid'=>$tgid,'total'=>$total,'captured'=>$captured,
                          's2done'=>$s2done,'remaining'=>$captured-$s2done,'tickets'=>$tickets]);
        exit;
    }

    // ── CAPTURE (Selling 2 — buyer grade + price only, preserves TIMB grade) ─
    if ($ajax === 'capture' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b    = json_decode(file_get_contents('php://input'), true) ?? [];
        $tid  = (int)($b['ticket_id']      ?? 0);
        $bgid = (int)($b['buyer_grade_id'] ?? 0);
        $price = (float)($b['price']       ?? 0);
        $sc   = strtoupper(trim($b['sale_code']  ?? ''));
        $rep  = $conn->real_escape_string(trim($b['sales_rep'] ?? ''));
        $sc_e = $conn->real_escape_string($sc);

        if (!$tid)  { echo json_encode(['error'=>'ticket_id required']); exit; }
        if (!$bgid) { echo json_encode(['error'=>'Buyer grade is required']); exit; }

        $is_rej = in_array($sc, ['R','CO','RO']);
        if (!$is_rej && $price <= 0) { echo json_encode(['error'=>'Price is required']); exit; }
        if ($is_rej) $price = 0;
        if ($price > 10) $price = round($price / 100, 4);
        $ps = $conn->real_escape_string(number_format($price, 4, '.', ''));

        // Verify ticket
        $chk = $conn->query("
            SELECT tk.id FROM tickets tk
            JOIN bale_junus bj ON bj.id = tk.bale_junusid
            JOIN transporter_growers tgr ON tgr.id = bj.transporter_growersid
            JOIN growers g ON g.id = tgr.growerid
            WHERE tk.id = $tid AND (g.selling_pointid = $spid OR $spid = 0) LIMIT 1
        ");
        if (!$chk || !$chk->num_rows) { echo json_encode(['error'=>'Ticket not found']); exit; }

        // Must have S1 record; preserve timb_gradesid
        $ex = $conn->query("SELECT id, timb_gradesid FROM sold_bales WHERE ticketsid=$tid LIMIT 1");
        if (!$ex || !$ex->num_rows) { echo json_encode(['error'=>'Selling 1 record not found — capture S1 first']); exit; }
        $erow  = $ex->fetch_assoc();
        $eid   = (int)$erow['id'];
        $tgid  = (int)$erow['timb_gradesid'];
        $conn->query("UPDATE sold_bales SET
            buyer_gradesid=$bgid, price='$ps', sale_code='$sc_e',
            sales_rep='$rep', userid=$userid, s2_confirmed=1,
            timb_gradesid=$tgid
            WHERE id=$eid");
        if ($conn->error) { echo json_encode(['error'=>'Update failed: '.$conn->error]); exit; }
        echo json_encode(['ok'=>true,'price_stored'=>$ps]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

// ── Page render ───────────────────────────────────────────────────────────────
$page_title = "Selling 2";

// Buyer grades (no TIMB grades needed)
$buyer_grades_arr = [];
$bg = $conn->query("
    SELECT bg.id, bg.description, COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name, b.id buyer_id, b.buyer_code
    FROM buyer_grades bg JOIN buyer b ON b.id=bg.buyerid
    WHERE (b.selling_pointid=$spid OR $spid=0) AND bg.active=1
    ORDER BY b.name, bg.description LIMIT 300
");
if ($bg && $bg !== true) while ($r = $bg->fetch_assoc()) $buyer_grades_arr[] = $r;

$buyer_grades_map = [];
$buyers_arr       = [];
$buyers_seen      = [];
foreach ($buyer_grades_arr as $r) {
    $bid = $r['buyer_id'];
    if (!isset($buyers_seen[$bid])) {
        $buyers_seen[$bid] = true;
        $buyers_arr[] = ['id'=>$bid,'name'=>$r['buyer_name'],'code'=>$r['buyer_code'] ?? ''];
    }
    $buyer_grades_map[$bid][] = ['id'=>$r['id'],'description'=>$r['description']];
}

// Today's S2 stats
$_sq = $conn->query("
    SELECT COUNT(*) c
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN transporter_growers tgr ON tgr.id=bj.transporter_growersid
    JOIN growers g ON g.id=tgr.growerid
    WHERE DATE(sb.created_at)='$today'
      AND sb.s2_confirmed=1
      AND (g.selling_pointid=$spid OR $spid=0)
");
$s2count = ($_sq && $_sq !== true) ? (int)$_sq->fetch_assoc()['c'] : 0;

include "includes/layout.php";
?>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="barcode_scanner.js"></script>


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

.selling-tab-panel {
    display: none;
}
.selling-tab-panel.active {
    display: block;
    animation: sell2FadeIn 0.25s ease-in-out;
}

@keyframes sell2FadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.selling-form-container {
    max-width: 640px;
    margin: 0 auto;
}
</style>

<div class="page-header">
    <div class="page-header-left">
        <h2>Selling 2 &mdash; Buyer Confirmation</h2>
        <p><span id="header-counts"><?= $s2count ?> bale<?= $s2count !== 1 ? 's' : '' ?> S2-confirmed today</span></p>
    </div>
</div>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="confirm" onclick="switchSelling2Tab('confirm')">
        💵 Confirm Price
    </button>
    <button class="premium-tab-btn" data-tab="batch" onclick="switchSelling2Tab('batch')">
        📦 Batch Delivery <span class="badge badge-gold" id="batch-tab-badge" style="display:none; font-size:.72rem; margin-left:4px"></span>
    </button>
    <button class="premium-tab-btn" data-tab="log" onclick="switchSelling2Tab('log')">
        📋 Instructions &amp; Activity <span class="badge badge-gray" id="s2TodayBadge" style="font-size:.72rem; margin-left:4px"><?= $s2count ?></span>
    </button>
</div>

<!-- Tab Panel 1: Confirm Price Form -->
<div id="panel-confirm" class="selling-tab-panel active">
    <div class="selling-form-container">
        <!-- BARCODE INPUT -->
        <div class="card" style="margin-bottom:1rem">
            <div class="card-body" style="padding:1rem">
                <div style="display:flex; gap:.5rem">
                    <input id="etBarcode" type="text" class="form-control"
                           placeholder="Scan or type barcode…"
                           autofocus autocomplete="off"
                           style="font-family:monospace;font-size:1.05rem;letter-spacing:.06em;flex:1"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();lookupBale();}">
                    <button class="btn btn-ghost" onclick="FloworkScanner.scan(function(bc){ document.getElementById('etBarcode').value=bc; lookupBale(); })" style="white-space:nowrap;border-color:var(--gold);color:var(--gold)" title="Scan with camera">
                        📷 Scan
                    </button>
                    <button class="btn btn-primary" onclick="lookupBale()" style="white-space:nowrap">
                        🔍 Lookup
                    </button>
                </div>
            </div>
        </div>

        <!-- BALE INFO CARD -->
        <div id="baleCard" class="card" style="display:none; margin-bottom:1rem">
            <div class="card-body" style="padding:1rem">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:.5rem">
                    <span style="font-size:.7rem;font-weight:700;letter-spacing:.1em;color:var(--text-muted);text-transform:uppercase">Bale &amp; Selling 1 Details</span>
                    <span id="baleStatus" style="font-size:.7rem;font-weight:600;padding:.2rem .6rem;border-radius:4px"></span>
                </div>
                <div id="baleInfo" style="font-family:monospace; font-size:.82rem; color:var(--text-secondary); line-height:1.8"></div>
            </div>
        </div>

        <!-- SELLING 2 FORM -->
        <div id="formCard" class="card" style="display:none; margin-bottom:1rem">
            <div class="card-head"><h3>Selling 2 — Confirm Price</h3></div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label>Buyer <span style="color:var(--danger)">*</span></label>
                        <select id="buyerSelect" class="form-control" onchange="onBuyerChange(this.value)">
                            <option value="">— Select Buyer —</option>
                            <?php foreach ($buyers_arr as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['code'] ?: $r['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Buyer Grade <span style="color:var(--danger)">*</span></label>
                        <select id="buyerGrade" class="form-control">
                            <option value="">— Select buyer first —</option>
                        </select>
                    </div>
                </div>

                <!-- Price — intentionally blank, no S1 price shown -->
                <div class="form-group" style="margin-bottom:.4rem">
                    <label>Confirmed Price (USD/kg) <span style="color:var(--danger)">*</span></label>
                    <input id="priceInput" type="number" class="form-control"
                           min="0" step="0.0001" placeholder="0.0000"
                           oninput="checkPriceDiff()"
                           style="font-size:1.4rem; font-weight:700; color:#1A4D2E; letter-spacing:.04em">
                </div>

                <!-- Real-time price match indicator -->
                <div id="priceIndicator" style="display:none; font-size:.78rem; font-weight:600; padding:.4rem .75rem; border-radius:6px; margin-bottom:.75rem"></div>
                <div id="statusMsg" style="display:none; margin-bottom:.75rem; padding:.6rem .9rem; border-radius:6px; font-size:.82rem"></div>

                <div style="display:flex; gap:.5rem">
                    <button id="btnCapture" class="btn btn-primary" onclick="captureForm()" style="flex:1; padding:.75rem">
                        ✓ Confirm Sale
                    </button>
                    <button class="btn btn-ghost" onclick="resetAll()" style="padding:.75rem .9rem" title="Clear">✕</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 2: Batch Panel -->
<div id="panel-batch" class="selling-tab-panel">
    <div class="selling-form-container">
        <div id="confirmedCard" class="card">
            <div class="card-head">
                <h3>Confirmed This Batch</h3>
                <span id="confirmedBadge" class="badge badge-gold"></span>
            </div>
            <div class="card-body" style="padding:.75rem 1rem">
                <div id="confirmedCounts" style="font-size:.78rem; color:var(--text-muted); margin-bottom:.5rem"></div>
                <!-- Column headers -->
                <div style="display:grid; grid-template-columns:1fr auto auto; gap:.5rem;
                     padding:.2rem .2rem .4rem; border-bottom:1px solid rgba(0,0,0,.08);
                     font-size:.78rem; font-weight:700; color:var(--text-muted)">
                    <span>Barcode</span>
                    <span>Buyer / Grade</span>
                    <span style="text-align:right; min-width:70px">Price</span>
                </div>
                <div id="confirmedRows"></div>
            </div>
        </div>
        <div id="noBatchCard" class="card" style="display:block">
            <div class="card-body text-center text-muted" style="padding:2.5rem">
                No batch delivery active. Scan a bale barcode to start tracking.
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 3: Instructions & Activity -->
<div id="panel-log" class="selling-tab-panel">
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start">
        <div class="card">
            <div class="card-head">
                <h3>How Selling 2 Works</h3>
            </div>
            <div class="card-body" style="font-size:.82rem; color:var(--text-secondary); line-height:1.7">
                <ol style="padding-left:1.2rem; margin:0">
                    <li style="margin-bottom:.6rem">Scan the bale barcode — the system retrieves it from Selling 1.</li>
                    <li style="margin-bottom:.6rem">Select the <strong>Buyer Grade</strong> the buyer confirmed.</li>
                    <li style="margin-bottom:.6rem">Enter the <strong>agreed price</strong> — the system will verify it matches the Selling 1 price.</li>
                    <li style="margin-bottom:.6rem">If the price <strong>matches</strong>, the sale is confirmed. If it differs, you will be warned and must correct it.</li>
                    <li>Confirmed bales appear in the list below with their final price.</li>
                </ol>
                <hr style="margin:.75rem 0; border:none; border-top:1px solid rgba(0,0,0,.07)">
                <p style="margin:0; font-size:.75rem; color:var(--text-muted)">
                    ⚠ The Selling 1 price is <strong>not displayed</strong> here &mdash; this is intentional.
                    The buyer rep must enter the price from the buyer's confirmed sheet independently.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h3>Today's S2 Activity</h3>
            </div>
            <div class="card-body" style="padding:.5rem 1rem">
                <div id="s2TodayList" style="font-size:.78rem; color:var(--text-muted)">
                    <span style="color:var(--text-muted)">Scan a bale to see batch details.</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const BUYERS       = <?= json_encode($buyers_arr) ?>;
const BUYER_GRADES = <?= json_encode($buyer_grades_map) ?>;

let currentTicketId = 0;
let currentTgid     = 0;
let s1Price         = 0;   // stored internally — NEVER displayed in the form

function switchSelling2Tab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.selling-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_selling2_tab', tabId);
        
        if (tabId === 'confirm') {
            setTimeout(() => {
                const el = document.getElementById('etBarcode');
                if (el) el.focus();
            }, 50);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const defaultTab = sessionStorage.getItem('active_selling2_tab') || 'confirm';
    switchSelling2Tab(defaultTab);
});

// ── Lookup ────────────────────────────────────────────────────────────────────
function lookupBale() {
    const bc = document.getElementById('etBarcode').value.trim();
    if (!bc) return;
    clearStatus();
    fetch('selling2.php?ajax=lookup&barcode=' + encodeURIComponent(bc),
          {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if (d.error) { showStatus(d.error, false); return; }
        showBaleInfo(d);
    }).catch(()=>showStatus('Network error', false));
}

function showBaleInfo(d) {
    currentTicketId = parseInt(d.ticket_id) || 0;
    currentTgid     = parseInt(d.tgid) || 0;

    // Store s1Price internally — do NOT display it
    let raw = parseFloat(d.price_stored) || 0;
    s1Price = (raw > 10) ? raw / 100 : raw;

    const s2conf = parseInt(d.s2_confirmed) === 1;
    const sc     = (d.sale_code || '').toUpperCase();
    const rej    = ['CO','RO','R'].indexOf(sc) !== -1;

    // Status badge
    let statusHtml, statusStyle;
    if (s2conf) {
        statusHtml  = '✓ S2 Already Confirmed';
        statusStyle = 'background:#E8F5E9;color:#1B5E20';
    } else {
        statusHtml  = '⏳ Awaiting S2 Confirmation';
        statusStyle = 'background:#FFF8E1;color:#F57C00';
    }
    const statusEl = document.getElementById('baleStatus');
    statusEl.innerHTML  = statusHtml;
    statusEl.style.cssText = statusStyle + ';font-size:.7rem;font-weight:600;padding:.2rem .6rem;border-radius:4px';

    // Bale info — deliberately omit S1 price
    document.getElementById('baleInfo').innerHTML =
        'Barcode: <b>' + d.barcode + '</b> &nbsp;|&nbsp; Mass: <b>' + parseFloat(d.mass||0).toFixed(1) + ' kg</b><br>' +
        'Grower: <b>' + d.grower_num + '</b> — ' + d.grower_name + ' ' + d.surname + '<br>' +
        'Lot: <b>' + (d.lot||'—') + '</b> &nbsp; Group: <b>' + (d.bale_group||'—') + '</b>' +
        (d.timb_grade ? '<br>TIMB Grade: <b>' + d.timb_grade + '</b>' : '') +
        (rej ? ' &nbsp; <span style="color:var(--bad-text);font-weight:600">' + sc + ' (Rejected)</span>' : '');

    document.getElementById('baleCard').style.display = '';
    document.getElementById('formCard').style.display = '';

    // Pre-select buyer grade if already captured
    if (d.buyer_id) {
        document.getElementById('buyerSelect').value = d.buyer_id;
        onBuyerChange(d.buyer_id, d.buyer_grade_id);
    } else {
        document.getElementById('buyerSelect').value = '';
        document.getElementById('buyerGrade').innerHTML = '<option value="">— Select buyer first —</option>';
    }

    // Always blank the price field — blind entry
    document.getElementById('priceInput').value = '';
    document.getElementById('priceIndicator').style.display = 'none';

    // Load confirmed batch
    if (currentTgid) loadBatch(currentTgid);

    // Handle rejection codes — price is 0, show hint
    if (rej) {
        document.getElementById('priceInput').value    = '0';
        document.getElementById('priceInput').readOnly = true;
        document.getElementById('priceInput').style.background = '#fff0f0';
        document.getElementById('priceInput').style.color      = '#c03030';
    } else {
        document.getElementById('priceInput').readOnly = false;
        document.getElementById('priceInput').style.background = '';
        document.getElementById('priceInput').style.color      = '#1A4D2E';
        setTimeout(()=>document.getElementById('priceInput').focus(), 50);
    }
}

// ── Real-time price indicator ─────────────────────────────────────────────────
function checkPriceDiff() {
    const indEl = document.getElementById('priceIndicator');
    const entered = parseFloat(document.getElementById('priceInput').value) || 0;
    if (!entered || s1Price <= 0) { indEl.style.display = 'none'; return; }

    const diff = entered - s1Price;
    const absDiff = Math.abs(diff);
    if (absDiff < 0.0001) {
        indEl.innerHTML    = '✓ Price matches';
        indEl.style.display = '';
        indEl.style.background = '#E8F5E9';
        indEl.style.color      = '#1B5E20';
        document.getElementById('priceInput').style.borderColor = '#4CAF50';
    } else {
        const sign = diff > 0 ? '+' : '';
        indEl.innerHTML    = '⚠ Variance: <b>' + sign + diff.toFixed(4) + '</b> from S1 price';
        indEl.style.display = '';
        indEl.style.background = '#FFF3E0';
        indEl.style.color      = '#E65100';
        document.getElementById('priceInput').style.borderColor = '#FF9800';
    }
}

// ── Buyer grade cascade ───────────────────────────────────────────────────────
function onBuyerChange(bid, preselect) {
    const sel = document.getElementById('buyerGrade');
    sel.innerHTML = '<option value="">— Select Grade —</option>';
    const grades = BUYER_GRADES[bid] || [];
    grades.forEach(g => {
        const o = document.createElement('option');
        o.value = g.id; o.textContent = g.description;
        if (preselect && parseInt(preselect) === g.id) o.selected = true;
        sel.appendChild(o);
    });
}
document.getElementById('buyerSelect').addEventListener('change', function(){
    onBuyerChange(this.value);
});

// ── Capture ───────────────────────────────────────────────────────────────────
function captureForm() {
    if (!currentTicketId) { showStatus('Scan a bale first', false); return; }
    const bgid  = parseInt(document.getElementById('buyerGrade').value) || 0;
    const price = parseFloat(document.getElementById('priceInput').value) || 0;
    const sc    = '';  // S2 doesn't set sale codes; rejection already handled in S1

    if (!bgid)       { showStatus('Buyer grade is required', false); return; }
    if (price <= 0)  { showStatus('Price is required', false); return; }

    // Variance check — block if price differs from S1
    if (s1Price > 0 && Math.abs(price - s1Price) >= 0.0001) {
        const diff  = price - s1Price;
        const sign  = diff > 0 ? '+' : '';
        window.confirm(
            'Price variance detected!\n\n' +
            'Entered price : $' + price.toFixed(4) + '\n' +
            'Variance      : ' + sign + diff.toFixed(4) + '\n\n' +
            'Prices do not match. The sale cannot be confirmed with a price difference.\n' +
            'Please correct the price and try again.\n\n' +
            'Press OK to go back and correct the price.'
        );
        return;
    }

    document.getElementById('btnCapture').disabled = true;
    document.getElementById('btnCapture').textContent = 'Saving…';

    const savedTgid = currentTgid;

    fetch('selling2.php?ajax=capture', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({
            ticket_id: currentTicketId,
            buyer_grade_id: bgid,
            price: price,
            sale_code: sc
        })
    })
    .then(r=>r.json()).then(d=>{
        document.getElementById('btnCapture').disabled = false;
        document.getElementById('btnCapture').textContent = '✓ Confirm Sale';
        if (d.error) { showStatus('❌ ' + d.error, false); return; }

        showStatus('✓ S2 Confirmed — $' + parseFloat(d.price_stored).toFixed(4), true);
        if (savedTgid) loadBatch(savedTgid);
        loadS2Today();
        resetForm();
    }).catch(e=>{
        document.getElementById('btnCapture').disabled = false;
        document.getElementById('btnCapture').textContent = '✓ Confirm Sale';
        showStatus('Network error: ' + e.message, false);
    });
}

// ── Batch panel ───────────────────────────────────────────────────────────────
function loadBatch(tgid) {
    fetch('selling2.php?ajax=batch&tgid=' + tgid,
          {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if (d.error || !d.tickets) return;
        const card   = document.getElementById('confirmedCard');
        const noCard = document.getElementById('noBatchCard');
        const rowsEl = document.getElementById('confirmedRows');
        const badge  = document.getElementById('confirmedBadge');
        const counts = document.getElementById('confirmedCounts');
        const tabBadge = document.getElementById('batch-tab-badge');

        if (noCard) noCard.style.display = 'none';
        badge.textContent = d.s2done + ' / ' + d.captured + ' S1 bales';
        counts.innerHTML  = '<b style="color:#1A4D2E">' + d.s2done + '</b> S2 confirmed &nbsp;·&nbsp; ' +
                            '<b style="color:' + (d.remaining>0?'#F57C00':'#388E3C') + '">' + d.remaining + '</b> pending S2';

        if (tabBadge) {
            tabBadge.textContent = d.remaining + ' pending';
            tabBadge.style.display = d.remaining > 0 ? '' : 'none';
        }

        let html = '';
        d.tickets.forEach(t => {
            const sold   = parseInt(t.sold_id) > 0;
            const s2conf = parseInt(t.s2_confirmed) === 1;
            const rej    = sold && t.sale_code && ['CO','RO','R'].indexOf(t.sale_code.toUpperCase()) !== -1;
            if (!sold) return; // skip uncaptured

            let pr = parseFloat(t.price_stored) || 0;
            if (pr > 10) pr = pr / 100;

            html += '<div style="display:grid;grid-template-columns:1fr auto auto;gap:.5rem;' +
                    'align-items:center;padding:.4rem .2rem;border-bottom:1px solid rgba(0,0,0,.05)">';
            html += '<code style="font-size:.72rem">' + t.barcode + '</code>';
            html += '<span style="font-size:.7rem;color:var(--text-muted)">' + (t.buyer_grade || t.buyer_name || '—') + '</span>';

            if (rej) {
                html += '<span style="font-size:.68rem;background:var(--surface-2);color:var(--bad-text);padding:.15rem .4rem;border-radius:3px;text-align:right">' + t.sale_code.toUpperCase() + '</span>';
            } else if (s2conf) {
                html += '<span style="font-size:.72rem;font-family:monospace;font-weight:700;color:#1A4D2E;text-align:right;min-width:70px">$' + pr.toFixed(4) + '</span>';
            } else {
                html += '<span style="font-size:.68rem;background:var(--surface-2);color:#9E9E9E;padding:.15rem .4rem;border-radius:3px;text-align:right">S2 pending</span>';
            }
            html += '</div>';
        });

        if (!html) html = '<div style="padding:.75rem;font-size:.78rem;color:var(--text-muted)">No captured bales in this batch yet.</div>';
        rowsEl.innerHTML = html;
        card.style.display = '';
    }).catch(()=>{});
}

// ── Today's S2 activity (right panel) ────────────────────────────────────────
function loadS2Today() {
    const badge = document.getElementById('s2TodayBadge');
    const curr  = parseInt(badge.textContent) || 0;
    badge.textContent = (curr + 1);
    
    const countText = (curr + 1) + ' bale' + ((curr + 1) !== 1 ? 's' : '') + ' S2-confirmed today';
    document.getElementById('header-counts').textContent = countText;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function showStatus(msg, ok) {
    const el = document.getElementById('statusMsg');
    el.textContent   = msg;
    el.style.display = '';
    el.style.background = ok ? '#E8F5E9' : '#FFEBEE';
    el.style.color      = ok ? '#1B5E20' : '#C62828';
}
function clearStatus() {
    document.getElementById('statusMsg').style.display = 'none';
    document.getElementById('priceIndicator').style.display = 'none';
}
function resetForm() {
    document.getElementById('etBarcode').value  = '';
    document.getElementById('baleCard').style.display  = 'none';
    document.getElementById('formCard').style.display  = 'none';
    document.getElementById('priceInput').style.borderColor = '';
    currentTicketId = 0;
    s1Price = 0;
    document.getElementById('etBarcode').focus();
}
function resetAll() {
    resetForm();
    document.getElementById('confirmedCard').style.display = 'none';
    const noCard = document.getElementById('noBatchCard');
    if (noCard) noCard.style.display = '';
    const tabBadge = document.getElementById('batch-tab-badge');
    if (tabBadge) tabBadge.style.display = 'none';
    currentTgid = 0;
    clearStatus();
}
</script>

<?php include "includes/layout_end.php"; ?>
