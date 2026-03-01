<?php
if (!current_user_can('manage_options')) {
    wp_die(__('Keine Berechtigung.'));
}

if (!function_exists('ahx_run_cmd_with_timeout') || !function_exists('ahx_find_git_binary')) {
    require_once plugin_dir_path(__FILE__) . 'commit-handler.php';
}

function ahx_wp_github_wizard_run_git($git_bin, $dir, $args, $timeout = 20) {
    $needs_remote_auth = preg_match('/^(pull|fetch|push|ls-remote)\b/i', trim($args)) === 1;
    $res = ahx_run_git_cmd($git_bin, $dir, $args, $timeout, $needs_remote_auth);

    $safe_config = '-c safe.directory=' . escapeshellarg($dir);
    $cmd_display = escapeshellarg($git_bin) . ' ' . $safe_config . ' ' . $args;
    if ($needs_remote_auth) {
        $cmd_display = escapeshellarg($git_bin) . ' ' . $safe_config . ' -c credential.helper= -c core.askPass= -c http.extraHeader=' . escapeshellarg('AUTHORIZATION: basic ***') . ' ' . $args;
    }

    return [
        'cmd' => $cmd_display,
        'exit' => intval($res['exit'] ?? -1),
        'output' => trim((string)($res['output'] ?? '')),
    ];
}

function ahx_wp_github_wizard_status($git_bin, $dir, $timeout = 15) {
    $branch = ahx_wp_github_wizard_run_git($git_bin, $dir, 'rev-parse --abbrev-ref HEAD', $timeout);
    $status = ahx_wp_github_wizard_run_git($git_bin, $dir, 'status --porcelain', $timeout);
    $upstream = ahx_wp_github_wizard_run_git($git_bin, $dir, 'rev-parse --abbrev-ref --symbolic-full-name @{u}', $timeout);

    return [
        'branch' => trim($branch['output']),
        'dirty' => $status['output'] !== '',
        'dirty_count' => $status['output'] === '' ? 0 : count(array_filter(explode("\n", $status['output']))),
        'upstream' => ($upstream['exit'] === 0) ? trim($upstream['output']) : '',
    ];
}

function ahx_wp_github_wizard_branch_exists($git_bin, $dir, $branch_name, $timeout = 10) {
    $res = ahx_wp_github_wizard_run_git(
        $git_bin,
        $dir,
        'show-ref --verify --quiet ' . escapeshellarg('refs/heads/' . $branch_name),
        $timeout
    );
    return intval($res['exit']) === 0;
}

global $wpdb;
$table = $wpdb->prefix . 'ahx_wp_github';
$repos = $wpdb->get_results("SELECT id, name, dir_path FROM $table ORDER BY name ASC");

$selected_repo_id = intval($_POST['repo_id'] ?? ($_GET['repo_id'] ?? 0));
$feature_branch = sanitize_text_field($_POST['feature_branch'] ?? '');
$branch_title = sanitize_text_field($_POST['branch_title'] ?? '');
$messages = [];
$results = [];
$repo = null;
$manual_fallback = null;

foreach ($repos as $r) {
    if (intval($r->id) === $selected_repo_id) {
        $repo = $r;
        break;
    }
}

$git_timeout = intval(get_option('ahx_wp_github_git_timeout_seconds', 15));
if ($git_timeout < 5) $git_timeout = 15;
if ($git_timeout > 120) $git_timeout = 120;

$git_bin = ahx_find_git_binary();
$repo_status = null;
$next_feature_number = 1;

$workflow_steps = [
    1 => ['action' => 'switch_main', 'label' => 'Auf main wechseln'],
    2 => ['action' => 'pull_main', 'label' => 'main vom Remote aktualisieren'],
    3 => ['action' => 'switch_feature', 'label' => 'Zurück auf den Feature-Branch wechseln'],
    4 => ['action' => 'rebase_main', 'label' => 'Feature-Branch auf main rebasen'],
];

