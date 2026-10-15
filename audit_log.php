<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "audit.php";
require_login();

$page_title  = "Audit Log";
$active_page = "audit_log";
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$userid      = current_userid();

// Query all selling points for floor filter selection
$sp_q = $conn->query("SELECT id, name FROM selling_point ORDER BY name");
$selling_points = ($sp_q && $sp_q !== true) ? $sp_q->fetch_all(MYSQLI_ASSOC) : [];

// Filters
$f_floor  = isset($_GET['spid']) ? (string)$_GET['spid'] : ($spid > 0 ? (string)$spid : 'all');
$f_user   = (int)($_GET['uid']   ?? 0);
$f_table  = trim($_GET['tbl']  ?? '');
$f_action = trim($_GET['act']  ?? '');
$f_from   = trim($_GET['from'] ?? date('Y-m-d'));
$f_to     = trim($_GET['to']   ?? date('Y-m-d'));
// Dates feed the export filename and HTML, so accept only YYYY-MM-DD.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $f_from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $f_to   = date('Y-m-d');
$f_search = trim($_GET['q'] ?? '');
$page_num = max(1,(int)($_GET['pg'] ?? 1));
$per_page = 50;
$offset   = ($page_num - 1) * $per_page;

$where = ["DATE(sa.created_at) BETWEEN ? AND ?"];
$params = [$f_from, $f_to];
$types = "ss";

if ($f_floor !== 'all' && $f_floor !== '') {
    $where[] = "sa.selling_pointid=?";
    $params[] = (int)$f_floor;
    $types .= "i";
}

if ($f_user) {
    $where[] = "sa.userid=?";
    $params[] = $f_user;
    $types .= "i";
}
if ($f_table !== '') {
    $where[] = "sa.table_name=?";
    $params[] = $f_table;
    $types .= "s";
}
if ($f_action !== '') {
    $where[] = "sa.action=?";
    $params[] = $f_action;
    $types .= "s";
}
if ($f_search !== '') {
    $where[] = "(sa.note LIKE ? OR sa.table_name LIKE ?)";
    $like_search = "%$f_search%";
    $params[] = $like_search;
    $params[] = $like_search;
    $types .= "ss";
}
$w = implode(' AND ', $where);

// Helper function to execute prepared selects safely
function get_rows_prepared($conn, $sql, $params, $types) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = ($res && $res !== true) ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
    return [];
}

