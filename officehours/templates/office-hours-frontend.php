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
                if (fsr_office_hours_member_is_cancelled($settings['cancellations'] ?? [], $rule['id'], $date)) {
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

            for ($i = 0; $i < 16; $i++) {

                $current_weekday = (int) date('N', $cursor);
                $delta = ($weekday - $current_weekday + 7) % 7;

                $candidate_ts = strtotime("+$delta days", $cursor);
                $date = date('Y-m-d', $candidate_ts);

                $settings = fsr_office_hours_get_settings();

                if (
                    $date >= $today &&
                    !fsr_office_hours_member_is_cancelled(
                        $settings['cancellations'] ?? [],
                        $rule['id'],
                        $date
                    )
                ) {

                    $bucket[$rule['id'].'_'.$date] = [
                        'rule_id' => $rule['id'],
                        'date' => $date,
                        'start_time' => $rule['start_time'],
                        'end_time' => $rule['end_time'],
                        'title' => $rule['title'],
                        'location' => $rule['location'],
                        'member_ids' => $rule['member_ids'],
                    ];
                }

                $cursor = strtotime("+{$week_interval} week", $candidate_ts);
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
    
    $members_raw = fsr_get_members_data('all')['members'];
    $members_map = [];
    foreach ($members_raw as $m) {
        $members_map[$m['id']] = [
            'id' => $m['id'],
            'first_name' => $m['first_name'] ?? '',
            'last_name' => $m['last_name'] ?? '',
            'email' => !empty($m['email_prefix']) ? $m['email_prefix'] . ' (at) fsr-etit.de' : '',
            'study' => trim(($m['studiengang'] ?? '') . ' ' . ($m['abschluss'] ?? '')),
            'roles' => !empty($m['amt']) ? [$m['amt']] : [],
        ];
    }
    $settings = fsr_office_hours_get_settings();
    $occurrences = fsr_office_hours_collect_occurrences(
        $settings['rules'],
        absint($atts['limit'])
    );
    if (empty($occurrences)) {
        return '<div class="fsr-office-hours-empty">Keine Termine.</div>';
    }
    $now = current_time('H:i');
    $todayDate = current_time('Y-m-d');
    $today = strtotime(current_time('Y-m-d'));
    $weekEnd = strtotime('+7 days', $today);
    $filtered = [];
    foreach ($occurrences as $o) {
        $ts = strtotime($o['date']);
        if ($ts < $today || $ts > $weekEnd) continue;
        if ((int) date('N', $ts) > 5) continue;
        $o['ts'] = $ts;
        $filtered[] = $o;
    }
    // Gruppieren nach Wochentag
    $grouped = [];
    foreach ($filtered as $item) {
        $weekday = (int) date('N', $item['ts']);
        $grouped[$weekday][] = $item;
    }
    ksort($grouped);
    $weekday_labels = [
        1 => 'Montag',
        2 => 'Dienstag',
        3 => 'Mittwoch',
        4 => 'Donnerstag',
        5 => 'Freitag'
    ];

    ob_start();

    echo '<div class="fsr-oh-weekplan">';

    foreach ($weekday_labels as $day => $label) {
        if (empty($grouped[$day])) continue;
        echo '<section class="fsr-oh-day">';
        echo '<h3>' . esc_html($label) . '</h3>';

        usort($grouped[$day], function ($a, $b) {
            return strcmp($a['start_time'], $b['start_time']);
        });

        foreach ($grouped[$day] as $item) {

            $is_active =
                $item['date'] === $todayDate &&
                $item['start_time'] <= $now &&
                $item['end_time'] >= $now;
            $members = [];

            foreach ($item['member_ids'] as $id) {
                if (!isset($members_map[$id])) continue;
                if (fsr_office_hours_member_is_cancelled(
                        $settings['cancellations'],
                        $item['rule_id'],
                        $item['date']
                )) {
                    continue;
                }
                $members[] = $members_map[$id];
            }
            $first_names = array_map(fn($m) => $m['first_name'], $members);
            $timeLabel = $item['start_time'] . '–' . $item['end_time'];
            echo '<details class="fsr-oh-card">';
            // CLOSED VIEW
            echo '<summary class="fsr-oh-summary">';
            if ($is_active) {
                echo '<span class="fsr-oh-live">🟢 Jetzt besetzt</span>';
            }
            echo '<span class="fsr-oh-time">' . esc_html($timeLabel) . '</span>';
            echo '<span class="fsr-oh-names">' . esc_html(implode(', ', $first_names)) . '</span>';
            echo '</summary>';
            // OPEN VIEW
            echo '<div class="fsr-oh-body">';
            echo '<p><strong>Mitglieder:</strong></p>';
            echo '<ul>';
            foreach ($members as $m) {
                $full = trim($m['first_name'] . ' ' . $m['last_name']);
                echo '<li>';
                echo esc_html($full);
                if (!empty($m['email'])) {
                    echo ' – ' . esc_html($m['email']);
                }
                if (!empty($m['study'])) {
                    echo ' – ' . esc_html($m['study']);
                }
                if (!empty($m['roles'])) {
                    echo ' – ' . esc_html(implode(', ', (array)$m['roles']));
                }
                echo '</li>';
            }

            echo '</ul>';

            if (!empty($item['location'])) {
                echo '<p><strong>Raum:</strong> ' . esc_html($item['location']) . '</p>';
            }

            echo '</div>';
            echo '</details>';
        }

        echo '</section>';
    }

    echo '</div>';

    return ob_get_clean();
}

function fsr_office_hours_member_is_cancelled($cancellations, $rule_id, $date) {

    foreach ($cancellations as $c) {

        if (
            ($c['rule_id'] ?? '') === $rule_id &&
            ($c['occurrence_date'] ?? '') === $date &&
            absint($c['member_id'] ?? 0) === absint($member_id)
        ) {
            return true;
        }
    }

    return false;
}
