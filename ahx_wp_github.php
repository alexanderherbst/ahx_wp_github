<?php
/*
Plugin Name: AHX WP GitHub
Description: Plugin zum Erfassen von Verzeichnissen, Initialisieren als GitHub-Repository und Listen der Einträge.
Version: v1.11.1
Author: AHX
Email: ahx@familie-herbst.net
*/

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/logging.php';

if (class_exists('AHX_Logging') && method_exists('AHX_Logging', 'get_instance')) {
    $github_log_level = get_option('ahx_wp_github_level_of_logging', get_option('ahx_wp_main_level_of_logging_overall', 'WARNING'));
    AHX_Logging::get_instance()->set_log_level('ahx_wp_github', $github_log_level);
}

// Plugin Aktivierung: Datenbanktabelle anlegen

register_activation_hook(__FILE__, 'ahx_wp_github_install');
function ahx_wp_github_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ahx_wp_github';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        dir_path text NOT NULL,
        type varchar(20) NOT NULL DEFAULT 'other',
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Prüfen, ob die Spalte 'name' existiert, sonst hinzufügen
    $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'name'");
    if (empty($columns)) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN name varchar(255) NOT NULL AFTER id");
    }
    // Prüfen, ob die Spalte 'type' existiert, sonst hinzufügen
    $columns_type = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'type'");
    if (empty($columns_type)) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN type varchar(20) NOT NULL DEFAULT 'other' AFTER dir_path");
    }
}

// Admin-Menü hinzufügen
add_action('admin_menu', 'ahx_wp_github_admin_menu');
function ahx_wp_github_admin_menu() {
    global $wpdb;
    $table = $wpdb->prefix . 'ahx_wp_github';
    // Prüfen, ob die Spalte 'safe_directory' existiert, sonst hinzufügen
    $columns_safe = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'safe_directory'");
    if (empty($columns_safe)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN safe_directory tinyint(1) NOT NULL DEFAULT 0 AFTER type");
    }
    // Top-level menu placed directly after AHX Main (AHX Main uses position 2)
    add_menu_page(
        'AHX WP GitHub',            // page title
        'AHX WP GitHub',            // menu title
        'manage_options',           // capability
        'ahx-wp-github',            // menu slug
        'ahx_wp_github_admin_page', // callback
        'dashicons-admin-site',     // icon
        3                           // position directly after AHX Main (2)
    );

    // Submenu: Overview (links back to the top-level page)
    add_submenu_page(
        'ahx-wp-github',
        'AHX WP GitHub',
        'Übersicht',
        'manage_options',
        'ahx-wp-github',
        'ahx_wp_github_admin_page'
    );

    // Submenu: Settings
    add_submenu_page(
        'ahx-wp-github',
        'AHX WP GitHub Einstellungen',
        'Einstellungen',
        'manage_options',
        'ahx-wp-github-config',
        'ahx_wp_github_settings_page'
    );

    // Submenu: Diagnose
    add_submenu_page(
        'ahx-wp-github',
        'AHX WP GitHub Diagnose',
        'Diagnose',
        'manage_options',
        'ahx-wp-github-diagnostics',
        'ahx_wp_github_diagnostics_page'
    );

    // Submenu: Workflow-Assistent
    add_submenu_page(
        'ahx-wp-github',
        'AHX WP GitHub Workflow-Assistent',
        'Workflow-Assistent',
        'manage_options',
        'ahx-wp-github-workflow-wizard',
        'ahx_wp_github_workflow_wizard_page'
    );

}

// Ensure settings are registered during admin_init (needed for options.php save requests)
require_once plugin_dir_path(__FILE__) . 'admin/settings.php';

// Admin-Seite anzeigen
function ahx_wp_github_admin_page() {
    require_once plugin_dir_path(__FILE__) . 'admin/admin-page.php';
}
function ahx_wp_github_settings_page() {
    require_once plugin_dir_path(__FILE__) . 'admin/config-page.php';
}
function ahx_wp_github_diagnostics_page() {
    require_once plugin_dir_path(__FILE__) . 'admin/diagnostics-page.php';
}
function ahx_wp_github_workflow_wizard_page() {
    require_once plugin_dir_path(__FILE__) . 'admin/workflow-wizard-page.php';
}

