<?php

if (!defined('ABSPATH')) {
    exit;
}

function fsr_etit_office_hours_shortcode($atts): string {
    $atts = shortcode_atts(['limit' => 50], $atts, 'fsr_office_hours');
    $limit = max(1, min(100, absint($atts['limit'])));
    $member_map = [];
    foreach (fsr_etit_office_hours_get_all_members() as $member) {
        $member_id = absint($member['id'] ?? 0);
        if ($member_id === 0 || !fsr_etit_office_hours_is_allowed_member($member)) {
            continue;
        }
        $member_map[$member_id] = [
            'first_name' => (string) ($member['first_name'] ?? ''),
            'last_name'  => (string) ($member['last_name'] ?? ''),
            'email'      => !empty($member['email_prefix'])
                ? $member['email_prefix'] . FSR_ETIT_EMAIL_SUFFIX
                : '',
            'study'      => trim((string) ($member['studiengang'] ?? '') . ' ' . (string) ($member['abschluss'] ?? '')),
            'roles'      => array_filter(array_map('trim', explode(',', (string) ($member['amt'] ?? '')))),
        ];
    }

    $highlight_rule = isset($_GET['fsr_oh_rule'])
        ? sanitize_key(fsr_etit_scalar_string(wp_unslash($_GET['fsr_oh_rule'])))
        : '';
    $highlight_date = isset($_GET['fsr_oh_date'])
        ? sanitize_text_field(fsr_etit_scalar_string(wp_unslash($_GET['fsr_oh_date'])))
        : '';
    if (!fsr_etit_office_hours_is_valid_date($highlight_date)) {
        $highlight_date = '';
    }

    $settings = fsr_etit_office_hours_get_settings();
    $occurrences = fsr_etit_office_hours_collect_occurrences(
        $settings['rules'],
        $limit,
        true,
        $settings['cancellations'],
        array_keys($member_map)
    );
    if (empty($occurrences)) {
        return '<div class="fsr-office-hours-empty">Keine kommenden Sprechstunden gefunden.</div>';
    }

    $today = new DateTimeImmutable('today', wp_timezone());
    $week_end = $today->modify('+6 days');
    $now = wp_date('H:i', time(), wp_timezone());
    $today_string = $today->format('Y-m-d');
    $grouped = [];
    $highlighted = [];

    foreach ($occurrences as $occurrence) {
        $date = fsr_etit_office_hours_date_object($occurrence['date']);
        if (!$date) {
            continue;
        }
        $is_highlighted =
            $highlight_rule !== '' &&
            $highlight_date !== '' &&
            $occurrence['rule_id'] === $highlight_rule &&
            $occurrence['date'] === $highlight_date;

        if ($date >= $today && $date <= $week_end && (int) $date->format('N') <= 5) {
            $occurrence['is_highlighted'] = $is_highlighted;
            $grouped[(int) $date->format('N')][] = $occurrence;
        } elseif ($is_highlighted) {
            $occurrence['is_highlighted'] = true;
            $highlighted[] = $occurrence;
        }
    }

    if ($highlighted) {
        $grouped[0] = $highlighted;
    }
    if (!$grouped) {
        return '<div class="fsr-office-hours-empty">Für die nächsten sieben Tage sind keine Sprechstunden geplant.</div>';
    }

    ksort($grouped);
    $weekday_labels = [
        0 => 'Gefundene Sprechstunde',
        1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag',
    ];

    ob_start();
    ?>
    <div id="fsr-office-hours" class="fsr-oh-weekplan">
        <?php foreach ($weekday_labels as $day => $label) :
            if (empty($grouped[$day])) {
                continue;
            }
            usort($grouped[$day], static fn($left, $right): int => strcmp($left['start_time'], $right['start_time']));
            ?>
            <section class="fsr-oh-day<?php echo $day === 0 ? ' fsr-oh-highlighted-day' : ''; ?>">
                <h3>
                    <?php echo esc_html($label); ?>
                    <?php if ($day === 0 && !empty($grouped[$day][0]['date'])) :
                        $date = fsr_etit_office_hours_date_object($grouped[$day][0]['date']);
                        ?>
                        – <?php echo esc_html(wp_date('l, d.m.Y', $date->getTimestamp(), wp_timezone())); ?>
                    <?php endif; ?>
                </h3>

                <?php foreach ($grouped[$day] as $item) :
                    $members = [];
                    foreach ($item['member_ids'] as $member_id) {
                        if (
                            !isset($member_map[$member_id]) ||
                            fsr_etit_office_hours_member_is_cancelled(
                                $settings['cancellations'],
                                $item['rule_id'],
                                $item['date'],
                                (int) $member_id
                            )
                        ) {
                            continue;
                        }
                        $members[] = $member_map[$member_id];
                    }
                    $first_names = array_column($members, 'first_name');
                    $is_active =
                        $item['date'] === $today_string &&
                        $item['start_time'] <= $now &&
                        $item['end_time'] >= $now;
                    $classes = ['fsr-oh-card'];
                    if (!empty($item['is_highlighted'])) {
                        $classes[] = 'is-selected-date';
                    }
                    ?>
                    <details class="<?php echo esc_attr(implode(' ', $classes)); ?>" <?php echo !empty($item['is_highlighted']) ? 'open' : ''; ?>>
                        <summary class="fsr-oh-summary">
                            <?php if ($is_active) : ?><span class="fsr-oh-live">🟢 Jetzt besetzt</span><?php endif; ?>
                            <span class="fsr-oh-time"><?php echo esc_html($item['start_time'] . '–' . $item['end_time']); ?></span>
                            <span class="fsr-oh-names"><?php echo esc_html(implode(', ', array_filter($first_names))); ?></span>
                            <span class="fsr-oh-title"><?php echo esc_html($item['title']); ?></span>
                        </summary>
                        <div class="fsr-oh-body">
                            <p><strong>Mitglieder:</strong></p>
                            <ul>
                                <?php foreach ($members as $member) :
                                    $details = array_filter([
                                        trim($member['first_name'] . ' ' . $member['last_name']),
                                        $member['email'],
                                        $member['study'],
                                        implode(', ', $member['roles']),
                                    ]);
                                    ?>
                                    <li><?php echo esc_html(implode(' – ', $details)); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (!empty($item['location'])) : ?><p><strong>Raum:</strong> <?php echo esc_html($item['location']); ?></p><?php endif; ?>
                            <?php if (!empty($item['notes'])) : ?><p><strong>Notiz:</strong> <?php echo esc_html($item['notes']); ?></p><?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
