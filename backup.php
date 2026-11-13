<?php
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_login();

// Admin only
$is_admin = (int)($_SESSION['is_admin'] ?? 0) === 1 || !empty($_SESSION['rights']['admin']);
if (!$is_admin) {
    header("Location: dashboard.php"); exit;
}

$page_title  = "Backup & Restore";
$active_page = "backup";
$msg = $err = '';

// ── Detect environment from conn.php ──────────────────────────────────────
$is_docker = getenv('FLOWORK_TRUST_PROCESS_ENV') === '1';
$db_host   = $_db_host ?? 'localhost';
$db_name   = $_db_name ?? 'king';
$db_user   = $_db_user ?? 'root';
$db_pass   = $_db_pass ?? '';
$root_pass = $is_docker ? (string)flowork_env('MYSQL_ROOT_PASSWORD', '') : ''; // Docker root pass

// Backup storage — inside the project so it's accessible
$backup_dir = __DIR__ . '/backups';
if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);

// ── Handle ZIP emergency download ─────────────────────────────────────────
if (isset($_GET['zip_backup'])) {
    $label    = 'emergency_dr';
    $sql_filename = 'flowork_' . $label . '_' . date('Ymd_His') . '.sql';
    $sql_filepath = $backup_dir . '/' . $sql_filename;

    $sql_dump = "-- Flowork Emergency DR Database Backup\n";
    $sql_dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql_dump .= "-- Database: $db_name\n\n";
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $sql_dump .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

    $tables = [];
    $tr = $conn->query("SHOW TABLES");
    while ($t = $tr->fetch_row()) $tables[] = $t[0];

    foreach ($tables as $table) {
        $cs = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row();
        $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql_dump .= $cs[1] . ";\n\n";

        $data = $conn->query("SELECT * FROM `$table`");
        if ($data && $data !== true && $data->num_rows > 0) {
            $cols = [];
            $fi = $data->fetch_fields();
            foreach ($fi as $f) $cols[] = "`{$f->name}`";
            $col_str = implode(',', $cols);

            while ($row = $data->fetch_row()) {
                $vals = array_map(function($v) use ($conn) {
                    if ($v === null) return 'NULL';
                    return "'" . $conn->real_escape_string($v) . "'";
                }, $row);
                $sql_dump .= "INSERT INTO `$table` ($col_str) VALUES (" . implode(',', $vals) . ");\n";
            }
        }
        $sql_dump .= "\n";
    }
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";
    file_put_contents($sql_filepath, $sql_dump);

    $zip_filename = 'flowork_dr_vault_' . date('Ymd_His') . '.zip';
    $zip_filepath = $backup_dir . '/' . $zip_filename;

    $zip = new ZipArchive();
    if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $zip->addFile($sql_filepath, $sql_filename);
        $uploads_dir = __DIR__ . '/uploads';
        if (is_dir($uploads_dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploads_dir),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $file_path = $file->getRealPath();
                    $relative_path = 'uploads/' . substr($file_path, strlen($uploads_dir) + 1);
                    $zip->addFile($file_path, $relative_path);
                }
            }
        }
        $zip->close();

        if (file_exists($zip_filepath)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
            header('Content-Length: ' . filesize($zip_filepath));
            readfile($zip_filepath);
            @unlink($zip_filepath);
            @unlink($sql_filepath);
            exit;
        }
    }
    $err = "Failed to compile ZIP archive.";
}

// ── Handle SQL Integrity Verification ─────────────────────────────────────
$verify_results = null;
if (isset($_GET['verify'])) {
    $file = basename($_GET['verify']);
    $path = $backup_dir . '/' . $file;
    if (file_exists($path) && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
        $content = file_get_contents($path);
        
        $table_count = preg_match_all('/CREATE TABLE/i', $content);
        $insert_count = preg_match_all('/INSERT INTO/i', $content);
        $clean_closure = (strpos(substr($content, -300), 'FOREIGN_KEY_CHECKS=1') !== false || strpos(substr($content, -300), 'FOREIGN_KEY_CHECKS = 1') !== false);
        
        $verify_results = [
            'name' => $file,
            'tables' => $table_count,
            'inserts' => $insert_count,
            'closed' => $clean_closure,
            'size' => round(filesize($path) / 1024, 1),
            'modified' => date('d M Y H:i', filemtime($path))
        ];
    } else {
        $err = "File not found for verification.";
    }
}

