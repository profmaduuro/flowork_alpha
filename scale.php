<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Scale — Bale Weighing";
$active_page = "scale";

$userid   = current_userid();
$seasonid = (int)($_SESSION['seasonid'] ?? 0);
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$today    = date('Y-m-d');

$msg = $err = '';

// ── SAVE BALE MASS (mirrors scale.php API) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'weigh') {
        $temp_barcode = $conn->real_escape_string(trim($_POST['temp_barcode'] ?? ''));
        $mass         = (int)($_POST['mass'] ?? 0);
        $created_at   = $conn->real_escape_string(trim($_POST['created_at'] ?? $today));

        if (!$temp_barcode || $mass <= 0) {
            $err = "Temp barcode and mass are required.";
        } else {
            $_dup_q = $conn->query("SELECT id FROM bale_mass WHERE temp_bacode='$temp_barcode'");
            $dup = ($_dup_q && $_dup_q !== true) ? $_dup_q->num_rows : 0;
            if ($dup) {
                $err = "Temp barcode '$temp_barcode' already used.";
            } else {
                $sql = "INSERT INTO bale_mass (userid,seasonid,temp_bacode,mass,created_at,selling_pointid)
                        VALUES ($userid,$seasonid,'$temp_barcode',$mass,'$created_at',$spid)";
                if ($conn->query($sql)) {
                    $msg = "Bale recorded — barcode: $temp_barcode, mass: {$mass}kg.";
                    $conn->query("INSERT INTO event_notifications (event_type, message) VALUES ('bale_weighed', 'Bale weighed: " . $conn->real_escape_string($temp_barcode) . " (" . $mass . "kg)')");
                } else {
                    $err = "DB Error: " . $conn->error;
                }
            }
        }
    }
}

// ── RECENT BALES ──────────────────────────────────────────────────────────
$_rq = $conn->query("
    SELECT bm.id, bm.temp_bacode, bm.mass, bm.created_at, bm.used,
           u.username
    FROM bale_mass bm
    LEFT JOIN users u ON u.id=bm.userid
    WHERE bm.seasonid=$seasonid AND ($spid=0 OR bm.selling_pointid=$spid)
    ORDER BY bm.id DESC LIMIT 50
");
$recent_rows = [];
if ($_rq && $_rq !== true) {
    while ($r = $_rq->fetch_assoc()) $recent_rows[] = $r;
}

$_tc = $conn->query("SELECT COUNT(*) c, COALESCE(SUM(mass),0) kg FROM bale_mass WHERE DATE(created_at)='$today' AND seasonid=$seasonid AND ($spid=0 OR selling_pointid=$spid)");
$today_count = ($_tc && $_tc !== true) ? $_tc->fetch_assoc() : ['c'=>0,'kg'=>0];

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

.scale-tab-panel {
    display: none;
}
.scale-tab-panel.active {
    display: block;
    animation: scaleFadeIn 0.25s ease-in-out;
}

@keyframes scaleFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.scale-console-container {
    max-width: 520px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 12px 40px rgba(26, 18, 8, 0.05);
    padding: 2rem;
}
body.theme-dark .scale-console-container {
    background: var(--ink-soft);
}

/* Glowing Input Styling */
.scale-barcode-input-container {
    position: relative;
    display: flex;
    gap: 0.5rem;
    align-items: stretch;
}
.scale-barcode-input {
    font-family: 'SF Mono', Fira Code, Monaco, monospace !important;
    font-size: 1.05rem !important;
    font-weight: 500;
    letter-spacing: 0.05em;
    padding: 0.75rem 1rem !important;
    border-radius: 6px !important;
    border: 1.5px solid var(--border);
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.scale-barcode-input:focus {
    border-color: var(--gold) !important;
    box-shadow: 0 0 0 4px rgba(200, 149, 42, 0.15) !important;
    background: #fff;
}

/* Modern Stat Capsules */
.scale-stats-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-top: 1rem;
}
.scale-stat-capsule {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.15rem;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(26,18,8,0.02);
    transition: transform 0.2s, box-shadow 0.2s;
}
.scale-stat-capsule:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(26,18,8,0.04);
}
body.theme-dark .scale-stat-capsule {
    background: var(--ink);
}
.scale-stat-lbl {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--muted);
}
.scale-stat-val {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.8rem;
    line-height: 1;
    letter-spacing: 0.05em;
}