$workflow_progress = [
    'last_completed_step' => 0,
    'last_executed_step' => 0,
    'last_executed_ok' => null,
    'last_action' => '',
];

if ($selected_repo_id > 0) {
    $counter_option_key = 'ahx_wp_github_feature_counter_' . $selected_repo_id;
    $last_used = intval(get_option($counter_option_key, 0));
    $next_feature_number = max(1, $last_used + 1);

    $progress_option_key = 'ahx_wp_github_wizard_progress_' . $selected_repo_id;
    $stored_progress = get_option($progress_option_key, []);
    if (is_array($stored_progress)) {
        $workflow_progress['last_completed_step'] = max(0, min(4, intval($stored_progress['last_completed_step'] ?? 0)));
        $workflow_progress['last_executed_step'] = max(0, min(4, intval($stored_progress['last_executed_step'] ?? 0)));
        $stored_ok = $stored_progress['last_executed_ok'] ?? null;
        $workflow_progress['last_executed_ok'] = is_bool($stored_ok) ? $stored_ok : null;
        $workflow_progress['last_action'] = sanitize_text_field((string)($stored_progress['last_action'] ?? ''));
    }
}

if ($repo && is_dir($repo->dir_path) && is_dir($repo->dir_path . DIRECTORY_SEPARATOR . '.git')) {
    $repo_status = ahx_wp_github_wizard_status($git_bin, $repo->dir_path, $git_timeout);
    if ($feature_branch === '' && !empty($repo_status['branch']) && $repo_status['branch'] !== 'main') {
        $feature_branch = $repo_status['branch'];
    }
}