// ── Handle DOWNLOAD of existing backup ────────────────────────────────────
if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $path = $backup_dir . '/' . $file;
    if (file_exists($path) && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path); exit;
    }
    $err = "File not found.";
}

// ── Handle DELETE backup ──────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $file = basename($_GET['delete']);
    $path = $backup_dir . '/' . $file;
    if (file_exists($path) && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
        unlink($path);
        $msg = "Backup deleted.";
    }
}

// ── Handle CREATE BACKUP ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup') {
    $label    = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['label'] ?? 'manual');
    $filename = 'flowork_' . $label . '_' . date('Ymd_His') . '.sql';
    $filepath = $backup_dir . '/' . $filename;

    // Generate SQL dump using PHP (works on all environments)
    $sql_dump = "-- Flowork Database Backup\n";
    $sql_dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql_dump .= "-- Database: $db_name\n\n";
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $sql_dump .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

    // Get all tables
    $tables = [];
    $tr = $conn->query("SHOW TABLES");
    while ($t = $tr->fetch_row()) $tables[] = $t[0];

    $total_rows = 0;
    foreach ($tables as $table) {
        // Table structure
        $cs = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row();
        $sql_dump .= "-- Table: $table\n";
        $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql_dump .= $cs[1] . ";\n\n";

        // Table data
        $data = $conn->query("SELECT * FROM `$table`");
        if (!$data || $data->num_rows === 0) continue;

        $cols = [];
        $fi = $data->fetch_fields();
        foreach ($fi as $f) $cols[] = "`{$f->name}`";
        $col_str = implode(',', $cols);

        $rows_in_table = 0;
        while ($row = $data->fetch_row()) {
            $vals = array_map(function($v) use ($conn) {
                if ($v === null) return 'NULL';
                return "'" . $conn->real_escape_string($v) . "'";
            }, $row);
            $sql_dump .= "INSERT INTO `$table` ($col_str) VALUES (" . implode(',', $vals) . ");\n";
            $rows_in_table++;
            $total_rows++;

            // Write in chunks to avoid memory issues
            if ($rows_in_table % 500 === 0) {
                file_put_contents($filepath, $sql_dump, FILE_APPEND);
                $sql_dump = '';
            }
        }
        $sql_dump .= "\n";
    }

    $sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";
    file_put_contents($filepath, $sql_dump, FILE_APPEND);

    if (file_exists($filepath) && filesize($filepath) > 0) {
        $size = round(filesize($filepath) / 1024, 1);
        $msg = "✓ Backup created: $filename ($size KB, $total_rows rows, " . count($tables) . " tables)";
    } else {
        $err = "Backup failed — could not write file.";
    }
}

// ── Handle RESTORE ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    $confirm = $_POST['confirm'] ?? '';
    $source  = $_POST['restore_source'] ?? 'upload';

    if ($confirm !== 'RESTORE') {
        $err = "Type RESTORE to confirm.";
    } else {
        $sql_content = '';

        if ($source === 'upload' && isset($_FILES['sql_file'])) {
            $f = $_FILES['sql_file'];
            if ($f['error'] !== 0) {
                $err = "Upload error code: " . $f['error'];
            } elseif (pathinfo($f['name'], PATHINFO_EXTENSION) !== 'sql') {
                $err = "Only .sql files allowed.";
            } elseif ($f['size'] > 100 * 1024 * 1024) {
                $err = "File too large (max 100MB).";
            } else {
                $sql_content = file_get_contents($f['tmp_name']);
            }
        } elseif ($source === 'existing') {
            $file = basename($_POST['existing_file'] ?? '');
            $path = $backup_dir . '/' . $file;
            if (file_exists($path)) {
                $sql_content = file_get_contents($path);
            } else {
                $err = "Backup file not found.";
            }
        }

        if ($sql_content && !$err) {
            // Execute the SQL dump
            $conn->query("SET FOREIGN_KEY_CHECKS=0");
            $statements = array_filter(
                array_map('trim', preg_split('/;\s*\n/', $sql_content)),
                fn($s) => strlen($s) > 3 && !str_starts_with($s, '--')
            );
            $done = 0; $failed = 0;
            foreach ($statements as $stmt) {
                if ($conn->query($stmt)) $done++;
                else $failed++;
            }
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
            $msg = "✓ Restore complete — $done statements executed" . ($failed ? ", $failed failed" : "") . ".";
        }
    }
}

