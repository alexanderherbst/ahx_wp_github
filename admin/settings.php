<?php
// Sicherheit prüfen
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ahx_wp_github_safe_log')) {
    require_once dirname(__DIR__) . '/includes/logging.php';
}

// Einstellungen registrieren
function ahx_wp_github_register_settings() {

    ahx_wp_github_safe_log('DEBUG', 'Aufruf: ' . __METHOD__ . '(' . implode(', ', array_map(fn($a) => preg_replace('/\s+/', ' ', trim(var_export($a, true))), func_get_args())) . ')');

    add_settings_section('ahx_wp_github_logging', 'Logging', null, 'ahx_wp_github_settings');
    
    add_settings_field( 'ahx_wp_github_level_of_logging', 'Log-Level', 'ahx_wp_github_level_of_logging_select', 'ahx_wp_github_settings', 'ahx_wp_github_logging');
    register_setting('ahx_wp_github_settings_group', 'ahx_wp_github_level_of_logging');
    
    // Push preferences
    add_settings_field('ahx_wp_github_prefer_api', 'Push via GitHub API bevorzugen', 'ahx_wp_github_prefer_api_checkbox', 'ahx_wp_github_settings', 'ahx_wp_github_logging');
    register_setting('ahx_wp_github_settings_group', 'ahx_wp_github_prefer_api');

    // Git command timeout for diagnostics and guarded git calls
    add_settings_field('ahx_wp_github_git_timeout_seconds', 'Git Timeout (Sekunden)', 'ahx_wp_github_git_timeout_seconds_input', 'ahx_wp_github_settings', 'ahx_wp_github_logging');
    register_setting('ahx_wp_github_settings_group', 'ahx_wp_github_git_timeout_seconds', [
        'type' => 'integer',
        'sanitize_callback' => 'ahx_wp_github_sanitize_git_timeout_seconds',
        'default' => 15,
    ]);

    // Remote URL policy
    add_settings_field('ahx_wp_github_remote_policy', 'Erlaubte Remote-URLs', 'ahx_wp_github_remote_policy_select', 'ahx_wp_github_settings', 'ahx_wp_github_logging');
    register_setting('ahx_wp_github_settings_group', 'ahx_wp_github_remote_policy', [
        'type' => 'string',
        'sanitize_callback' => 'ahx_wp_github_sanitize_remote_policy',
        'default' => 'all',
    ]);

}
add_action('admin_init', 'ahx_wp_github_register_settings');

function ahx_wp_github_level_of_logging_select() {
    ahx_wp_github_safe_log('DEBUG', 'Aufruf: ' . __METHOD__ . '(' . implode(', ', array_map(fn($a) => preg_replace('/\s+/', ' ', trim(var_export($a, true))), func_get_args())) . ')');
    echo AHX_Logging::get_instance()->build_config_select('ahx_wp_github_level_of_logging');
    echo '<p class="description">Wählen Sie das Log-Level aus, das für die Protokollierung dieses Plugins verwendet werden soll.</p>';
}

function ahx_wp_github_prefer_api_checkbox() {
    $val = get_option('ahx_wp_github_prefer_api', '1');
    $checked = ($val === '1' || $val === 1 || $val === true) ? 'checked' : '';
    echo '<input type="checkbox" name="ahx_wp_github_prefer_api" value="1" ' . $checked . '> Push via GitHub API (wenn Token vorhanden)';
    echo '<p class="description">Wenn aktiviert, versucht das Plugin bevorzugt die GitHub API mit dem in AHX Main gespeicherten Token zu verwenden. Fallback auf lokales <code>git push</code> nur, wenn API nicht möglich.</p>';
}

function ahx_wp_github_sanitize_git_timeout_seconds($value) {
    $seconds = intval($value);
    if ($seconds < 5) $seconds = 5;
    if ($seconds > 120) $seconds = 120;
    return $seconds;
}

function ahx_wp_github_sanitize_remote_policy($value) {
    $value = sanitize_key((string)$value);
    if ($value !== 'github_only' && $value !== 'all') {
        return 'all';
    }
    return $value;
}

function ahx_wp_github_git_timeout_seconds_input() {
    $val = intval(get_option('ahx_wp_github_git_timeout_seconds', 15));
    if ($val < 5) $val = 15;
    echo '<input type="number" min="5" max="120" step="1" name="ahx_wp_github_git_timeout_seconds" value="' . esc_attr((string) $val) . '" style="width:100px;">';
    echo '<p class="description">Timeout für Git-Befehle in Sekunden (Standard: 15). Git-Prozesse werden nach Ablauf automatisch beendet.</p>';
}

function ahx_wp_github_remote_policy_select() {
    $policy = (string)get_option('ahx_wp_github_remote_policy', 'all');
    if ($policy !== 'github_only' && $policy !== 'all') {
        $policy = 'all';
    }

    echo '<select name="ahx_wp_github_remote_policy">';
    echo '<option value="all"' . selected($policy, 'all', false) . '>Generisch (alle Git-Remotes)</option>';
    echo '<option value="github_only"' . selected($policy, 'github_only', false) . '>Nur github.com-Remotes</option>';
    echo '</select>';
    echo '<p class="description">Steuert, welche Remote-URLs beim Setzen von <code>origin</code> in den Repository-Details akzeptiert werden.</p>';
}

?>
