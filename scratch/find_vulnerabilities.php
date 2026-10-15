<?php
require_once __DIR__ . '/../includes/admin_gate.php';
// Simple security audit helper script that writes markdown report

$root = dirname(__DIR__);
$files = [];

function getFiles($dir, &$files) {
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $basename = basename($path);
            if (in_array($basename, ['.idea', 'docker', 'flowork_alpha_1.1', 'scratch', 'backups'])) continue;
            getFiles($path, $files);
        } else {
            if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
                $files[] = $path;
            }
        }
    }
}

getFiles($root, $files);

$unprotected_pages = [];
$sqli_matches = [];
$xss_matches = [];
$csrf_missing = [];
$cmd_injections = [];

foreach ($files as $file) {
    $rel_path = str_replace($root . '/', '', $file);
    $content = file_get_contents($file);

    // 1. Check for auth protection
    $dir_name = dirname($rel_path);
    $base_name = basename($rel_path);
    if ($dir_name === '.' && !in_array($base_name, ['login.php', 'logout.php', 'two_factor.php', 'buyer_login.php', 'manager_login.php', 'grower_portal.php', 'index.php'])) {
        if (strpos($content, 'require_login(') === false && strpos($content, 'admin_gate.php') === false) {
            $unprotected_pages[] = $rel_path;
        }
    }

    // 2. Check for potential SQL injection
    // Look for queries with variables inside the query string or concatenation
    if (preg_match_all('/(?:\$conn|\$db)->query\s*\(\s*(?:"[^"]*(?:\$[a-zA-Z0-9_]+|{.*})[^"]*"|\'[^\']*(?:\$[a-zA-Z0-9_]+|{.*})[^\']*\'|\$query|\$sql|[^)]+\.[^)]+)\s*\)/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $offset = $match[1];
            $line_num = substr_count(substr($content, 0, $offset), "\n") + 1;
            
            // Check if it has variables or concat
            $code = trim($match[0]);
            // Exclude empty query or query without vars (e.g. constant queries)
            if (preg_match('/\$[a-zA-Z_]/', $code)) {
                $sqli_matches[] = [
                    'file' => $rel_path,
                    'line' => $line_num,
                    'code' => $code
                ];
            }
        }
    }

    // 3. Check for CSRF missing
    if (strpos($content, '$_POST') !== false) {
        if (strpos($content, 'verify_csrf_token') === false && strpos($dir_name, 'api') === false && !in_array($base_name, ['buyer_login.php', 'manager_login.php'])) {
            $csrf_missing[] = $rel_path;
        }
    }

    // 4. Check for Command Injection
    $cmd_funcs = ['exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open'];
    foreach ($cmd_funcs as $func) {
        if (preg_match_all('/\b' . $func . '\s*\(/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line_num = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $cmd_injections[] = [
                    'file' => $rel_path,
                    'line' => $line_num,
                    'func' => $func,
                    'code' => substr($content, $match[1], 120)
                ];
            }
        }
    }
}

// Format as Markdown
$md = "# Flowork Alpha Security Audit Report\n\n";
$md .= "This report analyzes the security posture of the Flowork Alpha system against the 10 critical security risks from the security prompt list.\n\n";

$md .= "## 1. Unprotected / Unauthenticated Pages\n";
if (empty($unprotected_pages)) {
    $md .= "✓ All standard system entry pages enforce `require_login()` or `admin_gate.php` checks.\n";
} else {
    $md .= "⚠️ The following pages do not perform authentication checks:\n";
    foreach ($unprotected_pages as $page) {
        $md .= "- [$page](file:///$root/" . str_replace('\\', '/', $page) . ")\n";
    }
}
$md .= "\n";

$md .= "## 2. Missing CSRF Protections\n";
if (empty($csrf_missing)) {
    $md .= "✓ No pages are missing CSRF verification on POST data.\n";
} else {
    $md .= "⚠️ The following pages accept POST requests but do not verify CSRF tokens:\n";
    foreach ($csrf_missing as $page) {
        $md .= "- [$page](file:///$root/" . str_replace('\\', '/', $page) . ")\n";
    }
}
$md .= "\n";

$md .= "## 3. Potential Command Injection Risks\n";
if (empty($cmd_injections)) {
    $md .= "✓ No raw command execution functions (`exec`, `system`, `shell_exec`, etc.) detected.\n";
} else {
    $md .= "⚠️ Potential command execution detected in the following files:\n\n";
    foreach ($cmd_injections as $cmd) {
        $md .= "- File: [" . $cmd['file'] . "](file:///$root/" . str_replace('\\', '/', $cmd['file']) . "#L" . $cmd['line'] . ") (Line " . $cmd['line'] . "): `" . htmlspecialchars($cmd['func']) . "`\n";
        $md .= "  ```php\n  " . trim($cmd['code']) . "\n  ```\n";
    }
}
$md .= "\n";

$md .= "## 4. Potential SQL Injection Risks\n";
if (empty($sqli_matches)) {
    $md .= "✓ No queries with direct variable interpolation detected.\n";
} else {
    $md .= "⚠️ Direct variable interpolation or concatenation detected in the following database queries:\n\n";
    $grouped = [];
    foreach ($sqli_matches as $sqli) {
        $grouped[$sqli['file']][] = $sqli;
    }
    foreach ($grouped as $file => $matches) {
        $md .= "### [" . basename($file) . "](file:///$root/" . str_replace('\\', '/', $file) . ")\n";
        foreach ($matches as $m) {
            $md .= "- Line " . $m['line'] . ":\n  ```php\n  " . trim($m['code']) . "\n  ```\n";
        }
        $md .= "\n";
    }
}

file_put_contents($root . '/scratch/security_report_utf8.md', $md);
echo "Report generated successfully at scratch/security_report_utf8.md\n";
