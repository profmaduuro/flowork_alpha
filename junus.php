<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "Bale Junus";
$active_page = "junus";

$userid   = current_userid();
$seasonid = (int)($_SESSION['seasonid'] ?? 0);
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$today    = date('Y-m-d');

// ── Auto-seed splits if empty (FK requirement) ────────────────────────────
$_sc = $conn->query("SELECT COUNT(*) c FROM splits");
$splits_count = ($_sc && $_sc !== true) ? (int)$_sc->fetch_assoc()['c'] : 0;
if ($splits_count == 0) {
    for ($i = 0; $i <= 15; $i++) $conn->query("INSERT INTO splits (value) VALUES ($i)");
}

$msg = $err = '';

// ── POST: Create bale junus ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'junus') {
    $temp_barcode = $conn->real_escape_string(trim($_POST['temp_barcode'] ?? ''));
    $grower_num   = $conn->real_escape_string(strtoupper(trim($_POST['grower_num'] ?? '')));
    $bale_group   = (int)($_POST['bale_group'] ?? 1);
    $lot          = (int)($_POST['lot'] ?? 1);
    $splitid      = (int)($_POST['splitid'] ?? 1);
    $created_at   = $conn->real_escape_string(trim($_POST['created_at'] ?? $today));

    if (!$temp_barcode || !$grower_num) {
        $err = "Scale barcode and grower number are required.";
    } else {
        // 1. Find bale_mass
        $bm_q = $conn->query("SELECT id, mass FROM bale_mass WHERE temp_bacode='$temp_barcode' LIMIT 1");
        $bm   = ($bm_q && $bm_q !== true) ? $bm_q->fetch_assoc() : null;
        if (!$bm) {
            $err = "Scale barcode '$temp_barcode' not found. Weigh bale first on the scale.";
        } else {
            $bale_massid = (int)$bm['id'];
            $mass        = (float)$bm['mass'];

            // 2. Already junused?
            $au_q = $conn->query("SELECT id FROM bale_junus WHERE bale_massid=$bale_massid LIMIT 1");
            if ($au_q && $au_q->num_rows > 0) {
                $err = "Scale barcode '$temp_barcode' already assigned in junus.";
            } else {
                // 3. Find grower — search by selling_pointid or fallback
                $gr_q = $conn->query("SELECT id, name, surname FROM growers WHERE grower_num='$grower_num' AND selling_pointid=$spid LIMIT 1");
                $gr   = ($gr_q && $gr_q !== true) ? $gr_q->fetch_assoc() : null;
                if (!$gr && $spid) {
                    // Fallback: grower with selling_pointid=0
                    $gr_q2 = $conn->query("SELECT id, name, surname FROM growers WHERE grower_num='$grower_num' AND (selling_pointid=0 OR selling_pointid IS NULL) LIMIT 1");
                    $gr    = ($gr_q2 && $gr_q2 !== true) ? $gr_q2->fetch_assoc() : null;
                    if ($gr) $conn->query("UPDATE growers SET selling_pointid=$spid WHERE id={$gr['id']}");
                }
                if (!$gr) {
                    $err = "Grower '$grower_num' not found. Register in Grower Registry first.";
                } else {
                    $growerid = (int)$gr['id'];

                    // 4. Find open transporter_growers — prefer today/current sell date, allow bales=0
                    $tg_q = $conn->query("SELECT id, bales, junused_bales, sell_date FROM transporter_growers
                        WHERE growerid=$growerid AND open_close=0
                          AND (bales=0 OR bales>junused_bales)
                        ORDER BY
                          CASE WHEN sell_date='$today' THEN 0 ELSE 1 END,
                          id DESC
                        LIMIT 1");
                    $tg   = ($tg_q && $tg_q !== true) ? $tg_q->fetch_assoc() : null;

                    if (!$tg) {
                        $err = "No open delivery note found for grower '$grower_num'. Create a transporter delivery note first.";
                    } else {
                        $tgid   = (int)$tg['id'];
                        $tbales = (int)$tg['bales'];
                        $tjused = (int)$tg['junused_bales'];

                        // 5. Validate splitid FK
                        $sp_chk = $conn->query("SELECT id FROM splits WHERE id=$splitid LIMIT 1");
                        if (!$sp_chk || $sp_chk->num_rows === 0) $splitid = 1;

                        // 6. Insert bale_junus
                        $ins = "INSERT INTO bale_junus
                            (userid, transporter_growersid, bale_massid, growerid,
                             bale_group, lot, splitid, created_at, datetime)
                            VALUES ($userid, $tgid, $bale_massid, $growerid,
                             $bale_group, $lot, $splitid, '$created_at', NOW())";

                        if ($conn->query($ins)) {
                            $new_bj_id = (int)$conn->insert_id;
                            $conn->query("UPDATE transporter_growers SET junused_bales=junused_bales+1 WHERE id=$tgid");
                            $new_used = $tjused + 1;
                            audit_log($conn, $spid, $userid, 'bale_junus', 'create', $new_bj_id, null,
                                ['grower'=>$grower_num, 'lot'=>$lot, 'barcode'=>$temp_barcode]);
                            $msg = "✓ Bale assigned — Grower: <strong>$grower_num</strong> · "
                                 . "Lot: $lot · Group: $bale_group · Mass: {$mass}kg · "
                                 . "($new_used/$tbales bales)";
                            // Clear barcode for next scan, keep grower
                            $_POST['temp_barcode'] = '';
                            $_POST['lot'] = $new_used + 1; // auto-increment lot
                        } else {
                            $err = "Database error: " . $conn->error;
                        }
                    }
                }
            }
        }
    }
}

