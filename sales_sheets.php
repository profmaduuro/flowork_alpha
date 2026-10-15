<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Sales Sheets";
$active_page = "sales_sheets";

$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$userid = current_userid();

// Ensure printed column exists
$_pc = $conn->query("SHOW COLUMNS FROM balanced_bales LIKE 'printed'");
if ($_pc && $_pc->num_rows === 0)
    $conn->query("ALTER TABLE balanced_bales ADD COLUMN printed TINYINT DEFAULT 0");

// ── Mark single as printed ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_printed') {
    $bbid = (int)($_POST['bbid'] ?? 0);
    if ($bbid) $conn->query("UPDATE balanced_bales SET printed=1 WHERE id=$bbid");
    header("Location: sales_sheets.php"); exit;
}

// ── Mark multiple as printed (AJAX) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_many_printed') {
    header('Content-Type: application/json');
    $ids_raw = $_POST['ids'] ?? '';
    $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
    if (!empty($ids)) {
        $id_list = implode(',', $ids);
        $conn->query("UPDATE balanced_bales SET printed=1 WHERE id IN ($id_list)");
    }
    echo json_encode(['ok'=>true,'count'=>count($ids)]);
    exit;
}

$filter = $_GET['printed'] ?? 'all';

// ── Load sheets ───────────────────────────────────────────────────────────────
$where_filter = match($filter) {
    '0'   => "AND (bb.printed IS NULL OR bb.printed=0)",
    '1'   => "AND bb.printed=1",
    default => ""
};

// Fetch all so_deduction_mode parameters from settings
$so_modes = [0 => 'before']; // default mapping
$sm_res = $conn->query("SELECT selling_pointid, param_value FROM parameters WHERE param_key='so_deduction_mode'");
if ($sm_res) {
    while ($sm_row = $sm_res->fetch_assoc()) {
        $so_modes[(int)$sm_row['selling_pointid']] = $sm_row['param_value'];
    }
}

// Fetch active bank charge rates per selling point from settings
$bc_rates = [0 => 0.02]; // default mapping
$bc_res = $conn->query("SELECT selling_pointid, amount FROM grower_charges WHERE statutoryid = 7 AND active = 1");
if ($bc_res) {
    while ($bc_row = $bc_res->fetch_assoc()) {
        $bc_rates[(int)$bc_row['selling_pointid']] = (float)$bc_row['amount'];
    }
}

