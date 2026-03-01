
<?php
if (!current_user_can('manage_options')) {
    wp_die(__('Keine Berechtigung.'));
}

if (!function_exists('ahx_run_cmd_with_timeout') || !function_exists('ahx_find_git_binary')) {
    require_once plugin_dir_path(__FILE__) . 'commit-handler.php';
}

function ahx_wp_github_admin_run_git($dir, $args, $timeout = 20, $needs_remote_auth = false) {
    return ahx_run_git_cmd(ahx_find_git_binary(), $dir, $args, $timeout, $needs_remote_auth);
}

function ahx_wp_github_admin_is_sync_pending($dir, $git_timeout = 15) {
    $branch_res = ahx_wp_github_admin_run_git($dir, 'rev-parse --abbrev-ref HEAD', $git_timeout);
    if (intval($branch_res['exit'] ?? 1) !== 0) {
        return false;
    }

    $branch = trim((string)($branch_res['output'] ?? ''));
    if ($branch === '' || $branch === 'HEAD') {
        return false;
    }

    $upstream_res = ahx_wp_github_admin_run_git($dir, 'rev-parse --abbrev-ref --symbolic-full-name @{u}', $git_timeout);
    if (intval($upstream_res['exit'] ?? 1) === 0 && trim((string)($upstream_res['output'] ?? '')) !== '') {
        $ahead_res = ahx_wp_github_admin_run_git($dir, 'rev-list --left-right --count @{u}...HEAD', $git_timeout);
        if (intval($ahead_res['exit'] ?? 1) !== 0) {
            return false;
        }
        $parts = preg_split('/\s+/', trim((string)($ahead_res['output'] ?? '')));
        $ahead = isset($parts[1]) ? intval($parts[1]) : 0;
        return $ahead > 0;
    }

    // Kein Upstream: wenn ein origin-Remote existiert, ist ein initialer Publish/Sync wahrscheinlich nötig.
    $origin_res = ahx_wp_github_admin_run_git($dir, 'remote get-url origin', $git_timeout);
    if (intval($origin_res['exit'] ?? 1) === 0 && trim((string)($origin_res['output'] ?? '')) !== '') {
        return true;
    }

    $ahead_main_res = ahx_wp_github_admin_run_git($dir, 'rev-list --count main..HEAD', $git_timeout);
    if (intval($ahead_main_res['exit'] ?? 1) === 0) {
        return intval(trim((string)($ahead_main_res['output'] ?? '0'))) > 0;
    }

    $ahead_master_res = ahx_wp_github_admin_run_git($dir, 'rev-list --count master..HEAD', $git_timeout);
    if (intval($ahead_master_res['exit'] ?? 1) === 0) {
        return intval(trim((string)($ahead_master_res['output'] ?? '0'))) > 0;
    }

    return false;
}

function ahx_wp_github_admin_count_untracked_empty_dirs($dir, $git_timeout = 15) {
    $count = 0;
    $root = realpath($dir);
    if ($root === false || !is_dir($root)) {
        return 0;
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

        $ignore_res = ahx_wp_github_admin_run_git($root, 'check-ignore -q -- ' . escapeshellarg($rel . '/'), $git_timeout, false);
        if (intval($ignore_res['exit'] ?? 1) === 0) {
            continue;
        }

        $count++;
    }

    return $count;
}

