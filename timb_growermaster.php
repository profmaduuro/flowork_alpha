<?php
/**
 * timb_growermaster.php — TIMB grower master web service.
 *
 * Consumes:
 *   /growermasterWebservices     query one or more grower numbers
 *   /growermasterAllWebservices  pull the whole grower database for a floor
 *
 * Transport, auth, logging and mapping all live in includes/timb_ws.php.
 */
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
require_once "includes/auth.php";
require_once "includes/conn.php";
require_once "includes/timb_ws.php";
require_once "includes/pager.php";
require_once "audit.php";
require_login();

$page_title  = "TIMB Grower Master";
$active_page = "timb_growermaster";

$spid   = (int)($_SESSION['selling_pointid'] ?? 0);
$userid = current_userid();
$msg = $err = '';

if (!$spid) {
    $r = $conn->query("SELECT id FROM selling_point WHERE active=1 ORDER BY id LIMIT 1");
    if ($r && $r !== true && $r->num_rows > 0) { $spid = (int)$r->fetch_assoc()['id']; $_SESSION['selling_pointid'] = $spid; }
}

$can_use   = has_right('growers') || has_right('admin');
$can_admin = has_right('admin');

timb_ws_schema($conn);

// Floor code for the grower master ALL service.
$floor_code = '';
$fr = $conn->query("SELECT name, floor_code FROM selling_point WHERE id=$spid LIMIT 1");
$sp_row = ($fr && $fr !== true && $fr->num_rows > 0) ? $fr->fetch_assoc() : [];
$floor_code = strtoupper(trim((string)($sp_row['floor_code'] ?? '')));

$results     = $_SESSION['timb_gm_results'] ?? [];   // last lookup, kept for the import step
$last_call   = null;
$sync_stats  = null;
$diagnostics = null;

/* ── Export / import helpers ───────────────────────────────────────────── */

/** Column key => header label, shared by the lookup and registry exports. */
function timb_gm_export_cols() {
    return [
        'grower_num'=>'Grower No','surname'=>'Surname','name'=>'Name','initials'=>'Initials',
        'national_id'=>'National ID','grower_type'=>'Type','grower_class'=>'Class',
        'farm_name'=>'Farm','area'=>'Area','district'=>'District','province'=>'Province',
        'landholding'=>'Landholding','hectares'=>'Hectares','association'=>'Association',
        'contractor'=>'Contractor','barn_type'=>'Barn','amend_type'=>'Amend',
        'current_reg'=>'Current Reg','next_reg'=>'Next Reg',
        'postal_address1'=>'Postal Address 1','postal_address2'=>'Postal Address 2',
        'email'=>'Email','obligations_fulfilled'=>'Obligations Fulfilled',
    ];
}

/** Stream assoc rows as a UTF-8 (BOM) CSV download, then exit. */
function timb_gm_send_csv($filename, array $headers, array $rows) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");            // BOM so Excel opens UTF-8 correctly
    fputcsv($out, $headers);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

/**
 * Extract grower-number tokens from an uploaded CSV / TXT / XLSX file.
 * Returns raw tokens (uncleaned); the caller runs them through
 * timb_ws_clean_grower_num(). Returns [] on anything unreadable or too large.
 */
function timb_gm_tokens_from_upload(array $file) {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return [];
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK)                          return [];
    if (($file['size']  ?? 0) > 2 * 1024 * 1024)                         return [];  // 2 MB cap

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

    if ($ext === 'xlsx') {
        if (!class_exists('ZipArchive')) return [];
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) return [];
        $out = [];

        // Text lives in the shared string table (and inline strings, below).
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false && preg_match_all('~<t[^>]*>(.*?)</t>~s', $ss, $m)) {
            foreach ($m[1] as $t) $out[] = html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        for ($i = 1; $i <= 12; $i++) {
            $sh = $zip->getFromName("xl/worksheets/sheet$i.xml");
            if ($sh === false) continue;
            // Inline strings.
            if (preg_match_all('~<is>.*?<t[^>]*>(.*?)</t>.*?</is>~s', $sh, $mi)) {
                foreach ($mi[1] as $t) $out[] = html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            // Numeric cells only — skip t="s" cells, whose <v> is a shared-string
            // INDEX, not a value (that text is already pulled from sharedStrings).
            if (preg_match_all('~<c\b([^>]*)>\s*<v>([^<]*)</v>~s', $sh, $mc, PREG_SET_ORDER)) {
                foreach ($mc as $c) {
                    if (strpos($c[1], 't="s"') !== false) continue;
                    $v = trim($c[2]);
                    if ($v !== '' && preg_match('/^\d+(\.\d+)?$/', $v)) $out[] = $v;
                }
            }
        }
        $zip->close();
        return $out;
    }

    // CSV / TXT / anything text: split on commas, semicolons and whitespace.
    $text = (string)file_get_contents($file['tmp_name']);
    $toks = preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    return $toks ?: [];
}

