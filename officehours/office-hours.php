<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/templates/office-hours-frontend.php';
require_once __DIR__ . '/templates/office-hours-portal.php';
require_once __DIR__ . '/templates/office-hours-adminUI.php';

add_action('admin_init', 'fsr_office_hours_register_settings');
add_action('admin_enqueue_scripts', function () {
    wp_enqueue_script('select2');
    wp_enqueue_style('select2');
});

add_shortcode('fsr_office_hours_portal', 'fsr_office_hours_portal_shortcode');
add_shortcode('fsr_office_hours', 'fsr_office_hours_shortcode');

function fsr_office_hours_register_settings(): void {
    register_setting(
        'fsr_office_hours_settings',
        'fsr_office_hours_settings',
        [
            'sanitize_callback' => 'fsr_sanitize_office_hours_settings',
        ]
    );
}

function fsr_office_hours_get_settings(): array {
    return wp_parse_args(get_option('fsr_office_hours_settings', []), [
        'rules' => [],
        'cancellations' => [],
    ]);
}

function fsr_office_hours_sanitize_time($time, string $fallback = '10:00'): string {
    $time = trim((string) $time);
    return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time) ? $time : $fallback;
}

function fsr_office_hours_normalize_member_ids($incoming_ids): array {
    if (!is_array($incoming_ids)) {
        $incoming_ids = [$incoming_ids];
    }

    $ids = [];
    foreach ($incoming_ids as $value) {
        $id = absint($value);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function fsr_office_hours_sanitize_rule($rule, int $index = 0): array {
    
    $rule = is_array($rule) ? $rule : [];

    $id = sanitize_key((string) ($rule['id'] ?? ''));
    if ($id === '') {
        $id = 'rule_' . ($index + 1) . '_' . strtolower(wp_generate_password(6, false, false));
    }

    $recurrence = sanitize_key((string) ($rule['recurrence'] ?? 'monthly_nth'));
    if (!in_array($recurrence, ['monthly_nth', 'weekly'], true)) {
        $recurrence = 'monthly_nth';
    }

    return [
        'id' => $id,
        'type' => sanitize_key((string) ($rule['type'] ?? 'office_hour')),
        'title' => sanitize_text_field((string) ($rule['title'] ?? 'Office Hour')),
        'recurrence' => $recurrence,
        'nth_week' => max(1, min(4, absint($rule['nth_week'] ?? 1))),
        'weekday' => max(1, min(7, absint($rule['weekday'] ?? 3))),
        'week_interval' => max(1, min(8, absint($rule['week_interval'] ?? 1))),
        'start_time' => fsr_office_hours_sanitize_time($rule['start_time'] ?? '10:00', '10:00'),
        'end_time' => fsr_office_hours_sanitize_time($rule['end_time'] ?? '12:00', '12:00'),
        'location' => sanitize_text_field((string) ($rule['location'] ?? 'FSR Büro')),
        'member_ids' => fsr_office_hours_normalize_member_ids($rule['member_ids'] ?? []),
        'created_at' => sanitize_text_field((string) ($rule['created_at'] ?? current_time('mysql'))),
        'notes' => sanitize_text_field((string) ($rule['notes'] ?? '')),
        'start_date' => fsr_office_hours_sanitize_date($rule['start_date'] ?? current_time('Y-m-d')),
    ];
}

function fsr_office_hours_sanitize_date($date): string {
    $date = sanitize_text_field((string) $date);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    return current_time('Y-m-d');
}

function fsr_sanitize_office_hours_settings($input): array {
    $clean = [
        'rules' => [],
        'cancellations' => [],
    ];

    $rules = $input['rules'] ?? [];
    if (is_array($rules)) {
        foreach (array_values($rules) as $index => $rule) {
            $clean_rule = fsr_office_hours_sanitize_rule($rule, $index);
            if (empty($clean_rule['member_ids'])) {
                continue;
            }
            $clean['rules'][] = $clean_rule;
        }
    }

    $cancellations = $input['cancellations'] ?? [];
    if (is_array($cancellations)) {
        foreach ($cancellations as $item) {
            if (!is_array($item)) {
                continue;
            }

            $rule_id = sanitize_key((string) ($item['rule_id'] ?? ''));
            $member_id = absint($item['member_id'] ?? 0);
            $date = sanitize_text_field((string) ($item['occurrence_date'] ?? ''));

            if ($rule_id === '' || $member_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            $clean['cancellations'][] = [
                'rule_id' => $rule_id,
                'member_id' => $member_id,
                'occurrence_date' => $date,
                'reason' => sanitize_text_field((string) ($item['reason'] ?? '')),
                'created_at' => sanitize_text_field((string) ($item['created_at'] ?? current_time('mysql'))),
            ];
        }
    }

    return $clean;
}

function fsr_office_hours_get_all_members(): array {
    $data = fsr_get_members_data('all');
    $members = $data['members'] ?? [];
    return is_array($members) ? $members : [];
}

function fsr_office_hours_is_allowed_member(array $member): bool {
    return $member['team'] == FSR_TEAM1 || $member['team'] == FSR_TEAM2;
}

function fsr_office_hours_get_allowed_members(): array {
    $allowed = [];

    foreach (fsr_office_hours_get_all_members() as $member) {
        if (!is_array($member) || empty($member['id']) || !fsr_office_hours_is_allowed_member($member)) {
            continue;
        }

        $name = trim(
            (string) ($member['first_name'] ?? '') . ' ' .
            (string) ($member['last_name'] ?? '')
        );

        $allowed[] = [
            'id' => (int) $member['id'],
            'name' => $name !== '' ? $name : ('ID ' . (int) $member['id']),
        ];
    }

    return $allowed;
}

function fsr_office_hours_get_members_by_id(): array {
    $map = [];

    foreach (fsr_office_hours_get_all_members() as $member) {
        if (!is_array($member) || empty($member['id'])) {
            continue;
        }

        $map[(int) $member['id']] = $member;
    }

    return $map;
}

function fsr_office_hours_get_rule_members(array $rule): array {
    $allowed_members = fsr_office_hours_get_allowed_members();
    $allowed_map = [];
    foreach ($allowed_members as $member) {
        $allowed_map[(int) $member['id']] = $member['name'];
    }

    $names = [];
    foreach (($rule['member_ids'] ?? []) as $member_id) {
        $member_id = (int) $member_id;
        if (isset($allowed_map[$member_id])) {
            $names[] = $allowed_map[$member_id];
        }
    }

    return $names;
}

function fsr_office_hours_member_param(): int {
    return absint($_GET['member'] ?? $_POST['member'] ?? 0);
}

function fsr_office_hours_get_member_by_id(int $member_id): ?array {
    if ($member_id <= 0) {
        return null;
    }

    foreach (fsr_office_hours_get_all_members() as $member) {
        if ((int) ($member['id'] ?? 0) === $member_id) {
            return $member;
        }
    }

    return null;
}

function fsr_office_hours_get_selected_member(): ?array {
    static $selected = null;

    if ($selected !== null) {
        return $selected;
    }

    $member_id = fsr_office_hours_member_param();
    $member = fsr_office_hours_get_member_by_id($member_id);

    if ($member && fsr_office_hours_is_allowed_member($member)) {
        return $selected = $member;
    }

    $allowed = fsr_office_hours_get_allowed_members();
    if (!empty($allowed)) {
        $fallback_id = (int) $allowed[0]['id'];
        return $selected = fsr_office_hours_get_member_by_id($fallback_id);
    }

    return $selected = null;
}

function fsr_office_hours_set_cancellation(string $rule_id, int $member_id, string $date, string $reason = ''): void {
    $settings = fsr_office_hours_get_settings();
    $settings['cancellations'] = is_array($settings['cancellations'] ?? null) ? $settings['cancellations'] : [];

    foreach ($settings['cancellations'] as $key => $entry) {
        if (
            ($entry['rule_id'] ?? '') === $rule_id &&
            absint($entry['member_id'] ?? 0) === $member_id &&
            ($entry['occurrence_date'] ?? '') === $date
        ) {
            unset($settings['cancellations'][$key]);
            $settings['cancellations'] = array_values($settings['cancellations']);
            update_option('fsr_office_hours_settings', $settings);
            return;
        }
    }

    $settings['cancellations'][] = [
        'rule_id' => $rule_id,
        'member_id' => $member_id,
        'occurrence_date' => $date,
        'reason' => $reason,
        'created_at' => current_time('mysql'),
    ];

    update_option('fsr_office_hours_settings', $settings);
}

function fsr_office_hours_member_is_cancelled(array $cancellations, string $rule_id, string $date, int $member_id): bool {
    foreach ($cancellations as $entry) {
        if (
            ($entry['rule_id'] ?? '') === $rule_id &&
            absint($entry['member_id'] ?? 0) === $member_id &&
            ($entry['occurrence_date'] ?? '') === $date
        ) {
            return true;
        }
    }

    return false;
}

function fsr_office_hours_collect_occurrences(
    array $rules,
    int $limit = 12,
    bool $hide_fully_cancelled = true): array {
    $settings = fsr_office_hours_get_settings();
    $cancellations = $settings['cancellations'] ?? [];
    $today = current_time('Y-m-d');
    $today_ts = strtotime($today);
    $bucket = [];
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $rule = fsr_office_hours_sanitize_rule($rule);
        if (empty($rule['member_ids'])) {
            continue;
        }
        /*
        |--------------------------------------------------------------------------
        | Monatliche Regeln
        |--------------------------------------------------------------------------
        */
        if ($rule['recurrence'] === 'monthly_nth') {
            for ($offset = 0; $offset < 12; $offset++) {
                $month_ts = strtotime("first day of +{$offset} month", $today_ts);
                $year  = (int) date('Y', $month_ts);
                $month = (int) date('n', $month_ts);
                $date = fsr_office_hours_nth_weekday_date(
                    $year,
                    $month,
                    (int) $rule['weekday'],
                    (int) $rule['nth_week']
                );
                if (!$date || $date < $today) {
                    continue;
                }
                if (
                    $hide_fully_cancelled &&
                    fsr_office_hours_occurrence_is_cancelled(
                        $rule,
                        $date,
                        $cancellations
                    )
                ) {
                    continue;
                }
                $bucket[$rule['id'] . '_' . $date] = [
                    'rule_id'    => $rule['id'],
                    'title'      => $rule['title'],
                    'date'       => $date,
                    'start_time' => $rule['start_time'],
                    'end_time'   => $rule['end_time'],
                    'location'   => $rule['location'],
                    'notes'      => $rule['notes'],
                    'member_ids' => $rule['member_ids'],
                ];
            }
            continue;
        }
        /*
        |--------------------------------------------------------------------------
        | Wöchentliche Regeln
        |--------------------------------------------------------------------------
        */
        $weekday = (int) $rule['weekday'];
        $interval = max(1, (int) $rule['week_interval']);
        $start_ts = strtotime($rule['start_date'] ?? $today);
        if (!$start_ts) {
            $start_ts = $today_ts;
        }
        $cursor = max($start_ts, $today_ts);
        for ($i = 0; $i < 16; $i++) {
            $currentWeekday = (int) date('N', $cursor);
            $delta = ($weekday - $currentWeekday + 7) % 7;
            $candidate_ts = strtotime("+{$delta} days", $cursor);
            $date = date('Y-m-d', $candidate_ts);
            $weeks_since_start = floor(
                ($candidate_ts - $start_ts) / WEEK_IN_SECONDS
            );
            if ($weeks_since_start % $interval !== 0) {
                $cursor = strtotime("+1 day", $cursor);
                continue;
            }
            if ($date >= $today) {
                if (
                    !$hide_fully_cancelled ||
                    !fsr_office_hours_occurrence_is_cancelled(
                        $rule,
                        $date,
                        $cancellations
                    )
                ) {
                    $bucket[$rule['id'] . '_' . $date] = [
                        'rule_id'    => $rule['id'],
                        'title'      => $rule['title'],
                        'date'       => $date,
                        'start_time' => $rule['start_time'],
                        'end_time'   => $rule['end_time'],
                        'location'   => $rule['location'],
                        'notes'      => $rule['notes'],
                        'member_ids' => $rule['member_ids'],
                    ];
                }
            }
            $cursor = strtotime("+{$interval} week", $candidate_ts);
        }
    }
    usort($bucket, static function ($a, $b) {
        return strcmp(
            $a['date'] . ' ' . $a['start_time'],
            $b['date'] . ' ' . $b['start_time']
        );
    });
    return array_slice(array_values($bucket), 0, max(1, $limit));
}

function fsr_office_hours_handle_portal_actions(): array {
    $message = '';
    $ok = false;
    error_log('OFFICE HOURS: ' . print_r($_POST, true));
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return [false, ''];
    }

    if (isset($_POST['fsr_oh_join_submit'])) {
        if (!wp_verify_nonce($_POST['_fsr_oh_join_nonce'] ?? '', 'fsr_oh_join_submit')) {
            return [false, 'Ungültige Anfrage.'];
        }

        $member_id = absint($_POST['member'] ?? 0);
        $rule_id = sanitize_key($_POST['rule_id'] ?? '');

        $member = fsr_office_hours_get_member_by_id($member_id);
        if (!$member || !fsr_office_hours_is_allowed_member($member)) {
            return [false, 'Mitglied nicht erlaubt oder nicht gefunden.'];
        }

        $settings = fsr_office_hours_get_settings();
        $rules = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];

        foreach ($rules as &$rule) {
            if (!is_array($rule)) {
                continue;
            }

            if (($rule['id'] ?? '') === $rule_id) {
                $rule = fsr_office_hours_sanitize_rule($rule);
                $rule['member_ids'] = fsr_office_hours_normalize_member_ids($rule['member_ids'] ?? []);
                if (!in_array($member_id, $rule['member_ids'], true)) {
                    $rule['member_ids'][] = $member_id;
                }
                $rule['member_ids'] = array_values(array_unique($rule['member_ids']));
                break;
            }
        }
        unset($rule);

        $settings['rules'] = $rules;
        update_option('fsr_office_hours_settings', $settings);

        return [true, 'Du wurdest zur Sprechstunde hinzugefügt.'];
    }

    if (isset($_POST['fsr_oh_cancellation_submit'])) {
        if (!wp_verify_nonce($_POST['_fsr_oh_cancel_nonce'] ?? '', 'fsr_oh_cancellation_submit')) {
            return [false, 'Ungültige Anfrage.'];
        }

        $member_id = absint($_POST['member'] ?? 0);
        $rule_id = sanitize_key($_POST['rule_id'] ?? '');
        $date = sanitize_text_field($_POST['date'] ?? '');
        $reason = sanitize_text_field($_POST['reason'] ?? '');
        $action = sanitize_key($_POST['cancel_action'] ?? 'toggle');

        $member = fsr_office_hours_get_member_by_id($member_id);
        if (!$member || !fsr_office_hours_is_allowed_member($member)) {
            return [false, 'Mitglied nicht erlaubt oder nicht gefunden.'];
        }

        if ($rule_id === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [false, 'Ungültiger Termin.'];
        }

        if ($action === 'uncancel') {
            fsr_office_hours_set_cancellation($rule_id, $member_id, $date, '');
            return [true, 'Teilnahme wieder aktiviert.'];
        }

        fsr_office_hours_set_cancellation($rule_id, $member_id, $date, $reason);
        return [true, 'Teilnahme erfolgreich abgesagt.'];
    }

    if (isset($_POST['fsr_oh_create_rule_submit'])) {
        if (!wp_verify_nonce($_POST['_fsr_oh_create_nonce'] ?? '', 'fsr_oh_create_rule_submit')) {
            return [false, 'Ungültige Anfrage.'];
        }

        $member_id = absint($_POST['member'] ?? 0);
        $member = fsr_office_hours_get_member_by_id($member_id);
        if (!$member || !fsr_office_hours_is_allowed_member($member)) {
            return [false, 'Mitglied nicht erlaubt oder nicht gefunden.'];
        }

        $title = sanitize_text_field($_POST['title'] ?? 'Office Hour');
        $location = sanitize_text_field($_POST['location'] ?? 'FSR Büro');
        $type = sanitize_key($_POST['type'] ?? 'office_hour');
        $recurrence = sanitize_key($_POST['recurrence'] ?? 'weekly');
        $weekday = max(1, min(7, absint($_POST['weekday'] ?? 3)));
        $nth_week = max(1, min(4, absint($_POST['nth_week'] ?? 1)));
        $week_interval = max(1, min(8, absint($_POST['week_interval'] ?? 1)));
        $start_time = fsr_office_hours_sanitize_time($_POST['start_time'] ?? '10:00', '10:00');
        $end_time = fsr_office_hours_sanitize_time($_POST['end_time'] ?? '12:00', '12:00');
        $notes = sanitize_text_field($_POST['notes'] ?? '');
        $start_date = fsr_office_hours_sanitize_date(
            $_POST['start_date'] ?? current_time('Y-m-d')
        );

        $additional_member_ids = fsr_office_hours_normalize_member_ids($_POST['member_ids'] ?? []);
        $all_member_ids = array_values(array_unique(array_merge([$member_id], $additional_member_ids)));

        $rule = [
            'id' => 'rule_' . strtolower(wp_generate_password(8, false, false)),
            'type' => $type !== '' ? $type : 'office_hour',
            'title' => $title !== '' ? $title : 'Office Hour',
            'recurrence' => in_array($recurrence, ['monthly_nth', 'weekly'], true) ? $recurrence : 'weekly',
            'weekday' => $weekday,
            'nth_week' => $nth_week,
            'week_interval' => $week_interval,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'location' => $location !== '' ? $location : 'FSR Büro',
            'member_ids' => $all_member_ids,
            'created_at' => current_time('mysql'),
            'notes' => $notes,
            'start_date' => $start_date,
        ];

        $settings = fsr_office_hours_get_settings();
        $settings['rules'] = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];
        $settings['rules'][] = $rule;

        update_option('fsr_office_hours_settings', $settings);
        return [true, 'Neue Sprechstunde gespeichert.'];
    }

    if (isset($_POST['fsr_oh_delete_rule_submit'])) {

        error_log('DELETE HANDLER START');

        if (!wp_verify_nonce($_POST['_fsr_oh_delete_nonce'] ?? '', 'fsr_oh_delete_rule_submit')) {
            error_log('DELETE NONCE FAILED');
            return [false, 'Ungültige Anfrage.'];
        }

        $rule_id = sanitize_key($_POST['rule_id'] ?? '');

        error_log('DELETE RULE ID: ' . $rule_id);

        $settings = fsr_office_hours_get_settings();

        error_log(print_r($settings['rules'], true));

        $rules = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];

        $before = count($rules);

        $rules = array_filter($rules, static function ($rule) use ($rule_id) {
            return strtolower((string) ($rule['id'] ?? '')) !== strtolower($rule_id);
        });

        $after = count($rules);

        error_log("DELETE COUNT BEFORE: $before AFTER: $after");

        $settings['rules'] = array_values($rules);

        update_option('fsr_office_hours_settings', $settings);

        error_log('DELETE SAVED');

        wp_safe_redirect(remove_query_arg('edit_rule'));
        exit;
    }

    if (isset($_POST['fsr_oh_edit_rule_submit'])) {
        if (!wp_verify_nonce($_POST['_fsr_oh_edit_nonce'] ?? '', 'fsr_oh_edit_rule_submit')) {
            return [false, 'Ungültige Anfrage.'];
        }
        $member_id = absint($_POST['member'] ?? 0);
        $rule_id = sanitize_key($_POST['rule_id'] ?? '');
        if ($rule_id === '') {
            return [false, 'Ungültige Regel-ID.'];
        }
        $settings = fsr_office_hours_get_settings();
        $rules = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];
        $found = false;
        foreach ($rules as &$rule) {
            if (strtolower((string) ($rule['id'] ?? '')) === strtolower($rule_id)) {
                if (!in_array($member_id, fsr_office_hours_get_rule_members($rule), true)) {
                    return [false, 'Keine Berechtigung diese Sprechstunde zu bearbeiten.'];
                }

                $found = true;
                $rule = array_merge($rule, [
                    'title' => sanitize_text_field($_POST['title'] ?? $rule['title']),
                    'location' => sanitize_text_field($_POST['location'] ?? $rule['location']),
                    'type' => sanitize_key($_POST['type'] ?? $rule['type']),
                    'recurrence' => sanitize_key($_POST['recurrence'] ?? $rule['recurrence']),
                    'weekday' => max(1, min(7, absint($_POST['weekday'] ?? $rule['weekday']))),
                    'nth_week' => max(1, min(4, absint($_POST['nth_week'] ?? $rule['nth_week']))),
                    'week_interval' => max(1, min(8, absint($_POST['week_interval'] ?? $rule['week_interval']))),
                    'start_time' => fsr_office_hours_sanitize_time($_POST['start_time'] ?? $rule['start_time'], '10:00'),
                    'end_time' => fsr_office_hours_sanitize_time($_POST['end_time'] ?? $rule['end_time'], '12:00'),
                    'notes' => sanitize_text_field($_POST['notes'] ?? $rule['notes']),
                    'start_date' => fsr_office_hours_sanitize_date($_POST['start_date'] ?? $rule['start_date']),
                ]);
                break;
            }
        }
        unset($rule);

        if (!$found) {
            return [false, 'Regel nicht gefunden.'];
        }

        $settings['rules'] = array_values($rules);
        update_option('fsr_office_hours_settings', $settings);

        return [true, 'Sprechstunde aktualisiert.'];
    }
    return [$ok, $message];
}

