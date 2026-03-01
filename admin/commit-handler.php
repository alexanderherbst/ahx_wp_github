<?php
if (!defined('ABSPATH')) exit;

function ahx_wp_github_get_logger() {
    if (class_exists('AHX_Logging') && method_exists('AHX_Logging', 'get_instance')) {
        return AHX_Logging::get_instance();
    }
    return null;
}

function ahx_wp_github_log_debug($message) {
    $msg = (string)$message;
    $msg = ahx_wp_github_redact_command($msg);
    $token = trim((string)get_option('ahx_wp_main_github_token', ''));
    if ($token !== '') {
        $msg = str_replace($token, '[REDACTED]', $msg);
    }

    $logger = ahx_wp_github_get_logger();
    if ($logger) {
        $logger->log_debug($msg, 'ahx_wp_github');
    }

    error_log('[ahx_wp_github] ' . $msg);
}

function ahx_wp_github_redact_command($command) {
    $sanitized = (string)$command;
    $patterns = [
        '/(AUTHORIZATION:\s*basic\s+)[^\s\']+/i',
        '/(Authorization:\s*Bearer\s+)[^\s\']+/i',
        '/(x-access-token:)[^\s\']+/i',
    ];
    foreach ($patterns as $pattern) {
        $sanitized = preg_replace($pattern, '$1[REDACTED]', $sanitized);
    }
    return $sanitized;
}

