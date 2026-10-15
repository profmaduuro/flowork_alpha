<?php
// update_balance.php
// Upload THIS file to your flowork folder, then visit:
// http://localhost:8080/update_balance.php
// It will write the new balance.php directly on the server.

$target = __DIR__ . '/balance.php';

$new_content = <<<'BALANCE_PHP'
<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "Balance & Settlement";
$active_page = "balance";
$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$today  = date('Y-m-d');

// ── AJAX: panel ────────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'panel') {
    header('Content-Type: application/json');
    $tgid    = (int)($_GET['tgid'] ?? 0);
    $splitid = (int)($_GET['split'] ?? 0);
    if (!$tgid) { echo json_encode(['error'=>'No tgid']); exit; }
    $pg = $conn->query("SELECT g.id growerid,g.grower_num,g.name,g.surname,
        tg.id tgid,tg.bales tg_bales,tg.junused_bales,tg.booked,
        COALESCE(tg.sell_date,'$today') sell_dt,
        tr.name transporter_name,tr.truck_num,COALESCE(bl.code,'') loc_code
        FROM transporter_growers tg
        JOIN growers g ON g.id=tg.growerid
        JOIN transporter tr ON tr.id=tg.transporterid
        LEFT JOIN bale_locations bl ON bl.id=tr.location_id
        WHERE tg.id=$tgid LIMIT 1");
    $pg = ($pg && $pg!==true) ? $pg->fetch_assoc() : null;
    if (!$pg) { echo json_encode(['error'=>'Grower not found']); exit; }
    $growerid = (int)$pg['growerid'];
    $splits_q = $conn->query("SELECT DISTINCT bj.splitid,COUNT(*) cnt FROM bale_junus bj WHERE bj.transporter_growersid=$tgid AND bj.splitid>0 GROUP BY bj.splitid ORDER BY bj.splitid");
    $splits_available = ($splits_q && $splits_q!==true) ? $splits_q->fetch_all(MYSQLI_ASSOC) : [];
    if (!$splitid && !empty($splits_available)) $splitid=(int)$splits_available[0]['splitid'];
    if (!$splitid) $splitid=1;
    $_ab=$conn->query("SELECT id FROM balanced_bales WHERE transporter_growersid=$tgid AND splitid=$splitid LIMIT 1");
    $already_balanced=($_ab&&$_ab!==true&&$_ab->num_rows)?(int)$_ab->fetch_assoc()['id']:0;
    $sell_dt=$pg['sell_dt']; $sell_dt_alt=date('d-M-y',strtotime($sell_dt));
    $is_booked=(int)($pg['booked']??0)===1; $bales_booked=0;
    $_bb=$conn->query("SELECT id,bales_booked FROM booked_bales WHERE growerid=$growerid AND (sell_date='$sell_dt' OR sell_date='$sell_dt_alt') ORDER BY id DESC LIMIT 1");
    if(!$_bb||!$_bb->num_rows) $_bb=$conn->query("SELECT id,bales_booked FROM booked_bales WHERE growerid=$growerid ORDER BY id DESC LIMIT 1");
    if($_bb&&$_bb!==true&&$_bb->num_rows){$bbr=$_bb->fetch_assoc();$bales_booked=(int)$bbr['bales_booked'];if($bales_booked>0)$is_booked=true;}
    $split_where=$splitid>0?"AND bj.splitid=$splitid":"";
    $bale_q=$conn->query("SELECT bj.id bjid,bj.lot,bj.bale_group,bj.splitid,bm.mass,bm.temp_bacode timb_bc,tk.barcode,tk.id ticketid,sb.id sold_id,sb.price,sb.sale_code,sb.timb_gradesid,sb.buyer_gradesid,tg2.description timb_grade,bg.description buyer_grade,bg.id bgid,b.name buyer_name,b.id buyerid FROM bale_junus bj JOIN bale_mass bm ON bm.id=bj.bale_massid LEFT JOIN tickets tk ON tk.bale_junusid=bj.id LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id LEFT JOIN timb_grades tg2 ON tg2.id=sb.timb_gradesid LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid LEFT JOIN buyer b ON b.id=bg.buyerid WHERE bj.transporter_growersid=$tgid $split_where ORDER BY bj.lot ASC,bj.bale_group ASC");
    $bales=($bale_q&&$bale_q!==true)?$bale_q->fetch_all(MYSQLI_ASSOC):[];
    $total_bales=count($bales); $sold_cnt=count(array_filter($bales,fn($b)=>$b['sold_id']));
    $unsold=$total_bales-$sold_cnt; $total_mass=array_sum(array_column($bales,'mass'));
    $gross=array_reduce($bales,function($c,$b){if(!$b['sold_id'])return $c;$p=(float)$b['price'];if($p>10)$p/=100;return $c+(float)$b['mass']*$p;},0);
    $booking_warn='';
    if(!$is_booked)$booking_warn='NOT_BOOKED';
    elseif($bales_booked>0&&$total_bales>$bales_booked)$booking_warn="MISMATCH:$bales_booked:$total_bales";
    $tg_all=$conn->query("SELECT id,description FROM timb_grades WHERE active=1 ORDER BY description");
    $tg_all=($tg_all&&$tg_all!==true)?$tg_all->fetch_all(MYSQLI_ASSOC):[];
    $bg_all=$conn->query("SELECT bg.id,bg.description,b.name buyer_name FROM buyer_grades bg JOIN buyer b ON b.id=bg.buyerid WHERE bg.active=1 ORDER BY b.name,bg.description");
    $bg_all=($bg_all&&$bg_all!==true)?$bg_all->fetch_all(MYSQLI_ASSOC):[];
    echo json_encode(['ok'=>true,'grower'=>$pg,'splits'=>$splits_available,'current_split'=>$splitid,'already_balanced'=>$already_balanced,'booking_warn'=>$booking_warn,'bales_booked'=>$bales_booked,'bales'=>$bales,'stats'=>compact('total_bales','sold_cnt','unsold','total_mass','gross'),'timb_grades'=>$tg_all,'buyer_grades'=>$bg_all]);
    exit;
}

// ── AJAX: update bale ──────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_bale') {
    header('Content-Type: application/json');
    $sbid=(int)($_POST['sold_id']??0);
    if(!$sbid){echo json_encode(['error'=>'No ID']);exit;}
    $sets=[];
    if(!empty($_POST['timb_gradesid']))$sets[]="timb_gradesid=".(int)$_POST['timb_gradesid'];
    if(!empty($_POST['buyer_gradesid']))$sets[]="buyer_gradesid=".(int)$_POST['buyer_gradesid'];
    $price=(float)($_POST['price']??0);if($price>0)$sets[]="price=$price";
    if($sets)$conn->query("UPDATE sold_bales SET ".implode(',',$sets)." WHERE id=$sbid");
    $row=$conn->query("SELECT sb.id,sb.price,sb.timb_gradesid,sb.buyer_gradesid,tg.description timb_grade,bg.description buyer_grade,b.name buyer_name FROM sold_bales sb LEFT JOIN timb_grades tg ON tg.id=sb.timb_gradesid LEFT JOIN buyer_grades bg ON bg.id=sb.buyer_gradesid LEFT JOIN buyer b ON b.id=bg.buyerid WHERE sb.id=$sbid LIMIT 1");
    $row=($row&&$row!==true)?$row->fetch_assoc():[];
    echo json_encode(['ok'=>true,'row'=>$row]);exit;
}

