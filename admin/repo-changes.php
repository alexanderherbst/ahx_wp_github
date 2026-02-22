<?php
if (!current_user_can('manage_options')) {
    wp_die(__('Keine Berechtigung.'));
}

$dir = isset($_GET['dir']) ? $_GET['dir'] : '';
if (!$dir || !is_dir($dir)) {
    echo '<div class="error"><p>Ungültiges oder nicht vorhandenes Verzeichnis.</p></div>';
    return;
}

$git_dir = $dir . DIRECTORY_SEPARATOR . '.git';
if (!is_dir($git_dir)) {
    echo '<div class="error"><p>Kein Git-Repository gefunden.</p></div>';
    return;
}

ahx_wp_main_display_admin_notices();

// Änderungen auslesen
$changes = shell_exec('cd "' . $dir . '" && git status --porcelain');
$changes = trim($changes);

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

    // Ensure git sees new files for diff
    shell_exec('cd "' . $dir . '" && git add -N "' . $f['file'] . '" && git update-index --refresh');
    $diff = shell_exec('cd "' . $dir . '" && git diff HEAD -U2 -- "' . $f['file'] . '" 2>&1');
    if (trim($diff) === '') {
        $diff = shell_exec('cd "' . $dir . '" && git diff -U2 -- "' . $f['file'] . '" 2>&1');
    }
    if (trim($diff) === '') {
        $diff = shell_exec('cd "' . $dir . '" && git diff --no-index -U2 /dev/null "' . $f['file'] . '" 2>&1');
    }

    if (!trim($diff)) {
        $f['diff_html'] = '<em>Keine Änderungen im Text-Diff gefunden.</em>';
        continue;
    }

    // Parse diff into hunks and produce HTML (same style as previous inline code)
    $lines = explode("\n", $diff);
    $hunks = [];
    $currentHunk = [];
    $inHunk = false;
    $oldLine = 0; $newLine = 0;
    foreach ($lines as $i => $line) {
        if (preg_match('/^@@ -(\d+),?(\d*) \+(\d+),?(\d*) @@/', $line, $m)) {
            $oldLine = (int)$m[1];
            $newLine = (int)$m[3];
            $currentHunk = [];
            $inHunk = true;
        } elseif ($inHunk) {
            $currentHunk[] = ['raw' => $line, 'old' => $oldLine, 'new' => $newLine];
            if (strlen($line) > 0) {
                if ($line[0] === ' ') { $oldLine++; $newLine++; }
                elseif ($line[0] === '+') { $newLine++; }
                elseif ($line[0] === '-') { $oldLine++; }
            }
            if ($i+1 >= count($lines) || (isset($lines[$i+1][0]) && $lines[$i+1][0] === '@')) {
                $hunks[] = $currentHunk;
                $inHunk = false;
            }
        }
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
                $lineContent = isset($line[0]) ? substr($line, 1) : $line;
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

// Helper: run command with timeout and capture output/exit code (cross-platform)
function ahx_run_cmd_with_timeout($cmd, $cwd = null, $env = null, $timeout = 20) {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    if (stripos(PHP_OS, 'WIN') === 0) {
        $cmd_full = $cmd; // proc_open will use cmd.exe automatically when needed
    } else {
        $cmd_full = $cmd;
    }
    $process = proc_open($cmd_full, $descriptors, $pipes, $cwd, $env);
    if (!is_resource($process)) return ['exit' => -1, 'output' => 'proc_open failed'];
    stream_set_blocking($pipes[1], 0);
    stream_set_blocking($pipes[2], 0);
    $output = '';
    $start = microtime(true);
    $exit = null;
    while (true) {
        $status = proc_get_status($process);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        if ($out !== '') $output .= $out;
        if ($err !== '') $output .= $err;
        if (!$status['running']) {
            $exit = $status['exitcode'];
            break;
        }
        if (microtime(true) - $start > $timeout) {
            // timeout: try to terminate
            proc_terminate($process);
            $exit = 124; // timeout
            break;
        }
        usleep(100000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return ['exit' => $exit, 'output' => $output];
}

// Find git binary on the system (returns full path or 'git' fallback)
function ahx_find_git_binary() {
    // Windows: use where, Unix: use command -v
    if (stripos(PHP_OS, 'WIN') === 0) {
        exec('where git 2>&1', $lines, $code);
        if (!empty($lines)) return trim($lines[0]);
    } else {
        exec('command -v git 2>&1', $lines, $code);
        if (!empty($lines)) return trim($lines[0]);
    }
    return 'git';
}

// Push changes via GitHub Contents API using a Personal Access Token
function ahx_github_api_push($dir, $remote_url, $branch, $files, $commit_msg, $token) {
    $out = '';
    $exit = 0;
    // parse owner/repo from remote URL
    $owner_repo = '';
    if (preg_match('#github\.com[:/](.+?)(?:\.git)?$#', $remote_url, $m)) {
        $owner_repo = trim($m[1], '/');
    }
    if ($owner_repo === '') {
        $out .= "Could not parse owner/repo from remote URL: {$remote_url}\n";
        return ['exit' => 2, 'output' => $out];
    }
    list($owner, $repo) = array_pad(explode('/', $owner_repo, 2), 2, '');
    if ($owner === '' || $repo === '') {
        $out .= "Invalid owner/repo parsed: {$owner_repo}\n";
        return ['exit' => 2, 'output' => $out];
    }

    $api_base = "https://api.github.com/repos/{$owner}/{$repo}/contents/";
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'User-Agent' => 'WordPress GH Push',
        'Accept' => 'application/vnd.github.v3+json'
    ];

    foreach ($files as $f) {
        $status = trim($f['status']);
        $file = $f['file'];

        // Handle rename: format "old -> new"
        if (strpos($file, '->') !== false) {
            $parts = array_map('trim', explode('->', $file));
            if (count($parts) === 2) {
                $old = $parts[0]; $new = $parts[1];
                // Delete old
                $res_del = ahx_github_api_delete_file($api_base, $old, $branch, $commit_msg, $headers, $dir);
                $out .= $res_del['output'];
                if ($res_del['exit'] !== 0) $exit = max($exit, $res_del['exit']);
                // Add new
                $res_add = ahx_github_api_put_file($api_base, $new, $branch, $commit_msg, $headers, $dir);
                $out .= $res_add['output'];
                if ($res_add['exit'] !== 0) $exit = max($exit, $res_add['exit']);
                continue;
            }
        }

        if ($status === 'D') {
            $res = ahx_github_api_delete_file($api_base, $file, $branch, $commit_msg, $headers, $dir);
            $out .= $res['output'];
            if ($res['exit'] !== 0) $exit = max($exit, $res['exit']);
            continue;
        }

        // For added/modified/untracked etc. -> put file
        $res = ahx_github_api_put_file($api_base, $file, $branch, $commit_msg, $headers, $dir);
        $out .= $res['output'];
        if ($res['exit'] !== 0) $exit = max($exit, $res['exit']);
    }

    return ['exit' => $exit, 'output' => $out];
}

function ahx_github_api_put_file($api_base, $path, $branch, $message, $headers, $dir) {
    $out = '';
    $full = $dir . DIRECTORY_SEPARATOR . $path;
    if (!file_exists($full)) {
        return ['exit' => 1, 'output' => "Local file not found: {$path}\n"]; 
    }
    $content = file_get_contents($full);
    $content_b64 = base64_encode($content);

    // Try to get existing SHA
    $url = $api_base . implode('/', array_map('rawurlencode', explode('/', $path))) . '?ref=' . rawurlencode($branch);
    $resp = wp_remote_get($url, ['headers' => $headers, 'timeout' => 20]);
    $sha = null;
    if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($body['sha'])) $sha = $body['sha'];
    }

    $body = ['message' => $message, 'content' => $content_b64, 'branch' => $branch];
    if ($sha) $body['sha'] = $sha;

    $put_url = $api_base . implode('/', array_map('rawurlencode', explode('/', $path)));
    $args = ['headers' => array_merge($headers, ['Content-Type' => 'application/json']), 'body' => json_encode($body), 'timeout' => 30];
    $res = wp_remote_request($put_url, array_merge($args, ['method' => 'PUT']));
    if (is_wp_error($res)) {
        $out .= "PUT {$path} failed: " . $res->get_error_message() . "\n";
        return ['exit' => 1, 'output' => $out];
    }
    $code = wp_remote_retrieve_response_code($res);
    $out .= "PUT {$path}: HTTP {$code}\n";
    if ($code >= 200 && $code < 300) return ['exit' => 0, 'output' => $out];
    return ['exit' => 1, 'output' => $out . wp_remote_retrieve_body($res) . "\n"]; 
}

