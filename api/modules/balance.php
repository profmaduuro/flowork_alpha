<?php
$user     = requireAuth();
requireRight($user, 'balance');
$spid     = getSpid($user);
$db       = getDB();
$action   = $_GET['action'] ?? 'list';
$seasonid = getSeasonid($user);

if ($action === 'list') {
    $date = $db->real_escape_string($_GET['date'] ?? '');
    $dc   = $date ? "AND DATE(bb.created_at)='$date'" : '';
    $r = $db->query("
        SELECT bb.id bbid, bb.stoporder_processed, bb.created_at bal_date,
               g.grower_num, g.name, g.surname,
               COALESCE(dt.sold_bales,0) bales,
               COALESCE(dt.mass,0) mass,
               COALESCE(dt.usd_value,0) gross_usd,
               COALESCE(dtn.usd_net_value,0) net_usd,
               COALESCE(dtn.zim_net_value,0) net_zig,
               COALESCE(ps.status,'pending') pay_status
        FROM balanced_bales bb
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
        LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
        LEFT JOIN payment_status ps ON ps.transporter_growersid=tg.id AND ps.currency='usd'
        WHERE (g.selling_pointid=$spid OR $spid=0) $dc
        ORDER BY bb.created_at DESC LIMIT 100
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

if ($action === 'pending') {
    // One row per delivery split — splits already balanced drop off, the rest stay
    $r = $db->query("
        SELECT tg.id tgid, tg.bales, tg.sell_date,
               g.grower_num, g.name, g.surname, t.name transporter,
               COALESCE(bj.splitid,1) splitid,
               COUNT(DISTINCT bj.id) bale_count, COUNT(DISTINCT sb.id) sold_count
        FROM transporter_growers tg
        JOIN growers g ON g.id=tg.growerid
        JOIN transporter t ON t.id=tg.transporterid
        LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
        LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        WHERE tg.booked=1
          AND NOT EXISTS (SELECT 1 FROM balanced_bales _bb
                          WHERE _bb.transporter_growersid=tg.id
                            AND _bb.splitid=COALESCE(bj.splitid,1))
          AND (g.selling_pointid=$spid OR tg.selling_pointid=$spid OR $spid=0)
        GROUP BY tg.id, COALESCE(bj.splitid,1) ORDER BY g.grower_num
    ");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

if ($action === 'summary') {
    $date = $db->real_escape_string($_GET['date'] ?? date('Y-m-d'));
    $ag_q = $db->query("SELECT COUNT(*) c FROM growers g WHERE (g.selling_pointid=$spid OR $spid=0) AND g.active=1");
    $active_growers = ($ag_q && $ag_q !== true) ? (int)$ag_q->fetch_assoc()['c'] : 0;
    // price is VARCHAR — use CAST
    $r = $db->query("
        SELECT
            COUNT(DISTINCT bb.id) batches,
            COALESCE(SUM(dt.sold_bales),0) total_bales,
            COALESCE(SUM(dt.mass),0) total_mass,
            COALESCE(SUM(dt.usd_value),0) gross_usd,
            COALESCE(SUM(dtn.usd_net_value),0) net_usd,
            SUM(CASE WHEN ps.status='paid' THEN 1 ELSE 0 END) paid_count,
            (SELECT COUNT(*) FROM sold_bales sb2
             JOIN tickets tk2 ON tk2.id=sb2.ticketsid
             JOIN bale_junus bj2 ON bj2.id=tk2.bale_junusid
             JOIN growers g2 ON g2.id=bj2.growerid
             WHERE DATE(sb2.created_at)='$date'
               AND (g2.selling_pointid=$spid OR $spid=0)) today_sales,
            (SELECT COALESCE(SUM(CASE WHEN CAST(sb3.price AS DECIMAL(10,4))>10
                 THEN CAST(sb3.price AS DECIMAL(10,4))/100*bm3.mass
                 ELSE CAST(sb3.price AS DECIMAL(10,4))*bm3.mass END),0)
             FROM sold_bales sb3
             JOIN tickets tk3 ON tk3.id=sb3.ticketsid
             JOIN bale_junus bj3 ON bj3.id=tk3.bale_junusid
             JOIN bale_mass bm3 ON bm3.id=bj3.bale_massid
             JOIN growers g3 ON g3.id=bj3.growerid
             WHERE DATE(sb3.created_at)='$date'
               AND (g3.selling_pointid=$spid OR $spid=0)) today_gross_usd
        FROM balanced_bales bb
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
        LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
        LEFT JOIN payment_status ps ON ps.transporter_growersid=tg.id AND ps.currency='usd'
        WHERE (g.selling_pointid=$spid OR $spid=0) AND DATE(bb.created_at)='$date'
    ");
    $row = ($r && $r !== true) ? $r->fetch_assoc() : [];
    $row['active_growers'] = $active_growers;
    ok($row);
}

err('Unknown action');
