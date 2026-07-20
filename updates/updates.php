<?php
if (!defined('ABSPATH')) exit;
define(
    'FSR_UPDATE_OPTION_KEY',
    'fsr_update_settings'
);
function fsr_updates_register_settings() {
    register_setting( 'fsr_update_settings',FSR_UPDATE_OPTION_KEY,[
        'sanitize_callback' => 'fsr_updates_sanitize_settings'
    ]);
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
            'logging' => true,
        ]
    );;
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
        fsr_updates_log('Keine Berechtigung zum manuellen Installieren von Updates');
        fsr_redirect_to_update_page();
    }
    check_admin_referer(
        'fsr_manual_install'
    );
    delete_transient(
        'fsr_updates_cached_update'
    );
    $remote = fsr_updates_get_remote_version();
    if (!$remote) {
        fsr_updates_log('Manual install failed1: Keine Remote-Version gefunden');
        fsr_redirect_to_update_page();
        return;
    }
    if (get_option('fsr_installed_commit') === $remote['commit_sha']) {
        fsr_updates_log('Manual update skipped. Already latest commit.');
        fsr_redirect_to_update_page();
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
    fsr_updates_log('Starting upgrade: ' . $plugin_file);
    $transient = fsr_prepare_update_transient(get_site_transient('update_plugins'));
    $transient->response[$plugin_file] = fsr_updates_build_update($remote);
    set_site_transient(
        'update_plugins',
        $transient
    );
    $result = $upgrader->upgrade(
        $plugin_file
    );
    activate_plugin(
        $plugin_file
    );
    if (is_wp_error($result)) {
        fsr_updates_log('Upgrade failed: ' . $result->get_error_message());
        return;
    }
    fsr_updates_log('Upgrade successful: ' . $remote['commit_sha']);
    fsr_redirect_to_update_page();
    return fsr_updates_log('Update erfolgreich installiert');
}
add_action(
    'admin_post_fsr_manual_install',
    'fsr_updates_manual_install'
);

