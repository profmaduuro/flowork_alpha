<?php
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

$page_title  = 'Payment Reconciliation';
$active_page = 'pay_reconcile';
$spid        = (int)($_SESSION['selling_pointid'] ?? 0);
$uid         = (int)($_SESSION['userid'] ?? $_SESSION['user_id'] ?? 0);

// ── Create table if not exists ───────────────────────────────────────────────
@$conn->query("CREATE TABLE IF NOT EXISTS pay_reconciliations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  selling_pointid INT NOT NULL,
  batch_ref VARCHAR(80) DEFAULT '',
  grower_num VARCHAR(40) DEFAULT '',
  expected_amount DECIMAL(14,2) DEFAULT 0,
  bank_amount DECIMAL(14,2) DEFAULT 0,
  variance DECIMAL(14,2) DEFAULT 0,
  status ENUM('matched','unmatched','partial','manual') DEFAULT 'unmatched',
  reconciled_by INT DEFAULT NULL,
  reconciled_at TIMESTAMP NULL DEFAULT NULL,
  pay_batchid INT DEFAULT NULL,
  notes VARCHAR(255) DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sp(selling_pointid)
)");

// ── Helper ───────────────────────────────────────────────────────────────────
function qAll($c, $sql) {
    if (!$c) return [];
    $r = @$c->query($sql);
    if (!$r || $r === true) return [];
    return $r->fetch_all(MYSQLI_ASSOC) ?: [];
}

$msg   = '';
$msg_t = '';

// ── Handle manual status override ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'manual_update' && !empty($_POST['rec_id'])) {
        $rid   = (int)$_POST['rec_id'];
        $stat  = in_array($_POST['new_status'] ?? '', ['matched','unmatched','partial','manual'])
                 ? $_POST['new_status'] : 'manual';
        $notes = substr($conn->real_escape_string($_POST['notes'] ?? ''), 0, 255);
        $conn->query("UPDATE pay_reconciliations
            SET status='$stat', notes='$notes', reconciled_by=$uid,
                reconciled_at=NOW()
            WHERE id=$rid AND selling_pointid=$spid");
        $msg   = 'Record updated successfully.';
        $msg_t = 'success';
    }

    if ($action === 'upload_csv' && !empty($_FILES['bank_csv']['tmp_name'])) {
        $file = $_FILES['bank_csv']['tmp_name'];
        $handle = @fopen($file, 'r');
        if (!$handle) {
            $msg = 'Could not open uploaded file.';
            $msg_t = 'danger';
        } else {
            // Parse CSV — columns: date, reference, description, debit, credit
            $header    = null;
            $bank_rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = array_map(fn($h) => strtolower(trim($h)), $row);
                    continue;
                }
                if (count($row) < count($header)) continue;
                $map = array_combine($header, $row);
                $credit = (float)preg_replace('/[^0-9.]/', '', $map['credit'] ?? $map['amount'] ?? '0');
                $ref    = trim($map['reference'] ?? $map['ref'] ?? $map['description'] ?? '');
                if ($credit <= 0) continue;
                $bank_rows[] = ['ref' => $ref, 'credit' => $credit, 'date' => trim($map['date'] ?? '')];
            }
            fclose($handle);

            if (empty($bank_rows)) {
                $msg = 'No valid credit rows found in CSV.';
                $msg_t = 'warning';
            } else {
                // Pull pay_batches expecting payment
                $batches = qAll($conn, "SELECT pb.id, pb.batch_ref,
                    pb.total_amount expected_amount,
                    COALESCE(g.grower_num,'') grower_num
                    FROM pay_batches pb
                    LEFT JOIN growers g ON g.id=pb.growerid
                    WHERE pb.selling_pointid=$spid
                    ORDER BY pb.id DESC
                    LIMIT 500");

                // Build lookup by ref / amount
                $matched_count   = 0;
                $unmatched_count = 0;

                // Clear previous upload for this spid
                $conn->query("DELETE FROM pay_reconciliations WHERE selling_pointid=$spid AND status='unmatched' AND bank_amount=0");

                foreach ($bank_rows as $br) {
                    $bank_ref    = $br['ref'];
                    $bank_credit = $br['credit'];
                    $matched_batch = null;

                    // 1) Try to match by batch_ref substring
                    foreach ($batches as $bat) {
                        if ($bat['batch_ref'] !== '' && stripos($bank_ref, $bat['batch_ref']) !== false) {
                            $matched_batch = $bat;
                            break;
                        }
                    }
                    // 2) If no ref match, try amount match within 1%
                    if (!$matched_batch) {
                        foreach ($batches as $bat) {
                            $exp = (float)$bat['expected_amount'];
                            if ($exp > 0 && abs($exp - $bank_credit) / $exp < 0.01) {
                                $matched_batch = $bat;
                                break;
                            }
                        }
                    }

                    if ($matched_batch) {
                        $exp      = (float)$matched_batch['expected_amount'];
                        $variance = round($bank_credit - $exp, 2);
                        $status   = abs($variance) < 0.01 ? 'matched' : (abs($variance / max($exp,1)) < 0.05 ? 'partial' : 'unmatched');
                        $bref     = $conn->real_escape_string($matched_batch['batch_ref']);
                        $gnum     = $conn->real_escape_string($matched_batch['grower_num']);
                        $bid      = (int)$matched_batch['id'];
                        $conn->query("INSERT INTO pay_reconciliations
                            (selling_pointid, batch_ref, grower_num, expected_amount, bank_amount, variance, status, pay_batchid)
                            VALUES ($spid,'$bref','$gnum',$exp,$bank_credit,$variance,'$status',$bid)
                            ON DUPLICATE KEY UPDATE bank_amount=$bank_credit, variance=$variance, status='$status'");
                        $matched_count++;
                    } else {
                        // Insert as unmatched
                        $bref = $conn->real_escape_string(substr($bank_ref, 0, 80));
                        $conn->query("INSERT INTO pay_reconciliations
                            (selling_pointid, batch_ref, grower_num, expected_amount, bank_amount, variance, status)
                            VALUES ($spid,'$bref','',0,$bank_credit,$bank_credit,'unmatched')");
                        $unmatched_count++;
                    }
                }
                $msg   = "Processed ".count($bank_rows)." bank rows: {$matched_count} matched, {$unmatched_count} unmatched.";
                $msg_t = 'success';
            }
        }
    }
}

