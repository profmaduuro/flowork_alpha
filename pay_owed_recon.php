<?php
/**
 * pay_owed_recon.php — Paid vs Owed reconciliation.
 *
 * For every grower sale day (one transporter_growers delivery on one date) it
 * compares what the grower's sales sheets say is owed with what was actually
 * paid, and flags the difference. A reviewer can record an explanation for a
 * variance; the review keeps the amounts it was made against, so a later change
 * to either figure shows up as "changed since review".
 *
 * "Owed" comes only from includes/net_amount.php (the single source of a
 * delivery's net payable), so this page always agrees with the Payments page.
 * Bank-statement matching stays on pay_reconcile.php.
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/net_amount.php";
require_login();

$page_title  = 'Paid vs Owed';
$active_page = 'pay_owed_recon';
$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$userid = (int)current_userid();
$msg = $err = '';

const RECON_TOLERANCE = 0.01; // a cent either way counts as matched

$conn->query("CREATE TABLE IF NOT EXISTS payment_recon_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  selling_pointid INT NOT NULL,
  transporter_growersid INT NOT NULL,
  sell_date DATE NOT NULL,
  currency ENUM('usd','zig') NOT NULL DEFAULT 'usd',
  owed_at_review DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid_at_review DECIMAL(14,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NOT NULL DEFAULT '',
  reviewed_by INT NOT NULL,
  reviewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_recon (transporter_growersid, sell_date, currency),
  INDEX idx_sp (selling_pointid)
)");

// ── Filters ──────────────────────────────────────────────────────────────────
function recon_date($v, $fallback) {
    return (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : $fallback;
}
$date_from = recon_date($_GET['from'] ?? '', date('Y') . '-01-01');
$date_to   = recon_date($_GET['to']   ?? '', date('Y-m-d'));
$cur       = ($_GET['currency'] ?? 'usd') === 'zig' ? 'zig' : 'usd';
$STATUSES  = ['all' => 'All', 'attention' => 'Needs attention', 'over' => 'Overpaid',
              'under' => 'Underpaid', 'unpaid' => 'Unpaid', 'no_owed' => 'No owed figure',
              'matched' => 'Matched', 'reviewed' => 'Reviewed'];
$f_status  = array_key_exists($_GET['status'] ?? '', $STATUSES) ? $_GET['status'] : 'attention';
$f_search  = trim((string)($_GET['q'] ?? ''));

// ── Record a review note ─────────────────────────────────────────────────────
// (CSRF is checked centrally by require_login().)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    $r_tg   = (int)($_POST['tgid'] ?? 0);
    $r_date = recon_date($_POST['sell_date'] ?? '', '');
    $r_cur  = ($_POST['currency'] ?? 'usd') === 'zig' ? 'zig' : 'usd';
    $r_note = trim((string)($_POST['note'] ?? ''));
    $r_owed = round((float)($_POST['owed'] ?? 0), 2);
    $r_paid = round((float)($_POST['paid'] ?? 0), 2);

    // The delivery must belong to this floor.
    $chk = $conn->prepare("SELECT 1 FROM transporter_growers tg JOIN growers g ON g.id=tg.growerid
                           WHERE tg.id=? AND g.selling_pointid=? LIMIT 1");
    $chk->bind_param("ii", $r_tg, $spid);
    $chk->execute();
    $on_floor = $chk->get_result()->num_rows > 0;
    $chk->close();

    if (!$r_tg || !$r_date || !$on_floor) {
        $err = "That delivery is not on this floor.";
    } elseif ($r_note === '') {
        $err = "Write a note explaining the variance before marking it reviewed.";
    } else {
        $r_note = mb_substr($r_note, 0, 255);
        $st = $conn->prepare("INSERT INTO payment_recon_reviews
              (selling_pointid, transporter_growersid, sell_date, currency, owed_at_review, paid_at_review, note, reviewed_by)
              VALUES (?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE owed_at_review=VALUES(owed_at_review), paid_at_review=VALUES(paid_at_review),
                                      note=VALUES(note), reviewed_by=VALUES(reviewed_by), reviewed_at=NOW()");
        $st->bind_param("iissddsi", $spid, $r_tg, $r_date, $r_cur, $r_owed, $r_paid, $r_note, $userid);
        $st->execute();
        $st->close();
        audit_log($conn, $spid, $userid, 'payment_recon_reviews', 'review', $r_tg, null,
                  ['sell_date' => $r_date, 'currency' => $r_cur, 'owed' => $r_owed, 'paid' => $r_paid, 'note' => $r_note]);
        $msg = "Review saved.";
    }
}

// ── Load deliveries with what is owed and what was paid ──────────────────────
$net_col = $cur === 'zig' ? 'zim_net_value' : 'usd_net_value';
$df = $conn->real_escape_string($date_from);
$dt = $conn->real_escape_string($date_to);

$sql = "
SELECT d.*,
       ps.id AS psid, COALESCE(ps.status,'') AS pay_status, ps.payment_method, ps.reference_number,
       ps.payment_ref AS run_ref, COALESCE(ps.paid_at, ps.released_at) AS paid_at,
       CASE WHEN ps.status IN ('paid','released')
            THEN COALESCE(NULLIF(ps.amount_paid,0), ps.net_amount, 0) ELSE 0 END AS paid,
       pu.username AS paid_by_name,
       rv.note AS review_note, rv.owed_at_review, rv.paid_at_review, rv.reviewed_at, ru.username AS reviewer
FROM (
    SELECT tg.id AS tgid, DATE(bb.created_at) AS sell_date,
           g.grower_num, TRIM(CONCAT(COALESCE(g.name,''),' ',COALESCE(g.surname,''))) AS grower,
           COUNT(bb.id) AS sheets,
           SUM(dtn.balanced_balesid IS NULL) AS sheets_without_net,
           ROUND(SUM(COALESCE(dtn.$net_col,0)),2) AS owed
    FROM balanced_bales bb
    JOIN transporter_growers tg ON tg.id = bb.transporter_growersid
    JOIN growers g ON g.id = tg.growerid
    " . flowork_net_join('dtn', 'bb.id') . "
    WHERE g.selling_pointid = $spid
      AND DATE(bb.created_at) BETWEEN '$df' AND '$dt'
    GROUP BY tg.id, DATE(bb.created_at)
) d
LEFT JOIN payment_status ps
       ON ps.transporter_growersid = d.tgid AND ps.sell_date = d.sell_date AND ps.currency = '$cur'
LEFT JOIN users pu ON pu.id = COALESCE(ps.paid_by, ps.released_by)
LEFT JOIN payment_recon_reviews rv
       ON rv.transporter_growersid = d.tgid AND rv.sell_date = d.sell_date AND rv.currency = '$cur'
LEFT JOIN users ru ON ru.id = rv.reviewed_by
ORDER BY d.sell_date DESC, d.grower_num";
$res  = $conn->query($sql);
$rows = ($res && $res !== true) ? $res->fetch_all(MYSQLI_ASSOC) : [];
if (!$res) $err = $err ?: "Could not load deliveries: " . $conn->error;

// Payments recorded for a day on which this grower has no sales sheet in range.
$orph_sql = "
SELECT ps.id AS psid, ps.transporter_growersid AS tgid, ps.sell_date, g.grower_num,
       TRIM(CONCAT(COALESCE(g.name,''),' ',COALESCE(g.surname,''))) AS grower,
       COALESCE(NULLIF(ps.amount_paid,0), ps.net_amount, 0) AS paid, ps.payment_method, ps.reference_number,
       ps.payment_ref AS run_ref, COALESCE(ps.paid_at, ps.released_at) AS paid_at
FROM payment_status ps
JOIN transporter_growers tg ON tg.id = ps.transporter_growersid
JOIN growers g ON g.id = tg.growerid
WHERE g.selling_pointid = $spid AND ps.currency = '$cur'
  AND ps.status IN ('paid','released')
  AND ps.sell_date BETWEEN '$df' AND '$dt'
  AND NOT EXISTS (SELECT 1 FROM balanced_bales bb
                  WHERE bb.transporter_growersid = ps.transporter_growersid
                    AND DATE(bb.created_at) = ps.sell_date)
ORDER BY ps.sell_date DESC";
$orq = $conn->query($orph_sql);
$orphans = ($orq && $orq !== true) ? $orq->fetch_all(MYSQLI_ASSOC) : [];

// ── Classify ─────────────────────────────────────────────────────────────────
function recon_classify(array $r): string {
    $owed = (float)$r['owed']; $paid = (float)$r['paid'];
    $is_paid = in_array($r['pay_status'], ['paid', 'released'], true);
    if (!$is_paid)                          return $owed > RECON_TOLERANCE ? 'unpaid' : 'matched';
    if ($owed <= RECON_TOLERANCE)           return 'no_owed';
    $var = $paid - $owed;
    if ($var >  RECON_TOLERANCE)            return 'over';
    if ($var < -RECON_TOLERANCE)            return 'under';
    return 'matched';
}
$LABEL = ['matched' => ['Matched', 'badge-green'], 'over' => ['Overpaid', 'badge-red'],
          'under' => ['Underpaid', 'badge-gold'], 'unpaid' => ['Unpaid', 'badge-gray'],
          'no_owed' => ['No owed figure', 'badge-red']];

$tot = ['owed' => 0, 'paid' => 0, 'over' => 0, 'under' => 0, 'unpaid_amt' => 0];
$cnt = array_fill_keys(array_keys($LABEL), 0);
$cnt['reviewed'] = 0;
$attention = 0;
$shown = [];
foreach ($rows as $r) {
    $r['class']    = recon_classify($r);
    $r['variance'] = round((float)$r['paid'] - (float)$r['owed'], 2);
    $r['reviewed'] = $r['review_note'] !== null;
    // A review only stands while the amounts are the ones it was made against.
    $r['review_stale'] = $r['reviewed'] && (abs((float)$r['owed_at_review'] - (float)$r['owed']) > RECON_TOLERANCE
                                         || abs((float)$r['paid_at_review'] - (float)$r['paid']) > RECON_TOLERANCE);
    $cnt[$r['class']]++;
    if ($r['reviewed'] && !$r['review_stale']) $cnt['reviewed']++;

    $tot['owed'] += (float)$r['owed'];
    $tot['paid'] += (float)$r['paid'];
    if ($r['class'] === 'over')   $tot['over']  += $r['variance'];
    if ($r['class'] === 'under')  $tot['under'] += -$r['variance'];
    if ($r['class'] === 'unpaid') $tot['unpaid_amt'] += (float)$r['owed'];

    $needs = in_array($r['class'], ['over', 'under', 'no_owed'], true) && !($r['reviewed'] && !$r['review_stale']);
    if ($needs) $attention++;
    $keep = match ($f_status) {
        'all'       => true,
        'attention' => $needs,
        'reviewed'  => $r['reviewed'],
        default     => $r['class'] === $f_status,
    };
    if ($keep && $f_search !== '') {
        $keep = stripos($r['grower_num'] . ' ' . $r['grower'] . ' ' . $r['reference_number'] . ' ' . $r['run_ref'], $f_search) !== false;
    }
    if ($keep) $shown[] = $r;
}
$attention += count($orphans);

// ── CSV export ───────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="paid_vs_owed_' . $cur . '_' . $date_from . '_' . $date_to . '.csv"');
    $out = fopen('php://output', 'w');
    $C = strtoupper($cur);
    fputcsv($out, ['Sale date', 'Grower #', 'Grower', 'Sheets', "Owed $C", "Paid $C", "Variance $C", 'Status',
                   'Method', 'Receipt / ref', 'Payment run', 'Paid at', 'Paid by', 'Review note', 'Reviewed by', 'Reviewed at']);
    foreach ($shown as $r) {
        fputcsv($out, [$r['sell_date'], $r['grower_num'], $r['grower'], $r['sheets'],
            number_format((float)$r['owed'], 2, '.', ''), number_format((float)$r['paid'], 2, '.', ''),
            number_format($r['variance'], 2, '.', ''), $LABEL[$r['class']][0] . ($r['review_stale'] ? ' (changed since review)' : ''),
            $r['payment_method'], $r['reference_number'], $r['run_ref'], $r['paid_at'], $r['paid_by_name'],
            $r['review_note'], $r['reviewer'], $r['reviewed_at']]);
    }
    foreach ($orphans as $o) {
        fputcsv($out, [$o['sell_date'], $o['grower_num'], $o['grower'], 0, '0.00',
            number_format((float)$o['paid'], 2, '.', ''), number_format((float)$o['paid'], 2, '.', ''),
            'Paid with no sales sheet', $o['payment_method'], $o['reference_number'], $o['run_ref'], $o['paid_at'], '', '', '', '']);
    }
    fclose($out);
    exit;
}

function money($v) { return number_format((float)$v, 2); }
$sym = $cur === 'zig' ? 'ZiG ' : '$';
$qs  = function (array $over = []) use ($date_from, $date_to, $cur, $f_status, $f_search) {
    return '?' . http_build_query(array_merge(['from' => $date_from, 'to' => $date_to, 'currency' => $cur,
                                               'status' => $f_status, 'q' => $f_search], $over));
};

include "includes/layout.php";
?>
<style>
.rc-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.75rem;margin-bottom:1.25rem}
.rc-kpi{background:var(--card,#fff);border:1px solid var(--border);border-radius:10px;padding:.9rem 1rem}
.rc-kpi .l{font-size:.6rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.rc-kpi .v{font-family:'Bebas Neue',sans-serif;font-size:1.7rem;line-height:1.1;font-variant-numeric:tabular-nums}
.rc-kpi .s{font-size:.7rem;color:var(--muted)}
.rc-filters{display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;margin-bottom:1rem}
.rc-filters label{display:block;font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
.rc-tabs{display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.9rem}
.rc-tabs a{font-size:.72rem;padding:.3rem .65rem;border:1px solid var(--border);border-radius:999px;text-decoration:none;color:var(--ink)}
.rc-tabs a.on{background:var(--ink);color:var(--paper,#fff);border-color:var(--ink)}
.rc-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.rc-var-pos{color:var(--danger);font-weight:600}
.rc-var-neg{color:var(--gold-d,#9a6b12);font-weight:600}
.rc-review{font-size:.7rem;color:var(--muted);max-width:260px}
.rc-review form{display:flex;gap:.3rem;margin-top:.25rem}
.rc-review input[type=text]{font-size:.72rem;padding:.25rem .4rem;min-width:0;flex:1}
.rc-stale{color:var(--danger);font-weight:600}
@media(max-width:900px){.rc-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Paid vs Owed</h2>
    <p>Each grower's payment for a sale day checked against what their sales sheets say they are owed.</p>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="<?= htmlspecialchars($qs(['export' => 'csv'])) ?>" class="btn btn-ghost btn-sm">↓ Export CSV</a>
    <a href="pay_reconcile.php" class="btn btn-ghost btn-sm">Bank statement matching →</a>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger" style="margin-bottom:1rem"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="rc-kpis">
  <div class="rc-kpi"><div class="l">Owed (sales sheets)</div><div class="v"><?= $sym . money($tot['owed']) ?></div><div class="s"><?= count($rows) ?> grower sale days</div></div>
  <div class="rc-kpi"><div class="l">Paid</div><div class="v"><?= $sym . money($tot['paid']) ?></div><div class="s"><?= count($rows) - $cnt['unpaid'] ?> paid · <?= $cnt['unpaid'] ?> unpaid</div></div>
  <div class="rc-kpi"><div class="l">Overpaid</div><div class="v" style="color:var(--danger)"><?= $sym . money($tot['over']) ?></div><div class="s"><?= $cnt['over'] ?> grower days</div></div>
  <div class="rc-kpi"><div class="l">Underpaid</div><div class="v"><?= $sym . money($tot['under']) ?></div><div class="s"><?= $cnt['under'] ?> grower days</div></div>
  <div class="rc-kpi"><div class="l">Needs attention</div><div class="v" style="color:<?= $attention ? 'var(--danger)' : 'var(--leaf)' ?>"><?= $attention ?></div><div class="s"><?= $cnt['reviewed'] ?> reviewed · <?= $sym . money($tot['unpaid_amt']) ?> still to pay</div></div>
</div>

<form method="GET" class="rc-filters">
  <div><label for="rc-from">From</label><input id="rc-from" type="date" name="from" class="form-control" value="<?= htmlspecialchars($date_from) ?>"></div>
  <div><label for="rc-to">To</label><input id="rc-to" type="date" name="to" class="form-control" value="<?= htmlspecialchars($date_to) ?>"></div>
  <div><label for="rc-cur">Currency</label>
    <select id="rc-cur" name="currency" class="form-control">
      <option value="usd" <?= $cur === 'usd' ? 'selected' : '' ?>>USD</option>
      <option value="zig" <?= $cur === 'zig' ? 'selected' : '' ?>>ZiG</option>
    </select></div>
  <div style="flex:1;min-width:160px"><label for="rc-q">Search</label><input id="rc-q" type="text" name="q" class="form-control" placeholder="Grower #, name, receipt or run ref" value="<?= htmlspecialchars($f_search) ?>"></div>
  <input type="hidden" name="status" value="<?= htmlspecialchars($f_status) ?>">
  <button class="btn btn-primary btn-sm" type="submit">Apply</button>
</form>

<div class="rc-tabs">
  <?php foreach ($STATUSES as $k => $lbl):
      $n = match ($k) { 'all' => count($rows), 'attention' => $attention, 'reviewed' => $cnt['reviewed'], default => $cnt[$k] ?? 0 }; ?>
    <a href="<?= htmlspecialchars($qs(['status' => $k])) ?>" class="<?= $f_status === $k ? 'on' : '' ?>"><?= htmlspecialchars($lbl) ?> (<?= $n ?>)</a>
  <?php endforeach; ?>
</div>

<?php if ($orphans): ?>
<div class="alert alert-danger" style="margin-bottom:1rem">
  <strong><?= count($orphans) ?> payment(s) have no sales sheet for that day.</strong>
  Check the grower and date on each:
  <?php foreach ($orphans as $o): ?>
    <div style="font-size:.78rem;margin-top:.25rem">
      <?= htmlspecialchars($o['sell_date']) ?> · <?= htmlspecialchars($o['grower_num']) ?> <?= htmlspecialchars($o['grower']) ?>
      · <?= $sym . money($o['paid']) ?> <?= htmlspecialchars((string)$o['payment_method']) ?>
      · <?= htmlspecialchars((string)($o['reference_number'] ?: $o['run_ref'])) ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Sale date</th><th>Grower</th><th class="rc-num">Sheets</th>
        <th class="rc-num">Owed</th><th class="rc-num">Paid</th><th class="rc-num">Variance</th>
        <th>Status</th><th>Payment</th><th>Review</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$shown): ?>
      <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--muted)">
        <?= $f_status === 'attention' ? 'Nothing needs attention for these dates.' : 'No grower sale days match these filters.' ?>
      </td></tr>
    <?php endif; ?>
    <?php foreach ($shown as $r): [$lbl, $badge] = $LABEL[$r['class']]; ?>
      <tr>
        <td style="white-space:nowrap"><?= htmlspecialchars($r['sell_date']) ?></td>
        <td><div class="fw-500"><?= htmlspecialchars($r['grower_num']) ?></div>
            <div style="font-size:.72rem;color:var(--muted)"><?= htmlspecialchars($r['grower']) ?></div></td>
        <td class="rc-num"><?= (int)$r['sheets'] ?>
          <?php if ((int)$r['sheets_without_net'] > 0): ?>
            <div style="font-size:.62rem;color:var(--danger)"><?= (int)$r['sheets_without_net'] ?> without net</div>
          <?php endif; ?></td>
        <td class="rc-num"><?= $sym . money($r['owed']) ?></td>
        <td class="rc-num"><?= $r['pay_status'] === '' || !in_array($r['pay_status'], ['paid','released'], true) ? '—' : $sym . money($r['paid']) ?></td>
        <td class="rc-num <?= $r['variance'] > RECON_TOLERANCE ? 'rc-var-pos' : ($r['variance'] < -RECON_TOLERANCE && $r['class'] !== 'unpaid' ? 'rc-var-neg' : '') ?>">
          <?= $r['class'] === 'unpaid' ? '—' : ($r['variance'] > 0 ? '+' : '') . money($r['variance']) ?></td>
        <td><span class="badge <?= $badge ?>"><?= $lbl ?></span>
          <?php if ($r['pay_status'] === 'on_hold'): ?><div style="font-size:.62rem;color:var(--muted)">on hold</div><?php endif; ?></td>
        <td style="font-size:.72rem">
          <?php if (in_array($r['pay_status'], ['paid','released'], true)): ?>
            <div><?= htmlspecialchars((string)$r['payment_method']) ?></div>
            <div class="td-mono"><?= htmlspecialchars((string)$r['reference_number']) ?></div>
            <?php if ($r['run_ref']): ?><div class="td-mono" style="color:var(--muted)"><?= htmlspecialchars($r['run_ref']) ?></div><?php endif; ?>
            <div style="color:var(--muted)"><?= htmlspecialchars(substr((string)$r['paid_at'], 0, 16)) ?><?= $r['paid_by_name'] ? ' · ' . htmlspecialchars($r['paid_by_name']) : '' ?></div>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="rc-review">
          <?php if ($r['reviewed']): ?>
            <div><?= htmlspecialchars($r['review_note']) ?></div>
            <div><?= htmlspecialchars((string)$r['reviewer']) ?> · <?= htmlspecialchars(substr((string)$r['reviewed_at'], 0, 16)) ?></div>
            <?php if ($r['review_stale']): ?><div class="rc-stale">Amounts changed since this review</div><?php endif; ?>
          <?php endif; ?>
          <?php if (in_array($r['class'], ['over','under','no_owed'], true) && (!$r['reviewed'] || $r['review_stale'])): ?>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="review">
              <input type="hidden" name="tgid" value="<?= (int)$r['tgid'] ?>">
              <input type="hidden" name="sell_date" value="<?= htmlspecialchars($r['sell_date']) ?>">
              <input type="hidden" name="currency" value="<?= $cur ?>">
              <input type="hidden" name="owed" value="<?= htmlspecialchars((string)$r['owed']) ?>">
              <input type="hidden" name="paid" value="<?= htmlspecialchars((string)$r['paid']) ?>">
              <input type="text" name="note" id="rc-note-<?= (int)$r['tgid'] ?>-<?= htmlspecialchars($r['sell_date']) ?>" class="form-control" placeholder="Why does this differ?" maxlength="255" required>
              <button class="btn btn-ghost btn-sm" type="submit">Mark reviewed</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<p style="font-size:.72rem;color:var(--muted);margin-top:.75rem">
  Owed is the stored net payable on each sales sheet (after stop orders, levies and charges), the same figure the Payments page pays from.
  A difference of up to <?= $sym ?>0.01 counts as matched. A review only clears a variance while both amounts stay the same.
</p>

<?php include "includes/layout_end.php"; ?>
