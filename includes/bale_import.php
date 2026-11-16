<?php
/**
 * bale_import.php — bales sold on another system, brought in from a CSV file.
 *
 * Each row is one sold (or rejected) bale. The import writes the same records
 * the floor writes when it weighs, tickets and sells a bale here:
 *   transporter_growers (one delivery per grower and sell date)
 *   → bale_mass → bale_junus → tickets → sold_bales
 * so once the floor balances them (flowork_balance_delivery() on the Balance
 * page), Sales Sheets, EOD Reports and Payments read imported sales exactly
 * like their own. Every import is recorded in bale_imports so it can be undone.
 *
 * Nothing is written until bale_import_check() reports no errors.
 */
require_once __DIR__ . '/balance_delivery.php';
require_once __DIR__ . '/audit.php';

const BALE_IMPORT_MAX_ROWS  = 20000;
const BALE_IMPORT_MAX_BYTES = 5242880;   // 5 MB

if (!function_exists('bale_import_ensure_tables')) {
    function bale_import_ensure_tables(mysqli $conn): void {
        $conn->query("CREATE TABLE IF NOT EXISTS bale_imports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            selling_pointid INT NOT NULL DEFAULT 0,
            seasonid INT NOT NULL DEFAULT 0,
            userid INT NOT NULL DEFAULT 0,
            source_system VARCHAR(100) NOT NULL DEFAULT '',
            filename VARCHAR(255) NOT NULL DEFAULT '',
            file_sha1 CHAR(40) NOT NULL DEFAULT '',
            bales INT NOT NULL DEFAULT 0,
            deliveries INT NOT NULL DEFAULT 0,
            balanced INT NOT NULL DEFAULT 0,
            total_mass DECIMAL(14,2) NOT NULL DEFAULT 0,
            total_usd DECIMAL(14,2) NOT NULL DEFAULT 0,
            sell_dates VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('imported','reversed') NOT NULL DEFAULT 'imported',
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reversed_at TIMESTAMP NULL DEFAULT NULL,
            reversed_by INT NULL DEFAULT NULL,
            KEY (selling_pointid), KEY (file_sha1)
        ) ENGINE=InnoDB");
        $conn->query("CREATE TABLE IF NOT EXISTS bale_import_deliveries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bale_importid INT NOT NULL,
            transporter_growersid INT NOT NULL,
            booked_balesid INT NULL DEFAULT NULL,
            existing TINYINT(1) NOT NULL DEFAULT 0,
            KEY (bale_importid), UNIQUE KEY (transporter_growersid)
        ) ENGINE=InnoDB");
        // existing=1: the bales went onto a delivery the floor had already booked; undo keeps that delivery
        $c = $conn->query("SHOW COLUMNS FROM bale_import_deliveries LIKE 'existing'");
        if ($c && $c !== true && !$c->num_rows) {
            $conn->query("ALTER TABLE bale_import_deliveries ADD existing TINYINT(1) NOT NULL DEFAULT 0");
        }
        // A booking row the import completed (rather than created), and what it held
        // before, so undo can put it back
        $c = $conn->query("SHOW COLUMNS FROM bale_import_deliveries LIKE 'completed_bookingid'");
        if ($c && $c !== true && !$c->num_rows) {
            $conn->query("ALTER TABLE bale_import_deliveries
                ADD completed_bookingid INT NULL DEFAULT NULL,
                ADD prev_booked_id VARCHAR(100) NULL DEFAULT NULL,
                ADD prev_bales_booked VARCHAR(100) NULL DEFAULT NULL");
        }
        // What a booking's delivered count and a booked delivery note's bale count
        // held before the import filled them in
        $c = $conn->query("SHOW COLUMNS FROM bale_import_deliveries LIKE 'prev_bales_delivered'");
        if ($c && $c !== true && !$c->num_rows) {
            $conn->query("ALTER TABLE bale_import_deliveries
                ADD prev_bales_delivered VARCHAR(100) NULL DEFAULT NULL,
                ADD prev_tg_bales INT NULL DEFAULT NULL");
        }
        // The location a booked delivery-note line had before the import set one
        $c = $conn->query("SHOW COLUMNS FROM bale_import_deliveries LIKE 'prev_tg_locationid'");
        if ($c && $c !== true && !$c->num_rows) {
            $conn->query("ALTER TABLE bale_import_deliveries ADD prev_tg_locationid INT NULL DEFAULT NULL");
        }
    }
}

/**
 * The columns an import understands: key => [label, when required, header
 * names it is recognised by]. 'sold' means required unless the bale is rejected.
 */
function bale_import_fields(): array {
    return [
        'grower_num'    => ['Grower number',  'always', ['grower_num','grower_no','grower_number','growernum','grower','grower_code','growerid']],
        'barcode'       => ['Ticket barcode', 'always', ['barcode','ticket','ticket_barcode','ticket_no','ticket_number','bale_barcode','bale_ticket','bale_no','bale_number']],
        'mass'          => ['Mass (kg)',      'always', ['mass','mass_kg','weight','weight_kg','kg','net_mass','nett_mass']],
        'price'         => ['Price (USD/kg)', 'sold',   ['price','price_usd','usd_price','price_kg','price_per_kg','unit_price','usd_kg','sale_price','selling_price']],
        'timb_grade'    => ['TIMB grade',     'sold',   ['timb_grade','grade','official_grade','timb','timbgrade']],
        'buyer'         => ['Buyer code',     'sold',   ['buyer','buyer_code','buyer_name','merchant','merchant_code']],
        'buyer_grade'   => ['Buyer grade',    'no',     ['buyer_grade','company_grade','merchant_grade','buyergrade']],
        'sale_code'     => ['Sale code (R/CO/RO = rejected)', 'no', ['sale_code','salecode','status','reject_code','code']],
        'sell_date'     => ['Sell date',      'no',     ['sell_date','sale_date','selling_date','date','sold_date']],
        'scale_barcode' => ['Scale / TIMB tag', 'no',   ['scale_barcode','temp_barcode','timb_barcode','scale_tag','tag','timb_tag','temp_bacode']],
        'lot'           => ['Lot',            'no',     ['lot','lot_no','lot_number']],
        'group'         => ['Bale group',     'no',     ['group','bale_group','grp']],
        'split'         => ['Split',          'no',     ['split','split_no','splitid']],
        'sales_rep'     => ['Sales rep',      'no',     ['sales_rep','rep','auctioneer','salesrep']],
        'location'      => ['Bale location',  'no',     ['location','bale_location','location_code','loc','loc_code','bay','store','warehouse','section']],
    ];
}

function bale_import_norm_header(string $h): string {
    // camelCase (common in JSON exports) -> snake case: growerNumber -> grower_number
    $h = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)));
    $h = strtolower($h);
    return trim(preg_replace('/[^a-z0-9]+/', '_', $h), '_');
}

/**
 * Read a CSV / text file. The delimiter (comma, semicolon, tab or pipe) is
 * taken from the header line. Blank lines are dropped.
 * Returns ['headers' => [...], 'rows' => [[line, [cells]]...]] or ['error' => msg].
 */
