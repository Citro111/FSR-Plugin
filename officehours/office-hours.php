<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/templates/office-hours-adminUI.php';
require_once __DIR__ . '/templates/office-hours-frontend.php';
require_once __DIR__ . '/templates/office-hours-cancel.php';

add_action('admin_init', 'fsr_office_hours_register_settings');
add_action('admin_enqueue_scripts', function () {
    wp_enqueue_script('select2');
    wp_enqueue_style('select2');
});
add_shortcode('fsr_office_hours', 'fsr_office_hours_shortcode');
add_shortcode('fsr_office_hours_sick', 'fsr_office_hours_sick_shortcode');

function fsr_office_hours_get_settings() {
    return wp_parse_args(get_option('fsr_office_hours_settings', []), [
        'rules' => [],
        'cancellations' => []
    ]);
}

function fsr_office_hours_sanitize_time($time) {
    $time = trim((string) $time);
    return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time) ? $time : '10:00';
}

function fsr_office_hours_sanitize_rule($rule, $index = 0) {
    $rule = is_array($rule) ? $rule : [];

    $id = sanitize_key((string) ($rule['id'] ?? ''));
    if ($id === '') {
        $id = 'rule_' . ($index + 1) . '_' . wp_generate_password(6, false, false);
    }

    $recurrence = sanitize_key((string) ($rule['recurrence'] ?? 'monthly_nth'));
    if (!in_array($recurrence, ['monthly_nth', 'weekly'], true)) {
        $recurrence = 'monthly_nth';
    }

    $member_ids = [];
    $incoming_ids = $rule['member_ids'] ?? [];
    if (!is_array($incoming_ids)) {
        $incoming_ids = [$incoming_ids];
    }
    foreach ($incoming_ids as $id_value) {
        $clean_id = absint($id_value);
        if ($clean_id > 0) {
            $member_ids[] = $clean_id;
        }
    }

    return [
        'id' => $id,
        'title' => sanitize_text_field((string) ($rule['title'] ?? 'Office Hour')),
        'recurrence' => $recurrence,
        'nth_week' => max(1, min(4, absint($rule['nth_week'] ?? 1))),
        'weekday' => max(1, min(7, absint($rule['weekday'] ?? 3))),
        'week_interval' => max(1, min(8, absint($rule['week_interval'] ?? 1))),
        'start_time' => fsr_office_hours_sanitize_time($rule['start_time'] ?? '10:00'),
        'end_time' => fsr_office_hours_sanitize_time($rule['end_time'] ?? '12:00'),
        'location' => sanitize_text_field((string) ($rule['location'] ?? 'FSR Büro')),
        'member_ids' => array_values(array_unique($member_ids)),
    ];
}

function fsr_sanitize_office_hours_settings($input) {
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

function fsr_office_hours_is_workday($ts) {
    $weekday = (int) date('N', $ts); // 1-7
    return $weekday <= 5; // Mo-Fr
}

function fsr_office_hours_in_next_workdays($ts, $days = 7) {
    $today = strtotime(current_time('Y-m-d'));

    $count = 0;
    $cursor = $today;

    while ($cursor <= strtotime("+14 days", $today)) {
        if (fsr_office_hours_is_workday($cursor)) {
            $count++;
        }

        if ($cursor >= $ts && $count <= $days) {
            return true;
        }

        if ($count > $days) {
            return false;
        }

        $cursor = strtotime('+1 day', $cursor);
    }

    return false;
}

function fsr_office_hours_search($search_term) {
    $search_term = trim(wp_strip_all_tags($search_term));

    $settings = fsr_office_hours_get_settings();

    if (empty($settings['rules']) || !is_array($settings['rules'])) {
        return '';
    }

    $output = '';

    foreach ($settings['rules'] as $rule) {

        if (!is_array($rule)) {
            continue;
        }

        // Suchtext zusammenbauen
        $searchable = [];
        $searchable[] = $rule['title'] ?? '';
        $searchable[] = $rule['location'] ?? '';

        // Mitgliedsnamen ergänzen
        if (!empty($rule['member_ids'])) {
            $members = fsr_get_members_data();
            foreach ($members as $member) {
                if (in_array($member['id'], $rule['member_ids'], true)) {
                    $searchable[] = $member['first_name'] ?? '';
                    $searchable[] = $member['last_name'] ?? '';
                }
            }
        }

        $searchableText = implode(' ', $searchable);

        if (stripos($searchableText, $search_term) === false) {
            continue;
        }

        // Nächsten Termin bestimmen
        $nextDate = '';
        if (($rule['recurrence'] ?? '') === 'monthly_nth') {
            foreach (fsr_office_hours_get_occurrences($rule, 16) as $date) {
                if (
                    $date >= current_time('Y-m-d')
                    && !fsr_office_hours_occurrence_is_cancelled(
                        $rule,
                        $date,
                        $settings['cancellations'] ?? []
                    )
                ) {
                    $nextDate = $date;
                    break;
                }
            }
        } else {
            $occurrences = fsr_office_hours_get_occurrences($rule, 16);
            foreach ($occurrences as $date) {
                if (
                    $date >= current_time('Y-m-d')
                    && !fsr_office_hours_occurrence_is_cancelled(
                        $rule,
                        $date,
                        $settings['cancellations'] ?? []
                    )
                ) {
                    $nextDate = $date;
                    break;
                }
            }
        }

        // Ausgabe
        $output .= fsr_search_result(
            $rule['title'], 
            [
                $nextDate,
                $rule['start_time'] . ' - ' . $rule['end_time'],
                $rule['location']
            ],
            '',
            'office-hour'
        );
    }
    return $output;
}