.workspace-section-title {
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 6px;
}

.workspace-divider {
    height: 1px;
    background: var(--border);
    margin: 1.5rem 0;
}

/* Table Card Panel */
.scale-log-container {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 12px 40px rgba(26, 18, 8, 0.05);
    padding: 1.75rem;
}
body.theme-dark .scale-log-container {
    background: var(--ink-soft);
}

.scale-main-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.25rem;
}
.scale-main-header h3 {
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}

.scale-table-wrap {
    max-height: 520px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 6px;
    box-shadow: inset 0 2px 8px rgba(0,0,0,0.01);
}
.scale-table-wrap table thead th {
    background: var(--cream-d);
    color: var(--ink);
    font-weight: 600;
    font-size: 0.65rem;
    position: sticky;
    top: 0;
    z-index: 2;
}
body.theme-dark .scale-table-wrap table thead th {
    background: var(--ink);
    color: var(--cream);
}
</style>

<div class="page-header">
    <div class="page-header-left">
        <h2>Weighing Scale</h2>
        <p>Record bale mass — today: <strong><?= number_format($today_count['c']) ?> bales</strong> / <strong><?= number_format($today_count['kg']) ?> kg</strong></p>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Offline status banner -->
<div id="scale-offline-banner" class="alert" style="display:flex; justify-content:space-between; align-items:center; background:rgba(61,107,53,.06); border:1px solid rgba(61,107,53,.3); color:var(--leaf); padding:.75rem 1rem; border-radius:var(--radius); margin-bottom:1.25rem; font-size:.83rem">
    <div style="display:flex; align-items:center; gap:8px">
        <span class="lf-live-dot" id="scale-status-dot" style="background:var(--leaf); width:8px; height:8px; border-radius:50%; display:inline-block"></span>
        <span id="scale-status-text" style="font-weight:600">🟢 Operating State: Online</span>
    </div>
    <button type="button" class="btn btn-ghost" id="btn-sync-offline" style="display:none; font-size:0.7rem; padding:4px 8px; border-color:var(--gold); color:var(--gold); border-radius:4px; margin:0">
        🔄 Sync Offline Queue (<span id="offline-count-badge">0</span>)
    </button>
</div>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="weigh" onclick="switchScaleTab('weigh')">
        ⚖️ Weighing Console
    </button>
    <button class="premium-tab-btn" data-tab="log" onclick="switchScaleTab('log')">
        📋 Recent Records
    </button>
</div>

<!-- Tab Panel 1: Weighing Console -->
<div id="panel-weigh" class="scale-tab-panel active">
    <div class="scale-console-container">
        <div class="workspace-section-title">Record Bale Weight</div>
        <form method="POST" action="" id="scale-form">
            <input type="hidden" name="action" value="weigh">
            <div class="form-group mb-2">
                <label>Temp Barcode *</label>
                <div class="scale-barcode-input-container">
                    <input type="text" name="temp_barcode" id="temp_barcode" class="form-control scale-barcode-input"
                           required autofocus placeholder="Scan or type barcode..." style="flex:1">
                    <button type="button" class="btn btn-ghost" onclick="FloworkScanner.scan(function(bc){ document.getElementById('temp_barcode').value=bc; document.getElementById('mass').focus(); })" style="white-space:nowrap;border-color:var(--gold);color:var(--gold)" title="Scan with camera">
                        📷 Scan
                    </button>
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label>Mass (kg) *</label>
                    <input type="number" name="mass" id="mass" class="form-control"
                           required min="1" max="999" placeholder="0">
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="created_at" class="form-control"
                           value="<?= $today ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; margin-top:0.5rem; padding:0.75rem">
                Record Bale Weight
            </button>
        </form>

        <div class="workspace-divider"></div>

        <div>
            <div class="workspace-section-title">Today's Session</div>
            <div class="scale-stats-row">
                <div class="scale-stat-capsule">
                    <span class="scale-stat-lbl">Weighed Bales</span>
                    <span class="scale-stat-val" style="color:var(--gold)">
                        <?= number_format($today_count['c']) ?>
                    </span>
                </div>
                <div class="scale-stat-capsule">
                    <span class="scale-stat-lbl">Mass Weighed</span>
                    <span class="scale-stat-val" style="color:var(--leaf)">
                        <?= number_format($today_count['kg']) ?> <span style="font-size:0.9rem">kg</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tab Panel 2: Recent Activity Log -->