function bale_import_read_file(string $path): array {
    // A JSON file (first non-space character [ or {) is read as JSON records
    $peek = @file_get_contents($path, false, null, 0, 4096);
    if ($peek !== false) {
        $peek = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $peek));
        if ($peek !== '' && ($peek[0] === '[' || $peek[0] === '{')) return bale_import_read_json($path);
        // TIMB bale file (BL .v): fixed-width lines starting A + barcode + grower
        if (preg_match(BALE_IMPORT_TIMB_BL_RX, strtok($peek, "\r\n"))) return bale_import_read_timb_bl($path);
    }
    $fh = @fopen($path, 'r');
    if (!$fh) return ['error' => 'The file could not be read.'];
    $first = fgets($fh);
    if ($first === false) { fclose($fh); return ['error' => 'The file is empty.']; }
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $delim = ',';
    $best  = 0;
    foreach ([',', ';', "\t", '|'] as $d) {
        $n = substr_count($first, $d);
        if ($n > $best) { $best = $n; $delim = $d; }
    }
    rewind($fh);
    $headers = null;
    $rows = [];
    $line = 0;
    while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $line++;
        if ($cells === [null] || implode('', array_map('trim', $cells)) === '') continue;
        if ($headers === null) {
            $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cells[0]);
            $headers = array_map('trim', $cells);
            continue;
        }
        if (count($rows) >= BALE_IMPORT_MAX_ROWS) {
            fclose($fh);
            return ['error' => 'The file has more than ' . BALE_IMPORT_MAX_ROWS . ' bales. Split it into smaller files.'];
        }
        $rows[] = [$line, array_map(fn($c) => trim((string)$c), $cells)];
    }
    fclose($fh);
    if (!$headers) return ['error' => 'The file has no header row.'];
    if (!$rows)    return ['error' => 'The file has a header row but no bales.'];
    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * TIMB bale file (BL, ".v"): one fixed-width line per bale, then a TOTAL line.
 *   A | barcode 10 | grower 7 | 2 spaces | TIMB grade 5 | lot 6 | mass 7 (kg)
 *   + | price 8 (x100000 USD/kg) | ZiG rate 14 (x100000) | USD value 13 (x10000)
 *   + | ZiG value 16 (x10000) + | spaces | NP (sold) or *P (rejected) | floor code | P
 * Same layout timb_reports.php writes. It carries no buyer and no sell date.
 */
const BALE_IMPORT_TIMB_BL_RX = '/^A(?<barcode>[0-9A-Z]{10})(?<grower>[0-9A-Z ]{7})\s{1,3}(?<grade>.{5})(?<lot>\d{6})(?<mass>\d{7})\+(?<price>\d{8})(?<rate>\d{14})(?<usd>\d{13})\+(?<zig>\d{16})\+\s*(?<flag>NP|\*P)(?<sp>\S*)\s+\S*\s*$/';

function bale_import_read_timb_bl(string $path): array {
    $raw = @file_get_contents($path);
    if ($raw === false) return ['error' => 'The file could not be read.'];
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $rows = []; $bad = []; $total = null; $rates = [];
    foreach ($lines as $i => $ln) {
        $n = $i + 1;
        if (trim($ln) === '') continue;
        if (stripos(ltrim($ln), 'TOTAL') === 0) { $total = $ln; continue; }
        if (!preg_match(BALE_IMPORT_TIMB_BL_RX, $ln, $m)) { $bad[] = $n; continue; }
        if (count($rows) >= BALE_IMPORT_MAX_ROWS) {
            return ['error' => 'The file has more than ' . BALE_IMPORT_MAX_ROWS . ' bales. Split it into smaller files.'];
        }
        $rej  = $m['flag'] === '*P';
        $rate = (int)$m['rate'] / 100000;
        if ($rate > 0) $rates[number_format($rate, 4, '.', '')] = true;
        $rows[] = [$n, [
            trim($m['barcode']),                                   // barcode
            trim($m['grower']),                                    // grower_num
            trim($m['grade']),                                     // timb_grade
            (string)(int)$m['lot'],                                // lot
            (string)(int)$m['mass'],                               // mass (kg)
            $rej ? '' : rtrim(rtrim(number_format((int)$m['price'] / 100000, 5, '.', ''), '0'), '.'),  // price
            number_format($rate, 4, '.', ''),                      // exchange_rate
            number_format((int)$m['usd'] / 10000, 2, '.', ''),     // usd_value
            number_format((int)$m['zig'] / 10000, 2, '.', ''),     // zig_value
            $rej ? 'R' : '',                                       // sale_code
            $m['sp'],                                              // floor code in the file
        ]];
    }
    if ($bad) {
        return ['error' => 'This looks like a TIMB bale file, but line' . (count($bad) > 1 ? 's ' : ' ')
            . implode(', ', array_slice($bad, 0, 10)) . (count($bad) > 10 ? ' …' : '') . ' do' . (count($bad) > 1 ? '' : 'es')
            . ' not match the bale file layout. Check the file was not edited or cut short.'];
    }
    if (!$rows) return ['error' => 'The TIMB bale file has no bales in it.'];
    $notes = ['TIMB bale file: ' . count($rows) . ' bale(s). It has no buyer: choose the buyer for these bales below.'];
    if ($total && preg_match('/TOTAL\s*:\s*(\d+)/i', $total, $tm) && (int)$tm[1] !== count($rows)) {
        return ['error' => "The file's TOTAL line says {$tm[1]} bales but the file has " . count($rows) . '. It may be cut short; get the full file and upload it again.'];
    }
    return [
        'headers' => ['barcode', 'grower_num', 'timb_grade', 'lot', 'mass', 'price', 'exchange_rate', 'usd_value', 'zig_value', 'sale_code', 'floor_code'],
        'rows'    => $rows,
        'unit'    => 'line',
        'format'  => 'timb_bl',
        'notes'   => $notes,
        'rate'    => count($rates) === 1 ? (float)array_key_first($rates) : 0,
    ];
}

/**
 * A sell date from a TIMB file name: <floor><type><mmdd>.v (VLBL0522.v = 22 May).
 * The year is this year, or last year when that date is still ahead. Null if none.
 */
function bale_import_date_from_filename(string $name): ?string {
    if (!preg_match('/^[A-Z0-9]{1,6}(?:BL|SA|RE|DE|CS|DS)(\d{2})(\d{2})\.v$/i', basename($name), $m)) return null;
    $mo = (int)$m[1]; $d = (int)$m[2]; $y = (int)date('Y');
    if (!checkdate($mo, $d, $y)) return null;
    $date = sprintf('%04d-%02d-%02d', $y, $mo, $d);
    if ($date > date('Y-m-d')) $date = sprintf('%04d-%02d-%02d', $y - 1, $mo, $d);
    return checkdate($mo, $d, (int)substr($date, 0, 4)) ? $date : null;
}

/**
 * Read a JSON bale file into the same shape as a CSV: ['headers', 'rows', 'unit' => 'record'].
 * Accepted:
 *   [ {..record..}, ... ]                       a list of records (keys become columns)
 *   { "bales": [ ... ] }                        records under bales / data / rows / records / items,
 *                                               or under whichever key holds the list
 *   [ ["grower_num","barcode",...], [...], ...] a header row, then one row per bale
 * Nested objects are flattened ({"grower":{"num":..}} -> grower_num). Row numbers are record numbers.
 */