if (isset($_POST['ahx_wizard_action'])) {
    check_admin_referer('ahx_wp_github_workflow_wizard');
    $action = sanitize_key($_POST['ahx_wizard_action']);
    $before_result_count = count($results);
    $executed_step = 0;
    foreach ($workflow_steps as $step_number => $step_data) {
        if ($step_data['action'] === $action) {
            $executed_step = $step_number;
            break;
        }
    }
    if ($executed_step > 0) {
        $workflow_progress['last_executed_step'] = $executed_step;
        $workflow_progress['last_action'] = $action;
        $workflow_progress['last_executed_ok'] = null;
    }

    if (!$repo) {
        $messages[] = ['type' => 'error', 'text' => 'Bitte zuerst ein Repository auswählen.'];
    } elseif (!is_dir($repo->dir_path) || !is_dir($repo->dir_path . DIRECTORY_SEPARATOR . '.git')) {
        $messages[] = ['type' => 'error', 'text' => 'Das gewählte Verzeichnis ist kein gültiges Git-Repository.'];
    } else {
        if ($action === 'status') {
            $messages[] = ['type' => 'updated', 'text' => 'Repository-Status aktualisiert.'];
        }

        if ($action === 'create_numbered_feature_branch') {
            $counter_option_key = 'ahx_wp_github_feature_counter_' . $selected_repo_id;
            $last_used = intval(get_option($counter_option_key, 0));
            $number = max(1, $last_used + 1);
            $title_slug = sanitize_title($branch_title);
            $created = false;

            for ($i = 0; $i < 200; $i++) {
                $candidate_number = $number + $i;
                $padded = str_pad((string)$candidate_number, 4, '0', STR_PAD_LEFT);
                $candidate = 'feature/' . $padded;
                if ($title_slug !== '') {
                    $candidate .= '-' . $title_slug;
                }

                if (ahx_wp_github_wizard_branch_exists($git_bin, $repo->dir_path, $candidate, $git_timeout)) {
                    continue;
                }

                $create = ahx_wp_github_wizard_run_git($git_bin, $repo->dir_path, 'switch -c ' . escapeshellarg($candidate), $git_timeout);
                $results[] = $create;
                if (intval($create['exit']) === 0) {
                    $feature_branch = $candidate;
                    update_option($counter_option_key, $candidate_number, false);
                    $workflow_progress['last_completed_step'] = 0;
                    $workflow_progress['last_executed_step'] = 0;
                    $workflow_progress['last_executed_ok'] = null;
                    $workflow_progress['last_action'] = '';
                    update_option('ahx_wp_github_wizard_progress_' . $selected_repo_id, $workflow_progress, false);
                    $messages[] = ['type' => 'updated', 'text' => 'Feature-Branch erstellt: ' . $feature_branch];
                    $created = true;
                    break;
                }
            }

            if (!$created) {
                $messages[] = ['type' => 'error', 'text' => 'Konnte keinen freien nummerierten Feature-Branch erstellen.'];
            }
        }

        if ($action === 'switch_main') {
            $results[] = ahx_wp_github_wizard_run_git($git_bin, $repo->dir_path, 'switch main', $git_timeout);
        }

        if ($action === 'pull_main') {
            $results[] = ahx_wp_github_wizard_run_git($git_bin, $repo->dir_path, 'pull origin main', max(20, $git_timeout));
        }

        if ($action === 'switch_feature') {
            if ($feature_branch === '') {
                $messages[] = ['type' => 'error', 'text' => 'Bitte einen Feature-Branch angeben.'];
            } else {
                $results[] = ahx_wp_github_wizard_run_git($git_bin, $repo->dir_path, 'switch ' . escapeshellarg($feature_branch), $git_timeout);
            }
        }

        if ($action === 'rebase_main') {
            $results[] = ahx_wp_github_wizard_run_git($git_bin, $repo->dir_path, 'rebase main', max(20, $git_timeout));
        }

        if ($action === 'sync_origin_main') {
            $results[] = ahx_wp_github_wizard_run_git($git_bin, $repo->dir_path, 'fetch origin', $git_timeout);
            $results[] = ahx_wp_github_wizard_run_git($git_bin, $repo->dir_path, 'rebase origin/main', max(20, $git_timeout));
        }

        if ($action === 'push_force_with_lease') {
            $current_branch_res = ahx_wp_github_wizard_run_git($git_bin, $repo->dir_path, 'rev-parse --abbrev-ref HEAD', $git_timeout);
            $results[] = $current_branch_res;
            $current_branch = trim((string)($current_branch_res['output'] ?? ''));

            $upstream_res = ahx_wp_github_wizard_run_git($git_bin, $repo->dir_path, 'rev-parse --abbrev-ref --symbolic-full-name @{u}', $git_timeout);
            $upstream_res['non_blocking'] = true;
            $results[] = $upstream_res;
            $has_upstream = intval($upstream_res['exit']) === 0 && trim((string)($upstream_res['output'] ?? '')) !== '';

            if (!$has_upstream && $current_branch !== '' && $current_branch !== 'HEAD') {
                $results[] = ahx_wp_github_wizard_run_git(
                    $git_bin,
                    $repo->dir_path,
                    'push --set-upstream origin ' . escapeshellarg($current_branch),
                    max(20, $git_timeout)
                );
                $messages[] = ['type' => 'updated', 'text' => 'Upstream war nicht gesetzt und wurde automatisch eingerichtet.'];
            } else {
                $results[] = ahx_wp_github_wizard_run_git($git_bin, $repo->dir_path, 'push --force-with-lease', max(20, $git_timeout));
            }
        }

        $repo_status = ahx_wp_github_wizard_status($git_bin, $repo->dir_path, $git_timeout);

        if ($executed_step > 0) {
            $new_results = array_slice($results, $before_result_count);
            $step_ok = !empty($new_results);
            foreach ($new_results as $res) {
                if (!empty($res['non_blocking'])) {
                    continue;
                }
                if (intval($res['exit'] ?? 1) !== 0) {
                    $step_ok = false;
                    break;
                }
            }

            foreach ($new_results as $res) {
                $out = strtolower((string)($res['output'] ?? ''));
                if (strpos($out, 'getaddrinfo() thread failed to start') !== false) {
                    $repo_dir = $repo->dir_path;
                    $manual_fallback = [
                        'title' => 'Remote-Zugriff im Webserver-Prozess fehlgeschlagen (DNS-Thread konnte nicht starten).',
                        'commands' => [
                            'git -C "' . $repo_dir . '" switch main',
                            'git -C "' . $repo_dir . '" pull origin main',
                            ($feature_branch !== '' ? 'git -C "' . $repo_dir . '" switch ' . $feature_branch : ''),
                            'git -C "' . $repo_dir . '" rebase main',
                        ],
                    ];
                    $messages[] = [
                        'type' => 'error',
                        'text' => 'Der Git-Remote-Zugriff aus PHP/Webserver ist fehlgeschlagen (getaddrinfo-Thread). Bitte den Remote-Schritt im Terminal ausführen und danach im Assistenten mit "Status aktualisieren" fortfahren.',
                    ];
                    break;
                }
            }

            $workflow_progress['last_executed_ok'] = $step_ok;

            if ($step_ok) {
                if ($executed_step === 1) {
                    $workflow_progress['last_completed_step'] = 1;
                } elseif ($executed_step > intval($workflow_progress['last_completed_step'])) {
                    $workflow_progress['last_completed_step'] = $executed_step;
                }
            }

            update_option('ahx_wp_github_wizard_progress_' . $selected_repo_id, $workflow_progress, false);
        }
    }
}

