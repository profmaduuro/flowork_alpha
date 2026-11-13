<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "pay_schema.php";
// Same payment identity rules as payments.php: releasing a batch here must give
// each payment its reference and link it to the sales sheets it settles.
require_once "includes/payment_refs.php";
require_login();
$page_title = "Payment Batches"; $active_page = "pay_batches";
$userid = current_userid();
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
pay_schema($conn, $spid);
$my_role = pay_role($conn, $userid);
$msg = $err = '';

$PAYMENT_TYPES = [
    'Cash'=>'💵','Nedbank Transfer'=>'🏦','FBC Bank Transfer'=>'🏦','BancABC Transfer'=>'🏦',
    'CBZ Transfer'=>'🏦','Stanbic Transfer'=>'🏦','Other Bank Transfer'=>'🏦',
    'EcoCash'=>'📱','Mukuru'=>'📱','InnBucks'=>'📱','RTGS'=>'⚡','Cheque'=>'📝',
];

$date_f   = $conn->real_escape_string($_GET['date'] ?? date('Y-m-d'));
$view_bid = (int)($_GET['bid'] ?? 0);
$export   = $_GET['export'] ?? '';
$export_bid = (int)($_GET['ebid'] ?? $view_bid);

// ── Is day closed? ────────────────────────────────────────────────────────
$day_closed = $conn->query("SELECT id FROM payment_day_close WHERE sell_date='$date_f' AND selling_pointid=$spid LIMIT 1")->num_rows > 0;

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$day_closed) {
    $action = $_POST['action'] ?? '';

    // Create batch
    if ($action === 'create_batch' && pay_can($my_role,'create_batch')) {
        $method   = $conn->real_escape_string(trim($_POST['batch_method'] ?? ''));
        $ref      = $conn->real_escape_string(trim($_POST['batch_ref']    ?? ''));
        $notes    = $conn->real_escape_string(trim($_POST['batch_notes']  ?? ''));
        $currency = $_POST['currency'] === 'zig' ? 'zig' : 'usd';
        $tgids    = array_filter(array_map('intval', $_POST['batch_tgids'] ?? []));
        if (!$method || empty($tgids)) { $err = "Payment method and at least one grower required."; }
        else {
            $conn->query("INSERT INTO payment_batch (sell_date,currency,payment_method,batch_ref,notes,created_by,status) VALUES ('$date_f','$currency','$method','$ref','$notes',$userid,'draft')");
            $bid = (int)$conn->insert_id;
            $now = date('Y-m-d H:i:s');
            foreach ($tgids as $tgid) {
                $na = (float)($conn->query("SELECT COALESCE(SUM(dtn.usd_net_value),0) net FROM balanced_bales bb JOIN transporter_growers tg ON tg.id=bb.transporter_growersid LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id WHERE tg.id=$tgid AND DATE(bb.created_at)='$date_f' AND bb.stoporder_processed=1")->fetch_assoc()['net'] ?? 0);
                // Risk check
                $flags = pay_risk_check($conn, $tgid, $date_f, $na, $currency);
                $flags_str = $conn->real_escape_string(implode(',', $flags));
                $conn->query("INSERT INTO payment_batch_item (payment_batchid,transporter_growersid,net_amount,currency) VALUES ($bid,$tgid,$na,'$currency')");
                // Update payment_status
                $ex = $conn->query("SELECT id FROM payment_status WHERE transporter_growersid=$tgid AND sell_date='$date_f' AND currency='$currency' LIMIT 1")->fetch_assoc();
                if ($ex) {
                    $conn->query("UPDATE payment_status SET status='pending',risk_flags='$flags_str' WHERE id={$ex['id']}");
                } else {
                    $conn->query("INSERT INTO payment_status (transporter_growersid,sell_date,currency,net_amount,status,risk_flags) VALUES ($tgid,'$date_f','$currency',$na,'pending','$flags_str')");
                }
            }
            pay_log($conn,'payment_batch',$bid,$userid,'created',$currency,0,$method,$ref,"Batch of ".count($tgids)." growers created");
            $msg = "Batch #$bid created (".count($tgids)." growers). Submit for review.";
            header("Location: pay_batches.php?date=$date_f&bid=$bid&msg=".urlencode($msg)); exit;
        }
    }

    // Workflow transitions
    foreach ([
        'review'  => ['reviewed', 'review_batch',  'reviewed_by', 'reviewed_at'],
        'approve' => ['approved', 'approve_batch',  'approved_by', 'approved_at'],
        'release' => ['released', 'release_batch',  'released_by', 'released_at'],
        'lock'    => ['locked',   'lock_batch',     'released_by', 'locked_at'],
    ] as $act => [$new_status, $perm, $by_col, $at_col]) {
        if ($action === $act.'_batch' && pay_can($my_role,$perm)) {
            $bid = (int)($_POST['bid'] ?? 0);
            if (!flowork_on_floor($conn, 'pay_batch', $bid, $spid)) { $err = "Batch not found on this floor."; break; }
            $now = date('Y-m-d H:i:s');
            $conn->query("UPDATE payment_batch SET status='$new_status',$by_col=$userid,$at_col='$now' WHERE id=$bid");
            // On release: mark all items as paid in payment_status
            if ($act === 'release') {
                $items = $conn->query("SELECT pbi.transporter_growersid, pbi.balanced_balesid, pbi.net_amount,
                                              pbi.currency, pb.payment_method, pb.batch_ref
                                       FROM payment_batch_item pbi
                                       JOIN payment_batch pb ON pb.id=pbi.payment_batchid
                                       WHERE pbi.payment_batchid=$bid")->fetch_all(MYSQLI_ASSOC);
                // A grower can have several items in one batch, but gets ONE payment_status
                // row — so sum the items first. (Previously each item overwrote amount_paid,
                // leaving only the last item's figure recorded.)
                $groups = [];
                foreach ($items as $it) {
                    $k = (int)$it['transporter_growersid'].'|'.$it['currency'];
                    if (!isset($groups[$k])) $groups[$k] = [
                        'tg'    => (int)$it['transporter_growersid'],
                        'cur'   => $it['currency'],
                        'total' => 0.0,
                        'method'=> $it['payment_method'],
                        'ref'   => $it['batch_ref'],
                        'bbids' => [],
                    ];
                    $groups[$k]['total'] += (float)$it['net_amount'];
                    if (!empty($it['balanced_balesid'])) $groups[$k]['bbids'][] = (int)$it['balanced_balesid'];
                }
                foreach ($groups as $g) {
                    $tg = $g['tg']; $na = $g['total'];
                    $m  = $conn->real_escape_string($g['method']);
                    $r  = $conn->real_escape_string($g['ref']);
                    $c  = $conn->real_escape_string($g['cur']);
                    $ex = $conn->query("SELECT id FROM payment_status WHERE transporter_growersid=$tg AND sell_date='$date_f' AND currency='$c' LIMIT 1")->fetch_assoc();
                    if ($ex) {
                        $psid = (int)$ex['id'];
                        $conn->query("UPDATE payment_status SET status='released',payment_method='$m',reference_number='$r',released_by=$userid,released_at='$now',amount_paid=$na WHERE id=$psid");
                    } else {
                        $conn->query("INSERT INTO payment_status (transporter_growersid,sell_date,currency,net_amount,amount_paid,status,payment_method,reference_number,released_by,released_at) VALUES ($tg,'$date_f','$c',$na,$na,'released','$m','$r',$userid,'$now')");
                        $psid = (int)$conn->insert_id;
                    }
                    // Same identity rules as the direct-pay flow
                    if ($psid) {
                        flowork_assign_receipt($conn, $psid, $g['method']);
                        flowork_pay_ref_for($conn, $psid);
                        flowork_link_payment_sheet_ids($conn, $psid, $g['bbids']);
                    }
                }
            }
            pay_log($conn,'payment_batch',$bid,$userid,$act.'_batch');
            $msg = "Batch #$bid ".ucfirst($new_status).".";
            header("Location: pay_batches.php?date=$date_f&bid=$bid&msg=".urlencode($msg)); exit;
        }
    }
}

if (!empty($_GET['msg'])) $msg = htmlspecialchars(urldecode($_GET['msg']));

// ── Export handlers ───────────────────────────────────────────────────────
if ($export && $export_bid) {
    $bi = $conn->query("SELECT pb.*,u.username FROM payment_batch pb JOIN users u ON u.id=pb.created_by WHERE pb.id=$export_bid LIMIT 1")->fetch_assoc();
    $items = $conn->query("SELECT pbi.*,g.grower_num,CONCAT(g.name,' ',g.surname) grower_name,g.phone,g.national_id,gb.bank_name,gb.account_num,gb.account_name,gb.branch,gb.swift_code FROM payment_batch_item pbi JOIN transporter_growers tg ON tg.id=pbi.transporter_growersid JOIN growers g ON g.id=tg.growerid LEFT JOIN grower_banking gb ON gb.growerid=g.id WHERE pbi.payment_batchid=$export_bid ORDER BY g.grower_num")->fetch_all(MYSQLI_ASSOC);
    $batch_total = array_sum(array_column($items,'net_amount'));
    $cur = strtoupper($bi['currency'] ?? 'USD');
    $date_label = date('d F Y', strtotime($bi['sell_date']));
    $co_q = $conn->query("SELECT param_value FROM parameters WHERE param_key='company_name' LIMIT 1");
    $company = $co_q ? ($co_q->fetch_assoc()['param_value'] ?? 'Flowork') : 'Flowork';

    if ($export === 'csv' || $export === 'cbz_csv' || $export === 'fbc_csv') {
        $fn = "batch_{$export_bid}_".preg_replace('/[^A-Za-z0-9]','_',$bi['payment_method']).".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"$fn\"");
        if ($export === 'cbz_csv') {
            // CBZ Bank bulk upload format
            echo "\"Account Number\",\"Account Name\",\"Amount\",\"Reference\",\"Narrative\"\r\n";
            foreach ($items as $it) echo implode(',', ['"'.($it['account_num']??'').'"','"'.($it['account_name']??$it['grower_name']).'"',number_format((float)$it['net_amount'],2),'"'.$bi['batch_ref'].'"','"'.$it['grower_num'].'"'])."\r\n";
        } elseif ($export === 'fbc_csv') {
            // FBC Bank bulk upload format
            echo "BENEFICIARY_ACCOUNT,BENEFICIARY_NAME,AMOUNT,CURRENCY,REFERENCE,NARRATIVE\r\n";
            foreach ($items as $it) echo implode(',', [($it['account_num']??''),'"'.($it['account_name']??$it['grower_name']).'"',number_format((float)$it['net_amount'],2),$cur,$bi['batch_ref'],$it['grower_num']])."\r\n";
        } else {
            // Generic CSV
            echo "\"Payment Batch Report — Batch #$export_bid\"\r\n";
            echo "\"Date\",\"$date_label\"\r\n\"Method\",\"{$bi['payment_method']}\"\r\n\"Reference\",\"{$bi['batch_ref']}\"\r\n\"Status\",\"".strtoupper($bi['status'])."\"\r\n\"Total $cur\",\"".number_format($batch_total,4)."\"\r\n\r\n";
            echo "\"Grower #\",\"Grower Name\",\"Phone\",\"Bank\",\"Account #\",\"Account Name\",\"Branch\",\"Swift\",\"Amount ($cur)\"\r\n";
            foreach ($items as $it) echo implode(',', ['"'.$it['grower_num'].'"','"'.$it['grower_name'].'"','"'.($it['phone']??'').'"','"'.($it['bank_name']??'').'"','"'.($it['account_num']??'').'"','"'.($it['account_name']??'').'"','"'.($it['branch']??'').'"','"'.($it['swift_code']??'').'"',number_format((float)$it['net_amount'],4)])."\r\n";
            echo "\r\n\"TOTAL\",\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"".number_format($batch_total,4)."\"\r\n";
        }
        exit;
    }

    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=utf-8');
        $status_map = ['draft'=>'DRAFT','reviewed'=>'REVIEWED','approved'=>'APPROVED','released'=>'RELEASED','locked'=>'LOCKED'];
        ?><!DOCTYPE html><html><head><meta charset="UTF-8"><title>Batch #<?=$export_bid?></title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,sans-serif;font-size:8.5px;color:#111;padding:10px}.hdr{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;padding-bottom:7px;border-bottom:2px solid #1a1208}.hdr h1{font-size:14px;font-weight:700;color:#1a1208}.hdr h2{font-size:8px;color:#666;margin-top:1px}.tag{background:#c8952a;color:#fff;font-size:7px;font-weight:700;padding:2px 5px;border-radius:2px;text-transform:uppercase}.meta{display:grid;grid-template-columns:repeat(6,1fr);gap:5px;background:#f5f0e8;padding:6px 9px;border-radius:3px;margin-bottom:8px}.mi label{display:block;font-size:6.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#7a6e5f;margin-bottom:1px}.mi span{font-size:9.5px;font-weight:700}table{width:100%;border-collapse:collapse}thead th{background:#1a1208;color:#e8b84b;font-size:7px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3.5px 4px;text-align:left}thead th.r{text-align:right}tbody td{padding:3px 4px;border-bottom:1px solid #ede6d6;font-size:7.5px}tbody tr:nth-child(even){background:#faf8f5}tfoot td{background:#1a1208;color:#e8b84b;font-weight:700;padding:3.5px 4px;font-size:7.5px}.r{text-align:right}.badge{background:#fdf6e8;border:1px solid #e8c96a;padding:1px 3px;border-radius:2px;font-weight:700;font-size:6.5px}.sig{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-top:12px}.sb{border-top:1px solid #1a1208;padding-top:4px}.sb label{font-size:7px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7a6e5f;display:block;margin-bottom:12px}@media print{body{padding:0}@page{margin:8mm 6mm;size:A4 landscape}}</style></head><body>
<div class="hdr"><div><h1><?=htmlspecialchars($company)?></h1><h2>Payment Batch Report</h2></div><div style="text-align:right"><span class="tag">Batch #<?=$export_bid?> — <?=$status_map[$bi['status']]??''?></span><div style="font-size:7px;color:#666;margin-top:3px">Printed: <?=date('d M Y H:i')?></div></div></div>
<div class="meta">
<div class="mi"><label>Batch #</label><span><?=$export_bid?></span></div>
<div class="mi"><label>Sale Date</label><span><?=$date_label?></span></div>
<div class="mi"><label>Payment Method</label><span><?=htmlspecialchars($bi['payment_method'])?></span></div>
<div class="mi"><label>Reference</label><span><?=htmlspecialchars($bi['batch_ref']?:'—')?></span></div>
<div class="mi"><label>Growers</label><span><?=count($items)?></span></div>
<div class="mi"><label>Total <?=$cur?></label><span><?=number_format($batch_total,2)?></span></div>
</div>
<table><thead><tr><th>#</th><th>Grower #</th><th>Grower Name</th><th>Phone</th><th>Bank / Institution</th><th>Account #</th><th>Account Name</th><th>Branch</th><th class="r">Amount (<?=$cur?>)</th></tr></thead><tbody>
<?php $sq=0; foreach($items as $it): $sq++; ?>
<tr><td style="color:#888"><?=$sq?></td><td><span class="badge"><?=htmlspecialchars($it['grower_num'])?></span></td><td><?=htmlspecialchars($it['grower_name'])?></td><td><?=htmlspecialchars($it['phone']??'')?></td><td><?=htmlspecialchars($it['bank_name']??$bi['payment_method'])?></td><td style="font-family:monospace"><?=htmlspecialchars($it['account_num']??'—')?></td><td><?=htmlspecialchars($it['account_name']??'—')?></td><td><?=htmlspecialchars($it['branch']??'—')?></td><td class="r"><strong><?=number_format((float)$it['net_amount'],4)?></strong></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td colspan="8">TOTAL — <?=count($items)?> growers</td><td class="r"><?=$cur?> <?=number_format($batch_total,4)?></td></tr></tfoot></table>
<?php if($bi['approved_by']): $appU=$conn->query("SELECT username FROM users WHERE id={$bi['approved_by']} LIMIT 1")->fetch_assoc(); ?>
<div style="margin-top:8px;font-size:7px;color:#666">Approved by: <?=htmlspecialchars($appU['username']??'')?> &nbsp;·&nbsp; <?=date('d M Y H:i',strtotime($bi['approved_at']??''))?></div>
<?php endif; ?>
<div class="sig"><div class="sb"><label>Prepared by (Accounts)</label></div><div class="sb"><label>Approved by (Manager)</label></div><div class="sb"><label>Bank / Platform confirmed</label></div></div>
<script>window.print();</script></body></html>
<?php     exit;
    }

    if ($export === 'xlsx') {
        $py_data = json_encode(['batch_id'=>$export_bid,'date'=>$bi['sell_date'],'method'=>$bi['payment_method'],'ref'=>$bi['batch_ref'],'currency'=>$cur,'status'=>strtoupper($bi['status']??''),'items'=>$items,'total'=>$batch_total,'company'=>$company]);
        $tmpj = tempnam(sys_get_temp_dir(),'pb_').'.json';
        file_put_contents($tmpj, $py_data);
        $out = sys_get_temp_dir()."/pb_{$export_bid}.xlsx";
        $py=<<<PY
import json
from openpyxl import Workbook
from openpyxl.styles import Font,PatternFill,Alignment,Border,Side
from openpyxl.utils import get_column_letter
with open('$tmpj') as f: d=json.load(f)
wb=Workbook(); ws=wb.active; ws.title="Batch #"+str(d['batch_id'])
ink="1a1208"; gold="c8952a"
hf=Font(name='Arial',bold=True,color="e8b84b",size=9)
hfill=PatternFill("solid",fgColor=ink)
tf=Font(name='Arial',bold=True,color="e8b84b",size=9)
tfill=PatternFill("solid",fgColor=ink)
rb=Border(bottom=Side(style='thin',color='ede6d6'))
ws['A1']=d['company']+" — Payment Batch #"+str(d['batch_id'])
ws['A1'].font=Font(name='Arial',bold=True,size=13,color=ink)
ws['A2']=d['method']+" | "+d['date']+" | "+d['currency']+" "+"{:,.2f}".format(d['total'])+" | Status: "+d['status']
ws['A2'].font=Font(name='Arial',size=9,color="7a6e5f")
hdrs=["#","Grower #","Grower Name","Phone","Bank","Account #","Account Name","Branch","Amount ("+d['currency']+")"]
wds=[5,11,22,14,20,16,20,12,14]
for i,(h,w) in enumerate(zip(hdrs,wds),1):
    c=ws.cell(row=4,column=i,value=h)
    c.font=hf; c.fill=hfill
    c.alignment=Alignment(horizontal='right' if i==len(hdrs) else 'left',vertical='center')
    ws.column_dimensions[get_column_letter(i)].width=w
ws.row_dimensions[4].height=18
for seq,it in enumerate(d['items'],1):
    row=4+seq
    vals=[seq,it['grower_num'],it['grower_name'],it.get('phone',''),it.get('bank_name',d['method']),it.get('account_num',''),it.get('account_name',''),it.get('branch',''),float(it['net_amount'])]
    for col,v in enumerate(vals,1):
        c=ws.cell(row=row,column=col,value=v)
        c.font=Font(name='Arial',size=9); c.border=rb
        if col==len(vals): c.alignment=Alignment(horizontal='right'); c.number_format='#,##0.0000'
        elif col==1: c.font=Font(name='Arial',size=9,color="888888"); c.alignment=Alignment(horizontal='center')
    if seq%2==0:
        for col in range(1,len(vals)+1): ws.cell(row=row,column=col).fill=PatternFill("solid",fgColor="faf8f5")
tr=4+len(d['items'])+1
for col in range(1,9): c=ws.cell(row=tr,column=col,value="TOTAL" if col==1 else ""); c.fill=tfill; c.font=tf
tc=ws.cell(row=tr,column=9,value=float(d['total'])); tc.font=tf; tc.fill=tfill; tc.number_format='#,##0.0000'; tc.alignment=Alignment(horizontal='right')
ws.freeze_panes='A5'
wb.save('$out')
PY;
        file_put_contents('/tmp/gpb.py',$py); exec('python3 /tmp/gpb.py 2>&1',$o,$rc); unlink($tmpj);
        if($rc!==0||!file_exists($out)){echo "Excel error: ".implode("\n",$o);exit;}
        $fn="batch_{$export_bid}_".preg_replace('/[^A-Za-z0-9]/','_',$bi['payment_method']).".xlsx";
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"$fn\"");
        header('Content-Length: '.filesize($out));
        readfile($out); unlink($out); exit;
    }
}

