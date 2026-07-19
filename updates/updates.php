<?php
if (!defined('ABSPATH')) exit;
define(
    'FSR_UPDATE_OPTION_KEY',
    'fsr_update_settings'
);
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
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    $settings = wp_parse_args(
        get_option(FSR_UPDATE_OPTION_KEY, []),
        [
            'github_repo' => 'Citro111/FSR-Plugin',
            'branch' => 'main',
            'mode' => 'release',
            'logging' => true,
        ]
    );
    return $settings;
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
        'logging' => !empty(
            $input['logging']
        ),
    ];
}


function fsr_updates_manual_install() {
    fsr_updates_log('Manual install initiated');
    if (!current_user_can('update_plugins')) {
        return fsr_updates_log('Keine Berechtigung zum manuellen Installieren von Updates');
    }
    check_admin_referer(
        'fsr_manual_install'
    );
    delete_transient(
        'fsr_updates_cached_update'
    );
    $remote = fsr_updates_get_remote_version();
    if (!$remote) {
        fsr_updates_log('Manual install failed: Keine Remote-Version gefunden');
        return;
    }
    if (get_option('fsr_installed_commit') === $remote['commit_sha']) {
        fsr_updates_log(
            'Manual update skipped. Already latest commit.'
        );
        wp_safe_redirect(
            wp_get_referer()
        );
        exit;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    $upgrader = new Plugin_Upgrader(
        new WP_Ajax_Upgrader_Skin()
    );
    $plugin_file = plugin_basename(
        FSR_PLUGIN_FILE
    );
    fsr_updates_log(
        'Starting upgrade: ' . $plugin_file
    );

    $update = new stdClass();
    $update->slug = dirname($plugin_file);
    $update->plugin = $plugin_file;
    $update->new_version = $remote['version'];
    $update->package = $remote['download'];

    $transient = get_site_transient('update_plugins');

    if (!is_object($transient)) {
        $transient = new stdClass();
    }
    if (!isset($transient->response)) {
        $transient->response = [];
    }
    $transient->response[$plugin_file] = $update;
    set_site_transient(
        'update_plugins',
        $transient
    );
    update_option(
        'fsr_update_running',
        true
    );
    $result = $upgrader->upgrade(
        $plugin_file
    );
    fsr_updates_log(
        'Package URL: ' . $update->package
    );
    fsr_updates_log(
        'Current commit: ' . get_option('fsr_installed_commit')
    );
    fsr_updates_log(
        'Remote commit: ' . $remote['commit_sha']
    );
    fsr_updates_log('UPGRADE RETURN' . print_r($result, true));
    fsr_updates_log('PLUGIN EXISTS' .
        file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)
            ? 'YES'
            : 'NO'
    );
    activate_plugin(
        $plugin_file
    );
    delete_option(
        'fsr_update_running'
    );
    if (is_wp_error($result)) {
        fsr_updates_log(
            'Upgrade failed: ' . $result->get_error_message()
        );
        return;
    }
    update_option(
        'fsr_installed_commit',
        $remote['commit_sha']
    );
    fsr_updates_log(
        'Upgrade successful: ' . $remote['commit_sha']
    );
    wp_safe_redirect(
        wp_get_referer()
    );
    return fsr_updates_log('Update erfolgreich installiert');
}
add_action(
    'admin_post_fsr_manual_install',
    'fsr_updates_manual_install'
);

function fsr_updates_clear_cache() {
    if (!current_user_can('update_plugins')) {
        return fsr_updates_log('Keine Berechtigung zum Löschen des Update-Caches');
    }
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
    return fsr_updates_log('Update Cache gelöscht');
}
add_action(
    'admin_post_fsr_clear_update_cache',
    'fsr_updates_clear_cache'
);

