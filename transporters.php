<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

// Core session vars needed by AJAX handlers too
$userid   = current_userid();
$seasonid = (int)($_SESSION['seasonid'] ?? 0);
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$today    = date('Y-m-d');
$msg = $err = '';

// Transporters belong to a floor: any transporter id in the request must be on the user's floor.
$_req_tid = (int)($_POST['transporterid'] ?? $_POST['tid'] ?? $_GET['tid'] ?? 0);
if ($_req_tid && !flowork_on_floor($conn, 'transporter', $_req_tid, $spid)) {
    if (isset($_GET['ajax'])) {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Transporter not found on this floor']);
        exit;
    }
    $err = 'Transporter not found on this floor.';
    $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
}

// The floor's active selling day (start_of_day), or null when none is open.
function flowork_active_sale_day(mysqli $conn, int $spid): ?array {
    $q = $conn->query("SELECT id, description FROM start_of_day WHERE active=1 AND selling_pointid=$spid ORDER BY id DESC LIMIT 1");
    $r = ($q && $q !== true) ? $q->fetch_assoc() : null;
    if (!$r || !strtotime($r['description'])) return null;
    return ['id' => (int)$r['id'], 'date' => date('Y-m-d', strtotime($r['description']))];
}
if (isset($_GET['ajax'])) {
    // Clean any accidental output before JSON
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    mysqli_report(MYSQLI_REPORT_OFF);
    $aj = $_GET['ajax'];

    // AJAX add_tg
    if ($aj === 'add_tg') {
        $tid   = (int)($_POST['transporterid'] ?? 0);
        $gnum  = $conn->real_escape_string(strtoupper(trim($_POST['grower_num'] ?? '')));
        $bales = (int)($_POST['bales'] ?? 0);
        $sdate = $conn->real_escape_string(trim($_POST['sell_date'] ?? date('Y-m-d')));
        $now   = date('Y-m-d H:i:s');

        if (!$tid)  { echo json_encode(['error'=>'No transporter selected.']); exit; }
        if (!$gnum) { echo json_encode(['error'=>'Grower number is required.']); exit; }

        // Find grower — try floor-scoped first, then global
        $gr = $conn->query("SELECT id FROM growers WHERE grower_num='$gnum'
                            AND (selling_pointid=$spid OR $spid=0) LIMIT 1");
        if (!$gr || !$gr->num_rows)
            $gr = $conn->query("SELECT id FROM growers WHERE grower_num='$gnum' LIMIT 1");
        if (!$gr || !$gr->num_rows) {
            echo json_encode(['error'=>"Grower '$gnum' not found. Register them in Grower Registry first."]);
            exit;
        }
        $gid = (int)$gr->fetch_assoc()['id'];

        // Duplicate check: same grower + transporter + sell date only (allow same grower on different dates)
        $dup_q = $conn->query("SELECT id FROM transporter_growers
            WHERE transporterid=$tid AND growerid=$gid AND sell_date='$sdate' LIMIT 1");
        if ($dup_q && $dup_q->num_rows > 0) {
            echo json_encode(['error'=>"Grower $gnum is already on this transporter's delivery note for $sdate."]);
            exit;
        }

        $conn->query("INSERT INTO transporter_growers
            (userid,transporterid,growerid,selling_pointid,bales,sell_date,status,open_close,created_at,datetime)
            VALUES ($userid,$tid,$gid,$spid,$bales,'$sdate','active',0,'$now','$now')");
        if ($conn->insert_id) {
            sync_booked_delivery_bales($conn, $gid, $sdate);
            echo json_encode(['ok'=>true,'msg'=>"Grower $gnum added — $bales bales for $sdate."]);
        } else {
            echo json_encode(['error'=>'Database error: '.$conn->error]);
        }
        exit;
    }

    // AJAX update_tg
    if ($aj === 'update_tg') {
        $tgid      = (int)($_POST['tgid'] ?? 0);
        $new_bales = (int)($_POST['new_bales'] ?? 0);
        $new_date  = $conn->real_escape_string($_POST['new_date'] ?? date('Y-m-d'));
        $new_status= in_array($_POST['new_status']??'',['active','suspended','blocked'])?$_POST['new_status']:'active';
        $new_loc   = (int)($_POST['new_location_id'] ?? 0);
        $new_spid  = (int)($_POST['new_spid'] ?? 0);
        if (!$tgid) { echo json_encode(['error'=>'No tgid']); exit; }
        
        $old_q = $conn->query("SELECT growerid, sell_date FROM transporter_growers WHERE id=$tgid LIMIT 1");
        $old_row = ($old_q && $old_q->num_rows > 0) ? $old_q->fetch_assoc() : null;

        $sets = ["bales=$new_bales","sell_date='$new_date'","status='$new_status'","datetime=NOW()"];
        if ($new_loc > 0) $sets[] = "locationid=$new_loc";
        if ($new_spid > 0) $sets[] = "selling_pointid=$new_spid";
        $conn->query("UPDATE transporter_growers SET ".implode(',',$sets)." WHERE id=$tgid");
        // Update booked_bales
        $gr_q = $conn->query("SELECT growerid FROM transporter_growers WHERE id=$tgid LIMIT 1");
        if ($gr_q&&$gr_q->num_rows>0) {
            $gid2=(int)$gr_q->fetch_assoc()['growerid'];
            $conn->query("UPDATE booked_bales SET sell_date='$new_date' WHERE growerid=$gid2");
            
            sync_booked_delivery_bales($conn, $gid2, $new_date);
            if ($old_row && ($old_row['sell_date'] !== $new_date || (int)$old_row['growerid'] !== $gid2)) {
                sync_booked_delivery_bales($conn, (int)$old_row['growerid'], $old_row['sell_date']);
            }
        }
        echo json_encode(['ok'=>true,'msg'=>'Delivery note updated.']);
        exit;
    }

    // AJAX tg_list - get growers for a transporter
    if ($aj === 'tg_list') {
        $tid = (int)($_GET['tid'] ?? 0);
        // Use subqueries to avoid GROUP BY strict mode issues
        $rows = $conn->query("
            SELECT tg.id tgid, tg.bales, tg.junused_bales,
                   COALESCE(tg.status,'active') status,
                   tg.open_close, tg.booked,
                   COALESCE(tg.sell_date,'') sell_date,
                   COALESCE(tg.locationid,0) locationid,
                   tg.selling_pointid tg_spid,
                   g.grower_num, g.name, g.surname,
                   COALESCE(bl.code,'') loc_code,
                   (SELECT COUNT(*) FROM balanced_bales WHERE transporter_growersid=tg.id) is_balanced,
                   (SELECT COUNT(*) FROM bale_junus WHERE transporter_growersid=tg.id) junus_count,
                   (SELECT COUNT(*) FROM sold_bales sb2
                        JOIN tickets tk2 ON tk2.id=sb2.ticketsid
                        JOIN bale_junus bj2 ON bj2.id=tk2.bale_junusid
                        WHERE bj2.transporter_growersid=tg.id) sold_count
            FROM transporter_growers tg
            JOIN growers g ON g.id=tg.growerid
            LEFT JOIN bale_locations bl ON bl.id=tg.locationid
            WHERE tg.transporterid=$tid
            ORDER BY tg.datetime DESC, tg.id DESC
        ");
        $result = [];
        if ($rows && $rows !== true) {
            while ($row = $rows->fetch_assoc()) {
                $row['is_balanced'] = (int)$row['is_balanced'] > 0 ? 1 : 0;
                $result[] = $row;
            }
        }
        echo json_encode($result);
        exit;
    }

    // AJAX toggle open/close
    if ($aj === 'toggle_tg') {
        $tgid = (int)($_POST['tgid'] ?? 0);
        $oc   = (int)($_POST['open_close'] ?? 0);
        if (!flowork_on_floor($conn, 'tg', $tgid, $spid)) { echo json_encode(['error'=>'Delivery not found on this floor']); exit; }
        $conn->query("UPDATE transporter_growers SET open_close=$oc, datetime=NOW() WHERE id=$tgid");
        echo json_encode(['ok'=>true]);
        exit;
    }

    // AJAX export delivery note to CSV
    if ($aj === 'export_tg') {
        if (ob_get_level()) ob_clean();
        $tid = (int)($_GET['tid'] ?? 0);
        
        // Fetch transporter details
        $t_q = $conn->query("SELECT name, truck_num FROM transporter WHERE id=$tid LIMIT 1");
        $t_r = $t_q ? $t_q->fetch_assoc() : null;
        $t_name = $t_r['name'] ?? 'Transporter';
        $t_truck = $t_r['truck_num'] ?? '';
        
        // Fetch transporter growers
        $rows = $conn->query("
            SELECT tg.id tgid, tg.bales, tg.status, tg.open_close, tg.sell_date,
                   g.grower_num, g.name, g.surname,
                   COALESCE(bl.code,'') loc_code,
                   (SELECT COUNT(*) FROM balanced_bales WHERE transporter_growersid=tg.id) is_balanced
            FROM transporter_growers tg
            JOIN growers g ON g.id=tg.growerid
            LEFT JOIN bale_locations bl ON bl.id=tg.locationid
            WHERE tg.transporterid=$tid
            ORDER BY tg.datetime DESC, tg.id DESC
        ");
        
        $filename = "delivery_note_" . preg_replace('/[^a-zA-Z0-9]/', '_', $t_name) . "_" . date('Y-m-d') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $out = fopen('php://output', 'w');
        // Let's add transporter info at the top
        fputcsv($out, ["Transporter Delivery Note"]);
        fputcsv($out, ["Transporter:", $t_name]);
        fputcsv($out, ["Truck #:", $t_truck]);
        fputcsv($out, ["Export Date:", date('Y-m-d H:i:s')]);
        fputcsv($out, []); // Blank line
        
        // Column headers
        fputcsv($out, ['Grower #', 'Name', 'Bales', 'Sell Date', 'Status', 'D-Note Status', 'Balanced', 'Location']);
        
        if ($rows) {
            while ($r = $rows->fetch_assoc()) {
                $is_bal = (int)$r['is_balanced'] > 0 ? 'Yes' : 'No';
                $dnote = ((int)$r['open_close'] === 0) ? 'Open' : 'Closed';
                fputcsv($out, [
                    $r['grower_num'],
                    trim($r['name'] . ' ' . $r['surname']),
                    $r['bales'],
                    $r['sell_date'] ?: '—',
                    strtoupper($r['status'] ?: 'active'),
                    $dnote,
                    $is_bal,
                    $r['loc_code'] ?: '—'
                ]);
            }
        }
        fclose($out);
        exit;
    }
}

$page_title  = "Transporters";
$active_page = "transporters";

// Ensure extra columns exist
foreach ([
    "ALTER TABLE transporter_growers ADD COLUMN sell_date DATE NULL DEFAULT NULL",
    "ALTER TABLE transporter_growers ADD COLUMN status ENUM('active','suspended','blocked') NOT NULL DEFAULT 'active'",
    "ALTER TABLE transporter_growers ADD COLUMN booked TINYINT DEFAULT 0",
    "ALTER TABLE transporter_growers ADD COLUMN locationid INT NULL DEFAULT NULL",
    "ALTER TABLE transporter_growers ADD COLUMN splitid INT NOT NULL DEFAULT 1",
] as $ddl) { @$conn->query($ddl); }

// Reference data
$locations_q = $conn->query("SELECT id,code,name FROM bale_locations WHERE selling_pointid=$spid AND active=1 ORDER BY code");
$locations_arr = ($locations_q&&$locations_q!==true)?$locations_q->fetch_all(MYSQLI_ASSOC):[];
$selling_points_q = $conn->query("SELECT id,name FROM selling_point WHERE active=1 ORDER BY name");
$selling_points = ($selling_points_q&&$selling_points_q!==true)?$selling_points_q->fetch_all(MYSQLI_ASSOC):[];

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $now    = date('Y-m-d H:i:s');

    if ($action === 'add_transporter') {
        $name      = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $id_num    = $conn->real_escape_string(trim($_POST['id_num'] ?? ''));
        $truck_num = $conn->real_escape_string(trim($_POST['truck_num'] ?? ''));
        $loc_id    = (int)($_POST['location_id'] ?? 0);
        if (!$name || !$truck_num) { $err = "Name and truck number required."; }
        else {
            $loc_val = $loc_id > 0 ? $loc_id : 'NULL';
            $conn->query("INSERT INTO transporter (userid,seasonid,selling_pointid,name,id_num,truck_num,location,location_id,created_at,datetime)
                VALUES ($userid,$seasonid,$spid,'$name','$id_num','$truck_num','',$loc_val,'$now',NOW())");
            $msg = "Transporter '$name' added.";
            audit_log($conn,$spid,$userid,'transporter','create',(int)$conn->insert_id);
        }
    }

    if ($action === 'add_tg') {
        $tid    = (int)($_POST['transporterid'] ?? 0);
        $gnum   = $conn->real_escape_string(strtoupper(trim($_POST['grower_num'] ?? '')));
        $bales  = (int)($_POST['bales'] ?? 0);
        $sdate  = $conn->real_escape_string(trim($_POST['sell_date'] ?? $today));
        $gr = $conn->query("SELECT id FROM growers WHERE grower_num='$gnum'
            AND (selling_pointid=$spid OR $spid=0) LIMIT 1");
        if (!$gr || !$gr->num_rows)
            $gr = $conn->query("SELECT id FROM growers WHERE grower_num='$gnum' LIMIT 1");
        if (!$gr || !$gr->num_rows) {
            $err = "Grower '$gnum' not found.";
        } else {
            $gid  = (int)$gr->fetch_assoc()['id'];
            $dup_q = $conn->query("SELECT id FROM transporter_growers
                WHERE transporterid=$tid AND growerid=$gid AND sell_date='$sdate' LIMIT 1");
            if ($dup_q && $dup_q->num_rows > 0) {
                $err = "Grower $gnum is already on this delivery note for $sdate.";
            } else {
                $conn->query("INSERT INTO transporter_growers
                    (userid,transporterid,growerid,selling_pointid,bales,sell_date,status,open_close,created_at,datetime)
                    VALUES ($userid,$tid,$gid,$spid,$bales,'$sdate','active',0,'$now','$now')");
                sync_booked_delivery_bales($conn, $gid, $sdate);
                $msg = "Grower $gnum assigned — $bales bales.";
            }
        }
    }

    if ($action === 'toggle_transporter') {
        $tid    = (int)($_POST['tid'] ?? 0);
        $active = (int)($_POST['active'] ?? 1);
        $conn->query("UPDATE transporter SET active=$active, datetime=NOW() WHERE id=$tid");
        $msg = "Transporter ".($active?'activated':'deactivated').".";
    }

    if ($action === 'toggle_tg') {
        $tgid = (int)($_POST['tgid'] ?? 0);
        $oc   = (int)($_POST['open_close'] ?? 0);
        if (!flowork_on_floor($conn, 'tg', $tgid, $spid)) { $err = "Delivery not found on this floor."; }
        else {
            $conn->query("UPDATE transporter_growers SET open_close=$oc, datetime=NOW() WHERE id=$tgid");
            $msg = "Delivery note ".($oc?'closed':'opened').".";
        }
    }

    // ── Delivery note actions ─────────────────────────────────────────────
    if ($action === 'update_tg') {
        $tgid      = (int)($_POST['tgid'] ?? 0);
        $new_bales = (int)($_POST['new_bales'] ?? 0);
        $new_date  = $conn->real_escape_string($_POST['new_date'] ?? $today);
        $new_status= in_array($_POST['new_status']??'',['active','suspended','blocked'])?$_POST['new_status']:'active';
        $new_loc   = (int)($_POST['new_location_id'] ?? 0);
        $new_spid  = (int)($_POST['new_spid'] ?? 0);
        if ($tgid) {
            $old_q = $conn->query("SELECT growerid, sell_date FROM transporter_growers WHERE id=$tgid LIMIT 1");
            $old_row = ($old_q && $old_q->num_rows > 0) ? $old_q->fetch_assoc() : null;

            $sets = ["bales=$new_bales","sell_date='$new_date'","status='$new_status'","datetime=NOW()"];
            if ($new_loc > 0) $sets[] = "locationid=$new_loc";
            if ($new_spid > 0) $sets[] = "selling_pointid=$new_spid";
            $conn->query("UPDATE transporter_growers SET ".implode(',',$sets)." WHERE id=$tgid");
            // Update booked_bales sell_date
            $gr_q = $conn->query("SELECT growerid FROM transporter_growers WHERE id=$tgid LIMIT 1");
            if ($gr_q&&$gr_q->num_rows>0) {
                $gid2=(int)$gr_q->fetch_assoc()['growerid'];
                $conn->query("UPDATE booked_bales SET sell_date='$new_date' WHERE growerid=$gid2");
                
                sync_booked_delivery_bales($conn, $gid2, $new_date);
                if ($old_row && ($old_row['sell_date'] !== $new_date || (int)$old_row['growerid'] !== $gid2)) {
                    sync_booked_delivery_bales($conn, (int)$old_row['growerid'], $old_row['sell_date']);
                }
            }
            audit_log($conn,$spid,$userid,'transporter_growers','update',$tgid);
            $msg="Delivery note updated.";
        }
    }

    // Roll selected hanging deliveries over to the floor's active selling day.
    // Only unbalanced deliveries move, and only when no bale was sold before the
    // active day: bales sold on an earlier day are balanced on that day. Bales
    // already sold on the active day (a hanging delivery sold before it was
    // rolled over) move with it. The booking moves too, and the delivery's
    // tickets move to the new day so its bales are valued at that day's rate.
    if ($action === 'rollover_selected') {
        $sod = flowork_active_sale_day($conn, $spid);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['tgids'] ?? [])))));
        if (!$sod) {
            $err = "No active selling day on this floor. Start the selling day first, then roll deliveries over.";
        } elseif (!$ids) {
            $err = "Select at least one grower to roll over.";
        } else {
            $new_date = $conn->real_escape_string($sod['date']);
            $sodid    = (int)$sod['id'];
            $moved = []; $skipped = [];
            foreach ($ids as $tgid) {
                $q = $conn->query("SELECT tg.id, tg.growerid, tg.sell_date, g.grower_num,
                        (SELECT COUNT(*) FROM balanced_bales WHERE transporter_growersid=tg.id) bal,
                        (SELECT COUNT(*) FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid
                            JOIN bale_junus bj ON bj.id=tk.bale_junusid WHERE bj.transporter_growersid=tg.id
                            AND sb.created_at < '$new_date') sold_before
                    FROM transporter_growers tg JOIN growers g ON g.id=tg.growerid WHERE tg.id=$tgid LIMIT 1");
                $r = ($q && $q !== true) ? $q->fetch_assoc() : null;
                if (!$r || !flowork_on_floor($conn, 'tg', $tgid, $spid)) { $skipped[] = "#$tgid not on this floor"; continue; }
                if ((int)$r['bal']) { $skipped[] = "{$r['grower_num']} is already balanced"; continue; }
                if ((int)$r['sold_before']) { $skipped[] = "{$r['grower_num']} has bales sold before {$sod['date']} (balance it on its own day)"; continue; }
                if ((string)$r['sell_date'] >= $sod['date']) { $skipped[] = "{$r['grower_num']} is already on {$r['sell_date']}"; continue; }

                $gid = (int)$r['growerid'];
                $old = $conn->real_escape_string((string)$r['sell_date']);
                $conn->begin_transaction();
                $ok = $conn->query("UPDATE transporter_growers SET sell_date='$new_date', datetime=NOW() WHERE id=$tgid");
                // The booking for the old day moves too, unless the grower is already booked on the new day.
                $has_new = $conn->query("SELECT 1 FROM booked_bales WHERE growerid=$gid AND sell_date='$new_date' LIMIT 1");
                if ($ok && !($has_new && $has_new->num_rows))
                    $ok = $conn->query("UPDATE booked_bales SET sell_date='$new_date' WHERE growerid=$gid AND sell_date='$old'");
                $tix = 0;
                if ($ok) {
                    $ok = $conn->query("UPDATE tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid
                        SET tk.start_of_dayid=$sodid
                        WHERE bj.transporter_growersid=$tgid");
                    $tix = $ok ? $conn->affected_rows : 0;
                }
                if (!$ok) { $e = $conn->error; $conn->rollback(); $skipped[] = "{$r['grower_num']}: $e"; continue; }
                $conn->commit();
                sync_booked_delivery_bales($conn, $gid, (string)$r['sell_date']);
                sync_booked_delivery_bales($conn, $gid, $sod['date']);
                audit_log($conn, $spid, $userid, 'transporter_growers', 'rollover', $tgid,
                    ['sell_date' => $r['sell_date']], ['sell_date' => $sod['date'], 'start_of_dayid' => $sodid, 'tickets_moved' => $tix]);
                $moved[] = $r['grower_num'];
            }
            if ($moved) $msg = count($moved) . " delivery note(s) rolled over to {$sod['date']}: " . implode(', ', $moved) . ".";
            if ($skipped) $err = "Not rolled over: " . implode('; ', $skipped) . ".";
        }
    }
}

// ── AJAX handlers ─────────────────────────────────────────────────────────

// ── Hanging deliveries: unbalanced, nothing sold, dated before the active selling day ──
$active_sod = flowork_active_sale_day($conn, $spid);
$hanging = [];
if ($active_sod) {
    $ad = $conn->real_escape_string($active_sod['date']);
    $hq = $conn->query("
        SELECT tg.id tgid, tg.sell_date, tg.bales, tg.open_close, COALESCE(tg.status,'active') status,
               g.grower_num, g.name, g.surname, t.name transporter, t.truck_num,
               (SELECT COUNT(*) FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid
                 WHERE bj.transporter_growersid=tg.id) tickets,
               (SELECT COUNT(*) FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid
                 JOIN bale_junus bj ON bj.id=tk.bale_junusid WHERE bj.transporter_growersid=tg.id) sold_today
        FROM transporter_growers tg
        JOIN growers g ON g.id=tg.growerid
        JOIN transporter t ON t.id=tg.transporterid
        WHERE tg.selling_pointid=$spid AND tg.sell_date < '$ad'
          AND NOT EXISTS (SELECT 1 FROM balanced_bales bb WHERE bb.transporter_growersid=tg.id)
          AND NOT EXISTS (SELECT 1 FROM sold_bales sb JOIN tickets tk ON tk.id=sb.ticketsid
                JOIN bale_junus bj ON bj.id=tk.bale_junusid WHERE bj.transporter_growersid=tg.id
                AND sb.created_at < '$ad')
        ORDER BY tg.sell_date DESC, g.grower_num");
    $hanging = ($hq && $hq !== true) ? $hq->fetch_all(MYSQLI_ASSOC) : [];
}
// ── Load transporters ──────────────────────────────────────────────────────
$transporters_q = $conn->query("
    SELECT t.id, t.name, t.id_num, t.truck_num, t.active,
           bl.code loc_code, bl.id loc_id,
           COUNT(DISTINCT tg.id) grower_count,
           COALESCE(SUM(CASE WHEN tg.status='active' THEN tg.bales ELSE 0 END),0) total_bales,
           COUNT(DISTINCT CASE WHEN tg.open_close=0 THEN tg.id END) open_notes,
           COUNT(DISTINCT CASE WHEN bb.id IS NOT NULL THEN tg.id END) balanced_count
    FROM transporter t
    LEFT JOIN bale_locations bl ON bl.id=t.location_id
    LEFT JOIN transporter_growers tg ON tg.transporterid=t.id AND ($spid=0 OR tg.selling_pointid=$spid)
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
    WHERE t.seasonid=$seasonid AND ($spid=0 OR t.selling_pointid=$spid)
    GROUP BY t.id
    ORDER BY t.datetime DESC, t.id DESC
");
$transporters = ($transporters_q&&$transporters_q!==true)?$transporters_q->fetch_all(MYSQLI_ASSOC):[];

include "includes/layout.php";
?>

<style>
.t-table tr.t-row{cursor:pointer;transition:background .15s}
.t-table tr.t-row:hover{background:rgba(200,149,42,.07)}
.t-table tr.t-row.selected{background:rgba(200,149,42,.12);outline:2px solid var(--gold)}
/* Modal */
#tg-modal{display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.55);overflow-y:auto;padding:2rem 1rem}
#tg-modal-box{background:var(--cream);border-radius:10px;max-width:900px;margin:0 auto;box-shadow:0 8px 40px rgba(0,0,0,.3);overflow:hidden}
.modal-head{background:var(--ink);color:var(--cream);padding:1rem 1.25rem;display:flex;align-items:center;gap:.75rem}
.modal-body{padding:1.25rem}
.tg-row{display:grid;grid-template-columns:90px 1fr 60px 100px 80px 80px auto;gap:.5rem;align-items:center;
        padding:.55rem .75rem;border-bottom:1px solid var(--border);font-size:.8rem}
.tg-row:hover{background:rgba(200,149,42,.05)}
.tg-row.tg-head{background:var(--ink);color:var(--cream);font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;border-radius:4px 4px 0 0}
.tg-row.balanced{opacity:.6}
.inline-form{display:inline-flex;gap:3px;align-items:center}
.tg-grower-wrap{border-bottom:1px solid var(--border)}
.action-panel{display:none;background:rgba(200,149,42,.06);border-top:1px dashed var(--gold);
              padding:.85rem 1rem;margin:0}
</style>

<div class="page-header">
  <div class="page-header-left"><h2>Transporters</h2><p>Manage truck delivery notes and grower assignments</p></div>
  <div style="display:flex;gap:.5rem">
    <button class="btn btn-ghost btn-sm" onclick="togglePanel('rollover-panel')">📅 Roll Over Growers<?= $hanging ? ' (' . count($hanging) . ')' : '' ?></button>
    <button class="btn btn-primary btn-sm" onclick="togglePanel('add-panel')">+ Add Transporter</button>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- Add transporter -->
<div id="add-panel" style="display:none" class="card mb-3">
<div class="card-head"><h3>Add Transporter</h3><button class="btn btn-ghost btn-sm" onclick="togglePanel('add-panel')">✕</button></div>
<div class="card-body">
<form method="POST" style="display:flex;gap:.65rem;flex-wrap:wrap;align-items:flex-end">
<input type="hidden" name="action" value="add_transporter">
<div class="form-group" style="flex:1;min-width:150px;margin:0"><label>Name *</label><input type="text" name="name" class="form-control" required></div>
<div class="form-group" style="width:130px;margin:0"><label>National ID</label><input type="text" name="id_num" class="form-control"></div>
<div class="form-group" style="width:120px;margin:0"><label>Truck # *</label><input type="text" name="truck_num" class="form-control" required></div>
<div class="form-group" style="width:140px;margin:0"><label>Location</label>
<select name="location_id" class="form-control"><option value="">None</option>
<?php foreach($locations_arr as $l):?><option value="<?=$l['id']?>"><?=htmlspecialchars($l['code'].' '.$l['name'])?></option><?php endforeach;?>
</select></div>
<button type="submit" class="btn btn-primary">Add</button>
</form>
</div></div>

<!-- Roll over hanging deliveries -->
<div id="rollover-panel" style="display:none;border-left:3px solid var(--gold)" class="card mb-3">
<div class="card-head"><h3>📅 Roll Over Hanging Deliveries</h3><button class="btn btn-ghost btn-sm" onclick="togglePanel('rollover-panel')">✕</button></div>
<div class="card-body">
<?php if (!$active_sod): ?>
  <p style="margin:0;color:var(--muted)">No selling day is active on this floor. Start the selling day in Day Control, then come back to roll deliveries over.</p>
<?php elseif (!$hanging): ?>
  <p style="margin:0;color:var(--muted)">No hanging deliveries: every delivery before <?= htmlspecialchars($active_sod['date']) ?> is balanced or was sold on its own day.</p>
<?php else: ?>
<p style="margin:0 0 .65rem;font-size:.8rem;color:var(--muted)">
  Deliveries from earlier selling days that were not balanced or sold on their day. Tick the growers to move to the active selling day,
  <strong><?= htmlspecialchars(date('D j M Y', strtotime($active_sod['date']))) ?></strong>. Their booking and tickets move with them, including bales already sold today.
  Deliveries with bales sold on an earlier day are not listed: balance those on their own day.
</p>
<form method="POST" onsubmit="var n=this.querySelectorAll('input[name=&quot;tgids[]&quot;]:checked').length; if(!n){alert('Tick at least one grower.');return false;} return confirm('Roll '+n+' delivery note(s) over to <?= htmlspecialchars($active_sod['date']) ?>?')">
<input type="hidden" name="action" value="rollover_selected">
<div class="table-wrap"><table style="width:100%;border-collapse:collapse;font-size:.8rem">
<thead><tr style="text-align:left;border-bottom:1px solid var(--border)">
  <th style="padding:.4rem"><input type="checkbox" title="Select all" onclick="var on=this.checked;this.closest('table').querySelectorAll('input[name=&quot;tgids[]&quot;]').forEach(function(c){c.checked=on;})"></th>
  <th style="padding:.4rem">Grower</th><th style="padding:.4rem">Name</th><th style="padding:.4rem">Transporter</th>
  <th style="padding:.4rem">Sell date</th><th style="padding:.4rem;text-align:right">Bales</th><th style="padding:.4rem;text-align:right">Tickets</th><th style="padding:.4rem;text-align:right">Sold today</th><th style="padding:.4rem">Note</th>
</tr></thead><tbody>
<?php foreach ($hanging as $h): ?>
<tr style="border-bottom:1px solid var(--border)">
  <td style="padding:.4rem"><input type="checkbox" name="tgids[]" value="<?= (int)$h['tgid'] ?>" id="ro-<?= (int)$h['tgid'] ?>"></td>
  <td style="padding:.4rem"><label for="ro-<?= (int)$h['tgid'] ?>" class="badge badge-gold" style="font-size:.6rem;cursor:pointer"><?= htmlspecialchars($h['grower_num']) ?></label></td>
  <td style="padding:.4rem"><?= htmlspecialchars(trim($h['name'] . ' ' . $h['surname'])) ?></td>
  <td style="padding:.4rem"><?= htmlspecialchars($h['transporter']) ?> <span style="color:var(--muted)"><?= htmlspecialchars($h['truck_num']) ?></span></td>
  <td style="padding:.4rem"><?= htmlspecialchars($h['sell_date']) ?></td>
  <td style="padding:.4rem;text-align:right;font-family:monospace"><?= (int)$h['bales'] ?></td>
  <td style="padding:.4rem;text-align:right;font-family:monospace"><?= (int)$h['tickets'] ?></td>
  <td style="padding:.4rem;text-align:right;font-family:monospace"><?= (int)$h['sold_today'] ?: '' ?></td>
  <td style="padding:.4rem"><?= (int)$h['open_close'] === 0 ? 'Open' : 'Closed' ?><?= $h['status'] !== 'active' ? ' · ' . htmlspecialchars(ucfirst($h['status'])) : '' ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<div style="margin-top:.75rem"><button type="submit" class="btn btn-primary">Roll Over Selected to <?= htmlspecialchars($active_sod['date']) ?></button></div>
</form>
<?php endif; ?>
</div></div>

<!-- ── TRANSPORTERS TABLE ────────────────────────────────────────────────── -->
<div class="card">
<div class="card-head"><h3>Transporters — <?=count($transporters)?></h3><span style="font-size:.72rem;color:var(--muted)">Click a row to manage delivery notes</span></div>
<div class="table-wrap"><table class="t-table" style="width:100%;border-collapse:collapse">
<thead style="background:var(--ink);color:var(--cream)">
<tr>
  <th style="padding:.55rem .75rem;font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;text-align:left">Location</th>
  <th style="padding:.55rem .75rem;font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;text-align:left">Name</th>
  <th style="padding:.55rem .75rem;font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;text-align:left">ID</th>
  <th style="padding:.55rem .75rem;font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;text-align:left">Truck</th>
  <th style="padding:.55rem .75rem;font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;text-align:right">Growers</th>
  <th style="padding:.55rem .75rem;font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;text-align:right">Bales</th>
  <th style="padding:.55rem .75rem;font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;text-align:center">Status</th>
  <th style="padding:.55rem .75rem;font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;text-align:center">Delivery Notes</th>
  <th style="padding:.55rem .75rem;font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;text-align:center">Action</th>
</tr>
</thead>
<tbody>
<?php if(empty($transporters)):?>
<tr><td colspan="9" style="padding:2.5rem;text-align:center;color:var(--muted)">No transporters yet. Add one above.</td></tr>
<?php else: foreach($transporters as $t): ?>
<tr class="t-row <?=$t['active']?'':'opacity-50'?>" onclick="openModal(<?=$t['id']?>,'<?=htmlspecialchars(addslashes($t['name']))?>')" style="border-bottom:1px solid var(--border)">
  <td style="padding:.55rem .75rem;font-family:'Bebas Neue',sans-serif;font-size:1.05rem;color:var(--gold)">
    <?=$t['loc_code']?htmlspecialchars($t['loc_code']):'<span style="color:var(--muted);font-size:.75rem;font-family:\'DM Sans\',sans-serif">—</span>'?>
  </td>
  <td style="padding:.55rem .75rem">
    <div style="font-weight:500;font-size:.85rem"><?=htmlspecialchars($t['name'])?></div>
  </td>
  <td style="padding:.55rem .75rem;font-size:.75rem;color:var(--muted)"><?=htmlspecialchars($t['id_num']??'—')?></td>
  <td style="padding:.55rem .75rem"><span class="badge badge-gray" style="font-size:.65rem"><?=htmlspecialchars($t['truck_num'])?></span></td>
  <td style="padding:.55rem .75rem;text-align:right;font-family:monospace"><?=(int)$t['grower_count']?></td>
  <td style="padding:.55rem .75rem;text-align:right;font-family:monospace;font-weight:600"><?=(int)$t['total_bales']?></td>
  <td style="padding:.55rem .75rem;text-align:center">
    <?=$t['active']
      ?'<span class="badge badge-green" style="font-size:.6rem">Active</span>'
      :'<span class="badge badge-red" style="font-size:.6rem">Inactive</span>'?>
  </td>
  <td style="padding:.55rem .75rem;text-align:center;font-size:.78rem">
    <span class="badge badge-leaf" style="font-size:.6rem"><?=(int)$t['open_notes']?> open</span>
    <?php if($t['balanced_count']>0):?>
    <span class="badge badge-gray" style="font-size:.6rem;margin-left:2px"><?=(int)$t['balanced_count']?> balanced</span>
    <?php endif;?>
  </td>
  <td style="padding:.55rem .75rem;text-align:center" onclick="event.stopPropagation()">
    <form method="POST" style="display:inline">
    <input type="hidden" name="action" value="toggle_transporter">
    <input type="hidden" name="tid" value="<?=$t['id']?>">
    <input type="hidden" name="active" value="<?=$t['active']?0:1?>">
    <button class="btn btn-ghost btn-sm" style="font-size:.65rem"><?=$t['active']?'Deactivate':'Activate'?></button>
    </form>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table></div>
</div>

<!-- ── MODAL ─────────────────────────────────────────────────────────────── -->
<div id="tg-modal" onclick="if(event.target===this)closeModal()">
<div id="tg-modal-box">

  <!-- Modal header -->
  <div class="modal-head">
    <div>
      <div style="font-family:'Bebas Neue',sans-serif;font-size:1.15rem;color:var(--gold);letter-spacing:.08em" id="modal-title">Transporter</div>
      <div style="font-size:.72rem;color:rgba(245,240,232,.55)" id="modal-sub">Delivery Notes</div>
    </div>
    <div style="margin-left:auto;display:flex;gap:.5rem;align-items:center">
      <button class="btn btn-ghost btn-sm" onclick="exportDeliveryNote()" style="color:var(--leaf);border-color:rgba(61,107,53,.4);display:inline-flex;align-items:center;gap:4px">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.9"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Save to File
      </button>
      <button class="btn btn-ghost btn-sm" onclick="showAddGrower()" style="color:var(--gold);border-color:rgba(200,149,42,.4)">+ Add Grower</button>
      <button onclick="closeModal()" style="background:none;border:none;color:rgba(245,240,232,.5);font-size:1.3rem;cursor:pointer;line-height:1">✕</button>
    </div>
  </div>

  <!-- Add grower form inside modal (AJAX) -->
  <div id="modal-add-form" style="display:none;padding:.85rem 1.25rem;background:rgba(200,149,42,.07);border-bottom:1px solid var(--border)">
  <div id="add-form-msg" style="font-size:.75rem;margin-bottom:.4rem;display:none"></div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
  <div class="form-group" style="flex:1;min-width:130px;margin:0"><label style="font-size:.7rem">Grower # *</label>
    <input type="text" id="ag-grower" class="form-control" placeholder="e.g. V101410" style="text-transform:uppercase"
           onkeydown="if(event.key==='Enter'){event.preventDefault();addGrower()}"></div>
  <div class="form-group" style="width:75px;margin:0"><label style="font-size:.7rem">Bales</label>
    <input type="number" id="ag-bales" class="form-control" min="0" value="0"></div>
  <div class="form-group" style="width:135px;margin:0"><label style="font-size:.7rem">Sell Date</label>
    <input type="date" id="ag-date" class="form-control" value="<?=$today?>"></div>
  <button class="btn btn-primary btn-sm" onclick="addGrower()">Assign</button>
  <button class="btn btn-ghost btn-sm" onclick="showAddGrower()">Cancel</button>
  </div>
  </div>

  <!-- Grower list body -->
  <div class="modal-body" style="padding:0">

    <!-- Column headers -->
    <div class="tg-row tg-head">
      <div>Grower #</div>
      <div>Name</div>
      <div style="text-align:right">Bales</div>
      <div>Sale Date</div>
      <div>Status</div>
      <div style="text-align:center">D-Note</div>
      <div></div>
    </div>

    <!-- Grower rows (loaded by JS) -->
    <div id="tg-rows">
      <div style="padding:2rem;text-align:center;color:var(--muted);font-size:.82rem">Loading...</div>
    </div>

  </div>
</div>
</div>



<script>
var currentTid = 0;
var locations  = <?=json_encode($locations_arr)?>;
var floors     = <?=json_encode($selling_points)?>;

function togglePanel(id) {
    var el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? '' : 'none';
}

function openModal(tid, name) {
    currentTid = tid;
    document.getElementById('tg-modal').style.display = 'block';
    document.getElementById('modal-title').textContent = name;
    document.body.style.overflow = 'hidden';
    loadGrowers(tid);
}

function closeModal() {
    document.getElementById('tg-modal').style.display = 'none';
    document.body.style.overflow = '';
    currentTid = 0;
}

function showAddGrower() {
    var f = document.getElementById('modal-add-form');
    f.style.display = f.style.display === 'none' ? '' : 'none';
}

async function loadGrowers(tid) {
    document.getElementById('tg-rows').innerHTML =
        '<div style="padding:2rem;text-align:center;color:var(--muted);font-size:.82rem">Loading...</div>';
    try {
        var r    = await fetch('transporters.php?ajax=tg_list&tid=' + encodeURIComponent(tid),
                               {headers:{'X-Requested-With':'XMLHttpRequest'}});
        var data = await r.json();
        renderGrowers(data);
    } catch(e) {
        document.getElementById('tg-rows').innerHTML =
            '<div style="padding:2rem;text-align:center;color:var(--danger)">Failed to load growers: '+e.message+'</div>';
    }
}

async function addGrower() {
    var gnum  = document.getElementById('ag-grower').value.trim().toUpperCase();
    var bales = document.getElementById('ag-bales').value;
    var date  = document.getElementById('ag-date').value;
    var msgEl = document.getElementById('add-form-msg');

    function showMsg(text, ok) {
        msgEl.style.color   = ok ? 'var(--leaf)' : 'var(--danger)';
        msgEl.style.display = 'block';
        msgEl.textContent   = text;
    }

    if (!gnum)        { showMsg('Enter a grower number.', false); return; }
    if (!date)        { showMsg('Select a sell date.', false); return; }
    if (!currentTid)  { showMsg('No transporter selected.', false); return; }

    showMsg('Saving…', true);

    var fd = new FormData();
    fd.append('transporterid', currentTid);
    fd.append('grower_num',    gnum);
    fd.append('bales',         bales);
    fd.append('sell_date',     date);

    try {
        var r    = await fetch('transporters.php?ajax=add_tg', {
            method:  'POST',
            body:    fd,
            headers: {'X-Requested-With':'XMLHttpRequest'}
        });
        if (!r.ok) { showMsg('Server error (HTTP ' + r.status + '). Check PHP logs.', false); return; }
        var data = await r.json();
        if (data.error) {
            showMsg(data.error, false);
        } else {
            showMsg('✓ ' + data.msg, true);
            document.getElementById('ag-grower').value = '';
            document.getElementById('ag-bales').value  = '0';
            document.getElementById('ag-grower').focus();
            loadGrowers(currentTid);
        }
    } catch(e) {
        showMsg('Network error: ' + e.message, false);
    }
}

function renderGrowers(rows) {
    var container = document.getElementById('tg-rows');
    if (!rows || rows.length === 0) {
        container.innerHTML = '<div style="padding:2rem;text-align:center;color:var(--muted)">No growers assigned. Click "+ Add Grower".</div>';
        return;
    }

    var html = '';
    rows.forEach(function(r) {
        var balanced = parseInt(r.is_balanced) === 1;
        var stBadge  = {active:'badge-green', suspended:'badge-gold', blocked:'badge-red'}[r.status] || 'badge-gray';
        var sold     = parseInt(r.sold_count);
        var junus    = parseInt(r.junus_count);
        var balesNum = parseInt(r.bales);
        var isOpen   = r.open_close==='0' || r.open_close===0 || r.open_close==='';
        var dnBadge  = isOpen ? 'badge-green' : 'badge-red';
        var dnLabel  = isOpen ? 'Open' : 'Closed';

        // Each grower wrapped in a block div (row + action panel together)
        html += '<div class="tg-grower-wrap">';

        // The row itself
        html += '<div class="tg-row' + (balanced?' balanced':'') + '" id="tgrow-'+r.tgid+'">';
        html += '<div><span class="badge badge-gold" style="font-size:.6rem">'+esc(r.grower_num)+'</span></div>';
        html += '<div style="font-size:.78rem">'+esc(r.name+' '+r.surname)+
            '<div style="font-size:.65rem;color:var(--muted)">'+
            (junus>0?junus+' junused':'')+
            (sold>0?' · '+sold+' sold':'')+
            '</div></div>';
        html += '<div style="text-align:right;font-family:monospace;font-weight:600">'+balesNum+'</div>';
        html += '<div style="font-size:.72rem">'+(r.sell_date||'—')+'</div>';
        html += '<div><span class="badge '+stBadge+'" style="font-size:.58rem">'+r.status.toUpperCase()+'</span></div>';
        html += '<div style="text-align:center"><span class="badge '+dnBadge+'" style="font-size:.58rem">'+dnLabel+'</span></div>';
        if (!balanced) {
        rowDataStore[r.tgid] = r;
        html += '<div><button class="btn btn-ghost btn-sm" style="font-size:.65rem" onclick="toggleActions('+r.tgid+')">Edit ▾</button></div>';
        } else {
            html += '<div><span style="font-size:.65rem;color:var(--muted)">Balanced</span></div>';
        }
        html += '</div>'; // end tg-row

        // Action panel sits inside the wrapper, below the row
        html += '<div class="action-panel" id="ap-'+r.tgid+'">';
        html += buildActionPanel(r);
        html += '</div>';

        html += '</div>'; // end tg-grower-wrap
    });

    document.getElementById('tg-rows').innerHTML = html;
}

function buildActionPanel(r) {
    var locOpts = '<option value="">— unchanged —</option>';
    locations.forEach(function(l){ locOpts+='<option value="'+l.id+'">'+l.code+' '+l.name+'</option>'; });
    var floorOpts = '<option value="">— unchanged —</option>';
    floors.forEach(function(f){ floorOpts+='<option value="'+f.id+'">'+f.name+'</option>'; });

    return '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.6rem;align-items:flex-end">' +
        '<div class="form-group" style="margin:0"><label style="font-size:.65rem">Sale Date</label>'+
        '<input type="date" id="ap-date-'+r.tgid+'" class="form-control" value="'+(r.sell_date||'')+'" style="font-size:.78rem"></div>'+

        '<div class="form-group" style="margin:0"><label style="font-size:.65rem">Bale Count</label>'+
        '<input type="number" id="ap-bales-'+r.tgid+'" class="form-control" value="'+r.bales+'" min="0" style="font-size:.78rem"></div>'+

        '<div class="form-group" style="margin:0"><label style="font-size:.65rem">Status</label>'+
        '<select id="ap-status-'+r.tgid+'" class="form-control" style="font-size:.78rem">'+
        '<option value="active"'+(r.status==='active'?' selected':'')+'>Active</option>'+
        '<option value="suspended"'+(r.status==='suspended'?' selected':'')+'>Suspended</option>'+
        '<option value="blocked"'+(r.status==='blocked'?' selected':'')+'>Blocked</option>'+
        '</select></div>'+

        '<div class="form-group" style="margin:0"><label style="font-size:.65rem">Location</label>'+
        '<select id="ap-loc-'+r.tgid+'" class="form-control" style="font-size:.78rem">'+locOpts+'</select></div>'+

        '<div class="form-group" style="margin:0"><label style="font-size:.65rem">Move to Floor</label>'+
        '<select id="ap-spid-'+r.tgid+'" class="form-control" style="font-size:.78rem">'+floorOpts+'</select></div>'+

        '<div style="display:flex;gap:.35rem;align-items:flex-end">'+
        '<button class="btn btn-primary btn-sm" style="font-size:.7rem" onclick="saveActions('+r.tgid+')">✓ Save</button>'+
        '<button class="btn btn-ghost btn-sm" style="font-size:.7rem" onclick="toggleTg('+r.tgid+','+(r.open_close==='0'||r.open_close===0?1:0)+')">'+(r.open_close==='0'||r.open_close===0?'Close Note':'Open Note')+'</button>'+
        '</div>'+
        '</div>';
}

var rowDataStore = {};

function toggleActions(tgid, rowJson) {
    // Store row data
    try { rowDataStore[tgid] = JSON.parse(rowJson); } catch(e) {}
    // Close all other panels
    document.querySelectorAll('.action-panel').forEach(function(p){ p.style.display='none'; });
    var ap = document.getElementById('ap-'+tgid);
    if (ap) {
        if (ap.style.display === 'block') {
            ap.style.display = 'none';
        } else {
            ap.style.display = 'block';
            // If empty, build content now
            if (!ap.innerHTML.trim() || ap.innerHTML.trim() === '') {
                ap.innerHTML = buildActionPanel(rowDataStore[tgid] || {});
            }
        }
    }
}

async function saveActions(tgid) {
    var fd = new FormData();
    fd.append('tgid',           tgid);
    fd.append('new_bales',      document.getElementById('ap-bales-'+tgid).value);
    fd.append('new_date',       document.getElementById('ap-date-'+tgid).value);
    fd.append('new_status',     document.getElementById('ap-status-'+tgid).value);
    fd.append('new_location_id',document.getElementById('ap-loc-'+tgid).value);
    fd.append('new_spid',       document.getElementById('ap-spid-'+tgid).value);
    try {
        var r    = await fetch('transporters.php?ajax=update_tg', {method:'POST', body:fd,
                               headers:{'X-Requested-With':'XMLHttpRequest'}});
        var data = await r.json();
        if (data.ok) {
            tpToast(data.msg || 'Delivery note updated.', 'success');
            var ap = document.getElementById('ap-'+tgid);
            if (ap) ap.style.display = 'none';
            loadGrowers(currentTid);
        } else {
            tpToast(data.error || 'Update failed.', 'error');
        }
    } catch(e) { tpToast('Error: '+e.message, 'error'); }
}

async function toggleTg(tgid, newOc) {
    var fd = new FormData();
    fd.append('tgid', tgid);
    fd.append('open_close', newOc);
    try {
        var r    = await fetch('transporters.php?ajax=toggle_tg', {method:'POST', body:fd,
                               headers:{'X-Requested-With':'XMLHttpRequest'}});
        var data = await r.json();
        if (data.ok) { loadGrowers(currentTid); }
        else { tpToast(data.error || 'Toggle failed.', 'error'); }
    } catch(e) { tpToast('Error: '+e.message, 'error'); }
}

function tpToast(msg, type) {
    // Use global fw.toast if available, otherwise inline toast
    if (typeof fw !== 'undefined' && fw.toast) { fw.toast(msg, type); return; }
    var t = document.createElement('div');
    t.textContent = msg;
    var bg = type === 'error' ? '#c0392b' : '#3d6b35';
    t.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:99999;padding:.65rem 1.1rem;'
        + 'border-radius:6px;font-size:.82rem;color:#fff;background:'+bg
        + ';box-shadow:0 4px 16px rgba(0,0,0,.3);font-family:\'DM Sans\',sans-serif';
    document.body.appendChild(t);
    setTimeout(function(){ t.remove(); }, 3500);
}

function esc(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function exportDeliveryNote() {
    if (!currentTid) {
        tpToast('No transporter selected.', 'error');
        return;
    }
    window.location.href = 'transporters.php?ajax=export_tg&tid=' + currentTid;
}

// Keyboard close
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });
</script>

<?php include "includes/layout_end.php"; ?>
