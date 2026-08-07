<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/templates/adminUI.php';

add_action('admin_init', 'fsr_etit_updates_register_settings');
add_action('admin_post_fsr_manual_install', 'fsr_etit_updates_manual_install');
add_action('admin_post_fsr_clear_update_cache', 'fsr_etit_updates_clear_cache');
add_filter('pre_set_site_transient_update_plugins', 'fsr_etit_updates_check_for_update', 20);
add_filter('plugins_api', 'fsr_etit_updates_plugin_information', 10, 3);
add_filter('upgrader_source_selection', 'fsr_etit_updates_fix_source_folder', 10, 4);
add_action('upgrader_process_complete', 'fsr_etit_updates_after_update', 10, 2);

function fsr_etit_updates_defaults(): array {
    return [
        'github_repo' => 'Citro111/FSR-Plugin',
        'branch'      => 'main',
        'mode'        => 'release',
        'logging'     => false,
    ];
}

function fsr_etit_updates_register_settings(): void {
    register_setting(
        'fsr_etit_update_settings',
        FSR_ETIT_OPTION_UPDATE_SETTINGS,
        [
            'type'              => 'array',
            'sanitize_callback' => 'fsr_etit_updates_sanitize_settings',
            'default'           => fsr_etit_updates_defaults(),
        ]
    );
}

function fsr_etit_updates_settings(): array {
    $settings = get_option(FSR_ETIT_OPTION_UPDATE_SETTINGS, []);
    $defaults = fsr_etit_updates_defaults();
    $settings = wp_parse_args(is_array($settings) ? $settings : [], $defaults);

    $repo = trim(fsr_etit_scalar_string($settings['github_repo']));
    if (!preg_match('#^[A-Za-z0-9.-]+/[A-Za-z0-9._-]+$#', $repo)) {
        $repo = $defaults['github_repo'];
    }

    $branch = trim(fsr_etit_scalar_string($settings['branch']));
    if (
        strlen($branch) > 100 ||
        !preg_match('#^[A-Za-z0-9._/-]+$#', $branch) ||
        str_contains($branch, '..') ||
        str_starts_with($branch, '/') ||
        str_ends_with($branch, '/')
    ) {
        $branch = $defaults['branch'];
    }

    $mode = sanitize_key(fsr_etit_scalar_string($settings['mode']));
    return [
        'github_repo' => $repo,
        'branch'      => $branch,
        'mode'        => in_array($mode, ['release', 'branch'], true) ? $mode : $defaults['mode'],
        'logging'     => !empty($settings['logging']),
    ];
}

function fsr_etit_updates_sanitize_settings($input): array {
    $input = is_array($input) ? wp_unslash($input) : [];
    $current = fsr_etit_updates_settings();
    $repo = trim(fsr_etit_scalar_string($input['github_repo'] ?? ''));
    $branch = trim(fsr_etit_scalar_string($input['branch'] ?? 'main'));
    $mode = sanitize_key(fsr_etit_scalar_string($input['mode'] ?? 'release'));

    if (!preg_match('#^[A-Za-z0-9.-]+/[A-Za-z0-9._-]+$#', $repo)) {
        add_settings_error(
            FSR_ETIT_OPTION_UPDATE_SETTINGS,
            'fsr_etit_invalid_github_repo',
            'Das Repository muss das Format Benutzer/Repository haben. Der bisherige Wert wurde beibehalten.',
            'error'
        );
        $repo = $current['github_repo'];
    }

    if (
        strlen($branch) > 100 ||
        !preg_match('#^[A-Za-z0-9._/-]+$#', $branch) ||
        str_contains($branch, '..') ||
        str_starts_with($branch, '/') ||
        str_ends_with($branch, '/')
    ) {
        add_settings_error(
            FSR_ETIT_OPTION_UPDATE_SETTINGS,
            'fsr_etit_invalid_github_branch',
            'Der Branch-Name ist ungültig. Der bisherige Wert wurde beibehalten.',
            'error'
        );
        $branch = $current['branch'];
    }

    delete_transient('fsr_etit_updates_remote');
    return [
        'github_repo' => $repo,
        'branch'      => $branch,
        'mode'        => in_array($mode, ['release', 'branch'], true) ? $mode : 'release',
        'logging'     => !empty($input['logging']),
    ];
}

