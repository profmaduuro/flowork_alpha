<?php
// Packing House module — shared schema + helpers.
// Used by the web pages (packhouse.php, ph_*.php) and the mobile API
// (api/modules/packhouse.php) so both sides see identical tables.
//
// Flow: bales dispatched from the floor arrive at a packing house
//   (TPZ / CRP / MTC / ZLT) →
//   RECLASSIFY  (scan floor bale, capture pre-printed packing-house barcode,
//                inherit the buyer grade captured at sale) →
//   PALLETISE   (12 bales of the SAME buyer grade per pallet, pallet no. from
//                a physical tag) →
//   LINE        (pallet placed in Line 1, Line 2 …) →
//   DISPATCH    (whole pallets loaded onto trucks → sent to Processing).
//
// A bale's location then reads: TPZ · Willovale · Pallet 1001 · Line 1.

const PH_PALLET_CAPACITY = 12;   // bales per pallet, single buyer grade

function ph_ensure_tables(mysqli $c): void {
    // Packing houses — neutral physical sites that store any buyer's tobacco
    $c->query("CREATE TABLE IF NOT EXISTS ph_houses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(120) DEFAULT '',
        location VARCHAR(120) DEFAULT '',
        active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Lines within a packing house
    $c->query("CREATE TABLE IF NOT EXISTS ph_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        house_id INT NOT NULL,
        line_no VARCHAR(20) NOT NULL,
        active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_house_line (house_id, line_no),
        INDEX(house_id)
    )");

    // Pallets — pallet_no comes from a physical tag; one buyer grade; max 12 bales
    $c->query("CREATE TABLE IF NOT EXISTS ph_pallets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pallet_no VARCHAR(40) NOT NULL UNIQUE,
        house_id INT NOT NULL,
        line_id INT DEFAULT NULL,
        buyer_gradesid INT DEFAULT NULL,
        buyer_grade VARCHAR(100) DEFAULT '',
        status ENUM('open','full','dispatched') DEFAULT 'open',
        bale_count INT DEFAULT 0,
        created_by INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(house_id), INDEX(line_id), INDEX(status)
    )");

    // Reclassified bales — ph_barcode is the pre-printed packing-house label
    $c->query("CREATE TABLE IF NOT EXISTS ph_bales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ph_barcode VARCHAR(100) NOT NULL UNIQUE,
        source_barcode VARCHAR(100) DEFAULT '',
        sold_balesid INT DEFAULT NULL,
        buyer_gradesid INT DEFAULT NULL,
        buyer_grade VARCHAR(100) DEFAULT '',
        grower_num VARCHAR(100) DEFAULT '',
        mass DECIMAL(10,2) DEFAULT 0,
        house_id INT NOT NULL,
        pallet_id INT DEFAULT NULL,
        status ENUM('reclassified','palletised','dispatched') DEFAULT 'reclassified',
        reclassified_by INT DEFAULT 0,
        reclassified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(house_id), INDEX(pallet_id), INDEX(status), INDEX(source_barcode)
    )");

    // Pallet dispatch to the processing plant
    $c->query("CREATE TABLE IF NOT EXISTS ph_dispatch (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dispatch_no VARCHAR(40) NOT NULL UNIQUE,
        house_id INT NOT NULL,
        vehicle VARCHAR(120) DEFAULT '',
        destination VARCHAR(120) DEFAULT '',
        status ENUM('loading','dispatched') DEFAULT 'loading',
        pallet_count INT DEFAULT 0,
        bale_count INT DEFAULT 0,
        dispatched_by INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        dispatched_at DATETIME DEFAULT NULL,
        INDEX(house_id), INDEX(status)
    )");

    // Which pallets are on which dispatch
    $c->query("CREATE TABLE IF NOT EXISTS ph_dispatch_pallets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dispatch_id INT NOT NULL,
        pallet_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_disp_pallet (dispatch_id, pallet_id),
        INDEX(dispatch_id), INDEX(pallet_id)
    )");
}

// PHD-2026-0007 style dispatch numbers
function ph_next_dispatch_no(mysqli $c): string {
    $year = date('Y');
    $like = $c->real_escape_string("PHD-$year-") . '%';
    $r = $c->query("SELECT dispatch_no FROM ph_dispatch WHERE dispatch_no LIKE '$like' ORDER BY id DESC LIMIT 1");
    $seq = 1;
    if ($r && $r !== true && $r->num_rows > 0) {
        $last = $r->fetch_assoc()['dispatch_no'];
        $seq  = (int)substr($last, strrpos($last, '-') + 1) + 1;
    }
    return sprintf('PHD-%s-%04d', $year, $seq);
}

// Look up a floor bale by its barcode and return sale/grade/grower/mass.
// Returns null if the bale doesn't exist or hasn't been sold (no buyer grade).
function ph_lookup_floor_bale(mysqli $c, string $barcode): ?array {
    $bc = $c->real_escape_string(trim($barcode));
    $r = $c->query("
        SELECT tk.barcode,
               sb.id AS sold_balesid, sb.buyer_gradesid,
               bg.description AS buyer_grade,
               COALESCE(NULLIF(b.buyer_code,''), b.name) AS buyer,
               g.grower_num,
               COALESCE(bm.mass,0) AS mass
        FROM tickets tk
        JOIN bale_junus bj            ON bj.id  = tk.bale_junusid
        LEFT JOIN bale_mass bm        ON bm.id  = bj.bale_massid
        JOIN growers g                ON g.id   = bj.growerid
        JOIN sold_bales sb            ON sb.ticketsid = tk.id
        LEFT JOIN buyer_grades bg     ON bg.id  = sb.buyer_gradesid
        LEFT JOIN buyer b             ON b.id   = bg.buyerid
        WHERE tk.barcode = '$bc' OR bm.temp_bacode = '$bc'
        LIMIT 1");
    if (!$r || $r === true || $r->num_rows === 0) return null;
    return $r->fetch_assoc();
}

// Hand dispatched pallet bales over to the processing plant: create a
// proc_intake row per bale so they appear as incoming stock for batching.
// Safe to call repeatedly — proc_intake.barcode is unique (INSERT IGNORE).
function ph_handoff_to_processing(mysqli $c, array $palletIds): int {
    if (empty($palletIds)) return 0;
    require_once __DIR__ . '/proc_schema.php';
    proc_ensure_tables($c);
    $seasonid = proc_seasonid($c);
    $idlist = implode(',', array_map('intval', $palletIds));
    $r = $c->query("SELECT pb.ph_barcode, pb.grower_num, pb.buyer_grade, pb.mass,
                           h.code house_code, p.pallet_no
                    FROM ph_bales pb
                    JOIN ph_pallets p ON p.id = pb.pallet_id
                    JOIN ph_houses h  ON h.id = pb.house_id
                    WHERE pb.pallet_id IN ($idlist)");
    if (!$r || $r === true) return 0;
    $n = 0;
    while ($row = $r->fetch_assoc()) {
        $bc  = $c->real_escape_string($row['ph_barcode']);
        $gr  = $c->real_escape_string($row['grower_num']);
        $gd  = $c->real_escape_string($row['buyer_grade']);
        $src = $c->real_escape_string('PH:' . $row['house_code'] . ' Pallet ' . $row['pallet_no']);
        $m   = (float)$row['mass'];
        $c->query("INSERT IGNORE INTO proc_intake (barcode,grower_num,timb_grade,mass,source,seasonid)
                   VALUES ('$bc','$gr','$gd',$m,'$src',$seasonid)");
        if ($c->affected_rows > 0) $n++;
    }
    return $n;
}

// Locate a bale anywhere in the packing houses by ph or floor barcode.
function ph_locate_bale(mysqli $c, string $barcode): ?array {
    $bc = $c->real_escape_string(trim($barcode));
    $r = $c->query("
        SELECT pb.*, h.code house_code, h.name house_name, h.location house_location,
               p.pallet_no, l.line_no
        FROM ph_bales pb
        JOIN ph_houses h    ON h.id = pb.house_id
        LEFT JOIN ph_pallets p ON p.id = pb.pallet_id
        LEFT JOIN ph_lines l   ON l.id = p.line_id
        WHERE pb.ph_barcode = '$bc' OR pb.source_barcode = '$bc'
        LIMIT 1");
    if (!$r || $r === true || $r->num_rows === 0) return null;
    return $r->fetch_assoc();
}