function fsr_updates_clear_cache() {
    if (!current_user_can('update_plugins')) {
        fsr_updates_log('Keine Berechtigung zum manuellen Installieren von Updates');
        fsr_redirect_to_update_page();
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
    fsr_redirect_to_update_page();
    return fsr_updates_log('Update Cache gelöscht');
}
add_action(
    'admin_post_fsr_clear_update_cache',
    'fsr_updates_clear_cache'
);

function fsr_updates_check_for_update($transient) {
    $transient = fsr_prepare_update_transient($transient);
    if (
        defined('WP_INSTALLING')
        && WP_INSTALLING
    ) {
        return $transient;
    }
    if (empty($transient->checked)) {
        fsr_updates_log('Returning unchecked transient.' .
            print_r( $transient->response ?? [], true)
        );
        return $transient;
    }
    $settings = fsr_updates_settings();
    if (empty($settings['github_repo'])||empty($settings['branch'])) {
        fsr_updates_log('GitHub Repository oder Branch nicht konfiguriert. Keine Update-Prüfung durchgeführt.' .
            print_r($transient->response,true)
        );
        return $transient;
    }
    $plugin_file = plugin_basename(FSR_PLUGIN_FILE);
    fsr_updates_log('checking updates. Backtrace: ' . wp_debug_backtrace_summary());
    $remote = fsr_updates_get_remote_version();
    if (!$remote) {
        fsr_updates_log('Manual install failed2: Keine Remote-Version gefunden');
        fsr_redirect_to_update_page();
        return $transient;
    }
    if ($settings['mode'] === 'branch') {
        $installed_commit = get_option('fsr_installed_commit','');
        if (!empty($installed_commit) && $installed_commit === $remote['commit_sha'] ) {
            fsr_updates_log('Commit bereits installiert: ' . $installed_commit);
            return $transient;
        }
    }
    elseif ($settings['mode'] === 'release') {
        if (version_compare($remote['version'], FSR_PLUGIN_VERSION, '<=')) {
            fsr_updates_log('Installed release is up to date: ' . FSR_PLUGIN_VERSION);
            return $transient;
        }
    }
    fsr_updates_log('Adding update for: '.$plugin_file);
    $transient->response[$plugin_file] = fsr_updates_build_update($remote);
    fsr_updates_log('PLUGIN FILE: ' . $plugin_file);
    fsr_updates_log('Update available for ' . $plugin_file . ': ' . print_r($transient->response, true));
    return $transient;
}
add_filter(
    'site_transient_update_plugins',
    'fsr_updates_check_for_update'
);

function fsr_prepare_update_transient($transient){

    if (!is_object($transient)) {
        $transient = new stdClass();
    }

    $transient->response ??= [];
    $transient->checked ??= [];

    return $transient;
}

function fsr_updates_get_remote_version() {
    static $runtime_cache;
    if ($runtime_cache) {
        return $runtime_cache;
    }
    if (($cached = get_transient('fsr_updates_cached_update')) !== false) {
        fsr_updates_log('Using cached update check result: ' . print_r($cached, true));
        return $runtime_cache = $cached;
    }
    $settings = fsr_updates_settings();
    if (empty($settings['github_repo'])) {
        return false;
    }
    fsr_updates_log('No cached results');
    if (!($data = fsr_updates_github_request($settings))) {
        fsr_updates_log('GitHub request failed');
        return false;
    }
    if ($settings['mode'] === 'release') {
        if (empty($data['tag_name'])) {
            return false;
        }
        $remote = [
            'version'         => ltrim($data['tag_name'], 'v'),
            'download'        => $data['zipball_url'],
            'commit_message'  => 'NEW RELEASE: '.$data['name'].' - '.$data['body'],
            'commit_sha'      => $data['target_commitish'] ?? $data['id'] ?? '',
        ];
    } else {
        if (empty($data['commit']['commit']['committer']['date'])) {
            return false;
        }
        $remote = [
            'version'         => FSR_PLUGIN_VERSION,
            'download'        => sprintf(
                'https://github.com/%s/archive/refs/heads/%s.zip',
                $settings['github_repo'],
                $settings['branch']
            ),
            'commit_message'  => $data['commit']['commit']['message'],
            'commit_sha'      => $data['commit']['sha'],
        ];
    }
    fsr_updates_store('remote', $remote);
    fsr_updates_log('Remote version determined: ' . print_r($remote, true));
    set_transient('fsr_updates_cached_update', $remote, HOUR_IN_SECONDS);
    fsr_updates_log('Caching remote version for ' . HOUR_IN_SECONDS . ' seconds');
    return $runtime_cache = $remote;
}

function fsr_updates_github_request($settings) {
        if(!$settings['github_repo']) {
            return false;
        }
        $url = $settings['mode'] === 'branch' ?
            sprintf(
                'https://api.github.com/repos/%s/branches/%s',
                $settings['github_repo'],
                $settings['branch']
            )
            : sprintf(
                'https://api.github.com/repos/%s/releases/latest',
                $settings['github_repo']
            );
    $response = wp_remote_get($url, [
        'headers' => [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'FSR-Plugin/' . FSR_PLUGIN_VERSION
        ],
        'timeout' => 15
    ]);
    if (is_wp_error($response)) {
        fsr_updates_log('GitHub Error: ' . $response->get_error_message());
        return false;
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
        fsr_updates_log('GitHub HTTP Error: ' . wp_remote_retrieve_response_code($response));

        return false;
    }
    return json_decode( wp_remote_retrieve_body($response), true );
}

function fsr_updates_build_update($remote){
    $plugin = fsr_updates_plugin_data();
    $settings = fsr_updates_settings();
    return (object)[
        'id'=>'github://' . $settings['github_repo'],
        'slug'=>$plugin['slug'],
        'plugin'=>$plugin['file'],
        'new_version'=>$remote['version'],
        'url'=>'https://github.com/' . $settings['github_repo'],
        'package'=>$remote['download'],
        'tested'=>'6.8',
        'requires_php'=>'8.0'
    ];
}

function fsr_redirect_to_update_page() {
    if (!current_user_can('update_plugins')||!is_admin()) {
        return;
    }
    wp_safe_redirect(
        admin_url('admin.php?page=fsr-etit-settings')
    );
    exit;
}

function fsr_updates_log($message) {
    static $logging;
    $logging ??= fsr_updates_settings()['logging'];
    if (!$logging) return;
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

    set_transient('fsr_updates_qm_log', $log, 1 * MINUTE_IN_SECONDS);
}

function fsr_updates_flush_log() {
    $log = get_transient('fsr_updates_qm_log');

    if (empty($log) || !is_array($log)) {
        return;
    }

    foreach ($log as $line) {
        do_action('qm/debug', $line);
    }
}
add_action(
    'load-admin_page_fsr-etit-settings-updates',
    'fsr_updates_flush_log'
);

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
        fsr_updates_log('Manual install failed3: Keine Remote-Version gefunden');
        fsr_redirect_to_update_page();
        return;
    }
    fsr_updates_store('installed', $remote);
    fsr_updates_store('remote', $remote);
    fsr_updates_log('Package URL: ' . $remote['download']);
    fsr_updates_log('Current commit: ' . get_option('fsr_installed_commit'));
    fsr_updates_log('Remote commit: ' . $remote['commit_sha']);
    fsr_updates_log('UPGRADE RETURN' . print_r($result, true));
    fsr_updates_log('PLUGIN EXISTS' . (file_exists(WP_PLUGIN_DIR . '/' . $plugin_file) ? 'YES' : 'NO'));
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

