<?php
if (!defined('ABSPATH')) exit;
define(
    'FSR_UPDATE_OPTION_KEY',
    'fsr_update_settings'
);

require_once __DIR__ . '/templates/adminUI.php';
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
            'fast_update' => false,
            'check_update_admin' => true,
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
        'fast_update' => !empty(
            $input['fast_update']
        ),
        'check_update_admin' => !empty(
            $input['check_update_admin']
        ),
    ];
}


function fsr_updates_manual_install() {
    fsr_updates_log('Manual install initiated');
    if (!current_user_can('update_plugins')) {
        wp_die('Keine Berechtigung');
    }
    check_admin_referer(
        'fsr_manual_install'
    );
    delete_transient(
        'fsr_updates_cached_update'
    );
    $remote = fsr_updates_get_remote_version();
    if (!$remote) {
        fsr_print_log('Manual install failed: Keine Remote-Version gefunden');
    }
    fsr_updates_log(
        'Manual install requested: ' . $remote['version']
    );
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    $upgrader = new Plugin_Upgrader(
        new Automatic_Upgrader_Skin()
    );
    fsr_updates_log(
        'Upgrader Info:'
    );
    fsr_updates_log(
        print_r(
            $upgrader->skin->plugin_info,
            true
        )
    );
    fsr_updates_log(
        'Upgrader Result:'
    );
    fsr_updates_log(
        print_r(
            $upgrader->result,
            true
        )
    );
    $result = $upgrader->install(
        $remote['download']
    );
    fsr_updates_log(
        'Upgrader Result After Install:'
    );
    fsr_updates_log(
        print_r(
            $result,
            true
        )
    );
    if (is_wp_error($result)) {
        fsr_print_log(
            'Install failed: ' . $result->get_error_message()
        );
        wp_die(
            $result->get_error_message()
        );
    }
    delete_site_transient(
        'update_plugins'
    );
    wp_safe_redirect(
        wp_get_referer()
    );
    fsr_print_log('Manual install completed: ' . $remote['version']);
    update_option(
        'fsr_installed_version',
        $remote['version']
    );
    exit;
}
add_action(
    'admin_post_fsr_manual_install',
    'fsr_updates_manual_install'
);

