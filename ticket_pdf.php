<?php
/**
 * Flowork Bale Ticket PDF Generator
 * Pure PHP - zero dependencies, no Python, no shell_exec
 * Ticket: 609 x 213 pts = 214.8 x 75.1 mm (matches original GDN format)
 */
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$tgid    = (int)($_GET['tgid']   ?? 0);
$single  = trim($_GET['barcode'] ?? '');
$from_bc = strtoupper(trim($_GET['from'] ?? '')); // jam recovery: reprint from this barcode onwards
$spid    = (int)($_SESSION['selling_pointid'] ?? 0);

if (!$tgid && !$single) { header('Content-Type: text/plain'); echo "Missing parameter."; exit; }

// ── Load ticket data ─────────────────────────────────────────────────────────
if ($single) {
    $bc = $conn->real_escape_string($single);
    $sql = "
        SELECT tk.barcode, tk.created_at,
               bj.splitid, bj.lot, bj.bale_group,
               bm.mass, bm.temp_bacode AS timb_barcode,
               g.grower_num, tg.id AS tgid, 1 AS bale_seq,
               g.selling_pointid
        FROM tickets tk
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
        WHERE tk.barcode='$bc' LIMIT 1";
} else {
    // Always fetch ALL tickets for the group with their true sequence numbers.
    // Jam-recovery filtering happens in PHP so bale_seq is preserved correctly.
    $sql = "
        SELECT tk.barcode, tk.created_at,
               bj.splitid, bj.lot, bj.bale_group,
               bm.mass, bm.temp_bacode AS timb_barcode,
               g.grower_num, tg.id AS tgid,
               ROW_NUMBER() OVER (ORDER BY bj.id) AS bale_seq,
               g.selling_pointid
        FROM tickets tk
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
        WHERE bj.transporter_growersid=$tgid
        ORDER BY bj.id ASC";
}

$res = $conn->query($sql);
if (!$res || $res->num_rows === 0) {
    header('Content-Type: text/plain'); echo "No tickets found."; exit;
}

$rows = [];
$seq  = 1;
while ($r = $res->fetch_assoc()) {
    if (!isset($r['bale_seq'])) $r['bale_seq'] = $seq;
    $seq++;
    $rows[] = $r;
}

// Enforce selling point scope (IDOR check)
$is_admin = !empty($_SESSION['is_admin']) || !empty($_SESSION['rights']['admin']);
if (!$is_admin && $spid > 0) {
    foreach ($rows as $r) {
        if ((int)$r['selling_pointid'] !== $spid) {
            header('Content-Type: text/plain');
            http_response_code(403);
            echo "Access Denied: Ticket does not belong to your floor.";
            exit;
        }
    }
}

// ── Jam recovery: slice from the specified barcode onwards ───────────────────
// bale_seq values are already set correctly (1-based absolute positions),
// so bale #18 will still say "18" on the reprinted ticket.
if ($from_bc && !$single) {
    $found = false;
    $sliced = [];
    foreach ($rows as $r) {
        if (strtoupper($r['barcode']) === $from_bc) $found = true;
        if ($found) $sliced[] = $r;
    }
    if (!$found) {
        header('Content-Type: text/plain');
        echo "Barcode '$from_bc' was not found in this group.\n"
           . "Check the barcode and try again, or use 'Print All' to reprint the full batch.";
        exit;
    }
    $rows = $sliced;
}

// ── Generate PDF ─────────────────────────────────────────────────────────────
$pdf_bytes = buildTicketPDF($rows);
$label = $single
    ? "ticket_{$single}"
    : ($from_bc ? "reprint_from_{$from_bc}_tg{$tgid}" : "tickets_tg{$tgid}");

header('Content-Type: application/pdf');
header("Content-Disposition: inline; filename=\"{$label}.pdf\"");
header('Content-Length: ' . strlen($pdf_bytes));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $pdf_bytes;
exit;

// ═══════════════════════════════════════════════════════════════════════════
// PDF BUILDER - pure PHP, no external libs
// ═══════════════════════════════════════════════════════════════════════════

