<?php

if (!defined('ABSPATH')) {
    exit;
}

define('FSR_ETIT_TEAM_ELECTED', 'gewaehlte');
define('FSR_ETIT_TEAM_HELPERS', 'helfer');
define('FSR_ETIT_TEAM_FORMER', 'ehemalige');

/* Persisted keys stay unchanged so existing website data continues to work. */
define('FSR_ETIT_OPTION_FLUSH_REWRITE', 'fsr_dw_flush_rewrite');
define('FSR_ETIT_OPTION_VERSION', 'fsr_etit_plugin_version');
define('FSR_ETIT_OPTION_DOKUWIKI_SETTINGS', 'dw_bridge_settings');
define('FSR_ETIT_OPTION_DOKUWIKI_CACHE_VERSION', 'fsr_etit_dokuwiki_cache_version');
define('FSR_ETIT_OPTION_CALENDAR_URL', 'fsr_calendar_url');
define('FSR_ETIT_OPTION_CALENDAR_CATEGORIES', 'fsr_calendar_categories');
define('FSR_ETIT_OPTION_MEMBER_ROLE_ORDER', 'fsr_membercards_amt_order');
define('FSR_ETIT_OPTION_MEMBER_LAYOUT', 'fsr_membercards_layout');
define('FSR_ETIT_OPTION_OFFICE_HOURS', 'fsr_office_hours_settings');
define('FSR_ETIT_OPTION_UPDATE_SETTINGS', 'fsr_update_settings');

define('FSR_ETIT_DEFAULT_ROLE_ORDER', [
    '1. Vorsitz',
    '2. Vorsitz',
    'Newsletter',
    'IT',
    'StuPa',
    'OE-Woche',
    'OE-Fahrt',
    'Preise',
    'Firmenkontakte',
    'Merch',
    'Einkauf',
    'Fotos',
    'Getränke',
    'PA TM',
    'Prüfungsplanung',
    'Betreutes Lernen',
    'SDA',
    'WA TM',
    'Klausurtagung',
    'Awareness',
    'PA ET',
    'Kassenprüfung',
    'PA IIW/CS/DS',
    'Veranstaltungen',
    'Internationales',
    'Öffentliches',
    'Klausurenbeauftragte',
    'WA ET',
    'Mails',
    'Snacks',
    'Finanzen',
    'Campusshop Beirat',
    'HITECH',
    'OE',
    'HoPo',
    'Firmenkontakt',
    'Job-Börse',
    'WA IIW/CS/DS',
    'nerdBar',
]);

define('FSR_ETIT_EMAIL_SUFFIX', '(at) fsr-etit.de');

/* Compatibility aliases for site-specific snippets written against v4. */
$fsr_etit_legacy_constants = [
    'FSR_PLUGIN_DIR'       => FSR_ETIT_DIR,
    'FSR_PLUGIN_URL'       => FSR_ETIT_URL,
    'FSR_PLUGIN_FILE'      => FSR_ETIT_FILE,
    'FSR_PLUGIN_VERSION'   => FSR_ETIT_VERSION,
    'FSR_TEAM1'            => FSR_ETIT_TEAM_ELECTED,
    'FSR_TEAM2'            => FSR_ETIT_TEAM_HELPERS,
    'FSR_TEAM3'            => FSR_ETIT_TEAM_FORMER,
    'FSR_CALENDAR_URL'     => FSR_ETIT_OPTION_CALENDAR_URL,
    'FSR_DEFAULT_AMT_ORDER'=> FSR_ETIT_DEFAULT_ROLE_ORDER,
    'FSR_EMAIL_SUFFIX'     => FSR_ETIT_EMAIL_SUFFIX,
];

foreach ($fsr_etit_legacy_constants as $fsr_etit_name => $fsr_etit_value) {
    if (!defined($fsr_etit_name)) {
        define($fsr_etit_name, $fsr_etit_value);
    }
}

unset($fsr_etit_legacy_constants, $fsr_etit_name, $fsr_etit_value);