/* ── Export (CSV / Excel) of the last lookup, or of the registry ────────── */
if (isset($_GET['export'])) {
    if (!$can_use) { http_response_code(403); exit('Not permitted.'); }
    require_once 'includes/xlsx_writer.php';

    $cols  = timb_gm_export_cols();
    $which = (string)$_GET['export'];                       // {lookup,registry}_{csv,xlsx}
    $fmt   = (substr($which, -4) === 'xlsx') ? 'xlsx' : 'csv';

    if (strpos($which, 'registry') === 0) {
        $sq = trim($_GET['q'] ?? '');
        $rw = "WHERE r.selling_pointid=$spid";
        if ($sq !== '') {
            $s   = $conn->real_escape_string($sq);
            $rw .= " AND (r.grower_num LIKE '%$s%' OR r.name LIKE '%$s%' OR r.surname LIKE '%$s%' OR r.national_id LIKE '%$s%')";
        }
        $rq  = $conn->query("SELECT r.*, (SELECT g.id FROM growers g WHERE g.grower_num=r.grower_num AND g.selling_pointid=r.selling_pointid LIMIT 1) AS local_id
                             FROM timb_grower_registry r $rw ORDER BY r.grower_num");
        $src = ($rq && $rq !== true) ? $rq->fetch_all(MYSQLI_ASSOC) : [];
        $extra_labels = ['Source', 'Last Synced', 'On This Floor'];
        $extra_of = function ($r) {
            return [(string)($r['source'] ?? ''), (string)($r['last_synced'] ?? ''),
                    !empty($r['local_id']) ? 'Yes' : 'No'];
        };
        $base = 'timb_registry';
    } else {
        $src = $_SESSION['timb_gm_results'] ?? [];
        $extra_labels = ['Local Record'];
        $extra_of = function ($g) {
            $d = $g['diff'] ?? ['local'=>null, 'diffs'=>[]];
            if (empty($d['local']))  return ['Not on this floor'];
            if (empty($d['diffs']))  return ['Matches local'];
            return [count($d['diffs']) . ' difference(s)'];
        };
        $base = 'timb_lookup';
    }

    if (!$src) {
        header('Location: timb_growermaster.php' . (strpos($which, 'registry') === 0 ? '?tab=registry&noexport=1' : '?noexport=1'));
        exit;
    }

    $labels = array_merge(array_values($cols), $extra_labels);
    $rows   = [];
    foreach ($src as $g) {
        $row = [];
        foreach ($cols as $k => $_l) {
            $row[] = ($k === 'hectares') ? (float)($g[$k] ?? 0) : (string)($g[$k] ?? '');
        }
        foreach ($extra_of($g) as $v) $row[] = $v;
        $rows[] = $row;
    }

    $fname = $base . '_' . date('Ymd_His') . '.' . $fmt;
    if ($fmt === 'xlsx') {
        xlsx_send($fname, ['Growers' => array_merge([$labels], $rows)]);
    } else {
        timb_gm_send_csv($fname, $labels, $rows);
    }
}

/* ── Actions ───────────────────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $err = "Security token expired. Please retry.";
        $action = '';
    }
    if ($action && !$can_use) {
        $err = "You do not have permission to use the TIMB grower master service.";
        $action = '';
    }

    /* Query grower master by number ----------------------------------- */
    if ($action === 'lookup') {
        $raw_nums = trim($_POST['grower_numbers'] ?? '');

        // Grower numbers may be typed, uploaded as a file, or both.
        $typed       = preg_split('/[\s,;]+/', $raw_nums, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $typed_clean = array_filter(array_map('timb_ws_clean_grower_num', $typed));

        // An uploaded sheet carries a header row and other columns, so keep only
        // grower-number-shaped tokens (optional leading letter, 3+ digits,
        // optional trailing letter) — never raw header words like "Grower".
        $file_tokens = timb_gm_tokens_from_upload($_FILES['numbers_file'] ?? []);
        $file_clean  = array_filter(array_map('timb_ws_clean_grower_num', $file_tokens),
                                    fn($t) => preg_match('/^[A-Z]?\d{3,}[A-Z]?$/', $t));
        $from_file   = count(array_unique($file_clean));

        $nums = array_values(array_unique(array_merge($typed_clean, $file_clean)));

        if (!$nums) {
            $err = "Enter at least one grower number, or upload a CSV/Excel file containing them.";
        } else {
            // TIMB is queried by URL, so ask for at most 100 numbers per request
            // and send as many requests as the list needs: every number on the
            // list is fetched. Results are shown a page at a time below.
            @set_time_limit(0);
            $batch_size = 100;
            $batches    = array_chunk($nums, $batch_size);
            $by_num = []; $requested = []; $simulated = false; $batch_errs = [];
            foreach ($batches as $bi => $batch) {
                $res = timb_ws_growermaster($conn, $batch, ['spid'=>$spid, 'userid'=>$userid]);
                $last_call = $res;
                if ($res['ok']) {
                    foreach ($res['growers'] as $g) $by_num[$g['grower_num']] = $g;
                    $requested = array_merge($requested, $res['requested']);
                    if (!empty($res['simulated'])) $simulated = true;
                } else {
                    $batch_errs[] = (count($batches) > 1 ? 'request ' . ($bi + 1) . ' of ' . count($batches) . ': ' : '') . $res['error'];
                }
            }
            if ($by_num || !$batch_errs) {
                $results = [];
                foreach ($by_num as $g) {
                    $g['diff'] = timb_ws_diff_local($conn, $spid, $g);
                    $results[] = $g;
                }
                $_SESSION['timb_gm_results']   = $results;
                $_SESSION['timb_gm_simulated'] = $simulated;

                $missing = array_diff($requested, array_keys($by_num));
                $msg = "✓ TIMB returned " . count($results) . " of " . count($requested) . " requested grower(s)"
                     . (count($batches) > 1 ? " (" . count($batches) . " requests of up to $batch_size)" : "")
                     . ($simulated ? " (simulated — no live credentials configured)" : "") . ".";
                if ($from_file) $msg .= " Included $from_file number(s) from the uploaded file.";
                if ($missing) {
                    $shown_missing = array_slice($missing, 0, 50);
                    $msg .= " Not found at TIMB: " . implode(', ', $shown_missing) . (count($missing) > 50 ? ' … and ' . (count($missing) - 50) . ' more' : '') . ".";
                }
                if ($batch_errs) $err = "✕ Some requests failed (" . implode('; ', $batch_errs) . "). Run the lookup again for the numbers that are missing.";
            } else {
                $err = "✕ " . implode('; ', $batch_errs);
                $results = [];
                unset($_SESSION['timb_gm_results']);
            }
        }
    }

    /* Import the selected lookup results ------------------------------ */
    if ($action === 'import') {
        $picked  = array_map('strval', (array)($_POST['nums'] ?? []));
        $to_save = !empty($_POST['import_all']) ? array_values($results)
                 : array_values(array_filter($results, function ($g) use ($picked) {
                       return in_array($g['grower_num'], $picked, true);
                   }));

        if (!$to_save) {
            $err = "Select at least one grower to import.";
        } else {
            // Records fetched in simulation mode are documentation samples, not
            // real grower data — never let them overwrite the growers table.
            $was_sim = !empty($_SESSION['timb_gm_simulated']);
            $reg = timb_ws_save_registry($conn, $spid, $to_save, $was_sim ? 'simulated' : 'growermaster');
            $parts = ["registry: {$reg['new']} new, {$reg['updated']} updated, {$reg['unchanged']} unchanged"];

            if (!empty($_POST['update_growers']) && $was_sim) {
                $parts[] = "growers: not touched (simulated data)";
            } elseif (!empty($_POST['update_growers'])) {
                $create = !empty($_POST['create_missing']);
                $ap = timb_ws_apply_to_growers($conn, $spid, $to_save, $create);
                $parts[] = "growers: {$ap['updated']} updated" . ($create ? ", {$ap['created']} created" : "") . ", {$ap['skipped']} skipped";
            }
            audit_log($conn, $spid, $userid, 'timb_grower_registry', 'import', null, null,
                      ['service'=>'growermaster', 'count'=>count($to_save)], 'TIMB grower master lookup import');
            $msg = "✓ Imported " . count($to_save) . " grower(s) — " . implode(' · ', $parts) . ".";
        }
    }

    /* Full grower database pull --------------------------------------- */
    if ($action === 'sync_all') {
        $fc = strtoupper(trim($_POST['floorcode'] ?? $floor_code));
        if ($fc === '') {
            $err = "No floor code set for this selling point. Add it in Settings below or on the selling point record.";
        } else {
            $res = timb_ws_growermaster_all($conn, $fc, ['spid'=>$spid, 'userid'=>$userid]);
            $last_call = $res;
            if ($res['ok']) {
                $was_sim = !empty($res['simulated']);
                $reg = timb_ws_save_registry($conn, $spid, $res['growers'], $was_sim ? 'simulated' : 'growermaster_all');
                $sync_stats = $reg;
                $parts = ["{$reg['new']} new", "{$reg['updated']} updated", "{$reg['unchanged']} unchanged"];

                if (!empty($_POST['update_growers']) && $was_sim) {
                    $parts[] = "growers: not touched (simulated data)";
                } elseif (!empty($_POST['update_growers'])) {
                    $create = !empty($_POST['create_missing']);
                    $ap = timb_ws_apply_to_growers($conn, $spid, $res['growers'], $create);
                    $parts[] = "growers: {$ap['updated']} updated" . ($create ? ", {$ap['created']} created" : "");
                }
                $conn->query("INSERT INTO timb_sync_log (selling_pointid,sync_type,records_fetched,records_matched,records_new,records_updated,status,notes)
                              VALUES ($spid,'growermaster_all'," . count($res['growers']) . "," . (int)$reg['unchanged'] . "," . (int)$reg['new'] . "," . (int)$reg['updated'] . ",'success','floorcode " . $conn->real_escape_string($fc) . "')");
                audit_log($conn, $spid, $userid, 'timb_grower_registry', 'sync', null, null,
                          ['service'=>'growermaster_all', 'floorcode'=>$fc, 'count'=>count($res['growers'])], 'TIMB full grower database pull');

                $msg = "✓ Pulled " . count($res['growers']) . " grower(s) for floor $fc — " . implode(' · ', $parts) . "."
                     . (!empty($res['simulated']) ? " (simulated)" : "");
            } else {
                $err = "✕ " . $res['error'];
                $conn->query("INSERT INTO timb_sync_log (selling_pointid,sync_type,records_fetched,status,notes)
                              VALUES ($spid,'growermaster_all',0,'failed','" . $conn->real_escape_string(substr($res['error'], 0, 200)) . "')");
            }
        }
    }

    /* Connection probe ------------------------------------------------- */
    if ($action === 'test_connection') {
        $probe = timb_ws_clean_grower_num($_POST['probe_number'] ?? 'V123456');
        $res   = timb_ws_growermaster($conn, $probe, ['spid'=>$spid, 'userid'=>$userid, 'retries'=>1]);
        $last_call = $res;
        if ($res['ok']) {
            $msg = "✓ Grower master reachable — HTTP {$res['http']}, {$res['duration_ms']}ms, "
                 . count($res['growers']) . " record(s) returned"
                 . (!empty($res['simulated']) ? " (simulated — configure credentials for a live test)" : "") . ".";
        } else {
            $err = "✕ " . $res['error'] . " (HTTP {$res['http']}, {$res['duration_ms']}ms, {$res['attempts']} attempt(s))";
        }
    }

    /* Connection diagnostics ------------------------------------------- */
    if ($action === 'diagnose') {
        $diagnostics = timb_ws_diagnose($conn);
    }

    /* Settings --------------------------------------------------------- */
    if ($action === 'save_settings') {
        if (!$can_admin) {
            $err = "Only administrators can change TIMB service settings.";
        } else {
            foreach (['timb_base_url', 'timb_stoporder_base_url', 'timb_growermaster_param', 'timb_timeout',
                      'timb_matrix_date_format', 'timb_grades_path'] as $k) {
                if (isset($_POST[$k])) timb_ws_set_setting($conn, $k, trim($_POST[$k]));
            }
            timb_ws_set_setting($conn, 'timb_ssl_verify', !empty($_POST['timb_ssl_verify']) ? '1' : '0');
            timb_ws_set_setting($conn, 'timb_mock_mode',  !empty($_POST['timb_mock_mode'])  ? '1' : '0');

            $fc = strtoupper(trim($_POST['floor_code'] ?? ''));
            if ($fc !== '') {
                $conn->query("UPDATE selling_point SET floor_code='" . $conn->real_escape_string($fc) . "' WHERE id=$spid");
                $floor_code = $fc;
            }

            foreach (['booking', 'stoporder'] as $set) {
                $u = trim($_POST["{$set}_user"] ?? '');
                $p = (string)($_POST["{$set}_pass"] ?? '');
                if ($u !== '' || $p !== '') timb_ws_save_credentials($conn, $set, $u, $p, $userid);
            }
            audit_log($conn, $spid, $userid, 'timb_credentials', 'update', null, null,
                      ['base_url'=>timb_ws_setting($conn, 'timb_base_url', '')], 'TIMB web service settings');
            $msg = "✓ TIMB web service settings saved.";
            header("Location: timb_growermaster.php?tab=settings&saved=1");
            exit;
        }
    }
}

if (!empty($_GET['saved']))    $msg = "✓ TIMB web service settings saved.";
if (!empty($_GET['noexport'])) $err = "Nothing to export yet — run a lookup or open a registry with records first.";

/* ── One service-log entry's raw request/response, loaded on demand ────── */
if (isset($_GET['raw_log'])) {
    header('Content-Type: application/json');
    $lid = (int)$_GET['raw_log'];
    $lq = $conn->query("SELECT url, request_params, response_body FROM timb_service_log
                        WHERE id=$lid AND selling_pointid=$spid AND service LIKE 'growermaster%' LIMIT 1");
    $lr = ($lq && $lq !== true) ? $lq->fetch_assoc() : null;
    echo json_encode($lr ? ['ok' => true, 'url' => (string)$lr['url'], 'request' => (string)$lr['request_params'],
                            'response' => (string)$lr['response_body']]
                         : ['ok' => false, 'error' => 'Log entry not found on this floor.']);
    exit;
}

/* ── Page data ─────────────────────────────────────────────────────────── */

$cred_booking   = timb_ws_credentials($conn, 'booking');
$cred_stoporder = timb_ws_credentials($conn, 'stoporder');
$mock_active    = timb_ws_is_mock($conn, 'booking');

$search = trim($_GET['q'] ?? '');
$where  = "WHERE r.selling_pointid=$spid";
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where .= " AND (r.grower_num LIKE '%$s%' OR r.name LIKE '%$s%' OR r.surname LIKE '%$s%' OR r.national_id LIKE '%$s%')";
}
$reg_per = fw_per_page('rpp', 50);
$_rf = $conn->query("SELECT COUNT(*) n FROM timb_grower_registry r $where");
$reg_found = ($_rf && $_rf !== true) ? (int)$_rf->fetch_assoc()['n'] : 0;
[$reg_page, $reg_pages, $reg_off] = fw_page_window($reg_found, $reg_per, 'rp');
$reg_q = $conn->query("SELECT r.*, (SELECT g.id FROM growers g WHERE g.grower_num=r.grower_num AND g.selling_pointid=r.selling_pointid LIMIT 1) AS local_id
                       FROM timb_grower_registry r $where ORDER BY r.last_synced DESC, r.grower_num
                       LIMIT $reg_per OFFSET $reg_off");
$registry = ($reg_q && $reg_q !== true) ? $reg_q->fetch_all(MYSQLI_ASSOC) : [];
$reg_pager = function ($where_) use ($reg_page, $reg_pages, $reg_off, $registry, $reg_found, $reg_per, $search) {
    return fw_pager(['page'=>$reg_page, 'pages'=>$reg_pages, 'offset'=>$reg_off, 'shown'=>count($registry), 'found'=>$reg_found,
                     'per'=>$reg_per, 'page_param'=>'rp', 'size_param'=>'rpp', 'keep'=>['tab', 'q'], 'noun'=>'growers',
                     'filtered'=>$search !== '', 'where'=>$where_]);
};

$rc = $conn->query("SELECT COUNT(*) n, SUM(source LIKE 'growermaster%') ws, SUM(obligations_fulfilled='NO') unmet
                    FROM timb_grower_registry WHERE selling_pointid=$spid");
$reg_counts = ($rc && $rc !== true) ? $rc->fetch_assoc() : ['n'=>0,'ws'=>0,'unmet'=>0];

$log_q = $conn->query("SELECT l.id, l.created_at, l.service, l.simulated, l.ok, l.error_message, l.http_code,
                              l.rows_returned, l.duration_ms, l.attempts, u.username
                       FROM timb_service_log l
                       LEFT JOIN users u ON u.id=l.userid
                       WHERE l.selling_pointid=$spid AND l.service LIKE 'growermaster%'
                       ORDER BY l.id DESC LIMIT 40");
$logs = ($log_q && $log_q !== true) ? $log_q->fetch_all(MYSQLI_ASSOC) : [];

/* ── Find a grower by name / surname / ID ──────────────────────────────────
 * TIMB only look growers up by grower NUMBER, so a name/ID search runs against
 * what we already hold locally — the TIMB registry and the growers table for
 * this floor — to recover the grower number, which can then be pulled fresh
 * from TIMB. Both tables are searched and merged on grower number.
 */
$find         = trim($_GET['find'] ?? '');
$find_results = [];
if ($find !== '') {
    $like = '%' . $conn->real_escape_string($find) . '%';
    $cond = "(grower_num LIKE '$like' OR name LIKE '$like' OR surname LIKE '$like'
              OR national_id LIKE '$like' OR CONCAT(surname,' ',name) LIKE '$like'
              OR CONCAT(name,' ',surname) LIKE '$like')";
    $sources = [
        'registry' => "SELECT grower_num,name,surname,national_id,farm_name,area,province,obligations_fulfilled
                       FROM timb_grower_registry WHERE selling_pointid=$spid AND $cond
                       ORDER BY surname,name",
        'local'    => "SELECT grower_num,name,surname,national_id,farm_name,area,province,'' AS obligations_fulfilled
                       FROM growers WHERE selling_pointid=$spid AND $cond
                       ORDER BY surname,name",
    ];
    $by = [];
    foreach ($sources as $src => $sql) {
        $q = $conn->query($sql);
        if (!$q || $q === true) continue;
        while ($row = $q->fetch_assoc()) {
            $gn  = trim((string)$row['grower_num']);
            $key = $gn !== '' ? 'G:' . strtoupper($gn)
                              : 'N:' . strtoupper($row['national_id'] . '|' . $row['surname'] . $row['name']);
            if (!isset($by[$key])) { $by[$key] = $row; $by[$key]['sources'] = []; }
            $by[$key]['sources'][$src] = true;
            // Let a non-empty value from either table fill a blank in the other.
            foreach (['name','surname','national_id','farm_name','area','province','obligations_fulfilled'] as $f) {
                if (($by[$key][$f] ?? '') === '' && ($row[$f] ?? '') !== '') $by[$key][$f] = $row[$f];
            }
        }
    }
    $find_results = array_values($by);
    usort($find_results, function ($a, $b) {
        return strcmp($a['surname'] . $a['name'], $b['surname'] . $b['name']);
    });
}
$find_per = fw_per_page('fpp', 50);
[$find_page, $find_pages, $find_off] = fw_page_window(count($find_results), $find_per, 'fp');
$find_shown = array_slice($find_results, $find_off, $find_per);
$find_pager = function ($where_) use ($find_page, $find_pages, $find_off, $find_shown, $find_results, $find_per) {
    return fw_pager(['page'=>$find_page, 'pages'=>$find_pages, 'offset'=>$find_off, 'shown'=>count($find_shown), 'found'=>count($find_results),
                     'per'=>$find_per, 'page_param'=>'fp', 'size_param'=>'fpp', 'keep'=>['tab', 'find'], 'noun'=>'matches',
                     'filtered'=>true, 'where'=>$where_]);
};

// Lookup results (kept in the session) are shown a page at a time too
$lk_per = fw_per_page('lpp', 50);
[$lk_page, $lk_pages, $lk_off] = fw_page_window(count($results), $lk_per, 'lp');
$results_shown = array_slice($results, $lk_off, $lk_per);
$lk_pager = function ($where_) use ($lk_page, $lk_pages, $lk_off, $results_shown, $results, $lk_per) {
    return fw_pager(['page'=>$lk_page, 'pages'=>$lk_pages, 'offset'=>$lk_off, 'shown'=>count($results_shown), 'found'=>count($results),
                     'per'=>$lk_per, 'page_param'=>'lp', 'size_param'=>'lpp', 'keep'=>['tab'], 'noun'=>'growers',
                     'filtered'=>false, 'where'=>$where_]);
};

include "includes/layout.php";
?>

<div class="page-header">
  <div class="page-header-left">
    <h2>TIMB Grower Master</h2>
    <p>Query grower details straight from the T.I.M.B. grower master web service</p>
  </div>
  <div>
    <span class="badge <?= $mock_active ? 'badge-gold' : 'badge-green' ?>">
      <?= $mock_active ? '◌ Simulation mode' : '● Live — ' . htmlspecialchars(timb_ws_base_url($conn)) ?>
    </span>
  </div>
</div>

<?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>

<?php if($mock_active):?>
<div class="alert alert-gold">
  <strong>Simulation mode.</strong> No Booking &amp; Registration credentials are configured, so lookups return the sample
  records from the TIMB documentation instead of calling <?=htmlspecialchars(timb_ws_base_url($conn))?>.
  Everything downstream — mapping, diffing, import — runs for real against your database.
  <?php if($can_admin):?>Add credentials under <strong>Settings</strong> to go live.<?php endif;?>
</div>
<?php endif;?>

<div class="premium-tabs-nav" data-tabs="timbgm">
  <button type="button" class="premium-tab-btn" data-tab="lookup">🔍 Grower Lookup</button>
  <button type="button" class="premium-tab-btn" data-tab="sync">⇅ Full Database Pull</button>
  <button type="button" class="premium-tab-btn" data-tab="registry">📋 Registry <span class="badge badge-gray"><?=(int)$reg_counts['n']?></span></button>
  <button type="button" class="premium-tab-btn" data-tab="log">🧾 Service Log</button>
  <?php if($can_admin):?><button type="button" class="premium-tab-btn" data-tab="settings">⚙ Settings</button><?php endif;?>
</div>

<!-- ══ Lookup ══════════════════════════════════════════════════════════ -->
<div id="panel-lookup" class="tab-panel active" data-tabs="timbgm">

<!-- Find a grower by name / surname / ID (local directory search) -->
<div class="card">
  <div class="card-head">
    <h3>Find a grower by name or ID</h3>
    <span class="badge badge-gray">searches your floor</span>
  </div>
  <div class="card-body" style="padding-bottom:.75rem">
    <form method="GET" class="form-row" style="align-items:flex-end;gap:.5rem;margin-bottom:0">
      <input type="hidden" name="tab" value="lookup">
      <div class="form-group" style="margin-bottom:0;flex:1">
        <label>Name, surname or national ID</label>
        <input type="text" name="find" class="form-control" value="<?=htmlspecialchars($find)?>"
               placeholder="e.g. Maduuro, or 80-022879P80" autofocus>
      </div>
      <button class="btn btn-primary btn-sm">Search</button>
      <?php if($find!==''):?><a href="?tab=lookup" class="btn btn-ghost btn-sm">Clear</a><?php endif;?>
    </form>
    <small style="color:var(--muted);font-size:.72rem;display:block;margin-top:.5rem">
      TIMB only look up growers by number, so this searches the growers already on this floor
      (the TIMB registry and your grower records) to recover the number — then
      <strong>Look up at TIMB</strong> pulls the latest details for it.
    </small>
  </div>

  <?php if($find!==''):?>
    <?php if(!$find_results):?>
      <div class="card-body text-center text-muted" style="border-top:1px solid var(--border)">
        No local grower matches “<?=htmlspecialchars($find)?>”. If they are new, look them up by grower number below.
      </div>
    <?php else:?>
      <?= $find_pager('top') ?>
      <div class="table-wrap"><table>
        <thead><tr>
          <th>Grower no.</th><th>Name</th><th>National ID</th><th>Farm / Area</th><th>Found in</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach($find_shown as $g):
              $gn = trim((string)$g['grower_num']); ?>
          <tr>
            <td class="td-mono"><strong><?=htmlspecialchars($gn !== '' ? $gn : '—')?></strong></td>
            <td><?=htmlspecialchars(trim($g['surname'].' '.$g['name']))?></td>
            <td class="td-mono" style="font-size:.72rem"><?=htmlspecialchars((string)$g['national_id'])?></td>
            <td style="font-size:.75rem"><?=htmlspecialchars((string)$g['farm_name'])?>
              <?php if($g['area']):?><br><span style="font-size:.65rem;color:var(--muted)"><?=htmlspecialchars($g['area'])?></span><?php endif;?>
            </td>
            <td style="font-size:.68rem">
              <?php if(!empty($g['sources']['registry'])):?><span class="badge badge-blue" style="font-size:.72rem">TIMB registry</span><?php endif;?>
              <?php if(!empty($g['sources']['local'])):?><span class="badge badge-gray" style="font-size:.72rem">Local record</span><?php endif;?>
            </td>
            <td class="text-right">
              <?php if($gn !== ''):?>
                <form method="POST" style="margin:0">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="lookup">
                  <input type="hidden" name="grower_numbers" value="<?=htmlspecialchars($gn)?>">
                  <button class="btn btn-gold btn-sm" <?=$can_use?'':'disabled'?>>Look up at TIMB</button>
                </form>
              <?php else:?>
                <span class="badge badge-gold" style="font-size:.72rem">no grower number</span>
              <?php endif;?>
            </td>
          </tr>
        <?php endforeach;?>
        </tbody>
      </table></div>
      <?= $find_pager('bottom') ?>
    <?php endif;?>
  <?php endif;?>
</div>

<div class="card">
  <div class="card-head"><h3>Query grower master</h3><span class="badge badge-gray">POST /growermasterWebservices</span></div>
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="lookup">
      <div class="form-group">
        <label>Grower number(s)</label>
        <textarea name="grower_numbers" class="form-control" rows="3" placeholder="V111222, V302215A, V125521"><?=htmlspecialchars($_POST['grower_numbers'] ?? '')?></textarea>
        <small style="color:var(--muted);font-size:.72rem">
          Separate with commas, spaces or new lines. Bare numbers are given the <code>V</code> prefix automatically.
        </small>
      </div>
      <div class="form-group">
        <label>…or import a file of grower numbers</label>
        <input type="file" name="numbers_file" class="form-control" accept=".csv,.txt,.xlsx">
        <small style="color:var(--muted);font-size:.72rem">
          CSV, TXT or Excel (.xlsx). Numbers are read from anywhere in the file and merged with any typed above
          (max 100 per query · 2&nbsp;MB). For a whole floor, use the <strong>Full Database Pull</strong> tab instead.
        </small>
      </div>
      <button class="btn btn-primary" <?=$can_use?'':'disabled'?>>Query TIMB</button>
    </form>
  </div>
</div>

<?php if($results):?>
<form method="POST">
<?=csrf_field()?>
<input type="hidden" name="action" value="import">
<div class="card">
  <div class="card-head">
    <h3>TIMB response</h3>
    <div style="display:flex;align-items:center;gap:.5rem">
      <span class="badge badge-gray"><?=count($results)?> record(s)</span>
      <a href="?export=lookup_csv"  class="btn btn-ghost btn-sm">⭳ CSV</a>
      <a href="?export=lookup_xlsx" class="btn btn-ghost btn-sm">⭳ Excel</a>
    </div>
  </div>
  <?= $lk_pager('top') ?>
  <div class="table-wrap"><table>
    <thead><tr>
      <th style="width:30px"><input type="checkbox" aria-label="Tick every grower on this page" onclick="document.querySelectorAll('.gm-pick').forEach(c=>c.checked=this.checked)" checked></th>
      <th>Grower no.</th><th>Name</th><th>National ID</th><th>Farm / Area</th>
      <th>Province</th><th class="text-right">Ha</th><th>Contractor</th><th>Obligations</th><th>Local record</th>
    </tr></thead>
    <tbody>
    <?php foreach($results_shown as $g):
        $d = $g['diff'] ?? ['local'=>null,'diffs'=>[]];
        $unmet = ($g['obligations_fulfilled'] === 'NO');
    ?>
      <tr>
        <td><input type="checkbox" class="gm-pick" name="nums[]" value="<?=htmlspecialchars($g['grower_num'])?>" checked></td>
        <td class="td-mono"><strong><?=htmlspecialchars($g['grower_num'])?></strong>
          <?php if($g['grower_class']||$g['grower_type']):?>
            <br><span style="font-size:.65rem;color:var(--muted)">type <?=htmlspecialchars($g['grower_type'])?> · class <?=htmlspecialchars($g['grower_class'])?></span>
          <?php endif;?>
        </td>
        <td><?=htmlspecialchars(trim($g['surname'].' '.$g['name']))?>
          <?php if($g['association']):?><br><span style="font-size:.65rem;color:var(--muted)"><?=htmlspecialchars($g['association'])?></span><?php endif;?>
        </td>
        <td class="td-mono" style="font-size:.72rem"><?=htmlspecialchars($g['national_id'])?></td>
        <td style="font-size:.75rem"><?=htmlspecialchars($g['farm_name'])?>
          <?php if($g['area']):?><br><span style="font-size:.65rem;color:var(--muted)"><?=htmlspecialchars($g['area'])?> (district <?=htmlspecialchars($g['district'])?>)</span><?php endif;?>
        </td>
        <td style="font-size:.72rem"><?=htmlspecialchars($g['province'])?></td>
        <td class="td-mono text-right"><?=number_format((float)$g['hectares'], 2)?></td>
        <td style="font-size:.72rem"><?=htmlspecialchars($g['contractor'] ?: '—')?></td>
        <td>
          <?php if($g['obligations_fulfilled']===''):?><span class="badge badge-gray">—</span>
          <?php elseif($unmet):?><span class="badge badge-red">NOT FULFILLED</span>
          <?php else:?><span class="badge badge-green">Fulfilled</span><?php endif;?>
        </td>
        <td style="font-size:.72rem">
          <?php if(!$d['local']):?>
            <span class="badge badge-gold">Not on this floor</span>
          <?php elseif(!$d['diffs']):?>
            <span class="badge badge-green">Matches</span>
          <?php else:?>
            <span class="badge badge-blue"><?=count($d['diffs'])?> difference(s)</span>
            <div style="margin-top:.35rem;font-size:.68rem;line-height:1.5">
            <?php foreach($d['diffs'] as $f=>$pair):?>
              <div><strong><?=htmlspecialchars($f)?>:</strong>
                <span style="color:var(--danger)"><?=htmlspecialchars($pair[0] !== '' ? $pair[0] : '(blank)')?></span>
                → <span style="color:var(--gold)"><?=htmlspecialchars($pair[1])?></span></div>
            <?php endforeach;?>
            </div>
          <?php endif;?>
        </td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <div class="card-body" style="border-top:1px solid var(--border)">
    <label style="display:block;font-size:.78rem;margin-bottom:.4rem">
      <input type="checkbox" name="update_growers" value="1"> Also write these details onto the matching <strong>growers</strong> records
    </label>
    <label style="display:block;font-size:.78rem;margin-bottom:.75rem;padding-left:1.2rem">
      <input type="checkbox" name="create_missing" value="1"> …and create growers that don't exist on this floor yet
    </label>
    <p style="font-size:.72rem;color:var(--muted);margin-bottom:.75rem">
      The TIMB registry is always updated. Fields TIMB returns blank never overwrite what you already hold.
    </p>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <button class="btn btn-gold" <?=$can_use?'':'disabled'?>>Import ticked<?=$lk_pages>1?' on this page':''?></button>
      <?php if($lk_pages>1):?>
      <button class="btn btn-primary" name="import_all" value="1" <?=$can_use?'':'disabled'?>
              onclick="return confirm('Import all <?=count($results)?> growers from this lookup, on every page?')">Import all <?=number_format(count($results))?></button>
      <?php endif;?>
    </div>
  </div>
</div>
</form>
<?php endif;?>

</div><!-- /panel-lookup -->

<!-- ══ Full pull ═══════════════════════════════════════════════════════ -->
<div id="panel-sync" class="tab-panel" data-tabs="timbgm">
<div class="card">
  <div class="card-head"><h3>Pull the full grower database</h3><span class="badge badge-gray">POST /growermasterAllWebservices</span></div>
  <div class="card-body">
    <p style="font-size:.8rem;margin-bottom:1rem">
      Fetches every grower TIMB holds for this floor code and refreshes the local TIMB registry.
      Run it before the season opens and whenever TIMB advises the master has been amended.
    </p>
    <form method="POST" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Pulling from TIMB…'">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="sync_all">
      <div class="form-row">
        <div class="form-group">
          <label>Floor code</label>
          <input type="text" name="floorcode" class="form-control" value="<?=htmlspecialchars($floor_code)?>"
                 placeholder="e.g. BAF" style="text-transform:uppercase" required>
          <small style="color:var(--muted);font-size:.72rem">
            From <?=htmlspecialchars($sp_row['name'] ?? 'this selling point')?>'s record.
            <?= $floor_code === '' ? 'Not set — enter it here, it will be saved to the selling point.' : '' ?>
          </small>
        </div>
      </div>
      <label style="display:block;font-size:.78rem;margin-bottom:.4rem">
        <input type="checkbox" name="update_growers" value="1"> Also write details onto matching <strong>growers</strong> records
      </label>
      <label style="display:block;font-size:.78rem;margin-bottom:1rem;padding-left:1.2rem">
        <input type="checkbox" name="create_missing" value="1"> …and create growers that don't exist on this floor yet
      </label>
      <button class="btn btn-primary" <?=$can_use?'':'disabled'?>>Pull grower database</button>
    </form>
  </div>
</div>

<?php if($sync_stats):?>
<div class="card">
  <div class="card-head"><h3>Last pull</h3></div>
  <div class="card-body grid-3" style="text-align:center">
    <div><div style="font-size:1.6rem;font-weight:600;color:var(--gold)"><?=(int)$sync_stats['new']?></div><div style="font-size:.72rem;color:var(--muted)">NEW</div></div>
    <div><div style="font-size:1.6rem;font-weight:600"><?=(int)$sync_stats['updated']?></div><div style="font-size:.72rem;color:var(--muted)">UPDATED</div></div>
    <div><div style="font-size:1.6rem;font-weight:600;color:var(--muted)"><?=(int)$sync_stats['unchanged']?></div><div style="font-size:.72rem;color:var(--muted)">UNCHANGED</div></div>
  </div>
</div>
<?php endif;?>
</div><!-- /panel-sync -->

<!-- ══ Registry ════════════════════════════════════════════════════════ -->
<div id="panel-registry" class="tab-panel" data-tabs="timbgm">
<div class="card">
  <div class="card-head">
    <h3>TIMB registry — <?=htmlspecialchars($sp_row['name'] ?? '')?></h3>
    <span class="badge badge-gray"><?=(int)$reg_counts['n']?> records · <?=(int)$reg_counts['ws']?> from web service</span>
  </div>
  <div class="card-body" style="padding-bottom:.5rem">
    <form method="GET" class="form-row" style="align-items:flex-end;gap:.5rem">
      <input type="hidden" name="tab" value="registry">
      <div class="form-group" style="margin-bottom:0;flex:1">
        <input type="text" name="q" class="form-control" value="<?=htmlspecialchars($search)?>" placeholder="Search grower number, name or national ID">
      </div>
      <button class="btn btn-ghost btn-sm">Search</button>
      <?php if($search!==''):?><a href="?tab=registry" class="btn btn-ghost btn-sm">Clear</a><?php endif;?>
      <?php if($registry):
              $qs = $search !== '' ? '&q=' . urlencode($search) : ''; ?>
        <a href="?export=registry_csv<?=$qs?>"  class="btn btn-ghost btn-sm">⭳ CSV</a>
        <a href="?export=registry_xlsx<?=$qs?>" class="btn btn-ghost btn-sm">⭳ Excel</a>
      <?php endif;?>
    </form>
    <?php if((int)$reg_counts['unmet'] > 0):?>
      <p style="font-size:.75rem;margin-top:.6rem;color:var(--danger)">
        ⚠ <?=(int)$reg_counts['unmet']?> grower(s) in this registry have <strong>obligations not fulfilled</strong> at TIMB.
      </p>
    <?php endif;?>
  </div>
  <?php if(!$registry):?>
    <div class="card-body text-center text-muted">
      Registry is empty. Run a lookup or a full database pull.
    </div>
  <?php else:?>
  <?= $reg_pager('top') ?>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>Grower no.</th><th>Name</th><th>National ID</th><th>Farm / Area</th><th>Province</th>
      <th class="text-right">Ha</th><th>Barn</th><th>Obligations</th><th>Source</th><th>Synced</th><th>Local</th>
    </tr></thead>
    <tbody>
    <?php foreach($registry as $r):?>
      <tr>
        <td class="td-mono"><strong><?=htmlspecialchars($r['grower_num'])?></strong></td>
        <td><?=htmlspecialchars(trim($r['surname'].' '.$r['name']))?></td>
        <td class="td-mono" style="font-size:.72rem"><?=htmlspecialchars((string)$r['national_id'])?></td>
        <td style="font-size:.75rem"><?=htmlspecialchars((string)$r['farm_name'])?>
          <?php if($r['area']):?><br><span style="font-size:.65rem;color:var(--muted)"><?=htmlspecialchars($r['area'])?></span><?php endif;?></td>
        <td style="font-size:.72rem"><?=htmlspecialchars((string)$r['province'])?></td>
        <td class="td-mono text-right"><?=number_format((float)($r['hectares'] ?? 0), 2)?></td>
        <td style="font-size:.72rem"><?=htmlspecialchars((string)($r['barn_type'] ?? ''))?></td>
        <td>
          <?php $ob = strtoupper((string)($r['obligations_fulfilled'] ?? ''));?>
          <?php if($ob===''):?><span class="badge badge-gray">—</span>
          <?php elseif($ob==='NO'):?><span class="badge badge-red">NOT FULFILLED</span>
          <?php else:?><span class="badge badge-green">Fulfilled</span><?php endif;?>
        </td>
        <td><span class="badge <?= strpos((string)$r['source'],'growermaster')===0 ? 'badge-blue' : 'badge-gray' ?>" style="font-size:.72rem"><?=htmlspecialchars((string)$r['source'])?></span></td>
        <td style="font-size:.68rem;color:var(--muted)"><?=htmlspecialchars(date('d M H:i', strtotime($r['last_synced'])))?></td>
        <td><?= $r['local_id'] ? '<span class="badge badge-green">✓</span>' : '<span class="badge badge-gold">—</span>' ?></td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?= $reg_pager('bottom') ?>
  <?php endif;?>
</div>
</div><!-- /panel-registry -->

<!-- ══ Service log ═════════════════════════════════════════════════════ -->
<div id="panel-log" class="tab-panel" data-tabs="timbgm">
<div class="card">
  <div class="card-head"><h3>Grower master service log</h3><span class="badge badge-gray">last <?=count($logs)?> calls</span></div>
  <?php if(!$logs):?>
    <div class="card-body text-center text-muted">No calls yet.</div>
  <?php else:?>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Service</th><th>Result</th><th class="text-right">HTTP</th><th class="text-right">Rows</th><th class="text-right">Time</th><th>User</th><th></th></tr></thead>
    <tbody>
    <?php foreach($logs as $l):?>
      <tr>
        <td style="font-size:.7rem;color:var(--muted);white-space:nowrap"><?=htmlspecialchars(date('d M H:i:s', strtotime($l['created_at'])))?></td>
        <td style="font-size:.72rem"><?=htmlspecialchars($l['service'])?><?= $l['simulated'] ? ' <span class="badge badge-gold" style="font-size:.58rem">sim</span>' : '' ?></td>
        <td style="font-size:.72rem">
          <?php if($l['ok']):?><span class="badge badge-green">OK</span>
          <?php else:?><span class="badge badge-red">FAIL</span>
            <div style="font-size:.66rem;color:var(--danger);margin-top:.25rem"><?=htmlspecialchars((string)$l['error_message'])?></div>
          <?php endif;?>
        </td>
        <td class="td-mono text-right" style="font-size:.72rem"><?=(int)$l['http_code']?></td>
        <td class="td-mono text-right" style="font-size:.72rem"><?=(int)$l['rows_returned']?></td>
        <td class="td-mono text-right" style="font-size:.72rem"><?=(int)$l['duration_ms']?>ms<?= (int)$l['attempts']>1 ? ' ·'.(int)$l['attempts'].'×' : '' ?></td>
        <td style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars((string)($l['username'] ?? ''))?></td>
        <td><button type="button" class="btn btn-ghost btn-sm" aria-expanded="false" aria-controls="gmlog<?=(int)$l['id']?>"
                    onclick="gmRaw(<?=(int)$l['id']?>, this)">raw</button></td>
      </tr>
      <tr id="gmlog<?=(int)$l['id']?>" hidden>
        <td colspan="8" style="background:var(--surface-2);padding:.75rem">
          <div style="font-size:.78rem;color:var(--muted);margin-bottom:.25rem">Request — <span class="gm-raw-url"></span></div>
          <pre class="gm-raw-req" style="font-size:.75rem;white-space:pre-wrap;word-break:break-all;margin:0 0 .6rem">Loading…</pre>
          <div style="font-size:.78rem;color:var(--muted);margin-bottom:.25rem">Response</div>
          <pre class="gm-raw-res" style="font-size:.75rem;white-space:pre-wrap;word-break:break-all;max-height:280px;overflow:auto;margin:0"></pre>
        </td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?php endif;?>
</div>
</div><!-- /panel-log -->
<script>
/* Raw request/response for a service-log entry: fetched the first time it is opened */
function gmRaw(id, btn) {
    var row = document.getElementById('gmlog' + id);
    row.hidden = !row.hidden;
    btn.setAttribute('aria-expanded', row.hidden ? 'false' : 'true');
    if (row.hidden || row.dataset.loaded) return;
    row.dataset.loaded = '1';
    fetch('timb_growermaster.php?raw_log=' + id, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            row.querySelector('.gm-raw-url').textContent = d.ok ? d.url : '';
            row.querySelector('.gm-raw-req').textContent = d.ok ? (d.request || '(none)') : (d.error || 'Could not load.');
            row.querySelector('.gm-raw-res').textContent = d.ok ? (d.response || '(empty)') : '';
        })
        .catch(function () {
            row.dataset.loaded = '';
            row.querySelector('.gm-raw-req').textContent = 'Could not load this entry. Open it again to retry.';
        });
}
</script>

<!-- ══ Settings ════════════════════════════════════════════════════════ -->
<?php if($can_admin):?>
<div id="panel-settings" class="tab-panel" data-tabs="timbgm">
<div class="card">
  <div class="card-head"><h3>TIMB web service settings</h3></div>
  <div class="card-body">
    <form method="POST">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="save_settings">

      <div class="form-row">
        <div class="form-group">
          <label>Base URL <span class="badge badge-blue" style="font-size:.72rem">booking &amp; grower master</span></label>
          <input type="text" name="timb_base_url" class="form-control"
                 value="<?=htmlspecialchars(timb_ws_setting($conn,'timb_base_url',TIMB_WS_DEFAULT_BASE))?>">
          <small style="color:var(--muted);font-size:.72rem">Service paths are appended, e.g. <code>/growermasterWebservices</code>.</small>
        </div>
        <div class="form-group">
          <label>Floor code</label>
          <input type="text" name="floor_code" class="form-control" value="<?=htmlspecialchars($floor_code)?>" style="text-transform:uppercase">
          <small style="color:var(--muted);font-size:.72rem">Used by the full database pull.</small>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Stop Order base URL <span class="badge badge-gray" style="font-size:.72rem">optional</span></label>
          <input type="text" name="timb_stoporder_base_url" class="form-control" placeholder="leave blank to use the Base URL above"
                 value="<?=htmlspecialchars(timb_ws_setting($conn,'timb_stoporder_base_url',''))?>">
          <small style="color:var(--muted);font-size:.72rem">
            TIMB run the stop-order / debt-recovery services (price matrix, creditor, EOD files, grades) as a
            <strong>separate system</strong> with its own login (e.g. <code>www.timb.co.zw/sos19/</code>). If those
            webservices live at a different host/path than booking, set that base here — it is used only for
            stop-order services. Blank = use the Base URL above.
          </small>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Grower number parameter</label>
          <input type="text" name="timb_growermaster_param" class="form-control"
                 value="<?=htmlspecialchars(timb_ws_setting($conn,'timb_growermaster_param','growerNumbers'))?>">
          <small style="color:var(--muted);font-size:.72rem">
            The TIMB document calls this field <code>growerNumber</code> in prose but <code>growerNumbers</code> in the worked
            example. Flip it here if TIMB rejects the request.
          </small>
        </div>
        <div class="form-group">
          <label>Timeout (seconds)</label>
          <input type="number" name="timb_timeout" class="form-control" min="10" max="300"
                 value="<?=htmlspecialchars(timb_ws_setting($conn,'timb_timeout','60'))?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Price matrix date format</label>
          <input type="text" name="timb_matrix_date_format" class="form-control"
                 value="<?=htmlspecialchars(timb_ws_setting($conn,'timb_matrix_date_format','d-M-y'))?>">
          <small style="color:var(--muted);font-size:.72rem">
            PHP date format, uppercased before sending. <code>d-M-y</code> gives
            <code><?=htmlspecialchars(strtoupper(date(timb_ws_setting($conn,'timb_matrix_date_format','d-M-y'))))?></code>,
            matching the documented <code>12-DEC-17</code>.
          </small>
        </div>
        <div class="form-group">
          <label>Grade download path <span class="badge badge-gray" style="font-size:.72rem">optional</span></label>
          <input type="text" name="timb_grades_path" class="form-control" placeholder="/gradesWebservices"
                 value="<?=htmlspecialchars(timb_ws_setting($conn,'timb_grades_path',''))?>">
          <small style="color:var(--muted);font-size:.72rem">
            TIMB document no grade-download service. Leave blank unless they give you one —
            grades are otherwise pulled from the price matrix.
          </small>
        </div>
      </div>

      <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0">
      <h4 style="font-size:.85rem;margin-bottom:.25rem">Booking &amp; Registration credentials</h4>
      <p style="font-size:.72rem;color:var(--muted);margin-bottom:.75rem">
        Used by grower master and bookings. Stored AES-256 encrypted; the password is never rendered back to this page.
        <?php if($cred_booking['source']==='env'):?><br><strong>Currently supplied by environment variables</strong> — these fields are ignored.<?php endif;?>
      </p>
      <div class="form-row">
        <div class="form-group">
          <label>Username</label>
          <input type="text" name="booking_user" class="form-control" autocomplete="off"
                 value="<?=htmlspecialchars($cred_booking['source']==='db' ? $cred_booking['username'] : '')?>">
        </div>
        <div class="form-group">
          <label>Password <?= $cred_booking['password'] !== '' ? '<span class="badge badge-green" style="font-size:.58rem">set</span>' : '' ?></label>
          <input type="password" name="booking_pass" class="form-control" autocomplete="new-password"
                 placeholder="<?= $cred_booking['password'] !== '' ? 'Leave blank to keep current' : 'Not set' ?>">
        </div>
      </div>

      <h4 style="font-size:.85rem;margin:1rem 0 .25rem">Stop Order system credentials</h4>
      <p style="font-size:.72rem;color:var(--muted);margin-bottom:.75rem">
        Used by the bale, deduction, transporter, stop order, creditor, grades and price matrix services.
        <?php if($cred_stoporder['source']==='env'):?><br><strong>Currently supplied by environment variables.</strong><?php endif;?>
      </p>
      <div class="form-row">
        <div class="form-group">
          <label>Username</label>
          <input type="text" name="stoporder_user" class="form-control" autocomplete="off"
                 value="<?=htmlspecialchars($cred_stoporder['source']==='db' ? $cred_stoporder['username'] : '')?>">
        </div>
        <div class="form-group">
          <label>Password <?= $cred_stoporder['password'] !== '' ? '<span class="badge badge-green" style="font-size:.58rem">set</span>' : '' ?></label>
          <input type="password" name="stoporder_pass" class="form-control" autocomplete="new-password"
                 placeholder="<?= $cred_stoporder['password'] !== '' ? 'Leave blank to keep current' : 'Not set' ?>">
        </div>
      </div>

      <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0">
      <label style="display:block;font-size:.78rem;margin-bottom:.4rem">
        <input type="checkbox" name="timb_ssl_verify" value="1" <?=timb_ws_setting($conn,'timb_ssl_verify','1')==='1'?'checked':''?>>
        Verify TIMB's SSL certificate <span style="color:var(--muted)">(leave on unless TIMB's chain is broken)</span>
      </label>
      <label style="display:block;font-size:.78rem;margin-bottom:1rem">
        <input type="checkbox" name="timb_mock_mode" value="1" <?=timb_ws_setting($conn,'timb_mock_mode','0')==='1'?'checked':''?>>
        Force simulation mode <span style="color:var(--muted)">(never contacts TIMB, returns documentation samples)</span>
      </label>

      <button class="btn btn-primary">Save settings</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Diagnostics</h3></div>
  <div class="card-body">
    <div class="form-row" style="gap:.5rem;align-items:flex-end;margin-bottom:.5rem">
      <form method="POST" style="display:inline">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="diagnose">
        <button class="btn btn-primary">Run connection diagnostics</button>
      </form>
      <form method="POST" class="form-row" style="align-items:flex-end;gap:.5rem;margin:0;flex:1">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="test_connection">
        <div class="form-group" style="margin-bottom:0;flex:1">
          <label>Probe grower number</label>
          <input type="text" name="probe_number" class="form-control" value="V123456">
        </div>
        <button class="btn btn-ghost">Test grower master</button>
      </form>
    </div>
    <p style="font-size:.72rem;color:var(--muted)">
      Diagnostics walks the connection step by step — internet, DNS, TCP port, credentials — so a failure
      can be pinned to one of them. The probe sends one real lookup and records it in the service log;
      a 401 there means the credentials were rejected.
    </p>

    <?php if($diagnostics):?>
    <div class="table-wrap" style="margin-top:1rem"><table>
      <thead><tr><th style="width:40px"></th><th>Step</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach($diagnostics as $d):?>
        <tr>
          <td><?= $d['ok'] ? '<span class="badge badge-green">OK</span>' : '<span class="badge badge-red">✕</span>' ?></td>
          <td style="font-size:.78rem"><strong><?=htmlspecialchars($d['label'])?></strong></td>
          <td style="font-size:.75rem;color:<?= $d['ok'] ? 'var(--muted)' : 'var(--danger)' ?>"><?=htmlspecialchars($d['detail'])?></td>
        </tr>
      <?php endforeach;?>
      </tbody>
    </table></div>
    <?php
      $tcp_failed = false;
      foreach ($diagnostics as $d) if (strpos($d['label'], 'TCP connect') === 0 && !$d['ok']) $tcp_failed = true;
      $net_ok = $diagnostics[0]['ok'] ?? false;
      if ($tcp_failed && $net_ok): ?>
      <div class="alert alert-gold" style="margin-top:1rem;font-size:.78rem">
        <strong>This server has working internet but cannot open a connection to TIMB.</strong>
        That is almost always because TIMB has not registered this server's public IP address.
        TIMB's gateway only accepts connections from floor servers they have whitelisted — send them
        your public IP and ask them to add it, then run this again. A local firewall or ISP block
        on the port would look identical, so worth checking both.
      </div>
    <?php endif;?>
    <?php endif;?>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Service coverage</h3></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Service</th><th>Path</th><th>Credentials</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach(timb_ws_services() as $k=>$s):?>
      <tr>
        <td style="font-size:.78rem"><?=htmlspecialchars($s['label'])?></td>
        <td class="td-mono" style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($s['path'])?></td>
        <td><span class="badge <?= $s['cred']==='booking'?'badge-blue':'badge-gray' ?>" style="font-size:.72rem"><?=htmlspecialchars($s['cred'])?></span></td>
        <td><?= $s['ready'] ? '<span class="badge badge-green">Implemented</span>' : '<span class="badge badge-gray">Not yet wired</span>' ?></td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
</div>
</div><!-- /panel-settings -->
<?php endif;?>

<?php include "includes/layout_end.php"; ?>