// Helper: run command with timeout and capture output/exit code (cross-platform)
function ahx_run_cmd_with_timeout($cmd, $cwd = null, $env = null, $timeout = 20) {
    $logged_cmd = ahx_wp_github_redact_command($cmd);
    ahx_wp_github_log_debug('exec.start cmd=' . $logged_cmd . ' cwd=' . (string)$cwd . ' timeout=' . intval($timeout));

    $env = is_array($env) ? $env : [];

    $required_keys = ['SystemRoot', 'WINDIR', 'PATH', 'PATHEXT', 'COMSPEC', 'TEMP', 'TMP', 'HOME', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA'];
    foreach ($required_keys as $key) {
        if (!isset($env[$key]) || trim((string)$env[$key]) === '') {
            $val = getenv($key);
            if ($val !== false && $val !== '') {
                $env[$key] = $val;
            }
        }
    }

    if (isset($_ENV) && is_array($_ENV)) {
        foreach ($_ENV as $k => $v) {
            if (!is_string($k) || $k === '' || isset($env[$k])) {
                continue;
            }
            if (is_scalar($v)) {
                $env[$k] = (string)$v;
            }
        }
    }

    if (stripos(PHP_OS, 'WIN') === 0) {
        if ((!isset($env['PATH']) || trim((string)$env['PATH']) === '') && isset($_SERVER['PATH'])) {
            $env['PATH'] = (string)$_SERVER['PATH'];
        }
        if (!isset($env['SystemRoot']) || trim((string)$env['SystemRoot']) === '') {
            $env['SystemRoot'] = 'C:\\Windows';
        }
        if (!isset($env['WINDIR']) || trim((string)$env['WINDIR']) === '') {
            $env['WINDIR'] = $env['SystemRoot'];
        }
        if (!isset($env['TEMP']) || trim((string)$env['TEMP']) === '') {
            $env['TEMP'] = $env['WINDIR'] . '\\Temp';
        }
        if (!isset($env['TMP']) || trim((string)$env['TMP']) === '') {
            $env['TMP'] = $env['TEMP'];
        }
    }

    if (!isset($env['GIT_TERMINAL_PROMPT'])) {
        $env['GIT_TERMINAL_PROMPT'] = '0';
    }
    if (!isset($env['GIT_ASKPASS'])) {
        $env['GIT_ASKPASS'] = 'echo';
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, $cwd, $env);
    if (!is_resource($process)) {
        ahx_wp_github_log_debug('exec.error cmd=' . $logged_cmd . ' msg=proc_open failed');
        return ['exit' => -1, 'output' => 'proc_open failed'];
    }
    stream_set_blocking($pipes[1], 0);
    stream_set_blocking($pipes[2], 0);
    $output = '';
    $start = microtime(true);
    $exit = null;
    $pid = null;
    while (true) {
        $status = proc_get_status($process);
        if (isset($status['pid']) && $pid === null) {
            $pid = intval($status['pid']);
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        if ($out !== '') $output .= $out;
        if ($err !== '') $output .= $err;
        if (!$status['running']) {
            $exit = $status['exitcode'];
            break;
        }
        if (microtime(true) - $start > $timeout) {
            if ($pid && stripos(PHP_OS, 'WIN') === 0) {
                @exec('taskkill /F /T /PID ' . intval($pid) . ' 2>&1', $kill_out, $kill_exit);
                if (!empty($kill_out)) {
                    $output .= "\n" . implode("\n", (array)$kill_out);
                }
            }
            proc_terminate($process);
            $exit = 124;
            $output .= "\n[timeout after {$timeout}s]";
            break;
        }
        usleep(100000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $output_excerpt = mb_substr((string)$output, 0, 6000);
    ahx_wp_github_log_debug('exec.done cmd=' . $logged_cmd . ' exit=' . intval($exit) . ' output=' . $output_excerpt);
    return ['exit' => $exit, 'output' => $output];
}

function ahx_find_git_binary() {
    if (stripos(PHP_OS, 'WIN') === 0) {
        exec('where git 2>&1', $lines, $code);
        if (!empty($lines)) return trim($lines[0]);
    } else {
        exec('command -v git 2>&1', $lines, $code);
        if (!empty($lines)) return trim($lines[0]);
    }
    return 'git';
}

function ahx_run_git_cmd($git_bin, $dir, $args, $timeout = 20, $needs_remote_auth = false) {
    $cmd = escapeshellarg($git_bin) . ' -c safe.directory=' . escapeshellarg($dir);

    if ($needs_remote_auth) {
        $token = trim((string)get_option('ahx_wp_main_github_token', ''));
        if ($token !== '') {
            $auth_header = 'AUTHORIZATION: basic ' . base64_encode('x-access-token:' . $token);
            $cmd .= ' -c credential.helper= -c core.askPass= -c http.extraHeader=' . escapeshellarg($auth_header);
        }
    }

    $cmd .= ' ' . $args;
    ahx_wp_github_log_debug('git.run args=' . (string)$args . ' dir=' . (string)$dir . ' needs_remote_auth=' . ($needs_remote_auth ? '1' : '0'));
    return ahx_run_cmd_with_timeout($cmd, $dir, $_ENV, $timeout);
}

function ahx_wp_github_ensure_git_identity($git_bin, $dir, $timeout = 20) {
    $res_name = ahx_run_git_cmd($git_bin, $dir, 'config --get user.name', $timeout, false);
    $res_email = ahx_run_git_cmd($git_bin, $dir, 'config --get user.email', $timeout, false);

    $name = trim((string)($res_name['output'] ?? ''));
    $email = trim((string)($res_email['output'] ?? ''));
    if ($name !== '' && $email !== '') {
        ahx_wp_github_log_debug('git identity already configured name=' . $name . ' email=' . $email);
        return ['ok' => true, 'changed' => false, 'name' => $name, 'email' => $email, 'output' => ''];
    }

    $current_user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    $fallback_name = '';
    $fallback_email = '';

    if ($current_user && !empty($current_user->exists())) {
        $fallback_name = trim((string)($current_user->display_name ?: $current_user->user_login ?: ''));
        $fallback_email = trim((string)($current_user->user_email ?? ''));
    }
    if ($fallback_name === '') {
        $fallback_name = trim((string)get_bloginfo('name'));
    }
    if ($fallback_name === '') {
        $fallback_name = 'WordPress Admin';
    }

    if ($fallback_email === '') {
        $fallback_email = trim((string)get_option('admin_email', ''));
    }
    if (!is_email($fallback_email)) {
        $fallback_email = 'wordpress@localhost.localdomain';
    }

    $combined_output = '';
    $changed = false;

    if ($name === '') {
        $set_name = ahx_run_git_cmd($git_bin, $dir, 'config user.name ' . escapeshellarg($fallback_name), $timeout, false);
        $combined_output .= trim((string)($set_name['output'] ?? '')) . "\n";
        if (intval($set_name['exit'] ?? 1) !== 0) {
            return ['ok' => false, 'changed' => $changed, 'name' => $name, 'email' => $email, 'output' => trim($combined_output), 'message' => 'Konnte git user.name nicht setzen.'];
        }
        $name = $fallback_name;
        $changed = true;
    }

    if ($email === '') {
        $set_email = ahx_run_git_cmd($git_bin, $dir, 'config user.email ' . escapeshellarg($fallback_email), $timeout, false);
        $combined_output .= trim((string)($set_email['output'] ?? '')) . "\n";
        if (intval($set_email['exit'] ?? 1) !== 0) {
            return ['ok' => false, 'changed' => $changed, 'name' => $name, 'email' => $email, 'output' => trim($combined_output), 'message' => 'Konnte git user.email nicht setzen.'];
        }
        $email = $fallback_email;
        $changed = true;
    }

    ahx_wp_github_log_debug('git identity configured name=' . $name . ' email=' . $email . ' changed=' . ($changed ? '1' : '0'));
    return ['ok' => true, 'changed' => $changed, 'name' => $name, 'email' => $email, 'output' => trim($combined_output)];
}

function ahx_wp_github_extract_conflict_files($output) {
    $files = [];
    $text = (string)$output;
    if ($text === '') {
        return $files;
    }

    if (preg_match_all('/CONFLICT \([^\)]*\): .* in ([^\r\n]+)/i', $text, $matches)) {
        foreach (($matches[1] ?? []) as $file) {
            $file = trim((string)$file);
            if ($file !== '') {
                $files[] = $file;
            }
        }
    }

    return array_values(array_unique($files));
}

function ahx_wp_github_finalize_rebase_failure($git_bin, $dir, &$resp, $rebase_output, $context_message) {
    $resp['rebase_output'] = (string)$rebase_output;

    $conflict_files = ahx_wp_github_extract_conflict_files($rebase_output);
    if (!empty($conflict_files)) {
        $resp['rebase_conflicts'] = $conflict_files;
    }

    $abort_res = ahx_run_git_cmd($git_bin, $dir, 'rebase --abort', 25, false);
    $resp['rebase_abort_output'] = (string)($abort_res['output'] ?? '');
    $resp['rebase_abort_success'] = intval($abort_res['exit'] ?? 1) === 0;

    $msg = (string)$context_message;
    if (!empty($conflict_files)) {
        $msg .= ' Konflikte in: ' . implode(', ', array_slice($conflict_files, 0, 5));
    }
    if (!$resp['rebase_abort_success']) {
        $msg .= ' Zusätzlich konnte rebase --abort nicht sauber ausgeführt werden.';
    }

    $resp['push_output'] = $resp['rebase_output'];
    $resp['message'] = $msg;
    $resp['success'] = false;

    ahx_wp_github_log_debug(
        'rebase failure handled: context=' . $context_message
        . ' conflicts=' . (!empty($conflict_files) ? implode(', ', $conflict_files) : 'none')
        . ' abort_exit=' . intval($abort_res['exit'] ?? 1)
    );
}

function ahx_wp_github_try_force_with_lease_after_rebase_conflict($git_bin, $dir, $branch, $has_upstream, &$resp) {
    $branch = trim((string)$branch);
    if ($branch === '' || $branch === 'HEAD') {
        return false;
    }

    $push_cmd = $has_upstream
        ? 'push --force-with-lease'
        : ('push --force-with-lease --set-upstream origin ' . escapeshellarg($branch));

    ahx_wp_github_log_debug('force-with-lease fallback start cmd=' . $push_cmd);
    $res_force = ahx_run_git_cmd($git_bin, $dir, $push_cmd, 45, true);
    $resp['force_push_used'] = true;
    $resp['force_push_output'] = (string)($res_force['output'] ?? '');
    $ok = intval($res_force['exit'] ?? 1) === 0;

    if ($ok) {
        $resp['success'] = true;
        $resp['push_output'] = $resp['force_push_output'];
        $resp['message'] = 'Rebase-Konflikt erkannt; Sync wurde mit force-with-lease durchgeführt.';
        ahx_wp_github_log_debug('force-with-lease fallback success');
        return true;
    }

    $excerpt = trim(mb_substr($resp['force_push_output'], 0, 800));
    $resp['success'] = false;
    $resp['message'] = 'Rebase-Konflikt und Force-with-lease fehlgeschlagen.' . ($excerpt !== '' ? ' ' . $excerpt : '');
    ahx_wp_github_log_debug('force-with-lease fallback failed output=' . mb_substr($resp['force_push_output'], 0, 1500));
    return false;
}

function ahx_prepare_empty_dirs_for_git($git_bin, $dir, $timeout = 20) {
    $created = [];
    $root = realpath($dir);
    if ($root === false || !is_dir($root)) {
        return $created;
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

        $ignore_res = ahx_run_git_cmd($git_bin, $root, 'check-ignore -q -- ' . escapeshellarg($rel . '/'), $timeout, false);
        if (intval($ignore_res['exit'] ?? 1) === 0) {
            continue;
        }

        $placeholder = $abs . DIRECTORY_SEPARATOR . '.gitkeep';
        if (!file_exists($placeholder)) {
            if (@file_put_contents($placeholder, "") !== false) {
                $created[] = $rel . '/.gitkeep';
            }
        }
    }

    return $created;
}

function ahx_wp_github_parse_owner_repo($remote_url) {
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

function ahx_wp_github_normalize_version_tag($tag) {
    $tag = trim((string)$tag);
    if ($tag === '') {
        return '';
    }

    if (!preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/i', $tag, $m)) {
        return '';
    }

    return 'v' . intval($m[1]) . '.' . intval($m[2]) . '.' . intval($m[3]);
}

function ahx_wp_github_compare_version_tags($tag_a, $tag_b) {
    $a = ahx_wp_github_normalize_version_tag($tag_a);
    $b = ahx_wp_github_normalize_version_tag($tag_b);

    if ($a === '' && $b === '') {
        return 0;
    }
    if ($a === '') {
        return -1;
    }
    if ($b === '') {
        return 1;
    }

    preg_match('/^v(\d+)\.(\d+)\.(\d+)$/i', $a, $ma);
    preg_match('/^v(\d+)\.(\d+)\.(\d+)$/i', $b, $mb);

    $va = [intval($ma[1] ?? 0), intval($ma[2] ?? 0), intval($ma[3] ?? 0)];
    $vb = [intval($mb[1] ?? 0), intval($mb[2] ?? 0), intval($mb[3] ?? 0)];

    for ($i = 0; $i < 3; $i++) {
        if ($va[$i] < $vb[$i]) return -1;
        if ($va[$i] > $vb[$i]) return 1;
    }
    return 0;
}

function ahx_wp_github_ensure_release_for_version($remote_url, $branch, $requested_version) {
    $result = [
        'success' => false,
        'created' => false,
        'exists' => false,
        'version' => '',
        'output' => '',
    ];

    $token = trim((string)get_option('ahx_wp_main_github_token', ''));
    if ($token === '') {
        $result['output'] = 'GitHub-Token fehlt: Release kann nicht erstellt werden.';
        return $result;
    }

    list($owner, $repo) = ahx_wp_github_parse_owner_repo($remote_url);
    if ($owner === '' || $repo === '') {
        $result['output'] = 'Remote-URL konnte nicht als GitHub owner/repo erkannt werden.';
        return $result;
    }

    $version = ahx_wp_github_normalize_version_tag($requested_version);
    if ($version === '') {
        $result['output'] = 'Ungültige Version für Release: ' . (string)$requested_version;
        return $result;
    }

    $api_base = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'User-Agent' => 'AHX WP GitHub',
        'Accept' => 'application/vnd.github+json',
    ];

    $tags_url = $api_base . '/tags?per_page=100';
    $tags_res = wp_remote_get($tags_url, ['headers' => $headers, 'timeout' => 25]);
    if (!is_wp_error($tags_res) && intval(wp_remote_retrieve_response_code($tags_res)) === 200) {
        $tags_body = json_decode((string)wp_remote_retrieve_body($tags_res), true);
        if (is_array($tags_body)) {
            foreach ($tags_body as $tag_entry) {
                $tag_name = ahx_wp_github_normalize_version_tag($tag_entry['name'] ?? '');
                if ($tag_name === '') {
                    continue;
                }
                if (ahx_wp_github_compare_version_tags($tag_name, $version) > 0) {
                    $version = $tag_name;
                }
            }
        }
    }

    $result['version'] = $version;

    $release_get_url = $api_base . '/releases/tags/' . rawurlencode($version);
    $release_get_res = wp_remote_get($release_get_url, ['headers' => $headers, 'timeout' => 25]);
    if (!is_wp_error($release_get_res) && intval(wp_remote_retrieve_response_code($release_get_res)) === 200) {
        $result['success'] = true;
        $result['exists'] = true;
        $result['output'] = 'Release existiert bereits für ' . $version . '.';
        return $result;
    }

    $branch = trim((string)$branch);
    if ($branch === '' || $branch === 'HEAD') {
        $branch = 'main';
    }

    $create_payload = [
        'tag_name' => $version,
        'target_commitish' => $branch,
        'name' => $version,
        'body' => 'Automatisch erstellt durch AHX WP GitHub.',
        'draft' => false,
        'prerelease' => false,
        'generate_release_notes' => false,
    ];

    $create_url = $api_base . '/releases';
    $create_res = wp_remote_post($create_url, [
        'headers' => array_merge($headers, ['Content-Type' => 'application/json']),
        'body' => wp_json_encode($create_payload),
        'timeout' => 30,
    ]);

    if (is_wp_error($create_res)) {
        $result['output'] = 'Release-Erstellung fehlgeschlagen: ' . $create_res->get_error_message();
        return $result;
    }

    $create_code = intval(wp_remote_retrieve_response_code($create_res));
    $create_body = (string)wp_remote_retrieve_body($create_res);

    if ($create_code >= 200 && $create_code < 300) {
        $result['success'] = true;
        $result['created'] = true;
        $result['output'] = 'Release erstellt: ' . $version;
        return $result;
    }

    if ($create_code === 422 && stripos($create_body, 'already_exists') !== false) {
        $result['success'] = true;
        $result['exists'] = true;
        $result['output'] = 'Release existiert bereits für ' . $version . '.';
        return $result;
    }

    $result['output'] = 'Release-Erstellung fehlgeschlagen (HTTP ' . $create_code . '): ' . mb_substr($create_body, 0, 1000);
    return $result;
}

// Minimal GitHub API helpers (used by AJAX handler)
function ahx_github_api_put_file($api_base, $path, $branch, $message, $headers, $dir) {
    $out = '';
    $full = $dir . DIRECTORY_SEPARATOR . $path;
    if (!file_exists($full)) {
        return ['exit' => 1, 'output' => "Local file not found: {$path}\n", 'file' => $path, 'status' => 'missing'];
    }
    $content = file_get_contents($full);
    $content_b64 = base64_encode($content);
    $url = $api_base . implode('/', array_map('rawurlencode', explode('/', $path))) . '?ref=' . rawurlencode($branch);
    $resp = wp_remote_get($url, ['headers' => $headers, 'timeout' => 20]);
    $sha = null; $exists = false;
    if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($body['sha'])) { $sha = $body['sha']; $exists = true; }
    }
    $body = ['message' => $message, 'content' => $content_b64, 'branch' => $branch];
    if ($sha) $body['sha'] = $sha;
    $put_url = $api_base . implode('/', array_map('rawurlencode', explode('/', $path)));
    $args = ['headers' => array_merge($headers, ['Content-Type' => 'application/json']), 'body' => json_encode($body), 'timeout' => 30];
    $res = wp_remote_request($put_url, array_merge($args, ['method' => 'PUT']));
    if (is_wp_error($res)) {
        $out .= "PUT {$path} failed: " . $res->get_error_message() . "\n";
        return ['exit' => 1, 'output' => $out, 'file' => $path, 'status' => 'error'];
    }
    $code = wp_remote_retrieve_response_code($res);
    $out .= "PUT {$path}: HTTP {$code}\n";
    if ($code >= 200 && $code < 300) {
        $status = $exists ? 'updated' : 'created';
        return ['exit' => 0, 'output' => $out, 'file' => $path, 'status' => $status];
    }
    return ['exit' => 1, 'output' => $out . wp_remote_retrieve_body($res) . "\n", 'file' => $path, 'status' => 'error'];
}

function ahx_github_api_delete_file($api_base, $path, $branch, $message, $headers, $dir) {
    $out = '';
    $url = $api_base . implode('/', array_map('rawurlencode', explode('/', $path))) . '?ref=' . rawurlencode($branch);
    $resp = wp_remote_get($url, ['headers' => $headers, 'timeout' => 20]);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        $out .= "DELETE {$path}: file not found on remote or error\n";
        return ['exit' => 0, 'output' => $out, 'file' => $path, 'status' => 'not_found'];
    }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $sha = $body['sha'] ?? null;
    if (!$sha) {
        $out .= "DELETE {$path}: could not determine sha\n";
        return ['exit' => 1, 'output' => $out, 'file' => $path, 'status' => 'error'];
    }
    $del_body = ['message' => $message, 'sha' => $sha, 'branch' => $branch];
    $del_url = $api_base . implode('/', array_map('rawurlencode', explode('/', $path)));
    $args = ['headers' => array_merge($headers, ['Content-Type' => 'application/json']), 'body' => json_encode($del_body), 'timeout' => 30];
    $res = wp_remote_request($del_url, array_merge($args, ['method' => 'DELETE']));
    if (is_wp_error($res)) {
        $out .= "DELETE {$path} failed: " . $res->get_error_message() . "\n";
        return ['exit' => 1, 'output' => $out, 'file' => $path, 'status' => 'error'];
    }
    $code = wp_remote_retrieve_response_code($res);
    $out .= "DELETE {$path}: HTTP {$code}\n";
    if ($code >= 200 && $code < 300) return ['exit' => 0, 'output' => $out, 'file' => $path, 'status' => 'deleted'];
    return ['exit' => 1, 'output' => $out . wp_remote_retrieve_body($res) . "\n", 'file' => $path, 'status' => 'error'];
}

function ahx_github_api_push($dir, $remote_url, $branch, $files, $commit_msg, $token) {
    $out = '';
    $exit = 0;
    $per_file = [];
    list($owner, $repo) = ahx_wp_github_parse_owner_repo($remote_url);
    if ($owner === '' || $repo === '') {
        $out .= "Could not parse owner/repo from remote URL: {$remote_url}\n";
        return ['exit' => 2, 'output' => $out];
    }
    $api_base = "https://api.github.com/repos/{$owner}/{$repo}/contents/";
    $headers = ['Authorization' => 'Bearer ' . $token, 'User-Agent' => 'WordPress GH Push', 'Accept' => 'application/vnd.github.v3+json'];
    foreach ($files as $f) {
        $status = trim($f['status']);
        $file = $f['file'];
        if (strpos($file, '->') !== false) {
            $parts = array_map('trim', explode('->', $file));
            if (count($parts) === 2) {
                $old = $parts[0]; $new = $parts[1];
                $res_del = ahx_github_api_delete_file($api_base, $old, $branch, $commit_msg, $headers, $dir);
                $out .= $res_del['output'];
                if ($res_del['exit'] !== 0) $exit = max($exit, $res_del['exit']);
                $res_add = ahx_github_api_put_file($api_base, $new, $branch, $commit_msg, $headers, $dir);
                $out .= $res_add['output'];
                if ($res_add['exit'] !== 0) $exit = max($exit, $res_add['exit']);
                continue;
            }
        }
        if ($status === 'D') {
            $res = ahx_github_api_delete_file($api_base, $file, $branch, $commit_msg, $headers, $dir);
            $out .= $res['output'];
            $per_file[$file] = $res;
            if ($res['exit'] !== 0) $exit = max($exit, $res['exit']);
            continue;
        }
        $res = ahx_github_api_put_file($api_base, $file, $branch, $commit_msg, $headers, $dir);
        $out .= $res['output'];
        $per_file[$file] = $res;
        if ($res['exit'] !== 0) $exit = max($exit, $res['exit']);
    }
    return ['exit' => $exit, 'output' => $out, 'files' => $per_file];
}

// Main entry: process commit request and return structured array
function ahx_wp_github_process_commit_request($dir, $post_data) {
    ahx_wp_github_log_debug('commit.request start dir=' . (string)$dir . ' action=' . (string)($post_data['commit_action'] ?? '') . ' bump=' . (string)($post_data['version_bump'] ?? 'none'));

    $resp = ['success' => false, 'message' => '', 'fetch_output' => '', 'add_output' => '', 'commit_output' => '', 'push_output' => '', 'push_details' => []];
    if (!$dir || !is_dir($dir)) {
        $resp['message'] = 'Invalid dir';
        ahx_wp_github_log_debug('commit.request abort: invalid dir');
        return $resp;
    }

    $commit_msg = trim($post_data['commit_message'] ?? '');
    $action = $post_data['commit_action'] ?? 'commit';
    $remote_url = '';
    $branch = '';
    $allow_force_with_lease_on_rebase_conflict = !empty($post_data['allow_force_with_lease_on_rebase_conflict'])
        && in_array((string)$post_data['allow_force_with_lease_on_rebase_conflict'], ['1', 'true', 'on', 'yes'], true);
    ahx_wp_github_log_debug('commit.request option allow_force_with_lease_on_rebase_conflict=' . ($allow_force_with_lease_on_rebase_conflict ? '1' : '0'));
    if ($commit_msg === '') {
        $resp['message'] = 'Empty commit message';
        ahx_wp_github_log_debug('commit.request abort: empty commit message');
        return $resp;
    }

    $git_bin_for_scan = ahx_find_git_binary();
    $created_placeholders = ahx_prepare_empty_dirs_for_git($git_bin_for_scan, $dir, 20);
    if (!empty($created_placeholders)) {
        $resp['empty_dir_placeholders_created'] = $created_placeholders;
        ahx_wp_github_log_debug('empty dir placeholders created: ' . implode(', ', $created_placeholders));
    }

    // Determine changed files (simple porcelain parsing)
    $status_res = ahx_run_git_cmd($git_bin_for_scan, $dir, 'status --porcelain', 20, false);
    $changes = trim((string)($status_res['output'] ?? ''));
    $files = [];
    if ($changes) {
        $lines = preg_split('/\r\n|\r|\n/', $changes);
        foreach ($lines as $line) {
            if (strlen(trim($line)) < 1) continue;
            $status = substr($line, 0, 2);
            $file = ltrim(substr($line, 2));
            if ($file === '') continue;
            $files[] = ['status' => $status, 'file' => $file];
        }
    }
    ahx_wp_github_log_debug('detected changed entries: ' . count($files));

    if (count($files) === 0) {
        $resp['no_changes'] = true;
        $resp['message'] = 'Keine Änderungen vorhanden. Commit/Version-Bump wurde nicht ausgeführt.';
        ahx_wp_github_log_debug('commit.request abort: no changes detected before version bump');
        return $resp;
    }

    // Version bump
    $main_plugin_file = '';
    foreach ($files as $f) { if (preg_match('/^([^\/]+)\.php$/i', $f['file'], $mm)) { $main_plugin_file = $f['file']; break; } }
    if (!$main_plugin_file) { $plugin_dir = basename($dir); $main_plugin_file = $plugin_dir . '.php'; }
    $main_plugin_path = $dir . DIRECTORY_SEPARATOR . $main_plugin_file;
    $header_version = '';
    if (file_exists($main_plugin_path)) {
        $header = file_get_contents($main_plugin_path);
        if (preg_match('/Version:\s*v?(\d+\.\d+\.\d+)/mi', $header, $m2)) { $header_version = $m2[1]; }
    }
    if (!$header_version) $header_version = '1.0.0';
    list($major, $minor, $patch) = array_pad(explode('.', $header_version), 3, 0);
    $v_patch = 'v' . $major . '.' . $minor . '.' . ((int)$patch + 1);
    $v_minor = 'v' . $major . '.' . ((int)$minor + 1) . '.0';
    $v_major = 'v' . ((int)$major + 1) . '.0.0';
    $bump = $post_data['version_bump'] ?? 'none';
    $new_version = 'v' . $header_version;
    if ($bump === 'patch') $new_version = $v_patch;
    elseif ($bump === 'minor') $new_version = $v_minor;
    elseif ($bump === 'major') $new_version = $v_major;
    if (file_exists($main_plugin_path)) {
        $main_file_contents = file_get_contents($main_plugin_path);
        $main_file_contents = preg_replace('/(Version:\s*)v?(\d+\.\d+\.\d+)/i', '$1' . $new_version, $main_file_contents, 1);
        $main_file_contents = preg_replace(
            '/(define\(\s*[\'\"][A-Z0-9_]*VERSION[A-Z0-9_]*[\'\"]\s*,\s*[\'\"])v?(\d+\.\d+\.\d+)([\'\"]\s*\)\s*;)/i',
            '$1' . $new_version . '$3',
            $main_file_contents
        );
        file_put_contents($main_plugin_path, $main_file_contents);
    }
    $version_txt = $dir . DIRECTORY_SEPARATOR . 'version.txt';
    if (file_exists($version_txt)) file_put_contents($version_txt, $new_version . "\n");
    ahx_wp_github_log_debug('version bump result: old=' . (string)$header_version . ' new=' . (string)$new_version . ' mode=' . (string)$bump);

    // Decide API vs git
    $shell_exec_disabled = false;
    if (!function_exists('proc_open')) $shell_exec_disabled = true;
    $used_api = false;
    if (!$shell_exec_disabled) {
        $git_bin = ahx_find_git_binary();
        // Check repository record to see if we should run global safe.directory for this repo
        global $wpdb;
        $table = $wpdb->prefix . 'ahx_wp_github';
        $safe_flag = $wpdb->get_var($wpdb->prepare("SELECT safe_directory FROM $table WHERE dir_path = %s", $dir));
        if (intval($safe_flag) === 1) {
            $safe_res = ahx_run_git_cmd($git_bin, $dir, 'config --global --add safe.directory ' . escapeshellarg($dir), 15, false);
            $resp['safe_directory_configured'] = true;
            $resp['safe_directory_cmd_output'] = trim((string)($safe_res['output'] ?? ''));
            $resp['safe_directory_cmd_exit'] = intval($safe_res['exit'] ?? 1);
            ahx_wp_github_log_debug('safe.directory configured exit=' . intval($resp['safe_directory_cmd_exit']) . ' output=' . mb_substr((string)$resp['safe_directory_cmd_output'], 0, 2000));
        } else {
            $resp['safe_directory_configured'] = false;
            ahx_wp_github_log_debug('safe.directory not enabled for repo');
        }
        $gh_token = get_option('ahx_wp_main_github_token', '');
        $prefer_api = get_option('ahx_wp_github_prefer_api', '1');
        $prefer_api_enabled = ($prefer_api === '1' || $prefer_api === 1 || $prefer_api === true);

        $remote_res = ahx_run_git_cmd($git_bin, $dir, 'remote get-url origin', 20, false);
        $remote_url = (intval($remote_res['exit'] ?? 1) === 0) ? trim((string)($remote_res['output'] ?? '')) : '';

        $branch_res = ahx_run_git_cmd($git_bin, $dir, 'rev-parse --abbrev-ref HEAD', 20, false);
        $branch = (intval($branch_res['exit'] ?? 1) === 0) ? trim((string)($branch_res['output'] ?? '')) : '';

        if ($prefer_api_enabled && !empty($gh_token) && !empty($remote_url)) {
            ahx_wp_github_log_debug('commit mode: github-api push');
            $push_result = ahx_github_api_push($dir, $remote_url, $branch, $files, $commit_msg, $gh_token);
            $resp['push_output'] = $push_result['output'] ?? '';
            $resp['push_details'] = $push_result['files'] ?? [];
            $resp['success'] = intval($push_result['exit']) === 0;
            $resp['sync_status'] = $resp['success'] ? 'remote_synced_via_api' : 'remote_sync_failed_via_api';
            $resp['history_sync'] = 'not_guaranteed_in_api_mode';
            $used_api = true;

            // If API push succeeded, attempt to create a local git commit so the working tree matches the remote
            if (!$shell_exec_disabled) {
                $git_for_local = ahx_find_git_binary();
                $identity_res_local = ahx_wp_github_ensure_git_identity($git_for_local, $dir, 20);
                $resp['identity_output'] = trim((string)($identity_res_local['output'] ?? ''));
                if (!$identity_res_local['ok']) {
                    $resp['local_commit_success'] = false;
                    $resp['local_commit_message'] = 'Git-Identität (Name/E-Mail) konnte nicht gesetzt werden.';
                    $resp['message'] = $resp['local_commit_message'];
                    $resp['success'] = false;
                    ahx_wp_github_log_debug('local commit aborted: identity setup failed output=' . mb_substr($resp['identity_output'], 0, 2000));
                    $resp['new_version'] = $new_version;
                    return $resp;
                }
                $add_cmd = escapeshellarg($git_for_local) . ' -c safe.directory=' . escapeshellarg($dir) . ' add .';
                $res_add_local = ahx_run_cmd_with_timeout($add_cmd, $dir, $_ENV, 20);
                $resp['local_add_output'] = $res_add_local['output'] ?? '';
                $commit_cmd_local = escapeshellarg($git_for_local) . ' -c safe.directory=' . escapeshellarg($dir) . ' commit -m ' . escapeshellarg($commit_msg);
                $res_commit_local = ahx_run_cmd_with_timeout($commit_cmd_local, $dir, $_ENV, 20);
                $resp['local_commit_output'] = $res_commit_local['output'] ?? '';
                $resp['local_commit_success'] = intval($res_commit_local['exit']) === 0;
                ahx_wp_github_log_debug('local commit exit=' . intval($res_commit_local['exit']) . ' output=' . mb_substr((string)$resp['local_commit_output'], 0, 3000));

                // No local fetch here: remote sync is already done via API and fetch may fail in webserver runtime
                $resp['local_fetch_output'] = 'Skipped (API sync already performed)';

                $branch_name_res = ahx_run_git_cmd($git_for_local, $dir, 'rev-parse --abbrev-ref HEAD', 20, false);
                $branch_name = $branch ?: ((intval($branch_name_res['exit'] ?? 1) === 0) ? trim((string)($branch_name_res['output'] ?? '')) : '');
                if ($branch_name) {
                    $up_cmd = escapeshellarg($git_for_local) . ' -c safe.directory=' . escapeshellarg($dir) . ' branch --set-upstream-to=' . escapeshellarg('origin/' . $branch_name) . ' ' . escapeshellarg($branch_name);
                    $res_up = ahx_run_cmd_with_timeout($up_cmd, $dir, $_ENV, 10);
                    $resp['set_upstream_output'] = $res_up['output'] ?? '';
                    $resp['set_upstream_success'] = intval($res_up['exit']) === 0;
                    ahx_wp_github_log_debug('set-upstream exit=' . intval($res_up['exit']) . ' output=' . mb_substr((string)$resp['set_upstream_output'], 0, 3000));
                }
            } else {
                $resp['local_commit_message'] = 'Server configuration prevents running git commands.';
                ahx_wp_github_log_debug('local history sync skipped: proc_open not available');
            }
        } else {
            ahx_wp_github_log_debug('commit mode: local git');
            $res_fetch = ahx_run_git_cmd($git_bin, $dir, 'fetch --all', 20, true);
            $resp['fetch_output'] = $res_fetch['output'] ?? '';
            $res_add = ahx_run_git_cmd($git_bin, $dir, 'add .', 20, false);
            $resp['add_output'] = $res_add['output'] ?? '';
            $identity_res = ahx_wp_github_ensure_git_identity($git_bin, $dir, 20);
            $resp['identity_output'] = trim((string)($identity_res['output'] ?? ''));
            if (!$identity_res['ok']) {
                $resp['message'] = 'Git-Identität (Name/E-Mail) konnte nicht gesetzt werden.';
                $resp['commit_output'] = $resp['identity_output'];
                $resp['success'] = false;
                $resp['new_version'] = $new_version;
                ahx_wp_github_log_debug('commit aborted: identity setup failed output=' . mb_substr($resp['identity_output'], 0, 2000));
                return $resp;
            }
            $commit_cmd = 'commit -m ' . escapeshellarg($commit_msg);
            $res_commit = ahx_run_git_cmd($git_bin, $dir, $commit_cmd, 20, false);
            $resp['commit_output'] = $res_commit['output'] ?? '';
            if (intval($res_commit['exit'] ?? 1) !== 0) {
                $resp['success'] = false;
                $resp['message'] = 'Commit fehlgeschlagen.';
                $resp['new_version'] = $new_version;
                ahx_wp_github_log_debug('commit failed before sync; aborting action');
                return $resp;
            }
            if ($action === 'commit_sync') {
                $git_for_up = ahx_find_git_binary();

                if ($branch === '' || $branch === 'HEAD') {
                    $branch_retry_res = ahx_run_git_cmd($git_for_up, $dir, 'rev-parse --abbrev-ref HEAD', 20, false);
                    $branch = (intval($branch_retry_res['exit'] ?? 1) === 0) ? trim((string)($branch_retry_res['output'] ?? '')) : '';
                }

                if ($branch === '' || $branch === 'HEAD') {
                    $resp['push_output'] = 'Konnte aktuellen Branch nicht bestimmen; Sync abgebrochen.';
                    $resp['success'] = false;
                    ahx_wp_github_log_debug('push aborted: branch unresolved');
                } else {
                    $upstream_res = ahx_run_git_cmd($git_for_up, $dir, 'rev-parse --abbrev-ref --symbolic-full-name @{u}', 20, false);
                    $has_upstream = intval($upstream_res['exit'] ?? 1) === 0 && trim((string)($upstream_res['output'] ?? '')) !== '';
                    ahx_wp_github_log_debug('commit_sync upstream=' . ($has_upstream ? trim((string)$upstream_res['output']) : 'none'));
                    $can_push = true;

                    if ($has_upstream) {
                        $ahead_behind_res = ahx_run_git_cmd($git_for_up, $dir, 'rev-list --left-right --count @{u}...HEAD', 20, false);
                        $behind = 0;
                        $ahead = 0;
                        if (intval($ahead_behind_res['exit'] ?? 1) === 0) {
                            $parts = preg_split('/\s+/', trim((string)($ahead_behind_res['output'] ?? '')));
                            $behind = isset($parts[0]) ? intval($parts[0]) : 0;
                            $ahead = isset($parts[1]) ? intval($parts[1]) : 0;
                        }
                        ahx_wp_github_log_debug('commit_sync ahead=' . intval($ahead) . ' behind=' . intval($behind));

                        if ($behind > 0) {
                            $rebase_res = ahx_run_git_cmd($git_for_up, $dir, 'pull --rebase --autostash origin ' . escapeshellarg($branch), 60, true);
                            if (intval($rebase_res['exit'] ?? 1) !== 0) {
                                ahx_wp_github_finalize_rebase_failure(
                                    $git_for_up,
                                    $dir,
                                    $resp,
                                    (string)($rebase_res['output'] ?? ''),
                                    'Sync fehlgeschlagen: Rebase mit Remote konnte nicht durchgeführt werden.'
                                );
                                if ($allow_force_with_lease_on_rebase_conflict) {
                                    ahx_wp_github_try_force_with_lease_after_rebase_conflict($git_for_up, $dir, $branch, true, $resp);
                                }
                                $can_push = false;
                                ahx_wp_github_log_debug('commit_sync rebase failed; push skipped');
                            }
                        }
                    }

                    // Kein lokales Upstream: prüfen, ob der Remote-Branch bereits existiert.
                    // Falls ja, vor dem ersten set-upstream Push rebasen, um non-fast-forward zu vermeiden.
                    if (!$has_upstream && $can_push) {
                        $remote_branch_res = ahx_run_git_cmd(
                            $git_for_up,
                            $dir,
                            'ls-remote --heads origin ' . escapeshellarg($branch),
                            25,
                            true
                        );
                        $remote_branch_exists = intval($remote_branch_res['exit'] ?? 1) === 0
                            && trim((string)($remote_branch_res['output'] ?? '')) !== '';
                        ahx_wp_github_log_debug('commit_sync remote branch exists=' . ($remote_branch_exists ? '1' : '0') . ' branch=' . $branch);

                        if ($remote_branch_exists) {
                            $rebase_res = ahx_run_git_cmd($git_for_up, $dir, 'pull --rebase --autostash origin ' . escapeshellarg($branch), 60, true);
                            if (intval($rebase_res['exit'] ?? 1) !== 0) {
                                ahx_wp_github_finalize_rebase_failure(
                                    $git_for_up,
                                    $dir,
                                    $resp,
                                    (string)($rebase_res['output'] ?? ''),
                                    'Sync fehlgeschlagen: Rebase mit bestehendem Remote-Branch konnte nicht durchgeführt werden.'
                                );
                                if ($allow_force_with_lease_on_rebase_conflict) {
                                    ahx_wp_github_try_force_with_lease_after_rebase_conflict($git_for_up, $dir, $branch, false, $resp);
                                }
                                $can_push = false;
                                ahx_wp_github_log_debug('commit_sync rebase failed for existing remote branch; push skipped');
                            }
                        }
                    }

                    if ($can_push) {
                        if ($has_upstream) {
                            $push_cmd = 'push --force-with-lease';
                        } else {
                            $push_cmd = 'push --set-upstream origin ' . escapeshellarg($branch);
                        }
                        $res_push = ahx_run_git_cmd($git_for_up, $dir, $push_cmd, 45, true);
                        $resp['push_output'] = $res_push['output'] ?? '';
                        $resp['success'] = intval($res_push['exit']) === 0;
                        if (!$resp['success'] && empty($resp['message'])) {
                            $push_excerpt = trim(mb_substr((string)$resp['push_output'], 0, 600));
                            $resp['message'] = 'Push zum Remote fehlgeschlagen.' . ($push_excerpt !== '' ? ' ' . $push_excerpt : '');
                        }
                    }
                }
            } else {
                $resp['success'] = true;
            }
        }
    } else {
        $resp['message'] = 'Server configuration prevents running git commands.';
        ahx_wp_github_log_debug('commit failed: proc_open unavailable');
    }
    if ($action === 'commit_sync' && !empty($resp['success'])) {
        $release_result = ahx_wp_github_ensure_release_for_version($remote_url, $branch, $new_version);
        $resp['release_success'] = !empty($release_result['success']);
        $resp['release_created'] = !empty($release_result['created']);
        $resp['release_exists'] = !empty($release_result['exists']);
        $resp['release_version'] = (string)($release_result['version'] ?? '');
        $resp['release_output'] = (string)($release_result['output'] ?? '');

        if (!empty($release_result['success'])) {
            if (!empty($release_result['created'])) {
                ahx_wp_github_log_debug('release created tag=' . (string)$resp['release_version']);
            } else {
                ahx_wp_github_log_debug('release already exists tag=' . (string)$resp['release_version']);
            }
        } else {
            $resp['success'] = false;
            $resp['message'] = 'Sync erfolgreich, aber Release-Erstellung fehlgeschlagen. ' . (string)$resp['release_output'];
            ahx_wp_github_log_debug('release creation failed output=' . mb_substr((string)$resp['release_output'], 0, 2000));
        }
    }

    $resp['new_version'] = $new_version;
    ahx_wp_github_log_debug('commit.request done success=' . (!empty($resp['success']) ? '1' : '0') . ' message=' . (string)($resp['message'] ?? '') . ' new_version=' . (string)$new_version . ' used_api=' . ($used_api ? '1' : '0'));
    return $resp;
}
