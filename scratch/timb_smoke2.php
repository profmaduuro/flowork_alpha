<?php
require_once __DIR__ . '/../includes/admin_gate.php';
// Scratch smoke test for the grades + price matrix services (mock mode).
$_SERVER['SCRIPT_FILENAME']=__FILE__; $_SERVER['DOCUMENT_ROOT']='C:/xampp/htdocs';
require_once __DIR__.'/../includes/conn.php';
require_once __DIR__.'/../includes/timb_ws.php';
timb_ws_schema($conn);
$SPID = 1;

echo "-- matrix date formatting --\n";
foreach (['2017-12-12','2026-09-13','2026-01-05'] as $d)
    printf("  %-12s -> %s\n", $d, timb_ws_matrix_date($conn,$d));

echo "-- price normalisation (Flowork >10 = cents rule) --\n";
foreach (['235','453','133','103','2.35','9','10','10.5',''] as $p)
    printf("  %-6s -> US$%s\n", "'$p'", number_format(timb_ws_price_to_usd($p),4));

echo "\n-- daily price matrix (mock) --\n";
$res = timb_ws_price_matrix($conn, '2017-12-12', ['spid'=>$SPID,'userid'=>0]);
printf("  ok=%s sim=%s sent_as=%s grades=%d log_id=%d\n",
  var_export($res['ok'],true), var_export($res['simulated'],true),
  $res['matrix_date'], count($res['matrix']), $res['log_id']);
foreach ($res['matrix'] as $m) printf("    %-8s raw=%-5s US$%s\n", $m['grade'], $m['price_raw'], number_format($m['price_usd'],2));

$st = timb_ws_save_price_matrix($conn, $SPID, $res['matrix'], 'daily', '2017-12-12');
echo "  saved: ", json_encode($st), "\n";
echo "  re-save (should replace, not duplicate): ";
$st2 = timb_ws_save_price_matrix($conn, $SPID, $res['matrix'], 'daily', '2017-12-12');
echo json_encode($st2), "\n";
$n = $conn->query("SELECT COUNT(*) n FROM timb_price_matrix WHERE selling_pointid=$SPID AND matrix_type='daily' AND matrix_date='2017-12-12'")->fetch_assoc()['n'];
echo "  rows now: $n ", ($n==4?"PASS":"*** FAIL (duplicated) ***"), "\n";

echo "\n-- seasonal matrix (mock, no params) --\n";
$sres = timb_ws_seasonal_price_matrix($conn, ['spid'=>$SPID,'userid'=>0]);
printf("  ok=%s grades=%d\n", var_export($sres['ok'],true), count($sres['matrix']));
$sst = timb_ws_save_price_matrix($conn, $SPID, $sres['matrix'], 'seasonal', null);
echo "  saved: ", json_encode($sst), "\n";
$sn = $conn->query("SELECT COUNT(*) n FROM timb_price_matrix WHERE selling_pointid=$SPID AND matrix_type='seasonal' AND matrix_date IS NULL")->fetch_assoc()['n'];
echo "  seasonal rows: $sn\n";

echo "\n-- confirmation parsing (note TIMB's leading space) --\n";
foreach ([[['confirmation'=>' Successful']], [['confirmation'=>' Unsuccessful']], [['confirmation'=>'Successful']], [], [['x'=>1]]] as $d) {
    $c = timb_ws_grade_confirmation($d);
    printf("  %-40s -> text='%s' accepted=%s\n", json_encode($d), $c['text'], var_export($c['accepted'],true));
}

echo "\n-- grade submission, individually (mock) --\n";
$g = timb_ws_submit_grades($conn, 'T3OKQ, T4LD, bad grade!!', true, ['spid'=>$SPID,'userid'=>0]);
printf("  accepted=%d rejected=%d ref=%s\n", $g['accepted'], $g['rejected'], $g['batch_ref']);
foreach ($g['results'] as $r) printf("    %-12s accepted=%-5s batch_size=%d conf='%s'\n",
    $r['grade'], var_export($r['accepted'],true), $r['batch_size'], $r['confirmation']);

echo "\n-- grade submission, batched (mock) --\n";
$g2 = timb_ws_submit_grades($conn, 'T5AA, T5BB', false, ['spid'=>$SPID,'userid'=>0]);
printf("  accepted=%d rejected=%d (one HTTP call for both)\n", $g2['accepted'], $g2['rejected']);
foreach ($g2['results'] as $r) printf("    %-8s batch_size=%d\n", $r['grade'], $r['batch_size']);

echo "\n-- local catalogue guard --\n";
$before = $conn->query("SELECT COUNT(*) n FROM timb_grades")->fetch_assoc()['n'];
echo "  timb_grades before: $before\n";
echo "  ensure 'A1E' (already exists): ", var_export(timb_ws_ensure_local_grade($conn,'A1E'),true), " (false = already present)\n";
$after = $conn->query("SELECT COUNT(*) n FROM timb_grades")->fetch_assoc()['n'];
echo "  timb_grades after : $after ", ($before==$after?"PASS (no dup)":"*** FAIL ***"), "\n";
