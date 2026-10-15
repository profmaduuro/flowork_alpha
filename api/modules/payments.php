<?php
$user   = requireAuth();
requireRight($user, 'payments');
$spid   = getSpid($user);
$db     = getDB();
$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'list') {
    $date   = $db->real_escape_string($_GET['date'] ?? '');
    $status = $db->real_escape_string($_GET['status'] ?? '');
    $dc     = $date ? "AND DATE(bb.created_at)='$date'" : '';
    $r = $db->query("SELECT bb.id bbid,tg.id tgid,DATE(bb.created_at) bal_date,
            g.grower_num,g.name,g.surname,g.phone,
            COALESCE(dt.usd_value,0) gross_usd,
            COALESCE(dtn.usd_net_value,0) net_usd,COALESCE(dtn.zim_net_value,0) net_zig,
            COALESCE(ps.status,'pending') pay_status,
            COALESCE(ps.payment_method,'') method,
            COALESCE(ps.reference_number,'') reference,
            ps.paid_at paid_at_usd,
            COALESCE(gb.bank_name,'') bank_name,COALESCE(gb.account_num,'') account_num
        FROM balanced_bales bb
        JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
        JOIN growers g ON g.id=tg.growerid
        LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
        LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
        LEFT JOIN payment_status ps ON ps.transporter_growersid=tg.id AND ps.currency='usd'
        LEFT JOIN grower_banking gb ON gb.growerid=g.id
        WHERE (g.selling_pointid=$spid OR $spid=0) $dc
        GROUP BY bb.id
        ORDER BY bb.created_at DESC");
    if (!$r) err('Query failed: '.$db->error);
    $rows = $r->fetch_all(MYSQLI_ASSOC);
    if ($status) $rows = array_values(array_filter($rows, fn($p) => $p['pay_status'] === $status));
    ok($rows);
}

if ($action === 'mark_paid' && $method === 'POST') {
    // Mirrors the web 'confirm_payment' action (includes/pay_ajax.php): payment
    // role check, floor ownership, closed-day check, risk flags and payment_log.
    // Previously any token could mark any grower on any floor paid with any
    // amount, and an already paid/released record was silently overwritten.
    require_once __DIR__ . '/../../includes/pay_helpers.php';

    $b    = body();
    need($b, ['tgid','currency','amount','method']);
    $tgid = (int)$b['tgid'];
    $cur  = strtolower((string)$b['currency']) === 'zig' ? 'zig' : 'usd';
    $amt  = (float)$b['amount'];
    $meth = $db->real_escape_string($b['method']);
    $ref  = $db->real_escape_string($b['reference'] ?? '');
    $date = (string)($b['sell_date'] ?? date('Y-m-d'));
    $now  = date('Y-m-d H:i:s');
    $uid  = (int)$user['id'];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) err('sell_date must be YYYY-MM-DD');
    if ($amt <= 0) err('amount must be greater than zero');

    // Ensure table exists
    $db->query("CREATE TABLE IF NOT EXISTS payment_status (
        id INT NOT NULL AUTO_INCREMENT,
        transporter_growersid INT NOT NULL,
        sell_date DATE NOT NULL,
        currency ENUM('usd','zig') DEFAULT 'usd',
        gross_amount DECIMAL(14,2) DEFAULT '0.00',
        deductions DECIMAL(14,2) DEFAULT '0.00',
        net_amount DECIMAL(14,2) DEFAULT '0.00',
        status ENUM('pending','paid','partial','on_hold') DEFAULT 'pending',
        paid_at TIMESTAMP NULL DEFAULT NULL,
        paid_by INT DEFAULT NULL,
        notes VARCHAR(255) DEFAULT '',
        amount_paid DECIMAL(14,4) DEFAULT '0.0000',
        reviewed_by INT DEFAULT NULL,
        reviewed_at TIMESTAMP NULL DEFAULT NULL,
        approved_by INT DEFAULT NULL,
        approved_at TIMESTAMP NULL DEFAULT NULL,
        released_by INT DEFAULT NULL,
        released_at TIMESTAMP NULL DEFAULT NULL,
        risk_flags VARCHAR(255) DEFAULT '',
        payment_method VARCHAR(60) DEFAULT '',
        reference_number VARCHAR(100) DEFAULT '',
        PRIMARY KEY (id),
        UNIQUE KEY ps_unique (transporter_growersid,sell_date,currency),
        KEY sell_date (sell_date)
    )");

    try { $pay_role = pay_role($db, $uid); } catch (Throwable $e) { $pay_role = 'clerk'; }
    if (!pay_can($pay_role, 'create_payment')) err('Forbidden — your payment role cannot confirm payments', 403);

    // The grower behind this delivery must be on a floor this user may act on.
    $own = $db->query("SELECT g.selling_pointid FROM transporter_growers tg
                       JOIN growers g ON g.id = tg.growerid
                       WHERE tg.id = $tgid LIMIT 1");
    $own_row = ($own && $own !== true) ? $own->fetch_assoc() : null;
    if (!$own_row) err('Delivery not found', 404);
    $g_spid = (int)$own_row['selling_pointid'];
    $floor_ok = $spid > 0 ? ($g_spid === $spid) : in_array($g_spid, allowedSpids($user), true);
    if (!$floor_ok) err('Forbidden — this grower is not on your selling point', 403);

    $closed = $db->query("SELECT id FROM payment_day_close WHERE sell_date='$date' AND selling_pointid=$g_spid LIMIT 1");
    if ($closed && $closed !== true && $closed->num_rows > 0) err('Payment day is closed.');

    $ex = $db->query("SELECT id, status FROM payment_status WHERE transporter_growersid=$tgid AND currency='$cur' AND sell_date='$date' LIMIT 1");
    $ex_row = ($ex && $ex !== true) ? $ex->fetch_assoc() : null;
    if ($ex_row && in_array($ex_row['status'], ['paid', 'released'], true)) {
        err('This payment is already ' . $ex_row['status'] . '.', 409);
    }

    try { $flags = pay_risk_check($db, $tgid, $date, $amt, $cur); } catch (Throwable $e) { $flags = ['RISK_CHECK_FAILED']; }
    $flags_str = $db->real_escape_string(implode(',', $flags));

    if ($ex_row) {
        $pid = (int)$ex_row['id'];
        $db->query("UPDATE payment_status SET status='paid',payment_method='$meth',
            reference_number='$ref',paid_at='$now',paid_by=$uid,amount_paid=$amt,risk_flags='$flags_str' WHERE id=$pid");
    } else {
        $db->query("INSERT INTO payment_status (transporter_growersid,sell_date,currency,
            net_amount,amount_paid,status,payment_method,reference_number,paid_at,paid_by,risk_flags)
            VALUES ($tgid,'$date','$cur',$amt,$amt,'paid','$meth','$ref','$now',$uid,'$flags_str')");
        $pid = (int)$db->insert_id;
    }
    if ($db->error) err('Payment failed: '.$db->error);
    $receipt = flowork_assign_receipt($db, $pid, (string)$b['method']);
    try { pay_log($db, 'payment_status', $pid, $uid, 'paid', $cur, $amt, $b['method'], $receipt, 'via mobile API', implode(',', $flags)); } catch (Throwable $e) {}
    ok(['paid'=>true, 'reference'=>$receipt, 'flags'=>$flags]);
}

err('Unknown action');
