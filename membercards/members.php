<?php
if (!defined('ABSPATH')) exit;

add_action('init', 'fsr_etit_register_member_post_type');
add_action('admin_init', 'fsr_etit_maybe_migrate_legacy_members');
add_action('admin_init', 'fsr_etit_register_membercards_layout_settings');
add_action('admin_enqueue_scripts', 'fsr_etit_members_admin_assets');
add_shortcode('fsr_members', 'fsr_etit_members_shortcode_renderer');
add_action('wp_ajax_fsr_save_member_order', 'fsr_etit_ajax_save_member_order_handler');
add_action('wp_ajax_fsr_import_members', 'fsr_etit_ajax_import_members_handler');

function fsr_etit_members_admin_assets($hook): void {
    if (!str_contains((string) $hook, 'fsr-etit-settings-membercards')) {
        return;
    }

    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_style(
        'fsr-members-admin',
        plugin_dir_url(__FILE__) . 'assets/admin-style.css',
        [],
        FSR_ETIT_VERSION
    );
}

function fsr_etit_register_member_post_type() {
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

function fsr_etit_member_default_record() {
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
        'abgang_jahr' => '',
        'team' => FSR_ETIT_TEAM_ELECTED,
    ];
}

function fsr_etit_member_normalize_team($team) {
    $team = sanitize_key(fsr_etit_scalar_string($team));
    return in_array(
        $team,
        [FSR_ETIT_TEAM_ELECTED, FSR_ETIT_TEAM_HELPERS, FSR_ETIT_TEAM_FORMER],
        true
    ) ? $team : FSR_ETIT_TEAM_ELECTED;
}

function fsr_etit_member_clean_text($value) {
    return trim(sanitize_text_field(wp_unslash(fsr_etit_scalar_string($value))));
}

function fsr_etit_member_is_empty($member) {
    foreach (['first_name', 'last_name', 'image', 'studiengang', 'abschluss', 'pronomen', 'email_prefix', 'amt', 'erstes_jahr', 'semester_anzahl', 'abgang_jahr'] as $key) {
        if (!empty($member[$key])) {
            return false;
        }
    }
    return true;
}

function fsr_etit_sanitize_member_record($member) {
    $member = wp_parse_args(is_array($member) ? $member : [], fsr_etit_member_default_record());
    $member['id'] = absint(fsr_etit_scalar_string($member['id']));
    $member['sort_order'] = absint(fsr_etit_scalar_string($member['sort_order']));
    $member['first_name'] = fsr_etit_member_clean_text($member['first_name']);
    $member['last_name'] = fsr_etit_member_clean_text($member['last_name']);
    $member['image'] = esc_url_raw(trim(fsr_etit_scalar_string($member['image'])), ['http', 'https']);
    $member['studiengang'] = fsr_etit_member_clean_text($member['studiengang']);
    $member['abschluss'] = in_array($member['abschluss'], ['B.Sc.', 'M.Sc.','Abgeschlossen'], true) ? $member['abschluss'] : '';
    $member['pronomen'] = fsr_etit_member_clean_text($member['pronomen']);
    $member['email_prefix'] = fsr_etit_member_clean_text($member['email_prefix']);
    $member['amt'] = fsr_etit_member_clean_text($member['amt']);
    $member['erstes_jahr'] = fsr_etit_member_clean_text($member['erstes_jahr']);
    $semester = fsr_etit_scalar_string($member['semester_anzahl']);
    $member['semester_anzahl'] = $semester === '' ? '' : absint($semester);
    $member['abgang_jahr'] = fsr_etit_member_clean_text($member['abgang_jahr']);
    $member['team'] = fsr_etit_member_normalize_team($member['team']);
    $tags = array_filter(array_map('trim', explode(',', $member['amt'])));
    $amt_order = get_option(FSR_ETIT_OPTION_MEMBER_ROLE_ORDER, FSR_ETIT_DEFAULT_ROLE_ORDER);
    $amt_order = is_array($amt_order) ? $amt_order : FSR_ETIT_DEFAULT_ROLE_ORDER;
    $tags = fsr_etit_sort_tags($tags, $amt_order);
    $member['amt'] = implode(', ', $tags);
    return $member;
}