function fsr_updates_store($prefix,$remote){
    foreach([
        'commit'=>$remote['commit_sha'],
        'version'=>$remote['version'],
        'checked'=>current_time('mysql'),
        'commit_message'=>$remote['commit_message']
    ] as $k=>$v){
        update_option("fsr_{$prefix}_$k",$v);
    }
}

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
    fsr_updates_log('plugins_api called: ' . $action);
    fsr_updates_log('Arguments: ' . print_r($args, true));
    if ($action !== 'plugin_information') {
        return $res;
    }
    if ($args->slug !== dirname(plugin_basename(FSR_PLUGIN_FILE))) {
        return $res;
    }
    $remote = fsr_updates_get_remote_version();
    if (!$remote) {
        fsr_updates_log('Manual install failed4: Keine Remote-Version gefunden');
        fsr_redirect_to_update_page();
        return $res;
    }
    return (object)[
        'name' => 'FSR ET/IT Custom Plugin',
        'slug' => dirname(plugin_basename(FSR_PLUGIN_FILE)),
        'version' => $remote['version'],
        'author' => $remote['author'] ?? 'Enric & FSR ET/IT',
        'homepage' => 'https://fsr-etit.de',
        'requires' => '6.0',
        'tested' => '6.4',
        'download_link' => $remote['download'],
        'sections' => [
            'description' => 'Custom Plugin für die FSR ET/IT Website. Enthält DokuWiki-Integration, Mitgliedskarten, Office Hours und Update-Mechanismen.',
            'changelog' => $remote['commit_message'] ?? 'Keine Informationen verfügbar.',
        ],
    ];
}
add_filter(
    'plugins_api',
    'fsr_updates_plugin_information',
    10,
    3
);

// Debugging hooks for plugin Logging
/*
(
*/
    foreach ([
        'activated_plugin'   => 'ACTIVATED',
        'deactivated_plugin' => 'DEACTIVATED'
    ] as $hook => $label) {
        add_action($hook, function($plugin) use ($label) {
            fsr_updates_log("$label: $plugin");
        });

    }

    add_action('admin_menu', function(){
        require_once __DIR__ . '/templates/adminUI.php';
    });
/*
)
*/