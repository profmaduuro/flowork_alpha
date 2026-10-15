<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/audit.php";
require_login();

$page_title  = "Creditor Management";
$active_page = "creditor_portal";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
if (!$spid) {
    $_sp = $conn->query("SELECT id FROM selling_point WHERE active=1 ORDER BY id LIMIT 1");
    if ($_sp && $_sp !== true && $_sp->num_rows > 0) {
        $spid = (int)$_sp->fetch_assoc()['id'];
        $_SESSION['selling_pointid'] = $spid;
    }
}
$userid = current_userid();
$msg = $err = '';

// ── Ensure tables exist ────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS grower_creditors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid INT NOT NULL DEFAULT 0,
    selling_pointid INT DEFAULT 0,
    name VARCHAR(120) DEFAULT '',
    creditor_no VARCHAR(100) NOT NULL,
    creditor_name VARCHAR(120) DEFAULT '',
    creditor_type VARCHAR(100) DEFAULT '',
    business_type VARCHAR(100) DEFAULT '',
    cell_phone VARCHAR(30) DEFAULT '',
    bank_name VARCHAR(80) DEFAULT '',
    account_num VARCHAR(40) DEFAULT '',
    email VARCHAR(100) DEFAULT '',
    status ENUM('active','suspended','closed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cred (creditor_no, selling_pointid)
)");
$conn->query("ALTER TABLE grower_creditors ADD COLUMN IF NOT EXISTS selling_pointid INT DEFAULT 0");
$conn->query("ALTER TABLE grower_creditors ADD COLUMN IF NOT EXISTS bank_name VARCHAR(80) DEFAULT ''");
$conn->query("ALTER TABLE grower_creditors ADD COLUMN IF NOT EXISTS account_num VARCHAR(40) DEFAULT ''");
$conn->query("ALTER TABLE grower_creditors ADD COLUMN IF NOT EXISTS email VARCHAR(100) DEFAULT ''");
$conn->query("ALTER TABLE grower_creditors ADD COLUMN IF NOT EXISTS status ENUM('active','suspended','closed') DEFAULT 'active'");
// Only migrate orphans if we have a valid selling point
if ($spid > 0) $conn->query("UPDATE grower_creditors SET selling_pointid=$spid WHERE selling_pointid=0");