$sheets_q = $conn->query("
    SELECT bb.id AS bbid, bb.splitid, bb.sale_batchid, bb.printed,
           g.grower_num, g.name, g.surname, g.selling_pointid,
           t.name AS transporter_name,
           COALESCE(t.location,'—') location,
           dt.mass, dt.usd_value, dt.zim_value,
           dt.laid_bales, dt.sold_bales, dt.rejected_bales,
           dt.average_price, er.amount AS exchange_rate,
           dts.usd_split_value, dts.zim_split_value,
           
           -- Sum of USD charges excluding Bank Charge (statutoryid = 7)
           COALESCE((
               SELECT SUM(bbc.usd_value)
               FROM balanced_bales_charges bbc
               JOIN grower_charges gc ON gc.id = bbc.grower_chargeid
               WHERE bbc.balanced_balesid = bb.id AND gc.statutoryid != 7
           ), 0) AS charges_usd_no_bc,
           
           -- Sum of ZiG charges excluding Bank Charge
           COALESCE((
               SELECT SUM(bbc.zim_value)
               FROM balanced_bales_charges bbc
               JOIN grower_charges gc ON gc.id = bbc.grower_chargeid
               WHERE bbc.balanced_balesid = bb.id AND gc.statutoryid != 7
           ), 0) AS charges_zig_no_bc,
           
           -- Sum of TIMB internal stop orders
           COALESCE((
               SELECT SUM(isod.amount_deducted)
               FROM internal_stop_order_deducted_amounts isod
               WHERE isod.balanced_balesid = bb.id
           ), 0) AS internal_so,
           
           -- Sum of TIMB external stop orders
           COALESCE((
               SELECT SUM(sd.amount_deducted)
               FROM stoporder_deductions sd
               WHERE sd.balanced_balesid = bb.id
           ), 0) AS external_so,
           
           -- Sum of company internal stop orders
           COALESCE((
               SELECT SUM(amount)
               FROM internal_stoporders
               WHERE (transporter_growersid = tg.id OR (growerid = g.id AND (transporter_growersid IS NULL OR transporter_growersid = 0)))
                 AND active IN (0,1)
           ), 0) AS company_so,
           
           bb.sale_batchid batch_num
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g   ON g.id  = tg.growerid
    JOIN transporter t ON t.id = tg.transporterid
    LEFT JOIN daily_totals dt          ON dt.balanced_balesid = bb.id
    LEFT JOIN exchange_rate er         ON er.id = dt.exchange_rateid
    LEFT JOIN daily_totals_split_amounts dts ON dts.balanced_balesid = bb.id
    WHERE bb.stoporder_processed = 1
      AND (g.selling_pointid = $spid OR $spid = 0)
      $where_filter
    ORDER BY bb.id DESC
    LIMIT 500
");
$sheets = ($sheets_q && $sheets_q !== true) ? $sheets_q->fetch_all(MYSQLI_ASSOC) : [];

// Counts for badges
$total_q   = $conn->query("SELECT COUNT(*) c FROM balanced_bales bb JOIN transporter_growers tg ON tg.id=bb.transporter_growersid JOIN growers g ON g.id=tg.growerid WHERE bb.stoporder_processed=1 AND (g.selling_pointid=$spid OR $spid=0)");
$unprinted_q = $conn->query("SELECT COUNT(*) c FROM balanced_bales bb JOIN transporter_growers tg ON tg.id=bb.transporter_growersid JOIN growers g ON g.id=tg.growerid WHERE bb.stoporder_processed=1 AND (bb.printed IS NULL OR bb.printed=0) AND (g.selling_pointid=$spid OR $spid=0)");
$total_all    = (int)(($total_q && $total_q !== true) ? $total_q->fetch_assoc()['c'] : 0);
$unprinted_ct = (int)(($unprinted_q && $unprinted_q !== true) ? $unprinted_q->fetch_assoc()['c'] : 0);

include "includes/layout.php";
?>

<?php if ($unprinted_ct === 0 && $spid > 0): ?>
<div style="background:var(--leaf);color:#fff;padding:.85rem 1.15rem;border-radius:var(--radius);
            margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem">
    <div>
        <div style="font-weight:500;font-size:.9rem">✓ All sales sheets printed — Day End ready</div>
        <div style="font-size:.75rem;opacity:.85;margin-top:2px">Generate TIMB report files and view EOD reports</div>
    </div>
    <div style="display:flex;gap:.6rem">
        <a href="timb_reports.php?date=<?= date('Y-m-d') ?>" class="btn btn-ghost" style="border-color:rgba(255,255,255,.4);color:#fff">TIMB Files</a>
        <a href="eod_reports.php?date=<?= date('Y-m-d') ?>" style="background:#fff;color:var(--leaf);padding:.48rem .9rem;border-radius:var(--radius);font-size:.78rem;font-weight:500;text-decoration:none">EOD Reports →</a>
    </div>
</div>
<?php elseif ($unprinted_ct > 0): ?>
<div style="background:var(--gold-bg);border:1px solid rgba(200,149,42,.3);padding:.75rem 1.15rem;
            border-radius:var(--radius);margin-bottom:1.25rem;display:flex;align-items:center;
            justify-content:space-between;flex-wrap:wrap;gap:.5rem">
    <div style="font-size:.8rem;color:var(--muted)">
        <span style="font-weight:500;color:var(--ink)"><?= $unprinted_ct ?> sheet(s) still unprinted</span>
        — print all sheets to unlock EOD reports
    </div>
    <a href="eod_reports.php?date=<?= date('Y-m-d') ?>" style="font-size:.75rem;color:var(--gold)">View reports anyway →</a>
</div>
<?php endif; ?>

<div class="page-header">
    <div class="page-header-left">
        <h2>Sales Sheets</h2>
        <p><?= $total_all ?> processed &nbsp;·&nbsp; <?= $unprinted_ct ?> unprinted</p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <a href="?printed=0" class="btn <?= $filter==='0'?'btn-primary':'btn-ghost' ?> btn-sm">
            Unprinted <span class="badge badge-gold" style="font-size:.58rem;margin-left:3px"><?= $unprinted_ct ?></span>
        </a>
        <a href="?printed=1" class="btn <?= $filter==='1'?'btn-primary':'btn-ghost' ?> btn-sm">Printed</a>
        <a href="?printed=all" class="btn <?= $filter==='all'?'btn-primary':'btn-ghost' ?> btn-sm">All</a>
        <div style="width:1px;height:20px;background:var(--border);margin:0 .25rem"></div>
        <?php if ($unprinted_ct > 0): ?>
        <button class="btn btn-primary btn-sm" onclick="printAllUnprinted()">
            🖨 Print All Unprinted (<?= $unprinted_ct ?>)
        </button>
        <?php endif; ?>
        <button class="btn btn-ghost btn-sm" id="btnPrintSelected" onclick="printSelected()" style="display:none">
            🖨 Print Selected
        </button>
        <button class="btn btn-ghost btn-sm" id="btnMarkSelected" onclick="markSelectedPrinted()" style="display:none">
            ✓ Mark Selected Printed
        </button>
    </div>
</div>

<div class="card">
    <!-- Select all bar -->
    <div id="selectBar" style="display:none; padding:.5rem 1rem; background:rgba(26,77,46,.05);
         border-bottom:1px solid var(--border); display:flex; align-items:center; gap:.75rem; font-size:.78rem">
        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:600">
            <input type="checkbox" id="chkSelectAll" onchange="toggleSelectAll(this.checked)">
            Select all on this page
        </label>
        <span id="selectedLabel" style="color:var(--text-muted)">0 selected</span>
    </div>

    <div class="table-wrap">
        <table id="sheetsTable">
            <thead>
                <tr>
                    <th style="width:32px">
                        <input type="checkbox" id="chkHeader" onchange="toggleSelectAll(this.checked)" title="Select all">
                    </th>
                    <th>Batch</th>
                    <th>Grower</th>
                    <th>Transporter</th>
                    <th>Split</th>
                    <th class="text-right">Bales</th>
                    <th class="text-right">Mass</th>
                    <th class="text-right">Gross USD</th>
                    <th class="text-right">Net USD</th>
                    <th class="text-right">Net ZiG</th>
                    <th class="text-right">Avg $/kg</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($sheets)): ?>
            <tr><td colspan="13" class="text-center text-muted" style="padding:2rem">
                No records found.
            </td></tr>
            <?php else: ?>
            <?php foreach ($sheets as $r):
                $rej = (int)$r['rejected_bales'];
                
                // Calculate dynamic net payouts matching the sales sheet print
                $row_spid = (int)$r['selling_pointid'];
                $row_bc_rate = isset($bc_rates[$row_spid]) ? $bc_rates[$row_spid] : (isset($bc_rates[0]) ? $bc_rates[0] : 0.02);
                $row_so_mode = isset($so_modes[$row_spid]) ? $so_modes[$row_spid] : (isset($so_modes[0]) ? $so_modes[0] : 'before');

                $charges_usd = (float)$r['charges_usd_no_bc'];
                $charges_zig = (float)$r['charges_zig_no_bc'];
                $iso_usd     = (float)$r['internal_so'];
                $ext_so_usd  = (float)$r['external_so'];
                $company_so  = (float)$r['company_so'];

                $step_gross = (float)$r['usd_value'];
                $ex_rate    = (float)$r['exchange_rate'] ?: 26.0;
                $timb_so_total_disp = $iso_usd + $ext_so_usd;

                // Step 2: TIMB SO (before split mode)
                if ($row_so_mode === 'before') {
                    $step_after_so   = max(0, $step_gross - $timb_so_total_disp);
                    $split_base      = $step_after_so;
                } else {
                    $step_after_so   = $step_gross;
                    $split_base      = $step_gross;
                }

                // Step 3: RBZ Split
                $step_usd_portion = $split_base * 0.70;
                $step_zig_portion = $split_base * 0.30;
                $step_zig_in_zig  = $step_zig_portion * $ex_rate;

                // Step 4: Deduct from USD portion
                $step_usd_after_timb_so_after = $row_so_mode === 'after'
                    ? max(0, $step_usd_portion - $timb_so_total_disp)
                    : $step_usd_portion;

                // Calculate payout before bank charge
                $usd_net_before_bc = max(0, $step_usd_after_timb_so_after - $charges_usd - $company_so);
                $zig_net_before_bc = max(0, $step_zig_in_zig - $charges_zig);

                // Compute bank charge dynamically
                $bc_usd = $usd_net_before_bc * $row_bc_rate;
                $bc_zig = $zig_net_before_bc * $row_bc_rate;

                // Final Net
                $display_usd_net = max(0, $usd_net_before_bc - $bc_usd);
                $display_zig_net = max(0, $zig_net_before_bc - $bc_zig);
            ?>
            <tr data-bbid="<?= $r['bbid'] ?>" class="<?= $r['printed'] ? 'row-printed' : '' ?>">
                <td onclick="event.stopPropagation()">
                    <input type="checkbox" class="sheet-chk" value="<?= $r['bbid'] ?>"
                           onchange="onCheckChange()">
                </td>
                <td>
                    <span class="badge badge-blue" style="font-size:.65rem">#<?= $r['sale_batchid'] ?></span>
                </td>
                <td>
                    <div class="fw-500 text-gold" style="font-size:.82rem"><?= htmlspecialchars($r['grower_num']) ?></div>
                    <div class="td-muted" style="font-size:.7rem"><?= htmlspecialchars($r['name'].' '.$r['surname']) ?></div>
                </td>
                <td class="td-muted" style="font-size:.78rem"><?= htmlspecialchars($r['transporter_name']) ?></td>
                <td>
                    <span style="font-size:.7rem; background:#E8F5E9; color:#1B5E20;
                          padding:.15rem .5rem; border-radius:99px; font-weight:600">
                        Sale <?= (int)$r['splitid'] ?>
                    </span>
                </td>
                <td class="td-mono text-right" style="font-size:.78rem">
                    <?= (int)$r['sold_bales'] ?> / <?= (int)$r['laid_bales'] ?>
                    <?php if ($rej > 0): ?>
                    <div style="font-size:.65rem;color:#c03030"><?= $rej ?> rej</div>
                    <?php endif; ?>
                </td>
                <td class="td-mono text-right" style="font-size:.78rem"><?= number_format((float)$r['mass'],0) ?> kg</td>
                <td class="td-mono text-right" style="font-size:.78rem">$<?= number_format((float)$r['usd_value'],2) ?></td>
                <td class="td-mono text-right fw-500" style="color:var(--leaf);font-size:.82rem">
                    $<?= number_format($display_usd_net,2) ?>
                </td>
                <td class="td-mono text-right" style="font-size:.78rem"><?= number_format($display_zig_net,2) ?> ZiG</td>
                <td class="td-mono text-right" style="font-size:.78rem">
                    $<?= $r['mass'] > 0 ? number_format((float)$r['average_price'],4) : '—' ?>
                </td>
                <td>
                    <?php if ($r['printed']): ?>
                    <span class="badge badge-green" style="font-size:.62rem">Printed</span>
                    <?php else: ?>
                    <span class="badge badge-gold" style="font-size:.62rem">Pending</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                    <a href="print_sheet.php?bbid=<?= $r['bbid'] ?>" target="_blank"
                       class="btn btn-ghost btn-sm" style="font-size:.7rem">View</a>
                    <?php if (!$r['printed']): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="mark_printed">
                        <input type="hidden" name="bbid" value="<?= $r['bbid'] ?>">
                        <button class="btn btn-gold btn-sm" style="font-size:.7rem">✓ Mark</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
