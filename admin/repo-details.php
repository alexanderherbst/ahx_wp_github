<?php
if (!current_user_can('manage_options')) {
    wp_die(__('Keine Berechtigung.'));
}

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
    $remote = trim(shell_exec('cd "' . $dir . '" && git remote -v'));
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
    <p><a href="admin.php?page=ahx-wp-github" class="button">Zurück</a></p>
</div>
