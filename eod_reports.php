<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/xlsx_writer.php";
require_once "includes/pdf_table.php";
require_login();

$page_title  = "End of Day Reports";
$active_page = "eod_reports";
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$userid   = current_userid();
$msg = $err = '';

// ── Filters ────────────────────────────────────────────────────────────────
$date_f       = $conn->real_escape_string(trim($_GET['date']  ?? date('Y-m-d')));
$buyer_f      = $conn->real_escape_string(trim($_GET['buyer'] ?? ''));
if ($buyer_f === '0') $buyer_f = '';
$buyer_mark_f = $conn->real_escape_string(trim($_GET['buyer_mark'] ?? ''));
$loc_f        = (int)($_GET['loc']   ?? 0);
$report       = $_GET['report'] ?? '';

// Reset buyer mark if it does not match the selected buyer name
if ($buyer_f !== '' && $buyer_mark_f !== '') {
    $_buyer_sp = $spid > 0 ? "selling_pointid=$spid" : "1=1";
    $check_mark_q = $conn->query("SELECT id FROM buyer WHERE name='$buyer_f' AND buyer_code='$buyer_mark_f' AND $_buyer_sp LIMIT 1");
    if (!$check_mark_q || $check_mark_q->num_rows === 0) {
        $buyer_mark_f = '';
    }
}

// Load filter options
$_buyer_sp = $spid > 0 ? "selling_pointid=$spid" : "1=1";
$_locs_sp  = $spid > 0 ? "selling_pointid=$spid AND" : "";
$buyers_q = $conn->query("SELECT DISTINCT name FROM buyer WHERE $_buyer_sp ORDER BY name");
$buyers   = ($buyers_q && $buyers_q !== true) ? $buyers_q->fetch_all(MYSQLI_ASSOC) : [];

$buyer_marks_cond = "";
if ($buyer_f !== '') {
    $buyer_marks_cond = " AND name='$buyer_f'";
}
$buyer_marks_q = $conn->query("SELECT DISTINCT buyer_code FROM buyer WHERE $_buyer_sp AND buyer_code != '' $buyer_marks_cond ORDER BY buyer_code");
$buyer_marks   = ($buyer_marks_q && $buyer_marks_q !== true) ? $buyer_marks_q->fetch_all(MYSQLI_ASSOC) : [];
$locs_q   = $conn->query("SELECT id,code,name,subcontractor FROM bale_locations WHERE $_locs_sp active=1 ORDER BY code");
$locs     = ($locs_q && $locs_q !== true) ? $locs_q->fetch_all(MYSQLI_ASSOC) : [];

// Exchange rate — exchange_rate table has a sell_date column, use it directly
$er_q1 = $conn->query("SELECT amount FROM exchange_rate WHERE sell_date='$date_f' AND active=1 ORDER BY id DESC LIMIT 1");
$er = ($er_q1 && $er_q1 !== true && $er_q1->num_rows) ? $er_q1->fetch_assoc() : null;
if (!$er) {
    // Fallback: most recent active rate
    $er_q2 = $conn->query("SELECT amount FROM exchange_rate WHERE active=1 ORDER BY id DESC LIMIT 1");
    $er = ($er_q2 && $er_q2 !== true) ? $er_q2->fetch_assoc() : null;
}
$zig_rate = (float)($er['amount'] ?? 0);

// Company name
$sp_name = htmlspecialchars($_SESSION['selling_point_name'] ?? 'FLOWORK FLOOR');

