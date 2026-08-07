<?php
if (!defined('ABSPATH')) exit;


/**
 * Zentrale Shortcode Registry
 */
function fsr_etit_get_registered_shortcodes() {
    return [
        'fsr_members' => [
            'title' => 'Mitgliederkarten',
            'description' => 'Zeigt Mitglieder als Kartenansicht.',
            'attributes' => [
                'team' => [
                    'description' => 'Filtert nach Team.',
                    'values' => 'all, gewaehlte, helfer, ehemalige',
                    'default' => 'all',
                ],
                'amt' => [
                    'description' => 'Filtert nach Amt.',
                    'values' => get_option(FSR_ETIT_OPTION_MEMBER_ROLE_ORDER, FSR_ETIT_DEFAULT_ROLE_ORDER),
                    'default' => '',
                ],
                'name' => [
                    'description' => 'Filtert nach Vor- oder Nachname.',
                    'values' => 'Text',
                    'default' => '',
                ],
                'email' => [
                    'description' => 'Filtert nach Mail-Präfix.',
                    'values' => 'Text',
                    'default' => '',
                ],
            ],
            'example' => '[fsr_members team="gewaehlte" amt="Vorstand"]',
        ],

        'fsr_member_info' => [
            'title' => 'Mitgliederinformationen',
            'description' => 'Gibt einzelne Informationen von Mitgliedern aus.',
            'attributes' => [
                'amt' => [
                    'description' => 'Filtert nach Amt.',
                    'values' => get_option(FSR_ETIT_OPTION_MEMBER_ROLE_ORDER, FSR_ETIT_DEFAULT_ROLE_ORDER),
                    'default' => '',
                ],
                'fields' => [
                    'description' => 'Die Infos die ausgegeben werden.',
                    'values' => 'email, vorname, nachname, studiengang, abschluss, pronomen, amt, semester, start, abgang',
                    'default' => 'email',
                ],
                'team' => [
                    'description' => 'Filtert nach Team.',
                    'values' => 'all, gewaehlte, helfer, ehemalige',
                    'default' => 'all',
                ],
                'sep' => [
                    'description' => 'Trennzeichen zwischen mehreren Personen.',
                    'values' => 'Text oder <br>',
                    'default' => '<br>',
                ],
                'name' => [
                    'description' => 'Vor und Nachname des Mitglieds, falls amt leer ist.',
                    'values' => 'any string',
                    'default' => '',
                ],
            ],
            'example' => '[fsr_member_info amt="Vorsitz" fields="email"]',
        ],

        'fsr_office_hours' => [
            'title' => 'Sprechstunden',
            'description' => 'Zeigt die aktuellen Sprechstunden.',
            'attributes' => [
                'limit' => [
                    'description' => 'Maximale Zahl intern geladener kommender Termine.',
                    'values' => '1 bis 100',
                    'default' => 50,
                ],
            ],
            'example' => '[fsr_office_hours]',
        ],

        'fsr_office_hours_portal' => [
            'title' => 'Portal für die Sprechstunden',
            'description' => 'Geschütztes Verwaltungsportal für Sprechstunden. Standardmäßig nur für Administratoren.',
            'attributes' => [
                'limit' => [
                    'description' => 'Maximale Anzahl kommender Termine.',
                    'values' => '1 bis 100',
                    'default' => 20,
                ],
            ],
            'example' => '[fsr_office_hours_portal limit="20"]',
        ],

        'fsr_events' => [
            'title' => 'Events',
            'description' => 'Zeigt die aktuellen Events.',
            'attributes' => [
                'count' => [
                    'description' => 'Anzahl der anzuzeigenden Events.',
                    'values' => '1 bis 50',
                    'default' => 5,
                ],
                'category' => [
                    'description' => 'Filtert nach Kategorie.',
                    'values' => get_option(FSR_ETIT_OPTION_CALENDAR_CATEGORIES, []),
                    'default' => '',
                ],
            ],
            'example' => '[fsr_events]',
        ],
    ];
}

function fsr_etit_get_shortcode_usage($specific_shortcode = null, $include_non_public = null) {
    global $wpdb;
    $registered = fsr_etit_get_registered_shortcodes();
    $shortcodes = array_keys($registered);
    if ($specific_shortcode !== null) {
        $specific_shortcode = fsr_etit_scalar_string($specific_shortcode);
        if (!isset($registered[$specific_shortcode])) {
            return [];
        }
        $shortcodes = [$specific_shortcode];
    }
    if (empty($shortcodes)) {
        return [];
    }
    if ($include_non_public === null) {
        $include_non_public = is_admin() && current_user_can('edit_pages');
    }
    $statuses = $include_non_public
        ? ['publish', 'draft', 'private', 'pending']
        : ['publish'];
    $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    $conditions = [];
    $values = $statuses;
    foreach ($shortcodes as $shortcode) {
        $conditions[] = 'post_content LIKE %s';
        $values[] = '%' . $wpdb->esc_like('[' . $shortcode) . '%';
    }
    $sql = "
        SELECT ID, post_title, post_type, post_status, post_content
        FROM {$wpdb->posts}
        WHERE post_status IN ({$status_placeholders})
        AND post_type NOT IN ('revision','attachment')
        AND (" . implode(' OR ', $conditions) . ")
        ORDER BY post_modified DESC
    ";
    $posts = $wpdb->get_results(
        $wpdb->prepare($sql, $values)
    );
    $usage = [];
    foreach ($posts as $post) {
        foreach ($shortcodes as $shortcode) {
            if (
                stripos(
                    $post->post_content,
                    '[' . $shortcode
                ) !== false
            ) {
                $usage[$shortcode][] = [
                    'id' => (int) $post->ID,
                    'title' => $post->post_title ?: '(Ohne Titel)',
                    'type' => $post->post_type,
                    'status' => $post->post_status,
                    'edit' => get_edit_post_link($post->ID),
                    'view' => get_permalink($post->ID),
                ];
            }
        }
    }
    return $usage;
}

function fsr_etit_render_shortcode_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Du hast keine Berechtigung, diese Seite aufzurufen.', 'fsr-etit-website-tools'));
    }

    $shortcodes = fsr_etit_get_registered_shortcodes();
    $usage = fsr_etit_get_shortcode_usage(null, true);
    include __DIR__ . '/sc-template.php';
}
