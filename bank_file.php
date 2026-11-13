<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = "Bank File";
$active_page = "bank_file";
$userid      = current_userid();
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);

// ── Map payment method → file format ──────────────────────────────────────
$FORMAT_MAP = [
    'CBZ Transfer'        => 'cbz',
    'Nedbank Transfer'    => 'nedbank',
    'FBC Bank Transfer'   => 'fbc',
    'BancABC Transfer'    => 'bancabc',
    'Stanbic Transfer'    => 'stanbic',
    'Other Bank Transfer' => 'generic_bank',
    'EcoCash'             => 'ecocash',
    'Mukuru'              => 'mukuru',
    'InnBucks'            => 'innbucks',
    'RTGS'                => 'rtgs',
    'Cash'                => 'cash',
    'Cheque'              => 'cheque',
];

$FORMAT_LABEL = [
    'cbz'          => 'CBZ EFT',
    'nedbank'      => 'Nedbank EFT',
    'fbc'          => 'FBC EFT',
    'bancabc'      => 'BancABC EFT',
    'stanbic'      => 'Stanbic Bulk',
    'generic_bank' => 'Generic Bank EFT',
    'ecocash'      => 'EcoCash Bulk',
    'mukuru'       => 'Mukuru Bulk',
    'innbucks'     => 'InnBucks Bulk',
    'rtgs'         => 'RTGS Instruction',
    'cash'         => 'Cash Disbursement Sheet',
    'cheque'       => 'Cheque Register',
];

// ── Filters ────────────────────────────────────────────────────────────────
$date_from = $conn->real_escape_string(trim($_GET['date_from'] ?? date('Y-m-d')));
$date_to   = $conn->real_escape_string(trim($_GET['date_to']   ?? date('Y-m-d')));
$method_f  = $conn->real_escape_string(trim($_GET['method']    ?? ''));
$batch_id  = (int)($_GET['batch_id'] ?? 0);
$msg = $err = '';

