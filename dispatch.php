<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Dispatch";
$active_page = "dispatch";
$userid      = current_userid();
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$msg = $err = '';
if (!empty($_GET['msg'])) $msg = htmlspecialchars(urldecode($_GET['msg']));

// ── Tables ─────────────────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS selling_point_dispatch (
    id INT AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL,
    buyerid INT NOT NULL, destination VARCHAR(120) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(buyerid))");
$conn->query("CREATE TABLE IF NOT EXISTS dispatch_destination (
    id INT AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL,
    selling_point_dispatchid INT NOT NULL,
    driver_name VARCHAR(100) DEFAULT '', driver_surname VARCHAR(100) DEFAULT '',
    driver_id_number VARCHAR(60) DEFAULT '', horse_num VARCHAR(40) DEFAULT '',
    trailer_num VARCHAR(40) DEFAULT '', open_close TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(selling_point_dispatchid))");
$conn->query("CREATE TABLE IF NOT EXISTS dispatch (
    id INT AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL,
    dispatch_destinationid INT NOT NULL, sold_baleid INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(dispatch_destinationid), INDEX(sold_baleid))");

// ── TPZ builder ────────────────────────────────────────────────────────────
// Confirmed layout from Virtual Warehouse spec (total 52 chars):
//  [0:8]   Sale date       DDMMYYYY
//  [8:18]  Barcode         10 chars (9 digits + check char)
//  [18:25] Grower V-number 7 chars
//  [25:29] Grower lot no   4 digits zero-padded
//  [29:32] Mass kg         3 digits zero-padded
//  [32:37] Price cents/kg  5 digits zero-padded  (e.g. 00440 = $4.40/kg)
//  [37:52] Grade           15 chars left-justified space-padded right
function tpz_line($sale_date, $barcode, $grower_num, $lot, $mass_kg, $price_usd_kg, $grade) {
    // [0:8] DDMMYYYY
    $date_str  = date('dmY', strtotime($sale_date));

    // [8:18] barcode — keep as-is, exactly 10 chars (right-pad or truncate)
    $bc        = str_pad(substr(trim($barcode), 0, 10), 10);

    // [18:25] grower V-number exactly 7 chars
    $gn        = str_pad(substr(trim($grower_num), 0, 7), 7);

    // [25:29] lot number 4 digits zero-padded
    $lot_s     = str_pad((int)$lot, 4, '0', STR_PAD_LEFT);

    // [29:32] mass integer 3 digits zero-padded
    $mass_s    = str_pad(min(999, (int)round((float)$mass_kg)), 3, '0', STR_PAD_LEFT);

    // [32:37] price in CENTS per kg, 5 digits zero-padded
    // Web floor stores price as USD/kg (e.g. 4.40); Android may store as cents×100 (e.g. 440)
    $p = (float)$price_usd_kg;
    if ($p > 10) $p /= 100;              // normalise: 440 → 4.40 USD/kg
    $price_cents = (int)round($p * 100); // 4.40 → 440
    $price_s = str_pad(min(99999, $price_cents), 5, '0', STR_PAD_LEFT);

    // [37:52] grade 15 chars, left-justified, right-padded with spaces
    $grade_s   = str_pad(substr(trim($grade), 0, 15), 15);

    $line = $date_str . $bc . $gn . $lot_s . $mass_s . $price_s . $grade_s;
    // Sanity: must be exactly 52 chars
    return str_pad(substr($line, 0, 52), 52);
}