// Generate user-friendly summaries from raw action/table/JSON parameters
function get_audit_summary($r) {
    if (!empty($r['note'])) {
        return $r['note'];
    }
    
    $action = strtolower($r['action'] ?? '');
    $table = strtolower($r['table_name'] ?? '');
    $record_id = $r['record_id'];
    
    $old = [];
    $new = [];
    if (!empty($r['old_values'])) { $tmp = @json_decode($r['old_values'], true); if (is_array($tmp)) $old = $tmp; }
    if (!empty($r['new_values'])) { $tmp = @json_decode($r['new_values'], true); if (is_array($tmp)) $new = $tmp; }
    
    $table_label = ucwords(str_replace('_', ' ', $table));
    
    if ($table === 'balanced_bales') {
        if ($action === 'create') {
            $gross = $new['gross'] ?? 0;
            return "Balanced bales for booking ID #{$new['tgid']} (Gross: {$gross})";
        }
        if ($action === 'reverse_processing') {
            return "Reversed bale balancing for booking ID #{$record_id}";
        }
    }
    
    if ($table === 'bale_junus') {
        if ($action === 'split_assign') {
            return "Split and assigned bales for booking ID #{$record_id}";
        }
        if ($action === 'create') {
            return "Created bale assignment record #{$record_id}";
        }
    }
    
    if ($table === 'tickets') {
        if ($action === 'void') {
            return "Voided ticket #{$record_id}";
        }
        return ucfirst($action) . "d ticket #{$record_id}";
    }
    
    if ($table === 'sold_bales') {
        if ($action === 'void') {
            return "Voided sold bale record #{$record_id}";
        }
        if ($action === 're_offer') {
            $old_p = $old['price'] ?? '—';
            $new_p = $new['price'] ?? '—';
            return "Re-offered bale #{$record_id} (Price: {$old_p} → {$new_p})";
        }
    }
    
    if ($table === 'users') {
        if ($action === 'create') {
            $role = $new['role'] ?? 'user';
            return "Created user account '{$new['username']}' with role: {$role}";
        }
        if ($action === 'update') {
            if (isset($new['role'])) {
                return "Updated user #{$record_id} role to: {$new['role']}";
            }
            return "Updated profile for user #{$record_id}";
        }
        if ($action === 'reset_password') {
            return "Reset password for user #{$record_id}";
        }
    }
    
    if ($table === 'user_permissions') {
        if ($action === 'update') {
            if (isset($new['preset'])) {
                return "Updated permissions preset to: {$new['preset']} for user #{$record_id}";
            }
            $cols = array_keys($new);
            $col = reset($cols);
            $val = $new[$col] ?? '';
            return "Updated permission '{$col}' to '{$val}' for user #{$record_id}";
        }
    }
    
    if ($table === 'seasons') {
        if ($action === 'create') {
            return "Created new season '{$new['name']}'";
        }
        if ($action === 'activate') {
            return "Activated season #{$record_id}";
        }
        if ($action === 'close') {
            return "Closed season #{$record_id}";
        }
    }
    
    if ($table === 'void_log') {
        if ($action === 'request') {
            return "Requested ticket void (Ticket ID: {$new['ticket_id']})";
        }
        if ($action === 'approve') {
            return "Approved void request #{$record_id}";
        }
        if ($action === 'reject') {
            return "Rejected void request #{$record_id}";
        }
    }
    
    $desc = ucwords($action) . " " . strtolower($table_label);
    if ($record_id) {
        $desc .= " #{$record_id}";
    }
    
    if ($action === 'update' && !empty($old) && !empty($new)) {
        $diff_keys = [];
        foreach ($new as $k => $v) {
            if (isset($old[$k]) && (string)$old[$k] !== (string)$v) {
                $diff_keys[] = $k;
            }
        }
        if (!empty($diff_keys)) {
            $desc .= " (Changed: " . implode(', ', $diff_keys) . ")";
        }
    }
    
    return $desc;
}

if (isset($_GET['export'])) {
    $format = $_GET['export'];
    $all_rows = get_rows_prepared($conn, "SELECT sa.*, u.username FROM system_audit sa LEFT JOIN users u ON u.id=sa.userid WHERE $w ORDER BY sa.created_at DESC", $params, $types);

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="audit_log_'.$f_from.'_'.$f_to.'.csv"');
        echo "Time,User,Action,Target,Description,IP,Old Values,New Values\r\n";
        foreach ($all_rows as $r) {
            $old_str = $r['old_values'] ? str_replace('"','""',$r['old_values']) : '';
            $new_str = $r['new_values'] ? str_replace('"','""',$r['new_values']) : '';
            
            // Format Target
            $lbl = ucwords(str_replace('_', ' ', $r['table_name']));
            if (strtolower($r['table_name']) === 'system') {
                $target = '—';
            } else {
                $prefix = '';
                $tbl_lower = strtolower($r['table_name']);
                if ($tbl_lower === 'invoices' || $tbl_lower === 'flowork_invoices') {
                    $prefix = 'INV-';
                    $lbl = 'Invoice';
                } elseif ($tbl_lower === 'tickets') {
                    $prefix = 'TK-';
                    $lbl = 'Ticket';
                } elseif ($tbl_lower === 'users') {
                    $lbl = 'User';
                } elseif ($tbl_lower === 'selling_point') {
                    $lbl = 'Floor';
                }
                $target = $lbl;
                if ($r['record_id']) {
                    $target .= ' #' . $prefix . $r['record_id'];
                }
            }

            echo implode(',', [
                '"'.date('Y-m-d H:i:s',strtotime($r['created_at'])).'"',
                '"'.($r['username']??'System').'"',
                '"'.strtoupper($r['action']).'"',
                '"'.$target.'"',
                '"'.str_replace('"','""',get_audit_summary($r)).'"',
                '"'.($r['ip_address']?:'').'"',
                '"'.$old_str.'"',
                '"'.$new_str.'"',
            ])."\r\n";
        }
        exit;
    }

    if ($format === 'pdf') {
        $sp_name = htmlspecialchars($_SESSION['selling_point_name']??'Flowork');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Audit Log</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font-family:Arial,sans-serif;font-size:8px}