tr.row-printed td { opacity: .65; }
#sheetsTable tbody tr:not(.row-printed) { cursor: default; }
</style>

<script>
function getCheckedIds() {
    return [...document.querySelectorAll('.sheet-chk:checked')].map(c => c.value);
}

function onCheckChange() {
    const ids = getCheckedIds();
    const total = document.querySelectorAll('.sheet-chk').length;
    document.getElementById('btnPrintSelected').style.display  = ids.length ? '' : 'none';
    document.getElementById('btnMarkSelected').style.display   = ids.length ? '' : 'none';
    document.getElementById('chkHeader').checked = ids.length === total && total > 0;
    document.getElementById('chkHeader').indeterminate = ids.length > 0 && ids.length < total;
    const bar = document.getElementById('selectBar');
    bar.style.display = ids.length ? 'flex' : 'none';
    document.getElementById('selectedLabel').textContent = ids.length + ' selected';
}

function toggleSelectAll(checked) {
    document.querySelectorAll('.sheet-chk').forEach(c => c.checked = checked);
    document.getElementById('chkHeader').checked = checked;
    onCheckChange();
}

function printAllUnprinted() {
    window.open('print_all_sheets.php?filter=unprinted&spid=<?= $spid ?>', '_blank');
}

function printSelected() {
    const ids = getCheckedIds();
    if (!ids.length) { alert('Select at least one sheet.'); return; }
    window.open('print_all_sheets.php?bbids=' + ids.join(','), '_blank');
}

function markSelectedPrinted() {
    const ids = getCheckedIds();
    if (!ids.length) return;
    if (!confirm('Mark ' + ids.length + ' sheet(s) as printed?')) return;
    fetch('sales_sheets.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=mark_many_printed&ids=' + ids.join(',')
    }).then(r=>r.json()).then(d=>{
        if (d.ok) location.reload();
    });
}
</script>

<?php include "includes/layout_end.php"; ?>