// ── AJAX handlers ──────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $aj = $_GET['ajax'];

    // Get single creditor
    if ($aj === 'get_creditor') {
        $id = (int)($_GET['id'] ?? 0);
        $r = $conn->query("SELECT * FROM grower_creditors WHERE id=$id AND ($spid=0 OR selling_pointid=$spid) LIMIT 1");
        $row = ($r && $r !== true) ? $r->fetch_assoc() : null;
        echo json_encode($row ? ['ok'=>true,'row'=>$row] : ['error'=>'Not found']);
        exit;
    }

    // Save creditor (add or edit)
    if ($aj === 'save_creditor') {
        $id        = (int)($_POST['id'] ?? 0);
        $name      = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $cno       = $conn->real_escape_string(strtoupper(trim($_POST['creditor_no'] ?? '')));
        $cname     = $conn->real_escape_string(trim($_POST['creditor_name'] ?? $name));
        $ctype     = $conn->real_escape_string(trim($_POST['creditor_type'] ?? ''));
        $btype     = $conn->real_escape_string(trim($_POST['business_type'] ?? ''));
        $phone     = $conn->real_escape_string(trim($_POST['cell_phone'] ?? ''));
        $bank      = $conn->real_escape_string(trim($_POST['bank_name'] ?? ''));
        $acnum     = $conn->real_escape_string(trim($_POST['account_num'] ?? ''));
        $email     = $conn->real_escape_string(trim($_POST['email'] ?? ''));
        $status    = in_array($_POST['status']??'', ['active','suspended','closed']) ? $_POST['status'] : 'active';
        if (!$name || !$cno) { echo json_encode(['error'=>'Name and creditor number required']); exit; }
        if ($id) {
            $conn->query("UPDATE grower_creditors SET name='$name',creditor_no='$cno',creditor_name='$cname',
                creditor_type='$ctype',business_type='$btype',cell_phone='$phone',bank_name='$bank',
                account_num='$acnum',email='$email',status='$status'
                WHERE id=$id AND ($spid=0 OR selling_pointid=$spid)");
            audit_log($conn,$spid,$userid,'grower_creditors','update',$id);
            echo json_encode(['ok'=>true,'msg'=>"Creditor updated.",'id'=>$id]);
        } else {
            $conn->query("INSERT INTO grower_creditors (userid,selling_pointid,name,creditor_no,creditor_name,
                creditor_type,business_type,cell_phone,bank_name,account_num,email,status)
                VALUES ($userid,$spid,'$name','$cno','$cname','$ctype','$btype','$phone','$bank','$acnum','$email','$status')");
            $newid = (int)$conn->insert_id;
            if (!$newid && $conn->errno) { echo json_encode(['error'=>'Duplicate creditor number or DB error: '.$conn->error]); exit; }
            audit_log($conn,$spid,$userid,'grower_creditors','create',$newid);
            echo json_encode(['ok'=>true,'msg'=>"Creditor '$name' registered.",'id'=>$newid]);
        }
        exit;
    }

    // Delete creditor
    if ($aj === 'delete_creditor') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM grower_creditors WHERE id=$id AND ($spid=0 OR selling_pointid=$spid)");
        audit_log($conn,$spid,$userid,'grower_creditors','delete',$id);
        echo json_encode(['ok'=>true,'msg'=>'Creditor deleted.']);
        exit;
    }

    // Get single stop order
    if ($aj === 'get_stoporder') {
        $id = (int)($_GET['id'] ?? 0);
        $r = $conn->query("SELECT iso.*, isoa.amount, isoa.id isoa_id, isoa.growerid,
            isoa.sell_date, isoa.reference, isoa.active isoa_active,
            g.grower_num, g.name g_name, g.surname g_surname
            FROM internal_stop_order iso
            LEFT JOIN internal_stop_order_amount isoa ON isoa.internal_stop_orderid=iso.id AND isoa.active=1
            LEFT JOIN growers g ON g.id=isoa.growerid
            WHERE iso.id=$id AND ($spid=0 OR iso.selling_pointid=$spid) LIMIT 1");
        $row = ($r && $r !== true) ? $r->fetch_assoc() : null;
        echo json_encode($row ? ['ok'=>true,'row'=>$row] : ['error'=>'Not found']);
        exit;
    }

    // Save stop order
    if ($aj === 'save_stoporder') {
        $id      = (int)($_POST['id'] ?? 0);
        $desc    = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $cno     = $conn->real_escape_string(trim($_POST['creditor_number'] ?? ''));
        $gid     = (int)($_POST['growerid'] ?? 0);
        $amt     = (float)($_POST['amount'] ?? 0);
        $ref     = $conn->real_escape_string(trim($_POST['reference'] ?? ''));
        $sd      = $conn->real_escape_string($_POST['sell_date'] ?? date('Y-m-d'));
        if (!$desc || $amt <= 0) { echo json_encode(['error'=>'Description and amount required']); exit; }
        if ($id) {
            $conn->query("UPDATE internal_stop_order SET description='$desc',creditor_number='$cno' WHERE id=$id AND ($spid=0 OR selling_pointid=$spid)");
            $conn->query("UPDATE internal_stop_order_amount SET amount=$amt,growerid=".($gid?$gid:'NULL').",reference='$ref',sell_date='$sd' WHERE internal_stop_orderid=$id");
            audit_log($conn,$spid,$userid,'internal_stop_order','update',$id);
            echo json_encode(['ok'=>true,'msg'=>'Stop order updated.']);
        } else {
            $_sea = $conn->query("SELECT id FROM seasons WHERE active=1 LIMIT 1");
            $seasonid = ($_sea&&$_sea!==true)?(int)($_sea->fetch_assoc()['id']??0):0;
            $conn->query("INSERT INTO internal_stop_order (userid,selling_pointid,description,creditor_number) VALUES ($userid,$spid,'$desc','$cno')");
            $iso_id = (int)$conn->insert_id;
            if ($iso_id) {
                $conn->query("INSERT INTO internal_stop_order_amount (userid,internal_stop_orderid,growerid,seasonid,amount,reference,sell_date,active)
                    VALUES ($userid,$iso_id,".($gid?$gid:'NULL').",$seasonid,$amt,'$ref','$sd',1)");
            }
            audit_log($conn,$spid,$userid,'internal_stop_order','create',$iso_id);
            echo json_encode(['ok'=>true,'msg'=>'Stop order created.','id'=>$iso_id]);
        }
        exit;
    }

    // Delete stop order
    if ($aj === 'delete_stoporder') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM internal_stop_order_amount WHERE internal_stop_orderid=$id");
        $conn->query("DELETE FROM internal_stop_order WHERE id=$id AND ($spid=0 OR selling_pointid=$spid)");
        audit_log($conn,$spid,$userid,'internal_stop_order','delete',$id);
        echo json_encode(['ok'=>true,'msg'=>'Stop order deleted.']);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

// ── Page data ──────────────────────────────────────────────────────────────
$creditors = [];
// TIMB creditor schedule is a global registry — show all
// User-created creditors are filtered by spid
$cred_where = "1=1";
$so_spid_cond = $spid > 0 ? "AND iso.selling_pointid=$spid" : "";
$cq = $conn->query("
    SELECT gc.*,
           COALESCE(NULLIF(gc.name,''), gc.creditor_name) display_name,
           COUNT(DISTINCT iso.id) so_count
    FROM grower_creditors gc
    LEFT JOIN internal_stop_order iso ON iso.creditor_number=gc.creditor_no $so_spid_cond
    WHERE $cred_where
    GROUP BY gc.id
    ORDER BY COALESCE(NULLIF(gc.name,''), gc.creditor_name) ASC
");
if ($cq && $cq !== true) $creditors = $cq->fetch_all(MYSQLI_ASSOC);

$stoporders = [];
$sq = $conn->query("
    SELECT iso.id, iso.description, iso.creditor_number,
           COALESCE(isoa.amount,0) amount,
           COALESCE(isoa.id,0) isoa_id,
           COALESCE(isoa.growerid,0) growerid,
           COALESCE(isoa.reference,'') reference,
           COALESCE(isoa.sell_date,'') sell_date,
           COALESCE(isoa.active,1) isoa_active,
           COALESCE(isoa.processed,0) processed,
           COALESCE(g.grower_num,'') grower_num,
           COALESCE(g.name,'') g_name,
           COALESCE(g.surname,'') g_surname,
           COALESCE(gc.name, gc.creditor_name,'') cred_name
    FROM internal_stop_order iso
    LEFT JOIN internal_stop_order_amount isoa ON isoa.internal_stop_orderid=iso.id AND isoa.active=1
    LEFT JOIN growers g ON g.id=isoa.growerid
    LEFT JOIN grower_creditors gc ON gc.creditor_no=iso.creditor_number AND gc.selling_pointid=$spid
    WHERE ($spid=0 OR iso.selling_pointid=$spid)
    ORDER BY iso.id DESC
    LIMIT 500
");
if ($sq && $sq !== true) $stoporders = $sq->fetch_all(MYSQLI_ASSOC);

$growers = [];
$gq = $conn->query("SELECT id,grower_num,name,surname FROM growers WHERE ($spid=0 OR selling_pointid=$spid) ORDER BY grower_num LIMIT 1000");
if ($gq && $gq !== true) $growers = $gq->fetch_all(MYSQLI_ASSOC);

$total_active    = count(array_filter($creditors, fn($c) => $c['status']==='active'));
$total_suspended = count(array_filter($creditors, fn($c) => $c['status']==='suspended'));
$total_so        = count($stoporders);
$total_so_amt    = array_sum(array_column($stoporders,'amount'));

include "includes/layout.php";
?>
<style>
/* ── Tabs ── */

/* ── KPI strip ── */
.cp-kpi{display:flex;gap:.55rem;flex-wrap:wrap;margin-bottom:1.1rem}
.cp-kc{flex:1;min-width:110px;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:.55rem .85rem}
.cp-kl{font-size:.57rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.cp-kv{font-size:1.1rem;font-weight:700;color:var(--gold);line-height:1.2;margin-top:.1rem}
.cp-kv.g{color:var(--leaf)}.cp-kv.w{color:var(--text-strong)}.cp-kv.r{color:var(--danger)}

/* ── Tables ── */
.cp-tbl thead th{position:sticky;top:0;background:var(--ink);color:var(--cream);font-size:.63rem;padding:.42rem .7rem;white-space:nowrap;z-index:2}
.cp-tbl tbody tr{cursor:pointer;transition:background .1s}
.cp-tbl tbody tr:hover td{background:rgba(200,149,42,.08)!important}
.cp-tbl tbody td{font-size:.73rem;padding:.42rem .7rem;white-space:nowrap}

/* ── Modal ── */
#cp-modal{display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.55);overflow-y:auto;padding:2rem 1rem}
#cp-box{background:var(--cream);border-radius:10px;max-width:680px;margin:0 auto;box-shadow:0 8px 40px rgba(0,0,0,.3);overflow:hidden}
.cp-mh{background:var(--ink);color:var(--cream);padding:.85rem 1.25rem;display:flex;align-items:center;gap:.75rem}
.cp-mb{padding:1.25rem}
.cp-mf{padding:.75rem 1.25rem;border-top:2px solid var(--border);background:#f8f5ef;display:flex;gap:.65rem;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.cp-sect{font-size:.6rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin:.85rem 0 .4rem}
.cp-sect:first-child{margin-top:0}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Creditor Management</h2>
    <p><?=count($creditors)?> creditors &nbsp;&middot;&nbsp; <?=$total_so?> stop orders</p>
  </div>
  <div style="display:flex;gap:.45rem;flex-wrap:wrap">
    <a href="file_manager_uploads.php" class="btn btn-ghost btn-sm">&#8679; Import CSV</a>
    <button class="btn btn-primary btn-sm" onclick="openCreditorModal(0)">+ Register Creditor</button>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success" id="cp-msg"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<!-- KPIs -->
<div class="cp-kpi">
  <div class="cp-kc"><div class="cp-kl">Total Creditors</div><div class="cp-kv w"><?=count($creditors)?></div></div>
  <div class="cp-kc"><div class="cp-kl">Active</div><div class="cp-kv g"><?=$total_active?></div></div>
  <div class="cp-kc"><div class="cp-kl">Suspended</div><div class="cp-kv r"><?=$total_suspended?></div></div>
  <div class="cp-kc"><div class="cp-kl">Stop Orders</div><div class="cp-kv"><?=$total_so?></div></div>
  <div class="cp-kc"><div class="cp-kl">Total SO Value</div><div class="cp-kv">$<?=number_format($total_so_amt,2)?></div></div>
</div>

<!-- Tabs -->
<div class="premium-tabs-nav" data-tabs="creditor">
  <button type="button" class="premium-tab-btn active" data-tab="creditors">&#127970; Creditors <span class="badge badge-gray" style="font-size:.6rem;margin-left:.25rem"><?=count($creditors)?></span></button>
  <button type="button" class="premium-tab-btn" data-tab="stoporders">&#128721; Stop Orders <span class="badge badge-gray" style="font-size:.6rem;margin-left:.25rem"><?=$total_so?></span></button>
</div>

<!-- ── TAB 1: CREDITORS ── -->
<div class="tab-panel active" data-tabs="creditor" id="panel-creditors">
  <div class="card">
    <div class="card-head" style="flex-wrap:wrap;gap:.5rem">
      <h3 style="margin:0">Creditor Register</h3>
      <div style="display:flex;gap:.45rem;flex:1;justify-content:flex-end;flex-wrap:wrap">
        <input type="text" id="cred-srch" class="form-control" placeholder="Search name, number, type..."
               style="max-width:240px;font-size:.8rem" oninput="filterCreds()">
        <select id="cred-status-filter" class="form-control" style="max-width:130px;font-size:.8rem" onchange="filterCreds()">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
          <option value="closed">Closed</option>
        </select>
      </div>
    </div>
    <div style="overflow-x:auto;max-height:520px;overflow-y:auto">
    <?php if(empty($creditors)):?>
    <div class="text-center text-muted" style="padding:3rem">
      No creditors found. <a href="file_manager_uploads.php" style="color:var(--gold)">Upload a creditor CSV</a> or click <strong>+ Register Creditor</strong> above.
    </div>
    <?php else:?>
    <table class="cp-tbl" id="cred-tbl">
    <thead><tr>
      <th>Creditor #</th><th>Name</th><th>Type</th><th>Business Type</th>
      <th>Phone</th><th>Bank</th><th>Account #</th><th>Email</th>
      <th class="text-right">Stop Orders</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach($creditors as $c):
      $dn = htmlspecialchars($c['display_name'] ?: $c['creditor_name']);
      $sr = strtolower($c['creditor_no'].' '.$c['display_name'].' '.$c['creditor_name'].' '.$c['creditor_type'].' '.$c['business_type']);
    ?>
    <tr data-id="<?=$c['id']?>" data-s="<?=htmlspecialchars($sr,ENT_QUOTES)?>" data-status="<?=$c['status']?>"
        onclick="openCreditorModal(<?=$c['id']?>)" style="<?=$c['status']!=='active'?'opacity:.6':''?>">
      <td class="td-mono" style="font-weight:600;color:var(--gold)"><?=htmlspecialchars($c['creditor_no'])?></td>
      <td style="font-weight:500"><?=$dn?></td>
      <td style="color:var(--muted)"><?=htmlspecialchars($c['creditor_type'])?></td>
      <td style="color:var(--muted)"><?=htmlspecialchars($c['business_type']??'')?></td>
      <td style="font-family:monospace;font-size:.7rem"><?=htmlspecialchars($c['cell_phone']??'')?></td>
      <td><?=htmlspecialchars($c['bank_name']??'')?></td>
      <td style="font-family:monospace;font-size:.7rem"><?=htmlspecialchars($c['account_num']??'')?></td>
      <td style="font-size:.7rem;color:var(--muted);max-width:140px;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($c['email']??'')?></td>
      <td class="text-right">
        <?php if($c['so_count']>0):?><span class="badge badge-blue" style="font-size:.6rem"><?=(int)$c['so_count']?></span>
        <?php else:?><span style="color:var(--muted)">—</span><?php endif;?>
      </td>
      <td><span class="badge <?=$c['status']==='active'?'badge-green':($c['status']==='suspended'?'badge-gold':'badge-red')?>" style="font-size:.6rem"><?=ucfirst($c['status'])?></span></td>
    </tr>
    <?php endforeach;?>
    </tbody>
    </table>
    <?php endif;?>
    </div>
    <div style="padding:.45rem 1rem;font-size:.68rem;color:var(--muted);border-top:1px solid var(--border)">
      <span id="cred-cnt"><?=count($creditors)?></span> creditor(s)
    </div>
  </div>
</div>

<!-- ── TAB 2: STOP ORDERS ── -->
<div class="tab-panel" data-tabs="creditor" id="panel-stoporders">
  <div class="card">
    <div class="card-head" style="flex-wrap:wrap;gap:.5rem">
      <h3 style="margin:0">Stop Orders</h3>
      <div style="display:flex;gap:.45rem;flex:1;justify-content:flex-end;flex-wrap:wrap">
        <input type="text" id="so-srch" class="form-control" placeholder="Search creditor, grower, ref..."
               style="max-width:240px;font-size:.8rem" oninput="filterSOs()">
        <button class="btn btn-primary btn-sm" onclick="openSOModal(0)">+ New Stop Order</button>
      </div>
    </div>
    <div style="overflow-x:auto;max-height:520px;overflow-y:auto">
    <?php if(empty($stoporders)):?>
    <div class="text-center text-muted" style="padding:3rem">
      No stop orders found. Click <strong>+ New Stop Order</strong> or import from the TIMB stop order file.
    </div>
    <?php else:?>
    <table class="cp-tbl" id="so-tbl">
    <thead><tr>
      <th>#</th><th>Creditor #</th><th>Creditor Name</th><th>Description</th>
      <th>Grower</th><th class="text-right">Amount USD</th>
      <th>Reference</th><th>Sell Date</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach($stoporders as $so):
      $sr = strtolower($so['creditor_number'].' '.$so['cred_name'].' '.$so['description'].' '.$so['grower_num'].' '.$so['reference']);
    ?>
    <tr data-id="<?=$so['id']?>" data-s="<?=htmlspecialchars($sr,ENT_QUOTES)?>"
        onclick="openSOModal(<?=$so['id']?>)"
        style="<?=$so['processed']?'opacity:.55':''?>">
      <td class="td-mono" style="color:var(--muted);font-size:.68rem"><?=$so['id']?></td>
      <td class="td-mono" style="font-weight:600;color:var(--gold)"><?=htmlspecialchars($so['creditor_number'])?></td>
      <td style="font-weight:500"><?=htmlspecialchars($so['cred_name'])?></td>
      <td><?=htmlspecialchars($so['description'])?></td>
      <td>
        <?php if($so['grower_num']):?>
        <span style="color:var(--gold);font-family:monospace;font-size:.7rem"><?=htmlspecialchars($so['grower_num'])?></span>
        <span style="color:var(--muted);font-size:.68rem"> <?=htmlspecialchars($so['g_name'].' '.$so['g_surname'])?></span>
        <?php else:?><span style="color:var(--muted)">—</span><?php endif;?>
      </td>
      <td class="td-mono text-right" style="font-weight:600;color:var(--leaf)">$<?=number_format((float)$so['amount'],2)?></td>
      <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($so['reference'])?></td>
      <td style="font-size:.7rem"><?=$so['sell_date']?></td>
      <td>
        <?php if($so['processed']):?>
        <span class="badge badge-green" style="font-size:.6rem">Processed</span>
        <?php else:?>
        <span class="badge badge-gold" style="font-size:.6rem">Pending</span>
        <?php endif;?>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
    </table>
    <?php endif;?>
    </div>
    <div style="padding:.45rem 1rem;font-size:.68rem;color:var(--muted);border-top:1px solid var(--border)">
      <span id="so-cnt"><?=$total_so?></span> stop order(s) &nbsp;&middot;&nbsp;
      Total: <strong>$<?=number_format($total_so_amt,2)?></strong>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     CREDITOR MODAL
══════════════════════════════════════════════════════════ -->
<div id="cp-modal" onclick="if(event.target===this)closeModal()">
<div id="cp-box">
  <div class="cp-mh">
    <div style="flex:1">
      <div style="font-family:'Bebas Neue',sans-serif;font-size:1.1rem;color:var(--gold);letter-spacing:.08em" id="cp-m-title">Register Creditor</div>
      <div style="font-size:.68rem;color:rgba(245,240,232,.45)" id="cp-m-sub"></div>
    </div>
    <button onclick="closeModal()" style="background:none;border:none;color:rgba(245,240,232,.45);font-size:1.3rem;cursor:pointer;line-height:1">&times;</button>
  </div>
  <div class="cp-mb" id="cp-m-body">
    <!-- Filled by JS -->
  </div>
  <div class="cp-mf" id="cp-m-foot"></div>
</div>
</div>

<script>
var GROWERS = <?= json_encode(array_map(fn($g) => [
    'id'=>(int)$g['id'],
    'grower_num'=>$g['grower_num'],
    'name'=>$g['name'].' '.$g['surname']
], $growers)) ?>;

/* ── Creditor filter ── */
function filterCreds(){
    var q   = document.getElementById('cred-srch').value.trim().toLowerCase();
    var st  = document.getElementById('cred-status-filter').value;
    var rows= document.querySelectorAll('#cred-tbl tbody tr'), vis=0;
    rows.forEach(function(r){
        var ok = (!q||(r.dataset.s||'').includes(q)) && (!st||r.dataset.status===st);
        r.style.display=ok?'':'none'; if(ok)vis++;
    });
    document.getElementById('cred-cnt').textContent=vis;
}

/* ── SO filter ── */
function filterSOs(){
    var q   = document.getElementById('so-srch').value.trim().toLowerCase();
    var rows= document.querySelectorAll('#so-tbl tbody tr'), vis=0;
    rows.forEach(function(r){
        var ok = !q||(r.dataset.s||'').includes(q);
        r.style.display=ok?'':'none'; if(ok)vis++;
    });
    document.getElementById('so-cnt').textContent=vis;
}

/* ── Modal helpers ── */
function closeModal(){
    document.getElementById('cp-modal').style.display='none';
}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal();});

function xe(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function showModal(title, sub, bodyHtml, footHtml){
    document.getElementById('cp-m-title').textContent = title;
    document.getElementById('cp-m-sub').textContent   = sub;
    document.getElementById('cp-m-body').innerHTML    = bodyHtml;
    document.getElementById('cp-m-foot').innerHTML    = footHtml;
    document.getElementById('cp-modal').style.display = 'block';
}

function toast(msg, type){
    if(typeof fw!=='undefined' && fw.toast) fw.toast(msg, type||'success');
    else alert(msg);
}

/* ══════════════════════════════════════════
   CREDITOR MODAL
══════════════════════════════════════════ */
function openCreditorModal(id){
    if(id){
        fetch('creditor_portal.php?ajax=get_creditor&id='+id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){return r.json();})
        .then(function(d){
            if(!d.ok){toast(d.error,'error');return;}
            renderCreditorModal(d.row);
        });
    } else {
        renderCreditorModal(null);
    }
}

function renderCreditorModal(row){
    var isEdit = !!(row && row.id);
    var f = row || {};
    var statusOpts = ['active','suspended','closed'].map(function(s){
        return '<option value="'+s+'"'+(f.status===s?' selected':'')+'>'+s.charAt(0).toUpperCase()+s.slice(1)+'</option>';
    }).join('');

    var body = '<div class="cp-sect">Identity</div>'
        +'<div class="form-row cols-2">'
        +'<div class="form-group"><label>Creditor Name *</label>'
        +'<input type="text" id="f-name" class="form-control" value="'+xe(f.name||'')+'" placeholder="e.g. AFC Zimbabwe" required></div>'
        +'<div class="form-group"><label>Creditor Number *</label>'
        +'<input type="text" id="f-cno" class="form-control" value="'+xe(f.creditor_no||'')+'" placeholder="e.g. AFC001" style="font-family:monospace;text-transform:uppercase" required></div>'
        +'</div>'
        +'<div class="form-row cols-2">'
        +'<div class="form-group"><label>Creditor Type</label>'
        +'<select id="f-ctype" class="form-control">'
        +'<option value="">— Select —</option>'
        +['Financial Institution','Agricultural Input Supplier','Transport Company','Government Agency','Microfinance','Other'].map(function(t){
            return '<option value="'+t+'"'+(f.creditor_type===t?' selected':'')+'>'+t+'</option>';
        }).join('')+'</select></div>'
        +'<div class="form-group"><label>Business Type</label>'
        +'<select id="f-btype" class="form-control">'
        +['Private','Government','Parastatal','NGO','Other'].map(function(t){
            return '<option value="'+t+'"'+(f.business_type===t?' selected':'')+'>'+t+'</option>';
        }).join('')+'</select></div>'
        +'</div>'
        +'<div class="cp-sect">Contact</div>'
        +'<div class="form-row cols-2">'
        +'<div class="form-group"><label>Phone</label>'
        +'<input type="text" id="f-phone" class="form-control" value="'+xe(f.cell_phone||'')+'" placeholder="+263..."></div>'
        +'<div class="form-group"><label>Email</label>'
        +'<input type="email" id="f-email" class="form-control" value="'+xe(f.email||'')+'"></div>'
        +'</div>'
        +'<div class="cp-sect">Banking</div>'
        +'<div class="form-row cols-2">'
        +'<div class="form-group"><label>Bank Name</label>'
        +'<input type="text" id="f-bank" class="form-control" value="'+xe(f.bank_name||'')+'" placeholder="e.g. CBZ Bank"></div>'
        +'<div class="form-group"><label>Account Number</label>'
        +'<input type="text" id="f-acnum" class="form-control" value="'+xe(f.account_num||'')+'" style="font-family:monospace"></div>'
        +'</div>'
        +(isEdit?'<div class="cp-sect">Status</div><div class="form-group"><select id="f-status" class="form-control">'+statusOpts+'</select></div>':'');

    var foot = (isEdit
        ? '<button class="btn btn-danger btn-sm" onclick="deleteCreditor('+row.id+')">&#128465; Delete</button><span style="flex:1"></span>'
        : '<span style="flex:1"></span>')
        +'<button class="btn btn-ghost btn-sm" onclick="closeModal()">Cancel</button>'
        +'<button class="btn btn-primary" onclick="saveCreditor('+(isEdit?row.id:0)+')">'+(isEdit?'&#10003; Save Changes':'&#10003; Register Creditor')+'</button>';

    showModal(
        isEdit ? 'Edit Creditor' : 'Register New Creditor',
        isEdit ? '#'+xe(f.creditor_no)+' — '+xe(f.name||f.creditor_name) : 'Fill in creditor details below',
        body, foot
    );
    if(!isEdit) setTimeout(function(){var el=document.getElementById('f-name');if(el)el.focus();},100);
}

function saveCreditor(id){
    var name  = document.getElementById('f-name').value.trim();
    var cno   = document.getElementById('f-cno').value.trim().toUpperCase();
    if(!name||!cno){toast('Name and creditor number required','error');return;}
    var fd = new FormData();
    fd.append('id',id);
    fd.append('name',name);
    fd.append('creditor_no',cno);
    fd.append('creditor_name',name);
    fd.append('creditor_type',document.getElementById('f-ctype').value);
    fd.append('business_type',document.getElementById('f-btype').value);
    fd.append('cell_phone',document.getElementById('f-phone').value.trim());
    fd.append('email',document.getElementById('f-email').value.trim());
    fd.append('bank_name',document.getElementById('f-bank').value.trim());
    fd.append('account_num',document.getElementById('f-acnum').value.trim());
    fd.append('status',id&&document.getElementById('f-status')?document.getElementById('f-status').value:'active');
    fetch('creditor_portal.php?ajax=save_creditor',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.ok){toast(d.msg);closeModal();setTimeout(function(){location.reload();},1000);}
        else toast(d.error,'error');
    });
}

function deleteCreditor(id){
    if(!confirm('Delete this creditor?\n\nThis cannot be undone.'))return;
    var fd=new FormData();fd.append('id',id);
    fetch('creditor_portal.php?ajax=delete_creditor',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.ok){toast(d.msg);closeModal();
            var row=document.querySelector('#cred-tbl tr[data-id="'+id+'"]');
            if(row)row.remove();
        } else toast(d.error,'error');
    });
}

