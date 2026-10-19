<?php
/**
 * Transporter — exact real DB schema
 * transporter: (userid, seasonid, name, id_num, truck_num, location[varchar], active, open_close, created_at, location_id)
 * NOTE: no 'active' or 'selling_pointid' filter on list — use seasonid
 * transporter_growers: (userid, transporterid, growerid, selling_pointid, bales, junused_bales,
 *                       open_close, created_at, datetime, locationid, splitid, booked, sell_date, status)
 */
$user     = requireAuth();
$spid     = getSpid($user);
$db       = getDB();
$action   = $_GET['action'] ?? 'list';
$method   = $_SERVER['REQUEST_METHOD'];
$uid      = (int)$user['id'];
$seasonid = getSeasonid($user);

// ── LIST ─────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $q     = $db->real_escape_string(trim($_GET['q'] ?? ''));
    // Filter by seasonid; if spid set, also filter via transporter_growers.selling_pointid
    $where = "t.seasonid = $seasonid AND ($spid = 0 OR t.selling_pointid = $spid)";
    if ($q) $where .= " AND (t.name LIKE '%$q%' OR t.truck_num LIKE '%$q%' OR t.id_num LIKE '%$q%')";
    $r = $db->query("
        SELECT t.id, t.name, t.id_num, t.truck_num, COALESCE(bl.code, t.location) loc_code, t.active,
               t.location_id, bl.name loc_name,
               COUNT(DISTINCT tg.id)                                              grower_count,
               COALESCE(SUM(CASE WHEN tg.status='active' THEN tg.bales ELSE 0 END), 0) total_bales,
               COUNT(DISTINCT CASE WHEN tg.open_close=0 THEN tg.id END)          open_notes,
               COUNT(DISTINCT CASE WHEN bb.id IS NOT NULL THEN tg.id END)         balanced_count
        FROM transporter t
        LEFT JOIN bale_locations bl ON bl.id = t.location_id
        LEFT JOIN transporter_growers tg ON tg.transporterid = t.id
            AND ($spid = 0 OR tg.selling_pointid = $spid)
        LEFT JOIN balanced_bales bb ON bb.transporter_growersid = tg.id
        WHERE $where
        GROUP BY t.id
        ORDER BY t.active DESC, t.location, t.name
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

// ── CREATE ────────────────────────────────────────────────────────────────────
if ($action === 'create' && $method === 'POST') {
    $b       = body();
    need($b, ['name']);
    $name    = $db->real_escape_string(trim($b['name']));
    $truck   = $db->real_escape_string(trim($b['truck_num'] ?? ''));
    $id_num  = $db->real_escape_string(trim($b['id_num'] ?? ''));
    $loc     = $db->real_escape_string(trim($b['location'] ?? ''));
    $loc_id  = (int)($b['location_id'] ?? 0);
    $loc_val = $loc_id > 0 ? $loc_id : 'NULL';
    $now     = date('Y-m-d H:i:s');
    if (!$name) err('Name is required');
    // Exact columns from real DB schema
    $db->query("INSERT INTO transporter
        (userid, seasonid, selling_pointid, name, id_num, truck_num, location, location_id, created_at)
        VALUES ($uid, $seasonid, $spid, '$name', '$id_num', '$truck', '$loc', $loc_val, '$now')");
    if ($db->error) err('DB error: ' . $db->error);
    $new_id = (int)$db->insert_id;
    ok(['id'=>$new_id,'name'=>$name,'truck_num'=>$truck]);
}

// ── GET single + delivery notes ───────────────────────────────────────────────
if ($action === 'get') {
    $tid = (int)($_GET['id'] ?? 0);
    if (!$tid) err('id required');
    $t = $db->query("SELECT t.*, bl.code bl_code, bl.name bl_name
        FROM transporter t LEFT JOIN bale_locations bl ON bl.id=t.location_id
        WHERE t.id=$tid AND t.seasonid=$seasonid AND ($spid=0 OR t.selling_pointid=$spid) LIMIT 1");
    if (!$t || !$t->num_rows) err('Transporter not found');
    $tp = $t->fetch_assoc();
    // Exact tg_list subqueries from web transporters.php AJAX tg_list
    $rows = $db->query("
        SELECT tg.id tgid, tg.bales, tg.junused_bales,
               COALESCE(tg.status,'active') status,
               tg.open_close, tg.booked,
               COALESCE(tg.sell_date,'') sell_date,
               COALESCE(tg.locationid, 0) locationid,
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
        JOIN growers g ON g.id = tg.growerid
        LEFT JOIN bale_locations bl ON bl.id=tg.locationid
        WHERE tg.transporterid = $tid
          AND ($spid = 0 OR tg.selling_pointid = $spid)
        ORDER BY g.grower_num
    ");
    $growers = [];
    if ($rows && $rows !== true) {
        while ($row = $rows->fetch_assoc()) {
            $row['is_balanced'] = (int)$row['is_balanced'] > 0 ? 1 : 0;
            $growers[] = $row;
        }
    }
    $tp['growers'] = $growers;
    ok($tp);
}

// ── ADD GROWER ────────────────────────────────────────────────────────────────
if ($action === 'add_grower' && $method === 'POST') {
    $b     = body();
    need($b, ['transporterid', 'grower_num']);
    $tid   = (int)$b['transporterid'];
    $own   = $db->query("SELECT 1 FROM transporter WHERE id=$tid AND ($spid=0 OR selling_pointid=$spid) LIMIT 1");
    if (!$own || $own === true || !$own->num_rows) err('Transporter not found on this floor', 404);
    $gnum  = $db->real_escape_string(strtoupper(trim($b['grower_num'])));
    $bales = (int)($b['bales'] ?? 0);
    $sdate = $db->real_escape_string($b['sell_date'] ?? date('Y-m-d'));
    $locid = (int)($b['location_id'] ?? $b['locationid'] ?? 0);
    $loc_col = $locid > 0 ? ', locationid' : '';
    $loc_val = $locid > 0 ? ", $locid" : '';
    $now   = date('Y-m-d H:i:s');
    // Same grower lookup as web transporters.php
    $gr = $db->query("SELECT id FROM growers
        WHERE grower_num='$gnum' AND (selling_pointid=$spid OR selling_pointid=0) LIMIT 1");
    if (!$gr || !$gr->num_rows)
        $gr = $db->query("SELECT id FROM growers WHERE grower_num='$gnum' LIMIT 1");
    if (!$gr || !$gr->num_rows) err("Grower '$gnum' not found.");
    $gid = (int)$gr->fetch_assoc()['id'];
    $dup = $db->query("SELECT id FROM transporter_growers
        WHERE transporterid=$tid AND growerid=$gid LIMIT 1");
    if ($dup && $dup->num_rows > 0) err("Grower $gnum already assigned.");
    // Exact INSERT — real DB columns
    $db->query("INSERT INTO transporter_growers
        (userid, transporterid, growerid, selling_pointid, bales, junused_bales,
         open_close, created_at, splitid, booked, sell_date, status$loc_col)
        VALUES ($uid, $tid, $gid, $spid, $bales, 0, 0, '$now', 1, 0, '$sdate', 'active'$loc_val)");
    if ($db->error) err('DB error: ' . $db->error);
    ok(['id'=>(int)$db->insert_id,'msg'=>"Grower $gnum assigned — $bales bales."]);
}

// ── UPDATE TG ─────────────────────────────────────────────────────────────────
if ($action === 'update_tg' && $method === 'POST') {
    $b          = body();
    need($b, ['tgid']);
    $tgid       = (int)$b['tgid'];
    $new_bales  = (int)($b['new_bales'] ?? 0);
    $new_date   = $db->real_escape_string($b['new_date'] ?? date('Y-m-d'));
    $stat_raw   = $b['new_status'] ?? 'active';
    $new_status = in_array($stat_raw, ['active','suspended','blocked']) ? $stat_raw : 'active';
    $new_loc    = (int)($b['new_location_id'] ?? 0);
    $sets       = ["bales=$new_bales", "sell_date='$new_date'", "status='$new_status'"];
    if ($new_loc > 0) $sets[] = "locationid=$new_loc";
    $db->query("UPDATE transporter_growers SET " . implode(',', $sets) . " WHERE id=$tgid");
    // Also update booked_bales.sell_date (same as web)
    $gr_q = $db->query("SELECT growerid FROM transporter_growers WHERE id=$tgid LIMIT 1");
    if ($gr_q && $gr_q->num_rows > 0) {
        $gid2 = (int)$gr_q->fetch_assoc()['growerid'];
        $db->query("UPDATE booked_bales SET sell_date='$new_date' WHERE growerid=$gid2");
    }
    ok(['msg'=>'Delivery note updated.']);
}

// ── TOGGLE ────────────────────────────────────────────────────────────────────
if ($action === 'toggle_tg' && $method === 'POST') {
    $b    = body();
    $tgid = (int)($b['tgid'] ?? 0);
    $oc   = (int)($b['open_close'] ?? 0);
    if (!$tgid) err('tgid required');
    requireTgOnFloor($db, $user, $tgid);
    $db->query("UPDATE transporter_growers SET open_close=$oc WHERE id=$tgid");
    ok(['msg'=>'Delivery note ' . ($oc ? 'closed' : 'opened') . '.']);
}

// ── LOCATIONS ─────────────────────────────────────────────────────────────────
if ($action === 'locations') {
    $where = $spid > 0 ? "bl.selling_pointid=$spid" : "bl.selling_pointid > 0";
    $r = $db->query("
        SELECT bl.id, bl.code, bl.name, bl.subcontractor, bl.is_primary,
               COUNT(DISTINCT t.id) transporter_count
        FROM bale_locations bl
        LEFT JOIN transporter t ON t.location_id = bl.id AND t.seasonid = $seasonid
        WHERE $where AND bl.active = 1
        GROUP BY bl.id ORDER BY bl.code
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

// ── ROLLOVER ──────────────────────────────────────────────────────────────────
if ($action === 'rollover_all' && $method === 'POST') {
    $b        = body();
    $new_date = $db->real_escape_string($b['new_date'] ?? date('Y-m-d'));
    $db->query("
        UPDATE transporter_growers tg
        JOIN growers g ON g.id = tg.growerid
        LEFT JOIN balanced_bales bb ON bb.transporter_growersid = tg.id
        SET tg.sell_date = '$new_date'
        WHERE (g.selling_pointid = $spid OR $spid = 0)
          AND tg.open_close = 0 AND bb.id IS NULL
    ");
    ok(['rolled'=>(int)$db->affected_rows,'new_date'=>$new_date]);
}

err('Unknown action');