add_action('wp_ajax_ahx_repo_commit', 'ahx_wp_github_ajax_commit');
function ahx_wp_github_ajax_commit() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung');
    }
    $request_dir_raw = wp_unslash($_POST['dir'] ?? '');
    ahx_wp_github_safe_log('DEBUG', 'ajax_commit: incoming request dir=' . var_export($request_dir_raw, true));
    $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
    if (!wp_verify_nonce($nonce, 'ahx_repo_commit')) {
        wp_send_json_error('Ungültiger Nonce');
    }

    $dir = ahx_wp_github_normalize_dir_path(sanitize_text_field((string)$request_dir_raw));
    if (!$dir || !is_dir($dir)) {
        wp_send_json_error('Ungültiges Verzeichnis');
    }
    // Use internal shared handler to perform commit/push without remote HTTP request
    require_once plugin_dir_path(__FILE__) . 'admin/commit-handler.php';
    $post_body = [
        'commit_action' => sanitize_text_field(wp_unslash($_POST['commit_action'] ?? 'commit_sync')),
        'commit_message' => sanitize_textarea_field(wp_unslash($_POST['commit_message'] ?? '')),
        'version_bump' => sanitize_key(wp_unslash($_POST['version_bump'] ?? 'none')),
        'allow_force_with_lease_on_rebase_conflict' => sanitize_text_field(wp_unslash($_POST['allow_force_with_lease_on_rebase_conflict'] ?? '')),
        'ajax' => '1'
    ];
    $res = ahx_wp_github_process_commit_request($dir, $post_body);
    if (empty($res)) wp_send_json_error('Handler returned no response');
    wp_send_json_success($res);
}

function ahx_wp_github_normalize_dir_path($path) {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }

    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (preg_match('/^[A-Za-z]:' . preg_quote(DIRECTORY_SEPARATOR, '/') . '$/', $path)) {
        return strtoupper(substr($path, 0, 1)) . ':' . DIRECTORY_SEPARATOR;
    }

    $normalized = rtrim($path, "\\/");
    if (preg_match('/^[A-Za-z]:$/', $normalized)) {
        $normalized .= DIRECTORY_SEPARATOR;
    }

    return $normalized;
}

function ahx_wp_github_get_browse_roots() {
    $roots = [];

    if (preg_match('/^WIN/i', PHP_OS)) {
        foreach (range('A', 'Z') as $drive) {
            $candidate = $drive . ':\\';
            if (is_dir($candidate)) {
                $roots[] = $candidate;
            }
        }
    } else {
        $roots[] = DIRECTORY_SEPARATOR;
    }

    if (defined('ABSPATH')) {
        $roots[] = ABSPATH;
    }
    if (defined('WP_CONTENT_DIR')) {
        $roots[] = WP_CONTENT_DIR;
    }

    $normalized = [];
    foreach ($roots as $root) {
        $clean = ahx_wp_github_normalize_dir_path($root);
        if ($clean !== '' && is_dir($clean)) {
            $normalized[] = $clean;
        }
    }

    $normalized = array_values(array_unique($normalized));
    sort($normalized, SORT_NATURAL | SORT_FLAG_CASE);

    return $normalized;
}

function ahx_wp_github_get_git_timeout() {
    $git_timeout = intval(get_option('ahx_wp_github_git_timeout_seconds', 15));
    if ($git_timeout < 5) {
        $git_timeout = 15;
    }
    if ($git_timeout > 120) {
        $git_timeout = 120;
    }

    return $git_timeout;
}

function ahx_wp_github_run_git($dir, $args, $timeout = 20, $needs_remote_auth = false) {
    if (!function_exists('ahx_run_git_cmd') || !function_exists('ahx_find_git_binary')) {
        require_once plugin_dir_path(__FILE__) . 'admin/commit-handler.php';
    }

    return ahx_run_git_cmd(ahx_find_git_binary(), $dir, $args, $timeout, $needs_remote_auth);
}