function bale_import_read_json(string $path): array {
    $raw = @file_get_contents($path);
    if ($raw === false) return ['error' => 'The file could not be read.'];
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $data = json_decode($raw, true, 64);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'The JSON file is not valid (' . json_last_error_msg() . '). Check it in a JSON validator and upload it again.'];
    }
    $is_list = fn($v) => is_array($v) && ($v === [] || array_keys($v) === range(0, count($v) - 1));

    // Find the list of bales
    $list = null;
    if ($is_list($data)) {
        $list = $data;
    } elseif (is_array($data)) {
        foreach (['bales', 'data', 'rows', 'records', 'items', 'results'] as $k) {
            foreach ($data as $dk => $dv) {
                if (strcasecmp((string)$dk, $k) === 0 && $is_list($dv)) { $list = $dv; break 2; }
            }
        }
        if ($list === null) {
            foreach ($data as $dv) { if ($is_list($dv) && $dv && is_array($dv[0])) { $list = $dv; break; } }
        }
        if ($list === null) $list = [$data];   // a single bale record
    }
    if (!$list) return ['error' => 'The JSON file has no bales in it.'];
    if (count($list) > BALE_IMPORT_MAX_ROWS + 1) {
        return ['error' => 'The file has more than ' . BALE_IMPORT_MAX_ROWS . ' bales. Split it into smaller files.'];
    }

    $cell = function ($v): string {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_float($v)) return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        return trim((string)$v);
    };

    // A header row followed by rows (CSV written as JSON)
    if ($is_list($list[0]) && !array_filter($list[0], 'is_array')) {
        $headers = array_map(fn($h) => trim((string)$h), $list[0]);
        $rows = [];
        foreach (array_slice($list, 1) as $i => $r) {
            if (!is_array($r)) continue;
            $cells = array_map($cell, array_values($r));
            if (implode('', $cells) === '') continue;
            $rows[] = [$i + 2, $cells];
        }
        if (!$rows) return ['error' => 'The JSON file has a header row but no bales.'];
        return ['headers' => $headers, 'rows' => $rows, 'unit' => 'record'];
    }

    // A list of records: flatten nested objects, columns = every key, in first-seen order
    $flatten = function (array $rec, string $prefix = '') use (&$flatten, $is_list): array {
        $out = [];
        foreach ($rec as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '_' . $k;
            if (is_array($v) && !$is_list($v)) $out += $flatten($v, $key);
            else $out[$key] = $v;
        }
        return $out;
    };
    $records = []; $headers = [];
    foreach ($list as $i => $rec) {
        if (!is_array($rec)) continue;
        $flat = $flatten($rec);
        foreach ($flat as $k => $_) if (!isset($headers[$k])) $headers[$k] = count($headers);
        $records[] = [$i + 1, $flat];
    }
    if (!$records) return ['error' => 'The JSON file has no bale records (each bale should be an object like {"grower_num": "...", "barcode": "...", "mass": 112}).'];
    if (count($headers) > 200) return ['error' => 'The JSON records have more than 200 different fields. Export only the bale fields.'];
    $rows = [];
    foreach ($records as [$n, $flat]) {
        $cells = array_fill(0, count($headers), '');
        foreach ($flat as $k => $v) $cells[$headers[$k]] = $cell($v);
        if (implode('', $cells) === '') continue;
        $rows[] = [$n, $cells];
    }
    if (!$rows) return ['error' => 'The JSON file has no bales in it.'];
    return ['headers' => array_keys($headers), 'rows' => $rows, 'unit' => 'record'];
}

/** field => column index (or -1), matched on the header names each field knows. */
function bale_import_automap(array $headers): array {
    $norm = array_map('bale_import_norm_header', $headers);
    $map = [];
    $taken = [];
    foreach (bale_import_fields() as $key => [, , $aliases]) {
        $map[$key] = -1;
        foreach ($aliases as $a) {
            $i = array_search($a, $norm, true);
            if ($i !== false && !isset($taken[$i])) { $map[$key] = $i; $taken[$i] = true; break; }
        }
    }
    return $map;
}

/**
 * Y-m-d from the date formats other systems and Excel export: 2026-10-28,
 * 2026/10/28, 28/10/2026, 28-10-26, 28.10.2026, 28-Oct-26, 28 October 2026,
 * Oct 28, 2026, an Excel date number (46323), any of them followed by a time.
 * Numeric dates are read day first; month first only when day first is not a
 * date (10/28/2026).
 */
