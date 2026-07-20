<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Office Hours data model:
 * - rules: recurring office hour rules
 * - cancellations: per-member cancellation entries for a rule occurrence
 *
 * This file provides:
 * - settings registration / sanitizing
 * - member selection via URL (?member=123)
 * - helper functions for rules, members, next occurrences
 * - admin/summary shortcode that lets a selected member create/join rules
 * - a schedule view that lists upcoming occurrences for the selected member
 */ 

add_action('admin_init', 'fsr_office_hours_register_settings');
add_action('admin_enqueue_scripts', function () {
    wp_enqueue_script('select2');
    wp_enqueue_style('select2');
});

add_shortcode('fsr_office_hours', 'fsr_office_hours_portal_shortcode');

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

function fsr_office_hours_portal_shortcode($atts): string {
    $atts = shortcode_atts([
        'limit' => 20,
    ], $atts, 'fsr_office_hours');

    $selected_member = fsr_office_hours_get_selected_member();
    $selected_member_id = $selected_member ? (int) ($selected_member['id'] ?? 0) : 0;
    $selected_member_name = trim((string) ($selected_member['first_name'] ?? '') . ' ' . (string) ($selected_member['last_name'] ?? ''));
    $selected_member_name = $selected_member_name !== '' ? $selected_member_name : 'Mitglied';

    [$ok, $message] = fsr_office_hours_handle_portal_actions();

    $settings = fsr_office_hours_get_settings();
    $rules = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];
    $cancellations = is_array($settings['cancellations'] ?? null) ? $settings['cancellations'] : [];
    $allowed_members = fsr_office_hours_get_allowed_members();
    $occurrences = fsr_office_hours_collect_occurrences($rules, absint($atts['limit']), true);

    $selected_rules = [];
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $rule = fsr_office_hours_sanitize_rule($rule);
        if ($selected_member_id > 0 && in_array($selected_member_id, $rule['member_ids'], true)) {
            $selected_rules[] = $rule;
        }
    }

    ob_start();
    ?>
    <div class="fsr-office-hours-portal" style="display:grid;grid-template-columns:1.1fr .9fr;gap:24px;align-items:start;">
        <div>
            <form method="get" style="margin-bottom: 16px;">
                <label for="fsr_oh_member"><strong>Mitglied auswählen</strong></label><br>
                <select id="fsr_oh_member" name="member" onchange="this.form.submit()" style="min-width: 320px;">
                    <option value="">Bitte wählen</option>
                    <?php foreach ($allowed_members as $member) : ?>
                        <option value="<?php echo esc_attr($member['id']); ?>" <?php selected($selected_member_id, (int) $member['id']); ?>>
                            <?php echo esc_html($member['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="button">Anzeigen</button></noscript>
            </form>

            <?php if ($selected_member_id > 0) : ?>
                <div class="notice notice-info" style="margin-bottom:16px;padding:12px;">
                    <strong><?php echo esc_html($selected_member_name); ?></strong> ist ausgewählt.
                    <?php if (!empty($selected_rules)) : ?>
                        <div style="margin-top:8px;">Eigene Sprechstunden: <?php echo esc_html(count($selected_rules)); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($message !== '') : ?>
                <div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?>" style="margin-bottom:16px;padding:12px;">
                    <?php echo esc_html($message); ?>
                </div>
            <?php endif; ?>

            <details open style="margin-bottom: 16px; padding: 12px; border: 1px solid #ddd; background: #fff;">
                <summary style="cursor:pointer; font-weight:600;">+ Neue Sprechstunde anlegen</summary>
                <form method="post" style="margin-top:16px;">
                    <?php wp_nonce_field('fsr_oh_create_rule_submit', '_fsr_oh_create_nonce'); ?>
                    <input type="hidden" name="fsr_oh_create_rule_submit" value="1" />
                    <input type="hidden" name="member" value="<?php echo esc_attr($selected_member_id); ?>" />

                    <p>
                        <label><strong>Titel</strong></label><br>
                        <input type="text" name="title" class="regular-text" value="Office Hour" required />
                    </p>

                    <p>
                        <label><strong>Typ</strong></label><br>
                        <select name="type">
                            <option value="office_hour">Office Hour</option>
                            <option value="meeting">Sitzung</option>
                            <option value="assembly">Versammlung</option>
                        </select>
                    </p>

                    <p>
                        <label><strong>Rhythmus</strong></label><br>
                        <select name="recurrence" class="fsr-oh-recurrence-create">
                            <option value="weekly">Wöchentlich</option>
                            <option value="monthly_nth">Monatlich (n-ter Wochentag)</option>
                        </select>
                    </p>

                    <p class="fsr-oh-create-weekly">
                        <label><strong>Wochentag</strong></label><br>
                        <select name="weekday">
                            <option value="1">Montag</option>
                            <option value="2">Dienstag</option>
                            <option value="3" selected>Mittwoch</option>
                            <option value="4">Donnerstag</option>
                            <option value="5">Freitag</option>
                            <option value="6">Samstag</option>
                            <option value="7">Sonntag</option>
                        </select>
                        <br><br>
                        Alle <input type="number" name="week_interval" min="1" max="8" value="1" style="width:72px;" /> Wochen
                    </p>

                    <p class="fsr-oh-create-monthly" style="display:none;">
                        <label><strong>Monatlich</strong></label><br>
                        <select name="nth_week">
                            <option value="1">1.</option>
                            <option value="2">2.</option>
                            <option value="3">3.</option>
                            <option value="4">4.</option>
                        </select>
                        <select name="weekday">
                            <option value="1">Montag</option>
                            <option value="2">Dienstag</option>
                            <option value="3" selected>Mittwoch</option>
                            <option value="4">Donnerstag</option>
                            <option value="5">Freitag</option>
                            <option value="6">Samstag</option>
                            <option value="7">Sonntag</option>
                        </select>
                    </p>

                    <p>
                        <label><strong>Zeit</strong></label><br>
                        <input type="time" name="start_time" value="10:00" /> bis
                        <input type="time" name="end_time" value="12:00" />
                    </p>

                    <p>
                        <label><strong>Ort</strong></label><br>
                        <input type="text" name="location" class="regular-text" value="FSR Büro" />
                    </p>

                    <p>
                        <label><strong>Weitere Teilnehmende</strong></label><br>
                        <select name="member_ids[]" multiple size="6" class="fsr-oh-select2" style="min-width:320px;">
                            <?php foreach ($allowed_members as $member) : ?>
                                <?php if ((int) $member['id'] === $selected_member_id) { continue; } ?>
                                <option value="<?php echo esc_attr($member['id']); ?>"><?php echo esc_html($member['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p>
                        <label><strong>Notiz</strong></label><br>
                        <input type="text" name="notes" class="regular-text" placeholder="Optional" />
                    </p>

                    <p>
                        <button type="submit" class="button button-primary">Speichern</button>
                    </p>
                </form>
            </details>

            <?php if ($selected_member_id > 0) : ?>
                <details open style="margin-bottom: 16px; padding: 12px; border: 1px solid #ddd; background: #fff;">
                    <summary style="cursor:pointer; font-weight:600;">+ Einer vorhandenen Sprechstunde beitreten</summary>
                    <form method="post" style="margin-top:16px;">
                        <?php wp_nonce_field('fsr_oh_join_submit', '_fsr_oh_join_nonce'); ?>
                        <input type="hidden" name="fsr_oh_join_submit" value="1" />
                        <input type="hidden" name="member" value="<?php echo esc_attr($selected_member_id); ?>" />

                        <p>
                            <label><strong>Sprechstunde</strong></label><br>
                            <select name="rule_id" class="fsr-oh-select2" style="min-width:320px;" required>
                                <option value="">Bitte wählen</option>
                                <?php foreach ($rules as $rule) : ?>
                                    <?php $rule = fsr_office_hours_sanitize_rule($rule); ?>
                                    <option value="<?php echo esc_attr($rule['id']); ?>">
                                        <?php echo esc_html($rule['title'] . ' · ' . $rule['location']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>

                        <p>
                            <button type="submit" class="button">Teilnehmen</button>
                        </p>
                    </form>
                </details>
            <?php endif; ?>

            <h3 style="margin-top:24px;">Meine Sprechstunden</h3>
            <?php if (empty($selected_rules)) : ?>
                <p>Für das ausgewählte Mitglied sind noch keine Sprechstunden hinterlegt.</p>
            <?php else : ?>
                <div style="display:grid;gap:12px;">
                    <?php foreach ($selected_rules as $rule) : ?>
                        <?php $member_names = fsr_office_hours_get_rule_members($rule); ?>
                        <div style="padding:12px;border:1px solid #ddd;background:#fff;">
                            <strong><?php echo esc_html($rule['title']); ?></strong><br>
                            <?php echo esc_html(fsr_office_hours_describe_rule($rule)); ?><br>
                            <span class="description"><?php echo esc_html($rule['location']); ?></span><br>
                            <small><?php echo esc_html(implode(', ', $member_names)); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3 style="margin-top:24px;">Meine nächsten Termine</h3>
            <?php
            $selected_occurrences = [];
            foreach ($occurrences as $occurrence) {
                if ($selected_member_id <= 0 || !in_array($selected_member_id, $occurrence['member_ids'], true)) {
                    continue;
                }
                $selected_occurrences[] = $occurrence;
            }
            ?>

            <?php if (empty($selected_occurrences)) : ?>
                <p>Keine kommenden Termine gefunden.</p>
            <?php else : ?>
                <div style="display:grid;gap:12px;">
                    <?php foreach ($selected_occurrences as $occurrence) : ?>
                        <?php
                        $is_cancelled = fsr_office_hours_member_is_cancelled(
                            $cancellations,
                            $occurrence['rule_id'],
                            $occurrence['date'],
                            $selected_member_id
                        );

                        $active_member_ids = [];
                        foreach (($occurrence['member_ids'] ?? []) as $member_id) {
                            $member_id = (int) $member_id;
                            if ($member_id <= 0) {
                                continue;
                            }
                            if (fsr_office_hours_member_is_cancelled($cancellations, $occurrence['rule_id'], $occurrence['date'], $member_id)) {
                                continue;
                            }
                            $active_member_ids[] = $member_id;
                        }

                        $active_names = [];
                        foreach ($active_member_ids as $member_id) {
                            $member = fsr_office_hours_get_member_by_id($member_id);
                            if (!$member) {
                                continue;
                            }
                            $name = trim((string) ($member['first_name'] ?? '') . ' ' . (string) ($member['last_name'] ?? ''));
                            if ($name !== '') {
                                $active_names[] = $name;
                            }
                        }

                        $fallback_name = fsr_office_hours_get_rule_members(fsr_office_hours_sanitize_rule($occurrence));
                        $show_names = !empty($active_names) ? $active_names : $fallback_name;
                        $is_empty = empty($show_names);
                        ?>
                        <div style="padding:12px;border:1px solid #ddd;background:#fff;">
                            <strong><?php echo esc_html(date_i18n('d.m.Y', strtotime($occurrence['date'])) . ' · ' . $occurrence['title']); ?></strong><br>
                            <?php echo esc_html($occurrence['start_time'] . '–' . $occurrence['end_time']); ?> · <?php echo esc_html($occurrence['location']); ?><br>
                            <small>
                                <?php if ($is_empty) : ?>
                                    Keine aktiven Teilnehmenden
                                <?php else : ?>
                                    <?php echo esc_html(implode(', ', $show_names)); ?>
                                <?php endif; ?>
                            </small>

                            <form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                <?php wp_nonce_field('fsr_oh_cancellation_submit', '_fsr_oh_cancel_nonce'); ?>
                                <input type="hidden" name="fsr_oh_cancellation_submit" value="1" />
                                <input type="hidden" name="member" value="<?php echo esc_attr($selected_member_id); ?>" />
                                <input type="hidden" name="rule_id" value="<?php echo esc_attr($occurrence['rule_id']); ?>" />
                                <input type="hidden" name="date" value="<?php echo esc_attr($occurrence['date']); ?>" />

                                <?php if ($is_cancelled) : ?>
                                    <input type="hidden" name="cancel_action" value="uncancel" />
                                    <button type="submit" class="button">Wieder zusagen</button>
                                <?php else : ?>
                                    <input type="hidden" name="cancel_action" value="toggle" />
                                    <input type="text" name="reason" placeholder="Optionaler Grund" class="regular-text" style="max-width:240px;" />
                                    <button type="submit" class="button">Absagen</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <h3>Hinweise</h3>
            <div style="padding:12px;border:1px solid #ddd;background:#fff;">
                <p><strong>Mitglied per URL vorwählen:</strong></p>
                <code><?php echo esc_html(home_url('/?member=123')); ?></code>
                <p style="margin-top:12px;">Die ausgewählte Person kann oben neue Sprechstunden anlegen, bestehenden Regeln beitreten und unten kommende Termine absagen oder wieder zusagen.</p>
                <p>Wenn alle Teilnehmenden eines Termins abgesagt haben, erscheint der Termin in der Anzeige als leer bzw. fällt praktisch aus.</p>
            </div>
        </div>
    </div>

    <script>
        jQuery(function($) {
            function toggleCreateRecurrence() {
                const mode = $('.fsr-oh-recurrence-create').val();
                $('.fsr-oh-create-weekly').toggle(mode === 'weekly');
                $('.fsr-oh-create-monthly').toggle(mode === 'monthly_nth');
            }

            $('.fsr-oh-recurrence-create').on('change', toggleCreateRecurrence);
            toggleCreateRecurrence();

            if ($.fn.select2) {
                $('.fsr-oh-select2').select2({ width: '100%' });
            }
        });
    </script>
    <?php

    return ob_get_clean();
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
