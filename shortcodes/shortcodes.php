<?php
if (!defined('ABSPATH')) exit;


/**
 * Zentrale Shortcode Registry
 */
function fsr_get_registered_shortcodes() {
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
                    'values' => get_option('fsr_membercards_amt_order'),
                    'default' => '',
                ],
            ],
            'example' => '[fsr_members team="gewaehlte" amt="Vorstand"]',
        ],

        'fsr_member_info' => [
            'title' => 'Mitglieder Informationen',
            'description' => 'Gibt einzelne Informationen von Mitgliedern aus.',
            'attributes' => [
                'amt' => [
                    'description' => 'Filtert nach Amt.',
                    'values' => get_option('fsr_membercards_amt_order'),
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
                    'values' => 'any string',
                    'default' => ', ',
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
            'attributes' => [],
            'example' => '[fsr_office_hours]',
        ],

        'fsr_office_hours_sick' => [
            'title' => 'Krankheitsvertretung',
            'description' => 'Zeigt Hinweise zu ausgefallenen Sprechstunden.',
            'attributes' => [],
            'example' => '[fsr_office_hours_sick]',
        ],

    ];
}

function fsr_get_shortcode_usage($specific_shortcode = null) {
    global $wpdb;
    $registered = fsr_get_registered_shortcodes();
    $shortcodes = array_keys($registered);
    if (
        $specific_shortcode !== null &&
        isset($registered[$specific_shortcode])
    ) {
        $shortcodes = [$specific_shortcode];
    }
    if (empty($shortcodes)) {
        return [];
    }
    $conditions = [];
    $values = [];
    foreach ($shortcodes as $shortcode) {
        $conditions[] = 'post_content LIKE %s';
        $values[] = '%' . $wpdb->esc_like('[' . $shortcode) . '%';
    }
    $sql = "
        SELECT ID, post_title, post_type, post_status, post_content
        FROM {$wpdb->posts}
        WHERE post_status IN ('publish','draft','private','pending')
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

function fsr_render_shortcode_admin_page() {

    $shortcodes = fsr_get_registered_shortcodes();
    $usage = fsr_get_shortcode_usage();

    include __DIR__ . '/sc-template.php';
}