<?php
// Sicherheit prüfen
if (!defined('ABSPATH')) {
    exit;
}

// Einstellungen registrieren
function ahx_wp_github_register_settings() {

    AHX_Logging::get_instance()->log_debug('Aufruf: ' . __METHOD__ . '(' . implode(', ', array_map(fn($a) => preg_replace('/\s+/', ' ', trim(var_export($a, true))), func_get_args())) . ')', 'ahx_wp_github');

    add_settings_section('ahx_wp_github_logging', 'Logging', null, 'ahx_wp_github_settings');
    
    add_settings_field( 'ahx_wp_github_level_of_logging', 'Log-Level', 'ahx_wp_github_level_of_logging_select', 'ahx_wp_github_settings', 'ahx_wp_github_logging');
    register_setting('ahx_wp_github_settings_group', 'ahx_wp_github_level_of_logging');

}
add_action('admin_init', 'ahx_wp_github_register_settings');

function ahx_wp_github_level_of_logging_select() {
    AHX_Logging::get_instance()->log_debug('Aufruf: ' . __METHOD__ . '(' . implode(', ', array_map(fn($a) => preg_replace('/\s+/', ' ', trim(var_export($a, true))), func_get_args())) . ')', 'ahx_wp_github');
    echo AHX_Logging::get_instance()->build_config_select('ahx_wp_github_level_of_logging');
    echo '<p class="description">Wählen Sie das Log-Level aus, das für die Protokollierung dieses Plugins verwendet werden soll.</p>';
}

?>
