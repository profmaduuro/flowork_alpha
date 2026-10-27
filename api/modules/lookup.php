<?php
/**
 * Lookup / sync — pulls all reference data for offline cache
 * Sell date: the floor's active start_of_day (see sell_day.php)
 */
$user     = requireAuth();
$spid     = getSpid($user);
$db       = getDB();
$action   = $_GET['action'] ?? 'sync_data';
$seasonid = getSeasonid($user);

if ($action === 'sync_data') {
    // Sell date — this floor's active start_of_day (Day Control), then selling_days, then today
    require_once __DIR__ . '/sell_day.php';
    $sell_date = apiSellDate($db, $spid);

    // Latest exchange rate
    $rate = 0.0;
    $er = $db->query("SELECT amount FROM exchange_rate
        WHERE seasonid=$seasonid ORDER BY id DESC LIMIT 1");
    if ($er && $er !== true && $er->num_rows > 0)
        $rate = (float)$er->fetch_assoc()['amount'];

    // Grades
    $tg = $db->query("SELECT id,description FROM timb_grades WHERE active=1 ORDER BY description LIMIT 300");
    $bg = $db->query("
        SELECT bg.id, bg.description, COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name, b.id buyer_id, b.buyer_code
        FROM buyer_grades bg JOIN buyer b ON b.id=bg.buyerid
        WHERE (b.selling_pointid=$spid OR $spid=0) AND bg.active=1
        ORDER BY b.name, bg.description LIMIT 300
    ");

    // Locations
    $loc_where = $spid > 0 ? "bl.selling_pointid=$spid" : "bl.selling_pointid > 0";
    $locs = $db->query("SELECT bl.id,bl.code,bl.name,bl.subcontractor
        FROM bale_locations bl WHERE $loc_where AND bl.active=1 ORDER BY bl.code");

    // Growers (for offline cache)
    $g_where = $spid > 0 ? "g.selling_pointid=$spid" : "g.selling_pointid > 0";
    $growers = $db->query("SELECT g.id,g.grower_num,g.name,g.surname,g.national_id,g.phone,g.selling_pointid
        FROM growers g WHERE $g_where AND g.active=1 ORDER BY g.grower_num LIMIT 1000");

    ok([
        'sell_date'    => $sell_date,
        'exchange_rate'=> $rate,
        'timb_grades'  => ($tg && $tg !== true) ? $tg->fetch_all(MYSQLI_ASSOC) : [],
        'buyer_grades' => ($bg && $bg !== true) ? $bg->fetch_all(MYSQLI_ASSOC) : [],
        'locations'    => ($locs && $locs !== true) ? $locs->fetch_all(MYSQLI_ASSOC) : [],
        'growers'      => ($growers && $growers !== true) ? $growers->fetch_all(MYSQLI_ASSOC) : [],
    ]);
}

err('Unknown action');