/* ══════════════════════════════════════════
   STOP ORDER MODAL
══════════════════════════════════════════ */
function openSOModal(id){
    if(id){
        fetch('creditor_portal.php?ajax=get_stoporder&id='+id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){return r.json();})
        .then(function(d){
            if(!d.ok){toast(d.error,'error');return;}
            renderSOModal(d.row);
        });
    } else {
        renderSOModal(null);
    }
}

function renderSOModal(row){
    var isEdit = !!(row && row.id);
    var f = row || {};

    var growerOpts = '<option value="">— No specific grower —</option>'
        + GROWERS.map(function(g){
            return '<option value="'+g.id+'"'+(parseInt(f.growerid)===g.id?' selected':'')+'>'+xe(g.grower_num+' — '+g.name)+'</option>';
        }).join('');

    var body = '<div class="cp-sect">Stop Order Details</div>'
        +'<div class="form-row cols-2">'
        +'<div class="form-group"><label>Creditor Number</label>'
        +'<input type="text" id="sf-cno" class="form-control" value="'+xe(f.creditor_number||'')+'" placeholder="e.g. AFC001" style="font-family:monospace;text-transform:uppercase"></div>'
        +'<div class="form-group"><label>Description *</label>'
        +'<input type="text" id="sf-desc" class="form-control" value="'+xe(f.description||'')+'" placeholder="e.g. Loan repayment" required></div>'
        +'</div>'
        +'<div class="form-row cols-2">'
        +'<div class="form-group"><label>Amount (USD) *</label>'
        +'<input type="number" id="sf-amt" class="form-control" value="'+xe(f.amount||'')+'" step="0.01" min="0.01" required></div>'
        +'<div class="form-group"><label>Reference</label>'
        +'<input type="text" id="sf-ref" class="form-control" value="'+xe(f.reference||'')+'" placeholder="Loan/contract ref"></div>'
        +'</div>'
        +'<div class="cp-sect">Assignment</div>'
        +'<div class="form-row cols-2">'
        +'<div class="form-group"><label>Grower (optional)</label>'
        +'<select id="sf-gid" class="form-control">'+growerOpts+'</select></div>'
        +'<div class="form-group"><label>Sell Date</label>'
        +'<input type="date" id="sf-sd" class="form-control" value="'+(f.sell_date||'<?=date('Y-m-d')?>')+'" ></div>'
        +'</div>';

    var foot = (isEdit
        ? '<button class="btn btn-danger btn-sm" onclick="deleteStopOrder('+row.id+')">&#128465; Delete</button><span style="flex:1"></span>'
        : '<span style="flex:1"></span>')
        +'<button class="btn btn-ghost btn-sm" onclick="closeModal()">Cancel</button>'
        +'<button class="btn btn-primary" onclick="saveSO('+(isEdit?row.id:0)+')">'+(isEdit?'&#10003; Save Changes':'&#10003; Create Stop Order')+'</button>';

    showModal(
        isEdit ? 'Edit Stop Order' : 'New Stop Order',
        isEdit ? 'SO #'+row.id+' — '+xe(row.description) : 'Create a new stop order',
        body, foot
    );
    if(!isEdit) setTimeout(function(){var el=document.getElementById('sf-cno');if(el)el.focus();},100);
}

