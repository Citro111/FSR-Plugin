<?php
if (!defined('ABSPATH')) exit;
define(
    'FSR_UPDATE_OPTION_KEY',
    'fsr_update_settings'
);

require_once __DIR__ . '/templates/adminUI.php';
do_action('qm/debug', 'FSR Updates loaded');
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
    do_action(
        'qm/debug',
        'Sanitize Input: ' . print_r($input, true)
    );
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
    if ($settings['mode'] === 'branch') {
        return fsr_updates_check_branch();
    }
    return fsr_updates_check_release();
}

function fsr_updates_check_release() {
    $settings = fsr_updates_settings();
    $url = sprintf(
        'https://api.github.com/repos/%s/releases/latest',
        $settings['github_repo']
    );
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        return false;
    }
    do_action(
        'qm/debug','Release Check Response: ' .
        wp_remote_retrieve_body($response)
    );
    $data = json_decode(
        wp_remote_retrieve_body($response),
        true
    );
    if (empty($data['tag_name'])) {
        return false;
    }
    update_option(
        'fsr_remote_version',
        $data['tag_name']
    );
    return true;
}

function fsr_updates_check_branch() {
    $settings = fsr_updates_settings();
    $url = sprintf(
        'https://api.github.com/repos/%s/commits/%s',
        $settings['github_repo'],
        $settings['branch']
    );
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        return false;
    }
    do_action(
        'qm/debug','Branch Check Response: ' .
        wp_remote_retrieve_body($response)
    );
    $data = json_decode(
        wp_remote_retrieve_body($response),
        true
    );
    if(empty($data['sha'])) {
        return false;
    }
    update_option(
        'fsr_remote_commit',
        substr($data['sha'],0,7)
    );
    return true;
}

function fsr_updates_manual_check() {
    do_action('qm/debug', 'FSR Manual Update Check');
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
    do_action('qm/debug', 'FSR Clear Update Cache');
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
    if (
        empty($transient->checked)
    ) {
        return $transient;
    }
    $settings = fsr_updates_settings();
    if (
        empty($settings['github_repo'])
    ) {
        return $transient;
    }
    if ($settings['mode'] === 'branch') {
        $remote = fsr_updates_get_remote_branch();
    } else {
        $remote = fsr_updates_get_remote_version();
    }
    if (!$remote) {
        return $transient;
    }
    $plugin_file = plugin_basename(
        FSR_PLUGIN_DIR . 'fsr-etit-custom-plugin.php'
    );
    $current_version = FSR_PLUGIN_VERSION;
    if (
        version_compare(
            $remote['version'],
            $current_version,
            '>'
        )
    ) {
        $transient->response[$plugin_file] = (object)[
            'slug' =>
                dirname($plugin_file),
            'plugin' =>
                $plugin_file,
            'new_version' =>
                $remote['version'],
            'package' =>
                $remote['download'],
        ];
    }
    do_action(
        'qm/debug',
        'FSR Update Check: Current Version: ' .
        $current_version . ', Remote Version: ' .
        $remote['version'].', File: '.$plugin_file
    );
    return $transient;
}
add_filter(
    'site_transient_update_plugins',
    'fsr_updates_check_for_update'
);

function fsr_updates_get_remote_version() {
    $settings = fsr_updates_settings();
    $url = sprintf(
        'https://api.github.com/repos/%s/releases/latest',
        $settings['github_repo']
    );
    $response = wp_remote_get(
        $url,
        [
            'headers'=>[
                'Accept'=>'application/vnd.github+json'
            ]
        ]
    );
    if (
        is_wp_error($response)
    ) {
        return false;
    }
    $data = json_decode(
        wp_remote_retrieve_body($response),
        true
    );
    if (
        empty($data['tag_name'])
    ) {
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