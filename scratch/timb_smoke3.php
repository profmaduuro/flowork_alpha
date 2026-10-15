<?php
require_once __DIR__ . '/../includes/admin_gate.php';
// Scratch smoke test: diagnostics, richer mock, grade pull from price matrix.
$_SERVER['SCRIPT_FILENAME']=__FILE__; $_SERVER['DOCUMENT_ROOT']='C:/xampp/htdocs';
require_once __DIR__.'/../includes/conn.php';
require_once __DIR__.'/../includes/timb_ws.php';
timb_ws_schema($conn);
$SPID = 1;

echo "== 1. Diagnostics against the real (unreachable) TIMB host ==\n";
foreach (timb_ws_diagnose($conn) as $d)
    printf("  [%s] %-28s %s\n", $d['ok']?'OK':'XX', $d['label'], $d['detail']);

echo "\n== 2. Friendly error text ==\n";
foreach ([[28,'Failed to connect to www.timb.co.zw port 443 after 15004 ms: Timeout was reached'],
          [6,'Could not resolve host'],[60,'certificate verify failed'],[99,'something odd']] as $c)
    printf("  errno %-3d -> %s\n\n", $c[0], wordwrap(timb_ws_friendly_error($c[0],$c[1],'https://www.timb.co.zw/x',60,3), 100, "\n              "));

echo "== 3. Mock price matrix size (was 4) ==\n";
$res = timb_ws_price_matrix($conn, '2026-09-13', ['spid'=>$SPID,'userid'=>0,'force_mock'=>true]);
printf("  simulated=%s grades=%d\n", var_export($res['simulated'],true), count($res['matrix']));
foreach (array_slice($res['matrix'],0,4) as $m) printf("    %-10s %-5s US$%s\n",$m['grade'],$m['price_raw'],number_format($m['price_usd'],2));
echo "  ...\n";
$res2 = timb_ws_price_matrix($conn, '2026-09-13', ['spid'=>$SPID,'userid'=>0,'force_mock'=>true]);
$same = json_encode($res['matrix'])===json_encode($res2['matrix']);
echo "  deterministic across fetches: ", ($same?'PASS':'*** FAIL ***'), "\n";

echo "\n== 4. Store seasonal matrix, then pull grades from it ==\n";
$s = timb_ws_seasonal_price_matrix($conn, ['spid'=>$SPID,'userid'=>0,'force_mock'=>true]);
$st = timb_ws_save_price_matrix($conn, $SPID, $s['matrix'], 'seasonal', null);
echo "  seasonal stored: ", json_encode($st), "\n";
$before = $conn->query("SELECT COUNT(*) n FROM timb_grades")->fetch_assoc()['n'];
$imp = timb_ws_import_grades_from_matrix($conn, $SPID, 'seasonal', null);
$after = $conn->query("SELECT COUNT(*) n FROM timb_grades")->fetch_assoc()['n'];
printf("  seen=%d added=%d existing=%d | timb_grades %d -> %d\n", $imp['seen'],$imp['added'],$imp['existing'],$before,$after);
echo "  (mock matrix is built FROM timb_grades, so added should be 0): ", ($imp['added']===0?'PASS':'*** unexpected ***'), "\n";

echo "\n== 5. Grade pull from a matrix that does not exist ==\n";
$none = timb_ws_import_grades_from_matrix($conn, $SPID, 'daily', '1999-01-01');
echo "  seen=", $none['seen'], " (0 expected, page shows a 'fetch it first' message) ",
     ($none['seen']===0?'PASS':'*** FAIL ***'), "\n";

echo "\n== 6. Unconfigured direct grades endpoint is refused, not guessed ==\n";
$g = timb_ws_fetch_grades($conn, [], ['spid'=>$SPID,'userid'=>0]);
printf("  ok=%s\n  error=%s\n", var_export($g['ok'],true), $g['error']);

echo "\n== 7. Corrected endpoint paths ==\n";
foreach (timb_ws_services() as $k=>$s2)
    printf("  %-20s %-38s %-9s %s\n", $k, $s2['path']!==''?$s2['path']:'(from settings)', $s2['cred'], $s2['ready']?'ready':'-');
