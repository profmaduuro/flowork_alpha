<?php
$user   = requireAuth();
requireRight($user, 'stoporders');
$spid   = getSpid($user);
$db     = getDB();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $r = $db->query("SELECT isoa.id,isoa.amount,isoa.priority,isoa.processed,
            isoa.sell_date,iso.description,iso.creditor_number,
            COALESCE(NULLIF(gc.name,''),gc.creditor_name,iso.creditor_number) cred_name,
            COALESCE(g.grower_num,'—') grower_num,
            COALESCE(CONCAT(g.name,' ',g.surname),'') grower_name
        FROM internal_stop_order_amount isoa
        JOIN internal_stop_order iso ON iso.id=isoa.internal_stop_orderid
        LEFT JOIN grower_creditors gc ON gc.creditor_no=iso.creditor_number
        LEFT JOIN growers g ON g.id=isoa.growerid
        WHERE (iso.selling_pointid=$spid OR iso.selling_pointid=0 OR $spid=0)
          AND isoa.active=1
        ORDER BY isoa.processed ASC,isoa.priority ASC,isoa.id DESC LIMIT 300");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

if ($action === 'grower') {
    $gid = (int)($_GET['grower_id'] ?? 0);
    if (!$gid) err('grower_id required');
    $r = $db->query("SELECT isoa.id,isoa.amount,isoa.priority,isoa.processed,
            iso.description,iso.creditor_number,
            COALESCE(NULLIF(gc.name,''),gc.creditor_name,iso.creditor_number) cred_name
        FROM internal_stop_order_amount isoa
        JOIN internal_stop_order iso ON iso.id=isoa.internal_stop_orderid
        LEFT JOIN grower_creditors gc ON gc.creditor_no=iso.creditor_number
        WHERE isoa.growerid=$gid AND isoa.active=1 ORDER BY isoa.priority");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

err('Unknown action');