// ── CSV export ───────────────────────────────────────────────────────────────
if (!empty($_GET['export'])) {
    $filter = in_array($_GET['export'], ['matched','unmatched','partial','manual']) ? $_GET['export'] : 'all';
    $where  = $filter !== 'all' ? "AND status='$filter'" : '';
    $rows   = qAll($conn, "SELECT batch_ref, grower_num, expected_amount, bank_amount, variance,
        status, notes, created_at FROM pay_reconciliations
        WHERE selling_pointid=$spid $where ORDER BY id DESC");
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="pay_reconciliation_'.date('Ymd').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Batch Ref','Grower Num','Expected Amount','Bank Amount','Variance','Status','Notes','Created']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['batch_ref'],$r['grower_num'],number_format($r['expected_amount'],2),
            number_format($r['bank_amount'],2),number_format($r['variance'],2),$r['status'],$r['notes'],$r['created_at']]);
    }
    fclose($out);
    exit;
}

// ── Read filter ───────────────────────────────────────────────────────────────
$filter_status = '';
if (!empty($_GET['status']) && in_array($_GET['status'], ['matched','unmatched','partial','manual'])) {
    $filter_status = $_GET['status'];
}
$where_filter = $filter_status ? "AND status='$filter_status'" : '';

// ── KPIs ─────────────────────────────────────────────────────────────────────
$kpi = qAll($conn, "SELECT
    COUNT(*) total_records,
    COALESCE(SUM(expected_amount),0) total_expected,
    COALESCE(SUM(bank_amount),0)     total_bank,
    COALESCE(SUM(ABS(variance)),0)   total_variance,
    SUM(CASE WHEN status='matched'  THEN 1 ELSE 0 END) matched_count,
    SUM(CASE WHEN status='unmatched' THEN 1 ELSE 0 END) unmatched_count,
    SUM(CASE WHEN status='partial'  THEN 1 ELSE 0 END) partial_count,
    SUM(CASE WHEN status='manual'   THEN 1 ELSE 0 END) manual_count
    FROM pay_reconciliations WHERE selling_pointid=$spid");
$k = $kpi[0] ?? [];
$total_recs     = (int)($k['total_records'] ?? 0);
$total_expected = (float)($k['total_expected'] ?? 0);
$total_bank     = (float)($k['total_bank'] ?? 0);
$total_variance = (float)($k['total_variance'] ?? 0);
$matched_count  = (int)($k['matched_count'] ?? 0);
$pct_matched    = $total_recs > 0 ? round($matched_count / $total_recs * 100, 1) : 0;

// ── Records ───────────────────────────────────────────────────────────────────
$records = qAll($conn, "SELECT id, batch_ref, grower_num, expected_amount, bank_amount,
    variance, status, notes, pay_batchid, created_at
    FROM pay_reconciliations WHERE selling_pointid=$spid $where_filter
    ORDER BY id DESC LIMIT 200");

include "includes/layout.php";
?>
<style>
/* ── Pay Reconcile Styles ─────────────────────────────────────────── */
@keyframes pr-fade { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

.pr-kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.9rem;margin-bottom:1.75rem}
.pr-kpi{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:1.1rem 1.15rem;
  position:relative;overflow:hidden;transition:box-shadow .18s,transform .18s;animation:pr-fade .45s ease both}
.pr-kpi:hover{box-shadow:0 6px 20px rgba(0,0,0,.07);transform:translateY(-2px)}
.pr-kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:var(--pr-accent,linear-gradient(90deg,var(--gold),var(--gold-l)))}
.pr-kpi-label{font-size:.78rem;font-weight:600;color:var(--muted);margin-bottom:.5rem}
.pr-kpi-value{font-family:'DM Sans',sans-serif;font-size:1.61rem;line-height:1;color:var(--text);font-weight:600}
.pr-kpi-sub{font-size:.7rem;color:var(--muted);margin-top:.35rem}

.pr-card{background:var(--surface);border:1px solid var(--border);border-radius:10px;
  overflow:hidden;margin-bottom:1.25rem;animation:pr-fade .5s ease both}
.pr-card-head{padding:.85rem 1.15rem;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap}
.pr-card-head h3{font-size:.7rem;font-weight:600;letter-spacing:.12em;
  text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:.5rem}
.pr-card-body{padding:1.15rem}

/* Upload zone */
.pr-upload-zone{border:2px dashed var(--border);border-radius:8px;
  padding:1.5rem;text-align:center;transition:border-color .2s,background .2s;cursor:pointer}
.pr-upload-zone:hover,.pr-upload-zone.dragover{border-color:var(--gold);background:var(--gold-bg)}
.pr-upload-zone input[type="file"]{display:none}
.pr-upload-icon{font-size:1.8rem;margin-bottom:.5rem}
.pr-upload-label{font-size:.8rem;color:var(--muted)}
.pr-upload-label strong{color:var(--text)}

/* Progress bar (matched %) */
.pr-progress-track{height:8px;background:var(--cream-d);border-radius:4px;overflow:hidden;margin-top:.5rem}
.pr-progress-fill{height:8px;border-radius:4px;background:linear-gradient(90deg,var(--leaf),#7cc576);transition:width .6s}

/* Table */
.pr-table{width:100%;border-collapse:collapse;font-size:.78rem}
.pr-table thead th{font-size:.78rem;font-weight:600;color:var(--muted);padding:.58rem .85rem;
  text-align:left;border-bottom:1px solid var(--border);background:var(--surface);
  position:sticky;top:0;z-index:2;white-space:nowrap}
.pr-table tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
.pr-table tbody tr:last-child{border-bottom:none}
.pr-table tbody tr:hover{background:var(--surface-2)}
.pr-table tbody td{padding:.55rem .85rem;vertical-align:middle}
.td-num{font-variant-numeric:tabular-nums}
.td-r{text-align:right}

/* Status badges */
.st-matched{background:var(--leaf-l);color:var(--ok-text);border:1px solid #9bcb94}
.st-unmatched{background:var(--danger-bg);color:var(--danger);border:1px solid #f5b8b2}
.st-partial{background:var(--gold-bg);color:var(--warn-text);border:1px solid #e8c96a}
.st-manual{background:var(--info-bg);color:var(--info);border:1px solid #aacde6}

/* Variance colouring */
.var-pos{color:var(--leaf);font-weight:600}
.var-neg{color:var(--danger);font-weight:600}
.var-zero{color:var(--muted)}

/* Filter tabs */
.pr-tabs{display:flex;gap:.35rem;flex-wrap:wrap}
.pr-tab{padding:.3rem .75rem;border-radius:6px;font-size:.72rem;font-weight:500;
  cursor:pointer;text-decoration:none;border:1px solid var(--border);color:var(--text);transition:all .15s}
.pr-tab:hover{border-color:var(--gold);color:var(--gold)}
.pr-tab.active{background:var(--primary-bg);color:var(--primary-text);border-color:var(--primary-bg)}

/* Inline edit modal */
.pr-modal{display:none;position:fixed;inset:0;z-index:2000;
  background:rgba(0,0,0,.45);align-items:center;justify-content:center}
.pr-modal.open{display:flex}
.pr-modal-box{background:var(--surface);border-radius:12px;padding:1.5rem;width:min(460px,95vw);
  box-shadow:0 20px 60px rgba(0,0,0,.3);animation:pr-fade .25s ease both}
.pr-modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem}
.pr-modal-head h4{font-family:'DM Sans',sans-serif;font-size:1.02rem;font-weight:600}
.pr-modal-close{background:none;border:none;cursor:pointer;font-size:1.3rem;color:var(--muted);padding:0;line-height:1}

@media(max-width:900px){.pr-kpi-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.pr-kpi-grid{grid-template-columns:1fr 1fr}.pr-kpi-value{font-size:1.55rem}}
</style>

<!-- PAGE HEADER -->
<div class="page-header">
  <div class="page-header-left">
    <h2>Payment Reconciliation</h2>
    <p>Match payment batches against bank statement — upload CSV to auto-reconcile</p>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="?export=all" class="btn btn-ghost btn-sm">↓ Export All CSV</a>
    <a href="?export=unmatched" class="btn btn-ghost btn-sm" style="color:var(--danger);border-color:var(--danger)">↓ Unmatched</a>
    <a href="pay_batches.php" class="btn btn-primary btn-sm">← Payment Batches</a>
  </div>
</div>

<div class="alert alert-info" style="margin-bottom:1.25rem">
  This page matches an uploaded <strong>bank statement CSV</strong> against payment batches, so it stays empty until a statement is uploaded.
  To check what each grower was paid against what their sales sheets say they are owed, open
  <a href="pay_owed_recon.php"><strong>Paid vs Owed</strong></a>.
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg_t ?>" style="margin-bottom:1.25rem">
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- ══════ KPI STRIP ══════ -->
<div class="pr-kpi-grid">

  <div class="pr-kpi" style="--pr-accent:linear-gradient(90deg,var(--info),#42a5f5)">
    <div class="pr-kpi-label">📋 Total Expected</div>
    <div class="pr-kpi-value">$<?= number_format($total_expected, 0) ?></div>
    <div class="pr-kpi-sub"><?= number_format($total_recs) ?> records loaded</div>
  </div>

  <div class="pr-kpi" style="--pr-accent:linear-gradient(90deg,var(--leaf),#7cc576)">
    <div class="pr-kpi-label">🏦 Total in Bank</div>
    <div class="pr-kpi-value">$<?= number_format($total_bank, 0) ?></div>
    <div class="pr-kpi-sub"><?= $matched_count ?> records matched</div>
  </div>

  <div class="pr-kpi" style="--pr-accent:linear-gradient(90deg,<?= $total_variance > 0 ? 'var(--danger),#e57373' : 'var(--leaf),#7cc576' ?>)">
    <div class="pr-kpi-label">⚖️ Total Variance</div>
    <div class="pr-kpi-value" style="color:<?= $total_variance > 0 ? 'var(--danger)' : 'var(--leaf)' ?>">
      $<?= number_format(abs($total_variance), 2) ?>
    </div>
    <div class="pr-kpi-sub"><?= $total_variance >= 0 ? 'Over-payment' : 'Under-payment' ?></div>
  </div>

  <div class="pr-kpi" style="--pr-accent:linear-gradient(90deg,var(--gold),var(--gold-l))">
    <div class="pr-kpi-label">✅ % Matched</div>
    <div class="pr-kpi-value" style="color:<?= $pct_matched >= 90 ? 'var(--leaf)' : ($pct_matched >= 70 ? 'var(--gold)' : 'var(--danger)') ?>">
      <?= $pct_matched ?>%
    </div>
    <div class="pr-progress-track"><div class="pr-progress-fill" style="width:<?= $pct_matched ?>%"></div></div>
  </div>

</div>

<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:1.25rem;margin-bottom:1.25rem">

  <!-- Upload Panel -->
  <div class="pr-card" style="animation-delay:.1s">
    <div class="pr-card-head">
      <h3>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" style="width:14px;height:14px;opacity:.5"><path d="M3 2h7l3 3v9H3V2z"/><path d="M10 2v3h3"/><path d="M8 7v5M5.5 9.5l2.5-2.5 2.5 2.5"/></svg>
        Upload Bank Statement CSV
      </h3>
    </div>
    <div class="pr-card-body">
      <form method="POST" enctype="multipart/form-data" id="csv-form">
        <input type="hidden" name="action" value="upload_csv">
        <div class="pr-upload-zone" id="drop-zone" onclick="document.getElementById('bank_csv_input').click()">
          <div class="pr-upload-icon">📂</div>
          <div class="pr-upload-label">
            <strong id="csv-filename">Click to select CSV</strong><br>
            or drag &amp; drop here
          </div>
        </div>
        <input type="file" name="bank_csv" id="bank_csv_input" accept=".csv,text/csv" style="display:none">
        <div class="alert alert-info" style="margin-top:.85rem">
          Expected columns: <strong>date, reference, description, debit, credit</strong><br>
          Credits are matched against payment batch amounts ± 1%.
        </div>
        <button type="submit" class="btn btn-gold" style="width:100%;margin-top:.5rem;justify-content:center" id="upload-btn">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" style="width:14px;height:14px"><path d="M2 11v3h12v-3"/><path d="M8 2v9M5 8l3 3 3-3"/></svg>
          Process &amp; Auto-Match
        </button>
      </form>

      <!-- Status summary -->
      <div style="margin-top:1rem;display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
        <?php
        $status_items = [
          ['Matched',   $k['matched_count']   ?? 0, 'badge badge-green'],
          ['Unmatched', $k['unmatched_count'] ?? 0, 'badge badge-red'],
          ['Partial',   $k['partial_count']   ?? 0, 'badge badge-gold'],
          ['Manual',    $k['manual_count']    ?? 0, 'badge badge-blue'],
        ];
        foreach ($status_items as [$lbl, $cnt, $cls]): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;
          padding:.4rem .65rem;background:var(--surface);border-radius:6px;font-size:.75rem">
          <span><?= $lbl ?></span>
          <span class="<?= $cls ?>"><?= (int)$cnt ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Filter tabs + export quick actions -->
  <div class="pr-card" style="animation-delay:.12s">
    <div class="pr-card-head">
      <h3>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" style="width:14px;height:14px;opacity:.5"><path d="M2 4h12M4 8h8M6 12h4"/></svg>
        Filter &amp; Quick Actions
      </h3>
    </div>
    <div class="pr-card-body">
      <!-- Status filter -->
      <div class="form-group">
        <label>Filter by Status</label>
        <div class="pr-tabs">
          <?php
          $tabs = [['', 'All'], ['matched','Matched'], ['unmatched','Unmatched'], ['partial','Partial'], ['manual','Manual']];
          foreach ($tabs as [$val, $lbl]): ?>
          <a href="?status=<?= $val ?>" class="pr-tab <?= $filter_status === $val ? 'active' : '' ?>">
            <?= $lbl ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Export actions -->
      <div class="form-group" style="margin-top:1rem">
        <label>Export Reconciliation Report</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
          <a href="?export=all" class="btn btn-ghost btn-sm" style="justify-content:center">
            ↓ All Records
          </a>
          <a href="?export=matched" class="btn btn-ghost btn-sm" style="justify-content:center;color:var(--leaf);border-color:var(--leaf)">
            ↓ Matched Only
          </a>
          <a href="?export=unmatched" class="btn btn-ghost btn-sm" style="justify-content:center;color:var(--danger);border-color:var(--danger)">
            ↓ Unmatched
          </a>
          <a href="?export=partial" class="btn btn-ghost btn-sm" style="justify-content:center;color:var(--warn-text);border-color:var(--gold)">
            ↓ Partial
          </a>
        </div>
      </div>

      <!-- Clear data -->
      <form method="POST" style="margin-top:.85rem" onsubmit="return confirm('Clear all reconciliation data for this floor?')">
        <input type="hidden" name="action" value="clear_all">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%;justify-content:center">
          🗑 Clear All Reconciliation Data
        </button>
      </form>
    </div>
  </div>

</div>

<!-- Handle clear all -->
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_all') {
    $conn->query("DELETE FROM pay_reconciliations WHERE selling_pointid=$spid");
    echo '<script>location.reload();</script>';
}
?>

<!-- ══════ RECORDS TABLE ══════ -->
<div class="pr-card" style="animation-delay:.18s">
  <div class="pr-card-head">
    <h3>
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" style="width:14px;height:14px;opacity:.5"><rect x="1" y="3" width="14" height="11" rx="1"/><path d="M1 7h14M5 3V2M11 3V2"/></svg>
      Reconciliation Records
      <?php if ($filter_status): ?>
        <span class="badge badge-gray" style="font-size:.72rem;margin-left:.4rem"><?= ucfirst($filter_status) ?></span>
      <?php endif; ?>
    </h3>
    <span class="badge badge-gray"><?= count($records) ?> records</span>
  </div>
  <div class="table-wrap" style="max-height:calc(100vh - var(--top-h) - 2rem);overflow-y:auto">
    <table class="pr-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Batch Ref</th>
          <th>Grower #</th>
          <th class="td-r">Expected</th>
          <th class="td-r">Bank Credit</th>
          <th class="td-r">Variance</th>
          <th>Status</th>
          <th>Notes</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($records)): ?>
        <tr>
          <td colspan="9" style="text-align:center;padding:2.5rem;color:var(--muted)">
            <?php if ($total_recs === 0): ?>
            No reconciliation data yet — upload a bank statement CSV to get started.
            <?php else: ?>
            No records matching the current filter.
            <?php endif; ?>
          </td>
        </tr>
        <?php else: foreach ($records as $rec):
          $st   = $rec['status'];
          $scls = "st-$st";
          $var  = (float)$rec['variance'];
          $vcls = abs($var) < 0.005 ? 'var-zero' : ($var > 0 ? 'var-pos' : 'var-neg');
        ?>
        <tr id="row-<?= $rec['id'] ?>">
          <td class="td-muted td-num"><?= $rec['id'] ?></td>
          <td>
            <?php if ($rec['batch_ref']): ?>
              <?php if ($rec['pay_batchid']): ?>
                <a href="pay_batches.php?batch=<?= $rec['pay_batchid'] ?>" style="color:var(--info)">
                  <?= htmlspecialchars($rec['batch_ref']) ?>
                </a>
              <?php else: ?>
                <span style="font-family:monospace;font-size:.75rem"><?= htmlspecialchars($rec['batch_ref']) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="td-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="td-muted"><?= htmlspecialchars($rec['grower_num'] ?: '—') ?></td>
          <td class="td-num td-r fw-500">
            <?= $rec['expected_amount'] > 0 ? '$'.number_format($rec['expected_amount'], 2) : '<span class="td-muted">—</span>' ?>
          </td>
          <td class="td-num td-r fw-500">$<?= number_format($rec['bank_amount'], 2) ?></td>
          <td class="td-num td-r <?= $vcls ?>">
            <?= $var >= 0 ? '+' : '' ?>$<?= number_format($var, 2) ?>
          </td>
          <td>
            <span class="badge <?= $scls ?>"><?= ucfirst($st) ?></span>
          </td>
          <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($rec['notes']) ?>">
            <span class="td-muted"><?= htmlspecialchars($rec['notes'] ?: '—') ?></span>
          </td>
          <td>
            <button class="btn btn-ghost btn-sm" onclick="openEditModal(<?= $rec['id'] ?>,
              '<?= htmlspecialchars($st, ENT_QUOTES) ?>',
              '<?= htmlspecialchars(addslashes($rec['notes']), ENT_QUOTES) ?>')"
              style="font-size:.68rem;padding:.25rem .6rem">
              ✎ Edit
            </button>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ══════ MANUAL OVERRIDE MODAL ══════ -->
<div class="pr-modal" id="edit-modal">
  <div class="pr-modal-box">
    <div class="pr-modal-head">
      <h4>Manual Override</h4>
      <button class="pr-modal-close" onclick="closeEditModal()">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="manual_update">
      <input type="hidden" name="rec_id" id="modal-rec-id">
      <div class="form-group">
        <label>Status</label>
        <select name="new_status" id="modal-status" class="form-control">
          <option value="matched">Matched</option>
          <option value="unmatched">Unmatched</option>
          <option value="partial">Partial</option>
          <option value="manual">Manual</option>
        </select>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" id="modal-notes" class="form-control"
          rows="3" placeholder="Reconciliation notes…" maxlength="255"></textarea>
      </div>
      <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="btn btn-gold btn-sm">Save Override</button>
      </div>
    </form>
  </div>
</div>

<script>
// Modal
function openEditModal(id, status, notes) {
    document.getElementById('modal-rec-id').value  = id;
    document.getElementById('modal-status').value   = status;
    document.getElementById('modal-notes').value    = notes.replace(/\\n/g,'\n');
    document.getElementById('edit-modal').classList.add('open');
}
function closeEditModal() {
    document.getElementById('edit-modal').classList.remove('open');
}
document.getElementById('edit-modal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

// CSV drag & drop
(function() {
    var zone  = document.getElementById('drop-zone');
    var input = document.getElementById('bank_csv_input');
    var label = document.getElementById('csv-filename');
    if (!zone || !input) return;

    input.addEventListener('change', function() {
        if (this.files[0]) label.textContent = this.files[0].name;
    });
    zone.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });
    zone.addEventListener('dragleave', function() {
        this.classList.remove('dragover');
    });
    zone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        var file = e.dataTransfer.files[0];
        if (file) {
            var dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            label.textContent = file.name;
        }
    });

    // Upload btn spinner
    document.getElementById('csv-form').addEventListener('submit', function() {
        var btn = document.getElementById('upload-btn');
        btn.innerHTML = '<span class="fw-spinner"></span> Processing…';
        btn.disabled = true;
    });
})();
</script>

<?php include "includes/layout_end.php"; ?>
