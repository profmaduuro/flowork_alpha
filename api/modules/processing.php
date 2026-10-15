<?php
// Tobacco processing module — mobile API
// GET  ?module=processing&action=summary
// GET  ?module=processing&action=intake_list[&status=][&q=]
// POST ?module=processing&action=intake_add      {barcode, mass, timb_grade?, grower_num?, source?}
// GET  ?module=processing&action=batch_list[&status=]
// GET  ?module=processing&action=batch_detail&id=
// POST ?module=processing&action=batch_create    {blend_code?, description?}
// POST ?module=processing&action=batch_add_bales {batch_id, barcodes:[..]}
// POST ?module=processing&action=batch_start     {batch_id}
// POST ?module=processing&action=batch_output    {batch_id, output_type, mass, notes?}
// POST ?module=processing&action=batch_complete  {batch_id}
// GET  ?module=processing&action=carton_list[&status=][&batch_id=]
// POST ?module=processing&action=carton_pack     {batch_id, content_type, net_mass, gross_mass?, blend_code?, destination?}
// POST ?module=processing&action=carton_status   {id, status}

require_once __DIR__ . '/../../includes/proc_schema.php';

$action = $_GET['action'] ?? 'summary';
$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();
$user   = requireAuth();
$uid    = (int)$user['id'];
proc_ensure_tables($db);
$seasonid = proc_seasonid($db);

// ── SUMMARY ───────────────────────────────────────────────────────────────────
if ($action === 'summary') {
    $s = [];
    $r = $db->query("SELECT COUNT(*) n, COALESCE(SUM(mass),0) m FROM proc_intake WHERE status='received'");
    $row = $r->fetch_assoc(); $s['intake_bales'] = (int)$row['n']; $s['intake_mass'] = (float)$row['m'];
    $r = $db->query("SELECT COUNT(*) n FROM proc_batches WHERE status IN ('open','running')");
    $s['active_batches'] = (int)$r->fetch_assoc()['n'];
    $r = $db->query("SELECT COUNT(*) n, COALESCE(SUM(net_mass),0) m FROM proc_cartons WHERE status IN ('packed','staged')");
    $row = $r->fetch_assoc(); $s['cartons_ready'] = (int)$row['n']; $s['carton_mass'] = (float)$row['m'];
    $r = $db->query("SELECT COALESCE(SUM(input_mass),0) i FROM proc_batches WHERE status='completed'");
    $s['processed_mass'] = (float)$r->fetch_assoc()['i'];
    ok($s);
}

