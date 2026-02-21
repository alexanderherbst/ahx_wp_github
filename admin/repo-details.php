<?php
if (!current_user_can('manage_options')) {
    wp_die(__('Keine Berechtigung.'));
}

// Load logging helper (if available) for secure logging of git output
if (!class_exists('AHX_Logging') && file_exists(WP_CONTENT_DIR . '/plugins/ahx_wp_main/helper/logging.php')) {
    require_once WP_CONTENT_DIR . '/plugins/ahx_wp_main/helper/logging.php';
}
// instantiate logger if available
$logger = class_exists('AHX_Logging') ? AHX_Logging::get_instance() : null;

$dir = isset($_GET['dir']) ? $_GET['dir'] : '';
if (!$dir || !is_dir($dir)) {
    echo '<div class="error"><p>Ungültiges oder nicht vorhandenes Verzeichnis.</p></div>';
    return;
}

// Git-Infos auslesen
$git_dir = $dir . DIRECTORY_SEPARATOR . '.git';
$info = [];
if (is_dir($git_dir)) {
    // Aktueller Branch
    $branch = trim(shell_exec('cd "' . $dir . '" && git rev-parse --abbrev-ref HEAD'));
    $info['Branch'] = $branch;
    // Letzter Commit
    $commit = trim(shell_exec('cd "' . $dir . '" && git log -1 --pretty=format:"%h %s (%ci)"'));
    $info['Letzter Commit'] = $commit;
    // Remote
    $remote = trim(shell_exec('cd "' . $dir . '" && git remote -v 2>&1'));
    // Allow adding a remote URL when none exists; support remote name and optional push
    $add_remote_message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_remote'])) {
        $new_remote = trim(sanitize_text_field($_POST['remote_url'] ?? ''));
        $new_name = trim(sanitize_text_field($_POST['remote_name'] ?? 'origin'));
        $push_after = isset($_POST['push_after']) && $_POST['push_after'] === '1';

        // Validate remote name (simple safe chars)
        if ($new_name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $new_name)) {
            $add_remote_message = 'Ungültiger Remote-Name. Nur Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich erlaubt.';
        }
        // Validate URL (https, ssh scp-style, ssh://, git://)
        elseif ($new_remote === '' || !preg_match('/^(https?:\/\/\S+|git@[^:\s]+:[^\s]+|ssh:\/\/\S+|git:\/\/\S+)$/i', $new_remote)) {
            $add_remote_message = 'Ungültige Remote-URL. Erwartet z. B. https://... oder git@host:owner/repo.git oder ssh://...';
        } else {
            // Check if remote name already exists
            $existing = trim(shell_exec('cd "' . $dir . '" && git remote 2>&1'));
            $exists_arr = preg_split('/\s+/', $existing, -1, PREG_SPLIT_NO_EMPTY);
            if (in_array($new_name, $exists_arr, true)) {
                $add_remote_message = 'Ein Remote mit dem Namen "' . esc_html($new_name) . '" existiert bereits.';
            } else {
                // Add remote
                $out = trim(shell_exec('cd "' . $dir . '" && git remote add ' . escapeshellarg($new_name) . ' ' . escapeshellarg($new_remote) . ' 2>&1'));
                // refresh remote list
                $remote = trim(shell_exec('cd "' . $dir . '" && git remote -v 2>&1'));
                if ($remote !== '') {
                    $add_remote_message = 'Remote erfolgreich hinzugefügt: ' . esc_html($new_name);
                    // log detailed add output securely
                    if ($logger) $logger->log_info('git remote add output for ' . $dir . ': ' . $out, 'ahx_wp_github');
                    // Optionally push
                    if ($push_after) {
                        $branch = trim(shell_exec('cd "' . $dir . '" && git rev-parse --abbrev-ref HEAD 2>&1'));
                        if ($branch === '' || strpos($branch, 'fatal:') === 0) {
                            $add_remote_message .= ' — Push fehlgeschlagen: aktueller Branch konnte nicht ermittelt werden.';
                            if ($logger) $logger->log_error('Push failed (no branch) for ' . $dir, 'ahx_wp_github');
                        } else {
                            $push_out = trim(shell_exec('cd "' . $dir . '" && git push -u ' . escapeshellarg($new_name) . ' ' . escapeshellarg($branch) . ' 2>&1'));
                            // Log full push output securely
                            if ($logger) $logger->log_info('git push output for ' . $dir . ' to ' . $new_name . '/' . $branch . ': ' . $push_out, 'ahx_wp_github');
                            if (stripos($push_out, 'error') !== false || stripos($push_out, 'fatal') !== false) {
                                $add_remote_message .= ' — Push fehlgeschlagen (siehe Log).';
                            } else {
                                $add_remote_message .= ' — Push erfolgreich (Branch: ' . esc_html($branch) . ').';
                            }
                        }
                    }
                } else {
                    $add_remote_message = 'Fehler beim Hinzufügen der Remote (Details im Log).';
                    if ($logger) $logger->log_error('git remote add failed for ' . $dir . ': ' . $out, 'ahx_wp_github');
                }
            }
        }
    }

    $info['Remote'] = $remote ?: 'Kein Remote hinterlegt';
} else {
    $info['Fehler'] = 'Kein Git-Repository gefunden.';
}
?>
<div class="wrap">
    <h1>Repository-Details</h1>
    <p><strong>Verzeichnis:</strong> <?php echo preg_replace('/[\\\\\/]+/', '/', $dir); ?></p>
    <table class="widefat">
        <tbody>
        <?php foreach ($info as $k => $v) {
            echo '<tr><th>' . esc_html($k) . '</th><td><pre>' . esc_html($v) . '</pre></td></tr>';
        } ?>
        </tbody>
    </table>

    <?php if (trim($remote) === ''): ?>
        <?php if ($add_remote_message !== ''): ?>
            <div class="notice notice-success"><p><?php echo esc_html($add_remote_message); ?></p></div>
        <?php endif; ?>
        <h2>Remote hinzufügen</h2>
        <form method="post" style="max-width:600px;">
            <label for="remote_name">Remote-Name:</label><br>
            <input type="text" id="remote_name" name="remote_name" style="width:200px;" value="origin" required />
            <br><label for="remote_url">Remote-URL:</label><br>
            <input type="text" id="remote_url" name="remote_url" style="width:100%;" required />
            <p style="margin-top:8px;"><label><input type="checkbox" name="push_after" value="1"> Nach Hinzufügen sofort pushen (git push -u)</label></p>
            <p style="margin-top:8px;"><button type="submit" name="add_remote" class="button button-primary">Remote hinzufügen</button></p>
        </form>
    <?php endif; ?>
    <p><a href="admin.php?page=ahx-wp-github" class="button">Zurück</a></p>
</div>
