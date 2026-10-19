<?php
/**
 * pay_daily_recon.php — Daily Reconciliation (read-only).
 *
 * For one sale day on the user's floor: every balanced delivery with bales,
 * mass, gross, charges, stop orders, net USD/ZiG, the rule it was calculated
 * with and the payment made, plus the checks accounts signs off during the
 * pilot (see PILOT_PLAN.md). Same figures and checks as the command-line
 * report maintenance/daily_recon.php (includes/daily_recon_lib.php).
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/daily_recon_lib.php";
require_login();

$page_title  = 'Daily Reconciliation';
$active_page = 'pay_daily_recon';
$spid = (int)($_SESSION['selling_pointid'] ?? 0);

// Default: the most recent sale day with balanced deliveries on this floor.
$date = (string)($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $q = $conn->query("SELECT MAX(DATE(bb.created_at)) d FROM balanced_bales bb JOIN growers g ON g.id=bb.growerid
        WHERE g.selling_pointid=$spid OR bb.selling_pointid=$spid");
    $date = ($q && $q !== true && ($r = $q->fetch_assoc()) && $r['d']) ? $r['d'] : date('Y-m-d');
}
$rep = flowork_daily_recon($conn, $date, $spid);
$t   = $rep['totals'];

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="daily_recon_' . $date . '_floor' . $spid . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rep['csv'] as $line) fputcsv($out, $line);
    fclose($out);
    exit;
}

$days = [];
$dq = $conn->query("SELECT DATE(bb.created_at) d, COUNT(*) n FROM balanced_bales bb JOIN growers g ON g.id=bb.growerid
    WHERE g.selling_pointid=$spid OR bb.selling_pointid=$spid GROUP BY DATE(bb.created_at) ORDER BY d DESC LIMIT 30");
while ($dq && $dq !== true && $r = $dq->fetch_assoc()) $days[] = $r;

$m = fn($v) => number_format((float)$v, 2);
include "includes/layout.php";
?>
<style>
.dr-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.75rem;margin-bottom:1.1rem}
.dr-kpi{background:var(--card,#fff);border:1px solid var(--border);border-radius:10px;padding:.85rem 1rem}
.dr-kpi .l{font-size:.6rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.dr-kpi .v{font-family:'Bebas Neue',sans-serif;font-size:1.6rem;line-height:1.1;font-variant-numeric:tabular-nums}
.dr-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.dr-checks{list-style:none;margin:0;padding:0;display:grid;gap:.35rem;font-size:.8rem}
.dr-checks li{display:flex;gap:.5rem;align-items:baseline}
.dr-tag{font-size:.62rem;font-weight:700;letter-spacing:.06em;padding:1px 7px;border-radius:999px;white-space:nowrap}
.dr-ok{background:rgba(61,107,53,.12);color:var(--leaf,#2d6b25)}
.dr-fail{background:rgba(192,57,43,.12);color:var(--danger,#c0392b)}
.dr-wo{background:rgba(200,149,42,.14);color:var(--gold-d,#9a6b12)}
.dr-filters{display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;margin-bottom:1rem}
.dr-filters label{display:block;font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
@media(max-width:900px){.dr-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Daily Reconciliation</h2>
    <p>Every balanced delivery for one sale day, checked against its bales and payment. Compare with the manual records and sign the day off.</p>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="?date=<?= urlencode($date) ?>&amp;export=csv" class="btn btn-ghost btn-sm">↓ Export CSV</a>
    <a href="pay_owed_recon.php" class="btn btn-ghost btn-sm">Paid vs Owed →</a>
  </div>
</div>

<form method="GET" class="dr-filters">
  <div><label for="dr-date">Sale day</label><input id="dr-date" type="date" name="date" class="form-control" value="<?= htmlspecialchars($date) ?>"></div>
  <?php if ($days): ?>
  <div><label for="dr-recent">Recent sale days</label>
    <select id="dr-recent" class="form-control" onchange="if(this.value)location='?date='+this.value">
      <option value="">—</option>
      <?php foreach ($days as $d): ?><option value="<?= htmlspecialchars($d['d']) ?>" <?= $d['d'] === $date ? 'selected' : '' ?>><?= htmlspecialchars(date('d M Y', strtotime($d['d']))) ?> (<?= (int)$d['n'] ?>)</option><?php endforeach; ?>
    </select></div>
  <?php endif; ?>
  <button class="btn btn-primary btn-sm" type="submit">Show</button>
</form>

<div class="dr-kpis">
  <div class="dr-kpi"><div class="l">Deliveries</div><div class="v"><?= count($rep['rows']) ?></div></div>
  <div class="dr-kpi"><div class="l">Gross USD</div><div class="v">$<?= $m($t['gross']) ?></div></div>
  <div class="dr-kpi"><div class="l">Net USD / ZiG</div><div class="v">$<?= $m($t['net']) ?></div><div style="font-size:.72rem;color:var(--muted)">ZiG <?= $m($t['zig']) ?></div></div>
  <div class="dr-kpi"><div class="l">Paid USD</div><div class="v">$<?= $m($t['paid']) ?></div></div>
  <div class="dr-kpi"><div class="l">Checks</div><div class="v" style="color:<?= $rep['checks'] ? 'var(--danger)' : 'var(--leaf)' ?>"><?= $rep['rows'] ? ($rep['checks'] ? count($rep['checks']) . ' to fix' : 'All OK') : '—' ?></div></div>
</div>

<div class="card" style="margin-bottom:1rem">
  <div class="card-head"><h3>Checks — <?= htmlspecialchars(date('d M Y', strtotime($date))) ?></h3></div>
  <div class="card-body">
    <ul class="dr-checks">
      <?php if (!$rep['rows']): ?><li><span class="dr-tag dr-wo">INFO</span>No balanced deliveries on this day.</li><?php endif; ?>
      <?php foreach ($rep['checks'] as $c): ?><li><span class="dr-tag dr-fail">FAIL</span><?= htmlspecialchars($c) ?></li><?php endforeach; ?>
      <?php foreach ($rep['notes'] as $n): ?><li><span class="dr-tag dr-wo">WRITTEN OFF</span><?= htmlspecialchars($n) ?></li><?php endforeach; ?>
      <?php if ($rep['rows'] && !$rep['checks']): ?><li><span class="dr-tag dr-ok">OK</span>Gross matches the bales, one net and a recorded rule per delivery, every payment equals the stored net.</li><?php endif; ?>
    </ul>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
  <table>
    <thead><tr>
      <th>Grower</th><th>Rule</th><th class="dr-num">Bales</th><th class="dr-num">Mass kg</th><th class="dr-num">Gross</th>
      <th class="dr-num">Charges</th><th class="dr-num">TIMB SO</th><th class="dr-num">Internal SO</th>
      <th class="dr-num">Net USD</th><th class="dr-num">Net ZiG</th><th>Payment</th><th class="dr-num">Paid</th><th class="dr-num">Difference</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rep['rows'] as $r): ?>
      <tr>
        <td><div><?= htmlspecialchars($r['grower']) ?></div><div class="text-muted" style="font-size:.68rem"><?= htmlspecialchars($r['grower_num']) ?> · delivery <?= (int)$r['bbid'] ?></div></td>
        <td style="font-size:.72rem;white-space:nowrap"><?= htmlspecialchars($r['rule']) ?></td>
        <td class="dr-num"><?= (int)$r['sold_bales'] ?></td>
        <td class="dr-num"><?= number_format((float)$r['mass'], 0) ?></td>
        <td class="dr-num"><?= $m($r['gross_usd']) ?></td>
        <td class="dr-num"><?= $m($r['charges_usd']) ?></td>
        <td class="dr-num"><?= $m($r['timb_so']) ?></td>
        <td class="dr-num"><?= $m($r['internal_so']) ?></td>
        <td class="dr-num"><strong><?= $m($r['net_usd']) ?></strong></td>
        <td class="dr-num"><?= $m($r['net_zig']) ?></td>
        <td style="font-size:.72rem"><?= htmlspecialchars((string)$r['pay_status']) ?></td>
        <td class="dr-num"><?= $r['paid'] !== null ? $m($r['paid']) : '' ?></td>
        <td class="dr-num"><?php if ($r['difference'] !== null && abs($r['difference']) >= 0.01): ?>
          <span style="color:<?= $r['written_off'] !== null ? 'var(--gold-d,#9a6b12)' : 'var(--danger)' ?>;font-weight:600"><?= $m($r['difference']) ?></span>
          <?php if ($r['written_off'] !== null): ?><div style="font-size:.62rem;color:var(--muted)">written off</div><?php endif; ?>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rep['rows']): ?>
      <tr style="font-weight:600;border-top:2px solid var(--border)">
        <td>Total</td><td></td><td class="dr-num"><?= (int)$t['bales'] ?></td><td class="dr-num"><?= number_format($t['mass'], 0) ?></td>
        <td class="dr-num"><?= $m($t['gross']) ?></td><td class="dr-num"><?= $m($t['charges']) ?></td><td class="dr-num"><?= $m($t['timb']) ?></td>
        <td class="dr-num"><?= $m($t['internal']) ?></td><td class="dr-num"><?= $m($t['net']) ?></td><td class="dr-num"><?= $m($t['zig']) ?></td>
        <td></td><td class="dr-num"><?= $m($t['paid']) ?></td><td></td>
      </tr>
    <?php else: ?>
      <tr><td colspan="13" class="text-center text-muted" style="padding:2rem">No balanced deliveries on this day.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php include "includes/layout_end.php"; ?>
