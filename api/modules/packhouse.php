<?php
// Packing House module — mobile API
// GET  ?module=packhouse&action=summary
// GET  ?module=packhouse&action=houses
// GET  ?module=packhouse&action=lines&house_id=
// POST ?module=packhouse&action=reclassify     {ph_barcode, source_barcode, house_id}
// POST ?module=packhouse&action=pallet_create  {pallet_no, house_id, line_id?, ph_barcode?}
// POST ?module=packhouse&action=pallet_add_bale {pallet_no|pallet_id, ph_barcode}
// POST ?module=packhouse&action=pallet_assign_line {pallet_id, line_id}
// GET  ?module=packhouse&action=pallet_detail&pallet_no=  (or &id=)
// GET  ?module=packhouse&action=pallet_list[&house_id=][&status=]
// GET  ?module=packhouse&action=bale_locate&q=barcode
// POST ?module=packhouse&action=dispatch_create {house_id, vehicle?, destination?}
// POST ?module=packhouse&action=dispatch_add_pallet {dispatch_id, pallet_no|pallet_id}
// POST ?module=packhouse&action=dispatch_send   {dispatch_id}

require_once __DIR__ . '/../../includes/ph_schema.php';

$action = $_GET['action'] ?? 'summary';
$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();
$user   = requireAuth();
$uid    = (int)$user['id'];
ph_ensure_tables($db);

// ── SUMMARY ───────────────────────────────────────────────────────────────────
if ($action === 'summary') {
    $s = [];
    $r = $db->query("SELECT COUNT(*) n FROM ph_bales WHERE status='reclassified'");
    $s['awaiting_pallet'] = (int)$r->fetch_assoc()['n'];
    $r = $db->query("SELECT COUNT(*) n FROM ph_pallets WHERE status IN ('open','full')");
    $s['pallets_in_store'] = (int)$r->fetch_assoc()['n'];
    $r = $db->query("SELECT COUNT(*) n FROM ph_pallets WHERE status='full'");
    $s['pallets_full'] = (int)$r->fetch_assoc()['n'];
    $r = $db->query("SELECT COUNT(*) n FROM ph_bales WHERE status='palletised'");
    $s['bales_in_store'] = (int)$r->fetch_assoc()['n'];
    $r = $db->query("SELECT COUNT(*) n FROM ph_dispatch WHERE status='dispatched'");
    $s['dispatches'] = (int)$r->fetch_assoc()['n'];
    ok($s);
}

// ── HOUSES / LINES ────────────────────────────────────────────────────────────
if ($action === 'houses') {
    $r = $db->query("SELECT * FROM ph_houses WHERE active=1 ORDER BY code");
    ok($r->fetch_all(MYSQLI_ASSOC));
}

if ($action === 'lines') {
    $hid = (int)($_GET['house_id'] ?? 0);
    $r = $db->query("SELECT * FROM ph_lines WHERE house_id=$hid AND active=1 ORDER BY line_no");
    ok($r->fetch_all(MYSQLI_ASSOC));
}

