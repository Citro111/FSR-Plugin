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
                $settings = fsr_office_hours_get_settings();
                if (!$date || $date < $today) {
                    continue;
                }

                if (fsr_office_hours_is_cancelled($settings['cancellations'] ?? [], $rule['id'], $date)) {
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
                $settings = fsr_office_hours_get_settings();

                if ($date >= $today) {
                    if (fsr_office_hours_is_cancelled($settings['cancellations'] ?? [], $rule['id'], $date)) {
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
    $atts = shortcode_atts(['limit' => 50], $atts);

    $settings = fsr_office_hours_get_settings();
    $members_map = fsr_office_hours_get_members_map();

    $occurrences = fsr_office_hours_collect_occurrences(
        $settings['rules'],
        absint($atts['limit'])
    );

    if (empty($occurrences)) {
        return '<div class="fsr-office-hours-empty">Aktuell sind keine Office Hours hinterlegt.</div>';
    }

    $today = strtotime(current_time('Y-m-d'));
    $end = strtotime('+14 days', $today);

    // 🔥 Filter: nur nächste 7 Werktage im Zeitraum
    $filtered = [];

    foreach ($occurrences as $o) {
        $ts = strtotime($o['date']);

        if ($ts < $today || $ts > $end) continue;
        if ((int) date('N', $ts) > 5) continue; // Wochenende raus

        $o['ts'] = $ts;
        $filtered[] = $o;
    }

    // Sortieren nach Datum + Zeit
    usort($filtered, function ($a, $b) {
        return strcmp(
            $a['date'] . $a['start_time'],
            $b['date'] . $b['start_time']
        );
    });

    if (empty($filtered)) {
        return '<div class="fsr-office-hours-empty">Keine Termine in den nächsten Werktagen.</div>';
    }

    ob_start();

    echo '<div class="fsr-oh-weeklist">';

    foreach ($filtered as $item) {

        $members = [];

        foreach ($item['member_ids'] as $id) {
            if (!isset($members_map[$id])) continue;
            $members[] = $members_map[$id]['name'];
        }

        $dateLabel = date_i18n('D, d.m.', $item['ts']);
        $timeLabel = $item['start_time'] . '–' . $item['end_time'];

        echo '<details class="fsr-oh-card">';

        // 👉 CLOSED VIEW (kompakt)
        echo '<summary class="fsr-oh-summary">';
        echo '<span class="fsr-oh-main">';
        echo esc_html($timeLabel);
        echo '</span>';

        echo '<span class="fsr-oh-members-inline">';
        echo esc_html(implode(', ', $members) ?: 'Keine Person');
        echo '</span>';

        echo '</summary>';

        // 👉 OPEN VIEW (vollständig)
        echo '<div class="fsr-oh-body">';

        echo '<p><strong>Datum:</strong> ' . esc_html($dateLabel) . '</p>';
        echo '<p><strong>Zeit:</strong> ' . esc_html($timeLabel) . '</p>';

        echo '<p><strong>Titel:</strong> ' . esc_html($item['title']) . '</p>';

        if (!empty($item['location'])) {
            echo '<p><strong>Raum:</strong> ' . esc_html($item['location']) . '</p>';
        }

        echo '<p><strong>Mitglieder:</strong> '
            . esc_html(implode(', ', $members) ?: 'Keine Personen')
            . '</p>';

        echo '</div>';

        echo '</details>';
    }

    echo '</div>';

    return ob_get_clean();
}

function fsr_office_hours_is_cancelled($cancellations, $rule_id, $date) {
    foreach ($cancellations as $c) {
        if (($c['rule_id'] ?? '') === $rule_id && ($c['occurrence_date'] ?? '') === $date) {
            return true;
        }
    }
    return false;
}