function bale_import_parse_date(string $s): ?string {
    $s = trim(str_replace("\xC2\xA0", ' ', $s), " \t\"'");
    if ($s === '') return null;
    $ymd = fn($y, $mo, $d) => checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    $year = fn($y) => $y < 100 ? $y + 2000 : $y;

    // Excel serial day number (1900 system), within 2000–2099.
    if (preg_match('/^\d{5}(\.\d+)?$/', $s) && (int)$s >= 36526 && (int)$s <= 73050) {
        return gmdate('Y-m-d', ((int)$s - 25569) * 86400);
    }
    // Drop a trailing time: "28/10/2026 10:15", "2026-10-28T10:15:00", "… 10:15 AM".
    $s = preg_replace('/(?:[T\s]+\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?\s*(?:[AaPp][Mm])?(?:Z|[+\-]\d{2}:?\d{2})?)$/', '', $s);

    if (preg_match('#^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})$#', $s, $m)) {
        return $ymd((int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $s, $m)) {
        $y = $year((int)$m[3]);
        return $ymd($y, (int)$m[2], (int)$m[1]) ?? $ymd($y, (int)$m[1], (int)$m[2]);
    }
    if (preg_match('#^(\d{4})(\d{2})(\d{2})$#', $s, $m)) {
        return $ymd((int)$m[1], (int)$m[2], (int)$m[3]);
    }
    $month = function (string $name): int {
        $t = strtotime('1 ' . $name . ' 2000');
        return $t ? (int)date('n', $t) : 0;
    };
    if (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?[\s\-\/.,]+([A-Za-z]{3,9})\.?[\s\-\/.,]+(\d{2,4})$/', $s, $m)) {
        $mo = $month($m[2]);
        return $mo ? $ymd($year((int)$m[3]), $mo, (int)$m[1]) : null;
    }
    if (preg_match('/^([A-Za-z]{3,9})\.?[\s\-\/.]+(\d{1,2})(?:st|nd|rd|th)?,?[\s\-\/.]+(\d{2,4})$/', $s, $m)) {
        $mo = $month($m[1]);
        return $mo ? $ymd($year((int)$m[3]), $mo, (int)$m[2]) : null;
    }
    return null;
}

/** The floor's selling day for a date and its ZiG rate, or null. */
function bale_import_day(mysqli $conn, int $spid, string $date): ?array {
    $d = $conn->real_escape_string($date);
    $q = $conn->query("SELECT sd.id, (SELECT er.amount FROM exchange_rate er WHERE er.start_of_dayid=sd.id
                ORDER BY er.active DESC, er.id DESC LIMIT 1) rate
        FROM start_of_day sd WHERE sd.description='$d' AND (sd.selling_pointid=$spid OR sd.selling_pointid=0)
        ORDER BY (sd.selling_pointid=$spid) DESC, sd.active DESC, sd.id DESC LIMIT 1");
    $r = ($q && $q !== true && $q->num_rows) ? $q->fetch_assoc() : null;
    return $r ? ['sodid' => (int)$r['id'], 'rate' => (float)$r['rate']] : null;
}

/**
 * Check every row and resolve it to ids. Writes nothing.
 *
 * $opt: default_sell_date (Y-m-d), zig_rate (rate for sell dates the floor has
 * not opened; 0 = refuse those), default_locid (bale location for rows the
 * file gives none; 0 = none). A location in the file is matched to the floor's
 * bale locations by code or name (A, "Location A", "Main Bay").
 *
 * Returns ['errors' => [[line, msg]], 'notes' => [msg], 'bales' => [...],
 * 'deliveries' => [key => summary], 'days' => [date => info],
 * 'new_grades' => [buyerid|desc => [buyerid, desc]], 'totals' => [...]].
 */
function bale_import_check(mysqli $conn, int $spid, array $rows, array $map, array $opt): array {
    $errors = []; $notes = [];
    $fields = bale_import_fields();
    foreach ($fields as $key => [$label, $req]) {
        if ($req === 'always' && ($map[$key] ?? -1) < 0) $errors[] = [0, "Choose the column that holds the $label."];
    }
    if ($errors) return ['errors' => $errors, 'notes' => [], 'bales' => [], 'deliveries' => [], 'days' => [], 'new_grades' => [], 'totals' => []];

    $cell = function (array $cells, string $key) use ($map): string {
        $i = $map[$key] ?? -1;
        return $i >= 0 ? trim((string)($cells[$i] ?? '')) : '';
    };
    $esc = fn($s) => $conn->real_escape_string($s);
    $today = date('Y-m-d');

    // Lookups, loaded once.
    $growers = [];
    $gq = $conn->query("SELECT id, UPPER(TRIM(grower_num)) gn, name, surname, selling_pointid FROM growers
        WHERE selling_pointid=$spid OR selling_pointid=0 OR selling_pointid IS NULL
        ORDER BY (selling_pointid=$spid) DESC");
    while ($gq && $g = $gq->fetch_assoc()) {
        if (!isset($growers[$g['gn']])) $growers[$g['gn']] = $g;
    }
    $timb = [];
    $tq = $conn->query("SELECT id, UPPER(TRIM(description)) d FROM timb_grades WHERE active=1");
    while ($tq && $t = $tq->fetch_assoc()) $timb[$t['d']] = (int)$t['id'];
    $buyers_by_code = []; $buyers_by_name = [];
    $bq = $conn->query("SELECT id, UPPER(TRIM(buyer_code)) code, UPPER(TRIM(name)) name, selling_pointid FROM buyer
        WHERE active=1 AND (selling_pointid=$spid OR selling_pointid=0) ORDER BY (selling_pointid=$spid) DESC");
    while ($bq && $b = $bq->fetch_assoc()) {
        if ($b['code'] !== '' && !isset($buyers_by_code[$b['code']])) $buyers_by_code[$b['code']] = (int)$b['id'];
        $buyers_by_name[$b['name']][] = (int)$b['id'];
    }
    $bgrades = [];
    $bgq = $conn->query("SELECT id, buyerid, UPPER(TRIM(description)) d FROM buyer_grades WHERE active=1");
    while ($bgq && $x = $bgq->fetch_assoc()) {
        $k = $x['buyerid'] . '|' . $x['d'];
        if (!isset($bgrades[$k])) $bgrades[$k] = (int)$x['id'];
    }

    // Bale locations on this floor, by code and by name
    $locs = []; $loc_code = [];
    $lq = $conn->query("SELECT id, TRIM(code) code, TRIM(name) name FROM bale_locations WHERE selling_pointid=$spid AND active=1");
    while ($lq && $lq !== true && $l = $lq->fetch_assoc()) {
        $loc_code[(int)$l['id']] = $l['code'];
        foreach ([$l['code'], $l['name']] as $nm) {
            $nm = strtoupper(trim($nm));
            if ($nm !== '' && !isset($locs[$nm])) $locs[$nm] = (int)$l['id'];
        }
    }
    $find_loc = function (string $v) use ($locs): int {
        $v = strtoupper(trim(preg_replace('/\s+/', ' ', $v)));
        if (isset($locs[$v])) return $locs[$v];
        $v = trim(preg_replace('/^(LOCATION|LOC|BAY|SECTION)[\s.:#-]*/', '', $v));
        return $locs[$v] ?? 0;
    };
    $default_loc = (int)($opt['default_locid'] ?? 0);
    if ($default_loc && !isset($loc_code[$default_loc])) $default_loc = 0;

    $bales = []; $seen_bc = []; $seen_tag = []; $new_grades = []; $days = [];
    $default_date = $opt['default_sell_date'] ?? '';
    foreach ($rows as [$line, $cells]) {
        $row_err = [];
        $gn = strtoupper($cell($cells, 'grower_num'));
        $bc = $cell($cells, 'barcode');
        $mass_s = str_replace(',', '.', $cell($cells, 'mass'));
        $sc = strtoupper($cell($cells, 'sale_code'));
        $is_rej = in_array($sc, ['R', 'CO', 'RO'], true) || ($sc !== '' && $sc[0] === 'R' && strlen($sc) <= 3);

        $g = $growers[$gn] ?? null;
        if ($gn === '')  $row_err[] = 'grower number is blank';
        elseif (!$g)     $row_err[] = "grower $gn is not registered on this floor";

        if ($bc === '')                        $row_err[] = 'ticket barcode is blank';
        elseif (strlen($bc) > 100)             $row_err[] = 'ticket barcode is too long';
        elseif (isset($seen_bc[strtoupper($bc)])) $row_err[] = "barcode $bc is also on line " . $seen_bc[strtoupper($bc)];
        else $seen_bc[strtoupper($bc)] = $line;

        $mass = is_numeric($mass_s) ? round((float)$mass_s, 2) : 0;
        if ($mass <= 0)       $row_err[] = "mass '" . $cell($cells, 'mass') . "' is not a number above 0";
        elseif ($mass > 999)  $row_err[] = "mass {$mass} kg is too high for one bale";

        $price = 0.0;
        if (!$is_rej) {
            $p_s = str_replace([',', '$'], ['.', ''], $cell($cells, 'price'));
            $price = is_numeric($p_s) ? (float)$p_s : 0;
            if ($price > 10) $price = round($price / 100, 4);   // cents per kg, as on the floor
            if ($price <= 0)  $row_err[] = 'price is missing (use sale code R, CO or RO for a rejected bale)';
            elseif ($price > 10) $row_err[] = "price {$p_s} is too high";
        }

        $tg_desc = strtoupper($cell($cells, 'timb_grade'));
        $timb_id = 0;
        if ($tg_desc !== '') {
            $timb_id = $timb[$tg_desc] ?? 0;
            if (!$timb_id) $row_err[] = "TIMB grade $tg_desc is not in the TIMB grade list";
        } elseif (!$is_rej) {
            $row_err[] = 'TIMB grade is blank';
        }

        $buyer_in = strtoupper($cell($cells, 'buyer'));
        $buyerid = 0;
        // No buyer in the file (e.g. a TIMB bale file): the buyer chosen for the file
        if ($buyer_in === '' && !$is_rej && !empty($opt['default_buyerid'])) {
            $buyerid  = (int)$opt['default_buyerid'];
            $buyer_in = $opt['default_buyer_label'] ?? 'chosen buyer';
        } elseif ($buyer_in !== '') {
            $buyerid = $buyers_by_code[$buyer_in] ?? 0;
            if (!$buyerid && isset($buyers_by_name[$buyer_in])) {
                if (count($buyers_by_name[$buyer_in]) === 1) $buyerid = $buyers_by_name[$buyer_in][0];
                else $row_err[] = "buyer $buyer_in matches more than one buyer; use the buyer code";
            }
            if (!$buyerid && !isset($buyers_by_name[$buyer_in])) $row_err[] = "buyer $buyer_in is not set up on this floor";
        } elseif (!$is_rej) {
            $row_err[] = 'buyer is blank (choose the buyer for these bales above, or the column that holds it)';
        }

        // Buyer grade: the file's, else the TIMB grade. Missing ones are added on import.
        $bgid = 0; $bg_desc = '';
        if ($buyerid) {
            $bg_desc = strtoupper($cell($cells, 'buyer_grade')) ?: $tg_desc;
            if ($bg_desc === '') $row_err[] = 'buyer grade and TIMB grade are both blank';
            elseif (strlen($bg_desc) > 100) $row_err[] = 'buyer grade is too long';
            else {
                $bgid = $bgrades["$buyerid|$bg_desc"] ?? 0;
                if (!$bgid) $new_grades["$buyerid|$bg_desc"] = [$buyerid, $bg_desc, $buyer_in];
            }
        }

        $sd_raw = $cell($cells, 'sell_date');
        $sell_date = $sd_raw !== '' ? bale_import_parse_date($sd_raw) : $default_date;
        if (!$sell_date)            $row_err[] = $sd_raw !== '' ? "sell date '$sd_raw' is not a date" : 'no sell date';
        elseif ($sell_date > $today) $row_err[] = "sell date $sell_date is in the future";

        $split = $cell($cells, 'split') === '' ? 1 : (int)$cell($cells, 'split');
        if ($split < 1 || $split > 15) $row_err[] = 'split must be 1 to 15';

        $tag = $cell($cells, 'scale_barcode');
        if ($tag === '') $tag = 'IMP-' . $bc;
        if (strlen($tag) > 100) $row_err[] = 'scale tag is too long';
        elseif (isset($seen_tag[strtoupper($tag)])) $row_err[] = "scale tag $tag is also on line " . $seen_tag[strtoupper($tag)];
        else $seen_tag[strtoupper($tag)] = $line;

        $loc_in = $cell($cells, 'location');
        $locid = $default_loc;
        if ($loc_in !== '') {
            $locid = $find_loc($loc_in);
            if (!$locid) $row_err[] = "location $loc_in is not a bale location on this floor"
                . ($loc_code ? ' (' . implode(', ', $loc_code) . ')' : ' (add it under Locations first)');
        }

        foreach ($row_err as $e) $errors[] = [$line, $e];
        if ($row_err) continue;

        if ($sell_date && !isset($days[$sell_date])) $days[$sell_date] = null;
        $bales[] = [
            'line' => $line, 'growerid' => (int)$g['id'], 'grower_num' => $gn,
            'grower_spid' => (int)$g['selling_pointid'],
            'grower_name' => trim($g['name'] . ' ' . $g['surname']),
            'barcode' => $bc, 'tag' => $tag, 'mass' => $mass, 'price' => $price,
            'sale_code' => $sc, 'rejected' => $is_rej,
            'timb_gradesid' => $timb_id, 'buyerid' => $buyerid, 'buyer_gradesid' => $bgid, 'bg_desc' => $bg_desc,
            'sell_date' => $sell_date, 'split' => $split,
            'lot' => (int)$cell($cells, 'lot'), 'group' => $cell($cells, 'group') === '' ? 1 : (int)$cell($cells, 'group'),
            'sales_rep' => substr($cell($cells, 'sales_rep'), 0, 100),
            'locid' => $locid, 'loc_from_file' => $loc_in !== '',
        ];
    }

    // Barcodes and tags already in the system (any floor: a ticket barcode is looked up on its own).
    foreach (array_chunk($bales, 500) as $chunk) {
        $in = implode(',', array_map(fn($b) => "'" . $esc($b['barcode']) . "'", $chunk));
        $q = $conn->query("SELECT barcode FROM tickets WHERE barcode IN ($in)");
        while ($q && $r = $q->fetch_assoc()) {
            foreach ($chunk as $b) if (strcasecmp($b['barcode'], $r['barcode']) === 0)
                $errors[] = [$b['line'], "barcode {$b['barcode']} is already in the system (already sold or imported)"];
        }
        $in = implode(',', array_map(fn($b) => "'" . $esc($b['tag']) . "'", $chunk));
        $q = $conn->query("SELECT temp_bacode FROM bale_mass WHERE temp_bacode IN ($in)");
        while ($q && $r = $q->fetch_assoc()) {
            foreach ($chunk as $b) if (strcasecmp($b['tag'], $r['temp_bacode']) === 0)
                $errors[] = [$b['line'], "scale tag {$b['tag']} is already in the system"];
        }
    }

    // Selling days: the floor's own (with a ZiG rate), or a new one at the rate on the form.
    $zig = (float)($opt['zig_rate'] ?? 0);
    foreach (array_keys($days) as $d) {
        $day = bale_import_day($conn, $spid, $d);
        if ($day && $day['rate'] > 0) {
            $days[$d] = $day + ['create' => false];
        } elseif ($zig > 0) {
            $days[$d] = ['sodid' => $day['sodid'] ?? 0, 'rate' => $zig, 'create' => true];
            $notes[] = "Selling day $d " . ($day ? 'has no ZiG rate' : 'is not open on this floor')
                     . "; it will be recorded with the rate on this form (1 USD = $zig ZiG).";
        } else {
            $errors[] = [0, "Selling day $d " . ($day ? 'has no ZiG exchange rate' : 'is not set up on this floor')
                          . ". Open it and set its rate in Day Control, or enter the ZiG rate on this form."];
        }
    }

    // Deliveries: one per grower, sell date and bale location (a delivery-note
    // line carries one location). The grower's booking covers all of them.
    $deliveries = []; $by_loc = [];
    foreach ($bales as $b) {
        $k = $b['growerid'] . '|' . $b['sell_date'] . '|' . $b['locid'];
        $dv = &$deliveries[$k];
        if (!$dv) $dv = ['growerid' => $b['growerid'], 'grower_num' => $b['grower_num'], 'grower_name' => $b['grower_name'],
                         'grower_spid' => $b['grower_spid'], 'sell_date' => $b['sell_date'],
                         'locid' => $b['locid'], 'loc_code' => $loc_code[$b['locid']] ?? '', 'loc_from_file' => false,
                         'bales' => 0, 'sold' => 0, 'rejected' => 0, 'mass' => 0, 'usd' => 0, 'splits' => [], 'booking' => null,
                         'delivery' => null];
        if ($b['loc_from_file']) $dv['loc_from_file'] = true;
        $by_loc[$dv['loc_code'] !== '' ? $dv['loc_code'] : '(no location)'] = ($by_loc[$dv['loc_code'] !== '' ? $dv['loc_code'] : '(no location)'] ?? 0) + 1;
        $dv['bales']++;
        $dv['splits'][$b['split']] = true;
        if ($b['rejected']) { $dv['rejected']++; }
        else { $dv['sold']++; $dv['mass'] += $b['mass']; $dv['usd'] += $b['mass'] * $b['price']; }
        unset($dv);
    }
    $unbooked = 0; $on_booked = 0; $tg_taken = []; $gd_seen = [];
    foreach ($deliveries as &$dv) {
        $gdk = $dv['growerid'] . '|' . $dv['sell_date'];
        $gid = $dv['growerid'];
        $sd = $esc($dv['sell_date']);
        $alt = $esc(date('d-M-y', strtotime($dv['sell_date'])));
        $q = $conn->query("SELECT id FROM booked_bales WHERE growerid=$gid AND (sell_date='$sd' OR sell_date='$alt')
            AND bales_booked > 0 AND TRIM(COALESCE(booked_id,'')) <> '' ORDER BY id DESC LIMIT 1");
        $dv['booking'] = ($q && $q !== true && $q->num_rows) ? (int)$q->fetch_assoc()['id'] : null;
        if (!$dv['booking'] && !isset($gd_seen[$gdk])) $unbooked++;
        $gd_seen[$gdk] = true;
        $taken = $tg_taken ? ' AND tg.id NOT IN (' . implode(',', array_keys($tg_taken)) . ')' : '';
        // A delivery already booked for this grower and day, with no bales on it yet,
        // takes the file's bales, so they show on the delivery the floor booked.
        $q = $conn->query("SELECT tg.id FROM transporter_growers tg JOIN growers g ON g.id=tg.growerid
            WHERE tg.growerid=$gid AND tg.sell_date='$sd' AND tg.booked=1
              AND (tg.selling_pointid=$spid OR g.selling_pointid=$spid)
              AND NOT EXISTS (SELECT 1 FROM bale_junus bj WHERE bj.transporter_growersid=tg.id)
              AND NOT EXISTS (SELECT 1 FROM balanced_bales bb WHERE bb.transporter_growersid=tg.id)$taken
            ORDER BY (COALESCE(tg.locationid,0)=" . (int)$dv['locid'] . ") DESC, tg.id LIMIT 1");
        $dv['delivery'] = ($q && $q !== true && $q->num_rows) ? (int)$q->fetch_assoc()['id'] : null;
        if ($dv['delivery']) { $on_booked++; $tg_taken[$dv['delivery']] = true; }
    }
    unset($dv);
    if ($on_booked) {
        $notes[] = "$on_booked grower(s) already have a booked delivery for their sell date; their bales will be added to it.";
    }
    if ($unbooked) {
        $notes[] = "$unbooked grower(s) have no booking for their sell date; a booking with ID IMP-<import>-<grower> will be recorded for them so the sale can be balanced.";
    }
    if (count($by_loc) > 1 || array_key_first($by_loc) !== '(no location)') {
        ksort($by_loc);
        $notes[] = 'Bales by location: ' . implode(', ', array_map(fn($l, $n) => "$l $n", array_keys($by_loc), $by_loc)) . '.'
                 . (isset($by_loc['(no location)']) ? ' Choose a location on the form for the bales the file gives none.' : '');
    }
    if ($new_grades) {
        $list = array_map(fn($x) => "{$x[2]} {$x[1]}", array_slice(array_values($new_grades), 0, 15));
        $notes[] = count($new_grades) . ' buyer grade(s) are new and will be added: ' . implode(', ', $list)
                 . (count($new_grades) > 15 ? ', …' : '') . '.';
    }

    usort($errors, fn($a, $b) => $a[0] <=> $b[0]);
    $mass = array_sum(array_column($deliveries, 'mass'));
    $usd  = array_sum(array_column($deliveries, 'usd'));
    return [
        'errors' => $errors, 'notes' => $notes, 'bales' => $bales,
        'deliveries' => $deliveries, 'days' => $days, 'new_grades' => $new_grades,
        'totals' => ['bales' => count($bales), 'deliveries' => count($deliveries), 'mass' => $mass, 'usd' => $usd,
                     'rejected' => array_sum(array_column($deliveries, 'rejected'))],
    ];
}

/** The transporter that imported deliveries hang off, one per source system and floor. */
function bale_import_transporter(mysqli $conn, int $spid, int $seasonid, int $userid, string $source, int $locid = 0): int {
    $name = substr('Import: ' . ($source !== '' ? $source : 'bale file'), 0, 100);
    $n = $conn->real_escape_string($name);
    // One per source and location: reports filter bales by their transporter's location
    $loc_cond = $locid > 0 ? "location_id=$locid" : "location_id IS NULL";
    $q = $conn->query("SELECT id FROM transporter WHERE name='$n' AND selling_pointid=$spid AND $loc_cond ORDER BY id DESC LIMIT 1");
    if ($q && $q !== true && $q->num_rows) return (int)$q->fetch_assoc()['id'];
    $now = date('Y-m-d H:i:s');
    $lv = $locid > 0 ? $locid : 'NULL';
    $conn->query("INSERT INTO transporter (userid,seasonid,selling_pointid,name,id_num,truck_num,location,location_id,created_at,datetime)
        VALUES ($userid,$seasonid,$spid,'$n','','IMPORT','',$lv,'$now',NOW())");
    return (int)$conn->insert_id;
}

/**
 * Write a checked file in one transaction. The deliveries are left unbalanced;
 * the floor balances them on the Balance page.
 *
 * Returns ['ok' => true, 'import_id'] or ['error' => msg].
 */
function bale_import_commit(mysqli $conn, int $spid, int $seasonid, int $userid, array $check, array $meta): array {
    if ($check['errors'] || !$check['bales']) return ['error' => 'The file has errors; nothing was imported.'];
    bale_import_ensure_tables($conn);
    flowork_ensure_rule_columns($conn);   // ALTER commits implicitly: before the transaction
    $esc = fn($s) => $conn->real_escape_string((string)$s);
    $now = date('Y-m-d H:i:s');
    $src = substr(trim($meta['source'] ?? ''), 0, 100);
    $created_sods = [];

    $conn->begin_transaction();
    try {
        $dates = implode(', ', array_keys($check['days']));
        $conn->query("INSERT INTO bale_imports (selling_pointid,seasonid,userid,source_system,filename,file_sha1,sell_dates,notes)
            VALUES ($spid,$seasonid,$userid,'{$esc($src)}','{$esc(substr($meta['filename'] ?? '', 0, 255))}',
                    '{$esc($meta['sha1'] ?? '')}','{$esc(substr($dates, 0, 255))}','{$esc($meta['notes'] ?? '')}')");
        $import_id = (int)$conn->insert_id;
        if (!$import_id) throw new RuntimeException('Could not record the import: ' . $conn->error);

        // Selling days the floor has not opened (or has no rate for): recorded closed,
        // so the floor's active day is left alone.
        $sod_for = [];
        foreach ($check['days'] as $d => $day) {
            $sodid = (int)$day['sodid'];
            if ($day['create']) {
                if (!$sodid) {
                    $conn->query("INSERT INTO start_of_day (userid, seasonid, selling_pointid, description, active, datetime)
                        VALUES ($userid, $seasonid, $spid, '{$esc($d)}', 0, '$now')");
                    $sodid = (int)$conn->insert_id;
                    if (!$sodid) throw new RuntimeException("Could not record selling day $d: " . $conn->error);
                    $created_sods[] = $sodid;
                }
                $rate = (float)$day['rate'];
                $conn->query("INSERT INTO exchange_rate (userid, seasonid, start_of_dayid, selling_pointid, amount, active, source, datetime)
                    VALUES ($userid, $seasonid, $sodid, $spid, $rate, 1, 'bale import', '$now')");
                audit_log($conn, $spid, $userid, 'start_of_day', 'import', $sodid, null, ['date' => $d, 'rate' => $rate, 'import' => $import_id]);
            }
            $sod_for[$d] = $sodid;
        }

        foreach ($check['new_grades'] as $k => [$buyerid, $desc]) {
            $conn->query("INSERT INTO buyer_grades (buyerid, description, active) VALUES ($buyerid, '{$esc($desc)}', 1)");
            $id = (int)$conn->insert_id;
            if (!$id) throw new RuntimeException("Could not add buyer grade $desc: " . $conn->error);
            $check['new_grades'][$k][3] = $id;
        }

        // Receiving: growers without a booked delivery note go onto the chosen
        // transporter's delivery note (a new transporter, or the import transporter)
        $tid = 0;
        if (array_filter($check['deliveries'], fn($dv) => !$dv['delivery'])) {
            $nt = $meta['new_transporter'] ?? null;
            if (!empty($meta['transporterid'])) {
                $tid = (int)$meta['transporterid'];
            } elseif ($nt && trim($nt['name'] ?? '') !== '') {
                $lv = !empty($meta['locationid']) ? (int)$meta['locationid'] : 'NULL';
                $conn->query("INSERT INTO transporter (userid,seasonid,selling_pointid,name,id_num,truck_num,location,location_id,created_at,datetime)
                    VALUES ($userid,$seasonid,$spid,'{$esc(substr(trim($nt['name']), 0, 100))}','',
                            '{$esc(substr(trim($nt['truck'] ?? ''), 0, 100))}','',$lv,'$now',NOW())");
                $tid = (int)$conn->insert_id;
                if (!$tid) throw new RuntimeException('Could not add the transporter: ' . $conn->error);
            } else {
                $tid = bale_import_transporter($conn, $spid, $seasonid, $userid, $src, (int)($meta['locationid'] ?? 0));
            }
            if (!$tid) throw new RuntimeException('Could not record the import transporter: ' . $conn->error);
        }

        $tg_for = []; $gd_total = []; $gd_done = [];
        foreach ($check['deliveries'] as $dv) {
            $gdk = $dv['growerid'] . '|' . $dv['sell_date'];
            $gd_total[$gdk] = ($gd_total[$gdk] ?? 0) + (int)$dv['bales'];
        }
        foreach ($check['deliveries'] as $k => $dv) {
            $gid = $dv['growerid'];
            $gdk = $gid . '|' . $dv['sell_date'];
            $locv = !empty($dv['locid']) ? (int)$dv['locid'] : 'NULL';
            // A grower registered without a floor joins this one, as in Junus.
            if ($dv['grower_spid'] !== $spid) $conn->query("UPDATE growers SET selling_pointid=$spid WHERE id=$gid AND (selling_pointid=0 OR selling_pointid IS NULL)");
            $sd = $esc($dv['sell_date']);
            $n  = (int)$dv['bales'];
            $booked_new = null;
            $completed = 'NULL'; $prev_id = 'NULL'; $prev_bales = 'NULL'; $prev_deliv = 'NULL'; $prev_tg = 'NULL'; $prev_loc = 'NULL';
            $sqlv = fn($v) => $v === null ? 'NULL' : "'" . $esc($v) . "'";
            // The booking covers the grower's bales on that day at every location: once
            $book_now = !isset($gd_done[$gdk]);
            $gd_done[$gdk] = true;
            $nb = $gd_total[$gdk];
            if (!$book_now) {
                // booked with the grower's first location
            } elseif ($dv['booking']) {
                // Booked by the floor: the booking takes the grower's bales from the file
                $bk = (int)$dv['booking'];
                $ex = $conn->query("SELECT booked_id, bales_booked, bales_delivered FROM booked_bales WHERE id=$bk LIMIT 1");
                if (!$ex || $ex === true || !$ex->num_rows) throw new RuntimeException("The booking for {$dv['grower_num']} has gone. Check the file again.");
                $exr = $ex->fetch_assoc();
                $completed  = $bk;
                $prev_id    = $sqlv($exr['booked_id']);
                $prev_bales = $sqlv($exr['bales_booked']);
                $prev_deliv = $sqlv($exr['bales_delivered']);
                if (!$conn->query("UPDATE booked_bales SET bales_booked=GREATEST(CAST(COALESCE(bales_booked,'0') AS UNSIGNED),$nb),
                        bales_delivered='$nb', datetime=NOW() WHERE id=$bk")) {
                    throw new RuntimeException("Could not update the booking for {$dv['grower_num']}: " . $conn->error);
                }
            } else {
                $bid = $esc(substr("IMP-$import_id-{$dv['grower_num']}", 0, 100));
                $ex = $conn->query("SELECT id, booked_id, bales_booked FROM booked_bales WHERE growerid=$gid AND sell_date='$sd' LIMIT 1");
                if ($ex && $ex !== true && $ex->num_rows) {
                    // A booking row without bales or an ID: complete it rather than add a second one.
                    $exr = $ex->fetch_assoc();
                    $eid = (int)$exr['id'];
                    $completed  = $eid;
                    $prev_id    = $sqlv($exr['booked_id']);
                    $prev_bales = $sqlv($exr['bales_booked']);
                    $dq = $conn->query("SELECT bales_delivered FROM booked_bales WHERE id=$eid");
                    $prev_deliv = $sqlv(($dq && $dq !== true && $dq->num_rows) ? $dq->fetch_assoc()['bales_delivered'] : null);
                    $conn->query("UPDATE booked_bales SET bales_booked=GREATEST(CAST(COALESCE(bales_booked,'0') AS UNSIGNED),$nb),
                        bales_delivered='$nb',
                        booked_id=IF(TRIM(COALESCE(booked_id,''))='', '$bid', booked_id), datetime=NOW() WHERE id=$eid");
                } else {
                    $conn->query("INSERT INTO booked_bales
                        (userid,growerid,book_date,comm_graded,bales_booked,bales_delivered,bales_handled,sell_date,book_user,
                         ip_address,floor,sale,booked_id,reoffer,booked_by,prefered_rep,last_sell,prefered_time,venue,datetime)
                        VALUES ($userid,$gid,'$sd','0','$nb','$nb','0','$sd','$userid','','','','$bid','0','bale import','','','','',NOW())");
                    $booked_new = (int)$conn->insert_id;
                }
            }
            $existing = 0;
            if ($dv['delivery']) {
                // The booked delivery takes the bales (re-checked: still has none)
                $tgid = (int)$dv['delivery'];
                $busy = $conn->query("SELECT 1 FROM bale_junus WHERE transporter_growersid=$tgid
                    UNION ALL SELECT 1 FROM balanced_bales WHERE transporter_growersid=$tgid LIMIT 1");
                if (!$busy || $busy === true) throw new RuntimeException("Could not check the booked delivery for {$dv['grower_num']}: " . $conn->error);
                if ($busy->num_rows) {
                    throw new RuntimeException("The booked delivery for {$dv['grower_num']} ({$dv['sell_date']}) got bales on the floor after the check. Check the file again.");
                }
                $pq = $conn->query("SELECT bales, locationid FROM transporter_growers WHERE id=$tgid");
                $pr = ($pq && $pq !== true && $pq->num_rows) ? $pq->fetch_assoc() : null;
                $prev_tg  = $pr ? (int)$pr['bales'] : 'NULL';
                $prev_loc = ($pr && $pr['locationid'] !== null) ? (int)$pr['locationid'] : 'NULL';
                // The file's location wins; otherwise the line keeps its own, or takes the one chosen on the form
                $set_loc = ($dv['loc_from_file'] || $prev_loc === 'NULL') && $locv !== 'NULL' ? ", locationid=$locv" : '';
                if (!$conn->query("UPDATE transporter_growers SET junused_bales=$n, bales=IF(bales>0, bales, $n)$set_loc WHERE id=$tgid")) {
                    throw new RuntimeException("Could not use the booked delivery for {$dv['grower_num']}: " . $conn->error);
                }
                $existing = 1;
            } else {
                $booked = 1;   // booked (by the floor or by this import), so it can be balanced
                $conn->query("INSERT INTO transporter_growers
                    (userid,transporterid,growerid,selling_pointid,bales,junused_bales,booked,sell_date,status,open_close,locationid,created_at,datetime)
                    VALUES ($userid,$tid,$gid,$spid,$n,$n,$booked,'$sd','active',0,$locv,'$now','$now')");
                $tgid = (int)$conn->insert_id;
                if (!$tgid) throw new RuntimeException("Could not record the delivery for {$dv['grower_num']}: " . $conn->error);
            }
            $bn = $booked_new ?: 'NULL';
            // A booked delivery left by an undone import is linked to this import instead
            $ok = $conn->query("INSERT INTO bale_import_deliveries
                    (bale_importid, transporter_growersid, booked_balesid, existing, completed_bookingid, prev_booked_id, prev_bales_booked,
                     prev_bales_delivered, prev_tg_bales, prev_tg_locationid)
                VALUES ($import_id, $tgid, $bn, $existing, $completed, $prev_id, $prev_bales, $prev_deliv, $prev_tg, $prev_loc)
                ON DUPLICATE KEY UPDATE bale_importid=VALUES(bale_importid), booked_balesid=VALUES(booked_balesid), existing=VALUES(existing),
                    completed_bookingid=VALUES(completed_bookingid), prev_booked_id=VALUES(prev_booked_id), prev_bales_booked=VALUES(prev_bales_booked),
                    prev_bales_delivered=VALUES(prev_bales_delivered), prev_tg_bales=VALUES(prev_tg_bales),
                    prev_tg_locationid=VALUES(prev_tg_locationid)");
            if (!$ok) throw new RuntimeException("Could not link the delivery for {$dv['grower_num']} to the import: " . $conn->error);
            $tg_for[$k] = $tgid;
        }

        $lots = [];
        foreach ($check['bales'] as $b) {
            $k = $b['growerid'] . '|' . $b['sell_date'] . '|' . $b['locid'];
            $tgid = $tg_for[$k];
            $sd = $esc($b['sell_date']);
            $lots[$tgid] = ($lots[$tgid] ?? 0) + 1;
            $lot = $b['lot'] ?: $lots[$tgid];

            $conn->query("INSERT INTO bale_mass (userid,seasonid,selling_pointid,temp_bacode,mass,used,created_at)
                VALUES ($userid,$seasonid,$spid,'{$esc($b['tag'])}',{$b['mass']},1,'$sd')");
            $bmid = (int)$conn->insert_id;
            $conn->query("INSERT INTO bale_junus (userid,transporter_growersid,bale_massid,growerid,bale_group,lot,splitid,created_at,datetime)
                VALUES ($userid,$tgid,$bmid,{$b['growerid']},{$b['group']},$lot,{$b['split']},'$sd',NOW())");
            $bjid = (int)$conn->insert_id;
            $conn->query("INSERT INTO tickets (userid,bale_junusid,barcode,created_at,start_of_dayid,created_by)
                VALUES ($userid,$bjid,'{$esc($b['barcode'])}','$sd',{$sod_for[$b['sell_date']]},$userid)");
            $tkid = (int)$conn->insert_id;
            $bgid = $b['buyer_gradesid'] ?: (int)($check['new_grades'][$b['buyerid'] . '|' . $b['bg_desc']][3] ?? 0);
            $ps = number_format($b['price'], 4, '.', '');
            $conn->query("INSERT INTO sold_bales (userid,ticketsid,sales_rep,sale_code,timb_gradesid,buyer_gradesid,price,s2_confirmed,created_at)
                VALUES ($userid,$tkid,'{$esc($b['sales_rep'])}','{$esc($b['sale_code'])}',{$b['timb_gradesid']},$bgid,'$ps',1,'$sd')");
            if (!$bmid || !$bjid || !$tkid || !$conn->insert_id) {
                throw new RuntimeException("Could not record bale {$b['barcode']} (line {$b['line']}): " . $conn->error);
            }
        }

        $t = $check['totals'];
        $conn->query("UPDATE bale_imports SET bales={$t['bales']}, deliveries={$t['deliveries']},
            total_mass=" . round($t['mass'], 2) . ", total_usd=" . round($t['usd'], 2) . " WHERE id=$import_id");
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        // start_of_day and exchange_rate are MyISAM: the rollback does not undo them.
        foreach ($created_sods as $id) {
            $conn->query("DELETE FROM exchange_rate WHERE start_of_dayid=$id");
            $conn->query("DELETE FROM start_of_day WHERE id=$id");
        }
        return ['error' => $e->getMessage()];
    }

    audit_log($conn, $spid, $userid, 'bale_imports', 'create', $import_id, null,
        ['source' => $src, 'file' => $meta['filename'] ?? '', 'bales' => $check['totals']['bales'], 'deliveries' => $check['totals']['deliveries']]);

    return ['ok' => true, 'import_id' => $import_id];
}

/**
 * Undo an import: reverse its balanced deliveries, then delete the deliveries,
 * bales, tickets, sales and the bookings it recorded. Refused while any of its
 * growers' payments for those deliveries is paid or released. Selling days and
 * buyer grades it added are left in place.
 */
function bale_import_reverse(mysqli $conn, int $import_id, int $spid, int $userid): array {
    bale_import_ensure_tables($conn);
    $q = $conn->query("SELECT * FROM bale_imports WHERE id=$import_id AND selling_pointid=$spid LIMIT 1");
    $imp = ($q && $q !== true && $q->num_rows) ? $q->fetch_assoc() : null;
    if (!$imp) return ['error' => 'Import not found on this floor.'];
    if ($imp['status'] === 'reversed') return ['error' => 'This import has already been undone.'];

    $tgids = []; $bookings = []; $own = []; $completed = []; $tg_prev = []; $tg_prevloc = [];
    $dq = $conn->query("SELECT transporter_growersid, booked_balesid, existing,
            completed_bookingid, prev_booked_id, prev_bales_booked, prev_bales_delivered, prev_tg_bales, prev_tg_locationid
        FROM bale_import_deliveries WHERE bale_importid=$import_id");
    while ($dq && $d = $dq->fetch_assoc()) {
        $tgids[] = (int)$d['transporter_growersid'];
        if (!(int)$d['existing']) $own[] = (int)$d['transporter_growersid'];
        if ($d['booked_balesid']) $bookings[] = (int)$d['booked_balesid'];
        if ($d['completed_bookingid']) $completed[] = $d;
        if ((int)$d['existing'] && $d['prev_tg_bales'] !== null) $tg_prev[(int)$d['transporter_growersid']] = (int)$d['prev_tg_bales'];
        if ((int)$d['existing']) $tg_prevloc[(int)$d['transporter_growersid']] = $d['prev_tg_locationid'] === null ? 'NULL' : (int)$d['prev_tg_locationid'];
    }
    $sqlv = fn($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
    $tg_in  = $tgids ? implode(',', $tgids) : '0';
    $own_in = $own ? implode(',', $own) : '0';

    $paid = $conn->query("SELECT 1 FROM payment_status WHERE transporter_growersid IN ($tg_in) AND status IN ('paid','released') LIMIT 1");
    if ($paid && $paid !== true && $paid->num_rows) {
        return ['error' => 'Some growers in this import have already been paid. Reverse those payments first.'];
    }

    $skipped = [];
    $reversed = do_reverse($conn, "bb.transporter_growersid IN ($tg_in)", $spid, $userid, $skipped);
    if ($skipped) return ['error' => "$reversed balanced delivery(ies) reversed, but " . count($skipped) . ' are paid and were left; reverse those payments first, then undo again.'];

    $run = function (string $sql) use ($conn) {
        if (!$conn->query($sql)) throw new RuntimeException($conn->error);
    };
    $conn->begin_transaction();
    try {
        $tk_in = "SELECT tk.id FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid WHERE bj.transporter_growersid IN ($tg_in)";
        $run("DELETE FROM sold_bales WHERE ticketsid IN ($tk_in)");
        $run("DELETE FROM floor_summary WHERE ticketsid IN ($tk_in)");
        $run("DELETE tk FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid WHERE bj.transporter_growersid IN ($tg_in)");
        $run("DELETE bm FROM bale_mass bm JOIN bale_junus bj ON bj.bale_massid=bm.id WHERE bj.transporter_growersid IN ($tg_in)");
        $run("DELETE FROM bale_junus WHERE transporter_growersid IN ($tg_in)");
        $run("DELETE FROM sales_sheet_refs WHERE transporter_growersid IN ($tg_in)");
        if ($bookings) $run("DELETE FROM booked_bales WHERE id IN (" . implode(',', $bookings) . ")");
        // Bookings the import completed go back to what they held, so the grower
        // is not left booked under the import's IMP- booking ID
        foreach ($completed as $d) {
            $run("UPDATE booked_bales SET
                    booked_id=IF(booked_id LIKE 'IMP-$import_id-%', " . $sqlv($d['prev_booked_id']) . ", booked_id),
                    bales_booked=" . $sqlv($d['prev_bales_booked']) . ",
                    bales_delivered=" . ($d['prev_bales_delivered'] === null ? 'bales_delivered' : $sqlv($d['prev_bales_delivered'])) . "
                WHERE id=" . (int)$d['completed_bookingid']);
        }
        foreach ($tg_prev as $tg => $b) $run("UPDATE transporter_growers SET bales=$b WHERE id=$tg");
        foreach ($tg_prevloc as $tg => $l) $run("UPDATE transporter_growers SET locationid=$l WHERE id=$tg");
        // Deliveries the import created go; booked ones it added bales to stay, empty again
        $run("DELETE FROM transporter_growers WHERE id IN ($own_in)");
        $run("UPDATE transporter_growers SET junused_bales=0 WHERE id IN ($tg_in) AND id NOT IN ($own_in)");
        $run("UPDATE bale_imports SET status='reversed', reversed_at=NOW(), reversed_by=$userid WHERE id=$import_id");
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['error' => 'Undo failed: ' . $e->getMessage()];
    }
    audit_log($conn, $spid, $userid, 'bale_imports', 'reverse', $import_id, ['bales' => (int)$imp['bales']], null);
    return ['ok' => true, 'bales' => (int)$imp['bales'], 'deliveries' => count($tgids)];
}
