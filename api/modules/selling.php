<?php
/**
 * Selling — exact real DB schema
 * sold_bales.price is VARCHAR — stores '3.40' or '340' (cents)
 * IMPORTANT: normalise to USD/kg on read; store as-is from web (4 decimal string)
 * tickets.start_of_dayid is NOT NULL — must resolve it
 */
$user     = requireAuth();
requireRight($user, 'floor');
$spid     = getSpid($user);
$db       = getDB();
$action   = $_GET['action'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];
$uid      = (int)$user['id'];
$seasonid = getSeasonid($user);

// Helper: get current start_of_day id
require_once __DIR__ . '/sell_day.php';
function getCurrentSodId($db, $seasonid, $spid) {
    // This floor's active day (Day Control) first
    $sod = apiActiveSod($db, (int)$spid);
    if ($sod) return $sod['id'];
    // Fallback: any active day this season
    $sd = $db->query("SELECT id FROM start_of_day
        WHERE seasonid=$seasonid AND active=1
        ORDER BY id DESC LIMIT 1");
    if ($sd && $sd !== true && $sd->num_rows > 0)
        return (int)$sd->fetch_assoc()['id'];
    return 1; // fallback
}

// ── LOOKUP ────────────────────────────────────────────────────────────────────
if ($action === 'lookup') {
    $bc = $db->real_escape_string(trim($_GET['barcode'] ?? ''));
    if (!$bc) err('barcode required');
    $r = $db->query("
        SELECT tk.id ticket_id, tk.barcode, tk.created_at ticketed_at,
               bm.mass, bm.temp_bacode timb_bc,
               bj.lot, bj.bale_group, bj.splitid,
               g.grower_num, g.name, g.surname, g.id grower_id,
               tg.id tgid, tg.sell_date,
               t.name transporter_name, t.location loc_code, t.location location_code,
               COALESCE(sb.id, 0) sold_id,
               COALESCE(sb.sale_code, '') sale_code,
               COALESCE(sb.price, 0) price,
               tgd.description timb_grade,
               bgd.description buyer_grade,
               COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name
        FROM tickets tk
        JOIN bale_junus bj ON bj.id = tk.bale_junusid
        JOIN bale_mass bm ON bm.id = bj.bale_massid
        JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
        JOIN growers g ON g.id = tg.growerid
        LEFT JOIN transporter t ON t.id = tg.transporterid
        LEFT JOIN sold_bales sb ON sb.ticketsid = tk.id
        LEFT JOIN timb_grades tgd ON tgd.id = sb.timb_gradesid
        LEFT JOIN buyer_grades bgd ON bgd.id = sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id = bgd.buyerid
        WHERE tk.barcode = '$bc'
          AND (g.selling_pointid = $spid OR $spid = 0)
        LIMIT 1
    ");
    if (!$r || !$r->num_rows) err('Bale not found');
    ok($r->fetch_assoc());
}

// ── LISTS ─────────────────────────────────────────────────────────────────────
if ($action === 'lists') {
    $tg = $db->query("SELECT id, description FROM timb_grades WHERE active=1 ORDER BY description LIMIT 300");
    $bg = $db->query("
        SELECT bg.id, bg.description, COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name, b.id buyer_id, b.buyer_code
        FROM buyer_grades bg
        JOIN buyer b ON b.id = bg.buyerid
        WHERE (b.selling_pointid = $spid OR $spid = 0) AND bg.active = 1
        ORDER BY b.name, bg.description LIMIT 300
    ");
    ok([
        'timb_grades'  => ($tg && $tg !== true) ? $tg->fetch_all(MYSQLI_ASSOC) : [],
        'buyer_grades' => ($bg && $bg !== true) ? $bg->fetch_all(MYSQLI_ASSOC) : [],
    ]);
}

// ── Ensure s2_confirmed column exists (one-time migration) ───────────────────
$_chk = $db->query("SHOW COLUMNS FROM sold_bales LIKE 's2_confirmed'");
if ($_chk && $_chk->num_rows === 0)
    $db->query("ALTER TABLE sold_bales ADD COLUMN s2_confirmed TINYINT NOT NULL DEFAULT 0");

// ── CAPTURE ───────────────────────────────────────────────────────────────────
if ($action === 'capture' && $method === 'POST') {
    $b        = body();
    $tid      = (int)($b['ticket_id']      ?? $b['ticketsid']      ?? 0);
    $tgid     = (int)($b['timb_grade_id']  ?? $b['timb_gradesid']  ?? 0);
    $bgid     = (int)($b['buyer_grade_id'] ?? $b['buyer_gradesid'] ?? 0);
    $price    = (float)($b['price'] ?? 0);
    $sc       = $db->real_escape_string(strtoupper(trim($b['sale_code'] ?? '')));
    $rep      = $db->real_escape_string(trim($b['sales_rep'] ?? ''));
    $is_s2    = !empty($b['selling2']);   // Selling 2 only updates buyer grade + price

    if (!$tid)  err('ticket_id required');
    if (!$bgid) err('buyer_grade_id required');
    if (!$is_s2 && !$tgid) err('timb_grade_id required');

    // Normalise price: if stored as cents (>10), convert to USD/kg
    if ($price > 10) $price = round($price / 100, 4);
    if (in_array($sc, ['R','CO','RO'])) $price = 0;
    $price_str = $db->real_escape_string(number_format($price, 4, '.', ''));

    // Verify ticket belongs to this floor
    $tk_chk = $db->query("
        SELECT tk.id FROM tickets tk
        JOIN bale_junus bj ON bj.id = tk.bale_junusid
        JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
        JOIN growers g ON g.id = tg.growerid
        WHERE tk.id = $tid AND (g.selling_pointid = $spid OR $spid = 0) LIMIT 1
    ");
    if (!$tk_chk || !$tk_chk->num_rows) err('Ticket not found on this floor');

    $today  = date('Y-m-d');
    $s2_val = $is_s2 ? 1 : 0;  // mark as S2-confirmed when called from Selling 2
    $ex = $db->query("SELECT id, timb_gradesid FROM sold_bales WHERE ticketsid=$tid LIMIT 1");
    if ($ex && $ex->num_rows > 0) {
        $erow     = $ex->fetch_assoc();
        $eid      = (int)$erow['id'];
        $use_tgid = $is_s2 ? (int)$erow['timb_gradesid'] : $tgid;
        $db->query("UPDATE sold_bales SET
            timb_gradesid=$use_tgid, buyer_gradesid=$bgid,
            price='$price_str', sale_code='$sc', sales_rep='$rep', userid=$uid,
            s2_confirmed=$s2_val
            WHERE id=$eid");
        if ($db->error) err('Update failed: ' . $db->error);
        ok(['sold_bales_id'=>$eid,'updated'=>true,'price_stored'=>$price_str]);
    } else {
        $db->query("INSERT INTO sold_bales
            (userid, ticketsid, sales_rep, sale_code, timb_gradesid, buyer_gradesid, price, s2_confirmed, created_at)
            VALUES ($uid, $tid, '$rep', '$sc', $tgid, $bgid, '$price_str', $s2_val, '$today')");
        if ($db->error) err('Capture failed: ' . $db->error);
        ok(['sold_bales_id'=>(int)$db->insert_id,'updated'=>false,'price_stored'=>$price_str]);
    }
}

// ── BATCH CAPTURE ─────────────────────────────────────────────────────────────
if ($action === 'batch_capture' && $method === 'POST') {
    $b     = body();
    $items = $b['items'] ?? [];
    if (empty($items)) err('No items');
    $ok_n = 0; $errs = [];
    $today = date('Y-m-d');
    foreach ($items as $item) {
        $tid   = (int)($item['ticket_id']      ?? $item['ticketsid']      ?? 0);
        $tgid  = (int)($item['timb_grade_id']  ?? $item['timb_gradesid']  ?? 0);
        $bgid  = (int)($item['buyer_grade_id'] ?? $item['buyer_gradesid'] ?? 0);
        $price = (float)($item['price'] ?? 0);
        if ($price > 10) $price = round($price / 100, 4);
        $sc    = $db->real_escape_string(strtoupper(trim($item['sale_code'] ?? '')));
        $rep   = $db->real_escape_string(trim($item['sales_rep'] ?? ''));
        if (!$tid || !$tgid || !$bgid) { $errs[] = $item; continue; }
        if (in_array($sc, ['R','CO','RO'])) $price = 0;
        $ps = $db->real_escape_string(number_format($price, 4, '.', ''));
        $ex = $db->query("SELECT id FROM sold_bales WHERE ticketsid=$tid LIMIT 1");
        if ($ex && $ex->num_rows > 0) {
            $eid = (int)$ex->fetch_assoc()['id'];
            $db->query("UPDATE sold_bales SET timb_gradesid=$tgid,buyer_gradesid=$bgid,
                price='$ps',sale_code='$sc',sales_rep='$rep',userid=$uid WHERE id=$eid");
        } else {
            $db->query("INSERT INTO sold_bales (userid,ticketsid,sales_rep,sale_code,timb_gradesid,buyer_gradesid,price,created_at)
                VALUES ($uid,$tid,'$rep','$sc',$tgid,$bgid,'$ps','$today')");
        }
        if (!$db->error) $ok_n++; else $errs[] = array_merge($item,['db_error'=>$db->error]);
    }
    ok(['synced'=>$ok_n,'failed'=>count($errs),'errors'=>$errs]);
}

// ── BATCH STATUS ─────────────────────────────────────────────────────────────
// Returns all tickets for a transporter_growers batch with sell status + counts
if ($action === 'batch') {
    $tgid = (int)($_GET['tgid'] ?? 0);
    if (!$tgid) err('tgid required');
    $r = $db->query("
        SELECT tk.id ticket_id, tk.barcode,
               bj.lot, bj.bale_group,
               ROUND(bm.mass, 1) mass,
               COALESCE(sb.id, 0)              sold_id,
               COALESCE(sb.sale_code, '')      sale_code,
               COALESCE(sb.s2_confirmed, 0)    s2_confirmed,
               COALESCE(
                   CASE WHEN sb.s2_confirmed = 1 AND CAST(sb.price AS DECIMAL(10,4)) > 10
                        THEN ROUND(CAST(sb.price AS DECIMAL(10,4))/100, 4)
                        WHEN sb.s2_confirmed = 1
                        THEN CAST(sb.price AS DECIMAL(10,4))
                        ELSE NULL END,
               NULL) price_usd,
               COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name,
               bgd.description buyer_grade
        FROM tickets tk
        JOIN bale_junus bj  ON bj.id  = tk.bale_junusid
        JOIN bale_mass bm   ON bm.id  = bj.bale_massid
        JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
        JOIN growers g ON g.id = tg.growerid
        LEFT JOIN sold_bales sb    ON sb.ticketsid = tk.id
        LEFT JOIN buyer_grades bgd ON bgd.id = sb.buyer_gradesid
        LEFT JOIN buyer b          ON b.id   = bgd.buyerid
        WHERE bj.transporter_growersid = $tgid
          AND (g.selling_pointid = $spid OR $spid = 0)
        ORDER BY bj.lot ASC, bj.bale_group ASC
    ");
    $tickets = ($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $total    = count($tickets);
    $captured = count(array_filter($tickets, fn($t) => (int)$t['sold_id'] > 0));
    ok([
        'tgid'      => $tgid,
        'total'     => $total,
        'captured'  => $captured,
        'remaining' => $total - $captured,
        'tickets'   => $tickets,
    ]);
}

// ── FLOOR SUMMARY ─────────────────────────────────────────────────────────────
if ($action === 'floor_summary') {
    $date = $db->real_escape_string($_GET['date'] ?? date('Y-m-d'));
    // price is VARCHAR — normalise on the fly
    $r = $db->query("
        SELECT COALESCE(NULLIF(b.buyer_code,''), b.name) buyer_name, bg.description grade,
               COUNT(DISTINCT sb.id) bales,
               COALESCE(SUM(bm.mass), 0) mass,
               COALESCE(SUM(
                   CASE WHEN CAST(sb.price AS DECIMAL(10,4)) > 10
                        THEN CAST(sb.price AS DECIMAL(10,4))/100 * bm.mass
                        ELSE CAST(sb.price AS DECIMAL(10,4)) * bm.mass END
               ), 0) total_usd
        FROM sold_bales sb
        JOIN tickets tk ON tk.id = sb.ticketsid
        JOIN bale_junus bj ON bj.id = tk.bale_junusid
        JOIN bale_mass bm ON bm.id = bj.bale_massid
        JOIN growers g ON g.id = bj.growerid
        JOIN buyer_grades bg ON bg.id = sb.buyer_gradesid
        JOIN buyer b ON b.id = bg.buyerid
        WHERE DATE(sb.created_at) = '$date'
          AND (g.selling_pointid = $spid OR $spid = 0)
        GROUP BY b.id, bg.id ORDER BY b.name, bg.description
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

err('Unknown action');