// ── List existing backups ─────────────────────────────────────────────────
$backups = [];
foreach (glob($backup_dir . '/*.sql') as $f) {
    $backups[] = [
        'name'     => basename($f),
        'size'     => round(filesize($f) / 1024, 1),
        'modified' => filemtime($f),
    ];
}
usort($backups, fn($a,$b) => $b['modified'] - $a['modified']);

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>Backup & Restore</h2>
    <p>Create, download and restore database backups</p>
  </div>
  <span class="badge <?=$is_docker?'badge-green':'badge-gold'?>" style="font-size:.72rem">
    <?=$is_docker?'🐳 Docker':'🖥 Local'?>
  </span>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<style>
/* These card headers sit on a solid ink/red/green background, so their heading
   must inherit the header's light text instead of the muted grey default. */
.card-head[style*="background:var(--ink)"] h3,
.card-head[style*="background:#c0392b"] h3,
.card-head[style*="background: var(--leaf)"] h3,
.card-head[style*="background: var(--danger)"] h3 { color:inherit }
</style>

<div class="premium-tabs-nav" data-tabs="backup"<?= $verify_results ? ' data-force="restore"' : '' ?>>
  <button type="button" class="premium-tab-btn active" data-tab="backups">🗄 Saved Backups</button>
  <button type="button" class="premium-tab-btn" data-tab="create">💾 Create Backup</button>
  <button type="button" class="premium-tab-btn" data-tab="dr">📦 DR Export</button>
  <button type="button" class="premium-tab-btn" data-tab="restore">♻ Restore</button>
</div>

<!-- Create backup -->
<div id="panel-create" class="tab-panel" data-tabs="backup">
<div class="card">
<div class="card-head" style="background:var(--surface-2);color:var(--text)">
  <h3>Create Backup</h3>
  <span class="badge badge-green" style="font-size:.72rem">PHP Export</span>
</div>
<div class="card-body">
  <p style="font-size:.8rem;color:var(--muted);margin-bottom:1rem">
    Creates a full SQL dump of all <?=count($backups)?> existing backup(s).
    Works on Docker, XAMPP and live servers — no shell access required.
  </p>
  <form method="POST">
    <input type="hidden" name="action" value="backup">
    <div class="form-group">
      <label>Backup Label <span style="font-size:.68rem;color:var(--muted)">(optional — added to filename)</span></label>
      <input type="text" name="label" class="form-control" value="manual"
             placeholder="e.g. before_update, daily, weekly"
             style="font-family:monospace">
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%;padding:.7rem">
      💾 Create Backup Now
    </button>
  </form>
</div>
</div>
</div><!-- /panel-create -->

<div id="panel-dr" class="tab-panel" data-tabs="backup">
<div class="card" style="border-left: 3px solid var(--gold)">
<div class="card-head" style="background:var(--surface-2);color:var(--text)">
  <h3>Emergency DR Export Packager</h3>
  <span class="badge badge-gold" style="font-size:.72rem">ZIP Bundle</span>
</div>
<div class="card-body">
  <p style="font-size:.8rem;color:var(--muted);margin-bottom:1rem">
    Compiles the entire system into a single disaster recovery bundle instantly:
  </p>
  <ul style="font-size:.78rem;color:var(--muted);margin-left:1.25rem;margin-bottom:1rem;line-height:1.4">
    <li>Complete active database schema &amp; records export (.sql)</li>
    <li>Grower registration documentation vault uploads directory (/uploads)</li>
  </ul>
  <a href="?zip_backup=1" class="btn btn-gold" style="width:100%;padding:.7rem;justify-content:center">
    ⚡ Pack &amp; Download ZIP Vault
  </a>
</div>
</div>

</div><!-- /panel-dr -->

<!-- Restore -->
<div id="panel-restore" class="tab-panel" data-tabs="backup">
<div class="card">
<div class="card-head" style="background:#c0392b;color:#fff">
  <h3>Restore Database</h3>
  <span style="font-size:.72rem;opacity:.8">⚠ Overwrites existing data</span>