<div id="panel-log" class="scale-tab-panel">
    <div class="scale-log-container">
        <div class="scale-main-header">
            <h3>Recent Bale Records</h3>
            <span class="badge badge-gray">Last 50</span>
        </div>
        <div class="table-wrap scale-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Temp Barcode</th>
                        <th class="text-right">Mass (kg)</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($recent_rows): ?>
                <?php foreach ($recent_rows as $r): ?>
                <tr>
                    <td><code style="font-size:0.78rem; color:var(--ink)"><?= htmlspecialchars($r['temp_bacode']) ?></code></td>
                    <td class="td-mono text-right fw-500"><?= number_format($r['mass']) ?></td>
                    <td class="td-muted"><?= htmlspecialchars($r['created_at']) ?></td>
                    <td>
                        <?php if ($r['used']): ?>
                            <span class="badge badge-green">Used ✓</span>
                        <?php else: ?>
                            <span class="badge badge-gray">Available</span>
                        <?php endif; ?>
                    </td>
                    <td class="td-muted"><?= htmlspecialchars($r['username'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
                <tr><td colspan="5" class="text-center text-muted" style="padding:2rem">No bales recorded yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// IndexedDB Helper
const DB_NAME = 'flowork_scale_offline';
const STORE_NAME = 'weighed_bales';

function getDB() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = e => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { keyPath: 'temp_barcode' });
            }
        };
        req.onsuccess = e => resolve(e.target.result);
        req.onerror = e => reject(req.error);
    });
}

async function saveOfflineBale(bale) {
    const db = await getDB();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).put(bale);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

async function getOfflineBales() {
    const db = await getDB();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readonly');
        const req = tx.objectStore(STORE_NAME).getAll();
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(tx.error);
    });
}

async function deleteOfflineBale(temp_barcode) {
    const db = await getDB();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).delete(temp_barcode);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

// Update Network Visual State & Sync Badge
async function updateNetworkState() {
    const isOnline = navigator.onLine;
    const banner = document.getElementById('scale-offline-banner');
    const dot = document.getElementById('scale-status-dot');
    const text = document.getElementById('scale-status-text');
    const syncBtn = document.getElementById('btn-sync-offline');
    const badge = document.getElementById('offline-count-badge');
    
    const offlineList = await getOfflineBales();
    const count = offlineList.length;

    if (isOnline) {
        dot.style.background = 'var(--leaf)';
        dot.style.animation = 'none';
        text.textContent = '🟢 Operating State: Online';
        banner.style.background = 'rgba(61,107,53,.06)';
        banner.style.color = 'var(--leaf)';
        banner.style.borderColor = 'rgba(61,107,53,.3)';
        
        if (count > 0) {
            syncBtn.style.display = 'inline-flex';
            badge.textContent = count;
            text.textContent = `🟢 Operating State: Online (Pending Sync: ${count} bales)`;
        } else {
            syncBtn.style.display = 'none';
        }
    } else {
        dot.style.background = '#d9534f';
        dot.style.animation = 'lf-pulse 1.5s infinite';
        text.textContent = `🔴 Operating State: Offline (Cached: ${count} bales)`;
        banner.style.background = 'rgba(217,83,79,.08)';
        banner.style.color = '#d9534f';
        banner.style.borderColor = 'rgba(217,83,79,.3)';
        syncBtn.style.display = 'none';
    }
}

