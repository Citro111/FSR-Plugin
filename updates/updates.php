<?php
if (!defined('ABSPATH')) exit;
define(
    'FSR_UPDATE_OPTION_KEY',
    'fsr_update_settings'
);

require_once __DIR__ . '/templates/adminUI.php';
fsr_updates_log('FSR Updates Loaded');
function fsr_updates_register_settings() {
    register_setting(
        'fsr_update_settings',
        FSR_UPDATE_OPTION_KEY,
        [
            'sanitize_callback' => 'fsr_updates_sanitize_settings'
        ]
    );
}
add_action(
    'admin_init',
    'fsr_updates_register_settings'
);

function fsr_updates_settings() {
    return wp_parse_args(
        get_option(FSR_UPDATE_OPTION_KEY, []),
        [
            'github_repo' => 'Citro111/FSR-Plugin',
            'branch' => 'main',
            'mode' => 'release',
            'auto_update' => false,
            'check_admin' => true,
        ]
    );
}


function fsr_updates_sanitize_settings($input) {
    fsr_updates_log('Sanitize Input: ' . print_r($input, true));
    return [
        'github_repo' => sanitize_text_field(
            $input['github_repo'] ?? ''
        ),
        'branch' => sanitize_text_field(
            $input['branch'] ?? 'main'
        ),
        'mode' => sanitize_text_field(
            $input['mode'] ?? 'release'
        ),
        'auto_update' => !empty(
            $input['auto_update']
        ),
        'check_admin' => !empty(
            $input['check_admin']
        ),
    ];
}

function fsr_updates_manual_check() {
    fsr_updates_log('FSR Manual Update Check');
    delete_transient('fsr_remote_update');
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung');
    }
    check_admin_referer(
        'fsr_check_update'
    );
    fsr_updates_log('Manual update check started');
    delete_transient('fsr_update_checked');
    $remote = fsr_updates_get_remote_version();
    if (!$remote) {
        fsr_updates_log('Manual update check failed');
        exit;
    }
    fsr_updates_log(
        'Manual update check successful: ' . print_r($remote, true)
    );
    wp_safe_redirect(
        wp_get_referer()
    );
    exit;
}
add_action(
    'admin_post_fsr_check_update',
    'fsr_updates_manual_check'
);

function fsr_updates_clear_cache() {
    fsr_updates_log('FSR Clear Update Cache');
    check_admin_referer(
        'fsr_clear_update_cache'
    );
    delete_transient(
        'fsr_remote_update'
    );
    delete_option(
        'fsr_remote_version'
    );
    delete_option(
        'fsr_remote_commit'
    );
    wp_safe_redirect(
        wp_get_referer()
    );
    exit;
}

add_action(
    'admin_post_fsr_clear_update_cache',
    'fsr_updates_clear_cache'
);

function fsr_updates_check_for_update($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }
    $settings = fsr_updates_settings();
    if (empty($settings['github_repo'])) {
        return $transient;
    }
    $plugin_file = plugin_basename(FSR_PLUGIN_DIR . 'fsr-etit-custom-plugin.php');
    fsr_updates_log('Active plugins: ' . print_r(get_option('active_plugins'), true));
    fsr_updates_log('Checking for updates for plugin: ' . $plugin_file);
    $remote = fsr_updates_get_remote_version();
    if (!$remote) {
        return $transient;
    }
    if ($settings['mode'] === 'branch') {
        $installed_commit = get_option('fsr_installed_commit', '');
        if ($installed_commit === $remote['version']) {
            fsr_updates_log('Installed commit matches remote version: ' . $remote['version']);
            return $transient;
        }
    }
    elseif ($settings['mode'] === 'release') {
        if (version_compare($remote['version'], FSR_PLUGIN_VERSION, '<=')) {
            fsr_updates_log('Installed release is up to date: ' . FSR_PLUGIN_VERSION);
            return $transient;
        }
    }
    $transient->response[$plugin_file] = (object) [
        'slug'        => dirname($plugin_file),
        'plugin'      => $plugin_file,
        'new_version' => $remote['version'],
        'package'     => $remote['download'],
    ];
    return $transient;
}
add_filter(
    'site_transient_update_plugins',
    'fsr_updates_check_for_update'
);

