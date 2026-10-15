<?php
require_once __DIR__ . '/../includes/admin_gate.php';
// Scratch smoke test for includes/timb_ws.php (no DB writes to grower tables).
$_SERVER['SCRIPT_FILENAME']=__FILE__; $_SERVER['DOCUMENT_ROOT']='C:/xampp/htdocs';
require_once __DIR__.'/../includes/conn.php';
require_once __DIR__.'/../includes/timb_ws.php';

echo "-- schema --\n"; timb_ws_schema($conn); echo "ok\n";

echo "-- encrypt/decrypt --\n";
$e = timb_ws_encrypt('S3cret!pass');
echo "roundtrip: ", (timb_ws_decrypt($e)==='S3cret!pass' ? 'PASS' : 'FAIL'), "  (blob ".strlen($e)." chars)\n";
echo "blank     : '", timb_ws_encrypt(''), "'\n";

echo "-- grower number cleaning --\n";
foreach (['v111222',' V302215A ','125521','ZW-V998877','zw887766','!!!'] as $raw)
    printf("  %-12s -> %s\n", "'$raw'", timb_ws_clean_grower_num($raw) ?: '(rejected)');

echo "-- mock mode active? --\n";
var_dump(timb_ws_is_mock($conn,'booking'));

echo "-- mapping a documentation record --\n";
$m = timb_ws_map_grower(timb_ws_mock_growers()[0]);
unset($m['raw']);
foreach ($m as $k=>$v) printf("  %-22s %s\n", $k, var_export($v,true));

echo "-- lookup via mock --\n";
$res = timb_ws_growermaster($conn, 'V302201, 221559, v123456', ['spid'=>1,'userid'=>0]);
printf("  ok=%s simulated=%s rows=%d requested=%s log_id=%d\n",
    var_export($res['ok'],true), var_export($res['simulated'],true),
    count($res['growers']), implode('|',$res['requested']), $res['log_id']);
foreach ($res['growers'] as $g) printf("    %-10s %-12s obligations=%s\n", $g['grower_num'], $g['surname'], $g['obligations_fulfilled']);

echo "-- diff against local growers (spid 1) --\n";
foreach ($res['growers'] as $g) {
    $d = timb_ws_diff_local($conn, 1, $g);
    printf("    %-10s local=%s diffs=%d\n", $g['grower_num'], $d['local']?'yes':'no', count($d['diffs']));
}
echo "\nlog rows created: ";
$r=$conn->query("SELECT id,service,ok,simulated,rows_returned,duration_ms FROM timb_service_log ORDER BY id DESC LIMIT 3");
while($x=$r->fetch_assoc()) echo "\n  ",json_encode($x);
echo "\n";