// ── INTAKE ────────────────────────────────────────────────────────────────────
if ($action === 'intake_list') {
    $status = $db->real_escape_string($_GET['status'] ?? '');
    $q      = $db->real_escape_string(trim($_GET['q'] ?? ''));
    $where  = '1=1';
    if ($status !== '') $where .= " AND i.status='$status'";
    if ($q !== '')      $where .= " AND (i.barcode LIKE '%$q%' OR i.grower_num LIKE '%$q%')";
    $r = $db->query("SELECT i.*, b.batch_num FROM proc_intake i
                     LEFT JOIN proc_batches b ON b.id=i.batch_id
                     WHERE $where ORDER BY i.id DESC LIMIT 300");
    ok($r->fetch_all(MYSQLI_ASSOC));
}

if ($action === 'intake_add' && $method === 'POST') {
    $b = body();
    need($b, ['barcode', 'mass']);
    $barcode = $db->real_escape_string(trim($b['barcode']));
    $mass    = (float)$b['mass'];
    if ($mass <= 0) err('Mass must be greater than zero');
    $grade   = $db->real_escape_string(trim($b['timb_grade'] ?? ''));
    $grower  = $db->real_escape_string(trim($b['grower_num'] ?? ''));
    $source  = $db->real_escape_string(trim($b['source'] ?? ''));

    $ex = $db->query("SELECT id FROM proc_intake WHERE barcode='$barcode' LIMIT 1");
    if ($ex && $ex->num_rows > 0) err("Bale '$barcode' already received", 409);

    $db->query("INSERT INTO proc_intake (barcode,grower_num,timb_grade,mass,source,seasonid,received_by)
                VALUES ('$barcode','$grower','$grade',$mass,'$source',$seasonid,$uid)");
    if ($db->error) err('Intake failed: ' . $db->error);
    ok(['id' => $db->insert_id, 'barcode' => $barcode], 'Bale received');
}

// ── BATCHES ───────────────────────────────────────────────────────────────────
if ($action === 'batch_list') {
    $status = $db->real_escape_string($_GET['status'] ?? '');
    $where  = $status !== '' ? "WHERE b.status='$status'" : '';
    $r = $db->query("SELECT b.*,
                       (SELECT COALESCE(SUM(mass),0) FROM proc_outputs o WHERE o.batch_id=b.id) output_mass,
                       (SELECT COUNT(*) FROM proc_cartons c WHERE c.batch_id=b.id) carton_count
                     FROM proc_batches b $where ORDER BY b.id DESC LIMIT 200");
    ok($r->fetch_all(MYSQLI_ASSOC));
}

if ($action === 'batch_detail') {
    $id = (int)($_GET['id'] ?? 0);
    $r  = $db->query("SELECT * FROM proc_batches WHERE id=$id LIMIT 1");
    if (!$r || !$r->num_rows) err('Batch not found', 404);
    $batch = $r->fetch_assoc();
    $batch['stats']   = proc_batch_stats($db, $id);
    $bales = $db->query("SELECT id,barcode,grower_num,timb_grade,mass FROM proc_intake WHERE batch_id=$id ORDER BY id");
    $batch['bales']   = $bales->fetch_all(MYSQLI_ASSOC);
    $outs  = $db->query("SELECT * FROM proc_outputs WHERE batch_id=$id ORDER BY id DESC");
    $batch['outputs'] = $outs->fetch_all(MYSQLI_ASSOC);
    ok($batch);
}

if ($action === 'batch_create' && $method === 'POST') {
    $b     = body();
    $blend = $db->real_escape_string(trim($b['blend_code'] ?? ''));
    $desc  = $db->real_escape_string(trim($b['description'] ?? ''));
    $num   = proc_next_number($db, 'proc_batches', 'batch_num', 'PB');
    $db->query("INSERT INTO proc_batches (batch_num,blend_code,description,seasonid,created_by)
                VALUES ('$num','$blend','$desc',$seasonid,$uid)");
    if ($db->error) err('Batch creation failed: ' . $db->error);
    ok(['id' => $db->insert_id, 'batch_num' => $num], 'Batch created');
}

if ($action === 'batch_add_bales' && $method === 'POST') {
    $b = body();
    need($b, ['batch_id']);
    $bid = (int)$b['batch_id'];
    $r = $db->query("SELECT status FROM proc_batches WHERE id=$bid LIMIT 1");
    if (!$r || !$r->num_rows) err('Batch not found', 404);
    if ($r->fetch_assoc()['status'] === 'completed') err('Batch already completed');

    $barcodes = $b['barcodes'] ?? [];
    if (!is_array($barcodes) || empty($barcodes)) err('Field required: barcodes');
    $added = 0; $skipped = [];
    foreach ($barcodes as $raw) {
        $bc = $db->real_escape_string(trim((string)$raw));
        if ($bc === '') continue;
        $q = $db->query("SELECT id,status FROM proc_intake WHERE barcode='$bc' LIMIT 1");
        $row = ($q && $q->num_rows) ? $q->fetch_assoc() : null;
        if (!$row)                          { $skipped[] = "$bc (not received)"; continue; }
        if ($row['status'] !== 'received')  { $skipped[] = "$bc (already allocated)"; continue; }
        $db->query("UPDATE proc_intake SET batch_id=$bid, status='allocated' WHERE id={$row['id']}");
        $added++;
    }
    // Refresh denormalized batch totals
    $db->query("UPDATE proc_batches b SET
                  bale_count = (SELECT COUNT(*) FROM proc_intake WHERE batch_id=$bid),
                  input_mass = (SELECT COALESCE(SUM(mass),0) FROM proc_intake WHERE batch_id=$bid)
                WHERE b.id=$bid");
    ok(['added' => $added, 'skipped' => $skipped],
       $added . ' bale(s) added' . (count($skipped) ? ', ' . count($skipped) . ' skipped' : ''));
}

if ($action === 'batch_start' && $method === 'POST') {
    $b = body(); need($b, ['batch_id']);
    $bid = (int)$b['batch_id'];
    $db->query("UPDATE proc_batches SET status='running', started_at=NOW() WHERE id=$bid AND status='open'");
    if ($db->affected_rows === 0) err('Batch not found or not open');
    ok(null, 'Batch started');
}

if ($action === 'batch_output' && $method === 'POST') {
    $b = body();
    need($b, ['batch_id', 'output_type', 'mass']);
    $bid  = (int)$b['batch_id'];
    $type = $db->real_escape_string(trim($b['output_type']));
    $mass = (float)$b['mass'];
    if ($mass <= 0) err('Mass must be greater than zero');
    if (!in_array($type, ['lamina','stem','scrap','fines'], true)) err('Invalid output type');
    $r = $db->query("SELECT status FROM proc_batches WHERE id=$bid LIMIT 1");
    if (!$r || !$r->num_rows) err('Batch not found', 404);
    if ($r->fetch_assoc()['status'] === 'completed') err('Batch already completed');
    $notes = $db->real_escape_string(trim($b['notes'] ?? ''));
    $db->query("INSERT INTO proc_outputs (batch_id,output_type,mass,notes,recorded_by)
                VALUES ($bid,'$type',$mass,'$notes',$uid)");
    if ($db->error) err('Output failed: ' . $db->error);
    ok(['id' => $db->insert_id], 'Output recorded');
}

if ($action === 'batch_complete' && $method === 'POST') {
    $b = body(); need($b, ['batch_id']);
    $bid = (int)$b['batch_id'];
    $db->query("UPDATE proc_batches SET status='completed', completed_at=NOW()
                WHERE id=$bid AND status IN ('open','running')");
    if ($db->affected_rows === 0) err('Batch not found or already completed');
    $db->query("UPDATE proc_intake SET status='processed' WHERE batch_id=$bid");
    ok(proc_batch_stats($db, $bid), 'Batch completed');
}

// ── CARTONS ───────────────────────────────────────────────────────────────────
if ($action === 'carton_list') {
    $status = $db->real_escape_string($_GET['status'] ?? '');
    $bid    = (int)($_GET['batch_id'] ?? 0);
    $where  = '1=1';
    if ($status !== '') $where .= " AND c.status='$status'";
    if ($bid > 0)       $where .= " AND c.batch_id=$bid";
    $r = $db->query("SELECT c.*, b.batch_num, b.blend_code batch_blend FROM proc_cartons c
                     LEFT JOIN proc_batches b ON b.id=c.batch_id
                     WHERE $where ORDER BY c.id DESC LIMIT 300");
    ok($r->fetch_all(MYSQLI_ASSOC));
}

if ($action === 'carton_pack' && $method === 'POST') {
    $b = body();
    need($b, ['batch_id', 'net_mass']);
    $bid  = (int)$b['batch_id'];
    $net  = (float)$b['net_mass'];
    if ($net <= 0) err('Net mass must be greater than zero');
    $gross = (float)($b['gross_mass'] ?? 0);
    if ($gross > 0 && $gross < $net) err('Gross mass cannot be less than net mass');
    $type  = $db->real_escape_string(trim($b['content_type'] ?? 'lamina'));
    if (!in_array($type, ['lamina','stem'], true)) err('Invalid content type');

    $r = $db->query("SELECT blend_code FROM proc_batches WHERE id=$bid LIMIT 1");
    if (!$r || !$r->num_rows) err('Batch not found', 404);
    $batchBlend = $r->fetch_assoc()['blend_code'];
    $blend = $db->real_escape_string(trim($b['blend_code'] ?? '') ?: $batchBlend);
    $dest  = $db->real_escape_string(trim($b['destination'] ?? ''));
    $num   = proc_next_number($db, 'proc_cartons', 'carton_num', 'CT');

    $db->query("INSERT INTO proc_cartons (carton_num,batch_id,content_type,blend_code,net_mass,gross_mass,destination,packed_by)
                VALUES ('$num',$bid,'$type','$blend',$net,$gross,'$dest',$uid)");
    if ($db->error) err('Carton pack failed: ' . $db->error);
    ok(['id' => $db->insert_id, 'carton_num' => $num], 'Carton packed');
}

if ($action === 'carton_status' && $method === 'POST') {
    $b = body();
    need($b, ['id', 'status']);
    $id     = (int)$b['id'];
    $status = $db->real_escape_string(trim($b['status']));
    if (!in_array($status, ['packed','staged','shipped'], true)) err('Invalid status');
    $db->query("UPDATE proc_cartons SET status='$status' WHERE id=$id");
    if ($db->affected_rows === 0) err('Carton not found', 404);
    ok(null, 'Carton updated');
}

// ── EXPORT SHIPMENTS ───────────────────────────────────────────────────────────
// A shipment groups packed cartons into a container/consignment that leaves the
// plant for export. Flow: create (loading) → add cartons → seal → ship.
if ($action === 'shipment_list') {
    $status = $db->real_escape_string($_GET['status'] ?? '');
    $where  = $status !== '' ? "WHERE status='$status'" : '';
    $r = $db->query("SELECT * FROM proc_shipments $where ORDER BY id DESC LIMIT 200");
    ok($r->fetch_all(MYSQLI_ASSOC));
}

if ($action === 'shipment_detail') {
    $id = (int)($_GET['id'] ?? 0);
    $r = $db->query("SELECT * FROM proc_shipments WHERE id=$id LIMIT 1");
    if (!$r || !$r->num_rows) err('Shipment not found', 404);
    $head = $r->fetch_assoc();
    $cr = $db->query("SELECT c.id,c.carton_num,c.content_type,c.blend_code,c.net_mass,c.gross_mass,
                             c.status,b.batch_num
                      FROM proc_cartons c
                      LEFT JOIN proc_batches b ON b.id=c.batch_id
                      WHERE c.shipment_id=$id ORDER BY c.carton_num");
    $head['cartons'] = $cr ? $cr->fetch_all(MYSQLI_ASSOC) : [];
    ok($head);
}

if ($action === 'shipment_create' && $method === 'POST') {
    $b = body();
    $cont = $db->real_escape_string(trim($b['container_no'] ?? ''));
    $ves  = $db->real_escape_string(trim($b['vessel'] ?? ''));
    $cons = $db->real_escape_string(trim($b['consignee'] ?? ''));
    $dest = $db->real_escape_string(trim($b['destination'] ?? ''));
    $book = $db->real_escape_string(trim($b['booking_ref'] ?? ''));
    $num  = proc_next_number($db, 'proc_shipments', 'shipment_no', 'SH');
    $db->query("INSERT INTO proc_shipments (shipment_no,container_no,vessel,consignee,destination,booking_ref,seasonid,created_by)
                VALUES ('$num','$cont','$ves','$cons','$dest','$book',$seasonid,$uid)");
    if ($db->error) err('Shipment creation failed: ' . $db->error);
    ok(['id' => $db->insert_id, 'shipment_no' => $num], 'Shipment created');
}

if ($action === 'shipment_add_carton' && $method === 'POST') {
    $b = body();
    need($b, ['shipment_id']);
    $sid = (int)$b['shipment_id'];
    $sr  = $db->query("SELECT status FROM proc_shipments WHERE id=$sid LIMIT 1");
    if (!$sr || !$sr->num_rows) err('Shipment not found', 404);
    if ($sr->fetch_assoc()['status'] !== 'loading') err('Shipment is sealed — cannot add cartons');

    // accept carton_id or carton_num
    if (!empty($b['carton_id'])) {
        $cond = 'id=' . (int)$b['carton_id'];
    } elseif (!empty($b['carton_num'])) {
        $cond = "carton_num='" . $db->real_escape_string(trim($b['carton_num'])) . "'";
    } else {
        err('Field required: carton_id or carton_num');
    }
    $cr = $db->query("SELECT id,carton_num,status,shipment_id FROM proc_cartons WHERE $cond LIMIT 1");
    if (!$cr || !$cr->num_rows) err('Carton not found', 404);
    $c = $cr->fetch_assoc();
    if ($c['status'] === 'shipped')                          err('Carton already shipped');
    if (!empty($c['shipment_id']) && (int)$c['shipment_id'] !== $sid)
        err('Carton already on shipment #' . $c['shipment_id']);
    $db->query("UPDATE proc_cartons SET shipment_id=$sid, status='staged'
                WHERE id={$c['id']} AND (shipment_id IS NULL OR shipment_id=$sid)");
    if ($db->affected_rows === 0 && (int)$c['shipment_id'] !== $sid) err('Could not load carton');
    $t = proc_shipment_refresh($db, $sid);
    ok(['carton_num' => $c['carton_num'], 'totals' => $t], 'Carton loaded');
}

if ($action === 'shipment_remove_carton' && $method === 'POST') {
    $b = body();
    need($b, ['shipment_id', 'carton_id']);
    $sid = (int)$b['shipment_id']; $cid = (int)$b['carton_id'];
    $sr  = $db->query("SELECT status FROM proc_shipments WHERE id=$sid LIMIT 1");
    if (!$sr || !$sr->num_rows) err('Shipment not found', 404);
    if ($sr->fetch_assoc()['status'] !== 'loading') err('Shipment is sealed — cannot remove cartons');
    $db->query("UPDATE proc_cartons SET shipment_id=NULL, status='packed'
                WHERE id=$cid AND shipment_id=$sid AND status<>'shipped'");
    $t = proc_shipment_refresh($db, $sid);
    ok(['totals' => $t], 'Carton removed');
}

if ($action === 'shipment_seal' && $method === 'POST') {
    $b = body(); need($b, ['shipment_id']);
    $sid = (int)$b['shipment_id'];
    $t = proc_shipment_refresh($db, $sid);
    if ((int)$t['carton_count'] === 0) err('Cannot seal an empty shipment');
    $db->query("UPDATE proc_shipments SET status='sealed', sealed_at=NOW()
                WHERE id=$sid AND status='loading'");
    if ($db->affected_rows === 0) err('Shipment not found or not in loading state');
    ok($t, 'Shipment sealed');
}

if ($action === 'shipment_ship' && $method === 'POST') {
    $b = body(); need($b, ['shipment_id']);
    $sid = (int)$b['shipment_id'];
    $sr  = $db->query("SELECT status FROM proc_shipments WHERE id=$sid LIMIT 1");
    if (!$sr || !$sr->num_rows) err('Shipment not found', 404);
    if ($sr->fetch_assoc()['status'] === 'shipped') err('Already shipped');
    $db->query("UPDATE proc_cartons  SET status='shipped' WHERE shipment_id=$sid");
    $db->query("UPDATE proc_shipments SET status='shipped', shipped_at=NOW(),
                sealed_at=COALESCE(sealed_at,NOW()) WHERE id=$sid");
    $t = proc_shipment_refresh($db, $sid);
    ok($t, 'Shipment dispatched for export');
}

// ── TRACEABILITY ───────────────────────────────────────────────────────────────
// Full chain of custody for a carton (by carton_num) or a bale (by any barcode).
if ($action === 'trace') {
    $q = trim($_GET['q'] ?? ($_GET['barcode'] ?? ''));
    if ($q === '') err('Field required: q (carton number or bale barcode)');
    ok(proc_trace($db, $q));
}

err("Unknown action: $action");
