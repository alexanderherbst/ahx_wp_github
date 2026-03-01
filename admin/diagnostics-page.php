<?php
if (!current_user_can('manage_options')) {
    wp_die(__('Keine Berechtigung.'));
}

if (!function_exists('ahx_run_git_cmd') || !function_exists('ahx_find_git_binary')) {
    require_once plugin_dir_path(__FILE__) . 'commit-handler.php';
}

global $wpdb;
$table = $wpdb->prefix . 'ahx_wp_github';
$repos = $wpdb->get_results("SELECT id, name, dir_path FROM $table ORDER BY name ASC");

$configured_git_timeout = intval(get_option('ahx_wp_github_git_timeout_seconds', 15));
if ($configured_git_timeout < 5) $configured_git_timeout = 15;
if ($configured_git_timeout > 120) $configured_git_timeout = 120;

function ahx_diag_run_cmd($command, $cwd = null, $timeout = 20, $is_git_command = false) {
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cmd = $command;
    if (stripos(PHP_OS, 'WIN') === 0) {
        $cmd = 'cmd /c ' . $command;
    }

    error_log('[ahx_wp_github][diagnostics] start command=' . $command . ' cwd=' . ($cwd ?: '-') . ' timeout=' . intval($timeout) . ' is_git=' . ($is_git_command ? '1' : '0'));

    $process = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        error_log('[ahx_wp_github][diagnostics] proc_open failed command=' . $command);
        return ['exit' => -1, 'output' => 'proc_open failed'];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $start = microtime(true);
    $exitCode = null;

    $timedOut = false;
    $pid = null;
    while (true) {
        $status = proc_get_status($process);
        if (isset($status['pid']) && $pid === null) $pid = intval($status['pid']);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        if ($stdout !== '') $output .= $stdout;
        if ($stderr !== '') $output .= $stderr;

        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }

        if ((microtime(true) - $start) > $timeout) {
            $timedOut = true;
            if ($pid && stripos(PHP_OS, 'WIN') === 0) {
                @exec('taskkill /F /T /PID ' . intval($pid) . ' 2>&1', $killOut, $killExit);
                $output .= "\n[taskkill exit={$killExit}]\n" . implode("\n", (array)$killOut);
            }
            proc_terminate($process);
            $exitCode = 124;
            $output .= "\n[timeout after {$timeout}s]";
            break;
        }

        usleep(100000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $snippet = substr(trim($output), 0, 2000);
    error_log('[ahx_wp_github][diagnostics] done command=' . $command . ' exit=' . intval($exitCode) . ' timeout_hit=' . ($timedOut ? '1' : '0') . ' output=' . $snippet);

    return ['exit' => $exitCode, 'output' => trim($output)];
}

$results = [];
$selected_repo_id = 0;
if (isset($_POST['ahx_run_diagnostics'])) {
    check_admin_referer('ahx_wp_github_run_diagnostics');

    $selected_repo_id = intval($_POST['repo_id'] ?? 0);
    $selected_repo = null;
    foreach ($repos as $repo) {
        if (intval($repo->id) === $selected_repo_id) {
            $selected_repo = $repo;
            break;
        }
    }

    if (!$selected_repo) {
        $results[] = ['label' => 'Fehler', 'command' => '', 'exit' => 1, 'output' => 'Ungültiges Repository gewählt.'];
    } else {
        $dir = $selected_repo->dir_path;
        $commands = [
            ['label' => 'System User', 'cmd' => 'whoami', 'cwd' => null, 'is_git' => false],
            ['label' => 'Git Version', 'git_args' => '--version', 'display' => 'git --version', 'is_git' => true],
            ['label' => 'DNS github.com', 'cmd' => 'nslookup github.com', 'cwd' => null, 'is_git' => false],
            ['label' => 'Remote URLs', 'git_args' => 'remote -v', 'display' => 'git remote -v', 'is_git' => true],
            ['label' => 'Branch', 'git_args' => 'rev-parse --abbrev-ref HEAD', 'display' => 'git rev-parse --abbrev-ref HEAD', 'is_git' => true],
            ['label' => 'ls-remote origin', 'git_args' => 'ls-remote --heads origin', 'display' => 'git ls-remote --heads origin', 'is_git' => true],
            ['label' => 'Safe Directory List', 'git_args' => 'config --global --get-all safe.directory', 'display' => 'git config --global --get-all safe.directory', 'is_git' => true],
        ];

        $git_bin = ahx_find_git_binary();

        foreach ($commands as $item) {
            $is_git = !empty($item['is_git']);
            $timeout = $is_git ? $configured_git_timeout : 25;

            if ($is_git) {
                $git_args = (string)($item['git_args'] ?? '');
                $needs_remote_auth = preg_match('/^(fetch|pull|push|ls-remote)\b/i', $git_args) === 1;
                $res = ahx_run_git_cmd($git_bin, $dir, $git_args, $timeout, $needs_remote_auth);
                $command_display = (string)($item['display'] ?? ('git ' . $git_args));
            } else {
                $res = ahx_diag_run_cmd($item['cmd'], $item['cwd'] ?? null, $timeout, false);
                $command_display = (string)($item['cmd'] ?? '');
            }

            $results[] = [
                'label' => $item['label'],
                'command' => $command_display,
                'exit' => $res['exit'],
                'output' => $res['output'],
                'timeout' => $timeout,
                'is_git' => $is_git,
            ];
        }
    }
}
?>

<div class="wrap">
    <h1>AHX WP GitHub Diagnose</h1>
    <p>Diese Seite führt feste Diagnosebefehle aus (Git/DNS/Remote) und zeigt Exit-Code sowie Ausgabe.</p>
    <p><strong>Aktueller Git-Timeout:</strong> <?php echo esc_html((string)$configured_git_timeout); ?> Sekunden (konfigurierbar unter <em>Einstellungen</em>).</p>

    <form method="post" style="margin-bottom:20px;">
        <?php wp_nonce_field('ahx_wp_github_run_diagnostics'); ?>
        <label for="repo_id"><strong>Repository:</strong></label>
        <select name="repo_id" id="repo_id" required>
            <option value="">Bitte auswählen</option>
            <?php foreach ($repos as $repo): ?>
                <option value="<?php echo intval($repo->id); ?>" <?php selected($selected_repo_id, intval($repo->id)); ?>>
                    <?php echo esc_html($repo->name . ' — ' . $repo->dir_path); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" name="ahx_run_diagnostics" value="1" class="button button-primary">Diagnose starten</button>
    </form>

    <?php if (!empty($results)): ?>
        <h2>Ergebnisse</h2>
        <?php foreach ($results as $row): ?>
            <div style="border:1px solid #ddd; padding:10px; margin-bottom:10px; background:#fff;">
                <p style="margin:0 0 6px 0;"><strong><?php echo esc_html($row['label']); ?></strong></p>
                <?php if ($row['command'] !== ''): ?>
                    <p style="margin:0 0 6px 0;"><code><?php echo esc_html($row['command']); ?></code></p>
                <?php endif; ?>
                <p style="margin:0 0 6px 0;">Exit-Code: <strong><?php echo esc_html((string) $row['exit']); ?></strong> · Timeout: <strong><?php echo esc_html((string)($row['timeout'] ?? '')); ?>s</strong> · Typ: <strong><?php echo !empty($row['is_git']) ? 'git' : 'other'; ?></strong></p>
                <pre style="white-space:pre-wrap; margin:0; background:#f7f7f7; border:1px solid #eee; padding:8px;"><?php echo esc_html($row['output']); ?></pre>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