// ── Helper: load batch rows — one row per payment_batch_item (matches batch report) ──
function load_batch_rows($conn, $bid) {
    $q = $conn->query("
        SELECT
            pbi.id            AS item_id,
            pbi.net_amount    AS total_amount,
            pbi.currency,
            pbi.balanced_balesid,
            g.id AS growerid,
            g.grower_num, g.name, g.surname, g.phone, g.national_id,
            gb.bank_name, gb.branch, gb.account_num, gb.account_name,
            gb.swift_code, gb.mobile_number
        FROM payment_batch_item pbi
        JOIN transporter_growers tg ON tg.id = pbi.transporter_growersid
        JOIN growers g              ON g.id  = tg.growerid
        LEFT JOIN grower_banking gb
               ON gb.id = (SELECT MIN(id) FROM grower_banking WHERE growerid = g.id)
        WHERE pbi.payment_batchid = $bid
        ORDER BY g.grower_num ASC, pbi.id ASC
    ");
    return ($q && $q !== true) ? $q->fetch_all(MYSQLI_ASSOC) : [];
}

// ── CSV helper functions ────────────────────────────────────────────────────
function eq($v)       { return '"'.str_replace('"','""',$v??'').'"'; }
function am($v,$dp=4) { return number_format((float)$v,$dp,'.','')?? '0.0000'; }

// ── Download handler ───────────────────────────────────────────────────────
if (isset($_GET['download']) && $batch_id) {
    $bi = $conn->query("SELECT pb.*, u.username
        FROM payment_batch pb JOIN users u ON u.id=pb.created_by
        WHERE pb.id=$batch_id LIMIT 1")->fetch_assoc();
    if (!$bi) { echo "Batch not found."; exit; }

    $rows   = load_batch_rows($conn, $batch_id);
    $date   = $bi['sell_date'];
    $cur    = strtoupper($bi['currency']);
    $method = $bi['payment_method'];
    $ref    = $bi['batch_ref'] ?: "BATCH{$batch_id}";
    $fmt    = $FORMAT_MAP[$method] ?? 'generic_bank';
    $total  = array_sum(array_column($rows, 'total_amount'));
    $fn     = "batch_{$batch_id}_{$fmt}_{$date}.csv";
    $fn     = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $fn);

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$fn\"");

    $narration = 'TOBACCO PROCEEDS ' . date('dMY', strtotime($date));

    switch ($fmt) {

        // ── CBZ EFT ─────────────────────────────────────────────────────
        case 'cbz':
            echo "CBZ BANK BATCH EFT\r\n";
            echo "Date:,".date('d/m/Y',strtotime($date)).",Currency:,$cur,Batch:,#$batch_id\r\n";
            echo "Ref:,".eq($ref).",Growers:,".count($rows).",Total $cur:,".am($total,2)."\r\n\r\n";
            echo "Beneficiary Name,Account Number,Branch Code,Amount $cur,Narration,Reference\r\n";
            foreach ($rows as $r) {
                if ((float)$r['total_amount'] <= 0) continue;
                echo implode(',', [
                    eq(trim($r['name'].' '.$r['surname'])),
                    eq($r['account_num'] ?? ''),
                    eq($r['branch']      ?? '0000'),
                    am($r['total_amount'], 2),
                    eq($narration),
                    eq($r['grower_num']),
                ])."\r\n";
            }
            echo "\r\nTOTAL,,,,".am($total,2)."\r\n";
            break;

        // ── Nedbank EFT (same CBZ-style columns) ──────────────────────
        case 'nedbank':
            echo "NEDBANK BATCH EFT\r\n";
            echo "Date:,".date('d/m/Y',strtotime($date)).",Currency:,$cur,Batch:,#$batch_id\r\n\r\n";
            echo "Beneficiary Name,Account Number,Branch Code,Amount $cur,Reference,Narration\r\n";
            foreach ($rows as $r) {
                if ((float)$r['total_amount'] <= 0) continue;
                echo implode(',', [
                    eq(trim($r['name'].' '.$r['surname'])),
                    eq($r['account_num'] ?? ''),
                    eq($r['branch']      ?? ''),
                    am($r['total_amount'], 2),
                    eq($r['grower_num']),
                    eq($narration),
                ])."\r\n";
            }
            echo "\r\nTOTAL,,,".am($total,2)."\r\n";
            break;

        // ── FBC EFT ─────────────────────────────────────────────────────
        case 'fbc':
            echo "FBC BANK BATCH PAYMENT\r\n";
            echo "Date:,".date('d/m/Y',strtotime($date)).",Currency:,$cur,Batch:,#$batch_id\r\n\r\n";
            echo "Grower No,Account Name,Account Number,Branch,Amount $cur,Narration\r\n";
            foreach ($rows as $r) {
                if ((float)$r['total_amount'] <= 0) continue;
                echo implode(',', [
                    eq($r['grower_num']),
                    eq($r['account_name'] ?: trim($r['name'].' '.$r['surname'])),
                    eq($r['account_num'] ?? ''),
                    eq($r['branch']      ?? ''),
                    am($r['total_amount'], 2),
                    eq($narration),
                ])."\r\n";
            }
            echo "\r\nTOTAL,,,,".am($total,2)."\r\n";
            break;

        // ── BancABC EFT ─────────────────────────────────────────────────
        case 'bancabc':
            echo "BANCABC BULK PAYMENT\r\n";
            echo "ValueDate:,".date('d/m/Y',strtotime($date)).",Currency:,$cur,Batch:,#$batch_id\r\n\r\n";
            echo "Seq,BeneficiaryName,AccountNumber,SortCode,Amount,Currency,PaymentReference\r\n";
            $seq = 1;
            foreach ($rows as $r) {
                if ((float)$r['total_amount'] <= 0) continue;
                echo implode(',', [
                    $seq++,
                    eq(trim($r['name'].' '.$r['surname'])),
                    eq($r['account_num'] ?? ''),
                    eq($r['branch']      ?? ''),
                    am($r['total_amount'], 2),
                    $cur,
                    eq($r['grower_num'].' TOBACCO '.$date),
                ])."\r\n";
            }
            echo "\r\nTOTAL,,,,,".am($total,2)."\r\n";
            break;

        // ── Stanbic Bulk ────────────────────────────────────────────────
        case 'stanbic':
            echo "PAYMENT_TYPE,BENEFICIARY_NAME,ACCOUNT_NUMBER,BANK_CODE,AMOUNT,CURRENCY,REFERENCE,NARRATION\r\n";
            foreach ($rows as $r) {
                if ((float)$r['total_amount'] <= 0) continue;
                echo implode(',', [
                    'EFT',
                    eq(trim($r['name'].' '.$r['surname'])),
                    eq($r['account_num']  ?? ''),
                    eq($r['swift_code']   ?? ''),
                    am($r['total_amount'], 2),
                    $cur,
                    eq($r['grower_num']),
                    eq($narration),
                ])."\r\n";
            }
            break;

        // ── RTGS ────────────────────────────────────────────────────────
        case 'rtgs':
            echo "RTGS PAYMENT INSTRUCTION\r\n";
            echo "Date:,".date('d/m/Y',strtotime($date)).",Currency:,$cur,Batch:,#$batch_id\r\n\r\n";
            echo "Grower No,Beneficiary Name,Bank,Branch,Account Number,SWIFT,Amount $cur,Reference\r\n";
            foreach ($rows as $r) {
                if ((float)$r['total_amount'] <= 0) continue;
                echo implode(',', [
                    eq($r['grower_num']),
                    eq(trim($r['name'].' '.$r['surname'])),
                    eq($r['bank_name']  ?? ''),
                    eq($r['branch']     ?? ''),
                    eq($r['account_num']?? ''),
                    eq($r['swift_code'] ?? ''),
                    am($r['total_amount'], 2),
                    eq($ref),
                ])."\r\n";
            }
            echo "\r\nTOTAL,,,,,,,".am($total,2)."\r\n";
            break;

        // ── EcoCash Bulk ────────────────────────────────────────────────
        case 'ecocash':
            echo "ECOCASH BULK DISBURSEMENT\r\n";
            echo "Date:,".date('d/m/Y',strtotime($date)).",Batch:,#$batch_id\r\n\r\n";
            echo "MSISDN,Amount,Reference,Narration\r\n";
            foreach ($rows as $r) {
                if ((float)$r['total_amount'] <= 0) continue;
                $msisdn = preg_replace('/\D/', '', $r['mobile_number'] ?? '');
                if (!$msisdn) $msisdn = preg_replace('/\D/', '', $r['phone'] ?? '');
                if (strlen($msisdn) === 9) $msisdn = '263'.$msisdn;
                if (!$msisdn) $msisdn = 'NO_NUMBER';
                echo implode(',', [
                    eq($msisdn),
                    am($r['total_amount'], 2),
                    eq($r['grower_num']),
                    eq($narration),
                ])."\r\n";
            }
            break;

        // ── Mukuru ──────────────────────────────────────────────────────
        case 'mukuru':
            echo "MUKURU BULK PAYMENT\r\n";
            echo "Date:,".date('d/m/Y',strtotime($date)).",Batch:,#$batch_id\r\n\r\n";
            echo "FirstName,LastName,Mobile,Amount,Currency,Reference\r\n";
            foreach ($rows as $r) {
                if ((float)$r['total_amount'] <= 0) continue;
                $mob = preg_replace('/\D/', '', $r['mobile_number'] ?? $r['phone'] ?? '');
                echo implode(',', [
                    eq($r['name']),
                    eq($r['surname']),
                    eq($mob),
                    am($r['total_amount'], 2),
                    $cur,
                    eq($r['grower_num']),
                ])."\r\n";
            }
            break;

        // ── InnBucks ────────────────────────────────────────────────────
        case 'innbucks':
            echo "INNBUCKS BULK DISBURSEMENT\r\n";
            echo "Date:,".date('d/m/Y',strtotime($date)).",Batch:,#$batch_id\r\n\r\n";
            echo "PhoneNumber,Amount,Reference,Description\r\n";
            foreach ($rows as $r) {
                if ((float)$r['total_amount'] <= 0) continue;
                $mob = preg_replace('/\D/', '', $r['mobile_number'] ?? $r['phone'] ?? '');
                echo implode(',', [
                    eq($mob),
                    am($r['total_amount'], 2),
                    eq($r['grower_num']),
                    eq($narration),
                ])."\r\n";
            }
            break;

        // ── Cash / Cheque disbursement sheet ────────────────────────────
        case 'cash':
        case 'cheque':
            $label = $fmt === 'cheque' ? 'CHEQUE REGISTER' : 'CASH DISBURSEMENT SHEET';
            echo "$label\r\n";
            echo "Date:,".date('d/m/Y',strtotime($date)).",Batch:,#$batch_id\r\n\r\n";
            echo "Grower #,Name,National ID,Phone,Amount $cur,Signature / Receipt #\r\n";
            foreach ($rows as $r) {
                if ((float)$r['total_amount'] <= 0) continue;
                echo implode(',', [
                    eq($r['grower_num']),
                    eq(trim($r['name'].' '.$r['surname'])),
                    eq($r['national_id'] ?? ''),
                    eq($r['phone']       ?? ''),
                    am($r['total_amount'], 2),
                    '""',
                ])."\r\n";
            }
            echo "\r\nTOTAL,,,,".am($total,2)."\r\n";
            break;

        // ── Generic bank (all fields) ────────────────────────────────────
        default:
            echo "Grower #,Name,Surname,National ID,Phone,Bank,Branch,Account #,Account Name,SWIFT,Mobile,Amount $cur,Reference\r\n";
            foreach ($rows as $r) {
                echo implode(',', [
                    eq($r['grower_num']),
                    eq($r['name']),
                    eq($r['surname']),
                    eq($r['national_id']   ?? ''),
                    eq($r['phone']         ?? ''),
                    eq($r['bank_name']     ?? ''),
                    eq($r['branch']        ?? ''),
                    eq($r['account_num']   ?? ''),
                    eq($r['account_name']  ?? ''),
                    eq($r['swift_code']    ?? ''),
                    eq($r['mobile_number'] ?? ''),
                    am($r['total_amount'], 4),
                    eq($r['grower_num'].' '.$date),
                ])."\r\n";
            }
            echo "\r\nTOTAL,,,,,,,,,,,,".am($total,4)."\r\n";
    }
    exit;
}