</div>
<div class="card-body">
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="restore">

    <div class="form-group">
      <label>Restore from</label>
      <select name="restore_source" class="form-control" id="restore-source"
              onchange="document.getElementById('upload-area').style.display=this.value==='upload'?'block':'none';
                        document.getElementById('existing-area').style.display=this.value==='existing'?'block':'none'">
        <option value="upload">Upload SQL file</option>
        <?php if(!empty($backups)):?>
        <option value="existing">Existing backup</option>
        <?php endif;?>
      </select>
    </div>

    <div id="upload-area" class="form-group">
      <label>SQL File</label>
      <input type="file" name="sql_file" class="form-control" accept=".sql">
      <div style="font-size:.68rem;color:var(--muted);margin-top:3px">Max 100MB · .sql files only</div>
    </div>

    <div id="existing-area" class="form-group" style="display:none">
      <label>Select Backup</label>
      <select name="existing_file" class="form-control">
        <?php foreach($backups as $b):?>
        <option value="<?=htmlspecialchars($b['name'])?>"><?=htmlspecialchars($b['name'])?> (<?=$b['size']?> KB)</option>
        <?php endforeach;?>
      </select>
    </div>

    <div style="background:rgba(192,57,43,.06);border:1px solid rgba(192,57,43,.2);
                border-radius:var(--radius);padding:.75rem;margin-bottom:.85rem;font-size:.78rem">
      <div class="fw-500" style="color:var(--danger);margin-bottom:.4rem">⚠ This will overwrite ALL current data</div>
      <label style="display:flex;gap:.4rem;cursor:pointer;margin-bottom:.3rem">
        <input type="checkbox" required> I understand this will replace the current database
      </label>
      <label style="display:flex;gap:.4rem;cursor:pointer">
        <input type="checkbox" required> I have verified the backup file is correct
      </label>
    </div>

    <div class="form-group">
      <label>Type <strong>RESTORE</strong> to confirm *</label>
      <input type="text" name="confirm" class="form-control"
             placeholder="RESTORE" style="font-family:monospace;letter-spacing:.1em" required>
    </div>

    <button type="submit" class="btn" style="width:100%;padding:.7rem;background:#c0392b;color:#fff;border:none">
      ⚠ Restore Database
    </button>
  </form>
</div>
</div>

<?php if ($verify_results): ?>
<div class="card mb-3" style="border: 2px solid <?= $verify_results['closed'] ? 'var(--leaf)' : 'var(--danger)' ?>; background: var(--cream-d)">
  <div class="card-head" style="background: <?= $verify_results['closed'] ? 'var(--leaf)' : 'var(--danger)' ?>; color: #fff; padding: .6rem 1rem">
    <h3>🔍 Integrity Verification Results</h3>
    <a href="backup.php" style="color: #fff; text-decoration: none; font-size: 1.1rem">&times;</a>
  </div>
  <div class="card-body" style="padding: 1rem; font-size: .83rem">
    <div style="font-family: monospace; font-size: .8rem; margin-bottom: .6rem; font-weight: 600; color:var(--text)">
      File: <?= htmlspecialchars($verify_results['name']) ?>
    </div>
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem; margin-bottom: .75rem">
      <div style="background:var(--surface); padding: .5rem; border-radius: 4px; border: 1px solid var(--border)">
        <div style="color: var(--muted); font-size:.78rem; ">Tables Found</div>
        <div style="font-size:1.06rem; font-family:'DM Sans',sans-serif; color:var(--text);font-weight:600"><?= $verify_results['tables'] ?></div>
      </div>
      <div style="background:var(--surface); padding: .5rem; border-radius: 4px; border: 1px solid var(--border)">
        <div style="color: var(--muted); font-size:.78rem; ">Insert Statements</div>
        <div style="font-size:1.06rem; font-family:'DM Sans',sans-serif; color:var(--text);font-weight:600"><?= number_format($verify_results['inserts']) ?></div>
      </div>
      <div style="background:var(--surface); padding: .5rem; border-radius: 4px; border: 1px solid var(--border)">
        <div style="color: var(--muted); font-size:.78rem; ">Verification Size</div>
        <div style="font-size:1.06rem; font-family:'DM Sans',sans-serif; color:var(--text);font-weight:600"><?= $verify_results['size'] ?> KB</div>
      </div>
    </div>
    
    <div class="alert <?= $verify_results['closed'] ? 'alert-success' : 'alert-danger' ?>" style="margin: 0; padding: .6rem .8rem; display: flex; align-items: center; gap: 8px">
      <span style="font-size: 1.1rem"><?= $verify_results['closed'] ? '🟢' : '🔴' ?></span>
      <div>
        <strong><?= $verify_results['closed'] ? 'PASSED: Backup Integrity Verified' : 'FAILED: Corrupt / Incomplete Backup' ?></strong><br>
        <span style="font-size: .75rem">
          <?= $verify_results['closed'] 
              ? 'Database script closed cleanly. Foreign key verification sequences are intact and ready to execute.' 
              : 'Warning: The database backup did not terminate cleanly. File may have been truncated or aborted during export.' ?>
        </span>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