function ahx_wp_github_admin_get_repo_version($dir, $name = '') {
    $version_txt = rtrim((string)$dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'version.txt';
    if (is_file($version_txt) && is_readable($version_txt)) {
        $txt = @file_get_contents($version_txt);
        if ($txt !== false) {
            $ver = trim((string)$txt);
            if ($ver !== '') {
                return preg_match('/^v/i', $ver) ? $ver : ('v' . $ver);
            }
        }
    }

    $name = trim((string)$name);
    if ($name !== '') {
        $main_file = rtrim((string)$dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name . '.php';
        if (is_file($main_file) && is_readable($main_file)) {
            $header = @file_get_contents($main_file);
            if ($header !== false && preg_match('/Version:\s*v?(\d+\.\d+\.\d+)/mi', $header, $m)) {
                return 'v' . $m[1];
            }
        }
    }

    return '—';
}

// Änderungen-Ansicht einbinden, falls gewünscht, und sofort beenden
if (isset($_GET['repo_changes']) && $_GET['repo_changes'] == 1 && isset($_GET['dir'])) {
    require_once plugin_dir_path(__FILE__) . 'repo-changes.php';
    return;
}

// Details-Ansicht einbinden, falls gewünscht, und sofort beenden
if (isset($_GET['repo_details']) && $_GET['repo_details'] == 1 && isset($_GET['dir'])) {
    require_once plugin_dir_path(__FILE__) . 'repo-details.php';
    return;
}

// Verzeichnis erfassen
if (isset($_POST['ahx_github_dir_submit'])) {
    $dir = sanitize_text_field($_POST['ahx_github_dir'] ?? '');
    if ($dir && is_dir($dir)) {
        global $wpdb;
        $table = $wpdb->prefix . 'ahx_wp_github';
        // Name des untersten Verzeichnisses ermitteln
        $name = basename(rtrim($dir, DIRECTORY_SEPARATOR));
        // Typ bestimmen: plugin, template, other
        $type = 'other';
        // Plugin: Muss eine Datei mit gleichem Namen wie das Verzeichnis und Endung .php enthalten
        $plugin_file = $dir . DIRECTORY_SEPARATOR . $name . '.php';
        if (is_file($plugin_file)) {
            $type = 'plugin';
        } else {
            // Template: style.css und index.php vorhanden
            if (is_file($dir . DIRECTORY_SEPARATOR . 'style.css') && is_file($dir . DIRECTORY_SEPARATOR . 'index.php')) {
                $type = 'template';
            }
        }
        // Prüfen, ob Verzeichnis schon existiert
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE dir_path = %s", $dir));
        if (!$exists) {
            // Prüfen, ob .git existiert, sonst initialisieren
            if (!is_dir($dir . DIRECTORY_SEPARATOR . '.git')) {
                // Git initialisieren
                ahx_wp_github_admin_run_git($dir, 'init', 20, false);
            }
            $wpdb->insert($table, ['name' => $name, 'dir_path' => $dir, 'type' => $type, 'safe_directory' => 0]);
            echo '<div class="updated"><p>Verzeichnis gespeichert und ggf. als Git-Repo initialisiert.</p></div>';
        } else {
            echo '<div class="error"><p>Verzeichnis ist bereits erfasst.</p></div>';
        }
    } else {
        echo '<div class="error"><p>Ungültiges Verzeichnis.</p></div>';
    }
}

// Handle one-click sync from list view
if (isset($_POST['ahx_repo_sync_submit']) && current_user_can('manage_options')) {
    if (!isset($_POST['ahx_repo_sync_nonce']) || !wp_verify_nonce($_POST['ahx_repo_sync_nonce'], 'ahx_repo_sync')) {
        echo '<div class="error"><p>Ungültiger Nonce.</p></div>';
    } else {
        global $wpdb;
        $table = $wpdb->prefix . 'ahx_wp_github';
        $repo_id = intval($_POST['repo_id'] ?? 0);
        $repo = $wpdb->get_row($wpdb->prepare("SELECT id, name, dir_path FROM $table WHERE id = %d", $repo_id));

        if (!$repo || !is_dir($repo->dir_path) || !is_dir($repo->dir_path . DIRECTORY_SEPARATOR . '.git')) {
            echo '<div class="error"><p>Ungültiges Repository für Sync.</p></div>';
        } else {
            $git_timeout = intval(get_option('ahx_wp_github_git_timeout_seconds', 15));
            if ($git_timeout < 5) $git_timeout = 15;
            if ($git_timeout > 120) $git_timeout = 120;

            $branch_res = ahx_wp_github_admin_run_git($repo->dir_path, 'rev-parse --abbrev-ref HEAD', $git_timeout);
            $branch = trim((string)($branch_res['output'] ?? ''));

            if (intval($branch_res['exit'] ?? 1) !== 0 || $branch === '' || $branch === 'HEAD') {
                echo '<div class="error"><p>Aktueller Branch konnte nicht bestimmt werden.</p></div>';
            } else {
                $upstream_res = ahx_wp_github_admin_run_git($repo->dir_path, 'rev-parse --abbrev-ref --symbolic-full-name @{u}', $git_timeout);
                $has_upstream = intval($upstream_res['exit'] ?? 1) === 0 && trim((string)($upstream_res['output'] ?? '')) !== '';

                if ($has_upstream) {
                    $push_res = ahx_wp_github_admin_run_git($repo->dir_path, 'push --force-with-lease', max(20, $git_timeout), true);
                } else {
                    $push_res = ahx_wp_github_admin_run_git($repo->dir_path, 'push --set-upstream origin ' . escapeshellarg($branch), max(20, $git_timeout), true);
                }

                if (intval($push_res['exit'] ?? 1) === 0) {
                    echo '<div class="updated"><p>Sync erfolgreich für Repository: ' . esc_html($repo->name) . '.</p></div>';
                } else {
                    $msg = trim((string)($push_res['output'] ?? ''));
                    if ($msg === '') $msg = 'Unbekannter Fehler beim Sync.';
                    echo '<div class="error"><p>Sync fehlgeschlagen: ' . esc_html(substr($msg, 0, 500)) . '</p></div>';
                }
            }
        }
    }
}

ahx_wp_main_display_admin_notices();
?>
<div class="wrap">
    <h1>AHX WP GitHub</h1>
    <form method="post">
        <label for="ahx_github_dir">Verzeichnis:</label>
        <input type="text" name="ahx_github_dir" id="ahx_github_dir" style="width:400px;" required />
        <button type="button" id="ahx-open-dir-picker" class="button">Auswählen…</button>
        <input type="submit" name="ahx_github_dir_submit" class="button button-primary" value="Erfassen" />
    </form>

    <div id="ahx-dir-picker-modal" style="display:none; position:fixed; inset:0; z-index:100000; background:rgba(0,0,0,0.45);">
        <div style="width:760px; max-width:95vw; max-height:85vh; overflow:hidden; margin:5vh auto; background:#fff; border-radius:6px; box-shadow:0 8px 24px rgba(0,0,0,0.2); display:flex; flex-direction:column;">
            <div style="padding:12px 16px; border-bottom:1px solid #dcdcde; display:flex; justify-content:space-between; align-items:center;">
                <strong>Verzeichnis auswählen</strong>
                <button type="button" id="ahx-dir-picker-close" class="button">Schließen</button>
            </div>
            <div style="padding:12px 16px; border-bottom:1px solid #f0f0f1;">
                <div style="margin-bottom:8px;">
                    <label for="ahx-dir-picker-path"><strong>Aktueller Pfad</strong></label>
                    <input type="text" id="ahx-dir-picker-path" style="width:100%;" readonly />
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <button type="button" id="ahx-dir-picker-up" class="button">Eine Ebene höher</button>
                    <select id="ahx-dir-picker-roots" style="min-width:220px;"></select>
                    <button type="button" id="ahx-dir-picker-open-root" class="button">Root öffnen</button>
                    <button type="button" id="ahx-dir-picker-choose" class="button button-primary">Dieses Verzeichnis übernehmen</button>
                </div>
            </div>
            <div style="padding:12px 16px; overflow:auto; flex:1;">
                <div id="ahx-dir-picker-status" style="margin-bottom:8px; color:#50575e;"></div>
                <ul id="ahx-dir-picker-list" style="margin:0; padding:0; list-style:none;"></ul>
            </div>
        </div>
    </div>

    <hr />
    <h2>Erfasste Verzeichnisse</h2>
    <table class="widefat">
        <thead>
            <tr><th>ID</th><th>Name</th><th>Typ</th><th>Version</th><th>Verzeichnis</th><th>Erfasst am</th><th>Änderungen</th></tr>
        </thead>
        <tbody>
        <?php
        global $wpdb;
        $table = $wpdb->prefix . 'ahx_wp_github';
        $rows = $wpdb->get_results("SELECT * FROM $table");
        if ($rows) {
            $display_rows = [];
            foreach ($rows as $row) {
                $details_url = admin_url('admin.php?page=ahx-wp-github&repo_details=1&dir=' . urlencode($row->dir_path));
                $changes_url = admin_url('admin.php?page=ahx-wp-github&repo_changes=1&dir=' . urlencode($row->dir_path));
                $repo_version = ahx_wp_github_admin_get_repo_version($row->dir_path, $row->name);
                $git_dir = $row->dir_path . DIRECTORY_SEPARATOR . '.git';
                $btn_changes = '';
                $is_actionable = false;
                if (is_dir($git_dir)) {
                    $git_timeout = intval(get_option('ahx_wp_github_git_timeout_seconds', 15));
                    if ($git_timeout < 5) $git_timeout = 15;
                    if ($git_timeout > 120) $git_timeout = 120;

                    $res = ahx_wp_github_admin_run_git($row->dir_path, 'status --porcelain', $git_timeout);
                    $exit_code = intval($res['exit'] ?? 1);
                    $status = trim((string)($res['output'] ?? ''));
                    $lines = $status !== '' ? array_filter(preg_split('/\r\n|\r|\n/', $status)) : [];
                    $count = count($lines);
                    $empty_dir_count = ahx_wp_github_admin_count_untracked_empty_dirs($row->dir_path, $git_timeout);
                    $total_count = $count + $empty_dir_count;
                    $sync_pending = false;

                    if ($exit_code === 0 && $total_count > 0) {
                        $is_actionable = true;
                        $btn_changes = '<a href="' . esc_url($changes_url) . '" class="button" title="Änderungsdetails anzeigen">' . $total_count . ' Änderung' . ($total_count > 1 ? 'en' : '') . '</a>';
                    } elseif ($exit_code === 0 && $total_count === 0) {
                        $sync_pending = ahx_wp_github_admin_is_sync_pending($row->dir_path, $git_timeout);
                        if ($sync_pending) {
                            $is_actionable = true;
                        }
                    }

                    if ($exit_code === 0 && $total_count === 0 && $sync_pending) {
                        $btn_changes = '<form method="post" style="display:inline; margin:0;">';
                        $btn_changes .= wp_nonce_field('ahx_repo_sync', 'ahx_repo_sync_nonce', true, false);
                        $btn_changes .= '<input type="hidden" name="repo_id" value="' . intval($row->id) . '">';
                        $btn_changes .= '<button type="submit" name="ahx_repo_sync_submit" value="1" class="button button-primary" title="Ausstehenden Sync durchführen" onclick="return confirm(\'Möchten Sie den ausstehenden Sync jetzt durchführen?\');">Sync</button>';
                        $btn_changes .= '</form>';
                    } elseif ($exit_code !== 0) {
                        $btn_changes = '<span title="Git-Status konnte nicht gelesen werden" style="color:#b32d2e;">Statusfehler</span>';
                    }
                } else {
                    $btn_changes = '';
                }

                $display_rows[] = [
                    'row' => $row,
                    'details_url' => $details_url,
                    'repo_version' => $repo_version,
                    'btn_changes' => $btn_changes,
                    'priority' => $is_actionable ? 0 : 1,
                    'sort_name' => strtolower((string)$row->name),
                ];
            }

            usort($display_rows, function($a, $b) {
                $priority_cmp = intval($a['priority']) <=> intval($b['priority']);
                if ($priority_cmp !== 0) {
                    return $priority_cmp;
                }

                $name_cmp = strcmp((string)$a['sort_name'], (string)$b['sort_name']);
                if ($name_cmp !== 0) {
                    return $name_cmp;
                }

                return intval($a['row']->id) <=> intval($b['row']->id);
            });

            foreach ($display_rows as $entry) {
                $row = $entry['row'];
                $row_style = intval($entry['priority']) === 0 ? ' style="background:#fff8e5;"' : '';
                echo '<tr' . $row_style . '>';
                echo '<td>' . esc_html($row->id) . '</td>';
                echo '<td>' . esc_html($row->name) . '</td>';
                echo '<td>' . esc_html($row->type) . '</td>';
                echo '<td>' . esc_html($entry['repo_version']) . '</td>';
                echo '<td>' . esc_html(preg_replace('/[\\\\\/]+/', DIRECTORY_SEPARATOR, $row->dir_path)) . '</td>';
                echo '<td>' . esc_html($row->created_at) . '</td>';
                echo '<td><div style="display:inline-flex;gap:5px;align-items:center">' . $entry['btn_changes'] . '<a href="' . esc_url($entry['details_url']) . '" class="button">Details</a></div></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="7">Keine Einträge gefunden.</td></tr>';
        }
        ?>
        </tbody>
    </table>

<?php
echo "</div>";

$ahx_dir_browse_nonce = wp_create_nonce('ahx_repo_browse');
?>
<script>
(function() {
    const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    const browseNonce = <?php echo wp_json_encode($ahx_dir_browse_nonce); ?>;
    const dirInput = document.getElementById('ahx_github_dir');
    const openBtn = document.getElementById('ahx-open-dir-picker');
    const modal = document.getElementById('ahx-dir-picker-modal');
    const closeBtn = document.getElementById('ahx-dir-picker-close');
    const pathField = document.getElementById('ahx-dir-picker-path');
    const upBtn = document.getElementById('ahx-dir-picker-up');
    const rootsSelect = document.getElementById('ahx-dir-picker-roots');
    const openRootBtn = document.getElementById('ahx-dir-picker-open-root');
    const chooseBtn = document.getElementById('ahx-dir-picker-choose');
    const listEl = document.getElementById('ahx-dir-picker-list');
    const statusEl = document.getElementById('ahx-dir-picker-status');

    if (!openBtn || !modal || !dirInput) {
        return;
    }

    let currentPath = '';
    let parentPath = '';
    let isLoading = false;

    function setStatus(message, isError) {
        statusEl.textContent = message || '';
        statusEl.style.color = isError ? '#b32d2e' : '#50575e';
    }

    function setLoadingState(loading) {
        isLoading = !!loading;
        openRootBtn.disabled = isLoading;
        chooseBtn.disabled = isLoading;
        upBtn.disabled = isLoading || !parentPath;
    }

    function renderRoots(roots) {
        const currentValue = rootsSelect.value;
        rootsSelect.innerHTML = '';
        (roots || []).forEach(function(rootPath) {
            const option = document.createElement('option');
            option.value = rootPath;
            option.textContent = rootPath;
            rootsSelect.appendChild(option);
        });
        if (currentValue && Array.from(rootsSelect.options).some(function(o) { return o.value === currentValue; })) {
            rootsSelect.value = currentValue;
        }
    }

    function renderList(dirs) {
        listEl.innerHTML = '';
        if (!dirs || dirs.length === 0) {
            const empty = document.createElement('li');
            empty.textContent = 'Keine Unterverzeichnisse gefunden.';
            empty.style.padding = '8px 6px';
            empty.style.color = '#646970';
            listEl.appendChild(empty);
            return;
        }

        dirs.forEach(function(dir) {
            const li = document.createElement('li');
            li.style.margin = '0';
            li.style.padding = '0';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'button-link';
            btn.textContent = '📁 ' + dir.name;
            btn.style.display = 'block';
            btn.style.padding = '6px 4px';
            btn.style.textDecoration = 'none';
            btn.style.width = '100%';
            btn.style.textAlign = 'left';
            btn.addEventListener('click', function() {
                if (!isLoading) {
                    loadPath(dir.path);
                }
            });

            li.appendChild(btn);
            listEl.appendChild(li);
        });
    }

    function loadPath(path) {
        setLoadingState(true);
        setStatus('Lade Verzeichnisse…', false);

        const formData = new URLSearchParams();
        formData.append('action', 'ahx_repo_browse_dirs');
        formData.append('nonce', browseNonce);
        formData.append('path', path || '');

        fetch(ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: formData.toString(),
            credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(payload) {
            if (!payload || !payload.success || !payload.data) {
                const msg = payload && payload.data ? String(payload.data) : 'Verzeichnisliste konnte nicht geladen werden.';
                throw new Error(msg);
            }

            const data = payload.data;
            currentPath = String(data.path || '');
            parentPath = String(data.parent_path || '');

            pathField.value = currentPath;
            renderRoots(data.roots || []);
            renderList(data.dirs || []);
            setStatus('Verzeichnis auswählen und mit „Dieses Verzeichnis übernehmen“ bestätigen.', false);
        })
        .catch(function(error) {
            setStatus(error && error.message ? error.message : 'Fehler beim Laden der Verzeichnisse.', true);
            renderList([]);
        })
        .finally(function() {
            setLoadingState(false);
        });
    }

    function openModal() {
        modal.style.display = 'block';
        const initialPath = (dirInput.value || '').trim();
        loadPath(initialPath);
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });

    upBtn.addEventListener('click', function() {
        if (!isLoading && parentPath) {
            loadPath(parentPath);
        }
    });

    openRootBtn.addEventListener('click', function() {
        if (!isLoading && rootsSelect.value) {
            loadPath(rootsSelect.value);
        }
    });

    chooseBtn.addEventListener('click', function() {
        if (!currentPath) {
            return;
        }
        dirInput.value = currentPath;
        closeModal();
    });
})();
</script>