<?php
if (!defined('ABSPATH')) {
    exit;
}
if (!current_user_can('manage_options')) {
    wp_die(__('Keine Berechtigung.'));
}

global $wpdb;
$table = $wpdb->prefix . 'ahx_wp_github';
$rows  = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
$count = is_array($rows) ? count($rows) : 0;

$repos_url       = admin_url('admin.php?page=ahx-wp-github-repos');
$diagnostics_url = admin_url('admin.php?page=ahx-wp-github-diagnostics');
$workflow_url    = admin_url('admin.php?page=ahx-wp-github-workflow-wizard');
$settings_url    = admin_url('options-general.php?page=ahx-wp-github-settings');

// --- Stats ---
$gi_count = 0;
foreach ((array)$rows as $row) {
    $git_dir = rtrim((string)$row->dir_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.git';
    if (is_dir($git_dir)) {
        $gi_count++;
    }
}
$not_init = $count - $gi_count;

$plugin_data    = get_file_data(plugin_dir_path(__DIR__) . 'ahx_wp_github.php', ['Version' => 'Version']);
$plugin_version = trim((string)($plugin_data['Version'] ?? ''));

$token    = trim((string)get_option('ahx_wp_main_github_token', ''));
$token_ok = ($token !== '');
?>
<style>
.ahx-landing { max-width:1200px; }
.ahx-landing h1 { display:flex; align-items:center; gap:10px; margin-bottom:6px; }
.ahx-landing .ahx-version {
    font-size:13px; font-weight:normal; color:#777;
    background:#f0f0f1; border-radius:10px; padding:2px 10px;
}
.ahx-landing .ahx-subtitle { color:#777; margin-bottom:24px; font-size:14px; }
.ahx-status-row  { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:24px; }
.ahx-status-card {
    flex:1 1 160px; background:#fff; border:1px solid #c3c4c7;
    border-radius:6px; padding:16px 20px; display:flex;
    align-items:center; gap:14px; min-width:160px;
}
.ahx-status-card .ahx-sc-icon  { font-size:28px; line-height:1; flex-shrink:0; }
.ahx-status-card .ahx-sc-value { font-size:24px; font-weight:700; line-height:1.1; }
.ahx-status-card .ahx-sc-label { font-size:12px; color:#777; }
.ahx-features {
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(280px,1fr));
    gap:16px; margin-bottom:24px;
}
.ahx-feature-card {
    background:#fff; border:1px solid #c3c4c7;
    border-radius:6px; padding:20px 22px;
}
.ahx-feature-card h3 { margin:0 0 6px; font-size:14px; display:flex; align-items:center; gap:8px; }
.ahx-feature-card p  { margin:0 0 12px; color:#555; font-size:13px; line-height:1.5; }
.ahx-badge {
    display:inline-block; border-radius:10px; padding:2px 9px;
    font-size:11px; font-weight:600; color:#fff; margin-bottom:10px;
}
.ahx-badge-ok   { background:#00a32a; }
.ahx-badge-warn { background:#dba617; }
.ahx-badge-err  { background:#d63638; }
.ahx-badge-info { background:#2271b1; }
.ahx-badge-grey { background:#777; }
.ahx-feature-card .button { font-size:12px; margin-right:4px; }
.ahx-repo-mini-table { width:100%; border-collapse:collapse; font-size:13px; margin-top:4px; }
.ahx-repo-mini-table th { text-align:left; padding:5px 8px; color:#50575e; font-weight:600; border-bottom:1px solid #e2e4e7; }
.ahx-repo-mini-table td { padding:7px 8px; border-bottom:1px solid #f0f0f1; vertical-align:middle; }
.ahx-repo-mini-table tr:last-child td { border-bottom:none; }
.ahx-type-pill { display:inline-block; font-size:11px; padding:1px 8px; border-radius:20px; font-weight:600; }
.ahx-type-plugin   { background:#dde8f5; color:#2271b1; }
.ahx-type-template { background:#e6f4ea; color:#1a7e30; }
.ahx-type-other    { background:#f0f0f1; color:#646970; }
</style>

<div class="wrap ahx-landing">

    <h1>
        <span class="dashicons dashicons-admin-site" style="color:#2271b1;font-size:32px;width:32px;height:32px;"></span>
        AHX WP GitHub
        <?php if ($plugin_version !== ''): ?>
            <span class="ahx-version"><?php echo esc_html($plugin_version); ?></span>
        <?php endif; ?>
    </h1>
    <p class="ahx-subtitle">Git-Repositories verwalten – Commits, Push, Releases und Diagnose direkt im WordPress-Admin.</p>

    <p style="margin-top:-8px;margin-bottom:20px;">
        <a href="<?php echo esc_url($repos_url); ?>" class="button button-primary">Repositories</a>
        <a href="<?php echo esc_url($diagnostics_url); ?>" class="button button-secondary">Diagnose</a>
        <a href="<?php echo esc_url($workflow_url); ?>" class="button button-secondary">Workflow-Assistent</a>
        <a href="<?php echo esc_url($settings_url); ?>" class="button button-secondary">Einstellungen</a>
    </p>

    <!-- Status-Karten -->
    <div class="ahx-status-row">

        <div class="ahx-status-card">
            <div class="ahx-sc-icon">
                <span class="dashicons dashicons-category" style="color:#2271b1;"></span>
            </div>
            <div>
                <div class="ahx-sc-value"><?php echo esc_html($count); ?></div>
                <div class="ahx-sc-label">Repositories erfasst</div>
            </div>
        </div>

        <div class="ahx-status-card">
            <div class="ahx-sc-icon">
                <span class="dashicons dashicons-yes-alt"
                      style="color:<?php echo $gi_count > 0 ? '#00a32a' : '#c3c4c7'; ?>;"></span>
            </div>
            <div>
                <div class="ahx-sc-value" style="color:<?php echo $gi_count > 0 ? '#00a32a' : '#777'; ?>">
                    <?php echo esc_html($gi_count); ?>
                </div>
                <div class="ahx-sc-label">Git initialisiert</div>
            </div>
        </div>

        <div class="ahx-status-card">
            <div class="ahx-sc-icon">
                <span class="dashicons dashicons-warning"
                      style="color:<?php echo $not_init > 0 ? '#dba617' : '#c3c4c7'; ?>;"></span>
            </div>
            <div>
                <div class="ahx-sc-value" style="color:<?php echo $not_init > 0 ? '#dba617' : '#777'; ?>">
                    <?php echo esc_html($not_init); ?>
                </div>
                <div class="ahx-sc-label">Nicht initialisiert</div>
            </div>
        </div>

        <div class="ahx-status-card">
            <div class="ahx-sc-icon">
                <span class="dashicons dashicons-admin-network"
                      style="color:<?php echo $token_ok ? '#00a32a' : '#d63638'; ?>;"></span>
            </div>
            <div>
                <div class="ahx-sc-value" style="font-size:15px;color:<?php echo $token_ok ? '#00a32a' : '#d63638'; ?>">
                    <?php echo $token_ok ? 'Vorhanden' : 'Fehlt'; ?>
                </div>
                <div class="ahx-sc-label">GitHub API Token</div>
            </div>
        </div>

    </div>

    <!-- Feature-Karten -->
    <div class="ahx-features">

        <div class="ahx-feature-card">
            <h3><span class="dashicons dashicons-category"></span>Repositories</h3>
            <span class="ahx-badge ahx-badge-info"><?php echo esc_html($count); ?> erfasst &middot; <?php echo esc_html($gi_count); ?> mit Git</span>
            <p>Verzeichnisse als Git-Repositories erfassen, Änderungen einsehen, Commits erstellen und per Push synchronisieren.</p>
            <a href="<?php echo esc_url($repos_url); ?>" class="button">Repositories öffnen</a>
        </div>

        <div class="ahx-feature-card">
            <h3><span class="dashicons dashicons-search"></span>Diagnose</h3>
            <span class="ahx-badge ahx-badge-grey">Werkzeuge</span>
            <p>Git-Installation, Remote-Konfiguration und häufige Fehlerquellen direkt aus dem Browser prüfen.</p>
            <a href="<?php echo esc_url($diagnostics_url); ?>" class="button">Diagnose starten</a>
        </div>

        <div class="ahx-feature-card">
            <h3><span class="dashicons dashicons-admin-network"></span>GitHub API Token</h3>
            <?php if ($token_ok): ?>
                <span class="ahx-badge ahx-badge-ok">Konfiguriert</span>
            <?php else: ?>
                <span class="ahx-badge ahx-badge-err">Nicht konfiguriert</span>
            <?php endif; ?>
            <p>Ein GitHub Personal Access Token ermöglicht Push via API, Issue-Anzeige und automatische Release-Erstellung.</p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=ahx-wp-main-config')); ?>" class="button">Token hinterlegen</a>
        </div>

        <div class="ahx-feature-card">
            <h3><span class="dashicons dashicons-hammer"></span>Workflow-Assistent</h3>
            <span class="ahx-badge ahx-badge-grey">Geführter Setup</span>
            <p>Schritt-für-Schritt-Assistent zum Einrichten neuer Repositories, Branches und Push-Konfigurationen.</p>
            <a href="<?php echo esc_url($workflow_url); ?>" class="button">Assistent öffnen</a>
        </div>

        <div class="ahx-feature-card">
            <h3><span class="dashicons dashicons-admin-settings"></span>Einstellungen</h3>
            <span class="ahx-badge ahx-badge-grey">Konfiguration</span>
            <p>Log-Level, Git-Timeout, Push-Präferenzen (API vs. lokales git) und erlaubte Remote-URL-Richtlinien.</p>
            <a href="<?php echo esc_url($settings_url); ?>" class="button">Einstellungen öffnen</a>
        </div>

    </div>

    <!-- Letzte Repositories -->
    <?php if ($count > 0):
        $recent = array_slice((array)$rows, 0, 8);
    ?>
    <h2 style="font-size:15px;margin-bottom:10px;">Zuletzt erfasste Repositories</h2>
    <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;overflow:hidden;margin-bottom:20px;">
        <table class="ahx-repo-mini-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Typ</th>
                    <th>Verzeichnis</th>
                    <th>Erfasst am</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $row):
                $pill_class  = 'ahx-type-' . (in_array($row->type, ['plugin','template']) ? $row->type : 'other');
                $details_url = admin_url('admin.php?page=ahx-wp-github-repos&repo_details=1&dir=' . urlencode($row->dir_path));
                $changes_url = admin_url('admin.php?page=ahx-wp-github-repos&repo_changes=1&dir=' . urlencode($row->dir_path));
                $display_dir = function_exists('ahx_wp_github_format_dir_path_for_display')
                    ? ahx_wp_github_format_dir_path_for_display($row->dir_path)
                    : preg_replace('/[\\\\\/]+/', '/', $row->dir_path);
            ?>
                <tr>
                    <td><strong><?php echo esc_html($row->name); ?></strong></td>
                    <td><span class="ahx-type-pill <?php echo esc_attr($pill_class); ?>"><?php echo esc_html($row->type); ?></span></td>
                    <td style="font-family:monospace;font-size:12px;color:#646970;"><?php echo esc_html($display_dir); ?></td>
                    <td style="color:#777;white-space:nowrap;"><?php echo esc_html($row->created_at); ?></td>
                    <td style="white-space:nowrap;">
                        <a href="<?php echo esc_url($details_url); ?>" class="button button-small">Details</a>
                        <a href="<?php echo esc_url($changes_url); ?>" class="button button-small">Änderungen</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($count > 8): ?>
            <p style="margin:8px 12px 10px;font-size:12px;color:#777;">
                … und <?php echo esc_html($count - 8); ?> weitere &mdash;
                <a href="<?php echo esc_url($repos_url); ?>">Alle anzeigen</a>
            </p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px 22px;margin-bottom:20px;">
        <p style="margin:0;color:#777;">
            Noch keine Repositories erfasst. &mdash;
            <a href="<?php echo esc_url($repos_url); ?>">Jetzt ein Verzeichnis hinzufügen →</a>
        </p>
    </div>
    <?php endif; ?>

</div>
