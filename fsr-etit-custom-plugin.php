<?php
/*
Plugin Name: FSR ET/IT Custom WP Plugin
Plugin URI: https://github.com/Citro111/FSR-Plugin
Description: Custom Plugin für die FSR ET/IT Website. Enthält DokuWiki-Integration, Mitgliedskarten, Office Hours und Update-Mechanismen.
Version: 3.2.0
Author: Enric & FSR ET/IT
Author URI: https://fsr-etit.de
Text Domain: fsr-etit-settings
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

//Globals laden
// Admin Integration
fsr_load_module(FSR_PLUGIN_DIR . 'global/admin.php', 'Global Admin');
// Variables Integration
fsr_load_module(FSR_PLUGIN_DIR . 'global/variables.php', 'Variables');
// Search Integration
fsr_load_module(FSR_PLUGIN_DIR . 'global/search.php', 'Search');

//Categories Integration
// DokuWiki Integration
fsr_load_module(FSR_PLUGIN_DIR . 'dokuwiki/dw-connector.php', 'DokuWiki');
// Membercards Integration
fsr_load_module(FSR_PLUGIN_DIR . 'membercards/members.php', 'Membercards');
// Office Hours Integration
fsr_load_module(FSR_PLUGIN_DIR . 'officehours/office-hours.php', 'Office Hours');
// Update Mechanism Integration
fsr_load_module(FSR_PLUGIN_DIR . 'updates/updates.php', 'Updates');
// Calendar Integration
fsr_load_module(FSR_PLUGIN_DIR . 'calendar/calendar.php', 'Calendar');

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
    // Kalender CSS laden, falls die Datei existiert
    if (file_exists(FSR_PLUGIN_DIR . 'calendar/calendar.css')) {
        wp_enqueue_style('fsr-calendar-css', FSR_PLUGIN_URL . 'calendar/calendar.css', [], '1.0.0');
    }
    // Globale CSS-Datei laden, falls die Datei existiert
    if (file_exists(FSR_PLUGIN_DIR . 'global/global.css')) {
        wp_enqueue_style('fsr-global-css', FSR_PLUGIN_URL . 'global/global.css', [], '1.0.0');
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

function fsr_load_module($file, $name = '') {
    if (!file_exists($file)) {
        return false;
    }
    // PHP Syntax prüfen
    $output = [];
    $result = 0;
    exec(
        'php -l ' . escapeshellarg($file),
        $output,
        $result
    );
    if ($result !== 0) {
        error_log(
            'FSR Plugin: Syntaxfehler in ' . $file
        );
        return false;
    }
    try {
        require_once $file;
        return true;
    } catch (Throwable $e) {
        error_log(
            'FSR Plugin Fehler ' .
            $name .
            ': ' .
            $e->getMessage()
        );
        return false;
    }
}