function fsr_etit_sanitize_members_payload($input) {
    $clean = ['members' => []];
    if (!is_array($input) || empty($input['members']) || !is_array($input['members'])) {
        return $clean;
    }
    foreach ($input['members'] as $member) {
        $member = fsr_etit_sanitize_member_record($member);
        if (fsr_etit_member_is_empty($member) && empty($member['id'])) {
            continue;
        }
        $clean['members'][] = $member;
    }
    return $clean;
}

function fsr_etit_get_members_posts($team = 'all') {
    $query_args = [
        'post_type' => 'fsr_member',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'menu_order',
        'order' => 'ASC',
    ];
    if ($team !== 'all') {
        $query_args['meta_query'] = [[
            'key' => 'team',
            'value' => fsr_etit_member_normalize_team($team),
        ]];
    }
    $posts = get_posts($query_args);
    $members = [];
    foreach ($posts as $post) {
        $members[] = fsr_etit_member_post_to_record($post);
    }
    return $members;
}

function fsr_etit_member_post_to_record($post) {
    $record = fsr_etit_member_default_record();
    $record['id'] = $post->ID;
    $record['sort_order'] = (int) $post->menu_order;
    $record['first_name'] = fsr_etit_scalar_string(get_post_meta($post->ID, 'first_name', true));
    $record['last_name'] = fsr_etit_scalar_string(get_post_meta($post->ID, 'last_name', true));
    $record['image'] = fsr_etit_scalar_string(get_post_meta($post->ID, 'image', true));
    $record['studiengang'] = fsr_etit_scalar_string(get_post_meta($post->ID, 'studiengang', true));
    $record['abschluss'] = fsr_etit_scalar_string(get_post_meta($post->ID, 'abschluss', true));
    $record['pronomen'] = fsr_etit_scalar_string(get_post_meta($post->ID, 'pronomen', true));
    $record['email_prefix'] = fsr_etit_scalar_string(get_post_meta($post->ID, 'email_prefix', true));
    $record['amt'] = fsr_etit_scalar_string(get_post_meta($post->ID, 'amt', true));
    $record['erstes_jahr'] = fsr_etit_scalar_string(get_post_meta($post->ID, 'erstes_jahr', true));
    $record['semester_anzahl'] = fsr_etit_scalar_string(get_post_meta($post->ID, 'semester_anzahl', true));
    $record['abgang_jahr'] = fsr_etit_scalar_string(get_post_meta($post->ID, 'abgang_jahr', true));
    $record['team'] = fsr_etit_member_normalize_team(get_post_meta($post->ID, 'team', true));
    return $record;
}

function fsr_etit_get_members_data($team = 'all') {
    $members = fsr_etit_get_members_posts($team);
    if (!empty($members)) {
        return ['members' => $members];
    }
    $legacy = get_option('fsr_members_settings', ['members' => []]);
    if (!empty($legacy['members']) && is_array($legacy['members'])) {
        $legacy_members = [];
        foreach ($legacy['members'] as $index => $member) {
            $member['sort_order'] = $index;
            $member = fsr_etit_sanitize_member_record($member);
            if ($team !== 'all' && $member['team'] !== fsr_etit_member_normalize_team($team)) {
                continue;
            }
            if (fsr_etit_member_is_empty($member)) {
                continue;
            }
            $legacy_members[] = $member;
        }
        return ['members' => $legacy_members];
    }
    return ['members' => []];
}

function fsr_etit_member_post_title($member) {
    $name = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    if (!empty($member['email_prefix'])) {
        return $member['email_prefix'];
    }

    return 'Mitglied';
}

