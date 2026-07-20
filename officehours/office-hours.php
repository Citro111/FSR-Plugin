<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once FSR_PLUGIN_DIR . 'templates/office-hours-frontend.php';
require_once FSR_PLUGIN_DIR . 'templates/office-hours-portal.php';
require_once FSR_PLUGIN_DIR . 'templates/office-hours-adminUI.php';

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
        $id = 'rule_' . ($index + 1) . '_' . wp_generate_password(6, false, false);
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
        'created_by' => absint($rule['created_by'] ?? 0),
        'notes' => sanitize_text_field((string) ($rule['notes'] ?? '')),
    ];
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
    $role = strtolower((string) ($member['role'] ?? ''));

    return !empty($member['office_hours_allowed'])
        || in_array($role, ['gewählt', 'gewaehlt', 'helfer', 'helper'], true);
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

function fsr_office_hours_collect_occurrences(array $rules, int $limit = 20, bool $only_future = true): array {
    $rules = is_array($rules) ? $rules : [];
    $results = [];
    $today = current_time('Y-m-d');
    $until = strtotime('+18 months', strtotime($today));

    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $rule = fsr_office_hours_sanitize_rule($rule);
        if (empty($rule['member_ids'])) {
            continue;
        }

        $base_ts = strtotime($today . ' 00:00:00');
        $generated = 0;
        $cursor = $base_ts;

        while ($cursor <= $until && $generated < ($limit * 3)) {
            $date = wp_date('Y-m-d', $cursor);
            $weekday = (int) wp_date('N', $cursor);

            $matches = false;

            if ($rule['recurrence'] === 'weekly') {
                $week_interval = max(1, (int) $rule['week_interval']);
                $start_week = (int) wp_date('W', strtotime($today));
                $current_week = (int) wp_date('W', $cursor);
                $week_diff = $current_week - $start_week;

                $matches = $weekday === (int) $rule['weekday'] && $week_diff >= 0 && (($week_diff % $week_interval) === 0);
            } else {
                $nth_week = (int) $rule['nth_week'];
                $month_start = strtotime(wp_date('Y-m-01', $cursor));
                $nth_date = strtotime('+' . ($nth_week - 1) . ' weeks', strtotime('+' . (int) $rule['weekday'] . ' days', $month_start));
                $matches = $date === wp_date('Y-m-d', $nth_date);
            }

            if ($matches) {
                if ($only_future && $date < $today) {
                    $cursor = strtotime('+1 day', $cursor);
                    $generated++;
                    continue;
                }

                $results[] = [
                    'rule_id' => $rule['id'],
                    'title' => $rule['title'],
                    'date' => $date,
                    'start_time' => $rule['start_time'],
                    'end_time' => $rule['end_time'],
                    'location' => $rule['location'],
                    'member_ids' => $rule['member_ids'],
                ];

                if (count($results) >= $limit) {
                    break 2;
                }
            }

            $cursor = strtotime('+1 day', $cursor);
            $generated++;
        }
    }

    usort($results, static function ($a, $b) {
        return strcmp($a['date'] . ' ' . $a['start_time'], $b['date'] . ' ' . $b['start_time']);
    });

    return array_slice($results, 0, $limit);
}

function fsr_office_hours_handle_portal_actions(): array {
    $message = '';
    $ok = false;

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

        $additional_member_ids = fsr_office_hours_normalize_member_ids($_POST['member_ids'] ?? []);
        $all_member_ids = array_values(array_unique(array_merge([$member_id], $additional_member_ids)));

        $rule = [
            'id' => 'rule_' . wp_generate_password(8, false, false),
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
            'created_by' => $member_id,
            'notes' => $notes,
        ];

        $settings = fsr_office_hours_get_settings();
        $settings['rules'] = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];
        $settings['rules'][] = $rule;

        update_option('fsr_office_hours_settings', $settings);
        return [true, 'Neue Sprechstunde gespeichert.'];
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
