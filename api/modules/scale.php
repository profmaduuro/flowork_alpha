<?php
/**
 * Scale — exact match to real DB schema
 * bale_mass: (userid, seasonid, temp_bacode, mass, used, created_at, datetime, selling_pointid)
 * No growerid column on bale_mass — confirmed from real DB dump
 */
$user     = requireAuth();
requireRight($user, 'floor');
$spid     = getSpid($user);
$db       = getDB();
$action   = $_GET['action'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];
$uid      = (int)$user['id'];
$seasonid = getSeasonid($user);

// ── LOOKUP ────────────────────────────────────────────────────────────────────
if ($action === 'lookup') {
    $bc = $db->real_escape_string(trim($_GET['barcode'] ?? ''));
    if (!$bc) err('barcode required');
    $r = $db->query("
        SELECT bm.id, bm.temp_bacode timb_bc, bm.mass, bm.created_at weighed_at,
               g.grower_num, g.name, g.surname,
               tg.id tgid, tg.booked, tg.sell_date,
               t.name transporter_name, t.truck_num, t.location loc_code,
               bj.id junus_id, bj.lot, bj.bale_group, bj.splitid,
               tk.id ticket_id, tk.barcode
        FROM bale_mass bm
        LEFT JOIN bale_junus bj ON bj.bale_massid = bm.id
        LEFT JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
        LEFT JOIN growers g ON g.id = tg.growerid
        LEFT JOIN transporter t ON t.id = tg.transporterid
        LEFT JOIN tickets tk ON tk.bale_junusid = bj.id
        WHERE (bm.temp_bacode = '$bc' OR tk.barcode = '$bc')
          AND (g.selling_pointid = $spid OR $spid = 0 OR g.id IS NULL)
        LIMIT 1
    ");
    if (!$r || !$r->num_rows) err('Bale not found');
    ok($r->fetch_assoc());
}

// ── WEIGH ─────────────────────────────────────────────────────────────────────
if ($action === 'weigh' && $method === 'POST') {
    $b    = body();
    need($b, ['timb_barcode', 'mass']);
    $tbc  = $db->real_escape_string(trim($b['timb_barcode']));
    $mass = (int)round((float)$b['mass']);
    if (!$tbc) err('TIMB barcode required');
    if ($mass <= 0) err('Mass must be greater than 0');
    // Exact real DB columns: (userid, seasonid, temp_bacode, mass, used, created_at, selling_pointid)
    $dup = $db->query("SELECT id FROM bale_mass WHERE temp_bacode='$tbc' LIMIT 1");
    if ($dup && $dup->num_rows > 0) err("Barcode '$tbc' already used.");
    $created = $db->real_escape_string($b['created_at'] ?? date('Y-m-d'));
    $db->query("INSERT INTO bale_mass
        (userid, seasonid, temp_bacode, mass, used, created_at, selling_pointid)
        VALUES ($uid, $seasonid, '$tbc', $mass, 0, '$created', $spid)");
    if ($db->error) err('Failed: ' . $db->error);
    ok(['bale_mass_id'=>(int)$db->insert_id,'timb_bc'=>$tbc,'mass'=>$mass]);
}

// Create bale junus, matching the web app's junus.php workflow.
if ($action === 'junus' && $method === 'POST') {
    $b = body();
    need($b, ['temp_barcode', 'grower_num']);
    $temp_barcode = $db->real_escape_string(trim($b['temp_barcode']));
    $grower_num   = $db->real_escape_string(strtoupper(trim($b['grower_num'])));
    $bale_group   = (int)($b['bale_group'] ?? 1);
    $lot          = (int)($b['lot'] ?? 1);
    $splitid      = (int)($b['splitid'] ?? 1);
    $created_at   = $db->real_escape_string(trim($b['created_at'] ?? date('Y-m-d')));

    $bm_q = $db->query("SELECT id, mass FROM bale_mass WHERE temp_bacode='$temp_barcode' LIMIT 1");
    if (!$bm_q || !$bm_q->num_rows) err("Scale barcode '$temp_barcode' not found. Weigh bale first.");
    $bm = $bm_q->fetch_assoc();
    $bale_massid = (int)$bm['id'];

    $au_q = $db->query("SELECT id FROM bale_junus WHERE bale_massid=$bale_massid LIMIT 1");
    if ($au_q && $au_q->num_rows > 0) err("Scale barcode '$temp_barcode' already assigned in junus.");

    $gr_q = $db->query("SELECT id, name, surname FROM growers WHERE grower_num='$grower_num' AND selling_pointid=$spid LIMIT 1");
    $gr = ($gr_q && $gr_q !== true && $gr_q->num_rows > 0) ? $gr_q->fetch_assoc() : null;
    if (!$gr && $spid) {
        $gr_q = $db->query("SELECT id, name, surname FROM growers WHERE grower_num='$grower_num' AND (selling_pointid=0 OR selling_pointid IS NULL) LIMIT 1");
        $gr = ($gr_q && $gr_q !== true && $gr_q->num_rows > 0) ? $gr_q->fetch_assoc() : null;
        if ($gr) $db->query("UPDATE growers SET selling_pointid=$spid WHERE id={$gr['id']}");
    }
    if (!$gr) err("Grower '$grower_num' not found. Register in Grower Registry first.");
    $growerid = (int)$gr['id'];

    $tg_q = $db->query("SELECT id, bales, junused_bales, sell_date FROM transporter_growers
        WHERE growerid=$growerid AND open_close=0
          AND (bales=0 OR bales>junused_bales)
        ORDER BY CASE WHEN sell_date=CURDATE() THEN 0 ELSE 1 END, id DESC
        LIMIT 1");
    if (!$tg_q || !$tg_q->num_rows) err("No open delivery note found for grower '$grower_num'.");
    $tg = $tg_q->fetch_assoc();
    $tgid = (int)$tg['id'];

    $sp_chk = $db->query("SELECT id FROM splits WHERE id=$splitid LIMIT 1");
    if (!$sp_chk || $sp_chk->num_rows === 0) $splitid = 1;

    $db->query("INSERT INTO bale_junus
        (userid, transporter_growersid, bale_massid, growerid, bale_group, lot, splitid, created_at)
        VALUES ($uid, $tgid, $bale_massid, $growerid, $bale_group, $lot, $splitid, '$created_at')");
    if ($db->error) err('Database error: ' . $db->error);
    $bjid = (int)$db->insert_id;
    $db->query("UPDATE transporter_growers SET junused_bales=junused_bales+1 WHERE id=$tgid");

    ok([
        'bale_junus_id'=>$bjid,
        'transporter_growersid'=>$tgid,
        'growerid'=>$growerid,
        'grower_num'=>$grower_num,
        'lot'=>$lot,
        'bale_group'=>$bale_group,
        'splitid'=>$splitid,
        'mass'=>(float)$bm['mass']
    ], 'Bale assigned to junus.');
}

// ── TODAY ─────────────────────────────────────────────────────────────────────
if ($action === 'today') {
    $date = $db->real_escape_string($_GET['date'] ?? date('Y-m-d'));
    $r = $db->query("
        SELECT bm.id, bm.temp_bacode, bm.mass, bm.created_at,
               bj.id junus_id,
               g.grower_num, g.name, g.surname
        FROM bale_mass bm
        LEFT JOIN bale_junus bj ON bj.bale_massid = bm.id
        LEFT JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
        LEFT JOIN growers g ON g.id = tg.growerid
        WHERE DATE(bm.created_at) = '$date'
          AND bm.seasonid = $seasonid
          AND (bm.selling_pointid = $spid OR $spid = 0 OR bm.selling_pointid = 0)
        ORDER BY bm.id DESC LIMIT 200
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

// ── JUNUS QUEUE — grower delivery notes for a date with junus progress ────────
if ($action === 'junus_queue') {
    $date = $db->real_escape_string($_GET['date'] ?? date('Y-m-d'));
    $r = $db->query("
        SELECT tg.id tgid, tg.bales, tg.junused_bales junus_count,
               CASE WHEN tg.open_close=1 THEN 'closed' ELSE 'open' END junus_status,
               tg.sell_date, tg.open_close,
               g.grower_num, g.name, g.surname,
               t.name transporter_name, t.truck_num
        FROM transporter_growers tg
        JOIN growers g ON g.id = tg.growerid
        JOIN transporter t ON t.id = tg.transporterid
        WHERE tg.sell_date = '$date'
          AND (tg.selling_pointid = $spid OR $spid = 0 OR tg.selling_pointid = 0)
        ORDER BY g.grower_num
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

// ── TODAY LOG — alias for 'today' used by ScaleActivity ──────────────────────
if ($action === 'today_log') {
    $date = $db->real_escape_string($_GET['date'] ?? date('Y-m-d'));
    $r = $db->query("
        SELECT bm.id, bm.temp_bacode, bm.mass, bm.created_at,
               bj.id junus_id,
               g.grower_num, g.name, g.surname
        FROM bale_mass bm
        LEFT JOIN bale_junus bj ON bj.bale_massid = bm.id
        LEFT JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
        LEFT JOIN growers g ON g.id = tg.growerid
        WHERE DATE(bm.created_at) = '$date'
          AND bm.seasonid = $seasonid
          AND (bm.selling_pointid = $spid OR $spid = 0 OR bm.selling_pointid = 0)
        ORDER BY bm.id DESC LIMIT 200
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

// ── ADJUST ────────────────────────────────────────────────────────────────────
if ($action === 'adjust' && $method === 'POST') {
    $b      = body();
    need($b, ['ticket_id', 'type']);
    $tkid   = (int)$b['ticket_id'];
    $type   = $db->real_escape_string($b['type']);
    $value  = $db->real_escape_string($b['value'] ?? '');
    $reason = $db->real_escape_string($b['reason'] ?? '');

    $tk_q = $db->query("
        SELECT tk.id tkid, tk.bale_junusid bjid, bm.id bmid, bm.mass, sb.id sbid
        FROM tickets tk
        JOIN bale_junus bj ON bj.id = tk.bale_junusid
        JOIN bale_mass bm ON bm.id = bj.bale_massid
        JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
        JOIN growers g ON g.id = tg.growerid
        LEFT JOIN sold_bales sb ON sb.ticketsid = tk.id
        WHERE tk.id = $tkid AND (g.selling_pointid = $spid OR $spid = 0)
        LIMIT 1
    ");
    if (!$tk_q || !$tk_q->num_rows) err('Bale not found');
    $tk = $tk_q->fetch_assoc();

    if ($type === 'adjust_mass') {
        $new_mass = (int)round((float)$value);
        if ($new_mass <= 0) err('Valid mass required');
        $db->query("UPDATE bale_mass SET mass=$new_mass WHERE id={$tk['bmid']}");
        if ($db->error) err('Failed: ' . $db->error);
        ok(['msg' => "Mass adjusted to {$new_mass}kg"]);
    }
    if ($type === 'void_bale') {
        $db->query("UPDATE bale_mass SET used=0 WHERE id={$tk['bmid']}");
        $db->query("DELETE FROM bale_junus WHERE id={$tk['bjid']}");
        if ($tk['sbid']) $db->query("DELETE FROM sold_bales WHERE id={$tk['sbid']}");
        $db->query("DELETE FROM tickets WHERE id={$tk['tkid']}");
        ok(['msg' => 'Bale voided']);
    }
    if ($type === 'remark_unsold') {
        if (!$tk['sbid']) err('Bale is already unsold');
        $db->query("DELETE FROM sold_bales WHERE id={$tk['sbid']}");
        ok(['msg' => 'Bale marked as unsold']);
    }
    err('Unknown adjustment type');
}

err('Unknown action');