// ── Load all bales for a destination ──────────────────────────────────────
function dest_bales($conn, $dest_id, $spid) {
    return $conn->query("
        SELECT d.id AS dispatch_id, d.created_at AS dispatch_at,
               t.barcode, bm.mass, bm.temp_bacode AS timb_bc,
               g.grower_num, g.name AS gname, g.surname,
               bj.lot, sb.price,
               tgd.description AS timb_grade, bg.description AS buyer_grade,
               b.name AS buyer_name, b.buyer_code, spd.destination,
               dd.horse_num, dd.driver_name, dd.driver_surname,
               dd.trailer_num, dd.driver_id_number
        FROM dispatch d
        JOIN dispatch_destination dd  ON dd.id  = d.dispatch_destinationid
        JOIN selling_point_dispatch spd ON spd.id = dd.selling_point_dispatchid
        JOIN buyer b                  ON b.id   = spd.buyerid
        JOIN sold_bales sb            ON sb.id  = d.sold_baleid
        JOIN tickets t                ON t.id   = sb.ticketsid
        JOIN bale_junus bj            ON bj.id  = t.bale_junusid
        JOIN bale_mass bm             ON bm.id  = bj.bale_massid
        JOIN growers g                ON g.id   = bj.growerid
        LEFT JOIN timb_grades tgd     ON tgd.id = sb.timb_gradesid
        LEFT JOIN buyer_grades bg     ON bg.id  = sb.buyer_gradesid
        WHERE d.dispatch_destinationid = $dest_id
          AND b.selling_pointid = $spid
        ORDER BY d.id ASC
    ");
}

function dest_info($conn, $dest_id) {
    return $conn->query("
        SELECT dd.*, spd.destination, b.name AS buyer_name, b.buyer_code
        FROM dispatch_destination dd
        JOIN selling_point_dispatch spd ON spd.id = dd.selling_point_dispatchid
        JOIN buyer b ON b.id = spd.buyerid
        WHERE dd.id = $dest_id LIMIT 1
    ")->fetch_assoc();
}

// ── EXPORT — runs before HTML ──────────────────────────────────────────────
$xtype = $_GET['export']  ?? '';
$xdest = (int)($_GET['dest_id'] ?? 0);
$xdate = $_GET['date']    ?? date('Y-m-d');

if ($xtype && $xdest) {
    $bq    = dest_bales($conn, $xdest, $spid);
    $bales = $bq ? $bq->fetch_all(MYSQLI_ASSOC) : [];
    $di    = dest_info($conn, $xdest);
    $mmdd  = date('md', strtotime($xdate));
    $slug  = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $di['horse_num'] ?? 'DISP'));

    // ── .v  (TPZ Virtual Warehouse) ───────────────────────────────────
    if ($xtype === 'tpz') {
        header('Content-Type: text/plain; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$slug}{$mmdd}.v\"");
        foreach ($bales as $b) {
            $grade = trim($b['timb_grade'] ?? $b['buyer_grade'] ?? '');
            echo tpz_line($xdate, $b['barcode'], $b['grower_num'],
                          $b['lot'], $b['mass'], $b['price'], $grade) . "\r\n";
        }
        exit;
    }

    // ── CSV ────────────────────────────────────────────────────────────
    if ($xtype === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"dispatch_{$slug}_{$mmdd}.csv\"");
        echo "Sale Date,Barcode,TIMB Barcode,Grower #,Grower Name,Lot,Mass (kg),"
           . "Price (USD/kg),Value (USD),TIMB Grade,Buyer Grade,Buyer,"
           . "Destination,Horse #,Driver\r\n";
        foreach ($bales as $b) {
            $p = (float)$b['price']; if ($p > 10) $p /= 100;
            $v = round((float)$b['mass'] * $p, 2);
            echo implode(',', [
                $xdate,
                '"'.$b['barcode'].'"',
                '"'.($b['timb_bc'] ?? '').'"',
                '"'.$b['grower_num'].'"',
                '"'.$b['gname'].' '.$b['surname'].'"',
                (int)$b['lot'],
                (int)$b['mass'],
                number_format($p, 4),
                number_format($v, 2),
                '"'.($b['timb_grade'] ?? '').'"',
                '"'.($b['buyer_grade'] ?? '').'"',
                '"'.($b['buyer_name'] ?? '').'"',
                '"'.($di['destination'] ?? '').'"',
                '"'.($di['horse_num'] ?? '').'"',
                '"'.$di['driver_name'].' '.$di['driver_surname'].'"',
            ]) . "\r\n";
        }
        exit;
    }

    // ── PDF dispatch note (print-ready HTML) ──────────────────────────
    if ($xtype === 'pdf') {
        $tot_mass = array_sum(array_column($bales, 'mass'));
        $tot_val  = array_reduce($bales, function ($c, $b) {
            $p = (float)$b['price']; if ($p > 10) $p /= 100;
            return $c + (float)$b['mass'] * $p;
        }, 0);
        $co_q    = $conn->query("SELECT param_value FROM parameters WHERE param_key='company_name' LIMIT 1");
        $company = $co_q ? ($co_q->fetch_assoc()['param_value'] ?? 'Flowork') : 'Flowork';
        header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Dispatch Note — <?= htmlspecialchars($di['horse_num']) ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;font-size:10px;color:#111;padding:14px}
.hdr{display:flex;justify-content:space-between;align-items:flex-start;
     margin-bottom:10px;padding-bottom:8px;border-bottom:2px solid #1a1208}
.hdr h1{font-size:17px;font-weight:700;color:#1a1208;letter-spacing:.05em}
.hdr h2{font-size:10px;color:#666;margin-top:2px}
.tag{background:#c8952a;color:#fff;font-size:8px;font-weight:700;
     padding:2px 6px;border-radius:2px;text-transform:uppercase;letter-spacing:.1em}
.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;
      background:#f5f0e8;padding:8px;border-radius:3px;margin-bottom:10px}
.mi label{display:block;font-size:7.5px;font-weight:700;letter-spacing:.12em;
          text-transform:uppercase;color:#7a6e5f;margin-bottom:2px}
.mi span{font-size:10.5px;font-weight:700}
table{width:100%;border-collapse:collapse;margin-bottom:10px}
thead th{background:#1a1208;color:#e8b84b;font-size:7.5px;font-weight:700;
         letter-spacing:.1em;text-transform:uppercase;padding:4px 5px;text-align:left}
tbody td{padding:3.5px 5px;font-size:8.5px;border-bottom:1px solid #ede6d6}
tbody tr:nth-child(even){background:#faf8f5}
tfoot td{background:#ede6d6;font-weight:700;padding:4px 5px;
         font-size:8.5px;border-top:2px solid #1a1208}
.mono{font-family:monospace}.right{text-align:right}
.sigs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:14px}
.sig{border-top:1px solid #1a1208;padding-top:5px}
.sig label{font-size:7.5px;font-weight:700;letter-spacing:.1em;
           text-transform:uppercase;color:#7a6e5f;display:block;margin-bottom:16px}
@media print{body{padding:0}@page{margin:10mm 8mm}}
</style></head><body>
<div class="hdr">
  <div><h1><?=htmlspecialchars($company)?></h1><h2>Dispatch Note</h2></div>
  <div style="text-align:right">
    <span class="tag">Dispatch Note</span>
    <div style="font-size:8px;color:#666;margin-top:4px">
      Sale date: <?=date('d M Y',strtotime($xdate))?><br>
      Printed: <?=date('d M Y H:i')?>
    </div>
  </div>
</div>
<div class="meta">
  <div class="mi"><label>Buyer</label>
    <span><?=htmlspecialchars($di['buyer_code'] ?: $di['buyer_name'])?></span></div>
  <div class="mi"><label>Destination</label>
    <span><?=htmlspecialchars($di['destination'])?></span></div>
  <div class="mi"><label>Horse #</label>
    <span><?=htmlspecialchars($di['horse_num'])?></span></div>
  <div class="mi"><label>Trailer #</label>
    <span><?=htmlspecialchars($di['trailer_num']?:'—')?></span></div>
  <div class="mi"><label>Driver</label>
    <span><?=htmlspecialchars(trim($di['driver_name'].' '.$di['driver_surname']))?></span></div>
  <div class="mi"><label>Driver ID</label>
    <span><?=htmlspecialchars($di['driver_id_number']?:'—')?></span></div>
  <div class="mi"><label>Total Bales</label>
    <span><?=count($bales)?></span></div>
  <div class="mi"><label>Total Mass</label>
    <span><?=number_format($tot_mass,0)?> kg</span></div>
</div>
<table>
<thead><tr>
  <th>#</th><th>Barcode</th><th>Grower #</th><th>Grower Name</th>
  <th>Lot</th><th>TIMB Grade</th><th>Buyer Grade</th>
  <th class="right">Mass (kg)</th><th class="right">Price $/kg</th>
  <th class="right">Value USD</th>
</tr></thead>
<tbody>
<?php $sq=0; foreach($bales as $b): $sq++;
    $p=(float)$b['price']; if($p>10)$p/=100; $v=(float)$b['mass']*$p; ?>
<tr>
  <td class="mono" style="color:#888"><?=$sq?></td>
  <td class="mono"><?=htmlspecialchars($b['barcode'])?></td>
  <td><span style="background:#fdf6e8;border:1px solid #e8c96a;padding:1px 4px;
       border-radius:2px;font-weight:700;font-size:7.5px"><?=htmlspecialchars($b['grower_num'])?></span></td>
  <td><?=htmlspecialchars(trim($b['gname'].' '.$b['surname']))?></td>
  <td class="mono right"><?=(int)$b['lot']?></td>
  <td><span style="background:#1a1208;color:#e8b84b;padding:1px 3px;
       border-radius:2px;font-size:7.5px"><?=htmlspecialchars($b['timb_grade']??'—')?></span></td>
  <td style="color:#666"><?=htmlspecialchars($b['buyer_grade']??'—')?></td>
  <td class="mono right"><?=number_format((float)$b['mass'],0)?></td>
  <td class="mono right"><?=$p>0?'$'.number_format($p,4):'—'?></td>
  <td class="mono right"><?=$v>0?'$'.number_format($v,2):'—'?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr>
  <td colspan="7" style="text-align:right">TOTAL &nbsp;—&nbsp; <?=count($bales)?> bales</td>
  <td class="right"><?=number_format($tot_mass,0)?> kg</td>
  <td></td>
  <td class="right">$<?=number_format($tot_val,2)?></td>
</tr></tfoot>
</table>
<div class="sigs">
  <div class="sig"><label>Dispatched by (Floor)</label></div>
  <div class="sig"><label>Received by (Driver)</label></div>
  <div class="sig"><label>Received at Warehouse</label></div>
</div>
<script>window.print();</script>
</body></html>
<?php     exit;
    }
}

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_dispatch') {
        $bid  = (int)($_POST['buyerid'] ?? 0);
        $dest = $conn->real_escape_string(trim($_POST['destination'] ?? ''));
        if (!$bid || !$dest) $err = "Buyer and destination required.";
        elseif ($conn->query("SELECT id FROM selling_point_dispatch WHERE buyerid=$bid AND destination='$dest'")->num_rows)
            $err = "Dispatch point already exists.";
        else { $conn->query("INSERT INTO selling_point_dispatch (userid,buyerid,destination) VALUES ($userid,$bid,'$dest')"); $msg = "Dispatch point created."; }
    }

    if ($action === 'add_dest') {
        $spd = (int)($_POST['selling_point_dispatchid'] ?? 0);
        $dn  = $conn->real_escape_string(trim($_POST['driver_name']    ?? ''));
        $ds  = $conn->real_escape_string(trim($_POST['driver_surname'] ?? ''));
        $did = $conn->real_escape_string(trim($_POST['driver_id_number'] ?? ''));
        $hn  = $conn->real_escape_string(trim($_POST['horse_num']  ?? ''));
        $tn  = $conn->real_escape_string(trim($_POST['trailer_num'] ?? ''));
        if (!$spd || !$dn || !$hn) $err = "Dispatch point, driver name and horse # required.";
        else { $conn->query("INSERT INTO dispatch_destination (userid,selling_point_dispatchid,driver_name,driver_surname,driver_id_number,horse_num,trailer_num) VALUES ($userid,$spd,'$dn','$ds','$did','$hn','$tn')"); $msg = "Truck added: $dn — $hn."; }
    }

    // ── Scan one barcode into the session queue ──────────────────────────
    if ($action === 'scan_bale') {
        $dest_id = (int)($_POST['dispatch_destinationid'] ?? 0);
        $bc      = strtoupper(trim($_POST['barcode'] ?? ''));
        if (!$dest_id || !$bc) { $err = "Select a truck first."; }
        else {
            // Init session queue keyed by destination
            if (!isset($_SESSION['dispatch_queue'])) $_SESSION['dispatch_queue'] = [];
            $key = "dest_$dest_id";
            if (!isset($_SESSION['dispatch_queue'][$key]))
                $_SESSION['dispatch_queue'][$key] = ['dest_id'=>$dest_id,'barcodes'=>[],'results'=>[]];

            $q = &$_SESSION['dispatch_queue'][$key];
            $bc_esc = $conn->real_escape_string($bc);

            // Duplicate in queue?
            if (in_array($bc, $q['barcodes'])) {
                $q['results'][$bc] = ['status'=>'dup','msg'=>'Already in this batch'];
            } else {
                // Look up sold bale
                $sb = $conn->query("SELECT sb.id FROM sold_bales sb JOIN tickets t ON t.id=sb.ticketsid WHERE t.barcode='$bc_esc' LIMIT 1")->fetch_assoc();
                if (!$sb) {
                    $q['results'][$bc] = ['status'=>'err','msg'=>'Not found or not sold'];
                } elseif ($conn->query("SELECT id FROM dispatch WHERE sold_baleid={$sb['id']}")->num_rows) {
                    $q['results'][$bc] = ['status'=>'err','msg'=>'Already dispatched'];
                } else {
                    $q['barcodes'][] = $bc;
                    $q['results'][$bc] = ['status'=>'ok','msg'=>'Ready','sold_baleid'=>$sb['id']];
                }
            }
            // Keep dest_id in GET so page reloads correctly
            $spd_back = (int)($_POST['spd'] ?? 0);
            $dest_back = (int)($_POST['dest_back'] ?? 0);
            $redir = "dispatch.php?spd=$spd_back&dest=$dest_back&scanning=$dest_id";
            header("Location: $redir"); exit;
        }
    }

    // ── Remove one barcode from session queue ─────────────────────────
    if ($action === 'remove_scan') {
        $dest_id = (int)($_POST['dest_id'] ?? 0);
        $bc      = trim($_POST['barcode'] ?? '');
        $key     = "dest_$dest_id";
        if (isset($_SESSION['dispatch_queue'][$key])) {
            $q = &$_SESSION['dispatch_queue'][$key];
            $q['barcodes'] = array_values(array_filter($q['barcodes'], fn($b)=>$b!==$bc));
            unset($q['results'][$bc]);
        }
        $spd_back  = (int)($_POST['spd']  ?? 0);
        $dest_back = (int)($_POST['dest_back'] ?? 0);
        header("Location: dispatch.php?spd=$spd_back&dest=$dest_back&scanning=$dest_id"); exit;
    }

    // ── Clear entire session queue for a destination ──────────────────
    if ($action === 'clear_queue') {
        $dest_id = (int)($_POST['dest_id'] ?? 0);
        unset($_SESSION['dispatch_queue']["dest_$dest_id"]);
        $spd_back  = (int)($_POST['spd']  ?? 0);
        $dest_back = (int)($_POST['dest_back'] ?? 0);
        header("Location: dispatch.php?spd=$spd_back&dest=$dest_back"); exit;
    }

    // ── Commit all queued bales to dispatch ───────────────────────────
    if ($action === 'commit_dispatch') {
        $dest_id = (int)($_POST['dest_id'] ?? 0);
        $key     = "dest_$dest_id";
        $queue   = $_SESSION['dispatch_queue'][$key] ?? [];
        $done = $fail = 0;
        foreach ($queue['results'] ?? [] as $bc => $r) {
            if ($r['status'] === 'ok' && isset($r['sold_baleid'])) {
                $sid = (int)$r['sold_baleid'];
                // Final check: not already dispatched by another session
                if (!$conn->query("SELECT id FROM dispatch WHERE sold_baleid=$sid")->num_rows) {
                    $conn->query("INSERT INTO dispatch (userid,dispatch_destinationid,sold_baleid) VALUES ($userid,$dest_id,$sid)");
                    $done++;
                } else $fail++;
            }
        }
        unset($_SESSION['dispatch_queue'][$key]);
        $msg = "✓ $done bale(s) dispatched." . ($fail ? " $fail skipped (already dispatched)." : "");
        $spd_back  = (int)($_POST['spd']  ?? 0);
        $dest_back = (int)($_POST['dest_back'] ?? 0);
        header("Location: dispatch.php?spd=$spd_back&dest=$dest_back&msg=".urlencode($msg)); exit;
    }

    // ── Legacy single-bale dispatch (kept for backward compat) ───────
    if ($action === 'dispatch_bale') {
        $dest_id = (int)($_POST['dispatch_destinationid'] ?? 0);
        $bc      = $conn->real_escape_string(trim($_POST['barcode'] ?? ''));
        if (!$dest_id || !$bc) $err = "Select destination and scan barcode.";
        else {
            $sb = $conn->query("SELECT sb.id FROM sold_bales sb JOIN tickets t ON t.id=sb.ticketsid WHERE t.barcode='$bc' LIMIT 1")->fetch_assoc();
            if (!$sb) $err = "Barcode '$bc' not found or not sold yet.";
            elseif ($conn->query("SELECT id FROM dispatch WHERE sold_baleid={$sb['id']}")->num_rows)
                $err = "Bale '$bc' already dispatched.";
            else { $conn->query("INSERT INTO dispatch (userid,dispatch_destinationid,sold_baleid) VALUES ($userid,$dest_id,{$sb['id']})"); $msg = "✓ Bale $bc dispatched."; }
        }
    }

    if ($action === 'toggle_dest') {
        $did = (int)($_POST['did'] ?? 0); $oc = (int)($_POST['open_close'] ?? 0);
        $conn->query("UPDATE dispatch_destination SET open_close=$oc WHERE id=$did");
        $msg = $oc ? "Truck closed." : "Truck re-opened.";
    }

    if ($action === 'remove_bale') {
        $rid = (int)($_POST['dispatch_id'] ?? 0);
        if ($rid) { $conn->query("DELETE FROM dispatch WHERE id=$rid"); $msg = "Bale removed."; }
    }
}

// ── Data ────────────────────────────────────────────────────────────────────
$today   = date('Y-m-d');
// Active scanning session
$scanning_dest   = (int)($_GET['scanning'] ?? 0);
$active_queue    = $scanning_dest && isset($_SESSION['dispatch_queue']["dest_$scanning_dest"])
                 ? $_SESSION['dispatch_queue']["dest_$scanning_dest"]
                 : null;
$show_scan_inline = !empty($active_queue);
$buyers  = $conn->query("SELECT * FROM buyer WHERE selling_pointid=$spid ORDER BY name");

$dispatch_points = $conn->query("
    SELECT spd.*, b.name AS buyer_name, b.buyer_code,
           COUNT(DISTINCT dd.id) AS dest_count,
           SUM(CASE WHEN dd.open_close=1 THEN 1 ELSE 0 END) AS open_count
    FROM selling_point_dispatch spd
    JOIN buyer b ON b.id=spd.buyerid
    LEFT JOIN dispatch_destination dd ON dd.selling_point_dispatchid=spd.id
    WHERE b.selling_pointid=$spid
    GROUP BY spd.id ORDER BY b.name, spd.destination
");

$sel_spd  = (int)($_GET['spd']  ?? 0);
$sel_dest = (int)($_GET['dest'] ?? 0);
$sel_point = $destinations = null;

if ($sel_spd) {
    $sel_point = $conn->query("SELECT spd.*,b.name AS buyer_name FROM selling_point_dispatch spd JOIN buyer b ON b.id=spd.buyerid WHERE spd.id=$sel_spd")->fetch_assoc();
    $destinations = $conn->query("
        SELECT dd.*, COUNT(d.id) AS bale_count, COALESCE(SUM(bm.mass),0) AS total_mass
        FROM dispatch_destination dd
        LEFT JOIN dispatch d ON d.dispatch_destinationid=dd.id
        LEFT JOIN sold_bales sb ON sb.id=d.sold_baleid
        LEFT JOIN tickets t ON t.id=sb.ticketsid
        LEFT JOIN bale_junus bj ON bj.id=t.bale_junusid
        LEFT JOIN bale_mass bm ON bm.id=bj.bale_massid
        WHERE dd.selling_point_dispatchid=$sel_spd
        GROUP BY dd.id ORDER BY dd.id DESC
    ");
}

$open_dests = $conn->query("
    SELECT dd.id, dd.horse_num, dd.driver_name, dd.driver_surname,
           b.name AS buyer_name, spd.destination
    FROM dispatch_destination dd
    JOIN selling_point_dispatch spd ON spd.id=dd.selling_point_dispatchid
    JOIN buyer b ON b.id=spd.buyerid
    WHERE dd.open_close=1 AND b.selling_pointid=$spid ORDER BY dd.id DESC LIMIT 50
");

// Bale detail for selected destination
$dest_bales_arr = []; $dest_sel_info = null;
if ($sel_dest) {
    $dbq = dest_bales($conn, $sel_dest, $spid);
    $dest_bales_arr = $dbq ? $dbq->fetch_all(MYSQLI_ASSOC) : [];
    $dest_sel_info  = dest_info($conn, $sel_dest);
}

$today_count = $conn->query("
    SELECT COUNT(*) c, COALESCE(SUM(bm.mass),0) kg
    FROM dispatch d
    JOIN dispatch_destination dd ON dd.id=d.dispatch_destinationid
    JOIN selling_point_dispatch spd ON spd.id=dd.selling_point_dispatchid
    JOIN buyer b ON b.id=spd.buyerid
    JOIN sold_bales sb ON sb.id=d.sold_baleid
    JOIN tickets t ON t.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=t.bale_junusid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    WHERE DATE(d.created_at)='$today' AND b.selling_pointid=$spid
")->fetch_assoc() ?? ['c'=>0,'kg'=>0];

$log_today = $conn->query("
    SELECT d.id AS dispatch_id, d.created_at,
           t.barcode, bm.mass, g.grower_num, g.name AS grower_name,
           b.name AS buyer_name, spd.destination,
           dd.horse_num, dd.id AS dest_id, spd.id AS spd_id
    FROM dispatch d
    JOIN dispatch_destination dd ON dd.id=d.dispatch_destinationid
    JOIN selling_point_dispatch spd ON spd.id=dd.selling_point_dispatchid
    JOIN buyer b ON b.id=spd.buyerid
    JOIN sold_bales sb ON sb.id=d.sold_baleid
    JOIN tickets t ON t.id=sb.ticketsid
    JOIN bale_junus bj ON bj.id=t.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    WHERE DATE(d.created_at)='$today' AND b.selling_pointid=$spid
    ORDER BY d.id DESC LIMIT 100
");

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Dispatch</h2>
    <p>Today: <strong><?= number_format((int)$today_count['c']) ?> bales</strong>
       &nbsp;·&nbsp; <strong><?= number_format((float)$today_count['kg'],0) ?> kg</strong></p>
  </div>
  <div class="flex gap-2">
    <?php if($show_scan_inline): ?>
    <a href="#scan-form" class="btn btn-primary" onclick="document.getElementById('bc')?.focus()">⬆ Continue Scanning</a>
    <?php else: ?>
    <button class="btn btn-primary" onclick="tog('bale-dispatch');setTimeout(()=>document.getElementById('bc')?.focus(),60)">⬆ Dispatch Bales</button>
    <?php endif; ?>
    <button class="btn btn-ghost"   onclick="tog('dispatch-form')">+ Setup Point</button>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- ── Multi-scan dispatch session ───────────────────────────────────── -->
<?php
// Build open_dests array for reuse in scan form
$open_dests_arr = [];
if ($open_dests) { $open_dests->data_seek(0); while ($r = $open_dests->fetch_assoc()) $open_dests_arr[] = $r; }

// If a scan session is active, show it inline (always visible, no toggle)
?>

<?php if ($show_scan_inline): ?>
<?php
$q       = $active_queue;
$qd      = $scanning_dest;
$ok_cnt  = count(array_filter($q['results'], fn($r)=>$r['status']==='ok'));
$err_cnt = count(array_filter($q['results'], fn($r)=>$r['status']==='err'));
$dup_cnt = count(array_filter($q['results'], fn($r)=>$r['status']==='dup'));
// Get truck info
$truck_info = null;
foreach ($open_dests_arr as $od) { if ($od['id'] == $qd) { $truck_info = $od; break; } }
// Also check closed trucks
if (!$truck_info) {
    $ti = $conn->query("SELECT dd.*,b.name AS buyer_name,spd.destination FROM dispatch_destination dd JOIN selling_point_dispatch spd ON spd.id=dd.selling_point_dispatchid JOIN buyer b ON b.id=spd.buyerid WHERE dd.id=$qd LIMIT 1")->fetch_assoc();
    $truck_info = $ti;
}
?>
<div class="card mb-3" style="border-top:3px solid var(--gold)">
<div class="card-head" style="background:var(--surface-2);color:var(--text)">
  <div>
    <div class="fw-500" style="font-size:.95rem">
      🚛 Scanning → <?= htmlspecialchars($truck_info['horse_num'] ?? 'Truck') ?>
      <span style="font-size:.75rem;font-weight:400;color:rgba(245,240,232,.5);margin-left:8px">
        <?= htmlspecialchars(($truck_info['buyer_name']??'').' → '.($truck_info['destination']??'')) ?>
      </span>
    </div>
    <div style="display:flex;gap:.5rem;margin-top:5px">
      <span class="badge badge-green"><?= $ok_cnt ?> ready</span>
      <?php if($err_cnt):?><span class="badge badge-red"><?= $err_cnt ?> error</span><?php endif;?>
      <?php if($dup_cnt):?><span class="badge badge-gray"><?= $dup_cnt ?> dup</span><?php endif;?>
    </div>
  </div>
  <div class="flex gap-2">
    <?php if($ok_cnt > 0): ?>
    <form method="POST">
      <input type="hidden" name="action" value="commit_dispatch">
      <input type="hidden" name="dest_id" value="<?= $qd ?>">
      <input type="hidden" name="spd"  value="<?= $sel_spd ?>">
      <input type="hidden" name="dest_back" value="<?= $sel_dest ?>">
      <button class="btn btn-primary" style="font-size:.82rem">
        ✓ Dispatch <?= $ok_cnt ?> Bale<?= $ok_cnt!=1?'s':'' ?>
      </button>
    </form>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="action" value="clear_queue">
      <input type="hidden" name="dest_id" value="<?= $qd ?>">
      <input type="hidden" name="spd"  value="<?= $sel_spd ?>">
      <input type="hidden" name="dest_back" value="<?= $sel_dest ?>">
      <button class="btn btn-ghost btn-sm" style="color:rgba(245,240,232,.55)">✕ Cancel</button>
    </form>
  </div>
</div>

<!-- Scan input -->
<div style="padding:.85rem 1.15rem;border-bottom:1px solid rgba(200,149,42,.15);background:rgba(200,149,42,.05)">
<form method="POST" id="scan-form">
  <input type="hidden" name="action" value="scan_bale">
  <input type="hidden" name="dispatch_destinationid" value="<?= $qd ?>">
  <input type="hidden" name="spd"  value="<?= $sel_spd ?>">
  <input type="hidden" name="dest_back" value="<?= $sel_dest ?>">
  <div style="display:flex;gap:.6rem;align-items:center">
    <input type="text" name="barcode" id="bc" class="form-control" autofocus autocomplete="off"
           placeholder="Scan next barcode…"
           style="font-family:monospace;font-size:1.1rem;letter-spacing:.07em;flex:1;max-width:340px">
    <button type="submit" class="btn btn-gold">Add →</button>
    <span style="font-size:.72rem;color:var(--muted)">Scan continuously — barcodes queue below</span>
  </div>
</form>
</div>

<!-- Queue table -->
<?php if (!empty($q['results'])): ?>
<div class="table-wrap" style="max-height:320px;overflow-y:auto"><table>
<thead><tr>
  <th>Barcode</th><th>Status</th><th>Note</th><th></th>
</tr></thead>
<tbody>
<?php foreach (array_reverse(array_keys($q['results'])) as $bc):
  $r = $q['results'][$bc];
  $status_badge = match($r['status']) {
    'ok'  => '<span class="badge badge-green">✓ Ready</span>',
    'err' => '<span class="badge badge-red">✗ Error</span>',
    'dup' => '<span class="badge badge-gray">Duplicate</span>',
    default => ''
  };
?>
<tr style="<?= $r['status']==='err'?'background:rgba(192,57,43,.05)':($r['status']==='ok'?'':'background:rgba(0,0,0,.03)') ?>">
  <td><code style="font-size:.82rem;letter-spacing:.05em"><?= htmlspecialchars($bc) ?></code></td>
  <td><?= $status_badge ?></td>
  <td style="font-size:.75rem;color:var(--muted)"><?= htmlspecialchars($r['msg']) ?></td>
  <td>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="remove_scan">
      <input type="hidden" name="dest_id" value="<?= $qd ?>">
      <input type="hidden" name="barcode" value="<?= htmlspecialchars($bc) ?>">
      <input type="hidden" name="spd" value="<?= $sel_spd ?>">
      <input type="hidden" name="dest_back" value="<?= $sel_dest ?>">
      <button class="btn btn-ghost btn-sm" style="font-size:.65rem">Remove</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
<?php else: ?>
<div style="padding:1.5rem;text-align:center;color:var(--muted);font-size:.82rem">
  Scan the first barcode to start building the dispatch batch.
</div>
<?php endif; ?>

<?php if($ok_cnt > 0): ?>
<div style="padding:.65rem 1.15rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end">
  <form method="POST">
    <input type="hidden" name="action" value="commit_dispatch">
    <input type="hidden" name="dest_id" value="<?= $qd ?>">
    <input type="hidden" name="spd"  value="<?= $sel_spd ?>">
    <input type="hidden" name="dest_back" value="<?= $sel_dest ?>">
    <button class="btn btn-primary">✓ Dispatch <?= $ok_cnt ?> Bale<?= $ok_cnt!=1?'s':'' ?> Now</button>
  </form>
</div>
<?php endif; ?>
</div>

<?php else: ?>

<!-- Start new scan session (collapsed) -->
<div id="bale-dispatch" style="display:none;margin-bottom:1.5rem">
<div class="card" style="border-top:3px solid var(--gold)">
<div class="card-head">
  <h3>Dispatch Bales to Truck</h3>
  <button class="btn btn-ghost btn-sm" onclick="tog('bale-dispatch')">✕</button>
</div>
<div class="card-body">
<form method="POST" action="dispatch.php">
  <input type="hidden" name="action" value="scan_bale">
  <div class="form-row cols-2">
    <div class="form-group"><label>Active Truck *</label>
    <select name="dispatch_destinationid" id="truck-sel" class="form-control" required
            onchange="updateScanUrl(this)">
      <option value="">— Select open truck —</option>
      <?php foreach($open_dests_arr as $r): ?>
      <option value="<?=$r['id']?>"
              data-spd="<?= $sel_spd ?>"
              data-dest="<?= $sel_dest ?>">
        <?=htmlspecialchars($r['horse_num'])?> — <?=htmlspecialchars(trim($r['driver_name'].' '.$r['driver_surname']))?> · <?=htmlspecialchars($r['buyer_name'])?> → <?=htmlspecialchars($r['destination'])?>
      </option>
      <?php endforeach; ?>
    </select>
    </div>
    <div class="form-group"><label>First Barcode *</label>
    <input type="text" name="barcode" id="bc" class="form-control" autocomplete="off"
           placeholder="Scan first barcode to start session…"
           style="font-family:monospace;font-size:1.05rem;letter-spacing:.06em">
    <input type="hidden" name="spd"  value="<?= $sel_spd ?>">
    <input type="hidden" name="dest_back" value="<?= $sel_dest ?>">
    </div>
  </div>
  <button type="submit" class="btn btn-primary" style="padding:.7rem 2rem">Start Scanning →</button>
</form>
</div></div></div>

<?php endif; ?>

<!-- ── Setup dispatch point form ─────────────────────────────────────── -->
<div id="dispatch-form" style="display:none;margin-bottom:1.5rem">
<div class="card"><div class="card-head"><h3>Setup Dispatch Point</h3>
<button class="btn btn-ghost btn-sm" onclick="tog('dispatch-form')">Cancel</button></div>
<div class="card-body"><form method="POST"><input type="hidden" name="action" value="add_dispatch">
<div class="form-row cols-2">
  <div class="form-group"><label>Buyer *</label>
  <select name="buyerid" class="form-control" required><option value="">— Select buyer —</option>
  <?php if($buyers) while($r=$buyers->fetch_assoc()):?>
  <option value="<?=$r['id']?>"><?=htmlspecialchars($r['buyer_code'] ?: $r['name'])?></option>
  <?php endwhile;?></select></div>
  <div class="form-group"><label>Destination / Warehouse *</label>
  <input type="text" name="destination" class="form-control" required placeholder="e.g. Harare Tobacco Warehouse"></div>
</div>
<button type="submit" class="btn btn-primary">Create Point</button>
</form></div></div></div>

<div class="premium-tabs-nav" data-tabs="dispatch">
  <button type="button" class="premium-tab-btn active" data-tab="board">🚚 Dispatch Board</button>
  <button type="button" class="premium-tab-btn" data-tab="log">📋 Today's Log</button>
</div>

<div id="panel-board" class="tab-panel active" data-tabs="dispatch">

<!-- ── Main grid ─────────────────────────────────────────────────────── -->
<div class="grid-2" style="align-items:start;margin-bottom:1.5rem">

<!-- Dispatch points -->
<div class="card"><div class="card-head"><h3>Dispatch Points</h3></div>
<div class="table-wrap"><table>
<thead><tr><th>Buyer</th><th>Destination</th><th class="text-right">Trucks</th><th></th></tr></thead>
<tbody>
<?php if($dispatch_points&&$dispatch_points->num_rows>0):while($r=$dispatch_points->fetch_assoc()):?>
<tr style="cursor:pointer;<?=$sel_spd==$r['id']?'background:var(--gold-bg);border-left:3px solid var(--gold)':''?>"
    onclick="location='?spd=<?=$r['id']?>'">
  <td><span class="badge badge-blue" style="font-size:.72rem"><?=htmlspecialchars($r['buyer_code'])?></span>
      <span style="font-size:.82rem;margin-left:5px"><?=htmlspecialchars($r['buyer_name'])?></span></td>
  <td class="fw-500" style="font-size:.82rem"><?=htmlspecialchars($r['destination'])?></td>
  <td class="text-right">
    <?php if($r['open_count']>0):?><span class="badge badge-green" style="font-size:.72rem"><?=$r['open_count']?> open</span><?php endif;?>
    <span class="badge badge-gray" style="font-size:.72rem"><?=$r['dest_count']?> total</span>
  </td>
  <td><a href="?spd=<?=$r['id']?>" class="btn btn-ghost btn-sm" onclick="event.stopPropagation()">Manage</a></td>
</tr>
<?php endwhile;else:?>
<tr><td colspan="4" class="text-center text-muted" style="padding:2rem">No dispatch points. Click "+ Setup Point".</td></tr>
<?php endif;?>
</tbody></table></div></div>

<!-- Trucks for selected point -->
<div class="card">
<?php if($sel_point):?>
<div class="card-head">
  <h3><?=htmlspecialchars($sel_point['buyer_name'])?> → <?=htmlspecialchars($sel_point['destination'])?></h3>
  <button class="btn btn-gold btn-sm" onclick="tog('truck-form')">+ Add Truck</button>
</div>
<div id="truck-form" style="display:none;padding:1rem 1.25rem;border-bottom:1px solid var(--border);background:var(--surface)">
<form method="POST"><input type="hidden" name="action" value="add_dest">
<input type="hidden" name="selling_point_dispatchid" value="<?=$sel_spd?>">
<div class="form-row cols-2">
  <div class="form-group"><label>Driver Name *</label><input type="text" name="driver_name" class="form-control" required></div>
  <div class="form-group"><label>Driver Surname</label><input type="text" name="driver_surname" class="form-control"></div>
</div>
<div class="form-row cols-3">
  <div class="form-group"><label>Driver ID</label><input type="text" name="driver_id_number" class="form-control"></div>
  <div class="form-group"><label>Horse # *</label><input type="text" name="horse_num" class="form-control" required></div>
  <div class="form-group"><label>Trailer #</label><input type="text" name="trailer_num" class="form-control"></div>
</div>
<div class="flex gap-2">
  <button type="submit" class="btn btn-primary">Add Truck</button>
  <button type="button" class="btn btn-ghost" onclick="tog('truck-form')">Cancel</button>
</div></form></div>

<div class="table-wrap"><table>
<thead><tr>
  <th>Driver</th><th>Horse #</th><th>Trailer</th>
  <th class="text-right">Bales</th><th class="text-right">Mass</th>
  <th>Status</th><th></th>
</tr></thead><tbody>
<?php if($destinations&&$destinations->num_rows>0):while($r=$destinations->fetch_assoc()):?>
<tr style="<?=$sel_dest==$r['id']?'background:var(--gold-bg)':''?>">
  <td>
    <div class="fw-500" style="font-size:.82rem"><?=htmlspecialchars(trim($r['driver_name'].' '.$r['driver_surname']))?></div>
    <?php if($r['driver_id_number']):?><div class="td-muted" style="font-size:.7rem"><?=htmlspecialchars($r['driver_id_number'])?></div><?php endif;?>
  </td>
  <td><span class="badge badge-gray"><?=htmlspecialchars($r['horse_num'])?></span></td>
  <td class="td-muted" style="font-size:.78rem"><?=htmlspecialchars($r['trailer_num']?:'—')?></td>
  <td class="td-mono text-right fw-500"><?=number_format((int)$r['bale_count'])?></td>
  <td class="td-mono text-right"><?=$r['total_mass']>0?number_format((float)$r['total_mass'],0).' kg':'—'?></td>
  <td><?=$r['open_close']
      ?'<span class="badge badge-green" style="font-size:.62rem">Open</span>'
      :'<span class="badge badge-red"   style="font-size:.62rem">Closed</span>'?></td>
  <td style="white-space:nowrap">
    <a href="?spd=<?=$sel_spd?>&dest=<?=$r['id']?>" class="btn btn-ghost btn-sm">View</a>
    <?php if((int)$r['bale_count']>0):?>
    <a href="?export=tpz&dest_id=<?=$r['id']?>&date=<?=$today?>&spd=<?=$sel_spd?>"
       class="btn btn-ghost btn-sm" title="Virtual Warehouse .v (TPZ format)">⬇ .v</a>
    <a href="?export=csv&dest_id=<?=$r['id']?>&date=<?=$today?>&spd=<?=$sel_spd?>"
       class="btn btn-ghost btn-sm">⬇ CSV</a>
    <a href="?export=pdf&dest_id=<?=$r['id']?>&date=<?=$today?>&spd=<?=$sel_spd?>"
       class="btn btn-ghost btn-sm" target="_blank">🖨 PDF</a>
    <?php endif;?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="toggle_dest">
      <input type="hidden" name="did" value="<?=$r['id']?>">
      <input type="hidden" name="open_close" value="<?=$r['open_close']?0:1?>">
      <button class="btn btn-ghost btn-sm"><?=$r['open_close']?'Close':'Open'?></button>
    </form>
  </td>
</tr>
<?php endwhile;else:?>
<tr><td colspan="7" class="text-center text-muted" style="padding:2rem">No trucks yet.</td></tr>
<?php endif;?>
</tbody></table></div>
<?php else:?>
<div class="card-body text-center text-muted" style="padding:3rem">
  <div style="font-size:2.5rem;margin-bottom:.75rem;opacity:.3">🚛</div>
  <div class="fw-500">Select a dispatch point to manage trucks</div>
</div>
<?php endif;?>
</div></div><!-- /grid -->

<!-- ── Bale detail for selected truck ────────────────────────────────── -->
<?php if($sel_dest && !empty($dest_bales_arr)):
  $di = $dest_sel_info;
  $det_mass = array_sum(array_column($dest_bales_arr,'mass'));
  $det_val  = array_reduce($dest_bales_arr,function($c,$b){$p=(float)$b['price'];if($p>10)$p/=100;return $c+(float)$b['mass']*$p;},0);
?>
<div class="card mb-3">
<div class="card-head" style="background:var(--surface-2);color:var(--text)">
  <div>
    <div class="fw-500"><?=htmlspecialchars($di['horse_num'])?> — <?=htmlspecialchars(trim($di['driver_name'].' '.$di['driver_surname']))?></div>
    <div style="font-size:.72rem;color:rgba(245,240,232,.45);margin-top:2px">
      <?=htmlspecialchars($di['buyer_name'])?> → <?=htmlspecialchars($di['destination'])?>
      &nbsp;·&nbsp; <?=count($dest_bales_arr)?> bales &nbsp;·&nbsp; <?=number_format($det_mass,0)?> kg
      &nbsp;·&nbsp; $<?=number_format($det_val,2)?>
    </div>
  </div>
  <div class="flex gap-2">
    <a href="?export=tpz&dest_id=<?=$sel_dest?>&date=<?=$today?>&spd=<?=$sel_spd?>"
       style="background:rgba(200,149,42,.15);border:1px solid rgba(200,149,42,.35);color:var(--gold-l);
              padding:.35rem .8rem;border-radius:var(--radius);font-size:.75rem;text-decoration:none;white-space:nowrap"
       title="Virtual Warehouse TPZ import file">⬇ .v (TPZ)</a>
    <a href="?export=csv&dest_id=<?=$sel_dest?>&date=<?=$today?>&spd=<?=$sel_spd?>"
       style="background:rgba(200,149,42,.15);border:1px solid rgba(200,149,42,.35);color:var(--gold-l);
              padding:.35rem .8rem;border-radius:var(--radius);font-size:.75rem;text-decoration:none;white-space:nowrap">⬇ CSV</a>
    <a href="?export=pdf&dest_id=<?=$sel_dest?>&date=<?=$today?>&spd=<?=$sel_spd?>"
       style="background:rgba(200,149,42,.15);border:1px solid rgba(200,149,42,.35);color:var(--gold-l);
              padding:.35rem .8rem;border-radius:var(--radius);font-size:.75rem;text-decoration:none;white-space:nowrap"
       target="_blank">🖨 Dispatch Note</a>
  </div>
</div>

<div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
<thead><tr>
  <th>#</th><th>Barcode</th><th>Grower #</th><th>Grower Name</th>
  <th>TIMB Grade</th><th>Buyer Grade</th>
  <th class="text-right">Mass (kg)</th>
  <th class="text-right">Price $/kg</th>
  <th class="text-right">Value USD</th>
  <th>Time</th><th></th>
</tr></thead><tbody>
<?php $seq=0; foreach($dest_bales_arr as $b): $seq++;
  $p=(float)$b['price']; if($p>10)$p/=100; $v=(float)$b['mass']*$p; ?>
<tr>
  <td class="td-muted" style="font-size:.7rem"><?=$seq?></td>
  <td><code style="font-size:.72rem"><?=htmlspecialchars($b['barcode'])?></code></td>
  <td><span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($b['grower_num'])?></span></td>
  <td style="font-size:.78rem"><?=htmlspecialchars(trim($b['gname'].' '.$b['surname']))?></td>
  <td><span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($b['timb_grade']??'—')?></span></td>
  <td class="td-muted" style="font-size:.72rem"><?=htmlspecialchars($b['buyer_grade']??'—')?></td>
  <td class="td-mono text-right"><?=number_format((float)$b['mass'],0)?></td>
  <td class="td-mono text-right"><?=$p>0?'$'.number_format($p,4):'—'?></td>
  <td class="td-mono text-right fw-500"><?=$v>0?'$'.number_format($v,2):'—'?></td>
  <td class="td-muted" style="font-size:.72rem"><?=date('H:i',strtotime($b['dispatch_at']))?></td>
  <td>
    <form method="POST" style="display:inline" onsubmit="return confirm('Remove bale from dispatch?')">
      <input type="hidden" name="action" value="remove_bale">
      <input type="hidden" name="dispatch_id" value="<?=$b['dispatch_id']?>">
      <button class="btn btn-danger btn-sm">✕</button>
    </form>
  </td>
</tr>
<?php endforeach;?>
</tbody>
<tfoot><tr style="background:var(--cream-d)">
  <td colspan="6" style="padding:.5rem .9rem;font-weight:500">TOTAL — <?=count($dest_bales_arr)?> bales</td>
  <td class="td-mono text-right fw-500" style="padding:.5rem .9rem"><?=number_format($det_mass,0)?> kg</td>
  <td></td>
  <td class="td-mono text-right fw-500" style="padding:.5rem .9rem">$<?=number_format($det_val,2)?></td>
  <td colspan="2"></td>
</tr></tfoot></table></div>

<!-- TPZ live preview ─────────────────────────────────────────────────────── -->
<div style="padding:.75rem 1.15rem;border-top:1px solid var(--border)">
  <div style="font-size:.78rem;font-weight:500;color:var(--muted);margin-bottom:.4rem">
    TPZ Preview &nbsp;—&nbsp; Virtual Warehouse import file format (.v)
  </div>
  <div style="background:#1a1208;border-radius:var(--radius);padding:.7rem 1rem;
              font-family:monospace;font-size:.72rem;color:#e8c96a;
              max-height:130px;overflow-y:auto;line-height:1.8;letter-spacing:.04em">
  <?php foreach(array_slice($dest_bales_arr,0,5) as $b):
    $grade = trim($b['timb_grade'] ?? $b['buyer_grade'] ?? ''); ?>
  <div><?=htmlspecialchars(tpz_line($today,$b['barcode'],$b['grower_num'],$b['lot'],$b['mass'],$b['price'],$grade))?></div>
  <?php endforeach; if(count($dest_bales_arr)>5):?>
  <div style="color:rgba(232,184,75,.35)">… +<?=count($dest_bales_arr)-5?> more lines</div>
  <?php endif;?>
  </div>
  <div style="font-size:.68rem;color:var(--muted);margin-top:4px">
    SaleDate(8) · Barcode(10) · GrowerNo(7) · LotNo(4) · Mass(3) · Price¢/kg(5) · Grade(15) = 52 chars
  </div>
</div>
</div>
<?php endif;?>

</div><!-- /panel-board -->

<!-- ── Today's log ────────────────────────────────────────────────────── -->
<div id="panel-log" class="tab-panel" data-tabs="dispatch">
<div class="card">
<div class="card-head">
  <h3>Today's Dispatch Log</h3>
  <span class="badge badge-gold"><?=number_format((int)$today_count['c'])?> bales &nbsp;·&nbsp; <?=number_format((float)$today_count['kg'],0)?> kg</span>
</div>
<div class="table-wrap" style="max-height:380px;overflow-y:auto"><table>
<thead><tr>
  <th>Time</th><th>Barcode</th><th>Grower</th>
  <th>Buyer</th><th>Truck</th><th>Destination</th>
  <th class="text-right">Mass</th>
</tr></thead><tbody>
<?php if($log_today&&$log_today->num_rows>0): while($r=$log_today->fetch_assoc()):?>
<tr>
  <td class="td-muted" style="font-size:.72rem"><?=date('H:i',strtotime($r['created_at']))?></td>
  <td><code style="font-size:.72rem"><?=htmlspecialchars($r['barcode'])?></code></td>
  <td>
    <span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($r['grower_num'])?></span>
    <span class="td-muted" style="font-size:.72rem;margin-left:4px"><?=htmlspecialchars($r['grower_name'])?></span>
  </td>
  <td style="font-size:.78rem"><?=htmlspecialchars($r['buyer_name'])?></td>
  <td><span class="badge badge-gray" style="font-size:.72rem"><?=htmlspecialchars($r['horse_num'])?></span></td>
  <td class="td-muted" style="font-size:.75rem"><?=htmlspecialchars($r['destination'])?></td>
  <td class="td-mono text-right"><?=number_format((float)$r['mass'],0)?></td>
</tr>
<?php endwhile;else:?>
<tr><td colspan="7" class="text-center text-muted" style="padding:2rem">No bales dispatched today.</td></tr>
<?php endif;?>
</tbody></table></div></div>
</div><!-- /panel-log -->

<script>
function tog(id){var e=document.getElementById(id);if(!e)return;e.style.display=e.style.display==='none'?'block':'none';}
document.addEventListener('DOMContentLoaded',function(){
  // Auto-focus barcode input if scan session is active or form opens
  var bc=document.getElementById('bc');
  if(bc) bc.focus();
  <?php if(!$show_scan_inline && count($open_dests_arr)===1): ?>
  var sel=document.getElementById('truck-sel');
  if(sel) sel.value='<?= $open_dests_arr[0]['id'] ?>';
  <?php endif; ?>
});
</script>
<?php include "includes/layout_end.php"; ?>
