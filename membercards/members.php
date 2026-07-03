<?php
if (!defined('ABSPATH')) exit;

add_action('init', 'fsr_register_member_post_type');
add_action('admin_init', 'fsr_maybe_migrate_legacy_members');
add_action('admin_init', 'fsr_register_membercards_layout_settings');
add_action('admin_enqueue_scripts', 'fsr_members_admin_assets');
add_shortcode('fsr_members', 'fsr_members_shortcode_renderer');
add_action('wp_ajax_fsr_save_member_order', 'fsr_ajax_save_member_order_handler');
add_action('wp_ajax_fsr_import_members', 'fsr_ajax_import_members_handler');
add_action('wp_enqueue_scripts', 'fsr_members_frontend_assets');

function fsr_members_frontend_assets() {
    wp_enqueue_style(
        'fsr-members-css',
        plugin_dir_url(__FILE__) . 'members.css',
        [],
        '1.0.0'
    );
}

function fsr_members_admin_assets($hook) {
    if (strpos($hook, 'fsr-etit-settings') !== false) {
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style('fsr-members-admin-css', plugin_dir_url(__FILE__) . 'assets/admin-style.css', [], '1.1.0');
    }
}

function fsr_register_member_post_type() {
    register_post_type('fsr_member', [
        'labels' => [
            'name' => 'Mitglieder',
            'singular_name' => 'Mitglied',
        ],
        'public' => false,
        'show_ui' => false,
        'show_in_menu' => false,
        'show_in_rest' => false,
        'supports' => ['title', 'page-attributes'],
        'capability_type' => 'post',
        'map_meta_cap' => true,
        'exclude_from_search' => true,
    ]);
}

function fsr_member_default_record() {
    return [
        'id' => 0,
        'sort_order' => 0,
        'first_name' => '',
        'last_name' => '',
        'image' => '',
        'studiengang' => '',
        'abschluss' => '',
        'pronomen' => '',
        'email_prefix' => '',
        'amt' => '',
        'erstes_jahr' => '',
        'semester_anzahl' => '',
        'is_ehemalige' => 0,
        'abgang_jahr' => '',
        'team' => 'gewaehlte',
    ];
}

function fsr_member_normalize_team($team) {
    $team = sanitize_key((string) $team);
    return in_array($team, ['gewaehlte', 'helfer', 'ehemalige'], true) ? $team : 'gewaehlte';
}

function fsr_member_clean_text($value) {
    return trim(sanitize_text_field(wp_unslash((string) $value)));
}

function fsr_member_is_empty($member) {
    foreach (['first_name', 'last_name', 'image', 'studiengang', 'abschluss', 'pronomen', 'email_prefix', 'amt', 'erstes_jahr', 'semester_anzahl', 'abgang_jahr'] as $key) {
        if (!empty($member[$key])) {
            return false;
        }
    }
    return true;
}

function fsr_sanitize_member_record($member) {
    $member = wp_parse_args(is_array($member) ? $member : [], fsr_member_default_record());
    $legacy_team = sanitize_key((string) $member['team']);

    $member['id'] = absint($member['id']);
    $member['sort_order'] = absint($member['sort_order']);
    $member['first_name'] = fsr_member_clean_text($member['first_name']);
    $member['last_name'] = fsr_member_clean_text($member['last_name']);
    $member['image'] = esc_url_raw(trim((string) $member['image']));
    $member['studiengang'] = fsr_member_clean_text($member['studiengang']);
    $member['abschluss'] = in_array($member['abschluss'], ['B.Sc.', 'M.Sc.','Abgeschlossen'], true) ? $member['abschluss'] : '';
    $member['pronomen'] = fsr_member_clean_text($member['pronomen']);
    $member['email_prefix'] = fsr_member_clean_text($member['email_prefix']);
    $member['amt'] = fsr_member_clean_text($member['amt']);
    $member['erstes_jahr'] = fsr_member_clean_text($member['erstes_jahr']);
    $member['semester_anzahl'] = $member['semester_anzahl'] === '' ? '' : absint($member['semester_anzahl']);
    $member['is_ehemalige'] = !empty($member['is_ehemalige']) ? 1 : 0;
    $member['abgang_jahr'] = fsr_member_clean_text($member['abgang_jahr']);
    $member['team'] = fsr_member_normalize_team($member['team']);

    if ($legacy_team === 'ehemalige') {
        $member['is_ehemalige'] = 1;
    }

    return $member;
}

