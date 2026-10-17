<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

// ── Resolve BBIDs from query string ──────────────────────────────────────────
$spid_filter = (int)($_GET['spid'] ?? 0);

if (!empty($_GET['bbids'])) {
    // Explicit list
    $raw_ids = array_filter(array_map('intval', explode(',', $_GET['bbids'])));
} elseif (($_GET['filter'] ?? '') === 'unprinted') {
    // All unprinted for this selling point
    $where_sp  = $spid_filter ? "AND g.selling_pointid=$spid_filter" : "";
    $ids_q     = $conn->query("
        SELECT bb.id FROM balanced_bales bb
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        WHERE bb.stoporder_processed=1
          AND (bb.printed IS NULL OR bb.printed=0)
          $where_sp
        ORDER BY bb.id ASC
        LIMIT 500
    ");
    $raw_ids = [];
    if ($ids_q && $ids_q !== true) {
        while ($row = $ids_q->fetch_assoc()) $raw_ids[] = (int)$row['id'];
    }
} else {
    echo "<p style='font-family:sans-serif;padding:2rem'>No sheets requested.</p>"; exit;
}

if (empty($raw_ids)) {
    echo "<p style='font-family:sans-serif;padding:2rem'>No sheets found.</p>"; exit;
}

$bbids_safe = array_values(array_unique($raw_ids));

// ── Company info ─────────────────────────────────────────────────────────────
$company = [
    'name'       => 'Core Africa Group',
    'address'    => '',
    'phone'      => '',
    'email'      => '',
    'website'    => '',
    'reg_number' => '',
    'vat_number' => '',
    'logo_path'  => '',
    'tagline'    => 'Zimbabwe Tobacco Floor Auction System',
];
if ($conn->query("SHOW TABLES LIKE 'parameters'")->num_rows) {
    $pq = $conn->query("SELECT param_key, param_value FROM parameters WHERE param_key LIKE 'company_%'");
    if ($pq) {
        while ($pr = $pq->fetch_assoc()) {
            $key = str_replace('company_', '', $pr['param_key']);
            if (array_key_exists($key, $company)) $company[$key] = $pr['param_value'];
        }
    }
}

// Logo as base64
$logo_base64 = '';
if ($company['logo_path'] && file_exists(__DIR__ . '/' . ltrim($company['logo_path'], '/'))) {
    $logo_data   = file_get_contents(__DIR__ . '/' . ltrim($company['logo_path'], '/'));
    $finfo       = finfo_open(FILEINFO_MIME_TYPE);
    $mime        = finfo_file($finfo, __DIR__ . '/' . ltrim($company['logo_path'], '/'));
    finfo_close($finfo);
    $logo_base64 = 'data:' . $mime . ';base64,' . base64_encode($logo_data);
}

// RBZ split %
$_rbz_q      = $conn->query("SELECT usd_percent,other_currency_percent FROM rbz_retention_rate LIMIT 1");
$rbz_row     = ($_rbz_q && $_rbz_q !== true) ? $_rbz_q->fetch_assoc() : null;
$rbz_usd_pct = $rbz_row ? (float)$rbz_row['usd_percent'] : 0.70;
$rbz_zig_pct = 1 - $rbz_usd_pct;

// ── Load all sheets' master data in one query ─────────────────────────────────
$id_list = implode(',', $bbids_safe);
$master_q = $conn->query("
    SELECT bb.id bbid, bb.splitid, bb.sale_batchid, bb.transporter_growersid, bb.printed,
           g.id AS growerid, g.grower_num, g.name, g.surname, g.national_id, g.phone,
           g.farm_name, g.province, g.district, g.contractor, g.selling_pointid,
           t.name AS transporter_name, t.truck_num,
           COALESCE(s.description,'') AS season_name,
           sp.name AS selling_point_name, COALESCE(sp.floor_code,'') AS floor_code,
           dt.mass, dt.usd_value, dt.zim_value,
           dt.laid_bales, dt.sold_bales, dt.rejected_bales,
           dt.average_price, dt.created_at AS dt_created,
           er.amount AS exchange_rate,
           dtn.usd_net_value, dtn.zim_net_value,
           bb.sale_batchid AS batch_num
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g   ON g.id  = tg.growerid
    JOIN transporter t ON t.id = tg.transporterid
    LEFT JOIN seasons s         ON s.id  = t.seasonid
    LEFT JOIN selling_point sp  ON sp.id = g.selling_pointid
    LEFT JOIN daily_totals dt   ON dt.balanced_balesid = bb.id
    LEFT JOIN exchange_rate er  ON er.id = dt.exchange_rateid
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid = bb.id
    WHERE bb.id IN ($id_list)
    ORDER BY FIELD(bb.id, $id_list)
");
$sheets_data = [];
if ($master_q && $master_q !== true) {
    while ($row = $master_q->fetch_assoc()) $sheets_data[$row['bbid']] = $row;
}

// SO mode per selling point (cache)
$so_mode_cache = [];
function get_so_mode(mysqli $conn, int $spid): string {
    global $so_mode_cache;
    if (isset($so_mode_cache[$spid])) return $so_mode_cache[$spid];
    if ($spid) {
        $q = $conn->query("SELECT param_value FROM parameters
            WHERE param_key='so_deduction_mode' AND selling_pointid=$spid LIMIT 1");
        if ($q && $q !== true && $q->num_rows)
            return $so_mode_cache[$spid] = $q->fetch_assoc()['param_value'];
    }
    return $so_mode_cache[$spid] = 'before';
}

// ── Helper: load and optionally auto-fix daily_totals ───────────────────────
function maybe_fix_sheet(mysqli $conn, array &$bb): void {
    if ($bb['mass'] || $bb['usd_value']) return; // already has data
    $bbid  = (int)$bb['bbid'];
    $tg_r  = $conn->query("SELECT transporter_growersid, growerid, splitid, created_at FROM balanced_bales WHERE id=$bbid LIMIT 1");
    $tg    = ($tg_r && $tg_r !== true) ? $tg_r->fetch_assoc() : null;
    if (!$tg) return;

    $tgid  = (int)$tg['transporter_growersid'];
    $split = (int)$tg['splitid'];

    $bales_q = $conn->query("
        SELECT bm.mass, sb.price, COALESCE(sb.sale_code,'') sale_code, sb.id sold_id,
               er.id exchange_rateid, er.amount exchange_rate_amount
        FROM bale_junus bj
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN tickets tk ON tk.bale_junusid=bj.id
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        LEFT JOIN exchange_rate er ON tk.start_of_dayid=er.start_of_dayid
        WHERE bj.transporter_growersid=$tgid AND bj.splitid=$split
    ");
    $gross=0; $mass=0; $sold=0; $rej=0; $er_fix=0; $er_id=0;
    while ($bales_q && $bf = $bales_q->fetch_assoc()) {
        $sc = strtoupper(trim($bf['sale_code']));
        $is_rej = in_array($sc,['R','CO','RO']) || ($sc && $sc[0]==='R' && strlen($sc)<=3);
        if ($is_rej) { $rej++; continue; }
        if (!$bf['sold_id']) continue;
        $p = (float)$bf['price']; if ($p > 10) $p /= 100;
        $gross += $p * (float)$bf['mass'];
        $mass  += (float)$bf['mass'];
        $sold++;
        if (!$er_fix && $bf['exchange_rate_amount']) { $er_fix = (float)$bf['exchange_rate_amount']; $er_id = (int)$bf['exchange_rateid']; }
    }
    if (!$er_fix) {
        $er_r = $conn->query("SELECT id,amount FROM exchange_rate ORDER BY id DESC LIMIT 1");
        if ($er_r && $er_r !== true && $er_r->num_rows) { $er_rw = $er_r->fetch_assoc(); $er_fix = (float)$er_rw['amount']; $er_id = (int)$er_rw['id']; }
    }
    $avg    = ($sold > 0 && $mass > 0) ? $gross / $mass : 0;
    $laid   = $sold + $rej;
    // Display only. A print page must not write financial records: this used
    // to insert/update daily_totals on every view, falling back to the latest
    // exchange rate. Missing totals are fixed by recalculating on the Balance page.
    $bb = array_merge($bb, [
        'mass' => $mass, 'usd_value' => $gross, 'zim_value' => $gross * (float)$er_fix,
        'laid_bales' => $laid, 'sold_bales' => $sold, 'rejected_bales' => $rej,
        'average_price' => $avg, 'exchange_rate' => $er_fix, 'totals_missing' => true,
    ]);
}

// ── Render a single sales sheet HTML block ───────────────────────────────────
function render_one_sheet(mysqli $conn, array $bb, float $rbz_usd_pct, float $rbz_zig_pct,
                           string $so_mode, string $logo_base64, array $company): string {
    $bbid     = (int)$bb['bbid'];
    $split    = (int)$bb['splitid'];
    $tgid     = (int)$bb['transporter_growersid'];
    $growerid = (int)$bb['growerid'];
    $ex_rate  = (float)($bb['exchange_rate'] ?: 1);
    $gross_usd = (float)$bb['usd_value'];

    // Bale details
    $bales = $conn->query("
        SELECT sb.sale_code, sb.price,
               tk.barcode, bm.mass, bj.lot, bj.bale_group,
               COALESCE(tg.description,'') AS timb_grade,
               COALESCE(bg.description,'') AS buyer_grade,
               COALESCE(b.name,'') AS buyer_name,
               COALESCE(b.buyer_code,'') AS buyer_code
        FROM bale_junus bj
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN tickets tk ON tk.bale_junusid=bj.id
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        WHERE bj.transporter_growersid=$tgid AND bj.splitid=$split
        ORDER BY bj.lot ASC, bj.bale_group ASC
    ");

    // Charges
    $charges = $conn->query("
        SELECT bbc.usd_value, bbc.zim_value, st.description, st.id AS statutory_id
        FROM balanced_bales_charges bbc
        JOIN grower_charges gc ON gc.id=bbc.grower_chargeid
        JOIN statutory st ON st.id=gc.statutoryid
        WHERE bbc.balanced_balesid=$bbid ORDER BY st.id
    ");

    // Internal stop orders
    $iso = $conn->query("
        SELECT amount, COALESCE(NULLIF(description,''), charge_type) AS description, charge_type
        FROM internal_stoporders
        WHERE (transporter_growersid=$tgid
              OR (growerid=$growerid AND (transporter_growersid IS NULL OR transporter_growersid=0)))
          AND active IN (0,1)
        ORDER BY id ASC
    ");

    // TIMB stop orders
    $timb_so = $conn->query("
        SELECT isod.amount_deducted AS amount,
               COALESCE(NULLIF(iso.description,''), iso.creditor_number) AS description,
               iso.creditor_number AS creditor_no
        FROM internal_stop_order_deducted_amounts isod
        JOIN internal_stop_order_amount isoa ON isoa.id = isod.internal_stop_order_amountid
        JOIN internal_stop_order iso ON iso.id = isoa.internal_stop_orderid
        WHERE isod.balanced_balesid = $bbid
        
        UNION ALL
        
        SELECT sd.amount_deducted AS amount,
               COALESCE(gcr.creditor_name, gcr.name, CONCAT('Creditor ', sd.creditor_no)) AS description,
               sd.creditor_no
        FROM stoporder_deductions sd
        LEFT JOIN grower_creditors gcr ON gcr.creditor_no = sd.creditor_no
        WHERE sd.balanced_balesid = $bbid
    ");
    if (!$timb_so || $timb_so->num_rows === 0) {
        $timb_so = $conn->query("
            SELECT isoa.amount,
                   COALESCE(NULLIF(iso.description,''), iso.creditor_number) AS description,
                   iso.creditor_number AS creditor_no
            FROM internal_stop_order_amount isoa
            JOIN internal_stop_order iso ON iso.id = isoa.internal_stop_orderid
            WHERE isoa.growerid=$growerid AND isoa.processed=1
            
            UNION ALL
            
            SELECT so.amount_1 AS amount,
                   COALESCE(gcr.creditor_name, gcr.name, CONCAT('Creditor ', so.creditor_no)) AS description,
                   so.creditor_no
            FROM stoporders so
            LEFT JOIN grower_creditors gcr ON gcr.creditor_no = so.creditor_no
            WHERE so.growerid=$growerid AND so.processed=1
        ");
    }

    // Aggregate
    $timb_so_rows = []; $timb_so_total = 0;
    if ($timb_so && $timb_so !== true) while ($r = $timb_so->fetch_assoc()) { $timb_so_total += (float)$r['amount']; $timb_so_rows[] = $r; }

    // Query the active bank charge rate from grower_charges settings (statutoryid = 7)
    $bc_spid = (int)($bb['selling_pointid'] ?? 0);
    $bc_rate = 0.02; // default 2%
    $bc_q = $conn->query("
        SELECT amount
        FROM grower_charges
        WHERE statutoryid = 7 AND active = 1 AND (selling_pointid = $bc_spid OR selling_pointid = 0)
        LIMIT 1
    ");
    if ($bc_q && $bc_q->num_rows > 0) {
        $bc_rate = (float)$bc_q->fetch_assoc()['amount'];
    }

    $charges_rows = [];
    $charges_usd_total_excluding_bc = 0;
    $charges_zig_total_excluding_bc = 0;
    $has_bank_charge_row = false;

    if ($charges && $charges !== true) {
        while ($r = $charges->fetch_assoc()) {
            if ((int)($r['statutory_id'] ?? 0) === 7) {
                $has_bank_charge_row = true;
                continue;
            }
            $charges_usd_total_excluding_bc += (float)$r['usd_value'];
            $charges_zig_total_excluding_bc += (float)$r['zim_value'];
            $charges_rows[] = $r;
        }
    }

    $iso_rows = []; $iso_total = 0;
    if ($iso && $iso !== true) while ($r = $iso->fetch_assoc()) { $iso_total += (float)$r['amount']; $iso_rows[] = $r; }

    // Calculation
    if ($so_mode === 'before') {
        $step_after_so  = max(0, $gross_usd - $timb_so_total);
        $split_base     = $step_after_so;
    } else {
        $step_after_so  = $gross_usd;
        $split_base     = $gross_usd;
    }
    $step_usd_portion = $split_base * $rbz_usd_pct;
    $step_zig_portion = $split_base * $rbz_zig_pct;
    $step_zig_in_zig  = $step_zig_portion * $ex_rate;
    $step_usd_after_timb = $so_mode === 'after' ? max(0, $step_usd_portion - $timb_so_total) : $step_usd_portion;

    // Calculate payout before bank charge
    $step_usd_after_charges_no_bc = max(0, $step_usd_after_timb - $charges_usd_total_excluding_bc);
    $usd_net_before_bc            = max(0, $step_usd_after_charges_no_bc - $iso_total);
    $step_zig_net_before_bc       = max(0, $step_zig_in_zig - $charges_zig_total_excluding_bc);

    // Compute bank charge dynamically based on settings
    $bc_usd = $usd_net_before_bc * $bc_rate;
    $bc_zig = $step_zig_net_before_bc * $bc_rate;

    // Add Bank Charge row back to $charges_rows if it was in the database or if active in settings
    if ($has_bank_charge_row || $bc_rate > 0) {
        $charges_rows[] = [
            'usd_value'   => $bc_usd,
            'zim_value'   => $bc_zig,
            'description' => 'Bank Charge'
        ];
    }

    $charges_usd_total = $charges_usd_total_excluding_bc + $bc_usd;
    $charges_zig_total = $charges_zig_total_excluding_bc + $bc_zig;

    $step_usd_net     = max(0, $step_usd_after_timb - $charges_usd_total - $iso_total);
    $step_zig_net     = max(0, $step_zig_in_zig - $charges_zig_total);

    // Build bale table rows
    $bale_total_mass = 0; $bale_total_value = 0;
    $bale_rows_html  = '';
    $i = 1;
    if ($bales && $bales !== true) {
        while ($b = $bales->fetch_assoc()) {
            $raw_price   = (float)$b['price'];
            $price_per_kg= $raw_price > 10 ? $raw_price / 100 : $raw_price;
            $sc          = strtoupper(trim($b['sale_code'] ?? ''));
            $is_rej      = ($sc==='R'||$sc==='CO'||$sc==='RO'||($sc&&$sc[0]==='R'&&strlen($sc)<=3));
            $no_sale     = empty($b['sale_code']) && !$b['price'];
            $bale_value  = $is_rej ? 0 : $price_per_kg * (float)$b['mass'];
            $bale_total_mass  += (float)$b['mass'];
            $bale_total_value += $bale_value;
            $row_bg = $is_rej ? 'background:#fff5f5' : ($no_sale ? 'background:#fffbf0' : '');
            $bale_rows_html .= '<tr style="'.$row_bg.'">';
            $bale_rows_html .= '<td style="color:var(--muted)">'.$i.'</td>';
            $bale_rows_html .= '<td><code style="font-size:8.5px;letter-spacing:0.04em">'.htmlspecialchars($b['barcode']).'</code></td>';
            $bale_rows_html .= '<td style="text-align:center">'.$b['lot'].'</td>';
            $bale_rows_html .= '<td style="text-align:center">'.$b['bale_group'].'</td>';
            if ($is_rej)
                $bale_rows_html .= '<td><span style="background:#fee;color:#c0392b;padding:1px 5px;border-radius:3px;font-size:7.5px;font-weight:600;letter-spacing:.05em">'.($sc?:'REJ').'</span></td>';
            elseif ($b['timb_grade'])
                $bale_rows_html .= '<td><span class="badge">'.htmlspecialchars($b['timb_grade']).'</span></td>';
            else
                $bale_rows_html .= '<td><span style="color:var(--muted)">—</span></td>';
            $bale_rows_html .= '<td>'.htmlspecialchars($b['buyer_grade']).'</td>';
            $bale_rows_html .= '<td>'.htmlspecialchars($b['buyer_code']).'</td>';
            $bale_rows_html .= '<td class="text-right">'.number_format((float)$b['mass']).'</td>';
            if ($is_rej)
                $bale_rows_html .= '<td class="text-right"><span style="color:#c0392b;font-size:7.5px">REJECTED</span></td>';
            else
                $bale_rows_html .= '<td class="text-right">$'.number_format($price_per_kg,4).'</td>';
            if ($is_rej)
                $bale_rows_html .= '<td class="text-right" style="font-weight:500"><span style="color:#c0392b">$0.00</span></td>';
            else
                $bale_rows_html .= '<td class="text-right" style="font-weight:500">$'.number_format($bale_value,2).'</td>';
            $bale_rows_html .= '</tr>';
            $i++;
        }
    }

    // Charges HTML helpers
    $charges_html = '';
    foreach ($charges_rows as $c) {
        $charges_html .= '<tr>';
        $charges_html .= '<td>'.htmlspecialchars($c['description']).'</td>';
        $charges_html .= '<td class="text-right" style="color:#c0392b">'.((float)$c['usd_value']>0?'-$'.number_format((float)$c['usd_value'],2):'').'</td>';
        $charges_html .= '<td class="text-right" style="color:#c0392b">'.((float)$c['zim_value']>0?'-'.number_format((float)$c['zim_value'],2).' ZiG':'').'</td>';
        $charges_html .= '</tr>';
    }
    foreach ($iso_rows as $is) {
        $charges_html .= '<tr>';
        $charges_html .= '<td><span style="font-size:.7em;background:#e8f4e8;color:#2d6b25;padding:1px 4px;border-radius:3px">Internal</span> '.htmlspecialchars($is['description']).'</td>';
        $charges_html .= '<td class="text-right" style="color:#c0392b">-$'.number_format((float)$is['amount'],2).'</td>';
        $charges_html .= '<td></td>';
        $charges_html .= '</tr>';
    }
    if (empty($charges_rows) && empty($iso_rows))
        $charges_html = '<tr><td colspan="3" style="color:var(--muted);font-style:italic;text-align:center;padding:.5rem">No charges</td></tr>';

    // TIMB SO rows HTML
    $timb_so_html = '';
    foreach ($timb_so_rows as $ts) {
        $cn = $ts['creditor_no'] ? ' <span style="color:#999;font-size:.8em">('.htmlspecialchars($ts['creditor_no']).')</span>' : '';
        $timb_so_html .= '<tr><td style="padding-left:1rem"><span style="font-size:.7em;background:#e8eef8;color:#1a4a8a;padding:1px 4px;border-radius:3px">TIMB SO</span> '.htmlspecialchars($ts['description']).$cn.'</td>';
        $timb_so_html .= '<td class="text-right" style="color:#c0392b">-$'.number_format((float)$ts['amount'],2).'</td></tr>';
    }

    $step_label = ($so_mode==='before' && $timb_so_total>0) ? '3' : '2';
    $sell_date_disp = date('d F Y', strtotime($bb['dt_created'] ?? $bb['sell_date'] ?? date('Y-m-d')));
    $logo_html = $logo_base64
        ? '<img src="'.htmlspecialchars($logo_base64).'" alt="'.htmlspecialchars($company['name']).'">'
        : '<div class="logo-text-fallback">'.htmlspecialchars(strtoupper(substr($company['name'],0,3))).'</div>';

    ob_start();
?>
<!-- ═══════════════════════════════════════════════════════════════ HEADER -->
<div class="doc-header">
    <div class="logo-box"><?= $logo_html ?></div>
    <div class="company-info">
        <div class="company-name"><?= htmlspecialchars($company['name']) ?></div>
        <?php if ($company['tagline']): ?><div style="font-size:8.5px;color:var(--gold);font-weight:500;margin-bottom:3px"><?= htmlspecialchars($company['tagline']) ?></div><?php endif; ?>
        <div class="company-details">
            <?php if ($company['address']): ?><span>📍 <?= htmlspecialchars($company['address']) ?></span><?php endif; ?>
            <?php if ($company['phone']): ?><span>📞 <?= htmlspecialchars($company['phone']) ?></span><?php endif; ?>
            <?php if ($company['email']): ?><span>✉ <?= htmlspecialchars($company['email']) ?></span><?php endif; ?>
            <?php if ($company['website']): ?><span>🌐 <?= htmlspecialchars($company['website']) ?></span><?php endif; ?>
            <?php if ($company['reg_number']): ?><br><span>Reg: <?= htmlspecialchars($company['reg_number']) ?></span><?php endif; ?>
            <?php if ($company['vat_number']): ?><span>VAT: <?= htmlspecialchars($company['vat_number']) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="doc-meta">
        <div class="doc-title">Sales Sheet</div>
        <div class="doc-batch">Batch #<?= $bb['sale_batchid'] ?></div>
        <div class="doc-meta-row">Date: <strong><?= $sell_date_disp ?></strong></div>
        <div class="doc-meta-row">Split: <strong><?= $split ?></strong></div>
        <div class="doc-meta-row">Printed: <?= date('d/m/Y H:i') ?></div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════ FLOOR INFO BAR -->
<div class="floor-bar">
    <div class="floor-bar-item">Floor: <span><?= htmlspecialchars($bb['selling_point_name']) ?></span></div>
    <div class="floor-bar-item">Code: <span><?= htmlspecialchars($bb['floor_code']) ?></span></div>
    <div class="floor-bar-item">Season: <span><?= htmlspecialchars($bb['season_name']) ?></span></div>
    <div class="floor-bar-item">Rate: <span>1 USD = <?= number_format($ex_rate,4) ?> ZiG</span></div>
    <div class="floor-bar-item">Transporter: <span><?= htmlspecialchars($bb['transporter_name']) ?> — <?= htmlspecialchars($bb['truck_num']) ?></span></div>
</div>

<!-- ═══════════════════════════════════════════════════════ GROWER DETAILS -->
<div class="section">
    <div class="section-head">Grower Details</div>
    <div class="info-grid">
        <div class="info-item"><label>Grower #</label><span><?= htmlspecialchars($bb['grower_num']) ?></span></div>
        <div class="info-item"><label>Full Name</label><span><?= htmlspecialchars($bb['name'].' '.$bb['surname']) ?></span></div>
        <div class="info-item"><label>National ID</label><span><?= htmlspecialchars($bb['national_id']) ?></span></div>
        <div class="info-item"><label>Phone</label><span><?= htmlspecialchars($bb['phone']) ?></span></div>
        <div class="info-item"><label>Farm Name</label><span><?= htmlspecialchars($bb['farm_name']) ?></span></div>
        <div class="info-item"><label>Province</label><span><?= htmlspecialchars($bb['province']) ?></span></div>
        <div class="info-item"><label>District</label><span><?= htmlspecialchars($bb['district']) ?></span></div>
        <div class="info-item"><label>Contractor</label><span><?= htmlspecialchars($bb['contractor']) ?></span></div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════ BALE DETAILS -->
<div class="section">
    <div class="section-head">Bale Details</div>
    <table>
        <thead>
            <tr>
                <th style="width:22px">#</th>
                <th>Barcode</th>
                <th>Lot</th>
                <th>Grp</th>
                <th>TIMB Grade</th>
                <th>Buyer Grade</th>
                <th>Buyer</th>
                <th class="text-right">Mass (kg)</th>
                <th class="text-right">Price ($/kg)</th>
                <th class="text-right">Value (USD)</th>
            </tr>
        </thead>
        <tbody><?= $bale_rows_html ?></tbody>
        <tfoot>
            <tr>
                <td colspan="7" style="text-align:right;font-size:7.5px;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted)">Totals</td>
                <td class="text-right" style="font-weight:600"><?= number_format($bale_total_mass) ?> kg</td>
                <td></td>
                <td class="text-right" style="font-weight:600;color:var(--leaf)">$<?= number_format($bale_total_value,2) ?></td>
            </tr>
        </tfoot>
    </table>
</div>

<!-- ════════════════════════════════════════════════════════════ FINANCIALS -->
<div class="summary-wrap">
    <!-- LEFT: Settlement calculation -->
    <div>
        <div class="stitle">Settlement Calculation</div>
        <table class="sum-table">
            <tr style="background:#f8f5ef"><td style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600" colspan="2">Bale Summary</td></tr>
            <tr><td>Total Mass</td><td class="text-right"><?= number_format((float)$bb['mass'],1) ?> kg</td></tr>
            <tr><td>Bales Sold / Rejected / Laid</td><td class="text-right"><?= (int)$bb['sold_bales'] ?> / <?= (int)$bb['rejected_bales'] ?> / <?= (int)$bb['laid_bales'] ?></td></tr>
            <tr><td>Avg Price</td><td class="text-right">$<?= number_format((float)$bb['average_price'],4) ?>/kg</td></tr>
            <tr><td>Exchange Rate</td><td class="text-right">1 USD = <?= number_format($ex_rate,4) ?> ZiG</td></tr>
            <tr style="background:#f8f5ef"><td style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600" colspan="2">Step 1 — Gross Proceeds</td></tr>
            <tr class="subtotal"><td>Gross USD Value</td><td class="text-right" style="font-weight:700">$<?= number_format($gross_usd,2) ?></td></tr>
            <?php if ($so_mode === 'before' && $timb_so_total > 0): ?>
            <tr style="background:#f0f4fa"><td style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:#1a4a8a;font-weight:600" colspan="2">Step 2 — TIMB Stop Orders (before split)</td></tr>
            <?= $timb_so_html ?>
            <tr class="subtotal"><td>After TIMB Stop Orders</td><td class="text-right" style="font-weight:700">$<?= number_format($step_after_so,2) ?></td></tr>
            <?php endif; ?>
            <tr style="background:#f8f5ef"><td style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600" colspan="2">Step <?= $step_label ?> — RBZ Retention Split (<?= round($rbz_usd_pct*100) ?>/<?= round($rbz_zig_pct*100) ?>)</td></tr>
            <tr><td style="color:#2d6b25;font-weight:500">USD Portion (<?= round($rbz_usd_pct*100) ?>%)</td><td class="text-right" style="color:#2d6b25;font-weight:600">$<?= number_format($step_usd_portion,2) ?></td></tr>
            <tr><td style="color:#1a4a8a;font-weight:500">ZiG Portion (<?= round($rbz_zig_pct*100) ?>% × <?= number_format($ex_rate,4) ?>)</td><td class="text-right" style="color:#1a4a8a;font-weight:600"><?= number_format($step_zig_in_zig,2) ?> ZiG</td></tr>
            <?php if ($so_mode === 'after' && $timb_so_total > 0): ?>
            <tr style="background:#f0f4fa"><td style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:#1a4a8a;font-weight:600" colspan="2">Step 3 — TIMB Stop Orders (from USD portion)</td></tr>
            <?= $timb_so_html ?>
            <tr class="subtotal"><td>USD after TIMB SO</td><td class="text-right" style="font-weight:700">$<?= number_format($step_usd_after_timb,2) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- RIGHT: Deductions + Net -->
    <div>
        <div class="stitle red">Deductions &amp; Net Payout</div>
        <table class="sum-table">
            <tr style="background:var(--cream)">
                <td style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600">Charge</td>
                <td class="text-right" style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600">USD</td>
                <td class="text-right" style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600">ZiG</td>
            </tr>
            <?= $charges_html ?>
            <tr style="background:#f0f0f0;border-top:1.5px solid #ccc">
                <td style="font-weight:600;font-size:.78rem">Total Deductions</td>
                <td class="text-right" style="font-weight:700;color:#c0392b">-$<?= number_format($charges_usd_total + $iso_total,2) ?></td>
                <td class="text-right" style="font-weight:700;color:#c0392b"><?= $charges_zig_total>0?'-'.number_format($charges_zig_total,2).' ZiG':'-' ?></td>
            </tr>
            <?php
            // The payout printed is the stored net (what Payments pays), not this
            // page's own recalculation, which uses today's Settings.
            $has_stored = isset($bb['usd_net_value']) && $bb['usd_net_value'] !== null;
            $payout_usd = $has_stored ? (float)$bb['usd_net_value'] : $step_usd_net;
            $payout_zig = $has_stored ? (float)$bb['zim_net_value'] : $step_zig_net;
            ?>
            <tr class="net-row">
                <td>NET PAYOUT (USD)<div style="font-size:.65em;opacity:.7;font-weight:400"><?= $has_stored ? 'as recorded at balancing' : 'not yet recorded — estimate' ?></div></td>
                <td class="text-right" style="font-size:1.05em">$<?= number_format($payout_usd,2) ?></td>
                <td></td>
            </tr>
            <tr class="net-row" style="border-top:1px solid rgba(255,255,255,.15)">
                <td>NET PAYOUT (ZiG)<div style="font-size:.65em;opacity:.7;font-weight:400"><?= $has_stored ? 'as recorded at balancing' : 'not yet recorded — estimate' ?></div></td>
                <td></td>
                <td class="text-right" style="font-size:1.05em"><?= number_format($payout_zig,2) ?> ZiG</td>
            </tr>
            <?php if ($has_stored && (abs($payout_usd - $step_usd_net) > 0.01 || abs($payout_zig - $step_zig_net) > 0.01)): ?>
            <tr class="no-print" style="background:#fff8e1">
                <td colspan="3" style="font-size:.68rem;color:#8a5a0a">⚠ The steps above use today's Settings and give USD <?= number_format($step_usd_net,2) ?> / ZiG <?= number_format($step_zig_net,2) ?>; the payout shown is the recorded figure.</td>
            </tr>
            <?php endif; ?>
            <?php if (!empty($bb['totals_missing'])): ?>
            <tr class="no-print" style="background:#fff8e1">
                <td colspan="3" style="font-size:.68rem;color:#8a5a0a">⚠ This delivery has no saved totals; figures are calculated for display only. Recalculate it on the Balance page.</td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ SIGNATURES -->
<div class="sig-section">
    <div class="sig-block"><div class="sig-label">Grower Signature</div><div class="sig-date">Name: _______________________</div><div class="sig-date">Date: _______________________</div></div>
    <div class="sig-block"><div class="sig-label">Floor Manager</div><div class="sig-date">Name: _______________________</div><div class="sig-date">Date: _______________________</div></div>
    <div class="sig-block"><div class="sig-label">Accounts</div><div class="sig-date">Name: _______________________</div><div class="sig-date">Date: _______________________</div></div>
</div>

<!-- ════════════════════════════════════════════════════════════════ FOOTER -->
<div class="doc-footer">
    <div class="doc-footer-left">
        <div style="font-weight:500"><?= htmlspecialchars($company['name']) ?></div>
        <?php if ($company['address']): ?><div><?= htmlspecialchars($company['address']) ?></div><?php endif; ?>
        <?php if ($company['phone'] || $company['email']): ?><div><?= htmlspecialchars($company['phone']).(($company['phone']&&$company['email'])?' · ':'').htmlspecialchars($company['email']) ?></div><?php endif; ?>
    </div>
    <div style="text-align:center"><div class="watermark-box">COPY</div></div>
    <div class="doc-footer-right">
        <div>Flowork Auction System</div>
        <div>Batch #<?= $bb['sale_batchid'] ?> · Split <?= $split ?></div>
        <div>Printed: <?= date('d/m/Y H:i') ?></div>
    </div>
</div>
<?php
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= csrf_js() ?>
<meta charset="UTF-8">
<title>Sales Sheets — Bulk Print (<?= count($bbids_safe) ?> sheets)</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
    --ink:#1a1208;--gold:#c8952a;--gold-l:#e8b84b;--gold-bg:#fdf6e8;
    --cream:#f5f0e8;--leaf:#3d6b35;--muted:#7a6e5f;
    --border:rgba(26,18,8,0.1);--border-dark:rgba(26,18,8,0.25)
}
body{font-family:'DM Sans',sans-serif;font-size:10px;color:var(--ink);background:#e8e4dc}

/* ── CONTROLS BAR (no-print) ── */
.controls-bar{
    position:sticky;top:0;z-index:100;
    background:var(--ink);color:var(--gold-l);
    padding:.65rem 1.25rem;
    display:flex;align-items:center;gap:.85rem;flex-wrap:wrap;
    box-shadow:0 2px 8px rgba(0,0,0,.4)
}
.controls-bar .sheet-count{font-size:.78rem;opacity:.7;margin-right:.25rem}
.btn-ctrl{
    padding:.45rem 1rem;border:none;cursor:pointer;border-radius:3px;
    font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;
    letter-spacing:.04em;transition:opacity .15s
}
.btn-ctrl:hover{opacity:.85}
.btn-print-all{background:var(--gold);color:var(--ink)}
.btn-mark{background:var(--leaf);color:#fff}
.btn-close{background:transparent;color:var(--gold-l);border:1px solid rgba(255,255,255,.25)}
.mark-status{font-size:.75rem;color:#a3d9a5;display:none}

/* ── SHEET WRAPPER ── */
.sheet-wrap{
    max-width:210mm;
    margin:1.5rem auto;
    background:#fff;
    border-radius:3px;
    box-shadow:0 1px 4px rgba(0,0,0,.18);
    padding:20px 24px;
}
.sheet-num{
    font-family:'Bebas Neue',sans-serif;font-size:11px;letter-spacing:.15em;
    color:var(--muted);margin-bottom:10px;text-transform:uppercase;
}

/* ── Shared doc styles (same as print_sheet.php) ── */
.doc-header{display:grid;grid-template-columns:auto 1fr auto;gap:16px;align-items:center;padding-bottom:12px;border-bottom:3px solid var(--gold);margin-bottom:14px}
.logo-box{width:80px;height:60px;display:flex;align-items:center;justify-content:flex-start;flex-shrink:0}
.logo-box img{max-width:80px;max-height:60px;object-fit:contain}
.logo-text-fallback{font-family:'Bebas Neue',sans-serif;font-size:22px;color:var(--ink);letter-spacing:0.08em;line-height:1}
.company-info{min-width:0}
.company-name{font-family:'Bebas Neue',sans-serif;font-size:18px;letter-spacing:0.08em;color:var(--ink);line-height:1;margin-bottom:3px}
.company-details{font-size:8.5px;color:var(--muted);line-height:1.6}
.company-details span{margin-right:10px}
.doc-meta{text-align:right;flex-shrink:0}
.doc-title{font-family:'Bebas Neue',sans-serif;font-size:20px;letter-spacing:0.1em;color:var(--ink);line-height:1}
.doc-batch{font-family:'Bebas Neue',sans-serif;font-size:14px;color:var(--gold);letter-spacing:0.08em}
.doc-meta-row{font-size:8.5px;color:var(--muted);line-height:1.7}
.floor-bar{display:flex;align-items:center;gap:0;background:var(--ink);color:var(--cream);margin-bottom:12px;border-radius:2px;overflow:hidden}
.floor-bar-item{padding:5px 12px;font-size:8px;font-weight:500;letter-spacing:0.12em;text-transform:uppercase;border-right:1px solid rgba(255,255,255,0.1)}
.floor-bar-item:last-child{border-right:none;margin-left:auto}
.floor-bar-item span{color:var(--gold-l)}
.section{margin-bottom:12px}
.section-head{font-size:7.5px;font-weight:600;letter-spacing:0.18em;text-transform:uppercase;color:#fff;background:var(--muted);padding:3px 8px;margin-bottom:0;display:inline-block;border-radius:2px 2px 0 0}
.info-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:0;border:1px solid var(--border);border-radius:0 2px 2px 2px}
.info-item{padding:5px 8px;border-right:1px solid var(--border);border-bottom:1px solid var(--border)}
.info-item:nth-child(4n){border-right:none}
.info-item:nth-last-child(-n+4){border-bottom:none}
.info-item label{font-size:7px;color:var(--muted);text-transform:uppercase;letter-spacing:0.1em;display:block;margin-bottom:1px}
.info-item span{font-weight:500;font-size:10px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
table{width:100%;border-collapse:collapse;font-size:9.5px}
thead{background:var(--ink);color:var(--cream)}
thead th{font-size:7.5px;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;padding:4px 5px;text-align:left;border:1px solid rgba(255,255,255,0.1)}
tbody td{padding:3px 5px;border:1px solid var(--border);vertical-align:middle}
tbody tr:nth-child(even){background:var(--gold-bg)}
tbody tr:last-child td{border-bottom:1px solid var(--border-dark)}
tfoot td{padding:4px 5px;border:1px solid var(--border-dark);font-weight:500;background:var(--cream)}
.summary-wrap{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}
.stitle{font-size:7.5px;font-weight:600;letter-spacing:0.18em;text-transform:uppercase;color:#fff;background:var(--leaf);padding:3px 8px;display:inline-block;border-radius:2px 2px 0 0}
.stitle.red{background:var(--muted)}
.sum-table{width:100%;border-collapse:collapse;font-size:9.5px;border:1px solid var(--border)}
.sum-table td{padding:3.5px 7px;border-bottom:1px solid var(--border)}
.sum-table tr:last-child td{border-bottom:none}
.sum-table tr.subtotal td{background:var(--cream);font-weight:500}
.sum-table tr.net-row td{background:var(--ink);color:var(--gold-l);font-weight:600;font-size:10.5px}
.sum-table tr.net-row td:last-child{font-family:'Bebas Neue',sans-serif;font-size:14px;letter-spacing:0.04em}
.text-right{text-align:right}
.badge{display:inline-block;padding:1px 5px;border-radius:2px;font-size:7.5px;font-weight:500;letter-spacing:0.06em;text-transform:uppercase;background:var(--gold-bg);color:#92650e;border:1px solid #e8c96a}
.sig-section{display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;margin-top:20px}
.sig-block{border-top:1px solid var(--ink);padding-top:4px}
.sig-label{font-size:8px;color:var(--muted);font-weight:500;letter-spacing:0.08em;text-transform:uppercase}
.sig-date{font-size:8px;color:var(--muted);margin-top:16px}
.doc-footer{margin-top:14px;padding-top:8px;border-top:2px solid var(--gold);display:flex;justify-content:space-between;align-items:center}
.doc-footer-left{font-size:8px;color:var(--muted)}
.doc-footer-right{font-size:8px;color:var(--muted);text-align:right}
.watermark-box{border:2px solid var(--ink);padding:3px 10px;font-family:'Bebas Neue',sans-serif;font-size:11px;letter-spacing:0.12em;color:var(--ink);display:inline-block;border-radius:2px}

/* ── PRINT ── */
@media print {
    .controls-bar { display:none }
    body { background:#fff }
    .sheet-wrap {
        margin:0; padding:12mm 14mm;
        max-width:100%; width:100%;
        box-shadow:none; border-radius:0;
        page-break-after: always;
    }
    .sheet-wrap:last-child { page-break-after: auto }
    .sheet-num { display:none }
    .summary-wrap { page-break-inside:avoid }
    .sig-section { page-break-inside:avoid }
    @page { margin:0; size:A4 }
}
</style>
</head>
<body>

<!-- CONTROLS BAR -->
<div class="controls-bar no-print">
    <button class="btn-ctrl btn-print-all" onclick="doPrint()">🖨 Print All / Save PDF</button>
    <button class="btn-ctrl btn-mark" id="btnMarkAll" onclick="markAllPrinted()">✓ Mark All as Printed</button>
    <button class="btn-ctrl btn-close" onclick="window.close()">✕ Close</button>
    <span class="sheet-count"><?= count($bbids_safe) ?> sheet<?= count($bbids_safe)!==1?'s':'' ?></span>
    <span class="mark-status" id="markStatus"></span>
</div>

<?php
$total_sheets = count($bbids_safe);
foreach ($bbids_safe as $idx => $bbid):
    $bbid = (int)$bbid;
    if (!isset($sheets_data[$bbid])) continue;
    $bb = $sheets_data[$bbid];

    // Auto-fix missing data
    maybe_fix_sheet($conn, $bb);

    // The rule recorded on the delivery when it was calculated; today's Settings only as a fallback.
    $so_mode = get_so_mode($conn, (int)$bb['selling_pointid']);
    $sheet_usd_pct = $rbz_usd_pct;
    $_rule = $conn->query("SELECT rule_so_mode, rule_rbz_usd FROM balanced_bales WHERE id=" . (int)$bbid);
    $_rule = ($_rule && $_rule !== true) ? $_rule->fetch_assoc() : null;
    if ($_rule && $_rule['rule_so_mode'] !== null && $_rule['rule_rbz_usd'] !== null) {
        $so_mode = $_rule['rule_so_mode'];
        $sheet_usd_pct = (float)$_rule['rule_rbz_usd'];
    }
?>
<div class="sheet-wrap" data-bbid="<?= $bbid ?>">
    <div class="sheet-num">Sheet <?= ($idx+1) ?> of <?= $total_sheets ?> &nbsp;·&nbsp; <?= htmlspecialchars($bb['grower_num']) ?> — <?= htmlspecialchars($bb['name'].' '.$bb['surname']) ?></div>
    <?= render_one_sheet($conn, $bb, $sheet_usd_pct, 1 - $sheet_usd_pct, $so_mode, $logo_base64, $company) ?>
</div>
<?php endforeach; ?>

<script>
const ALL_BBIDS = <?= json_encode($bbids_safe) ?>;

function doPrint() {
    window.print();
    // After print dialog closes, prompt to mark as printed
    // (use onafterprint for auto-mark)
}

window.onafterprint = function() {
    const btn = document.getElementById('btnMarkAll');
    if (btn && !btn.disabled) {
        if (confirm('Mark all ' + ALL_BBIDS.length + ' sheet(s) as printed?')) {
            doMarkPrinted();
        }
    }
};

function markAllPrinted() {
    if (!confirm('Mark all ' + ALL_BBIDS.length + ' sheet(s) as printed?')) return;
    doMarkPrinted();
}

function doMarkPrinted() {
    const btn = document.getElementById('btnMarkAll');
    const status = document.getElementById('markStatus');
    if (btn) btn.disabled = true;
    fetch('sales_sheets.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=mark_many_printed&ids=' + ALL_BBIDS.join(',')
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            if (status) {
                status.textContent = '✓ ' + d.count + ' sheet(s) marked as printed';
                status.style.display = 'inline';
            }
            // Update watermark badges on each sheet
            document.querySelectorAll('.watermark-box').forEach(el => {
                el.textContent = 'PRINTED';
                el.style.borderColor = '#3d6b35';
                el.style.color = '#3d6b35';
            });
        }
    })
    .catch(() => {
        if (btn) btn.disabled = false;
        alert('Failed to mark as printed. Please try again.');
    });
}
</script>
</body>
</html>