function ahx_wp_github_is_sync_pending($dir, $git_timeout = 15) {
    $branch_res = ahx_wp_github_run_git($dir, 'rev-parse --abbrev-ref HEAD', $git_timeout);
    if (intval($branch_res['exit'] ?? 1) !== 0) {
        return false;
    }

    $branch = trim((string)($branch_res['output'] ?? ''));
    if ($branch === '' || $branch === 'HEAD') {
        return false;
    }

    $upstream_res = ahx_wp_github_run_git($dir, 'rev-parse --abbrev-ref --symbolic-full-name @{u}', $git_timeout);
    if (intval($upstream_res['exit'] ?? 1) === 0 && trim((string)($upstream_res['output'] ?? '')) !== '') {
        $ahead_res = ahx_wp_github_run_git($dir, 'rev-list --left-right --count @{u}...HEAD', $git_timeout);
        if (intval($ahead_res['exit'] ?? 1) !== 0) {
            return false;
        }
        $parts = preg_split('/\s+/', trim((string)($ahead_res['output'] ?? '')));
        $ahead = isset($parts[1]) ? intval($parts[1]) : 0;
        return $ahead > 0;
    }

    $origin_res = ahx_wp_github_run_git($dir, 'remote get-url origin', $git_timeout);
    if (intval($origin_res['exit'] ?? 1) === 0 && trim((string)($origin_res['output'] ?? '')) !== '') {
        return true;
    }

    $ahead_main_res = ahx_wp_github_run_git($dir, 'rev-list --count main..HEAD', $git_timeout);
    if (intval($ahead_main_res['exit'] ?? 1) === 0) {
        return intval(trim((string)($ahead_main_res['output'] ?? '0'))) > 0;
    }

    $ahead_master_res = ahx_wp_github_run_git($dir, 'rev-list --count master..HEAD', $git_timeout);
    if (intval($ahead_master_res['exit'] ?? 1) === 0) {
        return intval(trim((string)($ahead_master_res['output'] ?? '0'))) > 0;
    }

    return false;
}

function ahx_wp_github_count_untracked_empty_dirs($dir, $git_timeout = 15) {
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

        $ignore_res = ahx_wp_github_run_git($root, 'check-ignore -q -- ' . escapeshellarg($rel . '/'), $git_timeout, false);
        if (intval($ignore_res['exit'] ?? 1) === 0) {
            continue;
        }

        $count++;
    }

    return $count;
}

function ahx_wp_github_repo_status_cache_key($repo_id, $dir_path) {
    return 'ahx_gh_repo_status_' . intval($repo_id) . '_' . md5((string)$dir_path);
}

function ahx_wp_github_clear_repo_status_cache($repo_id, $dir_path) {
    delete_transient(ahx_wp_github_repo_status_cache_key($repo_id, $dir_path));
}

function ahx_wp_github_get_repo_status_data($repo_id, $dir_path) {
    $cache_key = ahx_wp_github_repo_status_cache_key($repo_id, $dir_path);
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['state'])) {
        return $cached;
    }

    $data = [
        'state' => 'none',
        'count' => 0,
    ];

    $git_dir = $dir_path . DIRECTORY_SEPARATOR . '.git';
    if (!is_dir($git_dir)) {
        set_transient($cache_key, $data, 45);
        return $data;
    }

    $git_timeout = ahx_wp_github_get_git_timeout();
    $res = ahx_wp_github_run_git($dir_path, 'status --porcelain', $git_timeout);
    $exit_code = intval($res['exit'] ?? 1);
    if ($exit_code !== 0) {
        $data['state'] = 'error';
        set_transient($cache_key, $data, 45);
        return $data;
    }

    $status = trim((string)($res['output'] ?? ''));
    $lines = $status !== '' ? array_filter(preg_split('/\r\n|\r|\n/', $status)) : [];
    $count = count($lines);
    $empty_dir_count = ahx_wp_github_count_untracked_empty_dirs($dir_path, $git_timeout);
    $total_count = $count + $empty_dir_count;

    if ($total_count > 0) {
        $data['state'] = 'changes';
        $data['count'] = $total_count;
    } elseif (ahx_wp_github_is_sync_pending($dir_path, $git_timeout)) {
        $data['state'] = 'sync';
    } else {
        $data['state'] = 'clean';
    }

    set_transient($cache_key, $data, 45);
    return $data;
}