function fsr_etit_updates_github_request(array $settings) {
    [$owner, $repository] = explode('/', $settings['github_repo'], 2);
    $base = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repository);
    $url = $settings['mode'] === 'branch'
        ? $base . '/branches/' . rawurlencode($settings['branch'])
        : $base . '/releases/latest';

    $response = wp_safe_remote_get($url, [
        'headers' => [
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ],
        'user-agent'          => 'FSR-ETIT-Website-Tools/' . FSR_ETIT_VERSION,
        'timeout'             => 15,
        'redirection'         => 2,
        'limit_response_size' => MB_IN_BYTES,
    ]);
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        fsr_etit_log('GitHub-Updateabfrage fehlgeschlagen.');
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($data) ? $data : false;
}

function fsr_etit_updates_is_allowed_package_url($url): bool {
    $parts = wp_parse_url(fsr_etit_scalar_string($url));
    return
        is_array($parts) &&
        strtolower((string) ($parts['scheme'] ?? '')) === 'https' &&
        !isset($parts['user']) &&
        !isset($parts['pass']) &&
        (!isset($parts['port']) || (int) $parts['port'] === 443) &&
        in_array(
            strtolower((string) ($parts['host'] ?? '')),
            ['api.github.com', 'github.com', 'codeload.github.com'],
            true
        );
}

function fsr_etit_updates_get_remote(bool $force = false) {
    static $runtime_cache = null;
    if ($force) {
        $runtime_cache = null;
        delete_transient('fsr_etit_updates_remote');
    }
    if (is_array($runtime_cache)) {
        return $runtime_cache;
    }

    $cached = get_transient('fsr_etit_updates_remote');
    if (is_array($cached)) {
        return $runtime_cache = $cached;
    }

    $settings = fsr_etit_updates_settings();
    $data = fsr_etit_updates_github_request($settings);
    if (!is_array($data)) {
        return false;
    }

    if ($settings['mode'] === 'release') {
        $version = ltrim(fsr_etit_scalar_string($data['tag_name'] ?? ''), "vV \t\n\r\0\x0B");
        $download = fsr_etit_scalar_string($data['zipball_url'] ?? '');
        if (
            !preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][A-Za-z0-9.-]+)?$/', $version) ||
            !fsr_etit_updates_is_allowed_package_url($download)
        ) {
            return false;
        }
        $remote = [
            'version'        => $version,
            'download'       => $download,
            'commit_message' => sanitize_textarea_field(trim(
                fsr_etit_scalar_string($data['name'] ?? '') . "\n\n" . fsr_etit_scalar_string($data['body'] ?? '')
            )),
            'commit_sha'     => sanitize_text_field(fsr_etit_scalar_string($data['tag_name'] ?? $version)),
            'published_at'   => sanitize_text_field(fsr_etit_scalar_string($data['published_at'] ?? '')),
        ];
    } else {
        $commit = is_array($data['commit'] ?? null) ? $data['commit'] : [];
        $commit_data = is_array($commit['commit'] ?? null) ? $commit['commit'] : [];
        $committer = is_array($commit_data['committer'] ?? null) ? $commit_data['committer'] : [];
        $sha = sanitize_text_field(fsr_etit_scalar_string($commit['sha'] ?? ''));
        $date = sanitize_text_field(fsr_etit_scalar_string($committer['date'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40}$/i', $sha) || strtotime($date) === false) {
            return false;
        }
        $download = sprintf(
            'https://github.com/%s/archive/refs/heads/%s.zip',
            $settings['github_repo'],
            implode('/', array_map('rawurlencode', explode('/', $settings['branch'])))
        );
        $remote = [
            'version'        => FSR_ETIT_VERSION . '.' . gmdate('YmdHis', strtotime($date)),
            'download'       => $download,
            'commit_message' => sanitize_textarea_field(fsr_etit_scalar_string($commit_data['message'] ?? '')),
            'commit_sha'     => $sha,
            'published_at'   => $date,
        ];
    }

    if (!fsr_etit_updates_is_allowed_package_url($remote['download'])) {
        return false;
    }

    $remote['commit_message'] = substr($remote['commit_message'], 0, 5000);
    fsr_etit_updates_store_status('remote', $remote);
    set_transient('fsr_etit_updates_remote', $remote, HOUR_IN_SECONDS);
    return $runtime_cache = $remote;
}