function fsr_updates_check_for_update($transient) {
    if (!is_object($transient)) {
        $transient = new stdClass();
    }
    if (!isset($transient->response)) {
        $transient->response = [];
    }
    if (!isset($transient->checked)) {
        $transient->checked = [];
    }
    if (
        defined('WP_INSTALLING')
        && WP_INSTALLING
    ) {
        return $transient;
    }
    if (
        get_option('fsr_update_running')
    ) {
        return $transient;
    }
    if (empty($transient->checked)) {
        fsr_updates_log('No plugins checked yet. Returning transient without checking for updates.' . 
            print_r(
                $transient->response ?? [],
                true
            )
        );
        return $transient;
    }
    $settings = fsr_updates_settings();
    if (empty($settings['github_repo'])||empty($settings['branch'])) {
        fsr_updates_log('GitHub Repository oder Branch nicht konfiguriert. Keine Update-Prüfung durchgeführt.' .
            print_r(
                $transient->response,
                true
            )
        );
        return $transient;
    }
    $plugin_file = plugin_basename(FSR_PLUGIN_FILE);
    fsr_updates_log('checking updates. Backtrace: ' . wp_debug_backtrace_summary());
    $remote = fsr_updates_get_remote_version();
    if (!$remote) {
        fsr_updates_log(
            'Fehler beim Abrufen der Remote-Version.' . print_r(
                $transient->response,
                true
            )
        );
    return $transient;
    }
    if ($settings['mode'] === 'branch') {
        $installed_commit = get_option(
            'fsr_installed_commit',
            ''
        );

        if (
            !empty($installed_commit)
            &&
            $installed_commit === $remote['commit_sha']
        ) {
            fsr_updates_log(
                'Commit bereits installiert: ' . $installed_commit
            );
            return $transient;
        }
    }
    elseif ($settings['mode'] === 'release') {
        if (version_compare($remote['version'], FSR_PLUGIN_VERSION, '<=')) {
            fsr_updates_log('Installed release is up to date: ' . FSR_PLUGIN_VERSION);
            return $transient;
        }
    }
    fsr_updates_log(
        'Adding update for: '.$plugin_file
    );
    $transient->response[$plugin_file] = (object)[
        'id'           => 'github://' . $settings['github_repo'],
        'slug'         => dirname($plugin_file),
        'plugin'       => $plugin_file,
        'new_version'  => $remote['version'],
        'url'          => 'https://github.com/' . $settings['github_repo'],
        'package'      => $remote['download'],
        'tested'       => '6.8',
        'requires_php' => '8.0',
    ];
    fsr_updates_log('PLUGIN FILE: ' . $plugin_file);
    fsr_updates_log('Update available for ' . $plugin_file . ': ' . print_r($transient->response, true));
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
    if (
        $settings['mode'] === 'release'
        && $status === 404
    ) {
        fsr_updates_log(
            'Noch keine GitHub Releases vorhanden.'
        );
        return false;
    }
    if ($status !== 200) {
        fsr_updates_log(
            'GitHub HTTP Error in remote_get_version: ' . $status
        );
        fsr_updates_log(
            'Response Body: ' .
            wp_remote_retrieve_body($response)
        );
        set_transient(
            'fsr_updates_cached_update',
            false,
            5 * MINUTE_IN_SECONDS
        );
        return $runtime_cache = false;
    }
    $data = json_decode(
        wp_remote_retrieve_body($response),
        true
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
        $remote = [
            'version' =>
                FSR_PLUGIN_VERSION,

            'download' =>
                sprintf(
                    'https://github.com/%s/archive/refs/heads/%s.zip',
                    $settings['github_repo'],
                    $settings['branch']
                ),
            'commit_message' =>
                $data['commit']['commit']['message'],
            'commit_sha' =>
                $data['commit']['sha']
        ];
        update_option(
            'fsr_remote_commit_message',
            $data['commit']['commit']['message']
        );
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
                $data['zipball_url'],
            'commit_message' =>
                'NEW REALEASE: ' . $data['name'] . ' - ' . $data['body'],
            'commit_sha' =>
                $data['target_commitish'] ?? $data['id'] ?? '',
        ];  
        update_option(
            'fsr_remote_commit_message',
            'NEW REALEASE: ' . $data['name'] . ' - ' . $data['body']
        );
    }
    fsr_updates_log('Remote version determined: ' . print_r($remote, true));
    $cache_time = 10;
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

