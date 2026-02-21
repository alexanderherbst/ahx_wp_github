
<?php
if (!current_user_can('manage_options')) {
    wp_die(__('Keine Berechtigung.'));
}

// Änderungen-Ansicht einbinden, falls gewünscht, und sofort beenden
if (isset($_GET['repo_changes']) && $_GET['repo_changes'] == 1 && isset($_GET['dir'])) {
    require_once plugin_dir_path(__FILE__) . 'repo-changes.php';
    return;
}

// Details-Ansicht einbinden, falls gewünscht, und sofort beenden (frühzeitig,
// bevor Seiteninhalt ausgegeben wird)
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
                $cmd = 'git init "' . $dir . '"';
                exec($cmd);
            }
            $wpdb->insert($table, ['name' => $name, 'dir_path' => $dir, 'type' => $type]);
            echo '<div class="updated"><p>Verzeichnis gespeichert und ggf. als Git-Repo initialisiert.</p></div>';
        } else {
            echo '<div class="error"><p>Verzeichnis ist bereits erfasst.</p></div>';
        }
    } else {
        echo '<div class="error"><p>Ungültiges Verzeichnis.</p></div>';
    }
}
?>
<div class="wrap">
    <h1>AHX WP GitHub</h1>
    <form method="post">
        <label for="ahx_github_dir">Verzeichnis:</label>
        <input type="text" name="ahx_github_dir" id="ahx_github_dir" style="width:400px;" required />
        <input type="submit" name="ahx_github_dir_submit" class="button button-primary" value="Erfassen" />
    </form>
    <hr />
    <h2>Erfasste Verzeichnisse</h2>
    <table class="widefat">
        <thead>
            <tr><th>ID</th><th>Name</th><th>Typ</th><th>Remote / Verzeichnis</th><th>Erfasst am</th><th>Änderungen</th><th></th></tr>
        </thead>
        <tbody>
        <?php
        global $wpdb;
        $table = $wpdb->prefix . 'ahx_wp_github';
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
        if ($rows) {
            foreach ($rows as $row) {
                $details_url = admin_url('admin.php?page=ahx-wp-github&repo_details=1&dir=' . urlencode($row->dir_path));
                $changes_url = admin_url('admin.php?page=ahx-wp-github&repo_changes=1&dir=' . urlencode($row->dir_path));
                $git_dir = $row->dir_path . DIRECTORY_SEPARATOR . '.git';
                $btn_changes = '';
                $remote_display = '';
                if (is_dir($git_dir)) {
                    $status = shell_exec('cd "' . $row->dir_path . '" && git status --porcelain');
                    $lines = array_filter(explode("\n", (string) $status));
                    $count = count($lines);
                    if ($count > 0) {
                        $btn_changes = '<a href="' . esc_url($changes_url) . '" class="button" title="Änderungsdetails anzeigen">' . $count . ' Änderung' . ($count > 1 ? 'en' : '') . '</a>';
                    }

                    // detect remotes (git remote -v) and collect unique URLs
                    $remotes_raw = trim((string) shell_exec('cd "' . $row->dir_path . '" && git remote -v 2>&1'));
                    if ($remotes_raw !== '') {
                        $urls = [];
                        $rlines = preg_split('/\r?\n/', $remotes_raw);
                        foreach ($rlines as $rline) {
                            if (preg_match('/^\S+\s+(\S+)\s+\((fetch|push)\)/', $rline, $m)) {
                                $urls[$m[1]] = true;
                            }
                        }
                        if (!empty($urls)) {
                            $uarr = array_keys($urls);
                            // show full URL(s), each on its own line
                            $lines_out = [];
                            foreach ($uarr as $uu) { $lines_out[] = esc_html($uu); }
                            $remote_display = '<div style="font-size:12px;color:#333;">' . implode('<br>', $lines_out) . '</div>';
                        }
                    }
                } else {
                    $btn_changes = '';
                }
                echo '<tr>';
                echo '<td>' . esc_html($row->id) . '</td>';
                echo '<td>' . esc_html($row->name) . '</td>';
                echo '<td>' . esc_html($row->type) . '</td>';
                echo '<td>' . ($remote_display !== '' ? $remote_display : '-') . '<div style="color:#666;font-size:12px;margin-top:4px;">' . esc_html(preg_replace('/[\\\\\/]+/', DIRECTORY_SEPARATOR, $row->dir_path)) . '</div></td>';
                echo '<td>' . esc_html($row->created_at) . '</td>';
                echo '<td><div style="display:inline-flex;gap:5px;align-items:center">' . $btn_changes . '<a href="' . esc_url($details_url) . '" class="button">Details</a></div></td>';
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