// ── Data for page ─────────────────────────────────────────────────────────
$recent_q = $conn->query("
    SELECT bj.id, bj.lot, bj.bale_group, bj.created_at,
           bm.temp_bacode, bm.mass,
           g.grower_num, g.name, g.surname,
           t.name AS transporter_name,
           tg.bales, tg.junused_bales,
           tk.barcode
    FROM bale_junus bj
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN growers g ON g.id=bj.growerid
    JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
    JOIN transporter t ON t.id=tg.transporterid
    LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
    WHERE g.selling_pointid=$spid
    ORDER BY bj.id DESC LIMIT 60
");
$recent = ($recent_q && $recent_q !== true) ? $recent_q->fetch_all(MYSQLI_ASSOC) : [];

// Today's count
$tc_q = $conn->query("SELECT COUNT(*) c FROM bale_junus bj
    JOIN growers g ON g.id=bj.growerid
    WHERE g.selling_pointid=$spid AND DATE(bj.datetime)='$today'");
$today_count = ($tc_q && $tc_q !== true) ? (int)$tc_q->fetch_assoc()['c'] : 0;

// Available tags (weighed, not yet junused)
$tags_q = $conn->query("SELECT bm.temp_bacode, bm.mass FROM bale_mass bm
    LEFT JOIN bale_junus bj ON bj.bale_massid=bm.id
    WHERE bj.id IS NULL" . ($seasonid ? " AND bm.seasonid=$seasonid" : "") . "
    ORDER BY bm.id DESC LIMIT 200");
$tags_arr = ($tags_q && $tags_q !== true) ? $tags_q->fetch_all(MYSQLI_ASSOC) : [];

// Grower progress — delivery notes
$progress_q = $conn->query("
    SELECT g.grower_num, g.name, g.surname,
           tg.id tgid, tg.bales, tg.junused_bales,
           t.name transporter_name
    FROM transporter_growers tg
    JOIN growers g ON g.id=tg.growerid
    JOIN transporter t ON t.id=tg.transporterid
    WHERE g.selling_pointid=$spid AND tg.open_close=0
    ORDER BY
      CASE WHEN tg.bales>0 AND tg.junused_bales>=tg.bales THEN 2 ELSE 1 END,
      tg.junused_bales DESC, g.grower_num
    LIMIT 50
");
$progress = ($progress_q && $progress_q !== true) ? $progress_q->fetch_all(MYSQLI_ASSOC) : [];

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

.junus-tab-panel {
    display: none;
}
.junus-tab-panel.active {
    display: block;
    animation: junusFadeIn 0.25s ease-in-out;
}

@keyframes junusFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.junus-console-container {
    max-width: 540px;
    margin: 0 auto;
}
</style>

<div class="page-header">
    <div class="page-header-left">
        <h2>Bale Junus</h2>
        <p>Scan scale barcode → enter grower details → assign bale · Today: <strong><?=number_format($today_count)?> bales</strong></p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="scale.php" class="btn btn-ghost btn-sm">⚖ Scale / Weigh</a>
        <a href="tickets.php" class="btn btn-ghost btn-sm">🎫 Tickets</a>
    </div>
</div>

<div class="progress-bar mb-3" style="display:flex;align-items:center;gap:.5rem;background:var(--cream-d);padding:.65rem 1rem;border-radius:var(--radius);border:1px solid var(--border)">
    <span class="badge badge-gray">1 · Scale / Weigh</span>
    <span style="color:var(--muted)">→</span>
    <span class="badge badge-gold" style="font-size:.72rem">2 · Junus ← here</span>
    <span style="color:var(--muted)">→</span>
    <span class="badge badge-gray">3 · Ticket Printing</span>
    <span style="color:var(--muted)">→</span>
    <span class="badge badge-gray">4 · Floor / Selling</span>
    <span style="color:var(--muted)">→</span>
    <span class="badge badge-gray">5 · Balance</span>
</div>

<?php if($msg):?><div class="alert alert-success"><?=$msg?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="assign" onclick="switchJunusTab('assign')">
        ⚖️ Assign Bale
    </button>
    <button class="premium-tab-btn" data-tab="log" onclick="switchJunusTab('log')">
        📋 Recent Entries
    </button>
</div>

<!-- Tab Panel 1: Assign Bale -->
<div id="panel-assign" class="junus-tab-panel active">
    <div class="junus-console-container">
        <div class="card mb-3">
            <div class="card-head"><h3>Assign Bale to Grower</h3>
              <span class="badge badge-gray"><?=count($tags_arr)?> tags available</span>
            </div>
            <div class="card-body">
                <form method="POST" id="junus-form">
                <input type="hidden" name="action" value="junus">

                <div class="form-group">
                    <label>Scale Barcode (from bale tag) *</label>
                    <input type="text" name="temp_barcode" id="temp_barcode" class="form-control" required
                           autofocus placeholder="Scan scale barcode..."
                           list="tags-list"
                           value="<?=htmlspecialchars($_POST['temp_barcode'] ?? '')?>"
                           style="font-family:monospace;font-size:1.05rem;letter-spacing:.05em">
                    <datalist id="tags-list">
                        <?php foreach($tags_arr as $t): ?>
                        <option value="<?=htmlspecialchars($t['temp_bacode'])?>"> <?=$t['mass']?>kg
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label>Grower Number * <span style="font-size:.68rem;color:var(--muted)">(from bale tag)</span></label>
                    <input type="text" name="grower_num" id="grower_num" class="form-control" required
                           placeholder="e.g. V101410"
                           value="<?=htmlspecialchars($_POST['grower_num'] ?? '')?>"
                           style="text-transform:uppercase">
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:.5rem">
                    <div class="form-group">
                        <label>Split</label>
                        <select name="splitid" class="form-control">
                            <?php for($s=1;$s<=15;$s++): ?>
                            <option value="<?=$s?>" <?=($_POST['splitid']??1)==$s?'selected':''?>><?=$s?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Lot #</label>
                        <input type="number" name="lot" class="form-control" min="1"
                               value="<?=htmlspecialchars($_POST['lot'] ?? '1')?>">
                    </div>
                    <div class="form-group">
                        <label>Group</label>
                        <input type="number" name="bale_group" class="form-control" min="1"
                               value="<?=htmlspecialchars($_POST['bale_group'] ?? '1')?>">
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="created_at" class="form-control" value="<?=$today?>">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;padding:.75rem;font-size:.9rem">
                    ✓ Assign Bale
                </button>
                </form>
            </div>
        </div>

        <!-- Grower progress -->
        <?php if(!empty($progress)):?>
        <div class="card">
            <div class="card-head"><h3>Delivery Note Progress</h3></div>
            <div style="max-height:280px;overflow-y:auto;padding:.5rem .75rem">
            <?php foreach($progress as $p):
                $tot  = (int)$p['bales'];
                $done = (int)$p['junused_bales'];
                $pct  = $tot > 0 ? round($done/$tot*100) : 0;
                $complete = $tot > 0 && $done >= $tot;
            ?>
            <div style="margin-bottom:.5rem;padding:.5rem .65rem;background:var(--cream-d);border-radius:5px;
                 border-left:3px solid <?=$complete?'var(--leaf)':'var(--gold)'?>">
                <div style="display:flex;justify-content:space-between;font-size:.78rem">
                    <span><strong><?=htmlspecialchars($p['grower_num'])?></strong>
                        <span style="color:var(--muted);font-size:.7rem"> <?=htmlspecialchars(trim($p['name'].' '.$p['surname']))?></span>
                    </span>
                    <span style="color:<?=$complete?'var(--leaf)':'var(--gold)'?>;font-weight:600">
                        <?=$complete?'✓ Complete':'$done/$tot'?>
                    </span>
                </div>
                <div style="background:rgba(0,0,0,.08);border-radius:99px;height:5px;margin-top:.3rem;overflow:hidden">
                    <div style="width:<?=$pct?>%;height:100%;background:<?=$complete?'var(--leaf)':'var(--gold)'?>;border-radius:99px"></div>
                </div>
            </div>
            <?php endforeach;?>
            </div>
        </div>
        <?php endif;?>
    </div>
</div>

<!-- Tab Panel 2: Recent Junus Entries -->
<div id="panel-log" class="junus-tab-panel">
    <div class="card">
        <div class="card-head">
            <h3>Recent Junus Entries</h3>
            <span class="badge badge-gray"><?=count($recent)?></span>
        </div>
        <div class="table-wrap" style="max-height:600px;overflow-y:auto">
            <table>
                <thead><tr>
                    <th>Scale BC</th><th>Grower</th>
                    <th class="text-right">kg</th>
                    <th>Lot/Grp</th><th>Transporter</th><th>Ticket</th>
                </tr></thead>
                <tbody>
                <?php if(empty($recent)):?>
                <tr><td colspan="6" class="text-center text-muted" style="padding:2rem">No junus entries yet.</td></tr>
                <?php else: foreach($recent as $r):
                    $is_complete = (int)$r['bales']>0 && (int)$r['junused_bales']>=(int)$r['bales'];
                ?>
                <tr>
                    <td><code style="font-size:.68rem"><?=htmlspecialchars($r['temp_bacode'])?></code></td>
                    <td>
                        <span class="badge badge-gold" style="font-size:.6rem"><?=htmlspecialchars($r['grower_num'])?></span>
                        <div style="font-size:.68rem;color:var(--muted)"><?=htmlspecialchars(trim($r['name'].' '.$r['surname']))?></div>
                    </td>
                    <td class="td-mono text-right"><?=number_format((float)$r['mass'],1)?></td>
                    <td class="td-mono" style="font-size:.72rem"><?=$r['lot']?>-<?=$r['bale_group']?></td>
                    <td style="font-size:.7rem;color:var(--muted)">
                        <?=htmlspecialchars($r['transporter_name'])?>
                        <div style="font-size:.65rem;color:<?=$is_complete?'var(--leaf)':'var(--gold)'?>">
                            <?=$r['junused_bales']?>/<?=$r['bales']?>
                        </div>
                    </td>
                    <td>
                        <?php if($r['barcode']):?>
                        <span class="badge badge-green" style="font-size:.58rem">✓ Ticketed</span>
                        <?php else:?>
                        <span class="badge badge-gray" style="font-size:.58rem">Pending</span>
                        <?php endif;?>
                    </td>
                </tr>
                <?php endforeach;endif;?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Enter on scale barcode → jump to grower number
document.getElementById('temp_barcode').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('grower_num').focus(); }
});
// Enter on grower number → submit form
document.getElementById('grower_num').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('junus-form').submit(); }
});

function switchJunusTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.junus-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_junus_tab', tabId);
    }
    
    if (tabId === 'assign') {
        setTimeout(() => {
            const temp_barcode = document.getElementById('temp_barcode');
            if (temp_barcode) temp_barcode.focus();
        }, 50);
    }
}

// Restore active tab
document.addEventListener('DOMContentLoaded', () => {
    const activeTab = sessionStorage.getItem('active_junus_tab') || 'assign';
    switchJunusTab(activeTab);
});
</script>

<?php include "includes/layout_end.php"; ?>
