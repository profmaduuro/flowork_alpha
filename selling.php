<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$active_page = "selling";
$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$today  = date('Y-m-d');

// ── One-time migration: ensure s2_confirmed column exists ────────────────────
$_chk = $conn->query("SHOW COLUMNS FROM sold_bales LIKE 's2_confirmed'");
if ($_chk && $_chk->num_rows === 0)
    $conn->query("ALTER TABLE sold_bales ADD COLUMN s2_confirmed TINYINT NOT NULL DEFAULT 0");

// ── AJAX handlers (called with X-Requested-With: XMLHttpRequest) ─────────────
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
        echo json_encode($r->fetch_assoc());
        exit;
    }

    // ── BATCH STATUS ──────────────────────────────────────────────────────────
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
                   tgd.description timb_grade,
                   bgd.description buyer_grade,
                   COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name
            FROM tickets tk
            JOIN bale_junus bj   ON bj.id  = tk.bale_junusid
            JOIN bale_mass bm    ON bm.id  = bj.bale_massid
            JOIN transporter_growers tgr ON tgr.id = bj.transporter_growersid
            JOIN growers g       ON g.id   = tgr.growerid
            LEFT JOIN sold_bales sb  ON sb.ticketsid = tk.id
            LEFT JOIN timb_grades tgd ON tgd.id = sb.timb_gradesid
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
        echo json_encode(['tgid'=>$tgid,'total'=>$total,'captured'=>$captured,
                          'remaining'=>$total-$captured,'tickets'=>$tickets]);
        exit;
    }

    // ── CAPTURE ───────────────────────────────────────────────────────────────
    if ($ajax === 'capture' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b    = json_decode(file_get_contents('php://input'), true) ?? [];
        $tid  = (int)($b['ticket_id']      ?? 0);
        $tgid = (int)($b['timb_grade_id']  ?? 0);
        $bgid = (int)($b['buyer_grade_id'] ?? 0);
        $price = (float)($b['price']       ?? 0);
        $sc   = strtoupper(trim($b['sale_code']  ?? ''));
        $rep  = $conn->real_escape_string(trim($b['sales_rep'] ?? ''));
        $sc_e = $conn->real_escape_string($sc);

        if (!$tid)  { echo json_encode(['error'=>'ticket_id required']); exit; }
        if (!$tgid) { echo json_encode(['error'=>'TIMB grade is required']); exit; }
        if (!$bgid) { echo json_encode(['error'=>'Buyer grade is required']); exit; }

        $is_rej = in_array($sc, ['R','CO','RO']);
        if ($is_rej) $price = 0;
        elseif ($price <= 0) { echo json_encode(['error'=>'Price is required for sold bales']); exit; }

        if ($price > 10) $price = round($price / 100, 4);
        $ps = $conn->real_escape_string(number_format($price, 4, '.', ''));

        // Verify ticket belongs to this floor
        $chk = $conn->query("
            SELECT tk.id, tk.barcode FROM tickets tk
            JOIN bale_junus bj ON bj.id = tk.bale_junusid
            JOIN transporter_growers tgr ON tgr.id = bj.transporter_growersid
            JOIN growers g ON g.id = tgr.growerid
            WHERE tk.id = $tid AND (g.selling_pointid = $spid OR $spid = 0) LIMIT 1
        ");
        if (!$chk || !$chk->num_rows) { echo json_encode(['error'=>'Ticket not found on this floor']); exit; }

        $chk_row = $chk->fetch_assoc();
        $barcode = $chk_row['barcode'];

        $buyer_q = $conn->query("SELECT COALESCE(NULLIF(b.buyer_code,''), b.name) AS name FROM buyer_grades bg JOIN buyer b ON b.id = bg.buyerid WHERE bg.id = $bgid");
        $buyer_name = 'Unknown';
        if ($buyer_q && $buyer_q->num_rows > 0) {
            $buyer_name = $buyer_q->fetch_assoc()['name'];
        }

        $ex = $conn->query("SELECT id FROM sold_bales WHERE ticketsid=$tid LIMIT 1");
        if ($ex && $ex->num_rows > 0) {
            $eid = (int)$ex->fetch_assoc()['id'];
            $conn->query("UPDATE sold_bales SET
                timb_gradesid=$tgid, buyer_gradesid=$bgid,
                price='$ps', sale_code='$sc_e', sales_rep='$rep',
                userid=$userid, s2_confirmed=0
                WHERE id=$eid");
            if ($conn->error) { echo json_encode(['error'=>'Update failed: '.$conn->error]); exit; }
            $conn->query("INSERT INTO event_notifications (event_type, message) VALUES ('bale_sold', 'Bale sold: " . $conn->real_escape_string($barcode) . " to " . $conn->real_escape_string($buyer_name) . " for $" . $ps . "/kg (Updated)')");
            echo json_encode(['ok'=>true,'updated'=>true,'price_stored'=>$ps]);
        } else {
            $conn->query("INSERT INTO sold_bales
                (userid,ticketsid,sales_rep,sale_code,timb_gradesid,buyer_gradesid,price,s2_confirmed,created_at)
                VALUES ($userid,$tid,'$rep','$sc_e',$tgid,$bgid,'$ps',0,'$today')");
            if ($conn->error) { echo json_encode(['error'=>'Capture failed: '.$conn->error]); exit; }
            $conn->query("INSERT INTO event_notifications (event_type, message) VALUES ('bale_sold', 'Bale sold: " . $conn->real_escape_string($barcode) . " to " . $conn->real_escape_string($buyer_name) . " for $" . $ps . "/kg')");
            echo json_encode(['ok'=>true,'updated'=>false,'price_stored'=>$ps,'id'=>(int)$conn->insert_id]);
        }
        exit;
    }

    // ── FLOOR LOG ─────────────────────────────────────────────────────────────
    if ($ajax === 'floor_log') {
        $r = $conn->query("
            SELECT sb.id, sb.sale_code, sb.price, sb.created_at,
                   tk.barcode,
                   g.grower_num, g.name, g.surname,
                   tg2.description timb_grade,
                   bg2.description buyer_grade,
                   COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name,
                   bm.mass
            FROM sold_bales sb
            JOIN tickets tk  ON tk.id   = sb.ticketsid
            JOIN bale_junus bj ON bj.id = tk.bale_junusid
            JOIN bale_mass bm ON bm.id  = bj.bale_massid
            JOIN transporter_growers tgr ON tgr.id = bj.transporter_growersid
            JOIN growers g   ON g.id    = tgr.growerid
            JOIN timb_grades tg2 ON tg2.id = sb.timb_gradesid
            JOIN buyer_grades bg2 ON bg2.id = sb.buyer_gradesid
            JOIN buyer b     ON b.id    = bg2.buyerid
            WHERE DATE(sb.created_at)='$today' AND (g.selling_pointid=$spid OR $spid=0)
            ORDER BY sb.id DESC LIMIT 40
        ");
        $rows = [];
        if ($r && $r !== true) while ($row = $r->fetch_assoc()) $rows[] = $row;
        echo json_encode($rows);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

// ── Page render ───────────────────────────────────────────────────────────────
$page_title = "Selling 1";

// Reference data for dropdowns
$timb_grades_arr = [];
$tg = $conn->query("SELECT id, description FROM timb_grades WHERE active=1 ORDER BY description LIMIT 300");
if ($tg && $tg !== true) while ($r = $tg->fetch_assoc()) $timb_grades_arr[] = $r;

$buyer_grades_arr = [];
$bg = $conn->query("
    SELECT bg.id, bg.description, COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name, b.id buyer_id, b.buyer_code
    FROM buyer_grades bg JOIN buyer b ON b.id=bg.buyerid
    WHERE (b.selling_pointid=$spid OR $spid=0) AND bg.active=1
    ORDER BY b.name, bg.description LIMIT 300
");
if ($bg && $bg !== true) while ($r = $bg->fetch_assoc()) $buyer_grades_arr[] = $r;

// Build buyer map for JS cascade
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

// Today's stats
$_sq = $conn->query("
    SELECT COUNT(*) c,
           COALESCE(SUM(CAST(price AS DECIMAL(10,4))),0) usd,
           COALESCE(AVG(CAST(price AS DECIMAL(10,4))),0) avg_price
    FROM sold_bales sb
    JOIN tickets tk ON tk.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN transporter_growers tgr ON tgr.id=bj.transporter_growersid
    JOIN growers g ON g.id=tgr.growerid
    WHERE DATE(sb.created_at)='$today' AND (g.selling_pointid=$spid OR $spid=0)
");
$ts = ($_sq && $_sq !== true) ? $_sq->fetch_assoc() : ['c'=>0,'usd'=>0,'avg_price'=>0];

// Initial floor log
$_rq = $conn->query("
    SELECT sb.id, sb.sale_code, sb.price, sb.created_at,
           tk.barcode,
           g.grower_num, g.name, g.surname,
           tg2.description timb_grade,
           bg2.description buyer_grade,
           COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name,
           bm.mass
    FROM sold_bales sb
    JOIN tickets tk  ON tk.id   = sb.ticketsid
    JOIN bale_junus bj ON bj.id = tk.bale_junusid
    JOIN bale_mass bm ON bm.id  = bj.bale_massid
    JOIN transporter_growers tgr ON tgr.id = bj.transporter_growersid
    JOIN growers g   ON g.id    = tgr.growerid
    JOIN timb_grades tg2 ON tg2.id = sb.timb_gradesid
    JOIN buyer_grades bg2 ON bg2.id = sb.buyer_gradesid
    JOIN buyer b     ON b.id    = bg2.buyerid
    WHERE DATE(sb.created_at)='$today' AND (g.selling_pointid=$spid OR $spid=0)
    ORDER BY sb.id DESC LIMIT 40
");
$recent_rows = [];
if ($_rq && $_rq !== true) while ($r = $_rq->fetch_assoc()) $recent_rows[] = $r;

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

.selling-tab-panel {
    display: none;
}
.selling-tab-panel.active {
    display: block;
    animation: sellFadeIn 0.25s ease-in-out;
}

@keyframes sellFadeIn {
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
        <h2>Selling 1 &mdash; Capture</h2>
        <p><span id="header-counts"><?= number_format((int)$ts['c']) ?> sold today &nbsp;&middot;&nbsp;
           $<?= number_format((float)$ts['usd'],2) ?> gross &nbsp;&middot;&nbsp;
           avg $<?= number_format((float)$ts['avg_price'],4) ?>/kg</span></p>
    </div>
</div>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="capture" onclick="switchSellingTab('capture')">
        💵 Capture Sale
    </button>
    <button class="premium-tab-btn" data-tab="batch" onclick="switchSellingTab('batch')">
        📦 Batch Delivery <span class="badge badge-gold" id="batch-tab-badge" style="display:none; font-size:.62rem; margin-left:4px"></span>
    </button>
    <button class="premium-tab-btn" data-tab="log" onclick="switchSellingTab('log')">
        📋 Floor Log <span class="badge badge-gray" id="logBadge" style="font-size:.62rem; margin-left:4px"><?= number_format((int)$ts['c']) ?></span>
    </button>
</div>

<!-- Tab Panel 1: Capture Sale Form -->
<div id="panel-capture" class="selling-tab-panel active">
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
                    <span style="font-size:.7rem; font-weight:700; letter-spacing:.1em; color:var(--text-muted); text-transform:uppercase">Bale Details</span>
                    <span id="baleStatus" style="font-size:.7rem; font-weight:600; padding:.2rem .6rem; border-radius:4px"></span>
                </div>
                <div id="baleInfo" style="font-family:monospace; font-size:.82rem; color:var(--text-secondary); line-height:1.8"></div>
            </div>
        </div>

        <!-- CAPTURE FORM -->
        <div id="formCard" class="card" style="display:none; margin-bottom:1rem">
            <div class="card-head"><h3>Capture Sale</h3></div>
            <div class="card-body">
                <div class="form-row cols-2" style="margin-bottom:.75rem">
                    <div class="form-group" style="margin-bottom:0">
                        <label>Sale Code</label>
                        <select id="saleCode" class="form-control" onchange="onSaleCodeChange(this.value)">
                            <option value="">— Sold —</option>
                            <option value="CO">CO — Collect (Rejected)</option>
                            <option value="RO">RO — Re-Offer (Rejected)</option>
                            <option value="R">R — Rejected</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label>Sales Rep</label>
                        <input id="salesRep" type="text" class="form-control" placeholder="Optional">
                    </div>
                </div>

                <div class="form-group">
                    <label>TIMB Grade <span style="color:var(--danger)">*</span></label>
                    <select id="timbGrade" class="form-control">
                        <option value="">— Select TIMB Grade —</option>
                        <?php foreach ($timb_grades_arr as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['description']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

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

                <div class="form-group" style="margin-bottom:.5rem">
                    <label>Price per kg (USD) <span id="priceRequired" style="color:var(--danger)">*</span></label>
                    <input id="priceInput" type="number" class="form-control"
                           min="0" step="0.0001" placeholder="0.0000"
                           style="font-size:1.3rem; font-weight:600; color:#1A4D2E">
                </div>
                <div id="saleCodeHint" style="font-size:.7rem;margin-bottom:.75rem;display:none;color:#c03030"></div>
                <div id="statusMsg" style="display:none; margin-bottom:.75rem; padding:.6rem .9rem; border-radius:6px; font-size:.82rem"></div>

                <div style="display:flex; gap:.5rem">
                    <button id="btnCapture" class="btn btn-primary" onclick="captureForm()" style="flex:1; padding:.75rem">
                        ✓ Confirm Sale
                    </button>
                    <button class="btn btn-ghost" onclick="resetAll()" style="padding:.75rem .9rem" title="Clear all">✕</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 2: Batch Panel -->
<div id="panel-batch" class="selling-tab-panel">
    <div class="selling-form-container">
        <div id="batchCard" class="card">
            <div class="card-head">
                <h3>Batch — Same Delivery</h3>
                <span id="batchBadge" class="badge badge-gold"></span>
            </div>
            <div class="card-body" style="padding:.75rem 1rem">
                <div id="batchCounts" style="font-size:.78rem; color:var(--text-muted); margin-bottom:.5rem"></div>
                <div id="batchRows"></div>
            </div>
        </div>
        <div id="noBatchCard" class="card" style="display:block">
            <div class="card-body text-center text-muted" style="padding:2.5rem">
                No batch delivery active. Scan a bale barcode to start tracking.
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 3: Today's Floor Log -->
<div id="panel-log" class="selling-tab-panel">
    <div class="card">
        <div class="card-head">
            <h3>Today's Floor Log</h3>
        </div>
        <div class="table-wrap" style="max-height:580px; overflow-y:auto">
            <table id="floorLogTable">
                <thead>
                    <tr>
                        <th>Barcode</th>
                        <th>Grower</th>
                        <th>TIMB</th>
                        <th>Grade</th>
                        <th>Buyer</th>
                        <th class="text-right">Mass</th>
                        <th class="text-right">Price</th>
                    </tr>
                </thead>
                <tbody id="floorLogBody">
                <?php if ($recent_rows): ?>
                <?php foreach ($recent_rows as $r):
                    $rej = strtoupper(substr(trim($r['sale_code']??''),0,1))==='R';
                    $pr  = (float)$r['price'];
                    if ($pr > 10) $pr = round($pr/100, 4);
                ?>
                <tr <?= $rej ? 'style="opacity:.55"' : '' ?>>
                    <td><code style="font-size:.72rem"><?= htmlspecialchars($r['barcode']) ?></code>
                        <?php if ($rej): ?><span class="badge badge-danger" style="font-size:.55rem;margin-left:3px"><?= htmlspecialchars(strtoupper(trim($r['sale_code']))) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-500" style="font-size:.76rem"><?= htmlspecialchars($r['grower_num']) ?></div>
                        <div class="td-muted" style="font-size:.7rem"><?= htmlspecialchars($r['name'].' '.$r['surname']) ?></div>
                    </td>
                    <td><span class="badge badge-gold" style="font-size:.6rem"><?= htmlspecialchars($r['timb_grade']) ?></span></td>
                    <td class="td-muted" style="font-size:.75rem"><?= htmlspecialchars($r['buyer_grade']) ?></td>
                    <td class="td-muted" style="font-size:.75rem"><?= htmlspecialchars($r['buyer_name']) ?></td>
                    <td class="td-mono text-right"><?= number_format((float)$r['mass'],1) ?>kg</td>
                    <td class="td-mono text-right fw-500">
                        <?= $rej ? '<span style="color:#c03030">REJ</span>' : '$'.number_format($pr,4) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
                <tr><td colspan="7" class="text-center text-muted" style="padding:2rem">No sales captured today.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const BUYERS      = <?= json_encode($buyers_arr) ?>;
const BUYER_GRADES = <?= json_encode($buyer_grades_map) ?>;
const REJECTION_CODES = ['CO','RO','R'];

let currentTicketId = 0;
let currentTgid     = 0;

function switchSellingTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.selling-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_selling_tab', tabId);
        
        if (tabId === 'capture') {
            setTimeout(() => {
                const el = document.getElementById('etBarcode');
                if (el) el.focus();
            }, 50);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const defaultTab = sessionStorage.getItem('active_selling_tab') || 'capture';
    switchSellingTab(defaultTab);
});

// ── Lookup ────────────────────────────────────────────────────────────────────
function lookupBale() {
    const bc = document.getElementById('etBarcode').value.trim();
    if (!bc) return;
    clearStatus();
    fetch('selling.php?ajax=lookup&barcode=' + encodeURIComponent(bc),
          {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if (d.error) { showStatus(d.error, false); return; }
        showBaleInfo(d);
    }).catch(()=>showStatus('Network error',false));
}

function showBaleInfo(d) {
    currentTicketId = parseInt(d.ticket_id) || 0;
    currentTgid     = parseInt(d.tgid) || 0;

    // Bale card
    const sold    = parseInt(d.sold_id) > 0;
    const rej     = sold && d.sale_code && ['CO','RO','R'].indexOf(d.sale_code.toUpperCase()) !== -1;
    const s2conf  = parseInt(d.s2_confirmed) === 1;

    let statusHtml, statusStyle;
    if (!sold) {
        statusHtml  = '⏳ Not captured';
        statusStyle = 'background:#FFF8E1;color:#F57C00';
    } else if (s2conf) {
        statusHtml  = '✓ S1 + S2 confirmed';
        statusStyle = 'background:#E8F5E9;color:#1B5E20';
    } else {
        statusHtml  = '✓ S1 captured';
        statusStyle = 'background:#E3F2FD;color:#0D47A1';
    }

    document.getElementById('baleStatus').innerHTML  = statusHtml;
    document.getElementById('baleStatus').style.cssText = statusStyle + ';font-size:.7rem;font-weight:600;padding:.2rem .6rem;border-radius:4px';

    let pr = parseFloat(d.price_stored) || 0;
    if (pr > 10) pr = pr / 100;

    document.getElementById('baleInfo').innerHTML =
        'Barcode: <b>' + d.barcode + '</b> &nbsp;|&nbsp; Mass: <b>' + parseFloat(d.mass||0).toFixed(1) + ' kg</b><br>' +
        'Grower: <b>' + d.grower_num + '</b> — ' + d.grower_name + ' ' + d.surname + '<br>' +
        'Lot: <b>' + (d.lot||'—') + '</b> &nbsp; Group: <b>' + (d.bale_group||'—') + '</b>' +
        (d.timb_bc ? ' &nbsp; TIMB: <b>' + d.timb_bc + '</b>' : '') +
        (sold && d.timb_grade ? '<br>TIMB Grade: <b>' + d.timb_grade + '</b>' +
             (d.buyer_grade ? ' &nbsp; Buyer Grade: <b>' + d.buyer_grade + '</b>' : '') +
             (!rej && pr > 0 ? ' &nbsp; Price: <b>$' + pr.toFixed(4) + '</b>' : '') : '');

    document.getElementById('baleCard').style.display = '';
    document.getElementById('formCard').style.display = '';

    // Pre-fill form if editing
    document.getElementById('saleCode').value = d.sale_code || '';
    onSaleCodeChange(d.sale_code || '');

    if (d.timb_grade_id) {
        document.getElementById('timbGrade').value = d.timb_grade_id;
    } else {
        document.getElementById('timbGrade').value = '';
    }
    if (d.buyer_id) {
        document.getElementById('buyerSelect').value = d.buyer_id;
        onBuyerChange(d.buyer_id, d.buyer_grade_id);
    } else {
        document.getElementById('buyerSelect').value = '';
        document.getElementById('buyerGrade').innerHTML = '<option value="">— Select buyer first —</option>';
    }

    document.getElementById('priceInput').value = (!rej && pr > 0) ? pr.toFixed(4) : '';

    // Load batch
    if (currentTgid) loadBatch(currentTgid);

    // Focus TIMB grade if not pre-filled
    if (!d.timb_grade_id) {
        setTimeout(()=>document.getElementById('timbGrade').focus(), 50);
    } else {
        setTimeout(()=>document.getElementById('priceInput').focus(), 50);
    }
}

// ── Sale code logic ───────────────────────────────────────────────────────────
function onSaleCodeChange(code) {
    const priceEl   = document.getElementById('priceInput');
    const hintEl    = document.getElementById('saleCodeHint');
    const reqEl     = document.getElementById('priceRequired');
    const isRej     = REJECTION_CODES.indexOf(code.toUpperCase()) !== -1;

    if (isRej) {
        priceEl.value    = '0';
        priceEl.readOnly = true;
        priceEl.style.background = '#fff0f0';
        priceEl.style.color      = '#c03030';
        reqEl.style.display      = 'none';
        hintEl.style.display     = 'block';
        const labels = {CO:'Collect — buyer collects the bale.',
                        RO:'Re-Offer — bale will be re-offered next sale.',
                        R:'Rejected — price zeroed.'};
        hintEl.textContent = '⚠ ' + (labels[code.toUpperCase()] || 'Rejection — price set to zero.');
    } else {
        priceEl.readOnly         = false;
        priceEl.style.background = '';
        priceEl.style.color      = '#1A4D2E';
        reqEl.style.display      = '';
        hintEl.style.display     = 'none';
        if (priceEl.value === '0') priceEl.value = '';
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
    const tgid = parseInt(document.getElementById('timbGrade').value) || 0;
    const bgid = parseInt(document.getElementById('buyerGrade').value) || 0;
    const price = parseFloat(document.getElementById('priceInput').value) || 0;
    const sc    = document.getElementById('saleCode').value;
    const rep   = document.getElementById('salesRep').value.trim();

    if (!tgid) { showStatus('TIMB grade is required', false); return; }
    if (!bgid) { showStatus('Buyer grade is required', false); return; }
    const isRej = REJECTION_CODES.indexOf(sc.toUpperCase()) !== -1;
    if (!isRej && price <= 0) { showStatus('Price is required', false); return; }

    document.getElementById('btnCapture').disabled = true;
    document.getElementById('btnCapture').textContent = 'Saving…';

    const savedTgid = currentTgid;

    fetch('selling.php?ajax=capture', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({
            ticket_id: currentTicketId,
            timb_grade_id: tgid,
            buyer_grade_id: bgid,
            price: price,
            sale_code: sc,
            sales_rep: rep
        })
    })
    .then(r=>r.json()).then(d=>{
        document.getElementById('btnCapture').disabled = false;
        document.getElementById('btnCapture').textContent = '✓ Confirm Sale';
        if (d.error) { showStatus('❌ ' + d.error, false); return; }

        const priceShown = (isRej ? sc + ' (Rejected)' : '$' + parseFloat(d.price_stored).toFixed(4));
        showStatus('✓ Captured — ' + priceShown, true);

        if (savedTgid) loadBatch(savedTgid);
        loadFloorLog();
        resetForm();
        updateStats();
    }).catch(e=>{
        document.getElementById('btnCapture').disabled = false;
        document.getElementById('btnCapture').textContent = '✓ Confirm Sale';
        showStatus('Network error: ' + e.message, false);
    });
}

function updateStats() {
    // Fetch stats via ajax and update header
    fetch('selling.php?ajax=floor_log', {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(rows => {
        if (!Array.isArray(rows)) return;
        const count = rows.length;
        let usd = 0;
        rows.forEach(r => {
            let pr = parseFloat(r.price) || 0;
            if (pr > 10) pr = pr / 100;
            usd += pr * parseFloat(r.mass || 0);
        });
        const avg = count > 0 ? (usd / rows.reduce((acc, r) => acc + parseFloat(r.mass || 0), 0)) : 0;
        
        document.getElementById('header-counts').innerHTML = 
            count.toLocaleString() + ' sold today &nbsp;·&nbsp; ' +
            '$' + usd.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' gross &nbsp;·&nbsp; ' +
            'avg $' + avg.toFixed(4) + '/kg';
    }).catch(()=>{});
}

// ── Batch panel ───────────────────────────────────────────────────────────────
function loadBatch(tgid) {
    fetch('selling.php?ajax=batch&tgid=' + tgid,
          {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if (d.error || !d.tickets) return;
        const card   = document.getElementById('batchCard');
        const noCard = document.getElementById('noBatchCard');
        const rows   = document.getElementById('batchRows');
        const badge  = document.getElementById('batchBadge');
        const counts = document.getElementById('batchCounts');
        const tabBadge = document.getElementById('batch-tab-badge');

        if (noCard) noCard.style.display = 'none';
        badge.textContent  = d.total + ' bales';
        counts.innerHTML   = '<b style="color:#1A4D2E">' + d.captured + '</b> captured &nbsp;·&nbsp; ' +
                             '<b style="color:' + (d.remaining>0?'#F57C00':'#388E3C') + '">' + d.remaining + '</b> remaining';

        if (tabBadge) {
            tabBadge.textContent = d.remaining + ' left';
            tabBadge.style.display = d.remaining > 0 ? '' : 'none';
        }

        let html = '';
        d.tickets.forEach(t => {
            const sold = parseInt(t.sold_id) > 0;
            const rej  = sold && t.sale_code && ['CO','RO','R'].indexOf(t.sale_code.toUpperCase()) !== -1;
            const isCurrent = currentTicketId === parseInt(t.ticket_id);
            let pr = parseFloat(t.price_stored) || 0;
            if (pr > 10) pr = pr / 100;

            html += '<div style="display:flex;align-items:center;gap:.5rem;padding:.4rem .2rem;' +
                    'border-bottom:1px solid rgba(0,0,0,.05);' +
                    (isCurrent ? 'background:rgba(26,77,46,.05);border-radius:4px;' : '') + '">';
            html += '<span style="font-size:.7rem;color:var(--text-muted);min-width:2.5rem;font-family:monospace">' +
                    (t.lot||'?') + '-' + (t.bale_group||'?') + '</span>';
            html += '<code style="font-size:.72rem;flex:1">' + t.barcode + '</code>';
            html += '<span style="font-size:.7rem;color:var(--text-muted)">' + parseFloat(t.mass||0).toFixed(1) + 'kg</span>';
            if (!sold) {
                html += '<span style="font-size:.68rem;background:#FFF8E1;color:#F57C00;padding:.1rem .4rem;border-radius:3px">Pending</span>';
            } else if (rej) {
                html += '<span style="font-size:.68rem;background:#FFEBEE;color:#C62828;padding:.1rem .4rem;border-radius:3px">' + t.sale_code + '</span>';
            } else {
                const label = (t.buyer_grade || t.buyer_name || 'Sold');
                html += '<span style="font-size:.68rem;background:#E8F5E9;color:#1B5E20;padding:.1rem .4rem;border-radius:3px">' +
                        label + '</span>';
                if (pr > 0) html += '<span style="font-size:.7rem;font-family:monospace;font-weight:600;color:#1A4D2E">$' + pr.toFixed(4) + '</span>';
            }
            html += '</div>';
        });
        rows.innerHTML = html;
        card.style.display = '';
    }).catch(()=>{});
}

// ── Floor log ─────────────────────────────────────────────────────────────────
function loadFloorLog() {
    fetch('selling.php?ajax=floor_log',
          {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(rows=>{
        if (!Array.isArray(rows)) return;
        document.getElementById('logBadge').textContent = rows.length;
        let html = '';
        if (rows.length === 0) {
            html = '<tr><td colspan="7" class="text-center text-muted" style="padding:2rem">No sales captured today.</td></tr>';
        } else {
            rows.forEach(r => {
                const rej = r.sale_code && r.sale_code.toUpperCase().charAt(0) === 'R';
                let pr = parseFloat(r.price) || 0;
                if (pr > 10) pr = pr / 100;
                const prHtml = rej ? '<span style="color:#c03030">' + r.sale_code.toUpperCase() + '</span>'
                                   : '$' + pr.toFixed(4);
                html += '<tr' + (rej?' style="opacity:.55"':'') + '>' +
                    '<td><code style="font-size:.72rem">' + r.barcode + '</code>' +
                    (rej ? '<span class="badge badge-danger" style="font-size:.55rem;margin-left:3px">' + r.sale_code.toUpperCase() + '</span>' : '') + '</td>' +
                    '<td><div class="fw-500" style="font-size:.76rem">' + r.grower_num + '</div>' +
                         '<div class="td-muted" style="font-size:.7rem">' + r.name + ' ' + r.surname + '</div></td>' +
                    '<td><span class="badge badge-gold" style="font-size:.6rem">' + r.timb_grade + '</span></td>' +
                    '<td class="td-muted" style="font-size:.75rem">' + r.buyer_grade + '</td>' +
                    '<td class="td-muted" style="font-size:.75rem">' + r.buyer_name + '</td>' +
                    '<td class="td-mono text-right">' + parseFloat(r.mass||0).toFixed(1) + 'kg</td>' +
                    '<td class="td-mono text-right fw-500">' + prHtml + '</td>' +
                    '</tr>';
            });
        }
        document.getElementById('floorLogBody').innerHTML = html;
    }).catch(()=>{});
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function showStatus(msg, ok) {
    const el = document.getElementById('statusMsg');
    el.textContent  = msg;
    el.style.display = '';
    el.style.background = ok ? '#E8F5E9' : '#FFEBEE';
    el.style.color      = ok ? '#1B5E20' : '#C62828';
}
function clearStatus() {
    document.getElementById('statusMsg').style.display = 'none';
}
function resetForm() {
    document.getElementById('etBarcode').value = '';
    document.getElementById('baleCard').style.display  = 'none';
    document.getElementById('formCard').style.display  = 'none';
    currentTicketId = 0;
    document.getElementById('etBarcode').focus();
}
function resetAll() {
    resetForm();
    document.getElementById('batchCard').style.display = 'none';
    const noCard = document.getElementById('noBatchCard');
    if (noCard) noCard.style.display = '';
    const tabBadge = document.getElementById('batch-tab-badge');
    if (tabBadge) tabBadge.style.display = 'none';
    currentTgid = 0;
    clearStatus();
}
</script>

<?php include "includes/layout_end.php"; ?>
