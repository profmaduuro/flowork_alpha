<?php
/**
 * syscheck.php
 * Diagnostic system health script.
 * Requires administrator login and a valid security key.
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

// Access key verification — a second factor on top of require_login() above.
// No hardcoded fallback: the old default was committed to the source tree.
// When no key is configured, fall back to requiring an administrator rather
// than to a guessable constant.
// An administrator gets in on their session alone, so in-app links need not
// carry a secret in the URL. The key remains as an alternative for automated
// callers. There is no hardcoded fallback: the old default was in the source.
$auth_key     = (string)($_GET['key'] ?? '');
$expected_key = getenv('SYSCHECK_KEY') ?: getenv('SETUP_KEY') ?: '';
$key_ok       = is_string($expected_key) && strlen($expected_key) >= 16
                && $auth_key !== '' && hash_equals($expected_key, $auth_key);

if (empty($_SESSION['is_admin']) && !$key_ok) {
    die("<pre style='color:red;padding:20px;font-family:monospace;'>Access Denied: administrator login or a valid SYSCHECK_KEY is required.</pre>");
}

$page_title = "Full System Diagnostics";
$active_page = "system_health";
$spid = (int)($_SESSION['selling_pointid'] ?? 0);

// Run Diagnostic Checks
$diag = [
    'php' => [],
    'db' => [],
    'fs' => [],
    'sec' => []
];

// ── 1. PHP Environment Diagnostics ──────────────────────────────────────────
$diag['php']['version'] = [
    'label' => 'PHP Version',
    'value' => PHP_VERSION,
    'status' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'warn',
    'desc' => 'Requires PHP 7.4.0 or higher (Current: ' . PHP_VERSION . ')'
];

$critical_exts = [
    'mysqli' => 'Required for MySQL Database connection',
    'openssl' => 'Required for stop order decryption (AES)',
    'mbstring' => 'Required for multibyte string operations',
    'curl' => 'Required for TIMB API requests',
    'gd' => 'Required for ticket barcode image rendering',
    'zip' => 'Required for backup compression',
    'json' => 'Required for API JSON serialization'
];

foreach ($critical_exts as $ext => $purpose) {
    $loaded = extension_loaded($ext);
    $diag['php']['ext_' . $ext] = [
        'label' => 'Extension: ' . $ext,
        'value' => $loaded ? 'Loaded' : 'Missing',
        'status' => $loaded ? 'ok' : 'error',
        'desc' => $purpose
    ];
}

$diag['php']['memory_limit'] = [
    'label' => 'PHP Memory Limit',
    'value' => ini_get('memory_limit'),
    'status' => 'ok',
    'desc' => 'Maximum memory a script can consume'
];

$diag['php']['upload_max'] = [
    'label' => 'Max Upload Size',
    'value' => ini_get('upload_max_filesize'),
    'status' => 'ok',
    'desc' => 'Maximum size of uploaded files (TIMB / Creditors schedule)'
];

$diag['php']['post_max'] = [
    'label' => 'Max POST Size',
    'value' => ini_get('post_max_size'),
    'status' => 'ok',
    'desc' => 'Maximum size of POST data accepted by PHP'
];


// ── 2. Database Integrity Diagnostics ────────────────────────────────────────
if ($conn->connect_error) {
    $diag['db']['connection'] = [
        'label' => 'DB Connection',
        'value' => 'Failed',
        'status' => 'error',
        'desc' => $conn->connect_error
    ];
} else {
    $diag['db']['connection'] = [
        'label' => 'DB Connection',
        'value' => 'Success',
        'status' => 'ok',
        'desc' => 'Connected to database server: ' . $conn->host_info
    ];

    // Core Tables Check
    $required_tables = [
        'growers', 'bale_mass', 'bale_junus', 'tickets', 'sold_bales', 'buyers', 
        'transporter', 'grower_creditors', 'stoporders', 'stop_order_keys',
        'internal_stop_order', 'internal_stop_order_amount', 'split_internal_stop_order',
        'balanced_bales', 'daily_totals', 'daily_totals_net_amount', 'users', 'seasons',
        'selling_days', 'exchange_rate', 'sms_log', 'audit_log', 'transporter_growers', 'booked_bales'
    ];

    foreach ($required_tables as $table) {
        $q = $conn->query("SHOW TABLES LIKE '$table'");
        $exists = ($q && $q->num_rows > 0);

        if (!$exists) {
            $diag['db']['table_' . $table] = [
                'label' => 'Table: ' . $table,
                'value' => 'Missing',
                'status' => 'error',
                'desc' => 'Critical table is missing from database schema!'
            ];
        } else {
            // Count rows & check table status
            $count_q = $conn->query("SELECT COUNT(*) c FROM `$table`");
            $rows = $count_q ? (int)$count_q->fetch_assoc()['c'] : 0;
            
            $check_q = $conn->query("CHECK TABLE `$table`");
            $check_status = 'ok';
            $check_msg = 'OK';
            if ($check_q) {
                $status_rows = $check_q->fetch_all(MYSQLI_ASSOC);
                foreach ($status_rows as $sr) {
                    if (strtolower($sr['Msg_type']) === 'status' && strtolower($sr['Msg_text']) !== 'ok') {
                        $check_status = 'warn';
                        $check_msg = $sr['Msg_text'];
                    }
                }
            }

            $diag['db']['table_' . $table] = [
                'label' => 'Table: ' . $table,
                'value' => number_format($rows) . ' rows',
                'status' => $check_status,
                'desc' => 'Table status check: ' . $check_msg
            ];
        }
    }
}


// ── 3. Filesystem Diagnostics ───────────────────────────────────────────────
$paths = [
    'uploads' => __DIR__ . '/uploads',
    'backups' => __DIR__ . '/backups',
    'session' => session_save_path()
];

foreach ($paths as $name => $path) {
    if (!$path) {
        $diag['fs'][$name] = [
            'label' => $name . ' Path',
            'value' => 'Not Configured',
            'status' => 'warn',
            'desc' => 'Default system temporary path will be used.'
        ];
        continue;
    }

    $exists = file_exists($path);
    $writable = $exists && is_writable($path);
    
    $status = 'ok';
    $val = 'Writable';
    if (!$exists) {
        $status = 'error';
        $val = 'Not Found';
        $desc = 'Directory does not exist: ' . $path;
    } elseif (!$writable) {
        $status = 'error';
        $val = 'Read-Only';
        $desc = 'Directory is not writable: ' . $path;
    } else {
        $desc = 'Path: ' . $path;
    }

    $diag['fs'][$name] = [
        'label' => ucfirst($name) . ' Directory',
        'value' => $val,
        'status' => $status,
        'desc' => $desc
    ];
}

// Disk Space Check
$free_bytes = @disk_free_space(__DIR__);
$total_bytes = @disk_total_space(__DIR__);
if ($free_bytes !== false && $total_bytes !== false) {
    $pct_free = round(($free_bytes / $total_bytes) * 100, 1);
    $free_gb = round($free_bytes / 1073741824, 2);
    $diag['fs']['disk_space'] = [
        'label' => 'Disk Space',
        'value' => $pct_free . '% Free',
        'status' => $pct_free < 10 ? 'error' : ($pct_free < 20 ? 'warn' : 'ok'),
        'desc' => $free_gb . ' GB free space remaining'
    ];
}


// ── 4. Security & Encryption Key Diagnostics ───────────────────────────────
if ($spid > 0) {
    $keys_q = $conn->query("SELECT system_key, vector FROM stop_order_keys WHERE selling_pointid=$spid LIMIT 1");
    $keys = ($keys_q && $keys_q !== true) ? $keys_q->fetch_assoc() : null;
    $diag['sec']['stop_order_keys'] = [
        'label' => 'Stop Order Keys',
        'value' => $keys ? 'Configured' : 'Missing',
        'status' => $keys ? 'ok' : 'warn',
        'desc' => $keys ? 'AES encryption key and IV are set for this floor.' : 'No stop order AES keys configured. Decryption will fail.'
    ];

    $timb_q = $conn->query("SELECT COUNT(*) c FROM timb_reports WHERE selling_pointid=$spid");
    $timb_cnt = $timb_q ? (int)$timb_q->fetch_assoc()['c'] : 0;
    $diag['sec']['timb_reporting'] = [
        'label' => 'TIMB Submissions',
        'value' => number_format($timb_cnt) . ' records',
        'status' => 'ok',
        'desc' => 'TIMB API reporting records count.'
    ];
}

// ── 5. Summarize System Diagnostics Status ──────────────────────────────────
$error_count = 0;
$warn_count = 0;
foreach ($diag as $cat => $items) {
    foreach ($items as $item) {
        if ($item['status'] === 'error') $error_count++;
        if ($item['status'] === 'warn') $warn_count++;
    }
}
$overall_status = $error_count > 0 ? 'critical' : ($warn_count > 0 ? 'warning' : 'healthy');

include "includes/layout.php";
?>
<style>
.check-card {
  display: flex;
  flex-direction: column;
  background: var(--cream);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.15rem;
  position: relative;
  overflow: hidden;
  margin-bottom: 1.25rem;
}
.check-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 4px;
  height: 100%;
}
.check-card.status-ok::before { background: var(--leaf); }
.check-card.status-warn::before { background: var(--gold); }
.check-card.status-error::before { background: var(--danger); }

.check-card-title {
  font-size: .75rem;
  font-weight: 500;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: .4rem;
}
.check-card-value {
  font-family: 'Bebas Neue', sans-serif;
  font-size: 1.85rem;
  letter-spacing: .04em;
  line-height: 1.1;
  display: flex;
  align-items: center;
  gap: .5rem;
}
.check-card-value.status-ok { color: var(--leaf); }
.check-card-value.status-warn { color: var(--gold); }
.check-card-value.status-error { color: var(--danger); }

.check-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: .5rem;
}
.check-table tr {
  border-bottom: 1px solid var(--border);
}
.check-table tr:last-child {
  border-bottom: none;
}
.check-table td {
  padding: .75rem .5rem;
  font-size: .82rem;
  vertical-align: middle;
}
.badge-diagnostic {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: .62rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .05em;
}
.badge-diag-ok { background: var(--leaf-l); color: var(--leaf); border: 1px solid rgba(61,107,53,0.3); }
.badge-diag-warn { background: var(--gold-bg); color: #92650e; border: 1px solid rgba(200,149,42,0.3); }
.badge-diag-error { background: var(--danger-bg); color: var(--danger); border: 1px solid rgba(192,57,67,0.3); }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h2>Full System Diagnostics</h2>
    <p>Comprehensive integrity check of PHP environment, Database structure, filesystems, and security modules</p>
  </div>
  <div style="display:flex;gap:.5rem">
    <a href="system_health.php" class="btn btn-ghost btn-sm">← Back to Health Dashboard</a>
    <a href="syscheck.php" class="btn btn-primary btn-sm">⟳ Re-run Diagnostics</a>
  </div>
</div>

<!-- Diagnostics summary card -->
<div class="card mb-3" style="border-left: 3px solid <?= $overall_status === 'healthy' ? 'var(--leaf)' : ($overall_status === 'warning' ? 'var(--gold)' : 'var(--danger)') ?>">
  <div class="card-head">
    <h3>System Status Summary</h3>
    <span class="badge <?= $overall_status === 'healthy' ? 'badge-green' : ($overall_status === 'warning' ? 'badge-gold' : 'badge-red') ?>">
      <?= strtoupper($overall_status) ?>
    </span>
  </div>
  <div class="card-body" style="display:flex;gap:1.5rem;align-items:center;padding:1.25rem;">
    <div style="font-size:2.5rem;">
      <?= $overall_status === 'healthy' ? '💚' : ($overall_status === 'warning' ? '💛' : '❤️') ?>
    </div>
    <div>
      <h4 style="font-size:.9rem;font-weight:600;margin-bottom:.2rem;">
        <?= $overall_status === 'healthy' ? 'Your system is fully operational.' : ($overall_status === 'warning' ? 'System warnings detected.' : 'Critical system issues detected!') ?>
      </h4>
      <p style="font-size:.78rem;color:var(--muted)">
        Diagnostics completed with <strong><?= $error_count ?> critical errors</strong> and <strong><?= $warn_count ?> configuration warnings</strong>.
      </p>
    </div>
  </div>
</div>

<div class="grid-2">
  
  <!-- Left Side: PHP & Filesystems -->
  <div style="display:flex;flex-direction:column;gap:1.25rem">
    
    <!-- PHP Configuration Card -->
    <div class="card">
      <div class="card-head">
        <h3>⚙️ PHP Environment & Configuration</h3>
      </div>
      <div class="card-body" style="padding:0 1.15rem">
        <table class="check-table">
          <tbody>
            <?php foreach($diag['php'] as $item): ?>
            <tr>
              <td style="width:35%;font-weight:500;"><?= htmlspecialchars($item['label']) ?></td>
              <td style="width:20%;">
                <span class="badge-diagnostic <?= $item['status'] === 'ok' ? 'badge-diag-ok' : ($item['status'] === 'warn' ? 'badge-diag-warn' : 'badge-diag-error') ?>">
                  <?= htmlspecialchars($item['value']) ?>
                </span>
              </td>
              <td style="color:var(--muted);font-size:.75rem;"><?= htmlspecialchars($item['desc']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Filesystem Status Card -->
    <div class="card">
      <div class="card-head">
        <h3>📂 Filesystem & Paths</h3>
      </div>
      <div class="card-body" style="padding:0 1.15rem">
        <table class="check-table">
          <tbody>
            <?php foreach($diag['fs'] as $item): ?>
            <tr>
              <td style="width:35%;font-weight:500;"><?= htmlspecialchars($item['label']) ?></td>
              <td style="width:20%;">
                <span class="badge-diagnostic <?= $item['status'] === 'ok' ? 'badge-diag-ok' : ($item['status'] === 'warn' ? 'badge-diag-warn' : 'badge-diag-error') ?>">
                  <?= htmlspecialchars($item['value']) ?>
                </span>
              </td>
              <td style="color:var(--muted);font-size:.75rem;"><?= htmlspecialchars($item['desc']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Security & Encryption -->
    <div class="card">
      <div class="card-head">
        <h3>🔐 Security & Keys</h3>
      </div>
      <div class="card-body" style="padding:0 1.15rem">
        <table class="check-table">
          <tbody>
            <?php foreach($diag['sec'] as $item): ?>
            <tr>
              <td style="width:35%;font-weight:500;"><?= htmlspecialchars($item['label']) ?></td>
              <td style="width:20%;">
                <span class="badge-diagnostic <?= $item['status'] === 'ok' ? 'badge-diag-ok' : ($item['status'] === 'warn' ? 'badge-diag-warn' : 'badge-diag-error') ?>">
                  <?= htmlspecialchars($item['value']) ?>
                </span>
              </td>
              <td style="color:var(--muted);font-size:.75rem;"><?= htmlspecialchars($item['desc']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- Right Side: Database Schema Verification -->
  <div>
    
    <div class="card">
      <div class="card-head">
        <h3>🗄️ Database Tables Verification</h3>
      </div>
      <div class="card-body" style="padding:0 1.15rem">
        <table class="check-table">
          <tbody>
            <?php foreach($diag['db'] as $name => $item): ?>
            <tr>
              <td style="width:40%;font-weight:500;"><?= htmlspecialchars($item['label']) ?></td>
              <td style="width:25%;">
                <span class="badge-diagnostic <?= $item['status'] === 'ok' ? 'badge-diag-ok' : ($item['status'] === 'warn' ? 'badge-diag-warn' : 'badge-diag-error') ?>">
                  <?= htmlspecialchars($item['value']) ?>
                </span>
              </td>
              <td style="color:var(--muted);font-size:.75rem;"><?= htmlspecialchars($item['desc']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

</div>

<?php include "includes/layout_end.php"; ?>