function fsr_sanitize_members_payload($input) {
    $clean = ['members' => []];

    if (!is_array($input) || empty($input['members']) || !is_array($input['members'])) {
        return $clean;
    }

    foreach ($input['members'] as $member) {
        $member = fsr_sanitize_member_record($member);
        if (fsr_member_is_empty($member) && empty($member['id'])) {
            continue;
        }
        $clean['members'][] = $member;
    }

    return $clean;
}

function fsr_get_members_posts($team = 'all') {
    $query_args = [
        'post_type' => 'fsr_member',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'menu_order',
        'order' => 'ASC',
    ];

    if ($team === 'ehemalige') {
        $query_args['meta_query'] = [[
            'key' => 'is_ehemalige',
            'value' => '1',
        ]];
    } elseif ($team !== 'all') {
        $query_args['meta_query'] = [[
            'key' => 'team',
            'value' => fsr_member_normalize_team($team),
        ]];
    }

    $posts = get_posts($query_args);
    $members = [];

    foreach ($posts as $post) {
        $members[] = fsr_member_post_to_record($post);
    }

    return $members;
}

function fsr_member_post_to_record($post) {
    $record = fsr_member_default_record();
    $record['id'] = $post->ID;
    $record['sort_order'] = (int) $post->menu_order;
    $record['first_name'] = (string) get_post_meta($post->ID, 'first_name', true);
    $record['last_name'] = (string) get_post_meta($post->ID, 'last_name', true);
    $record['image'] = (string) get_post_meta($post->ID, 'image', true);
    $record['studiengang'] = (string) get_post_meta($post->ID, 'studiengang', true);
    $record['abschluss'] = (string) get_post_meta($post->ID, 'abschluss', true);
    $record['pronomen'] = (string) get_post_meta($post->ID, 'pronomen', true);
    $record['email_prefix'] = (string) get_post_meta($post->ID, 'email_prefix', true);
    $record['amt'] = (string) get_post_meta($post->ID, 'amt', true);
    $record['erstes_jahr'] = (string) get_post_meta($post->ID, 'erstes_jahr', true);
    $record['semester_anzahl'] = (string) get_post_meta($post->ID, 'semester_anzahl', true);
    $record['abgang_jahr'] = (string) get_post_meta($post->ID, 'abgang_jahr', true);
    $record['is_ehemalige'] = absint(get_post_meta($post->ID, 'is_ehemalige', true));
    $record['team'] = fsr_member_normalize_team(get_post_meta($post->ID, 'team', true));

    if (sanitize_key((string) get_post_meta($post->ID, 'team', true)) === 'ehemalige') {
        $record['is_ehemalige'] = 1;
    }

    return $record;
}

function fsr_get_members_data($team = 'all') {
    $members = fsr_get_members_posts($team);

    if (!empty($members)) {
        return ['members' => $members];
    }

    $legacy = get_option('fsr_members_settings', ['members' => []]);
    if (!empty($legacy['members']) && is_array($legacy['members'])) {
        $legacy_members = [];
        foreach ($legacy['members'] as $index => $member) {
            $member['sort_order'] = $index;
            $member = fsr_sanitize_member_record($member);
            if ($team === 'ehemalige' && !$member['is_ehemalige']) {
                continue;
            }
            if ($team !== 'all' && $team !== 'ehemalige' && $member['team'] !== fsr_member_normalize_team($team)) {
                continue;
            }
            if (fsr_member_is_empty($member)) {
                continue;
            }
            $legacy_members[] = $member;
        }

        return ['members' => $legacy_members];
    }

    return ['members' => []];
}

function fsr_member_post_title($member) {
    $name = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    if (!empty($member['email_prefix'])) {
        return $member['email_prefix'];
    }

    return 'Mitglied';
}