function ahx_github_api_delete_file($api_base, $path, $branch, $message, $headers, $dir) {
    $out = '';
    // Need existing SHA
    $url = $api_base . implode('/', array_map('rawurlencode', explode('/', $path))) . '?ref=' . rawurlencode($branch);
    $resp = wp_remote_get($url, ['headers' => $headers, 'timeout' => 20]);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        $out .= "DELETE {$path}: file not found on remote or error\n";
        return ['exit' => 0, 'output' => $out]; // already deleted or not present
    }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $sha = $body['sha'] ?? null;
    if (!$sha) {
        $out .= "DELETE {$path}: could not determine sha\n";
        return ['exit' => 1, 'output' => $out];
    }
    $del_body = ['message' => $message, 'sha' => $sha, 'branch' => $branch];
    $del_url = $api_base . implode('/', array_map('rawurlencode', explode('/', $path)));
    $args = ['headers' => array_merge($headers, ['Content-Type' => 'application/json']), 'body' => json_encode($del_body), 'timeout' => 30];
    $res = wp_remote_request($del_url, array_merge($args, ['method' => 'DELETE']));
    if (is_wp_error($res)) {
        $out .= "DELETE {$path} failed: " . $res->get_error_message() . "\n";
        return ['exit' => 1, 'output' => $out];
    }
    $code = wp_remote_retrieve_response_code($res);
    $out .= "DELETE {$path}: HTTP {$code}\n";
    if ($code >= 200 && $code < 300) return ['exit' => 0, 'output' => $out];
    return ['exit' => 1, 'output' => $out . wp_remote_retrieve_body($res) . "\n"]; 
}

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