function fsr_etit_updates_is_newer(array $remote): bool {
    $settings = fsr_etit_updates_settings();
    if ($settings['mode'] === 'branch') {
        return get_option('fsr_installed_commit', '') !== $remote['commit_sha'];
    }
    return version_compare($remote['version'], FSR_ETIT_VERSION, '>');
}

function fsr_etit_updates_build_update(array $remote): object {
    $settings = fsr_etit_updates_settings();
    return (object) [
        'id'           => 'https://github.com/' . $settings['github_repo'],
        'slug'         => dirname(plugin_basename(FSR_ETIT_FILE)),
        'plugin'       => plugin_basename(FSR_ETIT_FILE),
        'new_version'  => $remote['version'],
        'url'          => 'https://github.com/' . $settings['github_repo'],
        'package'      => $remote['download'],
        'tested'       => '7.0',
        'requires'     => '6.4',
        'requires_php' => '8.0',
    ];
}

function fsr_etit_updates_prepare_transient($transient): object {
    $transient = is_object($transient) ? $transient : new stdClass();
    $transient->response = is_array($transient->response ?? null) ? $transient->response : [];
    $transient->no_update = is_array($transient->no_update ?? null) ? $transient->no_update : [];
    $transient->checked = is_array($transient->checked ?? null) ? $transient->checked : [];
    return $transient;
}

function fsr_etit_updates_check_for_update($transient) {
    $transient = fsr_etit_updates_prepare_transient($transient);
    if ((defined('WP_INSTALLING') && WP_INSTALLING) || empty($transient->checked)) {
        return $transient;
    }

    $plugin_file = plugin_basename(FSR_ETIT_FILE);
    $remote = fsr_etit_updates_get_remote();
    if (!is_array($remote)) {
        return $transient;
    }

    $update = fsr_etit_updates_build_update($remote);
    if (fsr_etit_updates_is_newer($remote)) {
        $transient->response[$plugin_file] = $update;
        unset($transient->no_update[$plugin_file]);
    } else {
        $update->new_version = FSR_ETIT_VERSION;
        $transient->no_update[$plugin_file] = $update;
        unset($transient->response[$plugin_file]);
    }

    return $transient;
}

function fsr_etit_updates_set_notice(string $type, string $message): void {
    set_transient(
        'fsr_etit_update_notice_' . get_current_user_id(),
        ['type' => $type, 'message' => $message],
        MINUTE_IN_SECONDS
    );
}

function fsr_etit_updates_get_notice(): array {
    $key = 'fsr_etit_update_notice_' . get_current_user_id();
    $notice = get_transient($key);
    delete_transient($key);
    if (!is_array($notice)) {
        return [];
    }

    return [
        'type'    => sanitize_key(fsr_etit_scalar_string($notice['type'] ?? 'info')),
        'message' => sanitize_text_field(fsr_etit_scalar_string($notice['message'] ?? '')),
    ];
}

function fsr_etit_updates_redirect(): void {
    wp_safe_redirect(admin_url('admin.php?page=fsr-etit-settings'));
    exit;
}