h1{font-size:13px;margin-bottom:3px}p{font-size:8px;color:#555;margin-bottom:8px}
table{width:100%;border-collapse:collapse}
th{background:#1a1208;color:#fff;padding:3px 5px;font-size:7.5px;text-align:left}
td{padding:3px 5px;border-bottom:1px solid #eee;font-size:7.5px}
tr:nth-child(even) td{background:#f9f9f9}
.btn{padding:6px 14px;background:#c8952a;color:#fff;border:none;border-radius:4px;cursor:pointer;margin-bottom:10px}
.no-print{} @media print{.no-print{display:none}@page{margin:8mm}}
.act-create{color:#3d6b35}.act-update{color:#c8952a}.act-delete,.act-void{color:#c03a2b}.act-login{color:#555}
</style></head><body>
<div class="no-print"><button class="btn" onclick="window.print()">🖨 Print / Save as PDF</button></div>
<h1>'.$sp_name.' — Audit Log</h1>
<p>Period: '.$f_from.' to '.$f_to.' · Generated: '.date('Y-m-d H:i:s').' · '.count($all_rows).' entries</p>
<table><thead><tr><th>Date/Time</th><th>User</th><th>Action</th><th>Target</th><th>Description</th><th>IP</th></tr></thead><tbody>';
        foreach ($all_rows as $r) {
            $action_colors = ['create'=>'#3d6b35','insert'=>'#3d6b35','add'=>'#3d6b35','update'=>'#c8952a','edit'=>'#c8952a','delete'=>'#c03a2b','login'=>'#555','void'=>'#c03a2b'];
            $col = $action_colors[strtolower($r['action'])]??'#555';
            
            // Format Target
            $lbl = ucwords(str_replace('_', ' ', $r['table_name']));
            if (strtolower($r['table_name']) === 'system') {
                $target = '—';
            } else {
                $prefix = '';
                $tbl_lower = strtolower($r['table_name']);
                if ($tbl_lower === 'invoices' || $tbl_lower === 'flowork_invoices') {
                    $prefix = 'INV-';
                    $lbl = 'Invoice';
                } elseif ($tbl_lower === 'tickets') {
                    $prefix = 'TK-';
                    $lbl = 'Ticket';
                } elseif ($tbl_lower === 'users') {
                    $lbl = 'User';
                } elseif ($tbl_lower === 'selling_point') {
                    $lbl = 'Floor';
                }
                $target = $lbl;
                if ($r['record_id']) {
                    $target .= ' #' . $prefix . $r['record_id'];
                }
            }

            echo '<tr>';
            echo '<td>'.date('Y-m-d H:i:s',strtotime($r['created_at'])).'</td>';
            echo '<td>'.htmlspecialchars($r['username']??'System').'</td>';
            echo '<td style="color:'.$col.';font-weight:600">'.strtoupper(htmlspecialchars($r['action'])).'</td>';
            echo '<td style="font-family:monospace">'.htmlspecialchars($target).'</td>';
            echo '<td>'.htmlspecialchars(substr(get_audit_summary($r),0,80)).'</td>';
            echo '<td style="color:#888">'.htmlspecialchars($r['ip_address']?:'—').'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<script>window.onload=()=>window.print()</script></body></html>';
        exit;
    }
}

// Page data
$total_rows = get_rows_prepared($conn, "SELECT COUNT(*) c FROM system_audit sa WHERE $w", $params, $types);
$total = !empty($total_rows) ? (int)$total_rows[0]['c'] : 0;
$pages = max(1, ceil($total / $per_page));

// Append LIMIT and OFFSET parameters for pagination
$page_params = $params;
$page_types = $types;
$page_params[] = $per_page;
$page_params[] = $offset;
$page_types .= "ii";

$rows = get_rows_prepared($conn, "
    SELECT sa.*, u.username FROM system_audit sa
    LEFT JOIN users u ON u.id=sa.userid
    WHERE $w ORDER BY sa.created_at DESC
    LIMIT ? OFFSET ?
", $page_params, $page_types);

// Filter options
$filter_users = get_rows_prepared($conn, "SELECT DISTINCT sa.userid,u.username FROM system_audit sa LEFT JOIN users u ON u.id=sa.userid WHERE sa.selling_pointid=? AND u.username IS NOT NULL ORDER BY u.username", [$spid], "i");

$tables_res = get_rows_prepared($conn, "SELECT DISTINCT table_name FROM system_audit WHERE selling_pointid=? ORDER BY table_name", [$spid], "i");
$filter_tables = array_column($tables_res, 'table_name');

$actions_res = get_rows_prepared($conn, "SELECT DISTINCT action FROM system_audit WHERE selling_pointid=? ORDER BY action", [$spid], "i");
$filter_actions = array_column($actions_res, 'action');

$ACTION_COLORS = [
    'create'=>'badge-green','insert'=>'badge-green','add'=>'badge-green',
    'update'=>'badge-gold','edit'=>'badge-gold','modify'=>'badge-gold',
    'delete'=>'badge-red','remove'=>'badge-red','void'=>'badge-red',
    'login'=>'badge-gray','logout'=>'badge-gray','view'=>'badge-gray',
    'export'=>'badge-gray','print'=>'badge-gray','import'=>'badge-blue',
    'approve'=>'badge-green','reject'=>'badge-red',
    'reset_password'=>'badge-gold',
];

// Row background tint per action category
$ROW_BG = [
    'create'=>'rgba(61,107,53,.06)','insert'=>'rgba(61,107,53,.06)','add'=>'rgba(61,107,53,.06)','approve'=>'rgba(61,107,53,.06)',
    'update'=>'rgba(200,149,42,.055)','edit'=>'rgba(200,149,42,.055)','modify'=>'rgba(200,149,42,.055)','reset_password'=>'rgba(200,149,42,.055)',
    'delete'=>'rgba(192,58,43,.06)','remove'=>'rgba(192,58,43,.06)','void'=>'rgba(192,58,43,.06)','reject'=>'rgba(192,58,43,.06)',
];

include "includes/layout.php";
?>
<style>
/* ── Audit-specific tweaks ── */
.audit-row { cursor:pointer; transition:background .12s; }
.audit-row:hover td { filter:brightness(.96); }
.audit-expand { display:none; background:var(--cream-d); }
.audit-expand td { padding:.65rem 1rem; }
.audit-expand-inner {
    display:grid; grid-template-columns:1fr 1fr; gap:.75rem;
    max-height:320px; overflow-y:auto;
}
.audit-expand-panel {
    background:#fff; border:1px solid var(--border); border-radius:6px;
    padding:.65rem .85rem; font-size:.73rem;
}
.audit-expand-panel h5 {
    font-size:.57rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.09em; color:var(--muted); margin-bottom:.4rem;
}
.audit-field-row { display:flex; gap:.5rem; padding:.22rem 0; border-bottom:1px solid var(--border); }
.audit-field-row:last-child { border-bottom:none; }
.audit-field-key { min-width:110px; font-family:monospace; color:var(--muted); font-size:.68rem; flex-shrink:0; }
.audit-field-val { flex:1; word-break:break-all; font-size:.72rem; }
.audit-changed-old { color:#c03a2b; }
.audit-changed-new { color:#3d6b35; font-weight:500; }
.diff-arrow { color:var(--muted); margin:0 .2rem; }
@media(max-width:700px){
    .audit-expand-inner { grid-template-columns:1fr; }
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Audit Log</h2>
    <p>Complete record of every action performed in the system</p>
  </div>
  <div style="display:flex;gap:.35rem">
    <a href="?from=<?=htmlspecialchars((string)$f_from)?>&to=<?=htmlspecialchars((string)$f_to)?>&spid=<?=urlencode($f_floor)?>&uid=<?=$f_user?>&tbl=<?=htmlspecialchars((string)$f_table)?>&act=<?=htmlspecialchars((string)$f_action)?>&q=<?=urlencode($f_search)?>&export=csv"
       class="btn btn-ghost btn-sm">⬇ CSV</a>
    <a href="?from=<?=htmlspecialchars((string)$f_from)?>&to=<?=htmlspecialchars((string)$f_to)?>&spid=<?=urlencode($f_floor)?>&uid=<?=$f_user?>&tbl=<?=htmlspecialchars((string)$f_table)?>&act=<?=htmlspecialchars((string)$f_action)?>&q=<?=urlencode($f_search)?>&export=pdf"
       target="_blank" class="btn btn-ghost btn-sm">📄 PDF</a>
  </div>
</div>

<!-- Filter bar -->
<div class="card mb-3">
<div class="card-body" style="padding:.65rem 1rem">
<form method="GET" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
  <div class="form-group" style="margin:0">
    <label style="font-size:.62rem">From</label>
    <input type="date" name="from" class="form-control" value="<?=htmlspecialchars((string)$f_from)?>" style="font-size:.8rem;padding:.35rem .6rem">
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.62rem">To</label>
    <input type="date" name="to" class="form-control" value="<?=htmlspecialchars((string)$f_to)?>" style="font-size:.8rem;padding:.35rem .6rem">
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.62rem">Floor</label>
    <select name="spid" class="form-control" style="font-size:.8rem;padding:.35rem .55rem">
      <option value="all" <?=$f_floor==='all'?'selected':''?>>All Floors</option>
      <?php foreach($selling_points as $sp):?>
      <option value="<?=$sp['id']?>" <?=$f_floor==$sp['id']?'selected':''?>><?=htmlspecialchars($sp['name'])?></option>
      <?php endforeach;?>
    </select>
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.62rem">User</label>
    <select name="uid" class="form-control" style="font-size:.8rem;padding:.35rem .55rem">
      <option value="">All users</option>
      <?php foreach($filter_users as $u):?>
      <option value="<?=$u['userid']?>" <?=$f_user==$u['userid']?'selected':''?>><?=htmlspecialchars($u['username'])?></option>
      <?php endforeach;?>
    </select>
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.62rem">Table</label>
    <select name="tbl" class="form-control" style="font-size:.8rem;padding:.35rem .55rem">
      <option value="">All tables</option>
      <?php foreach($filter_tables as $t):?>
      <option value="<?=$t?>" <?=$f_table===$t?'selected':''?>><?=$t?></option>
      <?php endforeach;?>
    </select>
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.62rem">Action</label>
    <select name="act" class="form-control" style="font-size:.8rem;padding:.35rem .55rem">
      <option value="">All</option>
      <?php foreach($filter_actions as $a):?>
      <option value="<?=$a?>" <?=$f_action===$a?'selected':''?>><?=strtoupper($a)?></option>
      <?php endforeach;?>
    </select>
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.62rem">Search</label>
    <input type="text" name="q" class="form-control" value="<?=htmlspecialchars($f_search)?>" placeholder="Note or table..." style="font-size:.8rem;padding:.35rem .6rem;width:140px">
  </div>
  <button type="submit" class="btn btn-primary btn-sm">Filter</button>
  <a href="audit_log.php" class="btn btn-ghost btn-sm">Reset</a>
</form>
</div>
</div>

<!-- KPI strip -->
<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1rem">
  <div class="kpi-card"><div class="kpi-label">Total Actions</div><div class="kpi-value"><?=number_format($total)?></div><div class="kpi-sub"><?=htmlspecialchars((string)$f_from)?> to <?=htmlspecialchars((string)$f_to)?></div></div>
  <div class="kpi-card"><div class="kpi-label">Unique Users</div><div class="kpi-value"><?=count($filter_users)?></div></div>
  <div class="kpi-card"><div class="kpi-label">Tables Affected</div><div class="kpi-value"><?=count($filter_tables)?></div></div>
  <div class="kpi-card"><div class="kpi-label">Page</div><div class="kpi-value"><?=$page_num?>/<?=$pages?></div><div class="kpi-sub"><?=$per_page?> per page</div></div>
</div>

<!-- Legend -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.75rem;font-size:.68rem;color:var(--muted)">
  <span style="font-size:.6rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em">Color guide:</span>
  <span style="background:rgba(61,107,53,.1);border:1px solid rgba(61,107,53,.25);border-radius:3px;padding:2px 8px;color:#3d6b35">● Create / Add</span>
  <span style="background:rgba(200,149,42,.1);border:1px solid rgba(200,149,42,.25);border-radius:3px;padding:2px 8px;color:#92650e">● Update / Edit</span>
  <span style="background:rgba(192,58,43,.1);border:1px solid rgba(192,58,43,.25);border-radius:3px;padding:2px 8px;color:#c03a2b">● Delete / Void</span>
  <span style="color:var(--muted);font-style:italic">· Click any row to expand diff details</span>
</div>

<div class="card">
<div class="card-head">
  <h3>Activity Log</h3>
  <span class="badge badge-gray"><?=number_format($total)?> entries</span>
</div>
<div class="table-wrap"><table>
<thead><tr>
  <th>Time</th><th>User</th><th>Action</th><th>Target</th><th>Note</th><th>IP Address</th><th style="width:80px;text-align:center">Details</th>
</tr></thead>
<tbody>
<?php if(empty($rows)):?>
<tr><td colspan="7" class="text-center text-muted" style="padding:2rem">No audit entries for this period and filter.</td></tr>
<?php else: foreach($rows as $idx=>$r):
  $act_lc = strtolower($r['action']);
  $badge  = $ACTION_COLORS[$act_lc] ?? 'badge-gray';
  $rowbg  = $ROW_BG[$act_lc] ?? '';
  $has_diff = ($r['old_values'] || $r['new_values']);
  $row_id = 'ar-'.$r['id'];
  
  // Format Target
  $lbl = ucwords(str_replace('_', ' ', $r['table_name']));
  if (strtolower($r['table_name']) === 'system') {
      $target = '—';
  } else {
      $prefix = '';
      $tbl_lower = strtolower($r['table_name']);
      if ($tbl_lower === 'invoices' || $tbl_lower === 'flowork_invoices') {
          $prefix = 'INV-';
          $lbl = 'Invoice';
      } elseif ($tbl_lower === 'tickets') {
          $prefix = 'TK-';
          $lbl = 'Ticket';
      } elseif ($tbl_lower === 'users') {
          $lbl = 'User';
      } elseif ($tbl_lower === 'selling_point') {
          $lbl = 'Floor';
      }
      $target = $lbl;
      if ($r['record_id']) {
          $target .= ' #' . $prefix . $r['record_id'];
      }
  }
?>
<tr class="audit-row" style="background:<?=$rowbg?>" onclick="toggleExpand('<?=$row_id?>')" title="Click to expand details">
  <td class="td-mono" style="font-size:.7rem;white-space:nowrap"><?=date('Y-m-d H:i:s',strtotime($r['created_at']))?></td>
  <td style="font-size:.78rem;font-weight:500"><?=htmlspecialchars($r['username']??'System')?></td>
  <td><span class="badge <?=$badge?>" style="font-size:.6rem"><?=strtoupper(htmlspecialchars($r['action']))?></span></td>
  <td style="font-size:.72rem;font-family:monospace;color:var(--muted)"><?=htmlspecialchars($target)?></td>
  <td style="font-size:.72rem;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
      title="<?=htmlspecialchars(get_audit_summary($r))?>"><?=htmlspecialchars(get_audit_summary($r))?></td>
  <td style="font-size:.68rem;color:var(--muted)"><?=htmlspecialchars($r['ip_address'] ?: '—')?></td>
  <td style="text-align:center">
    <?php if($has_diff):?>
    <span class="badge badge-gold" style="font-size:.58rem;cursor:pointer;padding:2px 6px" title="Click row to expand details">Details</span>
    <?php else:?>
    <span style="font-size:.65rem;color:var(--border)">—</span>
    <?php endif;?>
  </td>
</tr>
<!-- Expandable diff row -->
<tr class="audit-expand" id="<?=$row_id?>">
  <td colspan="7">
    <?php
    $old_data = null; $new_data = null;
    if ($r['old_values']) { $tmp = @json_decode($r['old_values'], true); if (is_array($tmp)) $old_data = $tmp; }
    if ($r['new_values']) { $tmp = @json_decode($r['new_values'], true); if (is_array($tmp)) $new_data = $tmp; }
    $all_keys = array_unique(array_merge(array_keys($old_data??[]), array_keys($new_data??[])));
    ?>
    <?php if(!$all_keys && !$has_diff):?>
    <div style="color:var(--muted);font-size:.78rem;font-style:italic">No field detail recorded for this entry.</div>
    <?php else:?>
    <div style="font-size:.67rem;color:var(--muted);margin-bottom:.45rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em">
      <?=strtoupper($r['action'])?> on <code style="font-size:.68rem"><?=htmlspecialchars($r['table_name'])?></code>
      · Record #<?=$r['record_id']??'—'?>
      · <?=date('d/m/Y H:i:s',strtotime($r['created_at']))?>
      · <?=htmlspecialchars($r['username']??'System')?>
    </div>
    <?php if($all_keys):?>
    <div class="audit-expand-inner">
      <?php if($old_data || $new_data):?>
      <div class="audit-expand-panel">
        <h5><?=$old_data&&$new_data ? 'Field Changes (Before → After)' : ($old_data ? 'Old Values (Before)' : 'New Values (After)')?></h5>
        <?php foreach($all_keys as $k):
          $ov = $old_data ? ($old_data[$k] ?? null) : null;
          $nv = $new_data ? ($new_data[$k] ?? null) : null;
          $changed = ($old_data && $new_data && (string)$ov !== (string)$nv);
        ?>
        <div class="audit-field-row" style="<?=$changed?'background:rgba(200,149,42,.05)':''?>">
          <span class="audit-field-key"><?=htmlspecialchars($k)?></span>
          <span class="audit-field-val">
            <?php if($old_data && $new_data):?>
              <?php if($changed):?>
              <span class="audit-changed-old"><?=htmlspecialchars(substr((string)$ov,0,120))?></span>
              <span class="diff-arrow">→</span>
              <span class="audit-changed-new"><?=htmlspecialchars(substr((string)$nv,0,120))?></span>
              <?php else:?>
              <span style="color:var(--muted)"><?=htmlspecialchars(substr((string)$ov,0,120))?></span>
              <?php endif;?>
            <?php elseif($old_data):?>
              <span class="audit-changed-old"><?=htmlspecialchars(substr((string)$ov,0,120))?></span>
            <?php else:?>
              <span class="audit-changed-new"><?=htmlspecialchars(substr((string)$nv,0,120))?></span>
            <?php endif;?>
          </span>
        </div>
        <?php endforeach;?>
      </div>
      <?php endif;?>
      <!-- Raw JSON panel -->
      <div class="audit-expand-panel">
        <h5>Raw JSON</h5>
        <?php if($old_data):?>
        <div style="margin-bottom:.35rem">
          <div style="font-size:.57rem;color:#c03a2b;font-weight:700;margin-bottom:.2rem">BEFORE</div>
          <pre style="font-size:.62rem;white-space:pre-wrap;word-break:break-all;color:var(--muted);max-height:120px;overflow-y:auto"><?=htmlspecialchars(json_encode($old_data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre>
        </div>
        <?php endif;?>
        <?php if($new_data):?>
        <div>
          <div style="font-size:.57rem;color:#3d6b35;font-weight:700;margin-bottom:.2rem">AFTER</div>
          <pre style="font-size:.62rem;white-space:pre-wrap;word-break:break-all;color:var(--muted);max-height:120px;overflow-y:auto"><?=htmlspecialchars(json_encode($new_data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre>
        </div>
        <?php endif;?>
        <?php if(!$old_data && !$new_data):?>
        <div style="color:var(--muted);font-style:italic;font-size:.73rem">No JSON data recorded.</div>
        <?php endif;?>
      </div>
    </div>
    <?php else:?>
    <div style="color:var(--muted);font-size:.78rem;font-style:italic">No field data recorded for this action.</div>
    <?php endif;?>
    <?php endif;?>
  </td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table></div>

<!-- Pagination -->
<?php if($pages>1):?>
<div style="display:flex;gap:.3rem;padding:.65rem 1rem;justify-content:center;flex-wrap:wrap">
<?php for($p=1;$p<=$pages;$p++):
  $href='?from='.$f_from.'&to='.$f_to.'&spid='.urlencode($f_floor).'&uid='.$f_user.'&tbl='.$f_table.'&act='.$f_action.'&q='.urlencode($f_search).'&pg='.$p;
?>
<a href="<?=$href?>" class="btn <?=$p===$page_num?'btn-primary':'btn-ghost'?> btn-sm" style="min-width:2rem;text-align:center;font-size:.7rem"><?=$p?></a>
<?php endfor;?>
</div>
<?php endif;?>
</div>

<!-- Legacy diff modal (kept for backward compatibility / keyboard shortcut) -->
<div id="diff-modal" style="display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.55);align-items:center;justify-content:center">
  <div style="background:var(--cream);border-radius:10px;padding:1.5rem;max-width:600px;width:90%;max-height:80vh;overflow-y:auto;border:1px solid var(--border)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
      <h3 id="diff-title" style="font-size:.9rem;margin:0">Change Details</h3>
      <button onclick="document.getElementById('diff-modal').style.display='none'" class="btn btn-ghost btn-sm">✕</button>
    </div>
    <div id="diff-body"></div>
  </div>
</div>

<script>
// ── Expandable rows ──────────────────────────────────────────────────────────
function toggleExpand(id) {
    var el = document.getElementById(id);
    if (!el) return;
    var isOpen = el.style.display === 'table-row';
    // Collapse all others
    document.querySelectorAll('.audit-expand').forEach(function(r){ r.style.display='none'; });
    el.style.display = isOpen ? 'none' : 'table-row';
    if (!isOpen) {
        el.scrollIntoView({ behavior:'smooth', block:'nearest' });
    }
}

// ── Legacy diff modal (kept) ─────────────────────────────────────────────────
function showDiff(data) {
    var modal=document.getElementById('diff-modal');
    var title=document.getElementById('diff-title');
    var body =document.getElementById('diff-body');
    title.textContent=data.action.toUpperCase()+' on '+data.table+' — '+data.time;
    var old=data.old?(typeof data.old==='string'?JSON.parse(data.old):data.old):null;
    var nw =data.new?(typeof data.new==='string'?JSON.parse(data.new):data.new):null;
    var keys=Object.keys(Object.assign({},old||{},nw||{}));
    if(!keys.length){body.innerHTML='<p style="color:var(--muted);font-size:.8rem">No field data recorded.</p>';}
    else{
        var html='<table style="width:100%;font-size:.75rem;border-collapse:collapse">';
        html+='<thead><tr><th style="text-align:left;padding:4px 8px;border-bottom:1px solid var(--border)">Field</th>';
        if(old)html+='<th style="text-align:left;padding:4px 8px;border-bottom:1px solid var(--border);color:#c03a2b">Before</th>';
        if(nw) html+='<th style="text-align:left;padding:4px 8px;border-bottom:1px solid var(--border);color:#3d6b35">After</th>';
        html+='</tr></thead><tbody>';
        keys.forEach(function(k){
            var ov=old?(old[k]??'—'):null,nv=nw?(nw[k]??'—'):null;
            var changed=old&&nw&&String(ov)!==String(nv);
            html+='<tr style="background:'+(changed?'rgba(200,149,42,.06)':'')+'">'
                 +'<td style="padding:4px 8px;font-family:monospace;color:var(--muted)">'+k+'</td>';
            if(old)html+='<td style="padding:4px 8px;color:'+(changed?'#c03a2b':'inherit')+'">'+String(ov)+'</td>';
            if(nw) html+='<td style="padding:4px 8px;color:'+(changed?'#3d6b35':'inherit')+'">'+String(nv)+'</td>';
            html+='</tr>';
        });
        html+='</tbody></table>';
        body.innerHTML=html;
    }
    modal.style.display='flex';
}
document.getElementById('diff-modal').addEventListener('click',function(e){if(e.target===this)this.style.display='none';});
</script>

<?php include "includes/layout_end.php"; ?>
