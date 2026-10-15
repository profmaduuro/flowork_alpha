<?php
/**
 * Dispatch — real DB schema
 * selling_point_dispatch: (userid, buyerid, destination, datetime) — no created_at!
 * dispatch_destination: (userid, selling_point_dispatchid, driver_name, driver_surname,
 *   driver_id_number, horse_num, trailer_num, open_close, created_at)
 * dispatch: (userid, dispatch_destinationid, sold_baleid, created_at)
 */
$user   = requireAuth();
requireRight($user, 'dispatch');
$spid   = getSpid($user);
$db     = getDB();
$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];
$uid    = (int)$user['id'];

// ── LIST ─────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $date = $db->real_escape_string($_GET['date'] ?? date('Y-m-d'));
    $r = $db->query("
        SELECT dd.id, dd.driver_name, dd.driver_surname, dd.driver_id_number,
               dd.horse_num, dd.trailer_num, dd.open_close, dd.created_at,
               spd.destination, b.name buyer_name, b.id buyer_id,
               COUNT(DISTINCT d.id) bale_count,
               COALESCE(SUM(bm.mass),0) total_mass
        FROM dispatch_destination dd
        JOIN selling_point_dispatch spd ON spd.id=dd.selling_point_dispatchid
        JOIN buyer b ON b.id=spd.buyerid
        LEFT JOIN dispatch d ON d.dispatch_destinationid=dd.id
        LEFT JOIN sold_bales sb ON sb.id=d.sold_baleid
        LEFT JOIN tickets tk ON tk.id=sb.ticketsid
        LEFT JOIN bale_junus bj ON bj.id=tk.bale_junusid
        LEFT JOIN bale_mass bm ON bm.id=bj.bale_massid
        WHERE b.selling_pointid=$spid AND DATE(dd.created_at)='$date'
        GROUP BY dd.id ORDER BY dd.created_at DESC
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

// ── CREATE TRUCK ─────────────────────────────────────────────────────────────
if ($action === 'create' && $method === 'POST') {
    $b    = body();
    need($b, ['buyer_id', 'destination', 'driver_name', 'horse_num']);
    $bid  = (int)$b['buyer_id'];
    $dest = $db->real_escape_string(trim($b['destination']));
    $dn   = $db->real_escape_string(trim($b['driver_name']));
    $ds   = $db->real_escape_string(trim($b['driver_surname'] ?? ''));
    $did  = $db->real_escape_string(trim($b['driver_id_number'] ?? ''));
    $hn   = $db->real_escape_string(trim($b['horse_num']));
    $tn   = $db->real_escape_string(trim($b['trailer_num'] ?? ''));
    // Find or create selling_point_dispatch — real schema has 'datetime' column
    $spd_q = $db->query("SELECT id FROM selling_point_dispatch WHERE buyerid=$bid AND destination='$dest' LIMIT 1");
    if ($spd_q && $spd_q->num_rows > 0) {
        $spd_id = (int)$spd_q->fetch_assoc()['id'];
    } else {
        $db->query("INSERT INTO selling_point_dispatch (userid,buyerid,destination) VALUES ($uid,$bid,'$dest')");
        if ($db->error) err('Failed to create dispatch point: ' . $db->error);
        $spd_id = (int)$db->insert_id;
    }
    $db->query("INSERT INTO dispatch_destination
        (userid,selling_point_dispatchid,driver_name,driver_surname,driver_id_number,horse_num,trailer_num,open_close)
        VALUES ($uid,$spd_id,'$dn','$ds','$did','$hn','$tn',1)");
    if ($db->error) err('Failed: ' . $db->error);
    ok(['id'=>(int)$db->insert_id,'msg'=>"Truck $hn created."]);
}

// ── ADD BALE ──────────────────────────────────────────────────────────────────
if ($action === 'add_bale' && $method === 'POST') {
    $b       = body();
    need($b, ['truck_id', 'barcode']);
    $dest_id = (int)$b['truck_id'];
    $bc      = $db->real_escape_string(trim($b['barcode']));
    $sb_q    = $db->query("SELECT sb.id FROM sold_bales sb
        JOIN tickets t ON t.id=sb.ticketsid WHERE t.barcode='$bc' LIMIT 1");
    if (!$sb_q || !$sb_q->num_rows) err("Barcode '$bc' not sold.");
    $sb_id = (int)$sb_q->fetch_assoc()['id'];
    $dup = $db->query("SELECT id FROM dispatch WHERE sold_baleid=$sb_id LIMIT 1");
    if ($dup && $dup->num_rows > 0) err("Bale '$bc' already dispatched.");
    $db->query("INSERT INTO dispatch (userid,dispatch_destinationid,sold_baleid) VALUES ($uid,$dest_id,$sb_id)");
    if ($db->error) err('Failed: ' . $db->error);
    $info = $db->query("SELECT sb.price, bm.mass, bm.temp_bacode timb_bc,
        g.grower_num, g.name grower_name, tgd.description timb_grade
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        LEFT JOIN timb_grades tgd ON tgd.id=sb.timb_gradesid
        WHERE sb.id=$sb_id LIMIT 1");
    ok(array_merge(
        ['dispatch_id'=>(int)$db->insert_id,'barcode'=>$bc],
        ($info && $info->num_rows > 0) ? $info->fetch_assoc() : []
    ));
}

// ── BALES IN TRUCK ────────────────────────────────────────────────────────────
if ($action === 'bales') {
    $dest_id = (int)($_GET['truck_id'] ?? 0);
    if (!$dest_id) err('truck_id required');
    $r = $db->query("
        SELECT d.id dispatch_id, d.created_at dispatched_at,
               tk.barcode, bm.mass, bm.temp_bacode timb_bc,
               g.grower_num, g.name grower_name, bj.lot, sb.price,
               tgd.description timb_grade, bgd.description buyer_grade
        FROM dispatch d
        JOIN sold_bales sb ON sb.id=d.sold_baleid
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        LEFT JOIN timb_grades tgd ON tgd.id=sb.timb_gradesid
        LEFT JOIN buyer_grades bgd ON bgd.id=sb.buyer_gradesid
        WHERE d.dispatch_destinationid=$dest_id ORDER BY d.id ASC
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

// ── FINALISE ──────────────────────────────────────────────────────────────────
if ($action === 'finalise' && $method === 'POST') {
    $b       = body();
    $dest_id = (int)($b['truck_id'] ?? 0);
    if (!$dest_id) err('truck_id required');
    $db->query("UPDATE dispatch_destination SET open_close=0 WHERE id=$dest_id");
    $cnt = $db->query("SELECT COUNT(*) c FROM dispatch WHERE dispatch_destinationid=$dest_id");
    $n   = ($cnt && $cnt !== true) ? (int)$cnt->fetch_assoc()['c'] : 0;
    ok(['msg'=>"Dispatch finalised — $n bales.",'bale_count'=>$n]);
}

// ── BUYERS ────────────────────────────────────────────────────────────────────
if ($action === 'buyers') {
    $r = $db->query("SELECT id,name,buyer_code FROM buyer WHERE selling_pointid=$spid ORDER BY name");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

err('Unknown action');