function saveSO(id){
    var desc = document.getElementById('sf-desc').value.trim();
    var amt  = parseFloat(document.getElementById('sf-amt').value);
    if(!desc||!(amt>0)){toast('Description and amount required','error');return;}
    var fd=new FormData();
    fd.append('id',id);
    fd.append('creditor_number',document.getElementById('sf-cno').value.trim().toUpperCase());
    fd.append('description',desc);
    fd.append('amount',amt);
    fd.append('reference',document.getElementById('sf-ref').value.trim());
    fd.append('growerid',document.getElementById('sf-gid').value);
    fd.append('sell_date',document.getElementById('sf-sd').value);
    fetch('creditor_portal.php?ajax=save_stoporder',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.ok){toast(d.msg);closeModal();setTimeout(function(){location.reload();},1000);}
        else toast(d.error,'error');
    });
}

function deleteStopOrder(id){
    if(!confirm('Delete Stop Order #'+id+'?\n\nThis cannot be undone.'))return;
    var fd=new FormData();fd.append('id',id);
    fetch('creditor_portal.php?ajax=delete_stoporder',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.ok){toast(d.msg);closeModal();
            var row=document.querySelector('#so-tbl tr[data-id="'+id+'"]');
            if(row)row.remove();
        } else toast(d.error,'error');
    });
}
</script>

<?php include "includes/layout_end.php"; ?>
