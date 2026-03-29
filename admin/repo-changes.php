<?php
if (!current_user_can('manage_options')) {
    wp_die(__('Keine Berechtigung.'));
}

$dir = isset($_GET['dir']) ? sanitize_text_field(wp_unslash($_GET['dir'])) : '';
if (!$dir || !is_dir($dir)) {
    echo '<div class="error"><p>Ungültiges oder nicht vorhandenes Verzeichnis.</p></div>';
    return;
}

$git_dir = $dir . DIRECTORY_SEPARATOR . '.git';
if (!is_dir($git_dir)) {
    echo '<div class="error"><p>Kein Git-Repository gefunden.</p></div>';
    return;
}

if (!function_exists('ahx_run_cmd_with_timeout') || !function_exists('ahx_find_git_binary')) {
    require_once plugin_dir_path(__FILE__) . 'commit-handler.php';
}

function ahx_wp_github_repo_changes_git($dir, $args, $timeout = 20) {
    return ahx_run_git_cmd(ahx_find_git_binary(), $dir, $args, $timeout, false);
}

function ahx_wp_github_repo_changes_diff_untracked_file($dir, $relative_file, $timeout = 20) {
    $relative_file = trim((string)$relative_file);
    if ($relative_file === '') {
        return '';
    }

    $absolute_file = $dir . DIRECTORY_SEPARATOR . $relative_file;
    if (!is_file($absolute_file)) {
        return '';
    }

    $tmp_file = @tempnam(sys_get_temp_dir(), 'ahx_git_empty_');
    if ($tmp_file === false || $tmp_file === '') {
        return '';
    }

    @file_put_contents($tmp_file, '');

    $res = ahx_wp_github_repo_changes_git(
        $dir,
        'diff --no-index -U2 -- ' . escapeshellarg($tmp_file) . ' ' . escapeshellarg($absolute_file),
        $timeout
    );

    @unlink($tmp_file);

    return (string)($res['output'] ?? '');
}

function ahx_wp_github_repo_changes_find_untracked_empty_dirs($dir, $timeout = 20) {
    $result = [];
    $root = realpath($dir);
    if ($root === false || !is_dir($root)) {
        return $result;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isDir()) {
            continue;
        }

        $abs = $item->getPathname();
        $normalized = str_replace('\\', '/', $abs);
        if (preg_match('#(^|/)\.git(/|$)#', $normalized)) {
            continue;
        }

        $entries = @scandir($abs);
        if ($entries === false) {
            continue;
        }

        $visible = array_values(array_filter($entries, function($entry) {
            return $entry !== '.' && $entry !== '..';
        }));
        if (count($visible) !== 0) {
            continue;
        }

        $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
        if ($rel === '') {
            continue;
        }

        $ignore_res = ahx_wp_github_repo_changes_git($root, 'check-ignore -q -- ' . escapeshellarg($rel . '/'), $timeout);
        if (intval($ignore_res['exit'] ?? 1) === 0) {
            continue;
        }

        $result[] = $rel;
    }

    return $result;
}

function ahx_wp_github_repo_changes_parse_owner_repo($remote_url) {
    if (function_exists('ahx_wp_github_parse_owner_repo_from_remote')) {
        return ahx_wp_github_parse_owner_repo_from_remote($remote_url);
    }

    $remote_url = trim((string)$remote_url);
    if ($remote_url === '') {
        return ['', ''];
    }

    if (preg_match('#github\.com[:/](.+?)(?:\.git)?$#', $remote_url, $m)) {
        $owner_repo = trim((string)$m[1], '/');
        $parts = explode('/', $owner_repo, 2);
        $owner = trim((string)($parts[0] ?? ''));
        $repo = trim((string)($parts[1] ?? ''));
        return [$owner, $repo];
    }

    return ['', ''];
}

function ahx_wp_github_repo_changes_is_github_remote($remote_url) {
    $remote_url = trim((string)$remote_url);
    if ($remote_url === '') {
        return false;
    }

    return preg_match('#^(https?://([^/@]+@)?github\.com/|ssh://git@github\.com/|git@github\.com:|git://github\.com/)#i', $remote_url) === 1;
}

