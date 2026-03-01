<?php
if (!current_user_can('manage_options')) {
    wp_die(__('Keine Berechtigung.'));
}

$dir = isset($_GET['dir']) ? sanitize_text_field(wp_unslash($_GET['dir'])) : '';
if (!$dir || !is_dir($dir)) {
    echo '<div class="error"><p>Ungültiges oder nicht vorhandenes Verzeichnis.</p></div>';
    return;
}

global $wpdb;
$table = $wpdb->prefix . 'ahx_wp_github';

// Spalte sicherstellen (für ältere Installationen)
$columns_safe = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'safe_directory'");
if (empty($columns_safe)) {
    $wpdb->query("ALTER TABLE $table ADD COLUMN safe_directory tinyint(1) NOT NULL DEFAULT 0 AFTER type");
}

$repo_row = $wpdb->get_row($wpdb->prepare("SELECT id, name, dir_path, safe_directory FROM $table WHERE dir_path = %s", $dir));

if (!$repo_row) {
    $normalize_path = static function($path) {
        $path = preg_replace('/[\\\/]+/', '/', (string)$path);
        return rtrim($path, '/');
    };
    $dir_norm = $normalize_path($dir);
    $all_rows = $wpdb->get_results("SELECT id, name, dir_path, safe_directory FROM $table");
    if (is_array($all_rows)) {
        foreach ($all_rows as $candidate) {
            if ($normalize_path($candidate->dir_path ?? '') === $dir_norm) {
                $repo_row = $candidate;
                break;
            }
        }
    }
}

$git_dir = $dir . DIRECTORY_SEPARATOR . '.git';
$has_git_repo = is_dir($git_dir);
$git_bin = '';
if ($has_git_repo) {
    if (!function_exists('ahx_run_git_cmd') || !function_exists('ahx_find_git_binary')) {
        require_once plugin_dir_path(__FILE__) . 'commit-handler.php';
    }
    $git_bin = ahx_find_git_binary();
}

if (isset($_POST['ahx_repo_settings_submit']) && current_user_can('manage_options')) {
    if (!isset($_POST['ahx_repo_settings_nonce']) || !wp_verify_nonce($_POST['ahx_repo_settings_nonce'], 'ahx_repo_settings')) {
        ahx_wp_main_add_notice('Ungültiger Nonce.', 'error');
    } elseif (!$repo_row) {
        ahx_wp_main_add_notice('Repository-Eintrag nicht gefunden.', 'error');
    } else {
        $saved_any = false;
        $reset_identity = isset($_POST['ahx_git_identity_reset']) && $_POST['ahx_git_identity_reset'] === '1';

        $safe = isset($_POST['safe_directory']) ? 1 : 0;
        $wpdb->update($table, ['safe_directory' => $safe], ['id' => intval($repo_row->id)]);
        $saved_any = true;

        if ($has_git_repo && $git_bin !== '') {
            if ($reset_identity) {
                $unset_name_res = ahx_run_git_cmd($git_bin, $dir, 'config --unset-all user.name', 20, false);
                $unset_email_res = ahx_run_git_cmd($git_bin, $dir, 'config --unset-all user.email', 20, false);

                $name_ok = intval($unset_name_res['exit'] ?? 1) === 0 || stripos((string)($unset_name_res['output'] ?? ''), 'No such') !== false;
                $email_ok = intval($unset_email_res['exit'] ?? 1) === 0 || stripos((string)($unset_email_res['output'] ?? ''), 'No such') !== false;

                if ($name_ok && $email_ok) {
                    $saved_any = true;
                    ahx_wp_main_add_notice('git user.name und git user.email wurden zurückgesetzt.', 'success');
                } else {
                    if (!$name_ok) {
                        $msg = trim((string)($unset_name_res['output'] ?? ''));
                        ahx_wp_main_add_notice('git user.name konnte nicht zurückgesetzt werden: ' . ($msg !== '' ? mb_substr($msg, 0, 300) : 'Unbekannter Fehler'), 'error');
                    }
                    if (!$email_ok) {
                        $msg = trim((string)($unset_email_res['output'] ?? ''));
                        ahx_wp_main_add_notice('git user.email konnte nicht zurückgesetzt werden: ' . ($msg !== '' ? mb_substr($msg, 0, 300) : 'Unbekannter Fehler'), 'error');
                    }
                }
            } else {
                $git_user_name = sanitize_text_field(wp_unslash($_POST['git_user_name'] ?? ''));
                $git_user_email = sanitize_email(wp_unslash($_POST['git_user_email'] ?? ''));

                if ($git_user_name !== '') {
                    $set_name_res = ahx_run_git_cmd($git_bin, $dir, 'config user.name ' . escapeshellarg($git_user_name), 20, false);
                    if (intval($set_name_res['exit'] ?? 1) !== 0) {
                        $msg = trim((string)($set_name_res['output'] ?? ''));
                        ahx_wp_main_add_notice('git user.name konnte nicht gesetzt werden: ' . ($msg !== '' ? mb_substr($msg, 0, 300) : 'Unbekannter Fehler'), 'error');
                    } else {
                        $saved_any = true;
                    }
                }

                if ($git_user_email !== '') {
                    if (!is_email($git_user_email)) {
                        ahx_wp_main_add_notice('git user.email ist ungültig.', 'error');
                    } else {
                        $set_email_res = ahx_run_git_cmd($git_bin, $dir, 'config user.email ' . escapeshellarg($git_user_email), 20, false);
                        if (intval($set_email_res['exit'] ?? 1) !== 0) {
                            $msg = trim((string)($set_email_res['output'] ?? ''));
                            ahx_wp_main_add_notice('git user.email konnte nicht gesetzt werden: ' . ($msg !== '' ? mb_substr($msg, 0, 300) : 'Unbekannter Fehler'), 'error');
                        } else {
                            $saved_any = true;
                        }
                    }
                }
            }
        } elseif (isset($_POST['git_user_name']) || isset($_POST['git_user_email'])) {
            ahx_wp_main_add_notice('Kein Git-Repository gefunden; git user.name/user.email konnten nicht gespeichert werden.', 'error');
        }

        if ($saved_any) {
            ahx_wp_main_add_notice('Einstellungen gespeichert.', 'success');
        }
    }

    $redirect_url = admin_url('admin.php?page=ahx-wp-github&repo_details=1&dir=' . urlencode($dir));
    if (!headers_sent()) {
        wp_safe_redirect($redirect_url);
        exit;
    }
    echo '<script>window.location.href = ' . json_encode($redirect_url) . ';</script>';
    exit;
}