</div><!-- /panel-restore -->

<!-- Backup list -->
<div id="panel-backups" class="tab-panel active" data-tabs="backup">
<div class="card">
<div class="card-head">
  <h3>Saved Backups</h3>
  <span class="badge badge-gray"><?=count($backups)?></span>
</div>
<?php if(!empty($backups)):?>
<div class="table-wrap"><table>
<thead><tr>
  <th>Filename</th>
  <th class="text-right">Size</th>
  <th>Created</th>
  <th></th>
</tr></thead>
<tbody>
<?php foreach($backups as $b):?>
<tr>
  <td style="font-family:monospace;font-size:.75rem"><?=htmlspecialchars($b['name'])?></td>
  <td class="td-mono text-right" style="font-size:.75rem"><?=$b['size']?> KB</td>
  <td class="td-muted" style="font-size:.72rem"><?=date('d M Y H:i',$b['modified'])?></td>
  <td style="white-space:nowrap; display:flex; gap:4px">
    <a href="?verify=<?=urlencode($b['name'])?>"
       class="btn btn-ghost btn-sm" style="border-color:var(--gold); color:var(--gold)">🔍 Verify</a>
    <a href="?download=<?=urlencode($b['name'])?>"
       class="btn btn-ghost btn-sm">⬇ Download</a>
    <a href="?delete=<?=urlencode($b['name'])?>"
       class="btn btn-danger btn-sm"
       onclick="return confirm('Delete this backup?')">✕</a>
  </td>
</tr>
<?php endforeach;?>
</tbody>
<tfoot>
<tr style="background:var(--cream-d)">
  <td colspan="2" style="padding:.45rem .9rem;font-size:.72rem;color:var(--muted)">
    Stored in <code>flowork/backups/</code>
  </td>
  <td colspan="2" style="padding:.45rem .9rem;text-align:right">
    <?php
    $total_size = array_sum(array_column($backups,'size'));
    echo number_format($total_size,1) . ' KB total';
    ?>
  </td>
</tr>
</tfoot>
</table></div>
<?php else:?>
<div class="card-body text-center text-muted" style="padding:3rem">
  <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem">💾</div>
  <div class="fw-500">No backups yet</div>
  <div style="font-size:.78rem;margin-top:.35rem">Click "Create Backup Now" to make your first backup</div>
</div>
<?php endif;?>
</div>

</div><!-- /panel-backups -->

<!-- Backup tips -->
<div class="card mt-3" style="border-left:3px solid var(--gold)">
<div class="card-body" style="padding:.75rem 1.15rem">
<div style="font-size:.78rem;display:flex;gap:2rem;flex-wrap:wrap">
  <div>
    <div class="fw-500" style="margin-bottom:.25rem">💡 Best practice</div>
    <div class="td-muted">Create a backup before any major changes, at end of each sell day, and weekly minimum.</div>
  </div>
  <div>
    <div class="fw-500" style="margin-bottom:.25rem">📁 Backup location</div>
    <div class="td-muted">Backups saved to <code>flowork/backups/</code> folder. Download and keep copies offsite.</div>
  </div>
  <div>
    <div class="fw-500" style="margin-bottom:.25rem">🐳 Docker tip</div>
    <div class="td-muted">You can run: <code>docker exec flowork_db mysqldump -u root -pflowork_root king > backup.sql</code></div>
  </div>
  <div>
    <div class="fw-500" style="margin-bottom:.25rem">⏰ Automated Cron</div>
    <div class="td-muted">Configure automated nightly exports via crontab: <code>0 2 * * * php <?= __DIR__ ?>/auto_backup.php</code></div>
  </div>
</div>
</div>
</div>

<?php include "includes/layout_end.php"; ?>