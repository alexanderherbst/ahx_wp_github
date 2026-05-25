<?php
// Sicherheit prüfen
if (!defined('ABSPATH')) {
    exit;
}
if (!current_user_can('manage_options')) {
    wp_die(__('Keine Berechtigung.'));
}

$back_url = admin_url('admin.php?page=ahx-wp-github');
?>
<div class="wrap">
    <h1>AHX WP GitHub – Einstellungen</h1>
    <p><a href="<?php echo esc_url($back_url); ?>">&larr; Zurück zur Übersicht</a></p>
    <form method="post" action="options.php">
        <?php
        settings_fields('ahx_wp_github_settings_group');
        do_settings_sections('ahx_wp_github_settings');
        submit_button('Einstellungen speichern');
        ?>
    </form>
</div>

