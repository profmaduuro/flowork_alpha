<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

// ── AES DECRYPTION HELPERS (mirrors file_manager_uploads.php) ─────────────
// Reuse the same booking_keys table written by settings.php & file_manager_uploads
function gb_get_timb_keys($conn, $selling_pointid) {
    $spid = (int)$selling_pointid;
    $r = $conn->query("SELECT system_key, vector FROM booking_keys WHERE selling_pointid=$spid LIMIT 1");
    if (!$r || $r->num_rows === 0) return null;
    return $r->fetch_assoc();
}

function gb_sum_printable($s) {
    $n = min(200, strlen($s)); if (!$n) return 0;
    $cnt = 0;
    for ($i = 0; $i < $n; $i++) { $o = ord($s[$i]); if ($o >= 32 && $o <= 126) $cnt++; }
    return $cnt / $n;
}

function gb_try_aes($data, $key, $iv = '') {
    if (!$key || strlen($key) < 8) return false;
    $data = trim($data);
    if ($iv) {
        $pt = @openssl_decrypt($data, 'AES256', $key, 0, $iv);
        if ($pt !== false && strlen($pt) > 0 && gb_sum_printable($pt) > 0.55) return $pt;
        $pt = @openssl_decrypt($data, 'aes-256-cbc', $key, 0, $iv);
        if ($pt !== false && strlen($pt) > 0 && gb_sum_printable($pt) > 0.55) return $pt;
    }
    $raw = base64_decode($data, true);
    if ($raw === false || strlen($raw) < 16) return false;
    $iv_pad = $iv ? str_pad(substr($iv, 0, 16), 16, "\0") : str_repeat("\0", 16);
    if ($iv) {
        $pt = @openssl_decrypt($raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv_pad);
        if ($pt !== false && strlen($pt) > 0 && gb_sum_printable($pt) > 0.55) return $pt;
    }
    if (preg_match('/^[0-9A-Fa-f]+$/', $key) && strlen($key) % 2 === 0) {
        $kb = hex2bin($key); $bits = min(256, strlen($kb) * 8);
        if ($bits >= 128) {
            $kb = str_pad($kb, $bits / 8, "\0");
            $pt = @openssl_decrypt($raw, "AES-{$bits}-CBC", $kb, OPENSSL_RAW_DATA, $iv_pad);
            if ($pt !== false && strlen($pt) > 0 && gb_sum_printable($pt) > 0.55) return $pt;
        }
    }
    return false;
}

function gb_decrypt($raw, $aes_key = '', $aes_iv = '') {
    if ($aes_key && $aes_iv) { $r = gb_try_aes($raw, $aes_key, $aes_iv); if ($r !== false) return $r; }
    if ($aes_key)             { $r = gb_try_aes($raw, $aes_key, '');      if ($r !== false) return $r; }
    // Already plain text?
    if (!preg_match('/[^ -~\r\n\t]/', substr($raw, 0, 400))) return $raw;
    $decoded = base64_decode(trim($raw), true);
    if ($decoded !== false && strlen($decoded) > 10) {
        $t = trim($decoded);
        if (!preg_match('/[^ -~\r\n\t]/', substr($t, 0, 200))) return $t;
    }
    return false;
}

// Parse a TIMB booking CSV line into [grower_num, bales, sell_date]
// Parse a TIMB booking CSV line into [grower_num, bales, sell_date] with dynamic column mapping
function gb_parse_booking_line($cols, $hdr) {
    $today = date('Y-m-d');
    if ($hdr) {
        $row = array_combine(array_slice($hdr, 0, count($cols)), array_slice($cols, 0, count($hdr)));
        
        // Match grower number column
        $gn = '';
        foreach (['growernumber', 'growernum', 'grower', 'growerno', 'growerid'] as $k) {
            if (isset($row[$k])) { $gn = strtoupper(trim($row[$k])); break; }
        }
        
        // Match bales column
        $bales = 0;
        foreach (['balesbooked', 'bales', 'noofbales', 'qty', 'quantity', 'bookedbales'] as $k) {
            if (isset($row[$k])) { $bales = (int)trim($row[$k]); break; }
        }
        
        // Match sell/book date column
        $sd = '';
        foreach (['selldate', 'sell_date', 'date', 'saledate', 'bookdate', 'bookingdate'] as $k) {
            if (isset($row[$k])) { $sd = trim($row[$k]); break; }
        }
        
        // Match booking ID column
        $bid = '';
        foreach (['bookedid', 'bookingid', 'bookid', 'bookingnumber', 'bookingno', 'bookingref', 'bookref', 'id', 'ref'] as $k) {
            if (isset($row[$k])) { $bid = trim($row[$k]); break; }
        }
        
        if ($sd && !preg_match('/^\d{4}-/', $sd)) {
            $ts = strtotime($sd);
            $sd = $ts ? date('Y-m-d', $ts) : '';
        }
        
        $bid = $bid ?: 'AUTO-' . $gn . '-' . date('ymd', strtotime($sd ?: $today));
        return ['grower_num' => $gn, 'bales' => $bales, 'sell_date' => $sd, 'booking_id' => $bid];
    }
    
    // Positional content-based auto-detection (fallback when no header row matches)
    $gn = ''; $bales = 0; $sd = ''; $bid = '';
    $used_indices = [];
    
    // First pass: identify date, grower number
    foreach ($cols as $idx => $col) {
        $col = trim($col);
        if (!$col) continue;
        
        // Is it a date?
        if (preg_match('/^\d{4}[\-\/]\d{2}[\-\/]\d{2}$/', $col) || 
            preg_match('/^\d{1,2}[\-\/][A-Za-z]{3}[\-\/]\d{2,4}$/', $col) || 
            preg_match('/^\d{1,2}[\-\/]\d{1,2}[\-\/]\d{2,4}$/', $col)) {
            $ts = strtotime($col);
            if ($ts) { $sd = date('Y-m-d', $ts); $used_indices[] = $idx; }
        }
        // Is it a grower number? (starts with letters or looks like typical grower ID)
        elseif (preg_match('/^[A-Z]{1,3}\-?[A-Z0-9]+$/i', $col) && !preg_match('/^\d+$/', $col)) {
            $gn = strtoupper($col);
            $used_indices[] = $idx;
        }
    }
    
    // Second pass: identify bales and booking ID from remaining columns
    foreach ($cols as $idx => $col) {
        if (in_array($idx, $used_indices)) continue;
        $col = trim($col);
        if (!$col) continue;
        
        if (preg_match('/^\d+$/', $col)) {
            $val = (int)$col;
            if ($val > 1000 && !$gn) {
                $gn = $col;
                $used_indices[] = $idx;
            } elseif (!$bales) {
                $bales = $val;
                $used_indices[] = $idx;
            } else {
                $bid = $col;
                $used_indices[] = $idx;
            }
        } else {
            $bid = $col;
            $used_indices[] = $idx;
        }
    }
    
    // Ultimate fallback: positional mapping [0 => grower_num, 1 => bales, 2 => date]
    if (!$gn)   $gn = strtoupper(trim($cols[0] ?? ''));
    if (!$bales)$bales = (int)trim($cols[1] ?? 0);
    if (!$sd)   $sd = trim($cols[2] ?? '');
    if ($sd && !preg_match('/^\d{4}-/', $sd)) { $ts = strtotime($sd); $sd = $ts ? date('Y-m-d', $ts) : ''; }
    
    $bid = $bid ?: 'AUTO-' . $gn . '-' . date('ymd', strtotime($sd ?: $today));
    return ['grower_num' => $gn, 'bales' => $bales, 'sell_date' => $sd, 'booking_id' => $bid];
}

$page_title  = "Grower Booking";
$active_page = "grower_booking";
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$userid   = current_userid();
$seasonid = (int)($_SESSION['seasonid'] ?? 0);
$is_admin = (int)($_SESSION['is_admin'] ?? 0);
$today    = date('Y-m-d');
$now      = date('Y-m-d H:i:s');
$msg = $_SESSION['gb_flash_msg'] ?? '';
unset($_SESSION['gb_flash_msg']);
$err = '';

if (!$spid) {
    $_sp = $conn->query("SELECT id FROM selling_point WHERE active=1 ORDER BY id LIMIT 1");
    if ($_sp && $_sp !== true && $_sp->num_rows) {
        $spid = (int)$_sp->fetch_assoc()['id'];
        $_SESSION['selling_pointid'] = $spid;
    }
}
if (!$seasonid) {
    $_sn = $conn->query("SELECT id FROM seasons ORDER BY id DESC LIMIT 1");
    if ($_sn && $_sn !== true && $_sn->num_rows) {
        $seasonid = (int)$_sn->fetch_assoc()['id'];
        $_SESSION['seasonid'] = $seasonid;
    }
}

