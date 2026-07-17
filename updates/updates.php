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

function fsr_updates_check() {
    $settings = fsr_updates_settings();
    fsr_updates_log('Checking for updates with settings: ' . print_r($settings, true));
    $url = fsr_updates_get_url();
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        return false;
    }
    fsr_updates_log('Release Check Response for ' . $url);
    fsr_updates_log('Response: ' . wp_remote_retrieve_body($response));
    $data = json_decode(
        wp_remote_retrieve_body($response),
        true
    );
    if($settings['mode'] === 'release') {
        if (empty($data['tag_name'])) {
            fsr_updates_log('No tag_name found in response: ' . print_r($data, true));
            return false;
        }
        fsr_updates_log('Remote Release Version: ' . $data['tag_name']);
        update_option(
            'fsr_remote_version',
            $data['tag_name']
        );
    } else {
        if (empty($data['sha'])) {
            fsr_updates_log('No sha found in response: ' . print_r($data, true));
            return false;
        }
        fsr_updates_log('Remote Commit SHA: ' . $data['sha']);
        update_option(
            'fsr_remote_commit',
            substr($data['sha'],0,7)
        );
    }
    fsr_updates_log('Update check completed successfully.');
    return true;
}

function fsr_updates_manual_check() {
    fsr_updates_log('FSR Manual Update Check');
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung');
    }
    check_admin_referer(
        'fsr_check_update'
    );
    fsr_updates_check();
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
        'fsr_update_checked'
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
    fsr_updates_log('Checking for updates for plugin: ' . $plugin_file);
    $remote = fsr_updates_get_remote_version();
    if ($settings['mode'] === 'branch') {
        if (!$remote) {
            return $transient;
        }
        $installed_commit = get_option('fsr_installed_commit', '');
        if ($installed_commit === $remote['remote_id']) {
            return $transient;
        }
        $transient->response[$plugin_file] = (object) [
            'slug'        => dirname($plugin_file),
            'plugin'      => $plugin_file,
            'new_version' => $remote['version'],
            'package'     => $remote['package'],
        ];
        fsr_updates_log('Branch update available: ' . $remote['version']);
        return $transient;
    }
    fsr_updates_log('Remote Release: ' . print_r($remote, true));
    fsr_updates_log('Local Version: ' . FSR_PLUGIN_VERSION);
    fsr_updates_log('Version Compare: ' . version_compare($remote['version'], FSR_PLUGIN_VERSION));
    if (!$remote) {
        return $transient;
    }
    if (version_compare($remote['version'], FSR_PLUGIN_VERSION, '<=')) {
        return $transient;
    }
    $transient->response[$plugin_file] = (object) [
        'slug'        => dirname($plugin_file),
        'plugin'      => $plugin_file,
        'new_version' => $remote['version'],
        'package'     => $remote['package'],
    ];
    fsr_updates_log('Release update available: ' . $remote['version']);
    fsr_updates_log('Update package: ' . $remote['package']);
    fsr_updates_log('Update transient: ' . print_r($transient, true));
    return $transient;
}
add_filter(
    'site_transient_update_plugins',
    'fsr_updates_check_for_update'
);

function fsr_updates_get_remote_version() {
    $settings = fsr_updates_settings();
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
        fsr_updates_log('Error fetching remote version: ' . $response->get_error_message());
        return false;
    }
    $data = json_decode(
        wp_remote_retrieve_body($response),
        true
    );
    fsr_updates_log('Remote version data: ' . print_r($data, true));
    if ($settings['mode'] === 'branch') {
        if (empty($data['sha'])) {
            return false;
        }
        return [
            'version' =>
                substr($data['sha'], 0, 7),
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
        return [
            'version' =>
                ltrim(
                    $data['tag_name'],
                    'v'
                ),
            'download' =>
                $data['zipball_url']
        ];
    }
    fsr_updates_log('Unknown update mode: ' . $settings['mode']);
    return false;
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
            'https://api.github.com/repos/%s/commits/%s',
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