function buildTicketPDF(array $tickets): string {
    $PW = 609;   // pts  (214.8 mm)
    $PH = 213;   // pts  (75.1 mm)

    $objects  = [];
    $obj_num  = 0;

    $alloc = function() use (&$objects, &$obj_num) {
        $obj_num++;
        $objects[$obj_num] = '';
        return $obj_num;
    };

    $cat_id   = $alloc();
    $pages_id = $alloc();
    $page_ids = [];

    foreach ($tickets as $idx => $row) {
        $barcode    = (string)($row['barcode']      ?? '');
        $grower_num = (string)($row['grower_num']   ?? '');
        $splitid    = (string)($row['splitid']      ?? '1');
        $bale_group = (string)($row['bale_group']   ?? '');
        $lot        = (string)($row['lot']          ?? '');
        // GDN prints whole masses without decimals (e.g. "88", not "88.0")
        $mass_val   = (float)($row['mass'] ?? 0);
        $mass_str   = fmod($mass_val, 1) == 0.0 ? (string)(int)$mass_val : number_format($mass_val, 1);
        $bale_seq   = (string)($row['bale_seq']     ?? ($idx + 1));
        $timb_bc    = (string)($row['timb_barcode'] ?? '');
        $raw_date   = (string)($row['created_at']   ?? '');

        // Format date dd/mm/yy
        try {
            $dt = new DateTime(substr($raw_date, 0, 10));
            $date_str = $dt->format('d/m/y');
        } catch (Exception $e) {
            $date_str = substr($raw_date, 0, 10);
        }

        // Content stream
        $stream = ticketStream($PW, $PH, $splitid, $date_str, $bale_group,
                               $barcode, $timb_bc, $lot,
                               $grower_num, $bale_seq, $mass_str);

        // Stream object
        $cs_id = $alloc();
        $objects[$cs_id] = "<<\n/Length " . strlen($stream) . "\n>>\nstream\n"
                         . $stream . "endstream";

        // Page object
        $pg_id = $alloc();
        $objects[$pg_id] = "<<\n/Type /Page\n/Parent $pages_id 0 R\n"
            . "/MediaBox [0 0 $PW $PH]\n"
            . "/Contents $cs_id 0 R\n"
            . "/Resources <<\n  /Font <<\n"
            . "    /F1 << /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>\n"
            . "    /F3 << /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>\n"
            . "  >>\n>>\n>>";

        $page_ids[] = $pg_id;
    }

    // Pages dict
    $kids = implode(' 0 R ', $page_ids) . ' 0 R';
    $objects[$pages_id] = "<<\n/Type /Pages\n/Kids [$kids]\n/Count " . count($page_ids) . "\n>>";

    // Catalog
    $objects[$cat_id] = "<<\n/Type /Catalog\n/Pages $pages_id 0 R\n>>";

    // ── Assemble ────────────────────────────────────────────────────────────
    $buf  = "%PDF-1.4\n";
    $xref = [];

    for ($i = 1; $i <= $obj_num; $i++) {
        $xref[$i] = strlen($buf);
        $buf .= "$i 0 obj\n" . $objects[$i] . "\nendobj\n";
    }

    $xref_pos = strlen($buf);
    $buf .= "xref\n0 " . ($obj_num + 1) . "\n";
    $buf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $obj_num; $i++) {
        $buf .= str_pad($xref[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $buf .= "trailer\n<<\n/Size " . ($obj_num + 1) . "\n/Root $cat_id 0 R\n>>\n";
    $buf .= "startxref\n$xref_pos\n%%EOF\n";

    return $buf;
}

// ── Content stream for one ticket ─────────────────────────────────────────
// Matches the original GDN bale ticket exactly (609 x 213 pts, pre-printed
// stationery): no borders or dividers, serif bold ~11.5pt at fixed field
// positions measured from a genuine GDN ticket PDF.
// Row 1: splitid | date | bale_group
// Row 2: barcode - TIMB_barcode | lot
// Row 3: grower_num | bale_seq | mass
function ticketStream($PW, $PH, $splitid, $date_str, $bale_group,
                      $barcode, $timb_bc, $lot,
                      $grower_num, $bale_seq, $mass_str): string {

    $FS = 11.52;    // field font size (GDN original)

    $s  = "q\n";

    // ── ROW 1 — baseline y=156.86 ────────────────────────────────────────────
    $s .= tBT( 94.41, 156.86, $splitid,    'F1', $FS);
    $s .= tBT(151.57, 156.86, $date_str,   'F1', $FS);
    $s .= tBT(252.95, 156.86, $bale_group, 'F1', $FS);

    // ── ROW 2 — baseline y=87.73 ─────────────────────────────────────────────
    $s .= tBT( 38.72,  87.73, $barcode,    'F1', $FS);
    $s .= tBT(104.63,  87.73, ' - ',       'F3', 9.22);
    $s .= tBT(112.27,  87.73, $timb_bc,    'F1', $FS);
    $s .= tBT(275.86,  87.73, $lot,        'F1', $FS);

    // ── ROW 3 — baseline y=47.40 ─────────────────────────────────────────────
    $s .= tBT( 38.66,  47.40, $grower_num, 'F1', $FS);
    $s .= tBT(157.58,  47.40, $bale_seq,   'F1', $FS);
    $s .= tBT(217.62,  47.40, $mass_str,   'F1', $FS);

    $s .= "Q\n";
    return $s;
}

// PDF text operator helper
function tBT($x, $y, $text, $font, $size): string {
    $x   = round($x, 2);
    $y   = round($y, 2);
    $esc = str_replace(['\\','(',')'],['\\\\',' \\(','\\)'], $text);
    return "BT\n/$font $size Tf\n$x $y Td\n($esc) Tj\nET\n";
}