function fsr_etit_upsert_member_records($members, $delete_missing = true) {
    $members = array_values($members);
    $existing_posts = get_posts([
        'post_type' => 'fsr_member',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ]);
    $existing_ids = array_map('absint', $existing_posts);
    $saved_ids = [];
    $errors = [];

    foreach ($members as $index => $member) {
        $member = fsr_etit_sanitize_member_record($member);
        if (fsr_etit_member_is_empty($member) && empty($member['id'])) {
            continue;
        }

        $post_title = fsr_etit_member_post_title($member);
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
            $errors[] = $saved_id->get_error_message();
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
        update_post_meta($saved_id, 'abgang_jahr', $member['abgang_jahr']);
        update_post_meta($saved_id, 'team', $member['team']);
    }

    if ($delete_missing && empty($errors)) {
        foreach ($existing_ids as $existing_id) {
            if (!in_array($existing_id, $saved_ids, true)) {
                $trashed = wp_trash_post($existing_id);
                if (!$trashed || is_wp_error($trashed)) {
                    $errors[] = 'Mitglied ' . $existing_id . ' konnte nicht in den Papierkorb verschoben werden.';
                }
            }
        }
    }

    if (!empty($errors)) {
        return new WP_Error(
            'fsr_etit_member_save_failed',
            'Mindestens ein Mitglied konnte nicht gespeichert oder in den Papierkorb verschoben werden. Bitte prüfe die Liste und versuche es erneut.',
            ['member_ids' => $saved_ids, 'errors' => $errors]
        );
    }

    return $saved_ids;
}

function fsr_etit_sort_tags(array $tags, array $amt_order) {
    usort($tags, function($a, $b) use ($amt_order) {
        $posA = array_search($a, $amt_order, true);
        $posB = array_search($b, $amt_order, true);
        $posA = ($posA === false) ? PHP_INT_MAX : $posA;
        $posB = ($posB === false) ? PHP_INT_MAX : $posB;
        if ($posA === $posB) {
            return strcmp($a, $b);
        }
        return $posA <=> $posB;
    });
    return $tags;
}

function fsr_etit_parse_member_import_payload($raw_payload) {
    $raw_payload = trim(fsr_etit_scalar_string(wp_unslash($raw_payload)));
    if ($raw_payload === '') {
        return new WP_Error('empty_import', 'Bitte füge JSON oder CSV-Daten ein.');
    }
    if (strlen($raw_payload) > MB_IN_BYTES) {
        return new WP_Error('import_too_large', 'Die Importdatei darf höchstens 1 MB groß sein.');
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
        $headers = array_slice(
            array_map(
                static fn($header): string => fsr_etit_lowercase(trim($header)),
                str_getcsv($header_line, $delimiter, '"', '')
            ),
            0,
            100
        );
        if (empty(array_filter($headers))) {
            return new WP_Error('invalid_import_header', 'Die CSV-Kopfzeile ist ungültig.');
        }
        $parsed = [];

        foreach ($lines as $line) {
            $values = str_getcsv($line, $delimiter, '"', '');
            $values = array_slice(array_pad($values, count($headers), ''), 0, count($headers));
            $row = array_combine($headers, $values);
            if (!is_array($row)) {
                continue;
            }
            $parsed[] = $row;
        }
    }
    $members = [];
    foreach (array_slice($parsed, 0, 500) as $row) {
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
            'abgang_jahr' => $row['abgang_jahr'] ?? $row['abgang'] ?? $row['departure_year'] ?? '',
            'team' => $row['team'] ?? $row['team_id'] ?? FSR_ETIT_TEAM_ELECTED,
        ];
    }

    return $members;
}

function fsr_etit_maybe_migrate_legacy_members() {
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
        $member = fsr_etit_sanitize_member_record($member);
        if (fsr_etit_member_is_empty($member)) {
            continue;
        }
        $members[] = $member;
    }

    if (empty($members)) {
        return;
    }

    $result = fsr_etit_upsert_member_records($members, false);
    if (is_wp_error($result)) {
        $error_data = $result->get_error_data();
        $created_ids = is_array($error_data) ? ($error_data['member_ids'] ?? []) : [];
        foreach ((array) $created_ids as $created_id) {
            wp_delete_post(absint($created_id), true);
        }
        return;
    }
    delete_option('fsr_members_settings');
}

