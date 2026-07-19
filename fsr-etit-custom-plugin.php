<?php
/*
Plugin Name: FSR ET/IT Custom WP Plugin
Plugin URI: https://github.com/Citro111/FSR-Plugin
Description: Custom Plugin für die FSR ET/IT Website. Enthält DokuWiki-Integration, Mitgliedskarten, Office Hours und Update-Mechanismen.
Version: 5.7
Author: Enric & FSR ET/IT
Author URI: https://fsr-etit.de
Text Domain: fsretit
Update URI: https://github.com/Citro111/FSR-Plugin
*/

if (!defined('ABSPATH')) exit;

// Pfad-Konstanten für einfache Einbindung
define('FSR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FSR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FSR_PLUGIN_FILE',__FILE__);
$plugin_data = get_file_data(
    __FILE__,
    [
        'Version' => 'Version'
    ]
);
define(
    'FSR_PLUGIN_VERSION',
    $plugin_data['Version']
);

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

// 6. GitHub Updates laden
require_once FSR_PLUGIN_DIR . 'updates/updates.php';

register_activation_hook(__FILE__, 'fsr_dw_activate');

function fsr_dw_activate() {
    update_option(
        'fsr_dw_flush_rewrite',
        1
    );
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

add_action('init', 'fsr_dw_activation_flush', 5);
function fsr_dw_activation_flush() {
    if (get_option('fsr_dw_flush_rewrite')) {
        fsr_dw_rewrite_rules();
        flush_rewrite_rules(false);
        delete_option(
            'fsr_dw_flush_rewrite'
        );
    }
}