// ── Data ──────────────────────────────────────────────────────────────────
$er  = $conn->query("SELECT amount FROM exchange_rate ORDER BY id DESC LIMIT 1")->fetch_assoc();
$zig = (float)($er['amount'] ?? 0);

// All settled growers for this date
$growers_q = $conn->query("
    SELECT tg.id AS tgid, g.grower_num, CONCAT(g.name,' ',g.surname) grower_name, g.phone,
           bl.code AS loc_code,
           COALESCE(SUM(dtn.usd_net_value),0) net_usd,
           COALESCE(SUM(dtn.zim_net_value),0) net_zig,
           COALESCE(ps_usd.status,'pending') status_usd,
           ps_usd.payment_method method_usd, ps_usd.risk_flags,
           gb.bank_name, gb.account_num, gb.account_name, gb.verified
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id=bb.transporter_growersid
    JOIN growers g ON g.id=tg.growerid
    JOIN transporter t ON t.id=tg.transporterid
    LEFT JOIN bale_locations bl ON bl.id=t.location_id
    LEFT JOIN daily_totals_net_amount dtn ON dtn.balanced_balesid=bb.id
    LEFT JOIN payment_status ps_usd ON ps_usd.transporter_growersid=tg.id AND ps_usd.sell_date='$date_f' AND ps_usd.currency='usd'
    LEFT JOIN grower_banking gb ON gb.growerid=g.id
    WHERE g.selling_pointid=$spid AND DATE(bb.created_at)='$date_f' AND bb.stoporder_processed=1
    GROUP BY tg.id ORDER BY loc_code, g.grower_num
");
$growers = $growers_q ? $growers_q->fetch_all(MYSQLI_ASSOC) : [];

// Existing batches for this date
$batches_q = $conn->query("
    SELECT pb.*, u.username, COUNT(pbi.id) cnt, COALESCE(SUM(pbi.net_amount),0) total
    FROM payment_batch pb
    JOIN users u ON u.id=pb.created_by
    LEFT JOIN payment_batch_item pbi ON pbi.payment_batchid=pb.id
    WHERE pb.sell_date='$date_f'
    GROUP BY pb.id ORDER BY pb.id DESC
");
$batches = $batches_q ? $batches_q->fetch_all(MYSQLI_ASSOC) : [];

// Batch detail
$batch_detail = null; $batch_items = [];
if ($view_bid) {
    $batch_detail = $conn->query("SELECT pb.*,u.username FROM payment_batch pb JOIN users u ON u.id=pb.created_by WHERE pb.id=$view_bid LIMIT 1")->fetch_assoc();
    $biq = $conn->query("SELECT pbi.*,g.grower_num,CONCAT(g.name,' ',g.surname) grower_name,g.phone,gb.bank_name,gb.account_num,gb.account_name,gb.branch,gb.verified,ps.risk_flags FROM payment_batch_item pbi JOIN transporter_growers tg ON tg.id=pbi.transporter_growersid JOIN growers g ON g.id=tg.growerid LEFT JOIN grower_banking gb ON gb.growerid=g.id LEFT JOIN payment_status ps ON ps.transporter_growersid=tg.id AND ps.sell_date='$date_f' AND ps.currency=pbi.currency WHERE pbi.payment_batchid=$view_bid ORDER BY g.grower_num");
    $batch_items = $biq ? $biq->fetch_all(MYSQLI_ASSOC) : [];
}

$status_cfg=['draft'=>['Draft','badge-gray'],'reviewed'=>['Reviewed','badge-gold'],'approved'=>['Approved','badge-blue'],'released'=>['Released','badge-green'],'locked'=>['Locked','badge-red']];
include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Payment Batches</h2>
    <p><?=date('d M Y',strtotime($date_f))?> &nbsp;·&nbsp; Role: <span class="badge badge-gray" style="font-size:.65rem"><?=ucfirst($my_role)?></span></p>
  </div>
  <div class="flex gap-2">
    <form method="GET" style="display:flex;gap:.4rem;align-items:flex-end">
      <input type="hidden" name="bid" value="<?=$view_bid?>">
      <input type="date" name="date" class="form-control" value="<?=htmlspecialchars((string)$date_f)?>" style="padding:.35rem .6rem" onchange="this.form.submit()">
    </form>
    <a href="pay_dashboard.php?date=<?=htmlspecialchars((string)$date_f)?>" class="btn btn-ghost">← Dashboard</a>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>
<?php if($day_closed):?><div class="alert alert-gold">🔒 Payment day is closed. Batches are locked.</div><?php endif;?>

<div class="grid-2" style="align-items:start">

<!-- Left: batch list + create -->
<div>

<!-- Create batch -->
<?php if(pay_can($my_role,'create_batch') && !$day_closed): ?>
<div class="card mb-3">
<div class="card-head" style="cursor:pointer" onclick="tog('create-form')">
  <h3>+ New Payment Batch</h3>
  <span class="td-muted" style="font-size:.72rem">▾ expand</span>
</div>
<div id="create-form" style="display:none">
<div class="card-body">
<form method="POST" id="batch-form">
<input type="hidden" name="action" value="create_batch">
<div class="form-row cols-2">
  <div class="form-group">
    <label>Payment Method *</label>
    <select name="batch_method" class="form-control" required>
      <option value="">— Select —</option>
      <?php foreach($PAYMENT_TYPES as $mt=>$ic):?>
      <option value="<?=htmlspecialchars($mt)?>"><?=$ic?> <?=htmlspecialchars($mt)?></option>
      <?php endforeach;?>
    </select>
  </div>
  <div class="form-group">
    <label>Currency</label>
    <select name="currency" class="form-control">
      <option value="usd">USD</option>
      <?php if($zig>0):?><option value="zig">ZiG</option><?php endif;?>
    </select>
  </div>
</div>
<div class="form-row cols-2">
  <div class="form-group">
    <label>Batch Reference</label>
    <input type="text" name="batch_ref" class="form-control" placeholder="Bank batch ref — blank gives each grower a receipt no." style="font-family:monospace">
  </div>
  <div class="form-group">
    <label>Notes</label>
    <input type="text" name="batch_notes" class="form-control">
  </div>
</div>

<!-- Grower selection -->
<div style="font-size:.78rem;font-weight:500;color:var(--muted);margin-bottom:.5rem">
  Select Growers
  <button type="button" onclick="selAll()" class="btn btn-ghost btn-sm" style="margin-left:.5rem">All pending</button>
  <button type="button" onclick="clrAll()" class="btn btn-ghost btn-sm">Clear</button>
</div>
<div style="max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:.85rem">
<table style="width:100%;border-collapse:collapse;font-size:.75rem">
<thead><tr style="background:var(--surface-2);color:var(--text-muted)">
  <th style="padding:4px 7px;width:28px"><input type="checkbox" id="ca" onchange="toggleAll(this)"></th>
  <th style="padding:4px 7px">Grower</th>
  <th style="padding:4px 7px;text-align:right">Net USD</th>
  <th style="padding:4px 7px">Bank</th>
  <th style="padding:4px 7px">Flags</th>
</tr></thead>
<tbody>
<?php foreach($growers as $gr):
  $paid = in_array($gr['status_usd'],['paid','released']);
  $flags = array_filter(explode(',',$gr['risk_flags']??''));
?>
<tr style="border-bottom:1px solid var(--border);<?=$paid?'opacity:.4':''?>" onclick="<?=$paid?'':'toggleRow(this)'?>">
  <td style="padding:3px 7px">
    <?php if(!$paid):?>
    <input type="checkbox" class="gchk" name="batch_tgids[]" value="<?=$gr['tgid']?>"
           data-net="<?=$gr['net_usd']?>" data-name="<?=htmlspecialchars($gr['grower_num'],ENT_QUOTES)?>"
           onchange="updSum()" onclick="event.stopPropagation()">
    <?php else:?>✓<?php endif;?>
  </td>
  <td style="padding:3px 7px">
    <span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($gr['grower_num'])?></span>
    <span style="font-size:.75rem;margin-left:3px"><?=htmlspecialchars($gr['grower_name'])?></span>
  </td>
  <td style="padding:3px 7px;text-align:right;font-family:monospace;font-size:.75rem">$<?=number_format((float)$gr['net_usd'],2)?></td>
  <td style="padding:3px 7px;font-size:.7rem;color:var(--muted)">
    <?=$gr['bank_name']?htmlspecialchars($gr['bank_name']):'<span style="color:var(--danger)">None</span>'?>
    <?=$gr['verified']?'<span class="badge badge-green" style="font-size:.55rem">✓</span>':''?>
  </td>
  <td style="padding:3px 7px">
    <?php foreach($flags as $f): if(!trim($f)) continue; ?>
    <span title="<?=htmlspecialchars(trim($f))?>" style="font-size:1rem">⚠</span>
    <?php endforeach;?>
  </td>
</tr>
<?php endforeach;?>
</tbody>
</table>
</div>

<div id="batch-sum" style="font-size:.78rem;color:var(--muted);margin-bottom:.75rem">No growers selected</div>
<button type="submit" class="btn btn-primary" onclick="return confirm('Create batch?')">Create Batch →</button>
</form>
</div>
</div>
</div>
<?php endif;?>

<!-- Existing batches -->
<div class="card">
<div class="card-head"><h3>Batches — <?=date('d M Y',strtotime($date_f))?></h3>
<span class="badge badge-gray"><?=count($batches)?></span>
</div>
<?php if(!empty($batches)):?>
<div class="table-wrap"><table>
<thead><tr>
  <th>#</th><th>Method</th><th>Status</th>
  <th class="text-right">Growers</th><th class="text-right">Total</th>
  <th></th>
</tr></thead><tbody>
<?php foreach($batches as $b):
  [$sl,$sc] = $status_cfg[$b['status']] ?? ['?','badge-gray'];
?>
<tr style="cursor:pointer;<?=$view_bid==$b['id']?'background:var(--gold-bg);border-left:3px solid var(--gold)':''?>"
    onclick="location='?date=<?=htmlspecialchars((string)$date_f)?>&bid=<?=$b['id']?>'">
  <td><span class="badge badge-blue" style="font-size:.72rem">#<?=$b['id']?></span></td>
  <td style="font-size:.8rem"><?=($PAYMENT_TYPES[$b['payment_method']]??'🔷')?> <?=htmlspecialchars($b['payment_method'])?></td>
  <td><span class="badge <?=$sc?>" style="font-size:.72rem"><?=$sl?></span></td>
  <td class="td-mono text-right"><?=(int)$b['cnt']?></td>
  <td class="td-mono text-right fw-500">$<?=number_format((float)$b['total'],2)?></td>
  <td onclick="event.stopPropagation()"><a href="?date=<?=htmlspecialchars((string)$date_f)?>&bid=<?=$b['id']?>" class="btn btn-ghost btn-sm">View</a></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php else:?>
<div class="card-body text-center text-muted" style="padding:2rem">No batches yet for this date.</div>
<?php endif;?>
</div>
</div>

<!-- Right: batch detail -->
<div>
<?php if($batch_detail):
  $bid_d = (int)$batch_detail['id'];
  $batch_total_d = array_sum(array_column($batch_items,'net_amount'));
  [$sl,$sc] = $status_cfg[$batch_detail['status']] ?? ['?','badge-gray'];
  $can_review  = pay_can($my_role,'review_batch') && $batch_detail['status']==='draft';
  $can_approve = pay_can($my_role,'approve_batch') && $batch_detail['status']==='reviewed';
  $can_release = pay_can($my_role,'release_batch') && $batch_detail['status']==='approved';
  $can_lock    = pay_can($my_role,'lock_batch') && $batch_detail['status']==='released';
?>
<div class="card">
<div class="card-head" style="background:var(--surface-2);color:var(--text)">
  <div>
    <div class="fw-500">Batch #<?=$bid_d?> — <?=htmlspecialchars($batch_detail['payment_method'])?></div>
    <div style="font-size:.72rem;color:rgba(245,240,232,.45);margin-top:2px">
      <?=count($batch_items)?> growers &nbsp;·&nbsp; $<?=number_format($batch_total_d,2)?> USD
      &nbsp;·&nbsp; <span class="badge <?=$sc?>" style="font-size:.72rem"><?=$sl?></span>
    </div>
  </div>
  <div class="flex gap-1">
    <a href="?date=<?=htmlspecialchars((string)$date_f)?>&bid=<?=$bid_d?>&ebid=<?=$bid_d?>&export=pdf" target="_blank"
       class="btn btn-ghost btn-sm" style="color:var(--gold-l);border-color:rgba(200,149,42,.3);font-size:.68rem">🖨 PDF</a>
    <a href="?date=<?=htmlspecialchars((string)$date_f)?>&bid=<?=$bid_d?>&ebid=<?=$bid_d?>&export=csv"
       class="btn btn-ghost btn-sm" style="color:var(--gold-l);border-color:rgba(200,149,42,.3);font-size:.68rem">⬇ CSV</a>
    <a href="?date=<?=htmlspecialchars((string)$date_f)?>&bid=<?=$bid_d?>&ebid=<?=$bid_d?>&export=xlsx"
       class="btn btn-ghost btn-sm" style="color:var(--gold-l);border-color:rgba(200,149,42,.3);font-size:.68rem">⬇ Excel</a>
    <div style="position:relative;display:inline-block">
      <button class="btn btn-ghost btn-sm" style="color:var(--gold-l);border-color:rgba(200,149,42,.3);font-size:.68rem" onclick="tog('bank-export-dd')">Bank Files ▾</button>
      <div id="bank-export-dd" style="display:none;position:absolute;right:0;top:100%;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);z-index:50;min-width:150px;box-shadow:0 4px 12px rgba(0,0,0,.1)">
        <a href="?date=<?=htmlspecialchars((string)$date_f)?>&bid=<?=$bid_d?>&ebid=<?=$bid_d?>&export=cbz_csv" style="display:block;padding:.4rem .75rem;font-size:.75rem;text-decoration:none;color:var(--text)">⬇ CBZ Upload CSV</a>
        <a href="?date=<?=htmlspecialchars((string)$date_f)?>&bid=<?=$bid_d?>&ebid=<?=$bid_d?>&export=fbc_csv" style="display:block;padding:.4rem .75rem;font-size:.75rem;text-decoration:none;color:var(--text)">⬇ FBC Upload CSV</a>
      </div>
    </div>
  </div>
</div>

<!-- Approval workflow -->
<div style="padding:.65rem 1.15rem;border-bottom:1px solid var(--border);background:var(--cream-d)">
<div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
  <?php
  $steps = [['draft','Created','Draft'],['reviewed','Reviewed','Review'],['approved','Approved','Approve'],['released','Released','Release'],['locked','Locked','Lock']];
  $status_order = ['draft'=>0,'reviewed'=>1,'approved'=>2,'released'=>3,'locked'=>4];
  $cur_order = $status_order[$batch_detail['status']] ?? 0;
  foreach ($steps as $i=>[$st,$done,$action_label]):
    $past = $status_order[$st] <= $cur_order;
  ?>
  <div style="display:flex;align-items:center;gap:.3rem">
    <div style="width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700;
                background:<?=$past?'var(--leaf)':'var(--border)'?>;color:<?=$past?'#fff':'var(--muted)'?>">
      <?=$past?'✓':($i+1)?>
    </div>
    <span style="font-size:.7rem;color:<?=$past?'var(--leaf)':'var(--muted)'?>;font-weight:<?=$past?'500':'400'?>"><?=$done?></span>
  </div>
  <?php if($i<count($steps)-1):?><div style="flex:1;height:1px;background:var(--border);min-width:12px"></div><?php endif;?>
  <?php endforeach;?>
</div>
</div>

<!-- Action buttons for workflow -->
<div style="padding:.65rem 1.15rem;border-bottom:1px solid var(--border);display:flex;gap:.5rem;flex-wrap:wrap">
  <?php if($can_review && !$day_closed):?>
  <form method="POST"><input type="hidden" name="action" value="review_batch"><input type="hidden" name="bid" value="<?=$bid_d?>">
  <button class="btn btn-primary btn-sm" onclick="return confirm('Mark as Reviewed?')">✓ Mark Reviewed</button></form>
  <?php endif;?>
  <?php if($can_approve && !$day_closed):?>
  <form method="POST"><input type="hidden" name="action" value="approve_batch"><input type="hidden" name="bid" value="<?=$bid_d?>">
  <button class="btn btn-primary btn-sm" onclick="return confirm('Approve this batch?')">✓ Approve</button></form>
  <?php endif;?>
  <?php if($can_release && !$day_closed):?>
  <form method="POST"><input type="hidden" name="action" value="release_batch"><input type="hidden" name="bid" value="<?=$bid_d?>">
  <button class="btn btn-primary btn-sm" style="background:var(--leaf)" onclick="return confirm('Release batch? This marks all growers as PAID.')">✓ Release & Mark Paid</button></form>
  <?php endif;?>
  <?php if($can_lock):?>
  <form method="POST"><input type="hidden" name="action" value="lock_batch"><input type="hidden" name="bid" value="<?=$bid_d?>">
  <button class="btn btn-ghost btn-sm" style="border-color:var(--danger);color:var(--danger)" onclick="return confirm('Lock batch? No further edits possible.')">🔒 Lock</button></form>
  <?php endif;?>
  <span style="font-size:.72rem;color:var(--muted);align-self:center">
    Role: <strong><?=ucfirst($my_role)?></strong>
  </span>
</div>

<!-- Batch items -->
<div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto"><table>
<thead><tr>
  <th>#</th><th>Grower #</th><th>Name</th>
  <th>Bank / Account</th><th>Verified</th>
  <th>Flags</th><th class="text-right">Amount</th>
</tr></thead><tbody>
<?php $sq=0; foreach($batch_items as $bi2): $sq++;
  $bflags = array_filter(explode(',',$bi2['risk_flags']??''));
  $flag_labels=['DUPLICATE_PAYMENT'=>'Duplicate','BANK_ACCT_CHANGED_RECENTLY'=>'Acct changed','AMOUNT_VARIANCE_GT_10PCT'=>'Amount variance','NO_BANK_ACCOUNT'=>'No bank'];
?>
<tr>
  <td class="td-muted" style="font-size:.7rem"><?=$sq?></td>
  <td><span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($bi2['grower_num'])?></span></td>
  <td style="font-size:.78rem"><?=htmlspecialchars($bi2['grower_name'])?></td>
  <td style="font-size:.72rem">
    <div><?=htmlspecialchars($bi2['bank_name']??'—')?></div>
    <div class="td-mono" style="font-size:.68rem"><?=htmlspecialchars($bi2['account_num']??'—')?></div>
  </td>
  <td>
    <?php if($bi2['verified']):?>
    <span class="badge badge-green" style="font-size:.72rem">✓ Verified</span>
    <?php else:?>
    <span class="badge badge-red" style="font-size:.72rem">Unverified</span>
    <?php endif;?>
  </td>
  <td>
    <?php foreach($bflags as $f): $f=trim($f); if(!$f) continue; ?>
    <span class="badge badge-red" style="font-size:.72rem;margin-right:2px" title="<?=htmlspecialchars($f)?>"><?=$flag_labels[$f]??$f?></span>
    <?php endforeach;?>
  </td>
  <td class="td-mono text-right fw-500">$<?=number_format((float)$bi2['net_amount'],4)?></td>
</tr>
<?php endforeach;?>
</tbody>
<tfoot><tr style="background:var(--cream-d)">
  <td colspan="6" style="padding:.45rem .9rem;font-weight:500">TOTAL — <?=count($batch_items)?></td>
  <td class="td-mono text-right fw-500" style="padding:.45rem .9rem">$<?=number_format($batch_total_d,4)?></td>
</tr></tfoot>
</table></div>
</div>
<?php else:?>
<div class="card">
<div class="card-body text-center text-muted" style="padding:3rem">
  <div style="font-size:2.5rem;margin-bottom:.75rem;opacity:.3">📦</div>
  <div class="fw-500">Select a batch to view details</div>
  <div style="font-size:.78rem;margin-top:.35rem">or create a new batch using the form</div>
</div>
</div>
<?php endif;?>
</div>
</div><!-- /grid -->

<script>
function tog(id){var e=document.getElementById(id);if(!e)return;e.style.display=e.style.display==='none'?'block':'none';}
function toggleRow(tr){var c=tr.querySelector('.gchk');if(c){c.checked=!c.checked;updSum();}}
function toggleAll(el){document.querySelectorAll('.gchk').forEach(function(c){c.checked=el.checked;});updSum();}
function selAll(){document.querySelectorAll('.gchk').forEach(function(c){c.checked=true;});updSum();}
function clrAll(){document.querySelectorAll('.gchk,.gchk').forEach(function(c){c.checked=false;});updSum();}
function updSum(){
  var cs=document.querySelectorAll('.gchk:checked'),t=0;
  cs.forEach(function(c){t+=parseFloat(c.dataset.net||0);});
  var el=document.getElementById('batch-sum');
  if(el) el.innerHTML=cs.length?'<strong>'+cs.length+' grower(s)</strong> — <strong>$'+t.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2})+' USD</strong>':'<span style="color:var(--muted)">No growers selected</span>';
}
// Close dropdown on outside click
document.addEventListener('click',function(e){
  var dd=document.getElementById('bank-export-dd');
  if(dd && !e.target.closest('[onclick*="bank-export-dd"]')) dd.style.display='none';
});
</script>
<?php include "includes/layout_end.php"; ?>