function fsr_etit_ajax_save_member_order_handler() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung.', 403);
    }
    check_ajax_referer('fsr-member-admin-nonce', 'nonce');

    if (!isset($_POST['member_count']) || !is_scalar($_POST['member_count'])) {
        wp_send_json_error('Die Anzahl der Mitglieder fehlt.', 400);
    }
    $expected_count = absint(wp_unslash($_POST['member_count']));
    if ($expected_count > 500) {
        wp_send_json_error('Es können höchstens 500 Mitglieder gespeichert werden.', 413);
    }

    $payload = isset($_POST['order']) ? wp_unslash($_POST['order']) : '';
    if (!is_string($payload) || strlen($payload) > 2 * MB_IN_BYTES) {
        wp_send_json_error('Die übermittelten Daten sind zu groß.', 413);
    }
    parse_str($payload, $form_data);
    $raw_members = $form_data['fsr_members_settings']['members'] ?? [];
    if (!is_array($raw_members) || count($raw_members) !== $expected_count) {
        wp_send_json_error('Die Mitgliederdaten wurden unvollständig übertragen. Bitte lade die Seite neu.', 400);
    }
    $member_payload = ['members' => $raw_members];
    $clean_data = fsr_etit_sanitize_members_payload($member_payload);
    if (count($clean_data['members']) !== $expected_count) {
        wp_send_json_error('Bitte entferne leere Mitgliederzeilen oder fülle sie aus.', 400);
    }
    $saved_ids = fsr_etit_upsert_member_records($clean_data['members'], true);
    if (is_wp_error($saved_ids)) {
        wp_send_json_error($saved_ids->get_error_message(), 500);
    }

    if (!empty($_POST['amt_order']) && is_array($_POST['amt_order'])) {
        $role_order = array_map(
            static fn($value): string => sanitize_text_field(
                fsr_etit_scalar_string(wp_unslash($value))
            ),
            array_slice($_POST['amt_order'], 0, 200)
        );
        $role_order = array_values(array_unique(array_filter($role_order)));
        update_option(
            FSR_ETIT_OPTION_MEMBER_ROLE_ORDER,
            $role_order,
            false
        );
    }

    wp_send_json_success([
        'message' => 'Mitglieder gespeichert.',
        'member_ids' => $saved_ids,
    ]);
}

function fsr_etit_ajax_import_members_handler() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung.', 403);
    }
    check_ajax_referer('fsr-member-admin-nonce', 'nonce');

    $replace_existing = !empty($_POST['replace_existing']);
    $parsed_members = fsr_etit_parse_member_import_payload(
        $_POST['import_data'] ?? ''
    );
    if (is_wp_error($parsed_members)) {
        wp_send_json_error($parsed_members->get_error_message(), 400);
    }

    $clean_members = [];
    foreach ($parsed_members as $member) {
        $member = fsr_etit_sanitize_member_record($member);
        if (fsr_etit_member_is_empty($member)) {
            continue;
        }
        $clean_members[] = $member;
    }

    if (empty($clean_members)) {
        wp_send_json_error('Keine gültigen Mitglieder gefunden.', 400);
    }

    $saved_ids = fsr_etit_upsert_member_records($clean_members, $replace_existing);
    if (is_wp_error($saved_ids)) {
        wp_send_json_error($saved_ids->get_error_message(), 500);
    }

    wp_send_json_success([
        'message' => sprintf('%d Mitglieder importiert.', count($saved_ids)),
        'member_ids' => $saved_ids,
    ]);
}

function fsr_etit_members_render_admin_interface() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $data = fsr_etit_get_members_data('all');
    $members = $data['members'] ?? [];
    $layout_settings = fsr_etit_get_membercards_layout_settings();
    
    include plugin_dir_path(__FILE__) . 'templates/admin-interface.php';
}