// Synchronize all pending bales from IndexedDB
async function syncOfflineQueue() {
    const bales = await getOfflineBales();
    if (bales.length === 0) return;
    
    const syncBtn = document.getElementById('btn-sync-offline');
    syncBtn.disabled = true;
    syncBtn.textContent = 'Syncing...';

    let successCount = 0;
    for (const bale of bales) {
        const formData = new FormData();
        formData.append('action', 'weigh');
        formData.append('temp_barcode', bale.temp_barcode);
        formData.append('mass', bale.mass);
        formData.append('created_at', bale.created_at);

        try {
            const resp = await fetch('scale.php', {
                method: 'POST',
                body: formData
            });
            if (resp.ok) {
                await deleteOfflineBale(bale.temp_barcode);
                successCount++;
            }
        } catch(e) {
            // Keep in queue to retry later
        }
    }
    
    if (successCount > 0) {
        // Quick reload to get updated totals and rows from server
        window.location.reload();
    } else {
        syncBtn.disabled = false;
        syncBtn.textContent = 'Retry Sync';
        alert('Sync failed. Connection to flowork server could not be established.');
    }
}

// Intercept form submit
document.getElementById('scale-form').addEventListener('submit', async function(e) {
    if (!navigator.onLine) {
        e.preventDefault(); // Stop server POST
        
        const temp_barcode = document.getElementById('temp_barcode').value.trim();
        const mass = parseInt(document.getElementById('mass').value) || 0;
        const created_at = document.querySelector('[name=created_at]').value;

        if (!temp_barcode || mass <= 0) return;

        // Save local
        const localBale = {
            temp_barcode,
            mass,
            created_at,
            timestamp: Date.now()
        };

        try {
            await saveOfflineBale(localBale);
            
            // Add row to the UI table directly to show immediate feedback!
            const table = document.querySelector('table tbody');
            const emptyRow = table.querySelector('td[colspan]');
            if (emptyRow) emptyRow.parentElement.remove();
            
            const tr = document.createElement('tr');
            tr.style.background = 'rgba(200,149,42,.03)';
            tr.innerHTML = `
                <td><code style="font-size:0.78rem; color:var(--gold)">${temp_barcode}</code></td>
                <td class="td-mono text-right fw-500">${mass}</td>
                <td class="td-muted">${created_at}</td>
                <td><span class="badge badge-gold" style="animation:lf-pulse 2s infinite">Offline Pending</span></td>
                <td class="td-muted">Local Cache</td>
            `;
            table.insertBefore(tr, table.firstChild);
            
            // Reset input and focus
            document.getElementById('temp_barcode').value = '';
            document.getElementById('temp_barcode').focus();
            
            await updateNetworkState();
        } catch(err) {
            alert('IndexedDB Error: ' + err.message);
        }
    }
});

// Auto-advance: after typing barcode and pressing Tab/Enter, jump to mass
document.getElementById('temp_barcode').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        document.getElementById('mass').focus();
        document.getElementById('mass').select();
    }
});

function switchScaleTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.scale-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_scale_tab', tabId);
    }
    
    if (tabId === 'weigh') {
        setTimeout(() => {
            const temp_barcode = document.getElementById('temp_barcode');
            if (temp_barcode) temp_barcode.focus();
        }, 50);
    }
}

// Setup listeners on load
document.addEventListener('DOMContentLoaded', () => {
    updateNetworkState();
    window.addEventListener('online', () => {
        updateNetworkState();
        syncOfflineQueue(); // Auto sync on regaining net
    });
    window.addEventListener('offline', updateNetworkState);
    document.getElementById('btn-sync-offline').addEventListener('click', syncOfflineQueue);
    
    // Restore active tab
    const activeTab = sessionStorage.getItem('active_scale_tab') || 'weigh';
    switchScaleTab(activeTab);
});
</script>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="barcode_scanner.js"></script>

<?php include "includes/layout_end.php"; ?>
