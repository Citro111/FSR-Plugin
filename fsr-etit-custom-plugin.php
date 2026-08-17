<?php
/**
 * Plugin Name:       FSR ET/IT Website Tools
 * Plugin URI:        https://github.com/Citro111/FSR-Plugin
 * Description:       Website-Funktionen für DokuWiki, Mitglieder, Sprechstunden, Kalender und Updates.
 * Version:           5.0.2
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Enric, FSR ET/IT
 * Author URI:        https://fsr-etit.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fsr-etit-website-tools
 * Update URI:        https://github.com/Citro111/FSR-Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FSR_ETIT_VERSION', '5.0.2');
define('FSR_ETIT_DIR', plugin_dir_path(__FILE__));
define('FSR_ETIT_URL', plugin_dir_url(__FILE__));
define('FSR_ETIT_FILE', __FILE__);

require_once FSR_ETIT_DIR . 'global/variables.php';
require_once FSR_ETIT_DIR . 'global/frontend-functions.php';
require_once FSR_ETIT_DIR . 'global/search.php';
require_once FSR_ETIT_DIR . 'global/admin.php';

require_once FSR_ETIT_DIR . 'dokuwiki/dw-connector.php';
require_once FSR_ETIT_DIR . 'membercards/members.php';
require_once FSR_ETIT_DIR . 'officehours/office-hours.php';
require_once FSR_ETIT_DIR . 'calendar/calendar.php';
require_once FSR_ETIT_DIR . 'shortcodes/shortcodes.php';
require_once FSR_ETIT_DIR . 'updates/updates.php';
require_once FSR_ETIT_DIR . 'link-marker/link-marker.php';

register_activation_hook(FSR_ETIT_FILE, 'fsr_etit_activate');
register_deactivation_hook(FSR_ETIT_FILE, 'fsr_etit_deactivate');

/**
 * Schedules a single rewrite-rule flush after activation.
 */
function fsr_etit_activate(): void {
    update_option(FSR_ETIT_OPTION_VERSION, FSR_ETIT_VERSION, false);
    update_option(FSR_ETIT_OPTION_FLUSH_REWRITE, 1, false);
}

/**
 * Removes the plugin's rewrite rules when it is deactivated.
 */
function fsr_etit_deactivate(): void {
    flush_rewrite_rules(false);
}

add_action('wp_enqueue_scripts', 'fsr_etit_enqueue_frontend_assets');
add_action('plugins_loaded', 'fsr_etit_maybe_run_upgrade');

/**
 * Schedules rewrite maintenance once after an in-place plugin update.
 */
function fsr_etit_maybe_run_upgrade(): void {
    if (get_option(FSR_ETIT_OPTION_VERSION) === FSR_ETIT_VERSION) {
        return;
    }

    update_option(FSR_ETIT_OPTION_VERSION, FSR_ETIT_VERSION, false);
    update_option(FSR_ETIT_OPTION_FLUSH_REWRITE, 1, false);
}

/**
 * Loads the small, dependency-free frontend styles shipped by the plugin.
 */
function fsr_etit_enqueue_frontend_assets(): void {
    $styles = [
        'fsr-dokuwiki'    => 'dokuwiki/dw.css',
        'fsr-members'     => 'membercards/members.css',
        'fsr-office-hours'=> 'officehours/office-hours.css',
        'fsr-calendar'    => 'calendar/calendar.css',
    ];

    foreach ($styles as $handle => $relative_path) {
        if (!file_exists(FSR_ETIT_DIR . $relative_path)) {
            continue;
        }

        wp_enqueue_style(
            $handle,
            FSR_ETIT_URL . $relative_path,
            [],
            FSR_ETIT_VERSION
        );
    }
}

add_action('init', 'fsr_etit_maybe_flush_rewrite_rules', 20);

/**
 * Flushes only after all init-time rewrite rules have been registered.
 */
function fsr_etit_maybe_flush_rewrite_rules(): void {
    if (!get_option(FSR_ETIT_OPTION_FLUSH_REWRITE)) {
        return;
    }

    flush_rewrite_rules(false);
    delete_option(FSR_ETIT_OPTION_FLUSH_REWRITE);
}
