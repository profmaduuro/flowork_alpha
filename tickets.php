<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Tickets";
$active_page = "tickets";

$userid   = current_userid();
$seasonid = (int)($_SESSION['seasonid'] ?? 0);
$spid     = (int)($_SESSION['selling_pointid'] ?? 0);
$today    = date('Y-m-d');

$msg = $err = '';

// ── Ensure ticket_splits table exists ─────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS ticket_splits (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    transporter_growersid INT NOT NULL,
    splitid               INT NOT NULL,
    bale_qty              INT NOT NULL DEFAULT 0,
    status                ENUM('open','confirmed') NOT NULL DEFAULT 'open',
    confirmed_at          TIMESTAMP NULL DEFAULT NULL,
    confirmed_by          INT NULL DEFAULT NULL,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ts_unique (transporter_growersid, splitid),
    FOREIGN KEY (transporter_growersid) REFERENCES transporter_growers(id)
)");

// ── Add lot-range columns to ticket_splits if not present ─────────────────
if ($conn->query("SHOW COLUMNS FROM ticket_splits LIKE 'lot_from'")->num_rows === 0) {
    $conn->query("ALTER TABLE ticket_splits ADD COLUMN lot_from INT NULL DEFAULT NULL");
    $conn->query("ALTER TABLE ticket_splits ADD COLUMN lot_to   INT NULL DEFAULT NULL");
}

// ── Check letter calculation ───────────────────────────────────────────────
function calc_check_letter($conn, $number) {
    $n   = (int)$number;
    $sum = 0;
    $str = str_pad($n, 10, '0', STR_PAD_LEFT);
    for ($i = 0; $i < strlen($str); $i++) $sum += (int)$str[$i];
    $rem = $sum % 43;
    $row = $conn->query("SELECT characters FROM ticket_check_letter WHERE character_value=$rem LIMIT 1")->fetch_assoc();
    return $row ? $row['characters'] : '?';
}

