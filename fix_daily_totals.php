<?php
require_once __DIR__ . '/includes/admin_gate.php';
// Fixes daily_totals rows that have zero/null values
// docker cp fix_daily_totals.php flowork_app:/var/www/html/fix_daily_totals.php
// visit http://localhost:8080/fix_daily_totals.php
session_start();
require_once "includes/conn.php";
echo "<pre style='font:12px monospace;background:#111;color:#9c9;padding:20px'>";

// Find batches with missing daily_totals data
$batches = $conn->query("
    SELECT bb.id bbid, bb.transporter_growersid tgid, bb.splitid, bb.growerid,
           bb.created_at,
           dt.id dtid, COALESCE(dt.mass,0) dt_mass, COALESCE(dt.usd_value,0) dt_usd,
           COALESCE(dt.sold_bales,0) dt_sold
    FROM balanced_bales bb
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    WHERE dt.id IS NULL OR dt.mass=0 OR dt.usd_value=0
    ORDER BY bb.id DESC
")->fetch_all(MYSQLI_ASSOC);

echo "Batches needing fix: ".count($batches)."\n\n";

foreach ($batches as $bat) {
    $bbid    = $bat['bbid'];
    $tgid    = $bat['tgid'];
    $splitid = $bat['splitid'];
    $today   = date('Y-m-d', strtotime($bat['created_at']));
    $userid  = 1;

    // Load bales
    $bales = $conn->query("
        SELECT bm.mass, sb.price, COALESCE(sb.sale_code,'') sale_code,
               sb.id sold_id,
               er.id exchange_rateid, er.amount exchange_rate_amount
        FROM bale_junus bj
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN tickets tk ON tk.bale_junusid=bj.id
        LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
        LEFT JOIN exchange_rate er ON tk.start_of_dayid=er.start_of_dayid
        WHERE bj.transporter_growersid=$tgid AND bj.splitid=$splitid
    ")->fetch_all(MYSQLI_ASSOC);

    $gross=0; $mass=0; $sold=0; $rej=0; $ex_rateid=0; $ex_rate_amt=0;
    foreach ($bales as $b) {
        $sc = strtoupper(trim($b['sale_code']));
        $is_rej = in_array($sc,['R','CO','RO'])||($sc&&$sc[0]==='R'&&strlen($sc)<=3);
        if ($is_rej) { $rej++; continue; }
        if (!$b['sold_id']) continue;
        $p = (float)$b['price']; if($p>10)$p/=100;
        $gross += $p * (float)$b['mass'];
        $mass  += (float)$b['mass'];
        $sold++;
        if (!$ex_rateid && $b['exchange_rateid']) {
            $ex_rateid    = (int)$b['exchange_rateid'];
            $ex_rate_amt  = (float)$b['exchange_rate_amount'];
        }
    }

    // Fallback exchange rate
    if (!$ex_rate_amt) {
        $er = $conn->query("SELECT id,amount FROM exchange_rate ORDER BY id DESC LIMIT 1")->fetch_assoc();
        if ($er) { $ex_rateid=(int)$er['id']; $ex_rate_amt=(float)$er['amount']; }
    }
    if (!$ex_rate_amt) $ex_rate_amt = 0;

    $zim_val  = $gross * $ex_rate_amt;
    $avg      = ($sold>0&&$mass>0) ? $gross/$mass : 0;
    $laid     = count($bales);
    $er_val   = $ex_rateid > 0 ? $ex_rateid : 'NULL';

    echo "bbid=$bbid tgid=$tgid split=$splitid => gross=$gross mass=$mass sold=$sold rej=$rej er=$ex_rate_amt\n";

    if ($bat['dtid']) {
        // UPDATE existing zero row
        $conn->query("UPDATE daily_totals SET
            mass=$mass, usd_value=$gross, zim_value=$zim_val,
            average_price=$avg, laid_bales=$laid, sold_bales=$sold, rejected_bales=$rej,
            exchange_rateid=$er_val
            WHERE balanced_balesid=$bbid");
        echo "  -> UPDATED daily_totals id={$bat['dtid']} err=".($conn->error?:'none')."\n";
    } else {
        // INSERT missing row
        $conn->query("INSERT INTO daily_totals
            (userid,balanced_balesid,exchange_rateid,mass,usd_value,zim_value,
             average_price,laid_bales,sold_bales,rejected_bales,created_at)
            VALUES ($userid,$bbid,$er_val,$mass,$gross,$zim_val,
             $avg,$laid,$sold,$rej,'$today')");
        if ($conn->error) {
            $conn->query("INSERT INTO daily_totals
                (userid,balanced_balesid,mass,usd_value,zim_value,
                 average_price,laid_bales,sold_bales,rejected_bales,created_at)
                VALUES ($userid,$bbid,$mass,$gross,$zim_val,
                 $avg,$laid,$sold,$rej,'$today')");
        }
        echo "  -> INSERTED daily_totals err=".($conn->error?:'none')."\n";
    }
}

echo "\n✓ Done. Reload the sales sheet.\n";
echo "</pre>";