$next_step_number = intval($workflow_progress['last_completed_step']) + 1;
if ($next_step_number > 4) {
    $next_step_number = 0;
}

$completed_steps = max(0, min(4, intval($workflow_progress['last_completed_step'])));
$progress_percent = (int) round(($completed_steps / 4) * 100);
?>

<div class="wrap">
    <h1>AHX WP GitHub Workflow-Assistent</h1>
    <p>Der Assistent führt schrittweise durch den konfliktarmen Workflow: <strong>main aktualisieren → Feature-Branch rebasen → optional mit force-with-lease pushen</strong>.</p>

    <?php foreach ($messages as $msg): ?>
        <div class="<?php echo esc_attr($msg['type']); ?>"><p><?php echo esc_html($msg['text']); ?></p></div>
    <?php endforeach; ?>

    <?php if ($manual_fallback): ?>
        <div class="notice notice-warning" style="padding:10px; margin-top:10px;">
            <p><strong><?php echo esc_html($manual_fallback['title']); ?></strong></p>
            <p>Führe die folgenden Befehle im lokalen Terminal aus:</p>
            <pre style="white-space:pre-wrap; margin:0; background:#f7f7f7; border:1px solid #eee; padding:8px;"><?php
                $manual_lines = array_values(array_filter($manual_fallback['commands']));
                echo esc_html(implode("\n", $manual_lines));
            ?></pre>
        </div>
    <?php endif; ?>

    <form method="post" style="margin-bottom:20px;">
        <?php wp_nonce_field('ahx_wp_github_workflow_wizard'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="repo_id">Repository</label></th>
                <td>
                    <select name="repo_id" id="repo_id" required>
                        <option value="">Bitte auswählen</option>
                        <?php foreach ($repos as $r): ?>
                            <option value="<?php echo intval($r->id); ?>" <?php selected($selected_repo_id, intval($r->id)); ?>>
                                <?php echo esc_html($r->name . ' — ' . $r->dir_path); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="feature_branch">Feature-Branch</label></th>
                <td>
                    <input type="text" name="feature_branch" id="feature_branch" value="<?php echo esc_attr($feature_branch); ?>" class="regular-text" placeholder="z. B. feature/mein-ticket">
                    <p class="description">Wird für Schritt 3 und die Empfehlung nach dem Rebase genutzt.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="branch_title">Branch-Titel</label></th>
                <td>
                    <input type="text" name="branch_title" id="branch_title" value="<?php echo esc_attr($branch_title); ?>" class="regular-text" placeholder="z. B. Login Problem beheben">
                    <button type="submit" name="ahx_wizard_action" value="create_numbered_feature_branch" class="button button-secondary" style="margin-left:8px;">Neuen Feature-Branch starten</button>
                    <p class="description">Erzeugt automatisch den nächsten Branch im Format <code>feature/<?php echo esc_html(str_pad((string)$next_feature_number, 4, '0', STR_PAD_LEFT)); ?></code>. Wenn ein Titel gesetzt ist, wird er als Suffix angehängt (z. B. <code>feature/<?php echo esc_html(str_pad((string)$next_feature_number, 4, '0', STR_PAD_LEFT)); ?>-login-problem-beheben</code>).</p>
                </td>
            </tr>
        </table>

        <p>
            <button type="submit" name="ahx_wizard_action" value="status" class="button">Status aktualisieren</button>
        </p>

        <h2>Schrittweise Ausführung</h2>
        <div style="max-width:900px; margin:0 0 12px 0; background:#fff; border:1px solid #ddd; padding:10px;">
            <p style="margin:0 0 8px 0;"><strong>Fortschritt:</strong> <?php echo esc_html((string)$completed_steps); ?> von 4 Kernschritten (<?php echo esc_html((string)$progress_percent); ?>%)</p>
            <div style="height:10px; background:#e6e6e6; border-radius:2px; overflow:hidden;">
                <div style="height:10px; width:<?php echo esc_attr((string)$progress_percent); ?>%; background:#2271b1;"></div>
            </div>
        </div>
        <table class="widefat" style="max-width:900px; margin-bottom:12px;">
            <tbody>
                <tr>
                    <th style="width:220px;">Zuletzt ausgeführt</th>
                    <td>
                        <?php if (intval($workflow_progress['last_executed_step']) > 0): ?>
                            <?php echo 'Schritt ' . esc_html((string)$workflow_progress['last_executed_step']) . ': ' . esc_html($workflow_steps[intval($workflow_progress['last_executed_step'])]['label']); ?>
                            <?php if ($workflow_progress['last_executed_ok'] === true): ?>
                                <span style="margin-left:8px; color:#0a7f2e;">✓ erfolgreich</span>
                            <?php elseif ($workflow_progress['last_executed_ok'] === false): ?>
                                <span style="margin-left:8px; color:#b32d2e;">✗ fehlgeschlagen</span>
                            <?php endif; ?>
                        <?php else: ?>
                            Noch kein Schritt ausgeführt.
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Als nächstes</th>
                    <td>
                        <?php if ($next_step_number > 0): ?>
                            <?php echo 'Schritt ' . esc_html((string)$next_step_number) . ': ' . esc_html($workflow_steps[$next_step_number]['label']); ?>
                        <?php else: ?>
                            Alle Kernschritte 1–4 abgeschlossen.
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <ol style="margin-left:18px;">
            <li style="margin-bottom:8px;">
                <strong>Auf main wechseln</strong><br>
                <?php if (intval($workflow_progress['last_completed_step']) >= 1): ?>
                    <span style="display:inline-block; margin:4px 0; color:#0a7f2e;">Status: Erledigt</span><br>
                <?php elseif ($next_step_number === 1): ?>
                    <span style="display:inline-block; margin:4px 0; color:#2271b1;">Status: Als nächstes</span><br>
                <?php endif; ?>
                <button type="submit" name="ahx_wizard_action" value="switch_main" class="button button-secondary">Schritt 1 ausführen</button>
            </li>
            <li style="margin-bottom:8px;">
                <strong>main vom Remote aktualisieren</strong> (<code>pull origin main</code>)<br>
                <?php if (intval($workflow_progress['last_completed_step']) >= 2): ?>
                    <span style="display:inline-block; margin:4px 0; color:#0a7f2e;">Status: Erledigt</span><br>
                <?php elseif ($next_step_number === 2): ?>
                    <span style="display:inline-block; margin:4px 0; color:#2271b1;">Status: Als nächstes</span><br>
                <?php endif; ?>
                <button type="submit" name="ahx_wizard_action" value="pull_main" class="button button-secondary">Schritt 2 ausführen</button>
            </li>
            <li style="margin-bottom:8px;">
                <strong>Zurück auf den Feature-Branch wechseln</strong><br>
                <?php if (intval($workflow_progress['last_completed_step']) >= 3): ?>
                    <span style="display:inline-block; margin:4px 0; color:#0a7f2e;">Status: Erledigt</span><br>
                <?php elseif ($next_step_number === 3): ?>
                    <span style="display:inline-block; margin:4px 0; color:#2271b1;">Status: Als nächstes</span><br>
                <?php endif; ?>
                <button type="submit" name="ahx_wizard_action" value="switch_feature" class="button button-secondary">Schritt 3 ausführen</button>
            </li>
            <li style="margin-bottom:8px;">
                <strong>Feature-Branch auf aktuellen main rebasen</strong><br>
                <?php if (intval($workflow_progress['last_completed_step']) >= 4): ?>
                    <span style="display:inline-block; margin:4px 0; color:#0a7f2e;">Status: Erledigt</span><br>
                <?php elseif ($next_step_number === 4): ?>
                    <span style="display:inline-block; margin:4px 0; color:#2271b1;">Status: Als nächstes</span><br>
                <?php endif; ?>
                <button type="submit" name="ahx_wizard_action" value="rebase_main" class="button button-secondary">Schritt 4 ausführen</button>
            </li>
            <li style="margin-bottom:8px;">
                <strong>Optional: Während langer Arbeit synchronisieren</strong> (<code>fetch origin</code> + <code>rebase origin/main</code>)<br>
                <button type="submit" name="ahx_wizard_action" value="sync_origin_main" class="button button-secondary">Zwischensync ausführen</button>
            </li>
            <li style="margin-bottom:8px;">
                <strong>Optional: Nach Rebase pushen</strong> (<code>push --force-with-lease</code>)<br>
                <button type="submit" name="ahx_wizard_action" value="push_force_with_lease" class="button button-primary">Push mit Lease ausführen</button>
            </li>
        </ol>
    </form>

    <?php if ($repo && $repo_status): ?>
        <h2>Aktueller Repository-Status</h2>
        <table class="widefat" style="max-width:900px;">
            <tbody>
                <tr>
                    <th style="width:220px;">Verzeichnis</th>
                    <td><?php echo esc_html($repo->dir_path); ?></td>
                </tr>
                <tr>
                    <th>Aktueller Branch</th>
                    <td><?php echo esc_html($repo_status['branch'] ?: 'unbekannt'); ?></td>
                </tr>
                <tr>
                    <th>Uncommittete Änderungen</th>
                    <td><?php echo $repo_status['dirty'] ? esc_html((string)$repo_status['dirty_count']) . ' Datei(en)' : 'Keine'; ?></td>
                </tr>
                <tr>
                    <th>Upstream</th>
                    <td><?php echo esc_html($repo_status['upstream'] ?: 'nicht gesetzt'); ?></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
        <h2>Letzte Befehlsausgaben</h2>
        <?php foreach ($results as $result): ?>
            <div style="border:1px solid #ddd; background:#fff; padding:10px; margin-bottom:10px;">
                <p style="margin:0 0 6px 0;"><strong>Exit-Code:</strong> <?php echo esc_html((string)$result['exit']); ?></p>
                <p style="margin:0 0 6px 0;"><code><?php echo esc_html($result['cmd']); ?></code></p>
                <pre style="white-space:pre-wrap; margin:0; background:#f7f7f7; border:1px solid #eee; padding:8px;"><?php echo esc_html($result['output'] !== '' ? $result['output'] : '(keine Ausgabe)'); ?></pre>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
