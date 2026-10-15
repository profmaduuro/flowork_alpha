<?php
$user   = requireAuth();
requireRight($user, 'floor');
$spid   = getSpid($user);
$db     = getDB();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $tgid = (int)($_GET['tgid'] ?? 0);
    if (!$tgid) err('tgid required');
    $r = $db->query("SELECT tk.id,tk.barcode,bm.mass,bm.temp_bacode timb_bc,
            bj.lot,bj.bale_group,bj.splitid,
            COALESCE(sb.sale_code,'') sale_code,COALESCE(sb.price,0) price
        FROM tickets tk
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        WHERE bj.transporter_growersid=$tgid ORDER BY bj.lot,bj.bale_group");
    ok(($r && $r !== true) ? $r->fetch_all(MYSQLI_ASSOC) : []);
}

if ($action === 'pdf_url') {
    $tgid = (int)($_GET['tgid'] ?? 0);
    if (!$tgid) err('tgid required');
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on') ? 'https' : 'http';
    $host   = $scheme.'://'.$_SERVER['HTTP_HOST'];
    $base   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])),'/');
    ok(['url'=>"$host$base/ticket_pdf.php?tgid=$tgid"]);
}

err('Unknown action');