// Commit-Logik: verarbeite POST bevor Ausgabe
if (isset($_POST['commit_action'])) {
    // Debug log: entered commit handler
    if (class_exists('AHX_Logging')) AHX_Logging::get_instance()->log_debug('repo-changes: entered POST handler', 'ahx_wp_github');
    error_log('[ahx_wp_github] repo-changes: entered POST handler');

    $commit_msg = trim($_POST['commit_message'] ?? '');
    $action = $_POST['commit_action'] ?? 'commit';
    error_log('[ahx_wp_github] repo-changes: commit_action=' . var_export($action, true));

    // Check whether shell_exec is available
    $disabled = ini_get('disable_functions');
    $shell_exec_disabled = false;
    if (!function_exists('shell_exec') || stripos($disabled ?? '', 'shell_exec') !== false) {
        $shell_exec_disabled = true;
        if (class_exists('AHX_Logging')) {
            AHX_Logging::get_instance()->log_error('repo-changes: shell_exec is disabled or not available', 'ahx_wp_github');
        } else {
            error_log('repo-changes: shell_exec is disabled or not available');
        }
        ahx_wp_main_add_notice('Serverkonfiguration verhindert Ausführung von Git-Kommandos (shell_exec deaktiviert).', 'error');
    }
    if ($commit_msg === '') {
        ahx_wp_main_print_notice_now('Bitte eine Commit-Beschreibung eingeben.', 'error');
    } else {
        $bump = $_POST['version_bump'] ?? 'none';
        $new_version = $header_version_disp;
        if ($bump === 'patch') $new_version = $v_patch;
        elseif ($bump === 'minor') $new_version = $v_minor;
        elseif ($bump === 'major') $new_version = $v_major;

        // Header im Hauptplugin aktualisieren
        if (file_exists($main_plugin_path)) {
            $main_file_contents = file_get_contents($main_plugin_path);
            $main_file_contents = preg_replace('/(Version:\s*)v?(\d+\.\d+\.\d+)/i', '$1' . $new_version, $main_file_contents, 1);
            $main_file_contents = preg_replace('/(define\s*\(\s*["\']([A-Z0-9_]+_VERSION)["\']\s*,\s*["\']?)v?(\d+\.\d+\.\d+)(["\']?\s*\))/i', '$1' . $new_version . '$4', $main_file_contents);
            $main_file_contents = preg_replace('/(const\s+[A-Z0-9_]+_VERSION\s*=\s*["\']?)v?(\d+\.\d+\.\d+)(["\']?)/i', '$1' . $new_version . '$3', $main_file_contents);
            file_put_contents($main_plugin_path, $main_file_contents);
        }
        $version_txt = $dir . DIRECTORY_SEPARATOR . 'version.txt';
        if (file_exists($version_txt)) file_put_contents($version_txt, $new_version . "\n");

        // Commit
        if (!$shell_exec_disabled) {
            $git_bin = ahx_find_git_binary();
            error_log('[ahx_wp_github] repo-changes: git binary detected: ' . var_export($git_bin, true));

            $add_cmd = escapeshellarg($git_bin) . ' add .';
            $commit_cmd = escapeshellarg($git_bin) . ' commit -m ' . escapeshellarg($commit_msg);

            error_log('[ahx_wp_github] repo-changes: running: cd ' . $dir . ' && ' . $add_cmd);
            exec('cd ' . escapeshellarg($dir) . ' && ' . $add_cmd . ' 2>&1', $add_lines, $add_exit);
            $add_output = trim(implode("\n", $add_lines));
            error_log('[ahx_wp_github] repo-changes: git add exit=' . intval($add_exit) . ' output=' . substr($add_output,0,2000));
            if (class_exists('AHX_Logging')) AHX_Logging::get_instance()->log_debug('repo-changes: git add exit=' . intval($add_exit) . ' output=' . substr($add_output,0,2000), 'ahx_wp_github');

            error_log('[ahx_wp_github] repo-changes: running: cd ' . $dir . ' && ' . $commit_cmd);
            exec('cd ' . escapeshellarg($dir) . ' && ' . $commit_cmd . ' 2>&1', $commit_lines, $commit_exit);
            $commit_output = trim(implode("\n", $commit_lines));
            error_log('[ahx_wp_github] repo-changes: git commit exit=' . intval($commit_exit) . ' output=' . substr($commit_output,0,4000));
            if (class_exists('AHX_Logging')) AHX_Logging::get_instance()->log_debug('repo-changes: git commit exit=' . intval($commit_exit) . ' output=' . substr($commit_output,0,4000), 'ahx_wp_github');

            // Normalize commit output variable for later checks
            if ($commit_exit !== 0 && $commit_output === '') {
                // No commit made or error; leave output as-is
            }
        } else {
            $commit_output = null;
            error_log('[ahx_wp_github] repo-changes: shell_exec disabled - skipping git commands');
        }

        // If requested, perform a push when a remote exists
        if ($action === 'commit_push') {
            $remotes_raw = '';
            if (!$shell_exec_disabled) {
                $remotes_raw = trim(shell_exec('cd "' . $dir . '" && git remote'));
            }
            $remotes_arr = preg_split('/\r\n|\r|\n/', $remotes_raw);
            $remote = '';
            foreach ($remotes_arr as $r) { $r = trim($r); if ($r !== '') { $remote = $r; break; } }

            error_log('[ahx_wp_github] repo-changes: detected remote=' . var_export($remote, true));
            if ($remote === '') {
                ahx_wp_main_add_notice('Commit durchgeführt, aber kein Remote-Repository gefunden (kein Push möglich).', 'warning');
            } else {
                // Determine current branch
                $branch = '';
                if (!$shell_exec_disabled) {
                    $branch = trim(shell_exec('cd "' . $dir . '" && git rev-parse --abbrev-ref HEAD'));
                }
                error_log('[ahx_wp_github] repo-changes: branch=' . var_export($branch, true));

                // If commit did not create a new commit, skip push
                if (stripos($commit_output ?? '', 'nothing to commit') !== false || trim($commit_output) === '') {
                    ahx_wp_main_add_notice('Commit hatte keine Änderungen; Push übersprungen.', 'info');
                } else {
                    // Prevent interactive credential prompts: Windows vs Unix prefix
                    if (stripos(PHP_OS, 'WIN') === 0) {
                        $env_prefix = 'set GIT_TERMINAL_PROMPT=0 && ';
                    } else {
                        $env_prefix = 'GIT_TERMINAL_PROMPT=0 ';
                    }
                    $push_output = '';
                    $push_exit = null;
                    if (!$shell_exec_disabled) {
                        // Build git command (without cd/redirection); use helper to run with timeout
                        $git_bin_push = ahx_find_git_binary();
                        error_log('[ahx_wp_github] repo-changes: git binary for push: ' . var_export($git_bin_push, true));

                        // Try GitHub API push when AHX Main provides a token
                        $gh_token = get_option('ahx_wp_main_github_token', '');
                        $remote_url = '';
                        if (!$shell_exec_disabled) {
                            $remote_url = trim(shell_exec('cd "' . $dir . '" && git remote get-url ' . escapeshellarg($remote) . ' 2>&1'));
                        }
                        if (empty($remote_url)) {
                            $git_config = @file_get_contents($dir . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'config');
                            if ($git_config && preg_match('/\[remote "' . preg_quote($remote, '/') . '"\][^\[]*url = (.+)/', $git_config, $m)) {
                                $remote_url = trim($m[1]);
                            }
                        }

                        $prefer_api = get_option('ahx_wp_github_prefer_api', '1');
                        $prefer_api_enabled = ($prefer_api === '1' || $prefer_api === 1 || $prefer_api === true);
                        if ($prefer_api_enabled && !empty($gh_token) && !empty($remote_url)) {
                            error_log('[ahx_wp_github] repo-changes: using GitHub API push via AHX Main token');
                            if (class_exists('AHX_Logging')) AHX_Logging::get_instance()->log_debug('repo-changes: using GitHub API push via AHX Main token', 'ahx_wp_github');
                            $push_result = ahx_github_api_push($dir, $remote_url, $branch, $files, $commit_msg, $gh_token);
                            $push_exit = $push_result['exit'];
                            $push_output = $push_result['output'];
                        } else {
                            // Ensure repository is considered safe by git when PHP runs as a different user
                            $safe_cmd = escapeshellarg($git_bin_push) . ' config --global --add safe.directory ' . escapeshellarg($dir);
                            error_log('[ahx_wp_github] repo-changes: running safe.directory command: ' . $safe_cmd);
                            exec('' . $safe_cmd . ' 2>&1', $safe_lines, $safe_exit);
                            $safe_output = trim(implode("\n", $safe_lines));
                            error_log('[ahx_wp_github] repo-changes: safe.directory exit=' . intval($safe_exit) . ' output=' . substr($safe_output,0,1000));

                            // Use -c safe.directory for this invocation to avoid 'dubious ownership' errors
                            $git_cmd = escapeshellarg($git_bin_push) . ' -c safe.directory=' . escapeshellarg($dir) . ' push --set-upstream ' . escapeshellarg($remote) . ' ' . escapeshellarg($branch);
                            $env_vars = ['GIT_TERMINAL_PROMPT' => '0'];
                            if (!empty($remote_url) && (strpos($remote_url, 'git@') === 0 || strpos($remote_url, 'ssh://') === 0)) {
                                $env_vars['GIT_SSH_COMMAND'] = 'ssh -o BatchMode=yes';
                            }
                            error_log('[ahx_wp_github] repo-changes: running push_cmd (helper)=' . $git_cmd . ' cwd=' . $dir . ' env=' . json_encode($env_vars));
                            $res = ahx_run_cmd_with_timeout($git_cmd, $dir, array_merge($_ENV, $env_vars), 20);
                            $push_exit = $res['exit'];
                            $push_output = $res['output'];
                            error_log('[ahx_wp_github] repo-changes: git push exit=' . intval($push_exit) . ' output=' . substr($push_output,0,2000));
                            if (class_exists('AHX_Logging')) AHX_Logging::get_instance()->log_debug('repo-changes: git push exit=' . intval($push_exit) . ' output=' . substr($push_output,0,2000), 'ahx_wp_github');
                        }
                    } else {
                        error_log('[ahx_wp_github] repo-changes: push skipped because shell_exec disabled');
                        if (class_exists('AHX_Logging')) AHX_Logging::get_instance()->log_error('repo-changes: push skipped because shell_exec disabled', 'ahx_wp_github');
                    }
                    // If API push was used and succeeded, try to set local upstream tracking
                    $gh_token_check = get_option('ahx_wp_main_github_token', '');
                    $remote_url_check = '';
                    if (!$shell_exec_disabled) {
                        $remote_url_check = trim(shell_exec('cd "' . $dir . '" && git remote get-url ' . escapeshellarg($remote) . ' 2>&1'));
                    }
                    if (empty($remote_url_check)) {
                        $git_config = @file_get_contents($dir . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'config');
                        if ($git_config && preg_match('/\[remote "' . preg_quote($remote, '/') . '"\][^\[]*url = (.+)/', $git_config, $m)) {
                            $remote_url_check = trim($m[1]);
                        }
                    }

                    if (!empty($gh_token_check) && !empty($remote_url_check) && intval($push_exit) === 0) {
                            if (!$shell_exec_disabled) {
                            $git_for_up = ahx_find_git_binary();
                            // Use -c safe.directory to avoid dubious ownership errors when running as different user
                            $up_cmd = escapeshellarg($git_for_up) . ' -c safe.directory=' . escapeshellarg($dir) . ' branch --set-upstream-to=' . escapeshellarg($remote . '/' . $branch) . ' ' . escapeshellarg($branch);
                            error_log('[ahx_wp_github] repo-changes: running upstream_cmd=' . $up_cmd . ' cwd=' . $dir);
                            $res_up = ahx_run_cmd_with_timeout($up_cmd, $dir, $_ENV, 8);
                            error_log('[ahx_wp_github] repo-changes: set-upstream exit=' . intval($res_up['exit']) . ' output=' . substr($res_up['output'],0,1000));
                            if (intval($res_up['exit']) === 0) {
                                ahx_wp_main_add_notice('Lokales Upstream-Tracking gesetzt: origin/' . esc_html($branch), 'success');
                            } else {
                                ahx_wp_main_add_notice('Upstream-Tracking konnte nicht automatisch gesetzt werden; siehe Log.', 'warning');
                            }
                        } else {
                            ahx_wp_main_add_notice('Upstream-Tracking nicht gesetzt (serverseitige Befehle deaktiviert).', 'info');
                        }
                    }

                    // Truncate output for notice to avoid huge messages
                    ahx_wp_main_add_notice('Commit und Push durchgeführt. Push-Ausgabe: ' . esc_html(substr($push_output, 0, 400)), 'success');
                }
            }
        } else {
            ahx_wp_main_add_notice('Commit erfolgreich durchgeführt. Neue Version: ' . esc_html($new_version), 'success');
        }

        $admin_url = admin_url('admin.php?page=ahx-wp-github');
        if (!headers_sent()) { header('Location: ' . $admin_url); exit; }
        else { echo '<script>window.location.href = ' . json_encode($admin_url) . ';</script>'; exit; }
    }
}

