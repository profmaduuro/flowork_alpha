<?php
// Tobacco processing module — shared schema + helpers.
// Used by the web pages (processing.php, proc_*.php) and the mobile API
// (api/modules/processing.php) so both sides see identical tables.
//
// Flow: bales bought on the floor arrive at the processing plant
//   intake (scan + weigh)  →  batch (blend/threshing run)
//   →  outputs (lamina / stems / scrap / fines)  →  cartons (export-ready)

function proc_ensure_tables(mysqli $c): void {
    // Bales received at the processing plant
    $c->query("CREATE TABLE IF NOT EXISTS proc_intake (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barcode VARCHAR(100) NOT NULL,
        grower_num VARCHAR(100) DEFAULT '',
        timb_grade VARCHAR(50) DEFAULT '',
        mass DECIMAL(10,2) NOT NULL DEFAULT 0,
        source VARCHAR(120) DEFAULT '',
        batch_id INT DEFAULT NULL,
        status ENUM('received','allocated','processed') DEFAULT 'received',
        seasonid INT DEFAULT 0,
        selling_pointid INT DEFAULT 0,
        received_by INT DEFAULT 0,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_proc_barcode (barcode),
        INDEX(batch_id), INDEX(status)
    )");

    // Processing runs (blend / threshing batches)
    $c->query("CREATE TABLE IF NOT EXISTS proc_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_num VARCHAR(40) NOT NULL UNIQUE,
        blend_code VARCHAR(40) DEFAULT '',
        description VARCHAR(200) DEFAULT '',
        status ENUM('open','running','completed') DEFAULT 'open',
        input_mass DECIMAL(12,2) DEFAULT 0,
        bale_count INT DEFAULT 0,
        started_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        seasonid INT DEFAULT 0,
        created_by INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(status)
    )");

    // Weighed outputs per batch
    $c->query("CREATE TABLE IF NOT EXISTS proc_outputs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        output_type ENUM('lamina','stem','scrap','fines') NOT NULL,
        mass DECIMAL(12,2) NOT NULL DEFAULT 0,
        notes VARCHAR(200) DEFAULT '',
        recorded_by INT DEFAULT 0,
        recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(batch_id)
    )");

    // Export cartons packed from batch outputs
    $c->query("CREATE TABLE IF NOT EXISTS proc_cartons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        carton_num VARCHAR(40) NOT NULL UNIQUE,
        batch_id INT NOT NULL,
        content_type ENUM('lamina','stem') DEFAULT 'lamina',
        blend_code VARCHAR(40) DEFAULT '',
        net_mass DECIMAL(10,2) NOT NULL DEFAULT 0,
        gross_mass DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('packed','staged','shipped') DEFAULT 'packed',
        destination VARCHAR(120) DEFAULT '',
        shipment_id INT DEFAULT NULL,
        packed_by INT DEFAULT 0,
        packed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(batch_id), INDEX(status), INDEX(shipment_id)
    )");
    // Older installs: add shipment_id if the table predates this column
    $hasCol = $c->query("SHOW COLUMNS FROM proc_cartons LIKE 'shipment_id'");
    if ($hasCol && $hasCol !== true && $hasCol->num_rows === 0) {
        $c->query("ALTER TABLE proc_cartons ADD COLUMN shipment_id INT DEFAULT NULL, ADD INDEX(shipment_id)");
    }

    // Export shipments — a container/consignment of packed cartons leaving the plant
    $c->query("CREATE TABLE IF NOT EXISTS proc_shipments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_no VARCHAR(40) NOT NULL UNIQUE,
        container_no VARCHAR(40) DEFAULT '',
        vessel VARCHAR(120) DEFAULT '',
        consignee VARCHAR(160) DEFAULT '',
        destination VARCHAR(120) DEFAULT '',
        booking_ref VARCHAR(80) DEFAULT '',
        status ENUM('loading','sealed','shipped') DEFAULT 'loading',
        carton_count INT DEFAULT 0,
        net_mass DECIMAL(12,2) DEFAULT 0,
        gross_mass DECIMAL(12,2) DEFAULT 0,
        seasonid INT DEFAULT 0,
        created_by INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sealed_at DATETIME DEFAULT NULL,
        shipped_at DATETIME DEFAULT NULL,
        INDEX(status)
    )");
}