function fsr_etit_updates_manual_install(): void {
    if (!current_user_can('update_plugins')) {
        wp_die(esc_html__('Du hast keine Berechtigung, Plugins zu aktualisieren.', 'fsr-etit-website-tools'));
    }
    check_admin_referer('fsr_manual_install');

    $remote = fsr_etit_updates_get_remote(true);
    if (!is_array($remote)) {
        fsr_etit_updates_set_notice('error', 'Die Update-Informationen konnten nicht geladen werden.');
        fsr_etit_updates_redirect();
    }
    if (!fsr_etit_updates_is_newer($remote)) {
        fsr_etit_updates_set_notice('info', 'Das Plugin ist bereits aktuell.');
        fsr_etit_updates_redirect();
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    $plugin_file = plugin_basename(FSR_ETIT_FILE);
    $was_active = is_plugin_active($plugin_file);
    $was_network_active = is_multisite() && is_plugin_active_for_network($plugin_file);
    $transient = fsr_etit_updates_prepare_transient(get_site_transient('update_plugins'));
    $transient->response[$plugin_file] = fsr_etit_updates_build_update($remote);
    set_site_transient('update_plugins', $transient);

    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->upgrade($plugin_file, ['clear_update_cache' => true]);
    if (is_wp_error($result) || $result === false) {
        $message = is_wp_error($result) ? $result->get_error_message() : 'Unbekannter Fehler beim Update.';
        fsr_etit_updates_set_notice('error', 'Update fehlgeschlagen: ' . $message);
        fsr_etit_updates_redirect();
    }

    $needs_reactivation = $was_network_active
        ? !is_plugin_active_for_network($plugin_file)
        : ($was_active && !is_plugin_active($plugin_file));
    if ($needs_reactivation) {
        $activation = activate_plugin($plugin_file, '', $was_network_active, true);
        if (is_wp_error($activation)) {
            fsr_etit_updates_set_notice('warning', 'Update installiert, aber das Plugin konnte nicht erneut aktiviert werden.');
            fsr_etit_updates_redirect();
        }
    }

    fsr_etit_updates_store_status('installed', $remote);
    fsr_etit_updates_set_notice('success', 'Update wurde erfolgreich installiert.');
    fsr_etit_updates_redirect();
}

function fsr_etit_updates_clear_cache(): void {
    if (!current_user_can('update_plugins')) {
        wp_die(esc_html__('Du hast keine Berechtigung für diese Aktion.', 'fsr-etit-website-tools'));
    }
    check_admin_referer('fsr_clear_update_cache');
    delete_transient('fsr_etit_updates_remote');
    foreach (['version', 'commit_message', 'checked', 'commit'] as $key) {
        delete_option('fsr_remote_' . $key);
    }
    wp_clean_plugins_cache(true);
    fsr_etit_updates_set_notice('success', 'Der Update-Cache wurde gelöscht.');
    fsr_etit_updates_redirect();
}

function fsr_etit_updates_store_status(string $prefix, array $remote): void {
    $values = [
        'commit'        => $remote['commit_sha'] ?? '',
        'version'       => $remote['version'] ?? '',
        'checked'       => current_time('mysql'),
        'commit_message'=> $remote['commit_message'] ?? '',
    ];
    foreach ($values as $key => $value) {
        update_option('fsr_' . $prefix . '_' . $key, $value, false);
    }
}

function fsr_etit_updates_fix_source_folder($source, $remote_source, $upgrader, $hook_extra) {
    $plugin_file = plugin_basename(FSR_ETIT_FILE);
    $targets = [];
    if (is_array($hook_extra)) {
        if (!empty($hook_extra['plugin'])) {
            $targets[] = $hook_extra['plugin'];
        }
        if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
            $targets = array_merge($targets, $hook_extra['plugins']);
        }
    }
    if (!in_array($plugin_file, $targets, true)) {
        return $source;
    }

    global $wp_filesystem;
    $path_exists = static function (string $path) use ($wp_filesystem): bool {
        return $wp_filesystem ? $wp_filesystem->exists($path) : file_exists($path);
    };

    $source = trailingslashit($source);
    $main_file = basename(FSR_ETIT_FILE);
    $plugin_source = $source;
    if ($path_exists($source . $main_file)) {
        $plugin_source = $source;
    } else {
        $directories = [];
        if ($wp_filesystem) {
            foreach ((array) $wp_filesystem->dirlist($source) as $name => $details) {
                if (($details['type'] ?? '') === 'd') {
                    $directories[] = $source . $name;
                }
            }
        } else {
            $directories = (array) glob($source . '*', GLOB_ONLYDIR);
        }
        foreach ($directories as $directory) {
            if ($path_exists(trailingslashit($directory) . $main_file)) {
                $plugin_source = trailingslashit($directory);
                break;
            }
        }
    }

    if (!$path_exists($plugin_source . $main_file)) {
        return new WP_Error(
            'fsr_etit_invalid_update_package',
            'Das Update-Paket enthält die Plugin-Hauptdatei nicht.'
        );
    }

    $installed_folder = dirname($plugin_file);
    if ($installed_folder === '.' || $installed_folder === '') {
        return $plugin_source;
    }
    if (basename(untrailingslashit($plugin_source)) === $installed_folder) {
        return $plugin_source;
    }

    $target = trailingslashit($remote_source) . $installed_folder;
    if (!$wp_filesystem || $wp_filesystem->exists($target)) {
        return new WP_Error(
            'fsr_etit_update_folder_conflict',
            'Der temporäre Zielordner für das Plugin-Update ist bereits belegt.'
        );
    }
    if (!$wp_filesystem->move(untrailingslashit($plugin_source), $target, true)) {
        return new WP_Error(
            'fsr_etit_update_folder_move_failed',
            'Der Update-Ordner konnte nicht auf den installierten Plugin-Namen vereinheitlicht werden.'
        );
    }

    return trailingslashit($target);
}