?>
<div class="wrap">
    <h1>Git-Änderungen</h1>
    <p><strong>Verzeichnis:</strong> <?php echo preg_replace('/[\\\\\/]+/', '/', $dir); ?></p>
    <?php if ($changes): ?>
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
        <form method="post" style="margin-top:24px;">
            <label for="commit_message"><strong>Commit-Beschreibung:</strong></label><br>
            <textarea id="commit_message" name="commit_message" rows="3" style="width:100%;max-width:600px;"></textarea><br>
            <fieldset style="margin:12px 0;">
                <legend><strong>Versionssprung:</strong></legend>
                <label><input type="radio" name="version_bump" value="none" checked> Kein Versionssprung (<?php echo esc_html($header_version_disp); ?>)</label>
                <label style="margin-left:16px;"><input type="radio" name="version_bump" value="patch"> Patch (<?php echo esc_html($header_version_disp . ' ⇒ ' . $v_patch); ?>)</label>
                <label style="margin-left:16px;"><input type="radio" name="version_bump" value="minor"> Minor (<?php echo esc_html($header_version_disp . ' ⇒ ' . $v_minor); ?>)</label>
                <label style="margin-left:16px;"><input type="radio" name="version_bump" value="major"> Major (<?php echo esc_html($header_version_disp . ' ⇒ ' . $v_major); ?>)</label>
            </fieldset>
            <button type="submit" name="commit_action" value="commit" class="button button-primary">Commit ausführen</button>
            <button type="submit" name="commit_action" value="commit_push" class="button">Commit und Push</button>
        </form>
    <?php endif; ?>
    <p><a href="admin.php?page=ahx-wp-github" class="button">Zurück</a></p>
</div>