function fsr_etit_members_shortcode_renderer($atts) {
    $a = shortcode_atts([
        'team'  => 'all',
        'amt'   => '',
        'name'  => '',
        'email' => ''
    ], $atts);
    $team = sanitize_key((string) ($a['team'] ?? 'all'));
    if (!in_array($team, ['all', 'gewaehlte', 'helfer', 'ehemalige'], true)) {
        $team = 'all';
    }
    $data = fsr_etit_get_members_data($team);
    $members = $data['members'] ?? [];
    // Filter nach Amt
    if (!empty($a['amt'])) {
        $filter_amt = fsr_etit_lowercase(trim(wp_strip_all_tags($a['amt'])));
        $members = array_filter($members, function($member) use ($filter_amt) {
            $aemter = array_map(
                'trim',
                explode(',', fsr_etit_lowercase($member['amt'] ?? ''))
            );
            return in_array($filter_amt, $aemter, true);
        });
    }
    // Filter nach Name
    if (!empty($a['name'])) {
        $filter_name = fsr_etit_lowercase(trim(wp_strip_all_tags($a['name'])));
        $members = array_filter($members, function($member) use ($filter_name) {
            $name = fsr_etit_lowercase(
                ($member['first_name'] ?? '') . ' ' .
                ($member['last_name'] ?? '')
            );
            return str_contains($name, $filter_name);
        });
    }
    // Filter nach Email-Präfix
    if (!empty($a['email'])) {
        $filter_email = fsr_etit_lowercase(trim(wp_strip_all_tags($a['email'])));
        $members = array_filter($members, function($member) use ($filter_email) {
            return str_contains(
                fsr_etit_lowercase($member['email_prefix'] ?? ''),
                $filter_email
            );
        });
    }
    $layout_settings = fsr_etit_get_membercards_layout_settings();
    if (empty($members)) {
        return '<div class="fsr-members-empty">Keine passenden Mitglieder gefunden.</div>';
    }
    ob_start();
    include plugin_dir_path(__FILE__) . 'templates/frontend-grid.php';
    return ob_get_clean();
}

function fsr_etit_membercards_layout_defaults() {
    return [
        'desktop_cols' => 4,
        'tablet_cols' => 2,
        'mobile_cols' => 1,
    ];
}

function fsr_etit_sanitize_membercards_layout_settings($input) {
    $defaults = fsr_etit_membercards_layout_defaults();
    $input = is_array($input) ? $input : [];

    $desktop = isset($input['desktop_cols'])
        ? absint(fsr_etit_scalar_string($input['desktop_cols']))
        : $defaults['desktop_cols'];
    $tablet = isset($input['tablet_cols'])
        ? absint(fsr_etit_scalar_string($input['tablet_cols']))
        : $defaults['tablet_cols'];
    $mobile = isset($input['mobile_cols'])
        ? absint(fsr_etit_scalar_string($input['mobile_cols']))
        : $defaults['mobile_cols'];

    $desktop = max(1, min(6, $desktop));
    $tablet = max(1, min($desktop, $tablet));
    $mobile = max(1, min($tablet, $mobile));

    return [
        'desktop_cols' => $desktop,
        'tablet_cols' => $tablet,
        'mobile_cols' => $mobile,
    ];
}

function fsr_etit_register_membercards_layout_settings() {
    register_setting(
        'fsr_membercards_layout_settings',
        FSR_ETIT_OPTION_MEMBER_LAYOUT,
        [
            'type' => 'array',
            'sanitize_callback' => 'fsr_etit_sanitize_membercards_layout_settings',
            'default' => fsr_etit_membercards_layout_defaults(),
        ]
    );
}

function fsr_etit_get_membercards_layout_settings() {
    $settings = get_option(FSR_ETIT_OPTION_MEMBER_LAYOUT, []);
    return fsr_etit_sanitize_membercards_layout_settings($settings);
}