function fsr_updates_log($message) {
    if (!fsr_updates_settings()['logging']) {
        return;
    }
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
    error_log('FSR UPDATES LOG: ' . $message);

    set_transient('fsr_updates_qm_log', $log, 5 * MINUTE_IN_SECONDS);
}


function fsr_updates_flush_log() {
    $log = get_transient('fsr_updates_qm_log');

    if (empty($log) || !is_array($log)) {
        return;
    }

    //delete_transient('fsr_updates_qm_log');

    foreach ($log as $line) {
        do_action('qm/debug', $line);
    }
}
add_action('admin_init', 'fsr_updates_flush_log');

function fsr_updates_after_update($upgrader, $hook_extra) {
    fsr_updates_log('Update process completed' . print_r($hook_extra, true));
    if (
        empty($hook_extra['plugins'])
        ||
        !in_array(
            plugin_basename(FSR_PLUGIN_FILE),
            $hook_extra['plugins'],
            true
        )
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
    update_option('fsr_installed_commit', $remote['commit_sha']);
    update_option('fsr_remote_version', $remote['version']);
    update_option('fsr_remote_checked', current_time('mysql'));
    fsr_updates_log('Installed version updated: ' . $remote['version']);
    fsr_updates_log('Active plugins after update: ' .
        print_r(
            get_option('active_plugins'),
            true
        )
    );
}
add_action(
    'upgrader_process_complete',
    'fsr_updates_after_update',
    10,
    2
);

function fsr_updates_fix_source_folder($source, $remote_source, $upgrader) {
    $source = trailingslashit($source);
    $plugin_file = basename(FSR_PLUGIN_FILE);
    fsr_updates_log('SOURCE: ' . $source);
    if (file_exists($source . $plugin_file)) {
        fsr_updates_log('Source already contains plugin file');
        return $source;
    }
    foreach (glob($source . '*', GLOB_ONLYDIR) as $dir) {
        if (file_exists(trailingslashit($dir) . $plugin_file)) {
            fsr_updates_log('Using nested plugin dir: ' . $dir);
            return trailingslashit($dir);
        }
    }
    return $source;
}
add_filter(
    'upgrader_source_selection',
    'fsr_updates_fix_source_folder',
    10,
    3
);

function fsr_updates_plugin_information($res, $action, $args) {
    fsr_updates_log(
        'plugins_api called: ' . $action
    );

    fsr_updates_log(
        'Arguments: ' .
        print_r($args, true)
    );
    if ($action !== 'plugin_information') {
        return $res;
    }
    if ($args->slug !== dirname(plugin_basename(FSR_PLUGIN_FILE))) {
        return $res;
    }
    $remote = fsr_updates_get_remote_version();
    if (!$remote) {
        return $res;
    }
    $res = new stdClass();
    $res->name = 'FSR ET/IT Custom Plugin';
    $res->slug = dirname(plugin_basename(FSR_PLUGIN_FILE));
    $res->version = $remote['version'] ?? '0.0.0';
    $res->author = $remote['author'] ?? 'Enric & FSR ET/IT';
    $res->homepage = 'https://fsr-etit.de';
    $res->requires = '6.0';
    $res->tested = '6.4';
    $res->download_link = $remote['download'] ?? '-';
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


// Debugging hooks for plugin Logging
add_action(
    'activated_plugin',
    function($plugin){
        fsr_updates_log(
            "ACTIVATED: ".$plugin
        );
    },
    10,
    1
);

add_action(
    'deactivated_plugin',
    function($plugin){
        fsr_updates_log(
            "DEACTIVATED: ".$plugin
        );
    },
    10,
    1
);

add_action('admin_menu', function(){

    require_once __DIR__ . '/templates/adminUI.php';

});