function upsert_booked_bales($conn, $userid, $growerid, $bales, $sell_date, $book_date, $booking_id = '') {
    $uid = (int)$userid; $gid = (int)$growerid; $bl = (int)$bales;
    $sd  = $conn->real_escape_string($sell_date);
    $bd  = $conn->real_escape_string($book_date);
    $bid = trim($booking_id);

    if (!$bid) {
        $g_num = '';
        $g_q = $conn->query("SELECT grower_num FROM growers WHERE id = $gid LIMIT 1");
        if ($g_q && $g_q->num_rows > 0) {
            $g_num = $g_q->fetch_assoc()['grower_num'];
        }
        $bid = 'MAN-' . ($g_num ?: $gid) . '-' . date('ymd', strtotime($sd ?: $bd));
    }

    $bid = $conn->real_escape_string($bid);
    $ex  = $conn->query("SELECT id FROM booked_bales WHERE growerid=$gid AND sell_date='$sd' LIMIT 1");
    if ($ex && $ex !== true && $ex->num_rows > 0) {
        $eid = (int)$ex->fetch_assoc()["id"];
        $conn->query("UPDATE booked_bales SET bales_booked='$bl', userid=$uid, booked_id='$bid', datetime=NOW() WHERE id=$eid");
        sync_booked_delivery_bales($conn, $gid, $sell_date);
        return $eid;
    }
    $conn->query("INSERT INTO booked_bales
        (userid,growerid,book_date,comm_graded,bales_booked,bales_delivered,
         bales_handled,sell_date,book_user,ip_address,floor,sale,
         booked_id,reoffer,booked_by,prefered_rep,last_sell,prefered_time,venue,datetime)
        VALUES ($uid,$gid,'$bd','0','$bl','0','0','$sd','$uid','','','','$bid','0','','','','','',NOW())");
    $eid = (int)$conn->insert_id;
    sync_booked_delivery_bales($conn, $gid, $sell_date);
    return $eid;
}

// AJAX
if (isset($_GET["ajax"])) {
    header("Content-Type: application/json");
    $action = $_GET["action"] ?? "";
    if ($action === "book") {
        $tgid = (int)$_GET["tgid"];
        if (!flowork_on_floor($conn, 'tg', $tgid, $spid)) { echo json_encode(["error"=>"Delivery not found on this floor"]); exit; }
        $conn->query("UPDATE transporter_growers SET booked=1, datetime=NOW() WHERE id=$tgid");
        $tg = $conn->query("SELECT growerid,bales,sell_date FROM transporter_growers WHERE id=$tgid LIMIT 1");
        if ($tg && $tg !== true && $tg->num_rows) {
            $r = $tg->fetch_assoc();
            upsert_booked_bales($conn,$userid,$r["growerid"],$r["bales"],$r["sell_date"]?:$today,$today);
        }
        echo json_encode(["ok"=>true]); exit;
    }
    if ($action === "unbook") {
        $bb_id = (int)$_GET["bb_id"];
        if (!flowork_on_floor($conn, 'booked_bale', $bb_id, $spid)) { echo json_encode(["error"=>"Booking not found on this floor"]); exit; }
        $bb_q = $conn->query("SELECT growerid, sell_date FROM booked_bales WHERE id=$bb_id LIMIT 1");
        if ($bb_q && $bb_q->num_rows > 0) {
            $bb_r = $bb_q->fetch_assoc();
            $gid = (int)$bb_r["growerid"];
            $sd = $conn->real_escape_string($bb_r["sell_date"]);
            
            // Check balanced
            $bal = $conn->query("SELECT id FROM balanced_bales WHERE growerid=$gid AND sell_date='$sd' AND (selling_pointid=$spid OR selling_pointid=0) LIMIT 1");
            if ($bal && $bal->num_rows > 0) {
                echo json_encode(["error"=>"Already balanced — cannot unbook"]); exit;
            }
            
            // Delete booking
            $conn->query("DELETE FROM booked_bales WHERE id=$bb_id");
            
            // Sync booking and delivery details
            sync_booked_delivery_bales($conn, $gid, $sd);
        }
        echo json_encode(["ok"=>true]); exit;
    }
    if ($action === "get_day_details") {
        $sell_date = $conn->real_escape_string($_GET["sell_date"] ?? $today);
        $details_q = $conn->query("
            SELECT bb.bales_booked, bb.bales_delivered, bb.booked_id, bb.id as bb_id,
                   g.grower_num, CONCAT(g.name, ' ', COALESCE(g.surname, '')) grower_name, g.id as growerid
            FROM booked_bales bb
            JOIN growers g ON g.id = bb.growerid
            WHERE g.selling_pointid = $spid AND bb.sell_date = '$sell_date'
            ORDER BY bb.id DESC
        ");
        $rows = [];
        if ($details_q && $details_q !== true) {
            while ($r = $details_q->fetch_assoc()) {
                $rows[] = [
                    'bb_id' => (int)$r['bb_id'],
                    'grower_num' => $r['grower_num'],
                    'grower_name' => $r['grower_name'],
                    'bales_booked' => (int)$r['bales_booked'],
                    'bales_delivered' => (int)$r['bales_delivered'],
                    'booked_id' => $r['booked_id'],
                    'growerid' => (int)$r['growerid']
                ];
            }
        }
        echo json_encode($rows);
        exit;
    }
    if ($action === "import_timb") {
        $imported = 0;
        $bb_q = $conn->query("SELECT DISTINCT growerid,bales_booked,sell_date FROM booked_bales");
        while ($bb_q && $r = $bb_q->fetch_assoc()) {
            $gid = (int)$r["growerid"];
            $tg_q = $conn->query("SELECT id FROM transporter_growers WHERE growerid=$gid AND selling_pointid=$spid LIMIT 1");
            if ($tg_q && $tg_q->num_rows > 0) {
                $tg = $tg_q->fetch_assoc();
                $conn->query("UPDATE transporter_growers SET booked=1, datetime=NOW() WHERE id={$tg["id"]}");
                $imported++;
            }
        }
        echo json_encode(["ok"=>true,"imported"=>$imported]); exit;
    }
    exit;
}

// POST
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "edit_booking") {
        header("Content-Type: application/json");
        if ($is_admin !== 1) {
            echo json_encode(["error" => "Access denied. Only systems administrators can edit bookings."]);
            exit;
        }
        $bb_id = (int)($_POST["bb_id"] ?? 0);
        $bales_booked = (int)($_POST["bales_booked"] ?? 0);
        $bales_delivered = (int)($_POST["bales_delivered"] ?? 0);
        $sell_dt = $conn->real_escape_string($_POST["sell_date"] ?? $today);
        $booked_id = $conn->real_escape_string(trim($_POST["booked_id"] ?? ''));
        
        if (!$bb_id) {
            echo json_encode(["error" => "Invalid booking ID."]);
            exit;
        }
        if (!flowork_on_floor($conn, 'booked_bale', $bb_id, $spid)) {
            echo json_encode(["error" => "Booking not found on this floor."]);
            exit;
        }
        
        $bb_q = $conn->query("SELECT growerid FROM booked_bales WHERE id=$bb_id LIMIT 1");
        if ($bb_q && $bb_q->num_rows > 0) {
            $gid = (int)$bb_q->fetch_assoc()["growerid"];
            $bal = $conn->query("SELECT id FROM balanced_bales WHERE growerid=$gid AND sell_date='$sell_dt' AND (selling_pointid=$spid OR selling_pointid=0) LIMIT 1");
            if ($bal && $bal->num_rows > 0) {
                echo json_encode(["error" => "Cannot edit a booking that has already been balanced."]);
                exit;
            }
            
            $conn->query("UPDATE booked_bales SET bales_booked='$bales_booked', sell_date='$sell_dt', booked_id='$booked_id', datetime=NOW() WHERE id=$bb_id");
            // Only deliveries not yet balanced or sold: a sold delivery stays on the day it was sold
            $conn->query("UPDATE transporter_growers tg SET tg.sell_date='$sell_dt', tg.datetime=NOW()
                WHERE tg.growerid=$gid AND tg.selling_pointid=$spid AND " . flowork_tg_movable_sql());
            
            sync_booked_delivery_bales($conn, $gid, $sell_dt);
            
            audit_log($conn, $spid, $userid, "booked_bales", "edit", $bb_id, null, ["bales_booked" => $bales_booked, "sell_date" => $sell_dt]);
            echo json_encode(["ok" => true, "msg" => "Booking updated successfully."]);
        } else {
            echo json_encode(["error" => "Booking not found."]);
        }
        exit;
    }

    if ($action === "book_manual") {
        header("Content-Type: application/json");
        $gid     = (int)($_POST["growerid"] ?? 0);
        $trid    = (int)($_POST["transporterid"] ?? 0);
        $bales   = (int)($_POST["bales"] ?? 0);
        $sell_dt = $conn->real_escape_string($_POST["sell_date"] ?? $today);
        if (!$gid || !$trid) {
            echo json_encode(["error"=>"Grower and transporter required."]);
            exit;
        }
        if (!flowork_on_floor($conn, 'grower', $gid, $spid) || !flowork_on_floor($conn, 'transporter', $trid, $spid)) {
            echo json_encode(["error"=>"Grower or transporter not found on this floor."]);
            exit;
        }
        // Reuse an open delivery only; a balanced or sold one keeps its day and a new delivery is made
        $tg_q = $conn->query("SELECT tg.id,tg.booked FROM transporter_growers tg WHERE tg.growerid=$gid AND tg.transporterid=$trid AND tg.selling_pointid=$spid AND " . flowork_tg_movable_sql() . " ORDER BY tg.id DESC LIMIT 1");
        if ($tg_q && $tg_q->num_rows > 0) {
            $tg = $tg_q->fetch_assoc();
            $conn->query("UPDATE transporter_growers SET booked=1,bales=$bales,sell_date='$sell_dt', datetime=NOW() WHERE id={$tg["id"]}");
            $tgid = (int)$tg["id"];
            $rmsg = "Grower booked (updated).";
        } else {
            $conn->query("INSERT INTO transporter_growers (userid,transporterid,growerid,selling_pointid,bales,booked,sell_date,open_close,created_at,datetime) VALUES ($userid,$trid,$gid,$spid,$bales,1,'$sell_dt',0,'$now','$now')");
            if ($conn->error) { echo json_encode(["error"=>$conn->error]); exit; }
            $tgid = (int)$conn->insert_id;
            $rmsg = "Grower booked successfully.";
        }
        $bb_id = upsert_booked_bales($conn,$userid,$gid,$bales,$sell_dt,$today);
        audit_log($conn,$spid,$userid,"transporter_growers","book",$tgid,null,["growerid"=>$gid,"bales"=>$bales]);
        echo json_encode(["ok"=>true,"msg"=>$rmsg,"bb_id"=>$bb_id]);
        exit;
    }

    if ($action === "book_all") {
        $all_q = $conn->query("SELECT tg.id tgid,tg.growerid,tg.bales,COALESCE(tg.sell_date,'$today') sd
            FROM transporter_growers tg JOIN growers g ON g.id=tg.growerid
            LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
            WHERE (g.selling_pointid=$spid OR tg.selling_pointid=$spid) AND bb.id IS NULL");
        $n = 0;
        while ($all_q && $r = $all_q->fetch_assoc()) {
            $conn->query("UPDATE transporter_growers SET booked=1, datetime=NOW() WHERE id={$r["tgid"]}");
            upsert_booked_bales($conn,$userid,$r["growerid"],$r["bales"],$r["sd"],$today);
            $n++;
        }
        $msg = "$n growers booked.";
    }

    if ($action === "book_for_date") {
        $sell_dt = $conn->real_escape_string($_POST["sell_date"] ?? $today);
        $all_q   = $conn->query("SELECT tg.id tgid,tg.growerid,tg.bales
            FROM transporter_growers tg JOIN growers g ON g.id=tg.growerid
            WHERE (g.selling_pointid=$spid OR tg.selling_pointid=$spid) AND " . flowork_tg_movable_sql());
        $n = 0;
        while ($all_q && $r = $all_q->fetch_assoc()) {
            $conn->query("UPDATE transporter_growers SET booked=1,sell_date='$sell_dt', datetime=NOW() WHERE id={$r["tgid"]}");
            upsert_booked_bales($conn,$userid,$r["growerid"],$r["bales"],$sell_dt,$today);
            $n++;
        }
        $msg = "$n growers booked for $sell_dt.";
    }

    // ── TIMB Booking File Decrypt & Direct Auto-Book ──────────────────
    if ($action === 'upload_timb_booking') {
        header('Content-Type: application/json');
        if (empty($_FILES['timb_booking_file']['tmp_name']) || $_FILES['timb_booking_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => 'No file uploaded or upload error.']); exit;
        }
        $raw      = file_get_contents($_FILES['timb_booking_file']['tmp_name']);
        $kp       = gb_get_timb_keys($conn, $spid);
        $aes_key  = $kp['system_key'] ?? '';
        $aes_iv   = $kp['vector']     ?? '';
        $decrypted = gb_decrypt($raw, $aes_key, $aes_iv);
        if ($decrypted === false) {
            echo json_encode(['error' => 'Could not decrypt the file. ' .
                ($aes_key ? 'The stored booking key did not work. ' : 'No booking key configured for this floor. ') .
                'Go to Settings → TIMB File Keys to enter your key.']); exit;
        }
        $lines = array_values(array_filter(
            explode("\n", str_replace(["\r\n","\r"], "\n", $decrypted)),
            fn($l) => trim($l) !== ''
        ));
        $hdr     = null;
        $records = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line, ',');
            if (count($cols) < 2) $cols = str_getcsv($line, ';');
            if (!$hdr) {
                // Detect if first line is a header by checking if any column contains keywords
                $is_header = false;
                foreach ($cols as $col) {
                    if (preg_match('/grower|number|name|date|bales|qty/i', trim($col))) {
                        $is_header = true;
                        break;
                    }
                }
                if ($is_header) {
                    $hdr = array_map(fn($h) => preg_replace('/[^a-z0-9]/', '', strtolower($h)), $cols);
                    continue;
                }
            }
            $parsed = gb_parse_booking_line($cols, $hdr);
            if (!$parsed['grower_num']) continue;
            // Lookup grower in DB using normalized matching
            $grow = find_grower_normalized($conn, $parsed['grower_num'], $spid);
            $records[] = [
                'grower_num'   => $parsed['grower_num'],
                'grower_name'  => $grow ? trim($grow['name'].' '.($grow['surname']??'')) : '(not found)',
                'growerid'     => $grow ? (int)$grow['id'] : 0,
                'bales'        => $parsed['bales'],
                'sell_date'    => $parsed['sell_date'] ?: $today,
                'booking_id'   => $parsed['booking_id'],
                'found'        => $grow ? true : false,
            ];
        }

        $imported = 0; $skipped = 0;
        // Get default transporter for this floor (first active one) for auto-linking
        $def_tr = $conn->query("SELECT id FROM transporter WHERE seasonid=$seasonid AND active=1 AND ($spid=0 OR selling_pointid=$spid) ORDER BY id LIMIT 1");
        $def_trid = ($def_tr && $def_tr !== true && $def_tr->num_rows) ? (int)$def_tr->fetch_assoc()['id'] : 0;
        foreach ($records as $rec) {
            if (!$rec['found'] || !$rec['growerid']) { $skipped++; continue; }
            $gid   = (int)$rec['growerid'];
            $bales = (int)$rec['bales'];
            $sd    = $conn->real_escape_string($rec['sell_date'] ?: $today);
            $bid   = $conn->real_escape_string($rec['booking_id'] ?? '');
            upsert_booked_bales($conn, $userid, $gid, $bales, $sd, $today, $bid);
            // Upsert transporter_growers: first look for any existing entry for this grower at this selling point
            $tq = $conn->query("SELECT tg.id FROM transporter_growers tg WHERE tg.growerid=$gid AND tg.selling_pointid=$spid AND " . flowork_tg_movable_sql() . " ORDER BY tg.id DESC LIMIT 1");
            if ($tq && $tq !== true && $tq->num_rows) {
                $tid = (int)$tq->fetch_assoc()['id'];
                $conn->query("UPDATE transporter_growers SET booked=1, bales=$bales, sell_date='$sd' WHERE id=$tid");
            } else if ($def_trid) {
                $now2 = date('Y-m-d H:i:s');
                $conn->query("INSERT INTO transporter_growers (userid,transporterid,growerid,selling_pointid,bales,booked,sell_date,open_close,created_at,datetime) VALUES ($userid,$def_trid,$gid,$spid,$bales,1,'$sd',0,'$now2','$now2')");
            }
            $imported++;
        }
        
        audit_log($conn, $spid, $userid, 'booked_bales', 'timb_file_import', null, null, ['imported' => $imported, 'skipped' => $skipped]);
        $_SESSION['gb_flash_msg'] = "Successfully auto-booked $imported growers from TIMB file ($skipped skipped).";
        
        echo json_encode([
            'ok'      => true,
            'imported' => $imported,
            'skipped'  => $skipped,
            'msg'     => $_SESSION['gb_flash_msg']
        ]);
        exit;
    }
}

// LOAD DATA
$f_status  = $_GET["status"] ?? "all";
$f_date    = $_GET["sell_date"] ?? "";
$status_cond = "";
if ($f_date) {
    $f_date_esc = $conn->real_escape_string($f_date);
    $status_cond .= " AND bb.sell_date='$f_date_esc'";
}
if ($f_status === "booked") {
    $status_cond .= " AND bal.id IS NULL";
} elseif ($f_status === "balanced") {
    $status_cond .= " AND bal.id IS NOT NULL";
}

// ── Auto-parse & Direct Auto-Book when arriving from File Manager ───────────
// file_manager_uploads.php stores the decoded raw text in timb_raw_from_fm
// when the user clicks "→ Grower Booking". Parse and book immediately.
if (!empty($_SESSION['timb_raw_from_fm'])) {
    $raw_fm   = $_SESSION['timb_raw_from_fm'];
    unset($_SESSION['timb_raw_from_fm']); // consume once
    $lines_fm = array_values(array_filter(
        explode("\n", str_replace(["\r\n","\r"], "\n", $raw_fm)),
        fn($l) => trim($l) !== ''
    ));
    $hdr_fm   = null;
    $recs_fm  = [];
    foreach ($lines_fm as $line_fm) {
        $cols_fm = str_getcsv($line_fm, ',');
        if (count($cols_fm) < 2) $cols_fm = str_getcsv($line_fm, ';');
        if (!$hdr_fm) {
            // Detect if first line is a header by checking if any column contains keywords
            $is_header_fm = false;
            foreach ($cols_fm as $col_fm) {
                if (preg_match('/grower|number|name|date|bales|qty/i', trim($col_fm))) {
                    $is_header_fm = true;
                    break;
                }
            }
            if ($is_header_fm) {
                $hdr_fm = array_map(fn($h) => preg_replace('/[^a-z0-9]/', '', strtolower($h)), $cols_fm);
                continue;
            }
        }
        $parsed_fm = gb_parse_booking_line($cols_fm, $hdr_fm);
        if (!$parsed_fm['grower_num']) continue;
        // Lookup grower in DB using normalized matching
        $grow_fm = find_grower_normalized($conn, $parsed_fm['grower_num'], $spid);
        $recs_fm[] = [
            'grower_num'  => $parsed_fm['grower_num'],
            'grower_name' => $grow_fm ? trim($grow_fm['name'].' '.($grow_fm['surname']??'')) : '(not found)',
            'growerid'    => $grow_fm ? (int)$grow_fm['id'] : 0,
            'bales'       => $parsed_fm['bales'],
            'sell_date'   => $parsed_fm['sell_date'] ?: $today,
            'booking_id'  => $parsed_fm['booking_id'],
            'found'       => (bool)$grow_fm,
        ];
    }
    
    if ($recs_fm) {
        $imported_fm = 0; $skipped_fm = 0;
        // Get default transporter for this floor (first active one) for auto-linking
        $def_tr = $conn->query("SELECT id FROM transporter WHERE seasonid=$seasonid AND active=1 AND ($spid=0 OR selling_pointid=$spid) ORDER BY id LIMIT 1");
        $def_trid = ($def_tr && $def_tr !== true && $def_tr->num_rows) ? (int)$def_tr->fetch_assoc()['id'] : 0;
        foreach ($recs_fm as $rec) {
            if (!$rec['found'] || !$rec['growerid']) { $skipped_fm++; continue; }
            $gid   = (int)$rec['growerid'];
            $bales = (int)$rec['bales'];
            $sd    = $conn->real_escape_string($rec['sell_date'] ?: $today);
            $bid   = $conn->real_escape_string($rec['booking_id'] ?? '');
            upsert_booked_bales($conn, $userid, $gid, $bales, $sd, $today, $bid);
            // Upsert transporter_growers
            $tq = $conn->query("SELECT tg.id FROM transporter_growers tg WHERE tg.growerid=$gid AND tg.selling_pointid=$spid AND " . flowork_tg_movable_sql() . " ORDER BY tg.id DESC LIMIT 1");
            if ($tq && $tq !== true && $tq->num_rows) {
                $tid = (int)$tq->fetch_assoc()['id'];
                $conn->query("UPDATE transporter_growers SET booked=1, bales=$bales, sell_date='$sd' WHERE id=$tid");
            } else if ($def_trid) {
                $now2 = date('Y-m-d H:i:s');
                $conn->query("INSERT INTO transporter_growers (userid,transporterid,growerid,selling_pointid,bales,booked,sell_date,open_close,created_at,datetime) VALUES ($userid,$def_trid,$gid,$spid,$bales,1,'$sd',0,'$now2','$now2')");
            }
            $imported_fm++;
        }
        audit_log($conn, $spid, $userid, 'booked_bales', 'timb_file_import', null, null, ['imported' => $imported_fm, 'skipped' => $skipped_fm]);
        $_SESSION['gb_flash_msg'] = "Successfully auto-booked $imported_fm growers from File Manager ($skipped_fm skipped).";
    }
    header('Location: grower_booking.php');
    exit;
}
$has_booking_keys = false;
$bk_chk = $conn->query("SHOW TABLES LIKE 'booking_keys'");
if ($bk_chk && $bk_chk->num_rows) {
    $bk_r = $conn->query("SELECT id FROM booking_keys WHERE selling_pointid=$spid LIMIT 1");
    $has_booking_keys = ($bk_r && $bk_r !== true && $bk_r->num_rows > 0);
}

// Auto-sync: Mark as booked in transporter_growers and sync bales_delivered in booked_bales
$conn->query("
    UPDATE transporter_growers tg
    JOIN booked_bales bb ON bb.growerid = tg.growerid AND bb.sell_date = COALESCE(tg.sell_date, '$today')
    SET tg.booked = 1
    WHERE tg.selling_pointid = $spid AND tg.booked = 0
");
$conn->query("
    UPDATE booked_bales bb
    LEFT JOIN (
        SELECT growerid, sell_date, SUM(bales) as tot_bales 
        FROM transporter_growers 
        WHERE selling_pointid = $spid
        GROUP BY growerid, sell_date
    ) tg ON tg.growerid = bb.growerid AND tg.sell_date = bb.sell_date
    SET bb.bales_delivered = LEAST(bb.bales_booked, COALESCE(tg.tot_bales, 0))
    WHERE bb.bales_delivered <> LEAST(bb.bales_booked, COALESCE(tg.tot_bales, 0))
      AND bb.growerid IN (SELECT id FROM growers WHERE selling_pointid = $spid)
");

$tg_list = [];
$tg_q = $conn->query("
    SELECT bb.id bb_id, bb.bales_booked, bb.bales_delivered, bb.sell_date sell_dt, bb.booked_id,
           g.id growerid, g.grower_num,
           CONCAT(g.name,' ',COALESCE(g.surname,'')) grower_name,
           g.name first_name, COALESCE(g.surname,'') surname,
           bal.id bal_id
    FROM booked_bales bb
    JOIN growers g ON g.id=bb.growerid
    LEFT JOIN balanced_bales bal ON bal.growerid=g.id AND bal.sell_date=bb.sell_date AND (bal.selling_pointid=$spid OR bal.selling_pointid=0)
    WHERE g.selling_pointid=$spid $status_cond
    ORDER BY bb.datetime DESC, bb.id DESC
");
if ($tg_q && $tg_q !== true) $tg_list = $tg_q->fetch_all(MYSQLI_ASSOC);

$total_count    = count($tg_list);
$booked_count   = count(array_filter($tg_list, fn($r)=>empty($r["bal_id"])));
$balanced_count = $total_count - $booked_count;
$total_booked_bales    = (int)array_sum(array_column($tg_list, "bales_booked"));
$total_delivered_bales = (int)array_sum(array_column($tg_list, "bales_delivered"));

$growers_q = $conn->query("SELECT id,grower_num,name,surname FROM growers WHERE ($spid=0 OR selling_pointid=$spid) ORDER BY grower_num LIMIT 500");
$growers   = ($growers_q && $growers_q!==true) ? $growers_q->fetch_all(MYSQLI_ASSOC) : [];
$trans_q   = $conn->query("SELECT id,name,truck_num FROM transporter WHERE seasonid=$seasonid AND active=1 AND ($spid=0 OR selling_pointid=$spid) ORDER BY name");
$transporters = ($trans_q && $trans_q!==true) ? $trans_q->fetch_all(MYSQLI_ASSOC) : [];

// Aggregate daily booked bales and details for calendar capacity grid
$daily_bales_q = $conn->query("
    SELECT bb.sell_date, SUM(bb.bales_booked) as total_bales 
    FROM booked_bales bb
    JOIN growers g ON g.id = bb.growerid
    WHERE g.selling_pointid = $spid
    GROUP BY bb.sell_date
");
$daily_bookings = [];
if ($daily_bales_q && $daily_bales_q !== true) {
    while ($r = $daily_bales_q->fetch_assoc()) {
        $daily_bookings[$r['sell_date']] = (int)$r['total_bales'];
    }
}

include "includes/layout.php";
?>
<style>
.admin-clickable:hover {
    background-color: rgba(200, 149, 42, 0.06) !important;
    transition: background-color 0.2s ease;
}
/* Smart Calendar CSS */
.cal-day {
  aspect-ratio: 1.2;
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 4px 6px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  cursor: pointer;
  background: var(--cream-d);
  transition: all .15s ease;
}
.cal-day:hover {
  border-color: var(--gold);
  transform: translateY(-1px);
  box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
.cal-day.active {
  border-color: var(--gold);
  outline: 2px solid var(--gold);
}
.cal-day.other-month {
  opacity: .35;
  cursor: not-allowed;
  pointer-events: none;
}
.cal-day-num {
  font-weight: 600;
  font-size: .85rem;
  color:var(--text);
}
.cal-day-bales {
  font-size: .65rem;
  font-weight: 700;
  align-self: flex-end;
  padding: 1px 4px;
  border-radius: 2px;
}
.cal-cap-green { background:var(--surface-2) !important; border-color: #c3e6cb !important; color:var(--ok-text) !important; }
.cal-cap-amber { background:var(--surface-2) !important; border-color: #e8c96a !important; color:var(--warn-text) !important; }
.cal-cap-red { background:var(--surface-2) !important; border-color: #f5b8b2 !important; color:var(--bad-text) !important; }

body.theme-dark .cal-cap-green { background: #122012 !important; border-color: #2e7d32 !important; color: #c0f0c0 !important; }
body.theme-dark .cal-cap-amber { background: #1e1800 !important; border-color: #b8860b !important; color: #f0dfa0 !important; }
body.theme-dark .cal-cap-red { background: #220e0e !important; border-color: #d32f2f !important; color: #f0c0c0 !important; }

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
    background:var(--primary-bg);
    color:var(--primary-text);
    box-shadow: 0 4px 12px rgba(26, 18, 8, 0.15);
    font-weight: 600;
}
body.theme-light .premium-tab-btn.active {
    background:var(--primary-bg);
    color:var(--primary-text);
}
body.theme-dark .premium-tab-btn.active {
    background: var(--gold);
    color: var(--ink);
    box-shadow: 0 4px 12px rgba(200, 149, 42, 0.2);
}

.booking-tab-panel {
    display: none;
}
.booking-tab-panel.active {
    display: block;
    animation: bookingFadeIn 0.25s ease-in-out;
}

#calendar-grid-wrapper.booking-tab-panel.active {
    display: grid !important;
}

@keyframes bookingFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.booking-form-container {
    max-width: 800px;
    margin: 0 auto;
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Grower Booking</h2>
    <p>Book growers onto the floor for the selling day</p>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
    <button onclick="openBookModal()" class="btn btn-primary btn-sm">+ Manual Booking</button>
    <form method="POST" style="display:inline-flex;gap:.35rem;align-items:center">
      <input type="hidden" name="action" value="book_for_date">
      <input type="date" name="sell_date" value="<?=$today?>" class="form-control" style="font-size:.78rem;padding:.3rem .5rem;width:135px">
      <button type="submit" class="btn btn-ghost btn-sm">✓ Book All for Date</button>
    </form>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="book_all">
      <button type="submit" class="btn btn-primary btn-sm">✓ Book All</button>
    </form>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- Tab Panel 3: Import File -->
<div id="panel-import" class="booking-tab-panel">
  <div class="booking-form-container">

<!-- ════════════════════════════════════════════════════
     TIMB BOOKING FILE IMPORT
════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:1.1rem">
  <div class="card-head">
    <h3 style="display:flex;align-items:center;gap:.5rem">
      <span>📂</span> Import TIMB Booking File
    </h3>
    <div style="display:flex;align-items:center;gap:.75rem">
      <?php if(!$has_booking_keys):?>
      <span style="font-size:.72rem;color:var(--gold)">⚠ No keys configured &mdash;
        <a href="settings.php" style="color:var(--gold);font-weight:600">Settings → TIMB File Keys</a>
      </span>
      <?php endif;?>
    </div>
  </div>

  <!-- Upload form (Always visible) -->
  <div id="timb-upload-area" style="display:block;padding:1rem 1.25rem">
    <div style="display:flex;align-items:flex-end;gap:.75rem;flex-wrap:wrap">
      <div class="form-group" style="flex:1;min-width:220px;margin:0">
        <label style="font-weight:500;margin-bottom:.4rem">TIMB Booking File <span style="color:var(--muted);font-size:.68rem">(.encry_en, .txt, .v, .csv)</span></label>
        <input type="file" id="timb-booking-file" class="form-control"
               accept=".encry_en,.txt,.v,.V,.enc,.csv,.dat"
               onchange="timb_booking_file_selected()">
      </div>
      <button class="btn btn-primary btn-sm" onclick="uploadTimbBooking()" id="timb-upload-btn"
              style="margin-bottom:2px" disabled>
        🔓 Decrypt &amp; Book Growers
      </button>
    </div>
    <div id="timb-upload-msg" style="margin-top:.5rem;font-size:.78rem"></div>
  </div>
</div>
</div>
</div>

<!-- KPIs -->
<div class="kpi-grid" id="booking-kpis" style="grid-template-columns:repeat(5,1fr);margin-bottom:1.1rem;gap:.5rem">
  <div class="kpi-card">
    <div class="kpi-label">Total Booked</div>
    <div class="kpi-value"><?=$total_count?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Pending Balanced</div>
    <div class="kpi-value" style="color:var(--gold)"><?=$booked_count?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Balanced</div>
    <div class="kpi-value" style="color:var(--leaf)"><?=$balanced_count?></div>
  </div>
  <div class="kpi-card" style="--kpi-color:var(--gold)">
    <div class="kpi-label">Booked Bales</div>
    <div class="kpi-value"><?=number_format($total_booked_bales)?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Delivered Bales</div>
    <div class="kpi-value" style="color:var(--leaf)"><?=number_format($total_delivered_bales)?></div>
  </div>
</div>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="registry" onclick="switchBookingTab('registry')">
        📋 Booking Registry
    </button>
    <button class="premium-tab-btn" data-tab="calendar" onclick="switchBookingTab('calendar')">
        📅 Capacity Calendar
    </button>
    <button class="premium-tab-btn" data-tab="import" onclick="switchBookingTab('import')">
        📥 Import File
    </button>
</div>

<!-- Tab Panel 2: Capacity Calendar -->
<div id="panel-calendar" class="booking-tab-panel">
  <!-- ── SMART CALENDAR VIEW CONTAINER ── -->
  <div class="grid-2" id="calendar-grid-wrapper" style="margin-bottom:1.5rem; align-items:start">
  <!-- Left Side: Calendar Card -->
  <div class="card">
    <div class="card-head" style="flex-wrap:wrap; gap:.5rem">
      <h3 style="margin:0; display:flex; align-items:center; gap:.5rem">
        <span>📅</span> Capacity Calendar
      </h3>
      <div style="display:flex; align-items:center; gap:.5rem">
        <button onclick="changeMonth(-1)" class="btn btn-ghost btn-sm" style="padding:2px 8px">&lt;</button>
        <span id="calendar-month-label" style="font-weight:600; font-size:.85rem; min-width:110px; text-align:center"></span>
        <button onclick="changeMonth(1)" class="btn btn-ghost btn-sm" style="padding:2px 8px">&gt;</button>
      </div>
    </div>
    <div class="card-body" style="padding:1rem">
      <div style="display:grid; grid-template-columns:repeat(7, 1fr); gap:.4rem; text-align:center; font-weight:600; font-size:.7rem; text-transform:uppercase; color:var(--muted); margin-bottom:.5rem">
        <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
      </div>
      <div id="calendar-days-grid" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:.4rem">
        <!-- Generated by JS -->
      </div>
      <!-- Capacity Legend -->
      <div style="display:flex; justify-content:center; gap:1.2rem; font-size:.7rem; color:var(--muted); margin-top:1.1rem; border-top:1px solid var(--border); padding-top:.75rem">
        <div style="display:flex; align-items:center; gap:4px">
          <span class="cal-cap-green" style="display:inline-block; width:10px; height:10px; border-radius:2px"></span>
          Green (&lt;150 Bales)
        </div>
        <div style="display:flex; align-items:center; gap:4px">
          <span class="cal-cap-amber" style="display:inline-block; width:10px; height:10px; border-radius:2px"></span>
          Amber (150-300 Bales)
        </div>
        <div style="display:flex; align-items:center; gap:4px">
          <span class="cal-cap-red" style="display:inline-block; width:10px; height:10px; border-radius:2px"></span>
          Red (&gt;300 Bales)
        </div>
      </div>
    </div>
  </div>
  
  <!-- Right Side: Details Panel -->
  <div class="card" id="calendar-day-details" style="min-height:300px">
    <div class="card-head" style="background:var(--surface-2); color:var(--text)">
      <h3 id="details-date-label" style="color:var(--gold)">Select a Date</h3>
    </div>
    <div class="card-body" id="details-body-container" style="padding:1rem; font-size:.8rem">
      <div class="text-center text-muted" style="padding:2rem">
        Click any calendar date to see daily bookings capacity, registered growers, and trigger quick manual booking.
      </div>
    </div>
  </div>
</div>
</div>

<!-- Tab Panel 1: Booking Registry -->
<div id="panel-registry" class="booking-tab-panel active">
  <!-- Booked Growers Registry table -->
  <div class="card" id="registry-list-card" style="display:block">
  <div class="card-head" style="flex-wrap:wrap;gap:.5rem">
    <h3 style="margin:0">Booked Growers Registry</h3>
    <input type="date" id="date-filter" value="<?=htmlspecialchars($f_date)?>"
           class="form-control" style="max-width:145px;font-size:.78rem;padding:.3rem .5rem"
           onchange="applyDateFilter(this.value)" title="Filter by sell date">
    <div id="registry-filters" style="display:flex;gap:.3rem;flex-wrap:wrap">
      <a href="?status=all<?=$f_date?"&sell_date=$f_date":""?>"
         class="btn <?=$f_status==="all"?"btn-primary":"btn-ghost"?> btn-sm" style="font-size:.68rem">All (<?=$total_count?>)</a>
      <a href="?status=booked<?=$f_date?"&sell_date=$f_date":""?>"
         class="btn <?=$f_status==="booked"?"btn-primary":"btn-ghost"?> btn-sm" style="font-size:.68rem">Booked (<?=$booked_count?>)</a>
      <a href="?status=balanced<?=$f_date?"&sell_date=$f_date":""?>"
         class="btn <?=$f_status==="balanced"?"btn-primary":"btn-ghost"?> btn-sm" style="font-size:.68rem">Balanced (<?=$balanced_count?>)</a>
      <?php if($f_date):?>
      <a href="?status=<?=htmlspecialchars((string)$f_status)?>" class="btn btn-ghost btn-sm" style="font-size:.68rem;color:var(--danger)">✕ Clear Date</a>
      <?php endif;?>
    </div>
  </div>
  <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
  <table id="booking-tbl">
  <thead><tr>
    <th>Grower #</th><th>First name</th><th>Surname</th><th>Booking ID</th>
    <th class="text-right">Bales booked</th>
    <th class="text-right">Bales delivered</th>
    <th>Sell date</th><th>Status</th><th>Action</th>
  </tr></thead>
  <tbody>
  <?php if(empty($tg_list)):?>
  <tr><td colspan="9" class="text-center text-muted" style="padding:2.5rem">
    No booked growers found for this floor.
  </td></tr>
  <?php else: foreach($tg_list as $tg):
    $balanced = !empty($tg["bal_id"]);
    $row_class = $is_admin === 1 ? 'admin-clickable' : '';
    $row_style = $is_admin === 1 ? 'cursor: pointer;' : '';
  ?>
  <tr id="tg-<?=$tg["bb_id"]?>" class="<?=$row_class?>" style="<?=$row_style?>"
      <?php if($is_admin === 1): ?>
      onclick="handleRowClick(<?=htmlspecialchars(json_encode($tg))?>)"
      <?php else: ?>
      onclick="handleNonAdminClick()"
      <?php endif; ?>>
    <td class="nowrap"><span class="badge badge-gold"><?=htmlspecialchars($tg["grower_num"])?></span></td>
    <td class="fw-500 nowrap"><?=htmlspecialchars($tg["first_name"])?></td>
    <td class="fw-500 nowrap"><?=htmlspecialchars($tg["surname"])?></td>
    <td class="td-muted nowrap" style="font-family:ui-monospace,monospace;font-size:.8rem"><?=htmlspecialchars($tg["booked_id"] ?: '—')?></td>
    <td class="td-mono text-right" style="font-weight:600"><?=(int)$tg["bales_booked"]?></td>
    <td class="td-mono text-right" style="color:<?=(int)$tg["bales_delivered"]>0?"var(--leaf)":"var(--muted)"?>">
      <?=(int)$tg["bales_delivered"]?>
    </td>
    <td class="nowrap"><?=htmlspecialchars($tg["sell_dt"]??"")?></td>
    <td>
      <?php if($balanced):?>
        <span class="badge badge-green">Balanced</span>
      <?php else:?>
        <span class="badge badge-blue">Booked</span>
      <?php endif;?>
    </td>
    <td style="white-space:nowrap">
      <?php if(!$balanced):?>
        <button onclick="event.stopPropagation(); unbookGrower(<?=$tg["bb_id"]?>, <?=$tg["growerid"]?>, '<?=htmlspecialchars($tg["sell_dt"])?>')" 
                class="btn btn-ghost btn-sm" style="color:var(--bad-text)">Unbook</button>
      <?php else:?>
        <span class="td-muted">Done</span>
      <?php endif;?>
    </td>
  </tr>
  <?php endforeach; endif;?>
  </tbody>
  </table>
  </div>
</div>
</div>

<!-- ── MANUAL BOOKING MODAL ──────────────────────────────────────────── -->
<div id="book-modal" style="display:none;position:fixed;inset:0;z-index:9000;
     background:rgba(0,0,0,.55);overflow-y:auto;padding:2rem 1rem"
     onclick="if(event.target===this)closeBookModal()">
<div style="background:var(--surface);border-radius:12px;max-width:520px;margin:0 auto;
            box-shadow:0 16px 60px rgba(0,0,0,.35);overflow:hidden">
  <div style="background:var(--surface-2);color:var(--text);padding:.85rem 1.25rem;
              display:flex;align-items:center;gap:.75rem">
    <div style="font-family:'DM Sans',sans-serif;font-size:0.94rem;color:var(--gold);
                flex:1;font-weight:600">Manual Booking Entry</div>
    <button onclick="closeBookModal()" style="background:none;border:none;
            color:var(--text-muted);font-size:1.4rem;cursor:pointer;line-height:1" aria-label="Close">&times;</button>
  </div>
  <div style="padding:1.25rem">
    <div id="book-modal-msg"></div>
    <div class="form-group">
      <label>Grower *</label>
      <select id="bm-grower" class="form-control">
        <option value="">Select grower...</option>
        <?php foreach($growers as $g):?>
        <option value="<?=$g["id"]?>"><?=htmlspecialchars($g["grower_num"]." — ".$g["name"]." ".($g["surname"]??""))?></option>
        <?php endforeach;?>
      </select>
    </div>
    <div class="form-group">
      <label>Transporter *</label>
      <select id="bm-transporter" class="form-control">
        <option value="">Select transporter...</option>
        <?php foreach($transporters as $t):?>
        <option value="<?=$t["id"]?>"><?=htmlspecialchars($t["name"]." (".$t["truck_num"].")")?></option>
        <?php endforeach;?>
      </select>
    </div>
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Bales Booked</label>
        <input type="number" id="bm-bales" class="form-control" min="0" value="0">
      </div>
      <div class="form-group">
        <label>Sell Date</label>
        <input type="date" id="bm-date" class="form-control" value="<?=$today?>">
      </div>
    </div>
  </div>
  <div style="padding:.75rem 1.25rem;border-top:2px solid var(--border);
              background:var(--surface-2);display:flex;gap:.65rem;justify-content:flex-end">
    <button onclick="closeBookModal()" class="btn btn-ghost btn-sm">Cancel</button>
    <button onclick="submitBooking()" class="btn btn-primary" id="bm-submit">✓ Book Grower</button>
  </div>
</div>
</div>

<!-- ── EDIT BOOKING MODAL ──────────────────────────────────────────── -->
<div id="edit-modal" style="display:none;position:fixed;inset:0;z-index:9000;
     background:rgba(0,0,0,.55);overflow-y:auto;padding:2rem 1rem"
     onclick="if(event.target===this)closeEditModal()">
<div style="background:var(--surface);border-radius:12px;max-width:520px;margin:0 auto;
            box-shadow:0 16px 60px rgba(0,0,0,.35);overflow:hidden">
  <div style="background:var(--surface-2);color:var(--text);padding:.85rem 1.25rem;
              display:flex;align-items:center;gap:.75rem">
    <div style="font-family:'DM Sans',sans-serif;font-size:0.94rem;color:var(--gold);
                flex:1;font-weight:600">Edit Booking Details</div>
    <button onclick="closeEditModal()" style="background:none;border:none;
            color:var(--text-muted);font-size:1.4rem;cursor:pointer;line-height:1" aria-label="Close">&times;</button>
  </div>
  <div style="padding:1.25rem">
    <div id="edit-modal-msg"></div>
    <input type="hidden" id="em-bb-id">
    
    <div class="form-group">
      <label>Grower</label>
      <input type="text" id="em-grower-name" class="form-control" readonly style="background:#e9e7e2; color:#555;">
    </div>
    
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Bales Booked *</label>
        <input type="number" id="em-bales-booked" class="form-control" min="0">
      </div>
      <div class="form-group">
        <label>Bales Delivered</label>
        <input type="number" id="em-bales-delivered" class="form-control" min="0">
      </div>
    </div>
    
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Sell Date *</label>
        <input type="date" id="em-sell-date" class="form-control">
      </div>
      <div class="form-group">
        <label>Booking ID / Reference *</label>
        <input type="text" id="em-booked-id" class="form-control">
      </div>
    </div>
  </div>
  <div style="padding:.75rem 1.25rem;border-top:2px solid var(--border);
              background:var(--surface-2);display:flex;gap:.65rem;justify-content:flex-end">
    <button onclick="closeEditModal()" class="btn btn-ghost btn-sm">Cancel</button>
    <button onclick="submitEditBooking()" class="btn btn-primary" id="em-submit">✓ Save Changes</button>
  </div>
</div>
</div>

<script type="application/json" id="daily-bookings-data"><?= json_encode((object)$daily_bookings, JSON_HEX_TAG) ?></script>

<script>
function applyDateFilter(val){
    var url = new URL(window.location.href);
    if(val) url.searchParams.set('sell_date', val);
    else url.searchParams.delete('sell_date');
    window.location.href = url.toString();
}

function openBookModal(){
    document.getElementById("book-modal").style.display="block";
    document.getElementById("bm-grower").focus();
    document.getElementById("book-modal-msg").innerHTML="";
}
function closeBookModal(){
    document.getElementById("book-modal").style.display="none";
}

function handleRowClick(tg) {
    // Fill the edit modal inputs
    document.getElementById("em-bb-id").value = tg.bb_id;
    document.getElementById("em-grower-name").value = tg.grower_num + " — " + tg.grower_name;
    document.getElementById("em-bales-booked").value = tg.bales_booked;
    document.getElementById("em-bales-delivered").value = tg.bales_delivered;
    document.getElementById("em-sell-date").value = tg.sell_dt;
    document.getElementById("em-booked-id").value = tg.booked_id || "";
    
    // Clear message
    document.getElementById("edit-modal-msg").innerHTML = "";
    
    // Show modal
    document.getElementById("edit-modal").style.display = "block";
}

function handleNonAdminClick() {
    if (window.fw && fw.toast) {
        fw.toast("Access denied. Only systems administrators can edit bookings.", "warning");
    } else {
        alert("Access denied. Only systems administrators can edit bookings.");
    }
}

function closeEditModal() {
    document.getElementById("edit-modal").style.display = "none";
}

document.addEventListener("keydown",function(e){
    if(e.key==="Escape") {
        closeBookModal();
        closeEditModal();
    }
});

function submitBooking(){
    var gid  = document.getElementById("bm-grower").value;
    var trid = document.getElementById("bm-transporter").value;
    var bales= document.getElementById("bm-bales").value;
    var date = document.getElementById("bm-date").value;
    var msg  = document.getElementById("book-modal-msg");

    if(!gid || !trid){
        msg.innerHTML='<div class="alert alert-danger" style="margin-bottom:.75rem">Grower and transporter are required.</div>';
        return;
    }

    var btn = document.getElementById("bm-submit");
    btn.disabled=true; btn.textContent="Booking...";

    var fd = new FormData();
    fd.append("action","book_manual");
    fd.append("growerid",gid);
    fd.append("transporterid",trid);
    fd.append("bales",bales);
    fd.append("sell_date",date);

    fetch("grower_booking.php",{method:"POST",body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d){
        btn.disabled=false; btn.textContent="✓ Book Grower";
        if(!d.ok){
            msg.innerHTML='<div style="background:var(--surface-2);color:var(--bad-text);padding:.6rem .9rem;border-radius:7px;font-size:.82rem;margin-bottom:.75rem;border-left:3px solid #e53935">'+xe(d.error||d.msg||"Booking failed.")+'</div>';
            return;
        }
        // Success
        msg.innerHTML='<div style="background:var(--surface-2);color:var(--ok-text);padding:.6rem .9rem;border-radius:7px;font-size:.82rem;margin-bottom:.75rem;border-left:3px solid #2e7d32">✓ '+xe(d.msg)+'</div>';
        if(window.fw && fw.toast) fw.toast(d.msg, "success");
        // Reset form
        document.getElementById("bm-grower").value="";
        document.getElementById("bm-transporter").value="";
        document.getElementById("bm-bales").value="0";
        refreshRegistry(d.bb_id);
    })
    .catch(function(e){
        btn.disabled=false; btn.textContent="✓ Book Grower";
        msg.innerHTML='<div style="background:var(--surface-2);color:var(--bad-text);padding:.6rem .9rem;border-radius:7px;font-size:.82rem;margin-bottom:.75rem;border-left:3px solid #e53935">Error: '+e.message+'</div>';
    });
}

function submitEditBooking() {
    var bb_id = document.getElementById("em-bb-id").value;
    var bales_booked = document.getElementById("em-bales-booked").value;
    var bales_delivered = document.getElementById("em-bales-delivered").value;
    var sell_date = document.getElementById("em-sell-date").value;
    var booked_id = document.getElementById("em-booked-id").value;
    var msg = document.getElementById("edit-modal-msg");
    var btn = document.getElementById("em-submit");

    if (!bales_booked || !sell_date || !booked_id) {
        msg.innerHTML = '<div class="alert alert-danger" style="margin-bottom:.75rem">Bales Booked, Sell Date, and Booking ID are required.</div>';
        return;
    }

    btn.disabled = true;
    btn.textContent = "Saving...";

    var fd = new FormData();
    fd.append("action", "edit_booking");
    fd.append("bb_id", bb_id);
    fd.append("bales_booked", bales_booked);
    fd.append("bales_delivered", bales_delivered);
    fd.append("sell_date", sell_date);
    fd.append("booked_id", booked_id);

    fetch("grower_booking.php", { method: "POST", body: fd })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        btn.textContent = "✓ Save Changes";
        if (!d.ok) {
            msg.innerHTML = '<div style="background:var(--surface-2);color:var(--bad-text);padding:.6rem .9rem;border-radius:7px;font-size:.82rem;margin-bottom:.75rem;border-left:3px solid #e53935">' + xe(d.error || d.msg || "Update failed.") + '</div>';
            return;
        }
        msg.innerHTML = '<div style="background:var(--surface-2);color:var(--ok-text);padding:.6rem .9rem;border-radius:7px;font-size:.82rem;margin-bottom:.75rem;border-left:3px solid #2e7d32">✓ ' + xe(d.msg) + '</div>';
        if (window.fw && fw.toast) fw.toast(d.msg, "success");
        refreshRegistry(bb_id);
        setTimeout(closeEditModal, 800);
    })
    .catch(e => {
        btn.disabled = false;
        btn.textContent = "✓ Save Changes";
        msg.innerHTML = '<div style="background:var(--surface-2);color:var(--bad-text);padding:.6rem .9rem;border-radius:7px;font-size:.82rem;margin-bottom:.75rem;border-left:3px solid #e53935">Error: ' + e.message + '</div>';
    });
}

async function unbookGrower(bbId, growerId, sellDate) {
    if (!confirm("Are you sure you want to unbook this grower? This will remove their booked bales entry.")) return;
    
    if (window.fw && fw.barStart) fw.barStart("Unbooking...");
    try {
        var r = await fw.get("grower_booking.php", { ajax: 1, action: "unbook", bb_id: bbId });
        if (window.fw && fw.barDone) fw.barDone();
        
        if (!r || !r.ok) {
            var em = (r && (r.error || r.msg)) || "Unbook failed.";
            if (window.fw && fw.toast) fw.toast(em, "error");
            else alert(em);
            return;
        }
        
        if (window.fw && fw.toast) fw.toast("Grower unbooked successfully", "success");
        refreshRegistry();
    } catch (e) {
        if (window.fw && fw.barDone) fw.barDone();
        if (window.fw && fw.toast) fw.toast(e.message, "error");
        else alert(e.message);
    }
}

// ── TIMB Booking File ────────────────────────────────────────────────
function timb_booking_file_selected(){
    var f = document.getElementById('timb-booking-file').files[0];
    document.getElementById('timb-upload-btn').disabled = !f;
}

async function uploadTimbBooking(){
    var fileInput = document.getElementById('timb-booking-file');
    var msgEl     = document.getElementById('timb-upload-msg');
    var btn       = document.getElementById('timb-upload-btn');
    if (!fileInput.files[0]) { msgEl.innerHTML='<span style="color:var(--danger)">Select a file first.</span>'; return; }
    btn.disabled = true; btn.textContent = '🔓 Decrypting...';
    msgEl.innerHTML = '<span style="color:var(--muted)">Uploading and decrypting…</span>';
    var fd = new FormData();
    fd.append('action', 'upload_timb_booking');
    fd.append('timb_booking_file', fileInput.files[0]);
    try {
        var resp = await fetch('grower_booking.php', {method:'POST', body:fd});
        var d = await resp.json();
        btn.disabled = false; btn.textContent = '🔓 Decrypt & Book Growers';
        if (d.error) {
            msgEl.innerHTML = '<span style="color:var(--danger)">✕ '+xe(d.error)+'</span>';
            return;
        }
        // Reload to show success msg & new bookings
        location.reload();
    } catch(e) {
        btn.disabled = false; btn.textContent = '🔓 Decrypt & Book Growers';
        msgEl.innerHTML = '<span style="color:var(--danger)">✕ '+xe(e.message)+'</span>';
    }
}

function xe(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── IN-PLACE REFRESH ──
// Re-reads this page (same URL, so the status and date filters stay) and swaps
// in the KPIs, filter counts, registry rows and calendar totals. The window and
// the registry's own scroll position are left alone.
async function refreshRegistry(highlightBbId) {
    try {
        var r = await fetch(window.location.href, {headers:{'X-Requested-With':'XMLHttpRequest'}});
        var doc = new DOMParser().parseFromString(await r.text(), 'text/html');
        var newBody = doc.querySelector('#booking-tbl tbody');
        if (!r.ok || !newBody) throw new Error('could not read the updated list');
        document.querySelector('#booking-tbl tbody').innerHTML = newBody.innerHTML;
        ['booking-kpis', 'registry-filters'].forEach(function(id) {
            var src = doc.getElementById(id), dst = document.getElementById(id);
            if (src && dst) dst.innerHTML = src.innerHTML;
        });
        var data = doc.getElementById('daily-bookings-data');
        if (data) {
            Object.keys(DAILY_BOOKINGS).forEach(function(k){ delete DAILY_BOOKINGS[k]; });
            Object.assign(DAILY_BOOKINGS, JSON.parse(data.textContent));
        }
        if (document.getElementById('panel-calendar').classList.contains('active')) {
            selectDate(selectedDateStr || TODAY_STR);
        }
        var row = highlightBbId ? document.getElementById('tg-' + highlightBbId) : null;
        if (row) {
            row.style.transition = 'background-color 1.5s ease';
            row.style.backgroundColor = 'rgba(61,107,53,.18)';
            setTimeout(function(){ row.style.backgroundColor = ''; }, 1500);
        }
    } catch (e) {
        if (window.fw && fw.toast) fw.toast('Saved, but the list did not refresh (' + e.message + '). Reload the page to see it.', 'warning');
    }
}

// ── SMART CALENDAR IMPLEMENTATION ──
const DAILY_BOOKINGS = JSON.parse(document.getElementById('daily-bookings-data').textContent);
const TODAY_STR = '<?= $today ?>';

let calYear = new Date().getFullYear();
let calMonth = new Date().getMonth(); // 0-indexed
let selectedDateStr = null;

function renderCalendar() {
    const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    document.getElementById('calendar-month-label').textContent = monthNames[calMonth] + " " + calYear;
    
    const daysGrid = document.getElementById('calendar-days-grid');
    daysGrid.innerHTML = '';
    
    // First day of current month
    const firstDay = new Date(calYear, calMonth, 1).getDay();
    // Days in current month
    const numDays = new Date(calYear, calMonth + 1, 0).getDate();
    // Days in previous month
    const prevNumDays = new Date(calYear, calMonth, 0).getDate();
    
    // Generate cells for previous month's padding
    for (let i = firstDay - 1; i >= 0; i--) {
        const dDiv = document.createElement('div');
        dDiv.className = 'cal-day other-month';
        dDiv.innerHTML = '<span class="cal-day-num">' + (prevNumDays - i) + '</span>';
        daysGrid.appendChild(dDiv);
    }
    
    // Generate current month days
    for (let day = 1; day <= numDays; day++) {
        const monthStr = String(calMonth + 1).padStart(2, '0');
        const dayStr = String(day).padStart(2, '0');
        const dateStr = calYear + '-' + monthStr + '-' + dayStr;
        
        const dDiv = document.createElement('div');
        dDiv.className = 'cal-day';
        if (dateStr === selectedDateStr) {
            dDiv.classList.add('active');
        }
        
        // Check capacity
        const totalBales = DAILY_BOOKINGS[dateStr] || 0;
        let capClass = '';
        if (totalBales > 0) {
            if (totalBales < 150) capClass = 'cal-cap-green';
            else if (totalBales <= 300) capClass = 'cal-cap-amber';
            else capClass = 'cal-cap-red';
            dDiv.classList.add(capClass);
        }
        
        dDiv.onclick = function() {
            selectDate(dateStr);
        };
        
        let balesBadge = '';
        if (totalBales > 0) {
            balesBadge = '<span class="cal-day-bales badge ' + (totalBales < 150 ? 'badge-green' : (totalBales <= 300 ? 'badge-gold' : 'badge-red')) + '">' + totalBales + ' Bales</span>';
        }
        
        dDiv.innerHTML = '<span class="cal-day-num">' + day + '</span>' + balesBadge;
        daysGrid.appendChild(dDiv);
    }
    
    // Generate remaining cells for next month's padding
    const totalCells = firstDay + numDays;
    const paddingCells = 42 - totalCells; // 6 rows of 7 days
    for (let i = 1; i <= paddingCells; i++) {
        const dDiv = document.createElement('div');
        dDiv.className = 'cal-day other-month';
        dDiv.innerHTML = '<span class="cal-day-num">' + i + '</span>';
        daysGrid.appendChild(dDiv);
    }
}

function changeMonth(dir) {
    calMonth += dir;
    if (calMonth < 0) {
        calMonth = 11;
        calYear--;
    } else if (calMonth > 11) {
        calMonth = 0;
        calYear++;
    }
    renderCalendar();
}

async function selectDate(dateStr) {
    selectedDateStr = dateStr;
    
    // Highlight day in calendar UI
    document.querySelectorAll('.cal-day').forEach(function(el) {
        el.classList.remove('active');
    });
    
    // Re-render to ensure selection is drawn correctly
    renderCalendar();
    
    // Populate drawer details
    const parsedDate = new Date(dateStr);
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    document.getElementById('details-date-label').textContent = parsedDate.toLocaleDateString('en-US', options);
    
    const detailsContainer = document.getElementById('details-body-container');
    
    // Show loading state
    detailsContainer.innerHTML = '<div class="text-center text-muted" style="padding:2.5rem 1rem"><div class="fw-spinner" style="margin-right:8px; border-top-color:var(--gold)"></div> Loading day details...</div>';
    
    try {
        const response = await fetch('grower_booking.php?ajax=1&action=get_day_details&sell_date=' + dateStr);
        const detailsList = await response.json();
        
        let html = '';
        
        // Daily stats summary banner
        const totalBales = DAILY_BOOKINGS[dateStr] || 0;
        let capStatus = 'Low Capacity &mdash; Floor Open';
        let alertType = 'alert-success';
        if (totalBales >= 150 && totalBales <= 300) {
            capStatus = 'Approaching Limit';
            alertType = 'alert-gold';
        } else if (totalBales > 300) {
            capStatus = 'Fully Booked Warning';
            alertType = 'alert-danger';
        }
        
        html += '<div class="alert ' + alertType + '" style="margin-bottom:1rem; padding:.6rem .8rem; font-size:.78rem">';
        html += '<strong>Capacity:</strong> ' + totalBales + ' Booked Bales &bull; <em>' + capStatus + '</em>';
        html += '</div>';
        
        // Fast booking trigger
        html += '<div style="margin-bottom:1.1rem; text-align:right">';
        html += '<button onclick="quickBookForDate(\'' + dateStr + '\')" class="btn btn-primary btn-sm" style="font-size:.72rem">+ Quick Book Grower</button>';
        html += '</div>';
        
        if (detailsList.length === 0) {
            html += '<div class="text-center text-muted" style="padding:2.5rem 1rem">';
            html += 'No registered growers booked for this date.';
            html += '</div>';
        } else {
            html += '<div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem); overflow-y:auto">';
            html += '<table>';
            html += '<thead><tr><th>Grower</th><th class="text-right">Bales</th><th>Ref</th></tr></thead>';
            html += '<tbody>';
            
            detailsList.forEach(function(item) {
                html += '<tr>';
                html += '<td>';
                html += '<span class="badge badge-gold" style="font-size:.72rem">' + xe(item.grower_num) + '</span>';
                html += '<div style="font-weight:600; font-size:.72rem; color:var(--text); margin-top:2px">' + xe(item.grower_name) + '</div>';
                html += '</td>';
                html += '<td class="td-mono text-right" style="font-weight:600">' + item.bales_booked + '</td>';
                html += '<td style="font-size:.68rem; font-family:monospace; color:var(--muted)">' + xe(item.booked_id || '—') + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            html += '</div>';
        }
        
        detailsContainer.innerHTML = html;
    } catch (e) {
        detailsContainer.innerHTML = '<div class="text-center text-danger" style="padding:2.5rem 1rem">✕ Failed to load details: ' + xe(e.message) + '</div>';
    }
}

function quickBookForDate(dateStr) {
    openBookModal();
    document.getElementById('bm-date').value = dateStr;
}

function switchBookingTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.booking-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        localStorage.setItem('booking_view_mode', tabId);
        
        if (tabId === 'calendar') {
            renderCalendar();
            if (!selectedDateStr) {
                selectDate(TODAY_STR);
            } else {
                selectDate(selectedDateStr);
            }
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const hasSync = params.has('fm_sync');
    
    const defaultTab = hasSync ? 'import' : (localStorage.getItem('booking_view_mode') || 'registry');
    switchBookingTab(defaultTab);
});
</script>

<?php include "includes/layout_end.php"; ?>
