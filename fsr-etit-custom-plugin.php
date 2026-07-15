<?php
/*
Plugin Name: FSR ET/IT Custom WP Plugin
Pulugin URI: https://github.com/Citro111/FSR-Plugin
Description: Modulares Custom-Plugin für den FSR ET/IT (DokuWiki Connector & Team-Mitgliederverwaltung).
Version: 5.4
Author: Enric & FSR ET/IT
Author URI: https://fsr-etit.de
Text Domain: fsretit
*/

if (!defined('ABSPATH')) exit;

// Pfad-Konstanten für einfache Einbindung
define('FSR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FSR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FSR_PLUGIN_VERSION', '5.5');

// 1. Globale Admin-Oberfläche laden
require_once FSR_PLUGIN_DIR . 'global/admin.php';

// 2. DokuWiki-Modul laden
require_once FSR_PLUGIN_DIR . 'dokuwiki/dw-connector.php';

// 3. Membercards-Modul laden
require_once FSR_PLUGIN_DIR . 'membercards/members.php';

// 4. Office-Hours-Modul laden
require_once FSR_PLUGIN_DIR . 'officehours/office-hours.php';

// 5. Suchergebnisse erweitern
require_once FSR_PLUGIN_DIR . 'global/search.php';

register_activation_hook(__FILE__, 'fsr_dw_activate');

function fsr_dw_activate() {

    if (!get_page_by_path('wiki')) {

        wp_insert_post([
            'post_title'   => 'Wiki',
            'post_name'    => 'wiki',
            'post_content' => '',
            'post_status'  => 'publish',
            'post_type'    => 'page'
        ]);
    }

    fsr_dw_rewrite_rules();
    flush_rewrite_rules();
}

// Zentrale Asset-Verwaltung
add_action('wp_enqueue_scripts', 'fsr_custom_enqueue_frontend_assets');
function fsr_custom_enqueue_frontend_assets() {
    // DokuWiki CSS laden, falls die Datei existiert
    if (file_exists(FSR_PLUGIN_DIR . 'dokuwiki/dw.css')) {
        wp_enqueue_style('fsr-dw-css', FSR_PLUGIN_URL . 'dokuwiki/dw.css', [], '5.3');
    }
    // Membercards CSS laden, falls die Datei existiert
    if (file_exists(FSR_PLUGIN_DIR . 'membercards/members.css')) {
        wp_enqueue_style('fsr-members-css', FSR_PLUGIN_URL . 'membercards/members.css', [], '5.3');
    }
    // Office Hours CSS laden, falls die Datei existiert
    if (file_exists(FSR_PLUGIN_DIR . 'officehours/office-hours.css')) {
        wp_enqueue_style('fsr-office-hours-css', FSR_PLUGIN_URL . 'officehours/office-hours.css', [], '1.0.0');
    }
}

add_action('plugins_loaded', 'fsr_dw_check_version');
function fsr_dw_check_version() {
    $installed = get_option('fsr_plugin_version');
    if ($installed !== FSR_PLUGIN_VERSION) {
        if (function_exists('fsr_dw_rewrite_rules')) {
            fsr_dw_activate();
        }
        update_option(
            'fsr_plugin_version',
            FSR_PLUGIN_VERSION
        );
    }
}
