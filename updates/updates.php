<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/templates/adminUI.php';
/**
 * FSR ET/IT Update Manager
 */
class FSR_ETIT_Update_Manager {
    const OPTION_KEY = 'fsr_update_settings';
    private string $github_repo = 'Citro111/FSR-Plugin';
    private string $plugin_file = 'FSR-Plugin/fsr-etit-custom-plugin.php';
    public function __construct() {
        add_action(
            'admin_init',
            [$this, 'register_settings']
        );
        add_action(
            'admin_post_fsr_check_update',
            [$this, 'manual_check']
        );
        add_action(
            'admin_post_fsr_clear_update_cache',
            [$this, 'clear_cache']
        );
        add_action(
            'admin_init',
            [$this, 'automatic_check']
        );
    }
    /**
     * Einstellungen
     */
    public function register_settings() {
        register_setting(
            'fsr_update_settings_group',
            self::OPTION_KEY
        );
    }
    /**
     * Einstellungen laden
     */
    private function settings() {
        return wp_parse_args(
            get_option(self::OPTION_KEY, []),
            [
                'mode' => 'release',
                'auto_update' => false,
                'check_admin' => true,
            ]
        );
    }
    /**
     * Bei Admin-Aufruf prüfen
     */
    public function automatic_check() {
        $settings = $this->settings();
        if (!$settings['check_admin']) {
            return;
        }
        if (
            get_transient('fsr_update_checked')
        ) {
            return;
        }
        $this->check();
        set_transient(
            'fsr_update_checked',
            true,
            HOUR_IN_SECONDS
        );
    }
    /**
     * GitHub prüfen
     */
    public function check() {
        $settings = $this->settings();
        if ($settings['mode'] === 'branch') {
            return $this->check_branch();
        }
        return $this->check_release();
    }
    /**
     * Release prüfen
     */
    private function check_release() {
        $url = sprintf(
            'https://api.github.com/repos/%s/releases/latest',
            $this->github_repo
        );
        $response = wp_remote_get(
            $url,
            [
                'headers'=>[
                    'Accept'=>'application/vnd.github+json'
                ]
            ]
        );
        if (is_wp_error($response)) {
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
        update_option(
            'fsr_remote_version',
            $data['tag_name']
        );
        return true;
    }
    /**
     * Branch prüfen
     */
    private function check_branch() {
        $url = sprintf(
            'https://api.github.com/repos/%s/commits/main',
            $this->github_repo
        );
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return false;
        }
        $data = json_decode(
            wp_remote_retrieve_body($response),
            true
        );
        if (
            empty($data['sha'])
        ) {
            return false;
        }
        update_option(
            'fsr_remote_commit',
            substr($data['sha'],0,7)
        );
        return true;
    }
    /**
     * Manuelle Prüfung
     */
    public function manual_check() {
        check_admin_referer(
            'fsr_check_update'
        );
        $this->check();
        wp_safe_redirect(
            admin_url(
                'admin.php?page=fsr-etit-settings-updates'
            )
        );
        exit;
    }
    /**
     * Cache löschen
     */
    public function clear_cache() {
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
            admin_url(
                'admin.php?page=fsr-etit-settings-updates'
            )
        );
        exit;
    }
    /**
     * Update installieren
     */
    public function install_update() {
        include_once ABSPATH .
        'wp-admin/includes/class-wp-upgrader.php';
        $upgrader = new Plugin_Upgrader();
        return $upgrader->upgrade(
            $this->plugin_file
        );
    }
}
new FSR_ETIT_Update_Manager();