// Recompute a shipment's denormalized carton/mass totals from its cartons.
function proc_shipment_refresh(mysqli $c, int $shipmentId): array {
    $c->query("UPDATE proc_shipments s SET
        carton_count = (SELECT COUNT(*)              FROM proc_cartons WHERE shipment_id=$shipmentId),
        net_mass     = (SELECT COALESCE(SUM(net_mass),0)   FROM proc_cartons WHERE shipment_id=$shipmentId),
        gross_mass   = (SELECT COALESCE(SUM(gross_mass),0) FROM proc_cartons WHERE shipment_id=$shipmentId)
      WHERE s.id=$shipmentId");
    $r = $c->query("SELECT carton_count,net_mass,gross_mass FROM proc_shipments WHERE id=$shipmentId LIMIT 1");
    return ($r && $r !== true && $r->num_rows) ? $r->fetch_assoc()
        : ['carton_count'=>0,'net_mass'=>0,'gross_mass'=>0];
}

// PB-2026-0007 style sequential numbers
function proc_next_number(mysqli $c, string $table, string $col, string $prefix): string {
    $year = date('Y');
    $like = $c->real_escape_string("$prefix-$year-") . '%';
    $r = $c->query("SELECT $col FROM $table WHERE $col LIKE '$like' ORDER BY id DESC LIMIT 1");
    $seq = 1;
    if ($r && $r !== true && $r->num_rows > 0) {
        $last = $r->fetch_assoc()[$col];
        $seq  = (int)substr($last, strrpos($last, '-') + 1) + 1;
    }
    return sprintf('%s-%s-%04d', $prefix, $year, $seq);
}

// Active season id (0 if none)
function proc_seasonid(mysqli $c): int {
    $r = $c->query("SELECT id FROM seasons WHERE active=1 LIMIT 1");
    return ($r && $r !== true && $r->num_rows > 0) ? (int)$r->fetch_assoc()['id'] : 0;
}

// Full chain of custody for a carton number or any bale barcode.
// Resolves the query to a batch, then walks both directions:
//   floor sale → packing house (pallet/dispatch) → intake → batch → outputs → cartons → shipment
function proc_trace(mysqli $c, string $q): array {
    $qE  = $c->real_escape_string(trim($q));
    $out = ['query' => $q, 'matched' => 'none', 'batch' => null,
            'bales' => [], 'outputs' => [], 'cartons' => []];

    $batchId = 0; $focusCarton = '';

    // 1) Carton number?
    $r = $c->query("SELECT batch_id, carton_num FROM proc_cartons WHERE carton_num='$qE' LIMIT 1");
    if ($r && $r !== true && $r->num_rows) {
        $row = $r->fetch_assoc();
        $batchId = (int)$row['batch_id']; $focusCarton = $row['carton_num'];
        $out['matched'] = 'carton';
    }
    // 2) Bale barcode — directly the intake (PH) barcode?
    if (!$batchId) {
        $r = $c->query("SELECT batch_id FROM proc_intake WHERE barcode='$qE' LIMIT 1");
        if ($r && $r !== true && $r->num_rows) {
            $batchId = (int)$r->fetch_assoc()['batch_id'];
            $out['matched'] = $batchId ? 'bale' : 'bale_unbatched';
        }
    }
    // 3) Packing-house barcode or original floor barcode → resolve via ph_bales,
    //    then forward to its processing intake/batch if it has been handed over.
    if (!$batchId && $out['matched'] === 'none') {
        $r = $c->query("SELECT ph_barcode, status FROM ph_bales
                        WHERE ph_barcode='$qE' OR source_barcode='$qE' LIMIT 1");
        if ($r && $r !== true && $r->num_rows) {
            $phb  = $c->real_escape_string($r->fetch_assoc()['ph_barcode']);
            $ir   = $c->query("SELECT batch_id FROM proc_intake WHERE barcode='$phb' LIMIT 1");
            if ($ir && $ir->num_rows) {
                $batchId = (int)$ir->fetch_assoc()['batch_id'];
                $out['matched'] = $batchId ? 'bale' : 'bale_unbatched';
            } else {
                $out['matched'] = 'bale_in_packhouse';   // not yet dispatched to plant
            }
        }
    }

    if (!$batchId) {
        $out['focus_carton'] = $focusCarton;
        return $out;
    }
    $out['focus_carton'] = $focusCarton;

    // Batch header
    $r = $c->query("SELECT id,batch_num,blend_code,description,status,input_mass,bale_count,
                           started_at,completed_at FROM proc_batches WHERE id=$batchId LIMIT 1");
    $out['batch'] = ($r && $r->num_rows) ? $r->fetch_assoc() : null;

    // Outputs
    $r = $c->query("SELECT output_type, SUM(mass) mass FROM proc_outputs
                    WHERE batch_id=$batchId GROUP BY output_type");
    if ($r && $r !== true) while ($o = $r->fetch_assoc()) $out['outputs'][] = $o;

    // Cartons (+ shipment)
    $r = $c->query("SELECT c.carton_num,c.content_type,c.blend_code,c.net_mass,c.gross_mass,c.status,
                           s.shipment_no,s.container_no,s.vessel,s.destination ship_dest,s.status ship_status
                    FROM proc_cartons c
                    LEFT JOIN proc_shipments s ON s.id=c.shipment_id
                    WHERE c.batch_id=$batchId ORDER BY c.carton_num");
    if ($r && $r !== true) while ($cn = $r->fetch_assoc()) $out['cartons'][] = $cn;

    // Intake bales of this batch, with packing-house + floor lineage
    $r = $c->query("
        SELECT pi.barcode, pi.grower_num, pi.timb_grade, pi.mass, pi.source,
               pb.source_barcode floor_barcode, pb.buyer_grade,
               h.code house_code, p.pallet_no, l.line_no,
               dp_disp.dispatch_no, dp_disp.vehicle
        FROM proc_intake pi
        LEFT JOIN ph_bales  pb ON pb.ph_barcode = pi.barcode
        LEFT JOIN ph_houses h  ON h.id = pb.house_id
        LEFT JOIN ph_pallets p ON p.id = pb.pallet_id
        LEFT JOIN ph_lines   l ON l.id = p.line_id
        LEFT JOIN (
            SELECT dpx.pallet_id, d.dispatch_no, d.vehicle
            FROM ph_dispatch_pallets dpx JOIN ph_dispatch d ON d.id=dpx.dispatch_id
        ) dp_disp ON dp_disp.pallet_id = pb.pallet_id
        WHERE pi.batch_id=$batchId ORDER BY pi.barcode");
    if ($r && $r !== true) while ($bl = $r->fetch_assoc()) $out['bales'][] = $bl;

    return $out;
}

// Mass-balance summary for one batch: input, outputs by type, yield %
function proc_batch_stats(mysqli $c, int $batchId): array {
    $stats = ['input' => 0.0, 'output_total' => 0.0, 'outputs' => [], 'cartons' => 0, 'carton_mass' => 0.0];
    $r = $c->query("SELECT input_mass FROM proc_batches WHERE id=$batchId LIMIT 1");
    if ($r && $r !== true && $r->num_rows > 0) $stats['input'] = (float)$r->fetch_assoc()['input_mass'];
    $r = $c->query("SELECT output_type, SUM(mass) m FROM proc_outputs WHERE batch_id=$batchId GROUP BY output_type");
    if ($r && $r !== true) {
        while ($row = $r->fetch_assoc()) {
            $stats['outputs'][$row['output_type']] = (float)$row['m'];
            $stats['output_total'] += (float)$row['m'];
        }
    }
    $r = $c->query("SELECT COUNT(*) n, COALESCE(SUM(net_mass),0) m FROM proc_cartons WHERE batch_id=$batchId");
    if ($r && $r !== true && $r->num_rows > 0) {
        $row = $r->fetch_assoc();
        $stats['cartons']     = (int)$row['n'];
        $stats['carton_mass'] = (float)$row['m'];
    }
    $stats['yield_pct'] = $stats['input'] > 0 ? round($stats['output_total'] / $stats['input'] * 100, 1) : 0;
    $stats['waste']     = max(0, round($stats['input'] - $stats['output_total'], 2));
    return $stats;
}
