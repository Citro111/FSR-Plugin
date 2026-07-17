<?php

if (!defined('ABSPATH')) exit;


define(
    'FSR_UPDATE_OPTION_KEY',
    'fsr_update_settings'
);


require_once __DIR__ . '/templates/adminUI.php';
error_log('FSR Updates Datei geladen');


function fsr_updates_register_settings() {

    register_setting(
        'fsr_update_settings',
        FSR_UPDATE_OPTION_KEY
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
            'mode' => 'release',
            'auto_update' => false,
            'check_admin' => true,
        ]
    );

}



function fsr_updates_check() {


    $settings = fsr_updates_settings();


    if ($settings['mode'] === 'branch') {

        return fsr_updates_check_branch();

    }


    return fsr_updates_check_release();

}



function fsr_updates_check_release() {


    $url = 
    'https://api.github.com/repos/Citro111/FSR-Plugin/releases/latest';


    $response = wp_remote_get($url);


    if (is_wp_error($response)) {
        return false;
    }


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


    $url =
    'https://api.github.com/repos/Citro111/FSR-Plugin/commits/main';


    $response = wp_remote_get($url);


    if (is_wp_error($response)) {
        return false;
    }


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
    do_action('fsr_check_update');
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
    do_action('fsr_clear_update_cache');


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