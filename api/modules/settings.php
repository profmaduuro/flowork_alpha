<?php
/**
 * Settings — real DB schema
 * Uses: selling_days (authoritative sell date), start_of_day, exchange_rate, settings table
 * selling_days: (selling_pointid, sell_date, status, opened_by, opened_at)
 * settings: (name, value) — simple key-value
 */
$user     = requireAuth();
$spid     = getSpid($user);
$db       = getDB();
$action   = $_GET['action'] ?? 'get';
$method   = $_SERVER['REQUEST_METHOD'];
$uid      = (int)$user['id'];
$seasonid = getSeasonid($user);

// ── GET current settings ───────────────────────────────────────────────────────
if ($action === 'get') {
    // Sell date from selling_days
    $sell_date = '';
    $sd_q = $db->query("SELECT sell_date FROM selling_days
        WHERE selling_pointid=$spid AND status='open'
        ORDER BY sell_date DESC LIMIT 1");
    if ($sd_q && $sd_q !== true && $sd_q->num_rows > 0) {
        $sell_date = $sd_q->fetch_assoc()['sell_date'];
    } else {
        // Fallback: start_of_day
        $sod = $db->query("SELECT description FROM start_of_day
            WHERE seasonid=$seasonid AND active=1 ORDER BY id DESC LIMIT 1");
        if ($sod && $sod !== true && $sod->num_rows > 0)
            $sell_date = $sod->fetch_assoc()['description'];
    }
    // Exchange rate — latest for this season
    $rate = 0.0;
    $er = $db->query("SELECT amount FROM exchange_rate
        WHERE seasonid=$seasonid ORDER BY id DESC LIMIT 1");
    if ($er && $er !== true && $er->num_rows > 0)
        $rate = (float)$er->fetch_assoc()['amount'];
    // Active start_of_day id
    $sod2 = $db->query("SELECT id FROM start_of_day
        WHERE seasonid=$seasonid AND active=1 ORDER BY id DESC LIMIT 1");
    $sod_id = ($sod2 && $sod2 !== true && $sod2->num_rows > 0)
        ? (int)$sod2->fetch_assoc()['id'] : 0;
    ok(['sell_date'=>$sell_date,'exchange_rate'=>$rate,'sod_id'=>$sod_id,'seasonid'=>$seasonid]);
}

// ── UPDATE sell date + exchange rate ──────────────────────────────────────────
if ($action === 'update_day' && $method === 'POST') {
    // Same rights as the web pages: day_control.php (balance), exchange_rate.php (accounts)
    requireRight($user, 'balance');
    $b         = body();
    if (isset($b['exchange_rate'])) requireRight($user, 'accounts');
    $sell_date = $db->real_escape_string(trim($b['sell_date'] ?? date('Y-m-d')));
    $rate      = isset($b['exchange_rate']) ? (float)$b['exchange_rate'] : null;
    $now       = date('Y-m-d H:i:s');

    // Step 1: Open/update selling_days (authoritative)
    $ex_sd = $db->query("SELECT id FROM selling_days
        WHERE selling_pointid=$spid AND sell_date='$sell_date' LIMIT 1");
    if ($ex_sd && $ex_sd->num_rows > 0) {
        $db->query("UPDATE selling_days SET status='open', opened_by=$uid, opened_at='$now'
            WHERE selling_pointid=$spid AND sell_date='$sell_date'");
    } else {
        $db->query("INSERT INTO selling_days
            (selling_pointid, sell_date, status, opened_by, opened_at)
            VALUES ($spid, '$sell_date', 'open', $uid, '$now')");
    }

    // Step 2: Open/update start_of_day (legacy — many tables reference this)
    $ex_sod = $db->query("SELECT id FROM start_of_day
        WHERE description='$sell_date' AND seasonid=$seasonid AND active=1 LIMIT 1");
    if ($ex_sod && $ex_sod->num_rows > 0) {
        $sod_id = (int)$ex_sod->fetch_assoc()['id'];
    } else {
        // Deactivate other active days
        $db->query("UPDATE start_of_day SET active=0
            WHERE seasonid=$seasonid AND active=1 AND description!='$sell_date'");
        $db->query("INSERT INTO start_of_day (userid, seasonid, description, active)
            VALUES ($uid, $seasonid, '$sell_date', 1)");
        $sod_id = (int)$db->insert_id;
    }

    // Step 3: Upsert exchange_rate
    if ($rate !== null && $rate > 0 && $sod_id) {
        $ex_er = $db->query("SELECT id FROM exchange_rate WHERE start_of_dayid=$sod_id LIMIT 1");
        if ($ex_er && $ex_er->num_rows > 0) {
            $db->query("UPDATE exchange_rate SET amount=$rate WHERE start_of_dayid=$sod_id");
        } else {
            $db->query("INSERT INTO exchange_rate
                (userid, seasonid, start_of_dayid, amount, active, selling_pointid)
                VALUES ($uid, $seasonid, $sod_id, $rate, 1, $spid)");
        }
    }

    ok(['sell_date'=>$sell_date,'exchange_rate'=>$rate,'sod_id'=>$sod_id]);
}

// ── SYNC DATA ─────────────────────────────────────────────────────────────────
if ($action === 'sync_data') {
    $sell_date = '';
    $sd_q = $db->query("SELECT sell_date FROM selling_days
        WHERE selling_pointid=$spid AND status='open'
        ORDER BY sell_date DESC LIMIT 1");
    if ($sd_q && $sd_q !== true && $sd_q->num_rows > 0)
        $sell_date = $sd_q->fetch_assoc()['sell_date'];

    $rate = 0.0;
    $er = $db->query("SELECT amount FROM exchange_rate
        WHERE seasonid=$seasonid ORDER BY id DESC LIMIT 1");
    if ($er && $er !== true && $er->num_rows > 0)
        $rate = (float)$er->fetch_assoc()['amount'];

    $tg = $db->query("SELECT id,description FROM timb_grades WHERE active=1 ORDER BY description LIMIT 300");
    $bg = $db->query("
        SELECT bg.id, bg.description, COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name, b.id buyer_id, b.buyer_code
        FROM buyer_grades bg JOIN buyer b ON b.id=bg.buyerid
        WHERE (b.selling_pointid=$spid OR $spid=0) AND bg.active=1
        ORDER BY b.name, bg.description LIMIT 300
    ");
    $locs = $db->query("SELECT id,code,name,subcontractor
        FROM bale_locations WHERE selling_pointid=$spid AND active=1 ORDER BY code");

    ok([
        'sell_date'    => $sell_date,
        'exchange_rate'=> $rate,
        'timb_grades'  => ($tg && $tg !== true) ? $tg->fetch_all(MYSQLI_ASSOC) : [],
        'buyer_grades' => ($bg && $bg !== true) ? $bg->fetch_all(MYSQLI_ASSOC) : [],
        'locations'    => ($locs && $locs !== true) ? $locs->fetch_all(MYSQLI_ASSOC) : [],
    ]);
}

// ── RATE HISTORY ──────────────────────────────────────────────────────────────
if ($action === 'rate_history') {
    $r = $db->query("
        SELECT er.id, er.amount exchange_rate, er.sell_date,
               er.sell_date changed_at, er.source, er.active,
               u.username changed_by
        FROM exchange_rate er
        LEFT JOIN users u ON u.id = er.userid
        WHERE (er.selling_pointid = $spid OR $spid = 0)
        ORDER BY er.id DESC
        LIMIT 50
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

err('Unknown action');
