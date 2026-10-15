<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$active_page = "bales";
$page_title  = "Bales";
$userid      = current_userid();
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$seasonid    = current_season_id();

$buyer_names_list = [];
$res_bn = $conn->query("SELECT DISTINCT name FROM buyer WHERE name != '' AND (selling_pointid=$spid OR $spid=0) ORDER BY name");
if ($res_bn && $res_bn !== true) {
    while ($row = $res_bn->fetch_assoc()) {
        $buyer_names_list[] = $row['name'];
    }
}

$buyers_list = [];
$res_b = $conn->query("SELECT DISTINCT name, buyer_code FROM buyer WHERE buyer_code != '' AND (selling_pointid=$spid OR $spid=0) ORDER BY buyer_code");
if ($res_b && $res_b !== true) {
    while ($row = $res_b->fetch_assoc()) {
        $buyers_list[] = $row;
    }
}

$locations_list = [];
$res_l = $conn->query("SELECT DISTINCT code, name FROM bale_locations WHERE active=1 AND (selling_pointid=$spid OR $spid=0) ORDER BY code");
if ($res_l && $res_l !== true) {
    while ($row = $res_l->fetch_assoc()) {
        $locations_list[$row['code']] = $row;
    }
}
$res_tl = $conn->query("SELECT DISTINCT location FROM transporter WHERE location != '' AND active=1");
if ($res_tl && $res_tl !== true) {
    while ($row = $res_tl->fetch_assoc()) {
        $loc_code = $row['location'];
        if (!isset($locations_list[$loc_code])) {
            $locations_list[$loc_code] = ['code' => $loc_code, 'name' => ''];
        }
    }
}
ksort($locations_list);

// ── Shared base SELECT ────────────────────────────────────────────────────────
function bales_base_sql(): string {
    return "
        SELECT
            bj.id                                   bjid,
            bm.id                                   bmid,
            COALESCE(tk.id,0)                       ticket_id,
            COALESCE(sb.id,0)                       sold_id,
            COALESCE(bb.id,0)                       balanced_id,
            bm.temp_bacode,
            COALESCE(tk.barcode,'')                 barcode,
            bj.lot,
            bj.bale_group,
            bm.mass,
            bj.splitid,
            bj.datetime                             created_at,
            g.id                                    growerid,
            g.grower_num,
            g.name                                  grower_name,
            g.surname                               grower_surname,
            tg.id                                   tgid,
            tg.sell_date,
            COALESCE(NULLIF(bl.code, ''), NULLIF(t.location, ''), '—') location,
            CONCAT('GDN',LPAD(tg.id,7,'0'))         dnote,
            COALESCE(sb.sale_code,'')               sale_code,
            COALESCE(sb.price,'0')                  price,
            COALESCE(tgd.description,'')            timb_grade,
            COALESCE(bgd.description,'')            buyer_grade,
            COALESCE(b.name,'')                     buyer_name,
            COALESCE(s.description,'')              season_name,
            bm.selling_pointid,
            bb.stoporder_processed
        FROM bale_junus bj
        JOIN bale_mass bm          ON bm.id  = bj.bale_massid
        JOIN transporter_growers tg ON tg.id = bj.transporter_growersid
        JOIN growers g             ON g.id   = bj.growerid
        JOIN transporter t         ON t.id   = tg.transporterid
        LEFT JOIN bale_locations bl ON bl.id = COALESCE(tg.locationid, t.location_id)
        LEFT JOIN tickets tk       ON tk.bale_junusid  = bj.id
        LEFT JOIN sold_bales sb    ON sb.ticketsid     = tk.id
        LEFT JOIN timb_grades tgd  ON tgd.id           = sb.timb_gradesid
        LEFT JOIN buyer_grades bgd ON bgd.id           = sb.buyer_gradesid
        LEFT JOIN buyer b          ON b.id             = bgd.buyerid
        LEFT JOIN balanced_bales bb ON bb.transporter_growersid = tg.id
                                    AND bb.splitid = bj.splitid
        LEFT JOIN seasons s        ON s.id = bm.seasonid
    ";
}

// ── Build WHERE from GET/POST filters ─────────────────────────────────────────
function bales_where(mysqli $conn, int $spid): array {
    $parts = [];
    // Strict per-floor isolation: a logged-in floor session sees only its own bales.
    if ($spid) $parts[] = "bm.selling_pointid=$spid";

    $date_from  = $conn->real_escape_string(trim($_GET['date_from'] ?? ''));
    $date_to    = $conn->real_escape_string(trim($_GET['date_to']   ?? ''));
    $sell_date  = $conn->real_escape_string(trim($_GET['sell_date'] ?? ''));
    $grower     = $conn->real_escape_string(trim($_GET['grower']    ?? ''));
    $status     = trim($_GET['status'] ?? '');  // sold|unsold|rejected|balanced|ticketed|pending
    $lot        = (int)($_GET['lot'] ?? 0);
    $barcode    = $conn->real_escape_string(trim($_GET['barcode'] ?? ''));
    $buyer_name = $conn->real_escape_string(trim($_GET['buyer_name'] ?? ''));
    $buyer_mark = $conn->real_escape_string(trim($_GET['buyer_mark'] ?? ''));
    $location   = $conn->real_escape_string(trim($_GET['location'] ?? ''));

    if ($date_from)  $parts[] = "DATE(bj.datetime) >= '$date_from'";
    if ($date_to)    $parts[] = "DATE(bj.datetime) <= '$date_to'";
    if ($sell_date)  $parts[] = "tg.sell_date='$sell_date'";
    if ($lot)        $parts[] = "bj.lot=$lot";
    if ($grower)     $parts[] = "(g.grower_num LIKE '%$grower%' OR g.name LIKE '%$grower%'
                                  OR g.surname LIKE '%$grower%' OR tk.barcode LIKE '%$grower%'
                                  OR bm.temp_bacode LIKE '%$grower%')";
    if ($barcode)    $parts[] = "(tk.barcode LIKE '%$barcode%' OR bm.temp_bacode LIKE '%$barcode%')";
    if ($buyer_name) $parts[] = "b.name='$buyer_name'";
    if ($buyer_mark) $parts[] = "b.buyer_code='$buyer_mark'";
    if ($location)   $parts[] = "(bl.code='$location' OR t.location='$location')";

    // Status filter (applied via SQL)
    switch ($status) {
        case 'sold':      $parts[] = "sb.id IS NOT NULL AND (sb.sale_code IS NULL OR sb.sale_code NOT REGEXP '^[Rr]')"; break;
        case 'unsold':    $parts[] = "tk.id IS NOT NULL AND sb.id IS NULL"; break;
        case 'rejected':  $parts[] = "sb.id IS NOT NULL AND sb.sale_code REGEXP '^[Rr]'"; break;
        case 'balanced':  $parts[] = "bb.id IS NOT NULL"; break;
        case 'ticketed':  $parts[] = "tk.id IS NOT NULL"; break;
        case 'pending':   $parts[] = "tk.id IS NULL"; break;
    }

    return [
        'sql'        => $parts ? 'WHERE ' . implode(' AND ', $parts) : '',
        'date_from'  => $date_from,
        'date_to'    => $date_to,
        'sell_date'  => $sell_date,
        'grower'     => $grower,
        'status'     => $status,
        'lot'        => $lot,
        'barcode'    => $barcode,
        'buyer_name' => $buyer_name,
        'buyer_mark' => $buyer_mark,
        'location'   => $location,
    ];
}