function fsr_etit_updates_after_update($upgrader, $hook_extra): void {
    $plugin_file = plugin_basename(FSR_ETIT_FILE);
    $updated = [];
    if (is_array($hook_extra)) {
        if (!empty($hook_extra['plugin'])) {
            $updated[] = $hook_extra['plugin'];
        }
        if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
            $updated = array_merge($updated, $hook_extra['plugins']);
        }
    }
    if (!in_array($plugin_file, $updated, true)) {
        return;
    }

    $remote = fsr_etit_updates_get_remote();
    if (is_array($remote)) {
        fsr_etit_updates_store_status('installed', $remote);
    }
}

function fsr_etit_updates_plugin_information($result, $action, $args) {
    if (
        $action !== 'plugin_information' ||
        !is_object($args) ||
        empty($args->slug) ||
        $args->slug !== dirname(plugin_basename(FSR_ETIT_FILE))
    ) {
        return $result;
    }

    $remote = fsr_etit_updates_get_remote();
    if (!is_array($remote)) {
        return $result;
    }
    $settings = fsr_etit_updates_settings();
    return (object) [
        'name'          => 'FSR ET/IT Website Tools',
        'slug'          => dirname(plugin_basename(FSR_ETIT_FILE)),
        'version'       => $remote['version'],
        'author'        => 'Enric, FSR ET/IT',
        'requires'      => '6.4',
        'requires_php'  => '8.0',
        'tested'        => '7.0',
        'download_link' => $remote['download'],
        'sections'      => [
            'description' => 'Website-Funktionen für DokuWiki, Mitglieder, Sprechstunden, Kalender und Updates.',
            'changelog'   => $remote['commit_message'] ?: 'Keine Informationen verfügbar.',
        ],
        'external'      => true,
        'homepage'      => 'https://github.com/' . $settings['github_repo'],
    ];
}