add_action('wp_ajax_ahx_repo_row_status', 'ahx_wp_github_ajax_repo_row_status');
function ahx_wp_github_ajax_repo_row_status() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung');
    }

    $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
    if (!wp_verify_nonce($nonce, 'ahx_repo_row_status')) {
        wp_send_json_error('Ungültiger Nonce');
    }

    $repo_id = intval(wp_unslash($_POST['repo_id'] ?? 0));
    if ($repo_id <= 0) {
        wp_send_json_error('Ungültige Repository-ID');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'ahx_wp_github';
    $repo = $wpdb->get_row($wpdb->prepare("SELECT id, dir_path FROM $table WHERE id = %d", $repo_id));
    if (!$repo || !is_dir($repo->dir_path)) {
        wp_send_json_success(['html' => '']);
    }

    $state = ahx_wp_github_get_repo_status_data($repo->id, $repo->dir_path);
    $changes_url = admin_url('admin.php?page=ahx-wp-github&repo_changes=1&dir=' . urlencode($repo->dir_path));
    $html = '';

    if (($state['state'] ?? 'none') === 'changes') {
        $total_count = intval($state['count'] ?? 0);
        $html = '<a href="' . esc_url($changes_url) . '" class="button" title="Änderungsdetails anzeigen">' . $total_count . ' Änderung' . ($total_count > 1 ? 'en' : '') . '</a>';
    } elseif (($state['state'] ?? 'none') === 'sync') {
        $html = '<form method="post" style="display:inline; margin:0;">';
        $html .= wp_nonce_field('ahx_repo_sync', 'ahx_repo_sync_nonce', true, false);
        $html .= '<input type="hidden" name="repo_id" value="' . intval($repo->id) . '">';
        $html .= '<button type="submit" name="ahx_repo_sync_submit" value="1" class="button button-primary" title="Ausstehenden Sync durchführen" onclick="return confirm(\'Möchten Sie den ausstehenden Sync jetzt durchführen?\');">Sync</button>';
        $html .= '</form>';
    } elseif (($state['state'] ?? 'none') === 'clean') {
        $html = '<span class="dashicons dashicons-yes-alt" title="Keine Änderungen" style="color:#8c8f94; font-size:16px; width:16px; height:16px; line-height:16px;"></span>';
    } elseif (($state['state'] ?? 'none') === 'error') {
        $html = '<span title="Git-Status konnte nicht gelesen werden" style="color:#b32d2e;">Statusfehler</span>';
    }

    wp_send_json_success(['html' => $html]);
}

function ahx_wp_github_repo_issues_cache_key($repo_id, $dir_path) {
    return 'ahx_gh_repo_issues_' . intval($repo_id) . '_' . md5((string)$dir_path);
}

function ahx_wp_github_is_github_remote_url($remote_url) {
    $remote_url = trim((string)$remote_url);
    if ($remote_url === '') {
        return false;
    }

    return preg_match('#^(https?://([^/@]+@)?github\.com/|ssh://git@github\.com/|git@github\.com:|git://github\.com/)#i', $remote_url) === 1;
}

function ahx_wp_github_parse_owner_repo_from_remote($remote_url) {
    $remote_url = trim((string)$remote_url);
    if ($remote_url === '') {
        return ['', ''];
    }

    if (preg_match('#github\.com[:/](.+?)(?:\.git)?$#', $remote_url, $m)) {
        $owner_repo = trim((string)$m[1], '/');
        $parts = explode('/', $owner_repo, 2);
        $owner = trim((string)($parts[0] ?? ''));
        $repo = trim((string)($parts[1] ?? ''));
        return [$owner, $repo];
    }

    return ['', ''];
}