// ── Load batches matching filter ───────────────────────────────────────────
$where_parts = ["pb.sell_date BETWEEN '$date_from' AND '$date_to'"];
if ($spid > 0) {
    $where_parts[] = "(pb.created_by IN (SELECT id FROM users WHERE selling_pointid=$spid OR selling_pointid IS NULL) OR 1=1)";
}
if ($method_f) {
    $where_parts[] = "pb.payment_method = '$method_f'";
}
$where_sql = implode(' AND ', $where_parts);

$batches_q = $conn->query("
    SELECT pb.*,
           u.username,
           COUNT(DISTINCT pbi.id)              AS item_count,
           COUNT(DISTINCT tg.growerid)         AS grower_count,
           SUM(pbi.net_amount)                 AS batch_total
    FROM payment_batch pb
    JOIN users u ON u.id = pb.created_by
    LEFT JOIN payment_batch_item pbi ON pbi.payment_batchid = pb.id
    LEFT JOIN transporter_growers tg ON tg.id = pbi.transporter_growersid
    WHERE $where_sql
    GROUP BY pb.id
    ORDER BY pb.sell_date DESC, pb.id DESC
");
$batches = ($batches_q && $batches_q !== true) ? $batches_q->fetch_all(MYSQLI_ASSOC) : [];

// Pre-load items for ALL batches so preview can be toggled in JS without page reload
$all_preview_rows = [];
foreach ($batches as $_pb) {
    $all_preview_rows[(int)$_pb['id']] = load_batch_rows($conn, (int)$_pb['id']);
}

// All known payment methods (static list — always show full dropdown)

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Bank File</h2>
    <p>Generate bank-ready payment files from confirmed payment batches</p>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<div class="premium-tabs-nav" data-tabs="bankfile">
  <button type="button" class="premium-tab-btn active" data-tab="batches">📦 Payment Batches</button>
  <button type="button" class="premium-tab-btn" data-tab="guide">📖 File Format Guide</button>
</div>

<div id="panel-batches" class="tab-panel active" data-tabs="bankfile">

<!-- ── Filter bar ─────────────────────────────────────────────────────── -->
<div class="card mb-3">
<div class="card-body" style="padding:.65rem 1.15rem">
<form method="GET" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
  <div class="form-group" style="margin:0">
    <label style="font-size:.72rem;display:block;margin-bottom:.2rem">From Date</label>
    <input type="date" name="date_from" class="form-control" value="<?=htmlspecialchars($date_from)?>"
           style="padding:.35rem .6rem;font-size:.82rem">
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.72rem;display:block;margin-bottom:.2rem">To Date</label>
    <input type="date" name="date_to" class="form-control" value="<?=htmlspecialchars($date_to)?>"
           style="padding:.35rem .6rem;font-size:.82rem">
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.72rem;display:block;margin-bottom:.2rem">Payment Method</label>
    <select name="method" class="form-control" style="padding:.35rem .55rem;font-size:.82rem;min-width:200px">
      <option value="">All Methods</option>
      <?php foreach($FORMAT_MAP as $m => $fmt_key):?>
      <option value="<?=htmlspecialchars($m)?>" <?=$method_f===$m?'selected':''?>>
        <?=htmlspecialchars($m)?> — <?=htmlspecialchars($FORMAT_LABEL[$fmt_key] ?? $fmt_key)?>
      </option>
      <?php endforeach;?>
    </select>
  </div>
  <div style="display:flex;gap:.35rem;align-items:flex-end">
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    <a href="bank_file.php?date_from=<?=date('Y-m-d')?>&date_to=<?=date('Y-m-d')?>"
       class="btn btn-ghost btn-sm">Today</a>
    <a href="bank_file.php" class="btn btn-ghost btn-sm">All</a>
  </div>
</form>
</div>
</div>

<!-- ── Batch list ─────────────────────────────────────────────────────── -->
<div class="card mb-3">
<div class="card-head">
  <h3>Payment Batches</h3>
  <span class="badge badge-gray"><?=count($batches)?></span>
</div>

<?php if(empty($batches)):?>
<div style="padding:2rem;text-align:center;color:var(--muted);font-size:.82rem">
  No payment batches found for the selected period<?=$method_f?' and method':''?>.
  <a href="payments.php" style="margin-left:.4rem">→ Go to Payments</a>
</div>
<?php else:?>
<div class="table-wrap"><table>
<thead><tr>
  <th>Batch #</th>
  <th>Sale Date</th>
  <th>Payment Method</th>
  <th>File Format</th>
  <th>Reference</th>
  <th>Currency</th>
  <th class="text-right">Bales</th>
  <th class="text-right">Growers</th>
  <th class="text-right">Total</th>
  <th>Created</th>
  <th>Status</th>
  <th></th>
</tr></thead>
<tbody>
<?php foreach($batches as $b):
  $fmt        = $FORMAT_MAP[$b['payment_method']] ?? 'generic_bank';
  $fmt_label  = $FORMAT_LABEL[$fmt] ?? 'Generic CSV';
  $is_rev     = !empty($b['reversed_at']);
  $prev_rows  = $all_preview_rows[(int)$b['id']] ?? [];
  $prev_total = array_sum(array_column($prev_rows, 'total_amount'));
  $is_mobile_method = in_array($b['payment_method'], ['EcoCash','Mukuru','InnBucks']);
  $no_acct    = array_filter($prev_rows, function($r) use($is_mobile_method){
      return $is_mobile_method ? (empty($r['mobile_number']) && empty($r['phone']))
                               : empty($r['account_num']);
  });
  // Auto-open if batch_id matches URL param
  $auto_open  = ($batch_id == $b['id']);
?>
<!-- ── Summary row ── -->
<tr style="cursor:pointer;<?=$is_rev?'opacity:.5':''?>"
    onclick="toggleBatchPreview(<?=$b['id']?>)">
  <td><span class="badge badge-blue" style="font-size:.72rem">#<?=$b['id']?></span></td>
  <td style="font-size:.78rem;font-weight:500"><?=date('d M Y',strtotime($b['sell_date']))?></td>
  <td><span style="font-size:.8rem;font-weight:500"><?=htmlspecialchars($b['payment_method'])?></span></td>
  <td>
    <span class="badge badge-gray" style="font-size:.72rem;background:var(--surface-2);color:var(--text)">
      <?=htmlspecialchars($fmt_label)?>
    </span>
  </td>
  <td class="td-mono" style="font-size:.72rem"><?=htmlspecialchars($b['batch_ref']?:'—')?></td>
  <td><span class="badge badge-gray" style="font-size:.72rem"><?=strtoupper($b['currency'])?></span></td>
  <td class="td-mono text-right"><?=(int)$b['item_count']?></td>
  <td class="td-mono text-right"><?=(int)$b['grower_count']?></td>
  <td class="td-mono text-right fw-500">
    <?=strtoupper($b['currency'])?> <?=number_format((float)$b['batch_total'],2)?>
  </td>
  <td style="font-size:.72rem;color:var(--muted)">
    <?=htmlspecialchars($b['username'])?>
    <div style="font-size:.65rem"><?=date('d/m H:i',strtotime($b['created_at']))?></div>
  </td>
  <td>
    <?php if($is_rev):?>
    <span class="badge badge-red" style="font-size:.72rem" title="<?=htmlspecialchars($b['reversal_reason'])?>">↩ Reversed</span>
    <?php else:?>
    <span class="badge badge-green" style="font-size:.72rem">✓ Active</span>
    <?php endif;?>
  </td>
  <td style="white-space:nowrap" onclick="event.stopPropagation()">
    <div class="flex gap-1" style="flex-wrap:wrap">
      <button type="button" onclick="toggleBatchPreview(<?=$b['id']?>)"
              class="btn btn-ghost btn-sm" style="font-size:.68rem">
        <span id="bfprev-ind-<?=$b['id']?>"><?=$auto_open?'▲ Hide':'▼ Preview'?></span>
      </button>
      <?php if(!$is_rev):?>
      <a href="?date_from=<?=htmlspecialchars((string)$date_from)?>&date_to=<?=htmlspecialchars((string)$date_to)?>&method=<?=urlencode($method_f)?>&batch_id=<?=$b['id']?>&download=1"
         class="btn btn-primary btn-sm" style="font-size:.68rem">
        ⬇ <?=htmlspecialchars($fmt_label)?>
      </a>
      <?php endif;?>
    </div>
  </td>
</tr>

<!-- ── Inline preview row (always rendered, JS-toggled) ── -->
<tr id="bfprev-<?=$b['id']?>" style="display:<?=$auto_open?'table-row':'none'?>">
<td colspan="12" style="padding:0;background:var(--gold-bg);border-bottom:2px solid var(--gold)">
<div style="padding:.85rem 1.15rem">

  <!-- Meta strip -->
  <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:.85rem;
              padding:.65rem 1rem;background:var(--surface-2);border-radius:var(--radius);color:var(--text)">
    <div><div style="font-size:.78rem;color:var(--cream-d);">Batch</div>
         <div style="color:var(--gold);font-weight:700;font-size:.9rem">#<?=$b['id']?></div></div>
    <div><div style="font-size:.78rem;color:var(--cream-d);">Sale Date</div>
         <div style="color:#fff;font-size:.82rem"><?=date('d M Y',strtotime($b['sell_date']))?></div></div>
    <div><div style="font-size:.78rem;color:var(--cream-d);">Method</div>
         <div style="color:#fff;font-size:.82rem"><?=htmlspecialchars($b['payment_method'])?></div></div>
    <div><div style="font-size:.78rem;color:var(--cream-d);">Reference</div>
         <div style="color:#fff;font-size:.82rem;font-family:monospace"><?=htmlspecialchars($b['batch_ref']?:'—')?></div></div>
    <div><div style="font-size:.78rem;color:var(--cream-d);">File Format</div>
         <div style="color:var(--gold);font-size:.82rem"><?=htmlspecialchars($fmt_label)?></div></div>
    <div style="margin-left:auto;text-align:right">
      <div style="font-size:.78rem;color:var(--cream-d);">Total <?=strtoupper($b['currency'])?></div>
      <div style="color:var(--gold);font-weight:700;font-size:0.94rem;font-family:'DM Sans',sans-serif">
        <?=strtoupper($b['currency'])?> <?=number_format($prev_total,2)?>
      </div>
    </div>
  </div>

  <?php if(!empty($no_acct)):?>
  <div style="background:rgba(200,149,42,.12);border-left:3px solid var(--gold);
              padding:.55rem .85rem;border-radius:2px;font-size:.75rem;margin-bottom:.75rem">
    ⚠ <?=count($no_acct)?> grower(s) missing <?=$is_mobile_method?'mobile number':'bank account'?> —
    they will be flagged in the file.
    <a href="banking.php" style="margin-left:.35rem;font-weight:500">→ Update banking details</a>
  </div>
  <?php endif;?>

  <!-- Items table -->
  <div style="max-height:320px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius)">
  <table style="width:100%;border-collapse:collapse;font-size:.76rem">
  <thead><tr style="background:var(--surface-2);color:var(--text-muted);position:sticky;top:0">
    <th style="padding:5px 8px">#</th>
    <th style="padding:5px 8px">Grower #</th>
    <th style="padding:5px 8px">Name</th>
    <th style="padding:5px 8px">Bank / Account</th>
    <th style="padding:5px 8px">Mobile</th>
    <th style="padding:5px 8px;text-align:right">Amount (<?=strtoupper($b['currency'])?>)</th>
    <th style="padding:5px 8px;text-align:center">Status</th>
  </tr></thead>
  <tbody>
  <?php $seq=0; foreach($prev_rows as $r): $seq++;
    $has_bank = !empty($r['account_num']);
    $has_mob  = !empty($r['mobile_number']) || !empty($r['phone']);
    $ready    = $is_mobile_method ? $has_mob : $has_bank;
  ?>
  <tr style="border-bottom:1px solid var(--border);<?=!$ready?'background:#fff8f8':($seq%2===0?'background:var(--cream-d)':'')?>">
    <td style="padding:3px 8px;color:var(--muted)"><?=$seq?></td>
    <td style="padding:3px 8px">
      <span class="badge badge-gold" style="font-size:.72rem"><?=htmlspecialchars($r['grower_num'])?></span>
    </td>
    <td style="padding:3px 8px;font-weight:500"><?=htmlspecialchars(trim($r['name'].' '.$r['surname']))?></td>
    <td style="padding:3px 8px;font-size:.72rem">
      <?php if($r['bank_name']):?>
        <div><?=htmlspecialchars($r['bank_name'])?></div>
        <div style="font-family:monospace;font-size:.68rem;color:var(--muted)"><?=htmlspecialchars($r['account_num']??'')?></div>
      <?php else:?><span style="color:var(--muted);font-size:.7rem">—</span><?php endif;?>
    </td>
    <td style="padding:3px 8px;font-family:monospace;font-size:.72rem;color:var(--muted)">
      <?=htmlspecialchars($r['mobile_number'] ?? $r['phone'] ?? '—')?>
    </td>
    <td style="padding:3px 8px;text-align:right;font-family:monospace;font-weight:600">
      <?=number_format((float)$r['total_amount'],4)?>
    </td>
    <td style="padding:3px 8px;text-align:center">
      <?php if($ready):?>
        <span class="badge badge-green" style="font-size:.72rem">✓ Ready</span>
      <?php else:?>
        <span class="badge badge-red" style="font-size:.72rem">
          ✗ No <?=$is_mobile_method?'mobile':'account'?>
        </span>
      <?php endif;?>
    </td>
  </tr>
  <?php endforeach;?>
  </tbody>
  <tfoot><tr style="background:var(--surface-2)">
    <td colspan="5" style="padding:4px 8px;color:var(--gold);font-size:.72rem;font-weight:600">
      TOTAL — <?=$seq?> item(s)
      <?php if(!empty($no_acct)):?>
        <span style="color:#e88;font-size:.65rem;margin-left:.5rem">(<?=count($no_acct)?> without details)</span>
      <?php endif;?>
    </td>
    <td style="padding:4px 8px;text-align:right;color:var(--gold);font-family:monospace;font-weight:700;font-size:.82rem">
      <?=strtoupper($b['currency'])?> <?=number_format($prev_total,4)?>
    </td>
    <td></td>
  </tfoot>
  </table>
  </div>

  <!-- Actions -->
  <div style="margin-top:.85rem;display:flex;gap:.5rem;justify-content:flex-end">
    <button type="button" onclick="toggleBatchPreview(<?=$b['id']?>)"
            class="btn btn-ghost">▲ Close Preview</button>
    <?php if(!$is_rev):?>
    <a href="?date_from=<?=htmlspecialchars((string)$date_from)?>&date_to=<?=htmlspecialchars((string)$date_to)?>&method=<?=urlencode($method_f)?>&batch_id=<?=$b['id']?>&download=1"
       class="btn btn-primary" style="padding:.55rem 1.25rem">
      ⬇ Download <?=htmlspecialchars($fmt_label)?>
    </a>
    <?php endif;?>
  </div>

</div>
</td>
</tr>

<?php endforeach;?>
</tbody>
</table></div>
<?php endif;?>
</div>

</div><!-- /panel-batches -->

<!-- ── Help card ─────────────────────────────────────────────────────── -->
<div id="panel-guide" class="tab-panel" data-tabs="bankfile">
<div class="card">
<div class="card-head"><h3>File Format Guide</h3></div>
<div class="card-body">
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.75rem">
<?php
$format_notes = [
    'CBZ EFT'             => 'Beneficiary Name, Account, Branch, Amount, Narration, Reference',
    'Nedbank EFT'         => 'Beneficiary Name, Account, Branch, Amount, Reference, Narration',
    'FBC EFT'             => 'Grower #, Account Name, Account #, Branch, Amount, Narration',
    'BancABC EFT'         => 'Seq, Name, Account #, Sort Code, Amount, Currency, Reference',
    'Stanbic Bulk'        => 'Type, Name, Account #, Bank Code, Amount, Currency, Ref, Narration',
    'Generic Bank EFT'    => 'All fields — Grower, Bank, Account, SWIFT, Amount, Reference',
    'EcoCash Bulk'        => 'MSISDN (263xxxxxxx), Amount, Reference, Narration',
    'Mukuru Bulk'         => 'FirstName, LastName, Mobile, Amount, Currency, Reference',
    'InnBucks Bulk'       => 'PhoneNumber, Amount, Reference, Description',
    'RTGS Instruction'    => 'All bank fields + SWIFT + Amount + Reference',
    'Cash Disbursement Sheet' => 'Grower #, Name, NatID, Phone, Amount, Signature column',
    'Cheque Register'     => 'Grower #, Name, NatID, Phone, Amount, Signature column',
];
foreach($format_notes as $fmt_name => $desc):?>
<div style="background:var(--cream-d);border:1px solid var(--border);border-radius:var(--radius);padding:.6rem .85rem">
  <div style="font-size:.72rem;font-weight:600;color:var(--text-strong);margin-bottom:.3rem"><?=htmlspecialchars($fmt_name)?></div>
  <div style="font-size:.68rem;color:var(--muted)"><?=htmlspecialchars($desc)?></div>
</div>
<?php endforeach;?>
</div>
</div>
</div>
</div><!-- /panel-guide -->

<script>
function toggleBatchPreview(bid) {
  var row = document.getElementById('bfprev-' + bid);
  var ind = document.getElementById('bfprev-ind-' + bid);
  if (!row) return;
  var opening = row.style.display === 'none' || row.style.display === '';
  row.style.display = opening ? 'table-row' : 'none';
  if (ind) ind.textContent = opening ? '▲ Hide' : '▼ Preview';
}
</script>
<?php include "includes/layout_end.php"; ?>
