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

// Load logging helper (if available) for secure logging of git push output
if (!class_exists('AHX_Logging') && file_exists(WP_CONTENT_DIR . '/plugins/ahx_wp_main/helper/logging.php')) {
    require_once WP_CONTENT_DIR . '/plugins/ahx_wp_main/helper/logging.php';
}
$logger = class_exists('AHX_Logging') ? AHX_Logging::get_instance() : null;

// Änderungen auslesen
$changes = shell_exec('cd "' . $dir . '" && git status --porcelain');
$changes = trim($changes);

// Mapping für Statuskürzel
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
    $lines = explode("\n", $changes);
    foreach ($lines as $line) {
        // Fange alle Zeilen ab, die mindestens 4 Zeichen haben
        if (strlen($line) >= 4) {
            $status = substr($line, 0, 2);
            $file = ltrim(substr($line, 2));
            if ($file !== '') {
                $full_path = $dir . DIRECTORY_SEPARATOR . $file;
                if (is_dir($full_path)) {
                    // Prüfe, ob das Verzeichnis leer ist oder nur .gitkeep/.keep enthält
                    $dir_files = @scandir($full_path);
                    $only_keep = true;
                    foreach ($dir_files as $df) {
                        if ($df === '.' || $df === '..') continue;
                        if (!in_array($df, ['.gitkeep', '.keep'])) {
                            $only_keep = false;
                            break;
                        }
                    }
                    if ($only_keep) {
                        $files[] = ['status' => $status, 'file' => $file, 'is_empty_dir' => true];
                    }
                } else {
                    $files[] = ['status' => $status, 'file' => $file, 'is_empty_dir' => false];
                }
            }
        }
    }
}

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
    $commit_msg = trim($_POST['commit_message'] ?? '');
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

        // prepare push option
        $commit_and_submit = isset($_POST['commit_and_submit']) && $_POST['commit_and_submit'] === '1';

        shell_exec('cd "' . $dir . '" && git add .');
        $commit_out = shell_exec('cd "' . $dir . '" && git commit -m ' . escapeshellarg($commit_msg) . ' 2>&1');

        ahx_wp_main_add_notice('Commit erfolgreich durchgeführt. Neue Version: ' . esc_html($new_version), 'success');

        if ($commit_and_submit) {
            // determine push remote
            $remotes = trim((string) shell_exec('cd "' . $dir . '" && git remote 2>&1'));
            $push_remote = 'origin';
            if ($remotes === '') {
                ahx_wp_main_add_notice('Push fehlgeschlagen: Kein Remote konfiguriert.', 'error');
                if ($logger) $logger->log_error('Push failed: no remotes for ' . $dir, 'ahx_wp_github');
            } else {
                $rem_list = preg_split('/\s+/', $remotes, -1, PREG_SPLIT_NO_EMPTY);
                if (!in_array($push_remote, $rem_list, true)) {
                    $push_remote = $rem_list[0];
                }
                $branch = trim(shell_exec('cd "' . $dir . '" && git rev-parse --abbrev-ref HEAD 2>&1'));
                if ($branch === '' || strpos($branch, 'fatal:') === 0) {
                    ahx_wp_main_add_notice('Push fehlgeschlagen: aktueller Branch konnte nicht ermittelt werden.', 'error');
                    if ($logger) $logger->log_error('Push failed (no branch) for ' . $dir, 'ahx_wp_github');
                } else {
                    $push_cmd = 'cd "' . $dir . '" && git push -u ' . escapeshellarg($push_remote) . ' ' . escapeshellarg($branch) . ' 2>&1';
                    $push_out = shell_exec($push_cmd);
                    if ($logger) $logger->log_info('git push output for ' . $dir . ' to ' . $push_remote . '/' . $branch . ': ' . $push_out, 'ahx_wp_github');
                    if (stripos($push_out, 'error') !== false || stripos($push_out, 'fatal') !== false) {
                        ahx_wp_main_add_notice('Push fehlgeschlagen (siehe Log).', 'error');
                    } else {
                        ahx_wp_main_add_notice('Push erfolgreich: ' . esc_html($push_remote) . '/' . esc_html($branch), 'success');
                    }
                }
            }
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
            <label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="commit_and_submit" value="1"> Commit + Submit (push)</label>
            <button type="submit" name="commit_action" class="button button-primary">Commit ausführen</button>
        </form>
    <?php endif; ?>
    <p><a href="admin.php?page=ahx-wp-github" class="button">Zurück</a></p>
</div>
