<?php
/**
 * Floor — real DB schema
 * floor_summary.row is INT (not VARCHAR)
 * bale_locations confirmed columns: id, selling_pointid, userid, code, name,
 *   subcontractor, contact_name, contact_phone, is_primary, active, created_at
 */
$user     = requireAuth();
requireRight($user, 'floor');
$spid     = getSpid($user);
$db       = getDB();
$action   = $_GET['action'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];
$uid      = (int)$user['id'];
$seasonid = getSeasonid($user);
$loc_where = $spid > 0 ? "bl.selling_pointid=$spid" : "bl.selling_pointid > 0";
$g_where   = $spid > 0 ? "g.selling_pointid=$spid" : "g.selling_pointid > 0";

// ── FLOOR MAP ─────────────────────────────────────────────────────────────────
if ($action === 'map') {
    $date = $db->real_escape_string($_GET['date'] ?? date('Y-m-d'));
    $locs_q = $db->query("SELECT bl.id,bl.code,bl.name,bl.subcontractor
        FROM bale_locations bl WHERE $loc_where AND bl.active=1 ORDER BY bl.code");
    $locs = ($locs_q && $locs_q !== true) ? $locs_q->fetch_all(MYSQLI_ASSOC) : [];
    $data = [];
    foreach ($locs as $loc) {
        $lid = (int)$loc['id'];
        $stats_q = $db->query("
            SELECT COUNT(DISTINCT bm.id) total_bales,
                   COUNT(DISTINCT tk.id) ticketed,
                   COUNT(DISTINCT sb.id) sold,
                   COALESCE(SUM(CASE WHEN sb.id IS NOT NULL THEN bm.mass ELSE 0 END),0) sold_mass,
                   COALESCE(SUM(
                       CASE WHEN CAST(sb.price AS DECIMAL(10,4))>10
                            THEN CAST(sb.price AS DECIMAL(10,4))/100*bm.mass
                            WHEN CAST(sb.price AS DECIMAL(10,4))>0
                            THEN CAST(sb.price AS DECIMAL(10,4))*bm.mass
                            ELSE 0 END
                   ),0) usd
            FROM bale_mass bm
            LEFT JOIN bale_junus bj ON bj.bale_massid=bm.id
            LEFT JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
            LEFT JOIN transporter t ON t.id=tg.transporterid
            LEFT JOIN growers g ON g.id=tg.growerid
            LEFT JOIN bale_locations bl ON bl.id=t.location_id OR bl.code=t.location
            LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
            LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
            WHERE DATE(bm.created_at)='$date' AND bl.id=$lid AND $g_where
        ");
        $stats = ($stats_q && $stats_q !== true) ? ($stats_q->fetch_assoc() ?? []) : [];
        $growers_q = $db->query("
            SELECT g.grower_num, COUNT(DISTINCT bm.id) bales
            FROM bale_mass bm
            JOIN bale_junus bj ON bj.bale_massid=bm.id
            JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
            JOIN transporter t ON t.id=tg.transporterid
            JOIN bale_locations bl ON bl.id=t.location_id OR bl.code=t.location
            JOIN growers g ON g.id=tg.growerid
            WHERE DATE(bm.created_at)='$date' AND bl.id=$lid AND $g_where
            GROUP BY g.id ORDER BY bales DESC LIMIT 5
        ");
        $data[] = [
            'id'=>$lid,'code'=>$loc['code'],'name'=>$loc['name'],
            'subcontractor'=>$loc['subcontractor'],
            'total'=>(int)($stats['total_bales']??0),
            'sold'=>(int)($stats['sold']??0),
            'unsold'=>max(0,(int)($stats['ticketed']??0)-(int)($stats['sold']??0)),
            'mass'=>round((float)($stats['sold_mass']??0),1),
            'usd'=>round((float)($stats['usd']??0),2),
            'growers'=>($growers_q && $growers_q !== true) ? $growers_q->fetch_all(MYSQLI_ASSOC) : [],
        ];
    }
    ok(['locations'=>$data,'date'=>$date,'count'=>count($locs)]);
}

// ── FLOOR SUMMARY ─────────────────────────────────────────────────────────────
if ($action === 'floor_summary') {
    $date = $db->real_escape_string($_GET['date'] ?? date('Y-m-d'));
    $r = $db->query("
        SELECT b.name buyer_name, bg.description grade,
               COUNT(DISTINCT sb.id) bales,
               COALESCE(SUM(bm.mass),0) mass,
               COALESCE(SUM(
                   CASE WHEN CAST(sb.price AS DECIMAL(10,4))>10
                        THEN CAST(sb.price AS DECIMAL(10,4))/100*bm.mass
                        ELSE CAST(sb.price AS DECIMAL(10,4))*bm.mass END
               ),0) total_usd
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        JOIN buyer b ON b.id=bg.buyerid
        WHERE DATE(sb.created_at)='$date' AND $g_where
        GROUP BY b.id,bg.id ORDER BY b.name,bg.description
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

// ── SCANNED BALES LIST ────────────────────────────────────────────────────────
if ($action === 'scanned') {
    $date = $db->real_escape_string($_GET['date'] ?? date('Y-m-d'));
    $r = $db->query("
        SELECT fs.id, fs.`row`, tk.barcode, bm.temp_bacode timb_bc,
               ROUND(bm.mass,1) mass,
               g.grower_num,
               CONCAT(g.name,' ',g.surname) grower_name,
               COALESCE(bl.code, t.location, '—') loc_code,
               TIME(fs.created_at) scan_time,
               CASE WHEN sb.id IS NOT NULL THEN 'SOLD' ELSE 'UNSOLD' END status
        FROM floor_summary fs
        JOIN tickets tk    ON tk.id  = fs.ticketsid
        JOIN bale_junus bj ON bj.id  = tk.bale_junusid
        JOIN bale_mass bm  ON bm.id  = bj.bale_massid
        JOIN growers g     ON g.id   = bj.growerid
        LEFT JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
        LEFT JOIN transporter t    ON t.id  = tg.transporterid
        LEFT JOIN bale_locations bl ON bl.id = t.location_id OR bl.code = t.location
        LEFT JOIN sold_bales sb    ON sb.ticketsid = tk.id
        WHERE DATE(fs.created_at) = '$date' AND ($g_where)
        ORDER BY fs.`row` ASC, tk.barcode ASC
    ");
    $rows = ($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $total_mass = array_sum(array_column($rows, 'mass'));
    $sold_count = count(array_filter($rows, fn($x) => $x['status'] === 'SOLD'));
    ok([
        'date'       => $date,
        'total'      => count($rows),
        'sold'       => $sold_count,
        'unsold'     => count($rows) - $sold_count,
        'total_mass' => round((float)$total_mass, 1),
        'bales'      => $rows,
    ]);
}

// ── BALE CHECK ────────────────────────────────────────────────────────────────
if ($action === 'bale_check') {
    $bc = $db->real_escape_string(trim($_GET['barcode'] ?? $_GET['q'] ?? ''));
    if (!$bc) err('barcode required');
    $has_dispatch = $db->query("SHOW TABLES LIKE 'dispatch'")->num_rows > 0;
    $d_sel  = $has_dispatch ? "d.id dispatched_id, d.created_at dispatched_at," : "NULL dispatched_id, NULL dispatched_at,";
    $d_join = $has_dispatch ? "LEFT JOIN dispatch d ON d.sold_baleid=sb.id" : "";
    // Packing house (reclassify → palletise) location, if that module exists
    $has_ph = $db->query("SHOW TABLES LIKE 'ph_bales'")->num_rows > 0;
    $ph_sel = $has_ph
        ? "pb.id ph_id, pb.ph_barcode, pb.status ph_status, pb.reclassified_at,
           ph_h.code ph_house, ph_h.name ph_house_name, ph_h.location ph_location,
           ph_p.pallet_no ph_pallet, ph_l.line_no ph_line,"
        : "NULL ph_id, NULL ph_barcode, NULL ph_status, NULL reclassified_at,
           NULL ph_house, NULL ph_house_name, NULL ph_location, NULL ph_pallet, NULL ph_line,";
    $ph_join = $has_ph
        ? "LEFT JOIN ph_bales pb   ON pb.source_barcode = tk.barcode
           LEFT JOIN ph_houses ph_h ON ph_h.id = pb.house_id
           LEFT JOIN ph_pallets ph_p ON ph_p.id = pb.pallet_id
           LEFT JOIN ph_lines ph_l   ON ph_l.id = ph_p.line_id"
        : "";
    $r = $db->query("
        SELECT bm.id, bm.temp_bacode timb_bc, bm.mass, bm.created_at weighed_at,
               bj.id junus_id, bj.lot, bj.bale_group, bj.splitid,
               g.grower_num, g.name, g.surname,
               t.name transporter_name, t.truck_num, t.location loc_code,
               tg.id tgid,
               tk.id ticket_id, tk.barcode, tk.created_at ticketed_at,
               sb.id sold_id, sb.price, sb.sale_code, sb.created_at sold_at,
               tgd.description timb_grade,
               bgd.description buyer_grade,
               COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name,
               bb.id balanced_id,
               fs.row floor_row,
               $ph_sel
               $d_sel NULL _pad
        FROM bale_mass bm
        LEFT JOIN bale_junus bj ON bj.bale_massid=bm.id
        LEFT JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
        LEFT JOIN growers g ON g.id=tg.growerid
        LEFT JOIN transporter t ON t.id=tg.transporterid
        LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        LEFT JOIN timb_grades tgd ON tgd.id=sb.timb_gradesid
        LEFT JOIN buyer_grades bgd ON bgd.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bgd.buyerid
        LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
                                   AND bb.splitid=COALESCE(bj.splitid,1)
        LEFT JOIN floor_summary fs ON fs.ticketsid=tk.id
        $ph_join
        $d_join
        WHERE (tk.barcode='$bc' OR bm.temp_bacode='$bc' OR bm.id='$bc')
          AND ($g_where OR g.id IS NULL) LIMIT 1
    ");
    if (!$r || !$r->num_rows) err('Bale not found');
    $bale = $r->fetch_assoc();

    // Packing-house location label (e.g. "TPZ · Willovale · Pallet 1001 · Line 1")
    $ph_label = '';
    if (!empty($bale['ph_id'])) {
        $ph_label = trim(($bale['ph_house'] ?? '')
            . (!empty($bale['ph_location']) ? ' · ' . $bale['ph_location'] : '')
            . (!empty($bale['ph_pallet'])   ? ' · Pallet ' . $bale['ph_pallet'] : '')
            . (!empty($bale['ph_line'])     ? ' · Line ' . $bale['ph_line'] : ''));
    }
    $bale['ph_location_label'] = $ph_label;

    $bale['pipeline'] = [
        ['step'=>'Weighed',      'done'=>true,                           'ts'=>$bale['weighed_at']??''],
        ['step'=>'Junus',        'done'=>!empty($bale['junus_id']),      'ts'=>''],
        ['step'=>'Ticketed',     'done'=>!empty($bale['ticket_id']),     'ts'=>$bale['ticketed_at']??''],
        ['step'=>'Sold',         'done'=>!empty($bale['sold_id']),       'ts'=>$bale['sold_at']??''],
        ['step'=>'Balanced',     'done'=>!empty($bale['balanced_id']),   'ts'=>''],
        ['step'=>'Dispatched',   'done'=>!empty($bale['dispatched_id']), 'ts'=>$bale['dispatched_at']??''],
        ['step'=>'Packing House','done'=>!empty($bale['ph_id']),         'ts'=>$bale['reclassified_at']??'', 'detail'=>$ph_label],
        ['step'=>'Processed',    'done'=>(($bale['ph_status']??'')==='dispatched'), 'ts'=>''],
    ];
    ok($bale);
}

// ── MOVE BALE — floor_summary.row is INT ──────────────────────────────────────
if ($action === 'move_bale' && $method === 'POST') {
    $b       = body();
    $bc      = $db->real_escape_string(trim($b['barcode'] ?? ''));
    $tkid_in = (int)($b['ticket_id'] ?? 0);
    $new_row = (int)($b['new_row'] ?? $b['location_id'] ?? 0);   // INT in real DB
    if (!$bc && !$tkid_in) err('barcode or ticket_id required');
    if (!$new_row) err('new_row required');
    $ticket_filter = $tkid_in > 0 ? "tk.id=$tkid_in" : "tk.barcode='$bc'";
    $tk_q    = $db->query("
        SELECT tk.id FROM tickets tk
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        WHERE $ticket_filter AND ($g_where OR g.id IS NULL) LIMIT 1
    ");
    if (!$tk_q || !$tk_q->num_rows) err('Bale not found');
    $tkid = (int)$tk_q->fetch_assoc()['id'];
    $today = date('Y-m-d');
    $ex = $db->query("SELECT id FROM floor_summary WHERE ticketsid=$tkid LIMIT 1");
    if ($ex && $ex->num_rows > 0) {
        $fsid = (int)$ex->fetch_assoc()['id'];
        $db->query("UPDATE floor_summary SET `row`=$new_row, created_at='$today' WHERE id=$fsid");
    } else {
        $db->query("INSERT INTO floor_summary (userid,ticketsid,`row`,created_at)
            VALUES ($uid,$tkid,$new_row,'$today')");
    }
    ok(['moved'=>true,'barcode'=>$bc,'ticket_id'=>$tkid,'row'=>$new_row]);
}

// ── LOCATIONS ─────────────────────────────────────────────────────────────────
if ($action === 'locations') {
    $r = $db->query("
        SELECT bl.id, bl.code, bl.name, bl.subcontractor, bl.is_primary,
               COUNT(DISTINCT t.id) transporter_count
        FROM bale_locations bl
        LEFT JOIN transporter t ON t.location_id=bl.id AND t.seasonid=$seasonid
        WHERE $loc_where AND bl.active=1
        GROUP BY bl.id ORDER BY bl.code
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

// ── ADD LOCATION ──────────────────────────────────────────────────────────────
if ($action === 'add_location' && $method === 'POST') {
    $b    = body();
    need($b, ['code', 'name']);
    $code = $db->real_escape_string(strtoupper(trim($b['code'])));
    $name = $db->real_escape_string(trim($b['name']));
    $sub  = $db->real_escape_string(trim($b['subcontractor'] ?? ''));
    $prim = (int)($b['is_primary'] ?? 0);
    $ex   = $db->query("SELECT id FROM bale_locations WHERE code='$code' AND selling_pointid=$spid LIMIT 1");
    if ($ex && $ex->num_rows > 0) err("Location code '$code' already exists.");
    if ($prim) $db->query("UPDATE bale_locations SET is_primary=0 WHERE selling_pointid=$spid");
    // Exact columns from real DB: (selling_pointid, userid, code, name, subcontractor, is_primary, active)
    $db->query("INSERT INTO bale_locations (selling_pointid,userid,code,name,subcontractor,is_primary,active)
        VALUES ($spid,$uid,'$code','$name','$sub',$prim,1)");
    if ($db->error) err('Failed: ' . $db->error);
    ok(['id'=>(int)$db->insert_id,'code'=>$code]);
}

err('Unknown action');
