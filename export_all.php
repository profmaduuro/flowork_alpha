<?php
/**
 * export_all.php — Bulk ZIP export for all Flowork reports
 * Generates all CSV files + print HTML for a given date/page and zips them.
 * Called from reports.php, eod_reports.php, timb_reports.php
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$sp_name  = $_SESSION['selling_point_name'] ?? 'Flowork';
$userid   = current_userid();
$page     = $_GET['page']    ?? 'reports';     // reports|eod|timb
$date_f   = $conn->real_escape_string($_GET['date']   ?? date('Y-m-d'));
$date_from= $conn->real_escape_string($_GET['from']   ?? date('Y-m-01'));
$date_to  = $conn->real_escape_string($_GET['to']     ?? date('Y-m-d'));
$buyer_f  = $conn->real_escape_string(trim($_GET['buyer']  ?? ''));
if ($buyer_f === '0') $buyer_f = '';
$buyer_mark_f = $_GET['buyer_mark'] ?? '';
$loc_f    = (int)($_GET['loc']    ?? 0);
$filter_spid = (int)($_GET['spid'] ?? $spid);

// ── Check ZIP extension ────────────────────────────────────────────────────
if (!extension_loaded('zip')) {
    die('<p style="font-family:sans-serif;padding:40px;color:red">
        ZIP extension not available on this server.<br>
        Use individual CSV/Print buttons instead.
    </p>');
}

// ── Temp directory ─────────────────────────────────────────────────────────
$tmp_dir = sys_get_temp_dir() . '/flowork_export_' . uniqid();
mkdir($tmp_dir, 0755, true);

$files_added = 0;
$zip_name    = 'flowork_' . $page . '_' . ($page === 'eod' ? $date_f : $date_from . '_' . $date_to) . '.zip';
$zip_path    = $tmp_dir . '/' . $zip_name;

$zip = new ZipArchive();
$zip->open($zip_path, ZipArchive::CREATE);

// ── Helper: write a CSV string to the zip ─────────────────────────────────
function add_csv($zip, &$files_added, $filename, $content) {
    global $tmp_dir;
    $path = $tmp_dir . '/' . $filename;
    file_put_contents($path, $content);
    $zip->addFile($path, $filename);
    $files_added++;
}

// ── Helper: money format ──────────────────────────────────────────────────
function em($v, $dp=2) { return number_format((float)$v, $dp); }
function eq($v) { return '"'.str_replace('"','""',$v ?? '').'"'; }

// ══════════════════════════════════════════════════════════════════════════
// REPORTS PAGE (date range based)
// ══════════════════════════════════════════════════════════════════════════
if ($page === 'reports') {
    $sp_scope = "g.selling_pointid = $filter_spid";
    $has_dtn  = $conn->query("SHOW TABLES LIKE 'daily_totals_net_amount'")->num_rows > 0;
    $has_locs = $conn->query("SHOW TABLES LIKE 'bale_locations'")->num_rows > 0;
    $net_col  = $has_dtn ? "SUM(dtn.usd_net_value)" : "SUM(dt.usd_value)";
    $net_join = $has_dtn ? "LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid = bb.id" : "";
    $loc_join = $has_locs
        ? "JOIN transporter t ON t.id=tg.transporterid LEFT JOIN bale_locations bl ON bl.id=COALESCE(tg.locationid, t.location_id)"
        : "JOIN transporter t ON t.id=tg.transporterid";
    $bl_sel   = $has_locs ? ", bl.code loc_code" : ", NULL loc_code";

    function rpt_qall($conn,$sql){$r=$conn->query($sql);return($r&&$r!==true)?$r->fetch_all(MYSQLI_ASSOC):[];}

    // 1. Growers
    $growers = rpt_qall($conn,"
        SELECT g.grower_num, g.name, g.surname, g.province $bl_sel,
               COUNT(DISTINCT bb.id) batches, COALESCE(SUM(dt.sold_bales),0) bales,
               COALESCE(SUM(dt.mass),0) mass, COALESCE(SUM(dt.usd_value),0) gross_usd,
               COALESCE($net_col,0) net_usd, COALESCE(AVG(dt.average_price),0) avg_price
        FROM balanced_bales bb
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        $loc_join
        LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id $net_join
        WHERE bb.stoporder_processed=1 AND $sp_scope
          AND DATE(dt.created_at) BETWEEN '$date_from' AND '$date_to'
        GROUP BY g.id ORDER BY gross_usd DESC LIMIT 500
    ");
    $out = "Grower #,Name,Province,Location,Batches,Bales,Mass kg,Gross USD,Net USD,Avg \$/kg\r\n";
    foreach ($growers as $r) {
        $ap=(float)$r['avg_price']; if($ap>10)$ap/=100;
        $out .= implode(',', [eq($r['grower_num']),eq(trim($r['name'].' '.$r['surname'])),
            eq($r['province']??''),eq($r['loc_code']??''),
            $r['batches'],$r['bales'],em($r['mass'],0),
            em($r['gross_usd']),em($r['net_usd']),em($ap,4)])."\r\n";
    }
    add_csv($zip, $files_added, '01_growers.csv', $out);

    // 2. By Buyer
    $buyers = rpt_qall($conn,"
        SELECT b.name buyer_name, b.buyer_code,
               COUNT(sb.id) bales, COALESCE(SUM(bm.mass),0) mass,
               COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END*bm.mass),0) gross_usd,
               AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END) avg_price
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        JOIN buyer b ON b.id=bg.buyerid
        WHERE $sp_scope AND DATE(sb.created_at) BETWEEN '$date_from' AND '$date_to'
        GROUP BY b.id ORDER BY gross_usd DESC
    ");
    $gt = array_sum(array_column($buyers,'gross_usd')) ?: 1;
    $out = "Buyer,Code,Bales,Mass kg,Gross USD,Avg \$/kg,% Share\r\n";
    foreach ($buyers as $b) {
        $out .= implode(',', [eq($b['buyer_name']),eq($b['buyer_code']??''),
            (int)$b['bales'],em($b['mass'],0),em($b['gross_usd']),
            em($b['avg_price'],4),round($b['gross_usd']/$gt*100,1).'%'])."\r\n";
    }
    add_csv($zip, $files_added, '02_by_buyer.csv', $out);

    // 3. By TIMB Grade
    $grades = rpt_qall($conn,"
        SELECT tg2.description grade, COUNT(sb.id) bales, COALESCE(SUM(bm.mass),0) mass,
               AVG(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END) avg_price,
               MAX(CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END) max_price
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        JOIN timb_grades tg2 ON tg2.id=sb.timb_gradesid
        WHERE $sp_scope AND DATE(sb.created_at) BETWEEN '$date_from' AND '$date_to'
        GROUP BY tg2.id ORDER BY bales DESC
    ");
    $out = "Grade,Bales,Mass kg,Avg \$/kg,Max \$/kg\r\n";
    foreach ($grades as $gr) {
        $out .= implode(',', [eq($gr['grade']),(int)$gr['bales'],
            em($gr['mass'],0),em($gr['avg_price'],4),em($gr['max_price'],4)])."\r\n";
    }
    add_csv($zip, $files_added, '03_by_grade.csv', $out);

    // 4. By Province
    $provs = rpt_qall($conn,"
        SELECT COALESCE(NULLIF(TRIM(g.province),''),'Unknown') province,
               COALESCE(SUM(dt.sold_bales),0) bales, COALESCE(SUM(dt.usd_value),0) gross_usd
        FROM balanced_bales bb
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
        WHERE bb.stoporder_processed=1 AND $sp_scope
          AND DATE(dt.created_at) BETWEEN '$date_from' AND '$date_to'
        GROUP BY province ORDER BY gross_usd DESC
    ");
    $out = "Province,Bales,Gross USD\r\n";
    foreach ($provs as $p) {
        $out .= implode(',', [eq($p['province']),(int)$p['bales'],em($p['gross_usd'])])."\r\n";
    }
    add_csv($zip, $files_added, '04_by_province.csv', $out);

    // 5. Daily breakdown
    $daily = rpt_qall($conn,"
        SELECT DATE(dt.created_at) sale_date,
               COUNT(DISTINCT bb.id) batches, COALESCE(SUM(dt.sold_bales),0) bales,
               COALESCE(SUM(dt.mass),0) mass, COALESCE(SUM(dt.usd_value),0) gross_usd
        FROM balanced_bales bb
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
        WHERE bb.stoporder_processed=1 AND $sp_scope
          AND DATE(dt.created_at) BETWEEN '$date_from' AND '$date_to'
        GROUP BY DATE(dt.created_at) ORDER BY sale_date
    ");
    $out = "Date,Batches,Bales,Mass kg,Gross USD\r\n";
    foreach ($daily as $d) {
        $out .= implode(',', [$d['sale_date'],(int)$d['batches'],
            (int)$d['bales'],em($d['mass'],0),em($d['gross_usd'])])."\r\n";
    }
    add_csv($zip, $files_added, '05_daily_trend.csv', $out);

    // 6. Locations
    if ($has_locs) {
        $locs = rpt_qall($conn,"
            SELECT bl.code, bl.name loc_name, bl.subcontractor,
                   COUNT(DISTINCT bb.id) batches,
                   COALESCE(SUM(dt.sold_bales),0) bales,
                   COALESCE(SUM(dt.mass),0) mass,
                   COALESCE(SUM(dt.usd_value),0) gross_usd
            FROM bale_locations bl
            LEFT JOIN (transporter_growers tg JOIN transporter t ON t.id=tg.transporterid)
                   ON COALESCE(tg.locationid, t.location_id)=bl.id
            LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
            LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
            LEFT JOIN transporter_growers tg2 ON tg2.id=bb.transporter_growersid
            LEFT JOIN growers g ON g.id=tg2.growerid
            WHERE bl.selling_pointid=$filter_spid
              AND (dt.id IS NULL OR DATE(dt.created_at) BETWEEN '$date_from' AND '$date_to')
            GROUP BY bl.id ORDER BY gross_usd DESC
        ");
        $out = "Code,Location,Subcontractor,Batches,Bales,Mass kg,Gross USD\r\n";
        foreach ($locs as $l) {
            $out .= implode(',', [eq($l['code']),eq($l['loc_name']),eq($l['subcontractor']??''),
                (int)$l['batches'],(int)$l['bales'],em($l['mass'],0),em($l['gross_usd'])])."\r\n";
        }
        add_csv($zip, $files_added, '06_by_location.csv', $out);
    }

    // 7. README
    $readme  = "FLOWORK SALES REPORT EXPORT\r\n";
    $readme .= "===========================\r\n";
    $readme .= "Floor:     $sp_name\r\n";
    $readme .= "Period:    $date_from to $date_to\r\n";
    $readme .= "Generated: ".date('d M Y H:i')."\r\n\r\n";
    $readme .= "FILES IN THIS ZIP:\r\n";
    $readme .= "01_growers.csv      - Top growers by revenue\r\n";
    $readme .= "02_by_buyer.csv     - Revenue split by buyer\r\n";
    $readme .= "03_by_grade.csv     - Volume and pricing by TIMB grade\r\n";
    $readme .= "04_by_province.csv  - Revenue by grower province\r\n";
    $readme .= "05_daily_trend.csv  - Day-by-day breakdown\r\n";
    if ($has_locs) $readme .= "06_by_location.csv  - Performance by subcontractor location\r\n";
    add_csv($zip, $files_added, 'README.txt', $readme);
}

// ══════════════════════════════════════════════════════════════════════════
// EOD REPORTS PAGE (single date based)
// ══════════════════════════════════════════════════════════════════════════
elseif ($page === 'eod') {
    // Bundle EVERY report in the EOD dropdown by fetching each report's own
    // download endpoint — so the ZIP always matches the dropdown and reuses
    // each report's exact format (xlsx / txt / csv / pdf / .v).
    $eod_list = ['buyer_disk','buyer_invoice','buyer_ytd','buyer_stats','daily_sales_ret',
        'cred_invoice','cred_ytd','cred_summary','rejected_summary','revenue_report',
        'floor_recon','daily_floor_recon','sales_control','invoice_control','purchase_summary',
        'origination','grower_sched_usd','grower_sched_zig','bank_summary','grower_sales_sum',
        'extract_bales','extract_bank'];
    // Collection advice = one PDF per grower charge deducted on the date
    $cc_charges = [];
    $ccq = $conn->query("SELECT MIN(gc.id) id FROM balanced_bales_charges bbc
        JOIN grower_charges gc ON gc.id=bbc.grower_chargeid
        LEFT JOIN statutory s ON s.id=gc.statutoryid
        JOIN balanced_bales bb ON bb.id=bbc.balanced_balesid
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        JOIN growers g ON g.id=bb.growerid
        WHERE (g.selling_pointid=$filter_spid OR g.selling_pointid=0)
          AND COALESCE(tg.sell_date,DATE(bb.created_at))='$date_f'
        GROUP BY COALESCE(s.description, gc.id)");
    if ($ccq && $ccq!==true) while($r=$ccq->fetch_assoc()) $cc_charges[]=(int)$r['id'];

    $cookie = session_name().'='.session_id();
    // The sub-requests repeat the browser's own request (same scheme, Host and
    // path, so the web server picks the same site / document root) but are always
    // connected to this machine. A bare http://127.0.0.1/... lands on Apache's
    // default site, which on a server where Flowork has its own vhost or domain
    // has no eod_reports.php, so every report came back HTTP 404.
    // The connection goes first to SERVER_ADDR, the local address the browser's
    // request arrived on (hosts that bind each site to the public IP do not serve
    // it on 127.0.0.1), then to 127.0.0.1. Both are this server, so the session
    // cookie never leaves the box, which is why certificate checks can be off
    // (the floor's LAN certificate is self-signed).
    $https  = !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off';
    $port   = (int)($_SERVER['SERVER_PORT'] ?? 0) ?: ($https ? 443 : 80);
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$|^\[[0-9A-Fa-f:.]+\](:\d+)?$/', $host)) $host = '127.0.0.1';
    $base = ($https ? 'https' : 'http') . '://' . $host
          . rtrim(str_replace('\\','/',dirname($_SERVER['PHP_SELF'])),'/') . '/eod_reports.php';
    $targets = [];
    $saddr = $_SERVER['SERVER_ADDR'] ?? '';
    if (filter_var($saddr, FILTER_VALIDATE_IP)) $targets[] = strpos($saddr, ':') !== false ? "[$saddr]" : $saddr;
    $targets[] = '127.0.0.1';
    $targets = array_values(array_unique($targets));
    $target = null;          // the first target that answers is used for the rest
    session_write_close();   // release the lock so sub-requests can read the session

    $failed = []; $empty = [];
    $dead = [];              // addresses that refused or timed out: not retried per report
    $fetch = function($url) use($cookie,$targets,$port,&$target,&$dead,&$failed){
        $rep=preg_match('/[?&]report=([a-z_]+)/',$url,$rm)?$rm[1]:$url;
        $tries=[];
        foreach(($target!==null?[$target]:array_diff($targets,$dead)) as $t){
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,
                CURLOPT_COOKIE=>$cookie,CURLOPT_TIMEOUT=>90,CURLOPT_CONNECTTIMEOUT=>10,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECT_TO=>["::$t:$port"],   // any host/port in the URL -> this server
                CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0]);
            $resp=curl_exec($ch);
            $hs=curl_getinfo($ch,CURLINFO_HEADER_SIZE); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
            $cerr=curl_error($ch);
            curl_close($ch);
            $tries[]="$t:$port ".($resp===false?$cerr:"HTTP $code");
            if($resp===false && $code===0) $dead[]=$t;
            if($resp===false || $code===404) continue;   // wrong site on this address, try the next
            $target=$t;
            if($code!==200) break;
            $headers=substr($resp,0,$hs); $body=substr($resp,$hs);
            // only accept real file downloads (must carry an attachment filename)
            if(!preg_match('/filename="([^"]+)"/i',$headers,$m)) return null;
            return [$m[1],$body];
        }
        $failed[]="$rep: ".($tries?implode('; ',$tries):'no reachable address ('.implode(', ',$dead).')')." (".preg_replace('/\?.*/','',$url).")";
        return null;
    };
    
    $filter_qs = "";
    if ($buyer_f) $filter_qs .= "&buyer=$buyer_f";
    if ($buyer_mark_f) $filter_qs .= "&buyer_mark=" . urlencode($buyer_mark_f);
    if ($loc_f) $filter_qs .= "&loc=$loc_f";

    foreach($eod_list as $rep){
        $url=$base."?report=$rep&dl=1&date=".rawurlencode($date_f) . $filter_qs;
        if($rep==='revenue_report') $url.="&from=".rawurlencode($date_f)."&to=".rawurlencode($date_f);
        $res=$fetch($url);
        if($res && $res[1]!==''){ $zip->addFromString($res[0], $res[1]); $files_added++; }
        elseif($res) $empty[]=$rep;   // fetched, but nothing for this date and these filters
    }
    foreach($cc_charges as $cid){
        $res=$fetch($base."?report=cred_collection&dl=1&charge=$cid&date=".rawurlencode($date_f) . $filter_qs);
        if($res && $res[1]!==''){ $zip->addFromString($res[0], $res[1]); $files_added++; }
    }
    if($empty) $zip->addFromString('NOT_INCLUDED.txt',
        "These reports had no data for $date_f with the filters used"
        .($filter_qs!==''?" (".ltrim(str_replace('&',', ',$filter_qs),', ').")":'')
        .", so they are not in this ZIP:\r\n".implode("\r\n",$empty)."\r\n\r\n"
        ."Bales are reported by sale date, and the location filter uses each bale's transporter location.\r\n");
    if($failed) $zip->addFromString('ERRORS.txt',"These reports could not be fetched:\r\n".implode("\r\n",$failed)."\r\n");
    elseif($files_added===0) $zip->addFromString('README.txt',"No report data for $date_f.\n");

    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$zip_name.'"');
    header('Content-Length: '.filesize($zip_path));
    readfile($zip_path);
    @unlink($zip_path);
    exit;
    // ── legacy inline EOD CSV generation below is now unreachable ──
    $eod_reports = [
        'extract_bales'    => 'Bales Extract',
        'origination'      => 'Origination Report',
        'purchase_summary' => 'Purchase Summary',
        'grower_sched_usd' => 'Grower Schedule USD',
        'grower_sched_zig' => 'Grower Schedule ZiG',
        'bank_summary'     => 'Bank Summary',
        'grower_sales_sum' => 'Grower Sales Summary',
        'buyer_invoice'    => 'Buyer Invoice',
        'buyer_disk'       => 'Buyer Disk File',
        'extract_bank'     => 'Bank Details Extract',
        'rejected_summary' => 'Rejected Bales',
        'cred_payment'     => 'Creditor Payment Schedule',
        'cred_invoice'     => 'Creditor Invoice',
        'cred_summary'     => 'Creditor Summary',
    ];

    $zig_q = $conn->query("SELECT amount FROM exchange_rate WHERE active=1 ORDER BY id DESC LIMIT 1");
    $zig_rate = $zig_q ? (float)($zig_q->fetch_assoc()['amount']??0) : 0;

    // Load bales once
    $bales_q = $conn->query("
        SELECT tk.barcode, bm.mass, bm.temp_bacode AS timb_bc, DATE(tk.created_at) AS sell_date,
               sb.price, sb.sale_code,
               tgd.description AS timb_grade, bg.description AS buyer_grade,
               b.id AS buyer_id, b.name AS buyer_name, b.buyer_code,
               g.id AS grower_id, g.grower_num, g.name AS gname, g.surname,
               COALESCE(g.farm_name,'') AS farm_name,
               COALESCE(g.province,'') AS province,
               COALESCE(g.national_id,'') AS national_id,
               bl.id AS loc_id, bl.code AS loc_code, bl.name AS loc_name,
               t.name AS transporter_name, bj.lot, bj.bale_group, bj.splitid
        FROM tickets tk
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        JOIN transporter_growers tg2 ON tg2.id=bj.transporter_growersid
        JOIN transporter t ON t.id=tg2.transporterid
        LEFT JOIN bale_locations bl ON bl.id=COALESCE(tg2.locationid, t.location_id)
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        LEFT JOIN timb_grades tgd ON tgd.id=sb.timb_gradesid
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        WHERE DATE(tk.created_at)='$date_f' AND g.selling_pointid=$spid
        ORDER BY g.grower_num, bj.lot
    ");
    $bales = [];
    while ($bales_q && $r = $bales_q->fetch_assoc()) {
        $raw = (float)$r['price'];
        $r['price_per_kg'] = $raw > 10 ? $raw/100 : $raw;
        $r['usd_value']    = $r['mass'] * $r['price_per_kg'];
        $r['zig_value']    = $r['usd_value'] * $zig_rate;
        $is_rej = strtoupper(substr(trim($r['sale_code']??''),0,1))==='R' && strlen(trim($r['sale_code']??''))<=3;
        $r['is_rej'] = $is_rej || (!$r['price_per_kg'] && $r['sale_code']!==null && $r['sale_code']!=='');
        $bales[] = $r;
    }
    $sold = array_filter($bales, fn($b) => !$b['is_rej']);

    function grp_eod($arr,$key){$g=[];foreach($arr as $r){$g[$r[$key]][]=$r;}return $g;}
    function sum_eod($arr,$key){return array_sum(array_column($arr,$key));}

    // 1. Extract Bales
    $out = "Sale Date,Barcode,TIMB BC,Grower #,Name,Farm,Province,TIMB Grade,Buyer Grade,Buyer,Location,Mass kg,Price USD/kg,Value USD,Value ZiG\r\n";
    foreach ($sold as $b) {
        $out .= implode(',', [date('d/m/Y',strtotime($b['sell_date'])),
            $b['barcode'],$b['timb_bc'],$b['grower_num'],
            eq($b['gname'].' '.$b['surname']),eq($b['farm_name']??''),eq($b['province']??''),
            $b['timb_grade']??'',$b['buyer_grade']??'',eq($b['buyer_name']??''),
            $b['loc_code']??'',$b['mass'],em($b['price_per_kg'],4),
            em($b['usd_value']),$zig_rate?em($b['zig_value']):''])."\r\n";
    }
    add_csv($zip, $files_added, '01_extract_bales.csv', $out);

    // 2. Origination
    $out = "Grower #,Name,Surname,Province,Area,Bales Laid,Bales Rejected,Bales Sold,Mass kg,Gross USD,Hi Price,Lo Price,Avg Price\r\n";
    foreach (grp_eod($bales,'grower_num') as $gn=>$gb) {
        $r0=$gb[0]; $s=array_filter($gb,fn($b)=>!$b['is_rej']); $rej=count($gb)-count($s);
        $mass=sum_eod($s,'mass'); $usd=sum_eod($s,'usd_value');
        $prices=array_filter(array_column($s,'price_per_kg'));
        $hi=$prices?max($prices):0; $lo=$prices?min($prices):0; $avg=$prices?array_sum($prices)/count($prices):0;
        $out .= implode(',', [$gn,eq($r0['gname']),eq($r0['surname']),eq($r0['province']??''),
            eq($r0['area']??''),count($gb),$rej,count($s),em($mass),em($usd),em($hi,4),em($lo,4),em($avg,4)])."\r\n";
    }
    add_csv($zip, $files_added, '02_origination.csv', $out);

    // 3. Grower Sales Summary
    $out = "Grower #,Name,Province,Location,Bales Laid,Rejected,Sold,Mass kg,Gross USD,Gross ZiG\r\n";
    foreach (grp_eod($bales,'grower_num') as $gn=>$gb) {
        $r0=$gb[0]; $s=array_filter($gb,fn($b)=>!$b['is_rej']); $rej=count($gb)-count($s);
        $out .= implode(',', [$gn,eq($r0['gname'].' '.$r0['surname']),eq($r0['province']??''),
            eq($r0['loc_code']??''),count($gb),$rej,count($s),
            em(sum_eod($s,'mass')),em(sum_eod($s,'usd_value')),
            $zig_rate?em(sum_eod($s,'usd_value')*$zig_rate):''])."\r\n";
    }
    add_csv($zip, $files_added, '03_grower_sales_summary.csv', $out);

    // 4. Buyer Invoice
    $out = "Buyer,Code,Grade,Bales,Mass kg,Price USD/kg,Value USD\r\n";
    foreach (grp_eod($sold,'buyer_name') as $bn=>$bb) {
        foreach (grp_eod($bb,'timb_grade') as $gr=>$gb) {
            $mass=sum_eod($gb,'mass'); $usd=sum_eod($gb,'usd_value'); $avg=$mass>0?$usd/$mass:0;
            $out .= implode(',', [eq($bn??''),eq($gb[0]['buyer_code']??''),eq($gr??''),
                count($gb),em($mass),em($avg,4),em($usd)])."\r\n";
        }
    }
    add_csv($zip, $files_added, '04_buyer_invoice.csv', $out);

    // 5. Grower Schedule USD
    $out = "Grower #,Name,Province,Location,Bales,Mass kg,Gross USD,Deductions USD,Net USD,Bank,Account #\r\n";
    foreach (grp_eod($sold,'grower_num') as $gn=>$gb) {
        $r0=$gb[0]; $gid=$r0['grower_id'];
        $mass=sum_eod($gb,'mass'); $usd=sum_eod($gb,'usd_value');
        
        // Fetch actual balanced values
        $bb_q = $conn->query("
            SELECT bb.id AS bbid, dtn.usd_net_value, dtn.zim_net_value
            FROM balanced_bales bb
            JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
            LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid = bb.id
            WHERE bb.growerid = $gid
              AND COALESCE(tg.sell_date, DATE(bb.created_at)) = '$date_f'
            LIMIT 1
        ");
        $bb_row = ($bb_q && $bb_q !== true) ? $bb_q->fetch_assoc() : null;
        if ($bb_row) {
            $bbid = (int)$bb_row['bbid'];
            $net_usd = (float)$bb_row['usd_net_value'];
            
            $ext_so_res = $conn->query("SELECT COALESCE(SUM(amount_deducted), 0) FROM stoporder_deductions WHERE balanced_balesid = $bbid");
            $ext_so = ($ext_so_res && $ext_so_res !== true) ? (float)$ext_so_res->fetch_row()[0] : 0.0;
            
            $int_so_res = $conn->query("SELECT COALESCE(SUM(amount_deducted), 0) FROM internal_stop_order_deducted_amounts WHERE balanced_balesid = $bbid");
            $int_so = ($int_so_res && $int_so_res !== true) ? (float)$int_so_res->fetch_row()[0] : 0.0;
            
            $ded = $ext_so + $int_so;
        } else {
            $ded = 0.0;
            $net_usd = $usd;
        }
        
        $bk_q=$conn->query("SELECT bank_name,account_num FROM grower_banking WHERE growerid=$gid LIMIT 1");
        $bk=$bk_q&&$bk_q!==true?($bk_q->fetch_assoc()??[]):[];
        $out .= implode(',', [$gn,eq($r0['gname'].' '.$r0['surname']),eq($r0['province']??''),eq($r0['loc_code']??''),
            count($gb),em($mass),em($usd),em($ded),em($net_usd),
            eq($bk['bank_name']??''),eq($bk['account_num']??'')])."\r\n";
    }
    add_csv($zip, $files_added, '05_grower_schedule_usd.csv', $out);

    // 6. Grower Schedule ZiG
    if ($zig_rate > 0) {
        $out = "Grower #,Name,Bales,Mass kg,Gross ZiG,Deductions ZiG,Net ZiG\r\n";
        foreach (grp_eod($sold,'grower_num') as $gn=>$gb) {
            $r0=$gb[0]; $gid=$r0['grower_id'];
            $usd=sum_eod($gb,'usd_value');
            
            // Fetch actual balanced values
            $bb_q = $conn->query("
                SELECT bb.id AS bbid, dtn.usd_net_value, dtn.zim_net_value
                FROM balanced_bales bb
                JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
                LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid = bb.id
                WHERE bb.growerid = $gid
                  AND COALESCE(tg.sell_date, DATE(bb.created_at)) = '$date_f'
                LIMIT 1
            ");
            $bb_row = ($bb_q && $bb_q !== true) ? $bb_q->fetch_assoc() : null;
            if ($bb_row) {
                $bbid = (int)$bb_row['bbid'];
                $net_zig = (float)$bb_row['zim_net_value'];
                
                $ext_so_res = $conn->query("SELECT COALESCE(SUM(amount_deducted), 0) FROM stoporder_deductions WHERE balanced_balesid = $bbid");
                $ext_so = ($ext_so_res && $ext_so_res !== true) ? (float)$ext_so_res->fetch_row()[0] : 0.0;
                
                $int_so_res = $conn->query("SELECT COALESCE(SUM(amount_deducted), 0) FROM internal_stop_order_deducted_amounts WHERE balanced_balesid = $bbid");
                $int_so = ($int_so_res && $int_so_res !== true) ? (float)$int_so_res->fetch_row()[0] : 0.0;
                
                $ded = $ext_so + $int_so;
            } else {
                $ded = 0.0;
                $net_zig = $usd * $zig_rate;
            }
            
            $gross_zig=$usd*$zig_rate; $ded_zig=$ded*$zig_rate;
            $out .= implode(',', [$gn,eq($r0['gname'].' '.$r0['surname']),count($gb),
                em(sum_eod($gb,'mass')),em($gross_zig),em($ded_zig),em($net_zig)])."\r\n";
        }
        add_csv($zip, $files_added, '06_grower_schedule_zig.csv', $out);
    }

    // 7. Bank Summary
    $out = "Grower #,Name,Bank,Branch,Account #,Account Name,SWIFT,Net USD".($zig_rate?",Net ZiG":"")."\r\n";
    foreach (grp_eod($sold,'grower_num') as $gn=>$gb) {
        $r0=$gb[0]; $gid=$r0['grower_id'];
        $usd=sum_eod($gb,'usd_value');
        
        // Fetch actual balanced values
        $bb_q = $conn->query("
            SELECT bb.id AS bbid, dtn.usd_net_value, dtn.zim_net_value
            FROM balanced_bales bb
            JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
            LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid = bb.id
            WHERE bb.growerid = $gid
              AND COALESCE(tg.sell_date, DATE(bb.created_at)) = '$date_f'
            LIMIT 1
        ");
        $bb_row = ($bb_q && $bb_q !== true) ? $bb_q->fetch_assoc() : null;
        if ($bb_row) {
            $net_usd = (float)$bb_row['usd_net_value'];
            $net_zig = (float)$bb_row['zim_net_value'];
        } else {
            $net_usd = $usd;
            $net_zig = $usd * $zig_rate;
        }
        
        $bk_q=$conn->query("SELECT * FROM grower_banking WHERE growerid=$gid LIMIT 1");
        $bk=$bk_q&&$bk_q!==true?($bk_q->fetch_assoc()??[]):[];
        $row = [$gn,eq($r0['gname'].' '.$r0['surname']),eq($bk['bank_name']??''),
            eq($bk['branch']??''),eq($bk['account_num']??''),eq($bk['account_name']??''),
            eq($bk['swift_code']??''),em($net_usd)];
        if ($zig_rate) $row[] = em($net_zig);
        $out .= implode(',',$row)."\r\n";
    }
    add_csv($zip, $files_added, '07_bank_summary.csv', $out);

    // 8. Extract Bank Details
    $out = "Grower #,Name,Surname,Bank,Branch,Account #,Account Name,SWIFT,Verified\r\n";
    $gids_done = [];
    foreach ($bales as $b) {
        $gid=$b['grower_id']; if(in_array($gid,$gids_done)) continue; $gids_done[]=$gid;
        $bk_q=$conn->query("SELECT * FROM grower_banking WHERE growerid=$gid LIMIT 1");
        $bk=$bk_q&&$bk_q!==true?($bk_q->fetch_assoc()??[]):[];
        $out .= implode(',', [$b['grower_num'],eq($b['gname']),eq($b['surname']),
            eq($bk['bank_name']??''),eq($bk['branch']??''),eq($bk['account_num']??''),
            eq($bk['account_name']??''),eq($bk['swift_code']??''),$bk['verified']??0])."\r\n";
    }
    add_csv($zip, $files_added, '08_extract_bank.csv', $out);

    // 9. Rejected Bales
    $rej_bales = array_filter($bales, fn($b) => $b['is_rej']);
    $out = "Grower #,Name,Grade,Buyer,Mass kg,Sale Code\r\n";
    foreach ($rej_bales as $b) {
        $out .= implode(',', [$b['grower_num'],eq($b['gname'].' '.$b['surname']),
            $b['timb_grade']??'',eq($b['buyer_name']??''),$b['mass'],$b['sale_code']??''])."\r\n";
    }
    add_csv($zip, $files_added, '09_rejected_bales.csv', $out);

    // 10. Purchase Summary
    $dates_q = $conn->query("SELECT DISTINCT DATE(tk.created_at) sd FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN growers g ON g.id=bj.growerid WHERE g.selling_pointid=$spid ORDER BY sd DESC LIMIT 60");
    $out = "Sale Date,Bales Laid,Bales Rejected,Bales Sold,Mass Sold kg,USD Value,Hi Price,Lo Price,Avg Price,Exchange Rate\r\n";
    while ($dates_q && $dr = $dates_q->fetch_assoc()) {
        $de=$conn->real_escape_string($dr['sd']);
        $dq=$conn->query("SELECT COUNT(*) laid, SUM(CASE WHEN sb.id IS NOT NULL THEN 1 ELSE 0 END) sold, SUM(bm.mass) mass, SUM(CASE WHEN sb.id IS NOT NULL THEN sb.price*bm.mass ELSE 0 END) gross, MAX(sb.price) hi, MIN(CASE WHEN sb.price>0 THEN sb.price END) lo, AVG(CASE WHEN sb.price>0 THEN sb.price END) avg FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid JOIN bale_mass bm ON bm.id=bj.bale_massid JOIN growers g ON g.id=bj.growerid LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id WHERE g.selling_pointid=$spid AND DATE(tk.created_at)='$de'");
        $drow=$dq&&$dq!==true?($dq->fetch_assoc()??[]):[];
        $er_q=$conn->query("SELECT amount FROM exchange_rate WHERE active=1 ORDER BY id DESC LIMIT 1");
        $er=$er_q&&$er_q!==true?((float)($er_q->fetch_assoc()['amount']??0)):0;
        $laid=(int)($drow['laid']??0); $s=(int)($drow['sold']??0);
        $hi=(float)($drow['hi']??0);$lo=(float)($drow['lo']??0);$avg=(float)($drow['avg']??0);
        if($hi>10){$hi/=100;$lo/=100;$avg/=100;}
        $gross=(float)($drow['gross']??0); if($s>0&&$gross>$s*20)$gross/=100;
        $out .= implode(',', [date('d/m/Y',strtotime($dr['sd'])),$laid,$laid-$s,$s,
            em($drow['mass']??0,0),em($gross),em($hi,4),em($lo,4),em($avg,4),$er])."\r\n";
    }
    add_csv($zip, $files_added, '10_purchase_summary.csv', $out);

    // README
    $readme  = "FLOWORK EOD REPORTS EXPORT\r\n";
    $readme .= "==========================\r\n";
    $readme .= "Floor:     $sp_name\r\n";
    $readme .= "Date:      $date_f\r\n";
    $readme .= "Generated: ".date('d M Y H:i')."\r\n\r\n";
    $readme .= "FILES:\r\n";
    $readme .= "01_extract_bales.csv       - All sold bales with full detail\r\n";
    $readme .= "02_origination.csv         - Origination report by grower\r\n";
    $readme .= "03_grower_sales_summary.csv - Grower totals\r\n";
    $readme .= "04_buyer_invoice.csv       - Buyer invoice by grade\r\n";
    $readme .= "05_grower_schedule_usd.csv - Grower payment schedule USD\r\n";
    if($zig_rate) $readme .= "06_grower_schedule_zig.csv - Grower payment schedule ZiG\r\n";
    $readme .= "07_bank_summary.csv        - Bank details with net amounts\r\n";
    $readme .= "08_extract_bank.csv        - Raw bank details extract\r\n";
    $readme .= "09_rejected_bales.csv      - Rejected/unsold bales\r\n";
    $readme .= "10_purchase_summary.csv    - Season purchase summary\r\n";
    add_csv($zip, $files_added, 'README.txt', $readme);
}

// ══════════════════════════════════════════════════════════════════════════
// TIMB REPORTS (basic export)
// ══════════════════════════════════════════════════════════════════════════
elseif ($page === 'timb') {
    $bales_q = $conn->query("
        SELECT bm.temp_bacode AS timb_bc, bm.mass,
               g.grower_num, g.name, g.surname, g.national_id, g.province,
               tgd.description AS timb_grade,
               CASE WHEN sb.price>10 THEN sb.price/100 ELSE sb.price END AS price,
               CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END AS usd_value,
               sb.sale_code, DATE(tk.created_at) sell_date,
               b.name AS buyer_name, b.buyer_code,
               bl.code AS loc_code
        FROM tickets tk
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
        JOIN transporter t ON t.id=tg.transporterid
        LEFT JOIN bale_locations bl ON bl.id=COALESCE(tg.locationid, t.location_id)
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        LEFT JOIN timb_grades tgd ON tgd.id=sb.timb_gradesid
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        WHERE DATE(tk.created_at)='$date_f' AND g.selling_pointid=$spid
        ORDER BY g.grower_num, bm.temp_bacode
    ");
    $rows = [];
    while ($bales_q && $r = $bales_q->fetch_assoc()) $rows[] = $r;

    // TIMB bale detail
    $out = "TIMB Barcode,Grower #,Name,National ID,Province,Grade,Buyer,Location,Mass kg,Price USD/kg,Value USD,Sale Code,Date\r\n";
    foreach ($rows as $r) {
        $out .= implode(',', [$r['timb_bc'],$r['grower_num'],
            eq($r['name'].' '.$r['surname']),eq($r['national_id']??''),eq($r['province']??''),
            $r['timb_grade']??'',eq($r['buyer_name']??''),$r['loc_code']??'',
            $r['mass'],em($r['price']??0,4),em($r['usd_value']??0),
            $r['sale_code']??'',date('d/m/Y',strtotime($r['sell_date']))])."\r\n";
    }
    add_csv($zip, $files_added, '01_timb_bale_detail.csv', $out);

    // By grade summary
    $by_grade = [];
    foreach ($rows as $r) {
        $gr=$r['timb_grade']??'Unknown';
        if(!isset($by_grade[$gr])) $by_grade[$gr]=['bales'=>0,'mass'=>0,'usd'=>0];
        $by_grade[$gr]['bales']++;
        $by_grade[$gr]['mass']+=$r['mass'];
        $by_grade[$gr]['usd']+=$r['usd_value']??0;
    }
    $out = "Grade,Bales,Mass kg,USD Value\r\n";
    foreach ($by_grade as $gr=>$v) {
        $out .= implode(',', [$gr,(int)$v['bales'],em($v['mass'],0),em($v['usd'])])."\r\n";
    }
    add_csv($zip, $files_added, '02_timb_by_grade.csv', $out);

    // By buyer
    $by_buyer = [];
    foreach ($rows as $r) {
        $bn=$r['buyer_name']??'Unknown'; $bc=$r['buyer_code']??'';
        if(!isset($by_buyer[$bn])) $by_buyer[$bn]=['code'=>$bc,'bales'=>0,'mass'=>0,'usd'=>0];
        $by_buyer[$bn]['bales']++;
        $by_buyer[$bn]['mass']+=$r['mass'];
        $by_buyer[$bn]['usd']+=$r['usd_value']??0;
    }
    $out = "Buyer,Code,Bales,Mass kg,USD Value\r\n";
    foreach ($by_buyer as $bn=>$v) {
        $out .= implode(',', [eq($bn),eq($v['code']),(int)$v['bales'],em($v['mass'],0),em($v['usd'])])."\r\n";
    }
    add_csv($zip, $files_added, '03_timb_by_buyer.csv', $out);

    $readme  = "FLOWORK TIMB REPORTS EXPORT\r\n";
    $readme .= "===========================\r\n";
    $readme .= "Floor: $sp_name | Date: $date_f | Generated: ".date('d M Y H:i')."\r\n\r\n";
    $readme .= "01_timb_bale_detail.csv - Full bale detail per TIMB barcode\r\n";
    $readme .= "02_timb_by_grade.csv    - Summary by TIMB grade\r\n";
    $readme .= "03_timb_by_buyer.csv    - Summary by buyer\r\n";
    add_csv($zip, $files_added, 'README.txt', $readme);
}

// ── Close zip and stream to browser ───────────────────────────────────────
$zip->close();

if (!file_exists($zip_path) || filesize($zip_path) === 0) {
    die('<p style="font-family:sans-serif;padding:40px;color:red">
        Export failed — no data found for the selected date/period.
    </p>');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.$zip_name.'"');
header('Content-Length: ' . filesize($zip_path));
header('Cache-Control: no-cache');
readfile($zip_path);

// Cleanup temp files
foreach (glob($tmp_dir . '/*') as $f) @unlink($f);
@rmdir($tmp_dir);
exit;
