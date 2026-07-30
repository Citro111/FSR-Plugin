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
                ],
                'amt' => [
                    'description' => 'Filtert nach Amt.',
                    'values' => 'z.B. Vorstand',
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
                    'values' => 'z.B. Vorsitz',
                ],
                'fields' => [
                    'description' => 'Auszugebende Felder.',
                    'values' => 'email, vorname, nachname',
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
    $shortcodes = array_keys(fsr_get_registered_shortcodes());
    if ($specific_shortcode !== null && in_array($specific_shortcode, $shortcodes)) {
        $shortcodes = [$specific_shortcode];
    }
    $conditions = [];
    $values = [];
    foreach ($shortcodes as $shortcode) {
        $conditions[] = 'post_content LIKE %s';
        $values[] = '%' . $wpdb->esc_like('[' . $shortcode) . '%';
    }
    if (empty($conditions)) {
        return [];
    }
    $sql = "
        SELECT ID, post_title, post_type, post_status, post_content
        FROM {$wpdb->posts}
        WHERE post_status NOT IN ('trash','auto-draft')
        AND (" . implode(' OR ', $conditions) . ")
        ORDER BY post_modified DESC
    ";
    $posts = $wpdb->get_results(
        $wpdb->prepare($sql, $values)
    );
    $usage = [];
    foreach ($posts as $post) {
        foreach ($shortcodes as $shortcode) {
            if (stripos($post->post_content, '[' . $shortcode) !== false) {
                $usage[$shortcode][] = [
                    'id' => $post->ID,
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

    include __DIR__ . '/templates/sc-template.php';
}