function ahx_wp_github_repo_changes_fetch_open_issues($dir) {
    $result = [
        'enabled' => false,
        'items' => [],
        'error' => '',
        'owner' => '',
        'repo' => '',
    ];

    $origin_res = ahx_wp_github_repo_changes_git($dir, 'remote get-url origin', 12);
    if (intval($origin_res['exit'] ?? 1) !== 0) {
        return $result;
    }

    $remote_url = trim((string)($origin_res['output'] ?? ''));
    if (!ahx_wp_github_repo_changes_is_github_remote($remote_url)) {
        return $result;
    }

    list($owner, $repo) = ahx_wp_github_repo_changes_parse_owner_repo($remote_url);
    if ($owner === '' || $repo === '') {
        $result['enabled'] = true;
        $result['error'] = 'GitHub owner/repo konnte nicht aus origin ermittelt werden.';
        return $result;
    }

    $result['enabled'] = true;
    $result['owner'] = $owner;
    $result['repo'] = $repo;

    $token = trim((string)get_option('ahx_wp_main_github_token', ''));
    $headers = [
        'User-Agent' => 'AHX WP GitHub',
        'Accept' => 'application/vnd.github+json',
    ];
    if ($token !== '') {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    $query = rawurlencode('repo:' . $owner . '/' . $repo . ' type:issue state:open');
    $url = 'https://api.github.com/search/issues?q=' . $query . '&sort=updated&order=desc&per_page=20';
    $response = wp_remote_get($url, [
        'headers' => $headers,
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        $result['error'] = 'Issues konnten nicht geladen werden: ' . $response->get_error_message();
        return $result;
    }

    $status = intval(wp_remote_retrieve_response_code($response));
    $body = json_decode((string)wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300 || !is_array($body)) {
        $msg = is_array($body) ? trim((string)($body['message'] ?? '')) : '';
        if ($msg === '') {
            $msg = 'HTTP ' . $status;
        }
        $result['error'] = 'Issues konnten nicht geladen werden: ' . $msg;
        return $result;
    }

    $items = is_array($body['items'] ?? null) ? $body['items'] : [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $number = intval($item['number'] ?? 0);
        if ($number <= 0) {
            continue;
        }
        $result['items'][] = [
            'number' => $number,
            'title' => trim((string)($item['title'] ?? '')),
            'html_url' => trim((string)($item['html_url'] ?? '')),
            'updated_at' => trim((string)($item['updated_at'] ?? '')),
        ];
    }

    return $result;
}

function ahx_wp_github_repo_changes_build_issue_footer($issue_ids, $close_issues) {
    if (!is_array($issue_ids) || empty($issue_ids)) {
        return '';
    }

    $clean_ids = [];
    foreach ($issue_ids as $id) {
        $num = intval($id);
        if ($num > 0) {
            $clean_ids[] = $num;
        }
    }
    $clean_ids = array_values(array_unique($clean_ids));
    sort($clean_ids, SORT_NUMERIC);

    if (empty($clean_ids)) {
        return '';
    }

    $refs = array_map(function($num) {
        return '#' . intval($num);
    }, $clean_ids);

    $lines = [];
    $lines[] = 'Refs: ' . implode(', ', $refs);

    if ($close_issues) {
        foreach ($clean_ids as $num) {
            $lines[] = 'Fixes #' . intval($num);
        }
    }

    return implode("\n", $lines);
}

ahx_wp_main_display_admin_notices();

// Änderungen auslesen
$status_result = ahx_wp_github_repo_changes_git($dir, 'status --porcelain -uall', 20);
$changes = trim((string)($status_result['output'] ?? ''));

// Mapping für Statuskürzel (Anzeigelegende)
$status_legend = [
    'M'   => 'Modified (geändert)',
    'A'   => 'Added (neu)',
    'D'   => 'Deleted (gelöscht)',
    'R'   => 'Renamed (umbenannt)',
    'C'   => 'Copied (kopiert)',
    'U'   => 'Unmerged (Konflikt)',
    '??'  => 'Untracked (nicht versioniert)',
    '!'   => 'Ignored (ignoriert)',
];

// Liste der geänderten Dateien extrahieren
$files = [];
if ($changes) {
    $lines = preg_split('/\r\n|\r|\n/', $changes);
    foreach ($lines as $line) {
        if (strlen(trim($line)) < 1) continue;
        $status = substr($line, 0, 2);
        $file = ltrim(substr($line, 2));
        if ($file === '') continue;
        $full_path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_dir($full_path)) {
            $dir_files = @scandir($full_path);
            $only_keep = true;
            foreach ($dir_files as $df) {
                if ($df === '.' || $df === '..') continue;
                if (!in_array($df, ['.gitkeep', '.keep'])) { $only_keep = false; break; }
            }
            $files[] = ['status' => $status, 'file' => $file, 'is_empty_dir' => $only_keep];
        } else {
            $files[] = ['status' => $status, 'file' => $file, 'is_empty_dir' => false];
        }
    }
}

$existing_files = [];
foreach ($files as $entry) {
    $existing_files[str_replace('\\', '/', (string)$entry['file'])] = true;
}
$empty_dirs = ahx_wp_github_repo_changes_find_untracked_empty_dirs($dir, 20);
foreach ($empty_dirs as $empty_dir_rel) {
    if (!isset($existing_files[$empty_dir_rel])) {
        $files[] = ['status' => 'A ', 'file' => $empty_dir_rel, 'is_empty_dir' => true, 'synthetic_empty_dir' => true];
    }
}

$has_changes = !empty($files);
$back_url = admin_url('admin.php?page=ahx-wp-github');

$prefill_commit_message = sanitize_textarea_field(wp_unslash($_GET['prefill_commit_message'] ?? ''));
$prefill_version_bump = sanitize_key(wp_unslash($_GET['prefill_version_bump'] ?? 'none'));
if (!in_array($prefill_version_bump, ['none', 'patch', 'minor', 'major'], true)) {
    $prefill_version_bump = 'none';
}

$open_issues_data = ahx_wp_github_repo_changes_fetch_open_issues($dir);

// Mapping für Statuskürzel

// --- VORBEREITUNG: Berechnungen, Diffs und Commit-Logik (keine Ausgabe) ---
// Status-Klassen Mapping
$status_classes = [
    'M' => 'git-status-M', 'A' => 'git-status-A', 'D' => 'git-status-D',
    'R' => 'git-status-R', 'C' => 'git-status-C', 'U' => 'git-status-U',
    '??' => 'git-status-untracked', '!' => 'git-status-ignored'
];

// Vorverarbeite jede Datei: Klasse, Binary-Check, Diff-HTML
foreach ($files as &$f) {
    $status = trim($f['status']);
    $f['status_class'] = $status_classes[$status] ?? '';
    $f['diff_html'] = '';
    // Handle empty dirs / added files quickly
    if (!empty($f['is_empty_dir'])) {
        $f['diff_html'] = '<em>Leeres Verzeichnis</em>';
        continue;
    }
    if ($status === 'A') {
        $f['diff_html'] = '<em>Datei wurde neu hinzugefügt. Kein Diff verfügbar.</em>';
        continue;
    }

    $file_path = $dir . DIRECTORY_SEPARATOR . $f['file'];
    $is_binary = false;
    if (is_file($file_path)) {
        $handle = @fopen($file_path, 'rb');
        $chunk = $handle ? fread($handle, 512) : '';
        if ($handle) fclose($handle);
        if (strpos($chunk, "\0") !== false) $is_binary = true;
    }
    if ($is_binary) {
        $f['diff_html'] = '<em>Binärdatei – kein Text-Diff möglich.</em>';
        continue;
    }

    $diff = '';
    if ($status === '??') {
        $diff = ahx_wp_github_repo_changes_diff_untracked_file($dir, $f['file'], 20);
    }

    if (trim($diff) === '') {
        // Ensure git sees new files for diff
        ahx_wp_github_repo_changes_git($dir, 'add -N -- ' . escapeshellarg($f['file']), 20);
        ahx_wp_github_repo_changes_git($dir, 'update-index --refresh', 20);

        $diff_res = ahx_wp_github_repo_changes_git($dir, 'diff HEAD -U2 -- ' . escapeshellarg($f['file']), 20);
        $diff = (string)($diff_res['output'] ?? '');
        if (trim($diff) === '') {
            $diff_res = ahx_wp_github_repo_changes_git($dir, 'diff -U2 -- ' . escapeshellarg($f['file']), 20);
            $diff = (string)($diff_res['output'] ?? '');
        }
        if (trim($diff) === '') {
            $diff = ahx_wp_github_repo_changes_diff_untracked_file($dir, $f['file'], 20);
        }
    }

    if (!trim($diff)) {
        $f['diff_html'] = '<em>Keine Änderungen im Text-Diff gefunden.</em>';
        continue;
    }

    // Parse diff into hunks and produce HTML (same style as previous inline code)
    $lines = preg_split('/\r\n|\r|\n/', $diff);
    $lines = array_values(array_filter($lines, function($line) {
        $trimmed = ltrim((string)$line);
        if ($trimmed === '') return true;
        if (stripos($trimmed, 'warning: ') === 0) return false;
        return true;
    }));
    $hunks = [];
    $currentHunk = [];
    $inHunk = false;
    $oldLine = 0; $newLine = 0;
    foreach ($lines as $i => $line) {
        $line = rtrim((string)$line, "\r");
        if (preg_match('/^@@ -(\d+),?(\d*) \+(\d+),?(\d*) @@/', $line, $m)) {
            if ($inHunk && !empty($currentHunk)) {
                $hunks[] = $currentHunk;
            }
            $oldLine = (int)$m[1];
            $newLine = (int)$m[3];
            $currentHunk = [];
            $inHunk = true;
        } elseif ($inHunk) {
            if (!preg_match('/^( |\+|-|\\\\)/', $line)) {
                if (!empty($currentHunk)) {
                    $hunks[] = $currentHunk;
                }
                $currentHunk = [];
                $inHunk = false;
                continue;
            }
            $currentHunk[] = ['raw' => $line, 'old' => $oldLine, 'new' => $newLine];
            if (strlen($line) > 0) {
                if ($line[0] === ' ') { $oldLine++; $newLine++; }
                elseif ($line[0] === '+') { $newLine++; }
                elseif ($line[0] === '-') { $oldLine++; }
            }
            if ($i+1 >= count($lines) || (isset($lines[$i+1][0]) && $lines[$i+1][0] === '@')) {
                $hunks[] = $currentHunk;
                $currentHunk = [];
                $inHunk = false;
            }
        }
    }
    if ($inHunk && !empty($currentHunk)) {
        $hunks[] = $currentHunk;
    }

    $html = '<div style="overflow:auto; border:1px solid #ccc; background:#f8f8f8; padding:8px;">';
    $styleAdd = 'background:#d6ffd6;';
    $styleDel = 'background:#ffecec;';
    $styleCtx = 'background:#f8f8f8;';
    foreach ($hunks as $hunk) {
        $changeIdx = [];
        foreach ($hunk as $idx => $entry) {
            if (isset($entry['raw'][0]) && ($entry['raw'][0] === '+' || $entry['raw'][0] === '-')) $changeIdx[] = $idx;
        }
        sort($changeIdx);
        $ranges = [];
        $lastTo = -1;
        if (empty($changeIdx) && !empty($hunk)) {
            $ranges[] = [0, count($hunk)-1];
        }
        foreach ($changeIdx as $ci) {
            $from = max(0, $ci-2);
            $to = min(count($hunk)-1, $ci+2);
            if ($from <= $lastTo && !empty($ranges)) {
                $ranges[count($ranges)-1][1] = max($ranges[count($ranges)-1][1], $to);
            } else {
                $ranges[] = [$from, $to];
            }
            $lastTo = max($lastTo, $to);
        }
        foreach ($ranges as $range) {
            for ($j = $range[0]; $j <= $range[1]; $j++) {
                $entry = $hunk[$j];
                $line = $entry['raw'];
                $old = $entry['old'];
                $new = $entry['new'];
                $lineStripped = preg_replace('/^\s*\d*\s*/', '', $line);
                if (strpos($lineStripped, 'No newline at end of file') !== false) continue;
                $style = $styleCtx;
                if (isset($line[0]) && $line[0] === '+') $style = $styleAdd;
                elseif (isset($line[0]) && $line[0] === '-') $style = $styleDel;
                $lnOld = isset($line[0]) && $line[0] === '+' ? '' : $old;
                $lnNew = isset($line[0]) && $line[0] === '-' ? '' : $new;
                $lineContent = (isset($line[0]) && ($line[0] === ' ' || $line[0] === '+' || $line[0] === '-')) ? substr($line, 1) : $line;
                $html .= '<div style="display:flex;font-family:monospace;'.$style.'">';
                $html .= '<span style="width:40px;text-align:right;color:#888;">' . ($lnOld !== '' ? $lnOld : '') . '</span>';
                $html .= '<span style="width:40px;text-align:right;color:#888;">' . ($lnNew !== '' ? $lnNew : '') . '</span>';
                $html .= '<span style="white-space:pre;"> ' . htmlspecialchars($lineContent) . '</span>';
                $html .= '</div>';
            }
        }
    }
    $html .= '</div>';
    $f['diff_html'] = $html;
}
unset($f);

// Bestimme Haupt-Plugin-Datei und Versionen für das Commit-Formular
$main_plugin_file = '';
foreach ($files as $f) {
    if (preg_match('/^([^\/]+)\.php$/i', $f['file'], $mm)) { $main_plugin_file = $f['file']; break; }
}
if (!$main_plugin_file) { $plugin_dir = basename($dir); $main_plugin_file = $plugin_dir . '.php'; }
$main_plugin_path = $dir . DIRECTORY_SEPARATOR . $main_plugin_file;
$header_version = '';
if (file_exists($main_plugin_path)) {
    $header = file_get_contents($main_plugin_path);
    if (preg_match('/Version:\s*v?(\d+\.\d+\.\d+)/mi', $header, $m2)) { $header_version = $m2[1]; }
}
if (!$header_version) $header_version = '1.0.0';
list($major, $minor, $patch) = explode('.', $header_version);
$v_patch = 'v' . $major . '.' . $minor . '.' . ((int)$patch + 1);
$v_minor = 'v' . $major . '.' . ((int)$minor + 1) . '.0';
$v_major = 'v' . ((int)$major + 1) . '.0.0';
$header_version_disp = (strpos($header_version, 'v') === 0 ? $header_version : 'v' . $header_version);

// Letzte Commits für Footer-Bereich laden
$recent_commits = [];
$recent_res = ahx_wp_github_repo_changes_git($dir, 'log -n 10 --date=iso --pretty=format:"%h|%ci|%an|%s"', 20);
if (intval($recent_res['exit'] ?? 1) === 0) {
    $recent_lines = preg_split('/\r\n|\r|\n/', trim((string)($recent_res['output'] ?? '')));
    foreach ($recent_lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $parts = explode('|', $line, 4);
        $recent_commits[] = [
            'hash' => $parts[0] ?? '',
            'time' => $parts[1] ?? '',
            'author' => $parts[2] ?? '',
            'subject' => $parts[3] ?? '',
        ];
    }
}

// Commit-Logik: verarbeite POST bevor Ausgabe (delegiert an gemeinsamen Handler)
if (isset($_POST['commit_action'])) {
    $ajax_flag = sanitize_text_field(wp_unslash($_POST['ajax'] ?? ''));
    $is_ajax = ($ajax_flag === '1');
    $commit_nonce = sanitize_text_field(wp_unslash($_POST['ahx_repo_commit_nonce'] ?? ''));
    $commit_nonce_ok = ($commit_nonce !== '' && wp_verify_nonce($commit_nonce, 'ahx_repo_commit_form'));
    if (!$commit_nonce_ok) {
        $error_message = 'Ungültiger Nonce.';
        if (!$is_ajax) {
            ahx_wp_main_add_notice($error_message, 'error');
            $admin_url = admin_url('admin.php?page=ahx-wp-github');
            if (!headers_sent()) { header('Location: ' . $admin_url); exit; }
            else { echo '<script>window.location.href = ' . wp_json_encode($admin_url) . ';</script>'; exit; }
        }
        header('Content-Type: application/json');
        echo wp_json_encode(['success' => false, 'message' => $error_message]);
        exit;
    }

    require_once dirname(__DIR__) . '/admin/commit-handler.php';
    $post_data = [
        'commit_action' => sanitize_text_field(wp_unslash($_POST['commit_action'] ?? 'commit')),
        'commit_message' => sanitize_textarea_field(wp_unslash($_POST['commit_message'] ?? '')),
        'version_bump' => sanitize_key(wp_unslash($_POST['version_bump'] ?? 'none')),
        'allow_force_with_lease_on_rebase_conflict' => sanitize_text_field(wp_unslash($_POST['allow_force_with_lease_on_rebase_conflict'] ?? '')),
        'ajax' => $is_ajax ? '1' : '0',
    ];

    $selected_issue_ids = wp_unslash($_POST['commit_issue_ids'] ?? []);
    if (!is_array($selected_issue_ids)) {
        $selected_issue_ids = [];
    }
    $close_selected_issues = sanitize_text_field(wp_unslash($_POST['commit_close_issues'] ?? '')) === '1';
    $issue_footer = ahx_wp_github_repo_changes_build_issue_footer($selected_issue_ids, $close_selected_issues);
    if ($issue_footer !== '') {
        $base_message = trim((string)$post_data['commit_message']);
        $post_data['commit_message'] = $base_message === '' ? $issue_footer : ($base_message . "\n\n" . $issue_footer);
    }

    $result = ahx_wp_github_process_commit_request($dir, $post_data);
    $action = (string)$post_data['commit_action'];
    $action_label = ($action === 'commit_sync') ? 'Commit+Sync' : 'Commit';

    // Add admin notices for non-AJAX requests
    if (!$is_ajax) {
        if (!empty($result['success'])) {
            ahx_wp_main_add_notice($action_label . ' erfolgreich durchgeführt. Neue Version: ' . esc_html($result['new_version']), 'success');
            if ($action === 'commit_sync' && !empty($result['release_success']) && !empty($result['release_version'])) {
                $release_notice = !empty($result['release_created'])
                    ? ('Release erstellt: ' . $result['release_version'])
                    : ('Release bereits vorhanden: ' . $result['release_version']);
                ahx_wp_main_add_notice(esc_html($release_notice), 'success');
            }
            if (!empty($result['push_output'])) ahx_wp_main_add_notice('Push-Ausgabe (gekürzt): ' . esc_html(substr($result['push_output'],0,400)), 'info');
        } else {
            $error_msg = trim((string)($result['message'] ?? ''));
            if ($error_msg === '') {
                $candidates = [
                    (string)($result['push_output'] ?? ''),
                    (string)($result['rebase_output'] ?? ''),
                    (string)($result['identity_output'] ?? ''),
                    (string)($result['commit_output'] ?? ''),
                    (string)($result['fetch_output'] ?? ''),
                    (string)($result['add_output'] ?? ''),
                ];
                foreach ($candidates as $candidate) {
                    $candidate = trim($candidate);
                    if ($candidate !== '') {
                        $error_msg = $candidate;
                        break;
                    }
                }
                if ($error_msg === '') {
                    $error_msg = 'Unbekannter Fehler';
                }
            }
            if (!empty($result['no_changes'])) {
                ahx_wp_main_add_notice($action_label . ': ' . esc_html(mb_substr($error_msg, 0, 500)), 'info');
            } else {
                ahx_wp_main_add_notice($action_label . ' fehlgeschlagen: ' . esc_html(mb_substr($error_msg, 0, 500)), 'error');
            }
        }
        $admin_url = admin_url('admin.php?page=ahx-wp-github');
        if (!headers_sent()) { header('Location: ' . $admin_url); exit; }
        else { echo '<script>window.location.href = ' . wp_json_encode($admin_url) . ';</script>'; exit; }
    }

    // AJAX response
    header('Content-Type: application/json');
    echo wp_json_encode(array_merge(['success' => !empty($result['success'])], $result));
    exit;
}

?>
<div class="wrap">
    <h1>Git-Änderungen</h1>
    <p><strong>Verzeichnis:</strong> <?php echo preg_replace('/[\\\\\/]+/', '/', $dir); ?></p>
    <?php if ($has_changes): ?>
        <h2>Legende</h2>
        <style>
        .git-legend { font-size:13px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .git-legend .item { display:inline-flex; align-items:center; gap:6px; padding:3px 6px; border-radius:4px; }
        .git-legend code { font-weight:bold; padding:2px 4px; border-radius:3px; }
        .git-status-M { color: #e2a100; } /* Modified */
        .git-status-A { color: #008800; } /* Added */
        .git-status-D { color: #d00000; } /* Deleted */
        .git-status-R { color: #0077cc; } /* Renamed */
        .git-status-C { color: #0077cc; } /* Copied */
        .git-status-U { color: #ff00cc; } /* Unmerged */
        .git-status-ignored { color: #888888; } /* Ignored */
        .git-status-untracked { color: #0055bb; } /* Untracked */
        </style>
        <div class="git-legend" style="max-width:720px">
            <?php foreach ($status_legend as $k => $v): 
                $class = '';
                if ($k === 'M') $class = 'git-status-M';
                elseif ($k === 'A') $class = 'git-status-A';
                elseif ($k === 'D') $class = 'git-status-D';
                elseif ($k === 'R') $class = 'git-status-R';
                elseif ($k === 'C') $class = 'git-status-C';
                elseif ($k === 'U') $class = 'git-status-U';
                elseif ($k === '??') $class = 'git-status-untracked';
                elseif ($k === '!') $class = 'git-status-ignored';
            ?>
                <div class="item"><code class="<?php echo $class; ?>"><?php echo esc_html($k); ?></code><span style="color:#333;"><?php echo esc_html($v); ?></span></div>
            <?php endforeach; ?>
            <div class="item"><code>...</code><span style="color:#333;">Kombinationen: Index/Arbeitsverzeichnis, z.B. "AM" = Added+Modified</span></div>
        </div>
        <h2>Geänderte Dateien</h2>
        <?php
        if (empty($files)) {
            echo '<p>Keine geänderten Dateien erkannt.</p>';
        } else {
            foreach ($files as $f) {
                $class = $f['status_class'] ?? '';
                if (!empty($f['is_empty_dir'])) {
                    echo '<h3><code class="' . $class . '">' . esc_html($f['status']) . '</code> ' . esc_html($f['file']) . ' <span style="color:#888">(leeres Verzeichnis)</span></h3>';
                } else {
                    echo '<h3><code class="' . $class . '">' . esc_html($f['status']) . '</code> ' . esc_html($f['file']) . '</h3>';
                }
                echo $f['diff_html'];
            }
        }
        ?>
    <?php else: ?>
        <p>Keine Änderungen vorhanden.</p>
    <?php endif; ?>

    <!-- Commit-Bereich -->
    <?php if (!empty($files)) : ?>
        <?php // Commit handling and version variables are precomputed above; nothing to do here. ?>
        <form id="ahx-repo-commit-form" method="post" style="margin-top:24px;">
            <?php wp_nonce_field('ahx_repo_commit_form', 'ahx_repo_commit_nonce'); ?>
            <label for="commit_message"><strong>Commit-Beschreibung:</strong></label><br>
            <textarea id="commit_message" name="commit_message" rows="3" style="width:100%;max-width:600px;"><?php echo esc_textarea($prefill_commit_message); ?></textarea><br>
            <?php if (!empty($open_issues_data['enabled'])): ?>
                <fieldset style="margin:12px 0; max-width:900px;">
                    <legend><strong>Offene Issues referenzieren</strong></legend>
                    <?php if (!empty($open_issues_data['error'])): ?>
                        <p style="color:#b32d2e;margin:0 0 8px 0;"><?php echo esc_html((string)$open_issues_data['error']); ?></p>
                    <?php elseif (!empty($open_issues_data['items'])): ?>
                        <p style="margin:0 0 8px 0;color:#50575e;">Wählen Sie die Issues, die im Commit referenziert werden sollen.</p>
                        <div style="max-height:220px;overflow:auto;border:1px solid #dcdcde;padding:8px;background:#fff;">
                            <?php foreach ($open_issues_data['items'] as $issue): ?>
                                <label style="display:block;margin:4px 0;">
                                    <input type="checkbox" name="commit_issue_ids[]" value="<?php echo intval($issue['number']); ?>">
                                    <strong>#<?php echo intval($issue['number']); ?></strong>
                                    <a href="<?php echo esc_url((string)$issue['html_url']); ?>" target="_blank" rel="noopener noreferrer" style="text-decoration:none;">
                                        <?php echo esc_html((string)$issue['title']); ?>
                                    </a>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p style="margin:8px 0 0 0;">
                            <label style="display:inline-flex;align-items:center;gap:6px;">
                                <input type="checkbox" name="commit_close_issues" value="1">
                                Gewählte Issues im Commit als erledigt markieren (Fixes #...)
                            </label>
                        </p>
                    <?php else: ?>
                        <p style="margin:0;color:#50575e;">Keine offenen Issues gefunden.</p>
                    <?php endif; ?>
                </fieldset>
            <?php endif; ?>
            <fieldset style="margin:12px 0; max-width:900px;">
                <legend><strong>Vorschau finaler Commit-Message</strong></legend>
                <p id="ahx-commit-preview-empty" style="margin:0 0 8px 0;color:#50575e;">Die Vorschau aktualisiert sich automatisch bei Änderungen.</p>
                <pre id="ahx-commit-message-preview" style="white-space:pre-wrap;margin:0;background:#f6f7f7;border:1px solid #dcdcde;padding:10px;min-height:72px;"></pre>
            </fieldset>
            <fieldset style="margin:12px 0;">
                <legend><strong>Versionssprung:</strong></legend>
                <label><input type="radio" name="version_bump" value="none" <?php checked($prefill_version_bump, 'none'); ?>> Kein Versionssprung (<?php echo esc_html($header_version_disp); ?>)</label>
                <label style="margin-left:16px;"><input type="radio" name="version_bump" value="patch" <?php checked($prefill_version_bump, 'patch'); ?>> Patch (<?php echo esc_html($header_version_disp . ' ⇒ ' . $v_patch); ?>)</label>
                <label style="margin-left:16px;"><input type="radio" name="version_bump" value="minor" <?php checked($prefill_version_bump, 'minor'); ?>> Minor (<?php echo esc_html($header_version_disp . ' ⇒ ' . $v_minor); ?>)</label>
                <label style="margin-left:16px;"><input type="radio" name="version_bump" value="major" <?php checked($prefill_version_bump, 'major'); ?>> Major (<?php echo esc_html($header_version_disp . ' ⇒ ' . $v_major); ?>)</label>
            </fieldset>
            <p style="margin:10px 0;">
                <label style="display:inline-flex;align-items:center;gap:6px;">
                    <input type="checkbox" name="allow_force_with_lease_on_rebase_conflict" value="1">
                    Bei Rebase-Konflikt automatisch <code>push --force-with-lease</code> versuchen
                </label>
            </p>
            <button type="submit" name="commit_action" value="commit" class="button button-primary" onclick="return confirm('Möchten Sie den Commit jetzt ausführen?');">Commit ausführen</button>
            <button type="submit" name="commit_action" value="commit_sync" class="button button-primary" style="margin-left:12px;" onclick="if(!confirm('Möchten Sie Commit und anschließenden Sync jetzt ausführen?')) return false; var forceCb=this.form.querySelector('input[name=\'allow_force_with_lease_on_rebase_conflict\']'); if(forceCb && forceCb.checked){ return confirm('Warnung: Bei Rebase-Konflikt wird force-with-lease versucht. Dadurch kann Remote-Historie überschrieben werden. Fortfahren?'); } return true;">Commit &amp; Sync</button>
        </form>
    <?php endif; ?>

    <h2 style="margin-top:28px;">Letzte 10 Commits</h2>
    <?php if (!empty($recent_commits)): ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:120px;">Hash</th>
                    <th style="width:220px;">Zeitstempel</th>
                    <th style="width:220px;">Autor</th>
                    <th>Beschreibung</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_commits as $c): ?>
                    <tr>
                        <td><code><?php echo esc_html($c['hash']); ?></code></td>
                        <td><?php echo esc_html($c['time']); ?></td>
                        <td><?php echo esc_html($c['author']); ?></td>
                        <td><?php echo esc_html($c['subject']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>Keine Commit-Historie verfügbar.</p>
    <?php endif; ?>

    <p><a href="<?php echo esc_url($back_url); ?>" class="button">Zurück</a></p>
</div>

<?php if (!empty($files)) : ?>
<script>
(function() {
    var form = document.getElementById('ahx-repo-commit-form');
    if (!form) {
        return;
    }

    var commitMessageEl = document.getElementById('commit_message');
    var previewEl = document.getElementById('ahx-commit-message-preview');
    var hintEl = document.getElementById('ahx-commit-preview-empty');

    if (!commitMessageEl || !previewEl) {
        return;
    }

    function collectIssueNumbers() {
        var checked = form.querySelectorAll('input[name="commit_issue_ids[]"]:checked');
        var values = [];
        for (var i = 0; i < checked.length; i++) {
            var num = parseInt(checked[i].value, 10);
            if (!isNaN(num) && num > 0 && values.indexOf(num) === -1) {
                values.push(num);
            }
        }
        values.sort(function(a, b) { return a - b; });
        return values;
    }

    function buildIssueFooter() {
        var issueNumbers = collectIssueNumbers();
        if (!issueNumbers.length) {
            return '';
        }

        var refs = [];
        for (var i = 0; i < issueNumbers.length; i++) {
            refs.push('#' + issueNumbers[i]);
        }

        var lines = ['Refs: ' + refs.join(', ')];
        var closeIssuesEl = form.querySelector('input[name="commit_close_issues"]');
        var closeIssues = !!(closeIssuesEl && closeIssuesEl.checked);
        if (closeIssues) {
            for (var j = 0; j < issueNumbers.length; j++) {
                lines.push('Fixes #' + issueNumbers[j]);
            }
        }

        return lines.join('\n');
    }

    function renderPreview() {
        var base = String(commitMessageEl.value || '').trim();
        var footer = buildIssueFooter();
        var finalMessage = '';

        if (base !== '' && footer !== '') {
            finalMessage = base + '\n\n' + footer;
        } else if (base !== '') {
            finalMessage = base;
        } else if (footer !== '') {
            finalMessage = footer;
        }

        previewEl.textContent = finalMessage !== '' ? finalMessage : '(leer)';
        if (hintEl) {
            hintEl.style.display = finalMessage !== '' ? 'none' : 'block';
        }
    }

    form.addEventListener('input', function(event) {
        var target = event.target;
        if (!target) {
            return;
        }
        var name = String(target.name || '');
        if (target.id === 'commit_message' || name === 'commit_issue_ids[]' || name === 'commit_close_issues') {
            renderPreview();
        }
    });

    form.addEventListener('change', function(event) {
        var target = event.target;
        if (!target) {
            return;
        }
        var name = String(target.name || '');
        if (name === 'commit_issue_ids[]' || name === 'commit_close_issues') {
            renderPreview();
        }
    });

    renderPreview();
})();
</script>
<?php endif; ?>
