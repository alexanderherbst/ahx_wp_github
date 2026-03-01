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

if (!function_exists('ahx_run_cmd_with_timeout') || !function_exists('ahx_find_git_binary')) {
    require_once plugin_dir_path(__FILE__) . 'commit-handler.php';
}

function ahx_wp_github_repo_changes_git($dir, $args, $timeout = 20) {
    return ahx_run_git_cmd(ahx_find_git_binary(), $dir, $args, $timeout, false);
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

ahx_wp_main_display_admin_notices();

// Änderungen auslesen
$status_result = ahx_wp_github_repo_changes_git($dir, 'status --porcelain', 20);
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
    ahx_wp_github_repo_changes_git($dir, 'add -N -- ' . escapeshellarg($f['file']), 20);
    ahx_wp_github_repo_changes_git($dir, 'update-index --refresh', 20);

    $diff_res = ahx_wp_github_repo_changes_git($dir, 'diff HEAD -U2 -- ' . escapeshellarg($f['file']), 20);
    $diff = (string)($diff_res['output'] ?? '');
    if (trim($diff) === '') {
        $diff_res = ahx_wp_github_repo_changes_git($dir, 'diff -U2 -- ' . escapeshellarg($f['file']), 20);
        $diff = (string)($diff_res['output'] ?? '');
    }
    if (trim($diff) === '') {
        $null_device = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
        $diff_res = ahx_wp_github_repo_changes_git($dir, 'diff --no-index -U2 ' . $null_device . ' ' . escapeshellarg($f['file']), 20);
        $diff = (string)($diff_res['output'] ?? '');
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

// Commit-Logik: verarbeite POST bevor Ausgabe (delegiert an gemeinsamen Handler)
if (isset($_POST['commit_action'])) {
    require_once dirname(__DIR__) . '/admin/commit-handler.php';
    $result = ahx_wp_github_process_commit_request($dir, $_POST);
    $action = sanitize_text_field($_POST['commit_action'] ?? 'commit');
    $action_label = ($action === 'commit_sync') ? 'Commit+Sync' : 'Commit';

    // Add admin notices for non-AJAX requests
    if (empty($_POST['ajax']) || $_POST['ajax'] != '1') {
        if (!empty($result['success'])) {
            ahx_wp_main_add_notice($action_label . ' erfolgreich durchgeführt. Neue Version: ' . esc_html($result['new_version']), 'success');
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
        else { echo '<script>window.location.href = ' . json_encode($admin_url) . ';</script>'; exit; }
    }

    // AJAX response
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => !empty($result['success'])], $result));
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
            <label for="commit_message"><strong>Commit-Beschreibung:</strong></label><br>
            <textarea id="commit_message" name="commit_message" rows="3" style="width:100%;max-width:600px;"></textarea><br>
            <fieldset style="margin:12px 0;">
                <legend><strong>Versionssprung:</strong></legend>
                <label><input type="radio" name="version_bump" value="none" checked> Kein Versionssprung (<?php echo esc_html($header_version_disp); ?>)</label>
                <label style="margin-left:16px;"><input type="radio" name="version_bump" value="patch"> Patch (<?php echo esc_html($header_version_disp . ' ⇒ ' . $v_patch); ?>)</label>
                <label style="margin-left:16px;"><input type="radio" name="version_bump" value="minor"> Minor (<?php echo esc_html($header_version_disp . ' ⇒ ' . $v_minor); ?>)</label>
                <label style="margin-left:16px;"><input type="radio" name="version_bump" value="major"> Major (<?php echo esc_html($header_version_disp . ' ⇒ ' . $v_major); ?>)</label>
            </fieldset>
            <button type="submit" name="commit_action" value="commit" class="button button-primary" onclick="return confirm('Möchten Sie den Commit jetzt ausführen?');">Commit ausführen</button>
            <button type="submit" name="commit_action" value="commit_sync" class="button button-primary" style="margin-left:12px;" onclick="return confirm('Möchten Sie Commit und anschließenden Sync jetzt ausführen?');">Commit &amp; Sync</button>
        </form>
    <?php endif; ?>
    <p><a href="<?php echo esc_url($back_url); ?>" class="button">Zurück</a></p>
</div>