function fsr_upsert_member_records($members, $delete_missing = true) {
    $members = array_values($members);
    $existing_posts = get_posts([
        'post_type' => 'fsr_member',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ]);
    $existing_ids = array_map('absint', $existing_posts);
    $saved_ids = [];

    foreach ($members as $index => $member) {
        $member = fsr_sanitize_member_record($member);
        if (fsr_member_is_empty($member) && empty($member['id'])) {
            continue;
        }

        $post_title = fsr_member_post_title($member);
        $post_data = [
            'post_type' => 'fsr_member',
            'post_status' => 'publish',
            'post_title' => $post_title,
            'menu_order' => (int) $index,
        ];

        if (!empty($member['id']) && in_array($member['id'], $existing_ids, true)) {
            $post_data['ID'] = $member['id'];
            $saved_id = wp_update_post($post_data, true);
        } else {
            $saved_id = wp_insert_post($post_data, true);
        }

        if (is_wp_error($saved_id)) {
            continue;
        }

        $saved_id = absint($saved_id);
        $saved_ids[] = $saved_id;

        update_post_meta($saved_id, 'first_name', $member['first_name']);
        update_post_meta($saved_id, 'last_name', $member['last_name']);
        update_post_meta($saved_id, 'image', $member['image']);
        update_post_meta($saved_id, 'studiengang', $member['studiengang']);
        update_post_meta($saved_id, 'abschluss', $member['abschluss']);
        update_post_meta($saved_id, 'pronomen', $member['pronomen']);
        update_post_meta($saved_id, 'email_prefix', $member['email_prefix']);
        update_post_meta($saved_id, 'amt', $member['amt']);
        update_post_meta($saved_id, 'erstes_jahr', $member['erstes_jahr']);
        update_post_meta($saved_id, 'semester_anzahl', $member['semester_anzahl']);
        update_post_meta($saved_id, 'is_ehemalige', $member['is_ehemalige']);
        update_post_meta($saved_id, 'abgang_jahr', $member['abgang_jahr']);
        update_post_meta($saved_id, 'team', $member['team']);
    }

    if ($delete_missing) {
        foreach ($existing_ids as $existing_id) {
            if (!in_array($existing_id, $saved_ids, true)) {
                wp_delete_post($existing_id, true);
            }
        }
    }

    return $saved_ids;
}

function fsr_parse_member_import_payload($raw_payload) {
    $raw_payload = trim((string) wp_unslash($raw_payload));
    if ($raw_payload === '') {
        return new WP_Error('empty_import', 'Bitte füge JSON oder CSV-Daten ein.');
    }

    $parsed = null;
    $decoded = json_decode($raw_payload, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        if (isset($decoded['members']) && is_array($decoded['members'])) {
            $decoded = $decoded['members'];
        }
        if (!empty($decoded) && (array_keys($decoded) !== range(0, count($decoded) - 1))) {
            $decoded = [$decoded];
        }
        $parsed = $decoded;
    }

    if ($parsed === null) {
        $lines = preg_split('/\r\n|\r|\n/', $raw_payload);
        $lines = array_values(array_filter(array_map('trim', $lines), static function ($line) {
            return $line !== '';
        }));

        if (empty($lines)) {
            return new WP_Error('empty_import', 'Die Importdaten konnten nicht gelesen werden.');
        }

        $header_line = array_shift($lines);
        $delimiter = (substr_count($header_line, ';') >= substr_count($header_line, ',')) ? ';' : ',';
        $headers = array_map('trim', str_getcsv($header_line, $delimiter));
        $parsed = [];

        foreach ($lines as $line) {
            $values = str_getcsv($line, $delimiter);
            $values = array_pad($values, count($headers), '');
            $row = array_combine($headers, $values);
            if (!is_array($row)) {
                continue;
            }
            $parsed[] = $row;
        }
    }

    $members = [];
    foreach ($parsed as $row) {
        if (!is_array($row)) {
            continue;
        }

        $members[] = [
            'id' => $row['id'] ?? $row['post_id'] ?? 0,
            'first_name' => $row['first_name'] ?? $row['vorname'] ?? '',
            'last_name' => $row['last_name'] ?? $row['nachname'] ?? '',
            'image' => $row['image'] ?? $row['bild'] ?? $row['bild_url'] ?? '',
            'studiengang' => $row['studiengang'] ?? $row['study_program'] ?? '',
            'abschluss' => $row['abschluss'] ?? '',
            'pronomen' => $row['pronomen'] ?? '',
            'email_prefix' => $row['email_prefix'] ?? $row['mail_prefix'] ?? '',
            'amt' => $row['amt'] ?? $row['aemter'] ?? $row['ämter'] ?? '',
            'erstes_jahr' => $row['erstes_jahr'] ?? $row['start_year'] ?? '',
            'semester_anzahl' => $row['semester_anzahl'] ?? $row['semester'] ?? '',
            'is_ehemalige' => $row['is_ehemalige'] ?? $row['ehemalige'] ?? 0,
            'abgang_jahr' => $row['abgang_jahr'] ?? $row['abgang'] ?? $row['departure_year'] ?? '',
            'team' => $row['team'] ?? $row['team_id'] ?? 'gewaehlte',
        ];
    }

    return $members;
}

