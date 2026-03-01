<?php
/*
Plugin Name: AHX WP GitHub
Description: Plugin zum Erfassen von Verzeichnissen, Initialisieren als GitHub-Repository und Listen der Einträge.
Version: v1.7.0
Author: AHX
*/

if (!defined('ABSPATH')) {
    exit;
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
    if (class_exists('AHX_Logging') && method_exists('AHX_Logging', 'get_instance')) {
        AHX_Logging::get_instance()->log_debug('ajax_commit: incoming request dir=' . var_export($_POST['dir'] ?? '', true), 'ahx_wp_github');
    }
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'ahx_repo_commit')) {
        wp_send_json_error('Ungültiger Nonce');
    }

    $dir = $_POST['dir'] ?? '';
    if (!$dir || !is_dir($dir)) {
        wp_send_json_error('Ungültiges Verzeichnis');
    }
    // Use internal shared handler to perform commit/push without remote HTTP request
    require_once plugin_dir_path(__FILE__) . 'admin/commit-handler.php';
    $post_body = [
        'commit_action' => $_POST['commit_action'] ?? 'commit_sync',
        'commit_message' => $_POST['commit_message'] ?? '',
        'version_bump' => $_POST['version_bump'] ?? 'none',
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

add_action('wp_ajax_ahx_repo_browse_dirs', 'ahx_wp_github_ajax_browse_dirs');
function ahx_wp_github_ajax_browse_dirs() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung');
    }

    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'ahx_repo_browse')) {
        wp_send_json_error('Ungültiger Nonce');
    }

    $roots = ahx_wp_github_get_browse_roots();
    $requested = isset($_POST['path']) ? wp_unslash($_POST['path']) : '';
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