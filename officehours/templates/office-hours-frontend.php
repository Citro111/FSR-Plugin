<?php
function fsr_office_hours_collect_occurrences($rules, $limit = 12) {
    $today = current_time('Y-m-d');
    $today_ts = strtotime($today);
    $bucket = [];

    foreach ($rules as $rule) {
        $rule = wp_parse_args($rule, [
            'id' => '',
            'recurrence' => 'monthly_nth',
            'nth_week' => 1,
            'weekday' => 3,
            'week_interval' => 1,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'location' => 'FSR Büro',
            'member_ids' => [],
        ]);

        if ($rule['recurrence'] === 'monthly_nth') {
            for ($offset = 0; $offset < 12; $offset++) {
                $month_ts = strtotime('first day of +' . $offset . ' month', $today_ts);
                $year = (int) date('Y', $month_ts);
                $month = (int) date('n', $month_ts);
                $date = fsr_office_hours_nth_weekday_date($year, $month, $rule['weekday'], $rule['nth_week']);
                if (!$date || $date < $today) {
                    continue;
                }

                if (fsr_office_hours_is_cancelled($GLOBALS['settings']['cancellations'] ?? [], $rule['id'], $date)) {
                    continue;
                }
                

                $bucket[] = [
                    'rule_id' => $rule['id'],
                    'date' => $date,
                    'start_time' => $rule['start_time'],
                    'end_time' => $rule['end_time'],
                    'title' => $rule['title'],
                    'location' => $rule['location'],
                    'member_ids' => $rule['member_ids'],
                ];
            }
        } else {
            $weekday = (int) $rule['weekday'];
            $week_interval = max(1, (int) $rule['week_interval']);
            $cursor = $today_ts;
            $produced = 0;

            while ($produced < 16) {
                $current_weekday = (int) date('N', $cursor);
                $delta = ($weekday - $current_weekday + 7) % 7;
                $candidate_ts = strtotime('+' . $delta . ' day', $cursor);
                $date = date('Y-m-d', $candidate_ts);

                if ($date >= $today) {
                    if (fsr_office_hours_is_cancelled($GLOBALS['settings']['cancellations'] ?? [], $rule['id'], $date)) {
                        continue;
                    }
                    $bucket[] = [
                        'rule_id' => $rule['id'],
                        'date' => $date,
                        'start_time' => $rule['start_time'],
                        'end_time' => $rule['end_time'],
                        'title' => $rule['title'],
                        'location' => $rule['location'],
                        'member_ids' => $rule['member_ids'],
                    ];
                    $produced++;
                }

                $cursor = strtotime('+' . $week_interval . ' week', $candidate_ts);
            }
        }
    }

    usort($bucket, static function ($a, $b) {
        $left = $a['date'] . ' ' . $a['start_time'];
        $right = $b['date'] . ' ' . $b['start_time'];
        return strcmp($left, $right);
    });

    return array_slice($bucket, 0, max(1, absint($limit)));
}

function fsr_office_hours_nth_weekday_date($year, $month, $weekday, $nth) {
    $first_day = sprintf('%04d-%02d-01', (int) $year, (int) $month);
    $first_ts = strtotime($first_day);
    if ($first_ts === false) {
        return null;
    }

    $first_weekday = (int) date('N', $first_ts);
    $delta = ($weekday - $first_weekday + 7) % 7;
    $day = 1 + $delta + (($nth - 1) * 7);

    $candidate = sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    $candidate_ts = strtotime($candidate);
    if ($candidate_ts === false || (int) date('n', $candidate_ts) !== (int) $month) {
        return null;
    }

    return $candidate;
}

function fsr_office_hours_shortcode($atts) {
    $atts = shortcode_atts(['limit' => 10], $atts);
    $settings = fsr_office_hours_get_settings();
    $members_map = fsr_office_hours_get_members_map();
    $occurrences = fsr_office_hours_collect_occurrences($settings['rules'], absint($atts['limit']));

    if (empty($occurrences)) {
        return '<div class="fsr-office-hours-empty">Aktuell sind keine Office Hours hinterlegt.</div>';
    }

    ob_start();
    echo '<div class="fsr-office-hours-list">';
    foreach ($occurrences as $occurrence) {

        $ts = strtotime($occurrence['date']);
        $weekday = date_i18n('l', $ts);
        $date = date_i18n('d.m.Y', $ts);

        $members = [];

        foreach ($occurrence['member_ids'] as $member_id) {
            if (!isset($members_map[$member_id])) continue;
            $members[] = $members_map[$member_id]['name'];
        }

        echo '<article class="fsr-office-hours-item">';

        echo '<p class="fsr-oh-date"><strong>'
            . esc_html("$weekday, $date {$occurrence['start_time']}–{$occurrence['end_time']}")
            . '</strong></p>';

        echo '<p class="fsr-oh-members">'
            . esc_html(implode(', ', $members) ?: 'Keine Personen')
            . '</p>';

        if (!empty($occurrence['location'])) {
            echo '<p class="fsr-oh-location">'
                . esc_html($occurrence['location'])
                . '</p>';
        }

        echo '</article>';
    }
    echo '</div>';

    return ob_get_clean();
}