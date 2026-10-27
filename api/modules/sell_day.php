<?php
/**
 * Active selling day for a floor — shared by the API modules (not a route).
 * start_of_day (opened in Day Control, one active row per floor) is the source
 * of truth; selling_days is only written by the mobile API and can keep an old
 * day "open", so it is a fallback only. Same order as sales.php.
 */

// This floor's active start_of_day row: ['id'=>int, 'sell_date'=>'Y-m-d'], or null
function apiActiveSod(mysqli $db, int $spid): ?array {
    $q = $db->query("SELECT id, description FROM start_of_day
        WHERE selling_pointid=$spid AND active=1 ORDER BY id DESC LIMIT 1");
    if (!$q || $q === true || $q->num_rows === 0) return null;
    $row = $q->fetch_assoc();
    $ts  = strtotime($row['description']);
    return ['id' => (int)$row['id'], 'sell_date' => $ts ? date('Y-m-d', $ts) : ''];
}

// Current sell date (Y-m-d): active start_of_day, then an open selling_days row, then today
function apiSellDate(mysqli $db, int $spid): string {
    $sod = apiActiveSod($db, $spid);
    if ($sod && $sod['sell_date']) return $sod['sell_date'];

    $sd = $db->query("SELECT sell_date FROM selling_days
        WHERE selling_pointid=$spid AND status='open'
        ORDER BY sell_date DESC LIMIT 1");
    if ($sd && $sd !== true && $sd->num_rows > 0)
        return $sd->fetch_assoc()['sell_date'];

    return date('Y-m-d');
}
