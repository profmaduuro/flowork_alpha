<?php
require_once __DIR__ . '/../includes/admin_gate.php';
// Scratch smoke test for the TIMB booking web service (mock mode).
$_SERVER['SCRIPT_FILENAME']=__FILE__; $_SERVER['DOCUMENT_ROOT']='C:/xampp/htdocs';
require_once __DIR__.'/../includes/conn.php';
require_once __DIR__.'/../includes/timb_ws.php';
timb_ws_schema($conn);
$SPID=1; $M=['spid'=>$SPID,'userid'=>0,'force_mock'=>true];
$fc = timb_ws_floor_code($conn,$SPID);
echo "floor code for selling point $SPID: '$fc'\n";

echo "\n== 1. Date + reoffer normalisation ==\n";
printf("  sale date 2018-05-12 -> %s (spec example: 12-MAY-18)\n", timb_ws_timb_date($conn,'2018-05-12'));
foreach (['0','1','','Y','n','yes','TRUE'] as $r) printf("  reoffer '%-4s' -> %s\n", $r, timb_ws_reoffer($r));

echo "\n== 2. THE ERROR CONVENTION: errors arrive inside a 200, in bookingID ==\n";
$cases = [
  'real booking'      => ['bookingID'=>'1552599548','grower'=>'V122512','balesBooked'=>'8','sellDate'=>'12-MAY-18','bookingDate'=>'10-MAY-18','bookedBy'=>'TESTUSER','floor'=>'EJV','reoffer'=>'Y'],
  'not booked/floor'  => ['bookingID'=>'Grower is not booked to sell on your floor.','bookingDate'=>'','grower'=>'','balesBooked'=>'','sellDate'=>'','bookedBy'=>'','floor'=>'','reoffer'=>''],
  'not booked/date'   => ['bookingID'=>'Grower is not booked for the specified date.','bookingDate'=>'','grower'=>'','balesBooked'=>'','sellDate'=>'','bookedBy'=>'','floor'=>'','reoffer'=>''],
  'empty row'         => [],
];
foreach ($cases as $name=>$row) {
  $e = timb_ws_booking_error($row);
  printf("  %-18s -> %s\n", $name, $e===''? 'treated as a REAL booking' : "ERROR: \"$e\"");
}

echo "\n== 3. Query (mock). Grower ending in 9 triggers the error path ==\n";
foreach (['V122512','V255369'] as $g) {
  $res = timb_ws_booking_query($conn,$g,'2018-05-12',$fc,$M);
  if ($res['ok']) {
    $b=$res['booking'];
    printf("  %-9s OK  id=%s bales=%d sell=%s reoffer=%s\n",$g,$b['booking_id'],$b['bales_booked'],$b['sell_date'],$b['reoffer']);
  } else {
    printf("  %-9s ERR \"%s\"  <-- 200 response, correctly NOT stored as a booking\n",$g,$res['error']);
  }
}

echo "\n== 4. Insert / update / delete (mock) ==\n";
$bk=['grower_num'=>'V255368','sell_date'=>'2018-05-12','booking_date'=>'2018-05-10',
     'bales_booked'=>150,'reoffer'=>'N','booked_by'=>'TESTUSER','floor'=>$fc];
foreach (['insert','update','delete'] as $op) {
  $r = timb_ws_booking_write($conn,$op,$bk,$M);
  printf("  %-7s ok=%-5s result=%-8s msg=%s\n",$op,var_export($r['ok'],true),$r['result'],$r['message']?:$r['error']);
}
echo "  -- failure path (0 bales) --\n";
$bad=$bk; $bad['bales_booked']=0;
$r=timb_ws_booking_write($conn,'insert',$bad,$M);
printf("  insert  ok=%-5s error=%s\n",var_export($r['ok'],true),$r['error']);

echo "\n== 5. Missing required fields are refused before any request ==\n";
foreach ([['grower_num'=>'','sell_date'=>'2018-05-12','floor'=>$fc],
          ['grower_num'=>'V1','sell_date'=>'','floor'=>$fc],
          ['grower_num'=>'V1','sell_date'=>'2018-05-12','floor'=>'']] as $b2) {
  $r=timb_ws_booking_write($conn,'insert',$b2+['bales_booked'=>5],$M);
  printf("  grower='%s' sell='%s' floor='%s' -> %s\n",$b2['grower_num'],$b2['sell_date'],$b2['floor'],$r['error']);
}

echo "\n== 6. apply_booking against booked_bales (real local write) ==\n";
$g = $conn->query("SELECT g.grower_num FROM booked_bales bb JOIN growers g ON g.id=bb.growerid WHERE g.selling_pointid=$SPID LIMIT 1");
$gn = $g && $g!==true && $g->num_rows ? $g->fetch_assoc()['grower_num'] : null;
if ($gn) {
  $row = $conn->query("SELECT bb.id,bb.sell_date,bb.bales_booked,bb.booked_id FROM booked_bales bb JOIN growers g ON g.id=bb.growerid WHERE g.grower_num='$gn' LIMIT 1")->fetch_assoc();
  echo "  existing booking #{$row['id']} grower=$gn sell={$row['sell_date']} bales={$row['bales_booked']} ref='{$row['booked_id']}'\n";
  $b3=['grower_num'=>$gn,'sell_date'=>$row['sell_date'],'booking_date'=>$row['sell_date'],
       'bales_booked'=>(int)$row['bales_booked'],'booking_id'=>$row['booked_id'],'reoffer'=>'N','booked_by'=>'X','floor'=>$fc];
  $a=timb_ws_apply_booking($conn,$SPID,$b3,0);
  echo "  same data again -> action={$a['action']} (expect 'unchanged'; nothing should be rewritten)\n";
  $a2=timb_ws_apply_booking($conn,$SPID,['grower_num'=>'ZZZNOTAGROWER','sell_date'=>'2026-01-01','booking_date'=>'2026-01-01','bales_booked'=>1,'booking_id'=>'X','reoffer'=>'N','booked_by'=>'','floor'=>$fc],0);
  echo "  unknown grower   -> action={$a2['action']} (expect 'no_grower'; no row created)\n";
}
$n=$conn->query("SELECT COUNT(*) n FROM booked_bales")->fetch_assoc()['n'];
echo "  booked_bales rows now: $n (was 49 at session start)\n";