// ── RECLASSIFY ────────────────────────────────────────────────────────────────
if ($action === 'reclassify' && $method === 'POST') {
    $b = body();
    need($b, ['ph_barcode', 'source_barcode', 'house_id']);
    $phbc = $db->real_escape_string(trim($b['ph_barcode']));
    $src  = trim($b['source_barcode']);
    $hid  = (int)$b['house_id'];

    $hx = $db->query("SELECT id FROM ph_houses WHERE id=$hid AND active=1 LIMIT 1");
    if (!$hx || !$hx->num_rows) err('Packing house not found');

    $ex = $db->query("SELECT id FROM ph_bales WHERE ph_barcode='$phbc' LIMIT 1");
    if ($ex && $ex->num_rows) err("Packing-house barcode '$phbc' already used", 409);

    $floor = ph_lookup_floor_bale($db, $src);
    if (!$floor) err("Bale '$src' not found, or it has not been sold yet (no buyer grade)", 404);

    $srcE   = $db->real_escape_string($src);
    $gradeE = $db->real_escape_string($floor['buyer_grade'] ?? '');
    $growE  = $db->real_escape_string($floor['grower_num'] ?? '');
    $sbid   = (int)($floor['sold_balesid'] ?? 0);
    $bgid   = (int)($floor['buyer_gradesid'] ?? 0);
    $mass   = (float)($floor['mass'] ?? 0);

    $db->query("INSERT INTO ph_bales
        (ph_barcode,source_barcode,sold_balesid,buyer_gradesid,buyer_grade,grower_num,mass,house_id,reclassified_by)
        VALUES ('$phbc','$srcE',$sbid,$bgid,'$gradeE','$growE',$mass,$hid,$uid)");
    if ($db->error) err('Reclassify failed: ' . $db->error);
    ok(['id' => $db->insert_id, 'ph_barcode' => $phbc, 'buyer_grade' => $floor['buyer_grade'],
        'grower_num' => $floor['grower_num'], 'mass' => $mass], 'Bale reclassified');
}

// ── PALLETS ───────────────────────────────────────────────────────────────────
if ($action === 'pallet_create' && $method === 'POST') {
    $b = body();
    need($b, ['pallet_no', 'house_id']);
    $pno = $db->real_escape_string(trim($b['pallet_no']));
    $hid = (int)$b['house_id'];
    $lid = (int)($b['line_id'] ?? 0);

    $ex = $db->query("SELECT id FROM ph_pallets WHERE pallet_no='$pno' LIMIT 1");
    if ($ex && $ex->num_rows) err("Pallet number '$pno' already exists", 409);

    // A pallet's buyer grade is set by the first bale added; allow seeding with one bale
    $bgid = 0; $grade = '';
    if (!empty($b['ph_barcode'])) {
        $seed = $db->query("SELECT buyer_gradesid, buyer_grade, status FROM ph_bales
                            WHERE ph_barcode='" . $db->real_escape_string(trim($b['ph_barcode'])) . "' LIMIT 1");
        if ($seed && $seed->num_rows) {
            $row = $seed->fetch_assoc();
            $bgid = (int)$row['buyer_gradesid'];
            $grade = $row['buyer_grade'];
        }
    }
    $gradeE = $db->real_escape_string($grade);
    $lidSql = $lid > 0 ? $lid : 'NULL';
    $db->query("INSERT INTO ph_pallets (pallet_no,house_id,line_id,buyer_gradesid,buyer_grade,created_by)
                VALUES ('$pno',$hid,$lidSql,$bgid,'$gradeE',$uid)");
    if ($db->error) err('Pallet creation failed: ' . $db->error);
    $pid = $db->insert_id;

    $msg = 'Pallet created';
    if (!empty($b['ph_barcode'])) {
        $res = ph_add_bale_to_pallet($db, $pid, trim($b['ph_barcode']));
        if (!$res['ok']) err($res['msg']);
        $msg .= ' — 1 bale added';
    }
    ok(['id' => $pid, 'pallet_no' => $pno], $msg);
}

// Shared add-bale logic with the 12-bale + same-grade rules
function ph_add_bale_to_pallet(mysqli $db, int $pid, string $phbc): array {
    $phbcE = $db->real_escape_string(trim($phbc));
    $pr = $db->query("SELECT * FROM ph_pallets WHERE id=$pid LIMIT 1");
    if (!$pr || !$pr->num_rows) return ['ok' => false, 'msg' => 'Pallet not found'];
    $pallet = $pr->fetch_assoc();
    if ($pallet['status'] === 'dispatched') return ['ok' => false, 'msg' => 'Pallet already dispatched'];
    if ((int)$pallet['bale_count'] >= PH_PALLET_CAPACITY)
        return ['ok' => false, 'msg' => 'Pallet is full (' . PH_PALLET_CAPACITY . ' bales)'];

    $br = $db->query("SELECT * FROM ph_bales WHERE ph_barcode='$phbcE' LIMIT 1");
    if (!$br || !$br->num_rows) return ['ok' => false, 'msg' => "Bale '$phbc' not reclassified yet"];
    $bale = $br->fetch_assoc();
    if ($bale['status'] === 'dispatched')   return ['ok' => false, 'msg' => 'Bale already dispatched'];
    if (!empty($bale['pallet_id']))         return ['ok' => false, 'msg' => 'Bale already on a pallet'];

    // Same buyer grade rule — pallet inherits grade from its first bale
    $pBg = (int)$pallet['buyer_gradesid'];
    $bBg = (int)$bale['buyer_gradesid'];
    if ($pBg === 0) {
        $gE = $db->real_escape_string($bale['buyer_grade']);
        $db->query("UPDATE ph_pallets SET buyer_gradesid=$bBg, buyer_grade='$gE' WHERE id=$pid");
        $pBg = $bBg;
    } elseif ($pBg !== $bBg) {
        return ['ok' => false, 'msg' => 'Different buyer grade — pallet holds ' . $pallet['buyer_grade']
                . ', bale is ' . $bale['buyer_grade']];
    }

    $db->query("UPDATE ph_bales SET pallet_id=$pid, status='palletised' WHERE id={$bale['id']}");
    $db->query("UPDATE ph_pallets SET bale_count=bale_count+1 WHERE id=$pid");
    $newCount = (int)$pallet['bale_count'] + 1;
    if ($newCount >= PH_PALLET_CAPACITY)
        $db->query("UPDATE ph_pallets SET status='full' WHERE id=$pid");
    return ['ok' => true, 'count' => $newCount, 'full' => $newCount >= PH_PALLET_CAPACITY];
}

if ($action === 'pallet_add_bale' && $method === 'POST') {
    $b = body();
    need($b, ['ph_barcode']);
    $pid = (int)($b['pallet_id'] ?? 0);
    if (!$pid && !empty($b['pallet_no'])) {
        $pno = $db->real_escape_string(trim($b['pallet_no']));
        $pr = $db->query("SELECT id FROM ph_pallets WHERE pallet_no='$pno' LIMIT 1");
        if ($pr && $pr->num_rows) $pid = (int)$pr->fetch_assoc()['id'];
    }
    if (!$pid) err('Pallet not specified');
    $res = ph_add_bale_to_pallet($db, $pid, $b['ph_barcode']);
    if (!$res['ok']) err($res['msg']);
    ok(['count' => $res['count'], 'full' => $res['full']],
       $res['full'] ? 'Bale added — pallet now full (12)' : 'Bale added (' . $res['count'] . '/12)');
}

if ($action === 'pallet_assign_line' && $method === 'POST') {
    $b = body();
    need($b, ['pallet_id', 'line_id']);
    $pid = (int)$b['pallet_id'];
    $lid = (int)$b['line_id'];
    $lx = $db->query("SELECT id FROM ph_lines WHERE id=$lid AND active=1 LIMIT 1");
    if (!$lx || !$lx->num_rows) err('Line not found');
    $db->query("UPDATE ph_pallets SET line_id=$lid WHERE id=$pid");
    if ($db->affected_rows === 0) err('Pallet not found', 404);
    ok(null, 'Pallet assigned to line');
}

if ($action === 'pallet_detail') {
    $pid = (int)($_GET['id'] ?? 0);
    if (!$pid && !empty($_GET['pallet_no'])) {
        $pno = $db->real_escape_string(trim($_GET['pallet_no']));
        $pr = $db->query("SELECT id FROM ph_pallets WHERE pallet_no='$pno' LIMIT 1");
        if ($pr && $pr->num_rows) $pid = (int)$pr->fetch_assoc()['id'];
    }
    if (!$pid) err('Pallet not found', 404);
    $r = $db->query("SELECT p.*, h.code house_code, h.name house_name, h.location house_location, l.line_no
                     FROM ph_pallets p JOIN ph_houses h ON h.id=p.house_id
                     LEFT JOIN ph_lines l ON l.id=p.line_id WHERE p.id=$pid LIMIT 1");
    if (!$r || !$r->num_rows) err('Pallet not found', 404);
    $pallet = $r->fetch_assoc();
    $bales = $db->query("SELECT id, ph_barcode, source_barcode, buyer_grade, grower_num, mass
                         FROM ph_bales WHERE pallet_id=$pid ORDER BY id");
    $pallet['bales'] = $bales->fetch_all(MYSQLI_ASSOC);
    ok($pallet);
}

if ($action === 'pallet_list') {
    $hid    = (int)($_GET['house_id'] ?? 0);
    $status = $db->real_escape_string($_GET['status'] ?? '');
    $where  = '1=1';
    if ($hid > 0)       $where .= " AND p.house_id=$hid";
    if ($status !== '') $where .= " AND p.status='$status'";
    $r = $db->query("SELECT p.*, h.code house_code, l.line_no
                     FROM ph_pallets p JOIN ph_houses h ON h.id=p.house_id
                     LEFT JOIN ph_lines l ON l.id=p.line_id
                     WHERE $where ORDER BY p.id DESC LIMIT 300");
    ok($r->fetch_all(MYSQLI_ASSOC));
}

// ── LOCATE A BALE ─────────────────────────────────────────────────────────────
if ($action === 'bale_locate') {
    $q = trim($_GET['q'] ?? $_GET['barcode'] ?? '');
    if ($q === '') err('barcode required');
    $loc = ph_locate_bale($db, $q);
    if (!$loc) err('Bale not found in any packing house', 404);
    $loc['location_label'] = trim(
        $loc['house_name'] . ($loc['house_location'] ? ' · ' . $loc['house_location'] : '')
        . ($loc['pallet_no'] ? ' · Pallet ' . $loc['pallet_no'] : '')
        . ($loc['line_no'] ? ' · Line ' . $loc['line_no'] : ''));
    ok($loc);
}

// ── DISPATCH PALLETS TO PROCESSING ────────────────────────────────────────────
if ($action === 'dispatch_create' && $method === 'POST') {
    $b = body();
    need($b, ['house_id']);
    $hid  = (int)$b['house_id'];
    $veh  = $db->real_escape_string(trim($b['vehicle'] ?? ''));
    $dest = $db->real_escape_string(trim($b['destination'] ?? 'Processing'));
    $no   = ph_next_dispatch_no($db);
    $db->query("INSERT INTO ph_dispatch (dispatch_no,house_id,vehicle,destination,dispatched_by)
                VALUES ('$no',$hid,'$veh','$dest',$uid)");
    if ($db->error) err('Dispatch creation failed: ' . $db->error);
    ok(['id' => $db->insert_id, 'dispatch_no' => $no], 'Dispatch started');
}

if ($action === 'dispatch_add_pallet' && $method === 'POST') {
    $b = body();
    need($b, ['dispatch_id']);
    $did = (int)$b['dispatch_id'];
    $pid = (int)($b['pallet_id'] ?? 0);
    if (!$pid && !empty($b['pallet_no'])) {
        $pno = $db->real_escape_string(trim($b['pallet_no']));
        $pr = $db->query("SELECT id FROM ph_pallets WHERE pallet_no='$pno' LIMIT 1");
        if ($pr && $pr->num_rows) $pid = (int)$pr->fetch_assoc()['id'];
    }
    if (!$pid) err('Pallet not found', 404);
    $pr = $db->query("SELECT status, bale_count FROM ph_pallets WHERE id=$pid LIMIT 1");
    if (!$pr || !$pr->num_rows) err('Pallet not found', 404);
    $p = $pr->fetch_assoc();
    if ($p['status'] === 'dispatched') err('Pallet already dispatched');
    $db->query("INSERT IGNORE INTO ph_dispatch_pallets (dispatch_id,pallet_id) VALUES ($did,$pid)");
    if ($db->affected_rows === 0) err('Pallet already on this dispatch');
    ok(['bales' => (int)$p['bale_count']], 'Pallet added to dispatch');
}

if ($action === 'dispatch_send' && $method === 'POST') {
    $b = body();
    need($b, ['dispatch_id']);
    $did = (int)$b['dispatch_id'];
    $dr = $db->query("SELECT status FROM ph_dispatch WHERE id=$did LIMIT 1");
    if (!$dr || !$dr->num_rows) err('Dispatch not found', 404);
    if ($dr->fetch_assoc()['status'] === 'dispatched') err('Already dispatched');

    $pids = [];
    $pr = $db->query("SELECT pallet_id FROM ph_dispatch_pallets WHERE dispatch_id=$did");
    while ($row = $pr->fetch_assoc()) $pids[] = (int)$row['pallet_id'];
    if (empty($pids)) err('No pallets on this dispatch');
    $idlist = implode(',', $pids);

    $bc = (int)$db->query("SELECT COUNT(*) n FROM ph_bales WHERE pallet_id IN ($idlist)")->fetch_assoc()['n'];
    $handed = ph_handoff_to_processing($db, $pids);   // create proc_intake rows
    $db->query("UPDATE ph_pallets SET status='dispatched' WHERE id IN ($idlist)");
    $db->query("UPDATE ph_bales   SET status='dispatched' WHERE pallet_id IN ($idlist)");
    $db->query("UPDATE ph_dispatch SET status='dispatched', dispatched_at=NOW(),
                  pallet_count=" . count($pids) . ", bale_count=$bc WHERE id=$did");
    ok(['pallets' => count($pids), 'bales' => $bc, 'to_processing' => $handed],
       'Dispatched to processing — ' . $handed . ' bale(s) now at the plant');
}

err("Unknown action: $action");