// ── AJAX: balance ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='balance') {
    header('Content-Type: application/json');
    $tgid=(int)($_POST['tgid']??0); $splitid=max(1,(int)($_POST['splitid']??1)); $growerid=(int)($_POST['growerid']??0);
    if(!$tgid||!$growerid){echo json_encode(['error'=>'Missing fields']);exit;}
    $_ab=$conn->query("SELECT id FROM balanced_bales WHERE transporter_growersid=$tgid AND splitid=$splitid LIMIT 1");
    if($_ab&&$_ab!==true&&$_ab->num_rows){echo json_encode(['error'=>'Already balanced.']);exit;}
    $_tgi=$conn->query("SELECT booked,COALESCE(sell_date,'$today') sell_dt FROM transporter_growers WHERE id=$tgid LIMIT 1");
    $tgi=($_tgi&&$_tgi!==true)?$_tgi->fetch_assoc():null;
    $is_booked=$tgi&&(int)$tgi['booked']===1;
    $sell_dt=$tgi['sell_dt']??$today; $sell_dt_alt=date('d-M-y',strtotime($sell_dt));
    $_bb=$conn->query("SELECT bales_booked FROM booked_bales WHERE growerid=$growerid AND (sell_date='$sell_dt' OR sell_date='$sell_dt_alt') ORDER BY id DESC LIMIT 1");
    if(!$_bb||!$_bb->num_rows)$_bb=$conn->query("SELECT bales_booked FROM booked_bales WHERE growerid=$growerid ORDER BY id DESC LIMIT 1");
    if($_bb&&$_bb!==true&&$_bb->num_rows){if((int)$_bb->fetch_assoc()['bales_booked']>0)$is_booked=true;}
    if(!$is_booked){echo json_encode(['error'=>'Grower not booked. Go to Grower Booking first.']);exit;}
    $res=$conn->query("SELECT bj.id,bm.mass,tk.start_of_dayid,sb.id sold_id,sb.price,COALESCE(sb.sale_code,'') sale_code,er.id exchange_rateid,er.amount exchange_rate_amount FROM bale_junus bj JOIN bale_mass bm ON bm.id=bj.bale_massid JOIN tickets tk ON tk.bale_junusid=bj.id LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id LEFT JOIN exchange_rate er ON tk.start_of_dayid=er.start_of_dayid WHERE bj.transporter_growersid=$tgid AND bj.splitid=$splitid");
    $bales=($res&&$res!==true)?$res->fetch_all(MYSQLI_ASSOC):[];
    if(empty($bales)){echo json_encode(['error'=>'No bales found.']);exit;}
    $unsold=count(array_filter($bales,fn($b)=>!$b['sold_id']));
    if($unsold>0){echo json_encode(['error'=>"$unsold bale(s) not yet sold."]);exit;}
    $gross=0;$ytd_mass=0;$sold_cnt=0;$ex_rateid=0;$ex_rate_amt=0;
    foreach($bales as $b){$is_rej=strtoupper(substr(trim($b['sale_code']),0,1))==='R'&&strlen(trim($b['sale_code']))<=3;if(!$is_rej&&$b['sold_id']){$p=(float)$b['price'];if($p>10)$p/=100;$ytd_mass+=(float)$b['mass'];$gross+=(float)$b['mass']*$p;if(!$ex_rateid){$ex_rateid=(int)$b['exchange_rateid'];$ex_rate_amt=(float)$b['exchange_rate_amount'];}$sold_cnt++;}}
    if(!$ex_rateid||!$ex_rate_amt){$_er=$conn->query("SELECT er.id,er.amount FROM exchange_rate er JOIN start_of_day sod ON sod.id=er.start_of_dayid WHERE sod.active=1 ORDER BY er.id DESC LIMIT 1");if(!$_er||!$_er->num_rows)$_er=$conn->query("SELECT id,amount FROM exchange_rate ORDER BY id DESC LIMIT 1");if($_er&&$_er!==true&&$_er->num_rows){$er=$_er->fetch_assoc();$ex_rateid=(int)$er['id'];$ex_rate_amt=(float)$er['amount'];}}
    if(!$ex_rate_amt)$ex_rate_amt=1;
    $zim_value=$gross*$ex_rate_amt; $avg_price=($sold_cnt>0&&$ytd_mass>0)?$gross/$ytd_mass:0;
    $conn->query("INSERT INTO balanced_bales (userid,splitid,growerid,transporter_growersid,created_at,datetime) VALUES ($userid,$splitid,$growerid,$tgid,'$today',NOW())");
    $bbid=(int)$conn->insert_id;
    if(!$bbid){echo json_encode(['error'=>'Balance insert failed: '.$conn->error]);exit;}
    $conn->query("INSERT INTO daily_totals (userid,balanced_balesid,exchange_rateid,mass,usd_value,zim_value,average_price,laid_bales,sold_bales,rejected_bales,sell_date,created_at) VALUES ($userid,$bbid,$ex_rateid,$ytd_mass,$gross,$zim_value,$avg_price,".count($bales).",$sold_cnt,".(count($bales)-$sold_cnt).",'$today','$today')");
    $cur_usd=$gross; $cur_zig=$zim_value;
    $cq=$conn->query("SELECT gc.id,gc.amount,gc.statutoryid,COALESCE(ct.description,'Amount') ct_d,COALESCE(cu.description,'USD') cu_d FROM grower_charges gc LEFT JOIN charge_type ct ON ct.id=gc.charge_typeid LEFT JOIN statutory st ON st.id=gc.statutoryid LEFT JOIN currency cu ON cu.id=st.currencyid WHERE gc.selling_pointid=$spid AND gc.active=1");
    $bank_charge_row=null;
    if($cq&&$cq!==true)while($ch=$cq->fetch_assoc()){$ca_u=0;$ca_z=0;$a=(float)$ch['amount'];if((int)$ch['statutoryid']===7){$bank_charge_row=$ch;continue;}if($ch['cu_d']==='USD'){if($ch['ct_d']==='Value')$ca_u=$gross*$a;elseif($ch['ct_d']==='Mass')$ca_u=$ytd_mass*$a;elseif($ch['ct_d']==='Mass and Value')$ca_u=($ytd_mass+$gross)*$a;elseif($ch['ct_d']==='Bales')$ca_u=$sold_cnt*$a;elseif($ch['ct_d']==='Amount')$ca_u=$a;}else{if($ch['ct_d']==='Value')$ca_z=$zim_value*$a;elseif($ch['ct_d']==='Mass')$ca_z=$ytd_mass*$a;elseif($ch['ct_d']==='Bales')$ca_z=$sold_cnt*$a;elseif($ch['ct_d']==='Amount')$ca_z=$a;}$conn->query("INSERT INTO balanced_bales_charges (userid,grower_chargeid,balanced_balesid,usd_value,zim_value) VALUES ($userid,{$ch['id']},$bbid,$ca_u,$ca_z)");$cur_usd-=$ca_u;$cur_zig-=$ca_z;}
    $iq=$conn->query("SELECT amount FROM internal_stoporders WHERE transporter_growersid=$tgid AND active=1");
    if($iq&&$iq!==true)while($so=$iq->fetch_assoc()){$amt=(float)$so['amount'];if($amt>0&&$cur_usd>0)$cur_usd-=min($amt,$cur_usd);}
    $_rbz=$conn->query("SELECT usd_percent FROM rbz_retention_rate LIMIT 1");$rbz=($_rbz&&$_rbz!==true)?$_rbz->fetch_assoc():null;
    if($rbz){$usd_split=$gross*(float)$rbz['usd_percent'];$zig_split=($gross-$usd_split)*$ex_rate_amt;$conn->query("INSERT INTO daily_totals_split_amounts (userid,balanced_balesid,usd_split_value,zim_split_value,created_at) VALUES ($userid,$bbid,$usd_split,$zig_split,'$today')");}
    if($bank_charge_row){$bc_rate=(float)$bank_charge_row['amount'];$bc_usd=max(0,$cur_usd*$bc_rate);$bc_zig=max(0,$cur_zig*$bc_rate);$conn->query("INSERT INTO balanced_bales_charges (userid,grower_chargeid,balanced_balesid,usd_value,zim_value) VALUES ($userid,{$bank_charge_row['id']},$bbid,$bc_usd,$bc_zig)");$cur_usd-=$bc_usd;$cur_zig-=$bc_zig;}
    if($cur_usd<0)$cur_usd=0; $net_zig=$cur_usd*$ex_rate_amt;
    $conn->query("INSERT INTO daily_totals_net_amount (userid,balanced_balesid,usd_net_value,zim_net_value,created_at) VALUES ($userid,$bbid,$cur_usd,$net_zig,'$today')");
    $conn->query("CREATE TABLE IF NOT EXISTS sales_batching (id INT AUTO_INCREMENT PRIMARY KEY,userid INT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->query("INSERT INTO sales_batching (userid) VALUES ($userid)");
    $batch_id=(int)$conn->insert_id?:1;
    $conn->query("UPDATE balanced_bales SET stoporder_processed=1,sale_batchid=$batch_id WHERE id=$bbid");
    $conn->query("UPDATE transporter_growers SET open_close=1 WHERE id=$tgid");
    audit_log($conn,$spid,$userid,'balanced_bales','create',$bbid,null,['tgid'=>$tgid,'gross'=>$gross,'net'=>$cur_usd]);
    echo json_encode(['ok'=>true,'bbid'=>$bbid,'batch'=>$batch_id,'gross'=>round($gross,2),'net'=>round($cur_usd,2),'net_zig'=>round($net_zig,2),'bales'=>count($bales),'msg'=>"Balanced \$".number_format($gross,2)." · Net \$".number_format($cur_usd,2)." · Batch #$batch_id"]);
    exit;
}