function fsr_office_hours_describe_rule(array $rule): string {
    $weekday_labels = [
        1 => 'Montag',
        2 => 'Dienstag',
        3 => 'Mittwoch',
        4 => 'Donnerstag',
        5 => 'Freitag',
        6 => 'Samstag',
        7 => 'Sonntag',
    ];

    $weekday = $weekday_labels[(int) ($rule['weekday'] ?? 3)] ?? 'Mittwoch';
    if (($rule['recurrence'] ?? '') === 'weekly') {
        return 'Alle ' . max(1, (int) ($rule['week_interval'] ?? 1)) . ' Wochen, ' . $weekday;
    }
    return max(1, (int) ($rule['nth_week'] ?? 1)) . '. ' . $weekday . ' im Monat';
}

function fsr_office_hours_search(string $search_term): array {

    $search_term = trim(wp_strip_all_tags($search_term));

    if ($search_term === '') {
        return [];
    }

    $settings = fsr_office_hours_get_settings();
    $rules = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];
    $cancellations = is_array($settings['cancellations'] ?? null) ? $settings['cancellations'] : [];

    $virtual_posts = [];

    foreach ($rules as $rule) {

        if (!is_array($rule)) {
            continue;
        }

        $rule = fsr_office_hours_sanitize_rule($rule);

        $searchable = implode(' ', [
            $rule['title'] ?? '',
            $rule['type'] ?? '',
            $rule['location'] ?? '',
            $rule['notes'] ?? '',
            $rule['recurrence'] ?? '',
            $rule['weekday'] ?? '',
            $rule['nth_week'] ?? '',
            $rule['week_interval'] ?? '',
            $rule['start_time'] ?? '',
            $rule['end_time'] ?? '',
            $rule['start_date'] ?? '',
            implode(' ', fsr_office_hours_get_rule_members($rule)),
            fsr_office_hours_describe_rule($rule),
        ]);

        if (stripos($searchable, $search_term) === false) {
            continue;
        }

        $occurrences = fsr_office_hours_collect_occurrences([$rule], 12, true);

        foreach ($occurrences as $occurrence) {

            if (fsr_office_hours_occurrence_is_cancelled(
                $rule,
                $occurrence['date'],
                $cancellations
            )) {
                continue;
            }

            $timestamp = strtotime(
                $occurrence['date'] . ' ' . $occurrence['start_time']
            );

            $content = implode(' ', array_filter([
                $rule['title'] ?? '',
                $rule['location'] ?? '',
                $rule['notes'] ?? '',
                fsr_office_hours_describe_rule($rule),
            ]));

            $excerpt =
                'Sprechstunde am ' .
                date_i18n('d.m.Y', $timestamp) .
                ' von ' .
                $occurrence['start_time'] .
                ' bis ' .
                $occurrence['end_time'] .
                ' Uhr in ' .
                ($rule['location'] ?? '');

            $virtual_posts[] = fsr_create_virtual_search_post(
                $title = $rule['title'],
                $excerpt = $excerpt,
                $content = $content,
                $url = add_query_arg([
                    'member' => fsr_office_hours_member_param(),
                    'edit_rule' => strtolower($rule['id']),
                ], get_permalink()),
                $date = date('Y-m-d H:i:s', $timestamp),
                $type = 'page'
            );
        }
    }

    return $virtual_posts;
}