function fsr_updates_clear_cache() {
    if (!current_user_can('update_plugins')) {
        wp_die('Keine Berechtigung');
    }
    /*
    global $wpdb;
    $wpdb->query(
        "
        DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_fsr_%'
        OR option_name LIKE 'fsr_updates_%'
        "
    );
    */
    fsr_updates_log('FSR Clear Update Cache');
    check_admin_referer(
        'fsr_clear_update_cache'
    );
    delete_transient(
        'fsr_updates_cached_update'
    );
    delete_option(
        'fsr_remote_version'
    );
    delete_option(
        'fsr_remote_commit_message'
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
    if (empty($settings['github_repo'])||empty($settings['branch'])||!$settings['check_update_admin']) {
        return $transient;
    }
    $plugin_file = plugin_basename(FSR_PLUGIN_DIR . FSR_PLUGIN_FILE);
    fsr_updates_log('Active plugins: ' . print_r(get_option('active_plugins'), true));
    fsr_updates_log('checking updates. Backtrace: ' . wp_debug_backtrace_summary());
    $remote = fsr_updates_get_remote_version();
    if (!$remote) {
        return $transient;
    }
    if ($settings['mode'] === 'branch') {
        $installed_version = get_option('fsr_installed_version', '');
        if ($installed_version === $remote['version']) {
            fsr_updates_log('Installed version matches remote version: ' . $remote['version']);
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
    $cached = get_transient('fsr_updates_cached_update');
    if ($cached !== false) {
        fsr_updates_log('Using cached update check result: ' . print_r($cached, true));
        $runtime_cache = $cached;
        return $cached;
    }
    $settings = fsr_updates_settings();
    fsr_updates_log('No cached results');
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
    update_option(
        'fsr_remote_commit_message',
        $data['commit']['commit']['message']
    );
    update_option(
        'fsr_remote_checked',
        current_time('mysql')
    );
    if ($settings['mode'] === 'branch') {
        if (
            empty($data['commit']['commit']['committer']['date'])
        ) {
            return false;
        }
        $commit_time = strtotime(
            $data['commit']['commit']['committer']['date']
        );
        $commit_version = date(
            'Ymd.His',
            $commit_time
        );
        $remote = [
            'version' =>
                FSR_PLUGIN_VERSION . '.' . $commit_version,

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
    $cache_time = 60 * MINUTE_IN_SECONDS;

    if ($settings['fast_update']) {
        $cache_time = 5;
    }
    fsr_updates_log('Caching remote version for ' . $cache_time . ' seconds');      
    set_transient(
        'fsr_updates_cached_update',
        $remote,
        $cache_time
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

function fsr_updates_print_log($message) {
    fsr_updates_log($message);
    set_transient(
        'fsr_updates_public_log',
        $message,
        5 * MINUTE_IN_SECONDS
    );
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

    if ($settings['mode'] !== 'branch') {
        return;
    }

    $remote = fsr_updates_get_remote_version();

    if (!$remote) {
        return;
    }

    update_option(
        'fsr_installed_version',
        $remote['version']
    );

    fsr_updates_log(
        'Installed version updated: ' . $remote['version']
    );
}
add_action(
    'upgrader_process_complete',
    'fsr_updates_after_update',
    10,
    2
);

function fsr_updates_fix_source_folder($source, $remote_source, $upgrader) {
    fsr_updates_log('SOURCE');
    fsr_updates_log($source);

    fsr_updates_log('REMOTE');
    fsr_updates_log($remote_source);

    fsr_updates_log('DESTINATION');

    fsr_updates_log(
        $upgrader->result
    );
    $plugin_slug = dirname(plugin_basename(FSR_PLUGIN_FILE));
    $source_base = basename(untrailingslashit($source));
    if ($source_base === $plugin_slug) {
        return $source;
    }
    $new_source = trailingslashit(dirname($source)) . $plugin_slug;
    if (file_exists($new_source)) {
        delete_dir($new_source);
    }
    wp_mkdir_p($new_source);
    foreach (glob($source . '/*') as $file) {
        rename(
            $file,
            $new_source . '/' . basename($file)
        );
    }
    rmdir($source);
    fsr_updates_log('Fixed source folder: ' . $new_source);
    fsr_updates_log('Contents of new source folder:');
    foreach (glob($new_source . '/*') as $file) {
        fsr_updates_log(' - ' . basename($file));
    }
    fsr_updates_log('Contents of destination folder:');
    foreach (glob(dirname($source) . '/*') as $file) {
        fsr_updates_log(' - ' . basename($file));
    }
    return $new_source;
}
add_filter(
    'upgrader_source_selection',
    'fsr_updates_fix_source_folder',
    10,
    3
);

function fsr_updates_plugin_information($res, $action, $args) {
    if ($action !== 'plugin_information') {
        return $res;
    }
    if ($args->slug !== plugin_basename(FSR_PLUGIN_FILE)) {
        return $res;
    }
    $remote = fsr_updates_get_remote_version();
    if (!$remote) {
        return $res;
    }
    $res = new stdClass();
    $res->name = 'FSR ET/IT Custom Plugin';
    $res->slug = plugin_basename(FSR_PLUGIN_FILE);
    $res->version = $remote['version'];
    $res->author = $remote['author'];
    $res->homepage = 'https://fsr-etit.de';
    $res->requires = '6.0';
    $res->tested = '6.4';
    $res->download_link = $remote['download'];
    $res->sections = [
        'description' => 'Custom Plugin für die FSR ET/IT Website. Enthält DokuWiki-Integration, Mitgliedskarten, Office Hours und Update-Mechanismen.',
        'changelog' => $remote['commit_message'] ?? 'Keine Informationen verfügbar.',
    ];
    return $res;
}
add_filter(
    'plugins_api',
    'fsr_updates_plugin_information',
    10,
    3
);