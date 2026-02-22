<?php
// Sicherheit prüfen
if (!defined('ABSPATH')) {
    exit;
}

global $PARAMS;

// Admin-Einstellungen rendern

?>
<div class="wrap">
    <h2>AHX WP GitHub Einstellungen</h2>
    <form method="post" action="options.php">
        <?php
        settings_fields('ahx_wp_github_settings_group');
        do_settings_sections('ahx_wp_github_settings');
        submit_button();
        ?>
    </form>
</div>


