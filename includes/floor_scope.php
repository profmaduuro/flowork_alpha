<?php
/**
 * floor_scope.php — "does this record belong to the user's floor?"
 *
 * Every floor (customer) shares one database, separated by selling_pointid.
 * Handlers that change a record by an id from the request call
 * flowork_on_floor() first, so a user on one floor cannot change another
 * floor's records by guessing ids.
 *
 * Kinds map to how each table reaches a floor. Tables with no floor column at
 * all (processing, packhouse, dispatch, transporters, TIMB grades) are shared
 * by every floor in the database and are not covered here.
 */

if (!function_exists('flowork_on_floor')) {
    function flowork_on_floor(mysqli $conn, string $kind, int $id, int $spid): bool {
        if ($id <= 0 || $spid <= 0) return false;
        $sql = [
            // delivery link: grower's floor, or the floor the delivery was recorded on
            'tg'          => "SELECT 1 FROM transporter_growers tg JOIN growers g ON g.id=tg.growerid
                              WHERE tg.id=$id AND (g.selling_pointid=$spid OR tg.selling_pointid=$spid)",
            'bb'          => "SELECT 1 FROM balanced_bales bb JOIN growers g ON g.id=bb.growerid
                              WHERE bb.id=$id AND (g.selling_pointid=$spid OR bb.selling_pointid=$spid)",
            'grower'      => "SELECT 1 FROM growers WHERE id=$id AND selling_pointid=$spid",
            'sold_bale'   => "SELECT 1 FROM sold_bales sb JOIN tickets t ON t.id=sb.ticketsid
                              JOIN bale_junus bj ON bj.id=t.bale_junusid JOIN growers g ON g.id=bj.growerid
                              WHERE sb.id=$id AND g.selling_pointid=$spid",
            'booked_bale' => "SELECT 1 FROM booked_bales b JOIN growers g ON g.id=b.growerid
                              WHERE b.id=$id AND g.selling_pointid=$spid",
            'buyer'       => "SELECT 1 FROM buyer WHERE id=$id AND selling_pointid=$spid",
            'buyer_grade' => "SELECT 1 FROM buyer_grades bg JOIN buyer b ON b.id=bg.buyerid
                              WHERE bg.id=$id AND b.selling_pointid=$spid",
            'loan'        => "SELECT 1 FROM grower_loans WHERE id=$id AND selling_pointid=$spid",
            'contract'    => "SELECT 1 FROM cf_contracts WHERE id=$id AND selling_pointid=$spid",
            'start_of_day'=> "SELECT 1 FROM start_of_day WHERE id=$id AND selling_pointid=$spid",
            'vehicle'     => "SELECT 1 FROM vehicles WHERE id=$id AND selling_pointid=$spid",
            // a payment batch belongs to a floor when all its growers do
            'pay_batch'   => "SELECT 1 FROM payment_batch_item pbi
                              JOIN transporter_growers tg ON tg.id=pbi.transporter_growersid
                              JOIN growers g ON g.id=tg.growerid
                              WHERE pbi.payment_batchid=$id
                              HAVING COUNT(*) > 0 AND SUM(g.selling_pointid=$spid OR tg.selling_pointid=$spid) = COUNT(*)",
        ][$kind] ?? null;
        if ($sql === null) throw new InvalidArgumentException("flowork_on_floor: unknown kind '$kind'");
        $q = $conn->query($sql . ($kind === 'pay_batch' ? '' : ' LIMIT 1'));
        return $q && $q !== true && $q->num_rows > 0;
    }
}