// ── CSV EXPORT (non-AJAX GET) ─────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $f = bales_where($conn, $spid);
    $sql = bales_base_sql() . $f['sql'] . " ORDER BY bj.id DESC LIMIT 50000";
    $res = $conn->query($sql);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bales_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Bale State','Scale Barcode','Ticket Barcode','Lot','Group','Mass(kg)',
                   'Grower #','Grower Name','D-Note Ref','Sale Date','Location','Split',
                   'TIMB Grade','Buyer Grade','Buyer','Sale Code','Price($/kg)',
                   'Season','Balanced','Created At']);
    if ($res && $res !== true) {
        while ($r = $res->fetch_assoc()) {
            $sc  = strtoupper(trim($r['sale_code']));
            $rej = ($sc && ($sc === 'R' || $sc === 'CO' || $sc === 'RO' || ($sc[0]==='R' && strlen($sc)<=3)));
            $state = $r['balanced_id'] ? 'Balanced'
                   : ($rej ? 'Rejected'
                   : ($r['sold_id'] ? 'Sold'
                   : ($r['ticket_id'] ? 'Ticketed'
                   : 'Pending')));
            $pr = (float)$r['price']; if ($pr > 10) $pr /= 100;
            fputcsv($out, [
                $state, $r['temp_bacode'], $r['barcode'], $r['lot'], $r['bale_group'],
                number_format((float)$r['mass'],2),
                $r['grower_num'], $r['grower_name'].' '.$r['grower_surname'],
                $r['dnote'], $r['sell_date'], $r['location'], $r['splitid'],
                $r['timb_grade'], $r['buyer_grade'], $r['buyer_name'],
                $r['sale_code'], $pr > 0 ? number_format($pr,4) : '',
                $r['season_name'], $r['balanced_id'] ? 'Yes' : 'No', $r['created_at'],
            ]);
        }
    }
    fclose($out);
    exit;
}

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $ajax = $_GET['ajax'] ?? '';

    // ── LIST ────────────────────────────────────────────────────────────────
    if ($ajax === 'list') {
        $per_page = 100;
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $offset   = ($page - 1) * $per_page;

        $f   = bales_where($conn, $spid);
        $cnt = $conn->query("SELECT COUNT(*) c FROM bale_junus bj
            JOIN bale_mass bm ON bm.id=bj.bale_massid
            JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
            JOIN growers g ON g.id=bj.growerid
            JOIN transporter t ON t.id=tg.transporterid
            LEFT JOIN bale_locations bl ON bl.id = COALESCE(tg.locationid, t.location_id)
            LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
            LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
            LEFT JOIN buyer_grades bgd ON bgd.id=sb.buyer_gradesid
            LEFT JOIN buyer b ON b.id=bgd.buyerid
            LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id AND bb.splitid=bj.splitid
            " . $f['sql']);
        $total = ($cnt && $cnt !== true) ? (int)$cnt->fetch_assoc()['c'] : 0;

        $sql  = bales_base_sql() . $f['sql']
              . " ORDER BY bj.id DESC LIMIT $per_page OFFSET $offset";
        $res  = $conn->query($sql);
        $rows = ($res && $res !== true) ? $res->fetch_all(MYSQLI_ASSOC) : [];

        echo json_encode([
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => max(1, (int)ceil($total / $per_page)),
        ]);
        exit;
    }

    // ── EDIT DETAILS (barcode, lot, bale_group) ──────────────────────────────
    if ($ajax === 'edit_details' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b       = json_decode(file_get_contents('php://input'), true) ?? [];
        $bjid    = (int)($b['bjid'] ?? 0);
        $barcode = $conn->real_escape_string(trim($b['barcode'] ?? ''));
        $lot     = (int)($b['lot'] ?? 0);
        $grp     = (int)($b['bale_group'] ?? 0);

        if (!$bjid) { echo json_encode(['error'=>'bjid required']); exit; }

        // Security: verify this bale belongs to this floor
        $chk = $conn->query("SELECT bj.id, COALESCE(bb.id,0) bal FROM bale_junus bj
            JOIN bale_mass bm ON bm.id=bj.bale_massid
            LEFT JOIN balanced_bales bb ON bb.transporter_growersid=bj.transporter_growersid AND bb.splitid=bj.splitid
            WHERE bj.id=$bjid AND (bm.selling_pointid=$spid OR $spid=0) LIMIT 1");
        $row = ($chk && $chk !== true) ? $chk->fetch_assoc() : null;
        if (!$row) { echo json_encode(['error'=>'Bale not found']); exit; }
        if ((int)$row['bal']) { echo json_encode(['error'=>'Bale is balanced — reverse first']); exit; }

        // Update lot/group on bale_junus
        $conn->query("UPDATE bale_junus SET lot=$lot, bale_group=$grp WHERE id=$bjid");

        // Update barcode on tickets (if exists)
        if ($barcode) {
            $conn->query("UPDATE tickets SET barcode='$barcode' WHERE bale_junusid=$bjid");
        }

        audit_log($conn, $spid, $userid, 'bale_junus', 'edit_details', $bjid, null,
            ['lot'=>$lot,'bale_group'=>$grp,'barcode'=>$barcode]);

        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── EDIT MASS ────────────────────────────────────────────────────────────
    if ($ajax === 'edit_mass' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b    = json_decode(file_get_contents('php://input'), true) ?? [];
        $bjid = (int)($b['bjid'] ?? 0);
        $mass = (float)($b['mass'] ?? 0);

        if (!$bjid)    { echo json_encode(['error'=>'bjid required']); exit; }
        if ($mass <= 0) { echo json_encode(['error'=>'Invalid mass']); exit; }
        if ($mass > 300){ echo json_encode(['error'=>'Mass seems too high (>300 kg)']); exit; }

        // Get bale_massid and verify ownership
        $chk = $conn->query("SELECT bj.bale_massid, bm.mass old_mass,
            (bm.selling_pointid=$spid OR $spid=0) allowed
            FROM bale_junus bj JOIN bale_mass bm ON bm.id=bj.bale_massid
            WHERE bj.id=$bjid LIMIT 1");
        $row = ($chk && $chk !== true) ? $chk->fetch_assoc() : null;
        if (!$row || !$row['allowed']) { echo json_encode(['error'=>'Bale not found']); exit; }

        $bmid = (int)$row['bale_massid'];
        $conn->query("UPDATE bale_mass SET mass=$mass WHERE id=$bmid");

        audit_log($conn, $spid, $userid, 'bale_mass', 'edit_mass', $bmid, null,
            ['old'=>$row['old_mass'],'new'=>$mass,'bjid'=>$bjid]);

        echo json_encode(['ok'=>true,'new_mass'=>$mass]);
        exit;
    }

    // ── SEARCH DELIVERIES (for change grower modal) ───────────────────────────
    if ($ajax === 'search_deliveries') {
        $q = $conn->real_escape_string(trim($_GET['q'] ?? ''));
        if (strlen($q) < 2) { echo json_encode([]); exit; }
        $r = $conn->query("
            SELECT tg.id tgid, g.grower_num, g.name, g.surname, g.id growerid,
                   tg.sell_date, tg.bales expected,
                   CONCAT('GDN',LPAD(tg.id,7,'0')) dnote,
                   COALESCE(t.location,'—') location
            FROM transporter_growers tg
            JOIN growers g ON g.id=tg.growerid
            JOIN transporter t ON t.id=tg.transporterid
            WHERE (g.selling_pointid=$spid OR $spid=0)
              AND (g.grower_num LIKE '%$q%' OR g.name LIKE '%$q%' OR g.surname LIKE '%$q%'
                   OR tg.id LIKE '%$q%')
            ORDER BY tg.sell_date DESC, g.grower_num ASC
            LIMIT 30
        ");
        echo json_encode(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
        exit;
    }

    // ── CHANGE GROWER / D-NOTE ─────────────────────────────────────────────
    if ($ajax === 'change_grower' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b        = json_decode(file_get_contents('php://input'), true) ?? [];
        $bjid     = (int)($b['bjid'] ?? 0);
        $new_tgid = (int)($b['new_tgid'] ?? 0);
        if (!$bjid || !$new_tgid) { echo json_encode(['error'=>'bjid and new_tgid required']); exit; }

        // Verify old bale
        $old = $conn->query("SELECT bj.transporter_growersid old_tgid, bj.growerid old_growerid,
            COALESCE(bb.id,0) bal, bm.selling_pointid spid
            FROM bale_junus bj JOIN bale_mass bm ON bm.id=bj.bale_massid
            LEFT JOIN balanced_bales bb ON bb.transporter_growersid=bj.transporter_growersid AND bb.splitid=bj.splitid
            WHERE bj.id=$bjid LIMIT 1");
        $old_r = ($old && $old !== true) ? $old->fetch_assoc() : null;
        if (!$old_r || ($spid && (int)$old_r['spid'] !== $spid))
            { echo json_encode(['error'=>'Bale not found']); exit; }
        if ((int)$old_r['bal'])
            { echo json_encode(['error'=>'Bale is balanced — reverse processing first']); exit; }

        // Verify new tgid
        $new = $conn->query("SELECT tg.id, tg.growerid new_growerid
            FROM transporter_growers tg JOIN growers g ON g.id=tg.growerid
            WHERE tg.id=$new_tgid AND (g.selling_pointid=$spid OR $spid=0) LIMIT 1");
        $new_r = ($new && $new !== true) ? $new->fetch_assoc() : null;
        if (!$new_r) { echo json_encode(['error'=>'Target delivery not found']); exit; }

        $new_growerid = (int)$new_r['new_growerid'];
        $conn->query("UPDATE bale_junus SET transporter_growersid=$new_tgid, growerid=$new_growerid WHERE id=$bjid");

        // Update ticket's start_of_dayid? No — tickets are independent
        audit_log($conn, $spid, $userid, 'bale_junus', 'change_grower', $bjid, null,
            ['old_tgid'=>$old_r['old_tgid'],'new_tgid'=>$new_tgid,'new_growerid'=>$new_growerid]);

        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── DELETE (single or bulk) ───────────────────────────────────────────────
    if ($ajax === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b    = json_decode(file_get_contents('php://input'), true) ?? [];
        $ids  = array_filter(array_map('intval', (array)($b['bjids'] ?? [])));
        if (empty($ids)) { echo json_encode(['error'=>'No bales specified']); exit; }

        $id_list = implode(',', $ids);

        // Check all belong to this floor and none are balanced
        $chk = $conn->query("SELECT bj.id, COALESCE(bb.id,0) bal, bm.selling_pointid spid
            FROM bale_junus bj JOIN bale_mass bm ON bm.id=bj.bale_massid
            LEFT JOIN balanced_bales bb ON bb.transporter_growersid=bj.transporter_growersid AND bb.splitid=bj.splitid
            WHERE bj.id IN ($id_list)");
        $blocked = []; $allowed_ids = [];
        while ($chk && $row = $chk->fetch_assoc()) {
            if ($spid && (int)$row['spid'] !== $spid) continue;
            if ((int)$row['bal']) { $blocked[] = (int)$row['id']; continue; }
            $allowed_ids[] = (int)$row['id'];
        }
        if (!empty($blocked))
            { echo json_encode(['error'=>count($blocked).' bale(s) are balanced and cannot be deleted']); exit; }
        if (empty($allowed_ids))
            { echo json_encode(['error'=>'No eligible bales to delete']); exit; }

        $safe_ids = implode(',', $allowed_ids);

        // Delete cascade: sold_bales → tickets → bale_junus; reset bale_mass.used
        $conn->query("DELETE sb FROM sold_bales sb
            JOIN tickets tk ON tk.id=sb.ticketsid
            WHERE tk.bale_junusid IN ($safe_ids)");
        $conn->query("DELETE FROM tickets WHERE bale_junusid IN ($safe_ids)");

        // Get bale_massids before deleting
        $bm_ids_q = $conn->query("SELECT bale_massid FROM bale_junus WHERE id IN ($safe_ids)");
        $bm_ids = [];
        while ($bm_ids_q && $bm_r = $bm_ids_q->fetch_assoc()) $bm_ids[] = (int)$bm_r['bale_massid'];

        $conn->query("DELETE FROM bale_junus WHERE id IN ($safe_ids)");

        // Release bale_mass records (mark as unused again)
        if ($bm_ids) {
            $bm_list = implode(',', $bm_ids);
            $conn->query("UPDATE bale_mass SET used=0 WHERE id IN ($bm_list)");
        }

        audit_log($conn, $spid, $userid, 'bale_junus', 'delete', 0, null,
            ['deleted_ids'=>$allowed_ids,'count'=>count($allowed_ids)]);

        echo json_encode(['ok'=>true,'deleted'=>count($allowed_ids)]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

// ── Page summary counts ────────────────────────────────────────────────────────
$summary_q = $conn->query("
    SELECT
        COUNT(DISTINCT bj.id)                                                 total,
        COUNT(DISTINCT CASE WHEN tk.id IS NOT NULL THEN bj.id END)           ticketed,
        COUNT(DISTINCT CASE WHEN sb.id IS NOT NULL
            AND (sb.sale_code IS NULL OR sb.sale_code NOT REGEXP '^[Rr]') THEN bj.id END) sold,
        COUNT(DISTINCT CASE WHEN sb.sale_code REGEXP '^[Rr]' THEN bj.id END) rejected,
        COUNT(DISTINCT CASE WHEN bb.id IS NOT NULL THEN bj.id END)           balanced,
        ROUND(SUM(bm.mass),0)                                                 total_mass
    FROM bale_junus bj
    JOIN bale_mass bm ON bm.id=bj.bale_massid
    JOIN transporter_growers tg ON tg.id=bj.transporter_growersid
    LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
    LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id AND bb.splitid=bj.splitid
    WHERE (bm.selling_pointid=$spid OR bm.selling_pointid=0 OR $spid=0)
");
$sum = ($summary_q && $summary_q !== true) ? $summary_q->fetch_assoc()
     : ['total'=>0,'ticketed'=>0,'sold'=>0,'rejected'=>0,'balanced'=>0,'total_mass'=>0];

include "includes/layout.php";
?>

<!-- KPI cards -->
<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color:var(--gold)">
        <div class="kpi-label">Total Bales</div>
        <div class="kpi-value"><?= number_format((int)$sum['total']) ?></div>
        <div class="kpi-sub"><?= number_format((float)$sum['total_mass']) ?> kg total mass</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#4CAF50">
        <div class="kpi-label">Sold</div>
        <div class="kpi-value"><?= number_format((int)$sum['sold']) ?></div>
        <div class="kpi-sub"><?= $sum['total'] > 0 ? round($sum['sold']/$sum['total']*100) : 0 ?>% of total</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#2196F3">
        <div class="kpi-label">Ticketed / Unsold</div>
        <div class="kpi-value"><?= number_format((int)$sum['ticketed'] - (int)$sum['sold']) ?></div>
        <div class="kpi-sub"><?= number_format((int)$sum['ticketed']) ?> ticketed total</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
        <div class="kpi-label">Rejected</div>
        <div class="kpi-value"><?= number_format((int)$sum['rejected']) ?></div>
        <div class="kpi-sub">&nbsp;</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--leaf)">
        <div class="kpi-label">Balanced</div>
        <div class="kpi-value"><?= number_format((int)$sum['balanced']) ?></div>
        <div class="kpi-sub">sheets processed</div>
    </div>
</div>

<!-- Page header -->
<div class="page-header">
    <div class="page-header-left">
        <h2>Bales</h2>
        <p id="tableSubtitle">All bales in system</p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <button class="btn btn-ghost btn-sm" onclick="exportCSV()">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" style="width:13px;height:13px"><path d="M2 11v2h12v-2M8 2v8M5 7l3 3 3-3"/></svg>
            Export CSV
        </button>
        <button class="btn btn-ghost btn-sm" onclick="exportPrint()">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" style="width:13px;height:13px"><rect x="3" y="1" width="10" height="4" rx="1"/><path d="M3 5v8h10V5"/><path d="M1 5h14v6H1z"/><path d="M5 10h6M5 12h4"/></svg>
            Print
        </button>
    </div>
</div>

<!-- Filters -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:.9rem">
    <div style="display:flex;flex-direction:column;gap:.2rem">
        <label style="font-size:.6rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">From</label>
        <input id="fDateFrom" type="date" class="form-control" style="width:145px" onchange="loadBales(1)">
    </div>
    <div style="display:flex;flex-direction:column;gap:.2rem">
        <label style="font-size:.6rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">To</label>
        <input id="fDateTo" type="date" class="form-control" style="width:145px" onchange="loadBales(1)">
    </div>
    <div style="display:flex;flex-direction:column;gap:.2rem">
        <label style="font-size:.6rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Sale Date</label>
        <input id="fSellDate" type="date" class="form-control" style="width:145px" onchange="loadBales(1)">
    </div>
    <div style="display:flex;flex-direction:column;gap:.2rem">
        <label style="font-size:.6rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Grower / Barcode</label>
        <input id="fGrower" type="text" class="form-control" placeholder="Search…" style="width:200px" oninput="loadBalesDebounced()">
    </div>
    <div style="display:flex;flex-direction:column;gap:.2rem">
        <label style="font-size:.6rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Status</label>
        <select id="fStatus" class="form-control" style="width:130px" onchange="loadBales(1)">
            <option value="">All</option>
            <option value="sold">Sold</option>
            <option value="unsold">Unsold</option>
            <option value="rejected">Rejected</option>
            <option value="balanced">Balanced</option>
            <option value="ticketed">Ticketed</option>
            <option value="pending">Pending (no ticket)</option>
        </select>
    </div>
    <div style="display:flex;flex-direction:column;gap:.2rem">
        <label style="font-size:.6rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Buyer</label>
        <select id="fBuyerName" class="form-control" style="width:130px" onchange="filterBuyerMarksDropdown(); loadBales(1)">
            <option value="">All</option>
            <?php foreach ($buyer_names_list as $b_name): ?>
                <option value="<?= htmlspecialchars($b_name) ?>"><?= htmlspecialchars($b_name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="display:flex;flex-direction:column;gap:.2rem">
        <label style="font-size:.6rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Buyer Mark</label>
        <select id="fBuyerMark" class="form-control" style="width:120px" onchange="loadBales(1)">
            <option value="">All</option>
            <?php foreach ($buyers_list as $b): ?>
                <option value="<?= htmlspecialchars($b['buyer_code']) ?>" data-buyer="<?= htmlspecialchars($b['name']) ?>"><?= htmlspecialchars($b['buyer_code']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="display:flex;flex-direction:column;gap:.2rem">
        <label style="font-size:.6rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Location</label>
        <select id="fLocation" class="form-control" style="width:120px" onchange="loadBales(1)">
            <option value="">All</option>
            <?php foreach ($locations_list as $loc): ?>
                <option value="<?= htmlspecialchars($loc['code']) ?>"><?= htmlspecialchars($loc['code'] . ($loc['name'] ? ' - ' . $loc['name'] : '')) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn btn-ghost btn-sm" onclick="clearFilters()" style="align-self:flex-end">✕ Clear</button>
    <span id="filterCount" style="font-size:.75rem;color:var(--muted);margin-left:auto;align-self:flex-end"></span>
</div>

<!-- Bulk action bar (hidden until items selected) -->
<div id="bulkBar" style="display:none;background:var(--ink);color:var(--gold-l);padding:.55rem 1rem;
     border-radius:var(--radius);margin-bottom:.75rem;
     align-items:center;gap:.75rem;flex-wrap:wrap;font-size:.8rem">
    <span id="bulkCount" style="font-weight:600"></span>
    <button class="btn btn-sm" onclick="bulkExportCSV()"
            style="background:rgba(200,149,42,.2);color:var(--gold-l);border:1px solid rgba(200,149,42,.3)">
        ⬇ Export Selected CSV
    </button>
    <button class="btn btn-sm" onclick="bulkDelete()"
            style="background:rgba(192,57,43,.25);color:#f5a9a2;border:1px solid rgba(192,57,43,.35)">
        🗑 Delete Selected
    </button>
    <button class="btn btn-sm" onclick="clearSelection()"
            style="background:transparent;color:rgba(245,240,232,.45);border:1px solid rgba(245,240,232,.15)">
        ✕ Clear
    </button>
</div>

<!-- Table -->
<div class="card">
    <div class="table-wrap" style="max-height:calc(100vh - 320px);overflow-y:auto">
        <table id="balesTable">
            <thead>
                <tr>
                    <th style="width:32px;padding-left:.6rem">
                        <input type="checkbox" id="chkAll" onchange="toggleSelectAll(this.checked)" title="Select all visible">
                    </th>
                    <th style="width:30px">#</th>
                    <th>State</th>
                    <th>Scale Barcode</th>
                    <th>Ticket Barcode</th>
                    <th>Lot · Grp</th>
                    <th class="text-right">Mass</th>
                    <th>Grower</th>
                    <th>D-Note / Date</th>
                    <th>Location</th>
                    <th>TIMB Grade</th>
                    <th>Buyer / Grade</th>
                    <th class="text-right">Price</th>
                    <th style="text-align:center">Split</th>
                    <th style="width:90px"></th>
                </tr>
            </thead>
            <tbody id="balesBody">
                <tr><td colspan="15" class="text-center text-muted" style="padding:2rem">Loading…</td></tr>
            </tbody>
        </table>
    </div>
    <!-- Pagination -->
    <div id="pagination" style="display:flex;align-items:center;gap:.5rem;padding:.6rem 1rem;
         border-top:1px solid var(--border);background:var(--cream-d);flex-wrap:wrap"></div>
</div>

<!-- ══════════════════════ MODALS ════════════════════════════════════════════ -->

<!-- Edit Details Modal -->
<div id="mdDetails" class="bale-modal" onclick="if(event.target===this)closeModal('mdDetails')">
<div class="modal-box" style="max-width:480px">
    <div class="modal-head">
        <div style="font-weight:700">Edit Bale Details</div>
        <button onclick="closeModal('mdDetails')" class="modal-close-btn">✕</button>
    </div>
    <div style="padding:1.25rem">
        <input type="hidden" id="detBjid">
        <div class="form-row cols-2">
            <div class="form-group">
                <label>Lot No.</label>
                <input id="detLot" type="number" class="form-control" min="1" max="999">
            </div>
            <div class="form-group">
                <label>Group No.</label>
                <input id="detGroup" type="number" class="form-control" min="1" max="99">
            </div>
        </div>
        <div class="form-group">
            <label>Ticket Barcode</label>
            <input id="detBarcode" type="text" class="form-control" placeholder="Leave blank to keep current">
        </div>
        <div id="detMsg" style="font-size:.78rem;min-height:1.2rem;margin-top:.5rem"></div>
    </div>
    <div class="modal-foot">
        <button class="btn btn-ghost" onclick="closeModal('mdDetails')">Cancel</button>
        <button class="btn btn-primary" onclick="saveDetails()">💾 Save</button>
    </div>
</div>
</div>

<!-- Edit Mass Modal -->
<div id="mdMass" class="bale-modal" onclick="if(event.target===this)closeModal('mdMass')">
<div class="modal-box" style="max-width:380px">
    <div class="modal-head">
        <div style="font-weight:700">Change Bale Mass</div>
        <button onclick="closeModal('mdMass')" class="modal-close-btn">✕</button>
    </div>
    <div style="padding:1.25rem">
        <input type="hidden" id="massBjid">
        <div class="form-group">
            <label>New Mass (kg)</label>
            <input id="massVal" type="number" class="form-control" min="0.1" max="299" step="0.1"
                   placeholder="e.g. 82.5">
        </div>
        <div id="massOld" style="font-size:.75rem;color:var(--muted)"></div>
        <div id="massMsg" style="font-size:.78rem;min-height:1.2rem;margin-top:.5rem"></div>
    </div>
    <div class="modal-foot">
        <button class="btn btn-ghost" onclick="closeModal('mdMass')">Cancel</button>
        <button class="btn btn-primary" onclick="saveMass()">💾 Save</button>
    </div>
</div>
</div>

<!-- Change Grower / D-Note Modal -->
<div id="mdGrowerChange" class="bale-modal" onclick="if(event.target===this)closeModal('mdGrowerChange')">
<div class="modal-box" style="max-width:560px">
    <div class="modal-head">
        <div>
            <div style="font-weight:700">Change Grower / Delivery Note</div>
            <div id="gcCurrentInfo" style="font-size:.72rem;opacity:.8;margin-top:2px"></div>
        </div>
        <button onclick="closeModal('mdGrowerChange')" class="modal-close-btn">✕</button>
    </div>
    <div style="padding:1.25rem">
        <input type="hidden" id="gcBjid">
        <div class="form-group">
            <label>Search Grower or D-Note</label>
            <input id="gcSearch" type="text" class="form-control" placeholder="Type grower #, name or D-Note…"
                   oninput="searchDeliveries()" autocomplete="off">
        </div>
        <div id="gcResults" style="border:1px solid var(--border);border-radius:var(--radius);
             max-height:240px;overflow-y:auto;display:none"></div>
        <div id="gcSelected" style="margin-top:.75rem;display:none;padding:.6rem .85rem;
             background:var(--leaf-l);border:1px solid #9bcb94;border-radius:var(--radius);font-size:.8rem"></div>
        <input type="hidden" id="gcNewTgid">
        <div id="gcMsg" style="font-size:.78rem;min-height:1.2rem;margin-top:.5rem"></div>
    </div>
    <div class="modal-foot">
        <button class="btn btn-ghost" onclick="closeModal('mdGrowerChange')">Cancel</button>
        <button id="btnSaveGrowerChange" class="btn btn-primary" onclick="saveGrowerChange()" disabled>
            💾 Move Bale
        </button>
    </div>
</div>
</div>

<!-- Shared modal styles -->
<style>
.bale-modal{display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.55);
            align-items:center;justify-content:center}
.bale-modal.open{display:flex}
.modal-box{background:#fff;border-radius:10px;width:min(96vw,100%);
           box-shadow:0 8px 40px rgba(0,0,0,.22);overflow:hidden}
.modal-head{background:#1A4D2E;color:#fff;padding:.9rem 1.25rem;
            display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem}
.modal-foot{padding:.75rem 1.25rem;border-top:1px solid rgba(0,0,0,.08);
            display:flex;justify-content:flex-end;gap:.6rem;background:#fafafa}
.modal-close-btn{background:rgba(255,255,255,.15);border:none;color:#fff;
                 border-radius:5px;padding:.3rem .65rem;cursor:pointer;font-size:.85rem;flex-shrink:0}
.modal-close-btn:hover{background:rgba(255,255,255,.25)}
body.theme-dark .modal-box{background:#1f1a14}
body.theme-dark .modal-foot{background:#191510;border-top-color:rgba(245,240,232,.10)}

/* State badges */
.state-sold     {background:#E8F5E9;color:#1B5E20;border:1px solid #9bcb94}
.state-unsold   {background:#E3F2FD;color:#0D47A1;border:1px solid #aacde6}
.state-rejected {background:#FFEBEE;color:#B71C1C;border:1px solid #f5b8b2}
.state-balanced {background:#F3E5F5;color:#4A148C;border:1px solid #CE93D8}
.state-pending  {background:var(--cream-d);color:var(--muted);border:1px solid var(--border)}

/* Action icon buttons */
.icon-btn{background:transparent;border:none;cursor:pointer;padding:.2rem .35rem;
          border-radius:3px;color:var(--muted);font-size:.82rem;transition:all .12s;line-height:1}
.icon-btn:hover{background:var(--cream-d);color:var(--text-strong)}
.icon-btn.danger:hover{background:var(--danger-bg);color:var(--danger)}
</style>

<script>
const SPID = <?= $spid ?>;
let currentPage = 1;
let totalPages  = 1;
let allRows     = [];       // rows on current page
let debounceTimer;

// ── Filters ───────────────────────────────────────────────────────────────────
function getFilters(page) {
    return {
        ajax:       'list',
        page:       page || currentPage,
        date_from:  document.getElementById('fDateFrom').value,
        date_to:    document.getElementById('fDateTo').value,
        sell_date:  document.getElementById('fSellDate').value,
        grower:     document.getElementById('fGrower').value.trim(),
        status:     document.getElementById('fStatus').value,
        buyer_name: document.getElementById('fBuyerName').value,
        buyer_mark: document.getElementById('fBuyerMark').value,
        location:   document.getElementById('fLocation').value,
    };
}

function clearFilters() {
    ['fDateFrom','fDateTo','fSellDate','fGrower'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('fStatus').value = '';
    document.getElementById('fBuyerName').value = '';
    document.getElementById('fBuyerMark').value = '';
    document.getElementById('fLocation').value = '';
    filterBuyerMarksDropdown();
    loadBales(1);
}

function filterBuyerMarksDropdown() {
    var selectedBuyer = document.getElementById('fBuyerName').value;
    var markSelect = document.getElementById('fBuyerMark');
    var currentMark = markSelect.value;
    var hasValidCurrentMark = false;
    
    var options = markSelect.options;
    for (var i = 0; i < options.length; i++) {
        var opt = options[i];
        var optBuyer = opt.getAttribute('data-buyer');
        if (!optBuyer) {
            opt.style.display = '';
            continue;
        }
        if (!selectedBuyer || optBuyer === selectedBuyer) {
            opt.style.display = '';
            if (opt.value === currentMark) {
                hasValidCurrentMark = true;
            }
        } else {
            opt.style.display = 'none';
        }
    }
    
    if (currentMark && !hasValidCurrentMark) {
        markSelect.value = '';
    }
}

function loadBalesDebounced() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => loadBales(1), 380);
}

// ── Load bales (AJAX) ─────────────────────────────────────────────────────────
function loadBales(page) {
    currentPage = page || 1;
    const params = new URLSearchParams(getFilters(currentPage));
    document.getElementById('balesBody').innerHTML =
        '<tr><td colspan="15" class="text-center text-muted" style="padding:1.5rem">Loading…</td></tr>';

    fetch('bales.php?' + params.toString(), {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        allRows    = d.rows || [];
        totalPages = d.pages || 1;
        document.getElementById('filterCount').textContent =
            d.total.toLocaleString() + ' bale' + (d.total!==1?'s':'');
        document.getElementById('tableSubtitle').textContent =
            d.total.toLocaleString() + ' bale' + (d.total!==1?'s':'') + ' found';
        renderBales(allRows);
        renderPagination(d);
        clearSelection();
    }).catch(e=>{
        document.getElementById('balesBody').innerHTML =
            '<tr><td colspan="15" class="text-center" style="color:var(--danger);padding:1.5rem">Network error. Try again.</td></tr>';
    });
}

// ── Render bales table ────────────────────────────────────────────────────────
function stateInfo(r) {
    const sc  = (r.sale_code||'').toUpperCase().trim();
    const rej = sc && (sc==='R'||sc==='CO'||sc==='RO'||(sc[0]==='R'&&sc.length<=3));
    if (parseInt(r.balanced_id))    return {label:'Balanced', cls:'state-balanced'};
    if (rej)                        return {label:'Rejected',  cls:'state-rejected'};
    if (parseInt(r.sold_id))        return {label:'Sold',      cls:'state-sold'};
    if (parseInt(r.ticket_id))      return {label:'Ticketed',  cls:'state-unsold'};
    return                                 {label:'Pending',   cls:'state-pending'};
}

function renderBales(rows) {
    const body = document.getElementById('balesBody');
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="15" class="text-center text-muted" style="padding:2rem">No bales found.</td></tr>';
        return;
    }
    const offset = (currentPage - 1) * 100;
    let html = '';
    rows.forEach((r, i) => {
        const st = stateInfo(r);
        let pr = parseFloat(r.price) || 0;
        if (pr > 10) pr /= 100;
        const locked  = parseInt(r.balanced_id) > 0;
        const locIcon = locked ? ' 🔒' : '';

        // Grower cell
        const grower = '<div class="fw-500" style="font-size:.78rem;color:var(--gold)">'
            + escH(r.grower_num) + '</div>'
            + '<div style="font-size:.68rem;color:var(--muted)">'
            + escH(r.grower_name + ' ' + r.grower_surname) + '</div>';

        // D-Note / date
        const dnote = '<div style="font-size:.72rem;font-weight:500">' + escH(r.dnote) + '</div>'
            + '<div style="font-size:.68rem;color:var(--muted)">' + (r.sell_date||'—') + '</div>';

        // Buyer / grade
        const buyer = r.buyer_grade
            ? '<div style="font-size:.75rem">' + escH(r.buyer_grade) + '</div>'
              + (r.buyer_name ? '<div style="font-size:.68rem;color:var(--muted)">' + escH(r.buyer_name) + '</div>' : '')
            : '<span style="color:var(--muted)">—</span>';

        // Price
        const priceHtml = pr > 0
            ? '<span style="font-weight:600;color:var(--leaf)">$' + pr.toFixed(4) + '</span>'
            : '<span style="color:var(--muted)">—</span>';

        // Action buttons (lock icon disables edit/delete if balanced)
        const editBtn = '<button class="icon-btn" title="Edit details" onclick="openDetails('
            + r.bjid+')" '+(locked?'disabled title="Locked">🔒':'>')+'✏️</button>';
        const massBtn = '<button class="icon-btn" title="Change mass" onclick="openMass('
            + r.bjid+','+r.mass+')">⚖️</button>';
        const moveBtn = '<button class="icon-btn" title="Change grower/D-Note" onclick="openGrowerChange('
            + r.bjid+',\'' + escH(r.grower_num) + '\',' + r.tgid + ')" '
            + (locked?'disabled>🔒':'>')+'↔️</button>';
        const delBtn  = '<button class="icon-btn danger" title="Delete bale" onclick="deleteSingle('
            + r.bjid+')" '+(locked?'disabled>🔒':'>')+'🗑</button>';

        html += '<tr data-bjid="' + r.bjid + '">'
            + '<td style="padding-left:.6rem"><input type="checkbox" class="row-chk" value="'
            + r.bjid + '" onchange="onChkChange()"></td>'
            + '<td class="text-muted" style="font-size:.72rem">' + (offset+i+1) + '</td>'
            + '<td><span class="badge ' + st.cls + '" style="font-size:.6rem">' + st.label + '</span></td>'
            + '<td><code style="font-size:.72rem;letter-spacing:.03em">' + escH(r.temp_bacode||'—') + '</code></td>'
            + '<td>' + (r.barcode
                ? '<code style="font-size:.72rem;letter-spacing:.03em;color:var(--leaf)">' + escH(r.barcode) + '</code>'
                : '<span style="color:var(--muted)">—</span>') + '</td>'
            + '<td class="td-mono" style="font-size:.78rem">' + (r.lot||'—') + ' · ' + (r.bale_group||'—') + '</td>'
            + '<td class="text-right td-mono" style="font-size:.8rem;font-weight:500">'
            + parseFloat(r.mass||0).toFixed(1) + '<span style="font-size:.68rem;color:var(--muted)"> kg</span></td>'
            + '<td>' + grower + '</td>'
            + '<td>' + dnote + '</td>'
            + '<td style="font-size:.75rem;color:var(--muted)">' + escH(r.location||'—') + '</td>'
            + '<td>' + (r.timb_grade ? '<span class="badge badge-gold" style="font-size:.6rem">' + escH(r.timb_grade) + '</span>' : '<span style="color:var(--muted)">—</span>') + '</td>'
            + '<td>' + buyer + '</td>'
            + '<td class="text-right">' + priceHtml + '</td>'
            + '<td style="text-align:center"><span style="font-size:.72rem;background:#E8F5E9;color:#1B5E20;padding:2px 7px;border-radius:99px;font-weight:600">S' + (r.splitid||1) + '</span></td>'
            + '<td style="white-space:nowrap;text-align:right">' + editBtn + massBtn + moveBtn + delBtn + '</td>'
            + '</tr>';
    });
    body.innerHTML = html;
}

// ── Pagination ────────────────────────────────────────────────────────────────
function renderPagination(d) {
    const wrap = document.getElementById('pagination');
    if (d.pages <= 1) { wrap.innerHTML = ''; return; }
    let html = '<span style="font-size:.75rem;color:var(--muted)">Page:</span>';
    const lo = Math.max(1, d.page - 3);
    const hi = Math.min(d.pages, d.page + 3);
    if (lo > 1) html += pageBtn(1, d.page) + (lo > 2 ? '<span style="color:var(--muted);padding:0 4px">…</span>' : '');
    for (let p = lo; p <= hi; p++) html += pageBtn(p, d.page);
    if (hi < d.pages) html += (hi < d.pages-1 ? '<span style="color:var(--muted);padding:0 4px">…</span>' : '') + pageBtn(d.pages, d.page);
    html += '<span style="font-size:.72rem;color:var(--muted);margin-left:.5rem">'
         + d.total.toLocaleString() + ' total</span>';
    wrap.innerHTML = html;
}
function pageBtn(p, cur) {
    const active = p === cur ? 'background:var(--ink);color:var(--gold-l)' : 'background:transparent;color:var(--text-strong);border:1px solid var(--border)';
    return '<button onclick="loadBales('+p+')" style="'+active+';border-radius:3px;padding:3px 9px;cursor:pointer;font-size:.75rem;font-family:inherit">' + p + '</button>';
}

// ── Selection / bulk ──────────────────────────────────────────────────────────
function onChkChange() {
    const all = document.querySelectorAll('.row-chk');
    const sel = document.querySelectorAll('.row-chk:checked');
    document.getElementById('chkAll').checked       = sel.length === all.length && all.length > 0;
    document.getElementById('chkAll').indeterminate = sel.length > 0 && sel.length < all.length;
    const bar = document.getElementById('bulkBar');
    if (sel.length) {
        bar.style.display = 'flex';
        document.getElementById('bulkCount').textContent = sel.length + ' selected';
    } else {
        bar.style.display = 'none';
    }
}
function toggleSelectAll(checked) {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = checked);
    onChkChange();
}
function clearSelection() {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = false);
    document.getElementById('chkAll').checked = false;
    document.getElementById('chkAll').indeterminate = false;
    document.getElementById('bulkBar').style.display = 'none';
}
function getSelectedIds() {
    return [...document.querySelectorAll('.row-chk:checked')].map(c => parseInt(c.value));
}

// ── Export CSV ────────────────────────────────────────────────────────────────
function exportCSV() {
    const params = new URLSearchParams(getFilters(1));
    params.set('export', 'csv');
    params.delete('ajax');
    params.delete('page');
    window.open('bales.php?' + params.toString());
}

function bulkExportCSV() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    // Build a minimal CSV from allRows filtered to selected ids
    const selected = allRows.filter(r => ids.includes(parseInt(r.bjid)));
    const headers = ['State','Scale Barcode','Ticket Barcode','Lot','Group','Mass(kg)',
                     'Grower #','Grower Name','D-Note','Sale Date','Location','Split',
                     'TIMB Grade','Buyer Grade','Buyer','Sale Code','Price($/kg)'];
    const rows = selected.map(r => {
        const st = stateInfo(r);
        let pr = parseFloat(r.price)||0; if (pr>10) pr/=100;
        return [st.label, r.temp_bacode, r.barcode, r.lot, r.bale_group, parseFloat(r.mass).toFixed(1),
                r.grower_num, r.grower_name+' '+r.grower_surname, r.dnote, r.sell_date,
                r.location, r.splitid, r.timb_grade, r.buyer_grade, r.buyer_name,
                r.sale_code, pr>0?pr.toFixed(4):''];
    });
    const csv = [headers, ...rows].map(row => row.map(v => '"'+(v||'').toString().replace(/"/g,'""')+'"').join(',')).join('\n');
    const blob = new Blob([csv], {type:'text/csv'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'bales_selected_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}

function exportPrint() {
    window.print();
}

// ── Delete ────────────────────────────────────────────────────────────────────
function deleteSingle(bjid) {
    if (!confirm('Delete this bale? This will also remove its ticket and sale record.\nThis cannot be undone.')) return;
    doDelete([bjid]);
}
function bulkDelete() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' bale(s)? This will also remove their tickets and sale records.\nThis cannot be undone.')) return;
    doDelete(ids);
}
function doDelete(ids) {
    fetch('bales.php?ajax=delete', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({bjids: ids})
    }).then(r=>r.json()).then(d=>{
        if (d.error) { alert('Error: ' + d.error); return; }
        showToast('✓ Deleted ' + d.deleted + ' bale(s)');
        loadBales(currentPage);
    }).catch(()=>alert('Network error. Please try again.'));
}

// ── Edit Details Modal ────────────────────────────────────────────────────────
function openDetails(bjid) {
    const r = allRows.find(x => parseInt(x.bjid) === bjid);
    if (!r) return;
    document.getElementById('detBjid').value   = bjid;
    document.getElementById('detLot').value    = r.lot || '';
    document.getElementById('detGroup').value  = r.bale_group || '';
    document.getElementById('detBarcode').value= '';
    document.getElementById('detMsg').textContent = '';
    document.getElementById('detMsg').style.color = '';
    openModal('mdDetails');
}
function saveDetails() {
    const bjid    = parseInt(document.getElementById('detBjid').value);
    const lot     = parseInt(document.getElementById('detLot').value) || 0;
    const grp     = parseInt(document.getElementById('detGroup').value) || 0;
    const barcode = document.getElementById('detBarcode').value.trim();
    const msg     = document.getElementById('detMsg');
    if (!lot || !grp) { msg.textContent = 'Lot and Group are required.'; msg.style.color='var(--danger)'; return; }
    fetch('bales.php?ajax=edit_details', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({bjid, lot, bale_group: grp, barcode})
    }).then(r=>r.json()).then(d=>{
        if (d.error) { msg.textContent = d.error; msg.style.color='var(--danger)'; return; }
        msg.textContent = '✓ Saved'; msg.style.color = 'var(--leaf)';
        setTimeout(() => { closeModal('mdDetails'); loadBales(currentPage); }, 700);
    }).catch(()=>{ msg.textContent='Network error'; msg.style.color='var(--danger)'; });
}

// ── Edit Mass Modal ───────────────────────────────────────────────────────────
function openMass(bjid, currentMass) {
    document.getElementById('massBjid').value = bjid;
    document.getElementById('massVal').value  = parseFloat(currentMass).toFixed(1);
    document.getElementById('massOld').textContent = 'Current: ' + parseFloat(currentMass).toFixed(1) + ' kg';
    document.getElementById('massMsg').textContent = '';
    openModal('mdMass');
}
function saveMass() {
    const bjid = parseInt(document.getElementById('massBjid').value);
    const mass = parseFloat(document.getElementById('massVal').value);
    const msg  = document.getElementById('massMsg');
    if (!mass || mass <= 0) { msg.textContent='Invalid mass'; msg.style.color='var(--danger)'; return; }
    fetch('bales.php?ajax=edit_mass', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({bjid, mass})
    }).then(r=>r.json()).then(d=>{
        if (d.error) { msg.textContent=d.error; msg.style.color='var(--danger)'; return; }
        msg.textContent = '✓ Updated to ' + d.new_mass.toFixed(1) + ' kg'; msg.style.color='var(--leaf)';
        setTimeout(() => { closeModal('mdMass'); loadBales(currentPage); }, 700);
    }).catch(()=>{ msg.textContent='Network error'; msg.style.color='var(--danger)'; });
}

// ── Change Grower / D-Note Modal ──────────────────────────────────────────────
let gcSearchTimer;
function openGrowerChange(bjid, growerNum, tgid) {
    document.getElementById('gcBjid').value    = bjid;
    document.getElementById('gcNewTgid').value = '';
    document.getElementById('gcSearch').value  = '';
    document.getElementById('gcResults').style.display = 'none';
    document.getElementById('gcResults').innerHTML     = '';
    document.getElementById('gcSelected').style.display= 'none';
    document.getElementById('gcSelected').innerHTML    = '';
    document.getElementById('gcMsg').textContent       = '';
    document.getElementById('gcCurrentInfo').textContent =
        'Current: ' + growerNum + ' — D-Note ' + 'GDN' + String(tgid).padStart(7,'0');
    document.getElementById('btnSaveGrowerChange').disabled = true;
    openModal('mdGrowerChange');
}
function searchDeliveries() {
    clearTimeout(gcSearchTimer);
    gcSearchTimer = setTimeout(() => {
        const q = document.getElementById('gcSearch').value.trim();
        if (q.length < 2) { document.getElementById('gcResults').style.display='none'; return; }
        fetch('bales.php?ajax=search_deliveries&q='+encodeURIComponent(q),
              {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json()).then(rows=>{
            const wrap = document.getElementById('gcResults');
            if (!rows.length) {
                wrap.style.display='block';
                wrap.innerHTML = '<div style="padding:.75rem;font-size:.78rem;color:var(--muted);text-align:center">No deliveries found</div>';
                return;
            }
            let html = '';
            rows.forEach(r => {
                html += '<div class="gc-result-row" onclick="selectDelivery('+r.tgid+',\''+escH(r.grower_num)+'\',\''+escH(r.name+' '+r.surname)+'\',\''+escH(r.dnote)+'\',\''+escH(r.sell_date||'')+'\')" style="padding:.55rem .9rem;cursor:pointer;border-bottom:1px solid var(--border);font-size:.78rem;transition:background .1s">'
                    + '<span class="badge badge-gold" style="font-size:.6rem;margin-right:.5rem">' + escH(r.grower_num) + '</span>'
                    + '<strong>' + escH(r.name+' '+r.surname) + '</strong>'
                    + ' <span style="color:var(--muted)">' + escH(r.dnote) + '</span>'
                    + ' <span style="color:var(--muted);font-size:.72rem">' + (r.sell_date||'') + '</span>'
                    + '</div>';
            });
            wrap.innerHTML = html;
            wrap.style.display = 'block';
            wrap.querySelectorAll('.gc-result-row').forEach(el => {
                el.addEventListener('mouseenter', () => el.style.background='var(--cream)');
                el.addEventListener('mouseleave', () => el.style.background='');
            });
        });
    }, 300);
}
function selectDelivery(tgid, growerNum, growerName, dnote, sellDate) {
    document.getElementById('gcNewTgid').value = tgid;
    document.getElementById('gcResults').style.display  = 'none';
    const sel = document.getElementById('gcSelected');
    sel.style.display = 'block';
    sel.innerHTML = '✓ Selected: <strong>' + escH(growerNum) + '</strong> — ' + escH(growerName)
        + ' &nbsp;·&nbsp; ' + escH(dnote) + (sellDate ? ' &nbsp;·&nbsp; ' + sellDate : '');
    document.getElementById('btnSaveGrowerChange').disabled = false;
}
function saveGrowerChange() {
    const bjid     = parseInt(document.getElementById('gcBjid').value);
    const new_tgid = parseInt(document.getElementById('gcNewTgid').value);
    const msg      = document.getElementById('gcMsg');
    if (!new_tgid) { msg.textContent='Please select a delivery first'; msg.style.color='var(--danger)'; return; }
    fetch('bales.php?ajax=change_grower', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({bjid, new_tgid})
    }).then(r=>r.json()).then(d=>{
        if (d.error) { msg.textContent=d.error; msg.style.color='var(--danger)'; return; }
        msg.textContent='✓ Bale moved successfully'; msg.style.color='var(--leaf)';
        setTimeout(() => { closeModal('mdGrowerChange'); loadBales(currentPage); }, 700);
    }).catch(()=>{ msg.textContent='Network error'; msg.style.color='var(--danger)'; });
}

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.addEventListener('keydown', e => {
    if (e.key==='Escape') document.querySelectorAll('.bale-modal.open').forEach(m=>m.classList.remove('open'));
});

// ── Utility ───────────────────────────────────────────────────────────────────
function escH(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function showToast(msg) {
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;background:var(--ink);color:var(--gold-l);'
        + 'padding:.65rem 1.1rem;border-radius:6px;font-size:.82rem;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.3)';
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}

// ── Print styling ─────────────────────────────────────────────────────────────
const printStyle = document.createElement('style');
printStyle.textContent = '@media print{.topbar,.sidebar,.page-header,.kpi-grid,#bulkBar,[style*="position:sticky"]{display:none!important}.main{margin:0!important;padding:0!important}table{font-size:9px}thead th,tbody td{padding:3px 5px}th:last-child,td:last-child{display:none}}';
document.head.appendChild(printStyle);

// ── Initial load ──────────────────────────────────────────────────────────────
filterBuyerMarksDropdown();
loadBales(1);
</script>

<?php include "includes/layout_end.php"; ?>
