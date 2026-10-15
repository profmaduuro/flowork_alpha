<?php
/**
 * email_reports_functions.php
 * Core function to compile and send the EOD email digest.
 */

function send_eod_email($conn, $spid, $date_f) {
    return _send_eod_inline($conn, $spid, $date_f);
}

function _send_eod_inline($conn, $spid, $date_f) {
    $settings_q = $conn->query("SELECT name,value FROM settings WHERE name LIKE 'email_%' LIMIT 20");
    $cfg = [];
    while($settings_q && $r=$settings_q->fetch_assoc()) $cfg[$r['name']] = $r['value'];
    
    $to   = $cfg['email_reports_to'] ?? '';
    $from = $cfg['email_from'] ?? 'flowork@localhost';
    
    if (!$to) {
        return ['ok'=>false,'error'=>'No recipient configured','recipients'=>''];
    }
    
    $de = $conn->real_escape_string($date_f);
    $sp_q = $conn->query("SELECT name FROM selling_point WHERE id=$spid LIMIT 1");
    $sp_name = ($sp_q && $sp_q !== true) ? ($sp_q->fetch_assoc()['name'] ?? 'Flowork') : 'Flowork';
    
    $stats = $conn->query("
        SELECT COUNT(DISTINCT sb.id) bales_sold,
               COALESCE(SUM(bm.mass),0) mass,
               COALESCE(SUM(CASE WHEN sb.price>10 THEN sb.price/100*bm.mass ELSE sb.price*bm.mass END),0) usd,
               COALESCE(AVG(CASE WHEN sb.price>10 THEN sb.price/100 WHEN sb.price>0 THEN sb.price END),0) avg_price,
               COUNT(DISTINCT g.id) growers, COUNT(DISTINCT b.id) buyers
        FROM sold_bales sb
        JOIN tickets tk ON tk.id=sb.ticketsid
        JOIN bale_junus bj ON bj.id=tk.bale_junusid
        JOIN bale_mass bm ON bm.id=bj.bale_massid
        JOIN growers g ON g.id=bj.growerid
        LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid
        LEFT JOIN buyer b ON b.id=bg.buyerid
        WHERE DATE(sb.created_at)='$de' AND g.selling_pointid=$spid
    ")->fetch_assoc();
    
    $date_display = date('l d F Y', strtotime($date_f));
    
    $body = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f5f0e8'>
    <div style='background:#1a1208;padding:20px;text-align:center'>
      <h1 style='color:#e8b84b;margin:0;font-size:20px'>🌿 " . htmlspecialchars($sp_name) . "</h1>
      <p style='color:rgba(232,184,75,.6);margin:4px 0 0;font-size:12px'>End of Day Report — $date_display</p>
    </div>
    <div style='background:#fff;padding:20px'>
    <table width='100%' cellpadding='8' cellspacing='4'>
      <tr>
        <td style='background:#faf8f4;border-radius:6px;text-align:center;padding:12px'><div style='font-size:24px;font-weight:700;color:#c8952a'>".number_format((int)($stats['bales_sold']??0))."</div><div style='font-size:10px;color:#888;text-transform:uppercase'>Bales Sold</div></td>
        <td style='background:#faf8f4;border-radius:6px;text-align:center;padding:12px'><div style='font-size:24px;font-weight:700;color:#c8952a'>$".number_format((float)($stats['usd']??0),0)."</div><div style='font-size:10px;color:#888;text-transform:uppercase'>USD Value</div></td>
        <td style='background:#faf8f4;border-radius:6px;text-align:center;padding:12px'><div style='font-size:24px;font-weight:700;color:#c8952a'>".number_format((float)($stats['mass']??0),0)."</div><div style='font-size:10px;color:#888;text-transform:uppercase'>Mass (kg)</div></td>
        <td style='background:#faf8f4;border-radius:6px;text-align:center;padding:12px'><div style='font-size:24px;font-weight:700;color:#c8952a'>$".number_format((float)($stats['avg_price']??0),4)."</div><div style='font-size:10px;color:#888;text-transform:uppercase'>Avg \$/kg</div></td>
      </tr>
    </table>
    <p style='font-size:12px;color:#888;margin-top:12px'>Growers: ".(int)($stats['growers']??0)." &nbsp;·&nbsp; Buyers: ".(int)($stats['buyers']??0)."</p>
    </div>
    <div style='background:#1a1208;padding:12px;text-align:center;font-size:11px;color:rgba(232,184,75,.4)'>Flowork Floor Management System &middot; Automated EOD Report</div>
    </body></html>";
    
    $subject = "[Flowork] EOD Report — $sp_name — $date_display";
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: $from\r\n";
    $sent = @mail($to, $subject, $body, $headers);
    return ['ok'=>$sent,'method'=>'mail','recipients'=>$to];
}
