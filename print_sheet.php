<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$bbid    = (int)($_GET['bbid'] ?? 0);
if (!$bbid) { echo "No batch ID."; exit; }

$spid = (int)($_SESSION['selling_pointid'] ?? 0);

// Read SO deduction mode from settings (parameters table) — floor-wide setting
$so_mode = 'before'; // default
$_sp_q = $conn->query("SELECT g.selling_pointid FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    WHERE bb.id=$bbid LIMIT 1");
$_sp_r = ($_sp_q && $_sp_q!==true) ? $_sp_q->fetch_assoc() : null;
$_sheet_spid = (int)($_sp_r['selling_pointid'] ?? 0);

// Enforce floor ownership (IDOR check)
$is_admin = !empty($_SESSION['is_admin']) || !empty($_SESSION['rights']['admin']);
if (!$is_admin && $spid > 0 && $_sheet_spid !== $spid) {
    http_response_code(403);
    die("Access Denied: Batch does not belong to your floor.");
}

if ($_sheet_spid) {
    $sm_q = $conn->query("SELECT param_value FROM parameters
        WHERE param_key='so_deduction_mode' AND selling_pointid=$_sheet_spid LIMIT 1");
    if ($sm_q && $sm_q!==true && $sm_q->num_rows) {
        $so_mode = $sm_q->fetch_assoc()['param_value'];
    }
}

// Load master data
$_bbq = $conn->query("
    SELECT bb.id, bb.splitid, bb.sale_batchid,
           g.grower_num, g.name, g.surname, g.national_id, g.phone,
           g.farm_name, g.province, g.district, g.contractor,
           t.name AS transporter_name, t.truck_num,
           COALESCE(s.description,'') AS season_name,
           sp.name AS selling_point_name, COALESCE(sp.floor_code,'') AS floor_code,
           dt.mass, dt.usd_value, dt.zim_value,
           dt.laid_bales, dt.sold_bales, dt.rejected_bales,
           dt.average_price, dt.created_at,
           er.amount AS exchange_rate,
           dtsa.usd_split_value, dtsa.zim_split_value,
           dtn.usd_net_value, dtn.zim_net_value
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    JOIN transporter t ON t.id=tg.transporterid
    LEFT JOIN seasons s ON s.id=t.seasonid
    LEFT JOIN selling_point sp ON sp.id=g.selling_pointid
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    LEFT JOIN exchange_rate er ON er.id=dt.exchange_rateid
    LEFT JOIN daily_totals_split_amounts dtsa ON dtsa.balanced_balesid=bb.id
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
    WHERE bb.id=$bbid LIMIT 1
");
$bb = ($_bbq && $_bbq !== true) ? $_bbq->fetch_assoc() : null;

if (!$bb) { echo "Record not found."; exit; }

// Auto-fix: if daily_totals has zeros, recalculate from bales
if (!$bb['mass'] && !$bb['usd_value']) {
    $tgid_fix    = (int)$_bbq2['transporter_growersid'] ?? 0;
    // Re-fetch tgid
    $_tgq = $conn->query("SELECT transporter_growersid, growerid, splitid, created_at FROM balanced_bales WHERE id=$bbid LIMIT 1");
    $_tgr = ($_tgq && $_tgq!==true) ? $_tgq->fetch_assoc() : null;
    if ($_tgr) {
        $tgid_fix  = (int)$_tgr['transporter_growersid'];
        $split_fix = (int)$_tgr['splitid'];
        $bales_fix = $conn->query("
            SELECT bm.mass, sb.price, COALESCE(sb.sale_code,'') sale_code, sb.id sold_id,
                   er.id exchange_rateid, er.amount exchange_rate_amount
            FROM bale_junus bj
            JOIN bale_mass bm ON bm.id=bj.bale_massid
            JOIN tickets tk ON tk.bale_junusid=bj.id
            LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
            LEFT JOIN exchange_rate er ON tk.start_of_dayid=er.start_of_dayid
            WHERE bj.transporter_growersid=$tgid_fix AND bj.splitid=$split_fix
        ");
        $gross_fix=0; $mass_fix=0; $sold_fix=0; $rej_fix=0; $er_fix=0; $er_id_fix=0;
        while ($bales_fix && $bf=$bales_fix->fetch_assoc()) {
            $sc = strtoupper(trim($bf['sale_code']));
            $is_rej = in_array($sc,['R','CO','RO'])||($sc&&$sc[0]==='R'&&strlen($sc)<=3);
            if ($is_rej) { $rej_fix++; continue; }
            if (!$bf['sold_id']) continue;
            $p=(float)$bf['price']; if($p>10)$p/=100;
            $gross_fix += $p*(float)$bf['mass'];
            $mass_fix  += (float)$bf['mass'];
            $sold_fix++;
            if (!$er_fix && $bf['exchange_rate_amount']) {
                $er_fix    = (float)$bf['exchange_rate_amount'];
                $er_id_fix = (int)$bf['exchange_rateid'];
            }
        }
        if (!$er_fix) {
            $er_r = $conn->query("SELECT id,amount FROM exchange_rate ORDER BY id DESC LIMIT 1");
            if ($er_r&&$er_r!==true&&$er_r->num_rows){$er_row=$er_r->fetch_assoc();$er_fix=(float)$er_row['amount'];$er_id_fix=(int)$er_row['id'];}
        }
        $avg_fix    = ($sold_fix>0&&$mass_fix>0)?$gross_fix/$mass_fix:0;
        $zim_fix    = $gross_fix*$er_fix;
        $laid_fix   = $sold_fix+$rej_fix;
        $er_val_fix = $er_id_fix>0?$er_id_fix:'NULL';
        $today_fix  = date('Y-m-d',strtotime($_tgr['created_at']));
        $uid_fix    = 1;
        if ($conn->query("SHOW COLUMNS FROM daily_totals LIKE 'balanced_balesid'")->num_rows) {
            $chk = $conn->query("SELECT id FROM daily_totals WHERE balanced_balesid=$bbid LIMIT 1");
            if ($chk&&$chk->num_rows) {
                $conn->query("UPDATE daily_totals SET mass=$mass_fix,usd_value=$gross_fix,zim_value=$zim_fix,
                    average_price=$avg_fix,laid_bales=$laid_fix,sold_bales=$sold_fix,rejected_bales=$rej_fix,
                    exchange_rateid=$er_val_fix WHERE balanced_balesid=$bbid");
            } else {
                $conn->query("INSERT INTO daily_totals (userid,balanced_balesid,exchange_rateid,mass,usd_value,
                    zim_value,average_price,laid_bales,sold_bales,rejected_bales,created_at)
                    VALUES ($uid_fix,$bbid,$er_val_fix,$mass_fix,$gross_fix,$zim_fix,
                    $avg_fix,$laid_fix,$sold_fix,$rej_fix,'$today_fix')");
                if ($conn->error) {
                    $conn->query("INSERT INTO daily_totals (userid,balanced_balesid,mass,usd_value,
                        zim_value,average_price,laid_bales,sold_bales,rejected_bales,created_at)
                        VALUES ($uid_fix,$bbid,$mass_fix,$gross_fix,$zim_fix,
                        $avg_fix,$laid_fix,$sold_fix,$rej_fix,'$today_fix')");
                }
            }
        }
        // Reload $bb with fixed data
        $_bbq2 = $conn->query("SELECT bb.id,bb.splitid,bb.sale_batchid,g.grower_num,g.name,g.surname,g.national_id,g.phone,g.farm_name,g.province,g.district,g.contractor,t.name AS transporter_name,t.truck_num,COALESCE(s.description,'') AS season_name,sp.name AS selling_point_name,COALESCE(sp.floor_code,'') AS floor_code,dt.mass,dt.usd_value,dt.zim_value,dt.laid_bales,dt.sold_bales,dt.rejected_bales,dt.average_price,dt.created_at,er.amount AS exchange_rate,dtsa.usd_split_value,dtsa.zim_split_value,dtn.usd_net_value,dtn.zim_net_value FROM balanced_bales bb JOIN transporter_growers tg ON tg.id=bb.transporter_growersid JOIN growers g ON g.id=tg.growerid JOIN transporter t ON t.id=tg.transporterid LEFT JOIN seasons s ON s.id=t.seasonid LEFT JOIN selling_point sp ON sp.id=g.selling_pointid LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id LEFT JOIN exchange_rate er ON er.id=dt.exchange_rateid LEFT JOIN daily_totals_split_amounts dtsa ON dtsa.balanced_balesid=bb.id LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id WHERE bb.id=$bbid LIMIT 1");
        if ($_bbq2&&$_bbq2!==true) $bb=$_bbq2->fetch_assoc();
    }
}

// Load company info from parameters table
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
// Load from parameters table if it exists
$params_exists = $conn->query("SHOW TABLES LIKE 'parameters'")->num_rows;
if ($params_exists) {
    $pq = $conn->query("SELECT param_key, param_value FROM parameters WHERE param_key LIKE 'company_%'");
    if ($pq) {
        while ($pr = $pq->fetch_assoc()) {
            $key = str_replace('company_', '', $pr['param_key']);
            if (array_key_exists($key, $company)) {
                $company[$key] = $pr['param_value'];
            }
        }
    }
}

// Load bale details — LEFT JOINs so rejected bales (no grade) still appear
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
    WHERE bj.transporter_growersid=(
        SELECT transporter_growersid FROM balanced_bales WHERE id=$bbid
    ) AND bj.splitid=".((int)$bb['splitid'])."
    ORDER BY bj.lot ASC, bj.bale_group ASC
");

// Load charges
$charges = $conn->query("
    SELECT bbc.usd_value, bbc.zim_value, st.description, st.id AS statutory_id
    FROM balanced_bales_charges bbc
    JOIN grower_charges gc ON gc.id=bbc.grower_chargeid
    JOIN statutory st ON st.id=gc.statutoryid
    WHERE bbc.balanced_balesid=$bbid
    ORDER BY st.id
");

// ── Load stop order deductions for this sales sheet ──────────────────────
$tgid_bb      = (int)($conn->query("SELECT transporter_growersid FROM balanced_bales WHERE id=$bbid LIMIT 1")->fetch_assoc()['transporter_growersid'] ?? 0);
$split_bb     = (int)$bb['splitid'];
$sell_date_bb = date('Y-m-d', strtotime($bb['created_at'] ?? $bb['sell_date'] ?? date('Y-m-d')));

// Get growerid
$growerid_bb = 0;
if ($bb['grower_num']) {
    $gq = $conn->query("SELECT id FROM growers WHERE grower_num='".$conn->real_escape_string($bb['grower_num'])."' LIMIT 1");
    if ($gq && $gq !== true && $gq->num_rows) $growerid_bb = (int)$gq->fetch_assoc()['id'];
}

// 1. Internal stop orders (transport, canteen, advance etc) — from internal_stoporders table
$iso = $conn->query("
    SELECT amount,
           COALESCE(NULLIF(description,''), charge_type) AS description,
           charge_type
    FROM internal_stoporders
    WHERE (transporter_growersid=$tgid_bb OR (growerid=$growerid_bb AND (transporter_growersid IS NULL OR transporter_growersid=0)))
      AND active IN (0,1)
    ORDER BY id ASC
");

// Legacy iso2 alias
$iso2 = null;

// 2. TIMB Global Stop Orders — from internal_stop_order_amount and stoporder_deductions (deducted at balance time)
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
// Fallback: query directly from isoa/stoporders if deducted_amounts not populated yet
if (!$timb_so || $timb_so->num_rows === 0) {
    $timb_so = $conn->query("
        SELECT isoa.amount,
               COALESCE(NULLIF(iso.description,''), iso.creditor_number) AS description,
               iso.creditor_number AS creditor_no
        FROM internal_stop_order_amount isoa
        JOIN internal_stop_order iso ON iso.id = isoa.internal_stop_orderid
        WHERE isoa.growerid = $growerid_bb
          AND isoa.processed = 1
          
        UNION ALL
        
        SELECT so.amount_1 AS amount,
               COALESCE(gcr.creditor_name, gcr.name, CONCAT('Creditor ', so.creditor_no)) AS description,
               so.creditor_no
        FROM stoporders so
        LEFT JOIN grower_creditors gcr ON gcr.creditor_no = so.creditor_no
        WHERE so.growerid = $growerid_bb
          AND so.processed = 1
    ");
}

// Logo as base64 for print reliability
$logo_base64 = '';
if ($company['logo_path'] && file_exists(__DIR__ . '/' . ltrim($company['logo_path'], '/'))) {
    $logo_data = file_get_contents(__DIR__ . '/' . ltrim($company['logo_path'], '/'));
    $finfo     = finfo_open(FILEINFO_MIME_TYPE);
    $mime      = finfo_file($finfo, __DIR__ . '/' . ltrim($company['logo_path'], '/'));
    finfo_close($finfo);
    $logo_base64 = 'data:' . $mime . ';base64,' . base64_encode($logo_data);
}

// Bale count for total value check
$total_value = 0;
$bale_count  = 0;
if ($bales->num_rows > 0) {
    $bales->data_seek(0);
    while ($b = $bales->fetch_assoc()) {
        $total_value += (float)$b['price'] * (float)$b['mass'];
        $bale_count++;
    }
    $bales->data_seek(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sales Sheet — <?= htmlspecialchars($bb['grower_num']) ?> — Batch #<?= $bb['sale_batchid'] ?></title>
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
body{font-family:'DM Sans',sans-serif;font-size:10px;color:var(--ink);background:#fff;padding:24px;max-width:210mm;margin:0 auto}

/* ── HEADER ── */
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

/* ── FLOOR INFO BAR ── */
.floor-bar{display:flex;align-items:center;gap:0;background:var(--ink);color:var(--cream);margin-bottom:12px;border-radius:2px;overflow:hidden}
.floor-bar-item{padding:5px 12px;font-size:8px;font-weight:500;letter-spacing:0.12em;text-transform:uppercase;border-right:1px solid rgba(255,255,255,0.1)}
.floor-bar-item:last-child{border-right:none;margin-left:auto}
.floor-bar-item span{color:var(--gold-l)}

/* ── SECTION TITLES ── */
.section{margin-bottom:12px}
.section-head{font-size:7.5px;font-weight:600;letter-spacing:0.18em;text-transform:uppercase;color:#fff;background:var(--muted);padding:3px 8px;margin-bottom:0;display:inline-block;border-radius:2px 2px 0 0}

/* ── INFO GRID ── */
.info-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:0;border:1px solid var(--border);border-radius:0 2px 2px 2px}
.info-item{padding:5px 8px;border-right:1px solid var(--border);border-bottom:1px solid var(--border)}
.info-item:nth-child(4n){border-right:none}
.info-item:nth-last-child(-n+4){border-bottom:none}
.info-item label{font-size:7px;color:var(--muted);text-transform:uppercase;letter-spacing:0.1em;display:block;margin-bottom:1px}
.info-item span{font-weight:500;font-size:10px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* ── BALE TABLE ── */
table{width:100%;border-collapse:collapse;font-size:9.5px}
thead{background:var(--ink);color:var(--cream)}
thead th{font-size:7.5px;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;padding:4px 5px;text-align:left;border:1px solid rgba(255,255,255,0.1)}
tbody td{padding:3px 5px;border:1px solid var(--border);vertical-align:middle}
tbody tr:nth-child(even){background:var(--gold-bg)}
tbody tr:last-child td{border-bottom:1px solid var(--border-dark)}
tfoot td{padding:4px 5px;border:1px solid var(--border-dark);font-weight:500;background:var(--cream)}

/* ── SUMMARY TABLES ── */
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

/* ── SIGNATURES ── */
.sig-section{display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;margin-top:20px}
.sig-block{border-top:1px solid var(--ink);padding-top:4px}
.sig-label{font-size:8px;color:var(--muted);font-weight:500;letter-spacing:0.08em;text-transform:uppercase}
.sig-date{font-size:8px;color:var(--muted);margin-top:16px}

/* ── FOOTER ── */
.doc-footer{margin-top:14px;padding-top:8px;border-top:2px solid var(--gold);display:flex;justify-content:space-between;align-items:center}
.doc-footer-left{font-size:8px;color:var(--muted)}
.doc-footer-right{font-size:8px;color:var(--muted);text-align:right}
.watermark-box{border:2px solid var(--ink);padding:3px 10px;font-family:'Bebas Neue',sans-serif;font-size:11px;letter-spacing:0.12em;color:var(--ink);display:inline-block;border-radius:2px}

/* ── NO PRINT CONTROLS ── */
.no-print{margin-bottom:14px;display:flex;gap:8px;align-items:center;padding:10px 14px;background:var(--cream);border:1px solid var(--border);border-radius:4px}
.btn-print{padding:7px 18px;background:var(--ink);color:var(--gold-l);border:none;font-family:'DM Sans',sans-serif;font-size:11px;font-weight:500;cursor:pointer;border-radius:3px;letter-spacing:0.05em}
.btn-print:hover{background:var(--gold);color:var(--ink)}
.btn-close{padding:7px 14px;background:#fff;border:1px solid var(--border);font-family:'DM Sans',sans-serif;font-size:11px;cursor:pointer;border-radius:3px}

@media print{
    .no-print{display:none}
    body{padding:0;max-width:100%}
    @page{margin:12mm 14mm;size:A4}
    .summary-wrap{page-break-inside:avoid}
    .sig-section{page-break-inside:avoid}
}
</style>
</head>
<body>

<!-- PRINT CONTROLS -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Close</button>
    <span style="font-size:10px;color:var(--muted);margin-left:8px">
        Batch #<?= $bb['sale_batchid'] ?> &nbsp;·&nbsp; <?= htmlspecialchars($bb['grower_num']) ?>
        &nbsp;·&nbsp; <?= htmlspecialchars($bb['name'].' '.$bb['surname']) ?>
    </span>
</div>

<!-- ═══════════════════════════════════════════════════════════════ HEADER -->
<div class="doc-header">

    <!-- LOGO -->
    <div class="logo-box">
        <?php if ($logo_base64): ?>
        <img src="<?= $logo_base64 ?>" alt="<?= htmlspecialchars($company['name']) ?>">
        <?php else: ?>
        <div class="logo-text-fallback"><?= htmlspecialchars(strtoupper(substr($company['name'],0,3))) ?></div>
        <?php endif; ?>
    </div>

    <!-- COMPANY INFO -->
    <div class="company-info">
        <div class="company-name"><?= htmlspecialchars($company['name']) ?></div>
        <?php if ($company['tagline']): ?>
        <div style="font-size:8.5px;color:var(--gold);font-weight:500;margin-bottom:3px"><?= htmlspecialchars($company['tagline']) ?></div>
        <?php endif; ?>
        <div class="company-details">
            <?php if ($company['address']): ?><span>📍 <?= htmlspecialchars($company['address']) ?></span><?php endif; ?>
            <?php if ($company['phone']): ?><span>📞 <?= htmlspecialchars($company['phone']) ?></span><?php endif; ?>
            <?php if ($company['email']): ?><span>✉ <?= htmlspecialchars($company['email']) ?></span><?php endif; ?>
            <?php if ($company['website']): ?><span>🌐 <?= htmlspecialchars($company['website']) ?></span><?php endif; ?>
            <?php if ($company['reg_number']): ?><br><span>Reg: <?= htmlspecialchars($company['reg_number']) ?></span><?php endif; ?>
            <?php if ($company['vat_number']): ?><span>VAT: <?= htmlspecialchars($company['vat_number']) ?></span><?php endif; ?>
        </div>
    </div>

    <!-- DOC META -->
    <div class="doc-meta">
        <div class="doc-title">Sales Sheet</div>
        <div class="doc-batch">Batch #<?= $bb['sale_batchid'] ?></div>
        <div class="doc-meta-row">Date: <strong><?= date('d F Y', strtotime($bb['created_at'] ?? $bb['sell_date'] ?? date('Y-m-d'))) ?></strong></div>
        <div class="doc-meta-row">Split: <strong><?= $bb['splitid'] ?></strong></div>
        <?php
        // The payment that settles this sheet, so the sheet and the payments
        // report carry the same reference.
        $_pay = null;
        $_pq = @$conn->query("SELECT psh.payment_ref, ps.payment_ref AS run_ref,
                                     ps.reference_number, ps.paid_at, ps.payment_method
                              FROM payment_sheets psh
                              JOIN payment_status ps ON ps.id = psh.payment_statusid
                              WHERE psh.balanced_balesid = $bbid
                                AND psh.payment_ref IS NOT NULL
                              ORDER BY ps.paid_at DESC LIMIT 1");
        if ($_pq && $_pq !== true && $_pq->num_rows) $_pay = $_pq->fetch_assoc();
        ?>
        <?php if ($_pay): ?>
        <div class="doc-meta-row">Payment Ref: <strong><?= htmlspecialchars($_pay['payment_ref']) ?></strong></div>
        <?php if (!empty($_pay['run_ref'])): ?>
        <div class="doc-meta-row">Payment Run: <strong><?= htmlspecialchars($_pay['run_ref']) ?></strong></div>
        <?php endif; ?>
        <?php if (!empty($_pay['reference_number'])): ?>
        <div class="doc-meta-row">Bank Ref: <strong><?= htmlspecialchars($_pay['reference_number']) ?></strong></div>
        <?php endif; ?>
        <?php endif; ?>
        <div class="doc-meta-row">Printed: <?= date('d/m/Y H:i') ?></div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════ FLOOR INFO BAR -->
<div class="floor-bar">
    <div class="floor-bar-item">Floor: <span><?= htmlspecialchars($bb['selling_point_name']) ?></span></div>
    <div class="floor-bar-item">Code: <span><?= htmlspecialchars($bb['floor_code']) ?></span></div>
    <div class="floor-bar-item">Season: <span><?= htmlspecialchars($bb['season_name']) ?></span></div>
    <div class="floor-bar-item">Rate: <span>1 USD = <?= number_format((float)$bb['exchange_rate'],4) ?> ZiG</span></div>
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
        <tbody>
        <?php
        $i = 1; $bale_total_mass = 0; $bale_total_value = 0;
        while ($b = $bales->fetch_assoc()):
            $raw_price = (float)$b['price'];
            $price_per_kg = $raw_price > 10 ? $raw_price / 100 : $raw_price;
            $sc_calc  = strtoupper(trim($b['sale_code'] ?? ''));
            $is_rej_c = ($sc_calc==='R'||$sc_calc==='CO'||$sc_calc==='RO'||($sc_calc&&$sc_calc[0]==='R'&&strlen($sc_calc)<=3));
            $bale_value = $is_rej_c ? 0 : $price_per_kg * (float)$b['mass'];
            $bale_total_mass  += (float)$b['mass'];
            $bale_total_value += $bale_value;
        ?>
        <?php
        $sc     = strtoupper(trim($b['sale_code'] ?? ''));
        $is_rej = ($sc === 'R' || $sc === 'CO' || $sc === 'RO' || ($sc && $sc[0]==='R' && strlen($sc)<=3));
        $no_sale= empty($b['sale_code']) && !$b['price'];
        $row_bg = $is_rej ? 'background:#fff5f5' : ($no_sale ? 'background:#fffbf0' : '');
        ?>
        <tr style="<?= $row_bg ?>">
            <td style="color:var(--muted)"><?= $i++ ?></td>
            <td><code style="font-size:8.5px;letter-spacing:0.04em"><?= htmlspecialchars($b['barcode']) ?></code></td>
            <td style="text-align:center"><?= $b['lot'] ?></td>
            <td style="text-align:center"><?= $b['bale_group'] ?></td>
            <td>
                <?php if($is_rej): ?>
                <span style="background:#fee;color:#c0392b;padding:1px 5px;border-radius:3px;font-size:7.5px;font-weight:600;letter-spacing:.05em"><?= $sc ?: 'REJ' ?></span>
                <?php elseif($b['timb_grade']): ?>
                <span class="badge"><?= htmlspecialchars($b['timb_grade']) ?></span>
                <?php else: ?>
                <span style="color:var(--muted)">—</span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($b['buyer_grade']) ?></td>
            <td><?= htmlspecialchars($b['buyer_code']) ?></td>
            <td class="text-right"><?= number_format((float)$b['mass']) ?></td>
            <td class="text-right">
                <?php if($is_rej): ?>
                <span style="color:#c0392b;font-size:7.5px">REJECTED</span>
                <?php else: ?>
                $<?= number_format($price_per_kg, 4) ?>
                <?php endif; ?>
            </td>
            <td class="text-right" style="font-weight:500">
                <?php if($is_rej): ?>
                <span style="color:#c0392b">$0.00</span>
                <?php else: ?>
                $<?= number_format($bale_value, 2) ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="7" style="text-align:right;font-size:7.5px;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted)">Totals</td>
                <td class="text-right" style="font-weight:600"><?= number_format($bale_total_mass) ?> kg</td>
                <td></td>
                <td class="text-right" style="font-weight:600;color:var(--leaf)">$<?= number_format($bale_total_value, 2) ?></td>
            </tr>
        </tfoot>
    </table>
</div>

<!-- ════════════════════════════════════════════════════════════ FINANCIALS -->
<?php
$gross_usd = (float)$bb['usd_value'];
$ex_rate   = (float)($bb['exchange_rate'] ?: 1);

// Get RBZ split %
$_rbz_q = $conn->query("SELECT usd_percent,other_currency_percent FROM rbz_retention_rate LIMIT 1");
$rbz_row = ($_rbz_q && $_rbz_q!==true) ? $_rbz_q->fetch_assoc() : null;
$rbz_usd_pct = $rbz_row ? (float)$rbz_row['usd_percent'] : 0.70;
// The rule recorded on the delivery when it was calculated; today's Settings only as a fallback.
$_rule = $conn->query("SELECT rule_so_mode, rule_rbz_usd FROM balanced_bales WHERE id=" . (int)$bbid);
$_rule = ($_rule && $_rule !== true) ? $_rule->fetch_assoc() : null;
if ($_rule && $_rule['rule_so_mode'] !== null && $_rule['rule_rbz_usd'] !== null) {
    $so_mode     = $_rule['rule_so_mode'];
    $rbz_usd_pct = (float)$_rule['rule_rbz_usd'];
}
$rbz_zig_pct = 1 - $rbz_usd_pct;

// TIMB SOs
$timb_so_total_disp = 0;
$timb_so_rows = [];
if ($timb_so && $timb_so !== true) {
    $timb_so->data_seek(0);
    while ($ts = $timb_so->fetch_assoc()) {
        $timb_so_total_disp += (float)$ts['amount'];
        $timb_so_rows[] = $ts;
    }
}

// Query the active bank charge rate from grower_charges settings (statutoryid = 7)
$bc_rate = 0.02; // default 2%
$bc_q = $conn->query("
    SELECT amount
    FROM grower_charges
    WHERE statutoryid = 7 AND active = 1 AND (selling_pointid = $_sheet_spid OR selling_pointid = 0)
    LIMIT 1
");
if ($bc_q && $bc_q->num_rows > 0) {
    $bc_rate = (float)$bc_q->fetch_assoc()['amount'];
}

// Charges
$charges_rows = [];
$charges_usd_total_excluding_bc = 0;
$charges_zig_total_excluding_bc = 0;
$has_bank_charge_row = false;

if ($charges && $charges !== true) {
    $charges->data_seek(0);
    while ($c = $charges->fetch_assoc()) {
        if ((int)($c['statutory_id'] ?? 0) === 7) {
            $has_bank_charge_row = true;
            continue;
        }
        $charges_usd_total_excluding_bc += (float)$c['usd_value'];
        $charges_zig_total_excluding_bc += (float)$c['zim_value'];
        $charges_rows[] = $c;
    }
}

// TIMB SOs
$timb_so_rows = [];
$timb_so_total_disp = 0;
if ($timb_so && $timb_so !== true) {
    $timb_so->data_seek(0);
    while ($ts = $timb_so->fetch_assoc()) {
        $timb_so_total_disp += (float)$ts['amount'];
        $timb_so_rows[] = $ts;
    }
}

// Internal SOs
$iso_rows = [];
$iso_total = 0;
if ($iso && $iso !== true) {
    $iso->data_seek(0);
    while ($is = $iso->fetch_assoc()) {
        $iso_total += (float)$is['amount'];
        $iso_rows[] = $is;
    }
}

// ── RECALCULATE FROM SCRATCH based on so_mode ────────────────────────────
// Step 1: Gross
$step_gross = $gross_usd;

// Step 2: TIMB SO (before split mode)
if ($so_mode === 'before') {
    $step_after_so   = max(0, $step_gross - $timb_so_total_disp);
    $split_base      = $step_after_so;
} else {
    $step_after_so   = $step_gross; // no SO deducted yet
    $split_base      = $step_gross;
}

// Step 3: RBZ Split
$step_usd_portion = $split_base * $rbz_usd_pct;
$step_zig_portion = $split_base * $rbz_zig_pct;
$step_zig_in_zig  = $step_zig_portion * $ex_rate;

// Step 4: Deduct from USD portion
$step_usd_after_timb_so_after = $so_mode === 'after'
    ? max(0, $step_usd_portion - $timb_so_total_disp)
    : $step_usd_portion;

// Calculate payout before bank charge
$step_usd_after_charges_no_bc = max(0, $step_usd_after_timb_so_after - $charges_usd_total_excluding_bc);
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

$step_usd_after_charges = max(0, $step_usd_after_timb_so_after - $charges_usd_total);
$step_usd_net           = max(0, $step_usd_after_charges - $iso_total);
$step_zig_net           = max(0, $step_zig_in_zig - $charges_zig_total);

// Total deductions USD
$total_ded_usd = $timb_so_total_disp + $charges_usd_total + $iso_total;
$total_ded_zig = $charges_zig_total;
?>
<div class="summary-wrap">

    <!-- LEFT: Step-by-step calculation -->
    <div>
        <div class="stitle">Settlement Calculation</div>
        <table class="sum-table">
            <tr style="background:#f8f5ef">
                <td style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600" colspan="2">Bale Summary</td>
            </tr>
            <tr><td>Total Mass</td><td class="text-right"><?= number_format((float)$bb['mass'],1) ?> kg</td></tr>
            <tr><td>Bales Sold / Rejected / Laid</td>
                <td class="text-right"><?= (int)$bb['sold_bales'] ?> / <?= (int)$bb['rejected_bales'] ?> / <?= (int)$bb['laid_bales'] ?></td></tr>
            <tr><td>Avg Price</td><td class="text-right">$<?= number_format((float)$bb['average_price'],4) ?>/kg</td></tr>
            <tr><td>Exchange Rate</td><td class="text-right">1 USD = <?= number_format($ex_rate,4) ?> ZiG</td></tr>

            <tr style="background:#f8f5ef">
                <td style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600" colspan="2">Step 1 — Gross Proceeds</td>
            </tr>
            <tr class="subtotal">
                <td>Gross USD Value</td>
                <td class="text-right" style="font-weight:700">$<?= number_format($step_gross,2) ?></td>
            </tr>

            <?php if($so_mode==='before' && $timb_so_total_disp > 0): ?>
            <tr style="background:#f0f4fa">
                <td style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:#1a4a8a;font-weight:600" colspan="2">Step 2 — TIMB Stop Orders (before split)</td>
            </tr>
            <?php foreach($timb_so_rows as $ts): ?>
            <tr>
                <td style="padding-left:1rem">
                    <span style="font-size:.7em;background:#e8eef8;color:#1a4a8a;padding:1px 4px;border-radius:3px">TIMB SO</span>
                    <?= htmlspecialchars($ts['description']) ?>
                    <?php if($ts['creditor_no']): ?><span style="color:#999;font-size:.8em"> (<?= htmlspecialchars($ts['creditor_no']) ?>)</span><?php endif; ?>
                </td>
                <td class="text-right" style="color:#c0392b">-$<?= number_format((float)$ts['amount'],2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="subtotal">
                <td>After TIMB Stop Orders</td>
                <td class="text-right" style="font-weight:700">$<?= number_format($step_after_so,2) ?></td>
            </tr>
            <?php endif; ?>

            <tr style="background:#f8f5ef">
                <td style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600" colspan="2">Step <?= ($so_mode==='before'&&$timb_so_total_disp>0)?'3':'2' ?> — RBZ Retention Split (<?= round($rbz_usd_pct*100) ?>/<?= round($rbz_zig_pct*100) ?>)</td>
            </tr>
            <tr>
                <td style="color:#2d6b25;font-weight:500">USD Portion (<?= round($rbz_usd_pct*100) ?>%)</td>
                <td class="text-right" style="color:#2d6b25;font-weight:600">$<?= number_format($step_usd_portion,2) ?></td>
            </tr>
            <tr>
                <td style="color:#1a4a8a;font-weight:500">ZiG Portion (<?= round($rbz_zig_pct*100) ?>% × <?= number_format($ex_rate,4) ?>)</td>
                <td class="text-right" style="color:#1a4a8a;font-weight:600"><?= number_format($step_zig_in_zig,2) ?> ZiG</td>
            </tr>

            <?php if($so_mode==='after' && $timb_so_total_disp > 0): ?>
            <tr style="background:#f0f4fa">
                <td style="font-size:7px;letter-spacing:.1em;text-transform:uppercase;color:#1a4a8a;font-weight:600" colspan="2">Step 3 — TIMB Stop Orders (from USD portion)</td>
            </tr>
            <?php foreach($timb_so_rows as $ts): ?>
            <tr>
                <td style="padding-left:1rem">
                    <span style="font-size:.7em;background:#e8eef8;color:#1a4a8a;padding:1px 4px;border-radius:3px">TIMB SO</span>
                    <?= htmlspecialchars($ts['description']) ?>
                    <?php if($ts['creditor_no']): ?><span style="color:#999;font-size:.8em"> (<?= htmlspecialchars($ts['creditor_no']) ?>)</span><?php endif; ?>
                </td>
                <td class="text-right" style="color:#c0392b">-$<?= number_format((float)$ts['amount'],2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="subtotal">
                <td>USD after TIMB SO</td>
                <td class="text-right" style="font-weight:700">$<?= number_format($step_usd_after_timb_so_after,2) ?></td>
            </tr>
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

            <!-- Grower Charges -->
            <?php foreach($charges_rows as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['description']) ?></td>
                <td class="text-right" style="color:#c0392b"><?= (float)$c['usd_value']>0?'-$'.number_format((float)$c['usd_value'],2):'' ?></td>
                <td class="text-right" style="color:#c0392b"><?= (float)$c['zim_value']>0?'-'.number_format((float)$c['zim_value'],2).' ZiG':'' ?></td>
            </tr>
            <?php endforeach; ?>

            <!-- Internal SOs -->
            <?php foreach($iso_rows as $is): ?>
            <tr>
                <td>
                    <span style="font-size:.7em;background:#e8f4e8;color:#2d6b25;padding:1px 4px;border-radius:3px">Internal</span>
                    <?= htmlspecialchars($is['description']) ?>
                </td>
                <td class="text-right" style="color:#c0392b">-$<?= number_format((float)$is['amount'],2) ?></td>
                <td></td>
            </tr>
            <?php endforeach; ?>

            <?php if(empty($charges_rows) && empty($iso_rows)): ?>
            <tr><td colspan="3" style="color:var(--muted);font-style:italic;text-align:center;padding:.5rem">No charges</td></tr>
            <?php endif; ?>

            <!-- Totals row -->
            <tr style="background:#f0f0f0;border-top:1.5px solid #ccc">
                <td style="font-weight:600;font-size:.78rem">Total Deductions</td>
                <td class="text-right" style="font-weight:700;color:#c0392b">-$<?= number_format($charges_usd_total + $iso_total,2) ?></td>
                <td class="text-right" style="font-weight:700;color:#c0392b"><?= $charges_zig_total>0?'-'.number_format($charges_zig_total,2).' ZiG':'-' ?></td>
            </tr>

            <!-- Net rows: the payout printed is the stored net (what Payments pays),
                 not this page's own recalculation, which uses today's Settings. -->
            <?php
            $has_stored = $bb['usd_net_value'] !== null;
            $db_net_usd = $has_stored ? (float)$bb['usd_net_value'] : $step_usd_net;
            $db_net_zig = $has_stored ? (float)$bb['zim_net_value'] : $step_zig_net;
            $net_note   = $has_stored ? 'as recorded at balancing' : 'not yet recorded — estimate';
            ?>
            <tr class="net-row">
                <td>NET PAYOUT (USD)<div style="font-size:.65em;opacity:.7;font-weight:400"><?= $net_note ?></div></td>
                <td class="text-right" style="font-size:1.05em">$<?= number_format($db_net_usd,2) ?></td>
                <td></td>
            </tr>
            <tr class="net-row" style="border-top:1px solid rgba(255,255,255,.15)">
                <td>NET PAYOUT (ZiG)<div style="font-size:.65em;opacity:.7;font-weight:400"><?= $net_note ?></div></td>
                <td></td>
                <td class="text-right" style="font-size:1.05em"><?= number_format($db_net_zig,2) ?> ZiG</td>
            </tr>

            <!-- Variance check (no-print) -->
            <?php
            $var_usd = round($step_usd_net - $db_net_usd, 2);
            $var_zig = round($step_zig_net - $db_net_zig, 2);
            if (abs($var_usd) > 0.01 || abs($var_zig) > 0.01):
            ?>
            <tr class="no-print" style="background:#fff8e1">
                <td style="font-size:.68rem;color:#8a5a0a">
                    ⚠ The steps above use today's Settings and differ from the recorded payout by
                </td>
                <td class="text-right" style="font-size:.68rem;color:#c0392b">
                    <?= $var_usd!=0 ? 'Δ $'.number_format($var_usd,2) : '' ?>
                </td>
                <td class="text-right" style="font-size:.68rem;color:#c0392b">
                    <?= $var_zig!=0 ? 'Δ '.number_format($var_zig,2).' ZiG' : '' ?>
                </td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ SIGNATURES -->
<div class="sig-section">
    <div class="sig-block">
        <div class="sig-label">Grower Signature</div>
        <div class="sig-date">Name: _______________________</div>
        <div class="sig-date">Date: _______________________</div>
    </div>
    <div class="sig-block">
        <div class="sig-label">Floor Manager</div>
        <div class="sig-date">Name: _______________________</div>
        <div class="sig-date">Date: _______________________</div>
    </div>
    <div class="sig-block">
        <div class="sig-label">Accounts</div>
        <div class="sig-date">Name: _______________________</div>
        <div class="sig-date">Date: _______________________</div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════ FOOTER -->
<div class="doc-footer">
    <div class="doc-footer-left">
        <div style="font-weight:500"><?= htmlspecialchars($company['name']) ?></div>
        <?php if ($company['address']): ?><div><?= htmlspecialchars($company['address']) ?></div><?php endif; ?>
        <?php if ($company['phone'] || $company['email']): ?>
        <div><?= htmlspecialchars($company['phone']) ?><?= ($company['phone'] && $company['email']) ? ' · ' : '' ?><?= htmlspecialchars($company['email']) ?></div>
        <?php endif; ?>
    </div>
    <div style="text-align:center">
        <div class="watermark-box">COPY</div>
    </div>
    <div class="doc-footer-right">
        <div>Flowork Auction System</div>
        <div>Batch #<?= $bb['sale_batchid'] ?> · Split <?= $bb['splitid'] ?></div>
        <div>Printed: <?= date('d/m/Y H:i') ?></div>
    </div>
</div>

</body>
</html>