ahx_wp_main_display_admin_notices();

// Git-Infos auslesen
$info = [];
$git_user_name_current = '';
$git_user_email_current = '';
if ($has_git_repo) {

    // Aktueller Branch
    $branch_res = ahx_run_git_cmd($git_bin, $dir, 'rev-parse --abbrev-ref HEAD', 20, false);
    $branch = trim((string)($branch_res['output'] ?? ''));
    $info['Branch'] = $branch;
    // Letzter Commit
    $commit_res = ahx_run_git_cmd($git_bin, $dir, 'log -1 --pretty=format:"%h %s (%ci)"', 20, false);
    $commit = trim((string)($commit_res['output'] ?? ''));
    $info['Letzter Commit'] = $commit;
    // Remote
    $remote_res = ahx_run_git_cmd($git_bin, $dir, 'remote -v', 20, false);
    $remote = trim((string)($remote_res['output'] ?? ''));
    $info['Remote'] = $remote ?: 'Kein Remote hinterlegt';

    $user_name_res = ahx_run_git_cmd($git_bin, $dir, 'config --get user.name', 20, false);
    $git_user_name_current = trim((string)($user_name_res['output'] ?? ''));
    $user_email_res = ahx_run_git_cmd($git_bin, $dir, 'config --get user.email', 20, false);
    $git_user_email_current = trim((string)($user_email_res['output'] ?? ''));
} else {
    $info['Fehler'] = 'Kein Git-Repository gefunden.';
}

$back_url = admin_url('admin.php?page=ahx-wp-github');
?>
<div class="wrap">
    <h1>Repository-Details</h1>
    <p><strong>Verzeichnis:</strong> <?php echo preg_replace('/[\\\\\/]+/', '/', $dir); ?></p>

    <?php if ($repo_row): ?>
        <h2>Einstellungen</h2>
        <form method="post" style="margin:12px 0 18px 0;">
            <?php wp_nonce_field('ahx_repo_settings','ahx_repo_settings_nonce', true, true); ?>
            <input type="hidden" name="ahx_repo_settings_submit" value="1">
            <p>
                <label style="display:inline-flex;align-items:center;gap:6px">
                <input type="checkbox" name="safe_directory" value="1"<?php echo intval($repo_row->safe_directory) ? ' checked' : ''; ?>>
                Safe Directory automatisch setzen (git config --global --add safe.directory)
                </label>
            </p>
            <table class="form-table" role="presentation" style="max-width:700px;">
                <tr>
                    <th scope="row"><label for="git_user_name">git user.name</label></th>
                    <td>
                        <input type="text" id="git_user_name" name="git_user_name" class="regular-text" value="<?php echo esc_attr($git_user_name_current); ?>" <?php echo $has_git_repo ? '' : 'disabled'; ?> />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="git_user_email">git user.email</label></th>
                    <td>
                        <input type="email" id="git_user_email" name="git_user_email" class="regular-text" value="<?php echo esc_attr($git_user_email_current); ?>" <?php echo $has_git_repo ? '' : 'disabled'; ?> />
                    </td>
                </tr>
            </table>
            <?php if (!$has_git_repo): ?>
                <p><em>Git-Benutzerdaten können erst gesetzt werden, wenn ein Git-Repository vorhanden ist.</em></p>
            <?php endif; ?>
            <p>
                <button type="submit" class="button button-primary">Speichern</button>
                <button type="submit" name="ahx_git_identity_reset" value="1" class="button" <?php echo $has_git_repo ? '' : 'disabled'; ?> style="margin-left:8px;" onclick="return confirm('Möchten Sie git user.name und git user.email für dieses Repository wirklich zurücksetzen?');">user.name / user.email zurücksetzen</button>
            </p>
        </form>
    <?php endif; ?>

    <table class="widefat">
        <tbody>
        <?php foreach ($info as $k => $v) {
            echo '<tr><th>' . esc_html($k) . '</th><td><pre>' . esc_html($v) . '</pre></td></tr>';
        } ?>
        </tbody>
    </table>
    <p><a href="<?php echo esc_url($back_url); ?>" class="button">Zurück</a></p>
</div>