// ── Helper: get group state ────────────────────────────────────────────────
// open    = has tickets but no confirmed splits
// split   = has splits defined but none confirmed
// closed  = all splits confirmed
// none    = no tickets yet
function group_state($conn, $tgid) {
    $tgid = (int)$tgid;
    $tc = (int)$conn->query("SELECT COUNT(*) c FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid WHERE bj.transporter_growersid=$tgid")->fetch_assoc()['c'];
    if ($tc === 0) return 'none';
    $sc = (int)$conn->query("SELECT COUNT(*) c FROM ticket_splits WHERE transporter_growersid=$tgid")->fetch_assoc()['c'];
    if ($sc === 0) return 'open';
    $cc = (int)$conn->query("SELECT COUNT(*) c FROM ticket_splits WHERE transporter_growersid=$tgid AND status='confirmed'")->fetch_assoc()['c'];
    return $cc > 0 ? 'closed' : 'split';
}

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // Every action here works on one delivery group: it must be on this floor.
    $_post_tgid = (int)($_POST['tgid'] ?? $_POST['transporter_growersid'] ?? 0);
    if ($_post_tgid && !flowork_on_floor($conn, 'tg', $_post_tgid, $spid)) {
        $err = "Delivery not found on this floor.";
        $action = '';
    }

    // ── Generate / resume barcodes ────────────────────────────────────────
    if ($action === 'gen_tickets') {
        $tgid          = (int)($_POST['transporter_growersid'] ?? 0);
        $start_barcode = $conn->real_escape_string(trim($_POST['start_barcode'] ?? ''));

        if (!$tgid || !$start_barcode) {
            $err = "Select a group and enter the start barcode.";
        } else {
            // Validate barcode format: 9 digits + check char
            if (!preg_match('/^\d{9}[A-Z0-9\-\. \/\+\%\$]$/i', $start_barcode)) {
                $err = "Barcode must be 9 digits + 1 check character (e.g. 000100001A).";
            } else {
                $sod_q = $conn->query("SELECT id FROM start_of_day WHERE description='$today' AND active=1 LIMIT 1");
                $sod = ($sod_q && $sod_q !== true) ? $sod_q->fetch_assoc() : null;
                if (!$sod) {
                    // fallback: any active start_of_day
                    $sod_q2 = $conn->query("SELECT id FROM start_of_day WHERE active=1 ORDER BY id DESC LIMIT 1");
                    $sod = ($sod_q2 && $sod_q2 !== true) ? $sod_q2->fetch_assoc() : null;
                }
                if (!$sod) {
                    $err = "No selling date open. Go to Settings → Set Selling Date first.";
                } else {
                    $sod_id = (int)$sod['id'];

                    // Check state — refuse if CLOSED
                    $state = group_state($conn, $tgid);
                    if ($state === 'closed') {
                        $err = "This group is CLOSED (splits confirmed). Reverse the split first before re-generating tickets.";
                    } else {
                        // Check start barcode not already used by ANOTHER group
                        $bc_used = $conn->query("
                            SELECT tk.id FROM tickets tk
                            JOIN bale_junus bj ON bj.id=tk.bale_junusid
                            WHERE tk.barcode='$start_barcode'
                              AND bj.transporter_growersid != $tgid
                            LIMIT 1")->num_rows;
                        if ($bc_used) {
                            $err = "Barcode '$start_barcode' is already used by another group.";
                        } else {
                            $my_number = (int)substr(preg_replace('/[^0-9]/','',$start_barcode), 0, 9);

                            // Only generate for junus that DON'T have a ticket yet (resume mode)
                            // If no tickets exist yet → generate all
                            // If some exist → only generate the missing ones starting from start_barcode
                            $junus = $conn->query("
                                SELECT bj.id, tk.id AS existing_ticket_id
                                FROM bale_junus bj
                                LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
                                WHERE bj.transporter_growersid=$tgid
                                ORDER BY bj.id ASC");

                            $created = $updated = $skipped = 0;
                            while ($bj = $junus->fetch_assoc()) {
                                $bjid = (int)$bj['id'];
                                if ($bj['existing_ticket_id']) {
                                    // Already has ticket — skip (resume: don't overwrite existing)
                                    $skipped++;
                                    $my_number++; // still increment counter to stay in sequence
                                    continue;
                                }
                                $check   = calc_check_letter($conn, $my_number);
                                $barcode = $my_number . $check;
                                $conn->query("INSERT INTO tickets (userid,bale_junusid,barcode,created_at,start_of_dayid)
                                              VALUES ($userid,$bjid,'$barcode','$today',$sod_id)");
                                $created++;
                                $my_number++;
                            }

                            if ($created > 0 || $skipped > 0)
                                $msg = "Tickets: $created generated" . ($skipped > 0 ? ", $skipped already had barcodes (skipped — resume mode)" : "") . ".";
                            else
                                $err = "No new junus found to assign barcodes to.";
                        }
                    }
                }
            }
        }
    }

    // ── Save split definition ─────────────────────────────────────────────
    if ($action === 'save_splits') {
        $tgid   = (int)($_POST['tgid'] ?? 0);
        $splits = $_POST['splits'] ?? []; // [splitid => bale_qty]
        $state  = group_state($conn, $tgid);

        if ($state === 'closed') {
            $err = "Cannot edit splits — this group is CLOSED. Reverse first.";
        } elseif ($tgid && !empty($splits)) {
            // Validate total bales
            $total_bales = (int)$conn->query("SELECT COUNT(*) c FROM bale_junus WHERE transporter_growersid=$tgid")->fetch_assoc()['c'];
            $split_total = array_sum(array_map('intval', $splits));

            if ($split_total !== $total_bales) {
                $err = "Split total ($split_total bales) must equal total bales ($total_bales). Please adjust.";
            } else {
                // Delete existing unconfirmed splits
                $conn->query("DELETE FROM ticket_splits WHERE transporter_growersid=$tgid AND status='open'");
                $saved = 0;
                foreach ($splits as $sid => $qty) {
                    $sid = (int)$sid; $qty = (int)$qty;
                    if ($qty > 0) {
                        $conn->query("INSERT INTO ticket_splits (transporter_growersid,splitid,bale_qty,status)
                                      VALUES ($tgid,$sid,$qty,'open')
                                      ON DUPLICATE KEY UPDATE bale_qty=$qty, status='open'");
                        $saved++;
                    }
                }
                $msg = "$saved split(s) saved. Review and confirm to lock.";
            }
        }
    }

    // ── Confirm splits ────────────────────────────────────────────────────
    if ($action === 'confirm_splits') {
        $tgid = (int)($_POST['tgid'] ?? 0);
        $now  = date('Y-m-d H:i:s');

        $sc = (int)$conn->query("SELECT COUNT(*) c FROM ticket_splits WHERE transporter_growersid=$tgid AND status='open'")->fetch_assoc()['c'];
        if ($sc === 0) {
            $err = "No pending splits to confirm. Define splits first.";
        } else {
            $conn->query("UPDATE ticket_splits SET status='confirmed', confirmed_at='$now', confirmed_by=$userid WHERE transporter_growersid=$tgid AND status='open'");
            $msg = "Splits confirmed — group is now CLOSED. Proceed to Junus with split assignments.";
        }
    }

    // ── Reverse CLOSED → SPLIT ────────────────────────────────────────────
    if ($action === 'reverse_confirm') {
        $tgid = (int)($_POST['tgid'] ?? 0);
        $conn->query("UPDATE ticket_splits SET status='open', confirmed_at=NULL, confirmed_by=NULL WHERE transporter_growersid=$tgid AND status='confirmed'");
        $msg = "Confirmation reversed — group is back to SPLIT state. Edit splits then confirm again.";
    }

    // ── Reverse SPLIT → OPEN (delete all splits) ──────────────────────────
    if ($action === 'reverse_splits') {
        $tgid = (int)($_POST['tgid'] ?? 0);
        $conn->query("DELETE FROM ticket_splits WHERE transporter_growersid=$tgid AND status='open'");
        $msg = "Splits removed — group is back to OPEN state.";
    }

    // ── Delete all tickets for a group (full reversal) ─────────────────────
    if ($action === 'delete_tickets') {
        $tgid  = (int)($_POST['tgid'] ?? 0);
        $state = group_state($conn, $tgid);
        if ($state === 'closed') {
            $err = "Cannot delete tickets — splits are confirmed. Reverse the split first.";
        } else {
            $conn->query("DELETE FROM ticket_splits WHERE transporter_growersid=$tgid");
            $conn->query("DELETE tk FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid WHERE bj.transporter_growersid=$tgid");
            $msg = "All tickets deleted for this group. You can now re-generate from any barcode.";
        }
    }

    // ── Printer jam recovery — reissue barcodes to un-printed tickets ─────────
    if ($action === 'jam_rebarcode') {
        $tgid         = (int)($_POST['tgid']         ?? 0);
        $last_good_bc = strtoupper(trim($_POST['last_good_bc'] ?? ''));
        $new_start_bc = strtoupper(trim($_POST['new_start_bc'] ?? ''));

        if (!$tgid || !$new_start_bc) {
            $err = "Please enter the new start barcode.";
        } elseif (!preg_match('/^\d{9}[A-Z0-9\-\. \/\+\%\$]$/i', $new_start_bc)) {
            $err = "New start barcode must be 9 digits + 1 check character (e.g. 000100031A).";
        } else {
            // Find the bj.id cutoff — tickets WITH bj.id > cutoff need new barcodes
            $cutoff_bjid = 0;
            if ($last_good_bc !== '') {
                $lg = $conn->real_escape_string($last_good_bc);
                $lr = $conn->query("
                    SELECT bj.id FROM tickets tk
                    JOIN bale_junus bj ON bj.id = tk.bale_junusid
                    WHERE tk.barcode='$lg' AND bj.transporter_growersid=$tgid
                    LIMIT 1")->fetch_assoc();
                if (!$lr) {
                    $err = "Barcode '$last_good_bc' not found in this group. Check and try again.";
                } else {
                    $cutoff_bjid = (int)$lr['id'];
                }
            }
            // (if last_good_bc is empty the user means the jam hit before anything printed → rebarcode ALL)

            if (!$err) {
                // Fetch the tickets that need new barcodes, in print order
                $where = $cutoff_bjid > 0
                    ? "bj.transporter_growersid=$tgid AND bj.id > $cutoff_bjid"
                    : "bj.transporter_growersid=$tgid";
                $affected = $conn->query("
                    SELECT tk.id AS tkid
                    FROM tickets tk
                    JOIN bale_junus bj ON bj.id = tk.bale_junusid
                    WHERE $where
                    ORDER BY bj.id ASC");

                $to_recode = [];
                while ($r = $affected->fetch_assoc()) $to_recode[] = (int)$r['tkid'];

                if (empty($to_recode)) {
                    $err = "All tickets in this group have already been printed — nothing to reissue.";
                } else {
                    // Pre-check: none of the new barcodes clash with OTHER groups
                    $my_num   = (int)substr(preg_replace('/[^0-9]/', '', $new_start_bc), 0, 9);
                    $conflict = '';
                    for ($i = 0; $i < count($to_recode); $i++) {
                        $chk     = calc_check_letter($conn, $my_num + $i);
                        $test_bc = $conn->real_escape_string(($my_num + $i) . $chk);
                        $clash   = $conn->query("
                            SELECT tk.id FROM tickets tk
                            JOIN bale_junus bj ON bj.id=tk.bale_junusid
                            WHERE tk.barcode='$test_bc'
                              AND bj.transporter_growersid != $tgid
                            LIMIT 1");
                        if ($clash && $clash->num_rows > 0) {
                            $conflict = ($my_num + $i) . $chk;
                            break;
                        }
                    }

                    if ($conflict) {
                        $err = "Barcode '$conflict' is already used by another group. Choose a different start barcode.";
                    } else {
                        // All clear — overwrite the barcodes
                        $my_num  = (int)substr(preg_replace('/[^0-9]/', '', $new_start_bc), 0, 9);
                        $updated = 0;
                        foreach ($to_recode as $tkid) {
                            $chk    = calc_check_letter($conn, $my_num);
                            $new_bc = $conn->real_escape_string($my_num . $chk);
                            $conn->query("UPDATE tickets SET barcode='$new_bc' WHERE id=$tkid");
                            $my_num++;
                            $updated++;
                        }
                        // Redirect straight to the PDF for the re-issued tickets
                        header("Location: ticket_pdf.php?tgid=$tgid&from=" . urlencode($new_start_bc));
                        exit;
                    }
                }
            }
        }
    }

    // ── Save lot-by-lot splits (Today's Tickets modal) ───────────────────────
    // POST: lot_split[LOT_VALUE] = SPLIT_ID  (one entry per lot captured in Junus)
    if ($action === 'save_lot_splits') {
        $tgid       = (int)($_POST['tgid']   ?? 0);
        $lot_splits = $_POST['lot_split']    ?? [];   // {'lot_value' => split_id}
        $state      = group_state($conn, $tgid);

        if ($state === 'closed') {
            $err = "Cannot edit splits — this group is CLOSED. Reverse first.";
        } elseif (!$tgid || empty($lot_splits)) {
            $err = "No split assignments received.";
        } else {
            // Group lots by split id
            $by_split = [];
            foreach ($lot_splits as $lot => $sid) {
                $sid = (int)$sid;
                if ($sid > 0) $by_split[$sid][] = $conn->real_escape_string((string)$lot);
            }

            if (empty($by_split)) {
                $err = "No lots have been assigned to any split.";
            } else {
                $total_bales = (int)$conn->query(
                    "SELECT COUNT(*) c FROM bale_junus WHERE transporter_growersid=$tgid"
                )->fetch_assoc()['c'];

                $splits = [];
                $split_total = 0;
                $valid = true;

                foreach ($by_split as $sid => $lots) {
                    $in_list = "'" . implode("','", $lots) . "'";
                    $cnt = (int)$conn->query("
                        SELECT COUNT(*) c FROM bale_junus
                        WHERE transporter_growersid=$tgid
                          AND lot IN ($in_list)")->fetch_assoc()['c'];
                    if ($cnt === 0) {
                        $err = "Split $sid: no bales found for the assigned lots.";
                        $valid = false; break;
                    }
                    // min/max lot for display (numeric order)
                    $rng = $conn->query("
                        SELECT MIN(lot+0) AS lf, MAX(lot+0) AS lt
                        FROM bale_junus
                        WHERE transporter_growersid=$tgid
                          AND lot IN ($in_list)")->fetch_assoc();
                    $splits[$sid] = ['qty'=>$cnt,'lf'=>(int)$rng['lf'],'lt'=>(int)$rng['lt']];
                    $split_total += $cnt;
                }

                if ($valid && $split_total !== $total_bales) {
                    $err = "Splits cover $split_total bales but this group has $total_bales. All bales must be assigned.";
                    $valid = false;
                }

                if ($valid) {
                    $conn->query("DELETE FROM ticket_splits WHERE transporter_growersid=$tgid AND status='open'");
                    ksort($splits);
                    $idx = 1;
                    foreach ($splits as $s) {
                        $conn->query("
                            INSERT INTO ticket_splits
                                (transporter_growersid, splitid, bale_qty, lot_from, lot_to, status)
                            VALUES ($tgid, $idx, {$s['qty']}, {$s['lf']}, {$s['lt']}, 'open')");
                        $idx++;
                    }
                    header("Location: ?tgid=$tgid");
                    exit;
                }
            }
        }
    }
}

// ── Load groups ─────────────────────────────────────────────────────────────
$tg_list = $conn->query("
    SELECT tg.id, tg.bales, tg.junused_bales,
           g.grower_num, g.name AS gname, g.surname,
           t.name AS transporter_name, t.truck_num,
           bl.code AS loc_code,
           COUNT(DISTINCT bj.id) AS junus_count,
           COUNT(DISTINCT tk.id) AS ticket_count,
           MAX(tk.barcode)       AS last_barcode
    FROM transporter_growers tg
    JOIN growers g ON g.id=tg.growerid
    JOIN transporter t ON t.id=tg.transporterid
    LEFT JOIN bale_locations bl ON bl.id=t.location_id
    LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
    LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
    WHERE t.seasonid=$seasonid
      AND ($spid=0 OR g.selling_pointid=$spid OR g.selling_pointid=0)
    GROUP BY tg.id
    HAVING junus_count > 0
    ORDER BY
        CASE WHEN tg.bales > 0 AND COUNT(DISTINCT bj.id) >= tg.bales THEN 1 ELSE 2 END ASC,
        tg.sell_date DESC, loc_code ASC, t.name ASC, g.grower_num ASC
");

// Build state map
$groups = [];
$tg_list->data_seek(0);
while ($r = $tg_list->fetch_assoc()) {
    $r['state'] = group_state($conn, $r['id']);
    // Load splits if any
    $r['splits'] = [];
    $sq = $conn->query("SELECT splitid, bale_qty, status FROM ticket_splits WHERE transporter_growersid={$r['id']} ORDER BY splitid");
    while ($s = $sq->fetch_assoc()) $r['splits'][] = $s;
    $groups[] = $r;
}

// Splits lookup table (max splitid from DB)
$max_splits = 15; // from splits table values 0-15

// Selected group for detail panel
$sel_tgid = (int)($_GET['tgid'] ?? 0);
$sel_group = null;
foreach ($groups as $g) { if ($g['id'] == $sel_tgid) { $sel_group = $g; break; } }

// State counts
$counts = ['none'=>0,'open'=>0,'split'=>0,'closed'=>0,'complete'=>0];
foreach ($groups as $g) {
    $counts[$g['state']]++;
    if ((int)$g['bales']>0 && (int)$g['junus_count']>=(int)$g['bales']) $counts['complete']++;
}

// Recent tickets log
$recent_tickets = $conn->query("
    SELECT tk.barcode, tk.created_at,
           g.grower_num, g.name AS gname, g.surname,
           t.name AS transporter_name, bl.code AS loc_code,
           bm.mass,
           sb.price,
           tg_desc.description AS timb_grade,
           bj.transporter_growersid AS tgid
    FROM tickets tk
    JOIN bale_junus bj ON bj.id=tk.bale_junusid
    JOIN growers g ON g.id=bj.growerid
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
    JOIN transporter t ON t.id=tg.transporterid
    LEFT JOIN bale_locations bl ON bl.id=t.location_id
    LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
    LEFT JOIN timb_grades tg_desc ON tg_desc.id=sb.timb_gradesid
    WHERE tk.created_at='$today'
      AND ($spid=0 OR g.selling_pointid=$spid OR g.selling_pointid=0)
    ORDER BY tk.id DESC LIMIT 60
");

// ── Today's tickets — grouped by tgid for the split management table ─────────
$today_groups = [];
$tg_today_q = $conn->query("
    SELECT tg.id AS tgid,
           g.grower_num, g.name AS gname, g.surname,
           bl.code AS loc_code,
           COUNT(DISTINCT tk.id)          AS ticket_count,
           COALESCE(SUM(bm.mass), 0)      AS total_mass
    FROM tickets tk
    JOIN bale_junus bj   ON bj.id  = tk.bale_junusid
    JOIN bale_mass bm    ON bm.id  = bj.bale_massid
    JOIN growers g       ON g.id   = bj.growerid
    JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
    JOIN transporter t   ON t.id   = tg.transporterid
    LEFT JOIN bale_locations bl ON bl.id = t.location_id
    WHERE DATE(tk.created_at) = '$today'
      AND ($spid=0 OR g.selling_pointid=$spid OR g.selling_pointid=0)
    GROUP BY tg.id, g.grower_num, g.name, g.surname, bl.code
    ORDER BY bl.code ASC, g.grower_num ASC
");
if ($tg_today_q) {
    while ($tgr = $tg_today_q->fetch_assoc()) {
        $tid = (int)$tgr['tgid'];
        $tgr['state'] = group_state($conn, $tid);

        // Lot distribution: lot → bale count
        $lq = $conn->query("
            SELECT bj.lot, COUNT(DISTINCT tk2.id) AS bales
            FROM bale_junus bj
            JOIN tickets tk2 ON tk2.bale_junusid = bj.id
            WHERE bj.transporter_growersid = $tid
              AND DATE(tk2.created_at) = '$today'
            GROUP BY bj.lot
            ORDER BY (bj.lot + 0) ASC, bj.lot ASC");
        $tgr['lots'] = [];
        if ($lq) while ($l = $lq->fetch_assoc())
            $tgr['lots'][] = ['lot' => $l['lot'], 'bales' => (int)$l['bales']];

        // Existing splits (include lot_from / lot_to)
        $sq2 = $conn->query("
            SELECT splitid, bale_qty,
                   COALESCE(lot_from, 0) AS lot_from,
                   COALESCE(lot_to,   0) AS lot_to,
                   status
            FROM ticket_splits
            WHERE transporter_growersid = $tid
            ORDER BY splitid ASC");
        $tgr['splits'] = [];
        if ($sq2) while ($sp = $sq2->fetch_assoc()) $tgr['splits'][] = $sp;

        $today_groups[] = $tgr;
    }
}

// Build JS data objects
$lot_data_js   = [];
$split_data_js = [];
$today_meta_js = [];
foreach ($today_groups as $tg2) {
    $tid2 = $tg2['tgid'];
    $lot_data_js[$tid2]   = $tg2['lots'];
    $split_data_js[$tid2] = $tg2['splits'];
    $today_meta_js[$tid2] = [
        'grower_num'   => $tg2['grower_num'],
        'gname'        => $tg2['gname'],
        'surname'      => $tg2['surname'],
        'loc_code'     => $tg2['loc_code'] ?? '',
        'state'        => $tg2['state'],
        'ticket_count' => (int)$tg2['ticket_count'],
    ];
}

include "includes/layout.php";
?>

<style>
/* ── State badges ─────────────────────────────────────────────────────── */
.sb { display:inline-block;font-size:.78rem;font-weight:600;padding:2px 9px;border-radius:999px }
.sb-none   { background:var(--surface-2);color:#6b7280 }
body.theme-dark .sb-none { background:rgba(245,240,232,.08);color:#bdb3a3 }
.sb-open   { background:var(--warn-tint);color:var(--warn-text) }
.sb-split  { background:var(--info-tint);color:var(--info-text) }
.sb-closed { background:var(--ok-tint);color:var(--ok-text) }
.sb-ready  { background:var(--ok-tint);color:var(--ok-text);border:1px solid #86efac }

/* ── Filter tabs ─────────────────────────────────────────────────────── */
.ftabs { display:flex;gap:4px;flex-wrap:wrap;margin-bottom:0 }
.ftab  { padding:5px 14px;border-radius:999px;font-size:0.72rem;font-weight:500;
         cursor:pointer;border:1px solid var(--border);background:var(--surface);
         color:var(--muted);transition:all 0.15s;user-select:none }
.ftab:hover   { border-color:var(--gold);color:var(--gold) }
.ftab.active  { background:var(--primary-bg);color:var(--primary-text);border-color:var(--primary-bg) }

/* ── Group rows ──────────────────────────────────────────────────────── */
.grow { cursor:pointer;transition:background 0.1s }
.grow:hover { background:var(--surface-2) }
.grow.sel   { background:var(--surface-2);border-left:3px solid var(--gold) }
.grow.g-ready { background:var(--surface-2) }
body.theme-dark .grow:hover   { background:rgba(245,240,232,.05) }
body.theme-dark .grow.sel     { background:rgba(200,149,42,.14) }
body.theme-dark .grow.g-ready { background:rgba(61,107,53,.18) }
.grow.g-ready.sel { background:var(--surface-2) }

/* ── Progress pill ───────────────────────────────────────────────────── */
.prog-pill { display:inline-flex;align-items:center;gap:4px;
             font-size:0.7rem;font-weight:600;padding:2px 8px;
             border-radius:999px;white-space:nowrap }
.prog-done { background:var(--ok-tint);color:var(--ok-text) }
.prog-part { background:var(--warn-tint);color:var(--warn-text) }
.prog-none { background:var(--surface-2);color:#6b7280 }

/* ── Step strip ──────────────────────────────────────────────────────── */
.steps { display:flex;gap:0;margin-bottom:1rem;border-radius:var(--radius);overflow:hidden;border:1px solid var(--border) }
.step  { flex:1;padding:8px 6px;text-align:center;font-size:.78rem;font-weight:500;
         color:var(--muted);
         background:var(--surface);border-right:1px solid var(--border);position:relative }
.step:last-child { border-right:none }
.step.done  { background:var(--ok-tint);color:var(--ok-text) }
.step.active{ background:var(--primary-bg);color:var(--primary-text) }
.step .snum { display:block;font-size:1rem;font-weight:700;line-height:1;margin-bottom:2px }

/* ── Print button ────────────────────────────────────────────────────── */
.btn-print { background:#1a4d2e;color:#fff;border:none;padding:0.55rem 1.2rem;
             border-radius:var(--radius);font-weight:600;font-size:0.85rem;
             cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem;
             text-decoration:none;transition:background 0.15s }
.btn-print:hover { background:#2d7a4f;color:#fff }
.btn-print.large { padding:0.75rem 1.6rem;font-size:1rem;width:100%;justify-content:center }

/* ── Search box ──────────────────────────────────────────────────────── */
#grp-search { width:100%;padding:0.45rem 0.85rem;border:1px solid var(--border);
              border-radius:var(--radius);font-size:0.82rem;background:var(--surface);
              color:var(--text);outline:none }
#grp-search:focus { border-color:var(--gold) }

/* ── Today's tickets table ───────────────────────────────────────────── */
.today-row { cursor:pointer;transition:background 0.1s }
.today-row:hover { background:var(--surface-2) }
.today-row.sel   { background:var(--surface-2);border-left:3px solid var(--gold) }
.today-panel-row td { padding:0 !important }
.today-panel-inner { padding:1rem 1.25rem;border-top:2px solid var(--gold) }

.lot-chip { min-width:30px;height:30px;border-radius:5px;border:1px solid #e5e7eb;
            background:var(--surface-2);display:inline-flex;align-items:center;justify-content:center;
            font-size:0.65rem;font-weight:700;color:#6b7280;
            position:relative;transition:background 0.15s,color 0.15s,border-color 0.15s;
            cursor:default }
.lot-chip .lot-badge { position:absolute;top:-5px;right:-5px;background:#374151;color:#fff;
                       border-radius:999px;font-size:.72rem;padding:0 3px;line-height:1.5;
                       font-weight:700 }

.split-row-inner { display:flex;align-items:center;gap:0.5rem;margin-bottom:0.4rem;
                   background:var(--surface);border:1px solid var(--border);
                   border-radius:var(--radius);padding:0.5rem 0.75rem;
                   transition:border-color 0.15s }
.split-row-inner:hover { border-color:#d1d5db }
.split-num-input { width:56px;padding:0.28rem 0.4rem;border:1px solid var(--border);
                   border-radius:4px;font-size:0.85rem;text-align:center;font-weight:600;
                   color:var(--text);background:var(--surface);outline:none }
.split-num-input:focus { border-color:var(--gold) }

/* Premium Segmented / Capsule Tab Strip */
.premium-tabs-nav {
    display: inline-flex;
    padding: 4px;
    background: var(--cream-d);
    border-radius: 8px;
    margin-bottom: 1.5rem;
    gap: 2px;
    border: 1px solid var(--border);
}
body.theme-dark .premium-tabs-nav {
    background: rgba(26,18,8,0.3);
}
.premium-tab-btn {
    padding: 0.6rem 1.2rem;
    background: transparent;
    border: none;
    border-radius: 6px;
    font-family: inherit;
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--muted);
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    user-select: none;
}
.premium-tab-btn:hover {
    color: var(--ink);
    background: rgba(26, 18, 8, 0.04);
}
body.theme-dark .premium-tab-btn:hover {
    color: var(--cream);
    background: rgba(255, 255, 255, 0.04);
}
.premium-tab-btn.active {
    background:var(--primary-bg);
    color:var(--primary-text);
    box-shadow: 0 4px 12px rgba(26, 18, 8, 0.15);
    font-weight: 600;
}
body.theme-light .premium-tab-btn.active {
    background:var(--primary-bg);
    color:var(--primary-text);
}
body.theme-dark .premium-tab-btn.active {
    background: var(--gold);
    color: var(--ink);
    box-shadow: 0 4px 12px rgba(200, 149, 42, 0.2);
}

.ticket-tab-panel {
    display: none;
}
.ticket-tab-panel.active {
    display: block;
    animation: ticketFadeIn 0.25s ease-in-out;
}

@keyframes ticketFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.ticket-console-container {
    max-width: 780px;
    margin: 0 auto;
}
</style>

<?php
// Compute "ready-to-print" count (junus complete AND tickets generated)
$ready_print = 0;
$needs_bc    = 0;
foreach ($groups as $g) {
    $full = (int)$g['bales'] > 0 && (int)$g['junus_count'] >= (int)$g['bales'];
    if ($full && (int)$g['ticket_count'] > 0) $ready_print++;
    if ((int)$g['ticket_count'] === 0) $needs_bc++;
}
?>

<div class="page-header">
    <div class="page-header-left">
        <h2>Tickets</h2>
        <p>Barcode assignment · split management · printing</p>
    </div>
    <div class="flex gap-2" style="flex-wrap:wrap">
        <span class="sb sb-ready">🖨 <?= $ready_print ?> ready to print</span>
        <span class="sb sb-none"><?= $needs_bc ?> need barcodes</span>
        <span class="sb sb-open"><?= $counts['open'] ?> open</span>
        <span class="sb sb-split"><?= $counts['split'] ?> split</span>
        <span class="sb sb-closed"><?= $counts['closed'] ?> closed</span>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Tab Navigation Strip -->
<div class="premium-tabs-nav">
    <button class="premium-tab-btn active" data-tab="groups" onclick="switchTicketTab('groups')">
        🎟️ Grower Groups
    </button>
    <button class="premium-tab-btn" data-tab="details" onclick="switchTicketTab('details')">
        📋 Group Details <?= $sel_group ? '('.htmlspecialchars($sel_group['grower_num']).')' : '' ?>
    </button>
    <button class="premium-tab-btn" data-tab="today" onclick="switchTicketTab('today')">
        🖨️ Today's Tickets
    </button>
</div>

<!-- Tab Panel 1: Grower Groups -->
<div id="panel-groups" class="ticket-tab-panel active">

<!-- ═══════════════════ LEFT: GROUPS TABLE ═══════════════════ -->
<div class="card">
    <!-- Card header with search + filters -->
    <div style="padding:0.85rem 1rem;border-bottom:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.7rem">
            <h3 style="margin:0">Grower Groups</h3>
            <span class="badge badge-gray" id="visible-count"><?= count($groups) ?></span>
            <input id="grp-search" type="text" placeholder="Search grower # or name…" style="max-width:220px;margin-left:auto" oninput="filterTable()">
        </div>
        <div class="ftabs" id="filter-tabs">
            <div class="ftab active" data-filter="all"    onclick="setFilter(this,'all')">All (<?= count($groups) ?>)</div>
            <div class="ftab"        data-filter="ready"  onclick="setFilter(this,'ready')">✓ Ready to Print (<?= $ready_print ?>)</div>
            <div class="ftab"        data-filter="none"   onclick="setFilter(this,'none')">Needs Barcodes (<?= $needs_bc ?>)</div>
            <div class="ftab"        data-filter="open"   onclick="setFilter(this,'open')">Open (<?= $counts['open'] ?>)</div>
            <div class="ftab"        data-filter="split"  onclick="setFilter(this,'split')">Split (<?= $counts['split'] ?>)</div>
            <div class="ftab"        data-filter="closed" onclick="setFilter(this,'closed')">Closed (<?= $counts['closed'] ?>)</div>
        </div>
    </div>

    <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
    <table id="groups-table">
        <thead style="position:sticky;top:0;z-index:1">
        <tr>
            <th style="width:42px">Loc</th>
            <th>Grower</th>
            <th>Transporter</th>
            <th class="text-center" style="width:80px">Progress</th>
            <th class="text-center" style="width:64px">Tickets</th>
            <th style="width:100px">Status</th>
            <th style="width:44px"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $g):
            $full    = (int)$g['bales'] > 0 && (int)$g['junus_count'] >= (int)$g['bales'];
            $jc      = (int)$g['junus_count'];
            $bc_done = (int)$g['bales'];
            $tc      = (int)$g['ticket_count'];
            $state_g = $g['state'];
            $is_ready= $full && $tc > 0;
        ?>
        <tr class="grow <?= $sel_tgid==$g['id']?'sel':'' ?> <?= $full?'g-ready':'' ?>"
            data-state="<?= $state_g ?>"
            data-ready="<?= $is_ready ? '1' : '0' ?>"
            data-search="<?= strtolower(htmlspecialchars($g['grower_num'].' '.$g['gname'].' '.$g['surname'])) ?>"
            onclick="window.location='?tgid=<?= $g['id'] ?>'">

            <td style="text-align:center">
                <?php if ($g['loc_code']): ?>
                <span style="font-family:'DM Sans',sans-serif;font-size:0.89rem;color:var(--gold);font-weight:600"><?= htmlspecialchars($g['loc_code']) ?></span>
                <?php else: ?><span class="td-muted">—</span><?php endif; ?>
            </td>

            <td>
                <div class="fw-500 text-gold" style="font-size:0.8rem"><?= htmlspecialchars($g['grower_num']) ?></div>
                <div class="td-muted" style="font-size:0.68rem;line-height:1.3"><?= htmlspecialchars($g['gname'].' '.$g['surname']) ?></div>
            </td>

            <td class="td-muted" style="font-size:0.75rem"><?= htmlspecialchars($g['transporter_name']) ?></td>

            <td class="text-center">
                <?php if ($full): ?>
                    <span class="prog-pill prog-done">✓ <?= $jc ?>/<?= $bc_done ?></span>
                <?php elseif ($jc > 0): ?>
                    <span class="prog-pill prog-part"><?= $jc ?>/<?= $bc_done ?></span>
                <?php else: ?>
                    <span class="prog-pill prog-none">0/<?= $bc_done ?></span>
                <?php endif; ?>
            </td>

            <td class="text-center td-mono" style="font-size:0.85rem;font-weight:<?= $tc>0?'600':'400' ?>;color:<?= $tc>0?'var(--leaf)':'var(--muted)' ?>">
                <?= $tc > 0 ? $tc : '—' ?>
            </td>

            <td>
                <span class="sb sb-<?= $state_g ?>"><?= $state_g ?></span>
            </td>

            <td style="text-align:center">
                <?php if ($tc > 0): ?>
                <a href="ticket_pdf.php?tgid=<?= $g['id'] ?>" target="_blank"
                   class="btn-print" style="padding:4px 10px;font-size:0.75rem"
                   onclick="event.stopPropagation()" title="Print tickets">🖨</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div id="no-rows" style="display:none;text-align:center;padding:2.5rem;color:var(--muted);font-size:0.85rem">No groups match this filter.</div>
    </div>
</div>
</div>

<!-- Tab Panel 2: Group Details -->
<div id="panel-details" class="ticket-tab-panel">
    <div class="ticket-console-container">
        <?php if (!$sel_group): ?>
        <div class="card" style="padding:3rem;text-align:center;color:var(--muted)">
            <span style="font-size:2rem;display:block;margin-bottom:1rem">📋</span>
            Select a grower group from the <strong>Grower Groups</strong> tab first to view and confirm splits.
        </div>
        <?php endif; ?>
<?php if ($sel_group): ?>
<?php
$state     = $sel_group['state'];
$tgid_sel  = $sel_group['id'];
$junus_cnt = (int)$sel_group['junus_count'];
$tk_cnt    = (int)$sel_group['ticket_count'];
$bales_set = (int)$sel_group['bales'];
$missing   = $junus_cnt - $tk_cnt;
$full_junus= $bales_set > 0 && $junus_cnt >= $bales_set;

$next_bc_suggestion = '';
if ($sel_group['last_barcode']) {
    $last_num = (int)substr($sel_group['last_barcode'], 0, 9);
    $next_num = $last_num + 1;
    $next_bc_suggestion = $next_num . calc_check_letter($conn, $next_num);
}

// Step completion
$step1 = $full_junus;                       // Junus complete
$step2 = $tk_cnt > 0;                       // Barcodes assigned
$step3 = in_array($state, ['split','closed']); // Splits defined
$step4 = $state === 'closed';               // Splits confirmed

// Barcode list for jam recovery
$bc_list = [];
if ($tk_cnt > 0) {
    $bc_q = $conn->query("
        SELECT tk.barcode,
               ROW_NUMBER() OVER (ORDER BY bj.id) AS seq
        FROM tickets tk
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        WHERE bj.transporter_growersid=$tgid_sel
        ORDER BY bj.id ASC");
    while ($br = $bc_q->fetch_assoc()) $bc_list[] = $br;
}
?>

<!-- Grower identity bar -->
<div style="background:var(--surface-2);color:var(--text);border-radius:var(--radius) var(--radius) 0 0;padding:0.9rem 1.1rem">
    <div style="display:flex;align-items:flex-start;gap:0.75rem">
        <div style="flex:1">
            <div style="font-family:'DM Sans',sans-serif;font-size:0.98rem;color:var(--gold);line-height:1;font-weight:600">
                <?= htmlspecialchars($sel_group['grower_num']) ?>
                <?php if ($full_junus): ?><span style="font-size:0.65rem;background:#14532d;color:#86efac;padding:1px 7px;border-radius:999px;vertical-align:middle;margin-left:6px">✓ JUNUS COMPLETE</span><?php endif; ?>
            </div>
            <div style="font-size:0.72rem;color:rgba(245,240,232,0.65);margin-top:3px">
                <?= htmlspecialchars($sel_group['gname'].' '.$sel_group['surname']) ?>
                &nbsp;·&nbsp; <?= htmlspecialchars($sel_group['transporter_name']) ?>
                <?php if ($sel_group['loc_code']): ?>&nbsp;·&nbsp; <?= htmlspecialchars($sel_group['loc_code']) ?><?php endif; ?>
            </div>
        </div>
        <div style="text-align:right;flex-shrink:0">
            <div style="font-size:1.4rem;font-weight:700;line-height:1;color:#fff"><?= $junus_cnt ?><span style="font-size:0.8rem;color:rgba(255,255,255,0.45)">/<?= $bales_set ?></span></div>
            <div style="font-size:.78rem;color:rgba(245,240,232,0.45);">bales junused</div>
        </div>
    </div>
</div>

<!-- Step progress strip -->
<div class="steps">
    <div class="step <?= $step1?'done':($state==='none'?'active':'') ?>">
        <span class="snum"><?= $step1 ? '✓' : '1' ?></span>Junus
    </div>
    <div class="step <?= $step2?'done':(!$step2&&$step1?'active':'') ?>">
        <span class="snum"><?= $step2 ? '✓' : '2' ?></span>Barcodes
    </div>
    <div class="step <?= $step4?'done':($step3&&!$step4?'done':'') ?> <?= $step2&&!$step3?'active':'' ?>">
        <span class="snum"><?= $step3 ? '✓' : '3' ?></span>Splits
    </div>
    <div class="step <?= $step4?'active':'' ?> <?= $step4?'done':'' ?>">
        <span class="snum"><?= $step4 ? '✓' : '4' ?></span>Print
    </div>
</div>

<!-- ── SECTION 1: PRINT (if tickets exist) ── -->
<?php if ($tk_cnt > 0): ?>
<?php
$bc_range = $conn->query("
    SELECT MIN(tk.barcode) first_bc, MAX(tk.barcode) last_bc
    FROM tickets tk JOIN bale_junus bj ON bj.id=tk.bale_junusid
    WHERE bj.transporter_growersid=$tgid_sel")->fetch_assoc();
?>
<div class="card" style="border-radius:0;border-top:none;margin-bottom:0.65rem">
    <div style="padding:0.85rem 1rem">
        <a href="ticket_pdf.php?tgid=<?= $tgid_sel ?>" target="_blank" class="btn-print large">
            🖨&nbsp; Print All <?= $tk_cnt ?> Ticket<?= $tk_cnt!=1?'s':'' ?>
        </a>
        <?php if ($bc_range && $bc_range['first_bc']): ?>
        <div style="margin-top:0.5rem;text-align:center;font-size:0.7rem;color:var(--muted)">
            Barcodes: <code><?= htmlspecialchars($bc_range['first_bc']) ?></code>
            &rarr; <code><?= htmlspecialchars($bc_range['last_bc']) ?></code>
        </div>
        <?php endif; ?>

        <!-- ── Jam recovery ─────────────────────────────────────── -->
        <details id="jam-panel" style="margin-top:0.85rem">
            <summary style="cursor:pointer;font-size:0.78rem;font-weight:500;color:var(--muted);
                            list-style:none;display:flex;align-items:center;gap:0.4rem;
                            padding:0.4rem 0.6rem;border-radius:var(--radius);
                            border:1px dashed var(--border);background:var(--surface);
                            user-select:none"
                     onmouseover="this.style.background='#fffbeb'"
                     onmouseout="this.style.background=''">
                <span>🔧</span> Printer jammed? Re-issue barcodes to unprinted tickets…
                <span style="margin-left:auto;opacity:0.4">▼</span>
            </summary>

            <div style="margin-top:0.55rem;padding:0.85rem;background:var(--surface-2);
                        border:1px solid #f59e0b;border-radius:var(--radius)">

                <p style="font-size:0.72rem;color:#92400e;margin:0 0 0.75rem;line-height:1.55">
                    Enter the <strong>last barcode that printed successfully</strong>, then enter the
                    <strong>first barcode on your fresh roll/stack</strong>. The system will overwrite
                    the un-printed tickets with the new barcode sequence and open the PDF immediately.
                    <br><em style="opacity:0.7">Leave "last printed" blank if the jam hit before a single ticket came out.</em>
                </p>

                <form method="POST" onsubmit="return confirmJam()">
                    <input type="hidden" name="action" value="jam_rebarcode">
                    <input type="hidden" name="tgid"   value="<?= $tgid_sel ?>">

                    <!-- Row 1: last printed (with chip picker) -->
                    <div style="margin-bottom:0.65rem">
                        <label style="font-size:.78rem;font-weight:600;color:#92400e;display:block;margin-bottom:4px">
                            ① Last barcode that printed successfully
                        </label>

                        <!-- Chip list -->
                        <div style="display:flex;flex-wrap:wrap;gap:3px;max-height:80px;overflow-y:auto;
                                    padding:0.35rem;margin-bottom:0.4rem;
                                    background:var(--surface);border:1px solid #fde68a;border-radius:5px">
                            <?php foreach ($bc_list as $bc): ?>
                            <button type="button"
                                    class="bc-jam-chip"
                                    data-bc="<?= htmlspecialchars($bc['barcode']) ?>"
                                    data-seq="<?= (int)$bc['seq'] ?>"
                                    onclick="pickLastGood(this)"
                                    style="background:var(--surface);border:1px solid #e5e7eb;border-radius:4px;
                                           padding:2px 7px;font-family:monospace;font-size:0.67rem;
                                           cursor:pointer;color:#374151;white-space:nowrap">
                                <span style="color:#9ca3af;font-size:.72rem">#<?= $bc['seq'] ?>&nbsp;</span><?= htmlspecialchars($bc['barcode']) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>

                        <input type="text" name="last_good_bc" id="jam-last-bc"
                               placeholder="(blank = jam before first ticket)"
                               maxlength="10"
                               oninput="this.value=this.value.toUpperCase();updateJamSummary()"
                               style="width:100%;padding:0.4rem 0.7rem;border:1px solid #f59e0b;
                                      border-radius:var(--radius);font-family:monospace;
                                      font-size:0.88rem;letter-spacing:0.06em;background:var(--info-tint);
                                      color:var(--info-text);outline:none;box-sizing:border-box">
                    </div>

                    <!-- Row 2: new start barcode -->
                    <div style="margin-bottom:0.65rem">
                        <label style="font-size:.78rem;font-weight:600;color:#92400e;display:block;margin-bottom:4px">
                            ② New start barcode (first fresh barcode)
                        </label>
                        <input type="text" name="new_start_bc" id="jam-new-bc"
                               placeholder="e.g. 000100031A" required maxlength="10"
                               oninput="this.value=this.value.toUpperCase();jamNewBcPreview();updateJamSummary()"
                               style="width:100%;padding:0.4rem 0.7rem;border:1px solid #f59e0b;
                                      border-radius:var(--radius);font-family:monospace;
                                      font-size:0.88rem;letter-spacing:0.06em;background:var(--info-tint);
                                      color:var(--info-text);outline:none;box-sizing:border-box">
                        <div id="jam-new-bc-preview"
                             style="font-size:0.67rem;color:var(--muted);margin-top:3px;height:13px"></div>
                    </div>

                    <!-- Summary line -->
                    <div id="jam-summary"
                         style="font-size:0.72rem;font-weight:500;color:#15803d;
                                min-height:16px;margin-bottom:0.65rem"></div>

                    <button type="submit" class="btn-print large"
                            style="background:#92400e;font-size:0.9rem">
                        🔧&nbsp;Re-issue barcodes &amp; print PDF
                    </button>
                </form>
            </div>
        </details>
    </div>
</div>
<?php endif; ?>

<!-- ── SECTION 2: GENERATE / RESUME BARCODES ── -->
<?php if ($state !== 'closed'): ?>
<div class="card" style="border-radius:0;<?= $tk_cnt>0?'border-top:1px solid var(--border);':'' ?>margin-bottom:0.65rem">
    <div class="card-head" style="padding:0.6rem 1rem">
        <div style="font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:var(--muted)">
            <?php if ($tk_cnt === 0): ?>Assign barcodes
            <?php elseif ($missing > 0): ?>Resume — <?= $missing ?> bale<?= $missing!=1?'s':'' ?> missing barcodes
            <?php else: ?>✓ All barcodes assigned
            <?php endif; ?>
        </div>
        <?php if ($missing > 0): ?>
        <span class="badge badge-gold" style="font-size:.72rem"><?= $missing ?> remaining</span>
        <?php endif; ?>
    </div>

    <?php if ($tk_cnt === 0 || $missing > 0): ?>
    <div style="padding:0.75rem 1rem">
        <?php if ($missing > 0 && $next_bc_suggestion): ?>
        <div style="background:var(--warn-tint);border:1px solid #f59e0b;border-radius:var(--radius);padding:0.5rem 0.75rem;font-size:0.78rem;margin-bottom:0.65rem;color:var(--warn-text)">
            Last assigned: <code><?= htmlspecialchars($sel_group['last_barcode']) ?></code>
            &nbsp;·&nbsp; Next: <strong><?= htmlspecialchars($next_bc_suggestion) ?></strong>
        </div>
        <?php endif; ?>
        <form method="POST" style="display:flex;gap:0.5rem;align-items:flex-end">
            <input type="hidden" name="action" value="gen_tickets">
            <input type="hidden" name="transporter_growersid" value="<?= $tgid_sel ?>">
            <div style="flex:1">
                <label style="font-size:0.65rem;font-weight:500;color:var(--muted);display:block;margin-bottom:3px">
                    <?= ($missing>0&&$next_bc_suggestion) ? 'Next barcode (auto-suggested)' : 'Start barcode' ?>
                </label>
                <input type="text" name="start_barcode" class="form-control"
                       style="font-family:monospace;font-size:0.95rem;letter-spacing:0.08em"
                       placeholder="000100001A"
                       value="<?= htmlspecialchars($next_bc_suggestion) ?>"
                       required maxlength="10" id="bc-input" oninput="previewCheck()">
                <div id="bc-preview" style="font-size:0.68rem;color:var(--muted);margin-top:3px;height:14px"></div>
            </div>
            <button type="submit" class="btn btn-primary" style="white-space:nowrap;padding:0.55rem 1rem">
                <?= $missing>0 ? '→ Resume' : '→ Generate' ?>
            </button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($tk_cnt > 0): ?>
    <div style="padding:0.4rem 1rem 0.65rem;border-top:1px solid var(--border)">
        <form method="POST" onsubmit="return confirm('Delete ALL <?= $tk_cnt ?> tickets for this group and start fresh?')">
            <input type="hidden" name="action" value="delete_tickets">
            <input type="hidden" name="tgid" value="<?= $tgid_sel ?>">
            <button class="btn btn-danger btn-sm" style="font-size:0.72rem">✕ Delete all &amp; restart</button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── SECTION 3: SPLITS ── -->
<?php if ($state !== 'none'): ?>
<div class="card" style="margin-bottom:0.65rem">
    <div class="card-head">
        <div>
            <div style="font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:var(--muted)">Sales Splits</div>
            <div style="font-size:0.72rem;color:var(--muted);margin-top:1px"><?= $junus_cnt ?> bales total — divide across grading splits</div>
        </div>
        <?php if ($state==='open'): ?><span class="sb sb-none">Optional</span>
        <?php elseif ($state==='split'): ?><span class="sb sb-split">Pending confirm</span>
        <?php else: ?><span class="sb sb-closed">✓ Confirmed</span><?php endif; ?>
    </div>

    <?php if ($state !== 'closed'): ?>
    <div style="padding:0.85rem 1rem">
    <form method="POST" id="split-form">
    <input type="hidden" name="action" value="save_splits">
    <input type="hidden" name="tgid" value="<?= $tgid_sel ?>">
    <?php
    $existing_splits = [];
    foreach ($sel_group['splits'] as $s) $existing_splits[$s['splitid']] = $s['bale_qty'];
    $show_splits = min(max(count($existing_splits) + 1, 2), 8);
    ?>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.5rem;margin-bottom:0.7rem">
    <?php for ($sid = 1; $sid <= $show_splits; $sid++): ?>
    <div>
        <label style="font-size:.78rem;font-weight:600;color:var(--muted);display:block;margin-bottom:3px">Split <?= $sid ?></label>
        <input type="number" name="splits[<?= $sid ?>]" class="form-control split-qty"
               style="text-align:center;font-size:0.92rem;font-weight:600;padding:0.45rem"
               min="0" value="<?= $existing_splits[$sid] ?? 0 ?>" oninput="updateSplitTotal()">
    </div>
    <?php endfor; ?>
    </div>

    <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.7rem;padding:0.4rem 0.75rem;background:var(--surface);border-radius:var(--radius);border:1px solid var(--border)">
        <span style="font-size:0.78rem;color:var(--muted)">Total:</span>
        <strong id="split-total">0</strong>
        <span style="font-size:0.78rem;color:var(--muted)">of <?= $junus_cnt ?></span>
        <span id="split-ok"   style="display:none;font-size:0.75rem;color:var(--leaf);font-weight:500">✓ Balanced</span>
        <span id="split-warn" style="display:none;font-size:0.75rem;color:var(--danger);font-weight:500">✗ Must equal <?= $junus_cnt ?></span>
    </div>

    <div class="flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm" id="save-splits-btn">Save Splits</button>
        <?php if (!empty($sel_group['splits'])): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Remove all splits?')">
            <input type="hidden" name="action" value="reverse_splits">
            <input type="hidden" name="tgid" value="<?= $tgid_sel ?>">
            <button class="btn btn-ghost btn-sm">↩ Clear</button>
        </form>
        <?php endif; ?>
    </div>
    </form>
    </div>

    <?php if ($state === 'split' && !empty($sel_group['splits'])): ?>
    <div style="padding:0.65rem 1rem;border-top:1px solid var(--border);background:var(--surface-2)">
        <form method="POST" onsubmit="return confirm('Confirm splits and close this group?')">
            <input type="hidden" name="action" value="confirm_splits">
            <input type="hidden" name="tgid" value="<?= $tgid_sel ?>">
            <button class="btn btn-gold" style="width:100%;padding:0.6rem">✓ Confirm Splits → Close Group</button>
        </form>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- CLOSED: show confirmed splits -->
    <div style="padding:0.75rem 1rem">
    <?php foreach ($sel_group['splits'] as $s): ?>
    <div style="display:flex;align-items:center;padding:5px 0;border-bottom:1px solid var(--border)">
        <span style="font-size:0.72rem;font-weight:500;color:var(--muted);width:55px">Split <?= $s['splitid'] ?></span>
        <strong style="font-size:0.85rem"><?= $s['bale_qty'] ?> bales</strong>
        <span class="sb sb-closed" style="margin-left:auto"><?= $s['status'] ?></span>
    </div>
    <?php endforeach; ?>
    <form method="POST" style="margin-top:0.75rem" onsubmit="return confirm('Reverse confirmation?')">
        <input type="hidden" name="action" value="reverse_confirm">
        <input type="hidden" name="tgid" value="<?= $tgid_sel ?>">
        <button class="btn btn-ghost" style="width:100%;font-size:0.78rem">↩ Reverse Confirmation</button>
    </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── SECTION 4: CLOSED state note ── -->
<?php if ($state === 'closed'): ?>
<div style="background:var(--ok-tint);border:1px solid #86efac;border-radius:var(--radius);padding:0.65rem 1rem;font-size:0.78rem;color:var(--ok-text)">
    ✓ Group is closed and locked. All splits confirmed. Use the reverse button above to re-open for editing.
</div>
<?php endif; ?>

<?php endif; ?>

<!-- ══ LOT SPLIT MODAL ══════════════════════════════════════════════════════ -->
<div id="sm-overlay" onclick="smOverlayClick(event)"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.52);
            z-index:9000;align-items:flex-start;justify-content:center;padding-top:4vh;
            overflow-y:auto">
  <div id="sm-card" onclick="event.stopPropagation()"
       style="background:var(--surface);border-radius:12px;width:min(700px,96vw);
              display:flex;flex-direction:column;
              box-shadow:0 25px 70px rgba(0,0,0,0.4);margin-bottom:2rem">

    <!-- Header -->
    <div style="background:var(--surface-2);color:var(--text);padding:1rem 1.25rem;
                border-radius:12px 12px 0 0;display:flex;align-items:flex-start;gap:0.75rem">
      <div style="flex:1">
        <div id="sm-grower-num"
             style="font-family:'DM Sans',sans-serif;font-size:1.15rem;
                    color:var(--gold);line-height:1;font-weight:600"></div>
        <div id="sm-grower-sub"
             style="font-size:0.72rem;color:rgba(245,240,232,0.6);margin-top:3px"></div>
      </div>
      <span id="sm-state-badge" class="sb" style="margin-top:2px"></span>
      <button onclick="closeSplitModal()"
              style="background:none;border:none;color:#fff;cursor:pointer;
                     font-size:1.6rem;line-height:1;padding:0 4px;opacity:0.55;
                     transition:opacity 0.15s;flex-shrink:0"
              onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.55'">×</button>
    </div>

    <!-- Body -->
    <div style="padding:1.1rem 1.25rem;overflow-y:auto;max-height:calc(100vh - var(--top-h) - 2rem)">

      <!-- Visual lot bar -->
      <div style="margin-bottom:1rem">
        <div style="font-size:.78rem;font-weight:600;color:var(--muted);margin-bottom:6px">Lot map</div>
        <div id="sm-lot-bar" style="display:flex;flex-wrap:wrap;gap:4px"></div>
      </div>

      <!-- Split range rows -->
      <div style="font-size:.78rem;font-weight:600;color:var(--muted);margin-bottom:6px">Splits</div>
      <div id="sm-split-rows" style="display:flex;flex-direction:column;gap:6px"></div>

      <!-- Add split + coverage -->
      <div style="display:flex;align-items:center;gap:0.75rem;margin-top:0.75rem;flex-wrap:wrap">
        <button id="sm-add-split-btn" onclick="smAddSplit()"
                style="background:none;border:1px dashed var(--border);border-radius:var(--radius);
                       padding:0.28rem 0.9rem;font-size:0.75rem;cursor:pointer;color:var(--muted)"
                onmouseover="this.style.borderColor='var(--gold)';this.style.color='var(--gold)'"
                onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">
          + Add Split
        </button>
        <div id="sm-coverage"
             style="flex:1;padding:0.45rem 0.8rem;border-radius:var(--radius);font-size:0.78rem;
                    font-weight:500;border:1px solid var(--border);background:var(--surface)"></div>
      </div>
    </div>

    <!-- Footer -->
    <div style="padding:0.85rem 1.25rem;border-top:1px solid var(--border);
                background:var(--surface-2);border-radius:0 0 12px 12px;
                display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap">
      <button id="sm-save-btn"    onclick="saveModalSplits()"    class="btn btn-primary">💾 Save Splits</button>
      <button id="sm-confirm-btn" onclick="confirmModalSplits()" class="btn btn-gold" style="display:none">✓ Confirm &amp; Close</button>
      <button id="sm-clear-btn"   onclick="clearModalSplits()"   class="btn btn-ghost btn-sm" style="display:none">↩ Clear</button>
      <button id="sm-reverse-btn" onclick="reverseModalConfirm()" class="btn btn-ghost btn-sm" style="display:none">↩ Reverse Confirm</button>
      <span style="flex:1"></span>
      <a id="sm-print-link" href="#" target="_blank"
         class="btn-print" style="padding:0.45rem 1rem;font-size:0.8rem">🖨 Print</a>
      <button onclick="closeSplitModal()" class="btn btn-ghost">Close</button>
    </div>
  </div>
</div>
</div>
</div>

<!-- Tab Panel 3: Today's Tickets -->
<div id="panel-today" class="ticket-tab-panel">
<div class="card mt-3" id="today-table">
<div class="card-head" style="padding:0.85rem 1rem">
    <div>
        <h3 style="margin:0">Today's Tickets</h3>
        <div style="font-size:0.72rem;color:var(--muted);margin-top:2px">Click a row to manage lot splits</div>
    </div>
    <span class="badge badge-gray"><?= count($today_groups) ?> grower<?= count($today_groups)!==1?'s':'' ?></span>
</div>

<?php if (empty($today_groups)): ?>
<div style="text-align:center;padding:2.5rem;color:var(--muted);font-size:0.85rem">No tickets issued today.</div>
<?php else: ?>
<div class="table-wrap">
<table>
<thead><tr>
    <th style="width:50px">Loc</th>
    <th style="width:78px">Grower #</th>
    <th>Name</th>
    <th class="text-center" style="width:72px">Tickets</th>
    <th class="text-right"  style="width:96px">Mass (kg)</th>
    <th style="width:100px">Splits</th>
    <th style="width:44px"></th>
</tr></thead>
<tbody>
<?php foreach ($today_groups as $tg):
    $tid    = (int)$tg['tgid'];
    $st     = $tg['state'];
    $splits = $tg['splits'];
?>
<tr class="today-row" data-tgid="<?= $tid ?>" onclick="openSplitModal(<?= $tid ?>)">
    <td style="text-align:center">
        <?php if ($tg['loc_code']): ?>
        <span style="font-family:'DM Sans',sans-serif;font-size:0.85rem;color:var(--gold);font-weight:600"><?= htmlspecialchars($tg['loc_code']) ?></span>
        <?php else: ?><span class="td-muted">—</span><?php endif; ?>
    </td>
    <td class="fw-500 text-gold" style="font-size:0.8rem"><?= htmlspecialchars($tg['grower_num']) ?></td>
    <td style="font-size:0.82rem"><?= htmlspecialchars($tg['gname'].' '.$tg['surname']) ?></td>
    <td class="text-center td-mono" style="font-weight:600;color:var(--leaf)"><?= $tg['ticket_count'] ?></td>
    <td class="text-right td-mono"><?= number_format((float)$tg['total_mass']) ?></td>
    <td>
        <span class="sb sb-<?= $st ?>"><?= $st ?></span>
        <?php if (!empty($splits)): ?>
        <span style="font-size:.72rem;color:var(--muted);margin-left:4px"><?= count($splits) ?> split<?= count($splits)!==1?'s':'' ?></span>
        <?php endif; ?>
    </td>
    <td style="text-align:center" onclick="event.stopPropagation()">
        <a href="ticket_pdf.php?tgid=<?= $tid ?>" target="_blank"
           class="btn-print" style="padding:3px 10px;font-size:0.72rem" title="Print">🖨</a>
    </td>
</tr>

<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div><!-- /today-table card -->
</div>

<script>
// ── Lot split modal — data ─────────────────────────────────────────────────
const LOT_DATA    = <?= json_encode($lot_data_js)   ?>;
const SPLIT_DATA  = <?= json_encode($split_data_js) ?>;
const TODAY_META  = <?= json_encode($today_meta_js) ?>;
const SPLIT_COLORS= ['#3b82f6','#8b5cf6','#f59e0b','#ef4444','#10b981','#ec4899','#14b8a6'];

// ── Modal state ────────────────────────────────────────────────────────────
let SM = { tgid: null, ranges: [], state: 'open' };

// ── Helpers ─────────────────────────────────────────────────────────────────
function smLotRange() {
    const lots = LOT_DATA[SM.tgid] || [];
    if (!lots.length) return { min: 1, max: 1 };
    const nums = lots.map(l => parseFloat(l.lot)).filter(n => !isNaN(n));
    return { min: Math.min(...nums), max: Math.max(...nums) };
}

function smBalesInRange(from, to) {
    from = parseFloat(from); to = parseFloat(to);
    if (isNaN(from) || isNaN(to)) return 0;
    return (LOT_DATA[SM.tgid] || []).reduce((s, l) => {
        const n = parseFloat(l.lot);
        return s + (n >= from && n <= to ? l.bales : 0);
    }, 0);
}

// ── Open / close ───────────────────────────────────────────────────────────
function openSplitModal(tgid) {
    const meta   = TODAY_META[tgid]  || {};
    const lots   = LOT_DATA[tgid]    || [];
    const splits = SPLIT_DATA[tgid]  || [];

    SM.tgid  = tgid;
    SM.state = meta.state || 'open';

    // Pre-fill ranges from saved splits, or default to full range
    if (splits.length > 0 && splits[0].lot_from > 0) {
        SM.ranges = splits.map(s => ({ from: s.lot_from, to: s.lot_to }));
    } else {
        const r = smLotRange();
        SM.ranges = [{ from: r.min, to: r.max }];
    }

    // Header info
    const totalBales = lots.reduce((s, l) => s + l.bales, 0);
    document.getElementById('sm-grower-num').textContent = meta.grower_num || '';
    document.getElementById('sm-grower-sub').textContent =
        [meta.gname, meta.surname].filter(Boolean).join(' ')
        + (meta.loc_code ? ' · ' + meta.loc_code : '')
        + ' · ' + meta.ticket_count + ' ticket' + (meta.ticket_count !== 1 ? 's' : '')
        + ' · ' + totalBales + ' bale' + (totalBales !== 1 ? 's' : '');

    const badge = document.getElementById('sm-state-badge');
    badge.className   = 'sb sb-' + SM.state;
    badge.textContent = SM.state;

    document.getElementById('sm-print-link').href = 'ticket_pdf.php?tgid=' + tgid;

    smRender();
    document.getElementById('sm-overlay').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeSplitModal() {
    document.getElementById('sm-overlay').style.display = 'none';
    document.body.style.overflow = '';
}

function smOverlayClick(e) {
    if (e.target === document.getElementById('sm-overlay')) closeSplitModal();
}

// ── Render ─────────────────────────────────────────────────────────────────
function smRender() {
    smRenderLotBar();
    smRenderRows();
    smRenderCoverage();
    smRenderButtons();
}

function smRenderLotBar() {
    const el = document.getElementById('sm-lot-bar');
    if (!el) return;
    el.innerHTML = '';
    const lots = LOT_DATA[SM.tgid] || [];

    lots.forEach(l => {
        const n = parseFloat(l.lot);
        let color = '#e2e8f0', textColor = '#94a3b8';
        for (let i = 0; i < SM.ranges.length; i++) {
            if (n >= SM.ranges[i].from && n <= SM.ranges[i].to) {
                color     = SPLIT_COLORS[i % SPLIT_COLORS.length];
                textColor = '#fff';
                break;
            }
        }
        const chip = document.createElement('div');
        chip.style.cssText =
            `min-width:36px;height:36px;padding:0 5px;border-radius:7px;
             background:${color};color:${textColor};
             display:inline-flex;flex-direction:column;align-items:center;
             justify-content:center;font-size:0.72rem;font-weight:700;
             transition:background 0.15s,color 0.15s;user-select:none`;
        chip.innerHTML = `<span>${l.lot}</span>`;
        if (l.bales > 1)
            chip.innerHTML += `<span style="font-size:.72rem;opacity:0.85">${l.bales}×</span>`;
        el.appendChild(chip);
    });
}

function smRenderRows() {
    const el = document.getElementById('sm-split-rows');
    if (!el) return;
    el.innerHTML = '';
    const closed = SM.state === 'closed';
    const r = smLotRange();

    SM.ranges.forEach((rng, i) => {
        const color  = SPLIT_COLORS[i % SPLIT_COLORS.length];
        const bales  = smBalesInRange(rng.from, rng.to);
        const bLabel = bales > 0 ? `${bales} bale${bales !== 1 ? 's' : ''}` : '—';

        const row = document.createElement('div');
        row.style.cssText =
            `display:flex;align-items:center;gap:10px;padding:9px 14px;
             border-radius:9px;background:${color}0f;border:1.5px solid ${color}44`;

        // Colour dot + label
        row.innerHTML =
            `<span style="width:10px;height:10px;border-radius:50%;
                          background:${color};flex-shrink:0"></span>
             <span style="font-size:0.78rem;font-weight:700;color:${color};
                          min-width:55px;white-space:nowrap">Split ${i + 1}</span>
             <span style="font-size:0.72rem;color:var(--muted);flex-shrink:0">Lots</span>`;

        if (closed) {
            const info = document.createElement('span');
            info.style.cssText = 'font-size:0.85rem;font-weight:600;color:var(--ink);flex:1';
            info.textContent = `${rng.from} – ${rng.to}`;
            row.appendChild(info);
        } else {
            // From input
            const fromIn = document.createElement('input');
            fromIn.type  = 'number';
            fromIn.value = rng.from;
            fromIn.min   = r.min; fromIn.max = r.max;
            fromIn.style.cssText =
                `width:62px;padding:5px 7px;border:1.5px solid ${color}66;border-radius:6px;
                 font-size:0.85rem;font-weight:700;text-align:center;
                 color:var(--ink);background:#fff;outline:none`;
            fromIn.oninput = () => smRangeChange(i, 'from', fromIn.value);

            const arrow = document.createElement('span');
            arrow.textContent = '→';
            arrow.style.cssText = 'font-size:0.9rem;color:var(--muted);flex-shrink:0';

            // To input
            const toIn = document.createElement('input');
            toIn.type  = 'number';
            toIn.value = rng.to;
            toIn.min   = r.min; toIn.max = r.max;
            toIn.style.cssText =
                `width:62px;padding:5px 7px;border:1.5px solid ${color}66;border-radius:6px;
                 font-size:0.85rem;font-weight:700;text-align:center;
                 color:var(--ink);background:#fff;outline:none`;
            toIn.oninput = () => smRangeChange(i, 'to', toIn.value);

            row.appendChild(fromIn);
            row.appendChild(arrow);
            row.appendChild(toIn);
        }

        // Bale count pill
        const ct = document.createElement('span');
        ct.style.cssText =
            `margin-left:auto;font-size:0.75rem;font-weight:700;
             background:${color};color:#fff;padding:3px 11px;
             border-radius:999px;white-space:nowrap;flex-shrink:0`;
        ct.textContent = bLabel;
        row.appendChild(ct);

        // Remove button (not when only 1 split, not when closed)
        if (!closed && SM.ranges.length > 1) {
            const del = document.createElement('button');
            del.textContent = '×';
            del.title = 'Remove this split';
            del.style.cssText =
                `background:none;border:none;cursor:pointer;color:#9ca3af;
                 font-size:1.2rem;line-height:1;padding:0 4px;flex-shrink:0`;
            del.onclick = () => smRemoveSplit(i);
            row.appendChild(del);
        }

        el.appendChild(row);
    });
}

function smRangeChange(idx, field, val) {
    const n = parseFloat(val);
    if (!isNaN(n)) {
        SM.ranges[idx][field] = n;
        // Auto-advance: changing "to" of split N nudges "from" of split N+1
        if (field === 'to' && idx + 1 < SM.ranges.length)
            SM.ranges[idx + 1].from = n + 1;
    }
    smRender();
}

function smAddSplit() {
    // Split the last range in half so both halves are valid
    const last = SM.ranges[SM.ranges.length - 1];
    if (!last) { smRender(); return; }
    const mid = Math.floor((last.from + last.to) / 2);
    if (mid >= last.to) {
        alert('Not enough lots to create another split here. Adjust the existing ranges first.');
        return;
    }
    // Shrink last range to [from .. mid], new range gets [mid+1 .. old_to]
    const oldTo = last.to;
    last.to = mid;
    SM.ranges.push({ from: mid + 1, to: oldTo });
    smRender();
}

function smRemoveSplit(idx) {
    SM.ranges.splice(idx, 1);
    smRender();
}

function smRenderCoverage() {
    const el   = document.getElementById('sm-coverage');
    if (!el) return;
    const lots  = LOT_DATA[SM.tgid] || [];
    const total = lots.reduce((s, l) => s + l.bales, 0);
    let covered = 0;
    lots.forEach(l => {
        const n = parseFloat(l.lot);
        for (let i = 0; i < SM.ranges.length; i++) {
            if (n >= SM.ranges[i].from && n <= SM.ranges[i].to) {
                covered += l.bales; break;
            }
        }
    });
    const ok = covered === total && total > 0;
    el.textContent    = ok
        ? `✓ All ${total} bales assigned across ${SM.ranges.length} split${SM.ranges.length !== 1 ? 's' : ''}`
        : `${covered} / ${total} bales covered — adjust ranges so every lot is included`;
    el.style.color       = ok ? '#15803d' : '#92400e';
    el.style.background  = ok ? '#f0fdf4' : '#fffbeb';
    el.style.borderColor = ok ? '#86efac' : '#fde68a';
    // Never disable — keep button always clickable; saveModalSplits() validates on click
}

function smRenderButtons() {
    const st       = SM.state;
    const hasSaved = (SPLIT_DATA[SM.tgid] || []).length > 0;
    const closed   = st === 'closed';
    const show = (id, vis) => { const el = document.getElementById(id); if (el) el.style.display = vis ? '' : 'none'; };
    show('sm-add-split-btn', !closed);
    show('sm-save-btn',      !closed);
    show('sm-confirm-btn',   st === 'split' && hasSaved);
    show('sm-clear-btn',     !closed && hasSaved);
    show('sm-reverse-btn',   closed);
}

// ── Form submissions ───────────────────────────────────────────────────────
function saveModalSplits() {
    const lots  = LOT_DATA[SM.tgid] || [];
    const total = lots.reduce((s, l) => s + l.bales, 0);

    // Convert ranges → individual lot→splitid assignments
    const assignments = {};
    lots.forEach(l => {
        const n = parseFloat(l.lot);
        for (let i = 0; i < SM.ranges.length; i++) {
            if (n >= SM.ranges[i].from && n <= SM.ranges[i].to) {
                assignments[l.lot] = i + 1; break;
            }
        }
    });

    const covered = lots.reduce((s, l) => s + (assignments[l.lot] ? l.bales : 0), 0);
    if (covered !== total) {
        alert('Not all bales are covered. Adjust the lot ranges so every lot is included.');
        return;
    }

    const f = document.createElement('form'); f.method = 'POST'; f.action = '';
    const h = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); };
    h('action', 'save_lot_splits'); h('tgid', SM.tgid);
    Object.entries(assignments).forEach(([lot, sid]) => h(`lot_split[${lot}]`, sid));
    document.body.appendChild(f); f.submit();
}

function confirmModalSplits() {
    if (!confirm('Confirm splits and close this group? This locks the split assignments.')) return;
    const f = document.createElement('form'); f.method = 'POST'; f.action = '';
    const h = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); };
    h('action', 'confirm_splits'); h('tgid', SM.tgid);
    document.body.appendChild(f); f.submit();
}

function clearModalSplits() {
    if (!confirm('Remove all split assignments for this group?')) return;
    const f = document.createElement('form'); f.method = 'POST'; f.action = '';
    const h = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); };
    h('action', 'reverse_splits'); h('tgid', SM.tgid);
    document.body.appendChild(f); f.submit();
}

function reverseModalConfirm() {
    if (!confirm('Reverse confirmation and re-open this group for editing?')) return;
    const f = document.createElement('form'); f.method = 'POST'; f.action = '';
    const h = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); };
    h('action', 'reverse_confirm'); h('tgid', SM.tgid);
    document.body.appendChild(f); f.submit();
}

// ── (old toggleTodayPanel stub — kept so stale links don't throw errors) ──
function toggleTodayPanel(tgid) { openSplitModal(tgid); }

// ── Check letter preview ───────────────────────────────────────────────
function checkChar(num) {
    const n = String(num).padStart(10,'0');
    let s=0; for(let i=0;i<n.length;i++) s+=parseInt(n[i])||0;
    return '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%'[s%43]||'?';
}
function previewCheck() {
    const inp = document.getElementById('bc-input');
    const prv = document.getElementById('bc-preview');
    if (!inp || !prv) return;
    const v = inp.value.trim();
    if (v.length >= 9) {
        const num = parseInt(v.substring(0,9));
        if (!isNaN(num)) {
            const expected = checkChar(num);
            const actual   = v.length === 10 ? v[9].toUpperCase() : '?';
            prv.textContent = 'Check character: ' + expected + (v.length===10 ? (actual===expected ? ' ✓' : ' ✗ got '+actual) : '');
            prv.style.color = v.length===10 && actual!==expected ? 'var(--danger)' : 'var(--muted)';
        }
    } else { prv.textContent = ''; }
}

// ── Split total validator ──────────────────────────────────────────────
function updateSplitTotal() {
    let total = 0;
    document.querySelectorAll('.split-qty').forEach(el => total += parseInt(el.value)||0);
    const max = <?= $sel_group ? (int)$sel_group['junus_count'] : 0 ?>;
    const el  = document.getElementById('split-total');
    if (el) el.textContent = total;
    const ok   = document.getElementById('split-ok');
    const warn = document.getElementById('split-warn');
    const btn  = document.getElementById('save-splits-btn');
    if (ok)   ok.style.display   = total===max ? 'inline' : 'none';
    if (warn) warn.style.display = total!==max ? 'inline' : 'none';
    if (btn)  btn.disabled       = total!==max;
}
document.addEventListener('DOMContentLoaded', () => { updateSplitTotal(); previewCheck(); });

// ── Table filter + search ──────────────────────────────────────────────
let activeFilter = 'all';

function setFilter(el, f) {
    activeFilter = f;
    document.querySelectorAll('.ftab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    filterTable();
}

function filterTable() {
    const q    = (document.getElementById('grp-search')?.value || '').toLowerCase();
    const rows = document.querySelectorAll('#groups-table tbody tr');
    let vis = 0;
    rows.forEach(row => {
        const state   = row.dataset.state;
        const ready   = row.dataset.ready === '1';
        const srchTxt = row.dataset.search || '';

        let show = true;
        if (activeFilter === 'ready')  show = ready;
        else if (activeFilter === 'none')   show = state === 'none';
        else if (activeFilter === 'open')   show = state === 'open';
        else if (activeFilter === 'split')  show = state === 'split';
        else if (activeFilter === 'closed') show = state === 'closed';

        if (show && q) show = srchTxt.includes(q);

        row.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    const vc = document.getElementById('visible-count');
    if (vc) vc.textContent = vis;
    const nr = document.getElementById('no-rows');
    if (nr) nr.style.display = vis === 0 ? 'block' : 'none';
}

// ── Jam recovery ───────────────────────────────────────────────────────────
function pickLastGood(btn) {
    document.querySelectorAll('.bc-jam-chip').forEach(b => {
        b.style.background  = '#fff';
        b.style.borderColor = '#e5e7eb';
        b.style.color       = '#374151';
    });
    btn.style.background  = '#fef3c7';
    btn.style.borderColor = '#f59e0b';
    btn.style.color       = '#92400e';
    const inp = document.getElementById('jam-last-bc');
    if (inp) { inp.value = btn.dataset.bc; updateJamSummary(); }
}

function jamNewBcPreview() {
    const inp = document.getElementById('jam-new-bc');
    const prv = document.getElementById('jam-new-bc-preview');
    if (!inp || !prv) return;
    const v = inp.value.trim();
    if (v.length >= 9) {
        const num = parseInt(v.substring(0,9));
        if (!isNaN(num)) {
            const expected = checkChar(num);
            const actual   = v.length === 10 ? v[9].toUpperCase() : '?';
            prv.textContent = 'Check character: ' + expected
                + (v.length===10 ? (actual===expected ? ' ✓' : ' ✗ got '+actual) : '');
            prv.style.color = v.length===10 && actual!==expected ? 'var(--danger)' : 'var(--muted)';
        }
    } else { prv.textContent = ''; }
}

function updateJamSummary() {
    const lastBc = (document.getElementById('jam-last-bc')?.value  || '').trim().toUpperCase();
    const newBc  = (document.getElementById('jam-new-bc')?.value   || '').trim().toUpperCase();
    const el     = document.getElementById('jam-summary');
    if (!el) return;

    const total = document.querySelectorAll('.bc-jam-chip').length;
    if (!total) { el.textContent = ''; return; }

    let affectedCount = total;
    if (lastBc) {
        const chip = document.querySelector(`.bc-jam-chip[data-bc="${lastBc}"]`);
        if (chip) {
            affectedCount = total - parseInt(chip.dataset.seq);
        }
    }

    if (affectedCount <= 0) {
        el.textContent = '⚠ That was the last ticket — nothing left to re-issue.';
        el.style.color = '#dc2626';
        return;
    }

    const newStr = newBc.length >= 9 ? ` starting at ${newBc}` : '';
    el.textContent = `→ ${affectedCount} ticket${affectedCount!==1?'s':''} will be re-issued${newStr}`;
    el.style.color  = '#15803d';
}

function confirmJam() {
    const last = (document.getElementById('jam-last-bc')?.value || '').trim();
    const nw   = (document.getElementById('jam-new-bc')?.value  || '').trim();
    const el   = document.getElementById('jam-summary');
    const info = el ? el.textContent : '';
    const msg  = last
        ? `Last printed: ${last}\nNew start: ${nw}\n\n${info}\n\nProceed? This will overwrite the un-printed ticket barcodes.`
        : `No last printed barcode set — ALL tickets in this group will be re-issued.\nNew start: ${nw}\n\nProceed?`;
    return confirm(msg);
}

function switchTicketTab(tabId) {
    document.querySelectorAll('.premium-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.ticket-tab-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetBtn = document.querySelector(`[data-tab="${tabId}"]`);
    const targetPanel = document.getElementById(`panel-${tabId}`);
    
    if (targetBtn && targetPanel) {
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
        sessionStorage.setItem('active_ticket_tab', tabId);
    }
}

// Restore active tab
document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const hasTgid = params.has('tgid');
    
    const activeTab = hasTgid ? 'details' : (sessionStorage.getItem('active_ticket_tab') || 'groups');
    switchTicketTab(activeTab);
});
</script>

<?php include "includes/layout_end.php"; ?>