// ── Sell date list ─────────────────────────────────────────────────────────
// COALESCE: use tg.sell_date when set (set at booking), else fall back to ticket
// creation date so records without a booked sell_date still appear.
$_sp_where = $spid > 0 ? "g.selling_pointid = $spid" : "1=1";
$sell_dates_q = $conn->query("
    SELECT COALESCE(tg.sell_date, DATE(tk.created_at)) AS sd,
           COUNT(DISTINCT tk.id) AS tix
    FROM tickets tk
    JOIN bale_junus bj ON bj.id = tk.bale_junusid
    JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
    JOIN growers g ON g.id = bj.growerid
    WHERE $_sp_where
    GROUP BY COALESCE(tg.sell_date, DATE(tk.created_at))
    ORDER BY sd DESC LIMIT 60
");
if (!$sell_dates_q) $sell_dates_q = null;

// ── Core bale loader ───────────────────────────────────────────────────────
function load_bales($conn, $date_f, $spid, $buyer_f, $loc_f, $ytd=false) {
    // Date condition: prefer tg2.sell_date (authoritative), fall back to ticket creation date.
    // YTD = cumulative — every bale up to AND INCLUDING the selected day (not the whole year).
    $date_cond = $ytd
        ? "COALESCE(tg2.sell_date, DATE(tk.created_at)) <= '$date_f'"
        : "COALESCE(tg2.sell_date, DATE(tk.created_at))='$date_f'";

    // selling_pointid filter: match on bm OR g (handles migrated growers where they differ).
    // When spid=0 (admin / no floor set) bypass the filter entirely — see selling.php pattern.
    $sp_cond = $spid > 0
        ? "(bm.selling_pointid=$spid OR g.selling_pointid=$spid)"
        : "1=1";
    $where = "$date_cond AND $sp_cond";
    if ($buyer_f !== '') {
        if (is_numeric($buyer_f)) {
            $where .= " AND b.id=$buyer_f";
        } else {
            $where .= " AND b.name='$buyer_f'";
        }
    }
    if ($loc_f)   $where .= " AND bl.id=$loc_f";

    // Apply global buyer mark filter if $buyer_f is not explicitly bypassed ('')
    $g_buyer = $GLOBALS['buyer_f'] ?? '';
    $g_buyer_mark = $conn->real_escape_string(trim($GLOBALS['buyer_mark_f'] ?? ''));
    if ($buyer_f === $g_buyer && $g_buyer_mark !== '') {
        $where .= " AND b.buyer_code='$g_buyer_mark'";
    }

    $q = $conn->query("
        SELECT tk.barcode,
               COALESCE(bm.mass, 0)            AS mass,
               COALESCE(bm.temp_bacode, '')     AS timb_bc,
               COALESCE(tg2.sell_date, DATE(tk.created_at)) AS sell_date,
               sb.price, sb.sale_code,
               tgd.description AS timb_grade, bg.description AS buyer_grade,
               b.id AS buyer_id, COALESCE(NULLIF(b.buyer_code,''), b.name) AS buyer_name, b.buyer_code,
               g.id AS grower_id, g.grower_num, g.name AS gname, g.surname,
               COALESCE(g.farm_name, tgr.farm_name, '') AS farm_name,
               COALESCE(g.province, tgr.province, '') AS province,
               COALESCE(tgr.area, '') AS area,
               COALESCE(tgr.district_name, '') AS district,
               COALESCE(g.national_id, tgr.national_id, '') AS national_id,
               bl.id AS loc_id, bl.code AS loc_code, bl.name AS loc_name, bl.subcontractor,
               COALESCE(t.name,'') AS transporter_name,
               bj.lot, bj.bale_group, bj.splitid
        FROM tickets tk
        JOIN  bale_junus bj           ON bj.id  = tk.bale_junusid
        LEFT JOIN bale_mass bm        ON bm.id  = bj.bale_massid
        JOIN  growers g               ON g.id   = bj.growerid
        JOIN  transporter_growers tg2 ON tg2.id = bj.transporter_growersid
        LEFT JOIN transporter t       ON t.id   = tg2.transporterid
        LEFT JOIN bale_locations bl   ON bl.id  = t.location_id
        LEFT JOIN timb_grower_registry tgr
                                      ON tgr.grower_num     = g.grower_num
                                     AND tgr.selling_pointid = $spid
        LEFT JOIN sold_bales sb       ON sb.ticketsid = tk.id
        LEFT JOIN timb_grades tgd     ON tgd.id = sb.timb_gradesid
        LEFT JOIN buyer_grades bg     ON bg.id  = sb.buyer_gradesid
        LEFT JOIN buyer b             ON b.id   = bg.buyerid
        WHERE $where
        ORDER BY g.grower_num ASC, bj.lot ASC
    ");
    if (!$q) {
        error_log("load_bales query failed: " . $conn->error);
        return [];
    }
    $rows = [];
    while ($q && $r = $q->fetch_assoc()) {
        $raw = (float)$r['price'];
        $r['price_per_kg'] = $raw > 10 ? $raw / 100 : $raw;
        $r['usd_value']    = $r['mass'] * $r['price_per_kg'];
        $r['zig_value']    = $r['usd_value'] * (float)$GLOBALS['zig_rate'];
        // A bale is rejected only when the sale_code starts with R and is ≤3 chars (e.g. R1, R2, RA).
        // Zero-price without an R-code means unsold/pending — NOT rejected.
        $r['is_rej'] = strtoupper(substr(trim($r['sale_code']??''),0,1))==='R' && strlen(trim($r['sale_code']??''))<=3;
        $rows[] = $r;
    }
    return $rows;
}

function grp($arr, $key) {
    $out = [];
    foreach ($arr as $r) { $out[$r[$key]][] = $r; }
    return $out;
}
function sum_col($arr, $col) { return array_sum(array_column($arr,$col)); }
function money($v,$d=2) { return number_format((float)$v,$d); }
function m($v,$d=2) { return '$'.money($v,$d); }

// ── Shared aggregation helpers (used by the XLSX exports) ───────────────────
// $dc = a SQL boolean on COALESCE(tg.sell_date, DATE(tk.created_at)) / bale date.
function eod_sales_stats($conn,$spid,$dc){
    $sp = $spid>0 ? "(g.selling_pointid=$spid OR g.selling_pointid=0)" : "1=1";
    $q=$conn->query("SELECT COUNT(DISTINCT tk.id) laid, COUNT(DISTINCT sb.id) sold,
        COALESCE(SUM(CASE WHEN sb.id IS NOT NULL THEN bm.mass END),0) mass,
        COALESCE(SUM(CASE WHEN sb.id IS NOT NULL THEN
          (CASE WHEN CAST(sb.price AS DECIMAL(12,4))>10 THEN CAST(sb.price AS DECIMAL(12,4))/100
                ELSE CAST(sb.price AS DECIMAL(12,4)) END)*bm.mass END),0) gross
      FROM tickets tk
      JOIN bale_junus bj ON bj.id=tk.bale_junusid
      JOIN bale_mass bm ON bm.id=bj.bale_massid
      JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
      JOIN growers g ON g.id=bj.growerid
      LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
      WHERE $sp AND ".str_replace('%DATE%',"COALESCE(tg.sell_date,DATE(tk.created_at))",$dc));
    return ($q&&$q!==true)?$q->fetch_assoc():['laid'=>0,'sold'=>0,'mass'=>0,'gross'=>0];
}
function eod_net($conn,$spid,$dc){
    $sp = $spid>0 ? "(g.selling_pointid=$spid OR g.selling_pointid=0)" : "1=1";
    $q=$conn->query("SELECT COALESCE(SUM(dtn.usd_net_value),0) net
      FROM balanced_bales bb
      JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
      JOIN growers g ON g.id=bb.growerid
      LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
      WHERE $sp AND ".str_replace('%DATE%',"COALESCE(tg.sell_date,DATE(bb.created_at))",$dc));
    return ($q&&$q!==true)?(float)$q->fetch_assoc()['net']:0;
}
// per-levy [usd,zwl] from the balancing charges
function eod_levies($conn,$spid,$dc){
    $sp = $spid>0 ? "(g.selling_pointid=$spid OR g.selling_pointid=0)" : "1=1";
    $q=$conn->query("SELECT s.description levy, SUM(bbc.usd_value) usd, SUM(bbc.zim_value) zwl
      FROM balanced_bales_charges bbc
      JOIN grower_charges gc ON gc.id=bbc.grower_chargeid
      LEFT JOIN statutory s ON s.id=gc.statutoryid
      JOIN balanced_bales bb ON bb.id=bbc.balanced_balesid
      JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
      JOIN growers g ON g.id=bb.growerid
      WHERE $sp AND ".str_replace('%DATE%',"COALESCE(tg.sell_date,DATE(bb.created_at))",$dc)
      ." GROUP BY s.description");
    $out=[];
    if($q&&$q!==true) while($r=$q->fetch_assoc()) $out[$r['levy']]=['usd'=>(float)$r['usd'],'zwl'=>(float)$r['zwl']];
    return $out;
}
// stop-order deductions per creditor (empty until stop orders run) [name=>['usd','zwl']]
function eod_stoporders($conn,$spid,$dc){
    $sp = $spid>0 ? "(g.selling_pointid=$spid OR g.selling_pointid=0)" : "1=1";
    $q=$conn->query("SELECT COALESCE(gcr.creditor_name,CONCAT('Creditor ',sd.creditor_no)) name,
        SUM(sd.amount_deducted) usd
      FROM stoporder_deductions sd
      JOIN balanced_bales bb ON bb.id=sd.balanced_balesid
      JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
      JOIN growers g ON g.id=bb.growerid
      LEFT JOIN grower_creditors gcr ON gcr.creditor_no=sd.creditor_no
      WHERE $sp AND ".str_replace('%DATE%',"COALESCE(tg.sell_date,DATE(bb.created_at))",$dc)
      ." GROUP BY sd.creditor_no");
    $out=[];
    if($q&&$q!==true) while($r=$q->fetch_assoc()) $out[$r['name']]=(float)$r['usd'];
    return $out;
}
// levy lookup by partial name
function eod_levy($lv,$needle){ foreach($lv as $k=>$v){ if(stripos($k,$needle)!==false) return $v; } return ['usd'=>0,'zwl'=>0]; }
function eod_distinct_dates($conn,$spid,$upto){
    $sp = $spid>0 ? "(g.selling_pointid=$spid OR g.selling_pointid=0)" : "1=1";
    $de=$conn->real_escape_string($upto);
    $q=$conn->query("SELECT DISTINCT COALESCE(tg.sell_date,DATE(tk.created_at)) d
      FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid
      JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
      JOIN growers g ON g.id=bj.growerid
      WHERE $sp AND COALESCE(tg.sell_date,DATE(tk.created_at)) <= '$de'
      ORDER BY d DESC");
    $out=[]; if($q&&$q!==true) while($r=$q->fetch_assoc()) $out[]=$r['d'];
    return $out;
}

// ── Official buyer-invoice TXT (matches VL_ILT_BUYER_INVOICE_xxxx.txt) ───────
// Buyer-side levy / retention rates. Confirm against finance rules before use.
const TOBACCO_LEVY_PCT       = 0.015;   // 1.5% of US value
const TTA_LEVY_PER_KG        = 0.005;   // $0.005 per kg
const FLOOR_CLEARING_PER_BALE= 0.65;    // $0.65 per bale
const NOSTRO_SPLIT_PCT       = 0.7533;  // share of net purchases settled in USD (nostro)
const STATS_FLR_CLEARING_PCT = 0.000173;// buyer-stats floor clearing as % of US value — CONFIRM exact rate/basis

// Buyer disk file (.v) — fixed-width electronic file, one line per paid bale.
// Layout (0-indexed): date[0:4] grade[4:12] grower[12:21] lot[21:26] mass[26:34]
// zwPrice×100[34:54] usPrice×1000[54:64] reserved[64:71] zwVal×100+sign[71:84]
// usVal×100+sign[84:97] barcode[97:109] timbGrade[109:117].
function buyerDiskFile($conn, array $bales, float $zig_rate): string {
    $rows = array_filter($bales, fn($b)=>!$b['is_rej'] && !empty($b['buyer_id']));
    usort($rows, function($a,$b){
        $g = strcmp((string)($a['grower_num']??''),(string)($b['grower_num']??''));
        return $g !== 0 ? $g : ((int)$b['lot'] - (int)$a['lot']);
    });
    $z = fn($v,$w)=>str_pad((string)$v, $w, '0', STR_PAD_LEFT);   // numeric, zero-padded
    $l = fn($s,$w)=>str_pad(substr((string)$s,0,$w), $w);         // text, left-justified
    $out='';
    foreach ($rows as $r) {
        $mass  = (float)$r['mass'];
        $uspk  = (float)$r['price_per_kg'];
        $zwpk  = $uspk * $zig_rate;
        $usval = $uspk * $mass;
        $zwval = $usval * $zig_rate;
        $out .= date('md', strtotime($r['sell_date']))
              . $l($r['buyer_grade'] ?? '', 8)
              . $l($r['grower_num'] ?? '', 9)
              . $z((int)$r['lot'], 5)
              . $z((int)round($mass), 8)
              . $z((int)($zwpk*100), 20)            // ZW price ×100 (truncated, matches sample)
              . $z((int)round($uspk*1000), 10)      // US price ×1000
              . '0000000'                           // reserved
              . $z((int)round($zwval*100), 12).'+'  // ZW value ×100
              . $z((int)round($usval*100), 12).'+'  // US value ×100
              . $l($r['barcode'], 12)
              . $l($r['timb_grade'] ?? '', 8)
              . "\r\n";
    }
    return $out;
}

// Creditor Invoice Advice — per-creditor (levy) blocks of grower deductions, from balancing charges.
function creditorInvoiceTxt($conn, int $spid, string $date_f, string $sp_name): string {
    $de = $conn->real_escape_string($date_f);
    $q = $conn->query("
        SELECT gc.id gcid, gc.creditor_no, gc.statutoryid, s.description levy, s.currencyid,
               g.grower_num, TRIM(CONCAT(g.name,' ',g.surname)) grower_name,
               bbc.usd_value, bbc.zim_value,
               COALESCE(tg.sell_date, DATE(bb.created_at)) sale_date
        FROM balanced_bales_charges bbc
        JOIN grower_charges gc ON gc.id=bbc.grower_chargeid
        LEFT JOIN statutory s ON s.id=gc.statutoryid
        JOIN balanced_bales bb ON bb.id=bbc.balanced_balesid
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        JOIN growers g ON g.id=bb.growerid
        WHERE (g.selling_pointid=$spid OR $spid=0)
          AND COALESCE(tg.sell_date, DATE(bb.created_at))='$de'
        ORDER BY gc.id, g.grower_num, bbc.id");
    $byLevy = [];
    if ($q && $q !== true) while ($r = $q->fetch_assoc()) $byLevy[$r['gcid']][] = $r;

    $sep = str_repeat('-',92)."\n";
    $dF  = date('d/m/Y', strtotime($date_f));
    $lj  = fn($s,$w)=>str_pad(substr((string)$s,0,$w), $w);
    $rj  = fn($s,$w)=>str_pad((string)$s, $w, ' ', STR_PAD_LEFT);
    $nc  = fn($v)=>number_format((float)$v,2);
    $out = '';
    foreach ($byLevy as $rows) {
        $first = $rows[0];
        $isZwl = ((int)$first['currencyid'] === 2);
        $credNo = (int)$first['creditor_no'] > 0 ? $first['creditor_no'] : $first['statutoryid'];
        $type   = $isZwl ? 'statutory' : 'Internal USD Invoices';
        $acct   = $isZwl ? 'ZWL ACCOUNT NUMBER' : 'USD ACCOUNT NUMBER';

        $out .= "$sp_name : INVOICE CREDITOR ADVICE - $dF to $dF\n";
        $out .= $sep;
        $out .= "CREDITOR NUMBER: $credNo\n";
        $out .= "CREDITOR NAME : ".$first['levy']."\n";
        $out .= "INVOICE TYPE : $type\n";
        $out .= "BANK           :  \n";
        $out .= "BRANCH         :   ,  \n";
        $out .= "$acct :  \n\n\n";
        $out .= "SALE DATE      GROWER NO.     GROWER NAME                            US\$          RTGS\$ \n";
        $out .= $sep;
        $tu = 0; $tz = 0;
        foreach ($rows as $r) {
            $tu += (float)$r['usd_value']; $tz += (float)$r['zim_value'];
            $out .= $lj(date('d/m/Y',strtotime($r['sale_date'])),10)."     "
                  . $lj($r['grower_num'],13).$lj($r['grower_name'],30)
                  . $rj($nc($r['usd_value']),12).$rj($nc($r['zim_value']),16)."\n";
        }
        $out .= "\n".str_pad('',13)."TOTAL COLLECTED".$rj($nc($tu),42).$rj($nc($tz),16)."\n\n\n";
    }
    if ($out === '') $out = "$sp_name : INVOICE CREDITOR ADVICE - $dF to $dF\n$sep"
        . "No creditor deductions for this date.\n";
    return $out;
}

// YTD Creditor Invoice — flat CSV, one row per charge, cumulative up to the date.
function creditorYtdCsv($conn, int $spid, string $date_f, string $floor_code): string {
    $de = $conn->real_escape_string($date_f);
    // python-style float string: trim trailing zeros, keep at least one decimal
    $pf = function($v){ $s=rtrim(rtrim(sprintf('%.2f',round((float)$v,2)),'0'),'.'); return strpos($s,'.')===false?$s.'.0':$s; };
    $out = "SALE POINT, SALE DATE, GROWER NO.,GROWER NAME, US\$, RTGS\$, CREDITOR NUMBER, CREDITOR NAME, CURRENCY\r\n";

    // 1) Statutory levies from the balancing charges (cumulative to the date)
    $q = $conn->query("
        SELECT COALESCE(tg.sell_date,DATE(bb.created_at)) sale_date,
               g.grower_num, TRIM(CONCAT(g.name,' ',g.surname)) grower_name,
               bbc.usd_value, bbc.zim_value,
               COALESCE(NULLIF(gc.creditor_no,0), gc.statutoryid) cred_no, s.description levy
        FROM balanced_bales_charges bbc
        JOIN grower_charges gc ON gc.id=bbc.grower_chargeid
        LEFT JOIN statutory s ON s.id=gc.statutoryid
        JOIN balanced_bales bb ON bb.id=bbc.balanced_balesid
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        JOIN growers g ON g.id=bb.growerid
        WHERE (g.selling_pointid=$spid OR $spid=0)
          AND COALESCE(tg.sell_date,DATE(bb.created_at)) <= '$de'
        ORDER BY bbc.grower_chargeid, sale_date DESC, g.grower_num");
    if ($q && $q !== true) while ($r = $q->fetch_assoc()) {
        $usd=(float)$r['usd_value']; $zim=(float)$r['zim_value'];
        if ($zim > 0 && $usd == 0) { $cur='ZWL'; $us='0'; $rt=$pf($zim); }
        else                       { $cur='USD'; $us=$pf($usd); $rt='0'; }
        $out .= implode(',', [$floor_code, date('d/m/Y',strtotime($r['sale_date'])),
                 $r['grower_num'], $r['grower_name'], $us, $rt, $r['cred_no'], $r['levy'], $cur])."\r\n";
    }

    // 2) Stop-order deductions -> external creditors (populates once stop orders are processed)
    $q2 = $conn->query("
        SELECT COALESCE(tg.sell_date,DATE(bb.created_at)) sale_date,
               g.grower_num, TRIM(CONCAT(g.name,' ',g.surname)) grower_name,
               sd.amount_deducted, sd.creditor_no,
               COALESCE(gcr.creditor_name, CONCAT('Creditor ',sd.creditor_no)) cred_name
        FROM stoporder_deductions sd
        JOIN balanced_bales bb ON bb.id=sd.balanced_balesid
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        JOIN growers g ON g.id=bb.growerid
        LEFT JOIN grower_creditors gcr ON gcr.creditor_no=sd.creditor_no
        WHERE (g.selling_pointid=$spid OR $spid=0)
          AND COALESCE(tg.sell_date,DATE(bb.created_at)) <= '$de'
        ORDER BY sd.creditor_no, sale_date DESC, g.grower_num");
    if ($q2 && $q2 !== true) while ($r = $q2->fetch_assoc()) {
        $out .= implode(',', [$floor_code, date('d/m/Y',strtotime($r['sale_date'])),
                 $r['grower_num'], $r['grower_name'], $pf($r['amount_deducted']), '0',
                 $r['creditor_no'], $r['cred_name'], 'USD'])."\r\n";
    }
    return $out;
}

// USD Creditor Invoice Summary — one line per creditor with SO commission + service charge.
function creditorSummaryTxt($conn, int $spid, string $date_f, string $sp_name, string $fc): string {
    $de=$conn->real_escape_string($date_f);
    $sp = $spid>0 ? "(g.selling_pointid=$spid OR g.selling_pointid=0)" : "1=1";
    // RBZ/stop-order creditors that carry commission + service charge (from stoporder_deductions)
    $q=$conn->query("SELECT sd.creditor_no, COALESCE(gcr.creditor_name,CONCAT('Creditor ',sd.creditor_no)) name,
        SUM(sd.amount_deducted) collected
      FROM stoporder_deductions sd
      JOIN balanced_bales bb ON bb.id=sd.balanced_balesid
      JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
      JOIN growers g ON g.id=bb.growerid
      LEFT JOIN grower_creditors gcr ON gcr.creditor_no=sd.creditor_no
      WHERE $sp AND COALESCE(tg.sell_date,DATE(bb.created_at))='$de'
      GROUP BY sd.creditor_no ORDER BY collected DESC");
    $d=date('d/m/y',strtotime($date_f));
    $rj=fn($s,$w)=>str_pad((string)$s,$w,' ',STR_PAD_LEFT);
    $nc=fn($v)=>number_format((float)$v,2);
    $o ="CURRENCY      : USD\n";
    $o.="SELLING POINT : $sp_name - $fc\n";
    $o.="DATE          : $d - $d\n\n";
    $o.="CREDITOR NO.   CREDITOR NAME                    TOTAL COLLECTED  SO COMMISSION SERVICE CHARGE    BALANCE DUE\n\n";
    $tc=$tso=$tsv=$tbd=0;
    if($q&&$q!==true) while($r=$q->fetch_assoc()){
        $col=(float)$r['collected']; $so=round($col*0.00667,2); $sv=round($col*0.02,2); $bd=$col-$so-$sv;
        $tc+=$col;$tso+=$so;$tsv+=$sv;$tbd+=$bd;
        $o.=str_pad($r['creditor_no'],15).str_pad(substr($r['name'],0,30),33)
           .$rj($nc($col),15).$rj($nc($so),15).$rj($nc($sv),15).$rj($nc($bd),15)."\n";
    }
    $o.=str_repeat('_',108)."\n";
    $o.=str_pad('TOTALS:',48).$rj($nc($tc),15).$rj($nc($tso),15).$rj($nc($tsv),15).$rj($nc($tbd),15)."\n";
    return $o;
}

// Daily Sales Return — two-panel (Today / Year-to-date) summary TXT.
function dailySalesReturnTxt(array $bales, array $bales_ytd, float $zig_rate, string $sp_name, string $date_f): string {
    $stats = function(array $all) {
        $sold = array_filter($all, fn($b)=>!$b['is_rej']);
        $mass = array_sum(array_map(fn($b)=>(float)$b['mass'],$sold));
        $usd  = array_sum(array_map(fn($b)=>(float)$b['usd_value'],$sold));
        $pr   = array_filter(array_map(fn($b)=>(float)$b['price_per_kg'],$sold));
        return ['bales'=>count($sold),'mass'=>$mass,'value'=>$usd,'avg'=>$mass>0?$usd/$mass:0,
                'laid'=>count($all),'rej'=>count($all)-count($sold),
                'hi'=>$pr?max($pr)*100:0,'lo'=>$pr?min($pr)*100:0];
    };
    $t=$stats($bales); $y=$stats($bales_ytd);
    $col = fn($v)=>str_pad((string)$v,17,' ',STR_PAD_LEFT)."  |";
    $bar = str_repeat('-',145)."\n";
    // one data row across both panels (re-sales always 0 here)
    $row = function($label,$tv,$yv,$res) use($col){
        return $col($tv).$col($res).$col($tv)
             . str_pad($label,26,' ',STR_PAD_LEFT).str_pad($yv,16,' ',STR_PAD_LEFT)."  |"
             . $col($res).$col($yv)."\n";
    };
    $nB = fn($v)=>number_format($v);              // bales/mass (commas)
    $nV = fn($v)=>number_format($v,2);            // value/avg

    $o  = "\n\n$sp_name\n\nDAILY SALES RETURN SUMMARY for: ".date('d/m/Y',strtotime($date_f))."\n\n\n\n";
    $o .= "T O D A Y ' S   S A L E                           E X C H A N G E   R A T E   "
        . number_format($zig_rate,4)."                 Y E A R   T O   D A T E\n\n\n";
    $o .= $bar;
    $o .= $col('NEW TOBACCO').$col('RE-SALES').$col('TOTAL')
        . str_pad('',26).str_pad('NEW TOBACCO',16,' ',STR_PAD_LEFT)."  |".$col('RE-SALES').$col('TOTAL')."\n";
    $o .= $bar;
    $o .= $row('1ST TIME   BALES SOLD', $nB($t['bales']), $nB($y['bales']), '0');
    $o .= $row('MASS SOLD',  $nB($t['mass']),  $nB($y['mass']),  '0');
    $o .= $row('VALUE SOLD', $nV($t['value']), $nV($y['value']), '0.00');
    $o .= $row('AVERAGE PRICE', $nV($t['avg']), $nV($y['avg']), '0.00');
    $o .= $bar;
    $o .= $row('BALES ADJ', '0.00','0.00','0');
    $o .= $row('MASS ADJ',  '0.00','0.00','0');
    $o .= $row('VALUE ADJ', '0.00','0.00','0.00');
    $o .= $bar;
    $o .= $row('TOTAL   BALES SOLD', $nB($t['bales']), $nB($y['bales']), '0');
    $o .= $row('MASS SOLD',  $nB($t['mass']),  $nB($y['mass']),  '0');
    $o .= $row('VALUE SOLD', $nV($t['value']), $nV($y['value']), '0.00');
    $o .= $row('AVERAGE PRICE', $nV($t['avg']), $nV($y['avg']), '0.00');
    $o .= $bar."\n\n";
    // bottom counts
    $bc = function($label,$tv,$yv){
        return str_pad($label,18).":".str_pad((string)$tv,21,' ',STR_PAD_LEFT)
             . str_pad('',38).str_pad($label,18).":".str_pad((string)$yv,21,' ',STR_PAD_LEFT)."\n";
    };
    $o .= $bc('BALES SOLD',     $t['bales'], $y['bales']);
    $o .= $bc('BALES REJECTED', $t['rej'],   $y['rej']);
    $o .= $bc('BALES LAID',     $t['laid'],  $y['laid']);
    $o .= "\n\n";
    $o .= $bc('HIGHEST PRICE', (int)round($t['hi']), (int)round($y['hi']));
    $o .= $bc('LOWEST PRICE',  (int)round($t['lo']), (int)round($y['lo']));
    $o .= "\n\n";
    return $o;
}

// Buyer stats / purchases TXT — Daily + Year-to-date sections, per buyer, with levies.
function buyerStatsTxt($conn, array $daily, array $ytd, float $zig_rate, string $sp_name, string $date_f): string {
    $rj = fn($s,$w)=>str_pad(substr((string)$s,0,$w), $w, ' ', STR_PAD_LEFT);
    $lj = fn($s,$w)=>str_pad(substr((string)$s,0,$w), $w);
    $nc = fn($v)=>number_format((float)$v,2);                       // comma + 2dp
    $ni = fn($v,$comma)=>$comma ? number_format((float)$v) : number_format((float)$v,0,'','');

    $rowStr = function($code,$bales,$mass,$pctStr,$us,$zw,$tta,$tob,$flr,$total,$ytd) use($rj,$lj,$nc,$ni) {
        return $lj($code,10).$rj($ni($bales,$ytd),8).$rj($ni($mass,$ytd),10).$rj($pctStr,10)
             .$rj($nc($us),16).$rj($nc($zw),16).$rj($nc($tta),12).$rj($nc($tob),14)
             .$rj($nc($flr),18).$rj($nc($total),20);
    };
    $hdr = "BUYER     BALES         MASS    % MASS        US VALUE       ZiG VALUE     TTA US\$  TOB LEVY US\$  FLR CLEARING US\$           US\$ TOTAL";

    $section = function(array $bales_in, bool $ytd) use ($zig_rate,$rowStr,$hdr,$rj,$nc) {
        $sold = array_filter($bales_in, fn($b)=>!$b['is_rej'] && !empty($b['buyer_id']));
        $by = [];
        foreach ($sold as $b) $by[(int)$b['buyer_id']][] = $b;
        $grand_mass = array_sum(array_map(fn($b)=>(float)$b['mass'], $sold));
        $out = $hdr."\n\n";
        $t = ['bales'=>0,'mass'=>0,'us'=>0,'zw'=>0,'tta'=>0,'tob'=>0,'flr'=>0,'total'=>0];
        foreach ($by as $bb) {
            $bales=count($bb);
            $mass=array_sum(array_map(fn($x)=>(float)$x['mass'],$bb));
            $us  =array_sum(array_map(fn($x)=>(float)$x['usd_value'],$bb));
            $zw  =round($us*$zig_rate,2);
            $tta =round($mass*TTA_LEVY_PER_KG,2);
            $tob =round($us*TOBACCO_LEVY_PCT,2);
            $flr =round($us*STATS_FLR_CLEARING_PCT,2);
            $total=round($us,2)+$tta+$tob+$flr;   // total = sum of displayed components
            $pct =$grand_mass>0 ? $mass/$grand_mass*100 : 0;
            $code=$bb[0]['buyer_code'] ?: ($bb[0]['buyer_name'] ?? '');
            $out .= $rowStr($code,$bales,$mass,number_format($pct,2),$us,$zw,$tta,$tob,$flr,$total,$ytd)."\n\n";
            $t['bales']+=$bales;$t['mass']+=$mass;$t['us']+=$us;$t['zw']+=$zw;
            $t['tta']+=$tta;$t['tob']+=$tob;$t['flr']+=$flr;$t['total']+=$total;
        }
        $totLine = "TOTALS :".$rj(($ytd?number_format($t['bales']):number_format($t['bales'],0,'','')),9)
                 .$rj(($ytd?number_format($t['mass']):number_format($t['mass'],0,'','')),10)
                 .$rj($t['bales']>0?'100':'0',10).$rj($nc($t['us']),16).$rj($nc($t['zw']),16)
                 .$rj($nc($t['tta']),12).$rj($nc($t['tob']),14).$rj($nc($t['flr']),18).$rj($nc($t['total']),20);
        return $out.$totLine."\n";
    };

    $out  = "\n\n$sp_name BUYERS PURCHASES for: ".date('d/m/Y',strtotime($date_f))."\n\n";
    $out .= "D A I L Y   P U R C H A S E S : \n\n";
    $out .= $section($daily, false);
    $out .= "\n\n\n\nY E A R   T O   D A T E   P U R C H A S E S: \n\n";
    $out .= $section($ytd, true);
    $out .= "\n .. \n";
    return $out;
}

function buyerInvoiceTxt($conn, array $bales, float $zig_rate, string $sp_name, string $date_f): string {
    // pad-left numeric string (with commas) and pad-right text helpers
    $pl = fn($s,$w)=>str_pad((string)$s, $w, ' ', STR_PAD_LEFT);
    $pr = fn($s,$w)=>str_pad((string)$s, $w, ' ', STR_PAD_RIGHT);
    $n2 = fn($v)=>number_format((float)$v, 2);

    // paid bales only: sold (has a buyer) and not rejected
    $sold = array_filter($bales, fn($b)=>!$b['is_rej'] && !empty($b['buyer_id']));
    // group by buyer id
    $by_buyer = [];
    foreach ($sold as $b) $by_buyer[(int)($b['buyer_id'] ?? 0)][] = $b;

    $out = '';
    foreach ($by_buyer as $bid => $bb) {
        // buyer name + mark for the header line ("ILT (PA2)")
        $bn = 'Unknown'; $bcode = '';
        $bq = $conn->query("SELECT name, buyer_code FROM buyer WHERE id=".(int)$bid." LIMIT 1");
        if ($bq && $bq !== true && $bq->num_rows) { $r=$bq->fetch_assoc(); $bn=$r['name']; $bcode=$r['buyer_code']; }
        $buyer_label = $bn . ($bcode ? " ($bcode)" : '');

        $out .= "\n\n\n\n";
        $out .= "$sp_name BUYERS GRADE INVOICE for: $date_f 00:00:00\n\n";
        $out .= "BUYER: $buyer_label\n\n\n\n\n";

        // group by grade, alphabetical
        $by_grade = [];
        foreach ($bb as $b) $by_grade[$b['buyer_grade'] ?: '?'][] = $b;
        ksort($by_grade);

        $hdr = "GRADE   GROWER NO      LOTNO    MASS  ZW PRICE  US PRICE      ZW VAL      US VAL     BARCODE\n";
        $g_bales=$g_mass=0; $g_us=$g_zw=0.0; $summary=[];

        foreach ($by_grade as $grd => $rows) {
            $out .= $hdr;
            $gm=0; $gu=$gz=0.0;
            foreach ($rows as $b) {
                $ppk   = (float)$b['price_per_kg'];
                $mass  = (float)$b['mass'];
                $uscts = (int)round($ppk*100);          // US PRICE column = cents
                $zwp   = $ppk*$zig_rate;                 // ZW price/kg
                $usval = $ppk*$mass;                     // US value
                $zwval = $usval*$zig_rate;               // ZW value
                $gm+=$mass; $gu+=$usval; $gz+=$zwval;
                $out .= $pr($grd,10).$pr($b['grower_num'],10).$pl((int)$b['lot'],10)
                      . $pl((int)round($mass),8).$pl($n2($zwp),10).$pl($uscts,10)
                      . $pl($n2($zwval),12).$pl($n2($usval),12)."  ".$b['barcode']."\n";
            }
            $out .= "\nGRADE TOTALS:  ".$pr(count($rows),2).$pl((int)round($gm),19)
                  . $pl($n2($gz),22).$pl($n2($gu),12)."\n\n";
            $g_bales+=count($rows); $g_mass+=$gm; $g_us+=$gu; $g_zw+=$gz;
            $summary[$grd]=['n'=>count($rows),'mass'=>$gm,'us'=>$gu,'zw'=>$gz];
        }

        // grand totals
        $out .= "GRAND TOTALS:\n";
        $out .= "                     BALES                MASS            US VALUE            ZW VALUE\n";
        $out .= $pl($g_bales,26).$pl((int)round($g_mass),20).$pl($n2($g_us),20).$pl($n2($g_zw),20)."\n\n\n\n\n";

        // grade summary
        $out .= "GRADE SUMMARY : \n\n";
        $out .= "GRADE               BALES     TOTAL MASS      TOTAL US VALUE      TOTAL ZW VALUE\n";
        foreach ($summary as $grd=>$s) {
            $out .= $pr($grd,20).$pl($s['n'],5).$pl((int)round($s['mass']),15)
                  . $pl($n2($s['us']),20).$pl($n2($s['zw']),20)."\n";
        }
        $out .= "\nTOTALS".$pl($g_bales,19).$pl((int)round($g_mass),15)
              . $pl($n2($g_us),20).$pl($n2($g_zw),20)."\n\n\n";

        // adjustments (none captured yet)
        $out .= "ADJUSTMENTS - CREDITS: \n\n\n\n";
        $out .= "        TOTALS                     0                            0.00        0.00            \n\n";
        $out .= "ADJUSTMENTS - UNCHARGED: \n\n\n";
        $out .= "        TOTALS                     0                            0.00        0.00            \n\n";

        // A/C summary
        $tob = $g_us * TOBACCO_LEVY_PCT;
        $tta = $g_mass * TTA_LEVY_PER_KG;
        $flr = $g_bales * FLOOR_CLEARING_PER_BALE;
        $levies = $tob + $tta + $flr;
        $nostro_usd = $g_us * NOSTRO_SPLIT_PCT;
        $rtgs_usd   = $g_us - $nostro_usd;
        $rtgs_zw    = $rtgs_usd * $zig_rate;
        $nostro_due = $nostro_usd + $levies;

        $L = fn($label,$usd,$rtgs)=>$pr($label,30)."US\$ ".$pl($n2($usd),17)."          RTGS\$ ".$pl($n2($rtgs),16)."\n";
        $bar = str_repeat('-',110)."\n";
        $out .= "A/C SUMMARY                EXCHANGE RATE:        ".$pl(number_format($zig_rate,4),9)."\n";
        $out .= $bar;
        $out .= $L('TOTAL PURCHASES', $g_us, $g_zw);
        $out .= $L('ADJUSTMENTS', 0, 0);
        $out .= $bar;
        $out .= $L('NET PURCHASES', $g_us, $g_zw);
        $out .= $L('NOSTRO / RTGS SPLIT', $nostro_usd, $rtgs_zw);
        $out .= $L('TOBACCO LEVY', $tob, 0);
        $out .= $L('TTA LEVY', $tta, 0);
        $out .= $L('FLOOR CLEARING', $flr, 0);
        $out .= $L('TOTAL LEVIES ETC', $levies, 0);
        $out .= $bar;
        $out .= $L('NOSTRO / RTGS BALANCE DUE', $nostro_due, $rtgs_zw);
        $out .= "\n".$bar."\n";
        $out .= "NOSTRO AMOUNT DUE:        ".$pl($n2($nostro_due),16)."\n\n";
        $out .= "RTGS AMOUNT DUE:          ".$pl($n2($rtgs_zw),16)."\n\n\n\n\n \n";
    }
    return $out;
}

// ── Report header helper ───────────────────────────────────────────────────
// Reports that support CSV download
$CSV_REPORTS = ['buyer_disk','buyer_invoice','buyer_ytd','buyer_stats','daily_sales_ret',
    'cred_payment','cred_invoice','cred_ytd','cred_summary','cred_collection',
    'rejected_summary','revenue_report','floor_recon','daily_floor_recon','sales_control',
    'invoice_control','purchase_summary','origination','grower_sched_usd','grower_sched_zig',
    'bank_summary','grower_sales_sum','extract_bales','extract_bank'];

function rpt_head($title, $subtitle='', $print_btn=true) {
    global $date_f, $sp_name, $buyer_f, $buyer_mark_f, $loc_f, $zig_rate, $report, $CSV_REPORTS;
    $filters = [];
    if ($buyer_f) $filters[] = 'buyer filtered';
    if ($buyer_mark_f) $filters[] = 'buyer mark filtered';
    if ($loc_f)   $filters[] = 'location filtered';
    $fs = $filters ? ' · '.implode(', ',$filters) : '';
    $base_qs = "date=".urlencode($date_f)."&report=".urlencode($report)
             .($buyer_f?"&buyer=$buyer_f":"")
             .($buyer_mark_f?"&buyer_mark=".urlencode($buyer_mark_f):"")
             .($loc_f?"&loc=$loc_f":"");
    $print_url = "?$base_qs&print=1";
    $dl_url    = "?$base_qs&dl=1";
    $has_csv   = in_array($report, $CSV_REPORTS);
    echo '<div class="rpt-section">';
    echo '<div class="rpt-head">';
    echo "<div><h3>$title</h3>";
    echo "<div class='rpt-meta'>$sp_name &nbsp;·&nbsp; ".date('d/m/Y',strtotime($date_f))."$fs";
    if ($subtitle) echo " &nbsp;·&nbsp; <em>$subtitle</em>";
    echo "</div></div>";
    echo "<div style='display:flex;gap:.35rem;flex-shrink:0'>";
    if ($has_csv) echo "<a href='$dl_url' class='btn btn-ghost btn-sm' title='Download CSV'>⬇ CSV</a>";
    if ($print_btn) echo "<a href='$print_url' target='_blank' class='btn btn-ghost btn-sm'>🖨 Print</a>";
    echo "</div></div>";
}
function rpt_foot() { echo '</div>'; }

// ── Print handler ──────────────────────────────────────────────────────────
if (isset($_GET['print']) && $report) {
    $bales = load_bales($conn,$date_f,$spid,$buyer_f,$loc_f, str_ends_with($report,'_ytd'));
    $sold  = array_filter($bales, fn($b)=>!$b['is_rej']);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'.htmlspecialchars($report).' — '.htmlspecialchars($date_f).'</title>';
    echo '<style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:"Segoe UI",sans-serif;font-size:10px;color:#000;padding:10mm}
    h1{font-size:14px;font-weight:700;margin-bottom:2px}
    h2{font-size:9px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;margin:12px 0 4px;border-bottom:1px solid #ccc;padding-bottom:2px;color:#444}
    .hdr{display:flex;justify-content:space-between;margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid #000}
    table{width:100%;border-collapse:collapse;font-size:9px;margin-bottom:12px}
    th{background:#1a1208;color:#fff;padding:4px 6px;font-size:8px;letter-spacing:.05em;text-transform:uppercase;text-align:left}
    td{padding:3px 6px;border-bottom:1px solid #e8e8e8}
    tr:nth-child(even) td{background:#f8f8f8}
    .tr{text-align:right}.bold{font-weight:700}
    tfoot td{font-weight:700;border-top:2px solid #000;background:#f0f0f0}
    @media print{body{padding:6mm}}
    </style></head><body>';
    echo '<div class="hdr"><div><h1>'.htmlspecialchars(str_replace('_',' ',strtoupper($report))).'</h1>';
    echo '<div style="font-size:9px;color:#555">'.htmlspecialchars($sp_name).'</div></div>';
    echo '<div style="text-align:right;font-size:9px"><div>'.date('d F Y',strtotime($date_f)).'</div>';
    echo '<div>Generated: '.date('d M Y H:i').'</div></div></div>';
    // Render bale table
    echo '<h2>Bales Detail ('.count($sold).' sold, '.(count($bales)-count($sold)).' rejected)</h2>';
    echo '<table><thead><tr><th>Grower #</th><th>Name</th><th>Grade</th><th>Buyer</th><th>Loc</th><th class="tr">Mass kg</th><th class="tr">Price $/kg</th><th class="tr">Value USD</th></tr></thead><tbody>';
    foreach ($sold as $b) {
        echo '<tr><td class="bold">'.htmlspecialchars($b['grower_num']).'</td>';
        echo '<td>'.htmlspecialchars($b['gname'].' '.$b['surname']).'</td>';
        echo '<td>'.htmlspecialchars($b['timb_grade']??'').'</td>';
        echo '<td>'.htmlspecialchars($b['buyer_name']??'').'</td>';
        echo '<td>'.htmlspecialchars($b['loc_code']??'').'</td>';
        echo '<td class="tr">'.number_format($b['mass'],1).'</td>';
        echo '<td class="tr">$'.number_format($b['price_per_kg'],4).'</td>';
        echo '<td class="tr bold">$'.number_format($b['usd_value'],2).'</td></tr>';
    }
    $tm=sum_col($sold,'mass'); $tv=sum_col($sold,'usd_value');
    echo '<tr style="background:#1a1208;color:#fff"><td colspan="5" class="bold">TOTAL — '.count($sold).' bales</td>';
    echo '<td class="tr bold">'.number_format($tm,1).'</td><td></td><td class="tr bold">$'.number_format($tv,2).'</td></tr>';
    echo '</tbody></table>';
    echo '<script>window.onload=()=>window.print()</script>';
    echo '</body></html>';
    exit;
}

// ── CSV download handler ───────────────────────────────────────────────────
if (isset($_GET['dl']) && $report) {
    $bales = load_bales($conn,$date_f,$spid,$buyer_f,$loc_f, str_ends_with($report,'_ytd'));
    if ($report==='daily_sales_ret') {
        $raw_sp = $_SESSION['selling_point_name'] ?? 'Flowork Floor';
        $bales_ytd = load_bales($conn,$date_f,$spid,$buyer_f,$loc_f,true);
        header('Content-Type: text/plain; charset=utf-8');
        $fn = strtoupper(preg_replace('/[^A-Za-z0-9]/','', $raw_sp)).'_DAILY_SALES_RETURN'.date('md',strtotime($date_f)).'.txt';
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        echo dailySalesReturnTxt($bales, $bales_ytd, (float)$zig_rate, $raw_sp, $date_f);
        exit;
    }
    if ($report==='cred_invoice') {
        $raw_sp = $_SESSION['selling_point_name'] ?? 'Flowork Floor';
        $md = date('md',strtotime($date_f));
        header('Content-Type: text/plain; charset=utf-8');
        $fn = strtoupper(preg_replace('/[^A-Za-z0-9]/','', $raw_sp))."_CREDITOR_INVOICE_{$md}_{$md}.txt";
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        echo creditorInvoiceTxt($conn, $spid, $date_f, $raw_sp);
        exit;
    }
    if ($report==='sales_control') {
        $raw_sp=$_SESSION['selling_point_name'] ?? 'Flowork Floor';
        $de=$conn->real_escape_string($date_f); $dDay="%DATE%='$de'"; $dYtd="%DATE%<='$de'";
        $sD=eod_sales_stats($conn,$spid,$dDay); $sY=eod_sales_stats($conn,$spid,$dYtd);
        $lvD=eod_levies($conn,$spid,$dDay);     $lvY=eod_levies($conn,$spid,$dYtd);
        $soD=eod_stoporders($conn,$spid,$dDay); $soY=eod_stoporders($conn,$spid,$dYtd);
        $d=date('Y-m-d',strtotime($date_f));
        $R=[];
        $R[]=["Sales Control Report - $raw_sp",'','','',"Sales Control Report - $raw_sp",'',''];
        $R[]=["Daily Report","  $raw_sp",'','',"Year To Date","  $raw_sp",''];
        $R[]=["Sale Date",$d,'','',"Sale Date",$d,''];
        $R[]=["Exchange Rate",(float)$zig_rate,'','',"Exchange Rate","N/A",''];
        $R[]=["Total Bales Laid",(int)$sD['laid'],'','',"Total Bales Laid",(int)$sY['laid'],''];
        $R[]=["Total Bales Sold",(int)$sD['sold'],'','',"Total Bales Sold",(int)$sY['sold'],''];
        $R[]=["Gross Value",(float)$sD['gross'],'','',"Gross Value",(float)$sY['gross'],''];
        $R[]=["Total Sold Mass",(float)$sD['mass'],'','',"Total Sold Mass",(float)$sY['mass'],''];
        $R[]=["Description","USD","ZWL",'',"Description","USD","ZWL"];
        $R[]=["Statutory Deductions",'','','',"Statutory Deductions",'',''];
        foreach([['Floor Commission','Floor Commission'],['Weighing and Auction','Weighing'],['MOA Levy','MOA'],['Afforestation Levy','Afforestation'],['Service Charge Growers','Service Charge']] as [$lbl,$ndl]){
            $a=eod_levy($lvD,$ndl); $b=eod_levy($lvY,$ndl);
            $R[]=[$lbl,$a['usd'],$a['zwl'],'',$lbl,$b['usd'],$b['zwl']];
        }
        $R[]=["Stop Order and Invoice Deductions",'','','',"Stop Order and Invoice Deductions",'',''];
        $R[]=["Stop Order Commission",0,0,'',"Stop Order Commission",0,0];
        $R[]=[$raw_sp,(float)($soD[$raw_sp]??0),0,'',$raw_sp,(float)($soY[$raw_sp]??0),0];
        $R[]=["SERVICE CHARGE",0,0,'',"SERVICE CHARGE",0,0];
        foreach($soY as $name=>$amt){ if($name===$raw_sp) continue; $R[]=[$name,(float)($soD[$name]??0),0,'',$name,(float)$amt,0]; }
        xlsx_send(strtoupper(preg_replace('/[^A-Za-z0-9]+/','_',$raw_sp))."_Sales_Control_$d.xlsx", ['Sales Control'=>$R]);
    }
    if ($report==='floor_recon' || $report==='daily_floor_recon') {
        $raw_sp=$_SESSION['selling_point_name'] ?? 'Flowork Floor';
        $hdr=['Date','Bales Laid','Bales Sold','Mass Sold','Gross Value','Net Amount','Floor Comm','W/A','AFF Levy','MOA Levy','Stop Orders','SO Comm','Service Charge','Recon'];
        $R=[["Floor Reconciliation Report - $raw_sp",'','','','','','','','','','','','',''],
            ["Sale Point: $raw_sp",'','','','','','','','','','','','',''], $hdr];
        $dates = ($report==='daily_floor_recon') ? [$date_f] : eod_distinct_dates($conn,$spid,$date_f);
        $tot=array_fill(0,13,0.0);
        foreach($dates as $dd){
            $de=$conn->real_escape_string($dd); $dc="%DATE%='$de'";
            $s=eod_sales_stats($conn,$spid,$dc); $net=eod_net($conn,$spid,$dc); $lv=eod_levies($conn,$spid,$dc);
            $fc=eod_levy($lv,'Floor Commission'); $wa=eod_levy($lv,'Weighing');
            $aff=eod_levy($lv,'Afforestation'); $moa=eod_levy($lv,'MOA'); $svc=eod_levy($lv,'Service Charge');
            $floorComm=$fc['usd']; $wA=$wa['usd']; $affL=$aff['usd']+$aff['zwl']; $moaL=$moa['usd']+$moa['zwl'];
            $svcL=$svc['usd']; $so=array_sum(eod_stoporders($conn,$spid,$dc)); $soc=0;
            $gross=(float)$s['gross'];
            $recon=$gross-$net-($floorComm+$wA+$affL+$moaL+$so+$soc+$svcL);
            $vals=[(int)$s['laid'],(int)$s['sold'],(float)$s['mass'],$gross,$net,$floorComm,$wA,$affL,$moaL,$so,$soc,$svcL,$recon];
            $R[]=array_merge([$dd],$vals);
            foreach($vals as $i=>$v) $tot[$i]+=$v;
        }
        $R[]=array_merge(['TOTALS'],array_map(fn($i)=>$tot[$i], range(0,12)));
        xlsx_send(strtoupper(preg_replace('/[^A-Za-z0-9]+/','_',$raw_sp)).($report==='daily_floor_recon'?"_Daily_Floor_Recon_":"_Floor_Recon_").date('Y-m-d',strtotime($date_f)).".xlsx", ['Floor Reconciliation'=>$R]);
    }
    if ($report==='revenue_report') {
        $raw_sp=$_SESSION['selling_point_name'] ?? 'Flowork Floor';
        $from = $conn->real_escape_string($_GET['from'] ?? $date_f);
        $to   = $conn->real_escape_string($_GET['to']   ?? $date_f);
        $hdr=['Date','Bales Laid','Bales Sold','Mass Sold','Gross Value (USD)','Net Cheque','Floor Commission','W/A','Floor Clearing','Grower Svc Charge','Creditor Svc Charge','Total Revenue'];
        $R=[["$raw_sp - Detailed Revenue (USD)   ".date('d M Y',strtotime($from))." to ".date('d M Y',strtotime($to)),'','','','','','','','','','',''], $hdr];
        $dates=array_values(array_filter(eod_distinct_dates($conn,$spid,$to), fn($d)=>$d>=$from));
        $tot=array_fill(0,11,0.0);
        foreach($dates as $dd){
            $de=$conn->real_escape_string($dd); $dc="%DATE%='$de'";
            $s=eod_sales_stats($conn,$spid,$dc); $net=eod_net($conn,$spid,$dc); $lv=eod_levies($conn,$spid,$dc);
            $fc=eod_levy($lv,'Floor Commission')['usd']; $wa=eod_levy($lv,'Weighing')['usd'];
            $svc=eod_levy($lv,'Service Charge')['usd'];
            $flrClear=(int)$s['sold']*FLOOR_CLEARING_PER_BALE;
            $credSvc=$svc*0.6610;   // creditor service-charge portion (approx — confirm rate)
            $totRev=$fc+$wa+$flrClear+$svc+$credSvc;
            $vals=[(int)$s['laid'],(int)$s['sold'],(float)$s['mass'],(float)$s['gross'],$net,$fc,$wa,$flrClear,$svc,$credSvc,$totRev];
            $R[]=array_merge([$dd],$vals);
            foreach($vals as $i=>$v) $tot[$i]+=$v;
        }
        $R[]=array_merge(['TOTALS'],array_map(fn($i)=>$tot[$i], range(0,10)));
        xlsx_send(strtoupper(preg_replace('/[^A-Za-z0-9]+/','_',$raw_sp))."_Revenue_".date('Ymd',strtotime($from))."_to_".date('Ymd',strtotime($to)).".xlsx", ['USD Revenue'=>$R]);
    }
    if (in_array($report,['purchase_summary_x','origination','invoice_control','grower_sched_usd','grower_sched_zig','bank_summary','grower_sales_sum'],true)) {
        $raw_sp=$_SESSION['selling_point_name'] ?? 'Flowork Floor';
        $is_ytd=str_ends_with($report,'_ytd');
        $bx=load_bales($conn,$date_f,$spid,($report==='invoice_control'?$buyer_f:0),$loc_f,$is_ytd);
        $soldx=array_filter($bx,fn($b)=>!$b['is_rej']);
        $fname=strtoupper(preg_replace('/[^A-Za-z0-9]+/','_',$raw_sp))."_".strtoupper($report)."_".date('Ymd',strtotime($date_f)).".xlsx";
        if ($report==='origination'){
            $R=[['Grower #','First Name','Surname','Laid','Rejected','Sold','Mass','Gross USD','Hi $/kg','Lo $/kg','Avg $/kg','Area','District','Province']];
            foreach(grp($soldx,'grower_num') as $gn=>$gb){ $r0=$gb[0];
                $prices=array_filter(array_column($gb,'price_per_kg')); $rej=count(array_filter($gb,fn($b)=>$b['is_rej']));
                $R[]=[$gn,$r0['gname'],$r0['surname'],count($gb)+$rej,$rej,count($gb),(float)sum_col($gb,'mass'),(float)sum_col($gb,'usd_value'),
                      $prices?max($prices):0,$prices?min($prices):0,$prices?array_sum($prices)/count($prices):0,
                      $r0['area']??'',$r0['district']??'',$r0['province']??''];
            }
            xlsx_send($fname,['Origination'=>$R]);
        }
        if ($report==='invoice_control'){
            $R=[['Buyer','Bales','Mass','Gross USD','Gross ZiG']];
            foreach(grp($soldx,'buyer_name') as $bn=>$bb){
                $R[]=[$bn,count($bb),(float)sum_col($bb,'mass'),(float)sum_col($bb,'usd_value'),(float)sum_col($bb,'zig_value')];
            }
            xlsx_send($fname,['Invoice Control'=>$R]);
        }
        if ($report==='grower_sched_usd'||$report==='grower_sched_zig'){
            $zig=$report==='grower_sched_zig';
            $R=[['Grower #','Name','Bales','Mass','Gross '.($zig?'ZiG':'USD'),'Deductions','Net '.($zig?'ZiG':'USD')]];
            foreach(grp($soldx,'grower_num') as $gn=>$gb){ $r0=$gb[0]; $gid=(int)$r0['grower_id'];
                $gross=$zig?sum_col($gb,'zig_value'):sum_col($gb,'usd_value');
                $dq=$conn->query("SELECT (SELECT COALESCE(SUM(amount_1+amount_2+amount_3),0) FROM stoporders WHERE growerid=$gid AND active=1) + (SELECT COALESCE(SUM(amount),0) FROM internal_stoporders WHERE growerid=$gid AND active=1) t");
                $ded=($dq&&$dq!==true)?(float)$dq->fetch_assoc()['t']:0; if($zig)$ded*=$zig_rate;
                $R[]=[$gn,trim($r0['gname'].' '.$r0['surname']),count($gb),(float)sum_col($gb,'mass'),(float)$gross,$ded,(float)$gross-$ded];
            }
            xlsx_send($fname,[($zig?'Grower Schedule ZiG':'Grower Schedule USD')=>$R]);
        }
        if ($report==='bank_summary'){
            $R=[['Grower #','Name','Bank','Branch','Account #','Net USD']];
            foreach(grp($soldx,'grower_num') as $gn=>$gb){ $r0=$gb[0]; $gid=(int)$r0['grower_id'];
                $bk=$conn->query("SELECT bank_name,branch,account_num FROM grower_banking WHERE growerid=$gid LIMIT 1");
                $bk=($bk&&$bk!==true&&$bk->num_rows)?$bk->fetch_assoc():[];
                $R[]=[$gn,trim($r0['gname'].' '.$r0['surname']),$bk['bank_name']??'',$bk['branch']??'',$bk['account_num']??'',(float)sum_col($gb,'usd_value')];
            }
            xlsx_send($fname,['Bank Summary'=>$R]);
        }
        if ($report==='grower_sales_sum'){
            $R=[['Grower #','Name','Bales','Mass','Gross USD','Avg $/kg']];
            foreach(grp($soldx,'grower_num') as $gn=>$gb){ $r0=$gb[0]; $pr=array_filter(array_column($gb,'price_per_kg'));
                $R[]=[$gn,trim($r0['gname'].' '.$r0['surname']),count($gb),(float)sum_col($gb,'mass'),(float)sum_col($gb,'usd_value'),$pr?array_sum($pr)/count($pr):0];
            }
            xlsx_send($fname,['Grower Sales Summary'=>$R]);
        }
    }
    if ($report==='purchase_summary') {
        $raw_sp=$_SESSION['selling_point_name'] ?? 'Flowork Floor';
        $R=[['Sale Date','Bales Laid','Bales Rejected','Bales Sold','Mass Sold','USD Value','Hi $/kg','Lo $/kg','Avg $/kg','Exchange Rate']];
        foreach(eod_distinct_dates($conn,$spid,$date_f) as $dd){ $de=$conn->real_escape_string($dd); $dc="%DATE%='$de'";
            $s=eod_sales_stats($conn,$spid,$dc);
            $pq=$conn->query("SELECT MAX(p) hi,MIN(NULLIF(p,0)) lo,AVG(NULLIF(p,0)) avg FROM (SELECT (CASE WHEN CAST(sb.price AS DECIMAL(12,4))>10 THEN CAST(sb.price AS DECIMAL(12,4))/100 ELSE CAST(sb.price AS DECIMAL(12,4)) END) p FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN transporter_growers tg ON tg.id=bj.transporter_growersid JOIN growers g ON g.id=bj.growerid WHERE (g.selling_pointid=$spid OR $spid=0) AND COALESCE(tg.sell_date,DATE(tk.created_at))='$de') x");
            $p=($pq&&$pq!==true)?$pq->fetch_assoc():['hi'=>0,'lo'=>0,'avg'=>0];
            $R[]=[$dd,(int)$s['laid'],(int)$s['laid']-(int)$s['sold'],(int)$s['sold'],(float)$s['mass'],(float)$s['gross'],(float)$p['hi'],(float)$p['lo'],(float)$p['avg'],(float)$zig_rate];
        }
        xlsx_send(strtoupper(preg_replace('/[^A-Za-z0-9]+/','_',$raw_sp))."_PURCHASE_SUMMARY_".date('Ymd',strtotime($date_f)).".xlsx",['Purchase Summary'=>$R]);
    }
    if ($report==='cred_summary') {
        $raw_sp=$_SESSION['selling_point_name'] ?? 'Flowork Floor';
        $fc='VL'; $fcq=$conn->query("SELECT floor_code FROM selling_point WHERE id=$spid LIMIT 1");
        if($fcq&&$fcq!==true&&$fcq->num_rows){$r=$fcq->fetch_assoc(); if(!empty($r['floor_code']))$fc=$r['floor_code'];}
        header('Content-Type: text/plain; charset=utf-8');
        $md=date('dmy',strtotime($date_f));
        header('Content-Disposition: attachment; filename="'.strtoupper(preg_replace('/[^A-Za-z0-9]/','',$raw_sp)).'_USD_CREDITOR_INVOICE_SUMMARY_'.date('md',strtotime($date_f)).date('md',strtotime($date_f)).'.txt"');
        echo creditorSummaryTxt($conn,$spid,$date_f,$raw_sp,$fc);
        exit;
    }
    if ($report==='rejected_summary') {
        $raw_sp=$_SESSION['selling_point_name'] ?? 'Flowork Floor';
        $de=$conn->real_escape_string($date_f);
        $rejQ=function($dc) use($conn,$spid){
            $sp=$spid>0?"(g.selling_pointid=$spid OR g.selling_pointid=0)":"1=1";
            $q=$conn->query("SELECT UPPER(TRIM(sb.sale_code)) reason, COUNT(*) cnt, COALESCE(SUM(bm.mass),0) mass
              FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid
              JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN bale_mass bm ON bm.id=bj.bale_massid
              JOIN transporter_growers tg ON tg.id=bj.transporter_growersid JOIN growers g ON g.id=bj.growerid
              WHERE $sp AND TRIM(sb.sale_code)<>'' AND CHAR_LENGTH(TRIM(sb.sale_code))<=3
                AND UPPER(TRIM(sb.sale_code)) REGEXP '^[A-Z]' AND $dc
              GROUP BY reason ORDER BY reason");
            $rows=[]; $tc=0;$tm=0;
            if($q&&$q!==true) while($r=$q->fetch_assoc()){ $rows[]=[$r['reason'],(int)$r['cnt'],number_format($r['mass'],0)]; $tc+=(int)$r['cnt']; $tm+=(float)$r['mass']; }
            return [$rows,$tc,$tm];
        };
        [$rT,$tcT,$tmT]=$rejQ("COALESCE(tg.sell_date,DATE(tk.created_at))='$de'");
        [$rY,$tcY,$tmY]=$rejQ("COALESCE(tg.sell_date,DATE(tk.created_at))<='$de'");
        $cols=[['TIMB Reason',40,'L'],['Bale Count',320,'R'],['Mass (kg)',460,'R']];
        $secs=[
          ['heading'=>"Today — ".date('d/m/Y',strtotime($date_f)),'cols'=>$cols,'rows'=>$rT,'totals'=>['Totals',$tcT,number_format($tmT,0)]],
          ['heading'=>"Year To Date",'cols'=>$cols,'rows'=>$rY,'totals'=>['Totals',$tcY,number_format($tmY,0)]],
        ];
        pdf_table_send(strtoupper(preg_replace('/[^A-Za-z0-9]+/','_',$raw_sp))."_Rejected_Bales_".date('Y-m-d',strtotime($date_f)).".pdf",
            "Rejected Bales Summary", ["$raw_sp", "Date: ".date('d/m/Y',strtotime($date_f))], $secs);
    }
    if ($report==='cred_collection' && isset($_GET['charge'])) {
        $raw_sp=$_SESSION['selling_point_name'] ?? 'Flowork Floor';
        $gcid=(int)$_GET['charge']; $de=$conn->real_escape_string($date_f);
        $sp=$spid>0?"(g.selling_pointid=$spid OR g.selling_pointid=0)":"1=1";
        $lq=$conn->query("SELECT s.description levy FROM grower_charges gc LEFT JOIN statutory s ON s.id=gc.statutoryid WHERE gc.id=$gcid LIMIT 1");
        $levy=($lq&&$lq!==true&&$lq->num_rows)?$lq->fetch_assoc()['levy']:"Charge $gcid";
        $q=$conn->query("SELECT g.grower_num, TRIM(CONCAT(g.name,' ',g.surname)) nm, SUM(bbc.usd_value) usd, SUM(bbc.zim_value) zwl
          FROM balanced_bales_charges bbc
          JOIN balanced_bales bb ON bb.id=bbc.balanced_balesid
          JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
          JOIN growers g ON g.id=bb.growerid
          WHERE bbc.grower_chargeid=$gcid AND $sp AND COALESCE(tg.sell_date,DATE(bb.created_at))='$de'
          GROUP BY g.id ORDER BY g.grower_num");
        $rows=[]; $tu=0;$tz=0;
        if($q&&$q!==true) while($r=$q->fetch_assoc()){ $rows[]=[$r['grower_num'],$r['nm'],number_format($r['usd'],2),number_format($r['zwl'],2)]; $tu+=(float)$r['usd']; $tz+=(float)$r['zwl']; }
        $cols=[['Grower No.',40,'L'],['Grower Name',130,'L'],['US$',430,'R'],['RTGS$',520,'R']];
        pdf_table_send(strtoupper(preg_replace('/[^A-Za-z0-9]+/','_',$levy))."_Collection_".date('Y-m-d',strtotime($date_f)).".pdf",
            "Collection Advice — $levy", ["$raw_sp", "Date: ".date('d/m/Y',strtotime($date_f))],
            [['heading'=>'','cols'=>$cols,'rows'=>$rows,'totals'=>['TOTAL','',number_format($tu,2),number_format($tz,2)]]]);
    }
    if ($report==='cred_ytd') {
        // floor code for the SALE POINT column + filename
        $fc='VL'; $raw_sp=$_SESSION['selling_point_name'] ?? 'Flowork Floor';
        $fcq=$conn->query("SELECT floor_code FROM selling_point WHERE id=$spid LIMIT 1");
        if ($fcq && $fcq!==true && $fcq->num_rows) { $r=$fcq->fetch_assoc(); if(!empty($r['floor_code'])) $fc=$r['floor_code']; }
        header('Content-Type: text/csv; charset=utf-8');
        $fn = strtoupper(preg_replace('/[^A-Za-z0-9]/','', $raw_sp)).'_YTD_CREDITOR_INVOICE_'.date('md',strtotime($date_f)).'.csv';
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        echo creditorYtdCsv($conn, $spid, $date_f, $fc);
        exit;
    }
    if ($report==='extract_bales') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="'.str_replace([' ','/'],['_',''],$report).'_'.str_replace('-','',$date_f).'.csv"');
        echo "Sale Date,Barcode,TIMB BC,Grower #,Name,Farm,Province,TIMB Grade,Buyer Grade,Buyer,Location,Mass kg,Price USD/kg,Value USD,Value ZiG\r\n";
        foreach ($bales as $b) {
            if ($b['is_rej']&&$report!=='extract_bales') continue;
            echo implode(',', [date('d/m/Y',strtotime($b['sell_date'])), $b['barcode'],
                $b['timb_bc'],$b['grower_num'],
                '"'.$b['gname'].' '.$b['surname'].'"','"'.($b['farm_name']??'').'"',
                '"'.($b['province']??'').'"',$b['timb_grade']??'',$b['buyer_grade']??'',
                '"'.($b['buyer_name']??'').'"',$b['loc_code']??'',
                $b['mass'],money($b['price_per_kg'],4),money($b['usd_value']),
                $zig_rate?money($b['zig_value']):'',
            ])."\r\n";
        }
        exit;
    }
    if ($report==='extract_bank') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="bank_details_'.str_replace('-','',$date_f).'.csv"');
        echo "Grower #,Name,Surname,Bank,Branch,Account #,Account Name,SWIFT,Verified\r\n";
        $gids = array_unique(array_column($bales,'grower_id'));
        foreach ($gids as $gid) {
            $g_qr = $conn->query("SELECT grower_num,name,surname FROM growers WHERE id=$gid LIMIT 1"); $g = ($g_qr && $g_qr !== true) ? $g_qr->fetch_assoc() : [];
            $bk_qr = $conn->query("SELECT * FROM grower_banking WHERE growerid=$gid LIMIT 1"); $bk = ($bk_qr && $bk_qr !== true) ? $bk_qr->fetch_assoc() : [];
            echo implode(',', [$g['grower_num']??'',$g['name']??'',$g['surname']??'',
                '"'.($bk['bank_name']??'').'"','"'.($bk['branch']??'').'"',
                '"'.($bk['account_num']??'').'"','"'.($bk['account_name']??'').'"',
                $bk['swift_code']??'',$bk['verified']??0,
            ])."\r\n";
        }
        exit;
    }
    if ($report==='buyer_invoice' || $report==='buyer_ytd') {
        // Official fixed-width buyer grade invoice (.txt)
        $raw_sp = $_SESSION['selling_point_name'] ?? 'Flowork Floor';
        header('Content-Type: text/plain; charset=utf-8');
        $fn = strtoupper(preg_replace('/[^A-Za-z0-9]/','', $raw_sp)).($report==='buyer_ytd'?'_BUYER_YTD_INVOICE_':'_BUYER_INVOICE_').date('md',strtotime($date_f)).'.txt';
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        echo buyerInvoiceTxt($conn, $bales, (float)$zig_rate, $raw_sp, $date_f);
        exit;
    }
    if ($report==='buyer_disk') {
        // Electronic buyer disk file (.v) — buyer code in the name
        $bcode = 'ALL';
        if ($buyer_f !== '') {
            if (is_numeric($buyer_f)) {
                $bq=$conn->query("SELECT buyer_code,name FROM buyer WHERE id=$buyer_f LIMIT 1");
            } else {
                $bq=$conn->query("SELECT buyer_code,name FROM buyer WHERE name='$buyer_f' LIMIT 1");
            }
            if ($bq && $bq!==true && $bq->num_rows){ $row=$bq->fetch_assoc(); $bcode=$row['buyer_code']?:$row['name']; }
        } else {
            foreach ($bales as $b) { if (!empty($b['buyer_code'])) { $bcode=$b['buyer_code']; break; } }
        }
        header('Content-Type: text/plain; charset=utf-8');
        $fn = strtoupper(preg_replace('/[^A-Za-z0-9]/','', $bcode)).'_BUYER_FILE_'.date('md',strtotime($date_f)).'.v';
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        echo buyerDiskFile($conn, $bales, (float)$zig_rate);
        exit;
    }
    if ($report==='buyer_stats') {
        // Buyer purchases stats (.txt) — Daily + Year-to-date
        $raw_sp = $_SESSION['selling_point_name'] ?? 'Flowork Floor';
        $bales_ytd = load_bales($conn,$date_f,$spid,$buyer_f,$loc_f,true);
        header('Content-Type: text/plain; charset=utf-8');
        $fn = strtoupper(preg_replace('/[^A-Za-z0-9]/','', $raw_sp)).'_BUYER_STATS_'.date('md',strtotime($date_f)).'.txt';
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        echo buyerStatsTxt($conn, $bales, $bales_ytd, (float)$zig_rate, $raw_sp, $date_f);
        exit;
    }
    if ($report==='purchase_summary') {
        // Multi-date CSV matching sample format
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="purchase_summary_'.str_replace('-','',$date_f).'.csv"');
        echo "Sale Date,Bales Laid,Bales Rejected,Bales Sold,Mass Sold,USD Value,Highest Price (USD/kg),Lowest Price (USD/kg),Avg Price (USD/kg),Exchange Rate,Rejection Reasons\r\n";
        $dates = $conn->query("SELECT DISTINCT COALESCE(tg.sell_date, DATE(tk.created_at)) AS sd FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN transporter_growers tg ON tg.id=bj.transporter_growersid JOIN growers g ON g.id=bj.growerid WHERE g.selling_pointid=$spid ORDER BY sd DESC LIMIT 60");
        while ($dates && $dr=$dates->fetch_assoc()) {
            $d=$dr['sd']; $de=$conn->real_escape_string($d);
            $dq_qr = $conn->query("SELECT COUNT(*) laid, SUM(CASE WHEN sb.id IS NOT NULL THEN 1 ELSE 0 END) sold, SUM(bm.mass) mass, SUM(CASE WHEN sb.id IS NOT NULL THEN (CASE WHEN CAST(sb.price AS DECIMAL(12,4))>10 THEN CAST(sb.price AS DECIMAL(12,4))/100 ELSE CAST(sb.price AS DECIMAL(12,4)) END)*bm.mass ELSE 0 END) gross, MAX(CASE WHEN CAST(sb.price AS DECIMAL(12,4))>10 THEN CAST(sb.price AS DECIMAL(12,4))/100 ELSE CAST(sb.price AS DECIMAL(12,4)) END) hi, MIN(CASE WHEN CAST(sb.price AS DECIMAL(12,4))>0 THEN (CASE WHEN CAST(sb.price AS DECIMAL(12,4))>10 THEN CAST(sb.price AS DECIMAL(12,4))/100 ELSE CAST(sb.price AS DECIMAL(12,4)) END) END) lo, AVG(CASE WHEN CAST(sb.price AS DECIMAL(12,4))>0 THEN (CASE WHEN CAST(sb.price AS DECIMAL(12,4))>10 THEN CAST(sb.price AS DECIMAL(12,4))/100 ELSE CAST(sb.price AS DECIMAL(12,4)) END) END) avg FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN transporter_growers tg ON tg.id=bj.transporter_growersid JOIN bale_mass bm ON bm.id=bj.bale_massid JOIN growers g ON g.id=bj.growerid LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id WHERE g.selling_pointid=$spid AND COALESCE(tg.sell_date, DATE(tk.created_at))='$de'"); $dq = ($dq_qr && $dq_qr !== true) ? $dq_qr->fetch_assoc() : [];
            // Per-date exchange rate; fall back to latest active
            $er2_q=$conn->query("SELECT amount FROM exchange_rate WHERE sell_date='$de' AND active=1 ORDER BY id DESC LIMIT 1");
            $er2=($er2_q&&$er2_q!==true&&$er2_q->num_rows)?($er2_q->fetch_assoc()??['amount'=>0]):null;
            if(!$er2){$er2_q2=$conn->query("SELECT amount FROM exchange_rate WHERE active=1 ORDER BY id DESC LIMIT 1");$er2=($er2_q2&&$er2_q2!==true)?($er2_q2->fetch_assoc()??['amount'=>0]):['amount'=>0];}
            $laid=(int)$dq['laid']; $sold=(int)$dq['sold']; $rej=$laid-$sold;
            // Cents→USD conversion happens per price inside the SQL — totals are already correct
            $gross=(float)$dq['gross'];
            $hi=(float)$dq['hi'];$lo=(float)$dq['lo'];$avg=(float)$dq['avg'];
            // Rejection reasons from sale codes
            $rq=$conn->query("SELECT sb.sale_code,COUNT(*) c FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN transporter_growers tg ON tg.id=bj.transporter_growersid JOIN growers g ON g.id=bj.growerid WHERE g.selling_pointid=$spid AND COALESCE(tg.sell_date, DATE(tk.created_at))='$de' AND LEFT(UPPER(TRIM(sb.sale_code)),1)='R' AND CHAR_LENGTH(TRIM(sb.sale_code))<=3 GROUP BY sb.sale_code");
            $reasons=[];while($rq && $rr=$rq->fetch_assoc())$reasons[]=$rr['sale_code'].': '.$rr['c'];
            // Plain numbers only — thousands separators would break unquoted CSV columns
            echo implode(',', [date('d/m/Y',strtotime($d)),$laid,$rej,$sold,
                number_format((float)$dq['mass'],0,'',''),number_format($gross,2,'.',''),
                number_format($hi,2,'.',''),number_format($lo,2,'.',''),number_format($avg,2,'.',''),
                $er2['amount'],'"'.implode(', ',$reasons).'"'])."\r\n";
        }
        exit;
    }
    if ($report==='origination') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="origination_'.str_replace('-','',$date_f).'.csv"');
        echo "Grower Number,First name,Surname,Bales Laid,Bales Rejected,Bales Sold,Mass Sold,Mass Delivered,Gross USD Value,Highest Price USD/kg,Lowest Price USD/kg,Avg Price USD/kg,Area,District,District Name,Province\r\n";
        $by_g = grp($bales,'grower_num');
        foreach ($by_g as $gn=>$gb) {
            $r0=$gb[0]; $sold=array_filter($gb,fn($b)=>!$b['is_rej']); $rej=count($gb)-count($sold);
            $mass=sum_col($sold,'mass'); $usd=sum_col($sold,'usd_value');
            $prices=array_filter(array_column($sold,'price_per_kg'));
            $hi=$prices?max($prices):0; $lo=$prices?min($prices):0; $avg=$prices?array_sum($prices)/count($prices):0;
            echo implode(',', [$gn,$r0['gname'],$r0['surname'],count($gb),$rej,count($sold),
                number_format($mass,0,'',''),number_format($mass,0,'',''),money($usd),money($hi),money($lo),money($avg,2),
                '"'.($r0['area']??'').'"', '"'.($r0['district']??'').'"','"'.($r0['district']??'').'"','"'.($r0['province']??'').'"'
            ])."\r\n";
        }
        exit;
    }

    // ── Generic CSV for all other report types ──────────────────────────────
    $sold = array_filter($bales, fn($b)=>!$b['is_rej']);
    $fn   = str_replace([' ','/'],['_',''],$report).'_'.str_replace('-','',$date_f).'.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="'.$fn.'"');

    if (in_array($report,['grower_sched_usd','grower_sched_zig','bank_summary'])) {
        $cur = str_ends_with($report,'zig') ? 'ZiG' : 'USD';
        echo "Grower #,Name,Surname,Province,Location,Bales,Mass kg,Gross $cur,Deductions,Net $cur,Bank,Account #\r\n";
        $by_g = grp($sold,'grower_num');
        foreach ($by_g as $gn=>$gb) {
            $r0=$gb[0]; $gid=$r0['grower_id'];
            $mass=sum_col($gb,'mass'); $usd=sum_col($gb,'usd_value');
            $is_zig=str_ends_with($report,'zig'); $val=$is_zig?$usd*$zig_rate:$usd;
            $ded_q=$conn->query("SELECT (SELECT COALESCE(SUM(amount_1+amount_2+amount_3),0) FROM stoporders WHERE growerid=$gid AND active=1) + (SELECT COALESCE(SUM(amount),0) FROM internal_stoporders WHERE growerid=$gid AND sell_date='$date_f' AND active=1) AS t");
            $ded=$ded_q&&$ded_q!==true?(float)($ded_q->fetch_assoc()['t']??0):0;
            $net=max(0,$val-($is_zig?$ded*$zig_rate:$ded));
            $bk_qr=$conn->query("SELECT bank_name,account_num FROM grower_banking WHERE growerid=$gid LIMIT 1"); $bk=($bk_qr&&$bk_qr!==true)?($bk_qr->fetch_assoc()??[]):[];
            echo implode(',', [$gn,$r0['gname'],$r0['surname'],
                '"'.($r0['province']??'').'"','"'.($r0['loc_code']??'').'"',
                count($gb),number_format($mass,1),money($val),money($ded),money($net),
                '"'.($bk['bank_name']??'').'"','"'.($bk['account_num']??'').'"'
            ])."\r\n";
        }
        exit;
    }

    if ($report==='grower_sales_sum') {
        echo "Grower #,Name,Province,Location,Bales Laid,Bales Rejected,Bales Sold,Mass kg,Gross USD,Gross ZiG\r\n";
        $by_g = grp($bales,'grower_num');
        foreach ($by_g as $gn=>$gb) {
            $r0=$gb[0]; $s=array_filter($gb,fn($b)=>!$b['is_rej']); $rej=count($gb)-count($s);
            $mass=sum_col($s,'mass'); $usd=sum_col($s,'usd_value');
            echo implode(',', [$gn,'"'.$r0['gname'].' '.$r0['surname'].'"',
                '"'.($r0['province']??'').'"','"'.($r0['loc_code']??'').'"',
                count($gb),$rej,count($s),number_format($mass,1),money($usd),$zig_rate?money($usd*$zig_rate):''
            ])."\r\n";
        }
        exit;
    }

    if (in_array($report,['buyer_invoice','buyer_ytd','buyer_disk'])) {
        echo "Buyer,Code,Grade,Bales,Mass kg,Price USD/kg,Value USD,Value ZiG\r\n";
        $by_b = grp($sold,'buyer_name');
        foreach ($by_b as $bn=>$bb) {
            $by_g2 = grp($bb,'timb_grade');
            foreach ($by_g2 as $gr=>$gb) {
                $mass=sum_col($gb,'mass'); $usd=sum_col($gb,'usd_value');
                $avg=$mass>0?$usd/$mass:0;
                echo implode(',', [
                    '"'.($bn??'').'"','"'.($gb[0]['buyer_code']??'').'"','"'.($gr??'').'"',
                    count($gb),number_format($mass,1),money($avg,4),money($usd),
                    $zig_rate?money($usd*$zig_rate):''
                ])."\r\n";
            }
        }
        exit;
    }

    if (in_array($report,['rejected_summary'])) {
        echo "Date,Grower #,Name,Grade,Buyer,Mass kg,Sale Code\r\n";
        $rej = array_filter($bales,fn($b)=>$b['is_rej']);
        foreach ($rej as $b) {
            echo implode(',', [date('d/m/Y',strtotime($b['sell_date'])),
                $b['grower_num'],'"'.$b['gname'].' '.$b['surname'].'"',
                $b['timb_grade']??'','"'.($b['buyer_name']??'').'"',
                $b['mass'],$b['sale_code']??''
            ])."\r\n";
        }
        exit;
    }

    if (in_array($report,['revenue_report','sales_control','invoice_control','floor_recon','daily_floor_recon','buyer_stats','cred_payment','cred_invoice','cred_ytd','cred_summary','cred_collection'])) {
        // Generic bale-level export for complex reports
        echo "Report: ".str_replace('_',' ',ucwords($report))."\r\n";
        echo "Date: $date_f\r\n";
        echo "Floor: $sp_name\r\n\r\n";
        echo "Grower #,Name,Province,Location,Grade,Buyer,Bales,Mass kg,Price USD/kg,Value USD\r\n";
        $by_g = grp($sold,'grower_num');
        foreach ($by_g as $gn=>$gb) {
            $r0=$gb[0];
            $by_g2=grp($gb,'timb_grade');
            foreach ($by_g2 as $gr=>$gb2) {
                $mass=sum_col($gb2,'mass');$usd=sum_col($gb2,'usd_value');
                $avg=$mass>0?$usd/$mass:0;
                echo implode(',',[$gn,'"'.$r0['gname'].' '.$r0['surname'].'"',
                    '"'.($r0['province']??'').'"','"'.($r0['loc_code']??'').'"',
                    '"'.($gr??'').'"','"'.($gb2[0]['buyer_name']??'').'"',
                    count($gb2),number_format($mass,1),money($avg,4),money($usd)
                ])."\r\n";
            }
        }
        exit;
    }

    // Fallback — raw bale export
    echo "Date,Barcode,Grower #,Name,Grade,Buyer,Loc,Mass kg,Price USD/kg,Value USD\r\n";
    foreach ($sold as $b) {
        echo implode(',', [date('d/m/Y',strtotime($b['sell_date'])),$b['barcode'],
            $b['grower_num'],'"'.$b['gname'].' '.$b['surname'].'"',
            $b['timb_grade']??'',$b['buyer_name']??'',$b['loc_code']??'',
            $b['mass'],money($b['price_per_kg'],4),money($b['usd_value'])
        ])."\r\n";
    }
    exit;
}

include "includes/layout.php";
?>
<style>
.rpt-section{background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:1.25rem}
body.theme-dark .rpt-section{background:#1f1a14;border-color:rgba(245,240,232,0.10)}
.rpt-head{padding:0.85rem 1.25rem;border-bottom:2px solid var(--gold);background:var(--ink);color:var(--cream);display:flex;align-items:flex-start;justify-content:space-between;gap:1rem}
.rpt-head h3{font-family:'Bebas Neue',sans-serif;font-size:1.15rem;letter-spacing:0.08em;color:var(--cream);line-height:1.1;margin:0}
.rpt-meta{font-size:0.7rem;color:rgba(245,240,232,0.5);margin-top:3px}
.rpt-table{width:100%;border-collapse:collapse;font-size:0.8rem}
.rpt-table thead th{font-size:0.62rem;font-weight:500;letter-spacing:0.08em;text-transform:uppercase;color:var(--muted);padding:0.55rem 0.9rem;background:var(--cream);border-bottom:1px solid var(--border);white-space:nowrap;text-align:left}
.rpt-table tbody tr{border-bottom:1px solid rgba(26,18,8,0.05)}
.rpt-table tbody tr:hover{background:#faf8f5}
.rpt-table td{padding:0.5rem 0.9rem;vertical-align:middle}
.rpt-table tfoot tr{background:var(--ink);color:var(--cream)}
.rpt-table tfoot td{padding:0.55rem 0.9rem;font-weight:500}
.rpt-table .tr-sub{background:var(--cream-d)}
.rpt-table .tr-sub td{font-size:0.65rem;color:var(--muted);padding:2px 0.9rem;letter-spacing:0.08em;text-transform:uppercase}
.rpt-kpis{display:grid;gap:0.85rem;padding:1.15rem}
.rpt-kpi{background:var(--cream);border:1px solid var(--border);border-radius:var(--radius);padding:0.85rem 1rem;position:relative;overflow:hidden}
.rpt-kpi::before{content:'';position:absolute;top:0;left:0;width:3px;height:100%;background:var(--kpi-color,var(--gold))}
.rpt-kpi-label{font-size:0.62rem;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted);margin-bottom:4px}
.rpt-kpi-val{font-family:'Bebas Neue',sans-serif;font-size:1.6rem;letter-spacing:0.04em;line-height:1}
.rpt-kpi-sub{font-size:0.68rem;color:var(--muted);margin-top:2px}
.recon-ok{color:var(--leaf);font-weight:500}.recon-warn{color:var(--danger);font-weight:500}.recon-zero{color:var(--muted)}
.rec-bar{width:60px;height:6px;background:var(--border);border-radius:3px;display:inline-block;vertical-align:middle;margin-right:4px}
.rec-fill{height:6px;border-radius:3px}
.rnav-cat{font-size:0.58rem;font-weight:500;letter-spacing:0.15em;text-transform:uppercase;color:rgba(245,240,232,0.22);padding:0.8rem 0.9rem 0.25rem;border-top:1px solid rgba(200,149,42,0.07);margin-top:2px}
.rnav-cat:first-child{border-top:none;margin-top:0}
.rnav-link{display:flex;align-items:center;gap:7px;padding:0.42rem 0.9rem;color:rgba(245,240,232,0.55);text-decoration:none;font-size:0.78rem;border-left:2px solid transparent;transition:all 0.12s;line-height:1.3}
.rnav-link:hover{background:rgba(200,149,42,0.08);color:rgba(245,240,232,0.85);border-left-color:rgba(200,149,42,0.3)}
.rnav-link.active{background:rgba(200,149,42,0.14);color:var(--gold-l);border-left-color:var(--gold);font-weight:500}
.rnav-dot{width:6px;height:6px;border-radius:50%;border:1.5px solid currentColor;flex-shrink:0}
.rnav-link.active .rnav-dot{background:var(--gold-l)}
/* eod layout: full width */
@media print{.sidebar,.topbar,.filter-bar,.rnav-panel,.page-header,.btn{display:none!important}.main{margin:0!important;padding:0!important}.eod-layout{grid-template-columns:1fr!important}
/* Reports always print on white paper, whatever the on-screen theme */
body,body.theme-dark{background:#fff!important;color:#1a1208!important;
  --text-strong:#1a1208;--leaf:#2d5226;--danger:#c0392b;--info:#1a6b9a;--muted:#5a5040}
body.theme-dark .rpt-section{background:#fff!important;border-color:rgba(26,18,8,0.12)!important}}
</style>

<div class="page-header">
    <div class="page-header-left"><h2>End of Day Reports</h2>
        <p><?= $sp_name ?> &nbsp;·&nbsp; <?= date('l d F Y',strtotime($date_f)) ?></p></div>
    <a href="export_all.php?page=eod&date=<?=urlencode($date_f)?>&spid=<?=$spid?><?=$buyer_f?"&buyer=$buyer_f":''?><?=$buyer_mark_f?"&buyer_mark=".urlencode($buyer_mark_f):''?><?=$loc_f?"&loc=$loc_f":''?>"
       class="btn btn-primary" title="Download all EOD reports as ZIP">📦 Export All ZIP</a>
    <button onclick="window.print()" class="btn btn-ghost">🖨 Print page</button>
</div>

<!-- Filter bar -->
<div class="card mb-3 filter-bar">
<div class="card-body" style="padding:0.65rem 1.15rem">
<form method="GET" style="display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap">
    <?php if($report):?><input type="hidden" name="report" value="<?=htmlspecialchars($report)?>"><?php endif;?>
    <div class="form-group" style="margin:0"><label style="font-size:0.62rem">Date</label>
        <select name="date" class="form-control" style="padding:0.35rem 0.55rem;font-size:0.8rem" onchange="this.form.submit()">
            <?php while($sell_dates_q && $sd=$sell_dates_q->fetch_assoc()):?>
            <option value="<?=$sd['sd']?>" <?=$date_f===$sd['sd']?'selected':''?>><?=date('d M Y',strtotime($sd['sd']))?> (<?=$sd['tix']?>)</option>
            <?php endwhile;?>
        </select></div>
    <div class="form-group" style="margin:0"><label style="font-size:0.62rem">Buyer</label>
        <select name="buyer" class="form-control" style="padding:0.35rem 0.5rem;font-size:0.78rem;width:auto" onchange="this.form.submit()">
            <option value="">All buyers</option>
            <?php foreach($buyers as $b):?>
            <option value="<?=htmlspecialchars($b['name'])?>" <?=$buyer_f===$b['name']?'selected':''?>><?=htmlspecialchars($b['name'])?></option>
            <?php endforeach;?>
        </select></div>
    <div class="form-group" style="margin:0"><label style="font-size:0.62rem">Buyer Mark</label>
        <select name="buyer_mark" class="form-control" style="padding:0.35rem 0.5rem;font-size:0.78rem;width:auto" onchange="this.form.submit()">
            <option value="">All marks</option>
            <?php foreach($buyer_marks as $bm):?>
            <option value="<?=$bm['buyer_code']?>" <?=$buyer_mark_f===$bm['buyer_code']?'selected':''?>><?=htmlspecialchars($bm['buyer_code'])?></option>
            <?php endforeach;?>
        </select></div>
    <div class="form-group" style="margin:0"><label style="font-size:0.62rem">Location</label>
        <select name="loc" class="form-control" style="padding:0.35rem 0.5rem;font-size:0.78rem;width:auto" onchange="this.form.submit()">
            <option value="0">All locations</option>
            <?php foreach($locs as $l):?>
            <option value="<?=$l['id']?>" <?=$loc_f==$l['id']?'selected':''?>><?=htmlspecialchars($l['code'])?> — <?=htmlspecialchars($l['name'])?></option>
            <?php endforeach;?>
        </select></div>
    <button type="submit" class="btn btn-primary">Apply</button>
    <?php if($buyer_f||$buyer_mark_f||$loc_f):?><a href="?date=<?=htmlspecialchars((string)$date_f)?>&report=<?=htmlspecialchars($report)?>" class="btn btn-ghost">Clear</a><?php endif;?>
</form></div></div>

<!-- Main layout: nav + report panel -->
<?php
// Build flat report list for dropdown
$nav = [
    'Buyer Reports' => [
        ['buyer_disk',       'Buyer Disk File'],
        ['buyer_invoice',    'Buyer Invoice'],
        ['buyer_ytd',        'Buyer YTD Invoice'],
        ['buyer_stats',      'Buyer Stats'],
        ['daily_sales_ret',  'Daily Sales Returns'],
    ],
    'Creditor Reports' => [
        ['cred_payment',     'Creditor Payment Schedule'],
        ['cred_invoice',     'Creditor Invoice'],
        ['cred_ytd',         'YTD Creditor Invoice'],
        ['cred_summary',     'Creditor Invoice Summary'],
        ['cred_collection',  'Collection Advice'],
    ],
    'Other Reports' => [
        ['rejected_summary', 'Rejected Bales Summary'],
        ['revenue_report',   'Revenue Report'],
        ['floor_recon',      'Floor Reconciliation'],
        ['sales_control',    'Sales Control'],
        ['invoice_control',  'Invoice Control'],
        ['purchase_summary', 'Purchase Summary'],
        ['origination',      'Origination Report'],
        ['daily_floor_recon','Daily Floor Reconciliation'],
    ],
    'Schedules' => [
        ['grower_sched_usd', 'Grower Schedule USD'],
        ['grower_sched_zig', 'Grower Schedule ZiG'],
        ['bank_summary',     'Bank Summary'],
        ['grower_sales_sum', 'Grower Sales Summary'],
        ['extract_bales',    'Extract Bales Sold'],
        ['extract_bank',     'Extract Bank Details'],
    ],
];
// Find current label
$current_label = 'Select a report…';
foreach ($nav as $items) {
    foreach ($items as [$id,$label]) {
        if ($id === $report) { $current_label = $label; break 2; }
    }
}
?>

<!-- REPORT SELECTOR BAR -->
<div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;flex-wrap:wrap">
    <label style="font-size:0.7rem;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted);white-space:nowrap">Report</label>
    <form method="GET" style="flex:1;min-width:220px">
        <?php if ($date_f): ?><input type="hidden" name="date" value="<?=htmlspecialchars($date_f)?>"><?php endif; ?>
        <?php if ($buyer_f): ?><input type="hidden" name="buyer" value="<?=htmlspecialchars((string)$buyer_f)?>"><?php endif; ?>
        <?php if ($buyer_mark_f): ?><input type="hidden" name="buyer_mark" value="<?=htmlspecialchars($buyer_mark_f)?>"><?php endif; ?>
        <?php if ($loc_f): ?><input type="hidden" name="loc" value="<?=$loc_f?>"><?php endif; ?>
        <select name="report" class="form-control" onchange="this.form.submit()"
                style="font-size:0.82rem;padding:0.45rem 0.7rem;max-width:380px;cursor:pointer">
            <option value="">— Select a report —</option>
            <?php foreach ($nav as $cat => $items): ?>
            <optgroup label="<?=htmlspecialchars($cat)?>">
                <?php foreach ($items as [$id,$label]): ?>
                <option value="<?=htmlspecialchars($id)?>" <?=$report===$id?'selected':''?>>
                    <?=htmlspecialchars($label)?>
                </option>
                <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($report): ?>
    <a href="?date=<?=urlencode($date_f)?><?=$buyer_f?"&buyer=$buyer_f":''?><?=$buyer_mark_f?"&buyer_mark=".urlencode($buyer_mark_f):''?><?=$loc_f?"&loc=$loc_f":''?>"
       class="btn btn-ghost btn-sm" style="white-space:nowrap">✕ Clear</a>
    <?php endif; ?>
</div>

<!-- REPORT PANEL (full width) -->
<div>
<?php if (!$report): ?>
<div class="rpt-section">
<div class="rpt-head"><h3>End of Day Reports</h3></div>
<div style="padding:3rem;text-align:center;color:var(--muted)">
    <div style="font-size:2.5rem;margin-bottom:0.75rem;opacity:0.3">📊</div>
    <div style="font-weight:500;font-size:0.9rem">Choose a report from the dropdown above</div>
    <div style="font-size:0.78rem;margin-top:0.35rem"><?=date('d M Y',strtotime($date_f))?> · <?=$buyer_f?'buyer filtered':($buyer_mark_f?'buyer mark filtered':'all buyers')?> · <?=$loc_f?'location filtered':'all locations'?></div>
</div>
</div>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// BUYER DISK FILE
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='buyer_disk'):
    $bales=load_bales($conn,$date_f,$spid,$buyer_f,$loc_f);
    rpt_head('Buyer Disk File','Grade invoice per buyer — barcode, grade, mass, price, USD & ZiG');
    $disk_url="?date=".urlencode($date_f)."&report=buyer_disk&dl=1".($buyer_f?"&buyer=$buyer_f":"").($loc_f?"&loc=$loc_f":"");
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="<?=$disk_url?>" class="btn btn-primary btn-sm">⬇ Download Buyer Disk File (.v)</a>
    </div><?php
    $by_buyer = grp(array_filter($bales,fn($b)=>!$b['is_rej']&&$b['buyer_name']),'buyer_name');
    ?>
    <?php foreach ($by_buyer as $bn=>$bb): ?>
    <div style="padding:0.5rem 1.15rem 0;border-top:1px solid var(--border)">
        <div style="font-size:0.72rem;font-weight:500;letter-spacing:0.08em;text-transform:uppercase;color:var(--muted)">
            <?=htmlspecialchars($bn)?> &nbsp;<span style="font-weight:400"><?=count($bb)?> bales · <?=money(sum_col($bb,'mass'),1)?> kg · <?=m(sum_col($bb,'usd_value'))?></span>
        </div>
    </div>
    <div class="table-wrap" style="overflow-x:auto">
    <table class="rpt-table">
    <thead><tr>
        <th>Grade</th><th>Grower #</th><th>Lot</th><th>TIMB BC</th><th>Barcode</th>
        <th class="text-right">Mass</th><th class="text-right">ZiG $/kg</th><th class="text-right">USD $/kg</th>
        <th class="text-right">ZiG Value</th><th class="text-right">USD Value</th>
    </tr></thead>
    <tbody>
    <?php
    $by_grade=grp($bb,'buyer_grade');
    foreach ($by_grade as $grd=>$gb):
        $gt_mass=sum_col($gb,'mass');$gt_usd=sum_col($gb,'usd_value');$gt_zig=sum_col($gb,'zig_value');
    ?>
    <?php foreach ($gb as $b): $zig_p=$b['price_per_kg']*$zig_rate; ?>
    <tr>
        <td><span class="badge badge-gold" style="font-size:0.65rem"><?=htmlspecialchars($b['buyer_grade']??'—')?></span></td>
        <td style="font-size:0.75rem"><?=htmlspecialchars($b['grower_num'])?></td>
        <td class="td-mono" style="font-size:0.75rem"><?=$b['lot']?></td>
        <td><code style="font-size:0.68rem"><?=htmlspecialchars($b['timb_bc']??'—')?></code></td>
        <td><code style="font-size:0.68rem"><?=htmlspecialchars($b['barcode'])?></code></td>
        <td class="td-mono text-right"><?=money($b['mass'],1)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?money($zig_p,2):'—'?></td>
        <td class="td-mono text-right"><?=money($b['price_per_kg'],4)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?money($b['zig_value']):'—'?></td>
        <td class="td-mono text-right fw-500"><?=m($b['usd_value'])?></td>
    </tr>
    <?php endforeach;?>
    <tr class="tr-sub"><td colspan="5">Grade totals: <?=count($gb)?></td>
        <td class="td-mono text-right"><?=money($gt_mass,1)?></td>
        <td colspan="2"></td>
        <td class="td-mono text-right"><?=$zig_rate>0?money($gt_zig):'—'?></td>
        <td class="td-mono text-right fw-500"><?=m($gt_usd)?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
    <tfoot><tr>
        <td colspan="5">TOTALS — <?=count($bb)?> bales</td>
        <td class="td-mono text-right"><?=money(sum_col($bb,'mass'),1)?> kg</td>
        <td colspan="2"></td>
        <td class="td-mono text-right"><?=$zig_rate>0?money(sum_col($bb,'zig_value')):'—'?></td>
        <td class="td-mono text-right"><?=m(sum_col($bb,'usd_value'))?></td>
    </tr></tfoot>
    </table></div>
    <?php endforeach;
    if(empty($by_buyer)) echo '<div style="padding:2rem;text-align:center;color:var(--muted)">No sold bales found.</div>';
    rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// BUYER INVOICE (daily and YTD share same template)
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='buyer_invoice'||$report==='buyer_ytd'):
    $is_ytd=$report==='buyer_ytd';
    $bales=load_bales($conn,$date_f,$spid,$buyer_f,$loc_f,$is_ytd);
    $title = $is_ytd ? 'Buyer YTD Grade Invoice' : 'Buyer Grade Invoice';
    $subtitle = $is_ytd ? 'All purchases up to '.date('d/m/Y',strtotime($date_f)).' (cumulative)' : date('d/m/Y',strtotime($date_f));
    rpt_head($title, $subtitle);
    $txt_url="?date=".urlencode($date_f)."&report=".htmlspecialchars($report)."&dl=1".($buyer_f?"&buyer=$buyer_f":"").($loc_f?"&loc=$loc_f":"");
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="<?=$txt_url?>" class="btn btn-primary btn-sm">⬇ Download Official Invoice (.txt)</a>
    </div><?php
    $sold=array_filter($bales,fn($b)=>!$b['is_rej'] && !empty($b['buyer_id']));
    $by_buyer=grp($sold,'buyer_name');
    foreach ($by_buyer as $bn=>$bb):
        $by_grade=grp($bb,'buyer_grade');
    ?>
    <div style="padding:0.75rem 1.15rem;border-top:1px solid var(--border);background:var(--cream)">
        <div style="font-weight:500;font-size:0.88rem">BUYER: <?=htmlspecialchars($bn)?></div>
    </div>
    <div class="table-wrap" style="overflow-x:auto">
    <table class="rpt-table">
    <thead><tr>
        <th>Grade</th><th>Grower #</th><th>Lot</th><th class="text-right">Mass</th>
        <th class="text-right">ZiG $/kg</th><th class="text-right">USD $/kg</th>
        <th class="text-right">ZiG Value</th><th class="text-right">USD Value</th><th>Barcode</th>
    </tr></thead><tbody>
    <?php foreach ($by_grade as $grd=>$gb):
        $gms=sum_col($gb,'mass');$gu=sum_col($gb,'usd_value');$gz=sum_col($gb,'zig_value');
    ?>
    <?php foreach ($gb as $b): $zp=$b['price_per_kg']*$zig_rate; ?>
    <tr>
        <td><span class="badge badge-gold" style="font-size:0.62rem"><?=htmlspecialchars($b['buyer_grade']??'—')?></span></td>
        <td style="font-size:0.75rem"><?=htmlspecialchars($b['grower_num'])?></td>
        <td class="td-mono" style="font-size:0.75rem"><?=$b['lot']?></td>
        <td class="td-mono text-right"><?=money($b['mass'],1)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?money($zp,2):'—'?></td>
        <td class="td-mono text-right"><?=money($b['price_per_kg'],4)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($b['zig_value'],2):'—'?></td>
        <td class="td-mono text-right fw-500"><?=money($b['usd_value'])?></td>
        <td><code style="font-size:0.65rem"><?=htmlspecialchars($b['barcode'])?></code></td>
    </tr>
    <?php endforeach;?>
    <tr class="tr-sub"><td colspan="3">Grade totals: <?=count($gb)?></td>
        <td class="td-mono text-right"><?=money($gms,1)?></td><td colspan="2"></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($gz,2):'—'?></td>
        <td class="td-mono text-right fw-500"><?=money($gu)?></td><td></td>
    </tr>
    <?php endforeach;?>
    </tbody>
    <tfoot><tr>
        <td colspan="3">BUYER TOTAL — <?=count($bb)?> bales</td>
        <td class="td-mono text-right"><?=money(sum_col($bb,'mass'),1)?> kg</td>
        <td colspan="2"></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format(sum_col($bb,'zig_value'),2):'—'?></td>
        <td class="td-mono text-right"><?=money(sum_col($bb,'usd_value'))?></td>
        <td></td>
    </tr></tfoot>
    </table></div>
    <?php endforeach;
    rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// BUYER STATS — matches HU_BUYER_STATS_0327.txt exactly
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='buyer_stats'):
    $bales=load_bales($conn,$date_f,$spid,$buyer_f,$loc_f);
    $bales_ytd=load_bales($conn,$date_f,$spid,$buyer_f,$loc_f,true);
    rpt_head('Buyer Stats','Daily & YTD purchases per buyer');
    $stats_url="?date=".urlencode($date_f)."&report=buyer_stats&dl=1".($buyer_f?"&buyer=$buyer_f":"").($loc_f?"&loc=$loc_f":"");
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="<?=$stats_url?>" class="btn btn-primary btn-sm">⬇ Download Buyer Stats (.txt)</a>
    </div><?php

    function buyer_stats_table($bales_in, $label, $zig_rate) {
        $sold=array_filter($bales_in,fn($b)=>!$b['is_rej']);
        $by_b=grp($sold,'buyer_name');
        $grand_u=sum_col($sold,'usd_value');
        $grand_z=sum_col($sold,'zig_value');
        $grand_m=sum_col($sold,'mass');
        ?>
        <div style="padding:0.75rem 1.15rem;background:var(--cream-d);border-top:1px solid var(--border)">
            <span style="font-size:0.72rem;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted)"><?=$label?></span>
        </div>
        <div class="table-wrap" style="overflow-x:auto">
        <table class="rpt-table">
        <thead><tr>
            <th>Buyer</th><th class="text-right">Bales</th><th class="text-right">Mass (kg)</th>
            <th class="text-right">% Mass</th><th class="text-right">US Value</th>
            <th class="text-right">ZiG Value</th><th class="text-right">TTA US$</th>
            <th class="text-right">Tob Levy US$</th><th class="text-right">Flr Clearing US$</th>
            <th class="text-right">US$ Total</th>
        </tr></thead><tbody>
        <?php $gt_tta=$gt_tob=$gt_flr=$gt_tot=0; foreach ($by_b as $bn=>$bb):
            $mass=sum_col($bb,'mass');$usd=sum_col($bb,'usd_value');$zig=sum_col($bb,'zig_value');
            $tta=round($mass*TTA_LEVY_PER_KG,2);$tob=round($usd*TOBACCO_LEVY_PCT,2);$flr=round($usd*STATS_FLR_CLEARING_PCT,2);
            $tot=round($usd,2)+$tta+$tob+$flr;
            $gt_tta+=$tta;$gt_tob+=$tob;$gt_flr+=$flr;$gt_tot+=$tot;
            $pct=$grand_m>0?round($mass/$grand_m*100,2):0;
        ?>
        <tr>
            <td class="fw-500" style="font-size:0.82rem"><?=htmlspecialchars($bb[0]['buyer_code']??$bn)?></td>
            <td class="td-mono text-right"><?=number_format(count($bb))?></td>
            <td class="td-mono text-right"><?=money($mass,0)?></td>
            <td class="td-mono text-right"><?=number_format($pct,2)?></td>
            <td class="td-mono text-right"><?=money($usd)?></td>
            <td class="td-mono text-right"><?=$zig_rate>0?number_format($zig,2):'—'?></td>
            <td class="td-mono text-right"><?=money($tta)?></td>
            <td class="td-mono text-right"><?=money($tob)?></td>
            <td class="td-mono text-right"><?=money($flr)?></td>
            <td class="td-mono text-right fw-500"><?=money($tot)?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
        <tfoot><tr>
            <td>TOTALS</td>
            <td class="td-mono text-right"><?=number_format(count($sold))?></td>
            <td class="td-mono text-right"><?=money($grand_m,0)?></td>
            <td class="td-mono text-right">100</td>
            <td class="td-mono text-right"><?=money($grand_u)?></td>
            <td class="td-mono text-right"><?=$zig_rate>0?number_format($grand_z,2):'—'?></td>
            <td class="td-mono text-right"><?=money($gt_tta)?></td>
            <td class="td-mono text-right"><?=money($gt_tob)?></td>
            <td class="td-mono text-right"><?=money($gt_flr)?></td>
            <td class="td-mono text-right fw-500"><?=money($gt_tot)?></td>
        </tr></tfoot>
        </table></div>
        <?php
    }
    buyer_stats_table($bales, 'Daily Purchases — '.date('d/m/Y',strtotime($date_f)), $zig_rate);
    buyer_stats_table($bales_ytd, 'Year to Date Purchases — '.date('Y',strtotime($date_f)), $zig_rate);
    rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// DAILY SALES RETURNS — matches HU_DAILY_SALES_RETURN0327.txt
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='daily_sales_ret'):
    $bales=load_bales($conn,$date_f,$spid,$buyer_f,$loc_f);
    $bales_ytd=load_bales($conn,$date_f,$spid,$buyer_f,$loc_f,true);
    $sold=array_filter($bales,fn($b)=>!$b['is_rej']);
    $sold_ytd=array_filter($bales_ytd,fn($b)=>!$b['is_rej']);
    $laid=count($bales);$rej=count($bales)-count($sold);
    $laid_ytd=count($bales_ytd);$rej_ytd=count($bales_ytd)-count($sold_ytd);
    $mass=sum_col($sold,'mass');$usd=sum_col($sold,'usd_value');
    $mass_ytd=sum_col($sold_ytd,'mass');$usd_ytd=sum_col($sold_ytd,'usd_value');
    $prices=array_filter(array_column($sold,'price_per_kg'));
    $prices_ytd=array_filter(array_column($sold_ytd,'price_per_kg'));
    $avg=$prices&&$mass>0?$usd/$mass:0;
    $avg_ytd=$prices_ytd&&$mass_ytd>0?$usd_ytd/$mass_ytd:0;
    $hi=$prices?max($prices)*100:0;$lo=$prices?min($prices)*100:0; // stored in cents for display
    $hi_ytd=$prices_ytd?max($prices_ytd)*100:0;$lo_ytd=$prices_ytd?min($prices_ytd)*100:0;
    rpt_head('Daily Sales Return Summary','Today vs Year-to-Date');
    $dsr_url="?date=".urlencode($date_f)."&report=daily_sales_ret&dl=1".($buyer_f?"&buyer=$buyer_f":"").($loc_f?"&loc=$loc_f":"");
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="<?=$dsr_url?>" class="btn btn-primary btn-sm">⬇ Download Daily Sales Return (.txt)</a>
    </div><?php
    ?>
    <div style="padding:1.15rem;overflow-x:auto">
    <table style="width:100%;font-size:0.8rem;border-collapse:collapse;min-width:700px">
    <thead>
    <tr style="text-align:right">
        <th style="text-align:left;padding:0.4rem 0.8rem;border-bottom:2px solid var(--border)">Description</th>
        <th colspan="3" style="padding:0.4rem 0.8rem;border-bottom:2px solid var(--border);border-left:1px solid var(--border)">TODAY'S SALE</th>
        <th colspan="3" style="padding:0.4rem 0.8rem;border-bottom:2px solid var(--border);border-left:2px solid var(--ink)">YEAR TO DATE &nbsp;<small style="font-weight:400;font-size:0.7rem">Rate: <?=$zig_rate?></small></th>
    </tr>
    <tr style="text-align:right">
        <th style="text-align:left;padding:0.4rem 0.8rem;border-bottom:1px solid var(--border)"></th>
        <th style="padding:0.4rem 0.8rem;border-bottom:1px solid var(--border);border-left:1px solid var(--border)">New Tobacco</th>
        <th style="padding:0.4rem 0.8rem;border-bottom:1px solid var(--border)">Re-Sales</th>
        <th style="padding:0.4rem 0.8rem;border-bottom:1px solid var(--border)">Total</th>
        <th style="padding:0.4rem 0.8rem;border-bottom:1px solid var(--border);border-left:2px solid var(--ink)">New Tobacco</th>
        <th style="padding:0.4rem 0.8rem;border-bottom:1px solid var(--border)">Re-Sales</th>
        <th style="padding:0.4rem 0.8rem;border-bottom:1px solid var(--border)">Total</th>
    </tr>
    </thead>
    <tbody>
    <?php
    function dsr_row($label, $today, $ytd, $fmt='n', $is_sub=false) {
        $s = $is_sub?'color:var(--muted);':'';
        $f = fn($v) => $fmt==='m' ? money($v) : ($fmt==='n' ? number_format($v) : number_format($v,2));
        echo "<tr style='border-bottom:1px solid var(--border);text-align:right'>";
        echo "<td style='text-align:left;padding:0.4rem 0.8rem;$s'>".htmlspecialchars($label)."</td>";
        echo "<td style='padding:0.4rem 0.8rem;border-left:1px solid var(--border);font-family:monospace'>".$f($today)."</td>";
        echo "<td style='padding:0.4rem 0.8rem;font-family:monospace'>0</td>";
        echo "<td style='padding:0.4rem 0.8rem;font-weight:500;font-family:monospace'>".$f($today)."</td>";
        echo "<td style='padding:0.4rem 0.8rem;border-left:2px solid var(--ink);font-family:monospace'>".$f($ytd)."</td>";
        echo "<td style='padding:0.4rem 0.8rem;font-family:monospace'>0</td>";
        echo "<td style='padding:0.4rem 0.8rem;font-weight:500;font-family:monospace'>".$f($ytd)."</td>";
        echo "</tr>";
    }
    dsr_row('1ST TIME  Bales Sold', count($sold), count($sold_ytd));
    dsr_row('Mass Sold', $mass, $mass_ytd, 'n');
    dsr_row('Value Sold', $usd, $usd_ytd, 'm');
    dsr_row('Average Price', $avg, $avg_ytd, 'f');
    ?>
    <tr style="background:var(--cream);border-bottom:1px solid var(--border)"><td colspan="7" style="padding:2px 0.8rem;font-size:0.68rem;color:var(--muted)">ADJUSTMENTS</td></tr>
    <?php
    dsr_row('Bales Adj', 0, 0, 'n', true);
    dsr_row('Mass Adj', 0, 0, 'n', true);
    dsr_row('Value Adj', 0, 0, 'm', true);
    ?>
    </tbody>
    <tfoot>
    <?php
    dsr_row('TOTAL  Bales Sold', count($sold), count($sold_ytd));
    dsr_row('Mass Sold', $mass, $mass_ytd, 'n');
    dsr_row('Value Sold', $usd, $usd_ytd, 'm');
    dsr_row('Average Price', $avg, $avg_ytd, 'f');
    ?>
    </tfoot>
    </table>

    <!-- Summary boxes below -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-top:1.25rem">
    <div style="border:1px solid var(--border);border-radius:var(--radius);padding:0.85rem 1rem">
        <div style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);margin-bottom:0.5rem">Today's Sale</div>
        <table style="width:100%;font-size:0.78rem"><tbody>
            <tr><td>Bales Sold</td><td class="td-mono text-right fw-500"><?=number_format(count($sold))?></td></tr>
            <tr><td>Bales Rejected</td><td class="td-mono text-right"><?=number_format($rej)?></td></tr>
            <tr><td>Bales Laid</td><td class="td-mono text-right"><?=number_format($laid)?></td></tr>
            <tr><td style="padding-top:0.35rem">Highest Price</td><td class="td-mono text-right text-leaf"><?=money($hi,0)?></td></tr>
            <tr><td>Lowest Price</td><td class="td-mono text-right text-danger"><?=money($lo,0)?></td></tr>
        </tbody></table>
    </div>
    <div style="border:1px solid var(--border);border-radius:var(--radius);padding:0.85rem 1rem">
        <div style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);margin-bottom:0.5rem">Year to Date</div>
        <table style="width:100%;font-size:0.78rem"><tbody>
            <tr><td>Bales Sold</td><td class="td-mono text-right fw-500"><?=number_format(count($sold_ytd))?></td></tr>
            <tr><td>Bales Rejected</td><td class="td-mono text-right"><?=number_format($rej_ytd)?></td></tr>
            <tr><td>Bales Laid</td><td class="td-mono text-right"><?=number_format($laid_ytd)?></td></tr>
            <tr><td style="padding-top:0.35rem">Highest Price</td><td class="td-mono text-right text-leaf"><?=money($hi_ytd,0)?></td></tr>
            <tr><td>Lowest Price</td><td class="td-mono text-right text-danger"><?=money($lo_ytd,0)?></td></tr>
        </tbody></table>
    </div>
    </div>
    </div>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// CREDITOR PAYMENT SCHEDULE
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='cred_payment'):
    $bales=load_bales($conn,$date_f,$spid,0,$loc_f);  // grower/creditor report — buyer filter not applied
    $gids=implode(',',array_map('intval',array_unique(array_column($bales,'grower_id'))))?:'0';
    $sos=$conn->query("
        SELECT g.grower_num, g.name, g.surname,
               (SELECT COALESCE(SUM(amount_1+amount_2+amount_3),0) FROM stoporders WHERE growerid=g.id AND active=1) timb_so,
               (SELECT COALESCE(SUM(amount),0) FROM internal_stoporders WHERE growerid=g.id AND sell_date='$date_f' AND active=1) int_so
        FROM growers g
        WHERE g.id IN ($gids) ORDER BY g.grower_num
    ");
    rpt_head('Creditor Payment Schedule','Stop order & internal deductions per grower');
    ?><div class="table-wrap"><table class="rpt-table">
    <thead><tr><th>Grower #</th><th>Name</th>
        <th class="text-right">TIMB SO (USD)</th><th class="text-right">Internal SO (USD)</th>
        <th class="text-right">Total USD</th><th class="text-right">Total ZiG</th>
    </tr></thead><tbody>
    <?php $tt=$ti=$tot=0; while($sos && $r=$sos->fetch_assoc()): $t=(float)$r['timb_so']+(float)$r['int_so']; $tt+=$r['timb_so'];$ti+=$r['int_so'];$tot+=$t; ?>
    <tr>
        <td><span class="badge badge-gold" style="font-size:0.65rem"><?=htmlspecialchars($r['grower_num'])?></span></td>
        <td style="font-size:0.82rem"><?=htmlspecialchars($r['name'].' '.$r['surname'])?></td>
        <td class="td-mono text-right"><?=$r['timb_so']>0?m($r['timb_so']):'—'?></td>
        <td class="td-mono text-right"><?=$r['int_so']>0?m($r['int_so']):'—'?></td>
        <td class="td-mono text-right fw-500 text-danger"><?=m($t)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($t*$zig_rate,2):'—'?></td>
    </tr>
    <?php endwhile;?>
    </tbody><tfoot><tr>
        <td colspan="2">TOTAL</td><td class="td-mono text-right"><?=m($tt)?></td>
        <td class="td-mono text-right"><?=m($ti)?></td>
        <td class="td-mono text-right"><?=m($tot)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($tot*$zig_rate,2):'—'?></td>
    </tr></tfoot></table></div>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// CREDITOR INVOICE — matches HU_CREDITOR_INVOICE_0327_0327.txt format
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='cred_invoice'):
    $bales=load_bales($conn,$date_f,$spid,0,$loc_f);  // grower/creditor report — buyer filter not applied
    $gids=implode(',',array_map('intval',array_unique(array_column($bales,'grower_id'))))?:'0';
    // Get all stop orders grouped by creditor reference
    $so_q=$conn->query("
        SELECT st.creditor_no AS cred_no, COALESCE(tcs.creditor_name,'Unknown') AS cred_name,
               tcs.account_num AS account_no, tcs.creditor_ref AS phone, tcs.creditor_type, tcs.business_type,
               g.grower_num, g.name, g.surname, (st.amount_1+st.amount_2+st.amount_3) AS amount, st.formatted_sos_date AS sell_date
        FROM stoporders st JOIN growers g ON g.id=st.growerid
        LEFT JOIN grower_creditors tcs ON tcs.creditor_no=st.creditor_no
        WHERE g.id IN ($gids) AND st.active=1
        ORDER BY st.creditor_no ASC, g.grower_num ASC
    ");
    rpt_head('Creditor Invoice','Per-creditor deduction invoice — '.date('d/m/Y',strtotime($date_f)).' to '.date('d/m/Y',strtotime($date_f)));
    $ci_url="?date=".urlencode($date_f)."&report=cred_invoice&dl=1";
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="<?=$ci_url?>" class="btn btn-primary btn-sm">⬇ Download Creditor Invoice (.txt)</a>
    </div><?php
    $rows_by_cred=[];
    while($so_q && $r=$so_q->fetch_assoc()) $rows_by_cred[$r['cred_no']][]=$r;
    ?>
    <?php foreach ($rows_by_cred as $cno=>$crows): $cr=$crows[0]; $ct=sum_col($crows,'amount'); ?>
    <div style="padding:0.65rem 1.15rem;background:var(--cream);border-top:1px solid var(--border)">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:0.5rem">
        <div>
            <div style="font-size:0.72rem;font-weight:500;color:var(--muted)">CREDITOR NUMBER: <span style="font-family:monospace;color:var(--ink)"><?=$cno?></span></div>
            <div class="fw-500"><?=htmlspecialchars($cr['cred_name'])?></div>
            <div style="font-size:0.72rem;color:var(--muted)"><?=htmlspecialchars($cr['phone']??'')?>  <?=$cr['account_no']?'· Acc: '.htmlspecialchars($cr['account_no']):''?></div>
        </div>
        <div style="text-align:right"><div class="fw-500"><?=m($ct)?></div>
            <?php if($zig_rate>0):?><div style="font-size:0.72rem;color:var(--muted)">ZiG <?=number_format($ct*$zig_rate,2)?></div><?php endif;?>
        </div>
        </div>
    </div>
    <div class="table-wrap"><table class="rpt-table">
    <thead><tr><th>Sale Date</th><th>Grower #</th><th>Grower Name</th>
        <th class="text-right">USD</th><th class="text-right">ZiG</th>
    </tr></thead><tbody>
    <?php foreach ($crows as $r): ?>
    <tr>
        <td class="td-mono" style="font-size:0.75rem"><?=date('d/m/Y',strtotime($r['sell_date']))?></td>
        <td><span class="badge badge-gold" style="font-size:0.62rem"><?=htmlspecialchars($r['grower_num'])?></span></td>
        <td style="font-size:0.8rem"><?=htmlspecialchars($r['name'].' '.$r['surname'])?></td>
        <td class="td-mono text-right"><?=money($r['amount'])?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($r['amount']*$zig_rate,2):'—'?></td>
    </tr>
    <?php endforeach;?>
    </tbody><tfoot><tr>
        <td colspan="3">TOTAL — <?=count($crows)?> growers</td>
        <td class="td-mono text-right"><?=m($ct)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($ct*$zig_rate,2):'—'?></td>
    </tr></tfoot></table></div>
    <?php endforeach;
    if(empty($rows_by_cred)) echo '<div style="padding:2rem;text-align:center;color:var(--muted)">No creditor deductions for this date.</div>';
    rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// YTD CREDITOR INVOICE
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='cred_ytd'):
    $so_q=$conn->query("
        SELECT st.creditor_no AS cred_no, COALESCE(tcs.creditor_name,'Unknown') AS cred_name,
               COUNT(*) entries, SUM(st.amount_1+st.amount_2+st.amount_3) total_usd
        FROM stoporders st JOIN growers g ON g.id=st.growerid
        LEFT JOIN grower_creditors tcs ON tcs.creditor_no=st.creditor_no
        WHERE g.selling_pointid=$spid AND YEAR(st.formatted_sos_date)=YEAR('$date_f') AND st.active=1
        GROUP BY st.creditor_no ORDER BY total_usd DESC
    ");
    $dl_url="?date=".urlencode($date_f)."&report=cred_ytd&dl=1".($buyer_f?"&buyer=$buyer_f":"").($loc_f?"&loc=$loc_f":"");
    rpt_head('YTD Creditor Invoice','Year-to-date deductions per creditor — '.date('Y',strtotime($date_f)));
    $cy_url="?date=".urlencode($date_f)."&report=cred_ytd&dl=1";
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="<?=$cy_url?>" class="btn btn-primary btn-sm">⬇ Download YTD Creditor Invoice (.csv)</a>
    </div><?php
    ?><div class="table-wrap"><table class="rpt-table">
    <thead><tr><th>Creditor #</th><th>Creditor Name</th>
        <th class="text-right">Entries</th><th class="text-right">Total USD</th><th class="text-right">Total ZiG</th>
    </tr></thead><tbody>
    <?php $gt=0; while($so_q && $r=$so_q->fetch_assoc()): $gt+=$r['total_usd']; ?>
    <tr>
        <td class="td-mono fw-500" style="font-size:0.78rem"><?=htmlspecialchars($r['cred_no'])?></td>
        <td style="font-size:0.82rem"><?=htmlspecialchars($r['cred_name'])?></td>
        <td class="td-mono text-right"><?=number_format($r['entries'])?></td>
        <td class="td-mono text-right fw-500"><?=m($r['total_usd'])?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($r['total_usd']*$zig_rate,2):'—'?></td>
    </tr>
    <?php endwhile;?>
    </tbody><tfoot><tr>
        <td colspan="3">TOTAL</td>
        <td class="td-mono text-right"><?=m($gt)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($gt*$zig_rate,2):'—'?></td>
    </tr></tfoot></table></div>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// CREDITOR INVOICE SUMMARY — matches HU_USD_CREDITOR_INVOICE_SUMMARY format
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='cred_summary'):
    $so_q=$conn->query("
        SELECT st.creditor_no AS cred_no, COALESCE(tcs.creditor_name,'Unknown') AS cred_name,
               SUM(st.amount_1+st.amount_2+st.amount_3) total_collected
        FROM stoporders st JOIN growers g ON g.id=st.growerid
        LEFT JOIN grower_creditors tcs ON tcs.creditor_no=st.creditor_no
        WHERE g.selling_pointid=$spid AND st.active=1
        GROUP BY st.creditor_no ORDER BY CAST(st.creditor_no AS UNSIGNED) ASC
    ");
    // commission = 0.667% of collected, service charge = 2% of collected (typical TIMB rates)
    $comm_rate=0.00667; $svc_rate=0.02;
    rpt_head('Creditor Invoice Summary','Currency: USD &nbsp;·&nbsp; '.date('d/m/Y',strtotime($date_f)));
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="?date=<?=urlencode($date_f)?>&report=cred_summary&dl=1" class="btn btn-primary btn-sm">⬇ Download Creditor Summary (.txt)</a>
    </div><?php
    ?><div style="padding:0.85rem 1.15rem;font-size:0.78rem;color:var(--muted)">
        <strong style="color:var(--ink)">CURRENCY:</strong> USD &nbsp;&nbsp;
        <strong style="color:var(--ink)">SELLING POINT:</strong> <?=$sp_name?>
    </div>
    <div class="table-wrap" style="overflow-x:auto"><table class="rpt-table">
    <thead><tr>
        <th>Creditor #</th><th>Creditor Name</th>
        <th class="text-right">Total Collected</th><th class="text-right">SO Commission</th>
        <th class="text-right">Service Charge</th><th class="text-right">Balance Due</th>
    </tr></thead><tbody>
    <?php $gc=$gco=$gsc=$gb2=0; while($so_q && $r=$so_q->fetch_assoc()):
        $tc=(float)$r['total_collected'];
        $co=round($tc*$comm_rate,2);$sc=round($tc*$svc_rate,2);$bal=$tc-$co-$sc;
        $gc+=$tc;$gco+=$co;$gsc+=$sc;$gb2+=$bal;
    ?>
    <tr>
        <td class="td-mono fw-500" style="font-size:0.78rem"><?=htmlspecialchars($r['cred_no'])?></td>
        <td style="font-size:0.8rem;max-width:200px"><?=htmlspecialchars(substr($r['cred_name'],0,32))?></td>
        <td class="td-mono text-right"><?=money($tc)?></td>
        <td class="td-mono text-right"><?=money($co)?></td>
        <td class="td-mono text-right"><?=money($sc)?></td>
        <td class="td-mono text-right fw-500"><?=money($bal)?></td>
    </tr>
    <?php endwhile;?>
    </tbody><tfoot><tr>
        <td colspan="2">TOTALS</td>
        <td class="td-mono text-right"><?=m($gc)?></td>
        <td class="td-mono text-right"><?=m($gco)?></td>
        <td class="td-mono text-right"><?=m($gsc)?></td>
        <td class="td-mono text-right"><?=m($gb2)?></td>
    </tr></tfoot></table></div>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// COLLECTION ADVICE
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='cred_collection'):
    $bales=load_bales($conn,$date_f,$spid,0,$loc_f);  // grower/creditor report — buyer filter not applied
    $gids=implode(',',array_map('intval',array_unique(array_column($bales,'grower_id'))))?:'0';
    $so_q=$conn->query("
        SELECT tcs.creditor_no, COALESCE(tcs.creditor_name,'Unknown') AS cred_name,
               tcs.account_num AS account_no, tcs.creditor_ref AS phone, tcs.business_type,
               g.grower_num, g.name, g.surname, (st.amount_1+st.amount_2+st.amount_3) AS amount
        FROM stoporders st JOIN growers g ON g.id=st.growerid
        LEFT JOIN grower_creditors tcs ON tcs.creditor_no=st.creditor_no
        WHERE g.id IN ($gids) AND st.active=1
        ORDER BY g.grower_num ASC, tcs.creditor_no ASC
    ");
    rpt_head('Creditor Collection Advice','Payment collection per grower per creditor');
    // List grower charges with deductions for this date — each downloadable as its own PDF
    $de_cc=$conn->real_escape_string($date_f);
    $sp_cc=$spid>0?"(g.selling_pointid=$spid OR g.selling_pointid=0)":"1=1";
    $chg_q=$conn->query("SELECT gc.id gcid, s.description levy, COUNT(*) line_count,
        SUM(bbc.usd_value) usd, SUM(bbc.zim_value) zwl
      FROM balanced_bales_charges bbc
      JOIN grower_charges gc ON gc.id=bbc.grower_chargeid
      LEFT JOIN statutory s ON s.id=gc.statutoryid
      JOIN balanced_bales bb ON bb.id=bbc.balanced_balesid
      JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
      JOIN growers g ON g.id=bb.growerid
      WHERE $sp_cc AND COALESCE(tg.sell_date,DATE(bb.created_at))='$de_cc'
      GROUP BY gc.id ORDER BY s.description");
    $chg=($chg_q&&$chg_q!==true)?$chg_q->fetch_all(MYSQLI_ASSOC):[];
    ?>
    <div class="card" style="margin:1rem 1.15rem"><div class="card-head"><h3>Grower Charges — choose one to download</h3></div>
    <div class="table-wrap"><table>
    <thead><tr><th>Charge / Levy</th><th class="text-right">Growers</th><th class="text-right">USD</th><th class="text-right">RTGS</th><th></th></tr></thead>
    <tbody>
    <?php if(!$chg):?><tr><td colspan="5" class="text-center" style="padding:1.5rem;color:var(--muted)">No grower charges deducted on this date.</td></tr>
    <?php else: foreach($chg as $c):?>
    <tr>
      <td style="font-weight:500"><?=htmlspecialchars($c['levy']?:'Charge '.$c['gcid'])?></td>
      <td class="text-right"><?=(int)$c['line_count']?></td>
      <td class="text-right"><?=number_format($c['usd'],2)?></td>
      <td class="text-right"><?=number_format($c['zwl'],2)?></td>
      <td class="text-right"><a href="?date=<?=urlencode($date_f)?>&report=cred_collection&dl=1&charge=<?=$c['gcid']?>" class="btn btn-primary btn-sm">⬇ PDF</a></td>
    </tr>
    <?php endforeach; endif;?>
    </tbody></table></div></div>
    <?php rpt_foot(); include "includes/layout_end.php"; exit; // chooser replaces the old per-creditor view
    rpt_head('Creditor Collection Advice','');
    $by_grower=[];
    while($so_q && $r=$so_q->fetch_assoc()) $by_grower[$r['grower_num']][]=$r;
    ?>
    <?php foreach ($by_grower as $gn=>$grows): $r0=$grows[0]; $gt=sum_col($grows,'amount'); ?>
    <div style="padding:0.65rem 1.15rem;border-top:1px solid var(--border)">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem">
            <div>
                <span class="badge badge-gold"><?=htmlspecialchars($gn)?></span>
                <span class="fw-500" style="margin-left:6px;font-size:0.88rem"><?=htmlspecialchars($r0['name'].' '.$r0['surname'])?></span>
            </div>
            <div style="text-align:right">
                <span class="fw-500"><?=m($gt)?></span>
                <?php if($zig_rate>0):?><span style="font-size:0.7rem;color:var(--muted);margin-left:6px">ZiG <?=number_format($gt*$zig_rate,2)?></span><?php endif;?>
            </div>
        </div>
        <table style="width:100%;font-size:0.75rem;border-collapse:collapse;margin-top:0.4rem">
        <?php foreach ($grows as $r): ?>
        <tr style="border-top:1px solid rgba(26,18,8,0.05)">
            <td style="padding:3px 0;color:var(--muted)">
                <span class="badge badge-gray" style="font-size:0.6rem;margin-right:4px"><?=htmlspecialchars($r['creditor_no']??'—')?></span>
                <?=htmlspecialchars($r['cred_name'])?>
                <?php if($r['phone']): ?><span style="color:var(--muted);margin-left:6px;font-size:0.7rem"><?=htmlspecialchars($r['phone'])?></span><?php endif;?>
            </td>
            <td style="padding:3px 0;text-align:right;font-family:monospace;font-weight:500"><?=m($r['amount'])?></td>
        </tr>
        <?php endforeach;?>
        </table>
    </div>
    <?php endforeach;
    if(empty($by_grower)) echo '<div style="padding:2rem;text-align:center;color:var(--muted)">No stop orders found.</div>';
    rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// REJECTED BALES SUMMARY — matches PDF sample
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='rejected_summary'):
    $bales=load_bales($conn,$date_f,$spid,0,$loc_f);       // rejected report — buyer filter not applied
    $bales_ytd=load_bales($conn,$date_f,$spid,0,$loc_f,true);
    $rejected=array_filter($bales,fn($b)=>$b['is_rej']);
    $rejected_ytd=array_filter($bales_ytd,fn($b)=>$b['is_rej']);
    rpt_head('Rejected Bales Summary','Daily & Year-to-Date');
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="?date=<?=urlencode($date_f)?>&report=rejected_summary&dl=1" class="btn btn-primary btn-sm">⬇ Download Rejected Bales (.pdf)</a>
    </div><?php

    function rej_table($rows,$label) {
        $by_grade=[];
        foreach ($rows as $b) {
            $g=strtoupper(trim($b['sale_code']??$b['timb_grade']??'Unknown'));
            if(!isset($by_grade[$g]))$by_grade[$g]=['cnt'=>0,'mass'=>0];
            $by_grade[$g]['cnt']++;$by_grade[$g]['mass']+=$b['mass'];
        }
        ?>
        <div style="padding:0.6rem 1.15rem;background:var(--cream-d);border-top:1px solid var(--border)">
            <span style="font-size:0.7rem;font-weight:500;letter-spacing:0.08em;text-transform:uppercase;color:var(--muted)"><?=$label?></span>
        </div>
        <div class="table-wrap"><table class="rpt-table">
        <thead><tr><th>TIMB Reason</th><th class="text-right">Bale Count</th><th class="text-right">Mass (kg)</th></tr></thead>
        <tbody>
        <?php if(empty($by_grade)):?>
        <tr><td colspan="3" class="text-center" style="color:var(--muted);padding:1rem">No rejections</td></tr>
        <?php else: foreach ($by_grade as $grade=>$d): ?>
        <tr>
            <td><span class="badge badge-red" style="font-size:0.62rem"><?=htmlspecialchars($grade)?></span></td>
            <td class="td-mono text-right"><?=$d['cnt']?></td>
            <td class="td-mono text-right"><?=money($d['mass'],1)?></td>
        </tr>
        <?php endforeach; endif;?>
        </tbody>
        <tfoot><tr>
            <td>Totals</td>
            <td class="td-mono text-right"><?=count($rows)?></td>
            <td class="td-mono text-right"><?=money(sum_col(array_values($rows),'mass'),1)?></td>
        </tr></tfoot>
        </table></div>
        <?php
    }
    rej_table($rejected, 'Daily Rejected Bales Summary — '.date('d-m-Y',strtotime($date_f)));
    rej_table($rejected_ytd, 'Year to Date Rejected Bales Summary');
    rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// REVENUE REPORT — matches Revenue Report.html structure
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='revenue_report'):
    $bales=load_bales($conn,$date_f,$spid,$buyer_f,$loc_f);
    $sold=array_filter($bales,fn($b)=>!$b['is_rej']);
    $gross=sum_col($sold,'usd_value'); $gross_zig=sum_col($sold,'zig_value');
    $bales_laid=count($bales); $bales_sold=count($sold);
    $mass=sum_col($sold,'mass');
    // Grower service charge = 1.6% of gross (configurable — typical TIMB rate)
    $svc_charge=round($gross*0.016,2);
    // Floor clearing = 0.39c per kg
    $floor_clearing=round($mass*0.0039,2);
    // Cred service charge
    $bales_gids=implode(',',array_map('intval',array_unique(array_column($bales,'grower_id'))))?:'0';
    $ded_q_qr = $conn->query("SELECT COALESCE(SUM((st.amount_1+st.amount_2+st.amount_3)*0.02),0) csc FROM stoporders st JOIN growers g ON g.id=st.growerid WHERE g.id IN ($bales_gids) AND st.active=1"); $ded_q = ($ded_q_qr && $ded_q_qr !== true) ? $ded_q_qr->fetch_assoc() : [];
    $cred_svc=(float)($ded_q['csc']??0);
    $total_revenue=$svc_charge+$floor_clearing+$cred_svc;
    $net_cheque=$gross-$total_revenue;
    rpt_head('Detailed Revenue Summary','US$ & ZiG Revenue');
    $rv_from=$_GET['from'] ?? $date_f; $rv_to=$_GET['to'] ?? $date_f;
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;border-bottom:1px solid var(--border);flex-wrap:wrap">
      <form method="GET" style="display:flex;gap:.5rem;align-items:center">
        <input type="hidden" name="report" value="revenue_report">
        <label style="font-size:.75rem;color:var(--muted)">From</label>
        <input type="date" name="from" value="<?=htmlspecialchars($rv_from)?>" class="form-control" style="width:auto;font-size:.8rem">
        <label style="font-size:.75rem;color:var(--muted)">To</label>
        <input type="date" name="to" value="<?=htmlspecialchars($rv_to)?>" class="form-control" style="width:auto;font-size:.8rem">
        <button class="btn btn-ghost btn-sm">Apply</button>
      </form>
      <a href="?report=revenue_report&dl=1&from=<?=urlencode($rv_from)?>&to=<?=urlencode($rv_to)?>" class="btn btn-primary btn-sm">⬇ Download Revenue (.xlsx)</a>
    </div><?php

    function revenue_table_row($label, $vals, $bold=false) {
        $s=$bold?'font-weight:500':'';
        echo "<tr style='border-bottom:1px solid var(--border)'>";
        echo "<td style='padding:0.45rem 0.9rem;$s'>".htmlspecialchars($label)."</td>";
        foreach ($vals as $v) {
            echo "<td style='padding:0.45rem 0.9rem;font-family:monospace;text-align:right;$s'>".htmlspecialchars($v)."</td>";
        }
        echo "</tr>";
    }
    ?>
    <!-- USD Revenue -->
    <div style="padding:0.75rem 1.15rem;border-top:1px solid var(--border)">
        <h5 style="font-size:0.8rem;font-weight:500;text-transform:uppercase;letter-spacing:0.08em">US$ Revenue</h5>
    </div>
    <div class="table-wrap" style="overflow-x:auto">
    <table class="rpt-table" style="min-width:800px">
    <thead><tr>
        <th>Date</th><th class="text-right">Bales Laid</th><th class="text-right">Bales Sold</th>
        <th class="text-right">Mass Sold</th><th class="text-right">Gross Value</th>
        <th class="text-right">Net Cheque</th><th class="text-right">Floor Comm</th>
        <th class="text-right">W/A</th><th class="text-right">Floor Clearing</th>
        <th class="text-right">Grower Svc</th><th class="text-right">Cred Svc</th>
        <th class="text-right">Total Revenue</th>
    </tr></thead>
    <tbody>
    <?php revenue_table_row(date('Y-m-d',strtotime($date_f)),
        [number_format($bales_laid),number_format($bales_sold),number_format($mass),
         money($gross),money($net_cheque),money(0),money(0),
         money($floor_clearing),money($svc_charge),money($cred_svc),money($total_revenue)]);
    ?>
    </tbody>
    <tfoot><tr>
        <td style="padding:0.5rem 0.9rem;font-weight:500">TOTALS</td>
        <?php foreach ([number_format($bales_laid),number_format($bales_sold),number_format($mass),
            money($gross),money($net_cheque),'0.00','0.00',
            money($floor_clearing),money($svc_charge),money($cred_svc),money($total_revenue)] as $v): ?>
        <td style="padding:0.5rem 0.9rem;font-family:monospace;text-align:right;font-weight:500"><?=htmlspecialchars($v)?></td>
        <?php endforeach;?>
    </tr></tfoot>
    </table></div>

    <?php if($zig_rate>0): $net_zig=$gross_zig-($total_revenue*$zig_rate); ?>
    <div style="padding:0.75rem 1.15rem;border-top:1px solid var(--border)">
        <h5 style="font-size:0.8rem;font-weight:500;text-transform:uppercase;letter-spacing:0.08em">ZiG Revenue</h5>
    </div>
    <div class="table-wrap"><table class="rpt-table">
    <thead><tr>
        <th>Date</th><th class="text-right">Bales Laid</th><th class="text-right">Bales Sold</th>
        <th class="text-right">Mass Sold</th><th class="text-right">Gross ZiG</th>
        <th class="text-right">Net Cheque ZiG</th><th class="text-right">Floor Comm</th>
        <th class="text-right">W/A</th><th class="text-right">Floor Clearing</th>
        <th class="text-right">Grower Svc</th><th class="text-right">Total Revenue</th>
    </tr></thead>
    <tfoot><tr>
        <td style="padding:0.5rem 0.9rem;font-weight:500">TOTALS</td>
        <?php foreach ([number_format($bales_laid),number_format($bales_sold),number_format($mass),
            number_format($gross_zig,2),number_format($net_zig,2),'0.00','0.00',
            number_format($floor_clearing*$zig_rate,2),number_format($svc_charge*$zig_rate,2),
            number_format($total_revenue*$zig_rate,2)] as $v): ?>
        <td style="padding:0.5rem 0.9rem;font-family:monospace;text-align:right;font-weight:500"><?=htmlspecialchars($v)?></td>
        <?php endforeach;?>
    </tr></tfoot>
    </table></div>
    <?php endif; rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// FLOOR RECONCILIATION — matches Floor_recon.html
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='floor_recon'||$report==='daily_floor_recon'):
    $dates_q=$conn->query("SELECT DISTINCT COALESCE(tg.sell_date, DATE(tk.created_at)) AS sd FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN transporter_growers tg ON tg.id=bj.transporter_growersid JOIN growers g ON g.id=bj.growerid WHERE g.selling_pointid=$spid ORDER BY sd DESC LIMIT 60");
    rpt_head('Floor Reconciliation','All sell dates — '.date('Y',strtotime($date_f)));
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="?date=<?=urlencode($date_f)?>&report=<?=htmlspecialchars($report)?>&dl=1" class="btn btn-primary btn-sm">⬇ Download Floor Reconciliation (.xlsx)</a>
    </div><?php
    ?><div class="table-wrap" style="overflow-x:auto">
    <table class="rpt-table" style="min-width:900px">
    <thead><tr>
        <th>Date</th><th class="text-right">Laid</th><th class="text-right">Sold</th>
        <th class="text-right">Mass Sold</th><th class="text-right">Gross USD</th>
        <th class="text-right">Net Amount</th><th class="text-right">Floor Comm</th>
        <th class="text-right">W/A</th><th class="text-right">Aff Levy</th>
        <th class="text-right">MOA Levy</th><th class="text-right">Stop Orders</th>
        <th class="text-right">SO Comm</th><th class="text-right">Svc Charge</th>
        <th class="text-right">Recon</th>
    </tr></thead><tbody>
    <?php
    $gtl=$gts=$gtm=$gtg=$gtn=$gta=$gtm2=$gts2=$gtso=$gtsc=$gtsvc=0;
    while ($dates_q && $dr=$dates_q->fetch_assoc()):
        $d=$dr['sd']; $de=$conn->real_escape_string($d);
        $bq_qr = $conn->query("SELECT COUNT(*) laid, SUM(CASE WHEN sb.id IS NOT NULL THEN 1 ELSE 0 END) sold, SUM(CASE WHEN sb.id IS NOT NULL THEN bm.mass ELSE 0 END) mass, SUM(CASE WHEN sb.id IS NOT NULL THEN (CASE WHEN CAST(sb.price AS DECIMAL(12,4))>10 THEN CAST(sb.price AS DECIMAL(12,4))/100 ELSE CAST(sb.price AS DECIMAL(12,4)) END)*bm.mass ELSE 0 END) gross FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN transporter_growers tg ON tg.id=bj.transporter_growersid JOIN bale_mass bm ON bm.id=bj.bale_massid JOIN growers g ON g.id=bj.growerid LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id WHERE g.selling_pointid=$spid AND COALESCE(tg.sell_date, DATE(tk.created_at))='$de'"); $bq = ($bq_qr && $bq_qr !== true) ? $bq_qr->fetch_assoc() : [];
        $laid=(int)$bq['laid'];$sold=(int)$bq['sold'];$mass=(float)$bq['mass'];
        $gross=(float)$bq['gross'];
        $gids2_qr = $conn->query("SELECT DISTINCT g.id FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN transporter_growers tg ON tg.id=bj.transporter_growersid JOIN growers g ON g.id=bj.growerid WHERE g.selling_pointid=$spid AND COALESCE(tg.sell_date, DATE(tk.created_at))='$de'"); $gids2 = ($gids2_qr && $gids2_qr !== true) ? $gids2_qr->fetch_all(MYSQLI_ASSOC) : [];
        $gidstr=implode(',',array_column($gids2,'id'))?:'0';
        $so_q=$conn->query("SELECT COALESCE(SUM(amount_1+amount_2+amount_3),0) t FROM stoporders WHERE growerid IN ($gidstr) AND active=1");$so_total=$so_q&&$so_q!==true?(float)($so_q->fetch_assoc()['t']??0):0;
        $so_comm=round($so_total*0.00667,2);
        $svc=round($gross*0.016,2);
        $aff=round($mass*0.0165,2); // typical aff levy
        $moa=round($mass*0.028,2);  // typical moa levy
        $net=$gross-$aff-$moa-$so_total-$so_comm-$svc;
        $recon=$gross-($aff+$moa+$so_total+$so_comm+$svc+$net);
        $gtl+=$laid;$gts+=$sold;$gtm+=$mass;$gtg+=$gross;$gtn+=$net;
        $gta+=$aff;$gtm2+=$moa;$gtso+=$so_total;$gtsc+=$so_comm;$gtsvc+=$svc;
        $is_cur=($d===$date_f);
    ?>
    <tr style="<?=$is_cur?'background:var(--gold-bg);font-weight:500':''?>">
        <td style="font-size:0.78rem"><?=date('Y-m-d',strtotime($d))?></td>
        <td class="td-mono text-right"><?=$laid?></td>
        <td class="td-mono text-right"><?=$sold?></td>
        <td class="td-mono text-right"><?=number_format($mass)?></td>
        <td class="td-mono text-right"><?=money($gross)?></td>
        <td class="td-mono text-right fw-500"><?=money($net)?></td>
        <td class="td-mono text-right">0.00</td>
        <td class="td-mono text-right">0.00</td>
        <td class="td-mono text-right"><?=money($aff)?></td>
        <td class="td-mono text-right"><?=money($moa)?></td>
        <td class="td-mono text-right"><?=money($so_total)?></td>
        <td class="td-mono text-right"><?=money($so_comm)?></td>
        <td class="td-mono text-right"><?=money($svc)?></td>
        <td class="td-mono text-right <?=abs($recon)<0.01?'recon-ok':($recon>0?'recon-warn':'recon-zero')?>"><?=money(abs($recon))?></td>
    </tr>
    <?php endwhile;?>
    </tbody><tfoot><tr>
        <td>TOTALS</td>
        <td class="td-mono text-right"><?=number_format($gtl)?></td>
        <td class="td-mono text-right"><?=number_format($gts)?></td>
        <td class="td-mono text-right"><?=number_format($gtm)?></td>
        <td class="td-mono text-right"><?=money($gtg)?></td>
        <td class="td-mono text-right"><?=money($gtn)?></td>
        <td class="td-mono text-right">0.00</td>
        <td class="td-mono text-right">0.00</td>
        <td class="td-mono text-right"><?=money($gta)?></td>
        <td class="td-mono text-right"><?=money($gtm2)?></td>
        <td class="td-mono text-right"><?=money($gtso)?></td>
        <td class="td-mono text-right"><?=money($gtsc)?></td>
        <td class="td-mono text-right"><?=money($gtsvc)?></td>
        <td></td>
    </tr></tfoot>
    </table></div>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// SALES CONTROL — matches Sales_Control.html
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='sales_control'):
    $bales=load_bales($conn,$date_f,$spid,$buyer_f,$loc_f);
    $sold=array_filter($bales,fn($b)=>!$b['is_rej']);
    $gross=sum_col($sold,'usd_value');$mass=sum_col($sold,'mass');
    $gids=implode(',',array_map('intval',array_unique(array_column($bales,'grower_id'))))?:'0';
    // Get all deductions
    $ded_q=$conn->query("SELECT st.creditor_no AS cred_no, COALESCE(tcs.creditor_name,'Unknown') AS cred_name, SUM(st.amount_1+st.amount_2+st.amount_3) total FROM stoporders st JOIN growers g ON g.id=st.growerid LEFT JOIN grower_creditors tcs ON tcs.creditor_no=st.creditor_no WHERE g.id IN ($gids) AND st.active=1 GROUP BY st.creditor_no ORDER BY total DESC");
    $deductions=[];$total_ded=0;
    while($ded_q && $r=$ded_q->fetch_assoc()){$deductions[]=$r;$total_ded+=$r['total'];}
    $iso_q=$conn->query("SELECT COALESCE(charge_type,'other') AS t, SUM(amount) total FROM internal_stoporders WHERE growerid IN ($gids) AND sell_date='$date_f' AND active=1 GROUP BY charge_type");
    while($iso_q && $r=$iso_q->fetch_assoc()){$deductions[]=['cred_name'=>'Internal: '.$r['t'],'total'=>$r['total']];$total_ded+=$r['total'];}
    // Levies
    $aff=round($mass*0.0165,2);$moa=round($mass*0.028,2);$svc=round($gross*0.016,2);
    $total_all_ded=$total_ded+$aff+$moa+$svc;
    $net_paid=$gross-$total_all_ded;
    rpt_head('Sales Control','Gross to net reconciliation — '.date('d/m/Y',strtotime($date_f)));
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="?date=<?=urlencode($date_f)?>&report=sales_control&dl=1" class="btn btn-primary btn-sm">⬇ Download Sales Control (.xlsx)</a>
    </div><?php
    ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0">
    <!-- Left: summary -->
    <div style="padding:1rem 1.15rem;border-right:1px solid var(--border)">
    <table style="width:100%;font-size:0.8rem;border-collapse:collapse">
    <tr><td style="padding:4px 0;color:var(--muted)">Sale Date</td><td style="text-align:right;font-weight:500;font-family:monospace"><?=date('Y-m-d',strtotime($date_f))?></td></tr>
    <tr><td style="padding:4px 0;color:var(--muted)">Exchange Rate</td><td style="text-align:right;font-family:monospace"><?=$zig_rate?></td></tr>
    <tr><td style="padding:4px 0;color:var(--muted)">Total Bales Laid</td><td style="text-align:right;font-family:monospace"><?=count($bales)?></td></tr>
    <tr><td style="padding:4px 0;color:var(--muted)">Total Bales Sold</td><td style="text-align:right;font-family:monospace fw-500"><?=count($sold)?></td></tr>
    <tr><td style="padding:4px 0;color:var(--muted)">Gross Value</td><td style="text-align:right;font-weight:500;font-family:monospace"><?=m($gross)?></td></tr>
    <tr><td style="padding:4px 0;color:var(--muted)">Total Sold Mass</td><td style="text-align:right;font-family:monospace"><?=number_format($mass)?></td></tr>
    </table>
    </div>
    <!-- Right: deductions -->
    <div style="padding:1rem 1.15rem">
    <table style="width:100%;font-size:0.78rem;border-collapse:collapse">
    <tr style="border-bottom:1px solid var(--border)">
        <td colspan="3" style="padding:4px 0;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--muted)">Description</td>
        <td style="text-align:right;padding:4px 0;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--muted)">USD</td>
        <td style="text-align:right;padding:4px 0;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--muted)">ZiG</td>
    </tr>
    <tr><td colspan="3" style="padding:6px 0 2px;font-size:0.68rem;color:var(--muted);text-transform:uppercase">Statutory Deductions</td><td colspan="2"></td></tr>
    <?php foreach ([['Floor Commission',0],['Weighing and Auction',0],['MOA Levy',$moa],['Afforestation Levy',$aff],['Service Charge Growers',$svc]] as [$n,$v]):?>
    <tr style="border-bottom:1px solid rgba(26,18,8,0.04)">
        <td colspan="3" style="padding:3px 0"><?=$n?></td>
        <td style="text-align:right;font-family:monospace"><?=money($v)?></td>
        <td style="text-align:right;font-family:monospace;color:var(--muted)"><?=$zig_rate>0?number_format($v*$zig_rate,2):'—'?></td>
    </tr>
    <?php endforeach;?>
    <tr><td colspan="3" style="padding:6px 0 2px;font-size:0.68rem;color:var(--muted);text-transform:uppercase">Stop Order & Invoice Deductions</td><td colspan="2"></td></tr>
    <?php foreach ($deductions as $d):?>
    <tr style="border-bottom:1px solid rgba(26,18,8,0.04)">
        <td colspan="3" style="padding:3px 0"><?=htmlspecialchars(substr($d['cred_name'],0,40))?></td>
        <td style="text-align:right;font-family:monospace"><?=money($d['total'])?></td>
        <td style="text-align:right;font-family:monospace;color:var(--muted)"><?=$zig_rate>0?number_format($d['total']*$zig_rate,2):'—'?></td>
    </tr>
    <?php endforeach;?>
    <tr style="border-top:2px solid var(--border);font-weight:500">
        <td colspan="3" style="padding:5px 0">Net Paid to Growers</td>
        <td style="text-align:right;font-family:monospace"><?=money($net_paid)?></td>
        <td style="text-align:right;font-family:monospace"><?=$zig_rate>0?number_format($net_paid*$zig_rate,2):'—'?></td>
    </tr>
    <tr style="border-top:1px solid var(--border);font-weight:500;background:var(--cream)">
        <td colspan="3" style="padding:5px 0">TOTAL</td>
        <td style="text-align:right;font-family:monospace"><?=money($gross)?></td>
        <td style="text-align:right;font-family:monospace"><?=$zig_rate>0?number_format($gross*$zig_rate,2):'—'?></td>
    </tr>
    </table>
    </div>
    </div>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// INVOICE CONTROL — matches Invoice control.html
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='invoice_control'):
    // Per selling point / buyer: bales, USD value, deductions, service charge, paid, obligations
    $buyers_q=$conn->query("SELECT id,name,buyer_code FROM buyer WHERE selling_pointid=$spid ORDER BY name");
    rpt_head('Invoice Control','Buyer obligations — USD & ZiG');
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="?date=<?=urlencode($date_f)?>&report=invoice_control&dl=1" class="btn btn-primary btn-sm">⬇ Download Invoice Control (.xlsx)</a>
    </div><?php
    ?><div class="table-wrap" style="overflow-x:auto">
    <table class="rpt-table" style="min-width:1200px">
    <thead><tr>
        <th>Code</th><th>Name</th><th class="text-right">Paid Bales</th>
        <th class="text-right">US Value</th><th class="text-right">US Deductions</th>
        <th class="text-right">US Svc Charge</th><th class="text-right">US Paid</th>
        <th class="text-right">US Obligations</th>
        <th class="text-right">ZW Value</th><th class="text-right">ZW Paid</th>
        <th class="text-right">ZW Deductions</th><th class="text-right">ZW Svc Charge</th>
        <th class="text-right">Aff Levy</th><th class="text-right">MOA Levy</th>
        <th class="text-right">ZW Obligations</th>
    </tr></thead><tbody>
    <?php while ($buyers_q && $byr=$buyers_q->fetch_assoc()):
        if ($buyer_f !== '' && $byr['name'] !== $buyer_f) continue;
        $bid=(int)$byr['id'];
        $bb=load_bales($conn,$date_f,$spid,$bid,0);
        $sold_b=array_filter($bb,fn($b)=>!$b['is_rej']);
        $usd=sum_col($sold_b,'usd_value'); $zig=sum_col($sold_b,'zig_value'); $mass=sum_col($sold_b,'mass');
        $svc=round($usd*0.016,2); $aff=round($mass*0.0165,2); $moa=round($mass*0.028,2);
        $gids3=implode(',',array_map('intval',array_unique(array_column($bb,'grower_id'))))?:'0';
        $so_t_q=$conn->query("SELECT COALESCE(SUM(amount_1+amount_2+amount_3),0) t FROM stoporders WHERE growerid IN ($gids3) AND active=1");$so_t=$so_t_q&&$so_t_q!==true?(float)($so_t_q->fetch_assoc()['t']??0):0;
        $us_ded=$so_t+$svc; $zw_ded=$so_t*$zig_rate; $zw_svc=$svc*$zig_rate;
        $ps_q_qr = $conn->query("SELECT COALESCE(SUM(CASE WHEN ps.currency='usd' AND ps.status='paid' THEN ps.net_amount END),0) us_p, COALESCE(SUM(CASE WHEN ps.currency='zig' AND ps.status='paid' THEN ps.net_amount END),0) zw_p FROM payment_status ps JOIN transporter_growers tg ON tg.id=ps.transporter_growersid JOIN growers g ON g.id=tg.growerid WHERE g.selling_pointid=$spid AND ps.sell_date='$date_f'"); $ps_q = ($ps_q_qr && $ps_q_qr !== true) ? $ps_q_qr->fetch_assoc() : [];
        $us_paid=(float)($ps_q['us_p']??0);$zw_paid=(float)($ps_q['zw_p']??0);
        $us_obl=$us_ded+$us_paid; $zw_obl=$aff+$moa+$zw_ded+$zw_svc+$zw_paid;
        if(!count($sold_b))continue;
    ?>
    <tr>
        <td class="fw-500"><?=htmlspecialchars($byr['buyer_code']??'')?></td>
        <td style="font-size:0.82rem"><?=htmlspecialchars($byr['name'])?></td>
        <td class="td-mono text-right"><?=number_format(count($sold_b),2)?></td>
        <td class="td-mono text-right"><?=money($usd)?></td>
        <td class="td-mono text-right"><?=money($us_ded)?></td>
        <td class="td-mono text-right"><?=money($svc)?></td>
        <td class="td-mono text-right"><?=money($us_paid)?></td>
        <td class="td-mono text-right fw-500"><?=money($us_obl)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($zig,2):'—'?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($zw_paid,2):'—'?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($zw_ded,2):'—'?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($zw_svc,2):'—'?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($aff,2):'—'?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($moa,2):'—'?></td>
        <td class="td-mono text-right fw-500"><?=$zig_rate>0?number_format($zw_obl,2):'—'?></td>
    </tr>
    <?php endwhile;?>
    </tbody></table></div>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// PURCHASE SUMMARY — matches CSV format with CSV download
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='purchase_summary'):
    $dl_url="?date=".urlencode($date_f)."&report=purchase_summary&dl=1";
    rpt_head('Purchase Summary','Season-to-date — '.date('Y',strtotime($date_f)));
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="?date=<?=urlencode($date_f)?>&report=purchase_summary&dl=1" class="btn btn-primary btn-sm">⬇ Download Purchase Summary (.xlsx)</a>
    </div><?php
    ?>
    <div style="padding:0.75rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="<?=$dl_url?>" class="btn btn-ghost btn-sm">⬇ Download CSV</a>
    </div>
    <div class="table-wrap" style="overflow-x:auto">
    <table class="rpt-table" style="min-width:900px">
    <thead><tr>
        <th>Sale Date</th><th class="text-right">Laid</th><th class="text-right">Rejected</th>
        <th class="text-right">Sold</th><th class="text-right">Mass (kg)</th>
        <th class="text-right">USD Value</th><th class="text-right">Hi $/kg</th>
        <th class="text-right">Lo $/kg</th><th class="text-right">Avg $/kg</th>
        <th class="text-right">Exch Rate</th><th>Rejection Reasons</th>
    </tr></thead><tbody>
    <?php
    $dates=$conn->query("SELECT DISTINCT COALESCE(tg.sell_date, DATE(tk.created_at)) AS sd FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN transporter_growers tg ON tg.id=bj.transporter_growersid JOIN growers g ON g.id=bj.growerid WHERE g.selling_pointid=$spid ORDER BY sd DESC LIMIT 60");
    $gtl=$gtr=$gts=$gtm=$gtu=0;
    while ($dates && $dr=$dates->fetch_assoc()):
        $d=$dr['sd'];$de=$conn->real_escape_string($d);
        $dq_qr = $conn->query("SELECT COUNT(*) laid, SUM(CASE WHEN sb.id IS NOT NULL THEN 1 ELSE 0 END) sold, SUM(bm.mass) mass, SUM(CASE WHEN sb.id IS NOT NULL THEN (CASE WHEN CAST(sb.price AS DECIMAL(12,4))>10 THEN CAST(sb.price AS DECIMAL(12,4))/100 ELSE CAST(sb.price AS DECIMAL(12,4)) END)*bm.mass ELSE 0 END) gross, MAX(CASE WHEN CAST(sb.price AS DECIMAL(12,4))>10 THEN CAST(sb.price AS DECIMAL(12,4))/100 ELSE CAST(sb.price AS DECIMAL(12,4)) END) hi, MIN(CASE WHEN CAST(sb.price AS DECIMAL(12,4))>0 THEN (CASE WHEN CAST(sb.price AS DECIMAL(12,4))>10 THEN CAST(sb.price AS DECIMAL(12,4))/100 ELSE CAST(sb.price AS DECIMAL(12,4)) END) END) lo, AVG(CASE WHEN CAST(sb.price AS DECIMAL(12,4))>0 THEN (CASE WHEN CAST(sb.price AS DECIMAL(12,4))>10 THEN CAST(sb.price AS DECIMAL(12,4))/100 ELSE CAST(sb.price AS DECIMAL(12,4)) END) END) avg FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN transporter_growers tg ON tg.id=bj.transporter_growersid JOIN bale_mass bm ON bm.id=bj.bale_massid JOIN growers g ON g.id=bj.growerid LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id WHERE g.selling_pointid=$spid AND COALESCE(tg.sell_date, DATE(tk.created_at))='$de'"); $dq = ($dq_qr && $dq_qr !== true) ? $dq_qr->fetch_assoc() : [];
        // Per-date exchange rate; fall back to latest active if not set for this date
        $er2_q=$conn->query("SELECT amount FROM exchange_rate WHERE sell_date='$de' AND active=1 ORDER BY id DESC LIMIT 1");
        $er2=($er2_q&&$er2_q!==true&&$er2_q->num_rows)?($er2_q->fetch_assoc()??['amount'=>0]):null;
        if(!$er2){$er2_q2=$conn->query("SELECT amount FROM exchange_rate WHERE active=1 ORDER BY id DESC LIMIT 1");$er2=($er2_q2&&$er2_q2!==true)?($er2_q2->fetch_assoc()??['amount'=>0]):['amount'=>0];}
        $laid=(int)$dq['laid'];$sold=(int)$dq['sold'];$rej=$laid-$sold;
        // Cents→USD conversion happens per price inside the SQL — totals are already correct
        $gross=(float)$dq['gross'];
        $hi=(float)$dq['hi'];$lo=(float)$dq['lo'];$avg=(float)$dq['avg'];
        $rq=$conn->query("SELECT sale_code,COUNT(*) c FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN transporter_growers tg ON tg.id=bj.transporter_growersid JOIN growers g ON g.id=bj.growerid WHERE g.selling_pointid=$spid AND COALESCE(tg.sell_date, DATE(tk.created_at))='$de' AND LEFT(UPPER(TRIM(sale_code)),1)='R' AND CHAR_LENGTH(TRIM(sale_code))<=3 GROUP BY sale_code");
        $reasons=[];while($rq && $rr=$rq->fetch_assoc())$reasons[]=$rr['sale_code'].': '.$rr['c'];
        $gtl+=$laid;$gtr+=$rej;$gts+=$sold;$gtm+=$dq['mass']??0;$gtu+=$gross;
        $is_cur=($d===$date_f);
    ?>
    <tr style="<?=$is_cur?'background:var(--gold-bg);font-weight:500':''?>">
        <td style="font-size:0.8rem"><?=date('d/m/Y',strtotime($d))?></td>
        <td class="td-mono text-right"><?=$laid?></td>
        <td class="td-mono text-right <?=$rej>0?'text-danger':''?>"><?=$rej?></td>
        <td class="td-mono text-right"><?=$sold?></td>
        <td class="td-mono text-right"><?=number_format($dq['mass']??0)?></td>
        <td class="td-mono text-right fw-500"><?=m($gross)?></td>
        <td class="td-mono text-right text-leaf"><?=m($hi)?></td>
        <td class="td-mono text-right text-danger"><?=m($lo)?></td>
        <td class="td-mono text-right"><?=m($avg,2)?></td>
        <td class="td-mono" style="font-size:0.72rem"><?=$er2['amount']?></td>
        <td style="font-size:0.72rem;color:var(--muted)"><?=htmlspecialchars(implode(', ',$reasons))?></td>
    </tr>
    <?php endwhile;?>
    </tbody><tfoot><tr>
        <td>TOTALS</td>
        <td class="td-mono text-right"><?=number_format($gtl)?></td>
        <td class="td-mono text-right"><?=number_format($gtr)?></td>
        <td class="td-mono text-right"><?=number_format($gts)?></td>
        <td class="td-mono text-right"><?=number_format($gtm)?></td>
        <td class="td-mono text-right"><?=m($gtu)?></td>
        <td colspan="5"></td>
    </tr></tfoot>
    </table></div>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// ORIGINATION REPORT — matches CSV format
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='origination'):
    $bales=load_bales($conn,$date_f,$spid,0,$loc_f);  // grower report — buyer filter not applied
    $dl_url="?date=".urlencode($date_f)."&report=origination&dl=1".($buyer_f?"&buyer=$buyer_f":"").($loc_f?"&loc=$loc_f":"");
    rpt_head('Origination Report','Bales sold by grower, area & province');
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="?date=<?=urlencode($date_f)?>&report=origination&dl=1" class="btn btn-primary btn-sm">⬇ Download Origination (.xlsx)</a>
    </div><?php
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="<?=$dl_url?>" class="btn btn-ghost btn-sm">⬇ Download CSV</a>
    </div>
    <div class="table-wrap" style="overflow-x:auto">
    <table class="rpt-table" style="min-width:1100px">
    <thead><tr>
        <th>Grower #</th><th>Name</th><th class="text-right">Laid</th><th class="text-right">Rej</th>
        <th class="text-right">Sold</th><th class="text-right">Mass kg</th>
        <th class="text-right">Gross USD</th><th class="text-right">Hi $/kg</th>
        <th class="text-right">Lo $/kg</th><th class="text-right">Avg $/kg</th>
        <th>Area</th><th>District</th><th>Province</th>
    </tr></thead><tbody>
    <?php
    $by_g=grp($bales,'grower_num');
    $gtl=$gtr=$gts=$gtm=$gtu=0;
    foreach ($by_g as $gn=>$gb):
        $r0=$gb[0]; $sold=array_filter($gb,fn($b)=>!$b['is_rej']); $rej=count($gb)-count($sold);
        $mass=sum_col($sold,'mass');$usd=sum_col($sold,'usd_value');
        $prices=array_filter(array_column($sold,'price_per_kg'));
        $hi=$prices?max($prices):0;$lo=$prices?min($prices):0;$avg=$prices?array_sum($prices)/count($prices):0;
        $gtl+=count($gb);$gtr+=$rej;$gts+=count($sold);$gtm+=$mass;$gtu+=$usd;
    ?>
    <tr>
        <td><span class="badge badge-gold" style="font-size:0.62rem"><?=htmlspecialchars($gn)?></span></td>
        <td style="font-size:0.78rem"><?=htmlspecialchars($r0['gname'].' '.$r0['surname'])?></td>
        <td class="td-mono text-right"><?=count($gb)?></td>
        <td class="td-mono text-right <?=$rej>0?'text-danger':''?>"><?=$rej?></td>
        <td class="td-mono text-right"><?=count($sold)?></td>
        <td class="td-mono text-right"><?=money($mass,1)?></td>
        <td class="td-mono text-right fw-500"><?=m($usd)?></td>
        <td class="td-mono text-right text-leaf"><?=m($hi)?></td>
        <td class="td-mono text-right text-danger"><?=m($lo)?></td>
        <td class="td-mono text-right"><?=m($avg,2)?></td>
        <td class="td-muted" style="font-size:0.72rem"><?=htmlspecialchars($r0['area']??'')?></td>
        <td class="td-muted" style="font-size:0.72rem"><?=htmlspecialchars($r0['district']??'')?></td>
        <td class="td-muted" style="font-size:0.72rem"><?=htmlspecialchars($r0['province']??'')?></td>
    </tr>
    <?php endforeach;?>
    </tbody><tfoot><tr>
        <td colspan="2">TOTALS</td>
        <td class="td-mono text-right"><?=$gtl?></td>
        <td class="td-mono text-right"><?=$gtr?></td>
        <td class="td-mono text-right"><?=$gts?></td>
        <td class="td-mono text-right"><?=money($gtm,1)?> kg</td>
        <td class="td-mono text-right"><?=m($gtu)?></td>
        <td colspan="6"></td>
    </tr></tfoot>
    </table></div>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// GROWER PAYMENT SCHEDULE USD / ZiG
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='grower_sched_usd'||$report==='grower_sched_zig'):
    $is_zig=$report==='grower_sched_zig';$cur=$is_zig?'ZiG':'USD';
    $bales=load_bales($conn,$date_f,$spid,0,$loc_f);  // grower report — buyer filter not applied
    $sold=array_filter($bales,fn($b)=>!$b['is_rej']);
    $by_g=grp($sold,'grower_num');
    rpt_head("Grower Payment Schedule — $cur",'Gross proceeds less deductions per grower');
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="?date=<?=urlencode($date_f)?>&report=<?=htmlspecialchars($report)?>&dl=1" class="btn btn-primary btn-sm">⬇ Download Schedule (.xlsx)</a>
    </div><?php
    ?><div class="table-wrap"><table class="rpt-table">
    <thead><tr>
        <th>Grower #</th><th>Name</th><th>Bank / Account</th>
        <th class="text-right">Bales</th><th class="text-right">Mass kg</th>
        <th class="text-right">Gross <?=$cur?></th>
        <th class="text-right">Deductions</th><th class="text-right">Net <?=$cur?></th>
    </tr></thead><tbody>
    <?php $tg=$tm=$tgr=$td=$tn=0;
    foreach ($by_g as $gn=>$gb):
        $gid=(int)$gb[0]['grower_id'];
        $mass=sum_col($gb,'mass');$usd=sum_col($gb,'usd_value');
        $ded_q=$conn->query("SELECT (SELECT COALESCE(SUM(amount_1+amount_2+amount_3),0) FROM stoporders WHERE growerid=$gid AND active=1) + (SELECT COALESCE(SUM(amount),0) FROM internal_stoporders WHERE growerid=$gid AND sell_date='$date_f' AND active=1) AS t");$ded=$ded_q&&$ded_q!==true?(float)($ded_q->fetch_assoc()['t']??0):0;
        $gross=$is_zig?$usd*$zig_rate:$usd;$ded_c=$is_zig?$ded*$zig_rate:$ded;
        $net=max(0,$gross-$ded_c);
        $bk_qr = $conn->query("SELECT bank_name,account_num FROM grower_banking WHERE growerid=$gid LIMIT 1"); $bk = ($bk_qr && $bk_qr !== true) ? $bk_qr->fetch_assoc() : [];
        $tg+=count($gb);$tm+=$mass;$tgr+=$gross;$td+=$ded_c;$tn+=$net;
    ?>
    <tr>
        <td><span class="badge badge-gold" style="font-size:0.62rem"><?=htmlspecialchars($gn)?></span></td>
        <td style="font-size:0.8rem"><?=htmlspecialchars($gb[0]['gname'].' '.$gb[0]['surname'])?></td>
        <td style="font-size:0.72rem;color:var(--muted)"><?=$bk?htmlspecialchars($bk['bank_name'].' · '.$bk['account_num']):'<span style="color:var(--danger)">No bank details</span>'?></td>
        <td class="td-mono text-right"><?=count($gb)?></td>
        <td class="td-mono text-right"><?=money($mass,1)?></td>
        <td class="td-mono text-right fw-500"><?=$is_zig?number_format($gross,2):m($gross)?></td>
        <td class="td-mono text-right text-danger"><?=$ded_c>0?($is_zig?number_format($ded_c,2):m($ded_c)):'—'?></td>
        <td class="td-mono text-right fw-500 text-leaf"><?=$is_zig?number_format($net,2):m($net)?></td>
    </tr>
    <?php endforeach;?>
    </tbody><tfoot><tr>
        <td colspan="3">TOTAL</td>
        <td class="td-mono text-right"><?=$tg?></td>
        <td class="td-mono text-right"><?=money($tm,1)?> kg</td>
        <td class="td-mono text-right"><?=$is_zig?number_format($tgr,2):m($tgr)?></td>
        <td class="td-mono text-right"><?=$is_zig?number_format($td,2):m($td)?></td>
        <td class="td-mono text-right"><?=$is_zig?number_format($tn,2):m($tn)?></td>
    </tr></tfoot></table></div>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// BANK SUMMARY
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='bank_summary'):
    $bales=load_bales($conn,$date_f,$spid,0,$loc_f);  // grower report — buyer filter not applied
    $sold=array_filter($bales,fn($b)=>!$b['is_rej']);
    $by_g=grp($sold,'grower_num');
    rpt_head('Bank Summary — USD / ZiG','Net payment amounts with bank details');
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="?date=<?=urlencode($date_f)?>&report=bank_summary&dl=1" class="btn btn-primary btn-sm">⬇ Download Bank Summary (.xlsx)</a>
    </div><?php
    ?><div class="table-wrap" style="overflow-x:auto"><table class="rpt-table">
    <thead><tr>
        <th>Grower #</th><th>Name</th><th>Bank</th><th>Branch</th>
        <th>Account #</th><th>Account Name</th>
        <th class="text-right">Net USD</th><th class="text-right">Net ZiG</th>
    </tr></thead><tbody>
    <?php $tu=$tz=0;
    foreach ($by_g as $gn=>$gb):
        $gid=(int)$gb[0]['grower_id'];
        $usd=sum_col($gb,'usd_value');
        $ded_q=$conn->query("SELECT (SELECT COALESCE(SUM(amount_1+amount_2+amount_3),0) FROM stoporders WHERE growerid=$gid AND active=1) + (SELECT COALESCE(SUM(amount),0) FROM internal_stoporders WHERE growerid=$gid AND sell_date='$date_f' AND active=1) AS t");$ded=$ded_q&&$ded_q!==true?(float)($ded_q->fetch_assoc()['t']??0):0;
        $net=max(0,$usd-$ded);$net_zig=$zig_rate>0?$net*$zig_rate:0;
        $bk_qr = $conn->query("SELECT * FROM grower_banking WHERE growerid=$gid LIMIT 1"); $bk = ($bk_qr && $bk_qr !== true) ? $bk_qr->fetch_assoc() : [];
        $tu+=$net;$tz+=$net_zig;
    ?>
    <tr <?=$bk?'':' style="background:#fff8ee"'?>>
        <td><span class="badge badge-gold" style="font-size:0.62rem"><?=htmlspecialchars($gn)?></span></td>
        <td style="font-size:0.8rem"><?=htmlspecialchars($gb[0]['gname'].' '.$gb[0]['surname'])?></td>
        <td style="font-size:0.75rem"><?=$bk?htmlspecialchars($bk['bank_name']):'<span style="color:var(--danger);font-size:0.7rem">⚠ Missing</span>'?></td>
        <td style="font-size:0.72rem;color:var(--muted)"><?=$bk?htmlspecialchars($bk['branch']):'—'?></td>
        <td class="td-mono" style="font-size:0.72rem"><?=$bk?htmlspecialchars($bk['account_num']):'—'?></td>
        <td style="font-size:0.72rem"><?=$bk?htmlspecialchars($bk['account_name']):'—'?></td>
        <td class="td-mono text-right fw-500"><?=m($net)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($net_zig,2):'—'?></td>
    </tr>
    <?php endforeach;?>
    </tbody><tfoot><tr>
        <td colspan="6">TOTAL</td>
        <td class="td-mono text-right"><?=m($tu)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($tz,2):'—'?></td>
    </tr></tfoot></table></div>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// GROWER SALES SUMMARY
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='grower_sales_sum'):
    $bales=load_bales($conn,$date_f,$spid,0,$loc_f);  // grower report — buyer filter not applied
    $by_g=grp($bales,'grower_num');
    // Count raw tickets for the date (no selling filters) so we can diagnose empty results
    $raw_tix_q=$conn->query("SELECT COUNT(DISTINCT tk.id) c FROM tickets tk
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
        JOIN growers g ON g.id=bj.growerid
        WHERE COALESCE(tg.sell_date,DATE(tk.created_at))='$date_f' AND g.selling_pointid=$spid");
    $raw_tix = ($raw_tix_q&&$raw_tix_q!==true) ? (int)$raw_tix_q->fetch_assoc()['c'] : 0;
    rpt_head('Grower Sales Summary','Bales, mass and value per grower');
    ?><div style="padding:0.6rem 1.15rem;display:flex;justify-content:flex-end;border-bottom:1px solid var(--border)">
        <a href="?date=<?=urlencode($date_f)?>&report=grower_sales_sum&dl=1" class="btn btn-primary btn-sm">⬇ Download Grower Sales Summary (.xlsx)</a>
    </div><?php
    if (empty($by_g)): ?>
    <div style="padding:2rem;text-align:center;color:var(--muted)">
        <div style="font-size:1.5rem;opacity:0.4;margin-bottom:0.5rem">📭</div>
        <div style="font-weight:500">No bales found for <?=date('d M Y',strtotime($date_f))?></div>
        <?php if ($raw_tix>0): ?>
        <div style="font-size:0.78rem;margin-top:0.35rem;color:var(--warning)">
            ⚠ <?=$raw_tix?> ticket(s) exist for this date but bale data could not be loaded.
            Check that bale_mass records are correctly linked.
        </div>
        <?php else: ?>
        <div style="font-size:0.78rem;margin-top:0.35rem">No tickets registered on this date<?=$loc_f?' for the selected location':''?>.</div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="table-wrap" style="overflow-x:auto"><table class="rpt-table">
    <thead><tr>
        <th>Grower #</th><th>Name</th><th>Farm</th>
        <th class="text-right">Laid</th><th class="text-right">Sold</th><th class="text-right">Rej</th>
        <th class="text-right">Mass kg</th><th class="text-right">Avg $/kg</th>
        <th class="text-right">Gross USD</th><th class="text-right">Gross ZiG</th>
    </tr></thead><tbody>
    <?php $tl=$ts=$tr=$tm=$tu=$tz=0;
    foreach ($by_g as $gn=>$gb):
        $r0=$gb[0];$sold=array_filter($gb,fn($b)=>!$b['is_rej']);$rej=count($gb)-count($sold);
        $mass=sum_col($sold,'mass');$usd=sum_col($sold,'usd_value');$zig=sum_col($sold,'zig_value');
        $prices=array_filter(array_column($sold,'price_per_kg'));
        $avg=$prices&&$mass>0?$usd/$mass:0;
        $tl+=count($gb);$ts+=count($sold);$tr+=$rej;$tm+=$mass;$tu+=$usd;$tz+=$zig;
    ?>
    <tr>
        <td><span class="badge badge-gold" style="font-size:0.62rem"><?=htmlspecialchars($gn)?></span></td>
        <td style="font-size:0.8rem"><?=htmlspecialchars($r0['gname'].' '.$r0['surname'])?></td>
        <td class="td-muted" style="font-size:0.72rem"><?=htmlspecialchars($r0['farm_name']??'')?></td>
        <td class="td-mono text-right"><?=count($gb)?></td>
        <td class="td-mono text-right text-leaf fw-500"><?=count($sold)?></td>
        <td class="td-mono text-right <?=$rej>0?'text-danger':''?>"><?=$rej?></td>
        <td class="td-mono text-right"><?=money($mass,1)?></td>
        <td class="td-mono text-right"><?=$avg>0?m($avg,3):'—'?></td>
        <td class="td-mono text-right fw-500"><?=m($usd)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($zig,2):'—'?></td>
    </tr>
    <?php endforeach;?>
    </tbody><tfoot><tr>
        <td colspan="3">TOTAL — <?=count($by_g)?> growers, <?=$tl?> bales</td>
        <td class="td-mono text-right"><?=$tl?></td>
        <td class="td-mono text-right"><?=$ts?></td>
        <td class="td-mono text-right"><?=$tr?></td>
        <td class="td-mono text-right"><?=money($tm,1)?> kg</td>
        <td></td>
        <td class="td-mono text-right"><?=m($tu)?></td>
        <td class="td-mono text-right"><?=$zig_rate>0?number_format($tz,2):'—'?></td>
    </tr></tfoot></table></div>
    <?php endif; ?>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// EXTRACT BALES SOLD
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='extract_bales'):
    $bales=load_bales($conn,$date_f,$spid,0,$loc_f);  // full extract — buyer filter not applied
    $sold=array_filter($bales,fn($b)=>!$b['is_rej']);
    $dl_url="?date=".urlencode($date_f)."&report=extract_bales&dl=1".($buyer_f?"&buyer=$buyer_f":"").($loc_f?"&loc=$loc_f":"");
    rpt_head('Extract Bales Sold','CSV export of all sold bales');
    ?><div style="padding:1.25rem">
    <p style="font-size:0.82rem;color:var(--muted);margin-bottom:1rem">
        <?=count($sold)?> bales ready for export · <?=m(sum_col($sold,'usd_value'))?> USD · <?=money(sum_col($sold,'mass'),0)?> kg
    </p>
    <a href="<?=$dl_url?>" class="btn btn-primary">⬇ Download CSV</a>
    </div>
    <?php rpt_foot();

// ═══════════════════════════════════════════════════════════════════════════
// EXTRACT BANK DETAILS
// ═══════════════════════════════════════════════════════════════════════════
elseif ($report==='extract_bank'):
    $bales=load_bales($conn,$date_f,$spid,0,$loc_f);  // full extract — buyer filter not applied
    $gids=array_unique(array_column($bales,'grower_id'));
    $dl_url="?date=".urlencode($date_f)."&report=extract_bank&dl=1".($buyer_f?"&buyer=$buyer_f":"").($loc_f?"&loc=$loc_f":"");
    $has_bank=(int)$conn->query("SELECT COUNT(*) c FROM grower_banking WHERE growerid IN (".implode(',',array_map('intval',$gids)?:'0').")")->fetch_assoc()['c'];
    rpt_head('Extract Bank Grower Details','CSV for payment processing');
    ?><div style="padding:1.25rem">
    <p style="font-size:0.82rem;color:var(--muted);margin-bottom:1rem">
        <?=count($gids)?> growers on sale · <?=$has_bank?> with bank details recorded
        <?php if($has_bank<count($gids)):?><span style="color:var(--danger)"> · <?=count($gids)-$has_bank?> missing bank details</span><?php endif;?>
    </p>
    <a href="<?=$dl_url?>" class="btn btn-primary">⬇ Download CSV</a>
    <a href="banking.php" class="btn btn-ghost" style="margin-left:0.5rem">+ Capture Bank Details</a>
    </div>
    <?php rpt_foot();

endif; ?>

</div><!-- /report panel -->
<?php include "includes/layout_end.php"; ?>