// ── REVERSE ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='reverse') {
    $batch=(int)($_POST['batch']??0); $rev=0;
    if($batch){$bl=$conn->query("SELECT id FROM balanced_bales WHERE sale_batchid=$batch");while($bl&&$b=$bl->fetch_assoc()){$bid=(int)$b['id'];$conn->query("DELETE FROM internal_stop_order_deducted_amounts WHERE balanced_balesid=$bid");$conn->query("DELETE FROM daily_totals_net_amount WHERE balanced_balesid=$bid");$conn->query("DELETE FROM balanced_bales_charges WHERE balanced_balesid=$bid");$conn->query("DELETE FROM daily_totals_split_amounts WHERE balanced_balesid=$bid");$conn->query("DELETE FROM daily_totals WHERE balanced_balesid=$bid");if($conn->query("DELETE FROM balanced_bales WHERE id=$bid"))$rev++;}}
    header("Location: balance.php?msg=".urlencode("Reversed batch #$batch — $rev removed.")); exit;
}

// ── PAGE DATA ──────────────────────────────────────────────────────────────
$msg = htmlspecialchars($_GET['msg'] ?? '');

$all_rows = [];
$rq = $conn->query("
    SELECT
        tg.id tgid, tg.booked, COALESCE(tg.sell_date,'$today') sell_dt,
        g.id growerid, g.grower_num,
        g.name first_name, g.surname,
        COALESCE(g.province,'') province,
        COALESCE(bl.code,'') loc_code,
        COALESCE(bl.name,'') loc_name,
        tr.name transporter_name, tr.truck_num,
        COALESCE(sp.floor_code,'VL') floor_code,
        COALESCE(MIN(bj.splitid),1) splitid,
        COUNT(DISTINCT bj.id) junus_total,
        COUNT(DISTINCT sb.id) sold_count,
        COALESCE(SUM(bm.mass),0) total_mass,
        COUNT(DISTINCT CASE WHEN UPPER(SUBSTR(TRIM(COALESCE(sb.sale_code,'')),1,1))='R'
            AND LENGTH(TRIM(COALESCE(sb.sale_code,'')))<=3 THEN sb.id END) rej_count,
        bb.id bbid,
        bb.sale_batchid batch_num,
        COALESCE(bb.printed,0) printed,
        COALESCE(dt.usd_value,0) gross_usd,
        COALESCE(dt.zim_value,0) gross_zig,
        COALESCE(dtn.usd_net_value,0) net_usd,
        COALESCE(dtn.zim_net_value,0) net_zig,
        COALESCE(dt.average_price,0) avg_price,
        COALESCE(dt.sold_bales,0) dt_sold,
        COALESCE(dt.rejected_bales,0) dt_rej,
        COALESCE(er.amount,0) exch_rate,
        COALESCE(bbc.usd_charges,0) usd_ded,
        COALESCE(bbc.zim_charges,0) zim_ded,
        COALESCE(ps_u.status,'') usd_pay_status,
        COALESCE(ps_u.payment_ref,'') usd_pay_ref,
        COALESCE(ps_z.status,'') zig_pay_status,
        COALESCE(ps_z.payment_ref,'') zig_pay_ref,
        COALESCE(gb_u.bank_name,'') fca_bank,
        COALESCE(gb_u.account_num,'') fca_acct,
        COALESCE(gb_z.bank_name,'') zw_bank,
        COALESCE(gb_z.account_num,'') zw_acct
    FROM transporter_growers tg
    JOIN growers g ON g.id=tg.growerid
    JOIN transporter tr ON tr.id=tg.transporterid
    LEFT JOIN bale_locations bl ON bl.id=tr.location_id
    LEFT JOIN selling_point sp ON sp.id=g.selling_pointid
    LEFT JOIN bale_junus bj ON bj.transporter_growersid=tg.id
    LEFT JOIN bale_mass bm ON bm.id=bj.bale_massid
    LEFT JOIN tickets tk ON tk.bale_junusid=bj.id
    LEFT JOIN sold_bales sb ON sb.ticketsid=tk.id
    LEFT JOIN balanced_bales bb ON bb.transporter_growersid=tg.id
    LEFT JOIN daily_totals dt ON dt.balanced_balesid=bb.id
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
    LEFT JOIN exchange_rate er ON er.id=dt.exchange_rateid
    LEFT JOIN (SELECT balanced_balesid,SUM(usd_value) usd_charges,SUM(zim_value) zim_charges FROM balanced_bales_charges GROUP BY balanced_balesid) bbc ON bbc.balanced_balesid=bb.id
    LEFT JOIN payment_status ps_u ON ps_u.transporter_growersid=tg.id AND ps_u.currency='USD'
    LEFT JOIN payment_status ps_z ON ps_z.transporter_growersid=tg.id AND ps_z.currency='ZIG'
    LEFT JOIN grower_banking gb_u ON gb_u.growerid=g.id AND gb_u.currency='USD'
    LEFT JOIN grower_banking gb_z ON gb_z.growerid=g.id AND gb_z.currency='ZIG'
    WHERE (g.selling_pointid=$spid OR $spid=0) AND tg.junused_bales>0
    GROUP BY tg.id,g.id,g.grower_num,g.name,g.surname,g.province,
        bl.code,bl.name,tr.name,tr.truck_num,sp.floor_code,
        bb.id,bb.sale_batchid,bb.printed,dt.usd_value,dt.zim_value,
        dtn.usd_net_value,dtn.zim_net_value,dt.average_price,dt.sold_bales,
        dt.rejected_bales,er.amount,bbc.usd_charges,bbc.zim_charges,
        ps_u.status,ps_u.payment_ref,ps_z.status,ps_z.payment_ref,
        gb_u.bank_name,gb_u.account_num,gb_z.bank_name,gb_z.account_num,
        tg.booked,tg.sell_date
    ORDER BY bb.id IS NULL DESC,
        (COUNT(DISTINCT sb.id)=COUNT(DISTINCT bj.id) AND COUNT(DISTINCT bj.id)>0) DESC,
        g.grower_num ASC
    LIMIT 1000");
if ($rq && $rq !== true) $all_rows = $rq->fetch_all(MYSQLI_ASSOC);

$total   = count($all_rows);
$bal_cnt = count(array_filter($all_rows, fn($r) => $r['bbid']));
$pend    = $total - $bal_cnt;
$t_gross = array_sum(array_column($all_rows,'gross_usd'));
$t_net   = array_sum(array_column($all_rows,'net_usd'));
$t_mass  = array_sum(array_column($all_rows,'total_mass'));

$_pso=$conn->query("SELECT COUNT(*) c FROM balanced_bales bb JOIN transporter_growers tg ON tg.id=bb.transporter_growersid JOIN growers g ON g.id=tg.growerid WHERE bb.stoporder_processed=0 AND (g.selling_pointid=$spid OR $spid=0)");
$pending_so=($_pso&&$_pso!==true)?(int)$_pso->fetch_assoc()['c']:0;

$_er_q=$conn->query("SELECT amount FROM exchange_rate ORDER BY id DESC LIMIT 1");
$er_now=($_er_q&&$_er_q!==true)?(float)$_er_q->fetch_assoc()['amount']:0;

include "includes/layout.php";
?>
<style>
.ksi{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.85rem}
.ksc{flex:1;min-width:105px;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:.55rem .8rem}
.ksl{font-size:.57rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.ksv{font-size:1.05rem;font-weight:700;line-height:1.2;margin-top:.1rem;color:var(--gold)}
.ksv.g{color:var(--leaf)}.ksv.w{color:var(--cream)}
.kss{font-size:.58rem;color:var(--muted);margin-top:.03rem}
.fbar{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;margin-bottom:.55rem}
.tpill{padding:.25rem .7rem;border-radius:20px;font-size:.7rem;cursor:pointer;border:1px solid var(--border);background:var(--card);color:var(--muted);transition:.1s}
.tpill.on{background:var(--gold);color:var(--ink);border-color:var(--gold);font-weight:600}
.bwrap{overflow-x:auto;max-height:640px;overflow-y:auto}
#bmt thead th{position:sticky;top:0;background:var(--ink);color:var(--cream);font-size:.63rem;white-space:nowrap;padding:.42rem .6rem;z-index:2;font-weight:500}
#bmt tbody tr{cursor:pointer;transition:background .1s}
#bmt tbody tr:hover{background:rgba(200,149,42,.08)!important}
#bmt tbody tr.rb{background:rgba(60,140,60,.04)}
#bmt tbody td{font-size:.7rem;padding:.4rem .6rem;white-space:nowrap}
#bmt tfoot td{font-size:.7rem;padding:.45rem .6rem;white-space:nowrap;background:var(--cream);font-weight:700;border-top:2px solid var(--border);position:sticky;bottom:0}
.sp{display:inline-flex;align-items:center;gap:.2rem;padding:.16rem .5rem;border-radius:20px;font-size:.6rem;font-weight:600;white-space:nowrap}
.sp-b{background:rgba(60,150,60,.14);color:#2a8a2a;border:1px solid rgba(60,150,60,.3)}
.sp-r{background:rgba(200,149,42,.14);color:#9a6a0a;border:1px solid rgba(200,149,42,.3)}
.sp-p{background:rgba(210,100,30,.12);color:#a05020;border:1px solid rgba(210,100,30,.3)}
.sp-n{background:rgba(190,30,30,.1);color:#991010;border:1px solid rgba(190,30,30,.25)}
#bmo{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.6);align-items:flex-start;justify-content:center;padding:1.5rem 1rem;overflow-y:auto}
#bmo.open{display:flex}
#bmb{background:var(--card);border-radius:var(--radius);width:100%;max-width:1080px;box-shadow:0 12px 50px rgba(0,0,0,.5);display:flex;flex-direction:column;animation:bmi .17s ease}
@keyframes bmi{from{opacity:0;transform:translateY(-14px)}to{opacity:1;transform:none}}
#bmh{background:var(--ink);padding:.8rem 1.1rem;border-radius:var(--radius) var(--radius) 0 0;display:flex;align-items:center;gap:.65rem;flex-wrap:wrap}
#bmf{padding:.7rem 1.1rem;border-top:2px solid var(--border);display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;background:var(--cream);border-radius:0 0 var(--radius) var(--radius)}
.ms{display:flex;flex-direction:column}
.msl{font-size:.55rem;color:rgba(245,240,232,.38);text-transform:uppercase;letter-spacing:.05em}
.msv{font-size:.86rem;font-weight:600;color:var(--gold)}
.msv.g{color:#6ec97a}.msv.r{color:#f08080}.msv.w{color:var(--cream)}
.ec{cursor:pointer}.ec:hover{background:rgba(200,149,42,.14)!important}
.ep{font-size:.48rem;color:var(--gold);margin-left:3px;opacity:.7}
</style>

<div class="page-header">
    <div class="page-header-left">
        <h2>Balance &amp; Settlement</h2>
        <p><?= $total ?> grower slots &nbsp;·&nbsp; <?= $bal_cnt ?> balanced &nbsp;·&nbsp; <?= $pend ?> pending</p>
    </div>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
        <?php if($pending_so>0):?>
        <form method="POST" onsubmit="return confirm('Run stop orders?')">
            <input type="hidden" name="action" value="run_so">
            <button class="btn btn-primary btn-sm">▶ Run Stop Orders (<?=$pending_so?>)</button>
        </form>
        <?php endif;?>
        <a href="grower_booking.php" class="btn btn-ghost btn-sm">📋 Booking</a>
        <a href="sales_sheets.php"   class="btn btn-ghost btn-sm">📄 Sales Sheets</a>
        <button onclick="doExport()" class="btn btn-ghost btn-sm">⬇ Export CSV</button>
    </div>
</div>
<?php if($msg):?><div class="alert alert-success"><?=$msg?></div><?php endif;?>

<div class="ksi">
    <div class="ksc"><div class="ksl">Growers</div><div class="ksv w"><?=$total?></div><div class="kss"><?=$bal_cnt?> balanced · <?=$pend?> pending</div></div>
    <div class="ksc"><div class="ksl">Bales / Mass</div><div class="ksv w"><?=number_format(array_sum(array_column($all_rows,'junus_total')))?></div><div class="kss"><?=number_format($t_mass,0)?> kg</div></div>
    <div class="ksc"><div class="ksl">Gross USD</div><div class="ksv">$<?=number_format($t_gross,2)?></div><div class="kss">balanced batches</div></div>
    <div class="ksc"><div class="ksl">Net USD</div><div class="ksv g">$<?=number_format($t_net,2)?></div><div class="kss">after deductions</div></div>
    <div class="ksc"><div class="ksl">Avg $/kg</div><div class="ksv"><?=$t_mass>0?'$'.number_format($t_gross/$t_mass,4):'—'?></div><div class="kss">floor average</div></div>
    <div class="ksc"><div class="ksl">Rate ZiG/USD</div><div class="ksv w"><?=$er_now>0?number_format($er_now,2):'—'?></div><div class="kss">current rate</div></div>
</div>

<div class="fbar">
    <input type="text" id="srch" class="form-control" placeholder="Search grower, transporter…" style="flex:1;min-width:150px;max-width:260px;font-size:.8rem" autocomplete="off">
    <button class="tpill on" id="tab-all"      onclick="setTab('all')">All <b id="c-all"><?=$total?></b></button>
    <button class="tpill"    id="tab-pending"  onclick="setTab('pending')">🔴 Pending <b id="c-pending"><?=$pend?></b></button>
    <button class="tpill"    id="tab-ready"    onclick="setTab('ready')">🟡 Ready <b id="c-ready">0</b></button>
    <button class="tpill"    id="tab-balanced" onclick="setTab('balanced')">🟢 Balanced <b id="c-balanced"><?=$bal_cnt?></b></button>
    <span id="rinfo" style="font-size:.67rem;color:var(--muted)"></span>
</div>

<div class="card"><div class="bwrap"><table id="bmt">
<thead><tr>
<th></th><th>Batch No.</th><th>Grower No.</th><th>First Name</th><th>Surname</th>
<th>Area</th><th>Province</th><th>Floor Code</th><th>Sale Date</th><th>Sale</th>
<th>S/Status</th><th>GDN Status</th>
<th class="text-right">Paid Bales</th><th class="text-right">Paid Mass</th>
<th class="text-right">Rejected Bales</th><th class="text-right">US Value</th>
<th class="text-right">USD Deductions</th><th class="text-right">USD Service Charge</th>
<th class="text-right">USD Paid</th><th>US Pay</th><th>USD Payment Ref</th>
<th class="text-right">RTGS Value</th><th class="text-right">ZW Deductions</th>
<th class="text-right">RTGS Service Charge</th><th class="text-right">ZW Paid</th>
<th>ZW Pay</th><th>ZWG Payment Ref</th>
<th>FCA Bank</th><th>FCA Account Number</th><th>ZW Bank</th><th>ZW Account Number</th>
<th class="text-right">Recon</th><th>Printed</th><th>Released</th><th>Cloud Pay</th><th></th>
</tr></thead>
<tbody>
<?php
$ready_cnt=0;
foreach($all_rows as $r):
    $isbal=!empty($r['bbid']);
    $asold=(int)$r['sold_count']===(int)$r['junus_total']&&(int)$r['junus_total']>0;
    $bkd=(int)($r['booked']??0)===1;
    $pct=$r['junus_total']>0?round($r['sold_count']/$r['junus_total']*100):0;
    $gdn=$isbal?'processed':($r['sold_count']>0?'selling':'laid');
    $sstat=$isbal?'Processed':'Draft';
    if($isbal){$tab='balanced';$dot='🟢';$pill='<span class="sp sp-b">✓ Balanced</span>';}
    elseif($asold&&$bkd){$tab='ready';$ready_cnt++;$dot='🟡';$pill='<span class="sp sp-r">⚡ Ready</span>';}
    elseif(!$bkd){$tab='pending';$dot='🔴';$pill='<span class="sp sp-n">⚠ Not Booked</span>';}
    else{$tab='pending';$dot='🔴';$pill='<span class="sp sp-p">'.$pct.'% Sold</span>';}
    $usp=$r['usd_pay_status']==='paid'?'Paid':($r['usd_pay_status']?ucfirst($r['usd_pay_status']):'0');
    $zwp=$r['zig_pay_status']==='paid'?'Paid':($r['zig_pay_status']?ucfirst($r['zig_pay_status']):'0');
    $rel=$r['printed']?$r['sell_dt']:'';
    $usd_svc_chg=$isbal?number_format((float)$r['gross_usd']-(float)$r['net_usd']-(float)$r['usd_ded'],2):'0.0';
    $zig_svc_chg=$isbal?number_format((float)$r['gross_zig']-(float)$r['net_zig']-(float)$r['zim_ded'],2):'0.0';
    $srch=strtolower(htmlspecialchars($r['grower_num'].' '.$r['first_name'].' '.$r['surname'].' '.$r['transporter_name'].' '.$r['loc_name'].' '.$r['province'],ENT_QUOTES));
?>
<tr class="<?=$isbal?'rb':''?>" data-tgid="<?=$r['tgid']?>" data-split="<?=$r['splitid']?>" data-tab="<?=$tab?>" data-s="<?=$srch?>" onclick="openModal(<?=$r['tgid']?>,<?=$r['splitid']?>)">
<td style="text-align:center"><?=$dot?></td>
<td><?=$r['batch_num']?'<span class="badge badge-blue" style="font-size:.6rem">'.$r['batch_num'].'</span>':'<span style="color:var(--muted)">—</span>'?></td>
<td><span class="fw-500" style="color:var(--gold)"><?=htmlspecialchars($r['grower_num'])?></span></td>
<td><?=htmlspecialchars($r['first_name'])?></td>
<td><?=htmlspecialchars($r['surname'])?></td>
<td style="font-size:.67rem"><?=htmlspecialchars($r['loc_name']?:$r['loc_code'])?></td>
<td style="font-size:.67rem"><?=htmlspecialchars($r['province'])?></td>
<td><span class="badge badge-gray" style="font-size:.58rem"><?=htmlspecialchars($r['floor_code'])?></span></td>
<td><?=$r['sell_dt']?></td>
<td class="td-mono">1</td>
<td><?=$pill?></td>
<td style="font-size:.67rem;color:var(--muted)"><?=$gdn?></td>
<td class="td-mono text-right fw-500"><?=$isbal?(int)$r['dt_sold']:(int)$r['sold_count']?></td>
<td class="td-mono text-right"><?=number_format((float)$r['total_mass'],0)?></td>
<td class="td-mono text-right"><?=$isbal?(int)$r['dt_rej']:(int)$r['rej_count']?></td>
<td class="td-mono text-right fw-500"><?=$isbal?'$'.number_format((float)$r['gross_usd'],2):'—'?></td>
<td class="td-mono text-right"><?=$isbal?number_format((float)$r['usd_ded'],2):'0.0'?></td>
<td class="td-mono text-right"><?=$isbal?$usd_svc_chg:'0.0'?></td>
<td class="td-mono text-right" style="color:var(--leaf);font-weight:600"><?=$isbal?number_format((float)$r['net_usd'],2):'0.0'?></td>
<td style="font-size:.67rem"><?=$usp?></td>
<td style="font-size:.63rem;color:var(--muted)"><?=htmlspecialchars($r['usd_pay_ref'])?></td>
<td class="td-mono text-right"><?=$isbal?number_format((float)$r['gross_zig'],2):'0.0'?></td>
<td class="td-mono text-right"><?=$isbal?number_format((float)$r['zim_ded'],2):'0.0'?></td>
<td class="td-mono text-right"><?=$isbal?$zig_svc_chg:'0.0'?></td>
<td class="td-mono text-right" style="color:var(--leaf);font-weight:600"><?=$isbal?number_format((float)$r['net_zig'],2):'0.0'?></td>
<td style="font-size:.67rem"><?=$zwp?></td>
<td style="font-size:.63rem;color:var(--muted)"><?=htmlspecialchars($r['zig_pay_ref'])?></td>
<td style="font-size:.65rem"><?=htmlspecialchars($r['fca_bank'])?></td>
<td style="font-size:.63rem;font-family:monospace"><?=htmlspecialchars($r['fca_acct'])?></td>
<td style="font-size:.65rem"><?=htmlspecialchars($r['zw_bank'])?></td>
<td style="font-size:.63rem;font-family:monospace"><?=htmlspecialchars($r['zw_acct'])?></td>
<td class="td-mono text-right"><?=$isbal?number_format((float)$r['net_usd']-(float)$r['gross_usd'],2):'0.0'?></td>
<td class="td-mono text-right"><?=$r['printed']?1:0?></td>
<td style="font-size:.67rem"><?=$rel?></td>
<td class="td-mono text-right">0</td>
<td onclick="event.stopPropagation()" style="text-align:center">
<?php if($isbal):?><a href="print_sheet.php?bbid=<?=$r['bbid']?>" target="_blank" class="btn btn-ghost btn-sm" style="font-size:.58rem;padding:2px 5px" title="Print">🖨</a><?php endif;?>
</td>
</tr>
<?php endforeach;?>
<?php if(empty($all_rows)):?><tr><td colspan="36" class="text-center text-muted" style="padding:2.5rem">No grower slots found.</td></tr><?php endif;?>
</tbody>
<tfoot><tr>
<td colspan="12"><strong id="tfl">TOTALS</strong></td>
<td class="td-mono text-right" id="tf1"><?=array_sum(array_column(array_filter($all_rows,fn($r)=>$r['bbid']),'dt_sold'))?></td>
<td class="td-mono text-right" id="tf2"><?=number_format($t_mass,0)?></td>
<td class="td-mono text-right" id="tf3"><?=array_sum(array_column(array_filter($all_rows,fn($r)=>$r['bbid']),'dt_rej'))?></td>
<td class="td-mono text-right" id="tf4">$<?=number_format($t_gross,2)?></td>
<td class="td-mono text-right" id="tf5"><?=number_format(array_sum(array_column($all_rows,'usd_ded')),2)?></td>
<td class="td-mono text-right" id="tf6">—</td>
<td class="td-mono text-right" id="tf7" style="color:var(--leaf)">$<?=number_format($t_net,2)?></td>
<td colspan="2"></td>
<td class="td-mono text-right" id="tf8"><?=number_format(array_sum(array_column($all_rows,'gross_zig')),2)?></td>
<td class="td-mono text-right" id="tf9"><?=number_format(array_sum(array_column($all_rows,'zim_ded')),2)?></td>
<td class="td-mono text-right" id="tf10">—</td>
<td class="td-mono text-right" id="tf11" style="color:var(--leaf)"><?=number_format(array_sum(array_column($all_rows,'net_zig')),2)?></td>
<td colspan="8"></td>
</tr></tfoot>
</table></div></div>

<!-- MODAL -->
<div id="bmo" onclick="if(event.target===this)closeModal()">
<div id="bmb">
<div id="bmh">
    <div style="flex:1">
        <div style="font-family:'Bebas Neue',sans-serif;font-size:1.1rem;color:var(--gold);letter-spacing:.08em" id="m-gn">—</div>
        <div style="font-size:.66rem;color:rgba(245,240,232,.43)" id="m-gs">—</div>
    </div>
    <div id="m-st" style="display:flex;gap:.8rem;flex-wrap:wrap"></div>
    <div id="m-sp" style="display:flex;gap:.3rem"></div>
    <button onclick="closeModal()" style="background:none;border:none;color:rgba(245,240,232,.4);font-size:1.2rem;cursor:pointer;padding:.2rem .45rem">✕</button>
</div>
<div id="m-eh" style="display:none;background:rgba(200,149,42,.07);padding:.3rem 1.1rem;font-size:.65rem;color:var(--muted);border-bottom:1px solid var(--border)">✏ Click TIMB Grade, Buyer Grade or Price to edit — saves instantly</div>
<div id="m-ld" style="text-align:center;padding:3rem"><div style="font-size:1.6rem">⚖</div><div style="color:var(--muted);font-size:.8rem;margin-top:.5rem">Loading bales…</div></div>
<div id="m-bd" style="display:none">
<div style="overflow-x:auto;max-height:400px;overflow-y:auto">
<table style="width:100%">
<thead><tr style="background:var(--ink);color:var(--cream)">
<th style="padding:.38rem .6rem;font-size:.62rem">#</th>
<th style="padding:.38rem .6rem;font-size:.62rem">Barcode</th>
<th style="padding:.38rem .6rem;font-size:.62rem">Scale BC</th>
<th style="padding:.38rem .6rem;font-size:.62rem">TIMB Grade</th>
<th style="padding:.38rem .6rem;font-size:.62rem">Buyer / Grade</th>
<th style="padding:.38rem .6rem;font-size:.62rem;text-align:right">Mass kg</th>
<th style="padding:.38rem .6rem;font-size:.62rem;text-align:right">$/kg</th>
<th style="padding:.38rem .6rem;font-size:.62rem;text-align:right">Value USD</th>
<th style="padding:.38rem .6rem;font-size:.62rem">Status</th>
</tr></thead>
<tbody id="m-bb"></tbody>
<tfoot><tr style="background:var(--cream);font-weight:700;border-top:2px solid var(--border)">
<td colspan="5" id="m-fl" style="padding:.38rem .6rem;font-size:.7rem"></td>
<td id="m-fm" style="padding:.38rem .6rem;font-size:.7rem;text-align:right;font-family:monospace"></td>
<td></td>
<td id="m-fv" style="padding:.38rem .6rem;font-size:.7rem;text-align:right;font-family:monospace"></td>
<td></td>
</tr></tfoot>
</table>
</div>
</div>
<div id="bmf"><div id="m-ac" style="flex:1;display:flex;align-items:center;gap:.65rem;flex-wrap:wrap"></div></div>
</div>
</div>

<script>
var TG=[],BG=[],AT=0,AS=0,AG=0;
function e(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// Filter
var aTab='all';
document.getElementById('srch').addEventListener('input',applyF);
function setTab(t){aTab=t;['all','pending','ready','balanced'].forEach(function(x){document.getElementById('tab-'+x).classList.toggle('on',x===t);});applyF();}
function applyF(){
    var q=document.getElementById('srch').value.trim().toLowerCase();
    var rows=document.querySelectorAll('#bmt tbody tr'),vis=0;
    rows.forEach(function(r){var ok=(aTab==='all'||r.dataset.tab===aTab)&&(!q||(r.dataset.s||'').includes(q));r.style.display=ok?'':'none';if(ok)vis++;});
    document.getElementById('rinfo').textContent=vis+' row'+(vis===1?'':'s');
    calcTotals();
}
function calcTotals(){
    var rows=document.querySelectorAll('#bmt tbody tr'),b=0,m=0,rj=0,g=0,ud=0,un=0,zv=0,zd=0,zn=0;
    rows.forEach(function(r){
        if(r.style.display==='none')return;
        var c=r.querySelectorAll('td');
        b+=parseInt(c[12]?.textContent||0)||0;
        m+=parseFloat(c[13]?.textContent||0)||0;
        rj+=parseInt(c[14]?.textContent||0)||0;
        g+=parseFloat((c[15]?.textContent||'').replace(/[$,]/g,''))||0;
        ud+=parseFloat(c[16]?.textContent||0)||0;
        un+=parseFloat(c[18]?.textContent||0)||0;
        zv+=parseFloat(c[21]?.textContent||0)||0;
        zd+=parseFloat(c[22]?.textContent||0)||0;
        zn+=parseFloat(c[24]?.textContent||0)||0;
    });
    document.getElementById('tf1').textContent=b;
    document.getElementById('tf2').textContent=m.toFixed(0);
    document.getElementById('tf3').textContent=rj;
    document.getElementById('tf4').textContent='$'+g.toFixed(2);
    document.getElementById('tf5').textContent=ud.toFixed(2);
    document.getElementById('tf7').textContent='$'+un.toFixed(2);
    document.getElementById('tf8').textContent=zv.toFixed(2);
    document.getElementById('tf9').textContent=zd.toFixed(2);
    document.getElementById('tf11').textContent=zn.toFixed(2);
    var vis=document.querySelectorAll('#bmt tbody tr:not([style*="none"])').length;
    document.getElementById('tfl').textContent='TOTALS ('+vis+')';
}
applyF();
(function(){var r=document.querySelectorAll('#bmt tbody tr[data-tab="ready"]').length;document.getElementById('c-ready').textContent=r;})();

// CSV export
function doExport(){
    var hh=[],lines=[];
    document.querySelectorAll('#bmt thead th').forEach(function(th){hh.push('"'+(th.textContent.trim()||'').replace(/"/g,'""')+'"');});
    lines.push(hh.join(','));
    document.querySelectorAll('#bmt tbody tr').forEach(function(row){
        if(row.style.display==='none')return;
        var cc=[];
        row.querySelectorAll('td').forEach(function(td){cc.push('"'+td.textContent.trim().replace(/"/g,'""')+'"');});
        lines.push(cc.join(','));
    });
    var a=document.createElement('a');a.href=URL.createObjectURL(new Blob([lines.join('\n')],{type:'text/csv'}));
    a.download='Sales_Master_<?=date('Ymd')?>.csv';a.click();
}

// Modal open
function openModal(tgid,split){
    split=parseInt(split)||0;AT=tgid;AS=split;
    document.getElementById('bmo').classList.add('open');
    document.getElementById('m-ld').style.display='block';
    document.getElementById('m-bd').style.display='none';
    document.getElementById('m-eh').style.display='none';
    document.getElementById('m-st').innerHTML='';
    document.getElementById('m-sp').innerHTML='';
    document.getElementById('m-ac').innerHTML='';
    document.getElementById('m-gn').textContent='…';
    document.getElementById('m-gs').textContent='';
    fetch('balance.php?ajax=panel&tgid='+tgid+'&split='+split,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json())
    .then(function(d){
        document.getElementById('m-ld').style.display='none';
        if(d.error){document.getElementById('m-ac').innerHTML='<div class="alert alert-danger">'+e(d.error)+'</div>';return;}
        TG=d.timb_grades||[];BG=d.buyer_grades||[];AG=parseInt(d.grower.growerid);
        renderModal(d);
    })
    .catch(function(err){document.getElementById('m-ld').style.display='none';document.getElementById('m-ac').innerHTML='<div class="alert alert-danger">Load failed: '+e(err.message)+'</div>';});
}
function closeModal(){document.getElementById('bmo').classList.remove('open');}
document.addEventListener('keydown',function(ev){if(ev.key==='Escape')closeModal();});

// Render modal
function renderModal(d){
    var g=d.grower,s=d.stats,bales=d.bales,warn=d.booking_warn||'',bbId=d.already_balanced;
    document.getElementById('m-gn').textContent=g.grower_num;
    document.getElementById('m-gs').textContent=g.name+' '+g.surname+' · '+g.transporter_name+(g.loc_code?' · Loc '+g.loc_code:'');
    var bBadge=warn==='NOT_BOOKED'?'<span class="sp sp-n">⚠ Not Booked</span>':warn.startsWith('MISMATCH')?function(){var pts=warn.split(':');return'<span class="sp sp-n">⚠ Mismatch '+pts[1]+'/'+pts[2]+'</span>';}():'<span class="sp sp-b">✓ Booked ('+d.bales_booked+')</span>';
    document.getElementById('m-st').innerHTML='<div class="ms"><span class="msl">Bales</span><span class="msv '+(s.unsold>0?'r':'g')+'">'+s.sold_cnt+'/'+s.total_bales+'</span></div><div class="ms"><span class="msl">Mass</span><span class="msv w">'+parseFloat(s.total_mass).toFixed(1)+' kg</span></div><div class="ms"><span class="msl">Gross</span><span class="msv">$'+parseFloat(s.gross).toFixed(2)+'</span></div>'+bBadge;
    if(d.splits.length>1)document.getElementById('m-sp').innerHTML=d.splits.map(function(sp){return'<button onclick="openModal('+AT+','+sp.splitid+')" class="btn btn-sm '+(sp.splitid==d.current_split?'btn-gold':'btn-ghost')+'" style="font-size:.6rem;padding:2px 8px">Split '+sp.splitid+' ('+sp.cnt+')</button>';}).join('');
    if(!bbId)document.getElementById('m-eh').style.display='block';
    var rm=0,rv=0,seq=0;
    document.getElementById('m-bb').innerHTML=bales.map(function(b){
        seq++;var p=parseFloat(b.price||0);if(p>10)p/=100;var mass=parseFloat(b.mass||0),val=b.sold_id?mass*p:0;rm+=mass;rv+=val;
        var sbid=parseInt(b.sold_id||0),isRej=b.sale_code&&b.sale_code.toUpperCase()[0]==='R'&&b.sale_code.length<=3,can=!!(sbid&&!isRej&&!bbId);
        var ep=can?'<span class="ep">✏</span>':'';
        var th=b.timb_grade?'<span class="badge badge-gold" style="font-size:.56rem">'+e(b.timb_grade)+'</span>':'<span class="td-muted">—</span>';
        var bh=b.buyer_grade?'<span class="badge badge-gray" style="font-size:.56rem">'+e(b.buyer_grade)+'</span>':'<span class="td-muted">—</span>';
        var bn=b.buyer_name?'<div style="font-size:.55rem;color:var(--muted)">'+e(b.buyer_name)+'</div>':'';
        var st=isRej?'<span class="badge badge-red" style="font-size:.52rem">Rejected</span>':b.sold_id?'<span class="badge badge-green" style="font-size:.52rem">Sold</span>':'<span class="badge badge-gray" style="font-size:.52rem">Unsold</span>';
        var tc=can?'id="t-'+sbid+'" class="ec" onclick="editG(\'t\','+sbid+','+parseInt(b.timb_gradesid||0)+')"':'';
        var bc=can?'id="bg-'+sbid+'" class="ec" onclick="editG(\'b\','+sbid+','+parseInt(b.buyer_gradesid||0)+')"':'id="bg-'+sbid+'"';
        var pc=can?'id="p-'+sbid+'" class="td-mono text-right ec" onclick="editP('+sbid+','+p.toFixed(4)+')"':'class="td-mono text-right"';
        return'<tr id="br-'+sbid+'" style="'+(isRej?'opacity:.5':!b.sold_id?'background:#fff8ee':'')+'"><td style="font-size:.65rem;color:var(--muted);padding:.36rem .6rem">'+seq+'</td><td style="padding:.36rem .6rem"><code style="font-size:.65rem">'+e(b.barcode||'—')+'</code></td><td style="padding:.36rem .6rem"><code style="font-size:.58rem;color:var(--muted)">'+e(b.timb_bc||'—')+'</code></td><td style="padding:.36rem .6rem" '+tc+'>'+th+ep+'</td><td style="padding:.36rem .6rem" '+bc+'>'+bh+bn+ep+'</td><td class="td-mono text-right" style="padding:.36rem .6rem">'+mass.toFixed(1)+'</td><td '+pc+' style="padding:.36rem .6rem">'+(sbid?'$'+p.toFixed(4):'<span class="td-muted">—</span>')+ep+'</td><td class="td-mono text-right" style="padding:.36rem .6rem" id="v-'+sbid+'">'+(val>0?'<span class="fw-500">$'+val.toFixed(2)+'</span>':'<span class="td-muted">—</span>')+'</td><td style="padding:.36rem .6rem">'+st+'</td></tr>';
    }).join('')||'<tr><td colspan="9" class="text-center text-muted" style="padding:2rem">No bales.</td></tr>';
    document.getElementById('m-fl').innerHTML=seq+' bales · '+s.sold_cnt+' sold'+(s.unsold>0?' · <span style="color:var(--danger)">'+s.unsold+' unsold</span>':'');
    document.getElementById('m-fm').textContent=rm.toFixed(1)+' kg';
    document.getElementById('m-fv').textContent='$'+rv.toFixed(2);
    document.getElementById('m-bd').style.display='block';
    var ac=document.getElementById('m-ac');
    if(bbId){ac.innerHTML='<div style="flex:1"><div class="fw-500" style="color:var(--leaf)">✓ Already balanced</div><div style="font-size:.68rem;color:var(--muted)">Gross $'+parseFloat(s.gross).toFixed(2)+'</div></div><a href="print_sheet.php?bbid='+bbId+'" target="_blank" class="btn btn-primary btn-sm">🖨 Print</a><button onclick="closeModal()" class="btn btn-ghost btn-sm">Close</button>';}
    else if(warn==='NOT_BOOKED'){ac.innerHTML='<div style="flex:1"><div style="font-size:.8rem;font-weight:600;color:var(--danger)">⛔ Not Booked</div><div style="font-size:.68rem"><a href="grower_booking.php" style="color:var(--gold)">Go to Grower Booking →</a></div></div><button onclick="closeModal()" class="btn btn-ghost btn-sm">Close</button>';}
    else if(warn.startsWith('MISMATCH')){var pts=warn.split(':');ac.innerHTML='<div style="flex:1"><div style="font-size:.8rem;font-weight:600;color:var(--danger)">⛔ Mismatch — booked: '+pts[1]+', received: '+pts[2]+'</div></div><button onclick="closeModal()" class="btn btn-ghost btn-sm">Close</button>';}
    else if(s.unsold>0){ac.innerHTML='<div style="flex:1"><div style="font-size:.8rem;font-weight:500;color:var(--danger)">'+s.unsold+' bale(s) not yet sold</div><div style="font-size:.68rem"><a href="selling.php" style="color:var(--gold)">Go to Selling →</a></div></div><button onclick="closeModal()" class="btn btn-ghost btn-sm">Close</button>';}
    else{ac.innerHTML='<div style="flex:1"><div style="font-size:.82rem;font-weight:600;color:var(--leaf)">✓ All '+s.total_bales+' bales sold · ready</div><div style="font-size:.68rem;color:var(--muted)">Gross $'+parseFloat(s.gross).toFixed(2)+' · '+parseFloat(s.total_mass).toFixed(1)+' kg · Booked: '+d.bales_booked+'</div></div><button id="bb-btn" class="btn btn-gold" style="padding:.58rem 1.4rem;font-size:.88rem" onclick="doBalance('+AG+')">✓ Confirm &amp; Balance</button><button onclick="closeModal()" class="btn btn-ghost btn-sm">Cancel</button>';}
}

// Balance
function doBalance(gid){
    if(!confirm('Balance this grower?\n\nCalculates net payout, applies deductions, creates sales sheet.\nCannot be undone without reversal.'))return;
    var btn=document.getElementById('bb-btn');if(btn){btn.disabled=true;btn.textContent='Processing…';}
    var fd=new FormData();fd.append('action','balance');fd.append('tgid',AT);fd.append('splitid',AS||1);fd.append('growerid',gid);
    fetch('balance.php?ajax=balance',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json())
    .then(function(d){
        if(d.ok){
            if(typeof fw!=='undefined')fw.toast('✓ '+d.msg,'success');
            document.getElementById('m-ac').innerHTML='<div style="flex:1"><div class="fw-500" style="color:var(--leaf)">✓ Balanced — Batch #'+d.batch+'</div><div style="font-size:.7rem;color:var(--muted)">Gross $'+parseFloat(d.gross).toFixed(2)+' · Net $'+parseFloat(d.net).toFixed(2)+' · ZiG '+parseFloat(d.net_zig).toFixed(2)+'</div></div><a href="print_sheet.php?bbid='+d.bbid+'" target="_blank" class="btn btn-primary btn-sm">🖨 Print Sheet</a><button onclick="closeModal()" class="btn btn-ghost btn-sm">Close</button>';
            var row=document.querySelector('#bmt tbody tr[data-tgid="'+AT+'"]');
            if(row){row.classList.add('rb');row.dataset.tab='balanced';var c=row.querySelectorAll('td');if(c[0])c[0].textContent='🟢';if(c[1])c[1].innerHTML='<span class="badge badge-blue" style="font-size:.6rem">'+d.batch+'</span>';if(c[10])c[10].innerHTML='<span class="sp sp-b">✓ Balanced</span>';if(c[11])c[11].textContent='processed';if(c[15])c[15].innerHTML='<span class="fw-500">$'+parseFloat(d.gross).toFixed(2)+'</span>';if(c[18])c[18].innerHTML='<span class="fw-500" style="color:var(--leaf)">'+parseFloat(d.net).toFixed(2)+'</span>';var pc=row.querySelector('td:last-child');if(pc)pc.innerHTML='<a href="print_sheet.php?bbid='+d.bbid+'" target="_blank" class="btn btn-ghost btn-sm" style="font-size:.58rem;padding:2px 5px">🖨</a>';applyF();}
        }else{if(typeof fw!=='undefined')fw.toast(d.error||'Failed','error');if(btn){btn.disabled=false;btn.textContent='✓ Confirm & Balance';}}
    }).catch(function(err){if(typeof fw!=='undefined')fw.toast('Error: '+err.message,'error');if(btn){btn.disabled=false;btn.textContent='✓ Confirm & Balance';}});
}

// Edit grade
function editG(type,sbid,cid){
    if(!sbid)return;var cell=document.getElementById((type==='t'?'t':'bg')+'-'+sbid);if(!cell||cell.querySelector('select'))return;
    var grades=type==='t'?TG:BG;var sel=document.createElement('select');sel.className='form-control';sel.style.cssText='font-size:.7rem;padding:2px 4px;min-width:130px';sel.innerHTML='<option value="">—</option>';
    grades.forEach(function(g){var o=document.createElement('option');o.value=g.id;o.textContent=g.description+(g.buyer_name?' ('+g.buyer_name+')':'');if(parseInt(g.id)===parseInt(cid))o.selected=true;sel.appendChild(o);});
    sel.onchange=function(){if(!sel.value)return;var fd=new FormData();fd.append('sold_id',sbid);fd.append(type==='t'?'timb_gradesid':'buyer_gradesid',sel.value);saveEdit(sbid,fd);};
    sel.onblur=function(){if(!sel.value)openModal(AT,AS);};
    cell.innerHTML='';cell.appendChild(sel);sel.focus();
}
// Edit price
function editP(sbid,cur){
    if(!sbid)return;var cell=document.getElementById('p-'+sbid);if(!cell||cell.querySelector('input'))return;
    var inp=document.createElement('input');inp.type='number';inp.step='0.0001';inp.min='0';inp.value=parseFloat(cur).toFixed(4);inp.className='form-control';inp.style.cssText='font-size:.7rem;padding:2px 4px;width:85px;text-align:right';
    var sv=function(){if(parseFloat(inp.value)<=0){openModal(AT,AS);return;}var fd=new FormData();fd.append('sold_id',sbid);fd.append('price',inp.value);saveEdit(sbid,fd);};
    inp.onblur=sv;inp.onkeydown=function(ev){if(ev.key==='Enter')sv();if(ev.key==='Escape')openModal(AT,AS);};
    cell.innerHTML='';cell.appendChild(inp);inp.focus();inp.select();
}
// Save edit
function saveEdit(sbid,fd){
    fetch('balance.php?ajax=update_bale',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json())
    .then(function(d){
        if(d.ok){var row=d.row;var p=parseFloat(row.price||0);if(p>10)p/=100;
            var tc=document.getElementById('t-'+sbid);if(tc){tc.innerHTML='<span class="badge badge-gold" style="font-size:.56rem">'+(row.timb_grade||'—')+'</span><span class="ep">✏</span>';tc.onclick=function(){editG('t',sbid,row.timb_gradesid);};}
            var bc=document.getElementById('bg-'+sbid);if(bc){bc.innerHTML='<span class="badge badge-gray" style="font-size:.56rem">'+(row.buyer_grade||'—')+'</span>'+(row.buyer_name?'<div style="font-size:.55rem;color:var(--muted)">'+e(row.buyer_name)+'</div>':'')+'<span class="ep">✏</span>';bc.onclick=function(){editG('b',sbid,row.buyer_gradesid);};}
            var pc=document.getElementById('p-'+sbid);if(pc){pc.innerHTML='$'+p.toFixed(4)+'<span class="ep">✏</span>';pc.onclick=function(){editP(sbid,p);};}
            var brow=document.getElementById('br-'+sbid);var mass=brow?parseFloat(brow.querySelectorAll('td')[5]?.textContent||0):0;var vc=document.getElementById('v-'+sbid);if(vc&&mass>0)vc.innerHTML='<span class="fw-500">$'+(mass*p).toFixed(2)+'</span>';
            if(typeof fw!=='undefined')fw.toast('Saved ✓','success');
        }else{if(typeof fw!=='undefined')fw.toast(d.error||'Failed','error');}
    }).catch(function(err){if(typeof fw!=='undefined')fw.toast('Error: '+err.message,'error');});
}
</script>
<?php include "includes/layout_end.php"; ?>
BALANCE_PHP;

// Write the file
$result = file_put_contents($target, $new_content);

echo "<div style='font-family:monospace;font-size:14px;padding:20px;background:#1a2a1a;color:#7ec97e;border-radius:8px'>";
if ($result === false) {
    echo "<span style='color:#f08080'>❌ FAILED to write balance.php<br>";
    echo "Path: $target<br>";
    echo "Check folder permissions.</span>";
} else {
    $kb = round($result / 1024, 1);
    echo "✓ balance.php updated successfully!<br>";
    echo "✓ Written: {$kb} KB ({$result} bytes)<br>";
    echo "✓ Path: $target<br><br>";
    echo "<span style='color:#c8952a'>➜ Now visit: <a href='balance.php' style='color:#e8c87a'>balance.php</a></span><br><br>";
    echo "<span style='color:#aaa;font-size:12px'>Delete this file after use: update_balance.php</span>";
}
echo "</div>";
?>