function fsr_maybe_migrate_legacy_members() {
    $existing_posts = get_posts([
        'post_type' => 'fsr_member',
        'post_status' => 'any',
        'posts_per_page' => 1,
        'fields' => 'ids',
    ]);

    if (!empty($existing_posts)) {
        return;
    }

    $legacy = get_option('fsr_members_settings', ['members' => []]);
    if (empty($legacy['members']) || !is_array($legacy['members'])) {
        return;
    }

    $members = [];
    foreach ($legacy['members'] as $member) {
        $member = fsr_sanitize_member_record($member);
        if (fsr_member_is_empty($member)) {
            continue;
        }
        $members[] = $member;
    }

    if (empty($members)) {
        return;
    }

    fsr_upsert_member_records($members, false);
    delete_option('fsr_members_settings');
}

function fsr_ajax_save_member_order_handler() {
    check_ajax_referer('fsr-member-admin-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung.');
    }

    $payload = isset($_POST['order']) ? wp_unslash($_POST['order']) : '';
    parse_str($payload, $form_data);

    if (!isset($form_data['fsr_members_settings']['members'])) {
        wp_send_json_error('Fehler beim Verarbeiten.');
    }

    $clean_data = fsr_sanitize_members_payload($form_data['fsr_members_settings']);
    $saved_ids = fsr_upsert_member_records($clean_data['members'], true);

    wp_send_json_success([
        'message' => 'Mitglieder gespeichert.',
        'member_ids' => $saved_ids,
    ]);
}

function fsr_ajax_import_members_handler() {
    check_ajax_referer('fsr-member-admin-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung.');
    }

    $replace_existing = !empty($_POST['replace_existing']);
    $parsed_members = fsr_parse_member_import_payload($_POST['import_data'] ?? '');
    if (is_wp_error($parsed_members)) {
        wp_send_json_error($parsed_members->get_error_message());
    }

    $clean_members = [];
    foreach ($parsed_members as $member) {
        $member = fsr_sanitize_member_record($member);
        if (fsr_member_is_empty($member)) {
            continue;
        }
        $clean_members[] = $member;
    }

    if (empty($clean_members)) {
        wp_send_json_error('Keine gültigen Mitglieder gefunden.');
    }

    $saved_ids = fsr_upsert_member_records($clean_members, $replace_existing);

    wp_send_json_success([
        'message' => sprintf('%d Mitglieder importiert.', count($saved_ids)),
        'member_ids' => $saved_ids,
    ]);
}

function fsr_get_shortcode_usage_overview($shortcodes = ['fsr_members', 'fsr_office_hours', 'fsr_office_hours_sick']) {
    global $wpdb;

    $like_parts = [];
    $like_values = [];

    foreach ($shortcodes as $shortcode) {
        $like_parts[] = 'post_content LIKE %s';
        $like_values[] = '%[' . $wpdb->esc_like($shortcode) . '%';
    }

    $sql = "SELECT ID, post_title, post_type, post_status, post_content
            FROM {$wpdb->posts}
            WHERE post_status NOT IN ('auto-draft', 'trash', 'inherit')
            AND (" . implode(' OR ', $like_parts) . ")
            ORDER BY post_modified DESC";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $like_values));
    if (empty($rows)) {
        return [];
    }

    $usage = [];
    $pattern = '/\[(fsr_members|fsr_office_hours|fsr_office_hours_sick)\b[^\]]*\]/i';

    foreach ($rows as $row) {
        if (!preg_match_all($pattern, (string) $row->post_content, $matches)) {
            continue;
        }

        $found_shortcodes = array_values(array_unique(array_map('strtolower', $matches[1])));
        $usage[] = [
            'id' => (int) $row->ID,
            'title' => $row->post_title !== '' ? $row->post_title : '(Ohne Titel)',
            'type' => (string) $row->post_type,
            'status' => (string) $row->post_status,
            'shortcodes' => $found_shortcodes,
            'edit_link' => get_edit_post_link((int) $row->ID, ''),
            'view_link' => get_permalink((int) $row->ID),
        ];
    }
    return $usage;
}