function ahx_wp_github_get_repo_issues_badge_html($repo_id, $dir_path) {
    $cache_key = ahx_wp_github_repo_issues_cache_key($repo_id, $dir_path);
    $cached = get_transient($cache_key);
    if (is_array($cached) && array_key_exists('html', $cached)) {
        return (string)$cached['html'];
    }

    $html = '';

    $git_dir = $dir_path . DIRECTORY_SEPARATOR . '.git';
    if (!is_dir($git_dir)) {
        set_transient($cache_key, ['html' => $html], 300);
        return $html;
    }

    $origin_res = ahx_wp_github_run_git($dir_path, 'remote get-url origin', 10);
    if (intval($origin_res['exit'] ?? 1) !== 0) {
        $html = '<span style="color:#8c8f94;">Issues: -</span>';
        set_transient($cache_key, ['html' => $html], 300);
        return $html;
    }

    $remote_url = trim((string)($origin_res['output'] ?? ''));
    if (!ahx_wp_github_is_github_remote_url($remote_url)) {
        $html = '<span style="color:#8c8f94;">Issues: -</span>';
        set_transient($cache_key, ['html' => $html], 600);
        return $html;
    }

    list($owner, $repo) = ahx_wp_github_parse_owner_repo_from_remote($remote_url);
    if ($owner === '' || $repo === '') {
        $html = '<span style="color:#8c8f94;">Issues: -</span>';
        set_transient($cache_key, ['html' => $html], 600);
        return $html;
    }

    $token = trim((string)get_option('ahx_wp_main_github_token', ''));
    $headers = [
        'User-Agent' => 'AHX WP GitHub',
        'Accept' => 'application/vnd.github+json',
    ];
    if ($token !== '') {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    $query = rawurlencode('repo:' . $owner . '/' . $repo . ' type:issue state:open');
    $url = 'https://api.github.com/search/issues?q=' . $query . '&per_page=1';
    $response = wp_remote_get($url, [
        'headers' => $headers,
        'timeout' => 8,
    ]);

    if (is_wp_error($response)) {
        $html = '<span style="color:#8c8f94;" title="' . esc_attr($response->get_error_message()) . '">Issues: -</span>';
        set_transient($cache_key, ['html' => $html], 120);
        return $html;
    }

    $status = intval(wp_remote_retrieve_response_code($response));
    $body = json_decode((string)wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300 || !is_array($body)) {
        $api_message = is_array($body) ? trim((string)($body['message'] ?? '')) : '';
        $title = $api_message !== '' ? $api_message : ('HTTP ' . $status);
        $html = '<span style="color:#8c8f94;" title="' . esc_attr($title) . '">Issues: -</span>';
        set_transient($cache_key, ['html' => $html], 120);
        return $html;
    }

    $count = max(0, intval($body['total_count'] ?? 0));
    $issues_url = 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/issues?q=is%3Aissue+is%3Aopen';
    $badge_style = 'display:inline-block;min-width:18px;padding:0 6px;border-radius:999px;background:#2271b1;color:#fff;font-size:11px;line-height:18px;text-align:center;';

    $html = '<a href="' . esc_url($issues_url) . '" target="_blank" rel="noopener noreferrer" style="text-decoration:none;">'
        . '<span style="color:#1d2327;">Issues</span> '
        . '<span style="' . esc_attr($badge_style) . '">' . esc_html((string)$count) . '</span>'
        . '</a>';

    set_transient($cache_key, ['html' => $html], 600);
    return $html;
}

add_action('wp_ajax_ahx_repo_row_issues', 'ahx_wp_github_ajax_repo_row_issues');
function ahx_wp_github_ajax_repo_row_issues() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung');
    }

    $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
    if (!wp_verify_nonce($nonce, 'ahx_repo_row_issues')) {
        wp_send_json_error('Ungültiger Nonce');
    }

    $repo_id = intval(wp_unslash($_POST['repo_id'] ?? 0));
    if ($repo_id <= 0) {
        wp_send_json_error('Ungültige Repository-ID');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'ahx_wp_github';
    $repo = $wpdb->get_row($wpdb->prepare("SELECT id, dir_path FROM $table WHERE id = %d", $repo_id));
    if (!$repo || !is_dir($repo->dir_path)) {
        wp_send_json_success(['html' => '']);
    }

    $html = ahx_wp_github_get_repo_issues_badge_html(intval($repo->id), (string)$repo->dir_path);
    wp_send_json_success(['html' => $html]);
}

add_action('wp_ajax_ahx_repo_browse_dirs', 'ahx_wp_github_ajax_browse_dirs');
function ahx_wp_github_ajax_browse_dirs() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung');
    }

    $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
    if (!wp_verify_nonce($nonce, 'ahx_repo_browse')) {
        wp_send_json_error('Ungültiger Nonce');
    }

    $roots = ahx_wp_github_get_browse_roots();
    $requested = sanitize_text_field(wp_unslash($_POST['path'] ?? ''));
    $path = ahx_wp_github_normalize_dir_path($requested);

    if ($path === '') {
        if (!empty($roots)) {
            $path = $roots[0];
        } elseif (defined('ABSPATH')) {
            $path = ahx_wp_github_normalize_dir_path(ABSPATH);
        }
    }

    if ($path === '' || !is_dir($path)) {
        wp_send_json_error('Verzeichnis nicht gefunden');
    }

    $items = @scandir($path);
    if ($items === false) {
        wp_send_json_error('Verzeichnis kann nicht gelesen werden');
    }

    $dirs = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) {
            $dirs[] = [
                'name' => $item,
                'path' => ahx_wp_github_normalize_dir_path($child),
            ];
        }
    }

    usort($dirs, function($a, $b) {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    $parent = dirname($path);
    $parent = ahx_wp_github_normalize_dir_path($parent);
    if ($parent === $path || $parent === '.') {
        $parent = '';
    }

    wp_send_json_success([
        'path' => $path,
        'parent_path' => $parent,
        'roots' => $roots,
        'dirs' => $dirs,
    ]);
}