function fsr_etit_membercards_search($search_term) {
    $search_term = trim(wp_strip_all_tags($search_term));
    if ($search_term === '') {
        return [];
    }
    $query = new WP_Query([
        'post_type'      => 'fsr_member',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ]);
    if (!$query->have_posts()) {
        return [];
    }
    $virtual_posts = [];
    $url_overview = fsr_etit_get_shortcode_usage('fsr_members');
    foreach ($query->posts as $post) {
        $member = fsr_etit_member_post_to_record($post);
        $searchable = implode(' ', [
            $member['first_name'] ?? '',
            $member['last_name'] ?? '',
            $member['team'] ?? '',
            $member['email_prefix'] ?? '',
            $member['amt'] ?? '',
            $member['studiengang'] ?? '',
            $member['abschluss'] ?? '',
        ]);
        if (!str_contains(fsr_etit_lowercase($searchable), fsr_etit_lowercase($search_term))) {
            continue;
        }
        $content = $member['first_name'] . ' ' . $member['last_name'] . ' ' . $member['amt'];
        $virtual_posts[] = fsr_etit_create_virtual_post(
            fsr_etit_member_post_title($member),
            $content,
            $content,
            $url_overview['fsr_members'][0]['view'] ?? home_url('/'),
            $post->post_modified ?: $post->post_date,
            'page'
        );
    }
    wp_reset_postdata();
    return $virtual_posts;
}


add_shortcode('fsr_member_info', 'fsr_etit_member_info_shortcode_renderer');
function fsr_etit_member_info_shortcode_renderer($atts) {
    $a = shortcode_atts([
        'team'   => 'all',
        'amt'    => '',
        'name'   => '',
        'fields' => 'email',
        'sep'    => '<br>',
    ], $atts);
    $team = sanitize_key((string) $a['team']);
    $team = in_array($team, ['all', 'gewaehlte', 'helfer', 'ehemalige'], true) ? $team : 'all';
    $members = fsr_etit_get_members_data($team)['members'] ?? [];
    // Nach Amt filtern
    if (!empty($a['amt'])) {
        $amt = fsr_etit_lowercase(trim(wp_strip_all_tags($a['amt'])));
        $members = array_filter($members, function($m) use ($amt) {
            $aemter = array_map('trim', explode(',', fsr_etit_lowercase($m['amt'] ?? '')));
            return in_array($amt, $aemter, true);
        });
    }
    // Nach Namen filtern
    if (!empty($a['name'])) {
        $name = fsr_etit_lowercase(trim(wp_strip_all_tags($a['name'])));
        $members = array_filter($members, function($m) use ($name) {
            return str_contains(
                fsr_etit_lowercase(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')),
                $name
            );
        });
    }
    if (empty($members)) {
        return '';
    }
    $field_map = [
        'vorname'     => 'first_name',
        'nachname'    => 'last_name',
        'studiengang' => 'studiengang',
        'abschluss'   => 'abschluss',
        'pronomen'    => 'pronomen',
        'amt'         => 'amt',
        'semester'    => 'semester_anzahl',
        'start'       => 'erstes_jahr',
        'abgang'      => 'abgang_jahr',
    ];
    $fields = array_map('trim', explode(',', fsr_etit_lowercase(wp_strip_all_tags($a['fields']))));
    $separator = wp_kses((string) $a['sep'], ['br' => []]);
    $output = [];
    foreach ($members as $m) {
        $line = [];
        foreach ($fields as $field) {
            if ($field === 'email') {
                if (!empty($m['email_prefix'])) {
                    $line[] = esc_html($m['email_prefix'] . FSR_ETIT_EMAIL_SUFFIX);
                }
                continue;
            }
            if (!isset($field_map[$field])) {
                continue;
            }
            $value = $m[$field_map[$field]] ?? '';
            if ($value !== '') {
                $line[] = esc_html($value);
            }
        }
        $output[] = implode(' ', $line);
    }
    return implode($separator, $output);
}
