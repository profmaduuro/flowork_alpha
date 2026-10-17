<?php
/**
 * Growers — mirrors web grower_registry.php + growers.php
 */
$user     = requireAuth();
requireRight($user, 'growers');
$spid     = getSpid($user);
$db       = getDB();
$action   = $_GET['action'] ?? 'list';
$method   = $_SERVER['REQUEST_METHOD'];
$uid      = (int)$user['id'];

// ── LIST ─────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $q     = $db->real_escape_string(trim($_GET['q'] ?? ''));
    $where = $spid > 0 ? "g.selling_pointid=$spid" : "g.selling_pointid >= 0";
    if ($q) $where .= " AND (g.grower_num LIKE '%$q%' OR g.name LIKE '%$q%'
                           OR g.surname LIKE '%$q%' OR g.national_id LIKE '%$q%')";
    $r = $db->query("
        SELECT g.id, g.grower_num, g.name, g.surname, g.phone, g.national_id,
               g.farm_name, g.province, g.district, g.contractor, g.active,
               g.address1, g.dry_land_ha, g.irr_land_ha, g.selling_pointid,
               (SELECT bl.code
                FROM transporter_growers tg
                JOIN transporter t ON t.id=tg.transporterid
                LEFT JOIN bale_locations bl ON bl.id=t.location_id
                WHERE tg.growerid=g.id ORDER BY tg.id DESC LIMIT 1) loc_code
        FROM growers g
        WHERE $where ORDER BY g.grower_num LIMIT 500
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

// ── REGISTER — mirrors web grower_registry.php add action ─────────────────────
if ($action === 'register' && $method === 'POST') {
    $b    = body();
    need($b, ['grower_num', 'name', 'surname']);
    $fields = ['grower_num','name','surname','national_id','phone',
               'address1','address2','contractor','farm_name','province',
               'district','dry_land_ha','irr_land_ha','dryland'];
    $d = [];
    foreach ($fields as $f) {
        $d[$f] = $db->real_escape_string(trim($b[$f] ?? ''));
    }
    // Check duplicate (same as web)
    $dup = $db->query("SELECT id FROM growers WHERE grower_num='{$d['grower_num']}'
        AND selling_pointid=$spid LIMIT 1");
    if ($dup && $dup->num_rows > 0) err("Grower number {$d['grower_num']} already exists.");
    $now = date('Y-m-d H:i:s');
    $db->query("INSERT INTO growers
        (userid, selling_pointid, grower_num, name, surname, national_id, phone,
         address1, address2, contractor, farm_name, province, district,
         dry_land_ha, irr_land_ha, dryland, created_at)
        VALUES ($uid, $spid,
         '{$d['grower_num']}', '{$d['name']}', '{$d['surname']}', '{$d['national_id']}',
         '{$d['phone']}', '{$d['address1']}', '{$d['address2']}', '{$d['contractor']}',
         '{$d['farm_name']}', '{$d['province']}', '{$d['district']}',
         '{$d['dry_land_ha']}', '{$d['irr_land_ha']}', '{$d['dryland']}', '$now')");
    if ($db->error) err('DB error: ' . $db->error);
    ok(['id'=>(int)$db->insert_id,'msg'=>"Grower {$d['grower_num']} — {$d['name']} {$d['surname']} registered."]);
}

// ── STATUS — delivery + stop order summary ────────────────────────────────────
if ($action === 'status') {
    $gid = (int)($_GET['grower_id'] ?? 0);
    if (!$gid) err('grower_id required');
    // daily_totals_net_amount can carry multiple recalculation rows per
    // balanced_balesid (each rerun inserts rather than updates) — join only
    // the latest row per id so deliveries/KPIs aren't multiplied.
    $tg_q = $db->query("
        SELECT tg.id tgid, tg.booked, tg.bales, tg.sell_date,
               t.name transporter, t.truck_num, bl.code location_code,
               CASE WHEN bb.id IS NOT NULL THEN 1 ELSE 0 END balanced,
               bb.id bbid,
               COALESCE(dt.sold_bales,0) sold_bales,
               COALESCE(dt.mass,0) mass,
               COALESCE(dt.usd_value,0) gross_usd,
               COALESCE(dtn.usd_net_value,0) net_usd,
               COALESCE(dtn.zim_net_value,0) net_zig,
               COALESCE(ps.status,'pending') pay_status
        FROM transporter_growers tg
        JOIN transporter t ON t.id=tg.transporterid
        LEFT JOIN bale_locations bl ON bl.id=t.location_id
        LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
        LEFT JOIN daily_totals dt ON dt.id = (
            SELECT d2.id FROM daily_totals d2
            WHERE d2.balanced_balesid=bb.id ORDER BY d2.id DESC LIMIT 1
        )
        LEFT JOIN daily_totals_net_amount dtn ON dtn.id = (
            SELECT d3.id FROM daily_totals_net_amount d3
            WHERE d3.balanced_balesid=bb.id ORDER BY d3.id DESC LIMIT 1
        )
        LEFT JOIN payment_status ps ON ps.transporter_growersid=tg.id AND ps.currency='usd'
        WHERE tg.growerid=$gid ORDER BY tg.id DESC
    ");
    $deliveries = ($tg_q && $tg_q !== true) ? $tg_q->fetch_all(MYSQLI_ASSOC) : [];

    // Aggregate KPIs across all of the grower's deliveries.
    // A single delivery (tgid) can be balanced across several batches, so
    // tg.bales is repeated per batch row — count it once per tgid. Mass and
    // net are per-batch amounts and sum correctly across all rows.
    $total_bales = 0; $total_mass = 0.0; $net_usd = 0.0; $seenTg = [];
    foreach ($deliveries as $d) {
        if (!isset($seenTg[$d['tgid']])) {
            $seenTg[$d['tgid']] = true;
            $total_bales += (int)$d['bales'];
        }
        $total_mass += (float)$d['mass'];
        $net_usd    += (float)$d['net_usd'];
    }

    $so_q = $db->query("SELECT isoa.amount, iso.description, iso.creditor_number,
        isoa.processed, isoa.priority
        FROM internal_stop_order_amount isoa
        JOIN internal_stop_order iso ON iso.id=isoa.internal_stop_orderid
        WHERE isoa.growerid=$gid AND isoa.active=1 ORDER BY isoa.priority");
    $stoporders = ($so_q && $so_q !== true) ? $so_q->fetch_all(MYSQLI_ASSOC) : [];

    // Grower profile — supplies the status field. Location (A/B/C) is the
    // bale_locations code of the transporter that handled the most recent
    // delivery, not a geographic place or the selling point itself.
    $gp   = $db->query("SELECT active FROM growers WHERE id=$gid LIMIT 1");
    $prof = ($gp && $gp !== true && $gp->num_rows) ? $gp->fetch_assoc() : [];
    $location = trim($deliveries[0]['location_code'] ?? '');

    ok([
        'deliveries'       => $deliveries,
        'stoporders'       => $stoporders,
        'total_bales'      => $total_bales,
        'total_mass'       => $total_mass,
        'net_usd'          => $net_usd,
        'stoporder_count'  => count($stoporders),
        'transporter_name' => $deliveries[0]['transporter'] ?? '',
        'location'         => $location,
        'status'           => (isset($prof['active']) && (int)$prof['active'] === 0) ? 'inactive' : 'active',
    ]);
}

function upsertBookedBales($db, $uid, $growerid, $bales, $sellDate, $bookDate) {
    $gid = (int)$growerid;
    $bl = (int)$bales;
    $sd = $db->real_escape_string($sellDate);
    $bd = $db->real_escape_string($bookDate);
    $ex = $db->query("SELECT id FROM booked_bales WHERE growerid=$gid AND sell_date='$sd' LIMIT 1");
    if ($ex && $ex !== true && $ex->num_rows > 0) {
        $eid = (int)$ex->fetch_assoc()['id'];
        $db->query("UPDATE booked_bales SET bales_booked='$bl', userid=$uid WHERE id=$eid");
        return $eid;
    }
    $db->query("INSERT INTO booked_bales
        (userid,growerid,book_date,comm_graded,bales_booked,bales_delivered,
         bales_handled,sell_date,book_user,ip_address,floor,sale,
         booked_id,reoffer,booked_by,prefered_rep,last_sell,prefered_time,venue)
        VALUES ($uid,$gid,'$bd','0','$bl','0','0','$sd','$uid','','','','','0','','','','','')");
    return (int)$db->insert_id;
}

if ($action === 'booking_list') {
    $date = $db->real_escape_string($_GET['sell_date'] ?? '');
    $dc = $date ? "AND tg.sell_date='$date'" : '';
    $r = $db->query("
        SELECT tg.id tgid, tg.booked, tg.bales, tg.sell_date,
               g.id growerid, g.grower_num, g.name, g.surname,
               t.id transporterid, t.name transporter, t.truck_num,
               bb.id bb_id,
               COUNT(DISTINCT bj.id) bale_count,
               COUNT(DISTINCT sb.id) sold_count
        FROM transporter_growers tg
        JOIN growers g ON g.id=tg.growerid
        JOIN transporter t ON t.id=tg.transporterid
        LEFT JOIN booked_bales bb ON bb.growerid=g.id AND bb.sell_date=COALESCE(tg.sell_date, CURDATE())
        LEFT JOIN balanced_bales bal ON bal.transporter_growersid=tg.id
        LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
        LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        WHERE ($spid=0 OR g.selling_pointid=$spid OR tg.selling_pointid=$spid) $dc
        GROUP BY tg.id
        ORDER BY tg.booked DESC, bal.id IS NOT NULL ASC, g.grower_num ASC
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

if ($action === 'book_tg' && $method === 'POST') {
    $b = body();
    $tgid = (int)($b['tgid'] ?? 0);
    if (!$tgid) err('tgid required');
    requireTgOnFloor($db, $user, $tgid);
    $tg = $db->query("SELECT growerid,bales,COALESCE(sell_date, CURDATE()) sell_date FROM transporter_growers WHERE id=$tgid LIMIT 1");
    if (!$tg || !$tg->num_rows) err('Delivery note not found');
    $row = $tg->fetch_assoc();
    $db->query("UPDATE transporter_growers SET booked=1 WHERE id=$tgid");
    upsertBookedBales($db, $uid, $row['growerid'], $row['bales'], $row['sell_date'], date('Y-m-d'));
    ok(['booked'=>true], 'Grower booked.');
}

if ($action === 'unbook_tg' && $method === 'POST') {
    $b = body();
    $tgid = (int)($b['tgid'] ?? 0);
    if (!$tgid) err('tgid required');
    requireTgOnFloor($db, $user, $tgid);
    $bb = $db->query("SELECT id FROM balanced_bales WHERE transporter_growersid=$tgid LIMIT 1");
    if ($bb && $bb->num_rows > 0) err('Already balanced - cannot unbook');
    $db->query("UPDATE transporter_growers SET booked=0 WHERE id=$tgid");
    ok(['booked'=>false], 'Grower unbooked.');
}

if ($action === 'book_for_date' && $method === 'POST') {
    $b = body();
    $sellDate = $db->real_escape_string($b['sell_date'] ?? date('Y-m-d'));
    $r = $db->query("SELECT tg.id tgid,tg.growerid,tg.bales
        FROM transporter_growers tg
        JOIN growers g ON g.id=tg.growerid
        LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
        WHERE ($spid=0 OR g.selling_pointid=$spid OR tg.selling_pointid=$spid) AND bb.id IS NULL");
    $n = 0;
    while ($r && $row = $r->fetch_assoc()) {
        $db->query("UPDATE transporter_growers SET booked=1,sell_date='$sellDate' WHERE id={$row['tgid']}");
        upsertBookedBales($db, $uid, $row['growerid'], $row['bales'], $sellDate, date('Y-m-d'));
        $n++;
    }
    ok(['booked'=>$n,'sell_date'=>$sellDate], "$n growers booked for $sellDate.");
}

err('Unknown action');
