<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Deductions";
$active_page = "deductions";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = (int)($_SESSION['userid'] ?? $_SESSION['user_id'] ?? 0);
$date_f      = $conn->real_escape_string(trim($_GET['date'] ?? date('Y-m-d')));
$search      = $conn->real_escape_string(trim($_GET['q'] ?? ''));
$export      = $_GET['export'] ?? '';
$tab         = $_GET['tab'] ?? 'deductions'; // 'deductions' or 'templates'

// ── Company name ──────────────────────────────────────────────────────────
$co_q    = $conn->query("SELECT param_value FROM parameters WHERE param_key='company_name' LIMIT 1");
$company = $co_q ? ($co_q->fetch_assoc()['param_value'] ?? 'Flowork') : 'Flowork';

// ── Ensure deduction_templates table exists ────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS deduction_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  selling_pointid INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) DEFAULT '',
  items JSON NOT NULL COMMENT 'Array of {label, type, value, currency}',
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sp(selling_pointid)
)");

// ── Handle template POST actions ───────────────────────────────────────────
$tmsg = $terr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create template
    if ($action === 'create_template') {
        $tname = $conn->real_escape_string(trim($_POST['tpl_name'] ?? ''));
        $tdesc = $conn->real_escape_string(trim($_POST['tpl_desc'] ?? ''));
        $labels  = $_POST['item_label']  ?? [];
        $types   = $_POST['item_type']   ?? [];
        $vals    = $_POST['item_value']  ?? [];
        $currs   = $_POST['item_currency'] ?? [];
        $items = [];
        foreach ($labels as $i => $lbl) {
            $lbl = trim($lbl);
            $val = (float)($vals[$i] ?? 0);
            if ($lbl === '' || $val <= 0) continue;
            $items[] = [
                'label'    => $lbl,
                'type'     => $types[$i] ?? 'fixed_usd',
                'value'    => $val,
                'currency' => $currs[$i] ?? 'USD',
            ];
        }
        if ($tname && !empty($items)) {
            $items_json = $conn->real_escape_string(json_encode($items));
            $conn->query("INSERT INTO deduction_templates (selling_pointid,name,description,items,created_by)
                VALUES ($spid,'$tname','$tdesc','$items_json',$userid)");
            $tmsg = "✓ Template \"$tname\" created with " . count($items) . " item(s).";
        } else {
            $terr = "Template name and at least one valid item are required.";
        }
    }

    // Delete template
    if ($action === 'delete_template') {
        $tid = (int)($_POST['template_id'] ?? 0);
        if ($tid > 0) {
            $conn->query("DELETE FROM deduction_templates WHERE id=$tid AND selling_pointid=$spid");
            $tmsg = "Template deleted.";
        }
    }

    // Apply template to a grower balance (creates internal stop order records)
    if ($action === 'apply_template') {
        $tid   = (int)($_POST['template_id'] ?? 0);
        $gid   = (int)($_POST['grower_id'] ?? 0);
        $adate = $conn->real_escape_string($_POST['apply_date'] ?? date('Y-m-d'));
        if ($tid > 0 && $gid > 0) {
            $tq = $conn->query("SELECT * FROM deduction_templates WHERE id=$tid AND selling_pointid=$spid LIMIT 1");
            $trow = $tq ? $tq->fetch_assoc() : null;
            if ($trow) {
                $items = json_decode($trow['items'], true) ?: [];
                $applied = 0;
                // Check if internal_stoporders table exists
                $has_iso_w = $conn->query("SHOW TABLES LIKE 'internal_stoporders'")->num_rows > 0;
                foreach ($items as $it) {
                    $lbl = $conn->real_escape_string($it['label']);
                    $amt = (float)$it['value'];
                    $typ = $conn->real_escape_string($it['type'] ?? 'fixed_usd');
                    if ($has_iso_w) {
                        // Insert as internal stop order
                        $conn->query("INSERT INTO internal_stoporders
                            (growerid, charge_type, amount, sell_date, active, created_by)
                            VALUES ($gid, '$lbl ($typ)', $amt, '$adate', 1, $userid)
                            ON DUPLICATE KEY UPDATE amount=amount");
                    }
                    $applied++;
                }
                $tmsg = "✓ Applied template \"".htmlspecialchars($trow['name'])."\" → $applied deduction(s) added for grower #$gid on $adate.";
            } else {
                $terr = "Template not found.";
            }
        } else {
            $terr = "Select a template and a grower.";
        }
    }
}

// ── Check tables ──────────────────────────────────────────────────────────
$has_bbc = $conn->query("SHOW TABLES LIKE 'balanced_bales_charges'")->num_rows > 0;
$has_so  = $conn->query("SHOW TABLES LIKE 'stoporders_timb'")->num_rows > 0;
$has_iso = $conn->query("SHOW TABLES LIKE 'internal_stoporders'")->num_rows > 0;

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

$where_s = $search ? "AND (g.grower_num LIKE '%$search%' OR g.name LIKE '%$search%' OR g.surname LIKE '%$search%')" : '';

$growers_q = $conn->query("
    SELECT g.id AS grower_id, g.grower_num,
           CONCAT(g.name,' ',g.surname) AS grower_name,
           g.province, g.selling_pointid,
           bb.id AS bb_id,
           bb.splitid,
           sp.value AS split_value,
           sale_batchid AS sheet_ref,
           dt.mass, dt.usd_value AS gross_usd,
           er.amount AS exchange_rate,
           dts.usd_split_value, dts.zim_split_value,
           tg.id AS tgid
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g              ON g.id  = tg.growerid
    LEFT JOIN splits sp         ON sp.id = bb.splitid
    LEFT JOIN daily_totals dt   ON dt.balanced_balesid = bb.id
    LEFT JOIN exchange_rate er  ON er.id = dt.exchange_rateid
    LEFT JOIN daily_totals_split_amounts dts ON dts.balanced_balesid = bb.id
    WHERE g.selling_pointid = $spid
      AND DATE(bb.created_at) = '$date_f'
      $where_s
    ORDER BY g.grower_num ASC, bb.splitid ASC
");

// ── Creditor names ────────────────────────────────────────────────────────
$creditor_cols = [];

if ($has_bbc) {
    $cq = $conn->query("
        SELECT DISTINCT st.description
        FROM balanced_bales_charges bbc
        JOIN balanced_bales bb ON bb.id = bbc.balanced_balesid
        JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
        JOIN growers g ON g.id = tg.growerid
        JOIN grower_charges gc ON gc.id = bbc.grower_chargeid
        JOIN statutory st ON st.id = gc.statutoryid
        WHERE g.selling_pointid = $spid AND DATE(bb.created_at) = '$date_f'
        ORDER BY st.description
    ");
    while ($cq && $cr = $cq->fetch_assoc()) {
        $creditor_cols[$cr['description']] = 'bbc_' . md5($cr['description']);
    }
}

if ($has_iso) {
    $iq = $conn->query("
        SELECT DISTINCT io.charge_type
        FROM internal_stoporders io
        JOIN growers g ON g.id = io.growerid
        WHERE g.selling_pointid = $spid
          AND io.sell_date = '$date_f' AND io.active = 1
        ORDER BY io.charge_type
    ");
    while ($iq && $it = $iq->fetch_assoc()) {
        $label = $it['charge_type'];
        if (!isset($creditor_cols[$label]))
            $creditor_cols[$label] = 'iso_' . md5($label);
    }
}

// Check for TIMB stop orders (both internal and external) on this date
$timb_so_exists_q = $conn->query("
    SELECT COUNT(*) cnt FROM (
        SELECT isod.id FROM internal_stop_order_deducted_amounts isod
        JOIN balanced_bales bb ON bb.id = isod.balanced_balesid
        JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
        JOIN growers g ON g.id = tg.growerid
        WHERE g.selling_pointid = $spid AND DATE(bb.created_at) = '$date_f'
        
        UNION ALL
        
        SELECT sd.id FROM stoporder_deductions sd
        JOIN balanced_bales bb ON bb.id = sd.balanced_balesid
        JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
        JOIN growers g ON g.id = tg.growerid
        WHERE g.selling_pointid = $spid AND DATE(bb.created_at) = '$date_f'
    ) tmp
");
$timb_so_exists = ($timb_so_exists_q) ? (int)$timb_so_exists_q->fetch_assoc()['cnt'] : 0;
if ($timb_so_exists > 0) {
    $creditor_cols['TIMB Stop Orders'] = 'timb_so';
}

// ── Build rows ────────────────────────────────────────────────────────────
$rows       = [];
$col_totals = array_fill_keys(array_values($creditor_cols), 0.0);
$grand = ['mass'=>0,'gross_usd'=>0,'total_ded'=>0,'net_usd'=>0,'gr_comm_usd'=>0];

if ($growers_q && $growers_q->num_rows > 0):
while ($gr = $growers_q->fetch_assoc()):
    $gid  = (int)$gr['grower_id'];
    $bbid = (int)$gr['bb_id'];
    $tgid = (int)$gr['tgid'];
    $split_no    = (int)$gr['splitid'];
    $split_value = $gr['split_value'] ?? $split_no;
    $split_label = 'Split '.((int)$split_value);
    $gross_usd   = (float)($gr['gross_usd'] ?? 0);
    $mass        = (float)($gr['mass']      ?? 0);
    $sheet_ref   = $gr['sheet_ref'] ?? '';
    $deductions  = array_fill_keys(array_values($creditor_cols), 0.0);
    $ded_zwg     = 0.0;

    // Resolve Bank Charge Rate and SO Deduction Mode
    $row_spid = (int)$gr['selling_pointid'];
    $row_bc_rate = isset($bc_rates[$row_spid]) ? $bc_rates[$row_spid] : (isset($bc_rates[0]) ? $bc_rates[0] : 0.02);
    $row_so_mode = isset($so_modes[$row_spid]) ? $so_modes[$row_spid] : (isset($so_modes[0]) ? $so_modes[0] : 'before');

    $step_gross = (float)$gr['gross_usd'];
    $ex_rate    = (float)$gr['exchange_rate'] ?: 26.0;

    // Load other charges excluding Bank Charge (statutoryid = 7)
    $charges_usd = 0.0;
    $charges_zig = 0.0;
    if ($has_bbc) {
        $bbcq = $conn->query("
            SELECT st.description, SUM(bbc.usd_value) usd_val, SUM(bbc.zim_value) zim_val, gc.statutoryid
            FROM balanced_bales_charges bbc
            JOIN grower_charges gc ON gc.id = bbc.grower_chargeid
            JOIN statutory st      ON st.id = gc.statutoryid
            WHERE bbc.balanced_balesid = $bbid AND gc.statutoryid != 7
            GROUP BY st.id
        ");
        while ($bbcq && $bc = $bbcq->fetch_assoc()) {
            $key = 'bbc_' . md5($bc['description']);
            if (isset($deductions[$key])) {
                $deductions[$key] += (float)$bc['usd_val'];
                $ded_zwg += (float)$bc['zim_val'];
            }
            $charges_usd += (float)$bc['usd_val'];
            $charges_zig += (float)$bc['zim_val'];
        }
    }

    // Load TIMB internal and external stop orders
    $iso_usd = 0.0;
    $ext_so_usd = 0.0;
    
    // Internal TIMB SO
    $iso_q = $conn->query("SELECT SUM(amount_deducted) AS t FROM internal_stop_order_deducted_amounts WHERE balanced_balesid = $bbid");
    if ($iso_q) {
        $iso_usd = (float)$iso_q->fetch_assoc()['t'];
    }
    
    // External TIMB SO
    $ext_so_q = $conn->query("SELECT SUM(amount_deducted) AS t FROM stoporder_deductions WHERE balanced_balesid = $bbid");
    if ($ext_so_q) {
        $ext_so_usd = (float)$ext_so_q->fetch_assoc()['t'];
    }
    
    $timb_so_total_disp = $iso_usd + $ext_so_usd;
    
    if (isset($deductions['timb_so'])) {
        $deductions['timb_so'] = $timb_so_total_disp;
    }

    // Load company internal stop orders
    $company_so = 0.0;
    if ($has_iso) {
        $isq = $conn->query("
            SELECT charge_type, SUM(amount) amt
            FROM internal_stoporders
            WHERE (transporter_growersid = $tgid OR (growerid = $gid AND (transporter_growersid IS NULL OR transporter_growersid = 0)))
              AND active IN (0,1)
            GROUP BY charge_type
        ");
        $first_split = ($conn->query("SELECT MIN(splitid) s FROM balanced_bales WHERE transporter_growersid=$tgid AND DATE(created_at)='$date_f'")->fetch_assoc()['s'] ?? 0);
        if ($split_no == (int)$first_split) {
            while ($isq && $iso = $isq->fetch_assoc()) {
                $key = 'iso_' . md5($iso['charge_type']);
                if (isset($deductions[$key])) {
                    $deductions[$key] += (float)$iso['amt'];
                }
                $company_so += (float)$iso['amt'];
            }
        }
    }

    // Now calculate split base and dynamic Bank Charge
    if ($row_so_mode === 'before') {
        $step_after_so   = max(0, $step_gross - $timb_so_total_disp);
        $split_base      = $step_after_so;
    } else {
        $step_after_so   = $step_gross;
        $split_base      = $step_gross;
    }

    $step_usd_portion = $split_base * 0.70;
    $step_zig_portion = $split_base * 0.30;
    $step_zig_in_zig  = $step_zig_portion * $ex_rate;

    $step_usd_after_timb_so_after = $row_so_mode === 'after'
        ? max(0, $step_usd_portion - $timb_so_total_disp)
        : $step_usd_portion;

    $usd_net_before_bc = max(0, $step_usd_after_timb_so_after - $charges_usd - $company_so);
    $zig_net_before_bc = max(0, $step_zig_in_zig - $charges_zig);

    $bc_usd = $usd_net_before_bc * $row_bc_rate;
    $bc_zig = $zig_net_before_bc * $row_bc_rate;

    // Add Bank Charge to deductions array
    $bc_key = 'bbc_' . md5('Bank Charge');
    if (isset($deductions[$bc_key])) {
        $deductions[$bc_key] += $bc_usd;
        $ded_zwg += $bc_zig;
    }

    $display_usd_net = max(0, $usd_net_before_bc - $bc_usd);
    $display_zig_net = max(0, $zig_net_before_bc - $bc_zig);

    $total_ded   = array_sum($deductions);
    $net_bb      = $display_usd_net;
    $gr_comm_usd = max(0, $step_gross - $net_bb - $total_ded);

    foreach ($deductions as $k => $v) $col_totals[$k] = ($col_totals[$k] ?? 0) + $v;
    $grand['mass']        += $mass;
    $grand['gross_usd']   += $gross_usd;
    $grand['total_ded']   += $total_ded;
    $grand['net_usd']     += $net_bb;
    $grand['gr_comm_usd'] += $gr_comm_usd;

    $rows[] = [
        'grower_id'   => $gid,
        'grower_num'  => $gr['grower_num'],
        'grower_name' => trim($gr['grower_name']),
        'province'    => $gr['province'] ?? '',
        'sheet_ref'   => $sheet_ref,
        'split_no'    => $split_no,
        'split_label' => $split_label,
        'mass'        => $mass,
        'gross_usd'   => $gross_usd,
        'deductions'  => $deductions,
        'total_ded'   => $total_ded,
        'net_usd'     => $net_bb,
        'gr_comm_usd' => $gr_comm_usd,
        'ded_zwg'     => $ded_zwg,
    ];
endwhile;
endif;

// ── Load templates ────────────────────────────────────────────────────────
$templates = [];
$tq = $conn->query("SELECT * FROM deduction_templates WHERE selling_pointid=$spid ORDER BY created_at DESC");
while ($tq && $tr = $tq->fetch_assoc()) {
    $tr['items_decoded'] = json_decode($tr['items'], true) ?: [];
    $templates[] = $tr;
}

// ── Load growers for apply-template form ─────────────────────────────────
$growers_list = [];
$glq = $conn->query("SELECT id, grower_num, CONCAT(name,' ',surname) gname FROM growers WHERE selling_pointid=$spid ORDER BY grower_num LIMIT 500");
while ($glq && $gr2 = $glq->fetch_assoc()) $growers_list[] = $gr2;

// ── CSV EXPORT ────────────────────────────────────────────────────────────
if ($export === 'csv') {
    ob_start();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="deductions_'.str_replace('-','',$date_f).'.csv"');

    $hdrs = ['Created on','Grower Number','Grower Name','Province','Split','Sales Sheet Ref',
             'Mass (kg)','Gross USD'];
    foreach ($creditor_cols as $label => $key) $hdrs[] = $label;
    $hdrs[] = 'Grower Commission US';
    $hdrs[] = 'Total Deductions USD';
    $hdrs[] = 'Net USD';

    echo implode(',', array_map(fn($h)=>'"'.$h.'"', $hdrs))."\r\n";

    foreach ($rows as $r) {
        $cols = [
            '"'.date('Y-m-d H:i:s').'"',
            '"'.$r['grower_num'].'"',
            '"'.$r['grower_name'].'"',
            '"'.$r['province'].'"',
            '"'.$r['split_label'].'"',
            '"'.$r['sheet_ref'].'"',
            number_format($r['mass'],1),
            number_format($r['gross_usd'],4),
        ];
        foreach ($creditor_cols as $label => $key)
            $cols[] = number_format($r['deductions'][$key] ?? 0, 8);
        $cols[] = number_format($r['gr_comm_usd'],4);
        $cols[] = number_format($r['total_ded'],4);
        $cols[] = number_format($r['net_usd'],4);
        echo implode(',', $cols)."\r\n";
    }
    $tcols = ['"TOTALS"','""','""','""','""','""',
              number_format(array_sum(array_column($rows,'mass')),1),
              number_format($grand['gross_usd'],4)];
    foreach ($creditor_cols as $label => $key)
        $tcols[] = number_format($col_totals[$key] ?? 0, 8);
    $tcols[] = number_format($grand['gr_comm_usd'],4);
    $tcols[] = number_format($grand['total_ded'],4);
    $tcols[] = number_format($grand['net_usd'],4);
    echo implode(',', $tcols)."\r\n";
    ob_end_flush();
    exit;
}

// ── PDF EXPORT ────────────────────────────────────────────────────────────
if ($export === 'pdf') {
    $date_label = date('d F Y', strtotime($date_f));
    header('Content-Type: text/html; charset=utf-8');
?><?php echo '<!DOCTYPE html>'; ?><html lang="en"><head><meta charset="UTF-8">
<title>Deductions — <?=$date_label?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;font-size:7.5px;color:#111;padding:10px}
.hdr{display:flex;justify-content:space-between;align-items:flex-start;
     margin-bottom:8px;padding-bottom:7px;border-bottom:2px solid #1a1208}
.hdr h1{font-size:14px;font-weight:700;color:#1a1208;letter-spacing:.05em}
.hdr h2{font-size:8px;color:#666;margin-top:1px}
.tag{background:#c8952a;color:#fff;font-size:7px;font-weight:700;
     padding:2px 5px;border-radius:2px;text-transform:uppercase;letter-spacing:.1em}
.meta{display:flex;gap:16px;background:#f5f0e8;padding:6px 10px;
      border-radius:3px;margin-bottom:8px}
.mi{min-width:80px}
.mi label{display:block;font-size:6.5px;font-weight:700;letter-spacing:.1em;
           text-transform:uppercase;color:#7a6e5f;margin-bottom:1px}
.mi span{font-size:9px;font-weight:700}
table{width:100%;border-collapse:collapse}
thead th{background:#1a1208;color:#e8b84b;font-weight:700;letter-spacing:.06em;
         text-transform:uppercase;padding:3.5px 4px;text-align:left;
         font-size:6.5px;white-space:nowrap}
thead th.right{text-align:right}
tbody td{padding:3px 4px;border-bottom:1px solid #ede6d6;font-size:7px;vertical-align:middle}
tbody tr:nth-child(even){background:#faf8f5}
tfoot td{background:#1a1208;color:#e8b84b;font-weight:700;
         padding:3.5px 4px;font-size:7px;border-top:2px solid #c8952a}
.right{text-align:right}
.danger{color:#c03a2b}
.green{color:#3d6b35;font-weight:600}
.muted{color:#888}
.badge{background:#fdf6e8;border:1px solid #e8c96a;padding:1px 3px;
       border-radius:2px;font-weight:700;font-size:6.5px}
@media print{
  body{padding:0}
  @page{margin:8mm 6mm;size:landscape}
}
</style></head><body>

<div class="hdr">
  <div><h1><?=htmlspecialchars($company)?></h1><h2>Stop Order Deductions Report</h2></div>
  <div style="text-align:right">
    <span class="tag">Deductions Report</span>
    <div style="font-size:7px;color:#666;margin-top:3px">
      Sale date: <?=$date_label?><br>
      Printed: <?=date('d M Y H:i')?>
    </div>
  </div>
</div>

<div class="meta">
  <div class="mi"><label>Sale Date</label><span><?=$date_label?></span></div>
  <div class="mi"><label>Growers</label><span><?=count($rows)?></span></div>
  <div class="mi"><label>Gross USD</label><span>$<?=number_format($grand['gross_usd'],2)?></span></div>
  <div class="mi"><label>Total Deductions</label><span style="color:#c03a2b">$<?=number_format($grand['total_ded'],2)?></span></div>
  <div class="mi"><label>Net USD</label><span style="color:#3d6b35">$<?=number_format($grand['net_usd'],2)?></span></div>
</div>

<table>
<thead><tr>
  <th>#</th>
  <th>Grower #</th>
  <th>Grower Name</th>
  <th>Split</th>
  <th>Sales Sheet</th>
  <th class="right">Mass kg</th>
  <th class="right">Gross USD</th>
  <?php foreach($creditor_cols as $label=>$key): ?>
  <th class="right"><?=htmlspecialchars($label)?></th>
  <?php endforeach; ?>
  <th class="right">Gr. Comm US</th>
  <th class="right">Total Ded.</th>
  <th class="right">Net USD</th>
</tr></thead>
<tbody>
<?php $seq=0; foreach($rows as $r): $seq++; ?>
<tr>
  <td class="muted"><?=$seq?></td>
  <td><span class="badge"><?=htmlspecialchars($r['grower_num'])?></span></td>
  <td><?=htmlspecialchars($r['grower_name'])?></td>
  <td class="muted" style="font-family:monospace"><?=htmlspecialchars($r['split_label'])?></td>
  <td class="muted"><?=htmlspecialchars($r['sheet_ref'])?></td>
  <td class="right"><?=number_format($r['mass'],0)?></td>
  <td class="right">$<?=number_format($r['gross_usd'],4)?></td>
  <?php foreach($creditor_cols as $label=>$key):
    $v=$r['deductions'][$key]??0; ?>
  <?php $cls=$v>0?'danger':'muted'; $val=$v>0?number_format($v,2):'0.0'; ?>
  <td class="right <?=$cls?>"><?=$val?></td>
  <?php endforeach; ?>
  <?php $gccls=$r['gr_comm_usd']>0?'danger':'muted'; $gcval=$r['gr_comm_usd']>0?number_format($r['gr_comm_usd'],4):'0.0'; ?>
  <td class="right <?=$gccls?>"><?=$gcval?></td>
  <td class="right danger"><?=$r['total_ded']>0?'$'.number_format($r['total_ded'],2):'—'?></td>
  <td class="right green">$<?=number_format($r['net_usd'],4)?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr>
  <td colspan="5">TOTAL — <?=count($rows)?> growers</td>
  <td class="right"><?=number_format($grand['mass'],0)?></td>
  <td class="right">$<?=number_format($grand['gross_usd'],2)?></td>
  <?php foreach($creditor_cols as $label=>$key): ?>
  <td class="right">$<?=number_format($col_totals[$key]??0,2)?></td>
  <?php endforeach; ?>
  <td class="right">$<?=number_format($grand['gr_comm_usd'],4)?></td>
  <td class="right">$<?=number_format($grand['total_ded'],2)?></td>
  <td class="right">$<?=number_format($grand['net_usd'],2)?></td>
</tr></tfoot>
</table>
<script>window.print();</script>
</body></html>
<?php
    exit;
}

// ── HTML PAGE ─────────────────────────────────────────────────────────────
include "includes/layout.php";
?>

<style>
/* Template items builder */
.tpl-item-row { display:grid; grid-template-columns:1fr 130px 100px 80px 32px; gap:.5rem; align-items:center; margin-bottom:.45rem; }
.tpl-preview-item { display:flex; align-items:center; gap:.4rem; padding:.2rem .4rem; background:var(--cream-d); border-radius:3px; font-size:.7rem; }
.tpl-type-badge { font-size:.78rem; padding:1px 5px; border-radius:2px; font-weight:600; }
.tpl-fixed  { background:rgba(26,107,154,.1); color:var(--info); }
.tpl-pct    { background:var(--warn-tint); color:var(--warn-text); }
.tpl-zig    { background:rgba(61,107,53,.1);  color:var(--leaf); }
.tab-bar { display:flex; gap:0; border-bottom:1px solid var(--border); margin-bottom:1.25rem; }
.tab-btn { padding:.55rem 1.1rem; font-size:.75rem; font-weight:500; cursor:pointer; border:none; background:none; color:var(--muted); border-bottom:2px solid transparent; transition:all .13s; letter-spacing:.04em; }
.tab-btn.active { color:var(--gold); border-bottom-color:var(--gold); }
.tab-btn:hover:not(.active) { color:var(--text-strong); }
.tab-pane { display:none; }
.tab-pane.active { display:block; }

/* Deduction expandable detail rows */
.ded-row:hover { background:rgba(200,149,42,.04); }
.ded-chevron { display:inline-block; transition:transform .15s ease; font-size:.65rem; }
.ded-row.open .ded-chevron { transform:rotate(90deg); }
.ded-detail td { background:rgba(245,240,232,.5); border-bottom:1px solid var(--border); }
.ded-chip {
  display:inline-flex; align-items:center; gap:.35rem;
  padding:.25rem .55rem; border-radius:4px;
  background:rgba(192,57,43,.06); border:1px solid rgba(192,57,43,.12);
  font-size:.72rem; line-height:1;
}
.ded-chip-label { color:var(--muted); font-size:.65rem; letter-spacing:.03em; }
.ded-chip-value { font-weight:600; color:var(--danger); font-family:'SFMono-Regular',Consolas,monospace; font-size:.72rem; }
.ded-chip-comm {
  background:rgba(200,149,42,.08); border-color:rgba(200,149,42,.18);
}
.ded-chip-comm .ded-chip-value { color:var(--warn-text); }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Stop Order Deductions</h2>
    <p>Deductions per grower on a selected sale date · Templates for reusable deduction sets</p>
  </div>
  <?php if(!empty($rows)): ?>
  <div class="flex gap-2">
    <a href="?date=<?=htmlspecialchars((string)$date_f)?><?=$search?"&q=".urlencode($search):''?>&export=csv"
       class="btn btn-ghost">⬇ CSV</a>
    <a href="?date=<?=htmlspecialchars((string)$date_f)?><?=$search?"&q=".urlencode($search):''?>&export=pdf"
       class="btn btn-ghost" target="_blank">🖨 PDF</a>
  </div>
  <?php endif; ?>
</div>

<!-- Tab bar -->
<div class="tab-bar">
  <button class="tab-btn <?=$tab==='deductions'?'active':''?>" onclick="switchTab('deductions')">📋 Deductions</button>
  <button class="tab-btn <?=$tab==='templates'?'active':''?>" onclick="switchTab('templates')">🗂 Templates <span class="badge badge-gray" style="margin-left:4px"><?=count($templates)?></span></button>
</div>

<?php if($tmsg): ?><div class="alert alert-success"><?=htmlspecialchars($tmsg)?></div><?php endif; ?>
<?php if($terr): ?><div class="alert alert-danger"><?=htmlspecialchars($terr)?></div><?php endif; ?>

<!-- ═══════════════ TAB: DEDUCTIONS ═══════════════ -->
<div class="tab-pane <?=$tab==='deductions'?'active':''?>" id="tab-deductions">

<!-- Filter -->
<div class="card mb-3">
<div class="card-body" style="padding:.65rem 1.15rem">
<form method="GET" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
  <input type="hidden" name="tab" value="deductions">
  <div class="form-group" style="margin:0">
    <label style="font-size:.65rem">Sale Date</label>
    <input type="date" name="date" class="form-control" value="<?=htmlspecialchars((string)$date_f)?>"
           style="padding:.35rem .6rem" onchange="this.form.submit()">
  </div>
  <div class="form-group" style="margin:0;flex:1;min-width:150px">
    <label style="font-size:.65rem">Search grower</label>
    <input type="text" name="q" class="form-control" value="<?=htmlspecialchars($search)?>"
           placeholder="Grower # or name" style="padding:.35rem .6rem">
  </div>
  <button type="submit" class="btn btn-primary">Apply</button>
  <?php if($search||$date_f!==date('Y-m-d')): ?>
  <a href="deductions.php" class="btn btn-ghost">Reset</a>
  <?php endif; ?>
</form>
</div>
</div>

<?php if(empty($rows)): ?>
<div class="card">
  <div class="card-body text-center text-muted" style="padding:3rem">
    <div style="font-size:2rem;margin-bottom:.5rem;opacity:.3">📋</div>
    No growers with sold bales on <?=date('d M Y',strtotime($date_f))?>.
  </div>
</div>

<?php else: ?>

<!-- KPI strip -->
<div class="kpi-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.25rem">
  <div class="kpi-card">
    <div class="kpi-label">Growers</div>
    <div class="kpi-value"><?=count($rows)?></div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--gold)">
    <div class="kpi-label">Gross USD</div>
    <div class="kpi-value sm">$<?=number_format($grand['gross_usd'],2)?></div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--danger)">
    <div class="kpi-label">Total Deductions</div>
    <div class="kpi-value sm">$<?=number_format($grand['total_ded'],2)?></div>
    <div class="kpi-sub"><?=$grand['gross_usd']>0?round($grand['total_ded']/$grand['gross_usd']*100,1).'% of gross':'—'?></div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--leaf)">
    <div class="kpi-label">Net USD</div>
    <div class="kpi-value sm">$<?=number_format($grand['net_usd'],2)?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Deduction Types</div>
    <div class="kpi-value"><?=count($creditor_cols)?></div>
    <div class="kpi-sub"><?=implode(', ',array_slice(array_keys($creditor_cols),0,2)).(count($creditor_cols)>2?' …':'')?></div>
  </div>
</div>

<!-- Main table -->
<div class="card">
<div class="card-head">
  <h3>Deductions — <?=date('d M Y',strtotime($date_f))?></h3>
  <div class="flex gap-2">
    <a href="?date=<?=htmlspecialchars((string)$date_f)?><?=$search?"&q=".urlencode($search):''?>&export=csv"
       class="btn btn-ghost btn-sm">⬇ CSV</a>
    <a href="?date=<?=htmlspecialchars((string)$date_f)?><?=$search?"&q=".urlencode($search):''?>&export=pdf"
       class="btn btn-ghost btn-sm" target="_blank">🖨 PDF</a>
  </div>
</div>
<div class="table-wrap">
<table id="ded-table">
<thead>
<tr>
  <th style="width:30px">#</th>
  <th>Grower</th>
  <th style="width:55px">Split</th>
  <th style="width:55px">Sheet</th>
  <th class="text-right" style="width:70px">Mass</th>
  <th class="text-right" style="width:100px">Gross USD</th>
  <th class="text-right" style="width:110px;color:#f08080">Deductions</th>
  <th class="text-right" style="width:100px;color:var(--leaf)">Net USD</th>
  <th style="width:28px"></th>
</tr>
</thead>
<tbody>
<?php
$seq = 0;
$prev_grower = null;
foreach($rows as $idx => $r):
    $seq++;
    $is_new_grower = ($r['grower_num'] !== $prev_grower);
    $prev_grower   = $r['grower_num'];
    $row_border = $is_new_grower && $idx > 0 ? 'border-top:2px solid var(--border)' : '';
    $has_deds = $r['total_ded'] > 0;
?>
<tr class="ded-row" style="<?=$row_border?>;cursor:<?=$has_deds?'pointer':'default'?>" <?=$has_deds?'onclick="toggleDetail(this)"':''?>>
  <td class="td-muted" style="font-size:.7rem"><?=$seq?></td>
  <td>
    <?php if($is_new_grower): ?>
    <span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($r['grower_num'])?></span>
    <span style="margin-left:4px;font-size:.8rem;font-weight:500"><?=htmlspecialchars($r['grower_name'])?></span>
    <?php else: ?>
    <span class="td-muted" style="font-size:.7rem;padding-left:4px">↳</span>
    <span style="font-size:.75rem;color:var(--muted)"><?=htmlspecialchars($r['grower_name'])?></span>
    <?php endif; ?>
  </td>
  <td>
    <span class="badge badge-gray" style="font-size:.72rem;font-family:monospace"><?=htmlspecialchars($r['split_label'])?></span>
  </td>
  <td class="td-muted" style="font-size:.75rem;font-family:monospace"><?=htmlspecialchars($r['sheet_ref'])?></td>
  <td class="td-mono text-right"><?=number_format($r['mass'],0)?></td>
  <td class="td-mono text-right fw-500">$<?=number_format($r['gross_usd'],2)?></td>
  <td class="td-mono text-right fw-500" style="color:var(--danger)">
    <?=$r['total_ded'] > 0 ? '$'.number_format($r['total_ded'],2) : '—'?>
  </td>
  <td class="td-mono text-right fw-500" style="color:var(--leaf)">$<?=number_format($r['net_usd'],2)?></td>
  <td style="text-align:center;font-size:.7rem;color:var(--muted)">
    <?=$has_deds?'<span class="ded-chevron">▸</span>':''?>
  </td>
</tr>
<?php if($has_deds): ?>
<tr class="ded-detail" style="display:none">
  <td></td>
  <td colspan="8" style="padding:.5rem .8rem .7rem">
    <div style="display:flex;flex-wrap:wrap;gap:.4rem .6rem;align-items:center">
    <?php foreach($creditor_cols as $label=>$key):
        $v = $r['deductions'][$key] ?? 0;
        if($v <= 0) continue;
    ?>
      <div class="ded-chip">
        <span class="ded-chip-label"><?=htmlspecialchars($label)?></span>
        <span class="ded-chip-value">$<?=number_format($v,2)?></span>
      </div>
    <?php endforeach; ?>
    <?php if($r['gr_comm_usd'] > 0): ?>
      <div class="ded-chip ded-chip-comm">
        <span class="ded-chip-label">Gr. Commission (30%)</span>
        <span class="ded-chip-value">$<?=number_format($r['gr_comm_usd'],2)?></span>
      </div>
    <?php endif; ?>
    </div>
  </td>
</tr>
<?php endif; ?>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr style="background:var(--surface-2);color:var(--text-muted)">
  <td colspan="4" style="padding:.55rem .9rem;font-weight:500;font-size:.78rem">
    TOTAL — <?=count($rows)?> growers
  </td>
  <td class="td-mono text-right" style="padding:.55rem .9rem">
    <?=number_format($grand['mass'],0)?>
  </td>
  <td class="td-mono text-right fw-500" style="padding:.55rem .9rem">
    $<?=number_format($grand['gross_usd'],2)?>
  </td>
  <td class="td-mono text-right fw-500" style="padding:.55rem .9rem;color:#f08080">
    $<?=number_format($grand['total_ded'],2)?>
  </td>
  <td class="td-mono text-right fw-500" style="padding:.55rem .9rem;color:var(--leaf)">
    $<?=number_format($grand['net_usd'],2)?>
  </td>
  <td></td>
</tr>
</tfoot>
</table>
</div>
</div>

<?php endif; ?>
</div><!-- /tab-deductions -->

<!-- ═══════════════ TAB: TEMPLATES ═══════════════ -->
<div class="tab-pane <?=$tab==='templates'?'active':''?>" id="tab-templates">

<div class="grid-2" style="align-items:start;gap:1.25rem">

<!-- Left: Create template form -->
<div>
<div class="card">
<div class="card-head"><h3>➕ Create Deduction Template</h3></div>
<div class="card-body">
<form method="POST">
  <input type="hidden" name="action" value="create_template">
  <div class="form-group">
    <label>Template Name</label>
    <input type="text" name="tpl_name" class="form-control" placeholder="e.g. Standard Levy Pack" required maxlength="80">
  </div>
  <div class="form-group">
    <label>Description (optional)</label>
    <input type="text" name="tpl_desc" class="form-control" placeholder="Short description of this template" maxlength="255">
  </div>

  <div style="font-size:.78rem;font-weight:500;color:var(--muted);margin-bottom:.6rem">
    Line Items
  </div>
  <div id="tpl-items-wrap">
    <div class="tpl-item-row">
      <input type="text" name="item_label[]" class="form-control" placeholder="Label (e.g. TIMB Levy)" required>
      <select name="item_type[]" class="form-control">
        <option value="fixed_usd">Fixed USD</option>
        <option value="percent">Percent %</option>
        <option value="fixed_zig">Fixed ZiG</option>
      </select>
      <input type="number" name="item_value[]" class="form-control" step="0.0001" min="0.0001" placeholder="0.00" required>
      <select name="item_currency[]" class="form-control">
        <option value="USD">USD</option>
        <option value="ZIG">ZiG</option>
      </select>
      <button type="button" onclick="addTplItem()" class="btn btn-ghost btn-sm" title="Add row">＋</button>
    </div>
  </div>
  <button type="button" onclick="addTplItem()" class="btn btn-ghost btn-sm mb-2" style="width:100%;margin-top:.2rem">
    ＋ Add Line Item
  </button>
  <button type="submit" class="btn btn-primary" style="width:100%">Save Template</button>
</form>
</div>
</div>

<!-- Apply template form -->
<div class="card mt-2">
<div class="card-head"><h3>⚡ Apply Template to Grower</h3></div>
<div class="card-body">
<?php if(empty($templates)): ?>
  <p class="text-muted" style="font-size:.8rem">No templates yet. Create one above first.</p>
<?php else: ?>
<form method="POST">
  <input type="hidden" name="action" value="apply_template">
  <div class="form-group">
    <label>Select Template</label>
    <select name="template_id" class="form-control" required>
      <option value="">— Choose template —</option>
      <?php foreach($templates as $tpl): ?>
      <option value="<?=$tpl['id']?>"><?=htmlspecialchars($tpl['name'])?> (<?=count($tpl['items_decoded'])?> items)</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>Grower</label>
    <select name="grower_id" class="form-control" required>
      <option value="">— Select grower —</option>
      <?php foreach($growers_list as $gl): ?>
      <option value="<?=$gl['id']?>"><?=htmlspecialchars($gl['grower_num'].' — '.$gl['gname'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>Apply Date</label>
    <input type="date" name="apply_date" class="form-control" value="<?=date('Y-m-d')?>">
  </div>
  <button type="submit" class="btn btn-gold" style="width:100%" onclick="return confirm('Apply this template to the grower balance?')">
    ⚡ Apply Template
  </button>
</form>
<?php endif; ?>
</div>
</div>
</div><!-- /left -->

<!-- Right: Template list -->
<div>
<div class="card">
<div class="card-head">
  <h3>Saved Templates</h3>
  <span class="badge badge-gold"><?=count($templates)?></span>
</div>
<?php if(empty($templates)): ?>
<div class="card-body text-center text-muted" style="padding:2.5rem">
  <div style="font-size:2rem;opacity:.25;margin-bottom:.5rem">🗂</div>
  No templates yet. Create your first template on the left.
</div>
<?php else: ?>
<div style="padding:.6rem">
<?php foreach($templates as $tpl): ?>
<div style="border:1px solid var(--border);border-radius:var(--radius);padding:.85rem;margin-bottom:.65rem;position:relative">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.5rem;margin-bottom:.45rem">
    <div>
      <div style="font-weight:500;font-size:.88rem"><?=htmlspecialchars($tpl['name'])?></div>
      <?php if($tpl['description']): ?>
      <div style="font-size:.72rem;color:var(--muted);margin-top:1px"><?=htmlspecialchars($tpl['description'])?></div>
      <?php endif; ?>
    </div>
    <form method="POST" style="flex-shrink:0">
      <input type="hidden" name="action" value="delete_template">
      <input type="hidden" name="template_id" value="<?=$tpl['id']?>">
      <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this template?')">🗑</button>
    </form>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:.3rem">
    <?php foreach($tpl['items_decoded'] as $it):
      $tcls = $it['type']==='percent'?'tpl-pct':($it['type']==='fixed_zig'?'tpl-zig':'tpl-fixed');
      $tlabel = $it['type']==='percent'?'%':($it['type']==='fixed_zig'?'ZiG':'USD');
    ?>
    <div class="tpl-preview-item">
      <span class="tpl-type-badge <?=$tcls?>"><?=$tlabel?></span>
      <span><?=htmlspecialchars($it['label'])?></span>
      <strong><?=number_format((float)$it['value'],2)?></strong>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="font-size:.72rem;color:var(--muted);margin-top:.4rem">
    Created <?=date('d M Y', strtotime($tpl['created_at']))?>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div><!-- /right -->

</div><!-- /grid-2 -->
</div><!-- /tab-templates -->

<script>
function switchTab(t) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-'+t).classList.add('active');
  document.querySelectorAll('.tab-btn').forEach(b => { if(b.textContent.toLowerCase().includes(t.substring(0,5))) b.classList.add('active'); });
  // Update URL without reload
  var url = new URL(window.location);
  url.searchParams.set('tab', t);
  history.replaceState(null, '', url);
}

function addTplItem() {
  var wrap = document.getElementById('tpl-items-wrap');
  var tpl = wrap.querySelector('.tpl-item-row').cloneNode(true);
  // Reset values
  tpl.querySelectorAll('input[type=text],input[type=number]').forEach(i => i.value = '');
  tpl.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
  // Change + to − for remove
  var btn = tpl.querySelector('button');
  btn.textContent = '−';
  btn.title = 'Remove row';
  btn.onclick = function() { this.closest('.tpl-item-row').remove(); };
  wrap.appendChild(tpl);
}

function toggleDetail(row) {
  var detail = row.nextElementSibling;
  if (!detail || !detail.classList.contains('ded-detail')) return;
  var isOpen = detail.style.display !== 'none';
  detail.style.display = isOpen ? 'none' : 'table-row';
  row.classList.toggle('open', !isOpen);
}
</script>

<?php include "includes/layout_end.php"; ?>