function fsr_members_render_admin_interface() {
    $data = fsr_get_members_data('all');
    $members = $data['members'] ?? [];
    $layout_settings = fsr_get_membercards_layout_settings();
    $shortcode_usage = fsr_get_shortcode_usage_overview();
    include plugin_dir_path(__FILE__) . 'templates/admin-interface.php';
}

function fsr_members_shortcode_renderer($atts) {
    $a = shortcode_atts(['team' => 'all'], $atts);
    $team = sanitize_key((string) ($a['team'] ?? 'all'));
    if (!in_array($team, ['all', 'gewaehlte', 'helfer', 'ehemalige'], true)) {
        $team = 'all';
    }

    $data = fsr_get_members_data($team);
    $members = $data['members'] ?? [];
    $layout_settings = fsr_get_membercards_layout_settings();

    if (empty($members)) {
        return '<div class="fsr-members-empty">Noch keine Mitglieder hinterlegt.</div>';
    }

    ob_start();
    include plugin_dir_path(__FILE__) . 'templates/frontend-grid.php';
    return ob_get_clean();
}

function fsr_membercards_layout_defaults() {
    return [
        'desktop_cols' => 4,
        'tablet_cols' => 2,
        'mobile_cols' => 1,
    ];
}

function fsr_sanitize_membercards_layout_settings($input) {
    $defaults = fsr_membercards_layout_defaults();
    $input = is_array($input) ? $input : [];

    $desktop = isset($input['desktop_cols']) ? absint($input['desktop_cols']) : $defaults['desktop_cols'];
    $tablet = isset($input['tablet_cols']) ? absint($input['tablet_cols']) : $defaults['tablet_cols'];
    $mobile = isset($input['mobile_cols']) ? absint($input['mobile_cols']) : $defaults['mobile_cols'];

    $desktop = max(1, min(6, $desktop));
    $tablet = max(1, min($desktop, $tablet));
    $mobile = max(1, min($tablet, $mobile));

    return [
        'desktop_cols' => $desktop,
        'tablet_cols' => $tablet,
        'mobile_cols' => $mobile,
    ];
}

function fsr_register_membercards_layout_settings() {
    register_setting(
        'fsr_membercards_layout_settings',
        'fsr_membercards_layout',
        [
            'type' => 'array',
            'sanitize_callback' => 'fsr_sanitize_membercards_layout_settings',
            'default' => fsr_membercards_layout_defaults(),
        ]
    );
}

function fsr_get_membercards_layout_settings() {
    $settings = get_option('fsr_membercards_layout', []);
    return wp_parse_args(is_array($settings) ? $settings : [], fsr_membercards_layout_defaults());
}

function fsr_membercards_search($search_term) {

    $search_term = trim(wp_strip_all_tags($search_term));

    if ($search_term === '') {
        return '';
    }

    $query = new WP_Query([
        'post_type'      => 'fsr_member',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ]);

    if (!$query->have_posts()) {
        return '';
    }
    $virtual_posts = [];
    $url_overview = fsr_get_shortcode_usage_overview(['fsr_members']);

    foreach ($query->posts as $post) {

        $member = fsr_member_post_to_record($post);

        $searchable = implode(' ', [
            $member['first_name'] ?? '',
            $member['last_name'] ?? '',
            $member['team'] ?? '',
            $member['email_prefix'] ?? '',
            $member['amt'] ?? '',
            $member['studiengang'] ?? '',
            $member['abschluss'] ?? '',
        ]);

        if (stripos($searchable, $search_term) === false) {
            continue;
        }

        $virtual_posts[] = fsr_create_virtual_search_post(
            $title = fsr_member_post_title($member),
            $excerpt = implode("\n", $searchable),
            $content = implode("\n", $searchable),
            $url = $url_overview[0]['view_link'] ?? '',
            $date = '',
            $type = 'page'
        );
    }

    wp_reset_postdata();

    return $virtual_posts;
}