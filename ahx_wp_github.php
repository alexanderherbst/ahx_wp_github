<?php
/*
Plugin Name: AHX WP GitHub
Description: Plugin zum Erfassen von Verzeichnissen, Initialisieren als GitHub-Repository und Listen der Einträge.
Version: v1.2.0
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