function fsr_updates_get_remote_version() {
    static $runtime_cache = null;
    if ($runtime_cache !== null) {
        return $runtime_cache;
    }
    $cached = get_transient('fsr_remote_update');
    if ($cached !== false) {
        fsr_updates_log('Using cached update check result: ' . print_r($cached, true));
        $runtime_cache = $cached;
        return $cached;
    }
    $settings = fsr_updates_settings();
    fsr_updates_log('No cached results');
    fsr_updates_log('Getting remote version with settings: ' . print_r($settings, true));
    if (empty($settings['github_repo'])) {
        return false;
    }
    $url = fsr_updates_get_url();
    $response = wp_remote_get(
        $url,
        [
            'headers'=>[
                'Accept'=>'application/vnd.github+json',
                'User-Agent'=>'FSR-Plugin/' . FSR_PLUGIN_VERSION
            ],
            'timeout'=>15
        ]
    );
    if (is_wp_error($response)) {
        fsr_updates_log(
            'GitHub WP Error: ' . $response->get_error_message()
        );
        return false;
    }
    $status = wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
        fsr_updates_log(
            'GitHub HTTP Error in remote_get_version: ' . $status
        );
        fsr_updates_log(
            wp_remote_retrieve_body($response)
        );
        return false;
    }
    $data = json_decode(
        wp_remote_retrieve_body($response),
        true
    );
    fsr_updates_log('Remote version data type: ' . gettype($data));
    if ($settings['mode'] === 'branch') {
        if (empty($data['commit']['sha'])) {
            return false;
        }

        $remote = [
            'version' =>
                substr($data['commit']['sha'], 0, 7),
            'download' =>
                sprintf(
                    'https://github.com/%s/archive/refs/heads/%s.zip',
                    $settings['github_repo'],
                    $settings['branch']
                )
        ];
    }
    elseif ($settings['mode'] === 'release') {
        if (empty($data['tag_name'])) {
            return false;
        }
        $remote = [
            'version' =>
                ltrim(
                    $data['tag_name'],
                    'v'
                ),
            'download' =>
                $data['zipball_url']
        ];
    }
    fsr_updates_log('Remote version determined: ' . print_r($remote, true));
    set_transient(
        'fsr_remote_update',
        $remote,
        6 * HOUR_IN_SECONDS
    );
    return $runtime_cache = $remote;
}

function fsr_updates_get_url() {
    $settings = fsr_updates_settings();
    if(
        empty($settings['github_repo'])
    ) {
        return false;
    }
    if(
        $settings['mode'] === 'branch'
    ) {
        $url = sprintf(
            'https://api.github.com/repos/%s/branches/%s',
            $settings['github_repo'],
            $settings['branch']
        );
    }
    else {
        $url = sprintf(
            'https://api.github.com/repos/%s/releases/latest',
            $settings['github_repo']
        );
    } 
    return $url;
}

function fsr_updates_log($message) {
    $log = get_transient('fsr_updates_qm_log');
    if (!is_array($log)) {
        $log = [];
    }

    if (is_array($message) || is_object($message)) {
        $message = print_r($message, true);
    } else {
        $message = (string) $message;
    }

    $log[] = '[' . current_time('mysql') . '] ' . $message;

    set_transient('fsr_updates_qm_log', $log, 5 * MINUTE_IN_SECONDS);
}


function fsr_updates_flush_log() {
    $log = get_transient('fsr_updates_qm_log');

    if (empty($log) || !is_array($log)) {
        return;
    }

    delete_transient('fsr_updates_qm_log');

    foreach ($log as $line) {
        do_action('qm/debug', $line);
    }
}
add_action('admin_init', 'fsr_updates_flush_log');

function fsr_updates_after_update($upgrader, $hook_extra) {

    if (
        empty($hook_extra['plugin'])
    ) {
        return;
    }

    if (
        $hook_extra['plugin'] !== plugin_basename(__FILE__)
    ) {
        return;
    }

    $settings = fsr_updates_settings();

    if (
        $settings['mode'] !== 'branch'
    ) {
        return;
    }

    $remote = fsr_updates_get_remote_version();

    if (
        !$remote
    ) {
        return;
    }

    update_option(
        'fsr_installed_commit',
        $remote['version']
    );

    fsr_updates_log(
        'Installed commit updated: ' . $remote['version']
    );
}

add_action(
    'upgrader_process_complete',
    'fsr_updates_after_update',
    10,
    2
);