<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Bale Check";
$active_page = "bale_check";
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);

$search = trim($_GET['q'] ?? $_POST['q'] ?? '');
$bale   = null;
$history= [];

if ($search) {
    $s = $conn->real_escape_string($search);

    // Guard optional tables
    $has_dispatch = $conn->query("SHOW TABLES LIKE 'dispatch'")->num_rows > 0;
    $d_sel  = $has_dispatch ? "d.id AS dispatched_id, d.created_at AS dispatched_at," : "NULL AS dispatched_id, NULL AS dispatched_at,";
    $d_join = $has_dispatch ? "LEFT JOIN dispatch d ON d.sold_baleid=sb.id" : "";

    $has_dt = $conn->query("SHOW TABLES LIKE 'daily_totals'")->num_rows > 0;
    $dt_sel  = $has_dt ? "dt.usd_value, dt.mass AS settled_mass," : "NULL AS usd_value, NULL AS settled_mass,";
    $dt_join = $has_dt ? "LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id" : "";

    $bale = $conn->query("
        SELECT bm.id, bm.temp_bacode AS timb_bc, bm.mass, bm.created_at AS weighed_at,
               bj.id AS junus_id, bj.lot, bj.bale_group, bj.splitid,
               g.grower_num, g.name, g.surname, g.province, g.farm_name,
               t.name AS transporter_name, t.truck_num,
               bl.code AS loc_code, bl.name AS loc_name,
               tg.id AS tgid,
               tk.id AS ticket_id, tk.barcode, tk.created_at AS ticketed_at,
               sb.id AS sold_id, sb.price, sb.sale_code, sb.created_at AS sold_at,
               tg_d.description AS timb_grade,
               bg.description AS buyer_grade,
               b.name AS buyer_name,
               bb.id AS balanced_id,
               $dt_sel
               $d_sel
               NULL AS _pad
        FROM bale_mass bm
        LEFT JOIN bale_junus bj ON bj.bale_massid=bm.id
        LEFT JOIN growers g ON g.id=bj.growerid
        LEFT JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
        LEFT JOIN transporter t ON t.id=tg.transporterid
        LEFT JOIN bale_locations bl ON bl.id=t.location_id
        LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        LEFT JOIN timb_grades tg_d ON tg_d.id=sb.timb_gradesid
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
        $dt_join
        $d_join
        WHERE (tk.barcode='$s' OR bm.temp_bacode='$s' OR bm.id='$s')
          AND (g.selling_pointid=$spid OR g.id IS NULL)
        LIMIT 1
    ")->fetch_assoc();
}

// Status pipeline
function bale_pipeline($b) {
    return [
        ['step'=>'Weighed',    'done'=> true,                           'ts'=> $b['weighed_at']   ?? ''],
        ['step'=>'Junus',      'done'=> !empty($b['junus_id']),         'ts'=> ''],
        ['step'=>'Ticketed',   'done'=> !empty($b['ticket_id']),        'ts'=> $b['ticketed_at']  ?? ''],
        ['step'=>'Sold',       'done'=> !empty($b['sold_id']),          'ts'=> $b['sold_at']      ?? ''],
        ['step'=>'Balanced',   'done'=> !empty($b['balanced_id']),      'ts'=> ''],
        ['step'=>'Dispatched', 'done'=> !empty($b['dispatched_id']),    'ts'=> $b['dispatched_at']?? ''],
    ];
}

include "includes/layout.php";
?>

<div class="page-header">
    <div class="page-header-left"><h2>Bale Check</h2>
    <p>Look up any bale by barcode, TIMB barcode, or bale ID</p></div>
</div>

<!-- Search bar — always visible, autofocus -->
<div class="card mb-3">
<div class="card-body" style="padding:1rem 1.15rem">
<form method="GET" style="display:flex;gap:0.6rem;align-items:flex-end">
    <div class="form-group" style="flex:1;margin:0">
        <label>Barcode / TIMB code / Bale ID</label>
        <input type="text" name="q" class="form-control" autofocus
               style="font-family:monospace;font-size:1rem;letter-spacing:0.06em"
               placeholder="Scan or type barcode..."
               value="<?= htmlspecialchars($search) ?>">
    </div>
    <button type="submit" class="btn btn-primary">Check →</button>
    <?php if ($search): ?>
    <a href="bale_check.php" class="btn btn-ghost">Clear</a>
    <?php endif; ?>
</form>
</div></div>

<?php if ($search && !$bale): ?>
<div class="alert alert-danger">No bale found matching "<strong><?= htmlspecialchars($search) ?></strong>". Try the barcode, TIMB temp barcode, or bale_mass ID.</div>
<?php endif; ?>

<?php if ($bale): ?>
<?php $pipeline = bale_pipeline($bale); ?>

<!-- Status pipeline -->
<div class="card mb-3">
<div class="card-head"><h3>Bale Status</h3>
    <?php
    $last_done = 'Weighed';
    foreach ($pipeline as $p) { if ($p['done']) $last_done = $p['step']; }
    ?>
    <span class="badge badge-gold"><?= $last_done ?></span>
</div>
<div style="padding:1.25rem 1.15rem">
<div style="display:flex;align-items:center;gap:0;position:relative">
<?php foreach ($pipeline as $i => $p):
    $is_last = $i === count($pipeline)-1;
?>
<div style="display:flex;flex-direction:column;align-items:center;flex:1;position:relative">
    <!-- Circle -->
    <div style="width:32px;height:32px;border-radius:50%;
                background:<?= $p['done'] ? 'var(--leaf)' : 'var(--border)' ?>;
                display:flex;align-items:center;justify-content:center;
                border:2px solid <?= $p['done'] ? 'var(--leaf)' : 'var(--border)' ?>;
                z-index:1;position:relative">
        <?php if ($p['done']): ?>
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2.5 7l3 3 6-6" stroke="white" stroke-width="1.5" stroke-linecap="round"/></svg>
        <?php else: ?>
        <div style="width:8px;height:8px;border-radius:50%;background:var(--muted)"></div>
        <?php endif; ?>
    </div>
    <!-- Label -->
    <div style="font-size:0.68rem;font-weight:500;margin-top:5px;color:<?= $p['done']?'var(--ink)':'var(--muted)' ?>;text-align:center"><?= $p['step'] ?></div>
    <?php if ($p['ts']): ?>
    <div style="font-size:.72rem;color:var(--muted);text-align:center"><?= date('d/m H:i', strtotime($p['ts'])) ?></div>
    <?php endif; ?>
    <!-- Connector line -->
    <?php if (!$is_last): ?>
    <div style="position:absolute;top:16px;left:50%;width:100%;height:2px;
                background:<?= $p['done']?'var(--leaf)':'var(--border)' ?>"></div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</div>
</div>

<!-- Detail cards -->
<div class="grid-2" style="align-items:start">

<div class="card">
<div class="card-head"><h3>Bale Details</h3></div>
<div class="table-wrap"><table>
<tr><td class="fw-500">TIMB Barcode</td><td class="td-mono"><?= htmlspecialchars($bale['timb_bc']) ?></td></tr>
<tr><td class="fw-500">Auction Barcode</td><td class="td-mono"><?= htmlspecialchars($bale['barcode'] ?? '—') ?></td></tr>
<tr><td class="fw-500">Mass</td><td><?= number_format($bale['mass']) ?> kg</td></tr>
<tr><td class="fw-500">Lot</td><td><?= $bale['lot'] ?? '—' ?></td></tr>
<tr><td class="fw-500">Bale Group</td><td><?= $bale['bale_group'] ?? '—' ?></td></tr>
<tr><td class="fw-500">Split</td><td><?= $bale['splitid'] ?? '—' ?></td></tr>
<tr><td class="fw-500">Location</td><td>
    <?php if ($bale['loc_code']): ?>
    <span style="font-family:'DM Sans',sans-serif;font-size:0.85rem;color:var(--gold);font-weight:600"><?= $bale['loc_code'] ?></span>
    <span class="td-muted"> — <?= htmlspecialchars($bale['loc_name']) ?></span>
    <?php else: ?>—<?php endif; ?>
</td></tr>
<tr><td class="fw-500">Weighed</td><td><?= $bale['weighed_at'] ? date('d M Y H:i', strtotime($bale['weighed_at'])) : '—' ?></td></tr>
</table></div>
</div>

<div class="card">
<div class="card-head"><h3>Grower &amp; Sale</h3></div>
<div class="table-wrap"><table>
<tr><td class="fw-500">Grower #</td><td><span class="badge badge-gold"><?= htmlspecialchars($bale['grower_num'] ?? '—') ?></span></td></tr>
<tr><td class="fw-500">Name</td><td><?= htmlspecialchars(($bale['name']??'').' '.($bale['surname']??'')) ?></td></tr>
<tr><td class="fw-500">Farm</td><td><?= htmlspecialchars($bale['farm_name'] ?? '—') ?></td></tr>
<tr><td class="fw-500">Province</td><td><?= htmlspecialchars($bale['province'] ?? '—') ?></td></tr>
<tr><td class="fw-500">Transporter</td><td><?= htmlspecialchars($bale['transporter_name'] ?? '—') ?></td></tr>
<tr><td class="fw-500">TIMB Grade</td>
    <td><?= $bale['timb_grade'] ? '<span class="badge badge-gold" style="font-size:0.72rem">'.htmlspecialchars($bale['timb_grade']).'</span>' : '—' ?></td></tr>
<tr><td class="fw-500">Buyer Grade</td><td><?= htmlspecialchars($bale['buyer_grade'] ?? '—') ?></td></tr>
<tr><td class="fw-500">Buyer</td><td><?= htmlspecialchars($bale['buyer_name'] ?? '—') ?></td></tr>
<tr><td class="fw-500">Price (USD/kg)</td>
    <td class="fw-500 td-mono"><?= $bale['price'] ? '$'.number_format((float)$bale['price'],4) : '—' ?></td></tr>
<?php if ($bale['sold_id']): ?>
<tr><td class="fw-500">Sold</td><td><?= date('d M Y H:i', strtotime($bale['sold_at'])) ?></td></tr>
<?php endif; ?>
<?php if ($bale['balanced_id']): ?>
<tr><td class="fw-500">Batch</td><td>#<?= $bale['balanced_id'] ?></td></tr>
<?php endif; ?>
<?php if ($bale['dispatched_id']): ?>
<tr><td class="fw-500">Dispatched</td><td><?= date('d M Y', strtotime($bale['dispatched_at'])) ?></td></tr>
<?php endif; ?>
</table></div>
</div>

</div>

<?php if ($bale['barcode']): ?>
<div style="margin-top:0.75rem">
    <a href="ticket_pdf.php?barcode=<?= urlencode($bale['barcode']) ?>" target="_blank" class="btn btn-ghost">
        🖨 Print Ticket
    </a>
    <a href="bale_adjustments.php?barcode=<?= urlencode($bale['barcode']) ?>" class="btn btn-ghost" style="margin-left:0.5rem">
        ✏ Adjust Bale
    </a>
    <a href="move_bales.php?barcode=<?= urlencode($bale['barcode']) ?>" class="btn btn-ghost" style="margin-left:0.5rem">
        ↔ Move Location
    </a>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include "includes/layout_end.php"; ?>
